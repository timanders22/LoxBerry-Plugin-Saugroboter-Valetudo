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
        return array_merge(array('r_name', 'r_ip', 'r_port', 'r_user', 'r_pass_loeschen', 'cache_sec', 'warn_hours',
                     'warn_prozent', 'notify_audio', 'notify_push', 'n_fertig', 'n_fehler', 'n_material',
                     'n_ereignis'),
                     ansage_x2_felder(array('modi' => ro_tts_wege())));    // Nr. 36 b, Stufe 2: ohne Sprechtoken
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

/* --- Testansage (U14, Durchgang 01.10.2026; Nr. 36 b, Stufe 2: ansage_testansage()) ---
 * Spricht den Testsatz der gemeinsamen Sprachausgabe ueber den eingestellten Ausgabeweg - auch ueber
 * Alexa-NG und Google-Lautsprecher -, mit den GESPEICHERTEN Einstellungen. POST, danach 303. Ins
 * Protokoll nur die Kurzform, nie Text oder Token. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ro_testansage'])) {
    $rb_ak = ro_ansage_k();
    $rb_ar = ansage_testansage(ro_tts(), $rb_ak);
    ro_log('Testansage: ' . ansage_kurz($rb_ar));
    if ($rb_ar['stand'] === 1) {
        $rb_meldungen[] = rb_e(ro_t('TEXT.TESTANSAGE_OK'));
        /* Bei Google steht die Antwort dabei (HTTP-Code und GRUND, z. B. EINGEREIHT) - nie Token oder Text. */
        if ($rb_ar['art'] === 'cc4lox') {
            $rb_meldungen[] = rb_e(sprintf(ro_t('TEXT.GOOGLE_ANTWORT'), (int) $rb_ar['http'],
                preg_match('/(?:^|;)GRUND=([A-Za-z0-9_]{1,40})(?:;|$)/', $rb_ar['zeile'], $rb_gm) ? $rb_gm[1] : '-'));
        }
    } elseif ($rb_ar['stand'] === -1) {
        $rb_meldungen[] = rb_e(sprintf(ro_t('TEXT.TESTANSAGE_NICHTS'), ansage_kennung_text($rb_ar['kennung'], $rb_ak)));
    } else {
        $rb_fehler[] = rb_e(ro_t('TEXT.TESTANSAGE_FEHL') . ' ' . ansage_kennung_text($rb_ar['kennung'], $rb_ak));
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
    /* --- Sprachausgabe (Nr. 36 b, Stufe 2, 1.1.15): ansage_formular_lesen() - Adresse und Vorlage im
     * Heimnetz, Token leer = behalten, Haken = loeschen, beides zugleich ein Widerspruch, Alexa-NG/
     * Google ohne Token beanstandet; kein Token steht in einer Meldung. Jede Beanstandung verhindert
     * das Speichern (Nr. 16); die beanstandeten Felder werden markiert (X-2). */
    $rb_tmangel = array();
    $rb_tbean = array();
    $rb_neu['tts'] = ansage_formular_lesen($_POST, ro_tts(), $rb_tmangel, $rb_tbean, array('modi' => ro_tts_wege()),
                                           ro_ansage_k());
    foreach ($rb_tmangel as $rb_tm) { $rb_hw[] = $rb_wert(ro_t('EINST.H_SPRACHAUSGABE'), $rb_tm['text']); }
    foreach ($rb_tbean as $rb_tb) { rb_bean($rb_tb); }
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
list($rb_tts) = ansage_vervollstaendigen(is_array($rb_cfg['tts']) ? $rb_cfg['tts'] : array(), 'musicserver');
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
/* ERGAENZT 1.1.15 (Nr. 36 b, Stufe 2): der Formular-Baustein der gemeinsamen Sprachausgabe benutzt
   sm-feld und sm-hilfe aus VORLAGE_hausstandard.css.html - die folgenden Zeilen stehen dort so. */
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
/* Bedienelemente werden von jQuery Mobile umgebaut und bekommen einen eigenen
   Behaelter. Begrenzt man das Feld selbst, bleibt der Behaelter breit - man
   sieht ein schmales Feld in einem breiten weissen Kasten. Und beim
   Auswahlfeld liegt das unsichtbare <select> ueber dem Knopf und faengt die
   Klicks ab; wer es gestaltet, schiebt es weg. Deshalb wird ausschliesslich
   der Behaelter begrenzt. */
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
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
/* Ergaenzung (Welle Bild, Entscheidung 45): Bild der Bausteine aus dem gemeinsamen Musterprojekt. */
.sm-bild { margin: 12px 0; }
.sm-bild img { max-width: 100%; height: auto; border: 1px solid #ccc; border-radius: 4px; background: #fff; }
.sm-bild figcaption { font-size: .9em; color: #555; margin-top: 4px; }
/* Welle Bild 10 VOLL: Schritte und Hinweise je Baustein-Liste. */
.sm-schritte { margin: 6px 0 0 18px; padding: 0; }
.sm-schritte li { margin: 3px 0; }

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
<?php /* Kopf (Entscheidung Nr. 43, seit 1.1.16): Statusuebersicht ueber den
   Reitern, immer sichtbar - die Kaesten darunter gibt es nur je eingerichtetem
   Roboter. Kein Dienst, der Abruf laeuft im Minutencron. Nur Werte, die die
   Seite ohnehin liest (lauf.json wie der Reiter Test, $rb_robots, $rb_states,
   $rb_cfg); keine Netzabfrage. */
$rb_kok = 0;
foreach ($rb_states as $rb_ks) { if (!empty($rb_ks['ok'])) { $rb_kok++; } }
$rb_klauf = ro_lauf_lesen();
$rb_kalt = $rb_klauf['ts'] > 0 ? time() - (int) $rb_klauf['ts'] : -1; ?>
<table class="sm-tbl" style="max-width:620px">
<tr><th><?= rb_e(ro_t('WORT.EIGENSCHAFT')) ?></th><th><?= rb_e(ro_t('WORT.WERT')) ?></th></tr>
<tr><td><?= rb_e(ro_t('TEXT.KOPF_ABRUF')) ?></td>
    <td><?= rb_e(ro_t('TEXT.KOPF_OHNE_DIENST')) ?></td></tr>
<tr><td><?= rb_e(ro_t('TEXT.KOPF_LETZTER_LAUF')) ?></td>
    <td><?= $rb_kalt < 0 ? rb_e(ro_t('TEXT.KOPF_NOCH_KEIN_LAUF'))
        : rb_e(date('d.m.Y H:i:s', (int) $rb_klauf['ts'])
               . ($rb_kalt < 3600 ? ' ' . sprintf(ro_t('TEXT.KOPF_VOR_S'), $rb_kalt) : '')) ?></td></tr>
<tr><td><?= rb_e(ro_t('TEXT.KOPF_ROBOTER')) ?></td>
    <td><?= rb_e(sprintf(ro_t('TEXT.KOPF_ROBOTER_ZAHL'), count($rb_robots), $rb_kok)) ?></td></tr>
<tr><td><?= rb_e(ro_t('REITER.MQTT')) ?></td>
    <td><?= rb_e(!empty($rb_cfg['mqtt_enabled']) ? ro_t('TEXT.KOPF_EIN') : ro_t('TEXT.KOPF_AUS')) ?></td></tr>
</table>

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
<div class="sm-hinweis"><?= ro_t('EINST.WAS_IST_DAS') ?></div>
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
<div class="sm-hilfe"><?= rb_e(ro_t('EINST.TTS_SCHALTER')) ?></div>
<?php /* Nr. 36 b, Stufe 2 (1.1.15): der Formular-Baustein der gemeinsamen Sprachausgabe
         (ansage_formular_html(), Klassen sm-feld/sm-hilfe/sm-hinweis aus der Vorlage); X-2 ueber
         rb_w()/rb_m()/rb_haken(). Das Sprechtoken steht nie in der Seite, der Platzhalter nennt
         nur seine Laenge. */ ?>
<?= ansage_formular_html($rb_tts, array(
    'w' => function ($n, $g) { return rb_w($n, $g); },
    'm' => function ($n) { return rb_m($n); },
    'c' => function ($n, $g) { return rb_haken($n, $g); },
    'modi' => ro_tts_wege()), ro_ansage_k()) ?>

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
<?php /* X-8 (02.10.2026, Entscheidung 36): Komplette Baustein-Liste in EINER
         nummerierten Tabelle, eine Zeile je Baustein. Typ, Name, Parameter und
         Verbindung stehen in [BAUSTEIN] der Sprachdateien. Vorlagen-Titel,
         Adressen und Befehlstitel kommen aus DENSELBEN Funktionen wie die
         Importvorlagen: ro_vorlage(1) und ro_vo_vorlage(1) werden gerufen und
         ihre XML gelesen, nicht abgeschrieben.
         Welle Bild 10 VOLL (1.1.19): drei Listen statt einer, siehe unten. */
$rb_bs_lesen = function ($xml) {
    $w = function ($el, $attr) {
        return preg_match('/\s' . $attr . '="([^"]*)"/', $el, $m)
            ? html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8') : '';
    };
    $aus = array('titel' => '', 'adresse' => '', 'zyklus' => '', 'befehle' => array());
    if (preg_match('/<(?:VirtualInHttp|VirtualOut)\s[^>]*>/', $xml, $m)) {
        $aus['titel'] = $w($m[0], 'Title');
        $aus['adresse'] = $w($m[0], 'Address');
        $aus['zyklus'] = $w($m[0], 'PollingTime');
    }
    preg_match_all('/<(?:VirtualInHttpCmd|VirtualOutCmd)\s[^>]*>/', $xml, $mm);
    foreach ($mm[0] as $el) {
        $aus['befehle'][] = array('titel' => $w($el, 'Title'), 'check' => $w($el, 'Check'), 'ein' => $w($el, 'CmdOn'));
    }
    return $aus;
};
$rb_bs_xml = ro_vorlage(1);
$rb_bs_vi = $rb_bs_lesen($rb_bs_xml[1]);
$rb_bs_xml = ro_vo_vorlage(1);
$rb_bs_vo = $rb_bs_lesen($rb_bs_xml[1]);
$rb_bs_titel = array();
$rb_bs_check = array();
foreach ($rb_bs_vi['befehle'] as $rb_bs_b) {
    if (preg_match('/;([A-Z0-9_]+)=/', $rb_bs_b['check'], $rb_bs_m)) {
        $rb_bs_titel['F:' . $rb_bs_m[1]] = $rb_bs_b['titel'];
        $rb_bs_check[$rb_bs_m[1]] = $rb_bs_b['check'];
    }
}
foreach ($rb_bs_vo['befehle'] as $rb_bs_b) {
    if (preg_match('/[?&]cmd=([^&]+)/', $rb_bs_b['ein'], $rb_bs_m)) {
        $rb_bs_titel['A:' . rawurldecode($rb_bs_m[1])] = $rb_bs_b['titel'];
    }
}
/* Welle Bild 10 VOLL (1.1.19, Hausherr 11.10.2026: Vollausstattung, ein absoluter Laie soll
 * das Maximum leicht uebernehmen koennen): drei Seiten aus dem LoxBerry-Plugins Musterprojekt,
 * dort in Loxone Config gebaut und mit leitungen_setzen.py verbunden
 * (Musterprojekt/baustein_listen.txt, Abschnitte "Saugroboter" 44, "Saugroboter Wartung" 42,
 * "Saugroboter Steuerung" 42 Zeilen). Je Seite: Ueberschrift, Kasten "So uebernehmen Sie
 * diese Seite", Tabelle, Hinweise, Bild, Link - alle immer sichtbar. Fuer Roboter 1 wie die
 * Vorlagen-Knoepfe ohne Auswahl. Bis 1.1.18 stand hier eine Seite mit 21 Zeilen.
 * Namen aus den Vorlagen ($rb_bs_f: Eingangsbefehl zum Feld, $rb_bs_a: Ausgangsbefehl zu
 * ?cmd=) und die Vorlagentitel im Typ kommen aus ro_vorlage(1) / ro_vo_vorlage(1), oben
 * gelesen - nicht abgeschrieben. Texte aus [BAUSTEIN]; jede Zelle ist fertiges, maskiertes HTML.
 * Zeile: array(Nr, Typ, Name, Parameter, Eingaenge). */
$rb_bs_a = function ($cmd) use ($rb_bs_titel) {
    return isset($rb_bs_titel['A:' . $cmd]) ? $rb_bs_titel['A:' . $cmd] : '{A:' . $cmd . '}';
};
$rb_bs_f = function ($feld) use ($rb_bs_titel) {
    return isset($rb_bs_titel['F:' . $feld]) ? $rb_bs_titel['F:' . $feld] : '{F:' . $feld . '}';
};
/* Erst vsprintf auf den Sprachtext, dann maskieren: eingesetzte Titel kommen nie roh heraus. */
$rb_bs_k = function ($schluessel, $argumente = array()) {
    $s = (string) ro_t('BAUSTEIN.' . $schluessel);
    return rb_e($argumente ? vsprintf($s, $argumente) : $s);
};
$rb_bs_m = '&mdash;';
$rb_bs_ein = function ($nr) use ($rb_bs_k) { return $rb_bs_k('E_EIN', array($nr)); };
$rb_bs_aus = function ($pin, $nr) use ($rb_bs_k) {
    return $pin === '' ? $rb_bs_k('E_AUSGANG_NUR', array($nr)) : $rb_bs_k('E_AUSGANG', array($pin, $nr));
};
/* Befehl aus der Eingangsvorlage: Typ mit Vorlagentitel, Name = Titel des Befehls zum Feld. */
$rb_bs_vi_z = function ($nr, $feld, $param) use ($rb_bs_k, $rb_bs_f, $rb_bs_vi, $rb_bs_m) {
    return array($nr, $rb_bs_k('TYP_VI', array($rb_bs_vi['titel'])), rb_e($rb_bs_f($feld)),
                 $param === '' ? $rb_bs_m : $rb_bs_k($param), $rb_bs_m);
};
/* Befehl aus der Ausgangsvorlage: Name = Titel des Befehls zu ?cmd=. */
$rb_bs_vo_z = function ($nr, $cmd, $param, $verb) use ($rb_bs_k, $rb_bs_a, $rb_bs_vo, $rb_bs_m) {
    return array($nr, $rb_bs_k('TYP_VO', array($rb_bs_vo['titel'])), rb_e($rb_bs_a($cmd)),
                 $param === '' ? $rb_bs_m : $rb_bs_k($param), $verb);
};
/* Uebriger Baustein: Typ-Schluessel, Name <S>_NAME, Parameter <S>_PARAM. */
$rb_bs_zl = function ($nr, $typ, $s, $param, $verb) use ($rb_bs_k, $rb_bs_m) {
    return array($nr, $rb_bs_k($typ), $rb_bs_k($s . '_NAME'), $param === '' ? $rb_bs_m : $rb_bs_k($param), $verb);
};
$rb_bs_seiten = array(
    array('kopf' => 'BL_WERTE', 'seite' => 'Saugroboter', 'bild' => 'einbindung_loxone_werte.png', 'zeilen' => array(
    $rb_bs_vi_z(1, 'CODE', 'S1_01_PARAM'),
    $rb_bs_vi_z(2, 'BATT', 'S1_02_PARAM'),
    $rb_bs_vi_z(3, 'FEHLER', 'S1_03_PARAM'),
    $rb_bs_vi_z(4, 'MATWARN', 'S1_04_PARAM'),
    $rb_bs_vi_z(5, 'ANN', 'S1_05_PARAM'),
    $rb_bs_vi_z(6, 'PUSH', 'S1_06_PARAM'),
    $rb_bs_vi_z(7, 'OK', 'S1_07_PARAM'),
    $rb_bs_vi_z(8, 'ZAEHLER', 'S1_08_PARAM'),
    $rb_bs_zl(9, 'T_KONSTANTE', 'S1_09', 'S1_09_PARAM', $rb_bs_m),
    $rb_bs_zl(10, 'T_STATUS', 'S1_10', 'S1_10_PARAM', 'V1 = #1, V2 = #2'),
    $rb_bs_zl(11, 'T_UND', 'S1_11', '', 'I1 = #5, I2 = #6'),
    $rb_bs_zl(12, 'T_BENACHR', 'S1_12', 'S1_12_PARAM', $rb_bs_ein(11)),
    $rb_bs_zl(13, 'T_BENACHR', 'S1_13', 'S1_13_PARAM', $rb_bs_ein(4)),
    $rb_bs_zl(14, 'T_NICHT', 'S1_14', '', 'I = #7'),
    $rb_bs_zl(15, 'T_VALID', 'S1_15', 'S1_15_PARAM', 'V = #8, En = #9'),
    $rb_bs_zl(16, 'T_ODER', 'S1_16', '', 'I1 = #14, I2 = ' . $rb_bs_aus('E', 15)),
    $rb_bs_zl(17, 'T_ODER', 'S1_17', '', 'I1 = #3, I2 = #16'),
    $rb_bs_zl(18, 'T_EVZ', 'S1_18', 'S1_18_PARAM', $rb_bs_ein(17)),
    $rb_bs_zl(19, 'T_BENACHR', 'S1_19', 'S1_19_PARAM', $rb_bs_ein(18)),
    $rb_bs_zl(20, 'T_VTASTE', 'S1_20', 'S1_20_PARAM', $rb_bs_m),
    $rb_bs_vo_z(21, 'start', '', $rb_bs_ein(43)),
    $rb_bs_vi_z(22, 'LAEDT', 'S1_22_PARAM'),
    $rb_bs_zl(23, 'T_STATUS', 'S1_23', 'S1_23_PARAM', 'V1 = #22, V2 = #2'),
    $rb_bs_vi_z(24, 'PTEST', 'S1_24_PARAM'),
    $rb_bs_zl(25, 'T_BENACHR', 'S1_25', 'S1_25_PARAM', $rb_bs_ein(24)),
    $rb_bs_vi_z(26, 'ALTER', 'S1_26_PARAM'),
    $rb_bs_vi_z(27, 'AUDIO', 'S1_27_PARAM'),
    $rb_bs_zl(28, 'T_STATUS', 'S1_28', 'S1_28_PARAM', 'V1 = #26, V2 = #6, V3 = #27'),
    $rb_bs_zl(29, 'T_VSCHALTER', 'S1_29', 'S1_29_PARAM', $rb_bs_m),
    $rb_bs_zl(30, 'T_VEIN', 'S1_30', 'S1_30_PARAM', $rb_bs_m),
    $rb_bs_zl(31, 'T_NICHT', 'S1_31', '', 'I = #30'),
    $rb_bs_zl(32, 'T_EVZ', 'S1_32', 'S1_32_PARAM', $rb_bs_ein(31)),
    $rb_bs_zl(33, 'T_IMPULS', 'S1_33', 'S1_33_PARAM', $rb_bs_m),
    $rb_bs_zl(34, 'T_IMPULS', 'S1_34', 'S1_34_PARAM', $rb_bs_m),
    $rb_bs_zl(35, 'T_GLEICH', 'S1_35', 'S1_35_PARAM', 'V1 = #1'),
    $rb_bs_zl(36, 'T_ODER', 'S1_36', '', 'I1 = ' . $rb_bs_aus('Q', 34) . ', I2 = #35'),
    $rb_bs_zl(37, 'T_RS', 'S1_37', 'S1_37_PARAM', 'S = ' . $rb_bs_aus('Q', 33) . ', R = #36'),
    $rb_bs_zl(38, 'T_KLEINER', 'S1_38', 'S1_38_PARAM', 'V1 = #1'),
    $rb_bs_zl(39, 'T_UND', 'S1_39', '', 'I1 = #38, I2 = #7'),
    $rb_bs_zl(40, 'T_UND', 'S1_40', '', 'I1 = #29, I2 = #32'),
    $rb_bs_zl(41, 'T_UND', 'S1_41', '', 'I1 = #39, I2 = #40'),
    $rb_bs_zl(42, 'T_UND', 'S1_42', '', 'I1 = #41, I2 = ' . $rb_bs_aus('', 37)),
    $rb_bs_zl(43, 'T_ODER', 'S1_43', 'S1_43_PARAM', 'I1 = #20, I2 = #42'),
    $rb_bs_zl(44, 'T_STATUS', 'S1_44', 'S1_44_PARAM', 'V1 = #29, V2 = ' . $rb_bs_aus('', 37) . ', V3 = #32'),
    ),
      'schritte' => array(
        array('S1_SCHRITT1', array(ro_t('LOX.H_VORLAGE'), ro_t('KNOPF.VORLAGE_VI'), ro_t('KNOPF.VORLAGE_VO'))),
        array('S1_SCHRITT2', array($rb_bs_vi['titel'])),
        array('S1_SCHRITT3', array($rb_bs_a('start'), $rb_bs_vo['titel'])),
        array('S1_SCHRITT4', array()),
        array('S1_SCHRITT5', array()),
        array('S1_SCHRITT6', array(ro_t('BAUSTEIN.T_VERB'))),
        array('S1_SCHRITT7', array()),
        array('S1_SCHRITT8', array()),
        array('S1_SCHRITT9', array(ro_t('REITER.EINSTELLUNGEN'), ro_t('EINST.H_MELDUNGEN'), ro_t('EINST.PUSH_AKTIV'))),
        array('S1_SCHRITT10', array(ro_t('REITER.TEST'), ro_t('TEST.K_PTEST'))),
        array('S1_SCHRITT11', array()),
      ),
      'hinweise' => array(
        array('S1_H1', array($rb_bs_a('start'))),
        array('S1_H2', array()),
        array('S1_H3', array()),
        array('S1_H4', array()),
        array('S1_H5', array()),
        array('S1_H6', array()),
        array('S1_H7', array($rb_bs_f('MATWARN'))),
        array('S1_H8', array($rb_bs_f('ANN'), ro_t('REITER.EINSTELLUNGEN'), ro_t('EINST.H_SPRACHAUSGABE'))),
        array('S1_H9', array(ro_t('LOX.VORLAGE_ROBOTER'))),
      )),
    array('kopf' => 'BL_WARTUNG', 'seite' => 'Saugroboter Wartung', 'bild' => 'einbindung_loxone_wartung.png', 'zeilen' => array(
    $rb_bs_vi_z(1, 'FILTER', 'S2_01_PARAM'),
    $rb_bs_vi_z(2, 'FILTER2', 'S2_02_PARAM'),
    $rb_bs_vi_z(3, 'BHAUPT', 'S2_03_PARAM'),
    $rb_bs_vi_z(4, 'BSEITE', 'S2_04_PARAM'),
    $rb_bs_vi_z(5, 'BSEITE2', 'S2_05_PARAM'),
    $rb_bs_vi_z(6, 'SENSOR', 'S2_06_PARAM'),
    $rb_bs_vi_z(7, 'RAEDER', 'S2_07_PARAM'),
    $rb_bs_vi_z(8, 'MOP', 'S2_08_PARAM'),
    $rb_bs_zl(9, 'T_STATUS', 'S2_09', 'S2_09_PARAM', 'V1 = #1, V2 = #2, V3 = #3, V4 = #4, V5 = #5, V6 = #6, V7 = #7, V8 = #8'),
    $rb_bs_vi_z(10, 'DOCKFILTER', 'S2_10_PARAM'),
    $rb_bs_vi_z(11, 'DOCKBUERSTE', 'S2_11_PARAM'),
    $rb_bs_vi_z(12, 'DOCKBEHAELTER', 'S2_12_PARAM'),
    $rb_bs_vi_z(13, 'REINIGER', 'S2_13_PARAM'),
    $rb_bs_zl(14, 'T_STATUS', 'S2_14', 'S2_14_PARAM', 'V1 = #10, V2 = #11, V3 = #12, V4 = #13'),
    $rb_bs_zl(15, 'T_VTASTE', 'S2_15', 'S2_15_PARAM', $rb_bs_m),
    $rb_bs_vo_z(16, 'reset', 'S2_16_PARAM', $rb_bs_ein(15)),
    $rb_bs_vi_z(17, 'DOCK', 'S2_17_PARAM'),
    $rb_bs_zl(18, 'T_STATUS', 'S2_18', 'S2_18_PARAM', 'V1 = #17'),
    $rb_bs_zl(19, 'T_GLEICH', 'S2_19', 'S2_19_PARAM', 'V1 = #17'),
    $rb_bs_zl(20, 'T_EVZ', 'S2_20', 'S2_20_PARAM', $rb_bs_ein(19)),
    $rb_bs_zl(21, 'T_BENACHR', 'S2_21', 'S2_21_PARAM', $rb_bs_ein(20)),
    $rb_bs_vi_z(22, 'BEHAELTER', 'S2_22_PARAM'),
    $rb_bs_vi_z(23, 'WASSERTANK', 'S2_23_PARAM'),
    $rb_bs_vi_z(24, 'WISCHER', 'S2_24_PARAM'),
    $rb_bs_zl(25, 'T_STATUS', 'S2_25', 'S2_25_PARAM', 'V1 = #22, V2 = #23, V3 = #24'),
    $rb_bs_vi_z(26, 'FEHLER', 'S2_26_PARAM'),
    $rb_bs_vi_z(27, 'FSTUFE', 'S2_27_PARAM'),
    $rb_bs_vi_z(28, 'FTEIL', 'S2_28_PARAM'),
    $rb_bs_zl(29, 'T_STATUS', 'S2_29', 'S2_29_PARAM', 'V1 = #26, V2 = #27'),
    $rb_bs_zl(30, 'T_STATUS', 'S2_30', 'S2_30_PARAM', 'V1 = #28'),
    $rb_bs_vi_z(31, 'EVENT', 'S2_31_PARAM'),
    $rb_bs_vi_z(32, 'EVTYP', 'S2_32_PARAM'),
    $rb_bs_vi_z(33, 'EVMUELL', 'S2_33_PARAM'),
    $rb_bs_zl(34, 'T_STATUS', 'S2_34', 'S2_34_PARAM', 'V1 = #31, V2 = #32, V3 = #33'),
    $rb_bs_zl(35, 'T_VTASTE', 'S2_35', 'S2_35_PARAM', $rb_bs_m),
    $rb_bs_vo_z(36, 'evquittieren', '', $rb_bs_ein(35)),
    $rb_bs_vi_z(37, 'FLAECHE', 'S2_37_PARAM'),
    $rb_bs_vi_z(38, 'DAUER', 'S2_38_PARAM'),
    $rb_bs_vi_z(39, 'FLAECHEG', 'S2_39_PARAM'),
    $rb_bs_vi_z(40, 'DAUERG', 'S2_40_PARAM'),
    $rb_bs_vi_z(41, 'ANZAHLG', 'S2_41_PARAM'),
    $rb_bs_zl(42, 'T_STATUS', 'S2_42', 'S2_42_PARAM', 'V1 = #37, V2 = #38, V3 = #39, V4 = #40, V5 = #41'),
    ),
      'schritte' => array(
        array('S2_SCHRITT1', array()),
        array('S2_SCHRITT2', array($rb_bs_vi['titel'], $rb_bs_f('FEHLER'))),
        array('S2_SCHRITT3', array($rb_bs_vo['titel'], $rb_bs_a('reset'), $rb_bs_a('evquittieren'))),
        array('S2_SCHRITT4', array()),
        array('S2_SCHRITT5', array(ro_t('BAUSTEIN.T_VERB'))),
        array('S2_SCHRITT6', array()),
        array('S2_SCHRITT7', array()),
        array('S2_SCHRITT8', array(ro_t('REITER.EINSTELLUNGEN'), ro_t('EINST.WARN_STUNDEN'), ro_t('EINST.WARN_PROZENT'))),
        array('S2_SCHRITT9', array()),
      ),
      'hinweise' => array(
        array('S2_H1', array()),
        array('S2_H2', array()),
        array('S2_H3', array($rb_bs_a('reset'))),
        array('S2_H4', array()),
      )),
    array('kopf' => 'BL_STEUERUNG', 'seite' => 'Saugroboter Steuerung', 'bild' => 'einbindung_loxone_steuerung.png', 'zeilen' => array(
    $rb_bs_vi_z(1, 'CODE', 'S3_01_PARAM'),
    $rb_bs_vi_z(2, 'SAUGST', 'S3_02_PARAM'),
    $rb_bs_vi_z(3, 'WASSER', 'S3_03_PARAM'),
    $rb_bs_vi_z(4, 'MODUS', 'S3_04_PARAM'),
    $rb_bs_zl(5, 'T_STATUS', 'S3_05', 'S3_05_PARAM', 'V1 = #2'),
    $rb_bs_zl(6, 'T_STATUS', 'S3_06', 'S3_06_PARAM', 'V1 = #3'),
    $rb_bs_zl(7, 'T_STATUS', 'S3_07', 'S3_07_PARAM', 'V1 = #4'),
    $rb_bs_zl(8, 'T_VTASTE', 'S3_08', 'S3_08_PARAM', $rb_bs_m),
    $rb_bs_vo_z(9, 'stop', '', $rb_bs_ein(8)),
    $rb_bs_zl(10, 'T_VTASTE', 'S3_10', 'S3_10_PARAM', $rb_bs_m),
    $rb_bs_vo_z(11, 'pause', '', $rb_bs_ein(10)),
    $rb_bs_zl(12, 'T_VTASTE', 'S3_12', 'S3_12_PARAM', $rb_bs_m),
    $rb_bs_zl(13, 'T_VEIN', 'S3_13', 'S3_13_PARAM', $rb_bs_m),
    $rb_bs_zl(14, 'T_VSCHALTER', 'S3_14', 'S3_14_PARAM', $rb_bs_m),
    $rb_bs_zl(15, 'T_GLEICH', 'S3_15', 'S3_15_PARAM', 'V1 = #1'),
    $rb_bs_zl(16, 'T_FLANKE', 'S3_16', 'S3_16_PARAM', $rb_bs_ein(13)),
    $rb_bs_zl(17, 'T_UND', 'S3_17', '', 'I1 = #14, I2 = #15'),
    $rb_bs_zl(18, 'T_UND', 'S3_18', '', 'I1 = #17, I2 = ' . $rb_bs_aus('On', 16)),
    $rb_bs_zl(19, 'T_ODER', 'S3_19', 'S3_19_PARAM', 'I1 = #12, I2 = #18'),
    $rb_bs_vo_z(20, 'home', '', $rb_bs_ein(19)),
    $rb_bs_zl(21, 'T_VTASTE', 'S3_21', 'S3_21_PARAM', $rb_bs_m),
    $rb_bs_vo_z(22, 'locate', '', $rb_bs_ein(21)),
    $rb_bs_zl(23, 'T_VTASTE', 'S3_23', 'S3_23_PARAM', $rb_bs_m),
    $rb_bs_vo_z(24, 'segments', 'S3_24_PARAM', $rb_bs_ein(23)),
    $rb_bs_zl(25, 'T_VTASTE', 'S3_25', 'S3_25_PARAM', $rb_bs_m),
    $rb_bs_vo_z(26, 'zone', 'S3_26_PARAM', $rb_bs_ein(25)),
    $rb_bs_zl(27, 'T_VTASTE', 'S3_27', 'S3_27_PARAM', $rb_bs_m),
    $rb_bs_vo_z(28, 'goto', 'S3_28_PARAM', $rb_bs_ein(27)),
    $rb_bs_zl(29, 'T_VTASTE', 'S3_29', 'S3_29_PARAM', $rb_bs_m),
    $rb_bs_vo_z(30, 'fan', 'S3_30_PARAM', $rb_bs_ein(29)),
    $rb_bs_zl(31, 'T_VTASTE', 'S3_31', 'S3_31_PARAM', $rb_bs_m),
    $rb_bs_vo_z(32, 'wasser', 'S3_32_PARAM', $rb_bs_ein(31)),
    $rb_bs_zl(33, 'T_VTASTE', 'S3_33', 'S3_33_PARAM', $rb_bs_m),
    $rb_bs_vo_z(34, 'modus', 'S3_34_PARAM', $rb_bs_ein(33)),
    $rb_bs_zl(35, 'T_VTASTE', 'S3_35', 'S3_35_PARAM', $rb_bs_m),
    $rb_bs_vo_z(36, 'ruhezeit', 'S3_36_PARAM', $rb_bs_ein(35)),
    $rb_bs_zl(37, 'T_VTASTE', 'S3_37', 'S3_37_PARAM', $rb_bs_m),
    $rb_bs_vo_z(38, 'absaugen', '', $rb_bs_ein(37)),
    $rb_bs_zl(39, 'T_VTASTE', 'S3_39', 'S3_39_PARAM', $rb_bs_m),
    $rb_bs_vo_z(40, 'wischwaschen', '', $rb_bs_ein(39)),
    $rb_bs_zl(41, 'T_VTASTE', 'S3_41', 'S3_41_PARAM', $rb_bs_m),
    $rb_bs_vo_z(42, 'wischtrocknen', '', $rb_bs_ein(41)),
    ),
      'schritte' => array(
        array('S3_SCHRITT1', array()),
        array('S3_SCHRITT2', array($rb_bs_vi['titel'], $rb_bs_f('CODE'), $rb_bs_f('SAUGST'), $rb_bs_f('WASSER'), $rb_bs_f('MODUS'))),
        array('S3_SCHRITT3', array($rb_bs_vo['titel'], implode(', ', array_map($rb_bs_a, array('stop', 'pause', 'home', 'locate', 'segments', 'zone', 'goto', 'fan', 'wasser', 'modus', 'ruhezeit', 'absaugen', 'wischwaschen', 'wischtrocknen'))))),
        array('S3_SCHRITT4', array()),
        array('S3_SCHRITT5', array()),
        array('S3_SCHRITT6', array(ro_t('BAUSTEIN.T_VERB'))),
        array('S3_SCHRITT7', array()),
        array('S3_SCHRITT8', array(ro_t('LOX.H_TOKEN'))),
      ),
      'hinweise' => array(
        array('S3_H1', array()),
        array('S3_H2', array(ro_t('REITER.TEST'), ro_t('TEST.H_RAEUME'), $rb_bs_a('zone'), $rb_bs_a('goto'))),
        array('S3_H3', array()),
        array('S3_H4', array(ro_t('REITER.TEST'), ro_t('TEST.H_FAEHIGKEITEN'))),
        array('S3_H5', array($rb_bs_a('start'))),
      )),
);
?>
<?= ro_t('BAUSTEIN.TEXT') ?>
<?php foreach ($rb_bs_seiten as $rb_bs_s) { ?>
<h3 class="sm-h3"><?= rb_e(ro_t('LOX.' . $rb_bs_s['kopf'])) ?></h3>
<div class="sm-hinweis"><b><?= $rb_bs_k('SCHRITTE_TITEL') ?></b>
<ol class="sm-schritte">
<?php foreach ($rb_bs_s['schritte'] as $rb_bs_x) { ?>
<li><?= $rb_bs_k($rb_bs_x[0], $rb_bs_x[1]) ?></li>
<?php } ?>
</ol></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th>#</th><th><?= rb_e(ro_t('BAUSTEIN.T_TYP')) ?></th><th><?= rb_e(ro_t('BAUSTEIN.T_NAME')) ?></th><th><?= rb_e(ro_t('BAUSTEIN.T_PARAM')) ?></th><th><?= rb_e(ro_t('BAUSTEIN.T_VERB')) ?></th></tr>
<?php foreach ($rb_bs_s['zeilen'] as $rb_bs_r) { ?>
<tr><td><?= (int) $rb_bs_r[0] ?></td><td><?= $rb_bs_r[1] ?></td><td><span class="sm-mono"><?= $rb_bs_r[2] ?></span></td><td><?= $rb_bs_r[3] ?></td><td><?= $rb_bs_r[4] ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-warnung"><b><?= $rb_bs_k('HINWEISE_TITEL') ?></b>
<ul class="sm-schritte">
<?php foreach ($rb_bs_s['hinweise'] as $rb_bs_x) { ?>
<li><?= $rb_bs_k($rb_bs_x[0], $rb_bs_x[1]) ?></li>
<?php } ?>
</ul></div>
<figure class="sm-bild">
<img src="<?= rb_e($rb_bs_s['bild']) ?>" alt="<?= rb_e(sprintf(ro_t('LOX.BILD_ALT'), $rb_bs_s['seite'])) ?>" loading="lazy">
<figcaption><?= rb_e(sprintf(ro_t('LOX.BILD_UNTERSCHRIFT'), $rb_bs_s['seite'])) ?></figcaption>
</figure>
<div class="sm-small"><?= ro_t('LOX.MUSTERPROJEKT') ?></div>
<?php } ?>
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
(function () {
    var tabs = document.querySelectorAll('.sm-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('sm-active', t.dataset.pane === id); });
        document.querySelectorAll('.sm-pane').forEach(function (p) { p.classList.toggle('sm-active', p.id === id); });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function (e) { e.preventDefault(); activate(t.dataset.pane); }); });
    activate(<?= json_encode($rb_tab) ?>);
})();
</script>
<?php
if ($rb_frame) { LBWeb::lbfooter(); } else { echo '</body></html>'; }
