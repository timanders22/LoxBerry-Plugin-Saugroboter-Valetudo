#!/bin/bash
# Saugroboter (Valetudo) - postupgrade
# command <TEMPFILE> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# Laeuft als LETZTES, nach postinstall. Zum sechsten Argument siehe die
# ausfuehrliche Begruendung in preupgrade.sh.

ARGV1=$1
ARGV3=$3
ARGV5=$5
ARGV6=$6
PFOLDER="${ARGV3:-saugrobo}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Bis 1.1.9 genuegte hier ein beliebiges Verzeichnis ([ -d "$BASE" ]): mit
# einem fuenften Argument ohne config/plugins legte dieses Skript dort
# config/, data/ und log/ an (in WSL gemessen,
# Pruefung-Saugroboter-Valetudo-1.1.10, Fall H12).
# Die Wurzel: $5 (vom Installer) oder $LBHOMEDIR, wenn dort config/plugins
# und data/plugins liegen - sonst vom eigenen Ablageort AUFWAERTS SUCHEN, bis
# ein Verzeichnis config/plugins, data/plugins UND config/system/general.json
# traegt. Keine feste Ebenenzahl und kein fest verdrahteter Systempfad danach.
# general.json ist die Bedingung aus dem Raumklima-Vorfall (Regeln/06): ein
# LoxBerry hat die Datei immer, ein Pruefstandsrest nie. Findet sich nichts,
# wird GEWARNT statt vollzogen. Bauart AWM-Abfuhr 1.4.13; gemessen in WSL,
# Pruefung-Saugroboter-Valetudo-1.1.10 (Faelle H und C).
ro_wurzel_suchen() {
    ro_v=$(cd "$1" 2>/dev/null && pwd -P) || return 1
    ro_i=0
    while [ -n "$ro_v" ] && [ "$ro_v" != "/" ] && [ "$ro_i" -lt 8 ]; do
        if [ -d "$ro_v/config/plugins" ] && [ -d "$ro_v/data/plugins" ] \
           && [ -f "$ro_v/config/system/general.json" ]; then
            echo "$ro_v"
            return 0
        fi
        ro_v=$(dirname "$ro_v")
        ro_i=$((ro_i + 1))
    done
    return 1
}
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ]; then
    BASE=$(ro_wurzel_suchen "$(dirname "$(readlink -f "$0")")") || BASE=""
fi
if [ -z "$BASE" ]; then
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden: weder als"
    echo "<WARNING> fuenftes Argument noch in \$LBHOMEDIR, und oberhalb von"
    echo "<WARNING> $(dirname "$(readlink -f "$0")") traegt kein Verzeichnis"
    echo "<WARNING> config/plugins, data/plugins und config/system/general.json."
    echo "<WARNING> Es wurde nichts angelegt und nichts zurueckgespielt."
    exit 1
fi

# Traegt eine Konfigurationsdatei INHALT? Lesbares JSON-Objekt UND ein nicht
# leeres Aktionstoken - dieselbe Frage, nach der ro_config() in
# webfrontend/html/robo_lib.php seit 1.1.4 aus der Zweitschrift heilt (das
# Token ist das Geheimnis, ohne das jede in Loxone eingetragene Adresse auf
# 403 laeuft). Bis 1.1.9 wurde hier nach der FORM entschieden (leer oder genau
# "{}") und eine kaputte oder leere Zweitschrift kopiert und als
# "wiederhergestellt" gemeldet (in WSL gemessen,
# Pruefung-Saugroboter-Valetudo-1.1.10, Faelle N1a, N1b, N3). Ohne PHP gilt
# eine Datei als ohne Inhalt.
ro_hat_inhalt() {
    [ -s "$1" ] || return 1
    command -v php >/dev/null 2>&1 || return 1
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        if (!is_array($d)) { exit(1); }
        exit((isset($d["aktionstoken"]) && is_string($d["aktionstoken"]) && trim($d["aktionstoken"]) !== "") ? 0 : 1);' -- "$1" 2>/dev/null
}

# I1 (Durchgang 01.10.2026, Entscheidung 1): die Marke von preupgrade.sh
# (data/plugins/<ordner>.upgrade_laeuft) raeumt dieses Skript ab - per trap,
# also auch, wenn es vorzeitig endet. Der Rueckgabewert bleibt der des
# Skripts. Bliebe sie liegen, hielte sich eine spaetere Neuinstallation fuer
# eine Aktualisierung.
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER'."; exit 1 ;;
esac
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
ro_marke_weg() {
    ro_rc=$?
    rm -f "$MARKE" 2>/dev/null
    if [ -e "$MARKE" ]; then
        echo "<WARNING> Die Marke $MARKE liess sich nicht entfernen - bitte von Hand loeschen, sonst haelt sich eine spaetere Neuinstallation fuer eine Aktualisierung."
    fi
    exit $ro_rc
}
trap ro_marke_weg EXIT

TMPDIR="$ARGV6"
if [ -z "$TMPDIR" ] || [ ! -d "$TMPDIR" ]; then
    TMPDIR="$PWD/$ARGV1"
fi

CDIR="$BASE/config/plugins/$PFOLDER"
LDIR="$BASE/log/plugins/$PFOLDER"
DDIR="$BASE/data/plugins/$PFOLDER"
CF="$CDIR/robo.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
mkdir -p "$CDIR" "$LDIR" "$DDIR" 2>/dev/null

# Zurueckholen, was preupgrade weggelegt hat - aber nur, wenn nicht schon eine
# brauchbare Konfiguration dasteht. postinstall hat sie moeglicherweise bereits
# aus der Sicherung neben dem Ordner wiederhergestellt.
# Die Ablage stammt aus preupgrade.sh DIESES Vorgangs und wird deshalb
# zurueckgelegt, wie sie ist - auch eine beschaedigte: ro_config() legt sie
# dann als .kaputt beiseite und heilt aus der Zweitschrift. Die Meldung sagt
# aber, was darin war. Bis 1.1.9 hiess es "uebernommen" auch fuer eine
# Ablage ohne jeden Inhalt (in WSL gemessen,
# Pruefung-Saugroboter-Valetudo-1.1.10, Fall N4).
#
# I3 (Durchgang 01.10.2026): traegt die Sicherung DIESES Laufs Inhalt, gewinnt
# sie - auch gegen eine Datei, die ein Minutentakt in der Luecke schon aus der
# Zweitschrift geheilt hat. Sie ist der juengste Stand. Danach wird die
# Zweitschrift angeglichen. Bis 1.1.11 stellte postinstall.sh vorher die
# Zweitschrift her, und eine abweichende (aeltere) Zweitschrift gewann (in WSL
# gemessen, Installer-Pruefer Fall C3: TOKNEU -> TOKSTALE).
if [ -f "$TMPDIR/robo.json" ]; then
    if ro_hat_inhalt "$TMPDIR/robo.json"; then
        if ! cmp -s "$TMPDIR/robo.json" "$CF"; then
            cp -p "$TMPDIR/robo.json" "$CF" && chmod 600 "$CF" 2>/dev/null
        fi
        if cmp -s "$TMPDIR/robo.json" "$CF"; then
            echo "<OK> Konfiguration aus dem Upgrade uebernommen."
            if ! cmp -s "$CF" "$BK"; then
                # umask 077: eine neu entstehende Zweitschrift hat 0600 VOR dem Inhalt.
                if (umask 077; cp "$CF" "$BK") 2>/dev/null && chmod 600 "$BK" 2>/dev/null && cmp -s "$CF" "$BK"; then
                    echo "<OK> Zweitschrift $PFOLDER.backup.json angeglichen."
                else
                    echo "<WARNING> Die Zweitschrift $BK liess sich nicht angleichen."
                fi
            fi
        else
            echo "<WARNING> Die Konfiguration aus dem Upgrade liess sich nicht nach $CF schreiben."
        fi
    elif [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        cp -p "$TMPDIR/robo.json" "$CF" && chmod 600 "$CF" 2>/dev/null
        echo "<INFO> Die Konfiguration aus dem Upgrade trug keine eingerichteten Einstellungen"
        echo "<INFO> (kein lesbares Objekt mit Aktionstoken); sie wurde unveraendert zurueckgelegt."
    fi
fi
if [ -f "$TMPDIR/robo.log" ] && [ ! -s "$LDIR/robo.log" ]; then
    cp -p "$TMPDIR/robo.log" "$LDIR/robo.log" 2>/dev/null
    echo "<OK> Protokoll aus dem Upgrade uebernommen."
fi

# Was preupgrade weggelegt hat, muss postupgrade WIEDERFINDEN.
# Der Zeitpunkt der letzten Reinigung je Roboter (siehe preupgrade.sh).
if [ -d "$TMPDIR/data" ]; then
    ANZ=0
    for F in "$TMPDIR/data"/last_*.json; do
        [ -f "$F" ] || continue
        cp -p "$F" "$DDIR/" 2>/dev/null && ANZ=$((ANZ + 1))
    done
    if [ "$ANZ" -gt 0 ]; then
        echo "<OK> Zeitpunkt der letzten Reinigung uebernommen ($ANZ Datei(en))."
    fi
    # M3: die Vormerkungen zum Abraeumen zurueckbehaltener MQTT-Themen.
    if [ -f "$TMPDIR/data/mqtt_raeumen.json" ]; then
        cp -p "$TMPDIR/data/mqtt_raeumen.json" "$DDIR/" 2>/dev/null \
            && echo "<OK> Vorgemerkte MQTT-Altwerte zum Abraeumen uebernommen."
    fi
fi

if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        if ro_hat_inhalt "$BK"; then
            cp -p "$BK" "$CF" && chmod 600 "$CF" 2>/dev/null
            echo "<OK> Konfiguration aus der Sicherung wiederhergestellt."
        else
            echo "<WARNING> Die Sicherung $PFOLDER.backup.json traegt keinen Inhalt (kein lesbares"
            echo "<WARNING> Objekt mit Aktionstoken) - sie wurde nicht uebernommen."
        fi
    fi
fi
# RECHTE 0600, NICHT 0640 - UND DAS IST GEMESSEN, NICHT GERATEN.
#
# In robo.json stehen das Aktionstoken und, falls eingerichtet, die Anmeldung
# an Valetudo. Der Hausstandard vom 03.09.2026 verlangt 0600, sobald ein Dienst
# die Datei braucht. 640 sagte "auch die Gruppe" - waehrend der Kommentar
# daneben behauptete, die Datei gehe niemanden ausser loxberry etwas an.
#
# Dass 0600 hier traegt, ist am Quelltext des LoxBerry-Kerns nachgemessen
# (Zweig master, 05.09.2026): der Konfigordner gehoert loxberry:loxberry
# (sbin/plugininstall.pl, make_path(... owner=>'loxberry', group=>'loxberry')),
# der Minutencron laeuft als loxberry (system/cron/cron.d/lbdefaults:
# "* * * * * loxberry cd / && for f in .../cron.01min/*") und Apache ebenfalls
# (system/apache2/envvars: APACHE_RUN_USER=loxberry). Es liest und schreibt
# also derselbe Benutzer - die Gruppenrechte wurden nie gebraucht.
chmod 600 "$CF" 2>/dev/null
chmod 600 "$BK" 2>/dev/null

# Altlast bis 1.0.3: cron.php lag im UNANGEMELDETEN Webordner und war damit
# fuer jeden erreichbar, der die LoxBerry-Oberflaeche im Netz sieht. Ein
# Aufruf kann eine Ansage ueber den Musicserver ausloesen. Seit 1.0.4 liegt
# die Datei unter bin/ und wird nur noch vom Cron ueber das Dateisystem
# aufgerufen.
ALT="$BASE/webfrontend/html/plugins/$PFOLDER/cron.php"
if [ -f "$ALT" ]; then
    rm -f "$ALT"
    echo "<OK> Alte, ueber HTTP erreichbare cron.php entfernt."
fi

# Altlast bis 1.0.14: der Zwischenspeicher lag fest unter /tmp/saugrobo,
# unabhaengig vom wirklichen Ordnernamen. Bei einer Zweitinstallation teilten
# sich beide Installationen cron.lock und alle Merker. Seit 1.1.0 heisst der
# Ordner wie das Plugin; der alte bleibt sonst mit veralteten Zustaenden liegen.
#
# ABER NUR, WENN ES KEINE INSTALLATION "saugrobo" GIBT. Seit 1.1.0 ist
# /tmp/saugrobo der laufende Zwischenspeicher der Installation dieses
# Namens (Sperrdatei des Minutenlaufs, Zustaende, Meldefenster) - und eine
# Zweitinstallation saugrobo_01 gibt es genau dann, wenn der Name schon
# belegt ist. Bis 1.1.9 raeumte ihr Update den Zwischenspeicher der ersten
# ab (in WSL gemessen, Pruefung-Saugroboter-Valetudo-1.1.10, Fall N6).
if [ -d "/tmp/saugrobo" ] && [ "$PFOLDER" != "saugrobo" ] \
   && [ ! -d "$BASE/webfrontend/html/plugins/saugrobo" ]; then
    rm -rf "/tmp/saugrobo"
    echo "<OK> Alter, gemeinsam benutzter Zwischenspeicher /tmp/saugrobo entfernt."
fi

# Die Zustaende im Zwischenspeicher stammen aus der alten Fassung und tragen
# die alten Feldnamen. Sie werden verworfen, damit der erste Abruf nach dem
# Upgrade wirklich misst statt einen halben Datensatz zu wiederholen.
rm -f "/tmp/$PFOLDER"/state_*.json "/tmp/$PFOLDER"/caps_*.json \
      "/tmp/$PFOLDER"/segments_*.json "/tmp/$PFOLDER"/info_*.json 2>/dev/null

# Der Hinweis gehoert zu DIESER Fassung, nicht zu einer von vor drei
# Schritten. Bis 1.1.3 stand hier unveraendert der Text von 1.1.0 und
# forderte bei jedem Update zum Neuerzeugen der Vorlage auf.
# Sachkorrektur (Durchgang 01.10.2026): der Satz zu den Platzhaltern stimmt
# seit Entscheidung Nr. 28 nicht mehr.
echo "<INFO> MQTT: ok, fehlertext, ereignistext und meldung gehen nicht retained hinaus;"
echo "<INFO> ihre zurueckbehaltenen Werte aus Fassungen vor 1.1.10 raeumt der Minutenlauf"
echo "<INFO> aus dem Broker, bis der Broker es bestaetigt. Ist der Roboter nicht erreichbar,"
echo "<INFO> gehen nur ok 0 und code 8 (fluechtig) hinaus; die uebrigen Werte bleiben auf"
echo "<INFO> dem letzten gemessenen Stand, ueber MQTT wie am Endpunkt."
exit 0
