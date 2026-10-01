<?php
/**
 * Saugroboter (Valetudo) - Admin-Oberflaeche
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Protokoll
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * WICHTIG: LBWeb::lbheader() setzt SDK-GLOBALS (u.a. $cfg aus general.json als
 * stdClass) und wuerde gleichnamige Plugin-Variablen ueberschreiben - daher
 * tragen hier ALLE Variablen ein rb_-Praefix.
 *
 * ==================================================================
 * DIE REIHENFOLGE IN DIESER DATEI IST BAUVORSCHRIFT
 * ==================================================================
 *
 *   1. Bibliothek laden
 *   2. Konfiguration lesen, Vorgaben vervollstaendigen, Token erzeugen
 *   3. WACHPOSTEN
 *   4. Reiterwahl
 *   5. Handler - darunter JEDER Download, der mit exit endet
 *   6. ERST JETZT LBWeb::lbheader()
 *   7. HTML
 *
 * Bis 1.0.14 standen die beiden Sicherungs-Handler hinter lbheader(). Der
 * Seitenkopf war damit schon geschrieben, und header('Content-Type: ...')
 * kam zu spaet. Gemessen mit PHP 8.4:
 *
 *   WARNUNG|Cannot modify header information - headers already sent|index.php:251
 *   WARNUNG|dasselbe|index.php:252
 *   Antwortkoerper: <!-- lbheader -->{ "robots": [], ... }
 *
 * Der Knopf "Einstellungen sichern" lieferte also KEINE Datei. Am PHP-CLI
 * ist der Fehler unsichtbar, weil header() dort wirkungslos ist und
 * headers_sent() immer falsch liefert - alle drei Hauswerkzeuge fuer die
 * Sicherung meldeten gruen.
 * ==================================================================
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* ---------- 1. Bibliothek ----------
 *
 * Installiert sind html/ und htmlauth/ ZWEI GETRENNTE Baeume. Welche Lage
 * gilt, entscheidet der eigene Ablageort, nicht die Reihenfolge der
 * Versuche: liegt diese Datei unter <Wurzel>/webfrontend/htmlauth/plugins/
 * <ordner>, ist sie installiert, und die Bibliothek liegt im html-Zweig
 * unter demselben Ordnernamen; sonst liegt sie in einem ausgepackten Archiv
 * gleich daneben. Bis 1.1.9 wurden Kandidaten der Reihe nach probiert, der
 * installierte VOR der eigenen Bibliothek - aus einem Archiv unter /plugin
 * war das //html/plugins/htmlauth/robo_lib.php ab der Laufwerkswurzel, und
 * was dort lag, lief als Bibliothek (in WSL gemessen,
 * Pruefung-Saugroboter-Valetudo-1.1.10, Fall C6; Bauart AWM-Abfuhr 1.4.13).
 * Wurzel und Ordnername kommen danach aus ro_paths() - EINE Stelle fuer die
 * Wurzelregel (general.json, Archivmodus).
 *
 * Findet sich die Bibliothek nicht, bricht die Seite mit lesbarem Text
 * ab - bis 1.0.14 lief sie weiter und starb mitten im <style>-Block an
 * einem "Call to undefined function ro_t()".
 */
if (basename(dirname(__DIR__)) === 'plugins' && basename(dirname(dirname(__DIR__))) === 'htmlauth') {
    $rb_gefunden = dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/robo_lib.php';
} else {
    $rb_gefunden = dirname(__DIR__) . '/html/robo_lib.php';   // ausgepacktes Archiv
}
if (!is_file($rb_gefunden)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Saugroboter: robo_lib.php nicht gefunden.\nGesucht in:\n  " . $rb_gefunden . "\n";
    exit;
}
require_once $rb_gefunden;

/* ---------- 2. Konfiguration ---------- */
$rb_p = ro_paths();
$rb_lbhome = $rb_p['lbhome'];
$rb_plugin = $rb_p['plugin'];
$rb_cfgdir = dirname($rb_p['config']);
$rb_cfgfile = $rb_p['config'];
$rb_bkfile = $rb_p['backup'];
$rb_logfile = $rb_p['log'];

if ($rb_lbhome) {
    $rb_sdk = $rb_lbhome . '/libs/phplib/loxberry_system.php';
    if (file_exists($rb_sdk)) { require_once $rb_sdk; require_once $rb_lbhome . '/libs/phplib/loxberry_web.php'; }
}

$rb_cfg = ro_config();

// Beim ersten Aufruf ein Token erzeugen, damit der Endpunkt fuer Loxone sofort
// benutzbar ist (schuetzt ?cmd= im unangemeldeten robo.php). Aus ihm leitet
// sich auch das Formularmerkmal des Wachpostens ab.
//
// U6 (Durchgang 01.10.2026): ist schon ein Roboter eingetragen, war vorher
// eine Anlage eingerichtet - dann sagt die Seite, dass die Adressen in Loxone
// nicht mehr passen. Bis 1.1.11 wurde still gewuerfelt (Oberflaechen-Pruefer
// Fall 8, nach einer Sicherung mit leerem Token).
$rb_token_meldung = '';
if (empty($rb_cfg['aktionstoken'])) {
    $rb_cfg['aktionstoken'] = ro_token_erzeugen();
    ro_config_speichern($rb_cfg);
    $rb_cfg = ro_config();
    if (ro_robots()) { $rb_token_meldung = ro_t('TEXT.TOKEN_ERZEUGT_ANLAGE'); }
}
$rb_fmt = ro_formtoken($rb_cfg);

$rb_meldungen = array();
$rb_fehler = array();
$rb_saved = false;

/* ---------- PRG: Umleitung nach jedem POST (U1, Durchgang 01.10.2026) ----------
 *
 * Regeln/04 "Jeder POST-Handler endet mit einer Umleitung", Entscheidung
 * Nr. 19. Bis 1.1.11 antworteten alle sieben POST-Zweige mit HTTP 200 und der
 * ganzen Seite; F5 wiederholte die Handlung: "Neues Token erzeugen" wuerfelte
 * ein zweites Token (die eben abgeschriebenen Loxone-Adressen liefen auf 403),
 * "Filter zuruecksetzen" setzte den Zaehler im Roboter ein zweites Mal zurueck
 * (in WSL gemessen, Oberflaechen-Pruefer Faelle 1-3).
 *
 * Das Ergebnis reist in data/plugins/<ordner>/einmalmeldung.json, Rechte
 * 0600, und wird NUR beim GET gelesen und dabei geloescht. Aelter als zwei
 * Minuten wird verworfen - sie erschiene sonst als Antwort auf eine Handlung,
 * die niemand ausgeloest hat. Die Downloads (Vorlagen, Sicherung) bleiben
 * ohne Umleitung. Bauform Abfahrtsassistent 1.6.19. */
function rb_flash_datei()
{
    return ro_datadir() . '/einmalmeldung.json';
}
function rb_umleiten($tab, array $inhalt)
{
    $inhalt['tab'] = $tab;
    $inhalt['zeit'] = time();
    $js = json_encode($inhalt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($js === false || !ro_write_atomic(rb_flash_datei(), $js, 0600)) {
        ro_log('Die Einmalmeldung liess sich nicht schreiben - nach der Umleitung fehlt die Rueckmeldung.');
    }
    header('Location: index.php?form=' . rawurlencode((string) preg_replace('/^tab-/', '', $tab)), true, 303);
    exit;
}
function rb_flash_lesen()
{
    $f = rb_flash_datei();
    if (!is_file($f)) { return array(); }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || time() - (int) $d['zeit'] > 120) { return array(); }
    return $d;
}

/* ---------- X-2: Eingaben nach einer Beanstandung (U4, Durchgang 01.10.2026) ----------
 *
 * Regeln/04, "Nach einer Beanstandung stehen die eingetippten Werte wieder im
 * Formular". Mit der Einmalmeldung reisen unter 'eingaben' die Felder des
 * EINEN beanstandeten Formulars und die Namen der beanstandeten Felder. Nie
 * mit reisen: die Valetudo-Kennwoerter (r_pass[]), das Sprechtoken fuer
 * Alexa-NG (tts_alexa_token), das fuer Chromecast 4 Lox NG (tts_google_token,
 * Ansage-3) und das Formularmerkmal - sie stehen in keiner
 * der Listen unten; ihre Felder koennen markiert werden, ihr Wert reist nie
 * mit. Ein Wert, der kein gueltiges UTF-8 ist oder laenger als 2100 Byte,
 * reist nicht mit; sein Feld zeigt dann den gespeicherten Wert (und bleibt
 * markiert). Bauform Abfahrtsassistent 1.6.19 (abf_*). */
function rb_eingabe_felder($formular)
{
    if ($formular === 'mqtt') {
        return array('mqtt_enabled', 'mqtt_topic');
    }
    if ($formular === 'settings') {
        return array('r_name', 'r_ip', 'r_port', 'r_user', 'r_pass_loeschen', 'cache_sec', 'warn_hours',
                     'warn_prozent', 'notify_audio', 'notify_push', 'n_fertig', 'n_fehler', 'n_material',
                     'n_ereignis', 'tts_mode', 'tts_ip', 'tts_port', 'tts_zones', 'tts_volume', 'tts_lang',
                     'tts_template', 'tts_alexa_geraet', 'tts_alexa_laut', 'tts_alexa_token_loeschen',
                     'tts_google_geraet', 'tts_google_laut', 'tts_google_token_loeschen');
    }
    return array();
}
/* Ein einzelner Wert, der mitreisen darf: Zeichenkette, UTF-8, hoechstens 2100 Byte. */
function rb_eingabe_tauglich($w)
{
    return is_string($w) && strlen($w) <= 2100 && preg_match('//u', $w) === 1;
}
/* Ein Feld beanstanden (Name, bei Tabellenzeilen mit Index); ohne Argument die Liste. */
function rb_bean($feld = null, $idx = null)
{
    static $liste = array();
    if ($feld !== null) {
        $n = (string) $feld . ($idx !== null ? '[' . (int) $idx . ']' : '');
        if (!in_array($n, $liste, true)) { $liste[] = $n; }
    }
    return $liste;
}
/* Welche Formularfelder gehoeren zu einem abgewiesenen Wert (zweite Wache)? */
function rb_bean_aus_wert($schluessel, $wert)
{
    if ($schluessel === 'tts' && is_array($wert)) {
        foreach ($wert as $uk => $uw) {
            if (ro_wert_pruefen('tts', array($uk => $uw)) !== '') { rb_bean('tts_' . $uk); }
        }
        return;
    }
    if (in_array($schluessel, array('cache_sec', 'warn_hours', 'warn_prozent'), true)) { rb_bean($schluessel); }
}
/* Die Eingaben eines Formulars aus $_POST sammeln - nur die Felder der Liste. */
function rb_eingaben_sammeln($formular)
{
    $werte = array();
    foreach (rb_eingabe_felder($formular) as $f) {
        if (!isset($_POST[$f])) { continue; }
        $w = $_POST[$f];
        if (is_array($w)) {
            $zeilen = array();
            foreach ($w as $k => $v) {
                if (count($zeilen) >= 4 || !preg_match('/^\d\z/', (string) $k)) { continue; }
                if (rb_eingabe_tauglich($v)) { $zeilen[(string) (int) $k] = $v; }
            }
            $werte[$f] = $zeilen;
        } elseif (rb_eingabe_tauglich($w)) {
            $werte[$f] = $w;
        }
    }
    return array('formular' => $formular, 'werte' => $werte, 'falsch' => rb_bean());
}
/* Beim GET: die Eingaben aus der Einmalmeldung pruefen (Form, Felder der
 * Liste, nichts anderes) und fuer die Seite ablegen. */
function rb_eingaben($setzen = null)
{
    static $e = null;
    if ($setzen !== null) {
        $e = null;
        if (is_array($setzen) && isset($setzen['formular'], $setzen['werte'], $setzen['falsch'])
            && is_string($setzen['formular']) && is_array($setzen['werte']) && is_array($setzen['falsch'])) {
            $erlaubt = rb_eingabe_felder($setzen['formular']);
            $werte = array();
            foreach ($setzen['werte'] as $f => $w) {
                if (!in_array((string) $f, $erlaubt, true)) { continue; }
                if (is_array($w)) {
                    $werte[$f] = array();
                    foreach ($w as $k => $v) { if (rb_eingabe_tauglich($v)) { $werte[$f][(string) $k] = $v; } }
                } elseif (rb_eingabe_tauglich($w)) {
                    $werte[$f] = $w;
                }
            }
            $falsch = array();
            foreach ($setzen['falsch'] as $n) {
                if (is_string($n) && preg_match('/^[a-z_]+(\[\d\])?\z/', $n)) { $falsch[] = $n; }
            }
            if ($erlaubt) { $e = array('formular' => $setzen['formular'], 'werte' => $werte, 'falsch' => $falsch); }
        }
    }
    return $e;
}
/* Gilt fuer dieses Feld eine Eingabe? Nur, wenn es zum beanstandeten Formular gehoert. */
function rb_eingabe_aktiv($feld)
{
    $e = rb_eingaben();
    return $e !== null && in_array($feld, rb_eingabe_felder($e['formular']), true);
}
/* Wert eines Textfelds: die Eingabe, sonst der gespeicherte Wert. */
function rb_w($feld, $gespeichert, $idx = null)
{
    if (rb_eingabe_aktiv($feld)) {
        $e = rb_eingaben();
        $w = isset($e['werte'][$feld]) ? $e['werte'][$feld] : null;
        if ($idx !== null) { $w = (is_array($w) && isset($w[(string) (int) $idx])) ? $w[(string) (int) $idx] : null; }
        if (is_string($w)) { return $w; }
    }
    return (string) $gespeichert;
}
/* Haken: nach einer Beanstandung so, wie er abgeschickt wurde. */
function rb_haken($feld, $gespeichert, $idx = null)
{
    if (!rb_eingabe_aktiv($feld)) { return (bool) $gespeichert; }
    $e = rb_eingaben();
    $w = isset($e['werte'][$feld]) ? $e['werte'][$feld] : null;
    if ($idx !== null) { return is_array($w) && isset($w[(string) (int) $idx]); }
    return $w !== null;
}
/* Markierung eines beanstandeten Felds (Attribute, schon maskiert). */
function rb_m($feld, $idx = null)
{
    $e = rb_eingaben();
    $n = (string) $feld . ($idx !== null ? '[' . (int) $idx . ']' : '');
    return ($e !== null && in_array($n, $e['falsch'], true)) ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}

/* ---------- 3. Wachposten gegen fremde Absender ----------
 *
 * htmlauth/ schuetzt gegen den unangemeldeten Aufruf, NICHT dagegen, dass
 * der Browser eines ANGEMELDETEN Bedieners ein Formular abschickt, das auf
 * einer fremden Seite steht: die Anmeldung schickt er automatisch mit.
 *
 * Bis 1.0.14 gab es hier gar nichts. Ein fremdes Formular genuegte, um mit
 * "token_neu" saemtliche Loxone-Adressen unbrauchbar zu machen oder mit
 * "ro_zurueck" die ganze Konfiguration zu ersetzen.
 *
 * Einen einzelnen Handler kann man beim Erweitern vergessen, einen
 * Wachposten am Eingang nicht.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($rb_fmt === '') {
        $rb_fehler[] = ro_t('FEHLER.CSRF_KEIN_TOKEN');
    } elseif (!ro_formtoken_ok($rb_cfg)) {
        $rb_fehler[] = ro_t('FEHLER.CSRF');
        ro_log('Ein Formular ohne gueltiges Merkmal wurde abgewiesen.');
    }
    if ($rb_fehler) {
        // $_POST leeren, damit danach KEIN Handler mehr anlaeuft. Den aktiven
        // Reiter behalten - die Meldung soll dort stehen, wo der Bediener war.
        $rb_behalten = isset($_POST['activetab']) && is_string($_POST['activetab'])
            ? $_POST['activetab'] : null;
        $_POST = array();
        if ($rb_behalten !== null) { $_POST['activetab'] = $rb_behalten; }
    }
}

/* ---------- 4. Reiterwahl ----------
 * Diese Liste, die Leiste weiter unten und die id der Flaechen muessen
 * deckungsgleich bleiben - alle drei. Der Reiter Test prueft es nach:
 * ro_reiterlage() liest diese Datei und vergleicht die drei Stellen
 * miteinander. Bis 1.1.3 stand derselbe Satz hier, und die Pruefung gab es
 * nicht - nachgewiesen durch Rueckbau am 04.09.2026.
 */
$rb_reiterliste = array('tab-settings', 'tab-mqtt', 'tab-loxone', 'tab-test', 'tab-log');
$rb_tab = 'tab-settings';
if (isset($_POST['activetab']) && is_string($_POST['activetab'])
    && in_array((string) $_POST['activetab'], $rb_reiterliste, true)) {
    $rb_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && is_string($_GET['form'])
          && in_array('tab-' . (string) $_GET['form'], $rb_reiterliste, true)) {
    $rb_tab = 'tab-' . (string) $_GET['form'];
}

/* ---------- 5. Handler ---------- */

/* U1: ein abgewiesenes Formular (Wachposten) endet ebenfalls mit einer Umleitung. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $rb_fehler) {
    rb_umleiten($rb_tab, array('fehler' => $rb_fehler));
}
/* U1: beim GET die Einmalmeldung des vorigen POST lesen (und loeschen). */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $rb_flash = rb_flash_lesen();
    foreach (array('meldungen' => 'rb_meldungen', 'fehler' => 'rb_fehler') as $rb_fk => $rb_fv) {
        if (isset($rb_flash[$rb_fk]) && is_array($rb_flash[$rb_fk])) {
            foreach ($rb_flash[$rb_fk] as $rb_fm) { if (is_string($rb_fm)) { ${$rb_fv}[] = $rb_fm; } }
        }
    }
    $rb_saved = !empty($rb_flash['saved']);
    if (isset($rb_flash['tab']) && is_string($rb_flash['tab']) && in_array($rb_flash['tab'], $rb_reiterliste, true)) {
        $rb_tab = $rb_flash['tab'];
    }
    rb_eingaben(isset($rb_flash['eingaben']) ? $rb_flash['eingaben'] : array());     // X-2
}
if ($rb_token_meldung !== '') { $rb_fehler[] = rb_e($rb_token_meldung); }

// --- Downloads. Sie enden mit exit und muessen VOR lbheader() stehen. ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vorlage_vo'])) {
    list($rb_vname, $rb_vinhalt) = ro_vo_vorlage(isset($_POST['vorlage_dev']) ? (int) $_POST['vorlage_dev'] : 1);
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $rb_vname . '"');
    echo $rb_vinhalt;
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vorlage'])) {
    list($rb_vname, $rb_vinhalt) = ro_vorlage(
        isset($_POST['vorlage_dev']) ? (int) $_POST['vorlage_dev'] : 1,
        !empty($_POST['vorlage_belegt']));
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $rb_vname . '"');
    echo $rb_vinhalt;
    exit;
}

/* Einstellungen sichern.
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin
 * kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das.
 *
 * Das FORMULARMERKMAL gehoert ausdruecklich NICHT hinein: es lebt eine
 * Sitzung und schuetzt gegen fremde Absender. Es wird aus dem Aktionstoken
 * abgeleitet und steht deshalb gar nicht erst in der Konfiguration. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ro_sichern'])) {
    $rb_sich = ro_sicherung_bauen();
    $rb_js = json_encode($rb_sich,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($rb_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="saugroboter_einstellungen_'
               . date('Ymd_His') . '.json"');
        /* U5 (X-3, Durchgang 01.10.2026): wuerde das eigene Zurueckspielen die
         * Datei abweisen, sagt es der Kopf - nur Namen, nie Werte. Geliefert
         * wird sie trotzdem vollstaendig; die Datei traegt _warnung, und ueber
         * dem Knopf steht der gelbe Hinweis. */
        if (isset($rb_sich['_warnung'])) {
            header('X-Saugroboter-Warnung: ' . preg_replace('/[^A-Za-z0-9_, ]/', '', implode(', ', ro_sicherung_warnung($rb_sich))));
        }
        header('Content-Length: ' . strlen($rb_js));
        echo $rb_js;
        exit;
    }
    rb_umleiten('tab-settings', array('fehler' => array(ro_t('TEXT.SICH_SCHREIBFEHLER'))));
}

/* Einstellungen zurueckspielen.
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei
 * des Servers unterschieben. Dann die Groessengrenze - eine Sicherung
 * dieses Plugins ist wenige Kilobyte gross; alles darueber wird gar
 * nicht erst gelesen. U1: das Ergebnis reist mit der Umleitung. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ro_zurueck'])) {
    if (!isset($_FILES['ro_sicherung']) || !is_array($_FILES['ro_sicherung'])
        || !isset($_FILES['ro_sicherung']['tmp_name']) || !is_string($_FILES['ro_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['ro_sicherung']['tmp_name'])) {
        $rb_fehler[] = ro_t('TEXT.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['ro_sicherung']['size'] > 262144) {
        $rb_fehler[] = ro_t('TEXT.SICH_ZU_GROSS');
    } else {
        $rb_erg = array_pad(ro_sicherung_lesen(
            (string) @file_get_contents($_FILES['ro_sicherung']['tmp_name'])), 4, array());
        list($rb_neu, $rb_mangel, $rb_n, $rb_beh) = $rb_erg;
        if ($rb_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert
             * wird nichts. */
            $rb_fehler[] = ro_t('TEXT.SICH_ABGELEHNT') . ' ' . implode(' ', $rb_mangel);
        } elseif (ro_config_speichern($rb_neu)) {
            $rb_meldungen[] = sprintf(ro_t('TEXT.SICH_UEBERNOMMEN'), $rb_n);
            if (is_array($rb_beh) && in_array('aktionstoken', $rb_beh, true)) {
                $rb_meldungen[] = rb_e(ro_t('TEXT.SICH_TOKEN_BEHALTEN'));
            }
        } else {
            $rb_fehler[] = ro_t('TEXT.SICH_SCHREIBFEHLER');
        }
    }
    rb_umleiten('tab-settings', array('meldungen' => $rb_meldungen, 'fehler' => $rb_fehler));
}

// --- Protokoll leeren ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearlog'])) {
    @mkdir(dirname($rb_logfile), 0775, true);
    if (@file_put_contents($rb_logfile, '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Admin-Oberflaeche)\n") !== false) {
        $rb_meldungen[] = rb_e(ro_t('TEXT.LOG_GELEERT'));
    } else {
        $rb_fehler[] = rb_e(ro_t('TEXT.LOG_NICHT_GELEERT') . ' ' . $rb_logfile);
    }
    rb_umleiten('tab-log', array('meldungen' => $rb_meldungen, 'fehler' => $rb_fehler));
}

// --- Neues Aktionstoken erzeugen (U1: F5 wuerfelt kein zweites) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token_neu'])) {
    $rb_neu = ro_config();
    $rb_neu['aktionstoken'] = ro_token_erzeugen();
    if (ro_config_speichern($rb_neu)) {
        $rb_meldungen[] = rb_e(ro_t('TEXT.TOKEN_NEU_OK'));
    } else {
        $rb_fehler[] = rb_e(ro_t('TEXT.SICH_SCHREIBFEHLER'));
    }
    rb_umleiten('tab-loxone', array('meldungen' => $rb_meldungen, 'fehler' => $rb_fehler));
}

// --- Verbrauchsteil zuruecksetzen (Reiter Test; U1: F5 setzt nicht erneut zurueck) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ro_reset'])) {
    $rb_teil = is_string($_POST['ro_reset']) ? (string) $_POST['ro_reset'] : '';
    $rb_rdev = (isset($_POST['reset_dev']) && is_string($_POST['reset_dev']) && preg_match('/^[1-9]\z/', $_POST['reset_dev']))
        ? (int) $_POST['reset_dev'] : 1;
    if (preg_match('#^[a-z]+(/[a-z_]+)?$#', $rb_teil)) {
        list($rb_ok, $rb_info) = ro_command('reset', $rb_rdev, $rb_teil);
        if ($rb_ok) {
            $rb_meldungen[] = rb_e(sprintf(ro_t('TEXT.RESET_OK'), $rb_teil));
        } else {
            $rb_fehler[] = rb_e(sprintf(ro_t('TEXT.RESET_FEHLER'), $rb_teil, $rb_info));
        }
    } else {
        $rb_fehler[] = rb_e(ro_t('TEXT.RESET_UNGUELTIG'));
    }
    rb_umleiten('tab-test', array('meldungen' => $rb_meldungen, 'fehler' => $rb_fehler));
}

// --- Raumliste und Faehigkeiten neu einlesen ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['neu_lesen'])) {
    ro_cache_leeren();
    rb_umleiten('tab-test', array('meldungen' => array(rb_e(ro_t('TEXT.NEU_GELESEN')))));
}

/* --- Testansage (U14, Durchgang 01.10.2026) ---
 * Spricht einen festen Satz ueber den eingestellten Ausgabeweg - auch ueber
 * Alexa-NG. Gesprochen heisst: HTTP 2xx bzw. SPRECHEN;OK=1. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ro_testansage'])) {
    $rb_tm = isset($rb_cfg['tts']['mode']) ? (string) $rb_cfg['tts']['mode'] : '';
    if ($rb_tm === 'audioserver') {
        $rb_fehler[] = rb_e(ro_t('TEXT.TESTANSAGE_AUDIOSERVER'));
    } elseif (ro_say(ro_t('TEXT.TESTANSAGE_TEXT'))) {
        $rb_meldungen[] = rb_e(ro_t('TEXT.TESTANSAGE_OK'));
        /* Ansage-3: bei Google steht die Antwort immer dabei (HTTP-Code und GRUND,
         * z. B. EINGEREIHT oder UNVERAENDERT) - nie Token oder Text. */
        $rb_td = $rb_tm === 'cc4lox' ? ro_sprech_letzte('google') : null;
        if (is_array($rb_td) && isset($rb_td['code'], $rb_td['antwort'])) {
            $rb_meldungen[] = rb_e(sprintf(ro_t('TEXT.GOOGLE_ANTWORT'), (int) $rb_td['code'], (string) $rb_td['antwort']));
        }
    } else {
        $rb_tg = '';
        // Ansage-2/-3: der Grund aus der Merkdatei der gewaehlten Sprech-Ausgabeart.
        $rb_tart = $rb_tm === 'alexang' ? 'alexa' : ($rb_tm === 'cc4lox' ? 'google' : '');
        $rb_td = $rb_tart !== '' ? ro_sprech_letzte($rb_tart) : null;
        if (is_array($rb_td) && isset($rb_td['grund']) && is_string($rb_td['grund'])) {
            $rb_tg = $rb_td['grund'];
        }
        $rb_fehler[] = rb_e(ro_t('TEXT.TESTANSAGE_FEHL') . ($rb_tg !== '' ? ' ' . $rb_tg : ''));
    }
    rb_umleiten('tab-test', array('meldungen' => $rb_meldungen, 'fehler' => $rb_fehler));
}

/* --- MQTT speichern (eigener Reiter seit 1.0.10, Hausstandard) ---
 *
 * U3 (Durchgang 01.10.2026, Nr. 19): ein Praefix mit Zeichen, die im Thema
 * nichts zu suchen haben, wird BEANSTANDET, nicht umgebaut - bis 1.1.11 wurde
 * "Mein Haus!" als "MeinHaus" gespeichert, ein leeres Feld still als saugrobo
 * (Oberflaechen-Pruefer Fall 5, MQTT-Pruefer Fall 7). Still bleibt nur der
 * Leerraum am Rand. Nach dem Speichern fuehrt das Plugin die Abo-Datei des
 * Gateways nach (M5); einen Praefixwechsel und das Ausschalten merkt
 * ro_config_speichern() zum Abraeumen vor (M3). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mqtt_save'])) {
    $rb_thema = !isset($_POST['mqtt_topic']) ? '' : (is_string($_POST['mqtt_topic']) ? trim($_POST['mqtt_topic']) : null);
    if ($rb_thema === null || $rb_thema === '' || ro_mqtt_thema_saeubern($rb_thema) !== $rb_thema
        || ro_wert_pruefen('mqtt_topic', $rb_thema) !== '') {
        rb_bean('mqtt_topic');
        rb_umleiten('tab-mqtt', array('fehler' => array(rb_e(ro_t('TEXT.NICHTS_GESPEICHERT')),
            $rb_thema === '' ? rb_e(ro_t('TEXT.MQTT_THEMA_LEER'))
                             : sprintf(ro_t('TEXT.MQTT_THEMA_UNGUELTIG'), rb_e((string) $rb_thema))),
            'eingaben' => rb_eingaben_sammeln('mqtt')));
    }
    $rb_neu = ro_config();
    $rb_neu['mqtt_enabled'] = isset($_POST['mqtt_enabled']) ? 1 : 0;
    $rb_neu['mqtt_topic'] = $rb_thema;
    if (ro_config_speichern($rb_neu)) {
        ro_abo_datei($rb_thema, true);
        rb_umleiten('tab-mqtt', array('saved' => 1));
    }
    rb_umleiten('tab-mqtt', array('fehler' => array(rb_e(ro_t('TEXT.NICHT_GESPEICHERT') . ' ' . $rb_cfgfile)),
        'eingaben' => rb_eingaben_sammeln('mqtt')));
}

/* --- Einstellungen speichern ---
 *
 * U2/U3 (Durchgang 01.10.2026, Entscheidungen Nr. 16 und 19): bei einer
 * Beanstandung wird NICHTS gespeichert - auch nicht die uebrigen richtigen
 * Felder -, das Feld ist markiert, und die Eingaben kommen zurueck (X-2).
 * Geprueft werden die ROHWERTE (Ziffernmuster, Bereich, Positivliste); nichts
 * wird mehr geklemmt, durch eine Vorgabe ersetzt oder still verworfen. Bis
 * 1.1.11 speicherte ein POST mit cache_sec=999, warn_hours=-5, tts_port=70000,
 * tts_volume=0, tts_mode=alexa und r_port=0 die Werte 300/0/65535/1/
 * musicserver/1, cache_sec=abc wurde 5, und ein zweiter Roboter mit
 * ungueltiger Adresse wurde still verworfen, waehrend der Rest gespeichert
 * wurde (Oberflaechen-Pruefer Faelle 4 und 5). Still bleiben nur: Leerraum am
 * Rand und das Kleinschreiben des Sprachkuerzels (wie der Laendercode,
 * Entscheidung Nr. 21). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $rb_hw = array();
    // Ein Textfeld: Leerraum am Rand faellt still weg; ein Feld (name[]) ist eine Beanstandung (null).
    $rb_text = function ($k) {
        if (!isset($_POST[$k])) { return ''; }
        return is_string($_POST[$k]) ? trim($_POST[$k]) : null;
    };
    $rb_zeile = function ($k, $i) {
        if (!isset($_POST[$k])) { return ''; }
        if (!is_array($_POST[$k])) { return null; }
        if (!isset($_POST[$k][$i])) { return ''; }
        return is_string($_POST[$k][$i]) ? trim($_POST[$k][$i]) : null;
    };
    $rb_zahl = function ($w, $min, $max) {
        return (is_string($w) && preg_match('/^-?[0-9]{1,9}\z/', $w) && (int) $w >= $min && (int) $w <= $max)
            ? (int) $w : null;
    };
    $rb_wert = function ($bez, $grund) {
        return sprintf(ro_t('TEXT.SICH_WERT'), rb_e($bez), rb_e($grund));
    };

    /* Aus dem Bestand uebernehmen, was dieses Formular nicht mitschickt.
     * BIS 1.0.9 FEHLTE DAS FUER aktionstoken: jedes Speichern der Einstellungen
     * warf das Token still weg, der naechste Seitenaufruf erzeugte ein NEUES -
     * und alle Loxone-Adressen liefen auf 403. */
    $rb_neu = ro_config();
    $rb_neu['robots'] = array();
    $rb_alt = ro_robots();
    $rb_nr_feld = (isset($_POST['r_nr']) && is_array($_POST['r_nr'])) ? $_POST['r_nr'] : array();
    $rb_vergeben = array();
    for ($rb_i = 0; $rb_i < 2; $rb_i++) {
        /* DIE GERAETENUMMER REIST MIT DER ZEILE, NICHT MIT DER POSITION.
         *
         * An ihr haengen virtueller Eingang, MQTT-Thema und Endpunktadresse.
         * Bis 1.1.3 war sie eine Aufzaehlung ueber die nicht leeren Zeilen:
         * wer die erste Adresse loeschte, bekam fuer &dev=1 den zweiten
         * Roboter. Und das Kennwort wurde ueber $rb_alt[$rb_i + 1] geholt,
         * also ebenfalls ueber die Position - gemessen am 04.09.2026 ging es
         * dabei still verloren, sobald die erste Zeile schon ohne Adresse
         * dastand. Beides haengt jetzt am versteckten Feld r_nr[]. */
        $rb_nr = (isset($rb_nr_feld[$rb_i]) && is_string($rb_nr_feld[$rb_i]) && preg_match('/^[1-9]\z/', $rb_nr_feld[$rb_i]))
            ? (int) $rb_nr_feld[$rb_i] : 0;
        // Kam die Nummer wirklich aus dem Formular? Nur dann darf ueber sie
        // ein Kennwort geerbt werden (siehe weiter unten).
        $rb_nr_echt = ($rb_nr >= 1 && $rb_nr <= 9 && !in_array($rb_nr, $rb_vergeben, true));
        if (!$rb_nr_echt) {
            $rb_nr = 1;
            while (in_array($rb_nr, $rb_vergeben, true)) { $rb_nr++; }
        }
        $rb_ip = $rb_zeile('r_ip', $rb_i);
        if ($rb_ip === null) {
            $rb_hw[] = sprintf(ro_t('TEXT.ROBOTER_ADRESSE_UNGUELTIG'), $rb_nr);
            rb_bean('r_ip', $rb_i);
            continue;
        }
        // Eine Zeile ohne Adresse ist ungenutzt (so steht es am Feld).
        if ($rb_ip === '') { continue; }
        $rb_vergeben[] = $rb_nr;
        /* U2 (Nr. 16): eine ungueltige Adresse SPERRT das Speichern. Bis
         * 1.1.11 wurde sie gemeldet ("der bisherige Eintrag bleibt stehen" -
         * auch wenn es keinen gab), und der Rest wurde gespeichert. */
        if (!preg_match('/^[\w\.\-]{1,253}\z/', $rb_ip)) {
            $rb_hw[] = sprintf(ro_t('TEXT.ROBOTER_ADRESSE_UNGUELTIG'), $rb_nr);
            rb_bean('r_ip', $rb_i);
        }
        $rb_port = $rb_zahl($rb_zeile('r_port', $rb_i), 1, 65535);
        if ($rb_port === null) {
            $rb_hw[] = $rb_wert(sprintf(ro_t('EINST.ROBOTER_FELD'), $rb_nr, ro_t('EINST.PORT')), ro_t('GRUND.PORT'));
            rb_bean('r_port', $rb_i);
        }
        $rb_name = $rb_zeile('r_name', $rb_i);
        $rb_user = $rb_zeile('r_user', $rb_i);
        foreach (array('r_name' => $rb_name, 'r_user' => $rb_user) as $rb_fk => $rb_fv) {
            if ($rb_fv === null || preg_match('/[\x00-\x1F\x7F]/', (string) $rb_fv)) {
                $rb_hw[] = $rb_wert(sprintf(ro_t('EINST.ROBOTER_FELD'), $rb_nr,
                    ro_t($rb_fk === 'r_name' ? 'EINST.NAME' : 'EINST.BENUTZER')), ro_t('TEXT.SICH_STEUERZEICHEN'));
                rb_bean($rb_fk, $rb_i);
            }
        }
        /* Ein leeres Kennwortfeld LOESCHT nicht - der Browser fuellt
         * type=password nicht vor. Geerbt wird aber NUR ueber eine Nummer, die
         * wirklich aus dem Formular kam.
         *
         * Ohne diese Bedingung haette der Umbau den alten Fehler nur
         * verschoben: gemessen an einem POST ohne r_nr[] (alte Formularseite,
         * von Hand gebauter Aufruf) bekam Roboter 2 das Kennwort von
         * Roboter 1, weil die Nummer auf "naechste freie" zurueckfiel.
         * Fail closed: lieber ein sichtbarer Verlust als ein stiller Griff in
         * die falsche Zeile. */
        $rb_pw_roh = (isset($_POST['r_pass']) && is_array($_POST['r_pass']) && isset($_POST['r_pass'][$rb_i]))
            ? $_POST['r_pass'][$rb_i] : '';
        $rb_loeschen = (isset($_POST['r_pass_loeschen']) && is_array($_POST['r_pass_loeschen'])
            && !empty($_POST['r_pass_loeschen'][$rb_i]));
        if (!is_string($rb_pw_roh) || preg_match('/[\x00-\x1F\x7F]/', $rb_pw_roh)) {
            $rb_hw[] = $rb_wert(sprintf(ro_t('EINST.ROBOTER_FELD'), $rb_nr, ro_t('EINST.KENNWORT')),
                ro_t('TEXT.SICH_STEUERZEICHEN'));
            rb_bean('r_pass', $rb_i);
            $rb_pw_roh = '';
        }
        $rb_pw = $rb_pw_roh;
        if ($rb_pw === '' && $rb_nr_echt && !$rb_loeschen && isset($rb_alt[$rb_nr]['pass'])) {
            $rb_pw = (string) $rb_alt[$rb_nr]['pass'];
        }
        if ($rb_loeschen) { $rb_pw = ''; }
        $rb_neu['robots'][] = array(
            'nr' => $rb_nr,
            'name' => (string) $rb_name,
            'ip' => $rb_ip,
            'port' => (int) $rb_port,
            'user' => (string) $rb_user,
            'pass' => $rb_pw);
    }
    foreach (array('cache_sec' => array(5, 300, 'EINST.CACHE'), 'warn_hours' => array(0, 200, 'EINST.WARN_STUNDEN'),
                   'warn_prozent' => array(0, 100, 'EINST.WARN_PROZENT')) as $rb_k => $rb_b) {
        $rb_z = $rb_zahl($rb_text($rb_k), $rb_b[0], $rb_b[1]);
        if ($rb_z === null) {
            $rb_hw[] = $rb_wert(ro_t($rb_b[2]), sprintf(ro_t('GRUND.BEREICH'), $rb_b[0], $rb_b[1]));
            rb_bean($rb_k);
        } else {
            $rb_neu[$rb_k] = $rb_z;
        }
    }
    $rb_neu['notify'] = array(
        'audio' => isset($_POST['notify_audio']) ? 1 : 0,
        'push' => isset($_POST['notify_push']) ? 1 : 0,
        'fertig' => isset($_POST['n_fertig']) ? 1 : 0,
        'fehler' => isset($_POST['n_fehler']) ? 1 : 0,
        'material' => isset($_POST['n_material']) ? 1 : 0,
        'ereignis' => isset($_POST['n_ereignis']) ? 1 : 0,
    );
    // --- Sprachausgabe ---
    $rb_ta = $rb_neu['tts'];
    $rb_t = array();
    $rb_t['mode'] = $rb_text('tts_mode');
    if ($rb_t['mode'] === null || !in_array($rb_t['mode'], ro_tts_wege(), true)) {
        $rb_hw[] = $rb_wert(ro_t('EINST.TTS_WEG'), ro_t('GRUND.TTS_WEG'));
        rb_bean('tts_mode');
        $rb_t['mode'] = (string) $rb_ta['mode'];
    }
    $rb_t['ip'] = $rb_text('tts_ip');
    if ($rb_t['ip'] === null || ($rb_t['ip'] !== '' && !preg_match('/^[\w\.\-]{1,253}\z/', $rb_t['ip']))) {
        $rb_hw[] = $rb_wert(ro_t('EINST.TTS_IP'), ro_t('GRUND.TTS_ADRESSE'));
        rb_bean('tts_ip');
    }
    $rb_t['port'] = $rb_zahl($rb_text('tts_port'), 1, 65535);
    if ($rb_t['port'] === null) {
        $rb_hw[] = $rb_wert(ro_t('EINST.TTS_PORT'), ro_t('GRUND.TTS_PORT'));
        rb_bean('tts_port');
    }
    $rb_t['zones'] = $rb_text('tts_zones');
    if ($rb_t['zones'] === null || !preg_match('/^[0-9,~ ]*\z/', $rb_t['zones'])) {
        $rb_hw[] = $rb_wert(ro_t('EINST.ZONEN'), ro_t('GRUND.ZONEN'));
        rb_bean('tts_zones');
    }
    $rb_t['volume'] = $rb_zahl($rb_text('tts_volume'), 1, 100);
    if ($rb_t['volume'] === null) {
        $rb_hw[] = $rb_wert(ro_t('EINST.LAUTSTAERKE'), ro_t('GRUND.LAUTSTAERKE'));
        rb_bean('tts_volume');
    }
    $rb_t['lang'] = $rb_text('tts_lang');
    if ($rb_t['lang'] !== null) { $rb_t['lang'] = strtolower($rb_t['lang']); }
    if ($rb_t['lang'] === null || !preg_match('/^[a-z]{0,5}\z/', $rb_t['lang'])
        || ($rb_t['mode'] === 'musicserver' && $rb_t['lang'] === '')) {
        $rb_hw[] = $rb_wert(ro_t('EINST.SPRACHE'), ro_t('GRUND.SPRACHE'));
        rb_bean('tts_lang');
    }
    $rb_t['template'] = $rb_text('tts_template');
    if ($rb_t['template'] === null || strlen($rb_t['template']) > 2000
        || preg_match('/[\x00-\x1F\x7F]/', $rb_t['template'])) {
        $rb_hw[] = $rb_wert(ro_t('EINST.TTS_VORLAGE'), ro_t('GRUND.VORLAGE'));
        rb_bean('tts_template');
    }
    /* Ansage-2 (U14): Ausgabeart Alexa-NG. Geraet und Lautstaerke werden
     * beanstandet statt zurechtgebogen. Das Sprechtoken ist ein Kennwort: es
     * steht nie in der Seite und reist nach einer Beanstandung nicht zurueck.
     * Leer abgeschickt heisst behalten, der Haken loescht es, beides zugleich
     * ist ein Widerspruch (Bauform Abfahrtsassistent 1.6.19). */
    $rb_t['alexa_geraet'] = $rb_text('tts_alexa_geraet');
    if ($rb_t['alexa_geraet'] === null || !ro_alexa_geraet_ok($rb_t['alexa_geraet'])) {
        $rb_hw[] = $rb_wert(ro_t('EINST.ALEXA_GERAET'), ro_t('GRUND.ALEXA_GERAET'));
        rb_bean('tts_alexa_geraet');
    }
    $rb_al = $rb_text('tts_alexa_laut');
    if ($rb_al === '') {
        $rb_t['alexa_laut'] = -1;       // leer: die Lautstaerke des Geraets bleibt
    } else {
        $rb_t['alexa_laut'] = $rb_zahl($rb_al, 0, 100);
        if ($rb_t['alexa_laut'] === null) {
            $rb_hw[] = $rb_wert(ro_t('EINST.ALEXA_LAUT'), ro_t('GRUND.ALEXA_LAUT'));
            rb_bean('tts_alexa_laut');
        }
    }
    $rb_t['alexa_token'] = (string) $rb_ta['alexa_token'];
    $rb_atn = $rb_text('tts_alexa_token');
    if ($rb_atn === null) {
        $rb_hw[] = $rb_wert(ro_t('EINST.ALEXA_TOKEN'), ro_t('GRUND.ALEXA_TOKEN'));
        rb_bean('tts_alexa_token');
    } elseif (!empty($_POST['tts_alexa_token_loeschen'])) {
        if ($rb_atn !== '') {
            $rb_hw[] = rb_e(ro_t('TEXT.ALEXA_TOKEN_WIDERSPRUCH'));
            rb_bean('tts_alexa_token');
            rb_bean('tts_alexa_token_loeschen');
        } else {
            $rb_t['alexa_token'] = '';
        }
    } elseif ($rb_atn !== '') {
        if (ro_alexa_token_ok($rb_atn)) {
            $rb_t['alexa_token'] = $rb_atn;
        } else {
            $rb_hw[] = $rb_wert(ro_t('EINST.ALEXA_TOKEN'), ro_t('GRUND.ALEXA_TOKEN'));
            rb_bean('tts_alexa_token');
        }
    }
    if ($rb_t['mode'] === 'alexang' && $rb_t['alexa_token'] === '' && !in_array('tts_alexa_token', rb_bean(), true)) {
        $rb_hw[] = rb_e(ro_t('TEXT.ALEXA_OHNE_TOKEN'));
        rb_bean('tts_alexa_token');
    }
    /* Ansage-3: Ausgabeart Google-Lautsprecher (Chromecast 4 Lox NG), gleiche Regeln
     * wie bei Alexa-NG, eigenes Sprechtoken. Leere Lautstaerke heisst: die
     * Ansagelautstaerke des Chromecast-Plugins (-1). */
    $rb_t['google_geraet'] = $rb_text('tts_google_geraet');
    if ($rb_t['google_geraet'] === null || !ro_alexa_geraet_ok($rb_t['google_geraet'])) {
        $rb_hw[] = $rb_wert(ro_t('EINST.GOOGLE_GERAET'), ro_t('GRUND.GOOGLE_GERAET'));
        rb_bean('tts_google_geraet');
    }
    $rb_gl = $rb_text('tts_google_laut');
    if ($rb_gl === '') {
        $rb_t['google_laut'] = -1;
    } else {
        $rb_t['google_laut'] = $rb_zahl($rb_gl, 0, 100);
        if ($rb_t['google_laut'] === null) {
            $rb_hw[] = $rb_wert(ro_t('EINST.GOOGLE_LAUT'), ro_t('GRUND.GOOGLE_LAUT'));
            rb_bean('tts_google_laut');
        }
    }
    $rb_t['google_token'] = (string) $rb_ta['google_token'];
    $rb_gtn = $rb_text('tts_google_token');
    if ($rb_gtn === null) {
        $rb_hw[] = $rb_wert(ro_t('EINST.GOOGLE_TOKEN'), ro_t('GRUND.GOOGLE_TOKEN'));
        rb_bean('tts_google_token');
    } elseif (!empty($_POST['tts_google_token_loeschen'])) {
        if ($rb_gtn !== '') {
            $rb_hw[] = rb_e(ro_t('TEXT.GOOGLE_TOKEN_WIDERSPRUCH'));
            rb_bean('tts_google_token');
            rb_bean('tts_google_token_loeschen');
        } else {
            $rb_t['google_token'] = '';
        }
    } elseif ($rb_gtn !== '') {
        if (ro_alexa_token_ok($rb_gtn)) {
            $rb_t['google_token'] = $rb_gtn;
        } else {
            $rb_hw[] = $rb_wert(ro_t('EINST.GOOGLE_TOKEN'), ro_t('GRUND.GOOGLE_TOKEN'));
            rb_bean('tts_google_token');
        }
    }
    if ($rb_t['mode'] === 'cc4lox' && $rb_t['google_token'] === '' && !in_array('tts_google_token', rb_bean(), true)) {
        $rb_hw[] = rb_e(ro_t('TEXT.GOOGLE_OHNE_TOKEN'));
        rb_bean('tts_google_token');
    }
    $rb_neu['tts'] = $rb_t;
    /* Dieselbe Wache wie beim Zurueckspielen - eine zweite Wahrheit ueber
     * zulaessige Werte gibt es nicht. Sie laeuft auf den GEPRUEFTEN Werten;
     * vorher stand sie hinter dem Klemmen und schlug nie an. */
    if (!$rb_hw) {
        foreach ($rb_neu as $rb_k => $rb_v) {
            if (!array_key_exists($rb_k, ro_vorgaben())) { continue; }
            $rb_grund = ro_wert_taugt($rb_v) ? ro_wert_pruefen($rb_k, $rb_v) : ro_t('TEXT.SICH_STEUERZEICHEN');
            if ($rb_grund !== '') {
                $rb_hw[] = $rb_wert($rb_k, $rb_grund);
                rb_bean_aus_wert($rb_k, $rb_v);
            }
        }
    }
    if ($rb_hw) {
        array_unshift($rb_hw, rb_e(ro_t('TEXT.NICHTS_GESPEICHERT')));
        rb_umleiten('tab-settings', array('fehler' => $rb_hw, 'eingaben' => rb_eingaben_sammeln('settings')));
    }
    if (ro_config_speichern($rb_neu)) {
        rb_umleiten('tab-settings', array('saved' => 1));
    }
    rb_umleiten('tab-settings', array('fehler' => array(rb_e(ro_t('TEXT.NICHT_GESPEICHERT') . ' ' . $rb_cfgfile)),
        'eingaben' => rb_eingaben_sammeln('settings')));
}

/* U1: ein POST, den kein Handler genommen hat, endet ebenfalls mit einer Umleitung. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rb_umleiten($rb_tab, array());
}

/* ---------- Daten fuer die Anzeige ---------- */
$rb_notify = is_array($rb_cfg['notify']) ? $rb_cfg['notify'] : array();
$rb_notify += array('audio' => 0, 'push' => 0, 'fertig' => 1, 'fehler' => 1, 'material' => 1, 'ereignis' => 1);
$rb_tts = is_array($rb_cfg['tts']) ? $rb_cfg['tts'] : array();
$rb_tts += array('mode' => 'musicserver', 'ip' => '', 'port' => 7091, 'zones' => '1', 'volume' => 8, 'lang' => 'de', 'template' => '');
$rb_robots = ro_robots();
$rb_states = array();
foreach ($rb_robots as $rb_k => $rb_r) { $rb_states[$rb_k] = ro_state($rb_k); }
// ro_log_lesen() liest BEIDE Protokolldateien (Plugin und Schale) und
// nur deren Ende - siehe die Messwerte im Kommentar in robo_lib.php.
$rb_loglines = array_reverse(ro_log_lesen(300));
$rb_host = ro_host();

/** Restlaufzeit anzeigen: Strich, wenn das Geraet den Wert nicht liefert. */
function rb_h($h, $einheit = 'h') { return $h < 0 ? '&ndash;' : (int) $h . '&nbsp;' . $einheit; }
/** Ja / Nein / Strich fuer die dreiwertigen Felder. */
function rb_jn($v) {
    $v = (int) $v;
    if ($v === 1) { return rb_e(ro_t('WORT.JA')); }
    if ($v === 0) { return rb_e(ro_t('WORT.NEIN')); }
    return '&ndash;';
}
/** Das versteckte Feld des Wachpostens - in JEDEM Formular. */
function rb_fmt() {
    global $rb_fmt;
    return '<input data-role="none" type="hidden" name="fmt" value="' . rb_e($rb_fmt) . '">';
}

/* ---------- 6. Erst jetzt der Seitenkopf ---------- */
$rb_frame = class_exists('LBWeb', false);
if ($rb_frame) {
    LBWeb::lbheader(ro_t('TEXT.TITEL') . ' ' . ro_pluginversion(),
        'https://wiki.loxberry.de/', 'help.html');
} else {
    echo '<!DOCTYPE html><html lang="' . rb_e(ro_sprache()) . '"><head><meta charset="utf-8">'
       . '<title>' . rb_e(ro_t('TEXT.TITEL')) . '</title></head><body>';
}
?>
<style>
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=password], .sm-wrap input[type=number], .sm-wrap select, .sm-wrap textarea {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1; min-width: 150px; }
.sm-row > div > label:not([style]) { min-height: 2.6em; display: flex; align-items: flex-end; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-warn { background: #fff8e1; border: 1px solid #ffe082; }
.sm-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.sm-small { font-size: 0.82em; color: #666; margin-top: 3px; }
/* Hinweis und Warnung. Beide gehoeren zum Hausstandard, und sie heissen SO.
   Bis 1.0.14 benutzte das HTML class="sm-warnung", der Stilblock kannte aber
   nur sm-warn - ausgerechnet der Satz, dass die Sicherungsdatei ein Geheimnis
   traegt, stand deshalb als nackter Fliesstext da. */
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; text-decoration: none !important; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.sm-tbl { border-collapse: collapse; margin: 8px 0; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; }
.sm-tbl th { background: #f0f0f0; }
.sm-breit { overflow-x: auto; }
.sm-breit table { width: 100%; }

/* --- Einheitliches Kachel-Raster (Standard aller Plugins) --- */
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
  background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px;
  font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600;
  box-shadow: none !important; text-decoration: none !important;
  display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; }
.sm-wrap .sm-knopfreihe .sm-btn { flex: 0 0 auto; min-width: 250px; text-align: center; margin-top: 0; }
.sm-wrap a.sm-btn:visited, .sm-wrap a.sm-btn:hover, .sm-wrap a.sm-btn:focus { color: #fff !important; }
.sm-wrap .sm-btn.sm-b-lesen,   .sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik, .sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion,  .sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #e0620d !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-pruef td:first-child { width: 26px; text-align: center; font-weight: 700; }
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen. Nachgezogen am
   05.09.2026 nach Regeln/04; Wortlaut aus VORLAGE_hausstandard.css.html.

   Am Geraet gemessen (LoxBerry 4.0.0.15, components.css): die Rahmen-CSS
   zeichnet seit der neuen Oberflaeche selbst einen Pfeil - Regel
   ".lb-content select". Darauf kann sich eine Plugin-Oberflaeche nicht
   verlassen: die Regel gibt es erst seit dieser Fassung, und die eigene
   Feldregel loescht sie, sobald sie die Kurzform "background:" benutzt.
   Dann steht ein Auswahlfeld da, das aussieht wie ein Textfeld.

   Die Raute im SVG wird als %23 geschrieben: eine rohe Raute beendet in
   einer CSS-Adresse den Wert. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
/* X-2 (Durchgang 01.10.2026): ein beanstandetes Feld nach der Umleitung - eigene Zutat, nicht Teil der Hausvorlage. */
.sm-wrap .sm-beanstandet { border: 2px solid #c62828 !important; background: #fff5f5 !important; }
.sm-wrap input[type=checkbox].sm-beanstandet { outline: 2px solid #c62828; outline-offset: 2px; }

</style>
<div class="sm-wrap">

<?php if ($rb_saved) { ?><div class="sm-alert sm-ok"><b><?= rb_e(ro_t('TEXT.KONFIGURATION_GESPEICHERT')) ?></b> <?= rb_e(ro_t('TEXT.INKL_SICHERUNGSKOPIE')) ?></div><?php } ?>
<?php
/* MELDUNGEN AUSGEBEN - UND WAS DABEI MASKIERT GEHOERT.
 *
 * Die Texte selbst duerfen Auszeichnung tragen (TEXT.SICH_ABGELEHNT enthaelt
 * absichtlich <b>). Was NICHT roh durchgehen darf, ist alles, was aus einer
 * hochgeladenen Datei stammt: ro_sicherung_lesen() setzt fremde SCHLUESSEL in
 * die Beanstandung ein, und ro_wert_taugt() prueft nur Werte, nie Schluessel.
 *
 * Gemessen am 04.09.2026 am laufenden Server, mit gueltigem Formularmerkmal:
 * eine Sicherungsdatei mit dem Schluessel <img src=x onerror=...> wurde
 * richtig abgelehnt - die Marke stand danach ROH in der Admin-Seite, auf der
 * auch das Aktionstoken steht. Maskiert wird deshalb jetzt an der Quelle
 * (ro_sicherung_lesen, ro_wert_pruefen), und diese Ausgabe bleibt so, wie sie
 * ist; die Wache dagegen steht in der Bibliothek. */
foreach ($rb_meldungen as $rb_m) { ?><div class="sm-hinweis"><?= $rb_m ?></div><?php }
foreach ($rb_fehler as $rb_f) { ?><div class="sm-warnung"><b><?= rb_e(ro_t('TEXT.FEHLER')) ?></b> <?= $rb_f ?></div><?php } ?>

<?php if (!$rb_robots) { ?>
<div class="sm-alert sm-info"><b><?= rb_e(ro_t('TEXT.NOCH_KEIN_ROBOTER')) ?></b> <?= rb_e(ro_t('TEXT.BITTE_ADRESSE_EINTRAGEN')) ?></div>
<?php } ?>
<?php foreach ($rb_states as $rb_k => $rb_s) { ?>
<div class="sm-alert <?= $rb_s['fehler'] ? 'sm-warn' : 'sm-info' ?>">
<b><?= rb_e($rb_s['name']) ?></b>:
<?php if ($rb_s['ok']) { ?>
<b><?= rb_e($rb_s['text']) ?></b> &middot; <?= rb_e(ro_t('TEXT.BATTERIE')) ?> <?= (int) $rb_s['batterie'] ?>&nbsp;%<?= $rb_s['laedt'] ? ' (' . rb_e(ro_t('TEXT.LAEDT')) . ')' : '' ?>
<?= $rb_s['fehler'] ? ' &middot; <b>' . rb_e(ro_t('TEXT.FEHLER_KURZ')) . ' ' . (int) $rb_s['fehler'] . '</b> ' . rb_e($rb_s['fehlertext']) : '' ?><br>
<?= rb_e(ro_t('TEXT.LETZTE_REINIGUNG')) ?> <?= rb_e($rb_s['flaeche']) ?>&nbsp;m&sup2; <?= rb_e(ro_t('WORT.IN')) ?> <?= (int) $rb_s['dauer'] ?>&nbsp;min<?= $rb_s['letzte'] ? ' (' . rb_e(date('d.m.Y H:i', $rb_s['letzte'])) . ')' : '' ?><br>
<?= rb_e(ro_t('TEXT.GESAMT')) ?> <?= rb_e($rb_s['flaeche_gesamt']) ?>&nbsp;m&sup2;, <?= rb_e($rb_s['dauer_gesamt']) ?>&nbsp;h, <?= (int) $rb_s['anzahl_gesamt'] ?> <?= rb_e(ro_t('TEXT.REINIGUNGEN')) ?><br>
<?= rb_e(ro_t('TEXT.VERBRAUCHSMATERIAL')) ?> <?= rb_e(ro_t('TEXT.FILTER')) ?> <?= rb_h($rb_s['filter']) ?> &middot; <?= rb_e(ro_t('TEXT.HAUPTBUERSTE')) ?> <?= rb_h($rb_s['buerste_haupt']) ?> &middot; <?= rb_e(ro_t('TEXT.SEITENBUERSTE')) ?> <?= rb_h($rb_s['buerste_seite']) ?> &middot; <?= rb_e(ro_t('TEXT.SENSOREN')) ?> <?= rb_h($rb_s['sensor']) ?><?php
if ($rb_s['mop'] >= 0) { echo ' &middot; ' . rb_e(ro_t('TEXT.WISCHBEZUG')) . ' ' . rb_h($rb_s['mop']); }
if ($rb_s['dock_behaelter'] >= 0) { echo ' &middot; ' . rb_e(ro_t('TEXT.STAUBBEUTEL')) . ' ' . rb_h($rb_s['dock_behaelter'], '%'); }
?>
<?= $rb_s['material_warn'] ? ' &rarr; <b>' . rb_e(ro_t('TEXT.WARTUNG_FAELLIG')) . '</b>' : '' ?><br>
<?= rb_e(ro_t('TEXT.ANBAUTEILE')) ?> <?= rb_e(ro_t('TEXT.BEHAELTER')) ?> <?= rb_jn($rb_s['behaelter']) ?> &middot; <?= rb_e(ro_t('TEXT.WASSERTANK')) ?> <?= rb_jn($rb_s['wassertank']) ?> &middot; <?= rb_e(ro_t('TEXT.WISCHMODUL')) ?> <?= rb_jn($rb_s['wischer']) ?>
<?php if ($rb_s['event'] > 0) { ?><br><b><?= rb_e(ro_t('TEXT.EREIGNIS')) ?></b> <?= rb_e($rb_s['evtext']) ?> (<?= (int) $rb_s['event'] ?>)<?php } ?>
<?php $rb_meld = ro_meldung_lesen($rb_k); if ($rb_meld !== '') { ?><br><?= rb_e(ro_t('TEXT.LETZTE_MELDUNG')) ?> <?= rb_e($rb_meld) ?><?php } ?>
<?php } else { ?>
<b><?= rb_e(ro_t('TEXT.KEINE_VERBINDUNG')) ?></b> <?= rb_e(ro_t('TEXT.ADRESSE_PRUEFEN')) ?>
<?php } ?>
</div>
<?php } ?>

<?php
/*
 * Reiter als echte Verweise, sm-active vom SERVER.
 *
 * Bis 1.0.2 standen hier <div class="sm-tab"> ohne Verweis, und sm-active
 * vergab allein das JavaScript am Seitenende. Da .sm-pane auf display:none
 * steht, war die Seite ohne JavaScript vollstaendig leer.
 *
 * BIS 1.1.3 WAR DIE LEISTE EINE foreach-SCHLEIFE - UND DAMIT WAR DIE PRUEFUNG
 * BLIND. hausstandard_pruefen.py sucht data-ziel/data-pane="tab-..." als
 * LITERAL; bei einer Schleife findet es null Reiter und setzt die Spalte auf
 * "-", also "trifft nicht zu", und ein Strich sammelt sich beim Ueberfliegen
 * wie ein Haken ein. Nachgewiesen am 04.09.2026 durch Rueckbau an einer
 * Kopie: 'tab-log' aus der Positivliste entfernt, der Reiter fuehrte auf die
 * Einstellungen - und die ganze Prueflette blieb gruen, byteweise dieselbe
 * Ausgabe.
 *
 * Die Leiste steht deshalb ausgeschrieben (Hausstandard, CLAUDE.md
 * Abschnitt 9), UND der Reiter Test misst die Kongruenz aller drei Stellen
 * selbst nach (ro_reiterlage). Zwei zu vergleichen genuegt nicht.
 *
 * WER HIER EINEN REITER AENDERT, aendert drei Stellen: diese Leiste, die id
 * des Bereichs weiter unten und $rb_reiterliste ganz oben. Die Pruefzeile im
 * Reiter Test sagt sofort, wenn eine davon fehlt.
 */
?>
<div class="sm-tabs">
    <a data-role="none" class="sm-tab<?= $rb_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-pane="tab-settings"
       href="index.php?form=settings"><?= rb_e(ro_t('REITER.EINSTELLUNGEN')) ?></a>
    <a data-role="none" class="sm-tab<?= $rb_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-pane="tab-mqtt"
       href="index.php?form=mqtt"><?= rb_e(ro_t('REITER.MQTT')) ?></a>
    <a data-role="none" class="sm-tab<?= $rb_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-pane="tab-loxone"
       href="index.php?form=loxone"><?= rb_e(ro_t('REITER.LOXONE')) ?></a>
    <a data-role="none" class="sm-tab<?= $rb_tab === 'tab-test' ? ' sm-active' : '' ?>" data-pane="tab-test"
       href="index.php?form=test"><?= rb_e(ro_t('REITER.TEST')) ?></a>
    <a data-role="none" class="sm-tab<?= $rb_tab === 'tab-log' ? ' sm-active' : '' ?>" data-pane="tab-log"
       href="index.php?form=log"><?= rb_e(ro_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Einstellungen ================= -->
<div class="sm-pane<?= $rb_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<?= rb_fmt() ?>

<h2><?= rb_e(ro_t('EINST.H_ROBOTER')) ?></h2>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:34px;"><?= rb_e(ro_t('WORT.NR')) ?></th><th style="width:22%;"><?= rb_e(ro_t('EINST.NAME')) ?></th><th><?= rb_e(ro_t('EINST.ADRESSE')) ?></th><th style="width:90px;"><?= rb_e(ro_t('EINST.PORT')) ?></th><th style="width:16%;"><?= rb_e(ro_t('EINST.BENUTZER')) ?></th><th style="width:16%;"><?= rb_e(ro_t('EINST.KENNWORT')) ?></th></tr>
<?php
/* DIE GERAETENUMMER REIST ALS VERSTECKTES FELD MIT DER ZEILE.
 *
 * Sie ist eine Adresse: an ihr haengen der virtuelle Eingang, das MQTT-Thema
 * und die Endpunktadresse (&dev=N). Bis 1.1.3 wurde sie beim Speichern aus der
 * Position gerechnet - wer die erste Adresse leerte, bekam fuer &dev=1 den
 * zweiten Roboter, und dessen Kennwort ging dabei still verloren (beides am
 * 04.09.2026 gemessen). Die angezeigte Nummer ist die WIRKLICHE, aus
 * ro_robots(); eine noch leere Zeile bekommt die naechste freie.
 */
$rb_zeilen = array();
$rb_belegt = array();
foreach (ro_robots() as $rb_nrv => $rb_rv) {
    $rb_zeilen[] = $rb_rv;
    $rb_belegt[] = $rb_nrv;
}
for ($rb_i = count($rb_zeilen); $rb_i < 2; $rb_i++) {
    $rb_frei = 1;
    while (in_array($rb_frei, $rb_belegt, true)) { $rb_frei++; }
    $rb_belegt[] = $rb_frei;
    $rb_zeilen[] = array('nr' => $rb_frei);
}
for ($rb_i = 0; $rb_i < 2; $rb_i++) {
    $rb_r = (array) $rb_zeilen[$rb_i];
    $rb_r += array('nr' => $rb_i + 1, 'name' => '', 'ip' => '', 'port' => 80, 'user' => '', 'pass' => ''); ?>
<tr>
<td><?= (int) $rb_r['nr'] ?><input data-role="none" type="hidden" name="r_nr[<?= $rb_i ?>]" value="<?= (int) $rb_r['nr'] ?>"></td>
<td><input data-role="none" type="text" name="r_name[<?= $rb_i ?>]" value="<?= rb_e(rb_w('r_name', $rb_r['name'], $rb_i)) ?>"<?= rb_m('r_name', $rb_i) ?> placeholder="<?= rb_e($rb_i === 0 ? ro_t('EINST.NAME_BEISPIEL') : ro_t('EINST.LEER_UNGENUTZT')) ?>"></td>
<td><input data-role="none" type="text" name="r_ip[<?= $rb_i ?>]" value="<?= rb_e(rb_w('r_ip', $rb_r['ip'], $rb_i)) ?>"<?= rb_m('r_ip', $rb_i) ?> placeholder="<?= rb_e($rb_i === 0 ? ro_t('EINST.IP_BEISPIEL') : '') ?>"></td>
<td><input data-role="none" type="number" name="r_port[<?= $rb_i ?>]" value="<?= rb_e(rb_w('r_port', (int) $rb_r['port'], $rb_i)) ?>"<?= rb_m('r_port', $rb_i) ?> min="1" max="65535"></td>
<td><input data-role="none" type="text" name="r_user[<?= $rb_i ?>]" value="<?= rb_e(rb_w('r_user', $rb_r['user'], $rb_i)) ?>"<?= rb_m('r_user', $rb_i) ?> autocomplete="off"></td>
<td><input data-role="none" type="password" name="r_pass[<?= $rb_i ?>]" value="" autocomplete="new-password"<?= rb_m('r_pass', $rb_i) ?> placeholder="<?= rb_e($rb_r['pass'] !== '' ? ro_t('EINST.GESETZT') : '') ?>">
<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;margin-top:4px;">
<input data-role="none" type="checkbox" name="r_pass_loeschen[<?= $rb_i ?>]" value="1"<?= rb_haken('r_pass_loeschen', false, $rb_i) ? ' checked' : '' ?>> <?= rb_e(ro_t('EINST.KENNWORT_LOESCHEN')) ?></label></td>
</tr>
<?php } ?>
</table>
</div>
<div class="sm-small"><?= ro_t('EINST.ROBOTER_HINWEIS') ?></div>
<div class="sm-small"><?= ro_t('EINST.ANMELDUNG_HINWEIS') ?></div>

<div class="sm-row">
    <div>
        <label><?= rb_e(ro_t('EINST.CACHE')) ?></label>
        <input data-role="none" type="number" name="cache_sec" value="<?= rb_e(rb_w('cache_sec', (int) $rb_cfg['cache_sec'])) ?>"<?= rb_m('cache_sec') ?> min="5" max="300">
        <div class="sm-small"><?= rb_e(ro_t('EINST.CACHE_HINWEIS')) ?></div>
    </div>
    <div>
        <label><?= rb_e(ro_t('EINST.WARN_STUNDEN')) ?></label>
        <input data-role="none" type="number" name="warn_hours" value="<?= rb_e(rb_w('warn_hours', (int) $rb_cfg['warn_hours'])) ?>"<?= rb_m('warn_hours') ?> min="0" max="200">
        <div class="sm-small"><?= ro_t('EINST.WARN_STUNDEN_HINWEIS') ?></div>
    </div>
    <div>
        <label><?= rb_e(ro_t('EINST.WARN_PROZENT')) ?></label>
        <input data-role="none" type="number" name="warn_prozent" value="<?= rb_e(rb_w('warn_prozent', (int) $rb_cfg['warn_prozent'])) ?>"<?= rb_m('warn_prozent') ?> min="0" max="100">
        <div class="sm-small"><?= rb_e(ro_t('EINST.WARN_PROZENT_HINWEIS')) ?></div>
    </div>
</div>

<h2><?= rb_e(ro_t('EINST.H_MELDUNGEN')) ?></h2>
<div style="margin-bottom:10px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;font-weight:400;">
        <input data-role="none" type="checkbox" name="notify_audio" <?= rb_haken('notify_audio', !empty($rb_notify['audio'])) ? 'checked' : '' ?>> <?= rb_e(ro_t('EINST.AUDIO_AKTIV')) ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
        <input data-role="none" type="checkbox" name="notify_push" <?= rb_haken('notify_push', !empty($rb_notify['push'])) ? 'checked' : '' ?>> <?= rb_e(ro_t('EINST.PUSH_AKTIV')) ?>
    </label>
    <div class="sm-small"><?= ro_t('EINST.MELDUNG_HINWEIS') ?></div>
</div>
<div>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;font-weight:400;">
        <input data-role="none" type="checkbox" name="n_fertig" <?= rb_haken('n_fertig', !empty($rb_notify['fertig'])) ? 'checked' : '' ?>> <?= rb_e(ro_t('EINST.N_FERTIG')) ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;font-weight:400;">
        <input data-role="none" type="checkbox" name="n_fehler" <?= rb_haken('n_fehler', !empty($rb_notify['fehler'])) ? 'checked' : '' ?>> <?= rb_e(ro_t('EINST.N_FEHLER')) ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;font-weight:400;">
        <input data-role="none" type="checkbox" name="n_material" <?= rb_haken('n_material', !empty($rb_notify['material'])) ? 'checked' : '' ?>> <?= rb_e(ro_t('EINST.N_MATERIAL')) ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
        <input data-role="none" type="checkbox" name="n_ereignis" <?= rb_haken('n_ereignis', !empty($rb_notify['ereignis'])) ? 'checked' : '' ?>> <?= rb_e(ro_t('EINST.N_EREIGNIS')) ?>
    </label>
</div>

<h2><?= rb_e(ro_t('EINST.H_SPRACHAUSGABE')) ?></h2>
<div class="sm-row">
    <div>
        <label><?= rb_e(ro_t('EINST.TTS_WEG')) ?></label>
        <?php $rb_tmod = rb_w('tts_mode', $rb_tts['mode']); ?>
        <select data-role="none" name="tts_mode" id="tts_mode" onchange="rbTtsMode()"<?= rb_m('tts_mode') ?>>
            <option value="musicserver"<?= $rb_tmod === 'musicserver' ? ' selected' : '' ?>><?= rb_e(ro_t('EINST.TTS_MUSICSERVER')) ?></option>
            <option value="ms4h"<?= $rb_tmod === 'ms4h' ? ' selected' : '' ?>><?= rb_e(ro_t('EINST.TTS_MS4H')) ?></option>
            <option value="audioserver"<?= $rb_tmod === 'audioserver' ? ' selected' : '' ?>><?= rb_e(ro_t('EINST.TTS_AUDIOSERVER')) ?></option>
            <option value="custom"<?= $rb_tmod === 'custom' ? ' selected' : '' ?>><?= rb_e(ro_t('EINST.TTS_EIGEN')) ?></option>
            <option value="alexang"<?= $rb_tmod === 'alexang' ? ' selected' : '' ?>><?= rb_e(ro_t('EINST.TTS_ALEXA')) ?></option>
            <option value="cc4lox"<?= $rb_tmod === 'cc4lox' ? ' selected' : '' ?>><?= rb_e(ro_t('EINST.TTS_GOOGLE')) ?></option>
        </select>
    </div>
    <div>
        <label><?= rb_e(ro_t('EINST.TTS_IP')) ?></label>
        <input data-role="none" type="text" name="tts_ip" value="<?= rb_e(rb_w('tts_ip', $rb_tts['ip'])) ?>"<?= rb_m('tts_ip') ?> placeholder="<?= rb_e(ro_t('EINST.IP_BEISPIEL2')) ?>">
    </div>
    <div>
        <label><?= rb_e(ro_t('EINST.PORT')) ?></label>
        <input data-role="none" type="number" name="tts_port" value="<?= rb_e(rb_w('tts_port', (int) $rb_tts['port'])) ?>"<?= rb_m('tts_port') ?> min="1" max="65535">
    </div>
</div>
<div class="sm-row">
    <div>
        <label><?= rb_e(ro_t('EINST.ZONEN')) ?></label>
        <input data-role="none" type="text" name="tts_zones" value="<?= rb_e(rb_w('tts_zones', $rb_tts['zones'])) ?>"<?= rb_m('tts_zones') ?> placeholder="2,4,6">
        <div class="sm-small"><?= ro_t('EINST.ZONEN_HINWEIS') ?></div>
    </div>
    <div>
        <label><?= rb_e(ro_t('EINST.LAUTSTAERKE')) ?></label>
        <input data-role="none" type="number" name="tts_volume" value="<?= rb_e(rb_w('tts_volume', (int) $rb_tts['volume'])) ?>"<?= rb_m('tts_volume') ?> min="1" max="100">
    </div>
    <div>
        <label><?= rb_e(ro_t('EINST.SPRACHE')) ?></label>
        <input data-role="none" type="text" name="tts_lang" value="<?= rb_e(rb_w('tts_lang', $rb_tts['lang'])) ?>"<?= rb_m('tts_lang') ?> maxlength="5">
    </div>
</div>
<div id="tts_template_row">
    <label><?= rb_e(ro_t('EINST.TTS_VORLAGE')) ?></label>
    <textarea data-role="none" name="tts_template" id="tts_template" rows="2"<?= rb_m('tts_template') ?> placeholder="http://{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}"><?= rb_e(rb_w('tts_template', $rb_tts['template'])) ?></textarea>
    <div class="sm-small"><?= ro_t('EINST.TTS_VORLAGE_HINWEIS') ?></div>
</div>
<div id="tts_audioserver_hint" class="sm-alert sm-info" style="display:none;">
    <?= ro_t('EINST.TTS_AUDIOSERVER_HINWEIS') ?>
</div>
<?php /* Ansage-2 (U14): Ausgabeart Alexa-NG. Das Sprechtoken steht nie in der
         Seite - das Feld ist immer leer, der Platzhalter sagt, ob eines
         gespeichert ist und wie lang es ist. */ ?>
<div id="tts_alexa_rows">
<div class="sm-alert sm-info"><?= ro_t('EINST.ALEXA_HINWEIS') ?></div>
<div class="sm-row">
    <div>
        <label><?= rb_e(ro_t('EINST.ALEXA_GERAET')) ?></label>
        <input data-role="none" type="text" name="tts_alexa_geraet" value="<?= rb_e(rb_w('tts_alexa_geraet', $rb_tts['alexa_geraet'])) ?>"<?= rb_m('tts_alexa_geraet') ?> maxlength="200" placeholder="kueche">
        <div class="sm-small"><?= ro_t('EINST.ALEXA_GERAET_HINWEIS') ?></div>
    </div>
    <div>
        <label><?= rb_e(ro_t('EINST.ALEXA_LAUT')) ?></label>
        <input data-role="none" type="number" name="tts_alexa_laut" value="<?= rb_e(rb_w('tts_alexa_laut', (int) $rb_tts['alexa_laut'] >= 0 ? (int) $rb_tts['alexa_laut'] : '')) ?>"<?= rb_m('tts_alexa_laut') ?> min="0" max="100">
        <div class="sm-small"><?= rb_e(ro_t('EINST.ALEXA_LAUT_HINWEIS')) ?></div>
    </div>
    <div>
        <label><?= rb_e(ro_t('EINST.ALEXA_TOKEN')) ?></label>
        <input data-role="none" type="password" name="tts_alexa_token" value="" autocomplete="new-password"<?= rb_m('tts_alexa_token') ?> placeholder="<?= rb_e((string) $rb_tts['alexa_token'] !== '' ? sprintf(ro_t('EINST.ALEXA_TOKEN_DA'), strlen((string) $rb_tts['alexa_token'])) : ro_t('EINST.ALEXA_TOKEN_LEER')) ?>">
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;margin-top:4px;">
            <input data-role="none" type="checkbox" name="tts_alexa_token_loeschen" value="1"<?= rb_haken('tts_alexa_token_loeschen', false) ? ' checked' : '' ?><?= rb_m('tts_alexa_token_loeschen') ?>> <?= rb_e(ro_t('EINST.KENNWORT_LOESCHEN')) ?>
        </label>
        <div class="sm-small"><?= rb_e(ro_t('EINST.ALEXA_TOKEN_HINWEIS')) ?></div>
    </div>
</div>
</div>
<?php /* Ansage-3: Ausgabeart Google-Lautsprecher (Chromecast 4 Lox NG). Das
         Sprechtoken steht nie in der Seite - das Feld ist immer leer, der
         Platzhalter sagt, ob eines gespeichert ist und wie lang es ist. */ ?>
<div id="tts_google_rows">
<div class="sm-alert sm-info"><?= ro_t('EINST.GOOGLE_HINWEIS') ?></div>
<div class="sm-row">
    <div>
        <label><?= rb_e(ro_t('EINST.GOOGLE_GERAET')) ?></label>
        <input data-role="none" type="text" name="tts_google_geraet" value="<?= rb_e(rb_w('tts_google_geraet', $rb_tts['google_geraet'])) ?>"<?= rb_m('tts_google_geraet') ?> maxlength="200" placeholder="Wohnzimmer">
        <div class="sm-small"><?= ro_t('EINST.GOOGLE_GERAET_HINWEIS') ?></div>
    </div>
    <div>
        <label><?= rb_e(ro_t('EINST.GOOGLE_LAUT')) ?></label>
        <input data-role="none" type="number" name="tts_google_laut" value="<?= rb_e(rb_w('tts_google_laut', (int) $rb_tts['google_laut'] >= 0 ? (int) $rb_tts['google_laut'] : '')) ?>"<?= rb_m('tts_google_laut') ?> min="0" max="100">
        <div class="sm-small"><?= rb_e(ro_t('EINST.GOOGLE_LAUT_HINWEIS')) ?></div>
    </div>
    <div>
        <label><?= rb_e(ro_t('EINST.GOOGLE_TOKEN')) ?></label>
        <input data-role="none" type="password" name="tts_google_token" value="" autocomplete="new-password"<?= rb_m('tts_google_token') ?> placeholder="<?= rb_e((string) $rb_tts['google_token'] !== '' ? sprintf(ro_t('EINST.GOOGLE_TOKEN_DA'), strlen((string) $rb_tts['google_token'])) : ro_t('EINST.GOOGLE_TOKEN_LEER')) ?>">
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;margin-top:4px;">
            <input data-role="none" type="checkbox" name="tts_google_token_loeschen" value="1"<?= rb_haken('tts_google_token_loeschen', false) ? ' checked' : '' ?><?= rb_m('tts_google_token_loeschen') ?>> <?= rb_e(ro_t('EINST.KENNWORT_LOESCHEN')) ?>
        </label>
        <div class="sm-small"><?= rb_e(ro_t('EINST.GOOGLE_TOKEN_HINWEIS')) ?></div>
    </div>
</div>
</div>

<?php /* U10 (Durchgang 01.10.2026): EINE Legende je Reiter, oben, ueber der
         ersten Knopfreihe, mit allen Farben des Reiters (Regeln/04). Bis
         1.1.11 standen hier zwei, beide unter den Knoepfen. */ ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= rb_e(ro_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= rb_e(ro_t('LEGENDE.AKTION')) ?></span>
</div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= rb_e(ro_t('KNOPF.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= rb_e(ro_t('EINST.H_SICHERUNG')) ?></h2>
<div class="sm-hinweis"><?= ro_t('EINST.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= ro_t('EINST.SICH_WARNUNG') ?></div>
<?php /* U5 (X-3): Wuerde das Zurueckspielen die eigene Sicherung abweisen,
         steht es hier - gelb, nur Namen (dieselbe Pruefung wie beim
         Zurueckspielen, ro_sicherung_warnung()). */
$rb_sich_warn = ro_sicherung_warnung(ro_sicherung_bauen());
if ($rb_sich_warn) { ?>
<div class="sm-warnung"><?= rb_e(sprintf(ro_t('TEXT.SICH_WARN_KNOPF'), implode(', ', $rb_sich_warn))) ?></div>
<?php } ?>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <?= rb_fmt() ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="ro_sichern" value="1"><?= rb_e(ro_t('KNOPF.SICHERN')) ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <?= rb_fmt() ?>
    <input data-role="none" type="file" name="ro_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ro_zurueck" value="1"><?= rb_e(ro_t('KNOPF.ZURUECK')) ?></button>
  </form>
</div>
</div>

<!-- ================= MQTT ================= -->
<div class="sm-pane<?= $rb_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="mqtt_save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<?= rb_fmt() ?>
<h2><?= rb_e(ro_t('MQTT.H_MQTT')) ?></h2>
<?php if (ro_mqtt_gateway_autostart() === false) { ?><div class="sm-warnung"><b>MQTT:</b> <?= ro_t('MQTT.W_AUTOSTART') ?></div><?php } ?>
<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
    <input data-role="none" type="checkbox" name="mqtt_enabled" <?= rb_haken('mqtt_enabled', !empty($rb_cfg['mqtt_enabled'])) ? 'checked' : '' ?>> <?= rb_e(ro_t('MQTT.EINSCHALTEN')) ?>
</label>
<div class="sm-row" style="margin-top:6px;">
    <div>
        <label><?= rb_e(ro_t('MQTT.PRAEFIX')) ?></label>
        <input data-role="none" type="text" name="mqtt_topic" value="<?= rb_e(rb_w('mqtt_topic', $rb_cfg['mqtt_topic'])) ?>"<?= rb_m('mqtt_topic') ?> placeholder="saugrobo">
        <div class="sm-small"><?= sprintf(ro_t('MQTT.PRAEFIX_HINWEIS'),
            '<span class="sm-mono">' . rb_e($rb_cfg['mqtt_topic']) . '/code</span>',
            '<span class="sm-mono">' . rb_e($rb_cfg['mqtt_topic']) . '/2/&hellip;</span>') ?></div>
    </div>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= rb_e(ro_t('LEGENDE.AKTION')) ?></span>
</div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= rb_e(ro_t('KNOPF.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= rb_e(ro_t('MQTT.H_ABO')) ?></h2>
<?php
/* Der Abo-Hinweis in der Fassung, die zum Gateway passt - aus EINER Quelle
 * (ro_abo_text()). Bis 1.0.14 stand hier gar nichts: unter Gateway V1 muss
 * das Abo von Hand eingetragen werden, sonst kommt am Miniserver nichts an,
 * und das ist die haeufigste Fehlerursache ueberhaupt. */
$rb_gw = ro_mqtt_gateway_info();
$rb_gwf = ($rb_gw === null) ? 0 : (int) $rb_gw['fassung'];
?>
<div class="<?= $rb_gwf >= 2 ? 'sm-hinweis' : 'sm-warnung' ?>"><?= ro_abo_text() ?></div>
<table class="sm-tbl">
<tr><th><?= rb_e(ro_t('MQTT.EINZUTRAGEN')) ?></th><th><?= rb_e(ro_t('WORT.ZWECK')) ?></th></tr>
<tr><td><span class="sm-mono"><?= rb_e($rb_cfg['mqtt_topic']) ?>/#</span></td><td><?= rb_e(ro_t('MQTT.ABO_ALLE')) ?></td></tr>
</table>
<?php /* M5 (Durchgang 01.10.2026): die Abo-Datei, die das Gateway V1 selbst liest. */
list($rb_abo_pfad, $rb_abo_da) = ro_abo_datei(ro_mqtt_thema_saeubern($rb_cfg['mqtt_topic']));
if ($rb_abo_pfad !== '') { ?>
<div class="sm-small"><?= sprintf(ro_t('MQTT.ABO_DATEI'), '<span class="sm-mono">' . rb_e($rb_abo_pfad) . '</span>',
    '<span class="sm-mono">' . rb_e(ro_mqtt_thema_saeubern($rb_cfg['mqtt_topic'])) . '/#</span>',
    rb_e($rb_abo_da ? ro_t('WORT.JA') : ro_t('WORT.NEIN'))) ?></div>
<?php } ?>

<h2><?= rb_e(ro_t('MQTT.H_THEMEN')) ?></h2>
<div class="sm-hinweis"><?= ro_t('MQTT.LEBENSZEICHEN_HINWEIS') ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= rb_e(ro_t('WORT.THEMA')) ?></th><th><?= rb_e(ro_t('WORT.BEDEUTUNG')) ?></th><th style="width:96px;"><?= rb_e(ro_t('WORT.RETAIN')) ?></th></tr>
<?php
/* EINE Liste fuer Tabelle und Sender (ro_mqtt_themen).
 *
 * Bis 1.1.3 lief diese Tabelle ueber die volle Feldliste, waehrend der Sender
 * ALTER und ZAEHLER ausnahm. Gemessen an der gerenderten Seite: 48 Themen
 * gelistet, 46 gesendet - wer die Tabelle abarbeitete, legte zwei virtuelle
 * Eingaenge an, die nie einen Wert bekamen. Die Pruefzeile im Reiter Test
 * haelt die Liste jetzt gegen das, was der Sender wirklich bildet. */
foreach (ro_mqtt_themen($rb_cfg['mqtt_topic'], 1) as $rb_thema => $rb_tf) { ?>
<tr><td><span class="sm-mono"><?= rb_e($rb_thema) ?></span></td><td><?= rb_e($rb_tf['bedeutung']) ?></td><td><?= rb_e($rb_tf['retain'] ? ro_t('WORT.JA') : ro_t('WORT.NEIN')) ?></td></tr>
<?php } ?>
</table>
</div>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="sm-pane<?= $rb_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= rb_e(ro_t('LOX.H_EINBINDUNG')) ?></h2>
<p><?= ro_t('LOX.EINLEITUNG') ?></p>

<div class="sm-step"><b><?= rb_e(ro_t('LOX.SCHRITT1')) ?></b>
<table class="sm-tbl">
<tr><th><?= rb_e(ro_t('WORT.EIGENSCHAFT')) ?></th><th><?= rb_e(ro_t('WORT.WERT')) ?></th></tr>
<tr><td>URL</td><td><span class="sm-mono">http://<?= rb_e($rb_host) ?><?= rb_e(ro_endpunkt_pfad()) ?></span></td></tr>
<tr><td><?= rb_e(ro_t('LOX.ROBOTER2')) ?></td><td><span class="sm-mono">http://<?= rb_e($rb_host) ?><?= rb_e(ro_endpunkt_pfad(array('dev' => 2))) ?></span></td></tr>
<tr><td><?= rb_e(ro_t('LOX.ABFRAGEZYKLUS')) ?></td><td><?= rb_e(ro_t('LOX.30_SEKUNDEN')) ?></td></tr>
</table>
</div>

<div class="sm-step"><b><?= rb_e(ro_t('LOX.SCHRITT2')) ?></b>
<div class="sm-hinweis"><?= ro_t('LOX.SUCHTEXT_HINWEIS') ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= rb_e(ro_t('LOX.BEFEHLSERKENNUNG')) ?></th><th><?= rb_e(ro_t('WORT.BEDEUTUNG')) ?></th><th><?= rb_e(ro_t('WORT.EINHEIT')) ?></th></tr>
<?php foreach (ro_felder() as $rb_name => $rb_f) { ?>
<tr><td><span class="sm-mono"><?= rb_e(ro_check($rb_name)) ?></span></td><td><?= rb_e($rb_f[4]) ?></td><td><?= rb_e($rb_f[3]) ?></td></tr>
<?php } ?>
</table>
</div>
</div>

<div class="sm-step"><b><?= rb_e(ro_t('LOX.SCHRITT3')) ?></b><br>
<?= ro_t('LOX.SCHRITT3_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= rb_e(ro_t('WORT.EIGENSCHAFT')) ?></th><th><?= rb_e(ro_t('WORT.WERT')) ?></th></tr>
<tr><td><?= rb_e(ro_t('LOX.ADRESSE_VO')) ?></td><td><span class="sm-mono">http://<?= rb_e($rb_host) ?></span></td></tr>
</table>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= rb_e(ro_t('LOX.BEFEHL_BEI_EIN')) ?></th><th><?= rb_e(ro_t('WORT.WIRKUNG')) ?></th></tr>
<?php foreach (ro_befehle() as $rb_bname => $rb_b) {
    $rb_werte = array('cmd' => $rb_bname);
    if ($rb_b[0] !== '') { $rb_werte['p'] = $rb_b[0]; }
    $rb_werte['token'] = $rb_cfg['aktionstoken']; ?>
<tr><td><span class="sm-mono"><?= rb_e(ro_endpunkt_pfad($rb_werte)) ?></span></td><td><?= rb_e($rb_b[1]) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-warnung"><?= ro_t('LOX.TOKEN_NOETIG') ?></div>
</div>

<?php /* U10: eine Legende je Reiter, oben, mit allen Farben des Reiters. */ ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= rb_e(ro_t('LEGENDE.AKTION_TOKEN')) ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= rb_e(ro_t('LEGENDE.TECHNIK')) ?></span>
</div>
<div class="sm-step"><b><?= rb_e(ro_t('LOX.H_TOKEN')) ?></b>
<table class="sm-tbl">
<tr><th><?= rb_e(ro_t('WORT.EIGENSCHAFT')) ?></th><th><?= rb_e(ro_t('WORT.WERT')) ?></th></tr>
<tr><td><?= rb_e(ro_t('LOX.AKTUELLES_TOKEN')) ?></td><td><span class="sm-mono"><?= rb_e($rb_cfg['aktionstoken']) ?></span></td></tr>
</table>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <?= rb_fmt() ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= rb_e(ro_t('KNOPF.TOKEN_NEU')) ?></button>
  </form>
</div>
</div>

<h2><?= rb_e(ro_t('LOX.H_VORLAGE')) ?></h2>
<div class="sm-hinweis"><?= ro_t('LOX.VORLAGE_TEXT') ?></div>
<form action="index.php" method="post">
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <?= rb_fmt() ?>
  <div class="sm-row">
    <div>
      <label><?= rb_e(ro_t('LOX.VORLAGE_ROBOTER')) ?></label>
      <select data-role="none" name="vorlage_dev">
        <option value="1">1</option><option value="2">2</option>
      </select>
    </div>
    <div>
      <label style="min-height:0;"><?= rb_e(ro_t('LOX.VORLAGE_UMFANG')) ?></label>
      <label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
        <input data-role="none" type="checkbox" name="vorlage_belegt" value="1"> <?= rb_e(ro_t('LOX.VORLAGE_NUR_BELEGT')) ?>
      </label>
      <div class="sm-small"><?= sprintf(ro_t('LOX.VORLAGE_ANZAHL'), count(ro_felder())) ?></div>
    </div>
  </div>
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="1"><?= rb_e(ro_t('KNOPF.VORLAGE_VI')) ?></button>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage_vo" value="1"><?= rb_e(ro_t('KNOPF.VORLAGE_VO')) ?></button>
  </div>
</form>

<div class="sm-step"><b><?= rb_e(ro_t('LOX.SCHRITT4')) ?></b><br>
<b><?= rb_e(ro_t('LOX.B4A')) ?></b>
<table class="sm-tbl">
<tr><th><?= rb_e(ro_t('WORT.BAUSTEIN')) ?></th><th><?= rb_e(ro_t('WORT.NAME')) ?></th><th><?= rb_e(ro_t('WORT.EINSTELLUNG')) ?></th><th><?= rb_e(ro_t('WORT.EINGAENGE')) ?></th></tr>
<tr><td><?= rb_e(ro_t('LOX.STATUSBAUSTEIN')) ?></td><td><?= rb_e(ro_t('LOX.SAUGROBOTER_ZUSTAND')) ?></td><td><?= rb_e(ro_t('LOX.TEXTE_JE_WERT')) ?></td><td><span class="sm-mono">CODE</span></td></tr>
<tr><td><?= rb_e(ro_t('LOX.ANALOGANZEIGEN')) ?></td><td><?= rb_e(ro_t('LOX.BATT_FLAECHE_DAUER')) ?></td><td><?= rb_e(ro_t('WORT.EINHEIT')) ?> <span class="sm-mono">&lt;v.0&gt; %</span>, <span class="sm-mono">&lt;v.1&gt; m&sup2;</span>, <span class="sm-mono">&lt;v.0&gt; min</span></td><td><span class="sm-mono">BATT, FLAECHE, DAUER</span></td></tr>
<tr><td><?= rb_e(ro_t('LOX.ANALOGANZEIGEN')) ?></td><td><?= rb_e(ro_t('LOX.VERBRAUCH')) ?></td><td><?= rb_e(ro_t('WORT.EINHEIT')) ?> <span class="sm-mono">&lt;v.0&gt; h</span></td><td><span class="sm-mono">FILTER, BHAUPT, BSEITE, SENSOR, MOP</span></td></tr>
<tr><td><?= rb_e(ro_t('LOX.STATUSBAUSTEIN')) ?></td><td><?= rb_e(ro_t('LOX.STATION')) ?></td><td><?= rb_e(ro_t('LOX.TEXTE_JE_WERT_DOCK')) ?></td><td><span class="sm-mono">DOCK</span></td></tr>
</table>
<b><?= rb_e(ro_t('LOX.B4B')) ?></b>
<table class="sm-tbl">
<tr><th><?= rb_e(ro_t('WORT.BAUSTEIN')) ?></th><th><?= rb_e(ro_t('WORT.NAME')) ?></th><th><?= rb_e(ro_t('WORT.EINSTELLUNG')) ?></th><th><?= rb_e(ro_t('WORT.EINGAENGE')) ?></th></tr>
<tr><td><?= rb_e(ro_t('LOX.SCHWELLWERT')) ?> S1</td><td><?= rb_e(ro_t('LOX.MELDEFENSTER')) ?></td><td><?= rb_e(ro_t('LOX.EIN05_AUS04')) ?></td><td><span class="sm-mono">ANN</span></td></tr>
<tr><td><?= rb_e(ro_t('LOX.SCHWELLWERT')) ?> S2</td><td><?= rb_e(ro_t('LOX.PUSH_FREIGEGEBEN')) ?></td><td><?= rb_e(ro_t('LOX.EIN05_AUS04')) ?></td><td><span class="sm-mono">PUSH</span></td></tr>
<tr><td><?= rb_e(ro_t('LOX.UND_ODER')) ?></td><td><?= rb_e(ro_t('LOX.ROBOTER_MELDUNG')) ?></td><td><?= rb_e(ro_t('LOX.O1_QUELLE')) ?></td><td><span class="sm-mono">S1, S2</span></td></tr>
<tr><td><?= rb_e(ro_t('LOX.BENACHRICHTIGUNG')) ?></td><td><?= rb_e(ro_t('LOX.PUSH_SAUGROBOTER')) ?></td><td><?= rb_e(ro_t('LOX.PUSH_TEXT')) ?></td><td><span class="sm-mono">O1</span></td></tr>
<tr><td><?= rb_e(ro_t('LOX.SCHWELLWERT')) ?> S3</td><td><?= rb_e(ro_t('LOX.STOERUNG')) ?></td><td><?= rb_e(ro_t('LOX.EIN05_AN')) ?> <span class="sm-mono">FEHLER</span></td><td><span class="sm-mono">FEHLER</span></td></tr>
<tr><td><?= rb_e(ro_t('LOX.SCHWELLWERT')) ?> S4</td><td><?= rb_e(ro_t('LOX.WARTUNG')) ?></td><td><?= rb_e(ro_t('LOX.EIN05_AN')) ?> <span class="sm-mono">MATWARN</span></td><td><span class="sm-mono">MATWARN</span></td></tr>
<tr><td><?= rb_e(ro_t('LOX.SCHWELLWERT')) ?> S5</td><td><?= rb_e(ro_t('LOX.BEHAELTER_VOLL')) ?></td><td><?= rb_e(ro_t('LOX.EIN05_AN')) ?> <span class="sm-mono">EVMUELL</span></td><td><span class="sm-mono">EVMUELL</span></td></tr>
<tr><td><?= rb_e(ro_t('LOX.BENACHRICHTIGUNG')) ?></td><td><?= rb_e(ro_t('LOX.TEST_PUSH')) ?></td><td><?= rb_e(ro_t('LOX.EIGENER_BAUSTEIN')) ?></td><td><span class="sm-mono">PTEST</span></td></tr>
</table>
<b><?= rb_e(ro_t('LOX.B4C')) ?></b>
<table class="sm-tbl">
<tr><th><?= rb_e(ro_t('WORT.BAUSTEIN')) ?></th><th><?= rb_e(ro_t('WORT.NAME')) ?></th><th><?= rb_e(ro_t('WORT.EINSTELLUNG')) ?></th><th><?= rb_e(ro_t('WORT.EINGAENGE')) ?></th></tr>
<tr><td><?= rb_e(ro_t('LOX.SCHWELLWERT')) ?> S6</td><td><?= rb_e(ro_t('LOX.PLUGIN_LEBT')) ?></td><td><?= rb_e(ro_t('LOX.ALTER_HINWEIS')) ?></td><td><span class="sm-mono">ALTER</span></td></tr>
<tr><td><?= rb_e(ro_t('LOX.SCHWELLWERT')) ?> S7</td><td><?= rb_e(ro_t('LOX.ROBOTER_BEREIT')) ?></td><td><?= rb_e(ro_t('LOX.INVERTIERT')) ?></td><td><span class="sm-mono">CODE</span></td></tr>
<tr><td><?= rb_e(ro_t('LOX.UND')) ?> U2</td><td><?= rb_e(ro_t('LOX.SAUGEN_FREIGEBEN')) ?></td><td><?= rb_e(ro_t('LOX.AUF_VO')) ?> <span class="sm-mono">?cmd=start</span></td><td><?= rb_e(ro_t('LOX.S7_ABWESENHEIT')) ?></td></tr>
<tr><td><?= rb_e(ro_t('LOX.UND')) ?> U3</td><td><?= rb_e(ro_t('LOX.HEIMSCHICKEN')) ?></td><td><?= rb_e(ro_t('LOX.AUF_VO')) ?> <span class="sm-mono">?cmd=home</span></td><td><?= rb_e(ro_t('LOX.ANWESENHEIT')) ?></td></tr>
<tr><td><?= rb_e(ro_t('LOX.UND')) ?> U4</td><td><?= rb_e(ro_t('LOX.ABSAUGEN_NACHTS')) ?></td><td><?= rb_e(ro_t('LOX.AUF_VO')) ?> <span class="sm-mono">?cmd=absaugen</span></td><td><?= rb_e(ro_t('LOX.U4_EINGAENGE')) ?></td></tr>
</table>
<b><?= rb_e(ro_t('LOX.PRAXIS')) ?></b> <?= ro_t('LOX.PRAXIS_TEXT') ?>
</div>

<div class="sm-step"><b><?= rb_e(ro_t('LOX.SCHRITT5')) ?></b><br>
<?= ro_t('LOX.SCHRITT5_TEXT') ?>
<span class="sm-mono">http://<?= rb_e($rb_host) ?><?= rb_e(ro_endpunkt_pfad(array('json' => 1))) ?></span>
</div>
</div>

<!-- ================= Test ================= -->
<div class="sm-pane<?= $rb_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= rb_e(ro_t('TEST.H_SELBSTPRUEFUNG')) ?></h2>
<div class="sm-small"><?= rb_e(ro_t('TEST.SELBST_HINWEIS')) ?></div>
<table class="sm-tbl sm-pruef">
<?php /* U9: Aufrufe, die warten koennen (eigener Endpunkt, Alexa-NG, Chromecast 4 Lox NG), nur bei offenem Reiter Test. */
foreach (ro_selbsttest(array('test_offen' => $rb_tab === 'tab-test')) as $rb_z) {
    $rb_zeichen = $rb_z['ok'] === 1 ? '&#10003;' : ($rb_z['ok'] === 0 ? '&#10007;' : '&ndash;');
    $rb_farbe = $rb_z['ok'] === 1 ? '#4f7d17' : ($rb_z['ok'] === 0 ? '#c62828' : '#888'); ?>
<tr><td style="color:<?= $rb_farbe ?>;"><?= $rb_zeichen ?></td><td><?= rb_e(ro_t($rb_z['bez'])) ?></td><td><?= rb_e($rb_z['text']) ?></td></tr>
<?php } ?>
</table>

<h2>Test</h2>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= rb_e(ro_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= rb_e(ro_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= rb_e(ro_t('LEGENDE.AKTION')) ?></span>
</div>

<h3 class="sm-h3"><?= rb_e(ro_t('TEST.ANSEHEN')) ?></h3>
<div class="sm-knopfreihe">
<a data-role="none" class="sm-btn sm-b-lesen" href="<?= rb_e(ro_endpunkt_pfad()) ?>" target="_blank"><?= rb_e(ro_t('TEST.K_ZEILE')) ?></a>
<a data-role="none" class="sm-btn sm-b-lesen" href="<?= rb_e(ro_endpunkt_pfad(array('json' => 1))) ?>" target="_blank"><?= rb_e(ro_t('TEST.K_JSON')) ?></a>
</div>

<h3 class="sm-h3"><?= rb_e(ro_t('TEST.TECHNIK')) ?></h3>
<div class="sm-knopfreihe">
<?php /* U13: mit Token - refresh ist seit 1.1.4 tokenpflichtig; ohne Token zeigte der Knopf den Zwischenspeicher. */ ?>
<a data-role="none" class="sm-btn sm-b-technik" href="<?= rb_e(ro_endpunkt_pfad(array('debug' => 1, 'refresh' => 1, 'token' => $rb_cfg['aktionstoken']))) ?>" target="_blank"><?= rb_e(ro_t('TEST.K_DEBUG')) ?></a>
<a data-role="none" class="sm-btn sm-b-technik" href="<?= rb_e(ro_endpunkt_pfad(array('selftest' => 1, 'token' => $rb_cfg['aktionstoken']))) ?>" target="_blank"><?= rb_e(ro_t('TEST.K_SELFTEST')) ?></a>
<form method="post" action="index.php">
  <input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?= rb_fmt() ?>
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="neu_lesen" value="1"><?= rb_e(ro_t('TEST.K_NEU_LESEN')) ?></button>
</form>
</div>

<h3 class="sm-h3"><?= rb_e(ro_t('TEST.LOEST_AUS')) ?></h3>
<div class="sm-knopfreihe">
<a data-role="none" class="sm-btn sm-b-aktion" href="<?= rb_e(ro_endpunkt_pfad(array('ptest' => 1, 'token' => $rb_cfg['aktionstoken']))) ?>" target="_blank"><?= rb_e(ro_t('TEST.K_PTEST')) ?></a>
<a data-role="none" class="sm-btn sm-b-aktion" href="<?= rb_e(ro_endpunkt_pfad(array('cmd' => 'locate', 'token' => $rb_cfg['aktionstoken']))) ?>" target="_blank"><?= rb_e(ro_t('TEST.K_LOCATE')) ?></a>
<a data-role="none" class="sm-btn sm-b-aktion" href="<?= rb_e(ro_endpunkt_pfad(array('cmd' => 'home', 'token' => $rb_cfg['aktionstoken']))) ?>" target="_blank"><?= rb_e(ro_t('TEST.K_HOME')) ?></a>
<a data-role="none" class="sm-btn sm-b-aktion" href="<?= rb_e(ro_endpunkt_pfad(array('cmd' => 'stop', 'token' => $rb_cfg['aktionstoken']))) ?>" target="_blank"><?= rb_e(ro_t('TEST.K_STOP')) ?></a>
<?php if (ro_kann(1, 'AutoEmptyDockManualTriggerCapability')) { ?>
<a data-role="none" class="sm-btn sm-b-aktion" href="<?= rb_e(ro_endpunkt_pfad(array('cmd' => 'absaugen', 'token' => $rb_cfg['aktionstoken']))) ?>" target="_blank"><?= rb_e(ro_t('TEST.K_ABSAUGEN')) ?></a>
<?php } ?>
<?php /* U14: Testansage ueber den eingestellten Ausgabeweg (auch Alexa-NG und Google-Lautsprecher). */ ?>
<form method="post" action="index.php">
  <input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?= rb_fmt() ?>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ro_testansage" value="1"><?= rb_e(ro_t('TEST.K_TESTANSAGE')) ?></button>
</form>
</div>
<div class="sm-small"><?= rb_e(ro_t('TEST.PIEPSEN_HINWEIS')) ?></div>

<h3 class="sm-h3"><?= rb_e(ro_t('TEST.H_RESET')) ?></h3>
<div class="sm-hinweis"><?= ro_t('TEST.RESET_HINWEIS') ?></div>
<div class="sm-knopfreihe">
<?php foreach (array('filter/main' => 'TEST.R_FILTER', 'brush/main' => 'TEST.R_BHAUPT',
                     'brush/side_right' => 'TEST.R_BSEITE', 'cleaning/sensor' => 'TEST.R_SENSOR',
                     'mop/all' => 'TEST.R_MOP') as $rb_teil => $rb_key) { ?>
<form method="post" action="index.php">
  <input data-role="none" type="hidden" name="activetab" value="tab-test">
  <input data-role="none" type="hidden" name="reset_dev" value="1">
  <?= rb_fmt() ?>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ro_reset" value="<?= rb_e($rb_teil) ?>"><?= rb_e(ro_t($rb_key)) ?></button>
</form>
<?php } ?>
</div>

<?php $rb_seg = ro_segments(1); if ($rb_seg) { ?>
<h2><?= rb_e(ro_t('TEST.H_RAEUME')) ?></h2>
<table class="sm-tbl"><tr><th>ID</th><th><?= rb_e(ro_t('WORT.NAME')) ?></th><th><?= rb_e(ro_t('TEST.AUFRUF')) ?></th></tr>
<?php foreach ($rb_seg as $rb_id => $rb_nm) { ?>
<tr><td><span class="sm-mono"><?= rb_e($rb_id) ?></span></td><td><?= rb_e($rb_nm) ?></td>
<td><span class="sm-mono">http://<?= rb_e($rb_host) ?><?= rb_e(ro_endpunkt_pfad(array('cmd' => 'segments', 'p' => $rb_id, 'token' => $rb_cfg['aktionstoken']))) ?></span></td></tr>
<?php } ?></table>
<?php /* U11 (Durchgang 01.10.2026): volle Adressen samt Token - Regeln/04 "Angezeigte
         Adressen zum Abschreiben tragen jeden Parameter". Bis 1.1.11 stand hier
         ?cmd=segments&p=.. ohne Pfad und Token; abgeschrieben ergab das 403. */ ?>
<div class="sm-small"><?= rb_e(ro_t('TEST.MEHRERE_RAEUME')) ?> <span class="sm-mono">http://<?= rb_e($rb_host) ?><?= rb_e(ro_endpunkt_pfad(array('cmd' => 'segments', 'p' => implode(',', array_slice(array_keys($rb_seg), 0, 2)), 'token' => $rb_cfg['aktionstoken']))) ?></span></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?= rb_e(ro_t('TEST.RAUMLISTE_FEHLT')) ?></div>
<?php } ?>

<?php $rb_caps = ro_capabilities(1); if ($rb_caps) { ?>
<h2><?= rb_e(ro_t('TEST.H_FAEHIGKEITEN')) ?></h2>
<div class="sm-small"><?= rb_e(ro_t('TEST.FAEHIGKEITEN_HINWEIS')) ?></div>
<div class="sm-breit"><table class="sm-tbl"><tr><?php
$rb_i = 0;
foreach ($rb_caps as $rb_c) {
    if ($rb_i > 0 && $rb_i % 3 === 0) { echo '</tr><tr>'; }
    echo '<td><span class="sm-mono">' . rb_e($rb_c) . '</span></td>';
    $rb_i++;
}
while ($rb_i % 3 !== 0) { echo '<td></td>'; $rb_i++; }
?></tr></table></div>
<?php } ?>
</div>

<!-- ================= Protokoll ================= -->
<div class="sm-pane<?= $rb_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<?php /* Seit 1.1.4 zwei Dateien statt einer - der Bediener muss wissen, wo die
       * Fehlerausgabe der Schale steht. */ ?>
<div class="sm-hinweis"><?= ro_t('LOG.CRON_DATEI') ?></div>
<h2><?= rb_e(ro_t('REITER.LOG')) ?></h2>
<div class="sm-small" style="margin-bottom:8px;"><?= rb_e(ro_t('LOG.HINWEIS')) ?><br><?= rb_e(ro_t('LOG.DATEI')) ?> <span class="sm-mono"><?= rb_e($rb_logfile) ?></span></div>
<?php if ($rb_loglines) { ?>
<div class="sm-log"><?= rb_e(implode("\n", $rb_loglines)) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?= rb_e(ro_t('LOG.LEER')) ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= rb_e(ro_t('LEGENDE.AKTION')) ?></span>
</div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <?= rb_fmt() ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= rb_e(ro_t('KNOPF.LOG_LEEREN')) ?></button>
</form>
</div>
</div>

</div>
<script>
function rbTtsMode() {
    var m = document.getElementById('tts_mode').value;
    document.getElementById('tts_audioserver_hint').style.display = (m === 'audioserver') ? 'block' : 'none';
    document.getElementById('tts_template_row').style.display = (m === 'ms4h' || m === 'custom') ? 'block' : 'none';
    var al = document.getElementById('tts_alexa_rows');
    if (al) { al.style.display = (m === 'alexang' || al.querySelector('.sm-beanstandet')) ? 'block' : 'none'; }
    var gl = document.getElementById('tts_google_rows');
    if (gl) { gl.style.display = (m === 'cc4lox' || gl.querySelector('.sm-beanstandet')) ? 'block' : 'none'; }
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
if ($rb_frame) { LBWeb::lbfooter(); } else { echo '</body></html>'; }
