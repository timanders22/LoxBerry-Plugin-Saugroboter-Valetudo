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
$rb_cloud = null;

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
        // Gruppe 2 (1.1.22): dazu die beiden Haken der Befehlsbruecke (Entwurf sv 3.3).
        return array('mqtt_enabled', 'mqtt_topic', 'befehle_mqtt_ein', 'befehle_mqtt_steuern');
    }
    if ($formular === 'settings') {
        // Gruppe 28 (1.1.23): dazu r_typ und r_token_loeschen - der Token selbst (r_token) reist NIE mit (N11).
        return array_merge(array('r_name', 'r_ip', 'r_port', 'r_user', 'r_pass_loeschen', 'r_typ', 'r_token_loeschen',
                     'cache_sec', 'warn_hours',
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
    // Gruppe 28 (1.1.23): das Ergebnis des Cloud-Abrufs (ohne Token) - nur fuer die Anzeige im Reiter Einstellungen.
    $rb_cloud = (isset($rb_flash['cloud']) && is_array($rb_flash['cloud']) && isset($rb_flash['cloud']['lage']))
        ? $rb_flash['cloud'] : null;
    if (isset($rb_flash['tab']) && is_string($rb_flash['tab']) && in_array($rb_flash['tab'], $rb_reiterliste, true)) {
        $rb_tab = $rb_flash['tab'];
    }
    rb_eingaben(isset($rb_flash['eingaben']) ? $rb_flash['eingaben'] : array());     // X-2
}
if ($rb_token_meldung !== '') { $rb_fehler[] = rb_e($rb_token_meldung); }

// --- Downloads. Sie enden mit exit und muessen VOR lbheader() stehen. ---
/* ---------- Gruppe 2 (1.1.22): Vorlage der MQTT-Befehle (Download, veraendert nichts). Fuer die Werte ueber das
 * MQTT-Gateway gibt es keine Vorlage (Hausherr 19.10.2026, Regeln/07 R2_078): Namenstabelle in Schritt 3. ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vorlage_befehle'])) {
    $rb_vq = ro_vorlage_befehle();
    if ($rb_vq === null) {
        list(, $rb_vqf) = ro_befehle_liste();
        rb_umleiten('tab-loxone', array('fehler' => array(rb_e(sprintf(ro_t('BEFEHL.LISTE_FEHLT'), $rb_vqf)))));
    }
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $rb_vq[0] . '"');
    echo $rb_vq[1];
    exit;
}
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
            (string) @file_get_contents($_FILES['ro_sicherung']['tmp_name'])), 5, array());
        list($rb_neu, $rb_mangel, $rb_n, $rb_beh, $rb_spaet) = $rb_erg;
        if ($rb_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert
             * wird nichts. */
            $rb_fehler[] = ro_t('TEXT.SICH_ABGELEHNT') . ' ' . implode(' ', $rb_mangel);
        } elseif (ro_config_speichern($rb_neu)) {
            $rb_meldungen[] = sprintf(ro_t('TEXT.SICH_UEBERNOMMEN'), $rb_n);
            if (is_array($rb_beh) && in_array('aktionstoken', $rb_beh, true)) {
                $rb_meldungen[] = rb_e(ro_t('TEXT.SICH_TOKEN_BEHALTEN'));
            }
            // Gruppe 28 (1.1.23, N7): Spaetschluessel, die die Sicherung nicht kannte, gelten weiter wie eingestellt.
            foreach ((array) $rb_spaet as $rb_sp) { $rb_meldungen[] = rb_e((string) $rb_sp); }
        } else {
            $rb_fehler[] = ro_t('TEXT.SICH_SCHREIBFEHLER');
        }
    }
    rb_umleiten('tab-settings', array('meldungen' => $rb_meldungen, 'fehler' => $rb_fehler));
}

// --- Protokoll leeren ---
/* Gruppe 28 (1.1.23, S4; Entscheidung 1, Regeln/04 :289): ein Haekchen zur Bestaetigung, sonst passiert nichts; geleert
 * werden robo.log UND cron.log und befehle_start.log (beide zeigt der Reiter), je mit gepruefter Rueckgabe. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearlog'])) {
    if (empty($_POST['clearlog_ok'])) {
        rb_umleiten('tab-log', array('fehler' => array(rb_e(ro_t('TEXT.LOG_HAKEN_FEHLT')))));
    }
    @mkdir(dirname($rb_logfile), 0775, true);
    $rb_lweg = array(); $rb_lbleibt = array();
    if (@file_put_contents($rb_logfile, '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Admin-Oberflaeche)\n") !== false) {
        $rb_lweg[] = basename($rb_logfile);
    } else {
        $rb_lbleibt[] = basename($rb_logfile);
    }
    foreach (array('cron.log', 'befehle_start.log') as $rb_ld) {
        $rb_lf = dirname($rb_logfile) . '/' . $rb_ld;
        if (!is_file($rb_lf)) { continue; }
        if (@file_put_contents($rb_lf, '') !== false) { $rb_lweg[] = $rb_ld; } else { $rb_lbleibt[] = $rb_ld; }
    }
    if ($rb_lweg) { $rb_meldungen[] = rb_e(sprintf(ro_t('TEXT.LOG_GELEERT_DATEIEN'), implode(', ', $rb_lweg))); }
    if ($rb_lbleibt) { $rb_fehler[] = rb_e(sprintf(ro_t('TEXT.LOG_NICHT_GELEERT_DATEIEN'), implode(', ', $rb_lbleibt))); }
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

/* --- Gruppe 28 (1.1.23, E-E12): Reiter Einbindung - den HTTP-Lesezweig fuer Loxone an- oder abschalten (endpunkt_ein).
 * Neuinstallation aus, Update an (Lese-Regel, postupgrade.sh); ?selftest, ?cmd und ?ptest bleiben in jedem Fall offen. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['endpunkt_save'])) {
    $rb_neu = ro_config();
    $rb_neu['endpunkt_ein'] = isset($_POST['endpunkt_ein']) ? 1 : 0;
    if (ro_config_speichern($rb_neu)) {
        rb_umleiten('tab-loxone', array('saved' => 1, 'meldungen' => array(rb_e(ro_t(
            $rb_neu['endpunkt_ein'] ? 'LOX.ENDPUNKT_AN' : 'LOX.ENDPUNKT_AUS')))));
    }
    rb_umleiten('tab-loxone', array('fehler' => array(rb_e(ro_t('TEXT.NICHT_GESPEICHERT') . ' ' . $rb_cfgfile))));
}

/* --- Gruppe 28 (1.1.23, Entscheidung "Token-Beschaffung"): Token aus der Xiaomi-Cloud holen ---
 * Nur POST, nur mit Formularmerkmal (Wachposten oben), nur im angemeldeten Bereich. Das Kennwort lebt nur in diesem
 * einen Aufruf: es wird gelesen, an xc_abruf() (Stammmodul xiaomi_cloud.php, ueber ro_xc()) gegeben und danach in $_POST und der Kopie ueberschrieben - nie
 * gespeichert, nie protokolliert, nie in einer Meldung, nie in den Eingaben nach einer Beanstandung (X-2). Ergebnis
 * (Liste ohne Token, Zweitfaktor-Link) reist mit der Umleitung; die Auswahl selbst liegt 0600 im data-Ordner. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['cloud_holen']) || isset($_POST['cloud_code_senden'])
        || isset($_POST['cloud_captcha_senden']) || isset($_POST['cloud_weg']))) {
    $rb_czeile = 0;          // Gruppe 37 (1.1.24): keine Zielzeile mehr - die Mehrfachauswahl verteilt (ro_token_mehrere())
    $rb_cfilter = ro_xc();   // Stammmodul laden, Ablage data-Ordner, Filter xiaomi.vacuum.* (nur WLAN mit Token)
    if (isset($_POST['cloud_captcha_senden'])) {
        // Nachbau 26.10. spaet: Captcha-Loesung des Hausherrn + Kennwort (erneut, nie gespeichert) - EIN Versuch je Bild.
        $rb_closung = (isset($_POST['cloud_captcha']) && is_string($_POST['cloud_captcha'])) ? trim($_POST['cloud_captcha']) : '';
        $rb_cpass = (isset($_POST['cloud_pass']) && is_string($_POST['cloud_pass'])) ? $_POST['cloud_pass'] : '';
        $_POST['cloud_pass'] = '';
        $rb_cerg = xc_captcha_loesen($rb_closung, $rb_cpass);
        $rb_cpass = str_repeat("\0", strlen($rb_cpass));
        $rb_cpass = '';
        $rb_closung = '';
    } elseif (isset($_POST['cloud_weg'])) {
        // Zweitfaktor: der Hausherr waehlt, wohin Xiaomi den Code schickt (Mail oder SMS).
        $rb_cweg = (is_string($_POST['cloud_weg']) && in_array($_POST['cloud_weg'], array('mail', 'sms'), true)) ? $_POST['cloud_weg'] : '';
        $rb_cerg = xc_code_anfordern($rb_cweg);
    } elseif (isset($_POST['cloud_code_senden'])) {
        $rb_ccode = (isset($_POST['cloud_code']) && is_string($_POST['cloud_code'])) ? trim($_POST['cloud_code']) : '';
        $rb_cerg = xc_abruf('', '', 'de', $rb_czeile, $rb_cfilter, $rb_ccode);
        $rb_ccode = '';
    } else {
        $rb_ckonto = (isset($_POST['cloud_konto']) && is_string($_POST['cloud_konto'])) ? trim($_POST['cloud_konto']) : '';
        $rb_cpass = (isset($_POST['cloud_pass']) && is_string($_POST['cloud_pass'])) ? $_POST['cloud_pass'] : '';
        $rb_creg = (isset($_POST['cloud_region']) && is_string($_POST['cloud_region'])) ? $_POST['cloud_region'] : 'de';
        $_POST['cloud_pass'] = '';
        if ($rb_ckonto === '' || $rb_cpass === '') {
            $rb_cerg = array('lage' => 'LEER');
        } else {
            $rb_cerg = xc_abruf($rb_ckonto, $rb_cpass, $rb_creg, $rb_czeile, $rb_cfilter);
        }
        $rb_cpass = str_repeat("\0", strlen($rb_cpass));
        $rb_cpass = '';
    }
    $rb_clage = isset($rb_cerg['lage']) ? (string) $rb_cerg['lage'] : 'ANTWORT';
    // Ins Protokoll nur die Lage - nie Konto, Kennwort, Code, Sitzung oder Token.
    ro_log('Token-Abruf aus der Xiaomi-Cloud: ' . $rb_clage
           . (isset($rb_cerg['geraete']) ? ' (' . count($rb_cerg['geraete']) . ' Saugroboter)' : ''));
    $rb_cflash = array('cloud' => array('lage' => $rb_clage));
    $rb_cks = array('SPERRE' => 'CLOUD.F_SENDEN_SPERRE', 'CAPTCHA' => 'CLOUD.F_SENDEN_CAPTCHA', 'UNBEKANNT' => 'CLOUD.F_SENDEN_UNBEKANNT');
    if ($rb_clage === 'ok') {
        $rb_cflash['cloud']['geraete'] = $rb_cerg['geraete'];
        $rb_cflash['meldungen'] = array(rb_e(sprintf(ro_t('CLOUD.M_GEFUNDEN'), count($rb_cerg['geraete']),
            implode(', ', $rb_cerg['regionen']))));
    } elseif ($rb_clage === 'ZWEITFAKTOR') {
        $rb_cflash['cloud']['link'] = (string) $rb_cerg['link'];
        $rb_cflash['meldungen'] = array(rb_e(ro_t('CLOUD.M_ZWEITFAKTOR')));
    } elseif ($rb_clage === 'CODE_GESENDET') {
        $rb_cflash['meldungen'] = array(rb_e(ro_t($rb_cerg['weg'] === 'sms' ? 'CLOUD.M_CODE_SMS' : 'CLOUD.M_CODE_MAIL')));
    } elseif ($rb_clage === 'CAPTCHA' || $rb_clage === 'CAPTCHA_FALSCH') {
        $rb_cflash[$rb_clage === 'CAPTCHA' ? 'meldungen' : 'fehler'] = array(rb_e(ro_t($rb_clage === 'CAPTCHA' ? 'CLOUD.M_CAPTCHA' : 'CLOUD.M_CAPTCHA_FALSCH')));
    } elseif ($rb_clage === 'SENDEN' && isset($rb_cerg['art'], $rb_cks[$rb_cerg['art']])) {
        // Gruppe 37 (1.1.24, Stammmodul 1.0.1): warum Xiaomi den Code nicht verschickt hat - mit der (bereinigten) Antwort
        $rb_cgr = (isset($rb_cerg['grund']) && is_string($rb_cerg['grund'])) ? $rb_cerg['grund'] : '';
        $rb_cflash['fehler'] = array(rb_e(ro_t($rb_cks[$rb_cerg['art']])
            . ($rb_cgr !== '' ? sprintf(ro_t('CLOUD.F_SENDEN_ANTWORT'), $rb_cgr) : '')));
    } elseif ($rb_clage === 'KEINE') {
        $rb_cflash['fehler'] = array(rb_e(sprintf(ro_t('CLOUD.F_KEINE'), implode(', ', $rb_cerg['regionen']))));
    } else {
        $rb_ck = 'CLOUD.F_' . preg_replace('/[^A-Z]/', '', $rb_clage);
        $rb_cflash['fehler'] = array(rb_e(ro_t($rb_ck) !== $rb_ck ? ro_t($rb_ck) : ro_t('CLOUD.F_ANTWORT')));
    }
    unset($rb_cerg);
    rb_umleiten('tab-settings', $rb_cflash);
}
/* Gruppe 37 (1.1.24): Mehrfachauswahl wie Xiaomi Licht 0.9.1 - die angehakten Indizes (cloud_index[]) mit EINEM Griff
 * ins Stammmodul (xc_auswahl_mehrere()), gespeichert wird einmal (ro_token_mehrere()). Nichts angehakt: die Liste
 * bleibt liegen und erscheint noch einmal (ohne Token). Ins Protokoll nur Zahlen und Gruende, nie Token oder Adresse. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cloud_nehmen'])) {
    $rb_cwahl = array();
    foreach ((isset($_POST['cloud_index']) && is_array($_POST['cloud_index'])) ? $_POST['cloud_index'] : array() as $rb_ci) {
        if (is_string($rb_ci) && preg_match('/^[0-9]{1,3}\z/', $rb_ci) && count($rb_cwahl) < 100) { $rb_cwahl[] = (int) $rb_ci; }
    }
    $rb_cerg = ro_token_mehrere($rb_cwahl);
    $rb_cab = array();
    foreach ($rb_cerg[3] as $rb_ck => $rb_cn) { $rb_cab[] = $rb_ck . ' ' . count($rb_cn); }
    ro_log('Token aus der Xiaomi-Cloud: ' . $rb_cerg[0] . ', ' . count($rb_cerg[1]) . ' uebernommen, ' . count($rb_cerg[2])
           . ' aufgefrischt' . ($rb_cab ? ', abgewiesen: ' . implode(', ', $rb_cab) : ''));
    list($rb_cgut, $rb_cschlecht) = ro_xc_meldungen($rb_cerg);
    $rb_cflash = array('meldungen' => array_map('rb_e', $rb_cgut), 'fehler' => array_map('rb_e', $rb_cschlecht));
    if ($rb_cerg[0] === 'ok' && ($rb_cerg[1] || $rb_cerg[2])) { $rb_cflash['saved'] = 1; }
    if ($rb_cerg[0] === 'WAHL' && ($rb_cliegt = xc_lesen('auswahl')) !== null && isset($rb_cliegt['liste'])) {
        $rb_cflash['cloud'] = array('lage' => 'ok', 'geraete' => ro_xc_zeigen($rb_cliegt['liste']));   // Liste noch einmal zeigen
    }
    unset($rb_cerg, $rb_cliegt);
    rb_umleiten('tab-settings', $rb_cflash);
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
    // Gruppe 2 (1.1.22): die beiden Haken der Befehlsbruecke (Entwurf sv 3.3, E4), ab Werk aus.
    $rb_neu['befehle_mqtt_ein'] = isset($_POST['befehle_mqtt_ein']) ? 1 : 0;
    $rb_neu['befehle_mqtt_steuern'] = isset($_POST['befehle_mqtt_steuern']) ? 1 : 0;
    if (ro_config_speichern($rb_neu)) {
        ro_abo_datei($rb_thema, true);
        /* Gruppe 2 (1.1.22): die Befehlsbruecke sofort starten bzw. anhalten (ro_bruecke_schalten()) - der Waechter
         * im Minutentakt startet hoechstens alle 600 s neu. Die Ausgabe von befehlsbruecke.sh traegt nie ein
         * Kennwort; sie wird gekuerzt und maskiert. */
        list($rb_brc, $rb_baus, $rb_bart) = ro_bruecke_schalten();
        $rb_baus = substr((string) preg_replace('/[^\x20-\x7E]/', ' ', (string) $rb_baus), 0, 160);
        $rb_bm = array();
        $rb_bf = array();
        if ($rb_bart === 'start') {
            if ($rb_brc === 0) { $rb_bm[] = rb_e(sprintf(ro_t('BEFEHL.M_EIN'), $rb_baus)); }
            else { $rb_bf[] = rb_e(sprintf(ro_t('BEFEHL.M_START_FEHL'), $rb_baus)); }
        } elseif ($rb_bart === 'stop') {
            if ($rb_brc === 0) { $rb_bm[] = rb_e(ro_t('BEFEHL.M_AUS')); }
            else { $rb_bf[] = rb_e(sprintf(ro_t('BEFEHL.M_STOP_FEHL'), $rb_baus)); }
        } elseif ($rb_brc !== 0) {
            $rb_bf[] = rb_e(sprintf(ro_t('BEFEHL.M_FEHLT'), $rb_baus));
        }
        rb_umleiten('tab-mqtt', array('saved' => 1, 'meldungen' => $rb_bm, 'fehler' => $rb_bf));
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
    $rb_zpos = array();   // Gruppe 28 (S3): Zeilenposition -> Geraetenummer
    // Nachbau 27.10.: bis zu RO_ROBOTER_MAX (5) Zeilen; leere Zeilen bleiben still ungenutzt.
    for ($rb_i = 0; $rb_i < RO_ROBOTER_MAX; $rb_i++) {
        /* DIE GERAETENUMMER REIST MIT DER ZEILE, NICHT MIT DER POSITION.
         *
         * An ihr haengen virtueller Eingang, MQTT-Thema und Endpunktadresse.
         * Bis 1.1.3 war sie eine Aufzaehlung ueber die nicht leeren Zeilen:
         * wer die erste Adresse loeschte, bekam fuer &dev=1 den zweiten
         * Roboter. Und das Kennwort wurde ueber $rb_alt[$rb_i + 1] geholt,
         * also ebenfalls ueber die Position - gemessen am 04.09.2026 ging es
         * dabei still verloren, sobald die erste Zeile schon ohne Adresse
         * dastand. Beides haengt jetzt am versteckten Feld r_nr[]. */
        $rb_nr = (isset($rb_nr_feld[$rb_i]) && is_string($rb_nr_feld[$rb_i]) && preg_match('/^[1-5]\z/', $rb_nr_feld[$rb_i]))
            ? (int) $rb_nr_feld[$rb_i] : 0;
        // Kam die Nummer wirklich aus dem Formular? Nur dann darf ueber sie
        // ein Kennwort geerbt werden (siehe weiter unten).
        $rb_nr_echt = ($rb_nr >= 1 && $rb_nr <= RO_ROBOTER_MAX && !in_array($rb_nr, $rb_vergeben, true));
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
        if ($rb_ip === '') {
            /* Gruppe 28 (1.1.23, S2; Entscheidung 1): eine Zeile OHNE Adresse, aber mit Inhalt (Name, Port ausser
             * 80/54321, Benutzer, Kennwort, Token) wuerde still verworfen - beanstanden. Eine ganz leere Zeile bleibt
             * still ungenutzt. */
            $rb_lp = $rb_zeile('r_port', $rb_i);
            $rb_inhalt = (string) $rb_zeile('r_name', $rb_i) !== '' || (string) $rb_zeile('r_user', $rb_i) !== ''
                || !in_array((string) $rb_lp, array('', '80', '54321'), true)
                || (isset($_POST['r_pass'][$rb_i]) && is_string($_POST['r_pass'][$rb_i]) && $_POST['r_pass'][$rb_i] !== '')
                || (isset($_POST['r_token'][$rb_i]) && is_string($_POST['r_token'][$rb_i]) && trim($_POST['r_token'][$rb_i]) !== '');
            if ($rb_inhalt) {
                $rb_hw[] = rb_e(sprintf(ro_t('TEXT.ADRESSE_FEHLT'), $rb_nr));
                rb_bean('r_ip', $rb_i);
            }
            continue;
        }
        $rb_vergeben[] = $rb_nr;
        $rb_zpos[$rb_i] = $rb_nr;
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
        /* Gruppe 28 (1.1.23, S2; Entscheidung 1): Kennwort eingetippt UND "loeschen" angehakt ist ein Widerspruch -
         * beanstanden, nichts speichern. Bis 1.1.22 gewann still der Haken. */
        if ($rb_pw_roh !== '' && $rb_loeschen) {
            $rb_hw[] = rb_e(sprintf(ro_t('TEXT.KENNWORT_UND_LOESCHEN'), $rb_nr));
            rb_bean('r_pass', $rb_i);
            rb_bean('r_pass_loeschen', $rb_i);
        }
        $rb_pw = $rb_pw_roh;
        if ($rb_pw === '' && $rb_nr_echt && !$rb_loeschen && isset($rb_alt[$rb_nr]['pass'])) {
            $rb_pw = (string) $rb_alt[$rb_nr]['pass'];
        }
        if ($rb_loeschen) { $rb_pw = ''; }
        /* Gruppe 28 (1.1.23, E-E1/E-E3): Geraetetyp und Token. Der Token: Passwortfeld, nie vorbefuellt, nie in den
         * Eingaben nach einer Beanstandung (N11 - r_token steht in keiner Liste von rb_eingabe_felder()); Leerraum
         * weg, Grossbuchstaben klein, nur 32 Hex; ein leeres Feld behaelt den alten Token (nur ueber eine echte
         * Geraetenummer, wie das Kennwort); Token UND "loeschen" zugleich ist ein Widerspruch. Kein Token in einer
         * Meldung. */
        $rb_typ = $rb_zeile('r_typ', $rb_i);
        if ($rb_typ === '') { $rb_typ = 'valetudo'; }
        if ($rb_typ === null || !in_array($rb_typ, ro_robot_typen(), true)) {
            $rb_hw[] = $rb_wert(sprintf(ro_t('EINST.ROBOTER_FELD'), $rb_nr, ro_t('EINST.TYP')), ro_t('GRUND.TYP'));
            rb_bean('r_typ', $rb_i);
            $rb_typ = 'valetudo';
        }
        $rb_tok_roh = (isset($_POST['r_token']) && is_array($_POST['r_token']) && isset($_POST['r_token'][$rb_i]))
            ? $_POST['r_token'][$rb_i] : '';
        $rb_tok_loeschen = (isset($_POST['r_token_loeschen']) && is_array($_POST['r_token_loeschen'])
            && !empty($_POST['r_token_loeschen'][$rb_i]));
        $rb_tok_alt = ($rb_nr_echt && isset($rb_alt[$rb_nr]['token'])) ? (string) $rb_alt[$rb_nr]['token'] : '';
        $rb_tok = $rb_tok_alt;
        if (!is_string($rb_tok_roh) || preg_match('/[\x00-\x1F\x7F]/', $rb_tok_roh)) {
            $rb_hw[] = $rb_wert(sprintf(ro_t('EINST.ROBOTER_FELD'), $rb_nr, ro_t('EINST.TOKEN')), ro_t('TEXT.SICH_STEUERZEICHEN'));
            rb_bean('r_token', $rb_i);
        } else {
            $rb_tok_roh = strtolower(trim($rb_tok_roh));
            if ($rb_tok_roh !== '' && $rb_tok_loeschen) {
                $rb_hw[] = rb_e(sprintf(ro_t('TEXT.TOKEN_UND_LOESCHEN'), $rb_nr));
                rb_bean('r_token', $rb_i);
                rb_bean('r_token_loeschen', $rb_i);
            } elseif ($rb_tok_loeschen) {
                $rb_tok = '';
            } elseif ($rb_tok_roh !== '') {
                if (preg_match('/^[0-9a-f]{32}\z/', $rb_tok_roh)) {
                    $rb_tok = $rb_tok_roh;
                } else {
                    $rb_hw[] = $rb_wert(sprintf(ro_t('EINST.ROBOTER_FELD'), $rb_nr, ro_t('EINST.TOKEN')), ro_t('GRUND.MIOT_TOKEN'));
                    rb_bean('r_token', $rb_i);
                }
            }
        }
        $rb_tok_roh = '';
        $rb_neu['robots'][] = array(
            'nr' => $rb_nr,
            'name' => (string) $rb_name,
            'ip' => $rb_ip,
            'port' => (int) $rb_port,
            'user' => (string) $rb_user,
            'pass' => $rb_pw,
            'typ' => $rb_typ,
            'token' => $rb_tok);
    }
    /* Gruppe 28 (1.1.23, S3): zwei Zeilen mit derselben Adresse fragten denselben Roboter doppelt ab - beanstanden.
     * Valetudo: gleiche IP und Port; Xiaomi: gleiche IP (Port fest 54321). */
    $rb_adr = array();
    foreach ($rb_neu['robots'] as $rb_ri => $rb_rz) {
        $rb_ak = $rb_rz['typ'] === 'miot' ? 'miot|' . strtolower($rb_rz['ip'])
                                          : strtolower($rb_rz['ip']) . ':' . (int) $rb_rz['port'];
        if (isset($rb_adr[$rb_ak])) {
            $rb_hw[] = rb_e(sprintf(ro_t('TEXT.ADRESSE_DOPPELT'), (int) $rb_neu['robots'][$rb_adr[$rb_ak]]['nr'], (int) $rb_rz['nr']));
            foreach ($rb_zpos as $rb_zi => $rb_znr) {
                if ($rb_znr === (int) $rb_rz['nr'] || $rb_znr === (int) $rb_neu['robots'][$rb_adr[$rb_ak]]['nr']) { rb_bean('r_ip', $rb_zi); }
            }
        } else {
            $rb_adr[$rb_ak] = $rb_ri;
        }
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
/** Nachbau 27.10. (T12): Reststunden und - meldet der Roboter ihn - der Restanteil: "120 h (67 %)". */
function rb_hp(array $s, $k) {
    $p = isset($s[$k . '_pct']) ? (int) $s[$k . '_pct'] : -1;
    return rb_h(isset($s[$k]) ? $s[$k] : -1) . ($p >= 0 ? ' (' . $p . '&nbsp;%)' : '');
}
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
        'https://wiki.loxberry.de/plugins/saugrobo/start', 'help.html');   // Gruppe 28 (1.1.23, S10): eigene Wiki-Seite
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

/* Gruppe 2 (1.1.22): der zugeklappte Abschnitt "Bisherige Anlagen (HTTP)" im Reiter Einbindung. */
.sm-http { margin: 18px 0 8px; }
.sm-http > summary { cursor: pointer; margin: 6px 0; }
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
<?php /* Nachbau 27.10. (Minutenbudget): Dauer der Abfragen und ausgelassene Roboter aus lauf.json. */ ?>
<?php if ($rb_kalt >= 0 && $rb_klauf['dauer_ms'] >= 0) { ?><tr><td><?= rb_e(ro_t('TEXT.KOPF_DAUER')) ?></td>
    <td><?= rb_e(sprintf(ro_t('TEXT.KOPF_DAUER_TEXT'), number_format($rb_klauf['dauer_ms'] / 1000, 1, ro_sprache() === 'de' ? ',' : '.', ''), RO_LAUF_FRIST)) ?></td></tr><?php } ?>
<?php if ($rb_kalt >= 0 && $rb_klauf['verspaetet']) { ?><tr><td><?= rb_e(ro_t('TEXT.KOPF_VERSPAETET')) ?></td>
    <td><b><?= rb_e(sprintf(ro_t('TEXT.KOPF_VERSPAETET_TEXT'), implode(', ', $rb_klauf['verspaetet']), RO_LAUF_FRIST)) ?></b></td></tr><?php } ?>
<tr><td><?= rb_e(ro_t('TEXT.KOPF_ROBOTER')) ?></td>
    <td><?= rb_e(sprintf(ro_t('TEXT.KOPF_ROBOTER_ZAHL'), count($rb_robots), $rb_kok)) ?></td></tr>
<tr><td><?= rb_e(ro_t('REITER.MQTT')) ?></td>
    <td><?= rb_e(!empty($rb_cfg['mqtt_enabled']) ? ro_t('TEXT.KOPF_EIN') : ro_t('TEXT.KOPF_AUS')) ?></td></tr>
</table>

<?php foreach ($rb_states as $rb_k => $rb_s) { ?>
<div class="sm-alert <?= $rb_s['fehler'] ? 'sm-warn' : 'sm-info' ?>">
<b><?= rb_e($rb_s['name']) ?></b><?= (isset($rb_s['typ']) && $rb_s['typ'] === 'miot') ? ' (' . rb_e(ro_t('EINST.TYP_MIOT')) . ')' : '' ?>:
<?php if ($rb_s['ok']) { ?>
<b><?= rb_e($rb_s['text']) ?></b> &middot; <?= rb_e(ro_t('TEXT.BATTERIE')) ?> <?= $rb_s['batterie'] < 0 ? '&ndash;' : (int) $rb_s['batterie'] . '&nbsp;%' ?><?= $rb_s['laedt'] ? ' (' . rb_e(ro_t('TEXT.LAEDT')) . ')' : '' ?>
<?= $rb_s['fehler'] ? ' &middot; <b>' . rb_e(ro_t('TEXT.FEHLER_KURZ')) . ' ' . (int) $rb_s['fehler'] . '</b> ' . rb_e($rb_s['fehlertext']) : '' ?><br>
<?= rb_e(ro_t('TEXT.LETZTE_REINIGUNG')) ?> <?= $rb_s['flaeche'] < 0 ? '&ndash;' : rb_e($rb_s['flaeche']) . '&nbsp;m&sup2; ' . rb_e(ro_t('WORT.IN')) . ' ' . (int) $rb_s['dauer'] . '&nbsp;min' ?><?= $rb_s['letzte'] ? ' (' . rb_e(date('d.m.Y H:i', $rb_s['letzte'])) . ')' : '' ?><br>
<?= rb_e(ro_t('TEXT.GESAMT')) ?> <?= $rb_s['anzahl_gesamt'] < 0 ? '&ndash;' : rb_e($rb_s['flaeche_gesamt']) . '&nbsp;m&sup2;, ' . ($rb_s['dauer_gesamt'] < 0 ? '&ndash;' : rb_e($rb_s['dauer_gesamt'])) . '&nbsp;h'
    . (isset($rb_s['dauer_gesamt_einheit']) && $rb_s['dauer_gesamt_einheit'] !== 's' ? ' (' . rb_e(ro_t($rb_s['dauer_gesamt_einheit'] === 'ms' ? 'TEXT.GESAMT_MS' : 'TEXT.GESAMT_UNPLAUSIBEL')) . ')' : '') . ', ' . (int) $rb_s['anzahl_gesamt'] . ' ' . rb_e(ro_t('TEXT.REINIGUNGEN')) ?><br>
<?= rb_e(ro_t('TEXT.VERBRAUCHSMATERIAL')) ?> <?= rb_e(ro_t('TEXT.FILTER')) ?> <?= rb_hp($rb_s, 'filter') ?> &middot; <?= rb_e(ro_t('TEXT.HAUPTBUERSTE')) ?> <?= rb_hp($rb_s, 'buerste_haupt') ?> &middot; <?= rb_e(ro_t('TEXT.SEITENBUERSTE')) ?> <?= rb_hp($rb_s, 'buerste_seite') ?> &middot; <?= rb_e(ro_t('TEXT.SENSOREN')) ?> <?= rb_h($rb_s['sensor']) ?><?php
if ($rb_s['mop'] >= 0) { echo ' &middot; ' . rb_e(ro_t('TEXT.WISCHBEZUG')) . ' ' . rb_hp($rb_s, 'mop'); }
if ($rb_s['dock_behaelter'] >= 0) { echo ' &middot; ' . rb_e(ro_t('TEXT.STAUBBEUTEL')) . ' ' . rb_h($rb_s['dock_behaelter'], '%'); }
?>
<?= $rb_s['material_warn'] ? ' &rarr; <b>' . rb_e(ro_t('TEXT.WARTUNG_FAELLIG')) . '</b>' : '' ?><br>
<?= rb_e(ro_t('TEXT.ANBAUTEILE')) ?> <?= rb_e(ro_t('TEXT.BEHAELTER')) ?> <?= rb_jn($rb_s['behaelter']) ?> &middot; <?= rb_e(ro_t('TEXT.WASSERTANK')) ?> <?= rb_jn($rb_s['wassertank']) ?> &middot; <?= rb_e(ro_t('TEXT.WISCHMODUL')) ?> <?= rb_jn($rb_s['wischer']) ?><?= (isset($rb_s['wasserstufe']) && (int) $rb_s['wasserstufe'] >= 0) ? ' &middot; ' . rb_e(ro_t('TEXT.WASSERSTUFE')) . ' ' . rb_e(ro_stufe_name($rb_s['wasserstufe'])) : '' ?>
<?php if ($rb_s['event'] > 0) { ?><br><b><?= rb_e(ro_t('TEXT.EREIGNIS')) ?></b> <?= rb_e($rb_s['evtext']) ?> (<?= (int) $rb_s['event'] ?>)<?php } ?>
<?php $rb_meld = ro_meldung_lesen($rb_k); if ($rb_meld !== '') { ?><br><?= rb_e(ro_t('TEXT.LETZTE_MELDUNG')) ?> <?= rb_e($rb_meld) ?><?php } ?>
<?php } else { ?>
<b><?= rb_e(ro_t('TEXT.KEINE_VERBINDUNG')) ?></b> <?= !empty($rb_s['grund']) ? rb_e(ro_ausfall_grund_anzeige($rb_s['grund'])) : rb_e(ro_t('TEXT.ADRESSE_PRUEFEN')) ?>
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
<tr><th style="width:34px;"><?= rb_e(ro_t('WORT.NR')) ?></th><th style="width:16%;"><?= rb_e(ro_t('EINST.NAME')) ?></th><th style="width:13%;"><?= rb_e(ro_t('EINST.TYP')) ?></th><th><?= rb_e(ro_t('EINST.ADRESSE')) ?></th><th style="width:80px;"><?= rb_e(ro_t('EINST.PORT')) ?></th><th style="width:12%;"><?= rb_e(ro_t('EINST.BENUTZER')) ?></th><th style="width:12%;"><?= rb_e(ro_t('EINST.KENNWORT')) ?></th><th style="width:15%;"><?= rb_e(ro_t('EINST.TOKEN')) ?></th></tr>
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
for ($rb_i = count($rb_zeilen); $rb_i < RO_ROBOTER_MAX; $rb_i++) {   // Nachbau 27.10.: 5 Zeilen
    $rb_frei = 1;
    while (in_array($rb_frei, $rb_belegt, true)) { $rb_frei++; }
    $rb_belegt[] = $rb_frei;
    $rb_zeilen[] = array('nr' => $rb_frei);
}
for ($rb_i = 0; $rb_i < RO_ROBOTER_MAX; $rb_i++) {
    $rb_r = (array) $rb_zeilen[$rb_i];
    $rb_r += array('nr' => $rb_i + 1, 'name' => '', 'ip' => '', 'port' => 80, 'user' => '', 'pass' => '', 'typ' => 'valetudo', 'token' => '');
    /* Gruppe 28 (1.1.23): der gespeicherte Port der Zeile (ro_robots() setzt beim Xiaomi-Treiber 54321 ein). */
    foreach ((array) $rb_cfg['robots'] as $rb_roh) {
        if (is_array($rb_roh) && isset($rb_roh['nr'], $rb_roh['port']) && (int) $rb_roh['nr'] === (int) $rb_r['nr']) { $rb_r['port'] = (int) $rb_roh['port']; }
    }
    $rb_rtyp = rb_w('r_typ', $rb_r['typ'], $rb_i);
    if (!in_array($rb_rtyp, ro_robot_typen(), true)) { $rb_rtyp = 'valetudo'; } ?>
<tr class="rb-zeile" data-typ="<?= rb_e($rb_rtyp) ?>">
<td><?= (int) $rb_r['nr'] ?><input data-role="none" type="hidden" name="r_nr[<?= $rb_i ?>]" value="<?= (int) $rb_r['nr'] ?>"></td>
<td><input data-role="none" type="text" name="r_name[<?= $rb_i ?>]" value="<?= rb_e(rb_w('r_name', $rb_r['name'], $rb_i)) ?>"<?= rb_m('r_name', $rb_i) ?> placeholder="<?= rb_e($rb_i === 0 ? ro_t('EINST.NAME_BEISPIEL') : ro_t('EINST.LEER_UNGENUTZT')) ?>"></td>
<td><select data-role="none" name="r_typ[<?= $rb_i ?>]" class="rb-typ"<?= rb_m('r_typ', $rb_i) ?>>
<option value="valetudo"<?= $rb_rtyp === 'valetudo' ? ' selected' : '' ?>><?= rb_e(ro_t('EINST.TYP_VALETUDO')) ?></option>
<option value="miot"<?= $rb_rtyp === 'miot' ? ' selected' : '' ?>><?= rb_e(ro_t('EINST.TYP_MIOT')) ?></option>
</select></td>
<td><input data-role="none" type="text" name="r_ip[<?= $rb_i ?>]" value="<?= rb_e(rb_w('r_ip', $rb_r['ip'], $rb_i)) ?>"<?= rb_m('r_ip', $rb_i) ?> placeholder="<?= rb_e($rb_i === 0 ? ro_t('EINST.IP_BEISPIEL') : '') ?>"></td>
<td><input data-role="none" type="number" class="rb-nur-valetudo" name="r_port[<?= $rb_i ?>]" value="<?= rb_e(rb_w('r_port', (int) $rb_r['port'], $rb_i)) ?>"<?= rb_m('r_port', $rb_i) ?> min="1" max="65535">
<div class="sm-small rb-nur-miot"><?= rb_e(ro_t('EINST.PORT_MIOT')) ?></div></td>
<td><input data-role="none" type="text" class="rb-nur-valetudo" name="r_user[<?= $rb_i ?>]" value="<?= rb_e(rb_w('r_user', $rb_r['user'], $rb_i)) ?>"<?= rb_m('r_user', $rb_i) ?> autocomplete="off"></td>
<td><input data-role="none" type="password" class="rb-nur-valetudo" name="r_pass[<?= $rb_i ?>]" value="" autocomplete="new-password"<?= rb_m('r_pass', $rb_i) ?> placeholder="<?= rb_e($rb_r['pass'] !== '' ? ro_t('EINST.GESETZT') : '') ?>">
<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;margin-top:4px;">
<input data-role="none" type="checkbox" name="r_pass_loeschen[<?= $rb_i ?>]" value="1"<?= rb_haken('r_pass_loeschen', false, $rb_i) ? ' checked' : '' ?><?= rb_m('r_pass_loeschen', $rb_i) ?>> <?= rb_e(ro_t('EINST.KENNWORT_LOESCHEN')) ?></label></td>
<?php /* Gruppe 28 (1.1.23, E-E3, N11): der Token - value bleibt IMMER leer (auch nach einer Beanstandung), der
         Platzhalter sagt nur "gesetzt". */ ?>
<td><input data-role="none" type="password" class="rb-nur-miot-feld" name="r_token[<?= $rb_i ?>]" value="" autocomplete="new-password" maxlength="64"<?= rb_m('r_token', $rb_i) ?> placeholder="<?= rb_e($rb_r['token'] !== '' ? ro_t('EINST.GESETZT') : ro_t('EINST.TOKEN_32HEX')) ?>">
<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;margin-top:4px;">
<input data-role="none" type="checkbox" name="r_token_loeschen[<?= $rb_i ?>]" value="1"<?= rb_haken('r_token_loeschen', false, $rb_i) ? ' checked' : '' ?><?= rb_m('r_token_loeschen', $rb_i) ?>> <?= rb_e(ro_t('EINST.KENNWORT_LOESCHEN')) ?></label></td>
</tr>
<?php } ?>
</table>
</div>
<div class="sm-small"><?= ro_t('EINST.ROBOTER_HINWEIS') ?></div>
<div class="sm-small"><?= ro_t('EINST.ANMELDUNG_HINWEIS') ?></div>
<div class="sm-small"><?= ro_t('EINST.MIOT_HINWEIS') ?></div>
<div class="sm-small"><?= ro_t('EINST.TOKEN_HINWEIS') ?></div>

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
<span><i class="sm-punkt sm-b-technik"></i> <?= rb_e(ro_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= rb_e(ro_t('LEGENDE.AKTION')) ?></span>
</div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= rb_e(ro_t('KNOPF.SPEICHERN')) ?></button>
</div>
</form>

<?php /* Gruppe 28 (1.1.23, Entscheidung "Token-Beschaffung"): Anleitung zum Token (aufklappbar) und der Abruf aus der
         Xiaomi-Cloud im Plugin. Eigene Formulare (nie im Speichern-Formular): Konto, Kennwort (Passwortfeld, nie
         vorbefuellt, nie zurueckgeschrieben), Region, Zielzeile. Das Ergebnis zeigt Name, Modell, IP und Region - nie
         den Token. Ein Captcha umgeht das Plugin nicht: Bild von Xiaomi, Loesung tippt der Hausherr (Nachbau 26.10. spaet);
         Zweitfaktor: Weg Mail/SMS waehlen, dann Code eintragen. */ ?>
<?php ro_xc();   /* Stammmodul xiaomi_cloud.php fuer die Anzeige (Captcha, Zweitfaktor, Regionen) */ ?>
<h2 id="xiaomi-token"><?= rb_e(ro_t('CLOUD.H_TOKEN')) ?></h2>
<details class="sm-http"<?= $rb_cloud ? ' open' : '' ?>>
<summary><b><?= rb_e(ro_t('CLOUD.ANLEITUNG_TITEL')) ?></b></summary>
<div class="sm-hinweis"><?= ro_t('CLOUD.ANLEITUNG') ?></div>
</details>
<details class="sm-http"<?= $rb_cloud ? ' open' : '' ?>>
<summary><b><?= rb_e(ro_t('CLOUD.ABRUF_TITEL')) ?></b></summary>
<div class="sm-hinweis"><?= ro_t('CLOUD.ABRUF_TEXT') ?></div>
<?php if ($rb_cloud && $rb_cloud['lage'] === 'ok' && !empty($rb_cloud['geraete']) && is_array($rb_cloud['geraete'])) { ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<?= rb_fmt() ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= rb_e(ro_t('CLOUD.SP_WAHL')) ?></th><th><?= rb_e(ro_t('WORT.NAME')) ?></th><th><?= rb_e(ro_t('CLOUD.SP_MODELL')) ?></th><th><?= rb_e(ro_t('EINST.ADRESSE')) ?></th><th><?= rb_e(ro_t('CLOUD.SP_REGION')) ?></th><th><?= rb_e(ro_t('CLOUD.SP_ONLINE')) ?></th></tr>
<?php foreach ($rb_cloud['geraete'] as $rb_cg) {
    if (!is_array($rb_cg) || !isset($rb_cg['i'])) { continue; }
    /* Gruppe 37 (1.1.24): Kaestchen statt Wahlknopf; vorangehakt nur, was die Cloud als online meldet (isOnline). */
    $rb_con = (isset($rb_cg['online']) && in_array($rb_cg['online'], array(0, 1), true)) ? $rb_cg['online'] : -1; ?>
<tr><td><input data-role="none" type="checkbox" id="cloud_wahl_<?= (int) $rb_cg['i'] ?>" name="cloud_index[]" value="<?= (int) $rb_cg['i'] ?>"<?= $rb_con === 1 ? ' checked' : '' ?>></td>
<td><label for="cloud_wahl_<?= (int) $rb_cg['i'] ?>"><?= rb_e(isset($rb_cg['name']) ? $rb_cg['name'] : '') ?></label></td><td><span class="sm-mono"><?= rb_e(isset($rb_cg['modell']) ? $rb_cg['modell'] : '') ?></span><?= (isset($rb_cg['modell']) && !ro_miot_modell_bekannt($rb_cg['modell'])) ? ' <b>' . rb_e(ro_t('CLOUD.MODELL_UNGEPRUEFT')) . '</b>' : '' ?></td>
<td><span class="sm-mono"><?= rb_e(isset($rb_cg['ip']) && $rb_cg['ip'] !== '' ? $rb_cg['ip'] : '-') ?></span></td><td><?= rb_e(isset($rb_cg['region']) ? $rb_cg['region'] : '') ?></td>
<td><?= rb_e(ro_t($rb_con === 1 ? 'CLOUD.ONLINE_JA' : ($rb_con === 0 ? 'CLOUD.ONLINE_NEIN' : 'CLOUD.ONLINE_UNBEKANNT'))) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-small"><?= rb_e(ro_t('CLOUD.UEBERNEHMEN_TEXT')) ?></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="cloud_nehmen" value="1"><?= rb_e(ro_t('CLOUD.K_AUSGEWAEHLT')) ?></button>
</div>
</form>
<?php } elseif ($rb_cloud && in_array($rb_cloud['lage'], array('CAPTCHA', 'CAPTCHA_FALSCH'), true)
                && ($rb_ccap = xc_lesen('captcha')) !== null && isset($rb_ccap['bild'], $rb_ccap['bildtyp'])
                && in_array($rb_ccap['bildtyp'], array('image/png', 'image/jpeg', 'image/gif'), true)) {
    /* Nachbau 26.10. spaet: das Captcha-Bild von Xiaomi unveraendert (data:-Adresse aus dem 0600-Zwischenzustand, kein
     * Aufruf an Xiaomi aus dem Browser). Der Hausherr tippt die Zeichen und sein Kennwort erneut - EIN Versuch. */ ?>
<div class="sm-warnung"><?= rb_e(ro_t('CLOUD.CAPTCHA_TEXT')) ?></div>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<?= rb_fmt() ?>
<div class="sm-row"><div>
<img src="data:<?= rb_e($rb_ccap['bildtyp']) ?>;base64,<?= rb_e(preg_replace('#[^A-Za-z0-9+/=]#', '', (string) $rb_ccap['bild'])) ?>" alt="<?= rb_e(ro_t('CLOUD.CAPTCHA_BILD')) ?>" style="max-width:100%;border:1px solid #ccc;background:#fff;">
</div></div>
<div class="sm-row">
  <div>
    <label><?= rb_e(ro_t('CLOUD.CAPTCHA_LOESUNG')) ?></label>
    <input data-role="none" type="text" name="cloud_captcha" value="" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="10">
  </div>
  <div>
    <label><?= rb_e(ro_t('CLOUD.KENNWORT')) ?></label>
    <input data-role="none" type="password" name="cloud_pass" value="" autocomplete="new-password" maxlength="200">
  </div>
</div>
<div class="sm-small"><?= rb_e(ro_t('CLOUD.KENNWORT_HINWEIS')) ?></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="cloud_captcha_senden" value="1"><?= rb_e(ro_t('CLOUD.K_CAPTCHA')) ?></button>
</div>
</form>
<?php } elseif ($rb_cloud && in_array($rb_cloud['lage'], array('ZWEITFAKTOR', 'SENDEN', 'WEG'), true)
                && ($rb_czf = xc_lesen('zweitfaktor')) !== null) {
    /* Zweitfaktor, Schritt 1: der Hausherr waehlt, wohin Xiaomi den Code schickt (nur die Wege, die Xiaomi anbietet). */ ?>
<div class="sm-warnung"><?= rb_e(ro_t('CLOUD.ZWEITFAKTOR_WAHL')) ?>
<?php if (!empty($rb_czf['link']) && is_string($rb_czf['link']) && preg_match('#^https://#', $rb_czf['link'])) { ?>
<br><a href="<?= rb_e($rb_czf['link']) ?>" target="_blank" rel="noopener noreferrer"><?= rb_e(ro_t('CLOUD.ZWEITFAKTOR_LINK')) ?></a>
<?php } ?></div>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<?= rb_fmt() ?>
<div class="sm-knopfreihe">
<?php if (in_array(8, (array) $rb_czf['optionen'], true)) { ?>
<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="cloud_weg" value="mail"><?= rb_e(ro_t('CLOUD.K_WEG_MAIL')) ?></button>
<?php } ?>
<?php if (in_array(4, (array) $rb_czf['optionen'], true)) { ?>
<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="cloud_weg" value="sms"><?= rb_e(ro_t('CLOUD.K_WEG_SMS')) ?></button>
<?php } ?>
</div>
</form>
<?php } elseif ($rb_cloud && in_array($rb_cloud['lage'], array('CODE_GESENDET', 'CODE'), true)
                && ($rb_czf = xc_lesen('zweitfaktor')) !== null && !empty($rb_czf['weg'])) {
    /* Zweitfaktor, Schritt 2: den Code eintragen (10 Minuten ab Beginn, ein Versuch). */ ?>
<div class="sm-warnung"><?= rb_e(ro_t('CLOUD.ZWEITFAKTOR_TEXT')) ?></div>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<?= rb_fmt() ?>
<div class="sm-row"><div>
<label><?= rb_e(ro_t('CLOUD.CODE')) ?></label>
<input data-role="none" type="text" name="cloud_code" value="" inputmode="numeric" autocomplete="one-time-code" maxlength="12">
</div></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="cloud_code_senden" value="1"><?= rb_e(ro_t('CLOUD.K_CODE')) ?></button>
</div>
</form>
<?php } ?>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<?= rb_fmt() ?>
<div class="sm-row">
  <div>
    <label><?= rb_e(ro_t('CLOUD.REGION')) ?></label>
    <select data-role="none" name="cloud_region">
<?php foreach (array_merge(xc_regionen(), array('alle')) as $rb_cr) { ?>
      <option value="<?= rb_e($rb_cr) ?>"><?= rb_e(ro_t('CLOUD.REGION_' . strtoupper($rb_cr))) ?></option>
<?php } ?>
    </select>
  </div>
</div>
<div class="sm-row">
  <div>
    <label><?= rb_e(ro_t('CLOUD.KONTO')) ?></label>
    <input data-role="none" type="text" name="cloud_konto" value="" autocomplete="off" maxlength="100">
  </div>
  <div>
    <label><?= rb_e(ro_t('CLOUD.KENNWORT')) ?></label>
    <input data-role="none" type="password" name="cloud_pass" value="" autocomplete="new-password" maxlength="200">
  </div>
</div>
<div class="sm-small"><?= rb_e(ro_t('CLOUD.KENNWORT_HINWEIS')) ?></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="cloud_holen" value="1"><?= rb_e(ro_t('CLOUD.K_HOLEN')) ?></button>
</div>
</form>
</details>

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
<?php /* Gruppe 2 (1.1.22): die Befehlsbruecke - zwei Haken, ab Werk aus (Entwurf sv 3.3, E4). Gespeichert mit dem
         MQTT-Formular; beim Speichern startet bzw. haelt das Plugin die Bruecke sofort (ro_bruecke_schalten()). */ ?>
<h2><?= rb_e(ro_t('BEFEHL.H_BEFEHLE')) ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
    <input data-role="none" type="checkbox" name="befehle_mqtt_ein" <?= rb_haken('befehle_mqtt_ein', !empty($rb_cfg['befehle_mqtt_ein'])) ? 'checked' : '' ?>> <?= rb_e(ro_t('BEFEHL.L_ANNEHMEN')) ?>
</label>
<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
    <input data-role="none" type="checkbox" name="befehle_mqtt_steuern" <?= rb_haken('befehle_mqtt_steuern', !empty($rb_cfg['befehle_mqtt_steuern'])) ? 'checked' : '' ?>> <?= rb_e(ro_t('BEFEHL.L_STEUERN')) ?>
</label>
<div class="sm-small"><?= rb_e(ro_t('BEFEHL.H_ANNEHMEN')) ?></div>
<?php if (empty($rb_cfg['mqtt_enabled'])) { ?><div class="sm-warnung"><?= rb_e(ro_t('BEFEHL.W_MQTT_AUS')) ?></div><?php } ?>
<?php list(, $rb_bzt) = ro_bruecke_zustand(); ?>
<div class="sm-small"><?= rb_e($rb_bzt) ?></div>
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
<?php
/* Gruppe 2 (1.1.22, Hausherr 18.10.2026: Werte UND Befehle ueber MQTT; Musterprojekt, Vorlagen und Haus nutzen nur
 * MQTT, die HTTP-Wege bleiben fuer bestehende Anlagen; Gateway V1 und V2). Sieben feste Schritte in der Reihenfolge
 * des MQTT-Wegs (Entwurf sv 2.5): einschalten, Gateway, Eingaenge nach der Namenstabelle (keine Importvorlage fuer
 * Gateway-Werte, Hausherr 19.10.2026), Ausfallerkennung, Befehle, Baustein-Seiten, Gegenprobe. Darunter zugeklappt
 * "Bisherige Anlagen (HTTP)". Namenstabelle und Hakenliste kommen aus ro_mqtt_namen(), die Befehle aus bb_liste(). */
$rb_schritt = 0;
$rb_lgw = ro_mqtt_gateway_info();
$rb_lgwf = ($rb_lgw === null) ? 0 : (int) $rb_lgw['fassung'];
$rb_wurzel = ro_mqtt_thema_saeubern($rb_cfg['mqtt_topic']);
$rb_bliste = ro_befehle_liste();
?>
<?php /* U10: eine Legende je Reiter, oben, mit allen Farben des Reiters. */ ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= rb_e(ro_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= rb_e(ro_t('LEGENDE.AKTION_TOKEN')) ?></span>
</div>
<?php /* Gruppe 28 (1.1.23): der Speichern-Knopf des Lesezweigs ist ebenfalls orange - die Legende nennt beide Wirkungen
         in einem Eintrag (Regeln/04: eine Legende je Reiter). */ ?>

<div class="sm-step"><b><?= rb_e(sprintf(ro_t('LOX.SCHRITT_N'), ++$rb_schritt, ro_t('LOX.S1_TITEL'))) ?></b><br>
<?= ro_t('LOX.S1_TEXT') ?>
<?php if (empty($rb_cfg['mqtt_enabled'])) { ?><div class="sm-warnung"><?= ro_t('LOX.S1_WARN_AUS') ?></div>
<?php } else { ?><div class="sm-small"><?= rb_e(sprintf(ro_t('LOX.S1_ZUSTAND_EIN'), $rb_wurzel)) ?></div><?php } ?>
</div>

<div class="sm-step"><b><?= rb_e(sprintf(ro_t('LOX.SCHRITT_N'), ++$rb_schritt, ro_t('LOX.S2_TITEL'))) ?></b><br>
<?php /* Entwurf sv 2.5: je Gateway-Fassung der passende Satz (Fassung aus general.json, ro_mqtt_gateway_info()).
         Unter V1 das mitgebrachte Abo, unter V2 die Hakenliste; ist die Fassung nicht lesbar, steht beides da. */ ?>
<?php if ($rb_lgwf <= 1) { ?>
<div class="<?= $rb_lgwf === 1 ? 'sm-hinweis' : 'sm-warnung' ?>"><?= sprintf(ro_t('LOX.S2_V1'), '<span class="sm-mono">' . rb_e($rb_wurzel) . '/#</span>') ?></div>
<?php } ?>
<?php if ($rb_lgwf !== 1) {
    $rb_haken = array();
    foreach (ro_mqtt_namen_geraete() as $rb_hd) {
        foreach (ro_mqtt_namen($rb_hd) as $rb_hz) { $rb_haken[] = $rb_hz; }
    }
    $rb_nc = 0; ?>
<div class="sm-hinweis"><?= ro_t('LOX.S2_V2') ?></div>
<?php if ($rb_lgwf === 0) { ?><div class="sm-small"><?= ro_t('LOX.S2_UNBEKANNT') ?></div><?php } ?>
<h3 class="sm-h3"><?= rb_e(ro_t('LOX.HAKEN_TITEL')) ?></h3>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:28%;"><?= rb_e(ro_t('LOX.HAKEN_SP_NAME')) ?></th><th style="width:26%;"><?= rb_e(ro_t('LOX.HAKEN_SP_THEMA')) ?></th><th style="width:10%;"><?= rb_e(ro_t('LOX.HAKEN_SP_NC')) ?></th><th><?= rb_e(ro_t('LOX.SP_BEDEUTUNG')) ?></th></tr>
<?php foreach ($rb_haken as $rb_h) {
    if ($rb_h['noncached']) { $rb_nc++; } ?>
<tr><td><span class="sm-mono"><?= rb_e($rb_h['name']) ?></span></td><td><span class="sm-mono"><?= rb_e($rb_h['thema']) ?></span></td><td><?= $rb_h['noncached'] ? 'N' : '' ?></td><td><?= rb_e($rb_h['bedeutung']) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-small"><?= rb_e(sprintf(ro_t('LOX.HAKEN_ANZAHL'), count($rb_haken), $rb_nc)) ?> <?= rb_e(ro_t('LOX.S2_NC_SATZ')) ?></div>
<?php } ?>
<div class="sm-small"><?= rb_e(ro_t('LOX.S2_RECONNECT')) ?></div>
</div>

<div class="sm-step"><b><?= rb_e(sprintf(ro_t('LOX.SCHRITT_N'), ++$rb_schritt, ro_t('LOX.S3_TITEL'))) ?></b><br>
<?= ro_t('LOX.S3_TEXT') ?>
<div class="sm-hinweis"><?= ro_t('LOX.S3_DIGITAL') ?> <?= ro_t('LOX.S3_RECHTE') ?> <?= ro_t('LOX.S3_MUSTER') ?></div>
<?php
/* Hausherr 19.10.2026 (Regeln/07 Abschnitt 4, R2_078): fuer Werte ueber das MQTT-Gateway KEINE Importvorlage - die
 * LoxBerry-Anleitung verlangt einfache Virtuelle Eingaenge. Die Tabelle entsteht aus ro_mqtt_namen() (dieselbe
 * Quelle wie Hakenliste und Selbstpruefung); der Knopf kopiert den Namen. Je eingerichtetem Roboter eine Tabelle. */
foreach (ro_mqtt_namen_geraete() as $rb_nd) {
    $rb_namen = ro_mqtt_namen($rb_nd); ?>
<h3 class="sm-h3"><?= rb_e(sprintf(ro_t('LOX.NAMEN_ROBOTER'), $rb_nd)) ?></h3>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:34%;"><?= rb_e(ro_t('LOX.NAMEN_SP_NAME')) ?></th><th><?= rb_e(ro_t('LOX.SP_BEDEUTUNG')) ?></th><th style="width:20%;"><?= rb_e(ro_t('LOX.NAMEN_SP_EINHEIT')) ?></th><th style="width:10%;"><?= rb_e(ro_t('LOX.NAMEN_SP_ART')) ?></th></tr>
<?php foreach ($rb_namen as $rb_nz) { ?>
<tr><td style="white-space:nowrap;"><span class="sm-mono"><?= rb_e($rb_nz['name']) ?></span> <button data-role="none" type="button" style="margin-left:6px;padding:1px 8px;font-size:12px;border:1px solid #9aa5ab;border-radius:4px;background:#f2f4f5;color:#333;cursor:pointer;" data-name="<?= rb_e($rb_nz['name']) ?>" data-ok="<?= rb_e(ro_t('LOX.KOPIERT')) ?>" data-fehl="<?= rb_e(ro_t('LOX.KOPIE_FEHL')) ?>" onclick="return rbNameKopieren(this);"><?= rb_e(ro_t('LOX.KOPIEREN')) ?></button></td><td><?= rb_e($rb_nz['bedeutung']) ?></td><td><?= rb_e($rb_nz['bereich']) ?></td><td><?= rb_e(ro_t($rb_nz['text'] ? 'LOX.ART_TEXT' : 'LOX.ART_ZAHL')) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-small"><?= rb_e(sprintf(ro_t('LOX.NAMEN_ANZAHL'), count($rb_namen))) ?></div>
<?php } ?>
<div class="sm-small"><?= rb_e(sprintf(ro_t('LOX.S3_ROBOTER2'), str_replace('_code', '_…', ro_mqtt_gateway_name(2, 'code')))) ?></div>
</div>

<div class="sm-step"><b><?= rb_e(sprintf(ro_t('LOX.SCHRITT_N'), ++$rb_schritt, ro_t('LOX.S4_TITEL'))) ?></b><br>
<?= sprintf(ro_t('LOX.S4_TEXT'), '<span class="sm-mono">' . rb_e(ro_mqtt_gateway_name(1, 'status/zaehler')) . '</span>',
    '<span class="sm-mono">' . rb_e(ro_mqtt_gateway_name(1, 'ok')) . '</span>') ?>
</div>

<div class="sm-step"><b><?= rb_e(sprintf(ro_t('LOX.SCHRITT_N'), ++$rb_schritt, ro_t('LOX.S5_TITEL'))) ?></b><br>
<?= sprintf(ro_t('LOX.S5_TEXT'), (int) (ro_mqtt_udpport() > 0 ? ro_mqtt_udpport() : 11884)) ?>
<div class="sm-small"><?= rb_e(sprintf(ro_t('LOX.S5_ZUSTAND'), ro_t(!empty($rb_cfg['befehle_mqtt_ein']) ? 'WORT.JA' : 'WORT.NEIN'),
    ro_t(!empty($rb_cfg['befehle_mqtt_steuern']) ? 'WORT.JA' : 'WORT.NEIN'))) ?></div>
<?php if ($rb_bliste[0] === null) { ?>
<div class="sm-warnung"><?= rb_e(sprintf(ro_t('BEFEHL.LISTE_FEHLT'), $rb_bliste[1])) ?></div>
<?php } else {
    $rb_bbsp = ro_befehle();
    $rb_muster = false; ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:24%;"><?= rb_e(ro_t('BEFEHL.SP_TITEL')) ?></th><th style="width:24%;"><?= rb_e(ro_t('BEFEHL.SP_THEMA')) ?></th><th><?= rb_e(ro_t('BEFEHL.SP_WERT')) ?></th><th><?= rb_e(ro_t('BEFEHL.SP_EINGREIFEND')) ?></th><th><?= rb_e(ro_t('BEFEHL.SP_SPERRE')) ?></th><th><?= rb_e(ro_t('BEFEHL.SP_QUITTUNG')) ?></th><th><?= rb_e(ro_t('BEFEHL.SP_T12')) ?></th></tr>
<?php foreach ($rb_bliste[0]['befehle'] as $rb_b) {
    if ($rb_b['muster']) { $rb_muster = true; continue; }
    if ($rb_b['art'] === 'auswahl') {
        $rb_bw = sprintf(ro_t('BEFEHL.WERT_AUSWAHL'), implode(', ', $rb_b['werte']));
    } elseif ($rb_b['art'] === 'text') {
        $rb_bw = sprintf(ro_t('BEFEHL.WERT_TEXT'), (int) $rb_b['max_byte'],
            isset($rb_bbsp[$rb_b['name']]) ? $rb_bbsp[$rb_b['name']][0] : '');
    } else {
        $rb_bw = ro_t('BEFEHL.WERT_PULS');
    } ?>
<tr><td><?= rb_e($rb_b['titel']) ?></td><td><span class="sm-mono"><?= rb_e($rb_b['thema']) ?></span></td><td><?= rb_e($rb_bw) ?></td><td><?= rb_e(ro_t($rb_b['eingreifend'] ? 'WORT.JA' : 'WORT.NEIN')) ?></td><td><?= rb_e(sprintf(ro_t('BEFEHL.SPERRE_S'), $rb_b['sperre_s'])) ?></td><td><?= rb_e(ro_t('BEFEHL.QUITT_AUSGEFUEHRT')) ?></td><td><?php
    /* Gruppe 28 (1.1.23, E-E7/E-E8): was der Treiber Xiaomi MIoT davon kann - aus EINER Liste (ro_miot_befehle()). */
    $rb_mb = ro_miot_befehle();
    echo rb_e(isset($rb_mb[$rb_b['name']]) ? ro_t($rb_mb[$rb_b['name']]['pruef'] === 'ja' ? 'WORT.JA' : 'WORT.UNGEPRUEFT') : ro_t('WORT.NEIN')); ?></td></tr>
<?php } ?>
</table>
</div>
<?php if ($rb_muster) { ?><div class="sm-small"><?= rb_e(sprintf(ro_t('BEFEHL.MUSTER_HINWEIS'), $rb_wurzel)) ?></div><?php } ?>
<div class="sm-small"><?= ro_t('LOX.VORLAGE_BEFEHLE_TEXT') ?></div>
<div class="sm-knopfreihe" style="margin-top:6px;">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <?= rb_fmt() ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage_befehle" value="1"><?= rb_e(ro_t('KNOPF.VORLAGE_BEFEHLE')) ?></button>
  </form>
</div>
<?php } ?>
</div>

<div class="sm-step"><b><?= rb_e(sprintf(ro_t('LOX.SCHRITT_N'), ++$rb_schritt, ro_t('LOX.S6_TITEL'))) ?></b><br>
<?php /* X-8 (02.10.2026, Entscheidung 36) und Welle Bild 10 VOLL (1.1.19): drei Seiten aus dem LoxBerry-Plugins
         Musterprojekt, je Seite Ueberschrift, Kasten "So uebernehmen Sie diese Seite", Tabelle, Hinweise, Bild, Link.
         Gruppe 2 (1.1.22): die Tabellen sind wortgleich zu Musterprojekt/baustein_listen.txt (Abschnitte
         "Saugroboter" 50, "Saugroboter Wartung" 43, "Saugroboter Steuerung" 42 Zeilen); jede Zeile steht ganz in
         [BAUSTEIN] Z<seite>_<nr> ("Typ | Name | Parameter | Eingaenge"; "%" als "%%", Ausgabe ueber sprintf). Die
         Eingaenge heissen wie das MQTT-Gateway sie benennt, die Befehle wie am gemeinsamen Ausgang "LoxBerry
         MQTT-Gateway" des Musterprojekts. Ein anderes Praefix als "saugrobo" wird in Namen, Themen und Texten
         eingesetzt ($rb_px). Jede Zelle ist fertiges, maskiertes HTML. */
$rb_px_w = $rb_wurzel;
$rb_px = function ($s) use ($rb_px_w) {
    if ($rb_px_w === 'saugrobo') { return $s; }
    return str_replace(array('saugrobo/', 'saugrobo_'), array($rb_px_w . '/', str_replace('/', '_', $rb_px_w) . '_'), $s);
};
/* Kasten- und Hinweistexte: erst vsprintf, dann Praefix, dann maskieren - eingesetzte Namen kommen nie roh heraus. */
$rb_bs_k = function ($schluessel, $argumente = array()) use ($rb_px) {
    return rb_e($rb_px(vsprintf((string) ro_t('BAUSTEIN.' . $schluessel), $argumente)));
};
/* Eine Tabellenzeile in vier Zellen; die Zahl der Zellen prueft der Bau (bau_g2.py) und die Selbstpruefung nicht. */
$rb_bs_zeile = function ($seite, $nr) use ($rb_px) {
    $c = array_pad(explode(' | ', $rb_px(sprintf((string) ro_t(sprintf('BAUSTEIN.Z%d_%02d', $seite, $nr))))), 4, '');
    return array($nr, rb_e($c[0]), rb_e($c[1]), rb_e($c[2]), rb_e(implode(' | ', array_slice($c, 3))));
};
/* Der Name einer Zeile (Spalte 2) - fuer die Kaesten, unmaskiert und noch mit dem Grundpraefix: $rb_bs_k setzt das
 * Praefix genau einmal ein und maskiert. */
$rb_bs_name = function ($seite, $nr) {
    $c = explode(' | ', sprintf((string) ro_t(sprintf('BAUSTEIN.Z%d_%02d', $seite, $nr))));
    return isset($c[1]) ? $c[1] : '';
};
$rb_bs_seiten = array(
    array('kopf' => 'BL_WERTE', 'seite' => 'Saugroboter', 'bild' => 'einbindung_loxone_werte.png', 'nr' => 1, 'zeilen' => 52,   // Gruppe 28: #51/#52 (V1)
      'schritte' => array(
        array('S1_SCHRITT1', array(ro_t('LOX.S3_TITEL'))),
        array('S1_SCHRITT2', array()),
        array('S1_SCHRITT3', array($rb_bs_name(1, 21))),
        array('S1_SCHRITT4', array()),
        array('S1_SCHRITT5', array()),
        array('S1_SCHRITT6', array(ro_t('BAUSTEIN.T_VERB'))),
        array('S1_SCHRITT7', array()),
        array('S1_SCHRITT8', array()),
        array('S1_SCHRITT9', array()),
        array('S1_SCHRITT10', array(ro_t('REITER.EINSTELLUNGEN'), ro_t('EINST.H_MELDUNGEN'), ro_t('EINST.PUSH_AKTIV'))),
        array('S1_SCHRITT11', array(ro_t('REITER.MQTT'), ro_t('BEFEHL.L_ANNEHMEN'), ro_t('BEFEHL.L_STEUERN'))),
        array('S1_SCHRITT12', array(ro_t('REITER.TEST'), ro_t('TEST.K_PTEST'))),
        array('S1_SCHRITT13', array()),
      ),
      'hinweise' => array(
        array('S1_H1', array($rb_bs_name(1, 21))),
        array('S1_H2', array()),
        array('S1_H3', array()),
        array('S1_H4', array()),
        array('S1_H5', array()),
        array('S1_H5_EN', array()),
        array('S1_H6', array()),
        array('S1_H7', array($rb_bs_name(1, 4))),
        array('S1_H8', array($rb_bs_name(1, 5), ro_t('REITER.EINSTELLUNGEN'), ro_t('EINST.H_SPRACHAUSGABE'))),
        array('S1_H9', array('saugrobo_2_', 'saugrobo')),   // Grundpraefix; $rb_bs_k setzt das eingestellte ein
        array('S1_H10', array()),
      )),
    array('kopf' => 'BL_WARTUNG', 'seite' => 'Saugroboter Wartung', 'bild' => 'einbindung_loxone_wartung.png', 'nr' => 2, 'zeilen' => 43,
      'schritte' => array(
        array('S2_SCHRITT1', array()),
        array('S2_SCHRITT2', array($rb_bs_name(2, 26))),
        array('S2_SCHRITT3', array($rb_bs_name(2, 43))),
        array('S2_SCHRITT4', array($rb_bs_name(2, 16), $rb_bs_name(2, 36))),
        array('S2_SCHRITT5', array()),
        array('S2_SCHRITT6', array(ro_t('BAUSTEIN.T_VERB'))),
        array('S2_SCHRITT7', array()),
        array('S2_SCHRITT8', array()),
        array('S2_SCHRITT9', array(ro_t('REITER.EINSTELLUNGEN'), ro_t('EINST.WARN_STUNDEN'), ro_t('EINST.WARN_PROZENT'))),
        array('S2_SCHRITT10', array()),
      ),
      'hinweise' => array(
        array('S2_H1', array()),
        array('S2_H2', array()),
        array('S2_H3', array($rb_bs_name(2, 16))),
        array('S2_H4', array()),
        array('S2_H5', array()),
      )),
    array('kopf' => 'BL_STEUERUNG', 'seite' => 'Saugroboter Steuerung', 'bild' => 'einbindung_loxone_steuerung.png', 'nr' => 3, 'zeilen' => 42,
      'schritte' => array(
        array('S3_SCHRITT1', array()),
        array('S3_SCHRITT2', array($rb_bs_name(3, 1), $rb_bs_name(3, 2), $rb_bs_name(3, 3), $rb_bs_name(3, 4))),
        array('S3_SCHRITT3', array()),
        array('S3_SCHRITT4', array()),
        array('S3_SCHRITT5', array()),
        array('S3_SCHRITT6', array(ro_t('BAUSTEIN.T_VERB'))),
        array('S3_SCHRITT7', array()),
        array('S3_SCHRITT8', array(ro_t('REITER.MQTT'), ro_t('BEFEHL.L_ANNEHMEN'), ro_t('BEFEHL.L_STEUERN'))),
      ),
      'hinweise' => array(
        array('S3_H1', array()),
        array('S3_H2', array(ro_t('REITER.TEST'), ro_t('TEST.H_RAEUME'), $rb_bs_name(3, 26), $rb_bs_name(3, 28))),
        array('S3_H3', array()),
        array('S3_H4', array(ro_t('REITER.TEST'), ro_t('TEST.H_FAEHIGKEITEN'))),
        array('S3_H5', array($rb_bs_name(1, 21))),
        array('S3_H6', array()),
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
<?php for ($rb_bs_i = 1; $rb_bs_i <= $rb_bs_s['zeilen']; $rb_bs_i++) {
    $rb_bs_r = $rb_bs_zeile($rb_bs_s['nr'], $rb_bs_i); ?>
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

<div class="sm-step"><b><?= rb_e(sprintf(ro_t('LOX.SCHRITT_N'), ++$rb_schritt, ro_t('LOX.S7_TITEL'))) ?></b><br>
<?php if (!empty($rb_cfg['endpunkt_ein'])) { ?><?= ro_t('LOX.SCHRITT5_TEXT') ?>
<span class="sm-mono">http://<?= rb_e($rb_host) ?><?= rb_e(ro_endpunkt_pfad(array('json' => 1))) ?></span>
<?php } else { ?><?= rb_e(ro_t('TEST.LESEZWEIG_AUS')) ?><?php } ?>
</div>

<?php /* Gruppe 28 (1.1.23, E-E12; Entwurf 3.4): der HTTP-Lesezweig fuer Loxone - zugeklappt, offen bei endpunkt_ein 1. */ ?>
<details class="sm-http"<?= !empty($rb_cfg['endpunkt_ein']) ? ' open' : '' ?>>
<summary><b><?= rb_e(ro_t('LOX.H_ENDPUNKT')) ?></b></summary>
<div class="sm-hinweis"><?= ro_t('LOX.ENDPUNKT_TEXT') ?></div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="endpunkt_save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<?= rb_fmt() ?>
<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
    <input data-role="none" type="checkbox" name="endpunkt_ein" value="1"<?= !empty($rb_cfg['endpunkt_ein']) ? ' checked' : '' ?>> <?= rb_e(ro_t('LOX.ENDPUNKT_HAKEN')) ?>
</label>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= rb_e(ro_t('KNOPF.SPEICHERN')) ?></button>
</div>
</form>
</details>

<details class="sm-http">
<summary><b><?= rb_e(ro_t('LOX.H_HTTP')) ?></b></summary>
<div class="sm-hinweis"><?= ro_t('LOX.HTTP_TEXT') ?></div>
<div class="sm-step"><b><?= rb_e(ro_t('LOX.SCHRITT1')) ?></b>
<table class="sm-tbl">
<tr><th><?= rb_e(ro_t('WORT.EIGENSCHAFT')) ?></th><th><?= rb_e(ro_t('WORT.WERT')) ?></th></tr>
<tr><td>URL</td><td><span class="sm-mono">http://<?= rb_e($rb_host) ?><?= rb_e(ro_endpunkt_pfad()) ?></span></td></tr>
<?php for ($rb_ed = 2; $rb_ed <= RO_ROBOTER_MAX; $rb_ed++) { /* Nachbau 27.10.: Roboter 2 bis 5 */ ?>
<tr><td><?= rb_e(sprintf(ro_t('LOX.ROBOTER2'), $rb_ed)) ?></td><td><span class="sm-mono">http://<?= rb_e($rb_host) ?><?= rb_e(ro_endpunkt_pfad(array('dev' => $rb_ed))) ?></span></td></tr>
<?php } ?>
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
<?php for ($rb_vd = 1; $rb_vd <= RO_ROBOTER_MAX; $rb_vd++) { ?>        <option value="<?= $rb_vd ?>"><?= $rb_vd ?></option>
<?php } ?>      </select>
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

</details>
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

<?php /* Gruppe 28 (1.1.23, E-E12): die Knoepfe des Lesezweigs (Zeile, JSON, Klartext) nur, wenn er an ist - sonst
         antwortet robo.php 403 ENDPUNKT_AUS. */
if (!empty($rb_cfg['endpunkt_ein'])) { ?>
<h3 class="sm-h3"><?= rb_e(ro_t('TEST.ANSEHEN')) ?></h3>
<div class="sm-knopfreihe">
<a data-role="none" class="sm-btn sm-b-lesen" href="<?= rb_e(ro_endpunkt_pfad()) ?>" target="_blank"><?= rb_e(ro_t('TEST.K_ZEILE')) ?></a>
<a data-role="none" class="sm-btn sm-b-lesen" href="<?= rb_e(ro_endpunkt_pfad(array('json' => 1))) ?>" target="_blank"><?= rb_e(ro_t('TEST.K_JSON')) ?></a>
</div>
<?php } else { ?>
<div class="sm-small"><?= rb_e(ro_t('TEST.LESEZWEIG_AUS')) ?></div>
<?php } ?>

<h3 class="sm-h3"><?= rb_e(ro_t('TEST.TECHNIK')) ?></h3>
<div class="sm-knopfreihe">
<?php /* U13: mit Token - refresh ist seit 1.1.4 tokenpflichtig; ohne Token zeigte der Knopf den Zwischenspeicher. */ ?>
<?php if (!empty($rb_cfg['endpunkt_ein'])) { ?>
<a data-role="none" class="sm-btn sm-b-technik" href="<?= rb_e(ro_endpunkt_pfad(array('debug' => 1, 'refresh' => 1, 'token' => $rb_cfg['aktionstoken']))) ?>" target="_blank"><?= rb_e(ro_t('TEST.K_DEBUG')) ?></a>
<?php } ?>
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
<?php /* U14: Testansage ueber den eingestellten Ausgabeweg (auch Alexa-NG und Google-Lautsprecher). */ ?>
<form method="post" action="index.php">
  <input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?= rb_fmt() ?>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ro_testansage" value="1"><?= rb_e(ro_t('TEST.K_TESTANSAGE')) ?></button>
</form>
</div>

<?php
/* Gruppe 28 (1.1.23, S8): EIN Block je eingetragenem Roboter - Testknoepfe mit &dev=<n>, Zuruecksetzen mit reset_dev=<n>,
 * Raumliste und Faehigkeiten dieses Roboters. Bis 1.1.22 zielte der Reiter fest auf Roboter 1. Beim Treiber Xiaomi MIoT
 * statt Valetudo-Raumliste und -Faehigkeiten die Verbindungspruefung ohne Bewegung (E-E9; nur bei offenem Reiter Test,
 * Regeln/04 :396-397) und die feste Faehigkeitsliste aus ro_miot_befehle(). */
$rb_test_offen = ($rb_tab === 'tab-test');
if (!$rb_robots) { ?>
<div class="sm-alert sm-info"><?= rb_e(ro_t('TEXT.NOCH_KEIN_ROBOTER')) ?></div>
<?php }
foreach ($rb_robots as $rb_tn => $rb_tr) {
    $rb_tst = isset($rb_states[$rb_tn]) ? $rb_states[$rb_tn] : ro_state($rb_tn);
    $rb_tok = $rb_cfg['aktionstoken']; ?>
<h2><?= rb_e(sprintf(ro_t('TEST.H_ROBOTER'), (int) $rb_tn, $rb_tr['name'], ro_t($rb_tr['typ'] === 'miot' ? 'EINST.TYP_MIOT' : 'EINST.TYP_VALETUDO'))) ?></h2>
<?php if (!empty($rb_tst['grund'])) { ?>
<div class="sm-warnung"><?= rb_e(ro_ausfall_grund_anzeige($rb_tst['grund'])) ?></div>
<?php } ?>
<div class="sm-knopfreihe">
<?php if ($rb_tr['typ'] !== 'miot') { ?>
<a data-role="none" class="sm-btn sm-b-aktion" href="<?= rb_e(ro_endpunkt_pfad(array('cmd' => 'locate', 'dev' => $rb_tn, 'token' => $rb_tok))) ?>" target="_blank"><?= rb_e(ro_t('TEST.K_LOCATE')) ?></a>
<?php } ?>
<a data-role="none" class="sm-btn sm-b-aktion" href="<?= rb_e(ro_endpunkt_pfad(array('cmd' => 'home', 'dev' => $rb_tn, 'token' => $rb_tok))) ?>" target="_blank"><?= rb_e(ro_t('TEST.K_HOME')) ?></a>
<a data-role="none" class="sm-btn sm-b-aktion" href="<?= rb_e(ro_endpunkt_pfad(array('cmd' => 'stop', 'dev' => $rb_tn, 'token' => $rb_tok))) ?>" target="_blank"><?= rb_e(ro_t('TEST.K_STOP')) ?></a>
<?php if ($rb_tr['typ'] !== 'miot' && ro_kann($rb_tn, 'AutoEmptyDockManualTriggerCapability')) { ?>
<a data-role="none" class="sm-btn sm-b-aktion" href="<?= rb_e(ro_endpunkt_pfad(array('cmd' => 'absaugen', 'dev' => $rb_tn, 'token' => $rb_tok))) ?>" target="_blank"><?= rb_e(ro_t('TEST.K_ABSAUGEN')) ?></a>
<?php } ?>
</div>
<?php if ($rb_tr['typ'] !== 'miot') { ?>
<div class="sm-small"><?= rb_e(ro_t('TEST.PIEPSEN_HINWEIS')) ?></div>

<h3 class="sm-h3"><?= rb_e(ro_t('TEST.H_RESET')) ?></h3>
<div class="sm-hinweis"><?= ro_t('TEST.RESET_HINWEIS') ?></div>
<div class="sm-knopfreihe">
<?php foreach (array('filter/main' => 'TEST.R_FILTER', 'brush/main' => 'TEST.R_BHAUPT',
                     'brush/side_right' => 'TEST.R_BSEITE', 'cleaning/sensor' => 'TEST.R_SENSOR',
                     'mop/all' => 'TEST.R_MOP') as $rb_teil => $rb_key) { ?>
<form method="post" action="index.php">
  <input data-role="none" type="hidden" name="activetab" value="tab-test">
  <input data-role="none" type="hidden" name="reset_dev" value="<?= (int) $rb_tn ?>">
  <?= rb_fmt() ?>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ro_reset" value="<?= rb_e($rb_teil) ?>"><?= rb_e(ro_t($rb_key)) ?></button>
</form>
<?php } ?>
</div>

<?php $rb_seg = ro_segments($rb_tn); if ($rb_seg) { ?>
<h3 class="sm-h3"><?= rb_e(ro_t('TEST.H_RAEUME')) ?></h3>
<table class="sm-tbl"><tr><th>ID</th><th><?= rb_e(ro_t('WORT.NAME')) ?></th><th><?= rb_e(ro_t('TEST.AUFRUF')) ?></th></tr>
<?php foreach ($rb_seg as $rb_id => $rb_nm) { ?>
<tr><td><span class="sm-mono"><?= rb_e($rb_id) ?></span></td><td><?= rb_e($rb_nm) ?></td>
<td><span class="sm-mono">http://<?= rb_e($rb_host) ?><?= rb_e(ro_endpunkt_pfad(array('cmd' => 'segments', 'p' => $rb_id, 'dev' => $rb_tn, 'token' => $rb_tok))) ?></span></td></tr>
<?php } ?></table>
<?php /* U11 (Durchgang 01.10.2026): volle Adressen samt Token - Regeln/04 "Angezeigte
         Adressen zum Abschreiben tragen jeden Parameter". */ ?>
<div class="sm-small"><?= rb_e(ro_t('TEST.MEHRERE_RAEUME')) ?> <span class="sm-mono">http://<?= rb_e($rb_host) ?><?= rb_e(ro_endpunkt_pfad(array('cmd' => 'segments', 'p' => implode(',', array_slice(array_keys($rb_seg), 0, 2)), 'dev' => $rb_tn, 'token' => $rb_tok))) ?></span></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?= rb_e(ro_t('TEST.RAUMLISTE_FEHLT')) ?></div>
<?php } ?>

<?php $rb_caps = ro_capabilities($rb_tn); if ($rb_caps) { ?>
<h3 class="sm-h3"><?= rb_e(ro_t('TEST.H_FAEHIGKEITEN')) ?></h3>
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
<?php } else {
    /* Treiber Xiaomi MIoT: Verbindung pruefen OHNE Bewegung (E-E9). Die Geraetekennung und der Token erscheinen nie. */ ?>
<h3 class="sm-h3"><?= rb_e(ro_t('TEST.H_MIOT_VERBINDUNG')) ?></h3>
<?php if (!$rb_test_offen) { ?>
<div class="sm-small"><?= rb_e(ro_t('TEST.MIOT_NUR_OFFEN')) ?></div>
<?php } else {
    $rb_d = ro_miot_diagnose($rb_tn);
    $rb_zz = function ($ok) { return $ok === 1 ? '<span style="color:#4f7d17;">&#10003;</span>' : ($ok === 0 ? '<span style="color:#c62828;">&#10007;</span>' : '&ndash;'); }; ?>
<table class="sm-tbl sm-pruef">
<tr><td><?= $rb_zz((int) $rb_d['gruss'][0]) ?></td><td><?= rb_e(ro_t('TEST.MIOT_GRUSS')) ?></td><td><?= rb_e($rb_d['gruss'][0] ? sprintf(ro_t('TEST.MIOT_GRUSS_JA'), (int) $rb_d['gruss'][1]) : ro_t('TEST.MIOT_GRUSS_NEIN')) ?></td></tr>
<tr><td><?= $rb_zz($rb_d['token'][0] ? 1 : ($rb_d['gruss'][0] || $rb_d['token'][1] === 'OPENSSL' || $rb_d['token'][1] === 'TOKEN_FEHLT' || $rb_d['token'][1] === 'TOKEN_FORM' ? 0 : 2)) ?></td><td><?= rb_e(ro_t('TEST.MIOT_TOKEN')) ?></td><td><?php
    if ($rb_d['token'][0]) {
        echo rb_e(sprintf(ro_t('TEST.MIOT_TOKEN_JA'), ro_t('MELDUNG.' . (isset($rb_d['status_schluessel']) ? $rb_d['status_schluessel'] : 'MIOT_STATUS_' . max(0, min(10, (int) $rb_d['token'][2])))),
            (int) $rb_d['token'][3] < 0 ? '-' : (int) $rb_d['token'][3]));
    } elseif ($rb_d['gruss'][0] && in_array($rb_d['token'][1], array('KEINE_ANTWORT', 'TOKEN'), true)) {
        echo rb_e(ro_t('TEST.MIOT_TOKEN_FALSCH'));
    } else {
        echo rb_e(ro_ausfall_grund_anzeige($rb_d['token'][1] !== '' ? $rb_d['token'][1] : 'KEIN_GRUSS'));
    } ?></td></tr>
<tr><td><?= $rb_zz(2) ?></td><td><?= rb_e(ro_t('TEST.MIOT_RAEUME')) ?></td><td><?= $rb_d['raeume'] !== null ? '<span class="sm-mono">' . rb_e($rb_d['raeume']) . '</span> ' : '' ?><?= rb_e(ro_t('TEST.MIOT_RAEUME_HINWEIS')) ?></td></tr>
<?php /* Nachbau 26.10. spaet: Warnung NUR fuer ein Modell ohne Tabelle (ro_miot_modell_bekannt()); die fuenf Werte roh. */ ?>
<tr><td><?= $rb_zz($rb_d['modell'] === '' ? 2 : (!empty($rb_d['bekannt']) ? 1 : 0)) ?></td><td><?= rb_e(ro_t('TEST.MIOT_MODELL')) ?></td><td><?= rb_e($rb_d['modell'] === '' ? '-' : $rb_d['modell'] . ($rb_d['firmware'] !== '' ? ', Firmware ' . $rb_d['firmware'] : '')
    . ($rb_d['modell'] !== '' && empty($rb_d['bekannt']) ? ' - ' . sprintf(ro_t('TEST.MIOT_MODELL_WARN'), $rb_d['modell']) : '')) ?></td></tr>
<?php if (!empty($rb_d['roh'])) { ?>
<tr><td><?= $rb_zz(2) ?></td><td><?= rb_e(ro_t('TEST.MIOT_ROH')) ?></td><td><span class="sm-mono"><?= rb_e($rb_d['roh']) ?></span></td></tr>
<?php } ?>
</table>
<div class="sm-small"><?= rb_e(ro_t('TEST.MIOT_OHNE_BEWEGUNG')) ?></div>
<?php } ?>
<h3 class="sm-h3"><?= rb_e(ro_t('TEST.H_FAEHIGKEITEN')) ?></h3>
<?php $rb_mk = array(); $rb_mn = array();
foreach (array_keys(ro_befehle()) as $rb_bn) {
    $rb_mb = ro_miot_befehle();
    if (isset($rb_mb[$rb_bn])) { $rb_mk[] = $rb_bn . ($rb_mb[$rb_bn]['pruef'] !== 'ja' ? ' (' . ro_t('WORT.UNGEPRUEFT') . ')' : ''); }
    else { $rb_mn[] = $rb_bn; }
} ?>
<div class="sm-small"><?= rb_e(sprintf(ro_t('TEST.MIOT_KANN'), implode(', ', $rb_mk))) ?><br><?= rb_e(sprintf(ro_t('TEST.MIOT_KANN_NICHT'), implode(', ', $rb_mn))) ?></div>
<?php } ?>
<?php } ?>
</div>

<!-- ================= Protokoll ================= -->
<div class="sm-pane<?= $rb_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<?php /* Seit 1.1.4 zwei Dateien statt einer - der Bediener muss wissen, wo die
       * Fehlerausgabe der Schale steht. */ ?>
<div class="sm-hinweis"><?= ro_t('LOG.CRON_DATEI') ?> <?= ro_t('LOG.BRUECKE_DATEI') ?></div>
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
    <label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;margin-right:12px;">
    <input data-role="none" type="checkbox" name="clearlog_ok" value="1"> <?= rb_e(ro_t('LOG.LEEREN_HAKEN')) ?></label>
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
    /* Gruppe 28 (1.1.23, S1; Form Docker D22/Beschattung B1): der Reiter Test ist ein ECHTER Seitenwechsel (der Link
       index.php?form=test laedt neu) - nur dann fragt die Seite, was warten kann (eigener Endpunkt, Alexa-NG, SonoX,
       Xiaomi-Verbindungspruefung). Die uebrigen Reiter schalten ohne Neuladen und ziehen die Adresse nach. */
    tabs.forEach(function (t) {
        t.addEventListener('click', function (e) {
            if (t.dataset.pane === 'tab-test') { return; }
            e.preventDefault();
            activate(t.dataset.pane);
            try { history.replaceState(null, '', 'index.php?form=' + t.dataset.pane.replace(/^tab-/, '')); } catch (x) {}
        });
    });
    activate(<?= json_encode($rb_tab) ?>);
    /* Gruppe 28 (1.1.23, E-E1): je Roboterzeile die Felder des anderen Treibers grau (nur lesbar). Ohne JavaScript
       bleiben alle Felder bedienbar - die Pruefung beim Speichern entscheidet (Regeln/04 :198). */
    document.querySelectorAll('tr.rb-zeile').forEach(function (z) {
        var s = z.querySelector('select.rb-typ');
        function zeigen() {
            var m = s.value === 'miot';
            z.querySelectorAll('.rb-nur-valetudo').forEach(function (f) { f.readOnly = m; f.style.opacity = m ? '0.45' : ''; });
            z.querySelectorAll('.rb-nur-miot-feld').forEach(function (f) { f.readOnly = !m; f.style.opacity = m ? '' : '0.45'; });
            z.querySelectorAll('.rb-nur-miot').forEach(function (f) { f.style.display = m ? '' : 'none'; });
        }
        if (s) { s.addEventListener('change', zeigen); zeigen(); }
    });
})();
/* Gruppe 2 (1.1.22): Namenstabelle in Schritt 3 - den Gateway-Namen in die Zwischenablage (Vorbild Marstek
   1.1.25 mvNameKopieren). execCommand zuerst: navigator.clipboard gibt es nur in einem sicheren Kontext, der LoxBerry
   laeuft ueblicherweise mit http im Heimnetz. */
function rbNameKopieren(k) {
    var a = k.getAttribute('data-name'), ok = false;
    try {
        var t = document.createElement('textarea');
        t.value = a; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.left = '-9999px';
        document.body.appendChild(t); t.select(); ok = document.execCommand('copy'); document.body.removeChild(t);
    } catch (e) { ok = false; }
    if (!ok && navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(a); ok = true; }
    k.textContent = k.getAttribute(ok ? 'data-ok' : 'data-fehl');
    return false;
}
</script>
<?php
if ($rb_frame) { LBWeb::lbfooter(); } else { echo '</body></html>'; }
