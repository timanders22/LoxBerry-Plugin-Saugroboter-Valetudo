<?php
/**
 * Saugroboter (Valetudo) - Admin-Oberflaeche
 * Reiter: Einstellungen | Einbindung in Loxone | Test | Protokoll
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * WICHTIG: LBWeb::lbheader() setzt SDK-GLOBALS (u.a. $cfg aus general.json als
 * stdClass) und wuerde gleichnamige Plugin-Variablen ueberschreiben - daher
 * tragen hier ALLE Variablen ein rb_-Praefix.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

$rb_lbhome = getenv('LBHOMEDIR') ?: lb_wurzel_ermitteln();
// Ermitteln, nicht raten - siehe die ausfuehrliche Begruendung in
// ro_paths() in robo_lib.php: der Rueckfall auf einen festen Namen liesse
// eine Zweitinstallation (saugrobo_01) in die Konfiguration der ersten
// greifen. Der mittlere Kandidat der alten Fassung war ohnehin wirkungslos:
// basename(dirname(__DIR__)) ergibt "plugins", nie einen Plugin-Ordner.
// Der feste Name greift nur noch dort, wo der ermittelte NACHWEISLICH kein
// Plugin-Ordner sein kann: aus dem ausgepackten Archiv heraus heisst der
// Ordner "htmlauth". Installiert heisst er immer wie das Plugin.
$rb_plugin = getenv('LBPPLUGINDIR');
if (!$rb_plugin) { $rb_plugin = basename(__DIR__); }
if ($rb_plugin === '' || $rb_plugin === '.' || $rb_plugin === '/' || $rb_plugin === 'htmlauth') {
    $rb_plugin = 'saugrobo';
}
if ($rb_lbhome) {
    $rb_sdk = $rb_lbhome . '/libs/phplib/loxberry_system.php';
    if (file_exists($rb_sdk)) { require_once $rb_sdk; require_once $rb_lbhome . '/libs/phplib/loxberry_web.php'; }
    $rb_cfgdir = $rb_lbhome . '/config/plugins/' . $rb_plugin;
    $rb_bkfile = $rb_lbhome . '/config/plugins/' . $rb_plugin . '.backup.json';
    $rb_logfile = $rb_lbhome . '/log/plugins/' . $rb_plugin . '/robo.log';
} else {
    $rb_cfgdir = dirname(dirname(__DIR__)) . '/config';
    $rb_bkfile = $rb_cfgdir . '/robo.backup.json';
    $rb_logfile = sys_get_temp_dir() . '/saugrobo/robo.log';
}
$rb_cfgfile = $rb_cfgdir . '/robo.json';

foreach (array(dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . $rb_plugin . '/robo_lib.php',
               dirname(__DIR__) . '/html/robo_lib.php') as $rb_cand) {
    if (is_file($rb_cand)) { require_once $rb_cand; break; }
}

if ((!is_file($rb_cfgfile) || trim((string) @file_get_contents($rb_cfgfile)) === '' || trim((string) @file_get_contents($rb_cfgfile)) === '{}') && is_file($rb_bkfile)) {
    @mkdir($rb_cfgdir, 0775, true);
    @copy($rb_bkfile, $rb_cfgfile);
}

$rb_saved = false; $rb_err = ''; $rb_note = '';
/* Aktiver Reiter: aus dem abgesendeten Formular (activetab) oder aus der
   Adresse (?form=...). Letzteres brauchen die Reiter, seit sie echte
   Verweise sind. Die Positivliste MUSS jeden Reiter enthalten. */
$rb_muster = '/^tab-(settings|mqtt|loxone|test|log)$/';
$rb_wunsch = isset($_POST['activetab']) ? (string) $_POST['activetab']
    : (isset($_GET['form']) ? 'tab-' . (string) $_GET['form'] : '');
$rb_tab = preg_match($rb_muster, $rb_wunsch) ? $rb_wunsch : 'tab-settings';

// ---------- Loxone-Vorlage herunterladen (Hausstandard) ----------
// Vor jeder Ausgabe, sonst stehen HTML-Reste in der XML-Datei.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vorlage_vo']) && function_exists('ro_vo_vorlage')) {
    list($rb_vname, $rb_vinhalt) = ro_vo_vorlage();
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $rb_vname . '"');
    echo $rb_vinhalt;
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vorlage']) && function_exists('ro_vorlage')) {
    list($rb_vname, $rb_vinhalt) = ro_vorlage(isset($_POST['vorlage_dev']) ? (int) $_POST['vorlage_dev'] : 1);
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $rb_vname . '"');
    echo $rb_vinhalt;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearlog'])) {
    @mkdir(dirname($rb_logfile), 0775, true);
    @file_put_contents($rb_logfile, '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Admin-Oberflaeche)\n");
    $rb_tab = 'tab-log';
}

// ---------- Neues Aktionstoken erzeugen ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token_neu'])) {
    $rb_cfg_tok = function_exists('ro_config') ? ro_config() : array();
    if (!is_array($rb_cfg_tok)) { $rb_cfg_tok = array(); }
    $rb_cfg_tok['aktionstoken'] = function_exists('ro_token_erzeugen') ? ro_token_erzeugen() : bin2hex(random_bytes(12));
    if (!is_dir($rb_cfgdir)) { @mkdir($rb_cfgdir, 0775, true); }
    $rb_json_tok = json_encode($rb_cfg_tok, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
    // schriebe dann eine Datei mit NULL Bytes - und meldete das als Erfolg.
    if ($rb_json_tok !== false && @file_put_contents($rb_cfgfile, $rb_json_tok) !== false) {
        @copy($rb_cfgfile, $rb_bkfile);
        $rb_note = 'Neues Token erzeugt. Die Adressen in Loxone muessen angepasst werden '
                 . '- die alten funktionieren nicht mehr.';
    }
    $rb_tab = 'tab-loxone';
}

// ---------- MQTT speichern (eigener Reiter seit 1.0.10, Hausstandard) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mqtt_save'])) {
    $mq_cfg = function_exists('ro_config') ? ro_config() : array();
    if (!is_array($mq_cfg)) { $mq_cfg = array(); }
    $mq_cfg['mqtt_enabled'] = isset($_POST['mqtt_enabled']) ? 1 : 0;
    $mq_cfg['mqtt_topic'] = preg_replace('#[^\w/\-]#', '', (string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : 'saugrobo')) ?: 'saugrobo';
    if (!is_dir($rb_cfgdir)) { @mkdir($rb_cfgdir, 0775, true); }
    $mq_json = json_encode($mq_cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($mq_json !== false && @file_put_contents($rb_cfgfile, $mq_json) !== false) {
        @copy($rb_cfgfile, $rb_bkfile);
    } else {
        $rb_err = 'Konfiguration konnte nicht gespeichert werden: ' . $rb_cfgfile;
    }
    $rb_tab = 'tab-mqtt';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $rb_new = array();
    $rb_new['robots'] = array();
    $rb_n = isset($_POST['r_name']) ? (array) $_POST['r_name'] : array();
    $rb_i2 = isset($_POST['r_ip']) ? (array) $_POST['r_ip'] : array();
    $rb_p2 = isset($_POST['r_port']) ? (array) $_POST['r_port'] : array();
    for ($rb_i = 0; $rb_i < 2; $rb_i++) {
        $ip = trim((string) (isset($rb_i2[$rb_i]) ? $rb_i2[$rb_i] : ''));
        if ($ip === '') { continue; }
        if (!preg_match('/^[\w\.\-]+$/', $ip)) { $rb_err = 'Roboter ' . ($rb_i + 1) . ': ung&uuml;ltige Adresse.'; continue; }
        $rb_new['robots'][] = array('name' => trim((string) (isset($rb_n[$rb_i]) ? $rb_n[$rb_i] : '')),
            'ip' => $ip, 'port' => max(1, min(65535, (int) (isset($rb_p2[$rb_i]) ? $rb_p2[$rb_i] : 80))));
    }
    $rb_new['cache_sec'] = max(5, min(300, (int) (isset($_POST['cache_sec']) ? $_POST['cache_sec'] : 20)));
    $rb_new['warn_hours'] = max(0, min(200, (int) (isset($_POST['warn_hours']) ? $_POST['warn_hours'] : 10)));
        // Aus dem Bestand uebernehmen, was dieses Formular nicht mitschickt.
    // BIS 1.0.9 FEHLTE DAS FUER aktionstoken: jedes Speichern der Einstellungen
    // warf das Token still weg, der naechste Seitenaufruf erzeugte ein NEUES -
    // und alle Loxone-Adressen liefen auf 403.
    // MQTT wohnt seit 1.0.10 im eigenen Reiter (Hausstandard).
    $alt_cfg = function_exists('ro_config') ? ro_config() : array();
    if (!is_array($alt_cfg)) { $alt_cfg = array(); }
    $rb_new['aktionstoken'] = isset($alt_cfg['aktionstoken']) ? (string) $alt_cfg['aktionstoken'] : '';
    $rb_new['mqtt_enabled'] = isset($alt_cfg['mqtt_enabled']) ? (int) $alt_cfg['mqtt_enabled'] : 0;
    $rb_new['mqtt_topic'] = isset($alt_cfg['mqtt_topic']) && $alt_cfg['mqtt_topic'] !== '' ? $alt_cfg['mqtt_topic'] : 'saugrobo';
    $rb_new['notify'] = array(
        'audio' => isset($_POST['notify_audio']) ? 1 : 0,
        'push' => isset($_POST['notify_push']) ? 1 : 0,
        'fertig' => isset($_POST['n_fertig']) ? 1 : 0,
        'fehler' => isset($_POST['n_fehler']) ? 1 : 0,
        'material' => isset($_POST['n_material']) ? 1 : 0,
    );
    $rb_mode = (string) (isset($_POST['tts_mode']) ? $_POST['tts_mode'] : 'musicserver');
    $rb_new['tts'] = array(
        'mode' => in_array($rb_mode, array('musicserver', 'ms4h', 'audioserver', 'custom'), true) ? $rb_mode : 'musicserver',
        'ip' => trim((string) (isset($_POST['tts_ip']) ? $_POST['tts_ip'] : '')),
        'port' => max(1, min(65535, (int) (isset($_POST['tts_port']) ? $_POST['tts_port'] : 7091))),
        'zones' => trim((string) (isset($_POST['tts_zones']) ? $_POST['tts_zones'] : '1')),
        'volume' => max(1, min(100, (int) (isset($_POST['tts_volume']) ? $_POST['tts_volume'] : 8))),
        'lang' => preg_replace('/[^a-z]/', '', strtolower((string) (isset($_POST['tts_lang']) ? $_POST['tts_lang'] : 'de'))) ?: 'de',
        'template' => trim((string) (isset($_POST['tts_template']) ? $_POST['tts_template'] : '')),
    );
    if ($rb_err === '') {
        if (!is_dir($rb_cfgdir)) { @mkdir($rb_cfgdir, 0775, true); }
        $rb_json = json_encode($rb_new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
        // schriebe dann eine Datei mit NULL Bytes - und meldete das als Erfolg.
        if ($rb_json !== false && @file_put_contents($rb_cfgfile, $rb_json) !== false) {
            $rb_saved = true;
            @copy($rb_cfgfile, $rb_bkfile);
            foreach (glob('/tmp/saugrobo/state_*.json') ?: array() as $g) { @unlink($g); }
        } else {
            $rb_err = 'Konfiguration konnte nicht gespeichert werden: ' . $rb_cfgfile;
        }
    }
}

$rb_cfg = function_exists('ro_config') ? ro_config() : array();
if (!is_array($rb_cfg)) { $rb_cfg = array(); }
$rb_cfg += array('robots' => array(), 'cache_sec' => 20, 'warn_hours' => 10, 'mqtt_enabled' => 0,
    'mqtt_topic' => 'saugrobo', 'notify' => array(), 'tts' => array(), 'aktionstoken' => '');

// Beim ersten Aufruf ein Token erzeugen, damit der Endpunkt fuer Loxone sofort
// benutzbar ist (schuetzt ?cmd= im unangemeldeten robo.php).
if (empty($rb_cfg['aktionstoken'])) {
    $rb_cfg['aktionstoken'] = function_exists('ro_token_erzeugen') ? ro_token_erzeugen() : bin2hex(random_bytes(12));
    if (!is_dir($rb_cfgdir)) { @mkdir($rb_cfgdir, 0775, true); }
    $rb_json_init = json_encode($rb_cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
    // schriebe dann eine Datei mit NULL Bytes - und meldete das als Erfolg.
    if ($rb_json_init !== false && @file_put_contents($rb_cfgfile, $rb_json_init) !== false) {
        @copy($rb_cfgfile, $rb_bkfile);
    }
}
$rb_notify = is_array($rb_cfg['notify']) ? $rb_cfg['notify'] : array();
$rb_notify += array('audio' => 0, 'push' => 0, 'fertig' => 1, 'fehler' => 1, 'material' => 1);
$rb_tts = is_array($rb_cfg['tts']) ? $rb_cfg['tts'] : array();
$rb_tts += array('mode' => 'musicserver', 'ip' => '', 'port' => 7091, 'zones' => '1', 'volume' => 8, 'lang' => 'de', 'template' => '');
$rb_robots = function_exists('ro_robots') ? ro_robots() : array();
$rb_states = array();
foreach ($rb_robots as $rb_k => $rb_r) { $rb_states[$rb_k] = ro_state($rb_k); }
$rb_loglines = array();
if (is_file($rb_logfile)) {
    // ro_log_tail() liest nur das Ende der Datei - siehe die Messwerte
    // im Kommentar in robo_lib.php.
    $rb_loglines = array_reverse(ro_log_tail($rb_logfile, 300));
}


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

function rb_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function rb_h($h) { return $h < 0 ? '&ndash;' : (int) $h . ' h'; }

$rb_frame = class_exists('LBWeb', false);
if ($rb_frame) { LBWeb::lbheader('Saugroboter', 'https://wiki.loxberry.de/', 'help.html'); }
$rb_host = rb_e(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '<loxberry-ip>');

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin
 * kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ro_sichern'])) {
    $ro_js = json_encode(ro_config(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($ro_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="saugroboter_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $ro_js;
        exit;
    }
    $rb_note = ro_t('TEXT.SICH_SCHREIBFEHLER');
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei
 * des Servers unterschieben. Dann die Groessengrenze - eine Sicherung
 * dieses Plugins ist wenige Kilobyte gross; alles darueber wird gar
 * nicht erst gelesen. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ro_zurueck'])) {
    if (!isset($_FILES['ro_sicherung']) || !is_array($_FILES['ro_sicherung'])
        || !isset($_FILES['ro_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['ro_sicherung']['tmp_name'])) {
        $rb_note = ro_t('TEXT.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['ro_sicherung']['size'] > 262144) {
        $rb_note = ro_t('TEXT.SICH_ZU_GROSS');
    } else {
        list($ro_neu, $ro_mangel, $ro_n) = ro_sicherung_lesen(
            (string) @file_get_contents($_FILES['ro_sicherung']['tmp_name']));
        if ($ro_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert
             * wird nichts. */
            $rb_note = ro_t('TEXT.SICH_ABGELEHNT') . ' ' . implode(' ', $ro_mangel);
        } elseif (ro_config_speichern($ro_neu)) {
            $rb_note = sprintf(ro_t('TEXT.SICH_UEBERNOMMEN'), $ro_n);
        } else {
            $rb_note = ro_t('TEXT.SICH_SCHREIBFEHLER');
        }
    }
}

?>
<style>
.sm-wrap { max-width: 940px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=number], .sm-wrap select, .sm-wrap textarea {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1; min-width: 150px; }
.sm-row > div > label:not([style]) { min-height: 2.6em; display: flex; align-items: flex-end; }
.sm-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-warn { background: #fff8e1; border: 1px solid #ffe082; }
.sm-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.sm-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; text-shadow: none !important; text-decoration: none !important; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
.sm-log { text-shadow: none !important; background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.sm-tbl { border-collapse: collapse; margin: 8px 0; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; }
.sm-tbl th { background: #f0f0f0; }
.sm-wrap .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button { text-shadow: none !important; box-shadow: none !important; }
.sm-wrap a.sm-btn, .sm-wrap a.sm-btn:visited, .sm-wrap a.sm-btn:hover { color: #fff !important; text-decoration: none; }

/* --- <?php echo ro_t('TEXT.EINHEIT'); ?>liches Kachel-Raster im Reiter <?php echo ro_t('TEXT.TEST'); ?> (Standard aller Plugins) --- */
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; text-shadow: none !important; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-knopfreihe .sm-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-btn.sm-b-lesen   { background: #6dac20; }
.sm-btn.sm-b-technik { background: #546e7a; }
.sm-btn.sm-b-aktion  { background: #e0620d; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
</style>
<div class="sm-wrap">

<?php if ($rb_saved) { ?><div class="sm-alert sm-ok"><b><?php echo ro_t('TEXT.KONFIGURATION_GESPEICHERT'); ?></b> <?php echo ro_t('TEXT.INKL_SICHERUNGSKOPIE_FR_UPDATES'); ?></div><?php } ?>
<?php if ($rb_note !== '') { ?><div class="sm-alert sm-ok"><?= rb_e($rb_note) ?></div><?php } ?>
<?php if ($rb_err !== '') { ?><div class="sm-alert sm-err"><b><?php echo ro_t('TEXT.FEHLER'); ?></b> <?= $rb_err ?></div><?php } ?>

<?php if (!$rb_robots) { ?>
<div class="sm-alert sm-info"><b><?php echo ro_t('TEXT.NOCH_KEIN_ROBOTER_EINGERICHTET'); ?></b> <?php echo ro_t('TEXT.BITTE_UNTEN_DIE_ADRESSE_DER_VALETU'); ?></div>
<?php } ?>
<?php foreach ($rb_states as $rb_k => $rb_s) { ?>
<div class="sm-alert <?= $rb_s['fehler'] ? 'sm-warn' : 'sm-info' ?>">
<b><?= rb_e($rb_s['name']) ?></b>:
<?php if ($rb_s['ok']) { ?>
<b><?= rb_e($rb_s['text']) ?></b> <?php echo ro_t('TEXT.BATTERIE'); ?> <?= (int) $rb_s['batterie'] ?> %<?= $rb_s['laedt'] ? ' (l&auml;dt)' : '' ?>
<?= $rb_s['fehler'] ? ' &middot; <b>Fehler ' . (int) $rb_s['fehler'] . '</b> ' . rb_e($rb_s['fehlertext']) : '' ?><br>
<?php echo ro_t('TEXT.LETZTE_REINIGUNG'); ?> <?= rb_e($rb_s['flaeche']) ?> <?php echo ro_t('TEXT.M_SUP2_IN'); ?> <?= (int) $rb_s['dauer'] ?> min<?= $rb_s['letzte'] ? ' (' . rb_e(date('d.m.Y H:i', $rb_s['letzte'])) . ')' : '' ?><br>
<?php echo ro_t('TEXT.GESAMT'); ?> <?= rb_e($rb_s['flaeche_gesamt']) ?> <?php echo ro_t('TEXT.M_SUP2'); ?> <?= rb_e($rb_s['dauer_gesamt']) ?> <?php echo ro_t('TEXT.H'); ?> <?= (int) $rb_s['anzahl_gesamt'] ?> <?php echo ro_t('TEXT.REINIGUNGEN'); ?><br>
<?php echo ro_t('TEXT.VERBRAUCHSMATERIAL_FILTER'); ?> <?= rb_h($rb_s['filter']) ?> <?php echo ro_t('TEXT.HAUPTBRSTE'); ?> <?= rb_h($rb_s['buerste_haupt']) ?> <?php echo ro_t('TEXT.SEITENBRSTE'); ?> <?= rb_h($rb_s['buerste_seite']) ?> <?php echo ro_t('TEXT.SENSOREN'); ?> <?= rb_h($rb_s['sensor']) ?>
<?= $rb_s['material_warn'] ? ' &rarr; <b>Wartung f&auml;llig</b>' : '' ?>
<?php } else { ?>
<b><?php echo ro_t('TEXT.KEINE_VERBINDUNG'); ?></b> <?php echo ro_t('TEXT.ADRESSE_PRFEN_VALETUDO_OBERFLCHE_I'); ?>
<?php } ?>
</div>
<?php } ?>

<?php
/*
 * Reiter als echte Verweise, sm-active vom SERVER.
 *
 * Bis 1.0.2 standen hier <div class="sm-tab"> ohne Verweis, und sm-active
 * vergab allein das JavaScript am Seitenende. Da .sm-pane auf display:none
 * steht, war die Seite ohne JavaScript vollstaendig leer - und die Reiter
 * liessen sich nicht einmal anklicken, weil ein <div> kein Verweis ist.
 *
 * Diese Liste, die Positivliste in $rb_muster und die id der Flaechen
 * muessen deckungsgleich bleiben - alle drei.
 */
$rb_reiter = array(
    'tab-settings' => ro_t('REITER.EINSTELLUNGEN'),
    'tab-mqtt'     => ro_t('REITER.MQTT'),
    'tab-loxone'   => ro_t('REITER.LOXONE'),
    'tab-test'     => ro_t('REITER.TEST'),
    'tab-log'      => ro_t('REITER.LOG'),
);
?>
<div class="sm-tabs">
<?php foreach ($rb_reiter as $rb_id => $rb_bez) { ?>
    <a class="sm-tab<?php echo $rb_tab === $rb_id ? ' sm-active' : ''; ?>" data-pane="<?php echo htmlspecialchars($rb_id, ENT_QUOTES, 'UTF-8'); ?>"
       href="index.php?form=<?php echo htmlspecialchars(substr($rb_id, 4), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $rb_bez; ?></a>
<?php } ?>
</div>

<!-- ================= <?php echo ro_t('TEXT.EINSTELLUNG'); ?>en ================= -->
<div class="sm-pane<?php echo $rb_tab === 'tab-settings' ? ' sm-active' : ''; ?>" id="tab-settings">
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?php echo ro_t('TEXT.ROBOTER_BIS_ZU_2'); ?></h2>
<table class="sm-tbl" style="width:100%;">
<tr><th style="width:36px;">Nr.</th><th style="width:34%;"><?php echo ro_t('TEXT.NAME_FREI'); ?></th><th><?php echo ro_t('TEXT.ADRESSE_IP_ODER_HOSTNAME'); ?></th><th style="width:100px;"><?php echo ro_t('TEXT.PORT'); ?></th></tr>
<?php for ($rb_i = 0; $rb_i < 2; $rb_i++) {
    $rb_r = isset($rb_cfg['robots'][$rb_i]) ? (array) $rb_cfg['robots'][$rb_i] : array();
    $rb_r += array('name' => '', 'ip' => '', 'port' => 80); ?>
<tr>
<td><?= $rb_i + 1 ?></td>
<td><input data-role="none" type="text" name="r_name[]" value="<?= rb_e($rb_r['name']) ?>" placeholder="<?= $rb_i === 0 ? 'z. B. Saugroboter OG' : 'leer = ungenutzt' ?>"></td>
<td><input data-role="none" type="text" name="r_ip[]" value="<?= rb_e($rb_r['ip']) ?>" placeholder="<?= $rb_i === 0 ? 'z. B. 192.168.1.10' : '' ?>"></td>
<td><input data-role="none" type="number" name="r_port[]" value="<?= (int) $rb_r['port'] ?>" min="1" max="65535"></td>
</tr>
<?php } ?>
</table>
<div class="sm-small"><?php echo ro_t('TEXT.VORAUSSETZUNG_AUF_DEM_ROBOTER_LUFT'); ?> <b><?php echo ro_t('TEXT.VALETUDO'); ?></b> <?php echo ro_t('TEXT.CLOUDFREIE_FIRMWARE_DIE_ADRESSE_IS'); ?> <span class="sm-mono"><?php echo ro_t('TEXT.DEV_2'); ?></span> <?php echo ro_t('TEXT.ABGEFRAGT'); ?></div>

<div class="sm-row">
    <div>
        <label><?php echo ro_t('TEXT.STATUS_CACHE_SEKUNDEN'); ?></label>
        <input data-role="none" type="number" name="cache_sec" value="<?= (int) $rb_cfg['cache_sec'] ?>" min="5" max="300">
        <div class="sm-small"><?php echo ro_t('TEXT.HUFIGERE_ABFRAGEN_WERDEN_AUS_DEM_Z'); ?></div>
    </div>
    <div>
        <label><?php echo ro_t('TEXT.WARNSCHWELLE_VERBRAUCHSMATERIAL_ST'); ?></label>
        <input data-role="none" type="number" name="warn_hours" value="<?= (int) $rb_cfg['warn_hours'] ?>" min="0" max="200">
        <div class="sm-small"><?php echo ro_t('TEXT.UNTERHALB_DIESER_RESTLAUFZEIT_MELD'); ?><span class="sm-mono"><?php echo ro_t('TEXT.MATWARN_1'); ?></span>).</div>
    </div>
</div>

<h2><?php echo ro_t('TEXT.MELDUNGEN'); ?></h2>
<div style="margin-bottom:10px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="notify_audio" <?= !empty($rb_notify['audio']) ? 'checked' : '' ?><?php echo ro_t('TEXT.AUDIOAUSGABE_AKTIV'); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="notify_push" <?= !empty($rb_notify['push']) ? 'checked' : '' ?><?php echo ro_t('TEXT.PUSH_NACHRICHT_AKTIV'); ?>
    </label>
    <div class="sm-small"><?php echo ro_t('TEXT.DIE_ANSAGE_SPRICHT_DAS_PLUGIN_SELB'); ?> <span class="sm-mono"><?php echo ro_t('TEXT.ANN_1'); ?></span>.</div>
</div>
<div>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;">
        <input data-role="none" type="checkbox" name="n_fertig" <?= !empty($rb_notify['fertig']) ? 'checked' : '' ?><?php echo ro_t('TEXT.REINIGUNG_FERTIG_MIT_FLCHE_UND_DAU'); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;">
        <input data-role="none" type="checkbox" name="n_fehler" <?= !empty($rb_notify['fehler']) ? 'checked' : '' ?><?php echo ro_t('TEXT.STRUNG_FEHLER'); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="n_material" <?= !empty($rb_notify['material']) ? 'checked' : '' ?><?php echo ro_t('TEXT.WARTUNG_FLLIG_HCHSTENS_1_TGLICH'); ?>
    </label>
</div>

<h2><?php echo ro_t('TEXT.SPRACHAUSGABE'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo ro_t('TEXT.AUDIO_AUSGABE'); ?></label>
        <select data-role="none" name="tts_mode" id="tts_mode" onchange="rbTtsMode()">
            <option value="musicserver"<?= $rb_tts['mode'] === 'musicserver' ? ' selected' : '' ?><?php echo ro_t('TEXT.LOXONE_MUSIC_SERVER_KLASSISCH'); ?></option>
            <option value="ms4h"<?= $rb_tts['mode'] === 'ms4h' ? ' selected' : '' ?><?php echo ro_t('TEXT.AUDIOSERVER4HOME_MUSICSERVER4HOME'); ?></option>
            <option value="audioserver"<?= $rb_tts['mode'] === 'audioserver' ? ' selected' : '' ?><?php echo ro_t('TEXT.ORIGINAL_LOXONE_AUDIOSERVER_VIA_LO'); ?></option>
            <option value="custom"<?= $rb_tts['mode'] === 'custom' ? ' selected' : '' ?><?php echo ro_t('TEXT.EIGENE_URL_VORLAGE'); ?></option>
        </select>
    </div>
    <div>
        <label><?php echo ro_t('TEXT.IP_DES_AUDIO_SERVERS'); ?></label>
        <input data-role="none" type="text" name="tts_ip" value="<?= rb_e($rb_tts['ip']) ?>" placeholder="z. B. 192.168.1.20">
    </div>
    <div>
        <label>Port</label>
        <input data-role="none" type="number" name="tts_port" value="<?= (int) $rb_tts['port'] ?>" min="1" max="65535">
    </div>
</div>
<div class="sm-row">
    <div>
        <label><?php echo ro_t('TEXT.ZONEN'); ?></label>
        <input data-role="none" type="text" name="tts_zones" value="<?= rb_e($rb_tts['zones']) ?>" placeholder="z. B. 2,4,6">
        <div class="sm-small"><?php echo ro_t('TEXT.ZONENNUMMERN_MIT_KOMMA_Z_B'); ?> <span class="sm-mono">2,4,6</span><?php echo ro_t('TEXT.DIE_LAUTSTRKE_KOMMT_AUS_DEM_FELD_D'); ?> <span class="sm-mono"><?php echo ro_t('TEXT.ZONE_LAUTSTRKE'); ?></span> <?php echo ro_t('TEXT.Z_B'); ?> <span class="sm-mono">2~25,4~40</span><?php echo ro_t('TEXT.LEERZEICHEN_NACH_DEM_KOMMA_SIND_ER'); ?> <span class="sm-mono">2,4,6</span> und <span class="sm-mono">2, 4, 6</span> <?php echo ro_t('TEXT.FUNKTIONIEREN_BEIDE'); ?></div>
    </div>
    <div>
        <label><?php echo ro_t('TEXT.LAUTSTRKE'); ?></label>
        <input data-role="none" type="number" name="tts_volume" value="<?= (int) $rb_tts['volume'] ?>" min="1" max="100">
    </div>
    <div>
        <label><?php echo ro_t('TEXT.SPRACHE'); ?></label>
        <input data-role="none" type="text" name="tts_lang" value="<?= rb_e($rb_tts['lang']) ?>" maxlength="2">
    </div>
</div>
<div id="tts_template_row">
    <label><?php echo ro_t('TEXT.URL_VORLAGE_FR_AUDIOSERVER4HOME_MS'); ?></label>
    <textarea data-role="none" name="tts_template" id="tts_template" rows="2" placeholder="<?php echo ro_t('TEXT.HTTP'); ?>{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}"><?= rb_e($rb_tts['template']) ?></textarea>
    <div class="sm-small"><?php echo ro_t('TEXT.PLATZHALTER'); ?> <span class="sm-mono"><?php echo ro_t('TEXT.IP_PORT_ZONES_VOL_LANG_TEXT'); ?></span><?php echo ro_t('TEXT.LEER_STANDARD_VORLAGE'); ?></div>
</div>
<div id="tts_audioserver_hint" class="sm-alert sm-info" style="display:none;">
    <?php echo ro_t('TEXT.DER_ORIGINALE_LOXONE_AUDIOSERVER_B'); ?> <span class="sm-mono">ANN=1</span>.
</div>

<button data-role="none" class="sm-btn" type="submit"><?php echo ro_t('TEXT.SPEICHERN'); ?></button>
</form>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<!-- ================= Reiter: MQTT (eigener Reiter seit 1.0.10, Hausstandard) ================= -->
<div class="sm-pane<?php echo $rb_tab === 'tab-mqtt' ? ' sm-active' : ''; ?>" id="tab-mqtt">
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="mqtt_save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<h2><?php echo ro_t('TEXT.MQTT_OPTIONAL'); ?></h2>
<?php if (function_exists('ro_mqtt_gateway_autostart') && ro_mqtt_gateway_autostart() === false) { ?><div class="sm-alert sm-warn"><b>MQTT:</b> <?php echo ro_t('TEXT.W_AUTOSTART'); ?></div><?php } ?>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="mqtt_enabled" <?= !empty($rb_cfg['mqtt_enabled']) ? 'checked' : '' ?><?php echo ro_t('TEXT.ZUSTAND_PER_MQTT_VERFFENTLICHEN'); ?>
</label>
<div class="sm-row" style="margin-top:6px;">
    <div>
        <label><?php echo ro_t('TEXT.TOPIC_PRFIX'); ?></label>
        <input data-role="none" type="text" name="mqtt_topic" value="<?= rb_e($rb_cfg['mqtt_topic']) ?>" placeholder="saugrobo">
        <div class="sm-small"><?php echo ro_t('TEXT.VERFFENTLICHT_U_A'); ?> <span class="sm-mono"><?= rb_e($rb_cfg['mqtt_topic']) ?><?php echo ro_t('TEXT.CODE'); ?></span>,
        <span class="sm-mono"><?php echo ro_t('TEXT.STATUS'); ?></span>, <span class="sm-mono"><?php echo ro_t('TEXT.BATTERIE_2'); ?></span>, <span class="sm-mono"><?php echo ro_t('TEXT.FEHLER_2'); ?></span>,
        <span class="sm-mono"><?php echo ro_t('TEXT.FLAECHE'); ?></span>, <span class="sm-mono"><?php echo ro_t('TEXT.FILTER'); ?></span>, <span class="sm-mono"><?php echo ro_t('TEXT.MATERIAL_WARN'); ?></span>
        <?php echo ro_t('TEXT.ROBOTER_2'); ?> <span class="sm-mono"><?= rb_e($rb_cfg['mqtt_topic']) ?>/2/...</span>).</div>
    </div>
</div>

<button data-role="none" class="sm-btn" type="submit"><?php echo ro_t('TEXT.SPEICHERN'); ?></button>
</form>
</div>

<div class="sm-pane<?php echo $rb_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" id="tab-loxone">
<h2><?php echo ro_t('TEXT.EINBINDUNG_IN_LOXONE_SCHRITT_FR_SC'); ?></h2>
<p><?php echo ro_t('TEXT.DAS_PLUGIN_FASST_DIE_VIER_VALETUDO'); ?> <b><?php echo ro_t('TEXT.EINER'); ?></b> <?php echo ro_t('TEXT.ABFRAGE_ZUSAMMEN_STATT_BISHER_VIER'); ?> <b><?php echo ro_t('TEXT.STATUSZAHL'); ?></b>.</p>

<div class="sm-step"><b><?php echo ro_t('TEXT.SCHRITT_1_VIRTUELLER_HTTP_EINGANG_'); ?></b> <?php echo ro_t('TEXT.ABFRAGE_ALLE_30_S'); ?>
<table class="sm-tbl">
<tr><th><?php echo ro_t('TEXT.EIGENSCHAFT'); ?></th><th><?php echo ro_t('TEXT.WERT'); ?></th></tr>
<tr><td>URL</td><td><span class="sm-mono">http://<?= $rb_host ?><?php echo ro_t('TEXT.PLUGINS'); ?><?= rb_e($rb_plugin) ?><?php echo ro_t('TEXT.ROBO_PHP'); ?></span> (Roboter 2: <span class="sm-mono"><?php echo ro_t('TEXT.DEV_2_2'); ?></span>)</td></tr>
<tr><td><?php echo ro_t('TEXT.ABFRAGEZYKLUS'); ?></td><td><?php echo ro_t('TEXT.30_SEKUNDEN'); ?></td></tr>
</table>
</div>

<div class="sm-step"><b><?php echo ro_t('TEXT.SCHRITT_2_BEFEHLSERKENNUNGEN'); ?></b>
<table class="sm-tbl">
<tr><th><?php echo ro_t('TEXT.BEFEHLSERKENNUNG'); ?></th><th><?php echo ro_t('TEXT.BEDEUTUNG'); ?></th></tr>
<tr><td><span class="sm-mono"><?php echo ro_t('TEXT.ICODE_I_V'); ?></span></td><td><b>Statuszahl</b><?php echo ro_t('TEXT.0_LADESTATION_1_BEREIT_2_REINIGT_3'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo ro_t('TEXT.IBATT_I_V'); ?></span> / <span class="sm-mono"><?php echo ro_t('TEXT.ILAEDT_I_V'); ?></span></td><td><?php echo ro_t('TEXT.BATTERIE_IN_1_LDT_GERADE'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo ro_t('TEXT.IFEHLER_I_V'); ?></span></td><td><?php echo ro_t('TEXT.FEHLERCODE_0_KEIN_FEHLER'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo ro_t('TEXT.IFLAECHE_I_V'); ?></span> / <span class="sm-mono"><?php echo ro_t('TEXT.IDAUER_I_V'); ?></span></td><td><?php echo ro_t('TEXT.LETZTE_REINIGUNG_M_SUP2_UND_MINUTE'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo ro_t('TEXT.IFLAECHEG_I_V'); ?></span> / <span class="sm-mono"><?php echo ro_t('TEXT.IDAUERG_I_V'); ?></span> / <span class="sm-mono"><?php echo ro_t('TEXT.IANZAHLG_I_V'); ?></span></td><td><?php echo ro_t('TEXT.GESAMTWERTE_M_SUP2_STUNDEN_ANZAHL_'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo ro_t('TEXT.IFILTER_I_V'); ?></span> / <span class="sm-mono"><?php echo ro_t('TEXT.IBHAUPT_I_V'); ?></span> / <span class="sm-mono"><?php echo ro_t('TEXT.IBSEITE_I_V'); ?></span> / <span class="sm-mono"><?php echo ro_t('TEXT.ISENSOR_I_V'); ?></span></td><td><?php echo ro_t('TEXT.VERBRAUCHSMATERIAL_RESTSTUNDEN_BIS'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo ro_t('TEXT.IMATWARN_I_V'); ?></span></td><td><?php echo ro_t('TEXT.1_MINDESTENS_EIN_TEIL_UNTER_DER_WA'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo ro_t('TEXT.IANN_I_V'); ?></span> / <span class="sm-mono"><?php echo ro_t('TEXT.IPUSH_I_V'); ?></span> / <span class="sm-mono"><?php echo ro_t('TEXT.IPTEST_I_V'); ?></span></td><td><?php echo ro_t('TEXT.MELDEFENSTER_PUSH_FREIGABE_TEST_PU'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo ro_t('TEXT.IOK_I_V'); ?></span></td><td><?php echo ro_t('TEXT.1_ROBOTER_ERREICHBAR'); ?></td></tr>
</table>
</div>

<div class="sm-step"><b><?php echo ro_t('TEXT.SCHRITT_3_STEUERUNG_BER_EINEN_VIRT'); ?></b><br>
<?php echo ro_t('TEXT.VALETUDO_VERLANGT_EIGENTLICH_PUT_A'); ?>
<table class="sm-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td><?php echo ro_t('TEXT.ADRESSE_VIRTUELLER_AUSGANG'); ?></td><td><span class="sm-mono">http://<?= $rb_host ?></span></td></tr>
</table>
<table class="sm-tbl">
<tr><th><?php echo ro_t('TEXT.BEFEHL_BEI_EIN'); ?></th><th><?php echo ro_t('TEXT.WIRKUNG'); ?></th></tr>
<tr><td><span class="sm-mono">/plugins/<?= rb_e($rb_plugin) ?><?php echo ro_t('TEXT.ROBO_PHP_CMD_START'); ?>&amp;token=<?= rb_e($rb_cfg['aktionstoken']) ?></span></td><td><?php echo ro_t('TEXT.KOMPLETTREINIGUNG_STARTEN'); ?></td></tr>
<tr><td><span class="sm-mono">/plugins/<?= rb_e($rb_plugin) ?><?php echo ro_t('TEXT.ROBO_PHP_CMD_PAUSE'); ?>&amp;token=<?= rb_e($rb_cfg['aktionstoken']) ?></span></td><td><?php echo ro_t('TEXT.PAUSIEREN'); ?></td></tr>
<tr><td><span class="sm-mono">/plugins/<?= rb_e($rb_plugin) ?><?php echo ro_t('TEXT.ROBO_PHP_CMD_STOP'); ?>&amp;token=<?= rb_e($rb_cfg['aktionstoken']) ?></span></td><td><?php echo ro_t('TEXT.STOPPEN'); ?></td></tr>
<tr><td><span class="sm-mono">/plugins/<?= rb_e($rb_plugin) ?><?php echo ro_t('TEXT.ROBO_PHP_CMD_HOME'); ?>&amp;token=<?= rb_e($rb_cfg['aktionstoken']) ?></span></td><td><?php echo ro_t('TEXT.ZUR_LADESTATION'); ?></td></tr>
<tr><td><span class="sm-mono">/plugins/<?= rb_e($rb_plugin) ?><?php echo ro_t('TEXT.ROBO_PHP_CMD_LOCATE'); ?>&amp;token=<?= rb_e($rb_cfg['aktionstoken']) ?></span></td><td><?php echo ro_t('TEXT.ROBOTER_PIEPSEN_LASSEN_WIEDERFINDE'); ?></td></tr>
<tr><td><span class="sm-mono">/plugins/<?= rb_e($rb_plugin) ?><?php echo ro_t('TEXT.ROBO_PHP_CMD_SEGMENTSP_1_4'); ?>&amp;token=<?= rb_e($rb_cfg['aktionstoken']) ?></span></td><td><?php echo ro_t('TEXT.NUR_BESTIMMTE_RUME_REINIGEN_IDS_SI'); ?></td></tr>
<tr><td><span class="sm-mono">/plugins/<?= rb_e($rb_plugin) ?><?php echo ro_t('TEXT.ROBO_PHP_CMD_FANP_MAX'); ?>&amp;token=<?= rb_e($rb_cfg['aktionstoken']) ?></span></td><td><?php echo ro_t('TEXT.SAUGSTRKE_LOW_MEDIUM_HIGH_MAX_TURB'); ?></td></tr>
</table>
<div class="sm-alert sm-warn"><b>Token n&ouml;tig:</b> Der Endpunkt liegt unangemeldet und ist deshalb mit einem Token abgesichert &ndash; ohne passendes <span class="sm-mono">&amp;token=...</span> antwortet er mit HTTP 403 (aktuelles Token im n&auml;chsten Abschnitt).</div>
</div>

<div class="sm-step"><b>Aktionstoken</b>
<table class="sm-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td>Aktuelles Token</td><td><span class="sm-mono"><?= rb_e($rb_cfg['aktionstoken']) ?></span></td></tr>
</table>
<div class="sm-knopfreihe sm-b-aktion">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" type="submit" name="token_neu" value="1"><?php echo ro_t('TEXT.K_TOKEN_NEU'); ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> Aktion &ndash; &auml;ndert bestehende Loxone-Adressen</span>
</div>
</div>

<h2><?php echo ro_t('TEXT.H_VORLAGE'); ?></h2>
<div class="sm-hinweis"><?php echo ro_t('TEXT.H_VORLAGE_TEXT'); ?></div>
<form action="index.php" method="post" style="margin-bottom:14px;">
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <input data-role="none" type="hidden" name="vorlage" value="1">
  <button data-role="none" class="sm-btn" type="submit" style="background:#546e7a;"><?php echo ro_t('TEXT.K_VORLAGE'); ?></button>
</form>
<form action="index.php" method="post" style="margin-bottom:14px;">
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <input data-role="none" type="hidden" name="vorlage_vo" value="1">
  <button data-role="none" class="sm-btn" type="submit" style="background:#546e7a;"><?php echo ro_t('TEXT.K_VORLAGE_VO'); ?></button>
</form>

<div class="sm-step"><b><?php echo ro_t('TEXT.SCHRITT_4_KOMPLETTE_BAUSTEIN_LISTE'); ?></b><br>
<b><?php echo ro_t('TEXT.4A_KACHELN_UND_ZUSTANDSANZEIGE'); ?></b>
<table class="sm-tbl">
<tr><th><?php echo ro_t('TEXT.BAUSTEIN'); ?></th><th><?php echo ro_t('TEXT.NAME'); ?></th><th>Einstellung</th><th><?php echo ro_t('TEXT.EINGNGE'); ?></th></tr>
<tr><td><?php echo ro_t('TEXT.STATUSBAUSTEIN'); ?></td><td><?php echo ro_t('TEXT.SAUGROBOTER_ZUSTAND'); ?></td><td><?php echo ro_t('TEXT.TEXTE_JE_WERT_0_IN_DER_LADESTATION'); ?></td><td><?php echo ro_t('TEXT.I1_CODE'); ?></td></tr>
<tr><td><?php echo ro_t('TEXT.ANALOGANZEIGEN'); ?></td><td><?php echo ro_t('TEXT.BATTERIE_FLCHE_DAUER'); ?></td><td><?php echo ro_t('TEXT.EINHEITEN'); ?> <span class="sm-mono">&lt;v.0&gt; %</span>, <span class="sm-mono"><?php echo ro_t('TEXT.V_1_M_SUP2'); ?></span>, <span class="sm-mono"><?php echo ro_t('TEXT.V_0_MIN'); ?></span></td><td><?php echo ro_t('TEXT.BATT_FLAECHE_DAUER'); ?></td></tr>
<tr><td>Analoganzeigen</td><td><?php echo ro_t('TEXT.FILTER_BRSTEN_SENSOREN'); ?></td><td>Einheit <span class="sm-mono">&lt;v.0&gt; h</span> <?php echo ro_t('TEXT.RESTLAUFZEIT_BIS_ZUR_WARTUNG'); ?></td><td><?php echo ro_t('TEXT.FILTER_BHAUPT_BSEITE_SENSOR'); ?></td></tr>
</table>
<b><?php echo ro_t('TEXT.4B_MELDUNGEN_FERTIG_STRUNG_WARTUNG'); ?></b>
<table class="sm-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td><?php echo ro_t('TEXT.SCHWELLWERTSCHALTER_S1'); ?></td><td><?php echo ro_t('TEXT.MELDEFENSTER_AKTIV'); ?></td><td><?php echo ro_t('TEXT.EIN_0_5_AUS_0_4'); ?></td><td><?php echo ro_t('TEXT.ANN'); ?></td></tr>
<tr><td><?php echo ro_t('TEXT.SCHWELLWERTSCHALTER_S2'); ?></td><td><?php echo ro_t('TEXT.PUSH_FREIGEGEBEN'); ?></td><td>Ein 0,5 / Aus 0,4</td><td><?php echo ro_t('TEXT.PUSH'); ?></td></tr>
<tr><td><?php echo ro_t('TEXT.UND_U1_ODER_O1'); ?></td><td><?php echo ro_t('TEXT.ROBOTER_MELDUNG'); ?></td><td><?php echo ro_t('TEXT.O1_IST_DIE_EINZIGE_QUELLE_DES_BENA'); ?></td><td><?php echo ro_t('TEXT.U1_S1_S2'); ?></td></tr>
<tr><td><?php echo ro_t('TEXT.BENACHRICHTIGUNGS_BAUSTEIN'); ?></td><td><?php echo ro_t('TEXT.PUSH_SAUGROBOTER'); ?></td><td><?php echo ro_t('TEXT.TEXT_Z_B_SAUGROBOTER_MELDUNG_DETAI'); ?></td><td><?php echo ro_t('TEXT.O1'); ?></td></tr>
<tr><td><?php echo ro_t('TEXT.SCHWELLWERTSCHALTER_S3'); ?></td><td><?php echo ro_t('TEXT.STRUNG'); ?></td><td><?php echo ro_t('TEXT.EIN_0_5_AN_FEHLER_FR_EINE_EIGENE_W'); ?></td><td><?php echo ro_t('TEXT.FEHLER_3'); ?></td></tr>
<tr><td><?php echo ro_t('TEXT.SCHWELLWERTSCHALTER_S4'); ?></td><td><?php echo ro_t('TEXT.WARTUNG_FLLIG'); ?></td><td><?php echo ro_t('TEXT.EIN_0_5_AN_MATWARN'); ?></td><td><?php echo ro_t('TEXT.MATWARN'); ?></td></tr>
<tr><td><?php echo ro_t('TEXT.BENACHRICHTIGUNGS_BAUSTEIN_2'); ?></td><td><?php echo ro_t('TEXT.TEST_PUSH'); ?></td><td><?php echo ro_t('TEXT.EIGENER_BAUSTEIN_NUR_FR_DEN_TEST'); ?></td><td><?php echo ro_t('TEXT.SCHWELLWERTSCHALTER_AN_PTEST'); ?></td></tr>
</table>
<b><?php echo ro_t('TEXT.4C_AUTOMATISCH_SAUGEN_WENN_NIEMAND'); ?></b>
<table class="sm-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td><?php echo ro_t('TEXT.SCHWELLWERTSCHALTER_S5'); ?></td><td><?php echo ro_t('TEXT.ROBOTER_IST_BEREIT'); ?></td><td><?php echo ro_t('TEXT.INVERTIERT_EIN_BEI_UNTERSCHREITEN_'); ?></td><td><?php echo ro_t('TEXT.CODE_2'); ?></td></tr>
<tr><td><?php echo ro_t('TEXT.UND_U2'); ?></td><td><?php echo ro_t('TEXT.SAUGEN_FREIGEBEN'); ?></td><td><?php echo ro_t('TEXT.AUF_DEN_VIRTUELLEN_AUSGANG'); ?> <span class="sm-mono"><?php echo ro_t('TEXT.CMD_START'); ?></span></td><td><?php echo ro_t('TEXT.S5_ABWESENHEIT_ZEITFENSTER_NICHT_W'); ?></td></tr>
<tr><td><?php echo ro_t('TEXT.UND_U3'); ?></td><td><?php echo ro_t('TEXT.SOFORT_HEIMSCHICKEN'); ?></td><td><?php echo ro_t('TEXT.TEXT'); ?> <span class="sm-mono"><?php echo ro_t('TEXT.CMD_HOME'); ?></span><?php echo ro_t('TEXT.WENN_JEMAND_NACH_HAUSE_KOMMT'); ?></td><td><?php echo ro_t('TEXT.ANWESENHEIT_CODE_2'); ?></td></tr>
</table>
<b><?php echo ro_t('TEXT.PRAXIS_ERFAHRUNG'); ?></b> <?php echo ro_t('TEXT.DER_BENACHRICHTIGUNGS_BAUSTEIN_SEN'); ?>
</div>

<div class="sm-step"><b><?php echo ro_t('TEXT.SCHRITT_5_MQTT_UND_JSON'); ?></b><br>
<?php echo ro_t('TEXT.ALLE_WERTE_AUCH_PER_MQTT_REITER_EI'); ?>
<span class="sm-mono">http://<?= $rb_host ?>/plugins/<?= rb_e($rb_plugin) ?><?php echo ro_t('TEXT.ROBO_PHP_JSON_1'); ?></span>
</div>
</div>

<!-- ================= Test ================= -->
<div class="sm-pane<?php echo $rb_tab === 'tab-test' ? ' sm-active' : ''; ?>" id="tab-test">
<h2>Test</h2>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo ro_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo ro_t('LEGENDE.TECHNIK'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo ro_t('LEGENDE.AKTION'); ?></span>
</div>

<h3 class="sm-h3"><?php echo ro_t('TEXT.ANSEHEN'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-lesen"  href="/plugins/<?= rb_e($rb_plugin) ?>/robo.php" target="_blank"><?php echo ro_t('TEXT.LOXONE_ZEILE_ABRUFEN'); ?></a>
<a class="sm-btn sm-b-lesen"  href="/plugins/<?= rb_e($rb_plugin) ?>/robo.php?json=1" target="_blank"><?php echo ro_t('TEXT.JSON_ANSICHT'); ?></a>
</div>

<h3 class="sm-h3"><?php echo ro_t('TEXT.TECHNISCHE_AUSKUNFT'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-technik"  href="/plugins/<?= rb_e($rb_plugin) ?>/robo.php?debug=1&amp;refresh=1" target="_blank"><?php echo ro_t('TEXT.DEBUG_INKL_RAUMLISTE'); ?></a>
</div>

<h3 class="sm-h3"><?php echo ro_t('TEXT.LST_ETWAS_AUS'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= rb_e($rb_plugin) ?>/robo.php?ptest=1&amp;token=<?= rb_e($rb_cfg['aktionstoken']) ?>" target="_blank"><?php echo ro_t('TEXT.TEST_PUSHNACHRICHT'); ?></a>
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= rb_e($rb_plugin) ?>/robo.php?cmd=locate&amp;token=<?= rb_e($rb_cfg['aktionstoken']) ?>" target="_blank"><?php echo ro_t('TEXT.ROBOTER_PIEPSEN_LASSEN'); ?></a>
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= rb_e($rb_plugin) ?>/robo.php?cmd=home&amp;token=<?= rb_e($rb_cfg['aktionstoken']) ?>" target="_blank"><?php echo ro_t('TEXT.ZUR_LADESTATION_2'); ?></a>
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= rb_e($rb_plugin) ?>/robo.php?cmd=stop&amp;token=<?= rb_e($rb_cfg['aktionstoken']) ?>" target="_blank"><?php echo ro_t('TEXT.STOPP'); ?></a>
</div>


<div class="sm-small"><?php echo ro_t('TEXT.PIEPSEN_LASSEN_IST_DER_UNGEFHRLICH'); ?></div>
<?php $rb_seg = function_exists('ro_segments') ? ro_segments(1) : array(); if ($rb_seg) { ?>
<h2><?php echo ro_t('TEXT.RUME_SEGMENT_IDS'); ?></h2>
<table class="sm-tbl"><tr><th>ID</th><th>Name</th><th><?php echo ro_t('TEXT.AUFRUF_FR_LOXONE'); ?></th></tr>
<?php foreach ($rb_seg as $rb_id => $rb_nm) { ?>
<tr><td><span class="sm-mono"><?= rb_e($rb_id) ?></span></td><td><?= rb_e($rb_nm) ?></td>
<td><span class="sm-mono"><?php echo ro_t('TEXT.CMD_SEGMENTSP'); ?><?= rb_e($rb_id) ?></span></td></tr>
<?php } ?></table>
<div class="sm-small"><?php echo ro_t('TEXT.MEHRERE_RUME_MIT_KOMMA'); ?> <span class="sm-mono">?cmd=segments&amp;p=<?= rb_e(implode(',', array_slice(array_keys($rb_seg), 0, 2))) ?></span></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo ro_t('TEXT.RAUMLISTE_NOCH_NICHT_VERFGBAR_ERSC'); ?></div>
<?php } ?>
</div>

<!-- ================= <?php echo ro_t('TEXT.PROTOKOLL'); ?> ================= -->
<div class="sm-pane<?php echo $rb_tab === 'tab-log' ? ' sm-active' : ''; ?>" id="tab-log">
<h2>Protokoll</h2>
<div class="sm-small" style="margin-bottom:8px;"><?php echo ro_t('TEXT.PROTOKOLLIERT_WERDEN_STATUSNDERUNG'); ?><br><?php echo ro_t('TEXT.DATEI'); ?> <span class="sm-mono"><?= rb_e($rb_logfile) ?></span></div>
<?php if ($rb_loglines) { ?>
<div class="sm-log"><?= rb_e(implode("\n", $rb_loglines)) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo ro_t('TEXT.NOCH_KEINE_PROTOKOLL_EINTRGE_VORHA'); ?></div>
<?php } ?>
<form action="index.php" method="post" style="margin-top:10px;">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn" type="submit" style="background:#c62828;"><?php echo ro_t('TEXT.PROTOKOLL_LEEREN'); ?></button>
</form>
</div>


<h2><?= ro_t('TEXT.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= ro_t('TEXT.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= ro_t('TEXT.SICH_WARNUNG') ?></div>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="ro_sichern" value="1"><?= ro_t('TEXT.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="file" name="ro_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ro_zurueck" value="1"><?= ro_t('TEXT.K_ZURUECK') ?></button>
  </form>
</div>
</div>
<script>
function rbTtsMode() {
    var m = document.getElementById('tts_mode').value;
    document.getElementById('tts_audioserver_hint').style.display = (m === 'audioserver') ? 'block' : 'none';
    document.getElementById('tts_template_row').style.display = (m === 'ms4h' || m === 'custom') ? 'block' : 'none';
    var port = document.getElementsByName('tts_port')[0];
    if (m === 'musicserver' && (!port.value || port.value === '80')) { port.value = 7091; }
}
(function () {
    var tabs = document.querySelectorAll('.sm-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('sm-active', t.dataset.pane === id); });
        document.querySelectorAll('.sm-pane').forEach(function (p) { p.classList.toggle('sm-active', p.id === id); });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function (e) { e.preventDefault(); activate(t.dataset.pane); }); });
    activate(<?= json_encode($rb_tab) ?>);
    rbTtsMode();
})();
</script>
<?php
if ($rb_frame) { LBWeb::lbfooter(); }
