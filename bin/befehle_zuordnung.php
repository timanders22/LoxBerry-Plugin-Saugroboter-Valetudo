<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Saugroboter (Valetudo) (Gruppe 2, 1.1.22).
 *
 * Die Befehlsbruecke (bin/befehlsbruecke.php und .sh, byte-gleich aus der Stammfassung Werkzeuge/gemeinsam/)
 * abonniert <praefix>/befehl/# und gibt jeden gueltigen Befehl an tun() dieser Tabelle. tun() ruft DIESELBE Funktion
 * wie der HTTP-Endpunkt robo.php - ro_befehl_ausfuehren() in robo_lib.php, mit Quelle 'mqtt' - und gibt array(ok,
 * grund) fuer die Quittung zurueck (ro_befehl_quittung()). Ein Token gibt es auf diesem Weg nicht; an seiner Stelle
 * stehen die Haken "Befehle ueber MQTT annehmen" und, fuer die eingreifenden Befehle, "Eingreifende Befehle ueber
 * MQTT annehmen" (befehle_mqtt_steuern, Entwurf sv E4). Beide ab Werk aus.
 *
 * Regeln (Entwurf gemeinsam 2.5): die Datei gibt ein Feld zurueck, setzt keine Konfiguration und schreibt nichts.
 * Feste Namen vor Mustern; kein Name endet auf "quittung"; zwei Eintraege treffen nie dasselbe Thema
 * (befehlsbruecke.php --pruefen). Wie der unangemeldete Endpunkt legt die Bruecke nichts an: einstellungen() liest
 * mit ro_config(false), tun() schaltet die Selbstheilung der Konfiguration fuer den Prozess ab.
 *
 * Die 17 Befehle (Entwurf sv 3.1/3.2) - Roboter 1 unter <praefix>/befehl/<name>, Roboter 2 bis 9 unter
 * <praefix>/befehl/<n>/<name> (Muster {nr}, Entwurf sv E16; die Bruecke abonniert genau ein Praefix):
 *   puls     start, stop, pause, home, locate, absaugen, wischwaschen, wischtrocknen, evquittieren (nur "1")
 *   auswahl  fan (off|min|low|medium|high|max|turbo), wasser (off|min|low|medium|high|max),
 *            modus (vacuum|mop|vacuum_and_mop|vacuum_then_mop) - Entwurf sv E5
 *   text     segments, zone, goto (bis 64 Byte), reset (40), ruhezeit (16); die Feinpruefung bleibt beim Plugin
 *            (ro_param_pruefen() und ro_command()).
 * Eingreifend sind alle ausser locate und evquittieren (15 von 17). Sperrzeiten (ENTSCHEIDUNGEN Gruppe 2 Punkt 2,
 * Entwurf sv E6; die Sperre gilt fuer gleichen Namen UND gleichen Wert): Auftraege und locate 5 s, fan, wasser,
 * modus und ruhezeit 0 s (die 60-s-Gleichwert-Bremse des Plugins bleibt die eine Pruefung), absaugen, wisch*,
 * reset und evquittieren die Vorgabe 60 s. "angenommen" heisst: die Funktion des Plugins meldet OK=1 (Valetudo
 * antwortete mit 2xx, oder die Gleichwert-Bremse meldet UNVERAENDERT=1).
 */
if (!function_exists('ro_befehl_ausfuehren')) {
    $ro_kand = array();
    $ro_kand[] = 'REPLACELBPHTMLDIR/robo_lib.php';
    $ro_kand[] = dirname(__DIR__) . '/webfrontend/html/robo_lib.php';
    if (basename(dirname(__DIR__)) === 'plugins') {
        $ro_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/robo_lib.php';
    }
    foreach ($ro_kand as $ro_k) {
        if (strpos($ro_k, 'REPLACE') !== 0 && is_file($ro_k)) {
            require_once $ro_k;
            break;
        }
    }
}
$ro_grund_auftrag = 'Ein zweiter Druck auf denselben Auftrag nach einem Stopp am Geraet soll wirken - der HTTP-Weg bremst '
    . 'Auftraege nie; 5 s fangen nur den Doppeldruck (ENTSCHEIDUNGEN Gruppe 2 Punkt 2, Entwurf sv E6).';
$ro_grund_soll = 'Die 60-s-Gleichwert-Bremse des Plugins bleibt die eine Pruefung; sie antwortet mit UNVERAENDERT=1, '
    . 'also mit angenommen (ENTSCHEIDUNGEN Gruppe 2 Punkt 2, Entwurf sv E6).';
$ro_tun = function ($cmd, $art) {
    return function ($w, $t) use ($cmd, $art) {
        ro_config_erzeugen_erlauben(false);
        return ro_befehl_quittung(ro_befehl_ausfuehren($cmd, isset($t['nr']) ? (int) $t['nr'] : 1,
            $art === 'puls' ? '' : (string) $w, 'mqtt'));
    };
};
/* Name => array(Art, eingreifend, sperre_s|null fuer die Vorgabe 60, Titel in Loxone, Kachel, Zusatz) */
$ro_def = array(
    'start'         => array('puls', true, 5, 'Saugroboter Reinigung starten (MQTT)', 'BEFEHL.K_START', array()),
    'stop'          => array('puls', true, 5, 'Saugroboter stoppen (MQTT)', 'BEFEHL.K_STOP', array()),
    'pause'         => array('puls', true, 5, 'Saugroboter pausieren (MQTT)', 'BEFEHL.K_PAUSE', array()),
    'home'          => array('puls', true, 5, 'Saugroboter zur Ladestation (MQTT)', 'BEFEHL.K_HOME', array()),
    'locate'        => array('puls', false, 5, 'Saugroboter piepsen lassen (MQTT)', 'BEFEHL.K_LOCATE', array()),
    'segments'      => array('text', true, 5, 'Saugroboter Räume reinigen (MQTT)', 'BEFEHL.K_SEGMENTS', array('max_byte' => 64)),
    'zone'          => array('text', true, 5, 'Saugroboter Zone reinigen (MQTT)', 'BEFEHL.K_ZONE', array('max_byte' => 64)),
    'goto'          => array('text', true, 5, 'Saugroboter Position anfahren (MQTT)', 'BEFEHL.K_GOTO', array('max_byte' => 64)),
    'fan'           => array('auswahl', true, 0, 'Saugroboter Saugstärke (MQTT)', 'BEFEHL.K_FAN',
                             array('werte' => array('off', 'min', 'low', 'medium', 'high', 'max', 'turbo'))),
    'wasser'        => array('auswahl', true, 0, 'Saugroboter Wischwasser (MQTT)', 'BEFEHL.K_WASSER',
                             array('werte' => array('off', 'min', 'low', 'medium', 'high', 'max'))),
    'modus'         => array('auswahl', true, 0, 'Saugroboter Betriebsart (MQTT)', 'BEFEHL.K_MODUS',
                             array('werte' => array('vacuum', 'mop', 'vacuum_and_mop', 'vacuum_then_mop'))),
    'absaugen'      => array('puls', true, null, 'Saugroboter Station absaugen (MQTT)', 'BEFEHL.K_ABSAUGEN', array()),
    'wischwaschen'  => array('puls', true, null, 'Saugroboter Wischmodul waschen (MQTT)', 'BEFEHL.K_WISCHWASCHEN', array()),
    'wischtrocknen' => array('puls', true, null, 'Saugroboter Wischmodul trocknen (MQTT)', 'BEFEHL.K_WISCHTROCKNEN', array()),
    'reset'         => array('text', true, null, 'Saugroboter Verbrauchsteil zurücksetzen (MQTT)', 'BEFEHL.K_RESET', array('max_byte' => 40)),
    'ruhezeit'      => array('text', true, 0, 'Saugroboter Ruhezeit (MQTT)', 'BEFEHL.K_RUHEZEIT', array('max_byte' => 16)),
    'evquittieren'  => array('puls', false, null, 'Saugroboter Ereignis quittieren (MQTT)', 'BEFEHL.K_EVQUITTIEREN', array()),
);
$ro_befehle = array();
foreach (array('' => '', '{nr}/' => ' Roboter N') as $ro_vor => $ro_zus) {
    foreach ($ro_def as $ro_name => $ro_d) {
        $ro_e = array('art' => $ro_d[0], 'eingreifend' => $ro_d[1],
                      'titel' => preg_replace('/ \(MQTT\)\z/', $ro_zus . ' (MQTT)', $ro_d[3]),
                      'kachel' => $ro_d[4], 'quittung' => 'ausgefuehrt', 'tun' => $ro_tun($ro_name, $ro_d[0]));
        if ($ro_d[2] !== null) {
            $ro_e['sperre_s'] = $ro_d[2];
            $ro_e['begruendung'] = $ro_d[2] === 0 ? $ro_grund_soll : $ro_grund_auftrag;
        }
        $ro_befehle[$ro_vor . $ro_name] = $ro_e + $ro_d[5];
    }
}
return array(
    'fassung' => 1,
    'linie'   => 'saugrobo',
    'plugin'  => 'Saugroboter',
    'geladen' => function_exists('ro_befehl_ausfuehren') && function_exists('ro_befehl_quittung')
                 && function_exists('ro_config') && function_exists('ro_robots_aus'),
    'zweiter_haken' => 'befehle_mqtt_steuern',
    'einstellungen' => function () {
        // ro_config(false): wie der unangemeldete Endpunkt legt die Bruecke nichts an und heilt nichts.
        $c = ro_config(false);
        return array('praefix'     => ro_mqtt_thema_saeubern($c['mqtt_topic']),
                     'annehmen'    => !empty($c['befehle_mqtt_ein']) && !empty($c['mqtt_enabled']) && (bool) ro_robots_aus($c),
                     'eingreifend' => !empty($c['befehle_mqtt_steuern']),
                     'mqtt_ein'    => !empty($c['mqtt_enabled']));
    },
    'protokoll' => function ($stufe, $text) {
        ro_log(($stufe === 'INFO' ? '' : $stufe . ' ') . $text);
    },
    'teile' => array('nr' => '[2-9]'),
    'befehle' => $ro_befehle,
);
