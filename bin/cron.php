<?php
/**
 * Saugroboter (Valetudo) - minutlicher Cron-Lauf
 *
 * 1. Status aller Roboter aktualisieren (Cache-schonend).
 * 2. Ereignisse erkennen und melden: Reinigung fertig, Fehler, Wartung faellig.
 * 3. MQTT bei Aenderung, mindestens halbstuendlich.
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
    fwrite(STDERR, "robo_lib.php nicht gefunden (gesucht in $ro_htmldir)\n");
    exit(1);
}
require_once $ro_htmldir . '/robo_lib.php';

/* ==================================================================
 * Nur ein Durchgang zur Zeit
 * ==================================================================
 *
 * Der Cron laeuft jede Minute. Ein Durchgang kostet im schlechtesten Fall
 * mehr, als man denkt: je Roboter bis zu vier Abrufe a 2 s, dazu eine
 * Ansage mit 10 s Zeitgrenze. Bei zwei Robotern kommt so gut eine halbe
 * Minute zusammen - noch unter dem Takt, aber ohne Reserve.
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

ro_events_check();

foreach (ro_robots() as $n => $r) {
    $st = ro_state($n);
    /* Die Meldeflags gehoeren in die Signatur, sonst waeren sie zwar in der
     * Nachricht - aber die Nachricht ginge nicht raus. ann und ptest aendern
     * sich naemlich OHNE Zustandswechsel, allein durch Zeitablauf. Ohne sie
     * in der Signatur bliebe ein ptest bis zum naechsten Zustandswechsel
     * oder bis zum halbstuendlichen Lebenszeichen liegen - sein Fenster ist
     * aber nur fuenf Minuten breit. */
    $sig = json_encode(array($st['code'], $st['batterie'], $st['fehler'], $st['material_warn'],
                             $st['flaeche'], $st['anzahl_gesamt'], ro_meldeflags($n)));
    if ($sig === false) { $sig = 'unlesbar'; }
    $sigf = ro_tmpdir() . '/mqtt_sig_' . $n . '.txt';
    $beat = ro_tmpdir() . '/mqtt_beat_' . $n;
    $old = is_file($sigf) ? (string) file_get_contents($sigf) : '';
    if ($sig !== $old || !is_file($beat) || time() - filemtime($beat) > 1800) {
        ro_mqtt_publish($st, $n);
        @file_put_contents($sigf, $sig);
        @touch($beat);
    }
}
echo "OK\n";

flock($ro_lock, LOCK_UN);
fclose($ro_lock);
