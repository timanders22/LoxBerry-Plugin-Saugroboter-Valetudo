<?php
/**
 * Saugroboter (Valetudo) - gemeinsame Bibliothek
 *
 * Fasst die Valetudo-Schnittstellen zu EINER Abfrage zusammen und liefert an
 * Loxone fertige Zahlenwerte - insbesondere einen numerischen Statuscode statt
 * der bisherigen Buchstaben-Bastelei. Zusaetzlich Steuerbefehle als einfache
 * GET-Aufrufe (Valetudo verlangt sonst PUT mit JSON-Rumpf).
 *
 * Keine persoenlichen Daten im Code - alles kommt aus der lokalen Konfiguration.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * ==================================================================
 * WOHER DIE SCHNITTSTELLENANGABEN STAMMEN (26.08.2026)
 * ==================================================================
 *
 * Bis 1.0.14 stammten sie aus zweiter Hand. Zwei Befehle haben deshalb NIE
 * funktioniert, ohne dass es jemand gemerkt haette:
 *
 *   ?cmd=fan   PUT .../FanSpeedControlCapability {"name":..}   -> HTTP 404
 *   ?cmd=goto  PUT .../GoToLocationCapability {"goToLocationId":..} -> HTTP 400
 *
 * Fuer 1.1.0 ist der Quelltext von Hypfer/Valetudo (Zweig master, 678 Dateien)
 * gelesen worden. Die Routen stehen in
 *   backend/lib/webserver/CapabilitiesRouter.js   (welche Faehigkeit welchen
 *                                                  Router bekommt)
 *   backend/lib/webserver/capabilityRouters/*.js  (die Routen selbst)
 * die Datengestalt in
 *   backend/lib/entities/state/attributes/*.js
 *   backend/lib/entities/core/ValetudoConsumable.js
 *
 * Jede Angabe in dieser Datei, die mit "Valetudo:" beginnt, ist dort belegt.
 * Was NICHT belegt ist: das Verhalten an einem echten Geraet. Zum Zeitpunkt
 * des Umbaus stand keines zur Verfuegung.
 * ==================================================================
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

/**
 * Der Plugin-Ordner - an EINER Stelle ermittelt.
 *
 * Bis 1.0.14 taten das drei Stellen auf drei verschiedene Weisen:
 *   ro_paths()       basename(__DIR__)              richtig
 *   ro_vorlage()     basename(dirname(__DIR__, 1))  ergibt installiert "plugins"
 *   ro_vo_vorlage()  fester Rueckfall 'saugrobo'    trifft die Zweitinstallation nicht
 *
 * Gemessen mit einem nachgebauten Installationsbaum und ohne gesetztes
 * LBPPLUGINDIR erzeugte die zweite Form die Adresse
 *     http://<host>/plugins/plugins/robo.php
 * also eine Adresse, die es nicht gibt.
 *
 * Diese Datei liegt IMMER im Plugin-Ordner (webfrontend/html/plugins/<ordner>/),
 * ihr eigener Ablageort ist also die verlaessliche Auskunft. Der feste Name
 * greift nur dort, wo der ermittelte NACHWEISLICH kein Plugin-Ordner sein kann:
 * aus dem ausgepackten Archiv heraus heisst der Ordner "html".
 */
function ro_plugin_ordner()
{
    $pd = getenv('LBPPLUGINDIR');
    if (!$pd) { $pd = basename(__DIR__); }
    if ($pd === '' || $pd === '.' || $pd === '/' || $pd === 'html'
        || $pd === 'htmlauth' || $pd === 'plugins' || $pd === 'webfrontend') {
        $pd = 'saugrobo';
    }
    return $pd;
}

function ro_paths() {
    $lb = getenv('LBHOMEDIR') ?: lb_wurzel_ermitteln();
    // Der Ordnername wird ERMITTELT, nicht geraten. Bis 1.0.3 stand hier ein
    // Rueckfall auf "saugrobo", sobald config/plugins/<ordner> noch fehlte -
    // etwa im Augenblick der Installation. Haengt LoxBerry bei einer
    // Zweitinstallation einen Zaehler an (saugrobo_01, weil der Name schon
    // belegt war), schrieb diese Zweitinstallation dann in die Konfiguration
    // der ersten.
    $pd = ro_plugin_ordner();
    if ($lb) {
        return array('config' => $lb . '/config/plugins/' . $pd . '/robo.json',
                     'backup' => $lb . '/config/plugins/' . $pd . '.backup.json',
                     'log' => $lb . '/log/plugins/' . $pd . '/robo.log',
                     'datadir' => $lb . '/data/plugins/' . $pd,
                     // Der Zwischenspeicher traegt den ERMITTELTEN Ordnernamen.
                     // Bis 1.0.14 stand hier fest '/tmp/saugrobo'; zwei
                     // Installationen teilten sich damit die Sperrdatei des
                     // Crons - dann laeuft je Minute nur EINER der beiden
                     // Durchgaenge -, dazu state_N.json, ev_N.json, stumm_N,
                     // ann_N und ptest.
                     //
                     // Die Sperrdatei ist hier bewusst UMSCHRIEBEN und nicht
                     // beim Namen genannt: attrappe_pruefen.py sucht die
                     // SDK-Namen mit einem Nicht-Wortzeichen davor und einer
                     // offenen Klammer dahinter. Der Dateiname der Sperre,
                     // gefolgt von einer Klammer, traf dieses Muster fuer den
                     // LoxBerry-Namen "lock" - in einem KOMMENTAR. Ein
                     // Fliesstext, der ein Pruefwerkzeug anschlagen laesst, ist
                     // kein Befund, kostet beim naechsten Mal aber wieder eine
                     // halbe Stunde. Die Vorlage warnt davor; ich bin beim
                     // Erklaeren der Falle prompt ein zweites Mal hineingelaufen.
                     'tmp' => '/tmp/' . $pd,
                     'plugin' => $pd, 'lbhome' => $lb);
    }
    return array('config' => dirname(dirname(__DIR__)) . '/config/robo.json',
                 'backup' => dirname(dirname(__DIR__)) . '/config/robo.backup.json',
                 'log' => sys_get_temp_dir() . '/' . $pd . '/robo.log',
                 'datadir' => sys_get_temp_dir() . '/' . $pd . '/data',
                 'tmp' => sys_get_temp_dir() . '/' . $pd,
                 'plugin' => $pd, 'lbhome' => '');
}

function ro_vorgaben()
{
    /* Die Vorgaben stehen an EINER abrufbaren Stelle. Die Sicherung
     * braucht die Schluesselliste, um Fremdes zu erkennen - ohne sie
     * koennte sie nur alles durchwinken. */
    return array(
    'robots' => array(),         // [{name, ip, port, user, pass}]
    'cache_sec' => 20,           // Status-Cache (schuetzt den Roboter)
    'warn_hours' => 10,          // Warnschwelle Verbrauchsmaterial in Stunden
    'warn_prozent' => 10,        // Warnschwelle fuer Teile, die Prozent melden
    'mqtt_enabled' => 0,
    'mqtt_topic' => 'saugrobo',
    'notify' => array(),
    'tts' => array(),
    'aktionstoken' => '',        // schuetzt ?cmd= (unangemeldeter Endpunkt)
);
}

/**
 * Die Konfiguration lesen.
 *
 * $erzeugen = false schaltet die Wiederherstellung aus der Sicherungskopie ab.
 * Der UNANGEMELDETE Endpunkt ruft sie so: gemessen am 26.08.2026 legte eine
 * einzige tokenlose Anfrage ?cmd=start den Konfigordner samt robo.json an,
 * weil ro_token_ok() ro_config() ruft und das die Datei wiederherstellte.
 * REGELN_2: "Der unangemeldete Endpunkt darf nichts schreiben."
 */
function ro_config($erzeugen = true) {
    $p = ro_paths();
    if ($erzeugen) {
        $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
        if (($roh === '' || $roh === '{}') && is_file($p['backup'])) {
            @mkdir(dirname($p['config']), 0775, true);
            @copy($p['backup'], $p['config']);
            @chmod($p['config'], 0640);
        }
    }
    $cfg = is_file($p['config']) ? (json_decode((string) @file_get_contents($p['config']), true) ?: array()) : array();
    if (!is_array($cfg)) { $cfg = array(); }
    $cfg += ro_vorgaben();
    if (!is_array($cfg['robots'])) { $cfg['robots'] = array(); }
    // Migration: Einzel-IP aus einer aelteren Fassung.
    // Der Altschluessel wird danach ENTFERNT. Bis 1.0.14 blieb er im Feld
    // stehen, wanderte in die Sicherungsdatei - und ro_sicherung_lesen()
    // lehnte die eigene Datei als "unbekannte Einstellung: ip" ab.
    if (!empty($cfg['ip'])) {
        if (empty($cfg['robots'])) {
            $cfg['robots'] = array(array('name' => 'Saugroboter', 'ip' => (string) $cfg['ip'], 'port' => 80));
        }
        unset($cfg['ip']);
    }
    if (!is_array($cfg['notify'])) { $cfg['notify'] = array(); }
    if (!is_array($cfg['tts'])) { $cfg['tts'] = array(); }
    $cfg['notify'] += array('audio' => 0, 'push' => 0, 'fertig' => 1, 'fehler' => 1,
                            'material' => 1, 'ereignis' => 1);
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
                         'port' => max(1, min(65535, (int) (isset($r['port']) ? $r['port'] : 80))),
                         // Valetudo kann eine Anmeldung verlangen (express-basic-auth,
                         // einzustellen unter /api/v2/valetudo/config/interfaces/http/auth/basic).
                         // Bis 1.0.14 gab es dafuer kein Feld: wer sie einschaltete,
                         // sah den Roboter als "nicht erreichbar".
                         'user' => trim((string) (isset($r['user']) ? $r['user'] : '')),
                         'pass' => (string) (isset($r['pass']) ? $r['pass'] : ''));
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

/* ==================================================================
 * Wachposten gegen fremde Absender
 * ==================================================================
 *
 * htmlauth/ schuetzt gegen den unangemeldeten Aufruf, NICHT dagegen, dass
 * der Browser eines ANGEMELDETEN Bedieners ein Formular abschickt, das auf
 * einer fremden Seite steht: die Anmeldung schickt er automatisch mit.
 *
 * Bis 1.0.14 gab es hier gar nichts. Ein fremdes Formular genuegte, um mit
 * "token_neu" saemtliche Loxone-Adressen unbrauchbar zu machen oder mit
 * "ro_zurueck" die ganze Konfiguration zu ersetzen.
 *
 * Das Merkmal wird aus dem Aktionstoken ABGELEITET, nicht zusaetzlich
 * gespeichert - es lebt damit genau so lange wie das Token und gehoert
 * ausdruecklich NICHT in die Sicherungsdatei.
 * ================================================================== */
function ro_formtoken($cfg = null)
{
    if ($cfg === null) { $cfg = ro_config(); }
    $grund = isset($cfg['aktionstoken']) ? (string) $cfg['aktionstoken'] : '';
    if ($grund === '') { return ''; }
    return hash_hmac('sha256', 'formular-v1', $grund);
}
function ro_formtoken_ok($cfg = null)
{
    $soll = ro_formtoken($cfg);
    $ist = isset($_POST['fmt']) && is_string($_POST['fmt']) ? (string) $_POST['fmt'] : '';
    return ($soll !== '' && hash_equals($soll, $ist));
}

function ro_tmpdir() { $p = ro_paths(); if (!is_dir($p['tmp'])) { @mkdir($p['tmp'], 0775, true); } return $p['tmp']; }
function ro_datadir() { $p = ro_paths(); if (!is_dir($p['datadir'])) { @mkdir($p['datadir'], 0775, true); } return $p['datadir']; }

function ro_log($msg) {
    $p = ro_paths(); $f = $p['log'];
    if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0775, true); }
    clearstatcache(true, $f);
    if (is_file($f) && filesize($f) > 512000) {
        // Auch das Kuerzen unteilbar: sonst liest die Oberflaeche gerade
        // dieselbe Datei fuer den Reiter Protokoll.
        ro_write_atomic($f, implode("\n", ro_log_tail($f, 200)) . "\n");
    }
    // Zeilenumbrueche im Text wuerden im Protokoll einen zweiten, echt
    // aussehenden Eintrag erzeugen. Gemessen am 26.08.2026 mit
    // ?cmd=fan&p=max%0A[2026-01-01 00:00:00] Befehl "home" ... - der
    // erfundene Eintrag stand danach im Reiter Protokoll.
    $msg = str_replace(array("\r\n", "\r", "\n"), ' ', (string) $msg);
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
 * ro_state() holt mehrere Dinge nacheinander. Bis 1.0.2 wartete jeder dieser
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
 * Arbeiter blockiert ist.
 *
 * Drei Aenderungen (1.0.3):
 *   1. Zeitgrenze 6 -> 2 Sekunden. Valetudo antwortet im eigenen Netz in
 *      Millisekunden; wer zwei Sekunden braucht, ist nicht da.
 *   2. Nach einem gescheiterten ERSTEN Abruf werden die uebrigen gar
 *      nicht mehr versucht.
 *   3. Ein Merker "antwortet gerade nicht". Solange er steht, kehrt ro_get()
 *      sofort zurueck, statt erneut zu warten.
 *
 * Nachgetragen 1.1.0: ro_segments() rief ro_get() OHNE $dev auf - damit griff
 * der Merker dort nicht, und einen Zwischenspeicher gab es fuer die Raumliste
 * gar nicht. Gemessen kostete ?json=1 dadurch JEDES MAL 2,1 s, ohne Token und
 * ohne Ende wiederholbar; mit &dev=2 waren es 4,2 s.
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
 *
 * Die Rechte werden VOR dem Inhalt gesetzt (REGELN_2, "Rechte gehoeren an das
 * Anlegen, nicht hinterher"): zwischen Anlegen und chmod stand die Datei sonst
 * kurz mit der Vorgabe der umask da - und in robo.json steht das Aktionstoken.
 */
function ro_write_atomic($datei, $inhalt, $rechte = 0640) {
    if ($inhalt === false || $inhalt === null) { return false; }
    $inhalt = (string) $inhalt;
    $ordner = dirname($datei);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) { return false; }
    $tmp = $datei . '.' . getmypid() . '.' . mt_rand(1000, 9999) . '.tmp';
    $fp = @fopen($tmp, 'wb');
    if ($fp === false) { return false; }
    @chmod($tmp, $rechte);
    $n = @fwrite($fp, $inhalt);
    @fclose($fp);
    if ($n !== strlen($inhalt)) { @unlink($tmp); return false; }
    if (!@rename($tmp, $datei)) { @unlink($tmp); return false; }
    return true;
}
function ro_write_json($datei, $daten, $rechte = 0644) {
    $js = json_encode($daten);
    if ($js === false) { return false; }
    return ro_write_atomic($datei, $js, $rechte);
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

/** Kopfzeilen fuer einen Abruf - mit Anmeldung, wenn eine eingetragen ist. */
function ro_kopfzeilen($r)
{
    $h = "Accept: application/json\r\n";
    if (is_array($r) && trim((string) (isset($r['user']) ? $r['user'] : '')) !== '') {
        $h .= 'Authorization: Basic '
            . base64_encode($r['user'] . ':' . (isset($r['pass']) ? $r['pass'] : '')) . "\r\n";
    }
    return $h;
}

/**
 * Ein GET an die Valetudo-Schnittstelle.
 *
 * $dev ist fuer den Stumm-Merker UND fuer die Anmeldung da; wird es nicht
 * uebergeben, wird nichts gemerkt (etwa beim Verbindungstest in der
 * Oberflaeche, der bewusst jedes Mal wirklich fragen soll).
 */
function ro_get($url, $tmo = 2, $dev = 0) {
    if ($dev > 0 && ro_stumm($dev)) { return false; }
    $kopf = "Accept: application/json\r\n";
    if ($dev > 0) { $kopf = ro_kopfzeilen(ro_robot($dev)); }
    $ctx = stream_context_create(array('http' => array('timeout' => $tmo, 'user_agent' => 'LoxBerry Saugroboter',
        'header' => $kopf, 'ignore_errors' => true)));
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
function ro_put($url, $payload, $tmo = 4, $r = null) {
    $body = json_encode($payload);
    // json_encode liefert bei ungueltigem UTF-8 false. strlen(false) ist 0,
    // und stream_context_create nimmt 'content' => false ohne Murren - der
    // PUT ginge mit leerem Rumpf und Content-Length: 0 hinaus.
    if ($body === false) { return array(0, 'ungueltige Zeichen im Parameter'); }
    $ctx = stream_context_create(array('http' => array(
        'method' => 'PUT', 'timeout' => $tmo, 'content' => $body, 'ignore_errors' => true,
        'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n"
                  . ($r !== null ? ro_kopfzeilen($r) : ''))));
    $antwort = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
    return array($code, $antwort === false ? '' : (string) $antwort);
}

/* ---------------- Statusabfrage ---------------- */

/** Valetudo-Statustext -> Zahl fuer Loxone.
 *  Valetudo: StatusStateAttribute.VALUE - acht Werte, alle abgedeckt. */
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

/** Valetudo: DockStatusStateAttribute.VALUE */
function ro_dock_code($txt) {
    switch (strtolower((string) $txt)) {
        case 'idle': return 0;
        case 'pause': return 1;
        case 'emptying': return 2;
        case 'cleaning': return 3;
        case 'drying': return 4;
        case 'error': return 9;
    }
    return -1;
}
/** Valetudo: PresetSelectionStateAttribute.INTENSITY */
function ro_stufe_code($txt) {
    $m = array('off' => 0, 'min' => 1, 'low' => 2, 'medium' => 3,
               'high' => 4, 'max' => 5, 'turbo' => 6, 'custom' => 7);
    $t = strtolower((string) $txt);
    return isset($m[$t]) ? $m[$t] : -1;
}
/** Valetudo: PresetSelectionStateAttribute.MODE */
function ro_modus_code($txt) {
    $m = array('vacuum' => 1, 'mop' => 2, 'vacuum_and_mop' => 3, 'vacuum_then_mop' => 4);
    $t = strtolower((string) $txt);
    return isset($m[$t]) ? $m[$t] : -1;
}
/** Valetudo: ValetudoRobotError.SEVERITY_LEVEL */
function ro_fstufe_code($txt) {
    $m = array('none' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'catastrophic' => 4);
    $t = strtolower((string) $txt);
    return isset($m[$t]) ? $m[$t] : -1;
}
/** Valetudo: ValetudoRobotError.SUBSYSTEM */
function ro_fteil_code($txt) {
    $m = array('none' => 0, 'core' => 1, 'power' => 2, 'sensors' => 3, 'motors' => 4,
               'navigation' => 5, 'attachments' => 6, 'dock' => 7);
    $t = strtolower((string) $txt);
    return isset($m[$t]) ? $m[$t] : -1;
}
/** Valetudo: die Klassen unter backend/lib/valetudo_events/events/ */
function ro_event_code($klasse) {
    $m = array('DustBinFullValetudoEvent' => 1,
               'ConsumableDepletedValetudoEvent' => 2,
               'MopAttachmentReminderValetudoEvent' => 3,
               'ErrorStateValetudoEvent' => 4,
               'PendingMapChangeValetudoEvent' => 5,
               'ValetudoUpdatedValetudoEvent' => 6,
               'ValetudoRuntimeErrorValetudoEvent' => 7);
    return isset($m[(string) $klasse]) ? $m[(string) $klasse] : 8;
}
function ro_event_text($code) {
    $t = array(0 => '', 1 => 'Staubbehaelter voll', 2 => 'Verbrauchsteil aufgebraucht',
               3 => 'Wischmodul pruefen', 4 => 'Stoerung', 5 => 'Karte hat sich geaendert',
               6 => 'Valetudo wurde aktualisiert', 7 => 'Fehler in Valetudo', 8 => 'unbekanntes Ereignis');
    return isset($t[$code]) ? $t[$code] : '';
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
/** ALLE Attribute einer Klasse - fuer die Anbauteile und die Stufen. */
function ro_attr_alle($list, $class) {
    $out = array();
    foreach ((array) $list as $a) {
        if (isset($a['__class']) && $a['__class'] === $class) { $out[] = $a; }
    }
    return $out;
}

/* ==================================================================
 * Verbrauchsmaterial - Einheit UND Untertyp
 * ==================================================================
 *
 * Bis 1.0.14 lautete die Zuordnung:
 *
 *     if ($typ === 'brush' && $sub === 'main') buerste_haupt
 *     elseif ($typ === 'brush')               buerste_seite
 *     elseif ($typ === 'filter')              filter
 *     elseif ($typ === 'sensor')              sensor
 *
 * und der Wert wurde als STUNDEN beschriftet. Gemessen gegen die Gestalt,
 * die Valetudos Roborock-Umsetzung liefert (Dock-Typ ULTRA):
 *
 *   Valetudo:  brush/main 18000 min = 300 h | brush/side_right 12000 min
 *              filter/main 9000 min | cleaning/sensor 1800 min
 *              brush/dock 87 % | filter/dock 64 % | bin/dock 41 %
 *
 *   Plugin:    BHAUPT 18000 "h" | BSEITE 87 "h" | FILTER 64 "h" | SENSOR -1
 *              MATWARN 0 bei Warnschwelle 10
 *
 * Vier Fehler in vier Zeilen:
 *   1. remaining.unit ist "minutes" oder "percent" - der Faktor 60 fehlte.
 *   2. "sensor" ist ein subType; der TYP heisst "cleaning". SENSOR war
 *      deshalb immer -1.
 *   3. Die Prozentwerte der Absaugstation ueberschrieben die Minutenwerte
 *      des Roboters, weil brush/dock in den brush-Zweig faellt.
 *   4. MaxVal="10000" in der Loxone-Vorlage klemmte die 18000 ab.
 *
 * Die Zuordnung ist jetzt ausgeschrieben. Was sie NICHT kennt, wird nicht
 * verschluckt, sondern gezaehlt und im Reiter Test genannt.
 * ================================================================== */
function ro_verbrauch_feld($typ, $sub)
{
    $t = strtolower((string) $typ);
    $s = strtolower((string) $sub);
    if ($s === '') { $s = 'none'; }
    $karte = array(
        'brush/main'        => 'buerste_haupt',
        'brush/none'        => 'buerste_haupt',
        'brush/all'         => 'buerste_haupt',
        'brush/side_right'  => 'buerste_seite',
        'brush/secondary'   => 'buerste_seite',
        'brush/side_left'   => 'buerste_seite2',
        'brush/dock'        => 'dock_buerste',
        'filter/main'       => 'filter',
        'filter/none'       => 'filter',
        'filter/all'        => 'filter',
        'filter/secondary'  => 'filter2',
        'filter/dock'       => 'dock_filter',
        'cleaning/sensor'   => 'sensor',
        'cleaning/wheel'    => 'raeder',
        'mop/all'           => 'mop',
        'mop/none'          => 'mop',
        'mop/main'          => 'mop',
        'detergent/dock'    => 'reiniger',
        'detergent/none'    => 'reiniger',
        'bin/dock'          => 'dock_behaelter',
        'bin/none'          => 'dock_behaelter',
    );
    $k = $t . '/' . $s;
    return isset($karte[$k]) ? $karte[$k] : '';
}

/** Welche Felder tragen Prozent statt Stunden? */
function ro_verbrauch_prozentfelder()
{
    return array('dock_buerste', 'dock_filter', 'dock_behaelter', 'reiniger');
}

/** Kompletter Zustand eines Roboters (mit Cache). */
function ro_state($dev = 1, $force = false) {
    $cfg = ro_config();
    $dev = max(1, (int) $dev);
    $r = ro_robot($dev);
    $cache = ro_tmpdir() . '/state_' . $dev . '.json';
    if (!$force && is_file($cache) && time() - filemtime($cache) < max(5, (int) $cfg['cache_sec'])) {
        $c = json_decode((string) @file_get_contents($cache), true);
        if (is_array($c)) { return $c; }
    }
    $st = array('ok' => 0, 'name' => $r ? $r['name'] : '-', 'code' => 8, 'text' => 'unbekannt',
                'batterie' => 0, 'laedt' => 0,
                'fehler' => 0, 'fehlertext' => '', 'fstufe' => -1, 'fteil' => -1,
                'flaeche' => 0, 'dauer' => 0, 'letzte' => 0,
                'flaeche_gesamt' => 0, 'dauer_gesamt' => 0, 'anzahl_gesamt' => 0,
                'buerste_haupt' => -1, 'buerste_seite' => -1, 'buerste_seite2' => -1,
                'filter' => -1, 'filter2' => -1, 'sensor' => -1, 'raeder' => -1, 'mop' => -1,
                'dock_buerste' => -1, 'dock_filter' => -1, 'dock_behaelter' => -1, 'reiniger' => -1,
                'material_warn' => 0, 'material_fremd' => array(),
                'behaelter' => -1, 'wassertank' => -1, 'wischer' => -1, 'dock' => -1,
                'saugstufe' => -1, 'wasserstufe' => -1, 'modus' => -1,
                'event' => 0, 'evtyp' => 0, 'evtext' => '', 'evmuell' => 0, 'evid' => '',
                'ts' => time());
    if ($r === null) {
        return $st;
    }
    $base = 'http://' . $r['ip'] . ':' . $r['port'] . '/api/v2/robot';
    // 1) Status - und mit ihm Anbauteile, Ladestation und die Stufen.
    //    Alle drei stecken in DERSELBEN Antwort und wurden bis 1.0.14
    //    weggeworfen; sie kosten keinen zusaetzlichen Abruf.
    $j = @json_decode((string) ro_get($base . '/state', 2, $dev), true);
    if (is_array($j) && isset($j['attributes'])) {
        $st['ok'] = 1;
        $s = ro_attr($j['attributes'], 'StatusStateAttribute');
        if ($s) {
            $st['code'] = ro_state_code(isset($s['value']) ? $s['value'] : '');
            $st['text'] = ro_state_text($st['code']);
            /* Der Fehlercode kommt aus error.vendorErrorCode, NICHT aus
             * metaData.error_code. Letzteres gibt es in Valetudo nicht: das
             * metaData der StatusStateAttribute traegt im gesamten Quelltext
             * genau zwei Schluessel, "zoned" und "segment_cleaning". Bis
             * 1.0.14 war FEHLER deshalb immer 0 oder 1, waehrend die Vorlage
             * ihn als 0..10000 beschrieb. */
            if (isset($s['error']) && is_array($s['error'])) {
                if (!empty($s['error']['message'])) { $st['fehlertext'] = (string) $s['error']['message']; }
                if (isset($s['error']['vendorErrorCode'])) {
                    $st['fehler'] = (int) preg_replace('/[^0-9]/', '', (string) $s['error']['vendorErrorCode']);
                }
                if (isset($s['error']['severity']['level'])) {
                    $st['fstufe'] = ro_fstufe_code($s['error']['severity']['level']);
                }
                if (isset($s['error']['subsystem'])) {
                    $st['fteil'] = ro_fteil_code($s['error']['subsystem']);
                }
            }
            if ($st['code'] === 9 && $st['fehler'] === 0) { $st['fehler'] = 1; }
        }
        $b = ro_attr($j['attributes'], 'BatteryStateAttribute');
        if ($b) {
            $st['batterie'] = (int) (isset($b['level']) ? $b['level'] : 0);
            $st['laedt'] = (isset($b['flag']) && $b['flag'] === 'charging') ? 1 : 0;
        }
        // Valetudo: AttachmentStateAttribute, type = dustbin | watertank | mop
        foreach (ro_attr_alle($j['attributes'], 'AttachmentStateAttribute') as $a) {
            $an = !empty($a['attached']) ? 1 : 0;
            $typ = isset($a['type']) ? strtolower((string) $a['type']) : '';
            if ($typ === 'dustbin')   { $st['behaelter'] = $an; }
            if ($typ === 'watertank') { $st['wassertank'] = $an; }
            if ($typ === 'mop')       { $st['wischer'] = $an; }
        }
        // Valetudo: DockStatusStateAttribute
        $d = ro_attr($j['attributes'], 'DockStatusStateAttribute');
        if ($d) { $st['dock'] = ro_dock_code(isset($d['value']) ? $d['value'] : ''); }
        // Valetudo: PresetSelectionStateAttribute, type = fan_speed | water_grade | operation_mode
        foreach (ro_attr_alle($j['attributes'], 'PresetSelectionStateAttribute') as $a) {
            $typ = isset($a['type']) ? strtolower((string) $a['type']) : '';
            $wert = isset($a['value']) ? $a['value'] : '';
            if ($typ === 'fan_speed')      { $st['saugstufe'] = ro_stufe_code($wert); }
            if ($typ === 'water_grade')    { $st['wasserstufe'] = ro_stufe_code($wert); }
            if ($typ === 'operation_mode') { $st['modus'] = ro_modus_code($wert); }
        }
    }
    /* Kam schon der Zustand nicht, sind die folgenden Abrufe verlorene Zeit:
       Gemessen waren das 24 s statt 6 je Roboter. Der Zwischenspeicher wird
       trotzdem geschrieben, damit die naechste Abfrage nicht sofort wieder
       wartet. */
    if ($st['ok'] !== 1) {
        ro_write_json($cache, $st);
        ro_log_if_changed('status_' . $dev, 'Status=' . $st['text'] . ' (nicht erreichbar)');
        return $st;
    }

    // 2) Statistik aktuell
    $j = @json_decode((string) ro_get($base . '/capabilities/CurrentStatisticsCapability', 2, $dev), true);
    foreach ((array) $j as $e) {
        if (!isset($e['type']) || !isset($e['value'])) { continue; }
        if ($e['type'] === 'area') { $st['flaeche'] = round(((float) $e['value']) / 10000, 1); }   // cm2 -> m2
        if ($e['type'] === 'time') { $st['dauer'] = (int) round(((float) $e['value']) / 60); }      // s -> min
    }
    // 3) Statistik gesamt
    $j = @json_decode((string) ro_get($base . '/capabilities/TotalStatisticsCapability', 2, $dev), true);
    foreach ((array) $j as $e) {
        if (!isset($e['type']) || !isset($e['value'])) { continue; }
        if ($e['type'] === 'area') { $st['flaeche_gesamt'] = round(((float) $e['value']) / 10000, 1); }
        if ($e['type'] === 'time') { $st['dauer_gesamt'] = round(((float) $e['value']) / 3600, 1); } // s -> h
        if ($e['type'] === 'count') { $st['anzahl_gesamt'] = (int) $e['value']; }
    }
    // 4) Verbrauchsmaterialien
    $j = @json_decode((string) ro_get($base . '/capabilities/ConsumableMonitoringCapability', 2, $dev), true);
    foreach ((array) $j as $e) {
        $typ = isset($e['type']) ? $e['type'] : '';
        $sub = isset($e['subType']) ? $e['subType'] : '';
        if (!isset($e['remaining']['value'])) { continue; }
        $wert = (float) $e['remaining']['value'];
        $einheit = isset($e['remaining']['unit']) ? strtolower((string) $e['remaining']['unit']) : 'minutes';
        $feld = ro_verbrauch_feld($typ, $sub);
        if ($feld === '') {
            // Nicht verschlucken - nennen. Der Reiter Test zeigt die Liste.
            $st['material_fremd'][] = $typ . '/' . ($sub !== '' ? $sub : 'none');
            continue;
        }
        if ($einheit === 'percent') {
            $st[$feld] = (int) round(max(0, min(100, $wert)));
        } else {
            // minutes -> Stunden, abgerundet. Wer 59 Minuten Restlaufzeit hat,
            // soll 0 sehen und nicht 1.
            $st[$feld] = (int) floor($wert / 60);
        }
    }
    $warn_h = max(0, (int) $cfg['warn_hours']);
    $warn_p = max(0, (int) $cfg['warn_prozent']);
    $prozent = ro_verbrauch_prozentfelder();
    foreach (array('buerste_haupt', 'buerste_seite', 'buerste_seite2', 'filter', 'filter2',
                   'sensor', 'raeder', 'mop', 'dock_buerste', 'dock_filter',
                   'dock_behaelter', 'reiniger') as $k) {
        $grenze = in_array($k, $prozent, true) ? $warn_p : $warn_h;
        if ($st[$k] >= 0 && $st[$k] <= $grenze) { $st['material_warn'] = 1; }
    }
    // 5) Valetudos Ereignisliste. Sie liegt NICHT unter /robot, sondern unter
    //    /valetudo - deshalb der eigene Aufbau der Adresse.
    $ev = @json_decode((string) ro_get('http://' . $r['ip'] . ':' . $r['port']
                                       . '/api/v2/valetudo/events', 2, $dev), true);
    if (is_array($ev)) {
        foreach ($ev as $e) {
            if (!is_array($e) || !empty($e['processed'])) { continue; }
            $st['event']++;
            $c = ro_event_code(isset($e['__class']) ? $e['__class'] : '');
            if ($c === 1) { $st['evmuell'] = 1; }
            // Der jueng(st)e offene Eintrag bestimmt EVTYP - die Liste kommt
            // in der Reihenfolge des Auftretens.
            $st['evtyp'] = $c;
            $st['evtext'] = ro_event_text($c);
            $st['evid'] = isset($e['id']) ? (string) $e['id'] : '';
        }
    }

    // Zeitpunkt der letzten Reinigung merken (Wechsel von "reinigt" auf etwas anderes)
    $lastf = ro_datadir() . '/last_' . $dev . '.json';
    $prev = is_file($lastf) ? (json_decode((string) @file_get_contents($lastf), true) ?: array()) : array();
    $st['letzte'] = isset($prev['letzte']) ? (int) $prev['letzte'] : 0;
    $prevcode = isset($prev['code']) ? (int) $prev['code'] : -1;
    /* Dieselbe Bedingung wie in ro_events_check(). Bis 1.0.14 standen hier
     * zwei verschiedene: ro_state() wertete JEDEN Wechsel weg von 2 aus
     * (ausser 3), ro_events_check() nur den nach 0/1/4. Beim Uebergang
     * "reinigt -> faehrt" schrieb das Protokoll deshalb "Reinigung beendet ...
     * in 0 min" und setzte den Zeitstempel, ohne dass eine Meldung herausging. */
    if ($prevcode === 2 && ro_reinigung_beendet($st['code'])) {
        $st['letzte'] = time();
        ro_write_json($lastf, array('code' => $st['code'], 'letzte' => $st['letzte']));
        ro_log('Reinigung beendet (' . $st['name'] . '): ' . $st['flaeche'] . ' m2 in ' . $st['dauer'] . ' min');
    } elseif ($prevcode !== $st['code']) {
        ro_write_json($lastf, array('code' => $st['code'], 'letzte' => $st['letzte']));
    }
    ro_write_json($cache, $st);
    ro_log_if_changed('status_' . $dev, 'Status=' . $st['text'] . ' Batterie=' . $st['batterie']
        . '% Fehler=' . $st['fehler'] . ' Material-Warnung=' . $st['material_warn']
        . ' Ereignisse=' . $st['event']);
    return $st;
}

/** Gilt eine Reinigung als beendet? EINE Bedingung fuer beide Aufrufer. */
function ro_reinigung_beendet($code)
{
    return in_array((int) $code, array(0, 1, 4), true);
}

/* ---------------- Steuerung ---------------- */

/**
 * Steuerbefehl an den Roboter. Valetudo erwartet PUT mit JSON - das Plugin macht
 * daraus einen einfachen GET-Aufruf, den Loxone direkt als virtuellen Ausgang
 * senden kann.
 *
 * Die Routen sind aus dem Valetudo-Quelltext uebernommen; die beiden Befehle,
 * die bis 1.0.14 ins Leere liefen, stehen im Kopf dieser Datei.
 */
function ro_command($cmd, $dev = 1, $param = '') {
    $r = ro_robot($dev);
    if ($r === null) { return array(0, 'Roboter nicht konfiguriert'); }
    $wurzel = 'http://' . $r['ip'] . ':' . $r['port'] . '/api/v2/';
    $base = $wurzel . 'robot/capabilities/';
    $cmd = strtolower(trim((string) $cmd));
    $param = (string) $param;
    switch ($cmd) {
        case 'start': case 'stop': case 'pause': case 'home':
            $a = array('start' => 'start', 'stop' => 'stop', 'pause' => 'pause', 'home' => 'home');
            list($code, $body) = ro_put($base . 'BasicControlCapability', array('action' => $a[$cmd]), 4, $r);
            break;
        case 'locate':
            list($code, $body) = ro_put($base . 'LocateCapability', array('action' => 'locate'), 4, $r);
            break;
        case 'segments': // Raeume reinigen, z. B. param=1,4  oder  1,4x2 (zwei Durchgaenge)
            $wdh = 1;
            if (preg_match('/^(.*?)x([1-9])$/', $param, $m)) { $param = $m[1]; $wdh = (int) $m[2]; }
            $ids = array();
            foreach (explode(',', $param) as $s) {
                $s = trim($s);
                if ($s !== '') { $ids[] = $s; }
            }
            if (!$ids) { return array(0, 'keine Raum-IDs angegeben'); }
            list($code, $body) = ro_put($base . 'MapSegmentationCapability',
                array('action' => 'start_segment_action', 'segment_ids' => $ids,
                      'iterations' => $wdh, 'customOrder' => true), 4, $r);
            break;
        case 'fan':    // Saugstaerke
        case 'wasser': // Wischwassermenge
        case 'modus':  // Betriebsart
            /* Valetudo haengt an alle drei Faehigkeiten den
             * PresetSelectionCapabilityRouter. Der kennt GET /presets und
             * PUT /preset - ein PUT auf die Wurzel trifft keine Route und
             * antwortet 404. Genau das tat das Plugin bis 1.0.14. */
            $faehig = array('fan' => 'FanSpeedControlCapability',
                            'wasser' => 'WaterUsageControlCapability',
                            'modus' => 'OperationModeControlCapability');
            if ($param === '') { return array(0, 'keine Stufe angegeben'); }
            list($code, $body) = ro_put($base . $faehig[$cmd] . '/preset',
                array('name' => $param), 4, $r);
            break;
        case 'goto': // Position anfahren, param = X,Y in Kartenkoordinaten
            /* Valetudo verlangt coordinates{x,y}. Der frueher benutzte
             * Schluessel goToLocationId kommt im gesamten Valetudo-Quelltext
             * nicht vor; gespeicherte Positionen wurden am 15.04.2022 entfernt
             * ("feat!: Remove ZonePresets and GoToLocationPresets"). */
            if (!preg_match('/^(-?\d+)\s*,\s*(-?\d+)$/', $param, $m)) {
                return array(0, 'goto braucht X,Y in Kartenkoordinaten');
            }
            list($code, $body) = ro_put($base . 'GoToLocationCapability',
                array('action' => 'goto', 'coordinates' => array('x' => (int) $m[1], 'y' => (int) $m[2])), 4, $r);
            break;
        case 'zone': // Zonenreinigung, param = X1,Y1,X2,Y2[xN]
            $wdh = 1;
            if (preg_match('/^(.*?)x([1-9])$/', $param, $m)) { $param = $m[1]; $wdh = (int) $m[2]; }
            if (!preg_match('/^(-?\d+),(-?\d+),(-?\d+),(-?\d+)$/', $param, $m)) {
                return array(0, 'zone braucht X1,Y1,X2,Y2 in Kartenkoordinaten');
            }
            $x1 = (int) $m[1]; $y1 = (int) $m[2]; $x2 = (int) $m[3]; $y2 = (int) $m[4];
            list($code, $body) = ro_put($base . 'ZoneCleaningCapability', array(
                'action' => 'clean', 'iterations' => $wdh,
                'zones' => array(array('points' => array(
                    'pA' => array('x' => $x1, 'y' => $y1),
                    'pB' => array('x' => $x2, 'y' => $y1),
                    'pC' => array('x' => $x2, 'y' => $y2),
                    'pD' => array('x' => $x1, 'y' => $y2))))), 4, $r);
            break;
        case 'reset': // Verbrauchsteil zuruecksetzen, param = filter/main
            if (!preg_match('#^([a-z]+)(?:/([a-z_]+))?$#', $param, $m)) {
                return array(0, 'reset braucht z. B. filter/main');
            }
            $pfad = $m[1] . (isset($m[2]) && $m[2] !== '' ? '/' . $m[2] : '');
            list($code, $body) = ro_put($base . 'ConsumableMonitoringCapability/' . $pfad,
                array('action' => 'reset'), 6, $r);
            break;
        case 'absaugen':
            list($code, $body) = ro_put($base . 'AutoEmptyDockManualTriggerCapability',
                array('action' => 'trigger'), 6, $r);
            break;
        case 'wischwaschen':
            list($code, $body) = ro_put($base . 'MopDockCleanManualTriggerCapability',
                array('action' => 'trigger'), 6, $r);
            break;
        case 'wischtrocknen':
            list($code, $body) = ro_put($base . 'MopDockDryManualTriggerCapability',
                array('action' => 'trigger'), 6, $r);
            break;
        case 'ruhezeit': // param = 22:00-07:00  oder  aus
            if (strtolower($param) === 'aus') {
                $alt = ro_ruhezeit($dev);
                $von = is_array($alt) ? $alt['start'] : array('hour' => 22, 'minute' => 0);
                $bis = is_array($alt) ? $alt['end'] : array('hour' => 7, 'minute' => 0);
                list($code, $body) = ro_put($base . 'DoNotDisturbCapability',
                    array('enabled' => false, 'start' => $von, 'end' => $bis), 4, $r);
                break;
            }
            if (!preg_match('/^([0-2]?\d):([0-5]\d)-([0-2]?\d):([0-5]\d)$/', $param, $m)) {
                return array(0, 'ruhezeit braucht HH:MM-HH:MM oder "aus"');
            }
            list($code, $body) = ro_put($base . 'DoNotDisturbCapability', array(
                'enabled' => true,
                'start' => array('hour' => min(23, (int) $m[1]), 'minute' => (int) $m[2]),
                'end'   => array('hour' => min(23, (int) $m[3]), 'minute' => (int) $m[4])), 4, $r);
            break;
        case 'evquittieren': // offenes Ereignis wegdruecken
            $st = ro_state($dev);
            $id = $param !== '' ? $param : (string) $st['evid'];
            if ($id === '' || !preg_match('/^[A-Za-z0-9\-]{1,64}$/', $id)) {
                return array(0, 'kein quittierbares Ereignis');
            }
            list($code, $body) = ro_put($wurzel . 'valetudo/events/' . rawurlencode($id) . '/interact',
                array('interaction' => 'ok'), 4, $r);
            break;
        default:
            return array(0, 'unbekannter Befehl');
    }
    $ok = ($code >= 200 && $code < 300) ? 1 : 0;
    ro_log('Befehl "' . $cmd . ($param !== '' ? ' ' . $param : '') . '" an ' . $r['name'] . ' -> HTTP ' . $code . ($ok ? '' : ' FEHLER ' . substr($body, 0, 120)));
    if ($ok) {
        // Der Zustand ist nach einem Befehl veraltet - sonst zeigt die
        // Oberflaeche bis zu cache_sec Sekunden lang den alten.
        @unlink(ro_tmpdir() . '/state_' . (int) $dev . '.json');
    }
    return array($ok, 'HTTP ' . $code);
}

/** Die Befehle, die ?cmd= annimmt - EINE Liste fuer Endpunkt, Vorlage und Anleitung. */
function ro_befehle()
{
    return array(
        'start'         => array('', 'Reinigung starten'),
        'stop'          => array('', 'Stoppen'),
        'pause'         => array('', 'Pausieren'),
        'home'          => array('', 'Zur Ladestation'),
        'locate'        => array('', 'Roboter piepsen lassen'),
        'segments'      => array('1,4', 'Nur bestimmte Raeume reinigen (IDs im Reiter Test; "1,4x2" = zwei Durchgaenge)'),
        'zone'          => array('2000,2000,3000,3000', 'Zone reinigen, X1,Y1,X2,Y2 in Kartenkoordinaten'),
        'goto'          => array('2500,1800', 'Position anfahren, X,Y in Kartenkoordinaten'),
        'fan'           => array('max', 'Saugstaerke: off, min, low, medium, high, max, turbo'),
        'wasser'        => array('low', 'Wischwassermenge: off, min, low, medium, high, max'),
        'modus'         => array('vacuum', 'Betriebsart: vacuum, mop, vacuum_and_mop, vacuum_then_mop'),
        'absaugen'      => array('', 'Absaugstation von Hand ausloesen'),
        'wischwaschen'  => array('', 'Wischmodul in der Station waschen'),
        'wischtrocknen' => array('', 'Wischmodul in der Station trocknen'),
        'reset'         => array('filter/main', 'Verbrauchsteil zuruecksetzen (filter/main, brush/main, brush/side_right, cleaning/sensor, mop/all)'),
        'ruhezeit'      => array('22:00-07:00', 'Nicht-stoeren-Zeit setzen; "aus" schaltet sie ab'),
        'evquittieren'  => array('', 'Offenes Valetudo-Ereignis wegdruecken'),
    );
}

/** Nicht-stoeren-Zeit lesen. Valetudo: DoNotDisturbCapability, GET / */
function ro_ruhezeit($dev = 1)
{
    $r = ro_robot($dev);
    if ($r === null) { return null; }
    $j = @json_decode((string) ro_get('http://' . $r['ip'] . ':' . $r['port']
        . '/api/v2/robot/capabilities/DoNotDisturbCapability', 2, $dev), true);
    return is_array($j) ? $j : null;
}

/**
 * Raumliste (Segmente) - mit Zwischenspeicher.
 *
 * Bis 1.0.14 rief diese Funktion ro_get() OHNE $dev: der Stumm-Merker griff
 * nicht, einen Zwischenspeicher gab es nicht, und die Oberflaeche wie der
 * unangemeldete Endpunkt warteten bei jedem Aufruf 2 Sekunden auf einen
 * Roboter, von dem laengst bekannt war, dass er schweigt.
 */
function ro_segments($dev = 1, $force = false) {
    $dev = max(1, (int) $dev);
    $cache = ro_tmpdir() . '/segments_' . $dev . '.json';
    if (!$force && is_file($cache) && time() - filemtime($cache) < 3600) {
        $c = json_decode((string) @file_get_contents($cache), true);
        if (is_array($c)) { return $c; }
    }
    $r = ro_robot($dev);
    if ($r === null) { return array(); }
    $j = @json_decode((string) ro_get('http://' . $r['ip'] . ':' . $r['port']
        . '/api/v2/robot/capabilities/MapSegmentationCapability', 2, $dev), true);
    $out = array();
    foreach ((array) $j as $e) {
        if (isset($e['id'])) {
            $out[(string) $e['id']] = isset($e['name']) ? (string) $e['name'] : ('Raum ' . $e['id']);
        }
    }
    // Auch eine leere Liste wird gemerkt - sonst fragt jeder Aufruf erneut.
    if (is_array($j)) { ro_write_json($cache, $out); }
    return $out;
}

/**
 * Welche Faehigkeiten hat DIESER Roboter?
 *
 * Valetudo: GET /api/v2/robot/capabilities liefert die Namensliste. Ohne sie
 * zeigt die Oberflaeche Knoepfe fuer Dinge, die das Geraet nicht kann, und der
 * Anwender sucht den Fehler bei sich.
 */
function ro_capabilities($dev = 1, $force = false) {
    $dev = max(1, (int) $dev);
    $cache = ro_tmpdir() . '/caps_' . $dev . '.json';
    if (!$force && is_file($cache) && time() - filemtime($cache) < 3600) {
        $c = json_decode((string) @file_get_contents($cache), true);
        if (is_array($c)) { return $c; }
    }
    $r = ro_robot($dev);
    if ($r === null) { return array(); }
    $j = @json_decode((string) ro_get('http://' . $r['ip'] . ':' . $r['port']
        . '/api/v2/robot/capabilities', 2, $dev), true);
    $out = array();
    foreach ((array) $j as $e) { if (is_string($e)) { $out[] = $e; } }
    if (is_array($j)) { ro_write_json($cache, $out); }
    return $out;
}
function ro_kann($dev, $faehigkeit) {
    $c = ro_capabilities($dev);
    // Eine leere Liste heisst "nicht feststellbar", nicht "kann nichts".
    return $c ? in_array($faehigkeit, $c, true) : null;
}

/** Steckbrief: Hersteller, Modell, Valetudo-Fassung. */
function ro_robotinfo($dev = 1, $force = false) {
    $dev = max(1, (int) $dev);
    $cache = ro_tmpdir() . '/info_' . $dev . '.json';
    if (!$force && is_file($cache) && time() - filemtime($cache) < 3600) {
        $c = json_decode((string) @file_get_contents($cache), true);
        if (is_array($c)) { return $c; }
    }
    $r = ro_robot($dev);
    if ($r === null) { return array(); }
    $wurzel = 'http://' . $r['ip'] . ':' . $r['port'] . '/api/v2/';
    $a = @json_decode((string) ro_get($wurzel . 'robot', 2, $dev), true);
    $b = @json_decode((string) ro_get($wurzel . 'valetudo/version', 2, $dev), true);
    $out = array(
        'hersteller' => is_array($a) && isset($a['manufacturer']) ? (string) $a['manufacturer'] : '',
        'modell'     => is_array($a) && isset($a['modelName']) ? (string) $a['modelName'] : '',
        'valetudo'   => is_array($b) && isset($b['release']) ? (string) $b['release'] : '',
    );
    if (is_array($a)) { ro_write_json($cache, $out); }
    return $out;
}

/* ---------------- Lebenszeichen ---------------- */

/* ==================================================================
 * Warum ein messendes Plugin ein Lebenszeichen braucht
 * ==================================================================
 *
 * Ein virtueller Eingang behaelt seinen letzten Wert, bei MQTT mit Retain
 * sogar ueber jeden Neustart des Miniservers hinweg. Faellt der Cron-Lauf
 * aus, steht in Loxone weiter "in der Ladestation, Batterie 100 %". Das ist
 * KEINE fehlende Auskunft, sondern eine Falschaussage - und sie sieht aus
 * wie eine richtige.
 *
 * ts geht bei JEDEM Durchgang hinaus, auch unveraendert. Der ZAEHLER
 * beantwortet, was der Zeitstempel nicht kann: ein Raspberry ohne
 * Echtzeituhr springt beim ersten Zeitabgleich; ein Alter kann danach
 * negativ oder stundenlang sein, obwohl alles laeuft. Eine umlaufende Zahl
 * nicht.
 * ================================================================== */
function ro_lauf_lesen()
{
    $f = ro_tmpdir() . '/lauf.json';
    $d = is_file($f) ? (json_decode((string) @file_get_contents($f), true) ?: array()) : array();
    if (!is_array($d)) { $d = array(); }
    $d += array('ts' => 0, 'zaehler' => 0, 'ok' => 0);
    return array('ts' => (int) $d['ts'], 'zaehler' => (int) $d['zaehler'], 'ok' => (int) $d['ok']);
}
function ro_lauf_setzen($ok)
{
    $a = ro_lauf_lesen();
    $neu = array('ts' => time(), 'zaehler' => ((int) $a['zaehler'] + 1) % 1000, 'ok' => $ok ? 1 : 0);
    ro_write_json(ro_tmpdir() . '/lauf.json', $neu);
    return $neu;
}
/** Alter des letzten Cron-Laufs in Sekunden; -1, wenn noch keiner lief. */
function ro_lauf_alter()
{
    $a = ro_lauf_lesen();
    return $a['ts'] > 0 ? max(0, time() - $a['ts']) : -1;
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

/**
 * Und dasselbe fuer das THEMA - das fehlte bis 1.0.14.
 *
 * Gemessen an einem Lauscher auf dem UDP-Eingang, nachdem eine Sicherung mit
 * "mqtt_topic": "saugrobo/x 1\npublish fremd/schalter 1" eingespielt war:
 *
 *   'publish saugrobo/x 1\npublish fremd/schalter 1/ok 0'
 *
 * Jedes Datagramm trug eine zweite publish-Zeile mit einem fremden Thema.
 * Ueber den Reiter MQTT war das nicht erreichbar - dort filtert das Formular -,
 * ueber eine zurueckgespielte Sicherung schon.
 */
function ro_mqtt_thema_saeubern($t)
{
    $t = preg_replace('#[^\w/\-]#', '', (string) $t);
    $t = trim((string) $t, '/');
    return $t !== '' ? $t : 'saugrobo';
}

/**
 * Ein UDP-Paket an den Gateway-Eingang.
 *
 * Mit stream_socket_client() statt socket_create(): die Erweiterung "sockets"
 * ist auf einem LoxBerry nicht garantiert geladen, und ein fehlendes
 * socket_create() ist KEIN abfangbarer Fehler, sondern ein fataler. Gemessen
 * mit PHP 8.4 ohne die Erweiterung:
 *
 *   Fatal error: Call to undefined function socket_create()   Rueckgabewert 255
 *
 * Im Cron, der nach /dev/null schreibt, saehe das niemand. Datenstroeme
 * gehoeren zum Kern.
 */
function ro_udp_senden($port, $zeilen)
{
    $fehler = 0; $text = '';
    $fp = @stream_socket_client('udp://127.0.0.1:' . (int) $port, $fehler, $text, 2);
    if ($fp === false) { return 0; }
    $n = 0;
    foreach ((array) $zeilen as $z) {
        if (@fwrite($fp, $z) !== false) { $n++; }
    }
    @fclose($fp);
    return $n;
}

function ro_mqtt_publish($st = null, $dev = 1) {
    $cfg = ro_config();
    if (empty($cfg['mqtt_enabled'])) { return 0; }
    $p = ro_paths();
    if ($p['lbhome'] === '') { return 0; }
    if ($st === null) { $st = ro_state($dev); }
    $gen = @json_decode((string) @file_get_contents($p['lbhome'] . '/config/system/general.json'), true);
    $udp = 0;
    if (isset($gen['Mqtt']['Udpinport'])) { $udp = (int) $gen['Mqtt']['Udpinport']; }
    if (!$udp && isset($gen['mqtt']['udpinport'])) { $udp = (int) $gen['mqtt']['udpinport']; }
    if (!$udp) { return 0; }
    $wurzel = ro_mqtt_thema_saeubern($cfg['mqtt_topic']);
    $prefix = $wurzel;
    if ((int) $dev > 1) { $prefix .= '/' . (int) $dev; }
    $m = ro_mqtt_werte($st, $dev);
    $zeilen = array();
    foreach ($m as $k => $v) {
        $zeilen[] = 'publish ' . $prefix . '/' . $k . ' ' . ro_mqtt_wert_saeubern($v);
    }
    /* Das Lebenszeichen haengt an der WURZEL, nicht am Geraet: es sagt etwas
     * ueber den Cron-Lauf, nicht ueber einen einzelnen Roboter. */
    $lauf = ro_lauf_lesen();
    $zeilen[] = 'publish ' . $wurzel . '/status/ok ' . (int) $lauf['ok'];
    $zeilen[] = 'publish ' . $wurzel . '/status/ts ' . (int) $lauf['ts'];
    $zeilen[] = 'publish ' . $wurzel . '/status/zaehler ' . (int) $lauf['zaehler'];
    return ro_udp_senden($udp, $zeilen);
}

/**
 * NUR das Lebenszeichen - ohne die Werte.
 *
 * Es geht bei JEDEM Cron-Durchgang hinaus, auch wenn sich nichts geaendert
 * hat: der Doppelt-senden-Filter wird fuer diese drei Themen uebergangen.
 * Sonst faellt bei einem Roboter, der eine Woche in der Ladestation steht,
 * genau das Zeichen aus, das sagen soll, dass das Plugin noch lebt.
 */
function ro_mqtt_lebenszeichen()
{
    $cfg = ro_config();
    if (empty($cfg['mqtt_enabled'])) { return 0; }
    $p = ro_paths();
    if ($p['lbhome'] === '') { return 0; }
    $gen = @json_decode((string) @file_get_contents($p['lbhome'] . '/config/system/general.json'), true);
    $udp = 0;
    if (isset($gen['Mqtt']['Udpinport'])) { $udp = (int) $gen['Mqtt']['Udpinport']; }
    if (!$udp && isset($gen['mqtt']['udpinport'])) { $udp = (int) $gen['mqtt']['udpinport']; }
    if (!$udp) { return 0; }
    $wurzel = ro_mqtt_thema_saeubern($cfg['mqtt_topic']);
    $lauf = ro_lauf_lesen();
    return ro_udp_senden($udp, array(
        'publish ' . $wurzel . '/status/ok ' . (int) $lauf['ok'],
        'publish ' . $wurzel . '/status/ts ' . (int) $lauf['ts'],
        'publish ' . $wurzel . '/status/zaehler ' . (int) $lauf['zaehler'],
    ));
}

/**
 * Was ueber MQTT hinausgeht - EINE Liste, damit HTTP und MQTT nicht
 * auseinanderlaufen.
 *
 * ALTER und ZAEHLER sind ausgenommen, und das ist kein Versehen:
 *
 *   1. Ueber MQTT gibt es kein "Alter", nur einen Zeitstempel. Der Miniserver
 *      rechnet selbst - <praefix>/status/ts steht dafuer da.
 *   2. Vor allem aber: diese Liste ist auch die SIGNATUR des Cron-Laufs.
 *      Steht ALTER darin, aendert sie sich jede Sekunde, der
 *      Doppelt-senden-Filter greift nie, und der Cron schickt jede Minute
 *      alle Themen. Gemessen war genau das der Fall: 48 Datagramme im
 *      zweiten Lauf, obwohl sich am Roboter nichts geruehrt hatte.
 *
 * Das Lebenszeichen geht stattdessen unter <praefix>/status/ hinaus, und
 * zwar bei JEDEM Durchgang - siehe ro_mqtt_lebenszeichen().
 */
function ro_mqtt_werte($st, $dev = 1)
{
    $m = array();
    foreach (ro_felder() as $name => $f) {
        if ($name === 'ALTER' || $name === 'ZAEHLER') { continue; }
        $m[strtolower($name)] = ro_feldwert($name, $st);
    }
    // Zwei Klartexte, die es ueber HTTP nicht gibt (dort waeren sie in der
    // Zeile ein Trennzeichenproblem).
    $m['status'] = $st['text'];
    $m['fehlertext'] = $st['fehlertext'];
    $m['ereignistext'] = $st['evtext'];
    $m['meldung'] = ro_meldung_lesen($dev);
    return $m;
}

/* ---------------- Ansage (TTS) ---------------- */

function ro_tts_url($text) {
    $cfg = ro_config(); $tts = $cfg['tts'];
    $mode = (string) (isset($tts['mode']) ? $tts['mode'] : 'musicserver');
    if (!in_array($mode, array('musicserver', 'ms4h', 'audioserver', 'custom'), true)) {
        $mode = 'musicserver';
    }
    if ($mode === 'audioserver') { return null; }
    if ($mode === 'musicserver' && (string) $tts['ip'] === '') {
        return '';   // ohne IP laesst sich die Music-Server-Adresse nicht bauen
    }

    /* Zonenliste EINMAL fuer alle Modi normalisieren. Vorher wurde nur im
     * Modus musicserver je Zone getrimmt; in den Vorlagen-Modi ging die
     * Eingabe roh in {zones} - aus "2, 4, 6" wurde eine Adresse mit
     * Leerzeichen. */
    $zl = array();
    foreach (explode(',', (string) $tts['zones']) as $z) {
        $z = trim($z);
        // Nur das, was eine Zone sein kann. Ueber eine zurueckgespielte
        // Sicherung kaeme sonst beliebiger Text in die Adresse.
        if ($z !== '' && preg_match('/^[0-9]+(~[0-9]+)?$/', $z)) { $zl[] = $z; }
    }
    $tts['zones'] = implode(',', $zl);
    $vol = max(1, min(100, (int) $tts['volume']));
    if ($mode === 'musicserver') {
        $zones = array();
        foreach ($zl as $z) {
            $zones[] = (strpos($z, '~') === false) ? $z . '~' . $vol : $z;
        }
        $zoneStr = $zones ? implode(',', $zones) : '1~' . $vol;
        return 'http://' . $tts['ip'] . ':' . (int) $tts['port'] . '/audio/grouped/tts/' . $zoneStr . '/' . rawurlencode($tts['lang'] . '|' . $text);
    }
    $tpl = trim((string) $tts['template']);
    if ($tpl === '') { $tpl = 'http://{ip}:{port}/tts?text={text}&zone={zones}&vol={vol}'; }
    /* Die IP wird nur verlangt, wenn die Vorlage sie auch verwendet.
     * Vorher stand die Pruefung unbedingt am Anfang der Funktion - eine
     * eigene Vorlage ohne {ip} war damit unbenutzbar (AWM-1.2.0-Fund,
     * hier nachgezogen). */
    if ((string) $tts['ip'] === '' && strpos($tpl, '{ip}') !== false) {
        return '';
    }
    $lang = preg_replace('/[^a-z]/', '', strtolower((string) $tts['lang']));
    return str_replace(array('{ip}', '{port}', '{zones}', '{vol}', '{lang}', '{text}'),
        array($tts['ip'], (int) $tts['port'], $tts['zones'], $vol, $lang, rawurlencode($text)), $tpl);
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
/** Der Text zur letzten Meldung. Bis 1.0.14 wurde er geschrieben und NIRGENDS
 *  gelesen - ANN=1 sagte "es gibt eine Meldung", ohne dass jemand an sie
 *  herankam. Jetzt steht er in der Oberflaeche und unter <praefix>/meldung. */
function ro_meldung_lesen($dev = 1) {
    $f = ro_tmpdir() . '/anntext_' . (int) $dev;
    if (!is_file($f) || time() - filemtime($f) > 86400) { return ''; }
    return trim((string) @file_get_contents($f));
}

/**
 * Die vier Meldeflags an EINER Stelle: ann, audio, push, ptest.
 *
 * Sie standen bis 1.0.12 nur in der HTTP-Antwort. Wer auf MQTT umstellte,
 * verlor sie ersatzlos: kein Meldefenster, keine Freigaben und vor allem
 * kein PTEST, also keine Moeglichkeit mehr, den Push-Weg zu pruefen, ohne
 * auf ein echtes Ereignis zu warten.
 */
function ro_meldeflags($dev = 1)
{
    $cfg = ro_config();
    return array(
        'ann'   => ro_ann_active($dev),
        'audio' => empty($cfg['notify']['audio']) ? 0 : 1,
        'push'  => empty($cfg['notify']['push']) ? 0 : 1,
        'ptest' => ro_ptest_active(),
    );
}

/** Cron: Ereignisse erkennen (fertig, Fehler, Material, Valetudo-Ereignis) und melden. */
function ro_events_check($zustaende = null) {
    $cfg = ro_config();
    foreach (ro_robots() as $n => $r) {
        $st = is_array($zustaende) && isset($zustaende[$n]) ? $zustaende[$n] : ro_state($n);
        $f = ro_tmpdir() . '/ev_' . $n . '.json';
        $prev = is_file($f) ? (json_decode((string) @file_get_contents($f), true) ?: array()) : array();
        /* Gesammelt, nicht ueberschrieben. Bis 1.0.14 schrieben Fertig,
         * Fehler und Material nacheinander DIESELBE Variable; fiel die
         * Wartungswarnung in derselben Minute an, in der eine Reinigung
         * endete, verschwand "ist fertig" ersatzlos. */
        $meldungen = array();
        // Reinigung beendet
        if (!empty($cfg['notify']['fertig']) && isset($prev['code']) && (int) $prev['code'] === 2
            && ro_reinigung_beendet($st['code'])) {
            $meldungen[] = $st['name'] . ' ist fertig. ' . str_replace('.', ',', (string) $st['flaeche'])
                         . ' Quadratmeter in ' . (int) $st['dauer'] . ' Minuten.';
        }
        // Fehler
        if (!empty($cfg['notify']['fehler']) && $st['code'] === 9 && (!isset($prev['code']) || (int) $prev['code'] !== 9)) {
            $meldungen[] = 'Achtung: ' . $st['name'] . ' meldet einen Fehler'
                         . ($st['fehlertext'] !== '' ? ': ' . $st['fehlertext'] : '.');
        }
        // Valetudo-Ereignis (hoechstens einmal je Ereignis)
        if (!empty($cfg['notify']['ereignis']) && $st['event'] > 0 && $st['evtyp'] > 0) {
            $evm = ro_tmpdir() . '/evm_' . $n . '_' . substr(md5((string) $st['evid'] . '|' . $st['evtyp']), 0, 12);
            if (!is_file($evm)) {
                @file_put_contents($evm, '1');
                $meldungen[] = $st['name'] . ': ' . $st['evtext'] . '.';
            }
        }
        // Verbrauchsmaterial (hoechstens einmal taeglich)
        if (!empty($cfg['notify']['material']) && $st['material_warn']) {
            $mf = ro_tmpdir() . '/mat_' . $n . '_' . date('Ymd');
            if (!is_file($mf)) {
                @file_put_contents($mf, '1');
                $teile = ro_material_faellig($st, $cfg);
                if ($teile) {
                    $meldungen[] = $st['name'] . ': Wartung faellig - ' . implode(', ', $teile) . ' pruefen oder wechseln.';
                }
            }
        }
        if ($meldungen) {
            $text = implode(' ', $meldungen);
            @touch(ro_tmpdir() . '/ann_' . $n);
            @file_put_contents(ro_tmpdir() . '/anntext_' . $n, $text);
            ro_log('Meldung: ' . $text);
            if (!empty($cfg['notify']['audio'])) {
                ro_say('Hallo! ' . $text);
            }
        }
        ro_write_json($f, array('code' => $st['code'], 'ts' => time()));
    }
    foreach (glob(ro_tmpdir() . '/mat_*') ?: array() as $g) {
        if (substr(basename($g), -8) !== date('Ymd')) { @unlink($g); }
    }
    // Ereignismerker nach einer Woche wegraeumen.
    foreach (glob(ro_tmpdir() . '/evm_*') ?: array() as $g) {
        if (time() - filemtime($g) > 604800) { @unlink($g); }
    }
}

/** Welche Verbrauchsteile sind unter der Warnschwelle? Klartext fuer die Meldung. */
function ro_material_faellig($st, $cfg = null)
{
    if ($cfg === null) { $cfg = ro_config(); }
    $warn_h = max(0, (int) $cfg['warn_hours']);
    $warn_p = max(0, (int) $cfg['warn_prozent']);
    $prozent = ro_verbrauch_prozentfelder();
    $namen = array('filter' => 'Filter', 'filter2' => 'Zweitfilter',
                   'buerste_haupt' => 'Hauptbuerste', 'buerste_seite' => 'Seitenbuerste',
                   'buerste_seite2' => 'zweite Seitenbuerste', 'sensor' => 'Sensoren',
                   'raeder' => 'Raeder', 'mop' => 'Wischbezug',
                   'dock_buerste' => 'Buerste der Station', 'dock_filter' => 'Filter der Station',
                   'dock_behaelter' => 'Staubbeutel der Station', 'reiniger' => 'Reinigungsmittel');
    $teile = array();
    foreach ($namen as $k => $bez) {
        if (!isset($st[$k])) { continue; }
        $grenze = in_array($k, $prozent, true) ? $warn_p : $warn_h;
        if ($st[$k] >= 0 && $st[$k] <= $grenze) { $teile[] = $bez; }
    }
    return $teile;
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
        $pfad = $home . '/templates/plugins/' . ro_plugin_ordner() . '/lang';
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

/* ---------------- Loxone-Vorlage (Hausstandard "Alles auf einmal anlegen") ---------------- */

/**
 * Die Befehlserkennung fuer Loxone - an EINER Stelle.
 *
 * Das fuehrende Semikolon ist Pflicht (REGELN_3, A11): Loxone nimmt die ERSTE
 * Fundstelle, und ohne Trennzeichen trifft ein Feldname auch dort, wo er
 * Endstueck eines laengeren ist.
 *
 * Bis 1.0.14 war das folgenlos - keines der 19 Feldnamenpaare kollidierte,
 * an der echten Antwortzeile nachgemessen. Mit den Feldern dieser Fassung
 * waere es das NICHT mehr:
 *
 *     FILTER=     traefe zuerst in     DOCKFILTER=
 *     BEHAELTER=  traefe zuerst in     DOCKBEHAELTER=
 *
 * Genau der Fall aus A11 (KM traf zuerst INSPKM). Deshalb steht das Muster
 * ab jetzt an einer Stelle, und die Sprachdateien tragen keine ausgeschriebenen
 * Suchtexte mehr.
 */
function ro_check($feld) { return '\i;' . $feld . '=\i\v'; }

/** name => array(analog, min, max, einheit, kommentar) */
function ro_felder() {
    return array(
        'OK'       => array(0, 0, 1,     '',      '1 = Roboter erreichbar'),
        'CODE'     => array(1, 0, 9,     '',      'Statuszahl: 0 Ladestation, 1 bereit, 2 reinigt, 3 pausiert, 4 faehrt zur Station, 5 faehrt, 8 unbekannt, 9 Fehler'),
        'BATT'     => array(1, 0, 100,   '%',     'Batterie in Prozent'),
        'LAEDT'    => array(0, 0, 1,     '',      '1 = laedt gerade'),
        'FEHLER'   => array(1, 0, 100000, '',     'Herstellerfehlercode (0 = kein Fehler)'),
        'FSTUFE'   => array(1, -1, 4,    '',      'Schwere: -1 unbekannt, 0 keine, 1 Hinweis, 2 Warnung, 3 Fehler, 4 schwer'),
        'FTEIL'    => array(1, -1, 7,    '',      'Betroffenes Teil: -1 unbekannt, 0 keins, 1 Kern, 2 Strom, 3 Sensoren, 4 Motoren, 5 Navigation, 6 Anbauteile, 7 Station'),
        'FLAECHE'  => array(1, 0, 1000,  'm2',    'letzte Reinigung: Flaeche'),
        'DAUER'    => array(1, 0, 600,   'min',   'letzte Reinigung: Dauer'),
        'FLAECHEG' => array(1, 0, 10000000, 'm2', 'Gesamtwerte: Flaeche'),
        'DAUERG'   => array(1, 0, 100000, 'h',    'Gesamtwerte: Stunden'),
        'ANZAHLG'  => array(1, 0, 100000, '',     'Gesamtwerte: Anzahl Reinigungen'),
        'FILTER'   => array(1, -1, 10000, 'h',    'Filter: Reststunden bis zum Wechsel (-1 = nicht verfuegbar)'),
        'FILTER2'  => array(1, -1, 10000, 'h',    'Zweitfilter: Reststunden (-1 = nicht verfuegbar)'),
        'BHAUPT'   => array(1, -1, 10000, 'h',    'Hauptbuerste: Reststunden (-1 = nicht verfuegbar)'),
        'BSEITE'   => array(1, -1, 10000, 'h',    'Seitenbuerste: Reststunden (-1 = nicht verfuegbar)'),
        'BSEITE2'  => array(1, -1, 10000, 'h',    'zweite Seitenbuerste: Reststunden (-1 = nicht verfuegbar)'),
        'SENSOR'   => array(1, -1, 10000, 'h',    'Sensoren: Reststunden bis zum Reinigen (-1 = nicht verfuegbar)'),
        'RAEDER'   => array(1, -1, 10000, 'h',    'Raeder: Reststunden (-1 = nicht verfuegbar)'),
        'MOP'      => array(1, -1, 10000, 'h',    'Wischbezug: Reststunden (-1 = nicht verfuegbar)'),
        'DOCKFILTER'    => array(1, -1, 100, '%', 'Filter der Station: Restanteil (-1 = keine Station)'),
        'DOCKBUERSTE'   => array(1, -1, 100, '%', 'Buerste der Station: Restanteil (-1 = keine Station)'),
        'DOCKBEHAELTER' => array(1, -1, 100, '%', 'Staubbeutel der Station: Restanteil (-1 = keine Station)'),
        'REINIGER' => array(1, -1, 100,  '%',    'Reinigungsmittel: Restanteil (-1 = nicht verfuegbar)'),
        'MATWARN'  => array(0, 0, 1,     '',      '1 = mindestens ein Teil unter der Warnschwelle'),
        'BEHAELTER'  => array(1, -1, 1,  '',      'Staubbehaelter eingesetzt (-1 = meldet das Geraet nicht)'),
        'WASSERTANK' => array(1, -1, 1,  '',      'Wassertank eingesetzt (-1 = meldet das Geraet nicht)'),
        'WISCHER'    => array(1, -1, 1,  '',      'Wischmodul angebaut (-1 = meldet das Geraet nicht)'),
        'DOCK'     => array(1, -1, 9,    '',      'Station: -1 keine, 0 bereit, 1 Pause, 2 saugt ab, 3 reinigt, 4 trocknet, 9 Fehler'),
        'SAUGST'   => array(1, -1, 7,    '',      'Saugstufe: -1 unbekannt, 0 aus, 1 min, 2 niedrig, 3 mittel, 4 hoch, 5 max, 6 turbo, 7 eigen'),
        'WASSER'   => array(1, -1, 7,    '',      'Wischwasser: -1 unbekannt, 0 aus, 1 min, 2 niedrig, 3 mittel, 4 hoch, 5 max, 6 turbo, 7 eigen'),
        'MODUS'    => array(1, -1, 4,    '',      'Betriebsart: -1 unbekannt, 1 saugen, 2 wischen, 3 saugen und wischen, 4 erst saugen, dann wischen'),
        'EVENT'    => array(1, 0, 99,    '',      'Anzahl offener Valetudo-Ereignisse'),
        'EVTYP'    => array(1, 0, 8,     '',      'Jueng(st)es Ereignis: 0 keins, 1 Staubbehaelter voll, 2 Verbrauchsteil leer, 3 Wischmodul pruefen, 4 Stoerung, 5 Karte geaendert, 6 Valetudo aktualisiert, 7 Valetudo-Fehler, 8 unbekannt'),
        'EVMUELL'  => array(0, 0, 1,     '',      '1 = Staubbehaelter voll (Valetudo meldet es)'),
        'ANN'      => array(0, 0, 1,     '',      'Meldefenster aktiv'),
        'AUDIO'    => array(0, 0, 1,     '',      'Ansage freigegeben'),
        'PUSH'     => array(0, 0, 1,     '',      'Push freigegeben'),
        'PTEST'    => array(0, 0, 1,     '',      'Test-Push ausloesen'),
        'ALTER'    => array(1, -1, 100000, 's',   'Alter des letzten Cron-Laufs in Sekunden (-1 = noch keiner). Gehoert auf eine Ueberwachung: ein festgefrorenes Ergebnis sieht sonst aus wie ein frisches.'),
        'ZAEHLER'  => array(1, 0, 999,   '',      'Laufzaehler, laeuft 0...999 um - steht er still, laeuft der Cron nicht mehr'),
    );
}

/** Der Wert eines Feldes aus dem Zustand - EINE Stelle fuer HTTP, MQTT und Vorlage. */
function ro_feldwert($name, $st)
{
    $flags = ro_meldeflags(isset($st['dev']) ? $st['dev'] : 1);
    $lauf = ro_lauf_lesen();
    switch ($name) {
        case 'OK': return (int) $st['ok'];
        case 'CODE': return (int) $st['code'];
        case 'BATT': return (int) $st['batterie'];
        case 'LAEDT': return (int) $st['laedt'];
        case 'FEHLER': return (int) $st['fehler'];
        case 'FSTUFE': return (int) $st['fstufe'];
        case 'FTEIL': return (int) $st['fteil'];
        case 'FLAECHE': return number_format((float) $st['flaeche'], 1, '.', '');
        case 'DAUER': return (int) $st['dauer'];
        case 'FLAECHEG': return number_format((float) $st['flaeche_gesamt'], 1, '.', '');
        case 'DAUERG': return number_format((float) $st['dauer_gesamt'], 1, '.', '');
        case 'ANZAHLG': return (int) $st['anzahl_gesamt'];
        case 'FILTER': return (int) $st['filter'];
        case 'FILTER2': return (int) $st['filter2'];
        case 'BHAUPT': return (int) $st['buerste_haupt'];
        case 'BSEITE': return (int) $st['buerste_seite'];
        case 'BSEITE2': return (int) $st['buerste_seite2'];
        case 'SENSOR': return (int) $st['sensor'];
        case 'RAEDER': return (int) $st['raeder'];
        case 'MOP': return (int) $st['mop'];
        case 'DOCKFILTER': return (int) $st['dock_filter'];
        case 'DOCKBUERSTE': return (int) $st['dock_buerste'];
        case 'DOCKBEHAELTER': return (int) $st['dock_behaelter'];
        case 'REINIGER': return (int) $st['reiniger'];
        case 'MATWARN': return (int) $st['material_warn'];
        case 'BEHAELTER': return (int) $st['behaelter'];
        case 'WASSERTANK': return (int) $st['wassertank'];
        case 'WISCHER': return (int) $st['wischer'];
        case 'DOCK': return (int) $st['dock'];
        case 'SAUGST': return (int) $st['saugstufe'];
        case 'WASSER': return (int) $st['wasserstufe'];
        case 'MODUS': return (int) $st['modus'];
        case 'EVENT': return (int) $st['event'];
        case 'EVTYP': return (int) $st['evtyp'];
        case 'EVMUELL': return (int) $st['evmuell'];
        case 'ANN': return (int) $flags['ann'];
        case 'AUDIO': return (int) $flags['audio'];
        case 'PUSH': return (int) $flags['push'];
        case 'PTEST': return (int) $flags['ptest'];
        case 'ALTER': return ro_lauf_alter();
        case 'ZAEHLER': return (int) $lauf['zaehler'];
    }
    return 0;
}

/** Die Antwortzeile fuer den Miniserver - EINE Stelle. */
function ro_zeile($st, $dev = 1)
{
    $st['dev'] = $dev;
    $teile = array('ROBO');
    foreach (ro_felder() as $name => $f) {
        $teile[] = $name . '=' . ro_feldwert($name, $st);
    }
    return implode(';', $teile);
}

/** Gepruefter PHP-Nachbau des LoxoneTemplateBuilder - Attributreihenfolge,
 *  CRLF und der Tabulator vor den Kindelementen entsprechen dem Original.
 *  Uebernommen aus LoxBerry-Plugin-APC-UPS, nur das Kuerzel getauscht. */
function ro_xml_virtual_in_http($kopf, $cmds) {
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp HintText="" ';
    $o .= 'Title="' . ro_x($kopf['title']) . '" ';
    $o .= 'Comment="' . ro_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . ro_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . ro_x(isset($kopf['polling']) ? $kopf['polling'] : '30') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf; // wie Original-Export aus Loxone Config 17.1
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . ro_x($c['title']) . '" ';
        $o .= 'Comment="' . ro_x($c['comment']) . '" ';
        $o .= 'Check="' . ro_x($c['check']) . '" ';
        $o .= 'Signed="' . ($c['min'] < 0 ? 'true' : 'false') . '" ';
        $o .= 'Analog="' . ($c['analog'] ? 'true' : 'false') . '" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="1" ';
        $o .= 'DestValHigh="1" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="' . (int) $c['min'] . '" ';
        $o .= 'MaxVal="' . (int) $c['max'] . '" ';
        $o .= 'Unit="' . ro_x(isset($c['unit']) ? $c['unit'] : '<v>') . '" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

function ro_x($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Der Rechnername fuer die angezeigten und erzeugten Adressen - EINE Stelle. */
function ro_host() {
    return isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
}

/** Die Adresse des Endpunkts - EINE Stelle fuer Vorlage, Tabelle und Knoepfe. */
function ro_endpunkt_pfad($werte = array())
{
    $p = '/plugins/' . ro_plugin_ordner() . '/robo.php';
    $teile = array();
    foreach ($werte as $k => $v) {
        $teile[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
    }
    return $p . ($teile ? '?' . implode('&', $teile) : '');
}

/**
 * Vorlage fuer den Import in Loxone Config. Rueckgabe: array(name, inhalt)
 *
 * $nur_belegte laesst die Felder weg, die dieser Roboter gerade nicht liefert
 * (-1). Die Feldliste ist mit 1.1.0 von 19 auf 41 gewachsen; wer einen
 * einfachen Sauger ohne Station und ohne Wischmodul hat, braucht die Haelfte
 * davon nicht. Die Vorgabe ist AUS - ein Feld, das gerade nur voruebergehend
 * fehlt, soll nicht stillschweigend verschwinden.
 */
function ro_vorlage($dev = 1, $nur_belegte = false) {
    $dev = max(1, min(9, (int) $dev));
    $st = $nur_belegte ? ro_state($dev) : null;
    $cmds = array();
    foreach (ro_felder() as $name => $f) {
        list($analog, $min, $max, $einheit, $text) = $f;
        if ($st !== null && $min < 0 && (int) ro_feldwert($name, $st) === -1) { continue; }
        $cmds[] = array(
            'title' => 'ROBO_' . $name . ($dev > 1 ? '_' . $dev : ''),
            'comment' => $text . ($einheit !== '' ? ' [' . $einheit . ']' : ''),
            'check' => ro_check($name),
            'unit' => ($einheit !== '' ? '<v.1> ' . $einheit : '<v.1>'),
            'analog' => $analog, 'min' => $min, 'max' => $max,
        );
    }
    return array('VI_saugroboter' . ($dev > 1 ? '_' . $dev : '') . '.xml', ro_xml_virtual_in_http(array(
        'title' => 'Saugroboter' . ($dev > 1 ? ' ' . $dev : ''),
        'address' => 'http://' . ro_host() . ro_endpunkt_pfad($dev > 1 ? array('dev' => $dev) : array()),
        'polling' => '30',
        'comment' => 'Erzeugt vom LoxBerry-Plugin Saugroboter (' . date('d.m.Y') . '). '
                   . 'Loxone Config legt beim Import neu an und ueberschreibt nichts - '
                   . 'zweimal eingelesen ergibt doppelte Bausteine.',
    ), $cmds));
}

/**
 * Zustand UND Fassung des LoxBerry-MQTT-Gateways.
 *
 * Die Fassung steht als Mqtt.Gatewayversion in general.json (ab Werk 1). Sie
 * entscheidet, was der Anwender eintragen muss:
 *   V1  Das Abo wird von Hand eingetragen - ohne den Eintrag kommt am
 *       Miniserver nichts an. Das ist die haeufigste Fehlerursache ueberhaupt.
 *   V2  Das Gateway erkennt die Themengruppe selbst; in den Subscriptions
 *       werden nur noch die gewuenschten Datenpunkte angehakt.
 *
 * Bis 1.0.14 sagte dieses Plugin zum Abo GAR NICHTS - weder das eine noch das
 * andere. Wer unter V1 einrichtete, wartete auf Werte, die nie kamen.
 *
 * Rueckgabe: null, wenn general.json nicht lesbar ist - sonst ein Feld mit
 * autostart (bool) und fassung (int, 0 = unbekannt). Die 0 wird NICHT auf 1
 * vorbelegt: "unbekannt" und "Fassung 1" sind verschiedene Aussagen.
 */
function ro_mqtt_gateway_info() {
    $p = ro_paths();
    if ($p['lbhome'] === '') { return null; }
    $d = @json_decode((string) @file_get_contents($p['lbhome'] . '/config/system/general.json'), true);
    if (!is_array($d) || !isset($d['Mqtt']) || !is_array($d['Mqtt'])) { return null; }
    $auto = isset($d['Mqtt']['Gatewayautostart']) ? $d['Mqtt']['Gatewayautostart'] : '';
    return array(
        'autostart' => in_array((string) $auto, array('1', 'true'), true),
        'fassung'   => isset($d['Mqtt']['Gatewayversion']) ? (int) $d['Mqtt']['Gatewayversion'] : 0,
    );
}
/** Hausstandard: nur der Autostart, fuer die Warnung im Reiter MQTT. */
function ro_mqtt_gateway_autostart() {
    $m = ro_mqtt_gateway_info();
    return $m === null ? null : $m['autostart'];
}
/** Der Abo-Hinweis in der Fassung, die zum Gateway passt - aus EINER Quelle. */
function ro_abo_text() {
    $g = ro_mqtt_gateway_info();
    $f = ($g === null) ? 0 : (int) $g['fassung'];
    if ($f <= 0) { return ro_t('MQTT.ABO_UNBEKANNT'); }
    return ro_t($f >= 2 ? 'MQTT.ABO_V2' : 'MQTT.ABO_V1')
         . ' <span class="sm-mono">' . sprintf(ro_t('MQTT.ABO_GEMESSEN'), $f) . '</span>';
}

/** Vorlage der Steuerbefehle (Virtueller Ausgang) - Format wie Original-Export aus Loxone Config 17.1. */
function ro_vo_vorlage($dev = 1) {
    $cfg = ro_config();
    $tok = isset($cfg['aktionstoken']) ? (string) $cfg['aktionstoken'] : '';
    $dev = max(1, min(9, (int) $dev));
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut HintText="" Title="Saugroboter steuern' . ($dev > 1 ? ' ' . $dev : '')
        . ' (LoxBerry-Plugin)" Comment="Steuerbefehle ueber das Plugin '
        . ro_x(ro_plugin_ordner()) . ' - enthaelt das Aktionstoken." Address="http://'
        . ro_x(ro_host()) . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    /* Aus ro_befehle() erzeugt, nicht von Hand aufgezaehlt. Bis 1.0.14 standen
     * hier fuenf feste Zeilen, waehrend der Endpunkt acht Befehle kannte -
     * segments, fan und goto fehlten in der Vorlage vollstaendig. */
    foreach (ro_befehle() as $name => $b) {
        list($beispiel, $zweck) = $b;
        $werte = array('cmd' => $name);
        if ($beispiel !== '') { $werte['p'] = $beispiel; }
        $werte['token'] = $tok;
        if ($dev > 1) { $werte['dev'] = $dev; }
        $o .= "\t" . '<VirtualOutCmd Title="' . ro_x(ucfirst($name) . ($dev > 1 ? ' ' . $dev : ''))
            . '" Comment="' . ro_x($zweck) . '" CmdOnMethod="GET" CmdOffMethod="GET" ';
        $o .= 'CmdOn="' . ro_x(ro_endpunkt_pfad($werte)) . '" ';
        $o .= 'CmdOnHTTP="" CmdOnPost="" CmdOff="" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" ';
        $o .= 'Analog="false" Repeat="0" RepeatRate="0" HintText=""/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return array('VQ_saugroboter' . ($dev > 1 ? '_' . $dev : '') . '_steuern.xml', $o);
}


/**
 * Den ganzen Konfigurationsstand ablegen - und sagen, ob es geklappt hat.
 *
 * Drei Dinge gehoeren dazu, und zwei davon fehlten bis 1.0.14:
 *   1. schreiben
 *   2. die Sicherungskopie NACHZIEHEN. Alle vier Speicherwege der Oberflaeche
 *      taten das; ausgerechnet das Zurueckspielen nicht. Gemessen stand danach
 *      in <ordner>.backup.json weiter das ALTE Aktionstoken - und genau diese
 *      Datei spielen postinstall, postupgrade und ro_config() zurueck, sobald
 *      robo.json leer oder {} ist.
 *   3. den Zwischenspeicher leeren. Sonst zeigt die Oberflaeche nach einer
 *      geaenderten Roboteradresse bis zu cache_sec Sekunden den Zustand des
 *      alten Geraets, mit dessen Namen.
 */
function ro_config_speichern($cfg)
{
    $p = ro_paths();
    // Nichts schreiben, was keine Zeile tragen kann (fail closed).
    foreach ($cfg as $k => $v) {
        if (!ro_wert_taugt($v)) { return false; }
    }
    $js = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES);
    if ($js === false) {
        return false;   /* ungueltiges UTF-8 - lieber gar nicht schreiben
                           als eine halbe Datei hinterlassen */
    }
    // Erst fragen, dann anlegen: mkdir() warnt auch mit @, wenn der Ordner
    // schon da ist, und ein eigener Fehler-Aufnehmer sieht diese Warnung.
    if (!is_dir(dirname($p['config']))) { @mkdir(dirname($p['config']), 0775, true); }
    if (!ro_write_atomic($p['config'], $js, 0640)) { return false; }
    ro_write_atomic($p['backup'], $js, 0640);
    ro_cache_leeren();
    return true;
}

/** Zwischenspeicher der Zustaende wegraeumen - ueber ro_paths(), nicht ueber
 *  einen fest verdrahteten Pfad. */
function ro_cache_leeren()
{
    foreach (array('state_*.json', 'stumm_*', 'segments_*.json', 'caps_*.json', 'info_*.json') as $muster) {
        foreach (glob(ro_tmpdir() . '/' . $muster) ?: array() as $g) { @unlink($g); }
    }
}

/**
 * Taugt ein Wert ueberhaupt fuer eine Zeile?
 *
 * Die erste der beiden Wachen aus dem Hausstandard. Sie fragt nicht, ob der
 * Wert richtig ist, sondern ob er Schaden anrichten kann: ein Zeilenumbruch im
 * Themenpraefix erzeugt beim MQTT-Gateway eine zweite publish-Zeile, ein
 * Nullbyte zerlegt jede Datei.
 */
function ro_wert_taugt($v)
{
    if (is_array($v)) {
        foreach ($v as $x) { if (!ro_wert_taugt($x)) { return false; } }
        return true;
    }
    if (is_object($v) || is_bool($v) || is_null($v) || is_int($v) || is_float($v)) { return true; }
    if (!is_string($v)) { return false; }
    if (strlen($v) > 4096) { return false; }
    return preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $v) !== 1;
}

/**
 * Die zweite Wache: taugt der Wert fuer DIESEN Schluessel?
 *
 * Gegen dieselbe Positivliste, die auch das Formular benutzt - eine zweite
 * Wahrheit ueber zulaessige Werte gibt es nicht.
 *
 * Bis 1.0.14 wurden nur die SCHLUESSEL geprueft. Gemessen gingen
 * robots="kein Feld", cache_sec="abc", tts=null und notify=5 mit null
 * Beanstandungen durch, obwohl der Kopfkommentar "NICHTS durchgehen lassen"
 * versprach.
 *
 * Rueckgabe: '' wenn in Ordnung, sonst der Grund.
 */
function ro_wert_pruefen($schluessel, $wert)
{
    switch ($schluessel) {
        case 'robots':
            if (!is_array($wert)) { return 'muss eine Liste sein'; }
            if (count($wert) > 2) { return 'hoechstens zwei Roboter'; }
            foreach ($wert as $r) {
                if (!is_array($r)) { return 'Roboter-Eintrag ist keine Liste'; }
                $ip = isset($r['ip']) ? (string) $r['ip'] : '';
                if ($ip !== '' && !preg_match('/^[\w\.\-]{1,253}$/', $ip)) {
                    return 'ungueltige Adresse';
                }
                if (isset($r['port']) && ((int) $r['port'] < 1 || (int) $r['port'] > 65535)) {
                    return 'Port ausserhalb 1..65535';
                }
                foreach (array('name', 'user', 'pass') as $k) {
                    if (isset($r[$k]) && !is_string($r[$k])) { return $k . ' muss Text sein'; }
                }
            }
            return '';
        case 'cache_sec':
            return (is_int($wert) || preg_match('/^\d+$/', (string) $wert))
                   && (int) $wert >= 5 && (int) $wert <= 300 ? '' : 'muss 5..300 sein';
        case 'warn_hours':
            return (is_int($wert) || preg_match('/^\d+$/', (string) $wert))
                   && (int) $wert >= 0 && (int) $wert <= 200 ? '' : 'muss 0..200 sein';
        case 'warn_prozent':
            return (is_int($wert) || preg_match('/^\d+$/', (string) $wert))
                   && (int) $wert >= 0 && (int) $wert <= 100 ? '' : 'muss 0..100 sein';
        case 'mqtt_enabled':
            return in_array((string) $wert, array('0', '1'), true) ? '' : 'muss 0 oder 1 sein';
        case 'mqtt_topic':
            return preg_match('#^[\w\-]+(/[\w\-]+)*$#', (string) $wert)
                   ? '' : 'nur Buchstaben, Ziffern, - _ und /';
        case 'aktionstoken':
            return (is_string($wert) && preg_match('/^[A-Za-z0-9]{0,64}$/', $wert))
                   ? '' : 'nur Buchstaben und Ziffern, hoechstens 64';
        case 'notify':
            if (!is_array($wert)) { return 'muss eine Liste sein'; }
            foreach ($wert as $k => $v) {
                if (!in_array($k, array('audio', 'push', 'fertig', 'fehler', 'material', 'ereignis'), true)) {
                    return 'unbekannter Schalter ' . $k;
                }
                if (!in_array((string) $v, array('0', '1'), true)) { return $k . ' muss 0 oder 1 sein'; }
            }
            return '';
        case 'tts':
            if (!is_array($wert)) { return 'muss eine Liste sein'; }
            foreach ($wert as $k => $v) {
                if (!in_array($k, array('mode', 'ip', 'port', 'zones', 'volume', 'lang', 'template'), true)) {
                    return 'unbekannte Einstellung ' . $k;
                }
            }
            if (isset($wert['mode']) && !in_array((string) $wert['mode'],
                array('musicserver', 'ms4h', 'audioserver', 'custom'), true)) {
                return 'unbekannter Ausgabeweg';
            }
            if (isset($wert['ip']) && (string) $wert['ip'] !== ''
                && !preg_match('/^[\w\.\-]{1,253}$/', (string) $wert['ip'])) {
                return 'ungueltige TTS-Adresse';
            }
            if (isset($wert['port']) && ((int) $wert['port'] < 1 || (int) $wert['port'] > 65535)) {
                return 'TTS-Port ausserhalb 1..65535';
            }
            if (isset($wert['volume']) && ((int) $wert['volume'] < 1 || (int) $wert['volume'] > 100)) {
                return 'Lautstaerke ausserhalb 1..100';
            }
            if (isset($wert['zones']) && !preg_match('/^[0-9,~\s]*$/', (string) $wert['zones'])) {
                return 'Zonen duerfen nur Ziffern, Komma und ~ enthalten';
            }
            if (isset($wert['lang']) && !preg_match('/^[a-z]{0,5}$/', (string) $wert['lang'])) {
                return 'Sprachkuerzel darf nur Kleinbuchstaben enthalten';
            }
            return '';
    }
    return 'unbekannt';
}

/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Der wichtigste Punkt: eine halb gueltige Datei ueberschreibt GAR NICHTS.
 * Wer eine Sicherung zurueckspielt, will entweder den ganzen Stand oder
 * gar keinen - eine zur Haelfte uebernommene Konfiguration ist schlimmer
 * als die alte, und man sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin. Schluessel,
 * die mit '_' beginnen, sind der lesbare Kopf der Datei und werden
 * UEBERGANGEN - sonst lehnte die Funktion die Datei ab, die dieselbe
 * Bibliothek zwei Zeilen vorher erzeugt hat.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
function ro_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(ro_t('TEXT.SICH_KEIN_JSON')), 0);
    }
    $neu = ro_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        $k = (string) $k;
        if ($k !== '' && $k[0] === '_') { continue; }   // lesbarer Kopf
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(ro_t('TEXT.SICH_FREMD'), $k);
            continue;
        }
        if (!ro_wert_taugt($w)) {
            $mangel[] = sprintf(ro_t('TEXT.SICH_WERT'), $k, ro_t('TEXT.SICH_STEUERZEICHEN'));
            continue;
        }
        $grund = ro_wert_pruefen($k, $w);
        if ($grund !== '') {
            $mangel[] = sprintf(ro_t('TEXT.SICH_WERT'), $k, $grund);
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = ro_t('TEXT.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}

/** Was in die Sicherungsdatei geschrieben wird - mit lesbarem Kopf. */
function ro_sicherung_bauen()
{
    $cfg = ro_config();
    $aus = array(
        '_hinweis' => 'Sicherung des LoxBerry-Plugins Saugroboter (Valetudo). '
                    . 'Enthaelt das Aktionstoken der Anlage - wie ein Passwort behandeln.',
        '_stand'   => date('Y-m-d H:i'),
        '_fassung' => ro_pluginversion(),
    );
    // Nur die bekannten Schluessel, in der Reihenfolge der Vorgaben. So kann
    // nichts in die Datei geraten, was die Leseseite danach ablehnt.
    foreach (array_keys(ro_vorgaben()) as $k) {
        $aus[$k] = isset($cfg[$k]) ? $cfg[$k] : null;
    }
    return $aus;
}

/**
 * Die laufende Fassung des Plugins.
 *
 * Die VERSION-Zeile der plugin.cfg wird ZEILENWEISE gelesen: LoxBerry
 * schreibt '#'-Kommentare, PHP erkennt seit 7.0 nur ';', und das
 * Ausrufezeichen in mancher plugin.cfg laesst parse_ini_file fuer die
 * GANZE Datei scheitern.
 */
function ro_pluginversion()
{
    static $v = null;
    if ($v !== null) { return $v; }
    $v = '';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'pluginversion')) {
        $v = (string) LBSystem::pluginversion();
    }
    if ($v === '') {
        foreach (array(dirname(dirname(dirname(__DIR__))) . '/plugin.cfg',
                       dirname(dirname(__DIR__)) . '/plugin.cfg') as $f) {
            if (!is_file($f)) { continue; }
            foreach (ro_log_tail($f, 200) as $z) {
                if (preg_match('/^VERSION\s*=\s*([0-9][0-9A-Za-z\.\-]*)/', trim($z), $m)) {
                    $v = $m[1]; break 2;
                }
            }
        }
    }
    return $v;
}

/* ==================================================================
 * Konfigurationslage - fehlend UND fremd
 *
 *   fehlend   in den Vorgaben, nicht in der Datei   -> im Betrieb greift die Vorgabe
 *   fremd     in der Datei, nicht in den Vorgaben   -> wirkt nicht, und das ueberrascht
 *
 * Fremdes wird NICHT geloescht. Niemand weiss, ob dort der Rest einer
 * aelteren Fassung steht oder etwas, das der naechsten schon gehoert.
 * Genannt gehoert es trotzdem.
 * ================================================================== */
function ro_cfg_lage()
{
    $vorgaben = ro_vorgaben();
    $p = ro_paths();
    $datei = is_file($p['config'])
        ? (json_decode((string) @file_get_contents($p['config']), true) ?: array())
        : array();
    if (!is_array($datei)) { $datei = array(); }
    $fehlend = array_values(array_diff(array_keys($vorgaben), array_keys($datei)));
    $fremd   = array_values(array_diff(array_keys($datei), array_keys($vorgaben)));
    sort($fehlend); sort($fremd);
    return array('fehlend' => $fehlend, 'fremd' => $fremd, 'anzahl' => count($vorgaben));
}

/* ==================================================================
 * Selbstpruefung - beantwortet OHNE Loxone, ob die Einrichtung traegt
 *
 * ok = 1 Haken, 0 Kreuz, 2 Strich ("nicht feststellbar"). Ein Strich ist
 * ausdruecklich KEIN Haken: was nicht gemessen werden konnte, sagt das.
 * ================================================================== */
function ro_selbsttest()
{
    $cfg = ro_config();
    $z = array();
    $add = function ($schluessel, $ok, $text) use (&$z) {
        $z[] = array('bez' => $schluessel, 'ok' => (int) $ok, 'text' => (string) $text);
    };

    $robots = ro_robots();
    $add('PRUEF.ROBOTER', $robots ? 1 : 0, (string) count($robots));
    $add('PRUEF.TOKEN', trim((string) $cfg['aktionstoken']) !== '' ? 1 : 0, '');

    $erreicht = 0; $modelle = array(); $unbekannt = array();
    foreach (array_keys($robots) as $n) {
        $st = ro_state($n);
        if ($st['ok']) { $erreicht++; }
        $i = ro_robotinfo($n);
        if (!empty($i['modell'])) {
            $modelle[] = trim($i['hersteller'] . ' ' . $i['modell'])
                       . ($i['valetudo'] !== '' ? ' / Valetudo ' . $i['valetudo'] : '');
        }
        foreach ((array) $st['material_fremd'] as $f) { $unbekannt[$f] = 1; }
    }
    $add('PRUEF.ERREICHBAR', ($robots && $erreicht === count($robots)) ? 1 : ($erreicht > 0 ? 2 : 0),
        $erreicht . '/' . count($robots));
    $add('PRUEF.MODELL', $modelle ? 1 : 2, implode(', ', $modelle));

    // Welche Faehigkeiten meldet der erste Roboter?
    $caps = $robots ? ro_capabilities(1) : array();
    $add('PRUEF.FAEHIGKEITEN', $caps ? 1 : 2, (string) count($caps));
    foreach (array('MapSegmentationCapability' => 'PRUEF.RAEUME',
                   'FanSpeedControlCapability' => 'PRUEF.SAUGSTUFE',
                   'ConsumableMonitoringCapability' => 'PRUEF.VERBRAUCH',
                   'AutoEmptyDockManualTriggerCapability' => 'PRUEF.ABSAUGEN') as $cap => $schl) {
        $kann = $caps ? in_array($cap, $caps, true) : null;
        $add($schl, $kann === null ? 2 : ($kann ? 1 : 0), '');
    }

    // Verbrauchsteile, die dieses Plugin nicht kennt - nennen, nicht verschlucken.
    $add('PRUEF.MATFREMD', $unbekannt ? 0 : 1, implode(', ', array_keys($unbekannt)));

    // Laeuft der Cron?
    $alter = ro_lauf_alter();
    $add('PRUEF.CRON', ($alter >= 0 && $alter <= 180) ? 1 : ($alter < 0 ? 0 : 2),
        $alter >= 0 ? ($alter . ' s') : '');

    // MQTT
    $g = ro_mqtt_gateway_info();
    if (empty($cfg['mqtt_enabled'])) {
        $add('PRUEF.MQTT', 2, ro_t('PRUEF.MQTT_AUS'));
    } else {
        $add('PRUEF.MQTT', $g === null ? 2 : ($g['autostart'] ? 1 : 0),
            $g === null ? '' : ('V' . (int) $g['fassung']));
    }

    // Konfigurationslage
    $lage = ro_cfg_lage();
    if ($lage['fremd']) {
        $add('PRUEF.KONFIG', 0, sprintf(ro_t('PRUEF.KONFIG_FREMD'), implode(', ', $lage['fremd'])));
    } elseif ($lage['fehlend']) {
        $add('PRUEF.KONFIG', 2, sprintf(ro_t('PRUEF.KONFIG_FEHLT'),
            count($lage['fehlend']), $lage['anzahl'], implode(', ', $lage['fehlend'])));
    } else {
        $add('PRUEF.KONFIG', 1, sprintf(ro_t('PRUEF.KONFIG_VOLL'), $lage['anzahl']));
    }

    // Die eigene Vorlage: wohlgeformt?
    if (function_exists('simplexml_load_string')) {
        list(, $inhalt) = ro_vorlage(1);
        $add('PRUEF.VORLAGE', (@simplexml_load_string($inhalt) === false) ? 0 : 1, '');
    } else {
        $add('PRUEF.VORLAGE', 2, '');
    }

    /* Trifft jede Befehlserkennung genau eine Stelle?
     *
     * Geprueft wird die WIRKUNG, nicht die Schreibweise: der Suchtext aus
     * ro_check() wird auf die ECHTE Antwortzeile losgelassen. Genau das ist
     * der Punkt, an dem FILTER und DOCKFILTER auseinandergehalten werden
     * muessen - ohne das fuehrende Semikolon traefe FILTER= zuerst in
     * DOCKFILTER=. */
    $probe = ro_state(1);
    $zeile = ro_zeile($probe, 1);
    $doppelt = array();
    foreach (array_keys(ro_felder()) as $feld) {
        if (substr_count($zeile, ';' . $feld . '=') !== 1) { $doppelt[] = $feld; }
    }
    $add('PRUEF.SUCHTEXT', $doppelt ? 0 : 1,
        $doppelt ? implode(', ', $doppelt) : (string) count(ro_felder()));

    // Nicht-stoeren-Zeit in Valetudo - sie kann einem Loxone-Programm in die
    // Quere fahren, ohne dass jemand daran denkt.
    if ($robots) {
        $dnd = ro_ruhezeit(1);
        if (is_array($dnd) && isset($dnd['enabled'])) {
            $add('PRUEF.RUHEZEIT', empty($dnd['enabled']) ? 1 : 2,
                empty($dnd['enabled']) ? '' : sprintf('%02d:%02d-%02d:%02d',
                    (int) $dnd['start']['hour'], (int) $dnd['start']['minute'],
                    (int) $dnd['end']['hour'], (int) $dnd['end']['minute']));
        } else {
            $add('PRUEF.RUHEZEIT', 2, '');
        }
    }

    return $z;
}

/* Der Escape-Helfer gehoert in die Bibliothek, nicht in
 * index.php: sonst steht er dem Endpunkt und jedem weiteren
 * Aufrufer nicht zur Verfuegung (Hausform, REGELN_2). */
function rb_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
