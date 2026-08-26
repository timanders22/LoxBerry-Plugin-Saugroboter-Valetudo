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

exit 0
