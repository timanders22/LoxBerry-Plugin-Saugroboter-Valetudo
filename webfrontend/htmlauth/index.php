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
 * Installiert sind html/ und htmlauth/ ZWEI GETRENNTE Baeume. Von
 * htmlauth/plugins/<ordner>/ sind es DREI dirname bis webfrontend/.
 * Findet keiner der Kandidaten etwas, bricht die Seite mit lesbarem Text
 * ab - bis 1.0.14 lief sie weiter und starb mitten im <style>-Block an
 * einem "Call to undefined function ro_t()".
 */
$rb_kandidaten = array();
$rb_home = getenv('LBHOMEDIR');
$rb_pdir = getenv('LBPPLUGINDIR');
if ($rb_home && $rb_pdir) {
    $rb_kandidaten[] = $rb_home . '/webfrontend/html/plugins/' . $rb_pdir . '/robo_lib.php';
}
$rb_kandidaten[] = dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/robo_lib.php';
$rb_kandidaten[] = dirname(__DIR__) . '/html/robo_lib.php';   // ausgepacktes Archiv
$rb_gefunden = '';
foreach ($rb_kandidaten as $rb_cand) {
    if (is_file($rb_cand)) { $rb_gefunden = $rb_cand; break; }
}
if ($rb_gefunden === '') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Saugroboter: robo_lib.php nicht gefunden.\nGesucht in:\n  "
       . implode("\n  ", $rb_kandidaten) . "\n";
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
if (empty($rb_cfg['aktionstoken'])) {
    $rb_cfg['aktionstoken'] = ro_token_erzeugen();
    ro_config_speichern($rb_cfg);
    $rb_cfg = ro_config();
}
$rb_fmt = ro_formtoken($rb_cfg);

$rb_meldungen = array();
$rb_fehler = array();
$rb_saved = false;

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
    $rb_js = json_encode(ro_sicherung_bauen(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($rb_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="saugroboter_einstellungen_'
               . date('Ymd_His') . '.json"');
        header('Content-Length: ' . strlen($rb_js));
        echo $rb_js;
        exit;
    }
    $rb_fehler[] = ro_t('TEXT.SICH_SCHREIBFEHLER');
}

/* Einstellungen zurueckspielen.
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei
 * des Servers unterschieben. Dann die Groessengrenze - eine Sicherung
 * dieses Plugins ist wenige Kilobyte gross; alles darueber wird gar
 * nicht erst gelesen. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ro_zurueck'])) {
    if (!isset($_FILES['ro_sicherung']) || !is_array($_FILES['ro_sicherung'])
        || !isset($_FILES['ro_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['ro_sicherung']['tmp_name'])) {
        $rb_fehler[] = ro_t('TEXT.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['ro_sicherung']['size'] > 262144) {
        $rb_fehler[] = ro_t('TEXT.SICH_ZU_GROSS');
    } else {
        list($rb_neu, $rb_mangel, $rb_n) = ro_sicherung_lesen(
            (string) @file_get_contents($_FILES['ro_sicherung']['tmp_name']));
        if ($rb_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert
             * wird nichts. */
            $rb_fehler[] = ro_t('TEXT.SICH_ABGELEHNT') . ' ' . implode(' ', $rb_mangel);
        } elseif (ro_config_speichern($rb_neu)) {
            $rb_meldungen[] = sprintf(ro_t('TEXT.SICH_UEBERNOMMEN'), $rb_n);
            /* NEU EINLESEN. Bis 1.0.14 zeichnete sich die Seite danach aus
             * der Variablen von weiter oben - der Anwender sah seine ALTEN
             * Werte, druckte "Speichern", und der save-Zweig machte das
             * Zurueckspielen rueckgaengig. Auch das Formularmerkmal haengt
             * am (neuen) Aktionstoken. */
            $rb_cfg = ro_config();
            $rb_fmt = ro_formtoken($rb_cfg);
        } else {
            $rb_fehler[] = ro_t('TEXT.SICH_SCHREIBFEHLER');
        }
    }
    $rb_tab = 'tab-settings';
}

// --- Protokoll leeren ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearlog'])) {
    @mkdir(dirname($rb_logfile), 0775, true);
    if (@file_put_contents($rb_logfile, '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Admin-Oberflaeche)\n") !== false) {
        $rb_meldungen[] = ro_t('TEXT.LOG_GELEERT');
    } else {
        $rb_fehler[] = ro_t('TEXT.LOG_NICHT_GELEERT') . ' ' . $rb_logfile;
    }
    $rb_tab = 'tab-log';
}

// --- Neues Aktionstoken erzeugen ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token_neu'])) {
    $rb_cfg['aktionstoken'] = ro_token_erzeugen();
    if (ro_config_speichern($rb_cfg)) {
        $rb_cfg = ro_config();
        $rb_fmt = ro_formtoken($rb_cfg);
        $rb_meldungen[] = ro_t('TEXT.TOKEN_NEU_OK');
    } else {
        $rb_fehler[] = ro_t('TEXT.SICH_SCHREIBFEHLER');
    }
    $rb_tab = 'tab-loxone';
}

// --- Verbrauchsteil zuruecksetzen (Reiter Test) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ro_reset'])
    && is_string($_POST['ro_reset'])) {
    $rb_teil = (string) $_POST['ro_reset'];
    $rb_rdev = isset($_POST['reset_dev']) ? max(1, min(9, (int) $_POST['reset_dev'])) : 1;
    if (preg_match('#^[a-z]+(/[a-z_]+)?$#', $rb_teil)) {
        list($rb_ok, $rb_info) = ro_command('reset', $rb_rdev, $rb_teil);
        if ($rb_ok) {
            $rb_meldungen[] = sprintf(ro_t('TEXT.RESET_OK'), $rb_teil);
        } else {
            $rb_fehler[] = sprintf(ro_t('TEXT.RESET_FEHLER'), $rb_teil, $rb_info);
        }
    } else {
        $rb_fehler[] = ro_t('TEXT.RESET_UNGUELTIG');
    }
    $rb_tab = 'tab-test';
}

// --- Raumliste und Faehigkeiten neu einlesen ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['neu_lesen'])) {
    ro_cache_leeren();
    $rb_meldungen[] = ro_t('TEXT.NEU_GELESEN');
    $rb_tab = 'tab-test';
}

// --- MQTT speichern (eigener Reiter seit 1.0.10, Hausstandard) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mqtt_save'])) {
    $rb_cfg['mqtt_enabled'] = isset($_POST['mqtt_enabled']) ? 1 : 0;
    $rb_thema = isset($_POST['mqtt_topic']) && is_string($_POST['mqtt_topic'])
        ? trim((string) $_POST['mqtt_topic']) : 'saugrobo';
    $rb_sauber = ro_mqtt_thema_saeubern($rb_thema);
    if ($rb_thema !== '' && $rb_sauber !== $rb_thema) {
        // Beanstanden, nicht stillschweigend zurechtbiegen.
        $rb_meldungen[] = sprintf(ro_t('TEXT.MQTT_THEMA_GEAENDERT'), $rb_sauber);
    }
    $rb_cfg['mqtt_topic'] = $rb_sauber;
    if (ro_config_speichern($rb_cfg)) {
        $rb_cfg = ro_config();
        $rb_saved = true;
    } else {
        $rb_fehler[] = ro_t('TEXT.NICHT_GESPEICHERT') . ' ' . $rb_cfgfile;
    }
    $rb_tab = 'tab-mqtt';
}

// --- Einstellungen speichern ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    /* Aus dem Bestand uebernehmen, was dieses Formular nicht mitschickt.
     * BIS 1.0.9 FEHLTE DAS FUER aktionstoken: jedes Speichern der Einstellungen
     * warf das Token still weg, der naechste Seitenaufruf erzeugte ein NEUES -
     * und alle Loxone-Adressen liefen auf 403. */
    $rb_neu = ro_config();
    $rb_neu['robots'] = array();
    $rb_n1 = isset($_POST['r_name']) ? (array) $_POST['r_name'] : array();
    $rb_i2 = isset($_POST['r_ip']) ? (array) $_POST['r_ip'] : array();
    $rb_p2 = isset($_POST['r_port']) ? (array) $_POST['r_port'] : array();
    $rb_u2 = isset($_POST['r_user']) ? (array) $_POST['r_user'] : array();
    $rb_w2 = isset($_POST['r_pass']) ? (array) $_POST['r_pass'] : array();
    $rb_alt = ro_robots();
    $rb_nr_feld = isset($_POST['r_nr']) ? (array) $_POST['r_nr'] : array();
    $rb_zeilenfehler = array();
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
        $rb_nr = isset($rb_nr_feld[$rb_i]) ? (int) $rb_nr_feld[$rb_i] : 0;
        // Kam die Nummer wirklich aus dem Formular? Nur dann darf ueber sie
        // ein Kennwort geerbt werden (siehe weiter unten).
        $rb_nr_echt = ($rb_nr >= 1 && $rb_nr <= 9 && !in_array($rb_nr, $rb_vergeben, true));
        if (!$rb_nr_echt) {
            $rb_nr = 1;
            while (in_array($rb_nr, $rb_vergeben, true)) { $rb_nr++; }
        }
        $rb_ip = trim((string) (isset($rb_i2[$rb_i]) ? $rb_i2[$rb_i] : ''));
        if ($rb_ip === '') { continue; }
        $rb_vergeben[] = $rb_nr;
        if (!preg_match('/^[\w\.\-]{1,253}$/', $rb_ip)) {
            /* Beanstanden - aber die uebrigen Zeilen NICHT verwerfen.
             *
             * DAS STAND SO SCHON IN 1.1.3 UND STIMMTE NICHT. Die Beanstandung
             * landete in $rb_fehler, und die Sperre weiter unten
             * (if (!array_filter($rb_fehler))) verhinderte damit das Speichern
             * der GANZEN Seite - waehrend der Text daneben sagte "der bisherige
             * Eintrag bleibt stehen", also das Gegenteil. Gemessen am
             * 04.09.2026: cache_sec 20 -> 77 und warn_hours 10 -> 55 kamen
             * NICHT an, gemeldet wurde nur die Adresse.
             *
             * Adressbeanstandungen kommen deshalb in eine EIGENE Liste. Sie
             * werden angezeigt, sperren aber nicht - genau das verlangt die
             * Hausregel "Beanstandungen melden, nicht das ganze Speichern
             * verhindern". Was das Speichern technisch unmoeglich macht,
             * sperrt weiterhin. */
            $rb_zeilenfehler[] = sprintf(ro_t('TEXT.ROBOTER_ADRESSE_UNGUELTIG'), $rb_nr);
            // Den bisherigen Eintrag behalten, damit nichts verlorengeht.
            if (isset($rb_alt[$rb_nr])) { $rb_neu['robots'][] = $rb_alt[$rb_nr]; }
            continue;
        }
        /* Ein leeres Kennwortfeld LOESCHT nicht. Der Browser fuellt
         * type=password nicht vor; wer nur den Namen aendert, verlaere sonst
         * die Anmeldung. Geloescht wird ueber den Haken daneben. */
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
        $rb_pw = (string) (isset($rb_w2[$rb_i]) ? $rb_w2[$rb_i] : '');
        if ($rb_pw === '' && $rb_nr_echt && empty($_POST['r_pass_loeschen'][$rb_i])
            && isset($rb_alt[$rb_nr]['pass'])) {
            $rb_pw = (string) $rb_alt[$rb_nr]['pass'];
        }
        if (!empty($_POST['r_pass_loeschen'][$rb_i])) { $rb_pw = ''; }
        $rb_neu['robots'][] = array(
            'nr' => $rb_nr,
            'name' => trim((string) (isset($rb_n1[$rb_i]) ? $rb_n1[$rb_i] : '')),
            'ip' => $rb_ip,
            'port' => max(1, min(65535, (int) (isset($rb_p2[$rb_i]) ? $rb_p2[$rb_i] : 80))),
            'user' => trim((string) (isset($rb_u2[$rb_i]) ? $rb_u2[$rb_i] : '')),
            'pass' => $rb_pw);
    }
    $rb_neu['cache_sec'] = max(5, min(300, (int) (isset($_POST['cache_sec']) ? $_POST['cache_sec'] : 20)));
    $rb_neu['warn_hours'] = max(0, min(200, (int) (isset($_POST['warn_hours']) ? $_POST['warn_hours'] : 10)));
    $rb_neu['warn_prozent'] = max(0, min(100, (int) (isset($_POST['warn_prozent']) ? $_POST['warn_prozent'] : 10)));
    $rb_neu['notify'] = array(
        'audio' => isset($_POST['notify_audio']) ? 1 : 0,
        'push' => isset($_POST['notify_push']) ? 1 : 0,
        'fertig' => isset($_POST['n_fertig']) ? 1 : 0,
        'fehler' => isset($_POST['n_fehler']) ? 1 : 0,
        'material' => isset($_POST['n_material']) ? 1 : 0,
        'ereignis' => isset($_POST['n_ereignis']) ? 1 : 0,
    );
    $rb_mode = (string) (isset($_POST['tts_mode']) && is_string($_POST['tts_mode']) ? $_POST['tts_mode'] : 'musicserver');
    $rb_neu['tts'] = array(
        'mode' => in_array($rb_mode, array('musicserver', 'ms4h', 'audioserver', 'custom'), true) ? $rb_mode : 'musicserver',
        'ip' => trim((string) (isset($_POST['tts_ip']) ? $_POST['tts_ip'] : '')),
        'port' => max(1, min(65535, (int) (isset($_POST['tts_port']) ? $_POST['tts_port'] : 7091))),
        'zones' => trim((string) (isset($_POST['tts_zones']) ? $_POST['tts_zones'] : '1')),
        'volume' => max(1, min(100, (int) (isset($_POST['tts_volume']) ? $_POST['tts_volume'] : 8))),
        'lang' => preg_replace('/[^a-z]/', '', strtolower((string) (isset($_POST['tts_lang']) ? $_POST['tts_lang'] : 'de'))) ?: 'de',
        'template' => trim((string) (isset($_POST['tts_template']) ? $_POST['tts_template'] : '')),
    );
    /* Dieselbe Wache wie beim Zurueckspielen - eine zweite Wahrheit ueber
     * zulaessige Werte gibt es nicht. */
    foreach ($rb_neu as $rb_k => $rb_v) {
        if (!array_key_exists($rb_k, ro_vorgaben())) { continue; }
        $rb_grund = ro_wert_pruefen($rb_k, $rb_v);
        if ($rb_grund !== '') { $rb_fehler[] = sprintf(ro_t('TEXT.SICH_WERT'), rb_e($rb_k), rb_e($rb_grund)); }
    }
    /* Gesperrt wird nur durch $rb_fehler - also durch das, was das Speichern
     * wirklich unmoeglich macht (ein unzulaessiger Wert, ein Schreibfehler).
     * Eine ungueltige Roboteradresse ist das NICHT: sie betrifft eine Zeile,
     * und die behaelt ihren alten Stand. */
    if (!array_filter($rb_fehler)) {
        if (ro_config_speichern($rb_neu)) {
            $rb_saved = true;
            $rb_cfg = ro_config();
            $rb_fmt = ro_formtoken($rb_cfg);
        } else {
            $rb_fehler[] = ro_t('TEXT.NICHT_GESPEICHERT') . ' ' . $rb_cfgfile;
        }
    }
    /* ERST JETZT dazu - angezeigt, aber nicht sperrend. */
    foreach ($rb_zeilenfehler as $rb_zf) { $rb_fehler[] = $rb_zf; }
    $rb_tab = 'tab-settings';
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
<td><input data-role="none" type="text" name="r_name[<?= $rb_i ?>]" value="<?= rb_e($rb_r['name']) ?>" placeholder="<?= rb_e($rb_i === 0 ? ro_t('EINST.NAME_BEISPIEL') : ro_t('EINST.LEER_UNGENUTZT')) ?>"></td>
<td><input data-role="none" type="text" name="r_ip[<?= $rb_i ?>]" value="<?= rb_e($rb_r['ip']) ?>" placeholder="<?= rb_e($rb_i === 0 ? ro_t('EINST.IP_BEISPIEL') : '') ?>"></td>
<td><input data-role="none" type="number" name="r_port[<?= $rb_i ?>]" value="<?= (int) $rb_r['port'] ?>" min="1" max="65535"></td>
<td><input data-role="none" type="text" name="r_user[<?= $rb_i ?>]" value="<?= rb_e($rb_r['user']) ?>" autocomplete="off"></td>
<td><input data-role="none" type="password" name="r_pass[<?= $rb_i ?>]" value="" autocomplete="new-password" placeholder="<?= rb_e($rb_r['pass'] !== '' ? ro_t('EINST.GESETZT') : '') ?>">
<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;margin-top:4px;">
<input data-role="none" type="checkbox" name="r_pass_loeschen[<?= $rb_i ?>]" value="1"> <?= rb_e(ro_t('EINST.KENNWORT_LOESCHEN')) ?></label></td>
</tr>
<?php } ?>
</table>
</div>
<div class="sm-small"><?= ro_t('EINST.ROBOTER_HINWEIS') ?></div>
<div class="sm-small"><?= ro_t('EINST.ANMELDUNG_HINWEIS') ?></div>

<div class="sm-row">
    <div>
        <label><?= rb_e(ro_t('EINST.CACHE')) ?></label>
        <input data-role="none" type="number" name="cache_sec" value="<?= (int) $rb_cfg['cache_sec'] ?>" min="5" max="300">
        <div class="sm-small"><?= rb_e(ro_t('EINST.CACHE_HINWEIS')) ?></div>
    </div>
    <div>
        <label><?= rb_e(ro_t('EINST.WARN_STUNDEN')) ?></label>
        <input data-role="none" type="number" name="warn_hours" value="<?= (int) $rb_cfg['warn_hours'] ?>" min="0" max="200">
        <div class="sm-small"><?= ro_t('EINST.WARN_STUNDEN_HINWEIS') ?></div>
    </div>
    <div>
        <label><?= rb_e(ro_t('EINST.WARN_PROZENT')) ?></label>
        <input data-role="none" type="number" name="warn_prozent" value="<?= (int) $rb_cfg['warn_prozent'] ?>" min="0" max="100">
        <div class="sm-small"><?= rb_e(ro_t('EINST.WARN_PROZENT_HINWEIS')) ?></div>
    </div>
</div>

<h2><?= rb_e(ro_t('EINST.H_MELDUNGEN')) ?></h2>
<div style="margin-bottom:10px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;font-weight:400;">
        <input data-role="none" type="checkbox" name="notify_audio" <?= !empty($rb_notify['audio']) ? 'checked' : '' ?>> <?= rb_e(ro_t('EINST.AUDIO_AKTIV')) ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
        <input data-role="none" type="checkbox" name="notify_push" <?= !empty($rb_notify['push']) ? 'checked' : '' ?>> <?= rb_e(ro_t('EINST.PUSH_AKTIV')) ?>
    </label>
    <div class="sm-small"><?= ro_t('EINST.MELDUNG_HINWEIS') ?></div>
</div>
<div>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;font-weight:400;">
        <input data-role="none" type="checkbox" name="n_fertig" <?= !empty($rb_notify['fertig']) ? 'checked' : '' ?>> <?= rb_e(ro_t('EINST.N_FERTIG')) ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;font-weight:400;">
        <input data-role="none" type="checkbox" name="n_fehler" <?= !empty($rb_notify['fehler']) ? 'checked' : '' ?>> <?= rb_e(ro_t('EINST.N_FEHLER')) ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;font-weight:400;">
        <input data-role="none" type="checkbox" name="n_material" <?= !empty($rb_notify['material']) ? 'checked' : '' ?>> <?= rb_e(ro_t('EINST.N_MATERIAL')) ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
        <input data-role="none" type="checkbox" name="n_ereignis" <?= !empty($rb_notify['ereignis']) ? 'checked' : '' ?>> <?= rb_e(ro_t('EINST.N_EREIGNIS')) ?>
    </label>
</div>

<h2><?= rb_e(ro_t('EINST.H_SPRACHAUSGABE')) ?></h2>
<div class="sm-row">
    <div>
        <label><?= rb_e(ro_t('EINST.TTS_WEG')) ?></label>
        <select data-role="none" name="tts_mode" id="tts_mode" onchange="rbTtsMode()">
            <option value="musicserver"<?= $rb_tts['mode'] === 'musicserver' ? ' selected' : '' ?>><?= rb_e(ro_t('EINST.TTS_MUSICSERVER')) ?></option>
            <option value="ms4h"<?= $rb_tts['mode'] === 'ms4h' ? ' selected' : '' ?>><?= rb_e(ro_t('EINST.TTS_MS4H')) ?></option>
            <option value="audioserver"<?= $rb_tts['mode'] === 'audioserver' ? ' selected' : '' ?>><?= rb_e(ro_t('EINST.TTS_AUDIOSERVER')) ?></option>
            <option value="custom"<?= $rb_tts['mode'] === 'custom' ? ' selected' : '' ?>><?= rb_e(ro_t('EINST.TTS_EIGEN')) ?></option>
        </select>
    </div>
    <div>
        <label><?= rb_e(ro_t('EINST.TTS_IP')) ?></label>
        <input data-role="none" type="text" name="tts_ip" value="<?= rb_e($rb_tts['ip']) ?>" placeholder="<?= rb_e(ro_t('EINST.IP_BEISPIEL2')) ?>">
    </div>
    <div>
        <label><?= rb_e(ro_t('EINST.PORT')) ?></label>
        <input data-role="none" type="number" name="tts_port" value="<?= (int) $rb_tts['port'] ?>" min="1" max="65535">
    </div>
</div>
<div class="sm-row">
    <div>
        <label><?= rb_e(ro_t('EINST.ZONEN')) ?></label>
        <input data-role="none" type="text" name="tts_zones" value="<?= rb_e($rb_tts['zones']) ?>" placeholder="2,4,6">
        <div class="sm-small"><?= ro_t('EINST.ZONEN_HINWEIS') ?></div>
    </div>
    <div>
        <label><?= rb_e(ro_t('EINST.LAUTSTAERKE')) ?></label>
        <input data-role="none" type="number" name="tts_volume" value="<?= (int) $rb_tts['volume'] ?>" min="1" max="100">
    </div>
    <div>
        <label><?= rb_e(ro_t('EINST.SPRACHE')) ?></label>
        <input data-role="none" type="text" name="tts_lang" value="<?= rb_e($rb_tts['lang']) ?>" maxlength="2">
    </div>
</div>
<div id="tts_template_row">
    <label><?= rb_e(ro_t('EINST.TTS_VORLAGE')) ?></label>
    <textarea data-role="none" name="tts_template" id="tts_template" rows="2" placeholder="http://{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}"><?= rb_e($rb_tts['template']) ?></textarea>
    <div class="sm-small"><?= ro_t('EINST.TTS_VORLAGE_HINWEIS') ?></div>
</div>
<div id="tts_audioserver_hint" class="sm-alert sm-info" style="display:none;">
    <?= ro_t('EINST.TTS_AUDIOSERVER_HINWEIS') ?>
</div>

<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= rb_e(ro_t('KNOPF.SPEICHERN')) ?></button>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= rb_e(ro_t('LEGENDE.AKTION')) ?></span>
</div>
</form>

<h2><?= rb_e(ro_t('EINST.H_SICHERUNG')) ?></h2>
<div class="sm-hinweis"><?= ro_t('EINST.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= ro_t('EINST.SICH_WARNUNG') ?></div>
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
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= rb_e(ro_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= rb_e(ro_t('LEGENDE.AKTION')) ?></span>
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
    <input data-role="none" type="checkbox" name="mqtt_enabled" <?= !empty($rb_cfg['mqtt_enabled']) ? 'checked' : '' ?>> <?= rb_e(ro_t('MQTT.EINSCHALTEN')) ?>
</label>
<div class="sm-row" style="margin-top:6px;">
    <div>
        <label><?= rb_e(ro_t('MQTT.PRAEFIX')) ?></label>
        <input data-role="none" type="text" name="mqtt_topic" value="<?= rb_e($rb_cfg['mqtt_topic']) ?>" placeholder="saugrobo">
        <div class="sm-small"><?= sprintf(ro_t('MQTT.PRAEFIX_HINWEIS'),
            '<span class="sm-mono">' . rb_e($rb_cfg['mqtt_topic']) . '/code</span>',
            '<span class="sm-mono">' . rb_e($rb_cfg['mqtt_topic']) . '/2/&hellip;</span>') ?></div>
    </div>
</div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= rb_e(ro_t('KNOPF.SPEICHERN')) ?></button>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= rb_e(ro_t('LEGENDE.AKTION')) ?></span>
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
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= rb_e(ro_t('LEGENDE.AKTION_TOKEN')) ?></span>
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
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= rb_e(ro_t('LEGENDE.TECHNIK')) ?></span>
</div>

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
<?php foreach (ro_selbsttest() as $rb_z) {
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
<a data-role="none" class="sm-btn sm-b-technik" href="<?= rb_e(ro_endpunkt_pfad(array('debug' => 1, 'refresh' => 1))) ?>" target="_blank"><?= rb_e(ro_t('TEST.K_DEBUG')) ?></a>
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
<td><span class="sm-mono">?cmd=segments&amp;p=<?= rb_e($rb_id) ?></span></td></tr>
<?php } ?></table>
<div class="sm-small"><?= rb_e(ro_t('TEST.MEHRERE_RAEUME')) ?> <span class="sm-mono">?cmd=segments&amp;p=<?= rb_e(implode(',', array_slice(array_keys($rb_seg), 0, 2))) ?></span></div>
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
<div class="sm-knopfreihe">
<form action="index.php" method="post">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <?= rb_fmt() ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= rb_e(ro_t('KNOPF.LOG_LEEREN')) ?></button>
</form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= rb_e(ro_t('LEGENDE.AKTION')) ?></span>
</div>
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
if ($rb_frame) { LBWeb::lbfooter(); } else { echo '</body></html>'; }
