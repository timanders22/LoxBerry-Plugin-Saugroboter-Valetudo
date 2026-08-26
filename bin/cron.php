<?php
/**
 * Saugroboter (Valetudo) - minutlicher Cron-Lauf
 *
 * 1. Status aller Roboter EINMAL holen.
 * 2. Ereignisse erkennen und melden: Reinigung fertig, Fehler, Wartung
 *    faellig, Valetudo-Ereignis (Staubbehaelter voll und Verwandte).
 * 3. Lebenszeichen fortschreiben.
 * 4. MQTT bei Aenderung, mindestens halbstuendlich.
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

foreach ($ro_robots as $ro_n => $ro_r) {
    $ro_st = $ro_zustaende[$ro_n];
    /* Die Signatur traegt ALLE Werte, die hinausgehen.
     *
     * Bis 1.0.14 standen darin nur code, batterie, fehler, material_warn,
     * flaeche und anzahl_gesamt. Gemessen blieben damit liegen: das Ende
     * eines Ladevorgangs, jede Aenderung am Verbrauchsmaterial, die
     * Gesamtdauer und die Dauer der letzten Reinigung - bis zum
     * halbstuendlichen Lebenszeichen.
     *
     * Der Zaehler des Lebenszeichens gehoert NICHT hinein: er aendert sich
     * jede Minute und wuerde den Filter wirkungslos machen. Er geht ueber
     * die eigene Zeile hinaus, die ro_mqtt_publish() immer schickt. */
    $ro_sig = json_encode(ro_mqtt_werte($ro_st, $ro_n));
    if ($ro_sig === false) { $ro_sig = 'unlesbar'; }
    $ro_sigf = ro_tmpdir() . '/mqtt_sig_' . $ro_n . '.txt';
    $ro_beat = ro_tmpdir() . '/mqtt_beat_' . $ro_n;
    $ro_old = is_file($ro_sigf) ? (string) @file_get_contents($ro_sigf) : '';
    if ($ro_sig !== $ro_old || !is_file($ro_beat) || time() - filemtime($ro_beat) > 1800) {
        ro_mqtt_publish($ro_st, $ro_n);
        @file_put_contents($ro_sigf, $ro_sig);
        @touch($ro_beat);
    } else {
        /* Der Zustand ist gleich geblieben - das Lebenszeichen geht trotzdem
         * hinaus. Sonst faellt bei einem Roboter, der eine Woche in der
         * Ladestation steht, genau das Zeichen aus, das sagen soll, dass das
         * Plugin noch lebt. */
        ro_mqtt_lebenszeichen();
    }
}
/* Auch ohne konfigurierten Roboter: das Lebenszeichen sagt, dass der Cron
 * laeuft. ok = 0 sagt dazu, dass er nichts gemessen hat. */
if (!$ro_robots) { ro_mqtt_lebenszeichen(); }

echo "OK\n";

flock($ro_lock, LOCK_UN);
fclose($ro_lock);
