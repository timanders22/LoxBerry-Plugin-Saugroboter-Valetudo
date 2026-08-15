<?php
/**
 * Saugroboter (Valetudo) - Miniserver-Endpunkt
 *
 * Abfrage (&dev=N waehlt bei mehreren Robotern das Geraet, Standard 1):
 *   (ohne Parameter) -> ROBO;OK=..;CODE=..;BATT=..;LAEDT=..;FEHLER=..;FLAECHE=..;DAUER=..;
 *                       FLAECHEG=..;DAUERG=..;ANZAHLG=..;FILTER=..;BHAUPT=..;BSEITE=..;SENSOR=..;
 *                       MATWARN=..;ANN=..;AUDIO=..;PUSH=..;PTEST=..
 *                       CODE: 0=Ladestation 1=bereit 2=reinigt 3=pausiert 4=faehrt zur Station
 *                             5=faehrt 8=unbekannt 9=Fehler
 *                       Verbrauchsmaterial in Reststunden (-1 = nicht verfuegbar)
 *
 * Steuerung (einfache GET-Aufrufe fuer virtuelle Ausgaenge; token-pflichtig):
 *   ?cmd=start | stop | pause | home | locate &token=T
 *   ?cmd=segments&p=1,4&token=T     Raeume reinigen (IDs siehe Reiter Test)
 *   ?cmd=fan&p=max&token=T          Saugstaerke (low, medium, high, max, turbo)
 *   ?cmd=goto&p=<id>&token=T        gespeicherte Position anfahren
 *   Ohne passendes Token aus dem Reiter "Einbindung in Loxone" antwortet
 *   ?cmd= mit HTTP 403.
 *
 * Weitere Aufrufe: ?debug=1  ?json=1  ?refresh=1
 *   ?ptest=1&token=T   Test-Pushnachricht anstossen (seit 1.0.4 tokenpflichtig)
 */

require_once __DIR__ . '/robo_lib.php';
$dev = isset($_GET['dev']) ? max(1, min(9, (int) $_GET['dev'])) : 1;

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    $st = ro_state($dev, isset($_GET['refresh']));
    $st['ann'] = ro_ann_active($dev);
    $st['ptest'] = ro_ptest_active();
    $st['raeume'] = ro_segments($dev);
    echo json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

/**
 * Tokenpruefung fuer alles, was etwas AUSLOEST.
 *
 * Die leere Sollseite wird VOR hash_equals abgefangen: hash_equals('', '')
 * liefert true. Ist noch kein Token vergeben, waere der Endpunkt sonst
 * gerade dann offen, wenn er es am wenigsten sein darf.
 */
function ro_token_ok() {
    $cfg = ro_config();
    $soll = isset($cfg['aktionstoken']) ? (string) $cfg['aktionstoken'] : '';
    if ($soll === '') { return false; }
    return hash_equals($soll, isset($_GET['token']) ? (string) $_GET['token'] : '');
}

/* ---------- Selbsttest: Token pruefen, ohne etwas auszuloesen ----------
 * Hausregel: jeder Aktionsendpunkt beantwortet ?selftest=1&token=... , ohne
 * dass etwas passiert. Sonst laesst sich nicht feststellen, ob die Adresse im
 * Miniserver noch stimmt, ohne wirklich zu schalten.
 */
if (isset($_GET['selftest'])) {
    if (!ro_token_ok()) {
        http_response_code(403);
        echo "SELFTEST;OK=0;ERR=TOKEN
";
        exit;
    }
    echo "SELFTEST;OK=1;TOKEN=OK;DEV=" . $dev . "
";
    exit;
}

if (isset($_GET['cmd'])) {
    if (!ro_token_ok()) {
        http_response_code(403);
        echo "CMD;OK=0;ERR=TOKEN\n";
        exit;
    }
    list($ok, $info) = ro_command($_GET['cmd'], $dev, isset($_GET['p']) ? $_GET['p'] : '');
    echo 'CMD;OK=' . $ok . ';BEFEHL=' . htmlspecialchars((string) $_GET['cmd'], ENT_QUOTES) . ';INFO=' . $info . "\n";
    exit;
}

if (isset($_GET['ptest'])) {
    // Seit 1.0.4 tokenpflichtig wie ?cmd=. Der Aufruf setzt PTEST=1 fuer
    // fuenf Minuten; das Loxone-Programm schickt daraufhin eine echte
    // Pushnachricht. Ohne Token konnte jedes Geraet im Netz dem Anwender
    // Meldungen aufs Telefon schicken.
    if (!ro_token_ok()) {
        http_response_code(403);
        echo "PTEST;OK=0;ERR=TOKEN\n";
        exit;
    }
    @file_put_contents(ro_tmpdir() . '/ptest', '1');
    ro_log('Test-Pushnachricht angefordert (PTEST=1 fuer 5 Minuten)');
    echo "PTEST;OK=1;DAUER=300\n";
    exit;
}

$st = ro_state($dev, isset($_GET['refresh']));
$cfg = ro_config();

if (isset($_GET['debug'])) {
    $r = ro_robot($dev);
    echo 'DEBUG  Roboter ' . $dev . ': ' . ($r ? $r['name'] . ' (' . $r['ip'] . ':' . $r['port'] . ')' : 'nicht konfiguriert') . "\n";
    echo 'Status: ' . $st['text'] . ' (Code ' . $st['code'] . ')  Batterie: ' . $st['batterie'] . '%'
       . ($st['laedt'] ? ' (laedt)' : '') . "\n";
    if ($st['fehler']) { echo 'FEHLER ' . $st['fehler'] . ': ' . $st['fehlertext'] . "\n"; }
    echo 'Letzte Reinigung: ' . $st['flaeche'] . ' m2 in ' . $st['dauer'] . ' min'
       . ($st['letzte'] ? ' (beendet ' . date('d.m.Y H:i', $st['letzte']) . ')' : '') . "\n";
    echo 'Gesamt: ' . $st['flaeche_gesamt'] . ' m2, ' . $st['dauer_gesamt'] . ' h, ' . $st['anzahl_gesamt'] . " Reinigungen\n";
    echo 'Verbrauchsmaterial (Reststunden): Filter ' . $st['filter'] . ', Hauptbuerste ' . $st['buerste_haupt']
       . ', Seitenbuerste ' . $st['buerste_seite'] . ', Sensoren ' . $st['sensor']
       . ' -> Warnung=' . $st['material_warn'] . "\n";
    $seg = ro_segments($dev);
    if ($seg) {
        echo "Raeume (fuer ?cmd=segments&p=...):\n";
        foreach ($seg as $id => $name) { echo '  ' . $id . '  ' . $name . "\n"; }
    }
    echo "\n";
}

printf("ROBO;OK=%d;CODE=%d;BATT=%d;LAEDT=%d;FEHLER=%d;FLAECHE=%.1f;DAUER=%d;FLAECHEG=%.1f;DAUERG=%.1f;ANZAHLG=%d;FILTER=%d;BHAUPT=%d;BSEITE=%d;SENSOR=%d;MATWARN=%d;ANN=%d;AUDIO=%d;PUSH=%d;PTEST=%d\n",
    $st['ok'], $st['code'], $st['batterie'], $st['laedt'], $st['fehler'],
    $st['flaeche'], $st['dauer'], $st['flaeche_gesamt'], $st['dauer_gesamt'], $st['anzahl_gesamt'],
    $st['filter'], $st['buerste_haupt'], $st['buerste_seite'], $st['sensor'], $st['material_warn'],
    ro_ann_active($dev),
    empty($cfg['notify']['audio']) ? 0 : 1,
    empty($cfg['notify']['push']) ? 0 : 1,
    ro_ptest_active());
