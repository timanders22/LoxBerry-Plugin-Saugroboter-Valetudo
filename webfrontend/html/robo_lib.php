<?php
/**
 * Saugroboter (Valetudo) - gemeinsame Bibliothek
 *
 * Fasst die vier Valetudo-Schnittstellen (Status, Statistik aktuell, Statistik
 * gesamt, Verbrauchsmaterialien) zu EINER Abfrage zusammen und liefert an Loxone
 * fertige Zahlenwerte - insbesondere einen numerischen Statuscode statt der
 * bisherigen Buchstaben-Bastelei. Zusaetzlich Steuerbefehle als einfache
 * GET-Aufrufe (Valetudo verlangt sonst PUT mit JSON-Rumpf).
 *
 * Keine persoenlichen Daten im Code - alles kommt aus der lokalen Konfiguration.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
date_default_timezone_set('Europe/Berlin');


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function ro_paths() {
    $lb = getenv('LBHOMEDIR') ?: lb_wurzel_ermitteln();
    // Der Ordnername wird ERMITTELT, nicht geraten. Bis 1.0.3 stand hier ein
    // Rueckfall auf "saugrobo", sobald config/plugins/<ordner> noch fehlte -
    // etwa im Augenblick der Installation. Haengt LoxBerry bei einer
    // Zweitinstallation einen Zaehler an (saugrobo_01, weil der Name schon
    // belegt war), schrieb diese Zweitinstallation dann in die Konfiguration
    // der ersten. Diese Datei liegt immer im Plugin-Ordner, ihr eigener
    // Ablageort ist also die verlaessliche Auskunft.
    // Der feste Name greift nur noch dort, wo der ermittelte NACHWEISLICH
    // kein Plugin-Ordner sein kann: aus dem ausgepackten Archiv heraus
    // heisst der Ordner "html". Installiert heisst er wie das Plugin.
    $pd = getenv('LBPPLUGINDIR');
    if (!$pd) { $pd = basename(__DIR__); }
    if ($pd === '' || $pd === '.' || $pd === '/' || $pd === 'html') { $pd = 'saugrobo'; }
    if ($lb) {
        return array('config' => $lb . '/config/plugins/' . $pd . '/robo.json',
                     'backup' => $lb . '/config/plugins/' . $pd . '.backup.json',
                     'log' => $lb . '/log/plugins/' . $pd . '/robo.log',
                     'data' => $lb . '/data/plugins/' . $pd,
                     'tmp' => '/tmp/saugrobo', 'lbhome' => $lb);
    }
    return array('config' => dirname(dirname(__DIR__)) . '/config/robo.json',
                 'backup' => dirname(dirname(__DIR__)) . '/config/robo.backup.json',
                 'log' => sys_get_temp_dir() . '/saugrobo/robo.log',
                 'data' => sys_get_temp_dir() . '/saugrobo/data',
                 'tmp' => sys_get_temp_dir() . '/saugrobo', 'lbhome' => '');
}

function ro_config() {
    $p = ro_paths();
    if ((!is_file($p['config']) || trim((string) @file_get_contents($p['config'])) === '' || trim((string) @file_get_contents($p['config'])) === '{}') && is_file($p['backup'])) {
        @mkdir(dirname($p['config']), 0775, true);
        @copy($p['backup'], $p['config']);
    }
    $cfg = is_file($p['config']) ? (json_decode((string) file_get_contents($p['config']), true) ?: array()) : array();
    if (!is_array($cfg)) { $cfg = array(); }
    $cfg += array(
        'robots' => array(),         // [{name, ip, port}]
        'cache_sec' => 20,           // Status-Cache (schuetzt den Roboter)
        'warn_hours' => 10,          // Warnschwelle Verbrauchsmaterial in Stunden
        'mqtt_enabled' => 0,
        'mqtt_topic' => 'saugrobo',
        'notify' => array(),
        'tts' => array(),
        'aktionstoken' => '',        // schuetzt ?cmd= (unangemeldeter Endpunkt)
    );
    if (!is_array($cfg['robots'])) { $cfg['robots'] = array(); }
    // Migration: Einzel-IP aus einer aelteren Fassung
    if (empty($cfg['robots']) && !empty($cfg['ip'])) {
        $cfg['robots'] = array(array('name' => 'Saugroboter', 'ip' => (string) $cfg['ip'], 'port' => 80));
    }
    if (!is_array($cfg['notify'])) { $cfg['notify'] = array(); }
    if (!is_array($cfg['tts'])) { $cfg['tts'] = array(); }
    $cfg['notify'] += array('audio' => 0, 'push' => 0, 'fertig' => 1, 'fehler' => 1, 'material' => 1);
    $cfg['tts'] += array('mode' => 'musicserver', 'ip' => '', 'port' => 7091,
                         'zones' => '1', 'volume' => 8, 'lang' => 'de', 'template' => '');
    return $cfg;
}

function ro_robots() {
    $cfg = ro_config();
    $out = array(); $n = 0;
    foreach ((array) $cfg['robots'] as $r) {
        $r = (array) $r;
        if (trim((string) (isset($r['ip']) ? $r['ip'] : '')) === '') { continue; }
        $n++;
        $out[$n] = array('name' => trim((string) (isset($r['name']) ? $r['name'] : '')) !== '' ? trim((string) $r['name']) : ('Saugroboter ' . $n),
                         'ip' => trim((string) $r['ip']),
                         'port' => max(1, min(65535, (int) (isset($r['port']) ? $r['port'] : 80))));
    }
    return $out;
}
function ro_robot($n) {
    $r = ro_robots(); $n = max(1, (int) $n);
    return isset($r[$n]) ? $r[$n] : null;
}

/**
 * Zufallstoken fuer die schaltenden Aufrufe (?cmd=).
 *
 * Der Endpunkt liegt im unangemeldeten Bereich, damit Loxone ihn ohne
 * Zugangsdaten erreicht. Ohne Token koennte jedes Geraet im Netz den
 * Roboter fernsteuern.
 */
function ro_token_erzeugen($laenge = 24) {
    $zeichen = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) {
        $t .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    return $t;
}
function ro_tmpdir() { $p = ro_paths(); if (!is_dir($p['tmp'])) { @mkdir($p['tmp'], 0775, true); } return $p['tmp']; }
function ro_datadir() { $p = ro_paths(); if (!is_dir($p['data'])) { @mkdir($p['data'], 0775, true); } return $p['data']; }

function ro_log($msg) {
    $p = ro_paths(); $f = $p['log'];
    if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0775, true); }
    clearstatcache(true, $f);
    if (is_file($f) && filesize($f) > 512000) {
        // Auch das Kuerzen unteilbar: sonst liest die Oberflaeche gerade
        // dieselbe Datei fuer den Reiter Protokoll.
        ro_write_atomic($f, implode("\n", ro_log_tail($f, 200)) . "\n");
    }
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}
function ro_log_if_changed($key, $line) {
    $f = ro_tmpdir() . '/last_' . $key . '.txt';
    $prev = is_file($f) ? (string) file_get_contents($f) : '';
    if ($line !== $prev) { ro_log($key . ': ' . $line); @file_put_contents($f, $line); }
}

/* ---------------- HTTP ---------------- */

/* ==================================================================
 * Zeitgrenzen - und warum ein stummer Roboter gemerkt wird
 * ==================================================================
 *
 * ro_state() holt VIER Dinge nacheinander: Zustand, laufende Statistik,
 * Gesamtstatistik und Verbrauchsmaterial. Bis 1.0.2 wartete jeder dieser
 * Abrufe 6 Sekunden. Nachgemessen gegen ein Gegenstueck, das die Verbindung
 * annimmt und dann schweigt (der schlimmste Fall - ein abgeschaltetes Geraet
 * weist die Verbindung sofort ab und kostet nichts):
 *
 *     ein einzelner ro_get()          6,0 s
 *     ro_state() (vier Abrufe)       24,0 s
 *     robo.php - was Loxone sieht    24,1 s
 *     ro_events_check(), 2 Roboter   48,1 s
 *
 * Die 24,1 Sekunden in robo.php sind der eigentliche Schaden: Ein
 * Loxone-Miniserver bricht einen virtuellen HTTP-Eingang nach wenigen
 * Sekunden ab - er bekommt gar nichts, waehrend auf dem LoxBerry ein
 * Arbeiter blockiert ist. Und der minuetliche Cron braucht mit zwei
 * Robotern laenger als eine Minute; der naechste Lauf startet, bevor der
 * vorige fertig ist.
 *
 * Drei Aenderungen:
 *   1. Zeitgrenze 6 -> 2 Sekunden. Valetudo antwortet im eigenen Netz in
 *      Millisekunden; wer zwei Sekunden braucht, ist nicht da.
 *   2. Nach einem gescheiterten ERSTEN Abruf werden die drei uebrigen gar
 *      nicht mehr versucht.
 *   3. Ein Merker "antwortet gerade nicht". Solange er steht, kehrt ro_get()
 *      sofort zurueck, statt erneut zu warten.
 * ================================================================== */

/** Wie lange ein stummer Roboter als stumm gilt, in Sekunden. */
define('RO_STUMM_SEK', 60);

function ro_stumm($dev) {
    $f = ro_tmpdir() . '/stumm_' . (int) $dev;
    return (is_file($f) && time() - filemtime($f) < RO_STUMM_SEK) ? 1 : 0;
}
function ro_stumm_setzen($dev) { @touch(ro_tmpdir() . '/stumm_' . (int) $dev); }
function ro_stumm_loeschen($dev) { @unlink(ro_tmpdir() . '/stumm_' . (int) $dev); }

/**
 * Eine Datei unteilbar schreiben: Nebendatei, dann umbenennen.
 *
 * Der Cron schreibt den Zwischenspeicher, waehrend Loxone ueber robo.php
 * liest. file_put_contents kuerzt die Datei zuerst auf null - der Leser
 * bekommt dann eine halbe oder leere Datei und damit kaputtes JSON.
 * rename() ist innerhalb eines Dateisystems unteilbar.
 */
function ro_write_atomic($datei, $inhalt) {
    if ($inhalt === false || $inhalt === null) { return false; }
    $inhalt = (string) $inhalt;
    $ordner = dirname($datei);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) { return false; }
    $tmp = $datei . '.' . getmypid() . '.' . mt_rand(1000, 9999) . '.tmp';
    if (@file_put_contents($tmp, $inhalt) !== strlen($inhalt)) { @unlink($tmp); return false; }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $datei)) { @unlink($tmp); return false; }
    return true;
}
function ro_write_json($datei, $daten) {
    return ro_write_atomic($datei, json_encode($daten));
}

/**
 * Die letzten $max Zeilen einer Datei - ohne sie ganz einzulesen.
 *
 * Der oft empfohlene Weg ueber das Programm "tail" spart zwar Speicher,
 * ist aber wegen des zusaetzlichen Prozesses LANGSAMER als das, was er
 * ersetzen soll. An einer 522-kB-Datei gemessen, 200 Zeilen Ausgabe:
 *
 *     file() + array_reverse   0,8 ms   1436 KB
 *     exec("tail -n 200")      1,7 ms     34 KB
 *     rueckwaerts mit fseek    0,3 ms     34 KB
 */
function ro_log_tail($datei, $max = 200, $block = 8192) {
    $fp = @fopen($datei, 'rb');
    if (!$fp) { return array(); }
    fseek($fp, 0, SEEK_END);
    $rest = ftell($fp);
    $puffer = '';
    while ($rest > 0 && substr_count($puffer, "\n") <= $max) {
        $lese = (int) min($block, $rest);
        $rest -= $lese;
        fseek($fp, $rest, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
    }
    fclose($fp);
    $zeilen = preg_split('/\R/', $puffer, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($zeilen) ? array_slice($zeilen, -$max) : array();
}

/**
 * Ein GET an die Valetudo-Schnittstelle.
 *
 * $dev ist nur fuer den Stumm-Merker da; wird es nicht uebergeben, wird
 * nichts gemerkt (etwa beim Verbindungstest in der Oberflaeche, der bewusst
 * jedes Mal wirklich fragen soll).
 */
function ro_get($url, $tmo = 2, $dev = 0) {
    if ($dev > 0 && ro_stumm($dev)) { return false; }
    $ctx = stream_context_create(array('http' => array('timeout' => $tmo, 'user_agent' => 'LoxBerry Saugroboter',
        'header' => "Accept: application/json\r\n", 'ignore_errors' => true)));
    $r = @file_get_contents($url, false, $ctx);
    if ($dev > 0) {
        if ($r === false) { ro_stumm_setzen($dev); } else { ro_stumm_loeschen($dev); }
    }
    return $r;
}
/* Befehle duerfen etwas laenger dauern als eine Abfrage - der Roboter
   quittiert erst, wenn er den Auftrag angenommen hat. Vier Sekunden reichen
   dafuer; acht waren zu grosszuegig, weil auch ein Befehl aus der
   Oberflaeche den Anwender warten laesst. */
function ro_put($url, $payload, $tmo = 4) {
    $body = json_encode($payload);
    $ctx = stream_context_create(array('http' => array(
        'method' => 'PUT', 'timeout' => $tmo, 'content' => $body, 'ignore_errors' => true,
        'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n")));
    $r = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
    return array($code, $r === false ? '' : (string) $r);
}

/* ---------------- Statusabfrage ---------------- */

/** Valetudo-Statustext -> Zahl fuer Loxone. */
function ro_state_code($txt) {
    switch (strtolower((string) $txt)) {
        case 'docked': return 0;
        case 'idle': return 1;
        case 'cleaning': return 2;
        case 'paused': return 3;
        case 'returning': return 4;
        case 'moving': case 'manual_control': return 5;
        case 'error': return 9;
    }
    return 8; // unbekannt
}
function ro_state_text($code) {
    $t = array(0 => 'in der Ladestation', 1 => 'bereit', 2 => 'reinigt', 3 => 'pausiert',
               4 => 'faehrt zur Ladestation', 5 => 'faehrt', 8 => 'unbekannt', 9 => 'Fehler');
    return isset($t[$code]) ? $t[$code] : 'unbekannt';
}

/** Wert aus der Valetudo-Attributliste holen. */
function ro_attr($list, $class, $extra = array()) {
    foreach ((array) $list as $a) {
        if (!isset($a['__class']) || $a['__class'] !== $class) { continue; }
        $ok = true;
        foreach ($extra as $k => $v) {
            if (!isset($a[$k]) || $a[$k] !== $v) { $ok = false; break; }
        }
        if ($ok) { return $a; }
    }
    return null;
}

/** Kompletter Zustand eines Roboters (mit Cache). */
function ro_state($dev = 1, $force = false) {
    $cfg = ro_config();
    $dev = max(1, (int) $dev);
    $r = ro_robot($dev);
    $cache = ro_tmpdir() . '/state_' . $dev . '.json';
    if (!$force && is_file($cache) && time() - filemtime($cache) < max(5, (int) $cfg['cache_sec'])) {
        $c = json_decode((string) file_get_contents($cache), true);
        if (is_array($c)) { return $c; }
    }
    $st = array('ok' => 0, 'name' => $r ? $r['name'] : '-', 'code' => 8, 'text' => 'unbekannt',
                'batterie' => 0, 'laedt' => 0, 'fehler' => 0, 'fehlertext' => '',
                'flaeche' => 0, 'dauer' => 0, 'letzte' => 0,
                'flaeche_gesamt' => 0, 'dauer_gesamt' => 0, 'anzahl_gesamt' => 0,
                'buerste_haupt' => -1, 'buerste_seite' => -1, 'filter' => -1, 'sensor' => -1,
                'material_warn' => 0, 'ts' => time());
    if ($r === null) {
        return $st;
    }
    $base = 'http://' . $r['ip'] . ':' . $r['port'] . '/api/v2/robot';
    // 1) Status
    $j = @json_decode((string) ro_get($base . '/state', 2, $dev), true);
    if (is_array($j) && isset($j['attributes'])) {
        $st['ok'] = 1;
        $s = ro_attr($j['attributes'], 'StatusStateAttribute');
        if ($s) {
            $st['code'] = ro_state_code(isset($s['value']) ? $s['value'] : '');
            $st['text'] = ro_state_text($st['code']);
            if (!empty($s['error']['message'])) { $st['fehlertext'] = (string) $s['error']['message']; }
            if (isset($s['metaData']['error_code'])) { $st['fehler'] = (int) $s['metaData']['error_code']; }
            if ($st['code'] === 9 && $st['fehler'] === 0) { $st['fehler'] = 1; }
        }
        $b = ro_attr($j['attributes'], 'BatteryStateAttribute');
        if ($b) {
            $st['batterie'] = (int) (isset($b['level']) ? $b['level'] : 0);
            $st['laedt'] = (isset($b['flag']) && $b['flag'] === 'charging') ? 1 : 0;
        }
    }
    /* Kam schon der Zustand nicht, sind die drei folgenden Abrufe
       verlorene Zeit: Gemessen waren das 24 s statt 6 je Roboter. Der
       Zwischenspeicher wird trotzdem geschrieben, damit die naechste
       Abfrage nicht sofort wieder wartet. */
    if ($st['ok'] !== 1) {
        ro_write_json($cache, $st);
        ro_log_if_changed('status_' . $dev, 'Status=' . $st['text'] . ' (nicht erreichbar)');
        return $st;
    }

    // 2) Statistik aktuell
    $j = @json_decode((string) ro_get($base . '/capabilities/CurrentStatisticsCapability', 2, $dev), true);
    foreach ((array) $j as $e) {
        if (!isset($e['type'])) { continue; }
        if ($e['type'] === 'area') { $st['flaeche'] = round(((float) $e['value']) / 10000, 1); }   // cm2 -> m2
        if ($e['type'] === 'time') { $st['dauer'] = (int) round(((float) $e['value']) / 60); }      // s -> min
    }
    // 3) Statistik gesamt
    $j = @json_decode((string) ro_get($base . '/capabilities/TotalStatisticsCapability', 2, $dev), true);
    foreach ((array) $j as $e) {
        if (!isset($e['type'])) { continue; }
        if ($e['type'] === 'area') { $st['flaeche_gesamt'] = round(((float) $e['value']) / 10000, 1); }
        if ($e['type'] === 'time') { $st['dauer_gesamt'] = round(((float) $e['value']) / 3600, 1); } // s -> h
        if ($e['type'] === 'count') { $st['anzahl_gesamt'] = (int) $e['value']; }
    }
    // 4) Verbrauchsmaterialien (Restlaufzeit in Stunden)
    $j = @json_decode((string) ro_get($base . '/capabilities/ConsumableMonitoringCapability', 2, $dev), true);
    foreach ((array) $j as $e) {
        $typ = isset($e['type']) ? $e['type'] : '';
        $sub = isset($e['subType']) ? $e['subType'] : '';
        $h = isset($e['remaining']['value']) ? (int) $e['remaining']['value'] : null;
        if ($h === null) { continue; }
        if ($typ === 'brush' && $sub === 'main') { $st['buerste_haupt'] = $h; }
        elseif ($typ === 'brush') { $st['buerste_seite'] = $h; }
        elseif ($typ === 'filter') { $st['filter'] = $h; }
        elseif ($typ === 'sensor') { $st['sensor'] = $h; }
    }
    $warn = max(0, (int) $cfg['warn_hours']);
    foreach (array('buerste_haupt', 'buerste_seite', 'filter', 'sensor') as $k) {
        if ($st[$k] >= 0 && $st[$k] <= $warn) { $st['material_warn'] = 1; }
    }
    // Zeitpunkt der letzten Reinigung merken (Wechsel von "reinigt" auf etwas anderes)
    $lastf = ro_datadir() . '/last_' . $dev . '.json';
    $prev = is_file($lastf) ? (json_decode((string) file_get_contents($lastf), true) ?: array()) : array();
    $st['letzte'] = isset($prev['letzte']) ? (int) $prev['letzte'] : 0;
    $prevcode = isset($prev['code']) ? (int) $prev['code'] : -1;
    if ($prevcode === 2 && $st['code'] !== 2 && $st['code'] !== 3) {
        $st['letzte'] = time();
        ro_write_json($lastf, array('code' => $st['code'], 'letzte' => $st['letzte']));
        ro_log('Reinigung beendet (' . $st['name'] . '): ' . $st['flaeche'] . ' m2 in ' . $st['dauer'] . ' min');
    } elseif ($prevcode !== $st['code']) {
        ro_write_json($lastf, array('code' => $st['code'], 'letzte' => $st['letzte']));
    }
    ro_write_json($cache, $st);
    ro_log_if_changed('status_' . $dev, 'Status=' . $st['text'] . ' Batterie=' . $st['batterie']
        . '% Fehler=' . $st['fehler'] . ' Material-Warnung=' . $st['material_warn']);
    return $st;
}

/* ---------------- Steuerung ---------------- */

/**
 * Steuerbefehl an den Roboter. Valetudo erwartet PUT mit JSON - das Plugin macht
 * daraus einen einfachen GET-Aufruf, den Loxone direkt als virtuellen Ausgang
 * senden kann.
 */
function ro_command($cmd, $dev = 1, $param = '') {
    $r = ro_robot($dev);
    if ($r === null) { return array(0, 'Roboter nicht konfiguriert'); }
    $base = 'http://' . $r['ip'] . ':' . $r['port'] . '/api/v2/robot/capabilities/';
    $cmd = strtolower(trim((string) $cmd));
    switch ($cmd) {
        case 'start': case 'stop': case 'pause': case 'home':
            $a = array('start' => 'start', 'stop' => 'stop', 'pause' => 'pause', 'home' => 'home');
            list($code, $body) = ro_put($base . 'BasicControlCapability', array('action' => $a[$cmd]));
            break;
        case 'locate':
            list($code, $body) = ro_put($base . 'LocateCapability', array('action' => 'locate'));
            break;
        case 'segments': // Raeume reinigen, z. B. param=1,4
            $ids = array();
            foreach (explode(',', (string) $param) as $s) {
                $s = trim($s);
                if ($s !== '') { $ids[] = $s; }
            }
            if (!$ids) { return array(0, 'keine Raum-IDs angegeben'); }
            list($code, $body) = ro_put($base . 'MapSegmentationCapability',
                array('action' => 'start_segment_action', 'segment_ids' => $ids, 'iterations' => 1, 'customOrder' => true));
            break;
        case 'fan': // Saugstaerke: low|medium|high|max|turbo
            list($code, $body) = ro_put($base . 'FanSpeedControlCapability', array('name' => (string) $param));
            break;
        case 'goto': // gespeicherte Position, param = ID
            list($code, $body) = ro_put($base . 'GoToLocationCapability',
                array('action' => 'goto', 'goToLocationId' => (string) $param));
            break;
        default:
            return array(0, 'unbekannter Befehl');
    }
    $ok = ($code >= 200 && $code < 300) ? 1 : 0;
    ro_log('Befehl "' . $cmd . ($param !== '' ? ' ' . $param : '') . '" an ' . $r['name'] . ' -> HTTP ' . $code . ($ok ? '' : ' FEHLER ' . substr($body, 0, 120)));
    return array($ok, 'HTTP ' . $code);
}

/** Raumliste (Segmente) auslesen - fuer die Anleitung in der Oberflaeche. */
function ro_segments($dev = 1) {
    $r = ro_robot($dev);
    if ($r === null) { return array(); }
    $j = @json_decode((string) ro_get('http://' . $r['ip'] . ':' . $r['port'] . '/api/v2/robot/capabilities/MapSegmentationCapability'), true);
    $out = array();
    foreach ((array) $j as $e) {
        if (isset($e['id'])) {
            $out[(string) $e['id']] = isset($e['name']) ? (string) $e['name'] : ('Raum ' . $e['id']);
        }
    }
    return $out;
}

/* ---------------- MQTT ---------------- */

/**
 * Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.
 *
 * Das Gateway liest ZEILENWEISE. Ein Zeilenumbruch im Wert - aus einer
 * Fehlermeldung des Betriebssystems, einem Geraetenamen oder der Ausgabe
 * eines Systembefehls - zerlegt die Uebertragung, und aus den Bruchstuecken
 * bildet das Gateway erfundene Themen. Ein Tabulator schadet ebenso, weil
 * Leerzeichen Thema und Wert trennt.
 */
function ro_mqtt_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

function ro_mqtt_publish($st = null, $dev = 1) {
    $cfg = ro_config();
    if (empty($cfg['mqtt_enabled'])) { return; }
    $p = ro_paths();
    if ($p['lbhome'] === '') { return; }
    if ($st === null) { $st = ro_state($dev); }
    $gen = @json_decode((string) @file_get_contents($p['lbhome'] . '/config/system/general.json'), true);
    $udp = 0;
    if (isset($gen['Mqtt']['Udpinport'])) { $udp = (int) $gen['Mqtt']['Udpinport']; }
    if (!$udp && isset($gen['mqtt']['udpinport'])) { $udp = (int) $gen['mqtt']['udpinport']; }
    if (!$udp) { return; }
    $prefix = trim((string) $cfg['mqtt_topic']) !== '' ? trim((string) $cfg['mqtt_topic']) : 'saugrobo';
    if ((int) $dev > 1) { $prefix .= '/' . (int) $dev; }
    $m = array('ok' => $st['ok'], 'code' => $st['code'], 'status' => $st['text'], 'batterie' => $st['batterie'],
               'laedt' => $st['laedt'], 'fehler' => $st['fehler'], 'flaeche' => $st['flaeche'], 'dauer' => $st['dauer'],
               'flaeche_gesamt' => $st['flaeche_gesamt'], 'dauer_gesamt' => $st['dauer_gesamt'],
               'anzahl_gesamt' => $st['anzahl_gesamt'], 'filter' => $st['filter'],
               'buerste_haupt' => $st['buerste_haupt'], 'buerste_seite' => $st['buerste_seite'],
               'sensor' => $st['sensor'], 'material_warn' => $st['material_warn']);
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$s) { return; }
    foreach ($m as $k => $v) {
        $msg = 'publish ' . $prefix . '/' . $k . ' ' . ro_mqtt_wert_saeubern($v);
        @socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $udp);
    }
    socket_close($s);
}

/* ---------------- Ansage (TTS) ---------------- */

function ro_tts_url($text) {
    $cfg = ro_config(); $tts = $cfg['tts']; $mode = $tts['mode'];
    if ($mode === 'audioserver') { return null; }
    if ((string) $tts['ip'] === '') { return ''; }
    if ($mode === 'musicserver') {
        $vol = max(1, min(100, (int) $tts['volume']));
        $zones = array();
        foreach (explode(',', (string) $tts['zones']) as $z) {
            $z = trim($z);
            if ($z === '') { continue; }
            $zones[] = (strpos($z, '~') === false) ? $z . '~' . $vol : $z;
        }
        $zoneStr = $zones ? implode(',', $zones) : '1~' . $vol;
        return 'http://' . $tts['ip'] . ':' . (int) $tts['port'] . '/audio/grouped/tts/' . $zoneStr . '/' . rawurlencode($tts['lang'] . '|' . $text);
    }
    $tpl = trim((string) $tts['template']);
    if ($tpl === '') { $tpl = 'http://{ip}:{port}/tts?text={text}&zone={zones}&vol={vol}'; }
    return str_replace(array('{ip}', '{port}', '{zones}', '{vol}', '{lang}', '{text}'),
        array($tts['ip'], (int) $tts['port'], $tts['zones'], (int) $tts['volume'], $tts['lang'], rawurlencode($text)), $tpl);
}
function ro_say($text) {
    $url = ro_tts_url($text);
    if ($url === null) { ro_log('Ansage: Modus Audioserver - Ausgabe ueber Loxone Config'); return false; }
    if ($url === '') { ro_log('Ansage uebersprungen: keine TTS-IP konfiguriert'); return false; }
    $r = ro_get($url, 10);
    ro_log('Ansage gesendet: "' . $text . '" -> ' . ($r !== false ? 'OK' : 'FEHLER'));
    return $r !== false;
}

/** Meldefenster fuer Loxone: 1 fuer 10 Minuten nach einem meldewuerdigen Ereignis. */
function ro_ann_active($dev = 1) {
    $f = ro_tmpdir() . '/ann_' . (int) $dev;
    return (is_file($f) && time() - filemtime($f) < 600) ? 1 : 0;
}
function ro_ptest_active() {
    $f = ro_tmpdir() . '/ptest';
    return (is_file($f) && time() - filemtime($f) < 300) ? 1 : 0;
}

/** Cron: Ereignisse erkennen (fertig, Fehler, Material) und melden. */
function ro_events_check() {
    $cfg = ro_config();
    foreach (ro_robots() as $n => $r) {
        $st = ro_state($n);
        $f = ro_tmpdir() . '/ev_' . $n . '.json';
        $prev = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: array()) : array();
        $meldung = '';
        // Reinigung beendet: von "reinigt" nach "faehrt zur Ladestation"/"in der Ladestation"
        if (!empty($cfg['notify']['fertig']) && isset($prev['code']) && (int) $prev['code'] === 2
            && in_array($st['code'], array(0, 1, 4), true)) {
            $meldung = $st['name'] . ' ist fertig. ' . str_replace('.', ',', (string) $st['flaeche'])
                     . ' Quadratmeter in ' . (int) $st['dauer'] . ' Minuten.';
        }
        // Fehler
        if (!empty($cfg['notify']['fehler']) && $st['code'] === 9 && (!isset($prev['code']) || (int) $prev['code'] !== 9)) {
            $meldung = 'Achtung: ' . $st['name'] . ' meldet einen Fehler'
                     . ($st['fehlertext'] !== '' ? ': ' . $st['fehlertext'] : '.') ;
        }
        // Verbrauchsmaterial (hoechstens einmal taeglich)
        if (!empty($cfg['notify']['material']) && $st['material_warn']) {
            $mf = ro_tmpdir() . '/mat_' . $n . '_' . date('Ymd');
            if (!is_file($mf)) {
                @file_put_contents($mf, '1');
                $teile = array();
                if ($st['filter'] >= 0 && $st['filter'] <= (int) $cfg['warn_hours']) { $teile[] = 'Filter'; }
                if ($st['buerste_haupt'] >= 0 && $st['buerste_haupt'] <= (int) $cfg['warn_hours']) { $teile[] = 'Hauptbuerste'; }
                if ($st['buerste_seite'] >= 0 && $st['buerste_seite'] <= (int) $cfg['warn_hours']) { $teile[] = 'Seitenbuerste'; }
                if ($st['sensor'] >= 0 && $st['sensor'] <= (int) $cfg['warn_hours']) { $teile[] = 'Sensoren'; }
                if ($teile) {
                    $meldung = $st['name'] . ': Wartung faellig - ' . implode(', ', $teile) . ' pruefen oder wechseln.';
                }
            }
        }
        if ($meldung !== '') {
            @touch(ro_tmpdir() . '/ann_' . $n);
            @file_put_contents(ro_tmpdir() . '/anntext_' . $n, $meldung);
            ro_log('Meldung: ' . $meldung);
            if (!empty($cfg['notify']['audio'])) {
                ro_say('Hallo! ' . $meldung);
            }
        }
        @file_put_contents($f, json_encode(array('code' => $st['code'], 'ts' => time())));
    }
    foreach (glob(ro_tmpdir() . '/mat_*') ?: array() as $g) {
        if (substr(basename($g), -8) !== date('Ymd')) { @unlink($g); }
    }
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini
 * immer vollstaendig sein.
 * ================================================================== */

function ro_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/**
 * Text zu einem Schluessel "ABSCHNITT.SCHLUESSEL".
 *
 * Ist der Schluessel unbekannt, wird er selbst zurueckgegeben - so faellt
 * beim Durchsehen sofort auf, was noch fehlt, statt dass die Seite leer
 * bleibt.
 */
function ro_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        // Installiert liegen die Dateien unter
        // <home>/templates/plugins/<ordner>/lang/ - der Ordnername ergibt
        // sich aus dem Ablageort dieser Datei.
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) { $home = $k; break; }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
            // Nicht installiert (Entwicklung): neben dem Plugin nachsehen.
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . ro_sprache() . '.ini',
                                 true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        // parse_ini_file mit INI_SCANNER_RAW liefert die Werte samt der
        // Anfuehrungszeichen zurueck, in die sie in der Datei stehen muessen.
        // Die gehoeren nicht in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}
