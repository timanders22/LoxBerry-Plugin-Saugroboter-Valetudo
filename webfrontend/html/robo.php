<?php
/**
 * Saugroboter (Valetudo) - Miniserver-Endpunkt
 *
 * Abfrage (&dev=N waehlt bei mehreren Robotern das Geraet, Standard 1):
 *   (ohne Parameter) -> ROBO;OK=..;CODE=..;BATT=..;...   siehe ro_felder()
 *                       CODE: 0=Ladestation 1=bereit 2=reinigt 3=pausiert
 *                             4=faehrt zur Station 5=faehrt 8=unbekannt 9=Fehler
 *                       Verbrauchsmaterial in Reststunden bzw. Prozent
 *                       (-1 = nicht verfuegbar)
 *
 * Steuerung (einfache GET-Aufrufe fuer virtuelle Ausgaenge; token-pflichtig):
 *   ?cmd=<befehl>&token=T  - die Liste steht in ro_befehle()
 *   ?cmd=segments&p=1,4&token=T     Raeume reinigen (IDs siehe Reiter Test)
 *   ?cmd=fan&p=max&token=T          Saugstaerke
 *   ?cmd=reset&p=filter/main&token=T  Verbrauchsteil zuruecksetzen
 *   Ohne passendes Token aus dem Reiter "Einbindung in Loxone" antwortet
 *   ?cmd= mit HTTP 403.
 *
 * Weitere Aufrufe: ?debug=1  ?json=1  ?refresh=1
 *   ?ptest=1&token=T     Test-Pushnachricht anstossen
 *   ?selftest=1&token=T  Token pruefen, ohne etwas auszuloesen
 *
 * ==================================================================
 * WAS DIESER ENDPUNKT NICHT TUT
 * ==================================================================
 *
 * Er liegt im UNANGEMELDETEN Bereich. Deshalb:
 *
 *   - ro_config(false): die Konfiguration wird gelesen, aber nicht ANGELEGT.
 *     Gemessen am 26.08.2026 legte eine einzige tokenlose Anfrage
 *     ?cmd=start den Konfigordner samt robo.json an - ro_token_ok() ruft
 *     ro_config(), und das stellte die Datei aus der Sicherungskopie wieder
 *     her. REGELN_2: "Der unangemeldete Endpunkt darf nichts schreiben."
 *
 *   - ?p= wird gegen ein enges Muster geprueft, bevor es irgendwohin geht.
 *     Gemessen ging ein Zeilenumbruch darin bis ins Protokoll durch und
 *     erzeugte dort einen frei erfundenen, echt aussehenden Eintrag.
 *
 *   - ?json= und ?debug= benutzen die zwischengespeicherte Raumliste.
 *     Bis 1.0.14 kostete jeder dieser Aufrufe 2,1 s gegen einen stummen
 *     Roboter, ohne Token und ohne Ende wiederholbar - genug, um alle
 *     PHP-Arbeiter des LoxBerry zu belegen.
 *
 *   - ?refresh=1 uebergeht den Zwischenspeicher und ist deshalb seit 1.1.4
 *     TOKENPFLICHTIG. Ohne Token wird die Anfrage nicht abgewiesen, sie
 *     bekommt nur den zwischengespeicherten Stand - Abfragen bleiben offen,
 *     das Abschalten des Schutzes nicht.
 * ==================================================================
 */

require_once __DIR__ . '/robo_lib.php';

/* DIESER ENDPUNKT LEGT NICHTS AN - UND ZWAR AUF JEDEM WEG.
 *
 * Bis 1.1.3 war das ein Parameter (ro_config(false)), und der stand nur in
 * ro_token_lage(). Gemessen am 04.09.2026 in einer nachgebauten
 * Installationslage: ?cmd=start ohne Token legte richtig nichts an - ein
 * Aufruf von robo.php OHNE Parameter, also der Weg, den Loxone bei jeder
 * Abfrage geht, stellte config/plugins/<ordner>/robo.json aus der
 * Zweitschrift wieder her. Ein Schalter fuer den ganzen Prozess kann man
 * beim naechsten Ausbau nicht an einer Aufrufstelle vergessen. */
ro_config_erzeugen_erlauben(false);

$dev = isset($_GET['dev']) ? max(1, min(9, (int) $_GET['dev'])) : 1;

/**
 * Darf dieser Aufruf den Zwischenspeicher uebergehen?
 *
 * ?refresh=1 zwingt ro_state() und ro_segments() zu einer vollstaendigen
 * Abfragerunde beim Roboter. cache_sec heisst in den Vorgaben "Status-Cache
 * (schuetzt den Roboter)" - bis 1.1.3 liess sich genau dieser Schutz von
 * jedem Geraet im Netz OHNE TOKEN beliebig oft abschalten. Gemessen am
 * 04.09.2026 gegen eine zaehlende Gegenstelle: drei Leseaufrufe ohne
 * refresh = 0 Abrufe beim Roboter, drei mit refresh = 15 Abrufe, und jede
 * Anfrage bindet dabei einen PHP-Arbeiter des LoxBerry.
 *
 * Abfragen bleiben offen, das Uebergehen des Schutzes nicht. Der Knopf im
 * Reiter Test hat den Token ohnehin zur Hand.
 */
function ro_refresh_erlaubt() {
    return isset($_GET['refresh']) && ro_token_ok();
}

/**
 * Tokenpruefung fuer alles, was etwas AUSLOEST.
 *
 * Die leere Sollseite wird VOR hash_equals abgefangen: hash_equals('', '')
 * liefert true. Ist noch kein Token vergeben, waere der Endpunkt sonst
 * gerade dann offen, wenn er es am wenigsten sein darf.
 *
 * Rueckgabe: 1 in Ordnung, 0 falsches Token, -1 noch keins vergeben.
 */
function ro_token_lage() {
    $cfg = ro_config(false);
    $soll = isset($cfg['aktionstoken']) ? (string) $cfg['aktionstoken'] : '';
    if ($soll === '') { return -1; }
    return hash_equals($soll, isset($_GET['token']) && is_string($_GET['token'])
        ? (string) $_GET['token'] : '') ? 1 : 0;
}
function ro_token_ok() { return ro_token_lage() === 1; }

/** Der Parameter ?p= - eng gefasst, bevor er irgendwohin geht. */
function ro_param() {
    $p = isset($_GET['p']) && is_string($_GET['p']) ? (string) $_GET['p'] : '';
    if ($p === '') { return ''; }
    if (strlen($p) > 64) { return false; }
    // Alles, was die Befehle brauchen: Ziffern, Buchstaben, Komma, Doppelpunkt,
    // Bindestrich, Unterstrich, Schraegstrich - und sonst nichts.
    return preg_match('#^[A-Za-z0-9,:_/\-]+$#', $p) ? $p : false;
}

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    $st = ro_state($dev, ro_refresh_erlaubt());
    /* Dieselbe Quelle wie die Zeile und wie MQTT. Bis 1.0.14 standen hier
     * ro_ann_active() und ro_ptest_active() einzeln - audio und push fehlten
     * in der JSON-Ansicht ersatzlos. Genau die Auseinanderentwicklung, die
     * 1.0.13 mit ro_meldeflags() beenden sollte; der dritte Weg war
     * uebersehen worden. */
    $st = array_merge($st, ro_meldeflags($dev));
    $st['alter'] = ro_lauf_alter();
    $st['zaehler'] = ro_lauf_lesen()['zaehler'];
    $st['meldung'] = ro_meldung_lesen($dev);
    $st['raeume'] = ro_segments($dev, ro_refresh_erlaubt());
    echo json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

/* ---------- Selbsttest: Token pruefen, ohne etwas auszuloesen ----------
 * Hausregel: jeder Aktionsendpunkt beantwortet ?selftest=1&token=... , ohne
 * dass etwas passiert. Sonst laesst sich nicht feststellen, ob die Adresse im
 * Miniserver noch stimmt, ohne wirklich zu schalten.
 *
 * Drei Antworten, nicht zwei: "falsches Token" und "noch keins vergeben" sind
 * beim Einrichten genau die Faelle, die man auseinanderhalten will.
 */
if (isset($_GET['selftest'])) {
    $lage = ro_token_lage();
    if ($lage === -1) {
        http_response_code(403);
        echo "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n";
        exit;
    }
    if ($lage !== 1) {
        http_response_code(403);
        echo "SELFTEST;OK=0;ERR=TOKEN\n";
        exit;
    }
    echo 'SELFTEST;OK=1;TOKEN=OK;DEV=' . $dev . ';FASSUNG=' . ro_pluginversion() . "\n";
    exit;
}

if (isset($_GET['cmd'])) {
    if (!ro_token_ok()) {
        http_response_code(403);
        echo "CMD;OK=0;ERR=TOKEN\n";
        exit;
    }
    $cmd = is_string($_GET['cmd']) ? strtolower(trim((string) $_GET['cmd'])) : '';
    if (!preg_match('/^[a-z]{1,20}$/', $cmd) || !isset(ro_befehle()[$cmd])) {
        http_response_code(400);
        echo "CMD;OK=0;ERR=BEFEHL\n";
        exit;
    }
    $p = ro_param();
    if ($p === false) {
        http_response_code(400);
        echo 'CMD;OK=0;BEFEHL=' . $cmd . ";ERR=PARAMETER\n";
        exit;
    }
    list($ok, $info) = ro_command($cmd, $dev, $p);
    /* $info kommt aus ro_command() und ist entweder "HTTP <n>" oder einer
     * von wenigen festen Saetzen. Trotzdem wird es gesaeubert: eine
     * Antwortzeile, die ein Semikolon oder einen Zeilenumbruch aus einer
     * fremden Quelle traegt, zerfaellt fuer den Miniserver in zwei. */
    $info = preg_replace('/[^A-Za-z0-9 _\.\-]/', ' ', (string) $info);
    echo 'CMD;OK=' . $ok . ';BEFEHL=' . $cmd . ';INFO=' . $info . "\n";
    exit;
}

if (isset($_GET['ptest'])) {
    // Tokenpflichtig wie ?cmd=. Der Aufruf setzt PTEST=1 fuer fuenf Minuten;
    // das Loxone-Programm schickt daraufhin eine echte Pushnachricht. Ohne
    // Token konnte jedes Geraet im Netz dem Anwender Meldungen aufs Telefon
    // schicken.
    if (!ro_token_ok()) {
        http_response_code(403);
        echo "PTEST;OK=0;ERR=TOKEN\n";
        exit;
    }
    $ok = @file_put_contents(ro_tmpdir() . '/ptest', '1') !== false;
    ro_log('Test-Pushnachricht angefordert (PTEST=1 fuer 5 Minuten)'
           . ($ok ? '' : ' - MERKER LIESS SICH NICHT SCHREIBEN'));
    /* Sofort melden, statt bis zu einer Minute auf den Cron zu warten.
     * Ueber HTTP holt sich der Miniserver den Merker beim naechsten Abruf;
     * ueber MQTT muss ihn das Plugin schicken - und ein Test, der erst eine
     * Minute spaeter wirkt, sieht aus wie ein Test, der nicht wirkt. */
    if ($ok) {
        foreach (array_keys(ro_robots()) as $ro_n) {
            ro_mqtt_publish(null, $ro_n);
        }
    }
    // Der Rueckgabewert wird geprueft, statt Erfolg zu behaupten.
    echo 'PTEST;OK=' . ($ok ? 1 : 0) . ';DAUER=300' . ($ok ? '' : ';ERR=SCHREIBEN') . "\n";
    exit;
}

$st = ro_state($dev, ro_refresh_erlaubt());

if (isset($_GET['debug'])) {
    $r = ro_robot($dev);
    $i = ro_robotinfo($dev);
    echo 'DEBUG  Roboter ' . $dev . ': ' . ($r ? $r['name'] . ' (' . $r['ip'] . ':' . $r['port'] . ')' : 'nicht konfiguriert') . "\n";
    if ($i && ($i['modell'] !== '' || $i['valetudo'] !== '')) {
        echo 'Geraet: ' . trim($i['hersteller'] . ' ' . $i['modell'])
           . ($i['valetudo'] !== '' ? '   Valetudo ' . $i['valetudo'] : '') . "\n";
    }
    echo 'Status: ' . $st['text'] . ' (Code ' . $st['code'] . ')  Batterie: ' . $st['batterie'] . '%'
       . ($st['laedt'] ? ' (laedt)' : '') . "\n";
    if ($st['fehler']) {
        echo 'FEHLER ' . $st['fehler'] . ': ' . $st['fehlertext']
           . '  (Schwere ' . $st['fstufe'] . ', Teil ' . $st['fteil'] . ")\n";
    }
    echo 'Anbauteile: Behaelter ' . $st['behaelter'] . '  Wassertank ' . $st['wassertank']
       . '  Wischmodul ' . $st['wischer'] . '   Station: ' . $st['dock'] . "\n";
    echo 'Stufen: Saugen ' . $st['saugstufe'] . '  Wasser ' . $st['wasserstufe']
       . '  Betriebsart ' . $st['modus'] . "\n";
    echo 'Letzte Reinigung: ' . $st['flaeche'] . ' m2 in ' . $st['dauer'] . ' min'
       . ($st['letzte'] ? ' (beendet ' . date('d.m.Y H:i', $st['letzte']) . ')' : '') . "\n";
    echo 'Gesamt: ' . $st['flaeche_gesamt'] . ' m2, ' . $st['dauer_gesamt'] . ' h, ' . $st['anzahl_gesamt'] . " Reinigungen\n";
    echo 'Verbrauchsmaterial (Stunden): Filter ' . $st['filter'] . '/' . $st['filter2']
       . ', Hauptbuerste ' . $st['buerste_haupt']
       . ', Seitenbuerste ' . $st['buerste_seite'] . '/' . $st['buerste_seite2']
       . ', Sensoren ' . $st['sensor'] . ', Raeder ' . $st['raeder']
       . ', Wischbezug ' . $st['mop'] . "\n";
    echo 'Station (Prozent): Filter ' . $st['dock_filter'] . ', Buerste ' . $st['dock_buerste']
       . ', Beutel ' . $st['dock_behaelter'] . ', Reiniger ' . $st['reiniger']
       . ' -> Warnung=' . $st['material_warn'] . "\n";
    if (!empty($st['material_fremd'])) {
        echo 'Verbrauchsteile, die dieses Plugin nicht kennt: '
           . implode(', ', $st['material_fremd']) . "\n";
    }
    echo 'Ereignisse: ' . $st['event'] . ' offen'
       . ($st['evtext'] !== '' ? ' (' . $st['evtext'] . ')' : '') . "\n";
    $lauf = ro_lauf_lesen();
    echo 'Cron: letzter Lauf vor ' . ro_lauf_alter() . ' s, Zaehler ' . $lauf['zaehler']
       . ', ok=' . $lauf['ok'] . "\n";
    $caps = ro_capabilities($dev);
    if ($caps) {
        echo 'Faehigkeiten (' . count($caps) . "):\n";
        foreach ($caps as $c) { echo '  ' . $c . "\n"; }
    }
    $seg = ro_segments($dev);
    if ($seg) {
        echo "Raeume (fuer ?cmd=segments&p=...):\n";
        foreach ($seg as $id => $name) { echo '  ' . $id . '  ' . $name . "\n"; }
    }
    echo "\n";
}

echo ro_zeile($st, $dev) . "\n";
