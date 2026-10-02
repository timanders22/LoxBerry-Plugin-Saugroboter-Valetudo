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


/* Gemeinsame Sprachausgabe (Abschrift von Werkzeuge/gemeinsam/sprachausgabe.php,
 * Nr. 36 b, Stufe 1). Liegt neben dieser Datei; sie legt beim Einbinden nur
 * Funktionen an und schuetzt sich selbst gegen doppeltes Laden. */
require_once __DIR__ . '/sprachausgabe.php';


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins, data/plugins UND config/system/general.json traegt. Das
 * trifft die uebliche Installation genauso wie eine an einem anderen Ort -
 * und es trifft auch den Fall, dass das Plugin noch als entpacktes Archiv
 * daliegt (dann findet es nichts und gibt einen Leerstring zurueck, was der
 * Aufrufer ohnehin abfangen muss).
 *
 * general.json ist die entscheidende Bedingung. Bis 1.1.9 genuegten
 * config/plugins und webfrontend - genau diese Ordner hinterlaesst ein
 * Pruefstand auf einem Arbeitsrechner (Regeln/06, Raumklima-Vorfall). In WSL
 * gemessen (Pruefung-Saugroboter-Valetudo-1.1.10, Faelle H1 und H2): in einem
 * fremden Baum ohne general.json nahm diese Bibliothek den Baum als Wurzel,
 * und bin/cron.php schrieb dort hinein.
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
                && is_file($d . '/config/system/general.json')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

/* Die Wurzel in der Reihenfolge der Hausregel: erst die Umgebung, dann die
 * Suche - und danach nichts mehr.
 *
 * Ein gesetztes LBHOMEDIR gilt mit config/plugins UND data/plugins darunter -
 * general.json wird hier nicht verlangt, damit die Attrappen der
 * Pruefwerkzeuge (Werkzeuge/lb) weiter tragen. Rueckgabe '' heisst "keine
 * Wurzel"; jeder Aufrufer muss das abfangen. Bauart awm_lbhome() aus
 * AWM-Abfuhr 1.4.13. */
function ro_lbhome()
{
    $h = getenv('LBHOMEDIR');
    if ($h && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')) {
        return rtrim($h, '/');
    }
    return lb_wurzel_ermitteln();
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

/**
 * Die Pfade - der Anlage, oder im Archivmodus die Ersatzpfade.
 *
 * Die Pfade DER ANLAGE gelten nur, wenn diese Bibliothek dort installiert
 * liegt (<Wurzel>/webfrontend/html/plugins/<ordner>, physisch verglichen)
 * oder der Aufrufer Wurzel UND Ordner ausdruecklich nennt ($LBHOMEDIR und
 * $LBPPLUGINDIR - so arbeiten die Pruefwerkzeuge mit ihrer Attrappe, und so
 * ruft die Deinstallation bin/cron.php). Sonst ist das ein ausgepacktes
 * Archiv oder ein Pruefordner, und es gelten die Ersatzpfade im Temp-Ordner.
 *
 * Bis 1.1.9 nahm ein Archiv unterhalb einer echten Wurzel diese Wurzel und
 * den festen Namen 'saugrobo' - Konfiguration, Daten, Protokoll und
 * Zwischenspeicher der Anlage; mit $LBHOMEDIR allein, wie es am Geraet in
 * /etc/environment steht, ebenso. Und ohne Wurzel lagen Konfiguration und
 * Zweitschrift im Archiv selbst - aus /webfrontend/html also ab der
 * Laufwerkswurzel, //config/robo.json (in WSL gemessen,
 * Pruefung-Saugroboter-Valetudo-1.1.10, Faelle B1, B2, B6, B7, B11, C10).
 * Bauart awm_paths() aus AWM-Abfuhr 1.4.13.
 *
 * Der Ordnername wird ERMITTELT, nicht geraten (ro_plugin_ordner()). Bis
 * 1.0.3 stand hier ein Rueckfall auf "saugrobo", sobald config/plugins/
 * <ordner> noch fehlte; eine Zweitinstallation (saugrobo_01) schrieb dann in
 * die Konfiguration der ersten.
 */
function ro_paths() {
    $lb = ro_lbhome();
    $pd = ro_plugin_ordner();
    $gefunden = $lb;
    if ($lb !== '') {
        $soll = @realpath($lb . '/webfrontend/html/plugins/' . basename(__DIR__));
        $ist = @realpath(__DIR__);
        $installiert = ($soll !== false && $ist !== false && $soll === $ist);
        $lbp = (string) getenv('LBPPLUGINDIR');
        $ausdruecklich = ($lbp !== '' && $pd === $lbp && $lb === rtrim((string) getenv('LBHOMEDIR'), '/'));
        if (!$installiert && !$ausdruecklich) { $lb = ''; }
    }
    if ($lb !== '') {
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
                     'plugin' => $pd, 'lbhome' => $lb,
                     'general' => $lb . '/config/system/general.json',
                     'archiv' => '');
    }
    /* Keine Wurzel (Entwicklung, Pruefstand, fremder Baum) oder Archivmodus:
     * die Ersatzpfade unter dem Temp-Ordner, unter einem EIGENEN Namen - nie
     * ein Pfad der Anlage, nie einer ab der Laufwerkswurzel und nie der
     * Zwischenspeicher /tmp/<ordner> der Anlage. bin/cron.php steigt in
     * beiden Faellen vorher aus (ro_keine_wurzel_abbruch()); MQTT verlangt
     * eine Wurzel. */
    $tmp = sys_get_temp_dir() . '/saugrobo-archiv';
    return array('config' => $tmp . '/robo.json',
                 'backup' => $tmp . '/robo.backup.json',
                 'log' => $tmp . '/robo.log',
                 'datadir' => $tmp . '/data',
                 'tmp' => $tmp,
                 'plugin' => $pd, 'lbhome' => '', 'general' => '',
                 // Die gefundene Wurzel, wenn diese Datei NICHT darin
                 // installiert liegt (Archivmodus) - fuer die Meldung.
                 'archiv' => $gefunden);
}

/* Fuer bin/cron.php: ohne Wurzel (oder aus einem ausgepackten Archiv) nichts
 * tun, eine Meldung auf stderr, Rueckgabewert 1. Steht dort VOR der Sperre,
 * denn schon die legt eine Datei an. Bis 1.1.9 lief bin/cron.php aus einem
 * Archiv unter einer echten Wurzel mit Konfiguration, Zwischenspeicher und
 * Protokoll der Anlage, fragte den Roboter und sandte MQTT (in WSL gemessen,
 * Pruefung-Saugroboter-Valetudo-1.1.10, Faelle B6, B7, H2). Bauart
 * awm_keine_wurzel_abbruch() aus AWM-Abfuhr 1.4.13. */
function ro_keine_wurzel_abbruch($programm)
{
    $p = ro_paths();
    if ($p['lbhome'] !== '') { return; }
    if ($p['archiv'] !== '') {
        fwrite(STDERR, $programm . ': Diese Datei liegt nicht in der Installation unter '
            . $p['archiv'] . "\n"
            . '(ausgepacktes Archiv oder Pruefordner). Damit nichts in die Anlage kommt,' . "\n"
            . 'wurde nichts abgefragt, nichts gesendet und nichts geschrieben.' . "\n"
            . 'Abhilfe: das Programm aus ' . $p['archiv'] . '/bin/plugins/<ordner>' . "\n"
            . 'aufrufen oder LBHOMEDIR und LBPPLUGINDIR ausdruecklich setzen.' . "\n");
        exit(1);
    }
    fwrite(STDERR, $programm . ': Es wurde kein LoxBerry-Wurzelverzeichnis gefunden.' . "\n"
        . '$LBHOMEDIR ist nicht gesetzt, und oberhalb von ' . __DIR__ . ' traegt kein' . "\n"
        . 'Verzeichnis config/plugins, data/plugins und config/system/general.json.' . "\n"
        . 'Es wurde nichts abgefragt, nichts gesendet und nichts geschrieben.' . "\n");
    exit(1);
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
 * Darf in diesem Prozess ueberhaupt etwas angelegt werden?
 *
 * BIS 1.1.3 WAR DAS EIN PARAMETER, UND DAS HAT NICHT GETRAGEN.
 * ro_config($erzeugen = false) stand nur in ro_token_lage(). Der LESEWEG des
 * unangemeldeten Endpunkts - also der Weg, den Loxone bei jeder Abfrage geht -
 * laeuft ueber ro_state() -> ro_config() mit der Vorgabe true. Gemessen am
 * 04.09.2026 in einer nachgebauten Installationslage: Konfigordner geloescht,
 * ein Aufruf von robo.php OHNE Parameter und OHNE Token, danach lag
 * config/plugins/<ordner>/robo.json wieder da, aus der Zweitschrift.
 *
 * Ein Parameter, den man an jeder Aufrufstelle durchreichen muss, wird beim
 * naechsten Ausbau wieder an einer vergessen. Deshalb jetzt ein Schalter fuer
 * den ganzen Prozess: robo.php setzt ihn einmal am Kopf, und ro_config()
 * achtet ihn, gleichgueltig wer sie ruft.
 */
/**
 * Der zuerst gesehene Zustand der Konfiguration - und nur der.
 *
 * Ein geheilter Schaden ist kein Nicht-Schaden. Die Selbstheilung laeuft beim
 * ERSTEN Aufruf der Lesefunktion; die Pruefzeile im Reiter Test ruft sie
 * spaeter ein zweites Mal und saehe dann eine heile Datei. Der Bediener
 * erfuehre nie, dass etwas war. Deshalb wird der erste Befund fuer die Dauer
 * des Prozesses festgehalten und von einem spaeteren "ok" NICHT ueberschrieben.
 *
 * Moegliche Zustaende: ok, fehlt, leer, kaputt, aus der Zweitschrift.
 */
function ro_cfg_zustand($setzen = null)
{
    static $zustand = 'ok';
    static $fest = false;
    if ($setzen !== null && !$fest) {
        $zustand = (string) $setzen;
        if ($setzen !== 'ok') { $fest = true; }
    }
    return $zustand;
}

function ro_config_erzeugen_erlauben($wert = null)
{
    static $erlaubt = true;
    if ($wert !== null) { $erlaubt = (bool) $wert; }
    return $erlaubt;
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
    $erzeugen = $erzeugen && ro_config_erzeugen_erlauben();
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';

    /* DREI LAGEN, DIE AUSEINANDERGEHALTEN GEHOEREN.
     *
     *   Datei fehlt        Neuinstallation         - ein Token darf entstehen
     *   Datei leer oder {} Aktualisierungsfall     - ein Token darf entstehen
     *   ungueltiges JSON   BESCHAEDIGT             - Fehler, kein leerer Zustand
     *
     * Bis 1.1.3 fielen alle drei zusammen: json_decode(...) ?: array() machte
     * aus einer abgeschnittenen Datei stillschweigend eine leere. Gemessen am
     * 04.09.2026 unter 7.4 UND 8.4, mit Kontrollfall: eine beschaedigte
     * robo.json plus EIN Oeffnen der Oberflaeche ersetzte Roboterliste,
     * MQTT-Praefix und Aktionstoken durch Werkseinstellungen - in der
     * Konfiguration UND in der Zweitschrift. Keine .kaputt-Datei, keine
     * Protokollzeile. Die einzige Rettungskopie war damit ueberschrieben, und
     * jede im Miniserver eingetragene Adresse lief auf 403.
     */
    $lage = 'ok';
    $daten = null;
    if ($roh === '' || $roh === '{}') {
        /* "{}" gilt ausdruecklich als LEER, nicht als gueltige Konfiguration.
         * json_decode('{}') liefert ein Feld und faellt deshalb nicht in den
         * kaputt-Zweig - ohne diese Zeile wuerde eine Anlage, deren
         * Konfiguration der Installer gerade auf "{}" gesetzt hat, ein NEUES
         * Aktionstoken bekommen, waehrend die gute Zweitschrift danebenliegt.
         * postinstall.sh behandelt denselben Fall genauso. */
        $daten = array();
        $lage = is_file($p['config']) ? 'leer' : 'fehlt';
    } else {
        $daten = json_decode($roh, true);
        if (!is_array($daten)) { $lage = 'kaputt'; $daten = null; }
    }

    if ($lage === 'kaputt') {
        /* Die beschaedigte Datei wird BEISEITEGELEGT, nicht ueberschrieben:
         * sie ist das Einzige, woraus sich hinterher noch etwas holen laesst. */
        $kaputt = $p['config'] . '.kaputt';
        /* I2 (Durchgang 01.10.2026): mit 0600 und den Rechten VOR dem Inhalt
         * (ro_write_atomic), nicht mit copy(). copy() legte die Datei mit der
         * umask an - in WSL gemessen 644 (Installer-Pruefer, Fall R1), und
         * darin stehen Aktionstoken und Valetudo-Anmeldung. Beiseitegelegt
         * wird der ungekuerzte Rohinhalt, nicht der getrimmte. */
        if (!is_file($kaputt)) {
            $ro_kroh = @file_get_contents($p['config']);
            if (is_string($ro_kroh)) { ro_write_atomic($kaputt, $ro_kroh, 0600); }
        }
        ro_log_if_changed('cfg_kaputt', 'Die Konfiguration ist beschaedigt (kein gueltiges JSON). '
            . 'Beiseitegelegt als ' . basename($kaputt) . '.');
    }

    /* SELBSTHEILUNG NACH INHALT, NICHT NACH FORM.
     * Bis 1.1.3 hiess die Bedingung ($roh === '' || $roh === '{}'). Eine halb
     * geschriebene Datei ist weder leer noch {}, wurde also nicht geheilt -
     * und eine Zweitschrift ohne Aktionstoken wurde blind kopiert. Geprueft
     * wird jetzt, ob die Zweitschrift ueberhaupt etwas Rettbares enthaelt. */
    if ($erzeugen && in_array($lage, array('fehlt', 'leer', 'kaputt'), true) && is_file($p['backup'])) {
        $bk = json_decode((string) @file_get_contents($p['backup']), true);
        if (is_array($bk) && isset($bk['aktionstoken']) && trim((string) $bk['aktionstoken']) !== '') {
            if (!is_dir(dirname($p['config']))) { @mkdir(dirname($p['config']), 0775, true); }
            if (ro_write_atomic($p['config'], json_encode($bk, JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0600)) {
                $daten = $bk;
                ro_log_if_changed('cfg_geheilt', 'Konfiguration aus der Zweitschrift wiederhergestellt ('
                    . $lage . ').');
                $lage = 'aus der Zweitschrift';
            }
        }
    }

    ro_cfg_zustand($lage);
    $cfg = is_array($daten) ? $daten : array();
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
                         'zones' => '1', 'volume' => 8, 'lang' => 'de', 'template' => '',
                         // Ansage-2 (01.10.2026): Alexa-NG, ab Werk nicht gewaehlt.
                         // Das Sprechtoken ist ein Geheimnis: nie in der Seite,
                         // nicht in der Sicherung.
                         'alexa_geraet' => '', 'alexa_laut' => -1, 'alexa_token' => '',
                         // Ansage-3 (01.10.2026): Google-Lautsprecher ueber Chromecast 4 Lox NG,
                         // ab Werk nicht gewaehlt. Eigenes Sprechtoken, getrennt vom Alexa-Token;
                         // ebenso nie in der Seite und nicht in der Sicherung.
                         'google_geraet' => '', 'google_laut' => -1, 'google_token' => '');
    return $cfg;
}

/**
 * Die eingerichteten Roboter, nach ihrer GERAETENUMMER.
 *
 * BIS 1.1.3 WAR DIE NUMMER EINE AUFZAEHLUNG, UND DAS IST KEINE ADRESSE.
 * Gemessen am 04.09.2026: Konfiguration mit zwei Robotern, die Adresse des
 * ersten geleert - danach lieferte &dev=1 den ZWEITEN Roboter, mit dessen
 * Namen, und &dev=2 meldete OK=0;CODE=8 fuer ein Geraet, das da ist. An der
 * Nummer haengen der virtuelle Eingang, das MQTT-Thema und die
 * Endpunktadresse; sie darf nicht wandern.
 *
 * Seit 1.1.4 traegt jede Zeile ihre Nummer selbst ($r['nr']). FEHLT SIE,
 * GILT DIE BISHERIGE ZAEHLUNG - das ist der Aktualisierungsfall, und eine
 * bestehende Anlage darf durch das Update keine andere Zuordnung bekommen.
 * Beim naechsten Speichern wird die Nummer festgeschrieben.
 */
function ro_robots() {
    return ro_robots_aus(ro_config());
}

/** Dieselbe Liste aus einer gegebenen Konfiguration - M3 vergleicht damit
 *  den Stand vor und nach dem Speichern. */
function ro_robots_aus($cfg) {
    $out = array(); $n = 0;
    if (!is_array($cfg) || !isset($cfg['robots']) || !is_array($cfg['robots'])) { return $out; }
    foreach ((array) $cfg['robots'] as $r) {
        $r = (array) $r;
        if (trim((string) (isset($r['ip']) ? $r['ip'] : '')) === '') { continue; }
        $n++;
        // Die eigene Nummer sticht die Zaehlung; eine belegte oder unsinnige
        // Nummer faellt auf die Zaehlung zurueck, damit nie eine Zeile verschwindet.
        $nr = isset($r['nr']) ? (int) $r['nr'] : 0;
        if ($nr < 1 || $nr > 9 || isset($out[$nr])) { $nr = $n; }
        while (isset($out[$nr])) { $nr++; }
        $out[$nr] = array('nr' => $nr,
                         'name' => trim((string) (isset($r['name']) ? $r['name'] : '')) !== '' ? trim((string) $r['name']) : ('Saugroboter ' . $nr),
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
function ro_write_atomic($datei, $inhalt, $rechte = 0600) {
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

/**
 * Das Protokoll fuer die Oberflaeche - BEIDE Dateien, juengste Zeile zuerst.
 *
 * Seit 1.1.4 schreibt der Minutencron seine Schalenfehler in eine eigene
 * cron.log neben robo.log: bis 1.1.3 gingen beide in dieselbe Datei, im
 * Reiter Protokoll standen zwei Formate gemischt, und eine Kuerzung durch das
 * Plugin (ab 512 kB) konnte eine Schalenmeldung mitnehmen.
 *
 * Der Reiter muss beide zeigen - sonst waere die Trennung ein Verlust: die
 * Zeile "Cron: kein PHP gefunden" ist genau die, die man sucht, wenn nichts
 * mehr ankommt.
 */
function ro_log_lesen($max = 300)
{
    $p = ro_paths();
    $zeilen = array();
    foreach (array($p['log'], dirname($p['log']) . '/cron.log') as $datei) {
        if (!is_file($datei)) { continue; }
        foreach (ro_log_tail($datei, $max) as $z) {
            if (trim($z) !== '') { $zeilen[] = $z; }
        }
    }
    /* Beide Dateien tragen den Zeitstempel im selben Format am Zeilenanfang;
     * danach laesst sich zusammenfuehren, ohne die Zeilen zu zerlegen. */
    sort($zeilen);
    return array_slice($zeilen, -$max);
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
 *
 * C1 (Durchgang 01.10.2026): den Merker SETZT und LOESCHT nur noch der
 * Abruf von /state ($merker = true). Bis 1.1.11 tat das jeder Abruf mit
 * $dev - eine einzige langsame Nebenabfrage (Ereignisliste, Gesamtwerte)
 * schaltete einen erreichbaren Roboter fuer 60 s auf "nicht erreichbar",
 * ueber HTTP, Cron und MQTT (in WSL gemessen, Code-Pruefer Fall E6: zwei
 * Aufrufe nach der Nebenabfrage meldeten OK=0;CODE=8 ohne einen einzigen
 * Abruf). Steht der Merker, kehren alle Abrufe weiter sofort zurueck - das
 * ist der Schutz aus 1.0.3 gegen 24 s Wartezeit.
 */
function ro_get($url, $tmo = 2, $dev = 0, $merker = false) {
    if ($dev > 0 && ro_stumm($dev)) { return false; }
    $kopf = "Accept: application/json\r\n";
    if ($dev > 0) { $kopf = ro_kopfzeilen(ro_robot($dev)); }
    $ctx = stream_context_create(array('http' => array('timeout' => $tmo, 'user_agent' => 'LoxBerry Saugroboter',
        'header' => $kopf, 'ignore_errors' => true)));
    $r = @file_get_contents($url, false, $ctx);
    if ($dev > 0 && $merker) {
        if ($r === false) { ro_stumm_setzen($dev); } else { ro_stumm_loeschen($dev); }
    }
    return $r;
}

/**
 * Ein HTTP-Abruf MIT Statuscode. Rueckgabe: array(Rumpf oder false, Code;
 * 0 = keine Antwort).
 *
 * C5 (Durchgang 01.10.2026, Bauart A): ueber fopen() und
 * stream_get_meta_data() statt ueber die Kopfzeilen-Variable von PHP. PHP 8.5
 * meldet sie schon beim Uebersetzen als ueberholt - der Minutencron schrieb
 * damit jede Minute eine Zeile nach cron.log -, und PHP 9 soll sie
 * abschaffen; dann hiesse jeder Code 0 und jeder Befehl OK=0. Bauform
 * ap_http_abruf() aus APC-UPS 1.2.17. Gezaehlt wird die LETZTE Statuszeile:
 * folgt der Aufrufer einer Umleitung, ist das die des Ziels.
 */
function ro_http($url, array $http)
{
    $ctx = stream_context_create(array('http' => $http));
    $fp = @fopen($url, 'r', false, $ctx);
    if ($fp === false) {
        return array(false, 0);
    }
    $meta = @stream_get_meta_data($fp);
    $t = @stream_get_contents($fp);
    @fclose($fp);
    $code = 0;
    $kopf = (is_array($meta) && isset($meta['wrapper_data']) && is_array($meta['wrapper_data']))
        ? $meta['wrapper_data'] : array();
    foreach ($kopf as $z) {
        if (is_string($z) && preg_match('#^HTTP/\S+\s+([0-9]{3})#', $z, $m)) {
            $code = (int) $m[1];
        }
    }
    return array($t === false ? '' : (string) $t, $code);
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
    /* C5: ohne Kopfzeilen-Variable (ro_http()) und OHNE Umleitung. PHP folgt
     * einer 302 sonst mit GET und ohne Rumpf - gemessen kam der Code des
     * Ziels (204) als Erfolg zurueck, obwohl der Befehl nie ankam
     * (Code-Pruefer, t3_put.php). Eine 3xx ist jetzt ehrlich ein Fehlschlag. */
    list($antwort, $code) = ro_http($url, array(
        'method' => 'PUT', 'timeout' => $tmo, 'content' => $body, 'ignore_errors' => true,
        'follow_location' => 0,
        'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n"
                  . ($r !== null ? ro_kopfzeilen($r) : '')));
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
/**
 * Unter welchem Pfad fuehrt DIESES Geraet seine Ereignisliste?
 *
 * Bis 1.1.5 stand '/api/v2/valetudo/events' fest im Quelltext, mit dem
 * Kommentar "sie liegt NICHT unter /robot, sondern unter /valetudo". Am
 * 07.09.2026 am Geraet nachgemessen (Roborock S5, Valetudo 2026.05.0):
 *
 *     /api/v2/valetudo/events   -> HTTP 404
 *     /api/v2/events            -> HTTP 200, eine offene Meldung
 *
 * Der Rueckgabewert ist der Teil HINTER /api/v2/ - beide Aufrufstellen
 * (Liste lesen, Ereignis quittieren) setzen ihn an dieselbe Wurzel an.
 * Gemerkt wird, welcher Pfad getragen hat, damit nicht jeder Seitenaufbau
 * zweimal fragt.
 */
function ro_ereignis_pfad($dev = 1, $setzen = null)
{
    static $pfad = array();
    $dev = (int) $dev;
    if ($setzen !== null) { $pfad[$dev] = (string) $setzen; }
    return isset($pfad[$dev]) ? $pfad[$dev] : '';
}

/**
 * Die Ereignisliste holen - oder null.
 *
 * null heisst NICHT "keine Ereignisse", sondern "nicht gelesen". Genau
 * dieser Unterschied ist bis 1.1.5 verlorengegangen: der Aufrufer sah eine
 * leere Schleife und schrieb EVENT=0 nach Loxone. Wer die Liste nicht lesen
 * kann, sagt es - im Reiter Test steht die Zeile dazu.
 */
function ro_ereignisse($dev = 1)
{
    $r = ro_robot($dev);
    if ($r === null) { return null; }
    $wurzel = 'http://' . $r['ip'] . ':' . $r['port'] . '/api/v2/';
    $pfade = array('events', 'valetudo/events');
    $gemerkt = ro_ereignis_pfad($dev);
    if ($gemerkt !== '' && $gemerkt !== $pfade[0]) { array_unshift($pfade, $gemerkt); }
    foreach ($pfade as $p) {
        $j = @json_decode((string) ro_get($wurzel . $p, 2, $dev), true);
        /* is_array() REICHT HIER NICHT, und das ist bei der Eichung
         * aufgefallen: der 404-Rumpf eines Valetudo ohne diesen Pfad ist
         * gueltiges JSON ({"error":"Not Found"}), json_decode macht daraus
         * ein Feld, und der Rueckfall waere nie gelaufen - die Fehlermeldung
         * selbst haette als Ereignisliste gegolten. Verlangt wird eine
         * LISTE: fortlaufende Zahlenschluessel. Eine leere Liste ist eine
         * gueltige Antwort und heisst "keine Ereignisse". */
        if (ro_ist_liste($j)) {
            ro_ereignis_pfad($dev, $p);
            return $j;
        }
    }
    return null;
}

/** Eine JSON-LISTE (fortlaufende Zahlenschluessel) - kein Objekt. */
function ro_ist_liste($j)
{
    if (!is_array($j)) { return false; }
    if ($j === array()) { return true; }
    return array_keys($j) === range(0, count($j) - 1);
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

/* ==================================================================
 * Ausfall des Roboters: der letzte Messwert bleibt (C3, Entscheidung Nr. 28)
 * ==================================================================
 *
 * Bis 1.1.11 trug ro_state() bei einem Ausfall Platzhalter: CODE=8, BATT=0,
 * FILTER=-1 ... - eine Logik "Batterie < 20 %" loeste bei jedem Funkloch aus
 * (Code-Pruefer Fall E7, MQTT-Pruefer Fall s07). Entscheidung Nr. 28 vom
 * 01.10.2026: die Zustaende bleiben auf dem letzten Messwert, OK geht auf 0,
 * und allein CODE 8 ("nicht erreichbar") geht als benannte Ausnahme hinaus -
 * an ihm haengen in der Anlage drei Schwellwertschalter. Endpunkt und MQTT
 * verhalten sich gleich: der Endpunkt nennt die letzten Werte, MQTT sendet die
 * Geraetewerte gar nicht (der Broker behaelt den letzten Stand).
 *
 * Der letzte erfolgreiche Zustand liegt in /tmp/<ordner>/gemessen_N.json,
 * zusammen mit der Adresse, unter der er gemessen wurde: nach einem Wechsel
 * der Roboteradresse gilt er nicht mehr. Gab es noch keine Messung, bleiben
 * die Platzhalter - es gibt dann keinen Wert, der stehen bleiben koennte.
 *
 * Teilausfall: liefert eine Nebenabfrage keine Liste, kommt ihre Gruppe aus
 * derselben Datei (ro_teil_uebernehmen()); ueber MQTT geht sie nicht hinaus.
 */
function ro_teil_felder()
{
    return array(
        'statistik' => array('flaeche', 'dauer'),
        'gesamt'    => array('flaeche_gesamt', 'dauer_gesamt', 'anzahl_gesamt'),
        'verbrauch' => array('buerste_haupt', 'buerste_seite', 'buerste_seite2', 'filter', 'filter2',
                             'sensor', 'raeder', 'mop', 'dock_buerste', 'dock_filter', 'dock_behaelter',
                             'reiniger', 'material_fremd'),
        'ereignisse' => array('event', 'evtyp', 'evtext', 'evmuell', 'evid'),
    );
}

/** Die Adresse, unter der ein Zustand gemessen wurde - fuer gemessen_N.json. */
function ro_gemessen_adresse($r)
{
    return is_array($r) ? ((string) $r['ip'] . ':' . (int) $r['port']) : '';
}

/** Der letzte erfolgreich gemessene Zustand dieses Roboters, oder null. */
function ro_gemessen_lesen($dev, $r)
{
    $f = ro_tmpdir() . '/gemessen_' . (int) $dev . '.json';
    if ($r === null || !is_file($f)) { return null; }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!is_array($d) || !isset($d['adresse'], $d['st']) || !is_array($d['st'])
        || (string) $d['adresse'] !== ro_gemessen_adresse($r) || !isset($d['st']['ts'])) {
        return null;
    }
    return $d['st'];
}

/** Ausfall: die Werte des letzten erfolgreichen Abrufs, OK=0, CODE=8. */
function ro_state_ausfall(array $platz, $vorher)
{
    if (!is_array($vorher)) { return $platz; }
    $aus = $vorher;
    foreach ($platz as $k => $w) {
        if (!array_key_exists($k, $aus)) { $aus[$k] = $w; }
    }
    $aus['ok'] = 0;
    $aus['code'] = 8;
    $aus['text'] = ro_state_text(8);
    $aus['name'] = $platz['name'];
    $aus['messung_ts'] = (int) $vorher['ts'];
    $aus['ts'] = $platz['ts'];
    return $aus;
}

/** Teilausfall: die Felder einer Gruppe aus der letzten Messung uebernehmen. */
function ro_teil_uebernehmen(array $st, $vorher, $gruppe)
{
    if (!is_array($vorher)) { return $st; }
    $felder = ro_teil_felder();
    foreach ($felder[$gruppe] as $k) {
        if (array_key_exists($k, $vorher)) { $st[$k] = $vorher[$k]; }
    }
    return $st;
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
    // C3: der letzte erfolgreich gemessene Zustand (fuer Ausfall und Teilausfall).
    $vorher = ro_gemessen_lesen($dev, $r);
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
                'evlesbar' => 0,
                // Welche Nebenabfragen eine LISTE geliefert haben (siehe
                // ro_mqtt_platzhalter()); 0 = Platzhalter.
                'teil_ok' => array('statistik' => 0, 'gesamt' => 0, 'verbrauch' => 0),
                // C3: wann die Werte gemessen wurden (bei Ausfall: die letzte Messung).
                'messung_ts' => 0,
                'ts' => time());
    if ($r === null) {
        return $st;
    }
    $base = 'http://' . $r['ip'] . ':' . $r['port'] . '/api/v2/robot';
    // 1) Status - und mit ihm Anbauteile, Ladestation und die Stufen.
    //    Alle drei stecken in DERSELBEN Antwort und wurden bis 1.0.14
    //    weggeworfen; sie kosten keinen zusaetzlichen Abruf.
    // C1: nur dieser Abruf setzt und loescht den Stumm-Merker.
    $j = @json_decode((string) ro_get($base . '/state', 2, $dev, true), true);
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
        // C3 (Nr. 28): die letzten Messwerte, OK=0, CODE=8.
        $st = ro_state_ausfall($st, $vorher);
        ro_write_json($cache, $st);
        ro_log_if_changed('status_' . $dev, 'Status=' . $st['text'] . ' (nicht erreichbar)');
        return $st;
    }

    // 2) Statistik aktuell
    $j = @json_decode((string) ro_get($base . '/capabilities/CurrentStatisticsCapability', 2, $dev), true);
    $st['teil_ok']['statistik'] = ro_ist_liste($j) ? 1 : 0;
    foreach ((array) $j as $e) {
        if (!isset($e['type']) || !isset($e['value'])) { continue; }
        if ($e['type'] === 'area') { $st['flaeche'] = round(((float) $e['value']) / 10000, 1); }   // cm2 -> m2
        if ($e['type'] === 'time') { $st['dauer'] = (int) round(((float) $e['value']) / 60); }      // s -> min
    }
    // 3) Statistik gesamt
    $j = @json_decode((string) ro_get($base . '/capabilities/TotalStatisticsCapability', 2, $dev), true);
    $st['teil_ok']['gesamt'] = ro_ist_liste($j) ? 1 : 0;
    foreach ((array) $j as $e) {
        if (!isset($e['type']) || !isset($e['value'])) { continue; }
        if ($e['type'] === 'area') { $st['flaeche_gesamt'] = round(((float) $e['value']) / 10000, 1); }
        if ($e['type'] === 'time') { $st['dauer_gesamt'] = round(((float) $e['value']) / 3600, 1); } // s -> h
        if ($e['type'] === 'count') { $st['anzahl_gesamt'] = (int) $e['value']; }
    }
    // 4) Verbrauchsmaterialien
    $j = @json_decode((string) ro_get($base . '/capabilities/ConsumableMonitoringCapability', 2, $dev), true);
    $st['teil_ok']['verbrauch'] = ro_ist_liste($j) ? 1 : 0;
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
    /* C3, Teilausfall: was nicht als Liste kam, kommt aus der letzten
     * Messung - VOR der Warnschwelle, damit MATWARN zu den Werten passt. */
    foreach (array('statistik', 'gesamt', 'verbrauch') as $g) {
        if (empty($st['teil_ok'][$g])) { $st = ro_teil_uebernehmen($st, $vorher, $g); }
    }
    $prozent = ro_verbrauch_prozentfelder();
    foreach (array('buerste_haupt', 'buerste_seite', 'buerste_seite2', 'filter', 'filter2',
                   'sensor', 'raeder', 'mop', 'dock_buerste', 'dock_filter',
                   'dock_behaelter', 'reiniger') as $k) {
        $grenze = in_array($k, $prozent, true) ? $warn_p : $warn_h;
        if ($st[$k] >= 0 && $st[$k] <= $grenze) { $st['material_warn'] = 1; }
    }
    // 5) Valetudos Ereignisliste. WELCHER Pfad gilt, entscheidet das Geraet -
    //    siehe ro_ereignisse(). evlesbar trennt "keine Ereignisse" von
    //    "nicht gelesen"; bis 1.1.5 war das dasselbe, und zwar stumm.
    $ev = ro_ereignisse($dev);
    $st['evlesbar'] = is_array($ev) ? 1 : 0;
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

    // C3, Teilausfall: die Ereignisse der letzten Messung, wenn die Liste nicht lesbar war.
    if (empty($st['evlesbar'])) { $st = ro_teil_uebernehmen($st, $vorher, 'ereignisse'); }

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
    // C3: der letzte erfolgreiche Zustand, mit der Adresse, unter der er gemessen wurde.
    $st['messung_ts'] = $st['ts'];
    ro_write_json(ro_tmpdir() . '/gemessen_' . $dev . '.json',
        array('adresse' => ro_gemessen_adresse($r), 'st' => $st));
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
                $alt = ro_ruhezeit($dev, true);   // vor dem Schreiben frisch lesen
                $von = is_array($alt) ? $alt['start'] : array('hour' => 22, 'minute' => 0);
                $bis = is_array($alt) ? $alt['end'] : array('hour' => 7, 'minute' => 0);
                list($code, $body) = ro_put($base . 'DoNotDisturbCapability',
                    array('enabled' => false, 'start' => $von, 'end' => $bis), 4, $r);
                break;
            }
            /* Das Muster laesst nur echte Uhrzeiten durch, und die Zahlen gehen
             * danach UNVERAENDERT hinaus. Bis 1.1.3 hiess es ([0-2]?\d) und traf
             * damit auch 24 bis 29; das min(23, ...) dahinter bog den Wert dann
             * still zurecht. Gemessen am 04.09.2026 gegen eine zaehlende
             * Gegenstelle: ?cmd=ruhezeit&p=29:30-07:00 meldete CMD;OK=1 und
             * stellte am Geraet 23:30 ein. "Eingaben werden abgewiesen und
             * gemeldet, nie still zurechtgebogen." */
            if (!preg_match('/^([01]\d|2[0-3]|\d):([0-5]\d)-([01]\d|2[0-3]|\d):([0-5]\d)$/', $param, $m)) {
                return array(0, 'ruhezeit braucht HH:MM-HH:MM (00:00 bis 23:59) oder "aus"');
            }
            list($code, $body) = ro_put($base . 'DoNotDisturbCapability', array(
                'enabled' => true,
                'start' => array('hour' => (int) $m[1], 'minute' => (int) $m[2]),
                'end'   => array('hour' => (int) $m[3], 'minute' => (int) $m[4])), 4, $r);
            break;
        case 'evquittieren': // offenes Ereignis wegdruecken
            $st = ro_state($dev);
            $id = $param !== '' ? $param : (string) $st['evid'];
            /* UNTERSTRICH GEHOERT DAZU. Die Wache liess bis 1.1.5 nur
             * [A-Za-z0-9-] durch; am 07.09.2026 am Geraet gelesen heisst eine
             * echte Kennung "consumable_depleted_cleaning_sensor". Der Befehl
             * haette sie mit "kein quittierbares Ereignis" abgewiesen - und
             * zwar genau die Ereignisse, um die es geht. */
            if ($id === '' || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $id)) {
                return array(0, 'kein quittierbares Ereignis');
            }
            /* Derselbe Pfad wie beim Lesen. Steht er noch nicht fest, wird
             * die Liste einmal geholt - sonst ginge der PUT an eine Adresse,
             * die dieses Geraet gar nicht kennt. */
            $evpfad = ro_ereignis_pfad($dev);
            if ($evpfad === '') {
                ro_ereignisse($dev);
                $evpfad = ro_ereignis_pfad($dev);
            }
            if ($evpfad === '') { $evpfad = 'events'; }
            list($code, $body) = ro_put($wurzel . $evpfad . '/' . rawurlencode($id) . '/interact',
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
        if ($cmd === 'ruhezeit') { @unlink(ro_tmpdir() . '/dnd_' . (int) $dev . '.json'); }
    }
    return array($ok, 'HTTP ' . $code);
}

/* ---------------- Gleichwert-Unterdrueckung fuer Sollwerte (X-7) ----------------
 *
 * C4 (Durchgang 01.10.2026, Entscheidungen Nr. 19 und 28): derselbe Sollwert
 * fuer denselben Roboter innerhalb von 60 s geht nicht noch einmal an
 * Valetudo - der Endpunkt antwortet HTTP 200 mit UNVERAENDERT=1. Kein 429:
 * ein anderer Wert geht sofort hinaus.
 *
 * Gebremst werden NUR die Sollwerte fan, wasser, modus und ruhezeit,
 * verglichen mit dem zuletzt GESENDETEN Wert (Nr. 28). Start, Stopp, Pause,
 * Heim und die Raum- und Zonenauftraege sind Auftraege: wer nach einem Stopp
 * am Geraet erneut "start" schickt, will, dass er wirkt. locate, goto, reset,
 * absaugen, wisch* und evquittieren sind Ereignisse. Bis 1.1.11 gab es keine
 * Bremse: zweimal ?cmd=fan&p=max ergab zwei PUTs (Code-Pruefer Fall E3).
 *
 * Merker unter flock, geoeffnet mit "e" (close-on-exec), faellt geschlossen
 * aus (503). Der Befehl wird VOR dem Senden vorgemerkt; scheitert er (OK=0),
 * wird der Eintrag wieder verworfen. Bauform BYD Autos 0.9.22.
 */
define('RO_GLEICHWERT_S', 60);

/** Pfad des Merkers. */
function ro_gleichwert_datei()
{
    return ro_tmpdir() . '/gleichwert.json';
}

/** Der Vergleichswert eines Sollwert-Befehls, oder null (nicht gebremst). */
function ro_gleichwert_wert($cmd, $param)
{
    if (!in_array($cmd, array('fan', 'wasser', 'modus', 'ruhezeit'), true)) { return null; }
    return strtolower(trim((string) $param));
}

/**
 * Vor dem Senden. Rueckgabe: array(Urteil, Sekunden, Marke).
 *   'UNVERAENDERT' - derselbe Wert ging vor weniger als 60 s hinaus;
 *   'MERKER'       - der Merker laesst sich nicht oeffnen, sperren oder
 *                    schreiben: geschlossen ausfallen;
 *   ''             - senden; der Befehl ist dann unter der Marke vorgemerkt.
 */
function ro_gleichwert_pruefen($schluessel, $wert)
{
    $fh = @fopen(ro_gleichwert_datei(), 'c+e');
    if ($fh === false || !@flock($fh, LOCK_EX)) {
        if (is_resource($fh)) { fclose($fh); }
        return array('MERKER', 0, '');
    }
    $m = json_decode((string) stream_get_contents($fh), true);
    if (!is_array($m)) { $m = array(); }    // unlesbar gilt als leer: im Zweifel senden
    $jetzt = time();
    if (isset($m[$schluessel]) && is_array($m[$schluessel]) && isset($m[$schluessel]['w'], $m[$schluessel]['t'])
        && is_scalar($m[$schluessel]['w']) && is_scalar($m[$schluessel]['t'])) {
        $seit = $jetzt - (int) $m[$schluessel]['t'];
        if ($seit >= 0 && $seit < RO_GLEICHWERT_S && (string) $m[$schluessel]['w'] === (string) $wert) {
            flock($fh, LOCK_UN);
            fclose($fh);
            return array('UNVERAENDERT', $seit, '');
        }
    }
    foreach ($m as $k => $e) {
        if (!is_array($e) || !isset($e['t']) || !is_scalar($e['t'])
            || $jetzt - (int) $e['t'] >= RO_GLEICHWERT_S || (int) $e['t'] > $jetzt) {
            unset($m[$k]);
        }
    }
    $marke = bin2hex(random_bytes(6));
    $m[$schluessel] = array('w' => (string) $wert, 't' => $jetzt, 'm' => $marke);
    $js = json_encode($m);
    $ok = $js !== false && ftruncate($fh, 0) && rewind($fh)
          && fwrite($fh, $js) === strlen($js) && fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    if (!$ok) { return array('MERKER', 0, ''); }
    return array('', 0, $marke);
}

/** Einen Eintrag verwerfen (nach OK=0) - nur den eigenen (Marke). */
function ro_gleichwert_vergessen($schluessel, $marke)
{
    $f = ro_gleichwert_datei();
    clearstatcache(true, $f);
    if (!is_file($f)) { return true; }
    $fh = @fopen($f, 'c+e');
    if ($fh === false || !@flock($fh, LOCK_EX)) {
        if (is_resource($fh)) { fclose($fh); }
        return false;
    }
    $m = json_decode((string) stream_get_contents($fh), true);
    $ok = true;
    if (is_array($m) && isset($m[$schluessel]['m']) && (string) $m[$schluessel]['m'] === (string) $marke) {
        unset($m[$schluessel]);
        $js = json_encode($m);
        $ok = $js !== false && ftruncate($fh, 0) && rewind($fh)
              && fwrite($fh, $js) === strlen($js) && fflush($fh);
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
}

/** Die Befehle, die ?cmd= annimmt - EINE Liste fuer Endpunkt, Vorlage und Anleitung. */
function ro_befehle()
{
    /* U12 (Durchgang 01.10.2026): die Erklaerspalte [1] kommt aus der
     * Sprachdatei ([BEFEHL]); der deutsche Text hier bleibt nur als
     * Rueckfall. Bis 1.1.11 stand er in der englischen Oberflaeche deutsch da
     * (Oberflaechen-Pruefer, LBLANG=en). Die Spalte [2] ist der Kachelname
     * der Vorlage und bleibt deutsch wie die Vorlage selbst. */
    $b = array(
        'start'         => array('', 'Reinigung starten', 'starten'),
        'stop'          => array('', 'Stoppen', 'stoppen'),
        'pause'         => array('', 'Pausieren', 'pausieren'),
        'home'          => array('', 'Zur Ladestation', 'zur Ladestation'),
        'locate'        => array('', 'Roboter piepsen lassen', 'piepsen lassen'),
        'segments'      => array('1,4', 'Nur bestimmte Räume reinigen (IDs im Reiter Test; "1,4x2" = zwei Durchgänge)', 'Räume reinigen'),
        'zone'          => array('2000,2000,3000,3000', 'Zone reinigen, X1,Y1,X2,Y2 in Kartenkoordinaten', 'Zone reinigen'),
        'goto'          => array('2500,1800', 'Position anfahren, X,Y in Kartenkoordinaten', 'Position anfahren'),
        'fan'           => array('max', 'Saugstärke: off, min, low, medium, high, max, turbo', 'Saugstärke setzen'),
        'wasser'        => array('low', 'Wischwassermenge: off, min, low, medium, high, max', 'Wischwasser setzen'),
        'modus'         => array('vacuum', 'Betriebsart: vacuum, mop, vacuum_and_mop, vacuum_then_mop', 'Betriebsart setzen'),
        'absaugen'      => array('', 'Absaugstation von Hand auslösen', 'absaugen'),
        'wischwaschen'  => array('', 'Wischmodul in der Station waschen', 'Wischmodul waschen'),
        'wischtrocknen' => array('', 'Wischmodul in der Station trocknen', 'Wischmodul trocknen'),
        'reset'         => array('filter/main', 'Verbrauchsteil zurücksetzen (filter/main, brush/main, brush/side_right, cleaning/sensor, mop/all)', 'Teil zurücksetzen'),
        'ruhezeit'      => array('22:00-07:00', 'Nicht-stören-Zeit setzen; "aus" schaltet sie ab', 'Ruhezeit setzen'),
        'evquittieren'  => array('', 'Offenes Valetudo-Ereignis wegdrücken', 'Ereignis quittieren'),
    );
    foreach ($b as $name => $z) {
        $t = ro_t('BEFEHL.' . strtoupper($name));
        if ($t !== 'BEFEHL.' . strtoupper($name)) { $b[$name][1] = $t; }
    }
    return $b;
}

/**
 * Nicht-stoeren-Zeit lesen. Valetudo: DoNotDisturbCapability, GET /
 *
 * MIT Zwischenspeicher, seit 1.1.4. Die Selbstpruefung steht im Reiter Test,
 * der Reiter Test wird aber bei JEDEM Seitenaufbau mitgerendert - auch beim
 * Reiter Protokoll. Gemessen am 04.09.2026 gegen eine zaehlende Gegenstelle:
 * erster Seitenaufruf 10 Abrufe, jeder weitere genau EINER, und das war
 * dieser hier. ro_state() (cache_sec), ro_segments() und ro_capabilities()
 * (je eine Stunde) puffern; diese eine Abfrage tat es als einzige nicht.
 */
function ro_ruhezeit($dev = 1, $force = false)
{
    $dev = max(1, (int) $dev);
    $cache = ro_tmpdir() . '/dnd_' . $dev . '.json';
    if (!$force && is_file($cache) && time() - filemtime($cache) < 300) {
        $c = json_decode((string) @file_get_contents($cache), true);
        return is_array($c) && array_key_exists('d', $c) ? $c['d'] : null;
    }
    $r = ro_robot($dev);
    if ($r === null) { return null; }
    $j = @json_decode((string) ro_get('http://' . $r['ip'] . ':' . $r['port']
        . '/api/v2/robot/capabilities/DoNotDisturbCapability', 2, $dev), true);
    $j = is_array($j) ? $j : null;
    /* Auch das Nichtergebnis wird gemerkt - sonst fragt jeder Seitenaufbau
     * erneut, und bei einem stummen Geraet kostet das je Aufruf zwei Sekunden. */
    ro_write_json($cache, array('d' => $j));
    return $j;
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
 * Welche Themen gehen ZURUECKBEHALTEN (retained) hinaus? Eine POSITIVLISTE.
 *
 * EINE Stelle fuer die Entscheidung: Sender, Thementabelle im Reiter MQTT
 * und die Deinstallation fragen dieselbe Funktion. Die Eintraege stehen in
 * der Feldtabelle (ro_felder(), Spalte 5, nur der Wert 1 zaehlt) und hier
 * fuer die Klartexte. Was in keiner der beiden Stellen steht, geht
 * FLUECHTIG hinaus.
 *
 * Bis 1.1.9 war es umgekehrt: ro_mqtt_retain_feld() gab fuer jeden Namen,
 * den die Feldtabelle nicht kannte, "retained" zurueck, und die vier
 * Klartexte standen fest auf retained - ein neues Thema waere still
 * zurueckbehalten worden (Bestandsliste Klasse E vom 19.09.2026). Am Sender
 * der Archive 1.1.4 bis 1.1.9 gemessen: je Roboter 40 Themen retained
 * (Pruefung-Saugroboter-Valetudo-1.1.10/themen_je_fassung.txt).
 *
 * Die Frage je Thema (Regeln/07, Entscheidungen vom 18. und 19.09.2026):
 * Wer sagt das - das Geraet, oder das Plugin ueber sich selbst? Und wird
 * der Wert allein durch den Lauf der Uhr falsch?
 *   ok            "Roboter erreichbar" - das Ergebnis der EIGENEN Abfrage,
 *                 also eine Aussage des Plugins ueber sich. Stirbt der
 *                 Minutenlauf, stuende die 1 nach jedem Neustart von Broker
 *                 oder Gateway wieder da. Nie retained.
 *   fehlertext,   regelmaessig LEER. Ein leerer Wert geht nie retained
 *   ereignistext  hinaus (er loeschte das Thema), also ersetzte nichts den
 *                 zurueckbehaltenen Text: "Rad blockiert" stand nach dem
 *                 Beheben weiter im Broker (in WSL gemessen,
 *                 Pruefung-Saugroboter-Valetudo-1.1.10, Fall R7).
 *   meldung       ebenso, und nach 24 Stunden allein durch die Uhr leer
 *                 (ro_meldung_lesen()).
 * Zurueckbehalten bleiben die Aussagen des GERAETS: Zustand, Fehlercode
 * und -schwere, Verbrauchsteile, Gesamtwerte, Anbauteile, Station, Stufen,
 * Ereigniszahl und -art, dazu die Freigaben aus der Konfiguration und der
 * Zustand als Klartext (status, nie leer).
 *
 * Preis: nach einem Neustart von Broker oder Gateway fehlen die
 * fluechtigen Themen, bis der naechste volle Satz hinausgeht (spaetestens
 * nach 30 Minuten, bin/cron.php). Die Altwerte der Vorfassungen raeumt
 * ro_mqtt_altlast() ab.
 */
function ro_mqtt_retain_liste()
{
    static $liste = null;
    if ($liste !== null) { return $liste; }
    $liste = array();
    foreach (ro_felder() as $name => $f) {
        if (ro_mqtt_ausgenommen($name)) { continue; }
        if (isset($f[5]) && $f[5] === 1) { $liste[strtolower($name)] = 1; }
    }
    // Von den vier Klartexten nur der Zustand - er ist nie leer.
    $liste['status'] = 1;
    return $liste;
}

/**
 * Die LANGFORM von sechs Themen - zusaetzlich zur Kurzform, mit demselben
 * Wert und derselben Retain-Regel.
 *
 * In einer bestehenden Anlage (Projektdatei gelesen am 25.09.2026,
 * Pruefung-Saugroboter-Valetudo-1.1.10/abnehmer_anlage.txt)
 * abonnieren sechs virtuelle Eingaenge diese Namen - saugrobo_batterie,
 * saugrobo_buerste_haupt, ... -, gesendet wurde aber seit mindestens 1.1.2
 * nur die Kurzform (batt, bhaupt, ...; am Sender gemessen, themen_je_fassung.txt).
 * An zwei der Eingaenge haengen Zustandsbausteine ("Wechsel Hauptbuerste in:",
 * "Wechsel Seitenbuerste in:"). Bestehende Namen werden nicht umbenannt
 * (Regeln/07); die Langform kommt DANEBEN und gilt fuer bestehende
 * Loxone-Vorlagen. Die eigene Importvorlage des Plugins traegt keine
 * MQTT-Namen (nur HTTP: ROBO_<FELD>, ;<FELD>=).
 *
 * Rueckgabe: langform => kurzform.
 */
function ro_mqtt_langform()
{
    return array('batterie' => 'batt', 'buerste_haupt' => 'bhaupt', 'buerste_seite' => 'bseite',
                 'dauer_gesamt' => 'dauerg', 'flaeche_gesamt' => 'flaecheg',
                 'material_warn' => 'matwarn');
}

/**
 * Geht dieses Thema (ohne Praefix, etwa "code") zurueckbehalten hinaus?
 *
 * DASS DER WEG DAS KANN, IST GEMESSEN - am Quelltext der Gegenstelle, nicht
 * geraten. LoxBerry-Kern, sbin/mqttgateway.pl (Zweig master, abgerufen
 * 05.09.2026):
 *
 *   Zeile 227   # "retain my/topic data" .... Publish a message with retain
 *   Zeile 293   if(lc($command) ne 'publish' and lc($command) ne 'retain' ...
 *   Zeile 354   } elsif($command eq 'retain') { ... $mqtt->retain(...) }
 *
 * UND EINE FALLE AUS DERSELBEN QUELLE, Zeile 360-364: ein retain mit LEEREM
 * Wert LOESCHT das Thema. $wert wird deshalb mitgegeben, wo er feststeht:
 * ein leerer Wert geht immer als publish hinaus.
 */
function ro_mqtt_retain($thema, $wert = null)
{
    if ($wert !== null && ro_mqtt_wert_saeubern($wert) === '') { return false; }
    // Die Langform folgt der Regel ihrer Kurzform (ro_mqtt_langform()).
    $lf = ro_mqtt_langform();
    if (isset($lf[(string) $thema])) { $thema = $lf[(string) $thema]; }
    $l = ro_mqtt_retain_liste();
    return isset($l[(string) $thema]);
}

/**
 * Eine Zeile fuer den UDP-Eingang bauen - EINE Stelle fuer publish und retain.
 *
 * Ein leerer Wert wird NIE retained gesendet (siehe oben): er wuerde das Thema
 * loeschen statt es zu setzen. In dem Fall geht die Zeile als publish hinaus,
 * und der virtuelle Eingang behaelt seinen letzten Wert.
 */
function ro_mqtt_zeile($retain, $thema, $wert)
{
    $w = ro_mqtt_wert_saeubern($wert);
    $befehl = ($retain && $w !== '') ? 'retain' : 'publish';
    return $befehl . ' ' . $thema . ' ' . $w;
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
    /* M4 (Durchgang 01.10.2026): mindestens 5 ms zwischen zwei Datagrammen -
     * auch ueber mehrere Aufrufe in einem Lauf (Roboter 1, Roboter 2,
     * Lebenszeichen). Der UDP-Eingang des Gateways verwirft am Geraet
     * stossweise 17-70 % (Regeln/07); ohne Pause gingen 104 Datagramme in
     * 49 ms hinaus, Median-Abstand 0,00 ms (MQTT-Pruefer Fall s16). Bauart
     * Fensterbilanz 0.12.9 / Marstek 1.1.17. */
    static $letzt = 0.0;
    foreach ((array) $zeilen as $z) {
        $warte = 0.005 - (microtime(true) - $letzt);
        if ($warte > 0) { usleep((int) ceil($warte * 1000000)); }
        if (@fwrite($fp, $z) !== false) { $n++; }
        $letzt = microtime(true);
    }
    @fclose($fp);
    return $n;
}

/** Der UDP-Eingangsport des Gateways aus der general.json - 0, wenn keiner da ist. */
function ro_mqtt_udpport()
{
    $p = ro_paths();
    if ($p['lbhome'] === '') { return 0; }
    $gen = @json_decode((string) @file_get_contents($p['general']), true);
    $udp = 0;
    if (isset($gen['Mqtt']['Udpinport'])) { $udp = (int) $gen['Mqtt']['Udpinport']; }
    if (!$udp && isset($gen['mqtt']['udpinport'])) { $udp = (int) $gen['mqtt']['udpinport']; }
    return ($udp > 0 && $udp <= 65535) ? $udp : 0;
}

/** Das Praefix eines Roboters: Roboter 1 behaelt die kurzen Themen. */
function ro_mqtt_praefix($wurzel, $dev = 1)
{
    return $wurzel . ((int) $dev > 1 ? '/' . (int) $dev : '');
}

/**
 * Die Themen, die frueher zurueckbehalten hinausgingen und es heute nicht
 * mehr tun. Die Liste ist die der Archive 1.1.4 bis 1.1.9, gemessen am
 * 25.09.2026 am Sender (Pruefung-Saugroboter-Valetudo-1.1.10,
 * themen_je_fassung.txt; 1.1.2 und 1.1.3 sandten nichts retained); abgezogen
 * wird, was heute noch in ro_mqtt_retain_liste() steht. Ihre Altwerte stehen
 * auf bestehenden Anlagen im Broker, bis jemand sie loescht - ein spaeteres
 * publish ersetzt einen zurueckbehaltenen Wert nicht.
 */
function ro_mqtt_frueher_behalten()
{
    $frueher = array(
        'ok', 'code', 'laedt', 'fehler', 'fstufe', 'fteil', 'flaeche', 'dauer',
        'flaecheg', 'dauerg', 'anzahlg', 'filter', 'filter2', 'bhaupt', 'bseite',
        'bseite2', 'sensor', 'raeder', 'mop', 'dockfilter', 'dockbuerste',
        'dockbehaelter', 'reiniger', 'matwarn', 'behaelter', 'wassertank', 'wischer',
        'dock', 'saugst', 'wasser', 'modus', 'event', 'evtyp', 'evmuell', 'audio',
        'push', 'status', 'fehlertext', 'ereignistext', 'meldung',
    );
    return array_values(array_diff($frueher, array_keys(ro_mqtt_retain_liste())));
}

/**
 * Alle Themen, die diese Linie je zurueckbehalten gesendet hat - fuer die
 * Deinstallation: die heutige Positivliste und die frueheren Eintraege.
 */
function ro_mqtt_leer_themen()
{
    $t = array();
    foreach (ro_mqtt_frueher_behalten() as $k) { $t[$k] = true; }
    foreach (array_keys(ro_mqtt_retain_liste()) as $k) { $t[$k] = true; }
    // Die Langform (seit 1.1.10, retained wie ihre Kurzform ausser batterie).
    foreach (array_keys(ro_mqtt_langform()) as $k) { $t[$k] = true; }
    ksort($t);
    return array_keys($t);
}

/**
 * Den Broker fragen, welche der Themen $themen er zurueckbehaelt - in EINER
 * Verbindung.
 *
 * Rueckgabe array('lage' => 'ok'|'unbekannt', 'belegt' => array(thema => true)).
 * 'ok' heisst: der Broker hat JEDES Abonnement bestaetigt; was dann nicht
 * unter 'belegt' steht, ist leer. 'unbekannt': er war nicht zu fragen
 * (keine Wurzel, keine Verbindung, Anmeldung abgewiesen, Abonnement
 * abgelehnt, keine Antwort) - das heisst NIE "nichts belegt".
 *
 * Warum ueberhaupt fragen: gesendet wird ueber den UDP-Eingang des Gateways,
 * und dort meldet sendto() auch fuer ein verworfenes Datagramm Erfolg. Am
 * Geraet gemessen (Regeln/07, "Ein Absender merkt nichts davon", Nachtraege
 * vom 19.09.2026): Beschattungswaechter 0.9.19 und KODI-NG 1.2.7 setzten
 * ihren Merker nach dem Senden, der Eingang verwarf, und der Altwert stand
 * weiter im Broker. Belegt ist das Abraeumen erst, wenn der Broker selbst
 * sagt, dass nichts mehr dasteht.
 *
 * MQTT 3.1.1 von Hand, nur CONNECT, SUBSCRIBE (QoS 0) und DISCONNECT - ohne
 * fremde Bibliothek; Bauart awm_mqtt_behalten_liste() aus AWM-Abfuhr 1.4.14
 * (dort aus Spotpreis-Tibber 0.9.19 und Beschattungswaechter 0.9.21). Die
 * Filter gehen in Paketen zu hoechstens 50 hinaus (die Deinstallation fragt
 * bis zu 360 Themen), und 'ok' verlangt fuer JEDES Paket ein SUBACK mit
 * dessen Paketkennung, so vielen Rueckgabebytes wie Filtern und keinem ab
 * 0x80. Die Anmeldung nimmt Brokeruser/Brokerpass aus der general.json
 * (Regeln/07, Abschnitt 2); das Kennwort steht nur im CONNECT-Paket, nie in
 * einem Protokoll und nie auf einer Kommandozeile.
 */
function ro_mqtt_behalten_liste(array $themen)
{
    $aus = array('lage' => 'unbekannt', 'belegt' => array());
    $soll = array();
    foreach ($themen as $t) {
        if ((string) $t !== '') { $soll[(string) $t] = true; }
    }
    if (!$soll) {
        $aus['lage'] = 'ok';
        return $aus;
    }
    $p = ro_paths();
    if ($p['lbhome'] === '' || !is_file($p['general'])) { return $aus; }
    $gen = json_decode((string) @file_get_contents($p['general']), true);
    if (!is_array($gen)) { return $aus; }
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) { $m = $gen['Mqtt']; }
    elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) { $m = $gen['mqtt']; }
    if (!$m) { return $aus; }
    $hol = function ($gross, $klein) use ($m) {
        if (isset($m[$gross])) { return (string) $m[$gross]; }
        return isset($m[$klein]) ? (string) $m[$klein] : '';
    };
    $host = trim($hol('Brokerhost', 'brokerhost'));
    if ($host === '' || $host === 'localhost') { $host = '127.0.0.1'; }
    $port = (int) $hol('Brokerport', 'brokerport');
    if ($port <= 0 || $port > 65535) { $port = 1883; }
    $benutzer = $hol('Brokeruser', 'brokeruser');
    $kennwort = $hol('Brokerpass', 'brokerpass');

    $s = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 2);
    if (!$s) { return $aus; }
    stream_set_timeout($s, 1);

    $zk = function ($t) { return pack('n', strlen($t)) . $t; };
    $laenge = function ($n) {
        $o = '';
        do {
            $b = $n % 128;
            $n = intdiv($n, 128);
            if ($n > 0) { $b |= 128; }
            $o .= chr($b);
        } while ($n > 0);
        return $o;
    };
    /* Genau $n Bytes lesen oder null - bei Zeitablauf und Verbindungsende. */
    $lies = function ($n) use ($s) {
        $d = '';
        while (strlen($d) < $n) {
            $t = @fread($s, $n - strlen($d));
            if ($t === false || $t === '') {
                $meta = stream_get_meta_data($s);
                if (!empty($meta['timed_out']) || !empty($meta['eof']) || feof($s)) { return null; }
                continue;
            }
            $d .= $t;
        }
        return $d;
    };
    /* Ein Paket: array(kopfbyte, rumpf) oder null. */
    $paket = function () use ($lies) {
        $k = $lies(1);
        if ($k === null) { return null; }
        $n = 0; $mult = 1;
        for ($i = 0; $i < 4; $i++) {
            $b = $lies(1);
            if ($b === null) { return null; }
            $n += (ord($b) & 127) * $mult;
            $mult *= 128;
            if (!(ord($b) & 128)) { break; }
        }
        $r = ($n > 0) ? $lies($n) : '';
        return ($r === null) ? null : array(ord($k), $r);
    };

    $flags = 0x02;                                  // saubere Sitzung
    $nutz = $zk('saugrueck' . getmypid());
    if ($benutzer !== '') {
        $flags |= 0x80;
        // Ein Kennwort ohne Benutzer laesst MQTT 3.1.1 nicht zu (Abschnitt
        // CONNECT, Kennwort-Merkmal).
        if ($kennwort !== '') { $flags |= 0x40; }
    }
    $kopf = $zk('MQTT') . chr(4) . chr($flags) . pack('n', 10);
    if ($benutzer !== '') {
        $nutz .= $zk($benutzer);
        if ($kennwort !== '') { $nutz .= $zk($kennwort); }
    }
    if (@fwrite($s, chr(0x10) . $laenge(strlen($kopf . $nutz)) . $kopf . $nutz) !== false) {
        $ack = $paket();
        // CONNACK mit Rueckgabe 0 - alles andere (etwa 5: Anmeldung
        // abgewiesen) heisst "nicht zu fragen".
        if ($ack !== null && ($ack[0] >> 4) === 2 && strlen($ack[1]) >= 2 && ord($ack[1][1]) === 0) {
            $pakete = array_chunk(array_keys($soll), 50);
            $kennung = 0;
            foreach ($pakete as $teil) {
                $kennung++;
                $sub = pack('n', $kennung);
                foreach ($teil as $t) { $sub .= $zk($t) . chr(0); }
                @fwrite($s, chr(0x82) . $laenge(strlen($sub)) . $sub);
            }
            $bestaetigt = 0;
            $ende = microtime(true) + 3.0;
            while (microtime(true) < $ende) {
                $pk = $paket();
                if ($pk === null) { break; }           // Zeitablauf: nichts mehr gekommen
                $art = $pk[0] >> 4;
                if ($art === 9) {
                    /* Hinter der Paketkennung je Filter ein Rueckgabebyte, in der
                       Reihenfolge des SUBSCRIBE mit dieser Kennung; ab 0x80 heisst
                       abgelehnt (etwa durch eine ACL). Danach schickt der Broker
                       nichts - ein abgelehntes oder unpassendes SUBACK waere sonst
                       "nichts belegt", und der Merker laege auf einer Antwort, die
                       keine war (in WSL gemessen, Pruefung-Saugroboter-Valetudo-1.1.10,
                       Faelle S3, S4, S7, S9, S11). Es zaehlt nicht, die Rueckfrage
                       endet "nicht zu fragen". */
                    $rc = (string) substr($pk[1], 2);
                    $nr = (strlen($pk[1]) >= 2) ? (int) unpack('n', substr($pk[1], 0, 2))[1] : 0;
                    if (!isset($pakete[$nr - 1]) || strlen($rc) !== count($pakete[$nr - 1])) { break; }
                    $abgelehnt = false;
                    for ($i = 0; $i < strlen($rc); $i++) {
                        if (ord($rc[$i]) >= 0x80) { $abgelehnt = true; }
                    }
                    if ($abgelehnt) { break; }
                    $bestaetigt++;
                    // Zurueckbehaltenes kommt unmittelbar nach dem SUBACK.
                    if ($bestaetigt >= count($pakete)) {
                        $ende = min($ende, microtime(true) + 1.0);
                    }
                } elseif ($art === 3 && strlen($pk[1]) >= 2) {
                    $tl = unpack('n', substr($pk[1], 0, 2));
                    $t = substr($pk[1], 2, $tl[1]);
                    $versatz = 2 + $tl[1] + ((($pk[0] >> 1) & 3) > 0 ? 2 : 0);
                    $wert = (string) substr($pk[1], $versatz);
                    if (isset($soll[$t]) && ($pk[0] & 1) && $wert !== '') {
                        $aus['belegt'][$t] = true;
                    }
                }
            }
            if ($bestaetigt >= count($pakete)) { $aus['lage'] = 'ok'; }
        }
        @fwrite($s, chr(0xE0) . chr(0));
    }
    fclose($s);
    return $aus;
}

/**
 * Welche Altwerte muessen in diesem Lauf noch abgeraeumt werden?
 *
 * Rueckgabe array('lage' => 'erledigt'|'belegt'|'unbekannt'|'aus',
 *                 'themen' => array(<thema ohne praefix>, ...)).
 *
 * Je Lauf, bis der Merker liegt:
 *   1. den Broker nach allen Themen aus ro_mqtt_frueher_behalten() fragen;
 *   2. keines belegt -> Merker schreiben, nichts abraeumen ('erledigt');
 *      einige belegt -> genau diese abraeumen, kein Merker ('belegt'); der
 *      Minutenlauf sendet dann VOLL (ro_mqtt_senden()), damit die
 *      leere retain-Nutzlast unmittelbar vor dem gueltigen Wert steht;
 *      nicht zu fragen -> alle, aber nur unmittelbar vor einem Wert, der
 *      ohnehin hinausgeht ('unbekannt'), KEIN Merker.
 * 'aus': MQTT ist ausgeschaltet oder es gibt keine Wurzel - dann wird nichts
 * gesendet und nichts gefragt.
 *
 * Ueber den UDP-Eingang gibt es keinen Merker auf den Sendeerfolg (Regeln/07,
 * Nachtrag 19.09.2026). Der Merker traegt die Kennung
 * "leer-bestaetigt <praefix>: <Themenliste>" - ein anderer Inhalt, ein
 * anderes Praefix, eine andere Liste gilt nicht, ebenso wenig ein Merker,
 * den eine Vorfassung angelegt haette. Er liegt je Roboter im Datenordner;
 * purge_installation raeumt ihn bei jedem Upgrade mit ab, dann wird genau
 * einmal nachgefragt. Bauart awm_mqtt_altlast() aus AWM-Abfuhr 1.4.13.
 */
function ro_mqtt_altlast($praefix, $dev = 1)
{
    static $cache = array();
    $praefix = (string) $praefix;
    if (isset($cache[$praefix])) { return $cache[$praefix]; }
    $cfg = ro_config();
    $p = ro_paths();
    if (empty($cfg['mqtt_enabled']) || $p['lbhome'] === '') {
        return $cache[$praefix] = array('lage' => 'aus', 'themen' => array());
    }
    $liste = ro_mqtt_frueher_behalten();
    $merker = ro_datadir() . '/.mqtt_altlast_geraeumt_' . max(1, (int) $dev);
    $kennung = 'leer-bestaetigt ' . $praefix . ': ' . implode(' ', $liste);
    if (is_file($merker) && trim((string) @file_get_contents($merker)) === $kennung) {
        return $cache[$praefix] = array('lage' => 'erledigt', 'themen' => array());
    }
    $voll = array();
    foreach ($liste as $t) { $voll[] = $praefix . '/' . $t; }
    $f = ro_mqtt_behalten_liste($voll);
    if ($f['lage'] === 'ok' && !$f['belegt']) {
        if (@file_put_contents($merker, $kennung . "\n") === false) {
            ro_log_if_changed('mqtt_merker', 'Der Merker ' . $merker . ' liess sich nicht schreiben - '
                . 'der Broker wird im naechsten Lauf wieder gefragt.');
        } else {
            ro_log('MQTT: unter ' . $praefix . '/ steht keines der ' . count($liste)
                . ' frueher zurueckbehaltenen Themen mehr im Broker (vom Broker bestaetigt).');
        }
        return $cache[$praefix] = array('lage' => 'erledigt', 'themen' => array());
    }
    if ($f['lage'] === 'ok') {
        $l = strlen($praefix) + 1;
        $t = array();
        foreach (array_keys($f['belegt']) as $v) { $t[] = substr($v, $l); }
        return $cache[$praefix] = array('lage' => 'belegt', 'themen' => $t);
    }
    ro_log_if_changed('mqtt_rueckfrage_' . max(1, (int) $dev), 'Der Broker liess sich nicht befragen, ob unter '
        . $praefix . '/ noch frueher zurueckbehaltene Werte stehen. Sie werden deshalb '
        . 'unmittelbar vor jedem Senden geloescht, bis der Broker antwortet.');
    return $cache[$praefix] = array('lage' => 'unbekannt', 'themen' => $liste);
}

/* Bis 1.1.11 stand hier ro_mqtt_altlast_offen() fuer den Minutenlauf. Seit
 * dem Durchgang vom 01.10.2026 (M2) wertet ro_mqtt_senden() ro_mqtt_altlast()
 * selbst aus: meldet der Broker noch einen Altwert, geht der Satz VOLL hinaus,
 * damit die Loeschung unmittelbar vor einem Wert steht (in WSL gemessen,
 * Pruefung-Saugroboter-Valetudo-1.1.10, Fall R10). */

/* ==================================================================
 * Abraeumen nach einem Wechsel (M3, Entscheidung Nr. 26 vom 01.10.2026)
 * ==================================================================
 *
 * Bis 1.1.11 blieben drei Arten retained Altwerte fuer immer im Broker
 * (MQTT-Pruefer, Faelle s04, s14/s15, s17):
 *   - nach einem Praefixwechsel die 41 Themen unter dem alten Praefix - auch
 *     nach der Deinstallation, die nur das eingestellte leerte;
 *   - die Themen eines ausgetragenen Roboters ("in der Ladestation, kein
 *     Fehler" nach jedem Gateway-Neustart);
 *   - beim Ausschalten von MQTT alles, was zuletzt gesendet war.
 * ro_config_speichern() merkt diese Faelle in data/plugins/<ordner>/
 * mqtt_raeumen.json vor (Praefix und Geraetenummern). Der Minutenlauf fragt
 * den Broker, sendet fuer jedes noch belegte Thema die leere retain-Nutzlast
 * ueber den UDP-Eingang und liest nach; die Vormerkung faellt erst, wenn der
 * Broker nichts mehr meldet. Laesst er sich nicht fragen, bleibt sie stehen
 * (eine Protokollzeile, bis es geht). Ist ein Praefix samt Geraet wieder in
 * Gebrauch, wird es nicht abgeraeumt. preupgrade/postupgrade tragen die Datei
 * ueber das Update; die Deinstallation leert auch die vorgemerkten Praefixe.
 */
function ro_mqtt_vormerk_datei()
{
    return ro_datadir() . '/mqtt_raeumen.json';
}

/** Die Vormerkungen: Liste von array('praefix' => .., 'devs' => array(..), 'seit' => ts). */
function ro_mqtt_vormerkungen()
{
    $f = ro_mqtt_vormerk_datei();
    if (!is_file($f)) { return array(); }
    $d = json_decode((string) @file_get_contents($f), true);
    $aus = array();
    foreach ((is_array($d) && isset($d['eintraege']) && is_array($d['eintraege'])) ? $d['eintraege'] : array() as $e) {
        if (!is_array($e) || !isset($e['praefix'], $e['devs']) || !is_string($e['praefix']) || !is_array($e['devs'])) {
            continue;
        }
        $pr = ro_mqtt_thema_saeubern($e['praefix']);
        $devs = array();
        foreach ($e['devs'] as $dv) {
            if (is_int($dv) && $dv >= 1 && $dv <= 9) { $devs[] = $dv; }
        }
        if ($devs && $pr === $e['praefix']) {
            $aus[] = array('praefix' => $pr, 'devs' => array_values(array_unique($devs)),
                           'seit' => isset($e['seit']) ? (int) $e['seit'] : 0);
        }
    }
    return $aus;
}

/**
 * Die Vormerkungen unter einer Sperre aendern: $fn bekommt die Liste und gibt
 * die neue zurueck. Oberflaeche und Minutenlauf schreiben beide - ohne Sperre
 * verloere einer die Aenderung des anderen.
 */
function ro_mqtt_vormerk_aendern($fn)
{
    $f = ro_mqtt_vormerk_datei();
    $fh = @fopen($f . '.sperre', 'c');
    if ($fh === false || !@flock($fh, LOCK_EX)) {
        if (is_resource($fh)) { fclose($fh); }
        ro_log_if_changed('mqtt_vormerk', 'Die Vormerkung zum Abraeumen (' . $f . ') liess sich nicht sperren.');
        return false;
    }
    $neu = $fn(ro_mqtt_vormerkungen());
    if (!$neu) {
        @unlink($f);
        $ok = !is_file($f);
    } else {
        $ok = ro_write_atomic($f, (string) json_encode(array('eintraege' => array_values($neu))), 0644);
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    if (!$ok) {
        ro_log_if_changed('mqtt_vormerk', 'Die Vormerkung zum Abraeumen (' . $f . ') liess sich nicht schreiben.');
    }
    return $ok;
}

/** Praefix und Geraete zum Abraeumen vormerken (zusammengefasst je Praefix). */
function ro_mqtt_vormerken($praefix, array $devs, $grund)
{
    $praefix = ro_mqtt_thema_saeubern($praefix);
    $devs = array_values(array_filter(array_map('intval', $devs), function ($d) { return $d >= 1 && $d <= 9; }));
    if (!$devs) { return true; }
    $ok = ro_mqtt_vormerk_aendern(function ($liste) use ($praefix, $devs) {
        foreach ($liste as $i => $e) {
            if ($e['praefix'] === $praefix) {
                $liste[$i]['devs'] = array_values(array_unique(array_merge($e['devs'], $devs)));
                return $liste;
            }
        }
        $liste[] = array('praefix' => $praefix, 'devs' => $devs, 'seit' => time());
        return $liste;
    });
    if ($ok) {
        ro_log('MQTT: ' . $grund . ' - die zurueckbehaltenen Themen unter ' . $praefix . '/ (Roboter '
            . implode(', ', $devs) . ') sind zum Abraeumen vorgemerkt; der Minutenlauf leert sie und liest beim Broker nach.');
    }
    return $ok;
}

/** Was hat sich zwischen zwei Konfigurationen fuer MQTT geaendert? (aus ro_config_speichern) */
function ro_mqtt_wechsel_vormerken($alt, $neu)
{
    if (!is_array($alt) || empty($alt['mqtt_enabled'])) { return; }
    $ab = ro_mqtt_thema_saeubern(isset($alt['mqtt_topic']) && is_string($alt['mqtt_topic']) ? $alt['mqtt_topic'] : '');
    $nb = ro_mqtt_thema_saeubern(isset($neu['mqtt_topic']) && is_string($neu['mqtt_topic']) ? $neu['mqtt_topic'] : '');
    $alt_dev = array_keys(ro_robots_aus($alt));
    if (!$alt_dev) { $alt_dev = array(1); }
    if (empty($neu['mqtt_enabled'])) {
        ro_mqtt_vormerken($ab, $alt_dev, 'MQTT ausgeschaltet');
        return;
    }
    if ($ab !== $nb) {
        ro_mqtt_vormerken($ab, $alt_dev, 'Themenpraefix gewechselt (' . $ab . ' -> ' . $nb . ')');
        return;
    }
    $weg = array_values(array_diff($alt_dev, array_keys(ro_robots_aus($neu))));
    if ($weg) {
        ro_mqtt_vormerken($ab, $weg, 'Roboter ausgetragen');
    }
}

/**
 * Minutenlauf: die Vormerkungen abraeumen, mit Nachlesen beim Broker. Laeuft
 * auch bei ausgeschaltetem MQTT (genau dann gibt es etwas zu raeumen).
 */
function ro_mqtt_raeumen_vorgemerkt()
{
    $liste = ro_mqtt_vormerkungen();
    if (!$liste) { return; }
    $cfg = ro_config();
    $basis = ro_mqtt_thema_saeubern($cfg['mqtt_topic']);
    $aktiv = !empty($cfg['mqtt_enabled']) ? array_keys(ro_robots()) : array();
    $udp = ro_mqtt_udpport();
    $erledigt = array();          // praefix => array(dev => true)
    foreach ($liste as $e) {
        $devs = array();
        foreach ($e['devs'] as $d) {
            if ($e['praefix'] === $basis && in_array($d, $aktiv, true)) {
                $erledigt[$e['praefix']][$d] = true;    // wieder in Gebrauch: nicht abraeumen
                continue;
            }
            $devs[] = $d;
        }
        if (!$devs) { continue; }
        $themen = array();
        foreach ($devs as $d) {
            foreach (ro_mqtt_leer_themen() as $t) { $themen[] = ro_mqtt_praefix($e['praefix'], $d) . '/' . $t; }
        }
        $f = ro_mqtt_behalten_liste($themen);
        if ($f['lage'] !== 'ok') {
            ro_log_if_changed('mqtt_raeumen_' . $e['praefix'], 'Der Broker liess sich nicht befragen - die '
                . 'zurueckbehaltenen Themen unter ' . $e['praefix'] . '/ bleiben vorgemerkt.');
            continue;
        }
        if ($f['belegt']) {
            if (!$udp) {
                ro_log_if_changed('mqtt_raeumen_' . $e['praefix'], 'Kein UDP-Eingang des Gateways in der '
                    . 'general.json - die Themen unter ' . $e['praefix'] . '/ bleiben vorgemerkt.');
                continue;
            }
            $zeilen = array();
            foreach (array_keys($f['belegt']) as $t) { $zeilen[] = 'retain ' . $t . ' '; }
            ro_udp_senden($udp, $zeilen);
            usleep(300000);     // dem Gateway Zeit bis zum Broker lassen
            $n = count($zeilen);
            $f = ro_mqtt_behalten_liste(array_keys($f['belegt']));
            if ($f['lage'] !== 'ok' || $f['belegt']) {
                ro_log_if_changed('mqtt_raeumen_' . $e['praefix'], 'MQTT: unter ' . $e['praefix'] . '/ '
                    . ($f['lage'] === 'ok' ? 'stehen nach dem Leeren noch ' . count($f['belegt']) . ' Themen'
                                            : 'liess sich das Leeren nicht nachlesen')
                    . ' - neuer Versuch im naechsten Lauf.');
                continue;
            }
            ro_log('MQTT: unter ' . $e['praefix'] . '/ ' . $n . ' zurueckbehaltene Themen geleert (vom Broker bestaetigt).');
        } else {
            ro_log('MQTT: unter ' . $e['praefix'] . '/ steht (Roboter ' . implode(', ', $devs)
                . ') nichts mehr zurueckbehalten - vom Broker bestaetigt.');
        }
        foreach ($devs as $d) { $erledigt[$e['praefix']][$d] = true; }
    }
    if (!$erledigt) { return; }
    ro_mqtt_vormerk_aendern(function ($l) use ($erledigt) {
        $aus = array();
        foreach ($l as $e) {
            $rest = array();
            foreach ($e['devs'] as $d) {
                if (!isset($erledigt[$e['praefix']][$d])) { $rest[] = $d; }
            }
            if ($rest) { $e['devs'] = $rest; $aus[] = $e; }
        }
        return $aus;
    });
}

/**
 * M5 (Durchgang 01.10.2026): die Abo-Datei des MQTT-Gateways,
 * config/plugins/<ordner>/mqtt_subscriptions.cfg, mit <praefix>/#. Das
 * Gateway V1 liest sie selbst, beim Start und bei jeder Aenderung (Regeln/07,
 * am Geraet belegt an Midea2Lox). Mitgeliefert wird saugrobo/#; ein anderes
 * Praefix fuehren Minutenlauf und Speichern nach (Bauform ap_abo_datei() aus
 * APC-UPS 1.2.17). Bis 1.1.11 gab es keine Datei: unter V1 kam ohne
 * Handeintrag nichts an, und nach einem Praefixwechsel zeigte das Hand-Abo ins
 * Leere (MQTT-Pruefer Fall 6). Rueckgabe: array(Pfad, traegt das Abo).
 */
function ro_abo_datei($praefix, $schreiben = false)
{
    $p = ro_paths();
    if ($p['lbhome'] === '') { return array('', false); }
    $pfad = dirname($p['config']) . '/mqtt_subscriptions.cfg';
    $soll = $praefix . '/#';
    $roh = is_readable($pfad) ? (string) @file_get_contents($pfad) : '';
    $da = in_array($soll, array_map('trim', preg_split('/\r?\n/', $roh)), true);
    if ($schreiben && ro_wert_pruefen('mqtt_topic', $praefix) === '' && $roh !== $soll . "\n"
        && is_dir(dirname($pfad))) {
        if (ro_write_atomic($pfad, $soll . "\n", 0644)) {
            ro_log('MQTT: Abo-Datei des Gateways auf ' . $soll . ' gesetzt (' . $pfad . ').');
            $da = true;
        } else {
            ro_log_if_changed('mqtt_abo', 'Die Abo-Datei ' . $pfad . ' liess sich nicht schreiben.');
        }
    }
    return array($pfad, $da);
}

/**
 * Aus der Deinstallation (bin/cron.php --mqtt-leeren): die zurueckbehaltenen
 * Themen der Linie leeren - unter dem eingestellten Praefix, Roboter 1 bis 9.
 *
 * Der Weg ist derselbe wie beim Senden - der UDP-Eingang des Gateways,
 * "retain <thema> " mit leerer Nutzlast (am Geraet belegt: die leere
 * Nachricht geht als Loeschung an den Broker, Regeln/07, Nachtraege vom
 * 19.09.2026). VOR der ersten Runde und nach jeder wird der Broker gefragt
 * (ro_mqtt_behalten_liste()); hinaus geht nur, was dort noch steht,
 * hoechstens $runden Runden. Ist der Broker nicht zu fragen, gehen die
 * Themen der EINGERICHTETEN Roboter in jeder Runde hinaus, und die Ausgabe
 * sagt, dass nicht nachgelesen wurde - der Eingang verwirft unter Last
 * Datagramme (Regeln/07), ein blosses Senden ist kein Beleg.
 *
 * Bis 1.1.9 raeumte die Deinstallation nichts ab und sagte nur, es koennten
 * Werte stehen bleiben (in WSL gemessen, Pruefung-Saugroboter-Valetudo-1.1.10,
 * Faelle U1 bis U8). Bauart awm_mqtt_leeren() aus AWM-Abfuhr 1.4.13.
 *
 * Der Aufrufer schaltet die Selbstheilung der Konfiguration ab; diese
 * Funktion schreibt weder Protokoll noch Datei. Ausgabe im Format der
 * Hakenskripte (<OK>/<INFO>/<WARNING>). Rueckgabe 0 geleert oder nicht
 * nachpruefbar, 1 es steht noch etwas bzw. der Eingang war nicht
 * erreichbar, 2 nicht moeglich.
 */
function ro_mqtt_leeren($runden = 3, $pause = 1.0)
{
    $cfg = ro_config();
    $basis = ro_mqtt_thema_saeubern($cfg['mqtt_topic']);
    $udpport = ro_mqtt_udpport();
    if (!$udpport) {
        echo "<INFO> MQTT: in der general.json steht kein UDP-Eingangsport des Gateways - "
           . "zurueckbehaltene Themen unter " . $basis . "/ wurden nicht geleert.\n";
        return 2;
    }
    $geraete = array_keys(ro_robots());
    if (!$geraete) { $geraete = array(1); }
    /* M3 (Durchgang 01.10.2026): auch die vorgemerkten Praefixe - ein altes
     * Praefix stand bis 1.1.11 nach der Deinstallation weiter im Broker
     * (MQTT-Pruefer Fall s17: 41 Themen unter saugrobo/ bei Praefix rob3). */
    $basen = array($basis => $geraete);
    foreach (ro_mqtt_vormerkungen() as $e) {
        $basen[$e['praefix']] = array_values(array_unique(array_merge(
            isset($basen[$e['praefix']]) ? $basen[$e['praefix']] : array(), $e['devs'])));
    }
    $alle = array();
    $eingerichtet = array();
    foreach ($basen as $b => $bdevs) {
        for ($k = 1; $k <= 9; $k++) {
            $pr = ro_mqtt_praefix($b, $k);
            foreach (ro_mqtt_leer_themen() as $t) {
                $alle[] = $pr . '/' . $t;
                if (in_array($k, $bdevs, true)) { $eingerichtet[] = $pr . '/' . $t; }
            }
        }
    }
    $basis = implode('/, ', array_keys($basen));
    $n = count($alle);
    $f = ro_mqtt_behalten_liste($alle);
    $nachgelesen = ($f['lage'] === 'ok');
    $offen = $nachgelesen ? array_keys($f['belegt']) : $eingerichtet;
    if ($nachgelesen && !$offen) {
        echo "<OK> MQTT: der Broker bestaetigt: keines der " . $n . " Themen unter " . $basis
           . "/ (Roboter 1 bis 9) steht zurueckbehalten - nichts zu leeren.\n";
        return 0;
    }
    $strom = @stream_socket_client('udp://127.0.0.1:' . (int) $udpport, $errno, $errstr, 2);
    if (!$strom) {
        echo "<WARNING> MQTT: der UDP-Eingang des Gateways war nicht erreichbar - "
           . "zurueckbehaltene Themen unter " . $basis . "/ wurden nicht geleert.\n";
        return 1;
    }
    $zu_leeren = count($offen);
    $datagramme = 0;
    for ($r = 1; $r <= max(1, (int) $runden) && $offen; $r++) {
        if ($r > 1) { usleep((int) ($pause * 1000000)); }
        foreach ($offen as $t) {
            // Ein Leerzeichen hinter dem Thema, sonst keine Nutzlast: die
            // Form, die das Gateway als Loeschung liest. M4: 5 ms Pause.
            if ($datagramme > 0) { usleep(5000); }
            @fwrite($strom, 'retain ' . $t . ' ');
            $datagramme++;
        }
        usleep(300000);     // dem Gateway Zeit bis zum Broker lassen
        $f = ro_mqtt_behalten_liste($offen);
        if ($f['lage'] === 'ok') {
            $nachgelesen = true;
            $offen = array_keys($f['belegt']);
        } else {
            $nachgelesen = false;
        }
    }
    fclose($strom);
    echo "<INFO> MQTT: " . $zu_leeren . " von " . $n . " Themen unter " . $basis . "/ mit leerer "
       . "Nutzlast an den UDP-Eingang " . (int) $udpport . " des Gateways gesendet ("
       . $datagramme . " Datagramme).\n";
    if ($nachgelesen && !$offen) {
        echo "<OK> MQTT: der Broker bestaetigt: keines der " . $n . " Themen steht mehr "
           . "zurueckbehalten.\n";
        return 0;
    }
    if ($nachgelesen) {
        echo "<WARNING> MQTT: " . count($offen) . " Themen stehen noch zurueckbehalten im Broker ("
           . implode(', ', array_slice($offen, 0, 5)) . (count($offen) > 5 ? ', ...' : '')
           . "). Von Hand: mosquitto_pub -r -n -t <thema>\n";
        return 1;
    }
    echo "<INFO> MQTT: der Broker liess sich nicht befragen - nicht nachgelesen. Der UDP-Eingang "
       . "verwirft unter Last Datagramme; was stehen bleibt, laesst sich mit "
       . "mosquitto_pub -r -n -t <thema> von Hand loeschen. Geleert wurden nur die Themen "
       . "der eingerichteten Roboter (" . implode(', ', $geraete) . ").\n";
    return 0;
}

/**
 * Den Zustand eines Roboters veroeffentlichen.
 *
 * M1 (Durchgang 01.10.2026, Entscheidung Nr. 28): Ist der Roboter nicht
 * erreichbar, gehen nur ok (0), code (8, FLUECHTIG - die benannte Ausnahme
 * fuer die Schwellwertschalter an saugrobo_code), die Werte, die das Plugin
 * selbst bildet (ann, audio, push, ptest, meldung), und das Lebenszeichen
 * hinaus; die Geraetewerte gar nicht - der Broker behaelt den letzten
 * gemessenen Stand, und das Gateway reicht keinen Platzhalter weiter. Bis
 * 1.1.11 gingen 50 Platzhalter fluechtig hinaus (batt 0, fehler 0, laedt 0,
 * ...; MQTT-Pruefer Fall s07). Bei einem Teilausfall wird die betroffene
 * Gruppe nicht gesendet (Fall s09).
 *
 * M2/M4: gesendet wird gegen das zuletzt GESENDETE Abbild
 * (/tmp/<ordner>/mqtt_gesendet_N.json, mit Praefix): nur geaenderte Werte,
 * ok in jedem Lauf, alle 30 Minuten der volle Satz (Entscheidung Nr. 26,
 * sinngemaess Raumklima). Ein anderes Praefix, ein fehlendes Abbild (jedes
 * Speichern leert es, MQTT aus verwirft es) oder ein Altwert im Broker
 * (ro_mqtt_altlast()) erzwingt den vollen Satz - bis 1.1.11 kamen nach einem
 * Praefixwechsel bis zu 30 min KEINE Zustaende unter dem neuen Praefix
 * (MQTT-Pruefer Faelle s04, s06), und jede einzelne Aenderung schickte alle
 * 52 Themen.
 *
 * Die Altwerte frueher zurueckbehaltener Themen werden abgeraeumt, solange
 * der Broker sie haelt (ro_mqtt_altlast()): die leere retain-Nutzlast geht
 * UNMITTELBAR vor dem gueltigen Wert hinaus.
 *
 * Rueckgabe: Zahl der gesendeten Datagramme.
 */
function ro_mqtt_senden($st, $dev = 1, $voll = false) {
    $cfg = ro_config();
    if (empty($cfg['mqtt_enabled'])) { return 0; }
    $udp = ro_mqtt_udpport();
    if (!$udp) { return 0; }
    if ($st === null) { $st = ro_state($dev); }
    $wurzel = ro_mqtt_thema_saeubern($cfg['mqtt_topic']);
    $prefix = ro_mqtt_praefix($wurzel, $dev);
    $werte = ro_mqtt_auswahl($st, $dev);
    $platz = ro_mqtt_platzhalter($st);
    $af = ro_tmpdir() . '/mqtt_gesendet_' . (int) $dev . '.json';
    $abbild = is_file($af) ? json_decode((string) @file_get_contents($af), true) : null;
    $gilt = is_array($abbild) && isset($abbild['praefix'], $abbild['werte'], $abbild['voll'])
            && $abbild['praefix'] === $prefix && is_array($abbild['werte']);
    if (!$gilt || time() - (int) $abbild['voll'] >= 1800 || (int) $abbild['voll'] > time()) { $voll = true; }
    $alt = ro_mqtt_altlast($prefix, $dev);
    if ($alt['lage'] === 'belegt') { $voll = true; }
    $raeumen = array_flip($alt['themen']);
    $gesendet = $gilt ? $abbild['werte'] : array();
    $zeilen = array();
    foreach ($werte as $k => $v) {
        $w = ro_mqtt_wert_saeubern($v);
        if (!$voll && $k !== 'ok' && array_key_exists($k, $gesendet) && (string) $gesendet[$k] === $w) {
            continue;
        }
        if (isset($raeumen[$k])) {
            // Ein Leerzeichen hinter dem Thema, sonst keine Nutzlast: die
            // Form, die das Gateway als Loeschung liest.
            $zeilen[] = 'retain ' . $prefix . '/' . $k . ' ';
        }
        $zeilen[] = ro_mqtt_zeile(ro_mqtt_retain($k, $v) && !isset($platz[$k]), $prefix . '/' . $k, $v);
        $gesendet[$k] = $w;
    }
    $n = $zeilen ? ro_udp_senden($udp, $zeilen) : 0;
    ro_write_json($af, array('praefix' => $prefix, 'werte' => $gesendet,
        'voll' => $voll ? time() : (int) $abbild['voll']));
    return $n;
}

/**
 * Sofort senden (robo.php ?ptest=1): die geaenderten Werte, ohne Lebenszeichen -
 * das sagt etwas ueber den Minutenlauf.
 */
function ro_mqtt_publish($st = null, $dev = 1) {
    return ro_mqtt_senden($st, $dev, false);
}

/** M1: Was ein Roboter in diesem Zustand ueber MQTT sendet - Thema => Wert. */
function ro_mqtt_auswahl($st, $dev = 1)
{
    $m = ro_mqtt_werte($st, $dev);
    if (!isset($st['ok']) || (int) $st['ok'] !== 1) {
        $aus = array('ok' => 0, 'code' => 8);
        foreach (ro_mqtt_eigene() as $k) {
            if (array_key_exists($k, $m)) { $aus[$k] = $m[$k]; }
        }
        return $aus;
    }
    foreach (ro_mqtt_teil_weg($st) as $k => $_) { unset($m[$k]); }
    return $m;
}

/** Die Themen, die das Plugin selbst bildet - sie gelten auch bei einem Ausfall. */
function ro_mqtt_eigene()
{
    return array('ann', 'audio', 'push', 'ptest', 'meldung');
}

/** M1, Teilausfall: die Themen der Gruppen, die dieser Abruf nicht gelesen hat. */
function ro_mqtt_teil_weg($st)
{
    $aus = array();
    $teil = (isset($st['teil_ok']) && is_array($st['teil_ok'])) ? $st['teil_ok'] : array();
    $gruppen = array(
        'statistik' => array('flaeche', 'dauer'),
        'gesamt'    => array('flaecheg', 'dauerg', 'anzahlg'),
        'verbrauch' => array('filter', 'filter2', 'bhaupt', 'bseite', 'bseite2', 'sensor', 'raeder', 'mop',
                           'dockfilter', 'dockbuerste', 'dockbehaelter', 'reiniger', 'matwarn'),
    );
    foreach ($gruppen as $g => $themen) {
        if (empty($teil[$g])) {
            foreach ($themen as $t) { $aus[$t] = true; }
        }
    }
    if (empty($st['evlesbar'])) {
        foreach (array('event', 'evtyp', 'evmuell', 'ereignistext') as $t) { $aus[$t] = true; }
    }
    foreach (ro_mqtt_langform() as $lang => $kurz) {
        if (isset($aus[$kurz])) { $aus[$lang] = true; }
    }
    return $aus;
}

/**
 * Welche Themen tragen in diesem Zustand einen Platzhalter? Seit M1 nur noch
 * code bei einem Ausfall - er geht FLUECHTIG hinaus. Alle anderen Werte eines
 * nicht gelesenen Geraets werden gar nicht gesendet (ro_mqtt_auswahl()).
 */
function ro_mqtt_platzhalter($st)
{
    if (!isset($st['ok']) || (int) $st['ok'] !== 1) {
        return array('code' => true);
    }
    return array();
}

/**
 * NUR das Lebenszeichen - ohne die Werte.
 *
 * Es geht bei JEDEM Cron-Durchgang hinaus, auch wenn sich nichts geaendert
 * hat: der Doppelt-senden-Filter wird fuer diese drei Themen uebergangen.
 * M4 (Durchgang 01.10.2026): EINMAL je Lauf, nicht je Roboter - bis 1.1.11
 * ging es bei zwei Robotern doppelt hinaus (MQTT-Pruefer Fall s13).
 * Sonst faellt bei einem Roboter, der eine Woche in der Ladestation steht,
 * genau das Zeichen aus, das sagen soll, dass das Plugin noch lebt.
 */
function ro_mqtt_lebenszeichen()
{
    $cfg = ro_config();
    if (empty($cfg['mqtt_enabled'])) { return 0; }
    $udp = ro_mqtt_udpport();
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
 * ALLE Themen, die dieses Plugin veroeffentlicht - mit Bedeutung und Retain.
 *
 * Bis 1.1.3 baute die Tabelle im Reiter MQTT ihre Zeilen selbst, aus der
 * vollen Feldliste. Der Sender nahm ALTER und ZAEHLER aus. Die Tabelle nannte
 * deshalb zwei Themen, die es nie gab (gemessen: 48 gelistet, 46 gesendet).
 * Jetzt gibt es EINE Liste, und die Pruefzeile im Reiter Test haelt sie gegen
 * das, was der Sender wirklich bildet. Die Spalte Retain fragt dieselbe
 * Positivliste wie der Sender (ro_mqtt_retain()).
 *
 * Rueckgabe: thema => array('bedeutung' => ..., 'retain' => 0|1)
 */
function ro_mqtt_themen($praefix = null, $dev = 1)
{
    $wurzel = ro_mqtt_thema_saeubern($praefix === null ? ro_config()['mqtt_topic'] : $praefix);
    $prefix = ro_mqtt_praefix($wurzel, $dev);
    $aus = array(
        // Das Lebenszeichen haengt an der WURZEL und ist nie retained.
        $wurzel . '/status/ok'      => array('bedeutung' => ro_t('MQTT.T_OK'), 'retain' => 0),
        $wurzel . '/status/ts'      => array('bedeutung' => ro_t('MQTT.T_TS'), 'retain' => 0),
        $wurzel . '/status/zaehler' => array('bedeutung' => ro_t('MQTT.T_ZAEHLER'), 'retain' => 0),
    );
    foreach (ro_felder() as $name => $f) {
        if (ro_mqtt_ausgenommen($name)) { continue; }
        $k = strtolower($name);
        $aus[$prefix . '/' . $k] = array(
            'bedeutung' => $f[4] . ($f[3] !== '' ? ' [' . $f[3] . ']' : ''),
            'retain' => ro_mqtt_retain($k) ? 1 : 0,
        );
    }
    foreach (array('status' => 'MQTT.T_STATUSTEXT', 'fehlertext' => 'MQTT.T_FEHLERTEXT',
                   'ereignistext' => 'MQTT.T_EREIGNISTEXT', 'meldung' => 'MQTT.T_MELDUNG') as $k => $s) {
        $aus[$prefix . '/' . $k] = array('bedeutung' => ro_t($s), 'retain' => ro_mqtt_retain($k) ? 1 : 0);
    }
    $felder = ro_felder();
    foreach (ro_mqtt_langform() as $lang => $kurz) {
        $f = $felder[strtoupper($kurz)];
        $aus[$prefix . '/' . $lang] = array(
            'bedeutung' => $f[4] . ($f[3] !== '' ? ' [' . $f[3] . ']' : '') . ' = ' . $prefix . '/' . $kurz,
            'retain' => ro_mqtt_retain($lang) ? 1 : 0,
        );
    }
    return $aus;
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
/**
 * Welche Felder gehen ueber MQTT NICHT hinaus?
 *
 * Bis 1.1.3 stand diese Entscheidung nur im Sender; die Themen-Tabelle im
 * Reiter MQTT lief ueber die volle Feldliste und nannte deshalb
 * <praefix>/alter und <praefix>/zaehler als veroeffentlichte Themen. Gemessen
 * am 04.09.2026 an der gerenderten Seite: 48 Themen in der Tabelle, 46
 * gesendet - die Differenz genau diese beiden. Wer die Tabelle abarbeitete,
 * legte zwei virtuelle Eingaenge an, die nie einen Wert bekamen, und zwar
 * ausgerechnet die beiden, die sagen sollen, ob das Plugin noch lebt.
 *
 * Jetzt fragen Sender und Tabelle dieselbe Funktion.
 */
function ro_mqtt_ausgenommen($name)
{
    return in_array(strtoupper((string) $name), array('ALTER', 'ZAEHLER'), true);
}

function ro_mqtt_werte($st, $dev = 1)
{
    /* DAS GERAET MUSS IM ZUSTAND STEHEN.
     *
     * ro_feldwert() holt ANN, AUDIO, PUSH und PTEST ueber
     * ro_meldeflags($st['dev']). ro_zeile() setzt $st['dev'] (HTTP-Weg war
     * deshalb richtig), ro_state() setzt den Schluessel nie - und diese
     * Funktion reichte $dev bis 1.1.3 nicht weiter. Gemessen am 04.09.2026
     * mit gesetztem Merker ann_2: HTTP meldete ANN=1, MQTT meldete ann=0.
     * Wer zwei Roboter hat und ueber MQTT arbeitet, bekam fuer den zweiten
     * nie ein Meldefenster. */
    $st['dev'] = max(1, (int) $dev);
    $m = array();
    foreach (ro_felder() as $name => $f) {
        if (ro_mqtt_ausgenommen($name)) { continue; }
        $m[strtolower($name)] = ro_feldwert($name, $st);
    }
    // Vier Klartexte, die es ueber HTTP nicht gibt (dort waeren sie in der
    // Zeile ein Trennzeichenproblem).
    $m['status'] = $st['text'];
    $m['fehlertext'] = $st['fehlertext'];
    $m['ereignistext'] = $st['evtext'];
    $m['meldung'] = ro_meldung_lesen($dev);
    // Die Langform fuer bestehende Loxone-Vorlagen (ro_mqtt_langform()).
    foreach (ro_mqtt_langform() as $lang => $kurz) {
        if (array_key_exists($kurz, $m)) { $m[$lang] = $m[$kurz]; }
    }
    return $m;
}

/* ---------------- Ausgabeart Alexa-NG (Ansage-2, 01.10.2026; ab Werk nicht gewaehlt) ----------------
 *
 * Das eigene Plugin LoxBerry-Plugin-Alexa-NG (Ordner alexang) laesst
 * Amazon-Echo-Geraete sprechen: https://github.com/timanders22/LoxBerry-Plugin-Alexa-NG
 * Aufruf per POST an seinen Endpunkt auf DIESEM LoxBerry (Port aus der
 * general.json): das Sprechtoken steht so in keiner Adresse und keinem
 * Zugriffsprotokoll. Faellt Alexa-NG aus, entfaellt die Ansage (kein stiller
 * Wechsel auf einen anderen Lautsprecher); Protokoll und Reiter Test nennen
 * HTTP-Code und GRUND, nie Token oder Text.
 */

/** Die Ausgabewege der Sprachausgabe - EINE Liste fuer Formular, Pruefung und Ansage. */
function ro_tts_wege()
{
    return array('musicserver', 'ms4h', 'audioserver', 'custom', 'alexang', 'cc4lox');
}

/** Die Schluessel unter tts - die Positivliste der Sicherung. */
function ro_tts_schluessel()
{
    return array('mode', 'ip', 'port', 'zones', 'volume', 'lang', 'template',
                 'alexa_geraet', 'alexa_laut', 'alexa_token',
                 'google_geraet', 'google_laut', 'google_token');
}

/**
 * Ansage-3 (01.10.2026): die beiden Sprech-Plugins auf DIESEM LoxBerry.
 * Alexa-NG (Ordner alexang) und Chromecast 4 Lox NG (Ordner chromecast-4lox-ng,
 * ab 1.3.15) haben dieselbe Schnittstelle: POST aktion=sprechen, token, text,
 * geraet, laut; selftest=1; Antwort "<PRAEFIX>;OK=1;...;GRUND=..."
 * (GOOGLE_SPRECHEN_SCHNITTSTELLE.md). Verschieden sind nur Ordner, die Felder
 * unter tts, die Texte und die Merkdatei - sie stehen hier; alles Uebrige
 * teilen sich beide Ausgabearten. Chromecast 4 Lox NG nimmt nur Aufrufe von
 * 127.0.0.1/::1 an (sonst 403 NUR_LOKAL), deshalb steht dort immer 127.0.0.1.
 * Faellt das Sprech-Plugin aus, entfaellt die Ansage - kein stiller Wechsel
 * auf einen anderen Lautsprecher.
 */
function ro_sprech_art($art)
{
    if ($art === 'google') {
        return array('ordner' => 'chromecast-4lox-ng', 'feld' => 'google_', 'letzte' => 'google_letzte.json',
            'geraet' => 'GRUND.GOOGLE_GERAET', 'laut' => 'GRUND.GOOGLE_LAUT', 'token' => 'GRUND.GOOGLE_TOKEN',
            'kein_token' => 'GRUND.GOOGLE_KEIN_TOKEN', 'keine_antwort' => 'GRUND.GOOGLE_KEINE_ANTWORT',
            'antwort' => 'GRUND.GOOGLE_ANTWORT', 'fehlt' => 'GRUND.GOOGLE_FEHLT',
            'unerwartet' => 'GRUND.GOOGLE_UNERWARTET', 'p_ok' => 'PRUEF.GOOGLE_OK', 'p_zu' => 'PRUEF.GOOGLE_ZU',
            'p_letzte_ok' => 'PRUEF.GOOGLE_LETZTE_OK', 'p_letzte_fehl' => 'PRUEF.GOOGLE_LETZTE_FEHL');
    }
    return array('ordner' => 'alexang', 'feld' => 'alexa_', 'letzte' => 'alexa_letzte.json',
        'geraet' => 'GRUND.ALEXA_GERAET', 'laut' => 'GRUND.ALEXA_LAUT', 'token' => 'GRUND.ALEXA_TOKEN',
        'kein_token' => 'GRUND.ALEXA_KEIN_TOKEN', 'keine_antwort' => 'GRUND.ALEXA_KEINE_ANTWORT',
        'antwort' => 'GRUND.ALEXA_ANTWORT', 'fehlt' => 'GRUND.ALEXA_FEHLT',
        'unerwartet' => 'GRUND.ALEXA_UNERWARTET', 'p_ok' => 'PRUEF.ALEXA_OK', 'p_zu' => 'PRUEF.ALEXA_ZU',
        'p_letzte_ok' => 'PRUEF.ALEXA_LETZTE_OK', 'p_letzte_fehl' => 'PRUEF.ALEXA_LETZTE_FEHL');
}

/** Kontext fuer die gemeinsame Sprachausgabe (Nr. 36 b): Webport und Kopfzeile
 *  dieses Plugins. Keine Merkdatei des Moduls - <art>_letzte.json fuehrt die
 *  Linie in Stufe 1 weiter selbst. */
function ro_ansage_k()
{
    return array('port' => ro_webport(), 'kopf' => array('User-Agent: LoxBerry Saugroboter'), 'ordner' => '');
}

/** Adresse des Sprech-Endpunkts auf 127.0.0.1 (Webport aus der general.json). */
function ro_sprech_adresse($art)
{
    $a = ro_sprech_art($art);
    return 'http://127.0.0.1' . (ro_webport() === 80 ? '' : ':' . ro_webport()) . '/plugins/' . $a['ordner'] . '/index.php';
}

/** Sprechtoken: 8 bis 128 Buchstaben, Ziffern, _ und - (Alexa-NG erzeugt 24 Hexzeichen,
 *  Chromecast 4 Lox NG 32 und verlangt selbst mindestens 16). Gilt fuer beide Ausgabearten. */
function ro_alexa_token_ok($t)
{
    return ansage_token_ok($t);     // Nr. 36 b: dieselbe Form, eine Quelle
}

/** Geraet: leer (= Standardgeraet des Sprech-Plugins) oder 1 bis 200 Zeichen UTF-8,
 *  ohne Steuerzeichen und ohne Leerraum am Rand (Name, Kommaliste,
 *  gruppe:<name> oder alle - das prueft das Sprech-Plugin selbst; bei
 *  Chromecast 4 Lox NG auch das MQTT-Thema). Gilt fuer beide Ausgabearten. */
function ro_alexa_geraet_ok($g)
{
    return is_string($g) && ($g === ''
        || (preg_match('/^.{1,200}\z/us', $g) === 1 && preg_match('/[\x00-\x1F\x7F]/', $g) !== 1
            && trim($g) === $g));
}

/** Die Felder einer Sprech-Ausgabeart unter tts pruefen (aus ro_wert_pruefen()). '' = in Ordnung. */
function ro_tts_sprech_pruefen(array $wert, $art)
{
    $a = ro_sprech_art($art);
    $g = $a['feld'] . 'geraet';
    $l = $a['feld'] . 'laut';
    $k = $a['feld'] . 'token';
    if (isset($wert[$g]) && !ro_alexa_geraet_ok($wert[$g])) {
        return ro_t($a['geraet']);
    }
    if (isset($wert[$l]) && !ro_ganz_ok($wert[$l], -1, 100)) {
        return ro_t($a['laut']);
    }
    if (isset($wert[$k]) && !(is_string($wert[$k])
            && ($wert[$k] === '' || ro_alexa_token_ok($wert[$k])))) {
        return ro_t($a['token']);
    }
    return '';
}

/**
 * POST an ein Sprech-Plugin. Rueckgabe: array('code' => HTTP-Code (0 = keine Antwort),
 * 'zeile' => erste Antwortzeile ohne Token und Steuerzeichen). Ohne
 * Weiterleitung. Das Token steht nur im Koerper, nie in der Adresse.
 */
function ro_sprech_rufen($art, array $felder, $tmo = 10)
{
    /* Nr. 36 b, Stufe 1: gerufen ueber die gemeinsame Sprachausgabe (curl, sonst
     * Datenstrom; ohne Weiterleitung, ohne Proxy; Verbindungsaufbau hoechstens
     * 3 s, gesamt $tmo wie bisher). Rueckgabe wie bisher. */
    $a = ansage_ng_rufen(ro_sprech_adresse($art), $felder, $tmo, ro_ansage_k());
    return array('code' => $a['code'], 'zeile' => $a['zeile']);
}

/**
 * Antwort eines Sprech-Plugins bewerten. Rueckgabe: '' bei "<praefix>;OK=1" mit
 * HTTP 200 (auch UNVERAENDERT und TEXT_NULL), sonst ein Grund als Text (nie mit
 * dem Token): keine Antwort, 404 ohne GRUND (Plugin nicht installiert bzw. bei
 * Chromecast 4 Lox NG aelter als 1.3.15), eine Abweisung mit GRUND (400, 403,
 * 404, 409, 429, 503, 200 mit OK=0 ...) oder eine unerwartete Antwort.
 */
function ro_sprech_bewerten($art, array $a, $praefix)
{
    $s_art = ro_sprech_art($art);
    if ($a['code'] === 200 && strpos($a['zeile'], $praefix . ';OK=1') === 0) {
        return '';
    }
    if ($a['code'] <= 0) {
        return sprintf(ro_t($s_art['keine_antwort']), ro_sprech_adresse($art));
    }
    if (preg_match('/(?:^|;)GRUND=([A-Za-z0-9_]{1,40})(?:;|$)/', $a['zeile'], $m)) {
        return sprintf(ro_t($s_art['antwort']), (int) $a['code'], $m[1]);
    }
    if ($a['code'] === 404) {
        return sprintf(ro_t($s_art['fehlt']), ro_sprech_adresse($art));
    }
    $s = substr((string) preg_replace('/[^A-Za-z0-9;=_.:\-]/', '', $a['zeile']), 0, 60);
    return sprintf(ro_t($s_art['unerwartet']), (int) $a['code'], $s !== '' ? $s : '-');
}

/** Der GRUND einer Antwortzeile (Protokoll, Testansage), '-' wenn keiner dasteht. */
function ro_sprech_grund(array $a)
{
    return preg_match('/(?:^|;)GRUND=([A-Za-z0-9_]{1,40})(?:;|$)/', $a['zeile'], $m) ? $m[1] : '-';
}

/**
 * Eine Ansage ueber Alexa-NG ($art 'alexa') oder Chromecast 4 Lox NG ('google').
 * Rueckgabe: '' = gesendet, sonst der Grund. Geraet und Lautstaerke aus den
 * Einstellungen. Das Ergebnis (Zeit, ok, Grund - nie Token oder Text) liegt
 * danach in <art>_letzte.json im Zwischenordner, fuer den Reiter Test; bei
 * Google dazu HTTP-Code und GRUND der Antwort (z. B. UNVERAENDERT).
 */
function ro_sprech_sprechen($art, $text, array $cfg)
{
    $s_art = ro_sprech_art($art);
    $t = isset($cfg['tts']) && is_array($cfg['tts']) ? $cfg['tts'] : array();
    $tok = isset($t[$s_art['feld'] . 'token']) ? $t[$s_art['feld'] . 'token'] : '';
    $a = array('code' => 0, 'zeile' => '');
    if (!ro_alexa_token_ok($tok)) {
        $grund = ro_t($s_art['kein_token']);
    } else {
        $f = array('aktion' => 'sprechen', 'token' => $tok);
        $g = (isset($t[$s_art['feld'] . 'geraet']) && is_string($t[$s_art['feld'] . 'geraet'])) ? $t[$s_art['feld'] . 'geraet'] : '';
        if ($g !== '') { $f['geraet'] = $g; }
        $laut = isset($t[$s_art['feld'] . 'laut']) ? (int) $t[$s_art['feld'] . 'laut'] : -1;
        if ($laut >= 0 && $laut <= 100) { $f['laut'] = $laut; }
        $f['text'] = (string) $text;
        $a = ro_sprech_rufen($art, $f, 10);
        $grund = ro_sprech_bewerten($art, $a, 'SPRECHEN');
    }
    $merk = array('zeit' => time(), 'ok' => $grund === '' ? 1 : 0, 'grund' => $grund);
    if ($art === 'google') {
        $merk['code'] = (int) $a['code'];
        $merk['antwort'] = $a['code'] > 0 ? ro_sprech_grund($a) : '-';
    }
    ro_write_json(ro_tmpdir() . '/' . $s_art['letzte'], $merk);
    return $grund;
}

/** Die Merkdatei der letzten Ansage (array mit zeit, ok, grund ...) oder null. */
function ro_sprech_letzte($art)
{
    $s_art = ro_sprech_art($art);
    $lf = ro_tmpdir() . '/' . $s_art['letzte'];
    $l = is_file($lf) ? json_decode((string) @file_get_contents($lf), true) : null;
    return (is_array($l) && isset($l['zeit'], $l['ok'])) ? $l : null;
}

/**
 * Zeile im Reiter Test, wenn Alexa-NG bzw. Chromecast 4 Lox NG die
 * Ausgabeart ist: array(Stand, Text). Gefragt wird selftest=1 (prueft nur das
 * Token, spricht nicht) und nur, wenn der Reiter Test offen ist - sonst
 * kostete jeder Seitenaufbau bis zu 10 s, wenn das Sprech-Plugin haengt. Die
 * letzte Ansage wird dazugenannt. Bei Google zeigt die Zeile Lautsprecher und
 * Lautstaerke; SPRECHEN=0 / DIENST=0 im Selbsttest werden als Hinweis genannt.
 */
function ro_pruef_sprech($art, array $cfg, $offen)
{
    $s_art = ro_sprech_art($art);
    $tok = isset($cfg['tts'][$s_art['feld'] . 'token']) ? $cfg['tts'][$s_art['feld'] . 'token'] : '';
    if (!ro_alexa_token_ok($tok)) {
        return array(0, ro_t($s_art['kein_token']));
    }
    $letzte = '';
    $stand = 1;
    $l = ro_sprech_letzte($art);
    if ($l !== null) {
        $s = max(0, time() - (int) $l['zeit']);
        $alter = $s < 90 ? $s . ' s' : ($s < 5400 ? (int) round($s / 60) . ' min' : (int) round($s / 3600) . ' h');
        if ((int) $l['ok'] === 1) {
            $letzte = ' ' . sprintf(ro_t($s_art['p_letzte_ok']), $alter,
                isset($l['antwort']) && is_string($l['antwort']) ? $l['antwort'] : '-');
        } else {
            $letzte = ' ' . sprintf(ro_t($s_art['p_letzte_fehl']), $alter,
                isset($l['grund']) && is_string($l['grund']) ? $l['grund'] : '-');
            $stand = 2;
        }
    }
    if (!$offen) {
        return array(2, ro_t($s_art['p_zu']) . $letzte);
    }
    $a = ro_sprech_rufen($art, array('selftest' => '1', 'token' => $tok), 10);
    $grund = ro_sprech_bewerten($art, $a, 'SELFTEST');
    if ($grund !== '') {
        return array(0, $grund . $letzte);
    }
    if ($art === 'google') {
        $t = $cfg['tts'];
        $gg = (isset($t['google_geraet']) && is_string($t['google_geraet']) && $t['google_geraet'] !== '')
            ? $t['google_geraet'] : ro_t('PRUEF.GOOGLE_STANDARDGERAET');
        $gl = (isset($t['google_laut']) && (int) $t['google_laut'] >= 0 && (int) $t['google_laut'] <= 100)
            ? (string) (int) $t['google_laut'] : ro_t('PRUEF.GOOGLE_ANSAGELAUT');
        $hinweis = '';
        if (strpos($a['zeile'] . ';', ';SPRECHEN=0;') !== false) {
            $hinweis .= ' ' . ro_t('PRUEF.GOOGLE_SPRECHEN_AUS');
            $stand = 2;
        }
        if (strpos($a['zeile'] . ';', ';DIENST=0;') !== false) {
            $hinweis .= ' ' . ro_t('PRUEF.GOOGLE_DIENST_AUS');
            $stand = 2;
        }
        return array($stand, sprintf(ro_t($s_art['p_ok']), ro_sprech_adresse($art), $gg, $gl) . $hinweis . $letzte);
    }
    return array($stand, sprintf(ro_t($s_art['p_ok']), ro_sprech_adresse($art)) . $letzte);
}

/* ---------------- Ansage (TTS) ---------------- */

function ro_tts_url($text) {
    /* Nr. 36 b, Stufe 1: die Adresse baut die gemeinsame Sprachausgabe
     * (ansage_tts_url()). Was diese Linie vorher anders machte als das Modul,
     * bleibt hier davor: ein unbekannter Modus gilt als musicserver, nur Zonen
     * der Form 3 oder 3~20 kommen in die Adresse (ueber eine zurueckgespielte
     * Sicherung kaeme sonst beliebiger Text hinein), die Lautstaerke ist auf 1
     * bis 100 begrenzt, und in Vorlagen steht {lang} nur aus Kleinbuchstaben. */
    $cfg = ro_config(); $tts = $cfg['tts'];
    $mode = (string) (isset($tts['mode']) ? $tts['mode'] : 'musicserver');
    if (!in_array($mode, array('musicserver', 'ms4h', 'audioserver', 'custom'), true)) {
        $mode = 'musicserver';
    }
    if ($mode === 'audioserver') { return null; }
    $zl = array();
    foreach (explode(',', (string) $tts['zones']) as $z) {
        $z = trim($z);
        if ($z !== '' && preg_match('/^[0-9]+(~[0-9]+)?$/', $z)) { $zl[] = $z; }
    }
    $tts['mode'] = $mode;
    $tts['zones'] = implode(',', $zl);
    $tts['volume'] = max(1, min(100, (int) $tts['volume']));
    $tts['ip'] = (string) $tts['ip'];
    $tts['template'] = (string) $tts['template'];
    $tts['lang'] = ($mode === 'musicserver') ? (string) $tts['lang']
        : (string) preg_replace('/[^a-z]/', '', strtolower((string) $tts['lang']));
    return ansage_tts_url((string) $text, $tts);
}
function ro_say($text) {
    /* Ansage-2: Ausgabeart Alexa-NG (POST). Faellt Alexa-NG aus, entfaellt die
     * Ansage - kein stiller Wechsel auf einen anderen Lautsprecher. Das
     * Protokoll nennt Laenge und Grund, nie Text oder Token. */
    $cfg = ro_config();
    if (isset($cfg['tts']['mode']) && $cfg['tts']['mode'] === 'alexang') {
        $grund = ro_sprech_sprechen('alexa', $text, $cfg);
        ro_log('Ansage ueber Alexa-NG (' . ro_zeichen($text) . ' Zeichen) -> '
            . ($grund === '' ? 'OK' : 'FEHLER: ' . $grund));
        return $grund === '';
    }
    /* Ansage-3: Ausgabeart Google-Lautsprecher (Chromecast 4 Lox NG). Gesendet
     * heisst: HTTP 200 und SPRECHEN;OK=1 (der Dienst hat eingereiht), nicht
     * "gesprochen". Das Protokoll nennt Laenge, Lautsprecher, Lautstaerke,
     * HTTP-Code und GRUND - nie Text oder Token. Kein eigener Wiederholversuch. */
    if (isset($cfg['tts']['mode']) && $cfg['tts']['mode'] === 'cc4lox') {
        $grund = ro_sprech_sprechen('google', $text, $cfg);
        $l = ro_sprech_letzte('google');
        $t = $cfg['tts'];
        ro_log('Ansage ueber Chromecast 4 Lox NG (' . ro_zeichen($text) . ' Zeichen, Lautsprecher '
            . ((isset($t['google_geraet']) && is_string($t['google_geraet']) && $t['google_geraet'] !== '')
                ? '"' . $t['google_geraet'] . '"' : 'Standardgeraet')
            . ', Lautstaerke ' . ((isset($t['google_laut']) && (int) $t['google_laut'] >= 0 && (int) $t['google_laut'] <= 100)
                ? (int) $t['google_laut'] : 'Ansagelautstaerke') . ') -> '
            . ($grund === '' ? 'gesendet, HTTP ' . (is_array($l) && isset($l['code']) ? (int) $l['code'] : 0)
                . ', GRUND=' . (is_array($l) && isset($l['antwort']) ? $l['antwort'] : '-')
                : 'FEHLER: ' . $grund));
        return $grund === '';
    }
    $url = ro_tts_url($text);
    if ($url === null) { ro_log('Ansage: Modus Audioserver - Ausgabe ueber Loxone Config'); return false; }
    if ($url === '') { ro_log('Ansage uebersprungen: keine TTS-IP konfiguriert'); return false; }
    /* C6 (Durchgang 01.10.2026): gesprochen heisst HTTP 2xx. Bis 1.1.11
     * zaehlte jeder Rumpf, auch der einer 404 oder 500 (ignore_errors) - im
     * Protokoll stand "Ansage gesendet ... -> OK", obwohl die Vorlage ins
     * Leere zeigte (in WSL gemessen, Code-Pruefer Fall C2). Und der
     * Ansagetext steht nicht mehr woertlich im Protokoll, nur seine Laenge
     * (wie Alexa-NG, Entscheidung Nr. 18). */
    /* Nr. 36 b, Stufe 1: abgerufen ueber die gemeinsame Sprachausgabe - ohne
     * Weiterleitung (bisher folgte der Abruf einer Umleitung und wertete die
     * Antwort des Ziels), ohne Proxy; gesendet heisst weiter HTTP 2xx, die
     * Zeitgrenze bleibt 10 s (mit curl hoechstens 3 s fuer den Verbindungsaufbau). */
    $k36 = ro_ansage_k();
    $a36 = ansage_ausfuehren(ansage_anfrage('GET', $url, null, 10, $k36), $k36);
    $code = $a36['code'];
    $r = $code > 0 ? $a36['rumpf'] : false;
    $ok = ($r !== false && $code >= 200 && $code < 300);
    ro_log('Ansage (' . ro_zeichen($text) . ' Zeichen) -> '
        . ($ok ? 'OK, HTTP ' . $code : 'FEHLER ' . ($code > 0 ? 'HTTP ' . $code : '(keine Antwort)')));
    return $ok;
}

/** Laenge eines Textes in Zeichen (UTF-8), fuer das Protokoll. */
function ro_zeichen($t)
{
    $t = (string) $t;
    return function_exists('mb_strlen') ? mb_strlen($t, 'UTF-8') : (int) preg_match_all('/./us', $t);
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
        /* C2 (Durchgang 01.10.2026): antwortet der Roboter nicht, wird nichts
         * gemeldet und ev_N.json NICHT ueberschrieben - der letzte GEMESSENE
         * Code bleibt, wie ro_state() es mit last_N.json haelt. Bis 1.1.11
         * stand danach code 8 darin: endete eine Reinigung waehrend einer
         * Funkpause (etwa beim Andocken), ging "fertig" verloren, und
         * 9 -> 8 -> 9 meldete einen Fehler doppelt (in WSL gemessen,
         * Code-Pruefer Fall C1: 0 Meldungen statt einer). */
        if (!isset($st['ok']) || (int) $st['ok'] !== 1) { continue; }
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
        // <Wurzel>/templates/plugins/<ordner>/lang/ - Wurzel und Ordner
        // kommen aus ro_paths(), EINER Stelle fuer die Wurzelregel.
        //
        // Bis 1.1.9 stand hier eine eigene Suche mit dem fest verdrahteten
        // Heimatverzeichnis des Benutzers loxberry als Rueckfall, und ohne
        // Wurzel wurde der Pfad ab der Laufwerkswurzel gebildet - VOR den
        // eigenen Sprachdateien. Was dort lag, lieferte die Texte (in WSL
        // gemessen, Pruefung-Saugroboter-Valetudo-1.1.10, Faelle C1 und C4).
        $wo = ro_paths();
        $pfad = '';
        if ($wo['lbhome'] !== '') {
            $pfad = $wo['lbhome'] . '/templates/plugins/' . $wo['plugin'] . '/lang';
        }
        if ($pfad === '' || !is_dir($pfad)) {
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

/**
 * Die Feldtabelle - EINE Quelle fuer Antwortzeile, MQTT, Vorlage und Anleitung.
 *
 *   name => array(analog, min, max, einheit, beschreibung, retain, kachelname)
 *
 * [5] RETAIN - die Positivliste des Senders (ro_mqtt_retain_liste()): NUR der
 *     Wert 1 heisst zurueckbehalten, alles andere und ein fehlender Eintrag
 *     fluechtig. Hausstandard seit 03.09.2026: Zustaende retained, Messwerte
 *     mit Zeitbezug nicht. Nicht retained sind deshalb BATT (ein alter
 *     Ladestand saehe nach einem Ausfall aus wie ein frischer) sowie ANN und
 *     PTEST (das sind Zeitfenster von 10 bzw. 5 Minuten; retained stuende das
 *     Fenster fuer immer offen). Seit 1.1.10 auch OK nicht: "Roboter
 *     erreichbar" ist das Ergebnis der EIGENEN Abfrage, eine Aussage des
 *     Plugins ueber sich (Regeln/07, Entscheidung vom 19.09.2026). ALTER und
 *     ZAEHLER gehen ueber MQTT ohnehin nicht hinaus.
 *
 * [6] KACHELNAME - der Comment der Importvorlage wird in Loxone Config zum
 *     ANZEIGENAMEN des Bausteins, nicht zur Dokumentation. Bis 1.1.3 wanderte
 *     dort die ganze Erklaerspalte hinein; ROBO_CODE hiess danach
 *     "Statuszahl: 0 Ladestation, 1 bereit, 2 reinigt, ...". Die Erklaerung
 *     bleibt in [4] und steht in der Feldtabelle der Oberflaeche.
 */
function ro_felder() {
    /* U12 (Durchgang 01.10.2026): die Beschreibung [4] kommt aus der
     * Sprachdatei ([FELD]); der deutsche Text hier bleibt nur als Rueckfall.
     * Der Kachelname [6] ist der Name des Bausteins in der Vorlage und bleibt. */
    static $f = null;
    if ($f !== null) { return $f; }
    $f = array(
        'OK'       => array(0, 0, 1,     '',      '1 = Roboter erreichbar', 0, 'Erreichbar'),
        'CODE'     => array(1, 0, 9,     '',      'Statuszahl: 0 Ladestation, 1 bereit, 2 reinigt, 3 pausiert, 4 fährt zur Station, 5 fährt, 8 unbekannt, 9 Fehler', 1, 'Status'),
        'BATT'     => array(1, 0, 100,   '%',     'Batterie in Prozent', 0, 'Batterie'),
        'LAEDT'    => array(0, 0, 1,     '',      '1 = lädt gerade', 1, 'Lädt'),
        'FEHLER'   => array(1, 0, 100000, '',     'Herstellerfehlercode (0 = kein Fehler)', 1, 'Fehlercode'),
        'FSTUFE'   => array(1, -1, 4,    '',      'Schwere: -1 unbekannt, 0 keine, 1 Hinweis, 2 Warnung, 3 Fehler, 4 schwer', 1, 'Fehlerschwere'),
        'FTEIL'    => array(1, -1, 7,    '',      'Betroffenes Teil: -1 unbekannt, 0 keins, 1 Kern, 2 Strom, 3 Sensoren, 4 Motoren, 5 Navigation, 6 Anbauteile, 7 Station', 1, 'Fehler: Teil'),
        'FLAECHE'  => array(1, 0, 1000,  'm2',    'letzte Reinigung: Fläche', 1, 'Reinigung Fläche'),
        'DAUER'    => array(1, 0, 600,   'min',   'letzte Reinigung: Dauer', 1, 'Reinigung Dauer'),
        'FLAECHEG' => array(1, 0, 10000000, 'm2', 'Gesamtwerte: Fläche', 1, 'Gesamt Fläche'),
        'DAUERG'   => array(1, 0, 100000, 'h',    'Gesamtwerte: Stunden', 1, 'Gesamt Stunden'),
        'ANZAHLG'  => array(1, 0, 100000, '',     'Gesamtwerte: Anzahl Reinigungen', 1, 'Gesamt Reinigungen'),
        'FILTER'   => array(1, -1, 10000, 'h',    'Filter: Reststunden bis zum Wechsel (-1 = nicht verfügbar)', 1, 'Filter Rest'),
        'FILTER2'  => array(1, -1, 10000, 'h',    'Zweitfilter: Reststunden (-1 = nicht verfügbar)', 1, 'Zweitfilter Rest'),
        'BHAUPT'   => array(1, -1, 10000, 'h',    'Hauptbürste: Reststunden (-1 = nicht verfügbar)', 1, 'Hauptbürste Rest'),
        'BSEITE'   => array(1, -1, 10000, 'h',    'Seitenbürste: Reststunden (-1 = nicht verfügbar)', 1, 'Seitenbürste Rest'),
        'BSEITE2'  => array(1, -1, 10000, 'h',    'zweite Seitenbürste: Reststunden (-1 = nicht verfügbar)', 1, 'Seitenbürste 2 Rest'),
        'SENSOR'   => array(1, -1, 10000, 'h',    'Sensoren: Reststunden bis zum Reinigen (-1 = nicht verfügbar)', 1, 'Sensoren Rest'),
        'RAEDER'   => array(1, -1, 10000, 'h',    'Räder: Reststunden (-1 = nicht verfügbar)', 1, 'Räder Rest'),
        'MOP'      => array(1, -1, 10000, 'h',    'Wischbezug: Reststunden (-1 = nicht verfügbar)', 1, 'Wischbezug Rest'),
        'DOCKFILTER'    => array(1, -1, 100, '%', 'Filter der Station: Restanteil (-1 = keine Station)', 1, 'Station Filter'),
        'DOCKBUERSTE'   => array(1, -1, 100, '%', 'Bürste der Station: Restanteil (-1 = keine Station)', 1, 'Station Bürste'),
        'DOCKBEHAELTER' => array(1, -1, 100, '%', 'Staubbeutel der Station: Restanteil (-1 = keine Station)', 1, 'Station Staubbeutel'),
        'REINIGER' => array(1, -1, 100,  '%',    'Reinigungsmittel: Restanteil (-1 = nicht verfügbar)', 1, 'Reinigungsmittel'),
        'MATWARN'  => array(0, 0, 1,     '',      '1 = mindestens ein Teil unter der Warnschwelle', 1, 'Materialwarnung'),
        'BEHAELTER'  => array(1, -1, 1,  '',      'Staubbehälter eingesetzt (-1 = meldet das Gerät nicht)', 1, 'Staubbehälter'),
        'WASSERTANK' => array(1, -1, 1,  '',      'Wassertank eingesetzt (-1 = meldet das Gerät nicht)', 1, 'Wassertank'),
        'WISCHER'    => array(1, -1, 1,  '',      'Wischmodul angebaut (-1 = meldet das Gerät nicht)', 1, 'Wischmodul'),
        'DOCK'     => array(1, -1, 9,    '',      'Station: -1 keine, 0 bereit, 1 Pause, 2 saugt ab, 3 reinigt, 4 trocknet, 9 Fehler', 1, 'Station Zustand'),
        'SAUGST'   => array(1, -1, 7,    '',      'Saugstufe: -1 unbekannt, 0 aus, 1 min, 2 niedrig, 3 mittel, 4 hoch, 5 max, 6 turbo, 7 eigen', 1, 'Saugstufe'),
        'WASSER'   => array(1, -1, 7,    '',      'Wischwasser: -1 unbekannt, 0 aus, 1 min, 2 niedrig, 3 mittel, 4 hoch, 5 max, 6 turbo, 7 eigen', 1, 'Wischwasser'),
        'MODUS'    => array(1, -1, 4,    '',      'Betriebsart: -1 unbekannt, 1 saugen, 2 wischen, 3 saugen und wischen, 4 erst saugen, dann wischen', 1, 'Betriebsart'),
        'EVENT'    => array(1, 0, 99,    '',      'Anzahl offener Valetudo-Ereignisse', 1, 'Ereignisse offen'),
        'EVTYP'    => array(1, 0, 8,     '',      'Jüngstes Ereignis: 0 keins, 1 Staubbehälter voll, 2 Verbrauchsteil leer, 3 Wischmodul prüfen, 4 Störung, 5 Karte geändert, 6 Valetudo aktualisiert, 7 Valetudo-Fehler, 8 unbekannt', 1, 'Ereignisart'),
        'EVMUELL'  => array(0, 0, 1,     '',      '1 = Staubbehälter voll (Valetudo meldet es)', 1, 'Behälter voll'),
        'ANN'      => array(0, 0, 1,     '',      'Meldefenster aktiv', 0, 'Meldefenster'),
        'AUDIO'    => array(0, 0, 1,     '',      'Ansage freigegeben', 1, 'Ansage frei'),
        'PUSH'     => array(0, 0, 1,     '',      'Push freigegeben', 1, 'Push frei'),
        'PTEST'    => array(0, 0, 1,     '',      'Test-Push auslösen', 0, 'Test-Push'),
        'ALTER'    => array(1, -1, 100000, 's',   'Alter des letzten Cron-Laufs in Sekunden (-1 = noch keiner). Gehört auf eine Überwachung: ein festgefrorenes Ergebnis sieht sonst aus wie ein frisches.', 0, 'Alter letzter Lauf'),
        'ZAEHLER'  => array(1, 0, 999,   '',      'Laufzähler, läuft 0...999 um - steht er still, läuft der Cron nicht mehr', 0, 'Laufzähler'),
    );
    foreach ($f as $name => $z) {
        $t = ro_t('FELD.' . $name);
        if ($t !== 'FELD.' . $name) { $f[$name][4] = $t; }
    }
    return $f;
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
        /* Derselbe Vorsatz wie in der Ausgangsvorlage. Ohne ihn heisst der
         * Baustein in der Anlage schlicht "Status" - und so heisst dort
         * bereits ein TextState (gemessen 06.09.2026). */
        $kachel = 'Saugroboter' . ($dev > 1 ? ' ' . $dev : '') . ': '
                . (isset($f[6]) && $f[6] !== '' ? $f[6] : $name);
        if ($st !== null && $min < 0 && (int) ro_feldwert($name, $st) === -1) { continue; }
        $cmds[] = array(
            'title' => 'ROBO_' . $name . ($dev > 1 ? '_' . $dev : ''),
            /* Der Comment wird in Loxone Config zum ANZEIGENAMEN. Deshalb der
             * kurze Kachelname aus der Feldtabelle, nicht die Erklaerspalte. */
            'comment' => $kachel . ($einheit !== '' ? ' [' . $einheit . ']' : ''),
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
                   . 'Loxone Config legt beim Import neu an und überschreibt nichts - '
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
        . ' (LoxBerry-Plugin)" Comment="Steuerbefehle über das Plugin '
        . ro_x(ro_plugin_ordner()) . ' - enthält das Aktionstoken." Address="http://'
        . ro_x(ro_host()) . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    /* Aus ro_befehle() erzeugt, nicht von Hand aufgezaehlt. Bis 1.0.14 standen
     * hier fuenf feste Zeilen, waehrend der Endpunkt acht Befehle kannte -
     * segments, fan und goto fehlten in der Vorlage vollstaendig. */
    /* Der Comment wird in Loxone Config zum ANZEIGENAMEN, nicht zur
     * Dokumentation. Deshalb steht hier der kurze dritte Eintrag aus
     * ro_befehle() und nicht die Erklaerspalte: die trug bis 1.1.4 bis zu
     * 98 Zeichen, und genau die standen danach als Bausteinname da.
     * Der Vorsatz macht den Namen in der Bausteinsuche eindeutig - dort
     * fehlt der Geraeteknoten (Regeln/07). */
    $vorsatz = 'Saugroboter' . ($dev > 1 ? ' ' . $dev : '') . ': ';
    foreach (ro_befehle() as $name => $b) {
        list($beispiel, $zweck) = $b;
        $anzeige = $vorsatz . (isset($b[2]) && $b[2] !== '' ? $b[2] : $zweck);
        $werte = array('cmd' => $name);
        if ($beispiel !== '') { $werte['p'] = $beispiel; }
        $werte['token'] = $tok;
        if ($dev > 1) { $werte['dev'] = $dev; }
        $o .= "\t" . '<VirtualOutCmd Title="' . ro_x(ucfirst($name) . ($dev > 1 ? ' ' . $dev : ''))
            . '" Comment="' . ro_x($anzeige) . '" CmdOnMethod="GET" CmdOffMethod="GET" ';
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
    /* ZWEI WACHEN, NICHT EINE.
     *
     * Bis 1.1.3 stand hier nur ro_wert_taugt() - die Formwache. Der Kopf von
     * ro_wert_pruefen() versprach dagegen "gegen dieselbe Positivliste, die
     * auch das Formular benutzt - eine zweite Wahrheit ueber zulaessige Werte
     * gibt es nicht", und genau diese Funktion wurde hier nie gerufen.
     * Gemessen am 04.09.2026: cache_sec="abc" und warn_hours=99999 wurden
     * geschrieben, Rueckgabe true - und wanderten in die Zweitschrift, wo sie
     * jedes Upgrade ueberlebten. Dieselbe Datei haette ro_sicherung_lesen()
     * abgelehnt.
     *
     * Fail closed: bei einem Durchfall wird GAR NICHTS geschrieben, und der
     * Grund steht im Protokoll - sonst sucht der Betreiber ihn in Loxone. */
    foreach ($cfg as $k => $v) {
        if (!ro_wert_taugt($v)) {
            ro_log('Nicht gespeichert: der Wert von "' . $k . '" traegt Steuerzeichen '
                 . 'oder ist zu lang.');
            return false;
        }
        if (array_key_exists($k, ro_vorgaben())) {
            $grund = ro_wert_pruefen($k, $v);
            if ($grund !== '') {
                ro_log('Nicht gespeichert: unzulaessiger Wert bei "' . $k . '" - ' . $grund);
                return false;
            }
        }
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
    // M3: der Stand VOR dem Schreiben - fuer Praefixwechsel, MQTT aus, ausgetragene Roboter.
    $vorher = ro_config();
    if (!ro_write_atomic($p['config'], $js, 0600)) { return false; }
    ro_mqtt_wechsel_vormerken($vorher, $cfg);
    /* C8 (Durchgang 01.10.2026): auch die Zweitschrift wird geprueft. Bis
     * 1.1.11 wurde ihr Rueckgabewert verworfen; scheiterte sie, blieb darin
     * ein altes Token, und ein Update spielte es zurueck. Die Konfiguration
     * selbst steht dann - deshalb kein Abbruch, aber eine Protokollzeile
     * (einmal, bis es wieder gelingt). */
    if (!ro_write_atomic($p['backup'], $js, 0600)) {
        ro_log_if_changed('zweitschrift', 'Die Zweitschrift ' . $p['backup'] . ' liess sich nicht schreiben - '
            . 'ein Update wuerde einen aelteren Stand zurueckspielen. Platz und Rechte im Ordner pruefen.');
    } elseif (is_file(ro_tmpdir() . '/last_zweitschrift.txt')) {
        @unlink(ro_tmpdir() . '/last_zweitschrift.txt');
    }
    ro_cache_leeren();
    return true;
}

/** Zwischenspeicher der Zustaende wegraeumen - ueber ro_paths(), nicht ueber
 *  einen fest verdrahteten Pfad. */
function ro_cache_leeren()
{
    // M2: auch das gesendete MQTT-Abbild - nach jedem Speichern geht der volle Satz hinaus.
    foreach (array('state_*.json', 'stumm_*', 'segments_*.json', 'caps_*.json', 'info_*.json',
                   'mqtt_gesendet_*.json') as $muster) {
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
    /* C7 (Durchgang 01.10.2026, Bauart E): ERST der Typ, dann das Muster.
     * Bis 1.1.11 wurde vor jedem Muster mit (string) oder (int) umgewandelt -
     * eine Liste wurde zu "Array" oder 1, und 7 von 9 kaputten Sicherungen
     * gingen durch (robots[0].ip als Liste -> "R1 (Array:..)", mqtt_topic als
     * Liste -> Themen unter Array/..., leeres Aktionstoken -> 403 an jeder
     * Loxone-Adresse; in WSL und unter 7.4/8.5 gemessen, Code-Pruefer t5/t6).
     * Zahlen: eine ganze Zahl oder eine reine Ziffernfolge, nichts anderes
     * ("80abc" ist kein Port). U12: die Gruende kommen aus der Sprachdatei. */
    switch ($schluessel) {
        case 'robots':
            if (!is_array($wert)) { return ro_t('GRUND.LISTE'); }
            if (count($wert) > 2) { return ro_t('GRUND.ROBOTER_MAX'); }
            foreach ($wert as $r) {
                if (!is_array($r)) { return ro_t('GRUND.ROBOTER_ZEILE'); }
                foreach (array_keys($r) as $k) {
                    if (!in_array($k, array('nr', 'name', 'ip', 'port', 'user', 'pass'), true)) {
                        return sprintf(ro_t('GRUND.ROBOTER_FELD'), $k);
                    }
                }
                foreach (array('name', 'ip', 'user', 'pass') as $k) {
                    if (isset($r[$k]) && !is_string($r[$k])) { return sprintf(ro_t('GRUND.TEXT'), $k); }
                }
                if (isset($r['ip']) && $r['ip'] !== '' && !preg_match('/^[\w\.\-]{1,253}\z/', $r['ip'])) {
                    return ro_t('GRUND.ADRESSE');
                }
                // Die Geraetenummer ist eine Adresse (seit 1.1.4) und wird
                // wie jeder andere Wert geprueft, nicht nur mitgenommen.
                if (isset($r['port']) && !ro_ganz_ok($r['port'], 1, 65535)) { return ro_t('GRUND.PORT'); }
                if (isset($r['nr']) && !ro_ganz_ok($r['nr'], 1, 9)) { return ro_t('GRUND.NR'); }
            }
            return '';
        case 'cache_sec':
            return ro_ganz_ok($wert, 5, 300) ? '' : sprintf(ro_t('GRUND.BEREICH'), 5, 300);
        case 'warn_hours':
            return ro_ganz_ok($wert, 0, 200) ? '' : sprintf(ro_t('GRUND.BEREICH'), 0, 200);
        case 'warn_prozent':
            return ro_ganz_ok($wert, 0, 100) ? '' : sprintf(ro_t('GRUND.BEREICH'), 0, 100);
        case 'mqtt_enabled':
            return ((is_int($wert) || is_string($wert)) && in_array((string) $wert, array('0', '1'), true))
                   ? '' : ro_t('GRUND.NULL_EINS');
        case 'mqtt_topic':
            return (is_string($wert) && preg_match('#^[\w\-]+(/[\w\-]+)*\z#', $wert))
                   ? '' : ro_t('GRUND.THEMA');
        case 'aktionstoken':
            /* Mindestens 8 Zeichen: ein leeres Token schaltet jede Loxone-
             * Adresse auf 403 (Klasse 10). Erzeugt werden 24 (ro_token_erzeugen). */
            return (is_string($wert) && preg_match('/^[A-Za-z0-9]{8,64}\z/', $wert))
                   ? '' : ro_t('GRUND.TOKEN');
        case 'notify':
            if (!is_array($wert)) { return ro_t('GRUND.LISTE'); }
            foreach ($wert as $k => $v) {
                if (!in_array($k, array('audio', 'push', 'fertig', 'fehler', 'material', 'ereignis'), true)) {
                    return sprintf(ro_t('GRUND.SCHALTER'), $k);
                }
                if (!((is_int($v) || is_string($v)) && in_array((string) $v, array('0', '1'), true))) {
                    return sprintf(ro_t('GRUND.SCHALTER_WERT'), $k);
                }
            }
            return '';
        case 'tts':
            if (!is_array($wert)) { return ro_t('GRUND.LISTE'); }
            foreach ($wert as $k => $v) {
                if (!in_array($k, ro_tts_schluessel(), true)) {
                    return sprintf(ro_t('GRUND.TTS_FELD'), $k);
                }
            }
            if (isset($wert['mode']) && !(is_string($wert['mode']) && in_array($wert['mode'], ro_tts_wege(), true))) {
                return ro_t('GRUND.TTS_WEG');
            }
            if (isset($wert['ip']) && !(is_string($wert['ip'])
                    && ($wert['ip'] === '' || preg_match('/^[\w\.\-]{1,253}\z/', $wert['ip'])))) {
                return ro_t('GRUND.TTS_ADRESSE');
            }
            if (isset($wert['port']) && !ro_ganz_ok($wert['port'], 1, 65535)) { return ro_t('GRUND.TTS_PORT'); }
            if (isset($wert['volume']) && !ro_ganz_ok($wert['volume'], 1, 100)) { return ro_t('GRUND.LAUTSTAERKE'); }
            if (isset($wert['zones']) && !(is_string($wert['zones']) && preg_match('/^[0-9,~ ]*\z/', $wert['zones']))) {
                return ro_t('GRUND.ZONEN');
            }
            if (isset($wert['lang']) && !(is_string($wert['lang']) && preg_match('/^[a-z]{0,5}\z/', $wert['lang']))) {
                return ro_t('GRUND.SPRACHE');
            }
            if (isset($wert['template']) && !(is_string($wert['template']) && strlen($wert['template']) <= 2000)) {
                return ro_t('GRUND.VORLAGE');
            }
            /* Ansage-2/-3: die Felder beider Sprech-Ausgabearten (Alexa-NG zuerst). */
            $sg = ro_tts_sprech_pruefen($wert, 'alexa');
            return $sg !== '' ? $sg : ro_tts_sprech_pruefen($wert, 'google');
    }
    return ro_t('GRUND.UNBEKANNT');
}

/** Eine ganze Zahl im Bereich - als int oder reine Ziffernfolge (C7). */
function ro_ganz_ok($w, $min, $max)
{
    if (is_int($w)) {
        $z = $w;
    } elseif (is_string($w) && preg_match('/^-?[0-9]{1,9}\z/', $w)) {
        $z = (int) $w;
    } else {
        return false;
    }
    return $z >= $min && $z <= $max;
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
    // C7: was aus der geltenden Konfiguration behalten wurde (fuer die Meldung).
    $behalten = array();
    $jetzt = ro_config();
    /* DER SCHLUESSELNAME KOMMT AUS EINER FREMDEN DATEI UND WIRD MASKIERT.
     *
     * ro_wert_taugt() prueft WERTE, nie Schluessel. Die Meldungen unten gehen
     * unverändert in die Admin-Seite (index.php gibt sie roh aus, weil die
     * Texte selbst Auszeichnung tragen duerfen). Gemessen am 04.09.2026 am
     * laufenden Server, mit gueltigem Formularmerkmal: eine Sicherungsdatei
     * mit dem Schluessel <img src=x onerror=...> wurde richtig abgelehnt - und
     * die Marke stand danach ROH in der Seite, auf der auch das Aktionstoken
     * steht. Der Weg ist eng (jemand muss den Bediener zum Einspielen einer
     * untergeschobenen Datei bringen), aber es ist genau der Weg, zu dem der
     * Knopf "Einstellungen zurueckspielen" einlaedt. */
    foreach ($daten as $k => $w) {
        $k = (string) $k;
        if ($k !== '' && $k[0] === '_') { continue; }   // lesbarer Kopf
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(ro_t('TEXT.SICH_FREMD'), rb_e($k));
            continue;
        }
        if (!ro_wert_taugt($w)) {
            $mangel[] = sprintf(ro_t('TEXT.SICH_WERT'), rb_e($k), ro_t('TEXT.SICH_STEUERZEICHEN'));
            continue;
        }
        /* C7 (Durchgang 01.10.2026, Bauart E): ein LEERES Aktionstoken in der
         * Datei ersetzt das geltende nicht - das geltende bleibt. Bis 1.1.11
         * wurde es angenommen ("9 Werte uebernommen"), der Endpunkt antwortete
         * danach 403 KEIN_TOKEN_EINGERICHTET, und der naechste Seitenaufruf
         * wuerfelte still ein neues Token: jede Loxone-Adresse war ungueltig
         * (in WSL gemessen, Oberflaechen-Pruefer Fall 8). Ist keines gespeichert,
         * wird die Datei beanstandet. */
        /* Ansage-2: Sicherungen tragen nie ein Sprechtoken fuer Alexa-NG.
         * Bringt eine Datei eines mit, wird sie abgewiesen - sie stammt nicht
         * aus "Einstellungen sichern", und das geltende Token bleibt. */
        /* Ansage-3: ebenso nie ein Sprechtoken fuer Chromecast 4 Lox NG (auch
         * nicht als Liste oder leere Liste - nur "" oder ein fehlender Schluessel). */
        if ($k === 'tts' && is_array($w)) {
            $sprech_mangel = 0;
            if (array_key_exists('alexa_token', $w) && $w['alexa_token'] !== '') {
                $mangel[] = ro_t('TEXT.SICH_ALEXA_TOKEN');
                $sprech_mangel++;
            }
            if (array_key_exists('google_token', $w) && $w['google_token'] !== '') {
                $mangel[] = ro_t('TEXT.SICH_GOOGLE_TOKEN');
                $sprech_mangel++;
            }
            if ($sprech_mangel) { continue; }
        }
        if ($k === 'aktionstoken' && $w === '') {
            $tok = (isset($jetzt['aktionstoken']) && is_string($jetzt['aktionstoken'])) ? $jetzt['aktionstoken'] : '';
            if (ro_wert_pruefen('aktionstoken', $tok) === '') {
                $neu['aktionstoken'] = $tok;
                $behalten[] = 'aktionstoken';
                $anzahl++;
            } else {
                $mangel[] = sprintf(ro_t('TEXT.SICH_WERT'), rb_e($k), rb_e(ro_t('GRUND.TOKEN_LEER')));
            }
            continue;
        }
        $grund = ro_wert_pruefen($k, $w);
        if ($grund !== '') {
            /* Auch der GRUND kann einen fremden Namen tragen:
             * ro_wert_pruefen() setzt bei notify und tts den unbekannten
             * Schalternamen in den Text ein. */
            $mangel[] = sprintf(ro_t('TEXT.SICH_WERT'), rb_e($k), rb_e($grund));
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = ro_t('TEXT.SICH_LEER');
    }
    // Ansage-2: das geltende Sprechtoken bleibt (die Sicherung traegt keines).
    if (isset($neu['tts']) && is_array($neu['tts'])) {
        $neu['tts']['alexa_token'] = (isset($jetzt['tts']['alexa_token']) && is_string($jetzt['tts']['alexa_token']))
            ? $jetzt['tts']['alexa_token'] : '';
        // Ansage-3: ebenso das geltende Sprechtoken fuer Chromecast 4 Lox NG.
        $neu['tts']['google_token'] = (isset($jetzt['tts']['google_token']) && is_string($jetzt['tts']['google_token']))
            ? $jetzt['tts']['google_token'] : '';
    }
    /* FEHLENDE Schluessel sind eine Beanstandung, kein stiller Rueckfall.
     *
     * Bis hierher war die Vorgabenliste der Ausgangspunkt, und nur was in
     * der Datei stand wurde darueber geschrieben. Eine Datei mit einem
     * einzigen Schluessel lief damit ohne Beanstandung durch, wurde
     * gespeichert, und alle uebrigen Einstellungen fielen auf Werk
     * zurueck - quittiert mit "1 Wert uebernommen".
     *
     * Gemessen an VolkswagenID 0.9.11 am 03.09.2026 unter PHP 7.4 und 8.4:
     * dort fiel dabei auch das Aktionstoken auf '', und jede im Miniserver
     * eingetragene Adresse war stumm ungueltig. Am 07.09.2026 ueber den
     * Bestand ausgerollt (30 Linien).
     *
     * Der Hausstandard sagt: eine halb gueltige Datei aendert gar nichts.
     * Verglichen wird gegen die VORGABEN, nicht gegen $bekannt: was
     * ausserhalb der Konfigurationsdatei liegt - Zugangsdaten in einer
     * eigenen Datei - faellt nicht auf Werk zurueck und darf hier fehlen. */
    $fehlend = array();
    foreach (array_keys(ro_vorgaben()) as $fk) {
        if (!array_key_exists($fk, $daten)) {
            $fehlend[] = $fk;
        }
    }
    if ($fehlend) {
        $mangel[] = sprintf(ro_t('TEXT.SICH_FEHLEND'), count($fehlend),
            htmlspecialchars(implode(', ', $fehlend), ENT_QUOTES, 'UTF-8'));
    }
    return array($mangel ? null : $neu, $mangel, $anzahl, $behalten);
}

/**
 * X-3 (Durchgang 01.10.2026): Welche Einstellungen wuerde das Zurueckspielen
 * der EIGENEN Sicherung abweisen? Gebaut wird genau die Datei, die
 * "Einstellungen sichern" liefert, und durch dieselbe Pruefung geschickt wie
 * beim Zurueckspielen (ro_sicherung_lesen()). Rueckgabe: Namen, nie Werte;
 * leer heisst "wuerde angenommen".
 *
 * Der Fall, der hier anschlaegt: ein gespeicherter Wert, den eine aeltere
 * Fassung oder eine Handaenderung hinterlassen hat (gemessen: cache_sec=999 -
 * die Sicherung kam ohne Warnung, das Zurueckspielen wies sie ab,
 * Oberflaechen-Pruefer Fall 7).
 */
function ro_sicherung_warnung(array $aus)
{
    $namen = array();
    foreach ($aus as $k => $w) {
        $k = (string) $k;
        if ($k !== '' && $k[0] === '_') { continue; }
        if ($k === 'aktionstoken' && $w === '') { continue; }   // das geltende bliebe
        if (!ro_wert_taugt($w) || ro_wert_pruefen($k, $w) !== '') { $namen[] = $k; }
    }
    return $namen;
}

/** Was in die Sicherungsdatei geschrieben wird - mit lesbarem Kopf. */
function ro_sicherung_bauen()
{
    $cfg = ro_config();
    $aus = array(
        '_hinweis' => 'Sicherung des LoxBerry-Plugins Saugroboter (Valetudo). '
                    . 'Enthaelt das Aktionstoken der Anlage - wie ein Passwort behandeln. '
                    . 'Die Sprechtoken fuer Alexa-NG und Chromecast 4 Lox NG sind nicht enthalten.',
        '_stand'   => date('Y-m-d H:i'),
        '_fassung' => ro_pluginversion(),
    );
    // Nur die bekannten Schluessel, in der Reihenfolge der Vorgaben. So kann
    // nichts in die Datei geraten, was die Leseseite danach ablehnt.
    foreach (array_keys(ro_vorgaben()) as $k) {
        $aus[$k] = isset($cfg[$k]) ? $cfg[$k] : null;
    }
    /* Ansage-2 (01.10.2026): das Sprechtoken fuer Alexa-NG ist ein Kennwort
     * eines anderen Plugins und geht nie mit; das Zurueckspielen behaelt das
     * geltende (ro_sicherung_lesen()). */
    if (isset($aus['tts']) && is_array($aus['tts'])) { $aus['tts'] = ansage_sicherung_bereinigen($aus['tts']); }   // Nr. 36 b: eine Quelle
    /* X-3: wuerde das eigene Zurueckspielen diese Datei abweisen, sagt es der
     * Kopf - nur Namen, nie Werte. Geliefert wird sie trotzdem vollstaendig. */
    $warn = ro_sicherung_warnung($aus);
    if ($warn) {
        $aus = array('_warnung' => sprintf(ro_t('TEXT.SICH_WARN_KOPF'), implode(', ', $warn))) + $aus;
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
        /* Ueber den Ordnernamen fragen (Regeln/03): ohne Argument haengt die
         * Antwort am ersten eingebundenen Skript - am Geraet gemessen
         * 17.09.2026: aus einem fremden Einstieg (php -r) NULL, mit dem
         * Ordnernamen die installierte Fassung. Installiert liegt diese Datei
         * unter webfrontend/html(auth)/plugins/<ordner>/. */
        $v = (string) LBSystem::pluginversion(basename(__DIR__));
    }
    /* DIE plugin.cfg WIRD GAR NICHT MITINSTALLIERT.
     *
     * Gemessen am 05.09.2026 an sbin/plugininstall.pl des LoxBerry-Kerns
     * (Zweig master): die Datei wird ausschliesslich im Auspackordner gelesen
     * (Zeilen 427-450) und an keiner Stelle in den Installationsbaum kopiert.
     * Die beiden frueheren Rueckfallpfade zeigten auf
     * <home>/webfrontend/plugin.cfg und <home>/webfrontend/html/plugin.cfg -
     * beides gibt es installiert nicht; sie trafen nur den ausgepackten
     * Archivbau. Gemessen am laufenden Server in der Installationslage
     * antwortete ?selftest=1 deshalb mit "FASSUNG=" - leer.
     *
     * Die belegte Quelle ist die Plugin-Datenbank des Kerns. Der Endpunkt
     * laedt das SDK nicht (das taete nur die Oberflaeche), liest die Datei
     * also selbst. */
    if ($v === '') {
        $p = ro_paths();
        if ($p['lbhome'] !== '') {
            /* Der Ort und die Gestalt sind am SDK des Kerns abgelesen, nicht
             * geraten: LBSDATADIR = LBHOMEDIR . "/data/system" und
             * PLUGINDATABASE = "$lbsdatadir/plugindatabase.json"
             * (libs/phplib/loxberry_system.php, Zeilen 73 und 111). Verglichen
             * wird "folder" gegen den Plugin-Ordner, genau wie LBSystem::
             * plugindata() es tut (ebenda, Zeile 637). */
            $db = @json_decode((string) @file_get_contents(
                $p['lbhome'] . '/data/system/plugindatabase.json'), true);
            $ordner = ro_plugin_ordner();
            $liste = isset($db['plugins']) && is_array($db['plugins']) ? $db['plugins'] : array();
            foreach ($liste as $eintrag) {
                if (!is_array($eintrag)) { continue; }
                if (isset($eintrag['folder']) && (string) $eintrag['folder'] === $ordner
                    && isset($eintrag['version']) && (string) $eintrag['version'] !== '') {
                    $v = (string) $eintrag['version'];
                    break;
                }
            }
        }
    }
    /* Rueckfall fuer den ausgepackten Archivbau (Entwicklung, Pruefstand):
     * dort liegt die plugin.cfg zwei Ebenen ueber dieser Datei. */
    if ($v === '') {
        $f = dirname(dirname(__DIR__)) . '/plugin.cfg';
        if (is_file($f)) {
            foreach (ro_log_tail($f, 200) as $z) {
                if (preg_match('/^VERSION\s*=\s*([0-9][0-9A-Za-z\.\-]*)/', trim($z), $m)) {
                    $v = $m[1]; break;
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
/**
 * Passen Reiterleiste, Bereiche und Positivliste zusammen?
 *
 * DER KOMMENTAR IN index.php VERSPRACH DAS BIS 1.1.3, UND ES GAB DIE PRUEFUNG
 * NICHT. Nachgewiesen am 04.09.2026 durch Rueckbau an einer Kopie: 'tab-log'
 * aus der Positivliste entfernt - der Reiter blieb in der Leiste stehen,
 * fuehrte aber auf die Einstellungen, und die ganze Prueflette blieb gruen
 * (hausstandard_pruefen.py gab byteweise dieselbe Ausgabe aus, Spalte tab
 * weiterhin ein Strich, Freigabetor "offene Spalten: keine").
 *
 * Zwei zu vergleichen genuegt nicht - eine Gegenprobe, die einen Namen aus
 * der Quelle entfernt, laesst den Reiter unerreichbar und die Pruefung gruen.
 * Deshalb ALLE DREI Stellen aus der gerenderten Datei selbst:
 *
 *   1. die Leiste       data-pane="tab-..."
 *   2. die Bereiche     id="tab-..."
 *   3. die Positivliste $rb_reiterliste = array('tab-...', ...)
 *
 * Rueckgabe: array(gefunden je Stelle, fehlend, ueberzaehlig). Ist die Datei
 * nicht lesbar, wird das GESAGT und nicht als "in Ordnung" gewertet.
 */
/** Wo liegt die Oberflaechendatei? EINE Stelle fuer alle Pruefzeilen,
 *  die an ihr messen. */
function ro_oberflaeche_datei()
{
    $p = ro_paths();
    $kandidaten = array();
    if ($p['lbhome'] !== '') {
        $kandidaten[] = $p['lbhome'] . '/webfrontend/htmlauth/plugins/' . $p['plugin'] . '/index.php';
    }
    $kandidaten[] = dirname(dirname(__DIR__)) . '/webfrontend/htmlauth/index.php';
    foreach ($kandidaten as $k) { if (is_file($k)) { return $k; } }
    return '';
}

function ro_reiterlage()
{
    $datei = ro_oberflaeche_datei();
    if ($datei === '') {
        return array('lesbar' => false, 'leiste' => array(), 'bereiche' => array(),
                     'liste' => array(), 'fehlend' => array(), 'ueberzaehlig' => array());
    }
    $t = (string) @file_get_contents($datei);
    preg_match_all('/data-pane="(tab-[a-z0-9]+)"/', $t, $m1);
    preg_match_all('/id="(tab-[a-z0-9]+)"/', $t, $m2);
    $liste = array();
    if (preg_match('/\$rb_reiterliste\s*=\s*array\(([^)]*)\)/', $t, $m3)) {
        preg_match_all("/'(tab-[a-z0-9]+)'/", $m3[1], $m4);
        $liste = $m4[1];
    }
    $leiste = array_values(array_unique($m1[1]));
    $bereiche = array_values(array_unique($m2[1]));
    $liste = array_values(array_unique($liste));
    sort($leiste); sort($bereiche); sort($liste);
    return array(
        'lesbar' => true,
        'leiste' => $leiste, 'bereiche' => $bereiche, 'liste' => $liste,
        // Was in der Leiste steht, muss einen Bereich UND einen Listeneintrag haben.
        'fehlend' => array_values(array_unique(array_merge(
            array_diff($leiste, $bereiche), array_diff($leiste, $liste)))),
        // Und umgekehrt: kein Bereich und kein Listeneintrag ohne Reiter.
        'ueberzaehlig' => array_values(array_unique(array_merge(
            array_diff($bereiche, $leiste), array_diff($liste, $leiste)))),
    );
}

/**
 * U9 (Durchgang 01.10.2026): Tragen alle Formulare der Oberflaeche das
 * Merkmal, und setzt der Server sm-active? Gezaehlt in der EIGENEN Datei
 * (Regeln/04, Pflichtzeilen): jedes <form>...</form> muss rb_fmt() tragen,
 * jeder Reiter der Positivliste muss die serverseitige Bedingung genau
 * zweimal haben (Leiste und Bereich).
 * Rueckgabe: array('lesbar', 'formulare', 'mit_merkmal', 'ohne_active' => Liste).
 */
function ro_formularlage()
{
    $datei = ro_oberflaeche_datei();
    $aus = array('lesbar' => false, 'formulare' => 0, 'mit_merkmal' => 0, 'ohne_active' => array());
    if ($datei === '') { return $aus; }
    $t = (string) @file_get_contents($datei);
    if ($t === '') { return $aus; }
    $aus['lesbar'] = true;
    preg_match_all('#<form\b.*?</form>#s', $t, $m);
    $aus['formulare'] = count($m[0]);
    foreach ($m[0] as $f) {
        if (strpos($f, 'rb_fmt()') !== false) { $aus['mit_merkmal']++; }
    }
    $rl = ro_reiterlage();
    foreach ($rl['liste'] as $tab) {
        $n = substr_count($t, "\$rb_tab === '" . $tab . "' ? ' sm-active'");
        if ($n !== 2) { $aus['ohne_active'][] = $tab; }
    }
    return $aus;
}

/** Der Port des LoxBerry-Webservers (general.json, Webserver.Port), sonst 80. */
function ro_webport()
{
    /* Nr. 36 b, Stufe 1: gemeinsame Sprachausgabe (Webserver.Port oder
     * WEBSERVER.Port, 1 bis 65535, sonst 80); einmal je Prozess gelesen wie
     * bisher. */
    static $port = null;
    if ($port !== null) { return $port; }
    $p = ro_paths();
    $port = ansage_webport($p['general']);
    return $port;
}

/**
 * U9: Antwortet der eigene Endpunkt? Ein echter Aufruf ?selftest=1 auf
 * 127.0.0.1 (loest nichts aus). Findet die getrennten Baeume, die keine
 * Leseprobe sieht. Rueckgabe array(Stand 1/0, Text).
 */
function ro_endpunkt_probe($token)
{
    $url = 'http://127.0.0.1' . (ro_webport() === 80 ? '' : ':' . ro_webport())
         . ro_endpunkt_pfad(array('selftest' => 1, 'token' => $token));
    list($r, $code) = ro_http($url, array('method' => 'GET', 'timeout' => 3, 'ignore_errors' => true,
        'follow_location' => 0, 'user_agent' => 'LoxBerry Saugroboter Selbstpruefung'));
    $z = trim((string) strtok((string) $r, "\n"));
    if ($code === 200 && strpos($z, 'SELFTEST;OK=1') === 0) {
        return array(1, sprintf(ro_t('PRUEF.ENDPUNKT_OK'), ro_endpunkt_pfad()));
    }
    $z = substr((string) preg_replace('/[^A-Za-z0-9;=_.:\-]/', '', $z), 0, 60);
    return array(0, sprintf(ro_t('PRUEF.ENDPUNKT_FEHL'), $code > 0 ? 'HTTP ' . $code : ro_t('PRUEF.KEINE_ANTWORT'),
        $z !== '' ? $z : '-'));
}

/**
 * $opt['test_offen']: der Reiter Test ist der aktive - nur dann werden
 * Aufrufe gemacht, die warten koennen (eigener Endpunkt, Alexa-NG). Der
 * Reiter Test wird bei JEDEM Seitenaufbau mitgerendert.
 */
function ro_selbsttest($opt = array())
{
    $test_offen = !empty($opt['test_offen']);
    $cfg = ro_config();
    $z = array();
    $add = function ($schluessel, $ok, $text) use (&$z) {
        $z[] = array('bez' => $schluessel, 'ok' => (int) $ok, 'text' => (string) $text);
    };

    $robots = ro_robots();
    $add('PRUEF.ROBOTER', $robots ? 1 : 0, (string) count($robots));
    $add('PRUEF.TOKEN', trim((string) $cfg['aktionstoken']) !== '' ? 1 : 0, '');

    $erreicht = 0; $modelle = array(); $unbekannt = array();
    $evlesbar = 0; $evzahl = 0; $evgeprueft = 0;
    foreach (array_keys($robots) as $n) {
        $st = ro_state($n);
        if ($st['ok']) { $erreicht++; }
        /* Aus dem schon geholten Zustand, nicht aus einer neuen Abfrage:
         * der Reiter Test wird bei JEDEM Seitenaufbau mitgerendert. */
        if (array_key_exists('evlesbar', $st)) {
            $evgeprueft++;
            if ($st['evlesbar']) { $evlesbar++; $evzahl += (int) $st['event']; }
        }
        $i = ro_robotinfo($n);
        if (!empty($i['modell'])) {
            $modelle[] = trim($i['hersteller'] . ' ' . $i['modell'])
                       . ($i['valetudo'] !== '' ? ' / Valetudo ' . $i['valetudo'] : '');
        }
        foreach ((array) $st['material_fremd'] as $f) { $unbekannt[$f] = 1; }
    }
    /* U7 (Durchgang 01.10.2026): kein Urteil ueber eine leere Menge. Ohne
     * Roboter stand hier ein Kreuz "0/0" (Oberflaechen-Pruefer Fall 10). */
    if (!$robots) {
        $add('PRUEF.ERREICHBAR', 2, ro_t('PRUEF.KEIN_ROBOTER'));
    } else {
        $add('PRUEF.ERREICHBAR', ($erreicht === count($robots)) ? 1 : ($erreicht > 0 ? 2 : 0),
            $erreicht . '/' . count($robots));
    }
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

    /* Verbrauchsteile, die dieses Plugin nicht kennt - nennen, nicht
     * verschlucken. UND NICHT UEBER EINE LEERE MENGE URTEILEN: ohne
     * eingerichteten Roboter laeuft die Schleife oben gar nicht, $unbekannt
     * bleibt leer, und bis 1.1.3 meldete die Zeile dann einen Haken - genau
     * beim Neueinrichten, wenn man auf die Selbstpruefung angewiesen ist. */
    if (!$robots || $erreicht === 0) {
        $add('PRUEF.MATFREMD', 2, ro_t('PRUEF.NICHTS_GEMESSEN'));
    } else {
        $add('PRUEF.MATFREMD', $unbekannt ? 0 : 1, implode(', ', array_keys($unbekannt)));
    }

    /* Laeuft der Cron?
     *
     * Drei Ausgaenge, und der mittlere ist neu: bis 1.1.3 bekam ein Alter ueber
     * 180 Sekunden den Ausgang 2 = Strich, und die Legende sagt "Strich = nicht
     * feststellbar". 19227 Sekunden sind aber gemessen, nicht unfeststellbar -
     * ein stehender Cron sah damit aus wie ein Messproblem. Strich bleibt nur
     * fuer den Fall, dass noch nie ein Lauf stattfand. */
    $alter = ro_lauf_alter();
    if ($alter < 0) {
        $add('PRUEF.CRON', 2, ro_t('PRUEF.CRON_NIE'));
    } else {
        $add('PRUEF.CRON', $alter <= 180 ? 1 : 0, $alter . ' s');
    }

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

    /* U8 (Durchgang 01.10.2026): Ist die Konfiguration heil? Aus
     * ro_cfg_zustand() - dem ERSTEN Befund dieses Prozesses, den eine
     * Selbstheilung nicht ueberschreibt. Bis 1.1.11 wurde er gesetzt und nie
     * gelesen; eine aus der Zweitschrift geheilte Datei zeigte nur einen
     * Haken (Oberflaechen-Pruefer Fall 11). Liegt eine .kaputt-Datei, steht
     * das auch in jedem spaeteren Aufruf. */
    $cz = ro_cfg_zustand();
    $kp = ro_paths();
    $kaputt_da = is_file($kp['config'] . '.kaputt');
    if ($cz === 'aus der Zweitschrift' || $cz === 'kaputt') {
        $add('PRUEF.HEIL', 0, ro_t($cz === 'kaputt' ? 'PRUEF.HEIL_KAPUTT' : 'PRUEF.HEIL_ZWEITSCHRIFT'));
    } elseif ($kaputt_da) {
        $add('PRUEF.HEIL', 2, sprintf(ro_t('PRUEF.HEIL_KAPUTT_LIEGT'),
            basename($kp['config']) . '.kaputt', date('d.m.Y H:i', (int) @filemtime($kp['config'] . '.kaputt'))));
    } elseif ($cz === 'fehlt' || $cz === 'leer') {
        $add('PRUEF.HEIL', 2, ro_t('PRUEF.HEIL_LEER'));
    } else {
        $add('PRUEF.HEIL', 1, ro_t('PRUEF.HEIL_OK'));
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

    /* Kann die Ereignisliste ueberhaupt gelesen werden? Ohne diese Zeile ist
     * EVENT=0 eine Behauptung: bis 1.1.5 sah "nicht gelesen" genauso aus wie
     * "nichts los", und der Fehler lag ein Jahr unbemerkt. */
    if (!$robots || $evgeprueft === 0 || $erreicht === 0) {
        $add('PRUEF.EREIGNISSE', 2, ro_t('PRUEF.NICHTS_GEMESSEN'));
    } elseif ($evlesbar > 0) {
        $add('PRUEF.EREIGNISSE', 1, sprintf(ro_t('PRUEF.EREIGNISSE_OK'), $evzahl,
            '/api/v2/' . (ro_ereignis_pfad(1) !== '' ? ro_ereignis_pfad(1) : 'events')));
    } else {
        $add('PRUEF.EREIGNISSE', 0, ro_t('PRUEF.EREIGNISSE_NEIN'));
    }

    /* Passen Leiste, Bereiche und Positivliste zusammen? (siehe ro_reiterlage) */
    $rl = ro_reiterlage();
    if (!$rl['lesbar']) {
        $add('PRUEF.REITER', 2, ro_t('PRUEF.REITER_UNLESBAR'));
    } elseif ($rl['fehlend'] || $rl['ueberzaehlig']) {
        $add('PRUEF.REITER', 0, sprintf(ro_t('PRUEF.REITER_FEHLT'),
            implode(', ', array_merge($rl['fehlend'], $rl['ueberzaehlig']))));
    } else {
        $add('PRUEF.REITER', 1, sprintf(ro_t('PRUEF.REITER_OK'),
            count($rl['leiste']), count($rl['bereiche']), count($rl['liste'])));
    }

    /* U9 (Durchgang 01.10.2026): drei Pflichtzeilen aus Regeln/04. */
    $fl = ro_formularlage();
    if (!$fl['lesbar']) {
        $add('PRUEF.MERKMAL', 2, ro_t('PRUEF.REITER_UNLESBAR'));
        $add('PRUEF.ACTIVE', 2, ro_t('PRUEF.REITER_UNLESBAR'));
    } else {
        $add('PRUEF.MERKMAL', ($fl['formulare'] > 0 && $fl['formulare'] === $fl['mit_merkmal']) ? 1 : 0,
            sprintf(ro_t('PRUEF.MERKMAL_ZAHL'), $fl['mit_merkmal'], $fl['formulare']));
        $add('PRUEF.ACTIVE', $fl['ohne_active'] ? 0 : 1,
            $fl['ohne_active'] ? implode(', ', $fl['ohne_active']) : ro_t('PRUEF.ACTIVE_OK'));
    }
    if (trim((string) $cfg['aktionstoken']) === '') {
        $add('PRUEF.ENDPUNKT', 2, ro_t('PRUEF.ENDPUNKT_KEIN_TOKEN'));
    } elseif (!$test_offen) {
        $add('PRUEF.ENDPUNKT', 2, ro_t('PRUEF.ENDPUNKT_ZU'));
    } else {
        list($es, $et) = ro_endpunkt_probe((string) $cfg['aktionstoken']);
        $add('PRUEF.ENDPUNKT', $es, $et);
    }

    /* Nennt die Themenliste genau das, was der Sender bildet?
     *
     * Gemessen wird die WIRKUNG: ro_mqtt_werte() wird wirklich gebildet und
     * gegen ro_mqtt_themen() gehalten. Bis 1.1.3 lief die Tabelle ueber die
     * volle Feldliste und nannte zwei Themen, die nie hinausgingen. */
    $themen = array_keys(ro_mqtt_themen(null, 1));
    $wurzel_t = ro_mqtt_thema_saeubern($cfg['mqtt_topic']);
    $gesendet = array($wurzel_t . '/status/ok', $wurzel_t . '/status/ts',
                      $wurzel_t . '/status/zaehler');
    foreach (array_keys(ro_mqtt_werte(ro_state(1), 1)) as $k) {
        $gesendet[] = $wurzel_t . '/' . $k;
    }
    sort($themen); sort($gesendet);
    $nur_liste = array_values(array_diff($themen, $gesendet));
    $nur_sender = array_values(array_diff($gesendet, $themen));
    /* UND: rendert die Tabelle ueberhaupt noch aus dieser Liste?
     *
     * Ohne diese Frage misst die Zeile zu wenig. Aufgefallen bei der Eichung
     * durch Rueckbau: wird ro_mqtt_ausgenommen() geaendert, aendern sich Liste
     * UND Sender gleichermassen, und die Zeile bleibt gruen. Der Fehler, um den
     * es geht, war aber ein anderer - die Tabelle fuhr eine EIGENE Schleife
     * ueber ro_felder(). Genau das wird hier gemessen. */
    $ob = ro_oberflaeche_datei();
    $tabelle_aus_liste = ($ob !== '')
        && strpos((string) @file_get_contents($ob), 'ro_mqtt_themen(') !== false;
    if (!$tabelle_aus_liste) {
        $add('PRUEF.THEMEN', 0, ro_t('PRUEF.THEMEN_TABELLE'));
    } elseif ($nur_liste || $nur_sender) {
        $add('PRUEF.THEMEN', 0, sprintf(ro_t('PRUEF.THEMEN_AB'),
            implode(', ', array_merge($nur_liste, $nur_sender))));
    } else {
        $add('PRUEF.THEMEN', 1, sprintf(ro_t('PRUEF.THEMEN_OK'), count($themen)));
    }

    // Ansage-2: Alexa-NG als Ausgabeart - fragt nur bei offenem Reiter Test.
    if (isset($cfg['tts']['mode']) && $cfg['tts']['mode'] === 'alexang') {
        list($as, $at) = ro_pruef_sprech('alexa', $cfg, $test_offen);
        $add('PRUEF.ALEXA', $as, $at);
    }
    // Ansage-3: Google-Lautsprecher (Chromecast 4 Lox NG) - ebenso nur bei offenem Reiter Test.
    if (isset($cfg['tts']['mode']) && $cfg['tts']['mode'] === 'cc4lox') {
        list($gs, $gt) = ro_pruef_sprech('google', $cfg, $test_offen);
        $add('PRUEF.GOOGLE', $gs, $gt);
    }

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
