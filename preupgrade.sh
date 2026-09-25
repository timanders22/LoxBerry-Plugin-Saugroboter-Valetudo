#!/bin/bash
# Saugroboter (Valetudo) - preupgrade
# command <TEMPFILE> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# WARUM HIER UND NICHT SPAETER
# Der Installer raeumt unmittelbar nach diesem Skript auf:
#   preupgrade -> rm -rf config/plugins/<ordner>/ und data/plugins/<ordner>/
#              -> config/* aus dem Archiv kopieren -> postinstall -> postupgrade
# Wer eine Konfiguration ueber das Upgrade retten will, muss das VOR dem
# Loeschen tun.
#
# WAS AN $1 BIS 1.0.14 FALSCH WAR
# Beide Upgrade-Skripte benutzten "$1" als Zielordner. Gemessen am Quelltext
# von sbin/plugininstall.pl ruft der Installer aber
#   cd "$tempfolder" && "$script" "$tempfile" "$pname" "$pfolder" \
#                                 "$pversion" "$lbhomedir" "$tempfolder"
# $1 ist also $tempfile - eine zehnstellige ZUFALLSKENNUNG, kein Verzeichnis.
# Der absolute Arbeitsordner steht im SECHSTEN Argument. Dass es trotzdem
# gutging, haengt allein daran, dass beide Skripte mit demselben
# Arbeitsverzeichnis und derselben Kennung laufen - eine Wette, keine Zusage.

ARGV1=$1
ARGV3=$3
ARGV5=$5
ARGV6=$6
PFOLDER="${ARGV3:-saugrobo}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Bis 1.1.9 stand hier nur die Zeile darueber, ohne jede Pruefung: ohne
# fuenftes Argument und ohne LBHOMEDIR wurde aus /config/plugins/... ab der
# Laufwerkswurzel gelesen und "//data" angelegt, Rueckgabe 0 (in WSL
# gemessen, Pruefung-Saugroboter-Valetudo-1.1.10, Faelle H10 und C13).
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
    echo "<WARNING> Es wurde nichts gesichert."
    exit 1
fi

# Erste Wahl: das sechste Argument. Rueckfall: der alte Weg, damit ein
# aelterer Installer nichts verliert.
TMPDIR="$ARGV6"
if [ -z "$TMPDIR" ] || [ ! -d "$TMPDIR" ]; then
    TMPDIR="$PWD/$ARGV1"
fi
[ -n "$TMPDIR" ] || exit 0
mkdir -p "$TMPDIR" 2>/dev/null

cp -p "$BASE/config/plugins/$PFOLDER/robo.json" "$TMPDIR/robo.json" 2>/dev/null
cp -p "$BASE/log/plugins/$PFOLDER/robo.log"     "$TMPDIR/robo.log"  2>/dev/null

# Der Zeitpunkt der letzten Reinigung, je Roboter.
#
# Was gesichert wird, richtet sich nach dem, was der CODE schreibt, nicht nach
# dem Archivinhalt. ro_state() legt data/plugins/<ordner>/last_<n>.json an
# (robo_lib.php), und data/plugins/<ordner>/ raeumt der Installer beim Upgrade
# ab - genau wie config/plugins/<ordner>/. Bis 1.1.3 wurde die Datei nicht
# mitgesichert: nach jedem Update stand "letzte" wieder auf 0, der Zeitpunkt
# der letzten Reinigung war in Loxone weg, und der erste Zustandswechsel
# danach wurde anders bewertet als vorher.
mkdir -p "$TMPDIR/data" 2>/dev/null
cp -p "$BASE/data/plugins/$PFOLDER"/last_*.json "$TMPDIR/data/" 2>/dev/null

exit 0
