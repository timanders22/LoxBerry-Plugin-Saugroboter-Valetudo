<?php
/**
 * Saugroboter (Valetudo) - minutlicher Cron-Lauf
 *
 * 1. Status aller Roboter EINMAL holen.
 * 2. Ereignisse erkennen und melden: Reinigung fertig, Fehler, Wartung
 *    faellig, Valetudo-Ereignis (Staubbehaelter voll und Verwandte).
 * 3. Lebenszeichen fortschreiben.
 * 4. MQTT: vorgemerkte Altwerte abraeumen (auch bei MQTT aus), dann die
 *    geaenderten Werte, alle 30 Minuten der volle Satz, und das
 *    Lebenszeichen einmal je Lauf (Durchgang 01.10.2026, M1-M5).
 *
 * ==================================================================
 * WARUM DIESE DATEI SEIT 1.0.4 UNTER bin/ LIEGT
 * ==================================================================
 *
 * Bis 1.0.3 lag sie unter webfrontend/html/ - im UNANGEMELDETEN Bereich.
 * Aufgerufen wird sie ausschliesslich vom Minutencron ueber die
 * PHP-Kommandozeile, nie ueber HTTP; im HTML-Verzeichnis war sie
 * zusaetzlich fuer jeden abrufbar, der die LoxBerry-Oberflaeche im Netz
 * sieht. Und ein Aufruf ist nicht folgenlos: ro_events_check() kann eine
 * ANSAGE ueber den Musicserver ausloesen und das Meldefenster fuer Loxone
 * setzen - eine fremde Anfrage haette also die Wohnung sprechen lassen
 * koennen.
 *
 * Die Sperre unten begrenzt das Stapeln; sie verhindert den Aufruf nicht.
 * Deshalb der Umzug.
 * ==================================================================
 */

/* Die Bibliothek bleibt im HTML-Verzeichnis, weil dort auch robo.php liegt -
   der Endpunkt fuer den Miniserver. REPLACELBPHTMLDIR ersetzt LoxBerry bei
   der Installation. Der Rueckfall gilt dem Lauf aus dem ausgepackten Archiv,
   in dem noch nichts ersetzt wurde. Bleibt beides erfolglos, bricht das
   Skript mit einer Meldung ab, statt still nichts zu tun. */
$ro_htmldir = 'REPLACELBPHTMLDIR';
if (strpos($ro_htmldir, 'REPLACE') === 0 || !is_file($ro_htmldir . '/robo_lib.php')) {
    $ro_htmldir = dirname(__DIR__) . '/webfrontend/html';
}
if (!is_file($ro_htmldir . '/robo_lib.php')) {
    /* Zweiter Rueckfall fuer den INSTALLIERTEN Zustand, falls der Platzhalter
     * nicht ersetzt wurde: von <home>/bin/plugins/<ordner>/ aus sind es drei
     * Ebenen bis <home>. Ohne ihn liefe der Cron jede Minute ins Leere - und
     * weil cron.01min nach /dev/null schreibt, saehe das niemand. */
    $ro_kandidat = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/'
                 . basename(__DIR__);
    if (is_file($ro_kandidat . '/robo_lib.php')) { $ro_htmldir = $ro_kandidat; }
}
if (!is_file($ro_htmldir . '/robo_lib.php')) {
    fwrite(STDERR, "robo_lib.php nicht gefunden (gesucht in $ro_htmldir)\n");
    exit(1);
}
require_once $ro_htmldir . '/robo_lib.php';

/* Nur aus der Installation - oder mit LBHOMEDIR UND LBPPLUGINDIR, wie die
 * Pruefwerkzeuge und die Deinstallation es tun. Bis 1.1.9 lief diese Datei
 * aus einem ausgepackten Archiv mit Konfiguration, Zwischenspeicher und
 * Protokoll der Anlage (siehe ro_keine_wurzel_abbruch()). Die Pruefung steht
 * VOR der Sperre, denn schon die legt eine Datei an. */
$ro_argv = (isset($argv) && is_array($argv)) ? array_slice($argv, 1) : array();
if (in_array('--mqtt-leeren', $ro_argv, true)) {
    /* Aus uninstall/uninstall: die zurueckbehaltenen Themen der Linie
     * leeren (ro_mqtt_leeren()). Keine Abfrage, keine Sperre, keine Datei,
     * keine Selbstheilung der Konfiguration. */
    ro_keine_wurzel_abbruch('cron.php');
    ro_config_erzeugen_erlauben(false);
    exit(ro_mqtt_leeren());
}
ro_keine_wurzel_abbruch('cron.php');

/* ==================================================================
 * Nur ein Durchgang zur Zeit
 * ==================================================================
 *
 * Der Cron laeuft jede Minute. Ein Durchgang kostet im schlechtesten Fall
 * mehr, als man denkt: je Roboter mehrere Abrufe a 2 s, dazu eine Ansage
 * mit 10 s Zeitgrenze.
 *
 * Ueberlappen zwei Durchgaenge, ist nicht die Rechenzeit das Problem,
 * sondern die Meldung: beide lesen ev_N.json am Anfang und schreiben es
 * erst am Ende. Beide saehen denselben Uebergang "reinigt" -> "fertig" und
 * beide sagten ihn an. flock() mit LOCK_NB kostet nichts und schliesst das
 * aus.
 * ================================================================== */
$ro_lockdatei = ro_tmpdir() . '/cron.lock';
$ro_lock = @fopen($ro_lockdatei, 'c');
if ($ro_lock === false) {
    fwrite(STDERR, "Sperrdatei $ro_lockdatei nicht anlegbar\n");
    ro_log('Cron: Sperrdatei ' . $ro_lockdatei . ' nicht anlegbar.');
    exit(1);
}
if (!flock($ro_lock, LOCK_EX | LOCK_NB)) {
    // Hoechstens stuendlich protokollieren, sonst laeuft das Log voll.
    $ro_merker = ro_tmpdir() . '/cron_lock_log';
    if (!is_file($ro_merker) || time() - filemtime($ro_merker) > 3600) {
        @touch($ro_merker);
        ro_log('Cron: voriger Durchgang laeuft noch, dieser wird uebersprungen.');
    }
    exit(0);
}

/* Den Zustand EINMAL holen und weiterreichen.
 *
 * Bis 1.0.14 riefen ro_events_check() und die Schleife danach ro_state()
 * getrennt. Bei cache_sec = 20 (Vorgabe) und einem Durchgang, der bei zwei
 * antwortenden Robotern ueber 20 s kommen kann, war der Zwischenspeicher beim
 * zweiten Aufruf abgelaufen: weitere HTTP-Abrufe, und die gemeldete Signatur
 * beschrieb einen anderen Augenblick als die Ereignispruefung. */
$ro_zustaende = array();
$ro_erreicht = 0;
$ro_robots = ro_robots();
foreach ($ro_robots as $ro_n => $ro_r) {
    $ro_zustaende[$ro_n] = ro_state($ro_n);
    if (!empty($ro_zustaende[$ro_n]['ok'])) { $ro_erreicht++; }
}

ro_events_check($ro_zustaende);

/* Das Lebenszeichen. Es sagt etwas ueber den LAUF, nicht ueber einen
 * Roboter: ok = 1 heisst "dieser Durchgang hat wirklich gemessen". Ohne
 * konfigurierten Roboter gibt es nichts zu messen - dann ist ok = 0, und
 * das ist die richtige Auskunft, nicht "alles in Ordnung". */
$ro_lauf = ro_lauf_setzen($ro_robots && $ro_erreicht > 0);

/* ==================================================================
 * MQTT (Durchgang 01.10.2026, Bauliste M1-M5)
 * ==================================================================
 *
 * Bis 1.1.11 ging je Roboter bei JEDER Aenderung der volle Satz hinaus (52
 * Datagramme ohne Pause), das Lebenszeichen je Roboter, und die Signatur
 * kannte weder Praefix noch Schalter: nach einem Praefixwechsel kamen bis zu
 * 30 min keine Zustaende unter dem neuen Praefix (MQTT-Pruefer s04, s06,
 * s13, s16). Jetzt:
 *   - vorgemerkte Altwerte (altes Praefix, ausgetragener Roboter, MQTT aus)
 *     abraeumen, mit Nachlesen beim Broker - auch bei MQTT aus (M3);
 *   - je Roboter nur die geaenderten Werte gegen das gesendete Abbild, ok in
 *     jedem Lauf, alle 30 Minuten der volle Satz (ro_mqtt_senden(), M2/M4);
 *     bei einem Ausfall nur ok, code 8 und die Plugin-eigenen Werte (M1);
 *   - das Lebenszeichen EINMAL je Lauf, auch ohne Roboter;
 *   - die Abo-Datei des Gateways auf <praefix>/# (M5).
 * Ist MQTT aus, wird das Abbild verworfen: beim Wiedereinschalten geht der
 * volle Satz hinaus.
 */
ro_mqtt_raeumen_vorgemerkt();
$ro_cfg = ro_config();
if (!empty($ro_cfg['mqtt_enabled'])) {
    ro_abo_datei(ro_mqtt_thema_saeubern($ro_cfg['mqtt_topic']), true);
    foreach ($ro_robots as $ro_n => $ro_r) {
        ro_mqtt_senden($ro_zustaende[$ro_n], $ro_n, false);
    }
    ro_mqtt_lebenszeichen();
} else {
    foreach (glob(ro_tmpdir() . '/mqtt_gesendet_*.json') ?: array() as $ro_g) { @unlink($ro_g); }
}
// Reste bis 1.1.11 (Signatur und Takt je Roboter) - ersetzt durch mqtt_gesendet_N.json.
foreach (array_merge(glob(ro_tmpdir() . '/mqtt_sig_*.txt') ?: array(),
                     glob(ro_tmpdir() . '/mqtt_beat_*') ?: array()) as $ro_g) {
    @unlink($ro_g);
}

echo "OK\n";

flock($ro_lock, LOCK_UN);
fclose($ro_lock);
