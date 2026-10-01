#!/bin/bash
# Saugroboter (Valetudo) - preinstall
# command <TEMPFILE> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# Neu im Durchgang 01.10.2026 (I1, Entscheidung 1 vom 29.09.2026; Vorbild
# Abfahrtsassistent 1.6.19). Der Installer ruft dieses Skript bei JEDEM Einbau
# auf, nach dem Aufraeumen der alten Fassung und VOR dem Kopieren von
# Konfiguration, Cron-Datei und Oberflaeche (sbin/plugininstall.pl: preupgrade
# :846, purge :874, preinstall :877, Cron :990, HTML :1066 -
# Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts: die Zweitschrift braucht
# postupgrade.sh.
#
# Ohne Marke ist es eine NEUINSTALLATION. Eine liegengebliebene Zweitschrift
# (config/plugins/<ordner>.backup.json) einer frueheren Installation geht nach
# <name>.alt, gemeldet mit genau einer <WARNING>. Bis 1.1.11 spielte
# postinstall.sh sie zurueck ("Aktualisierung abgeschlossen"), und schon der
# erste Minutentakt - die Cron-Datei liegt am Geraet vor postinstall.sh -
# holte Roboteradressen, Valetudo-Anmeldung und Aktionstoken der frueheren
# Installation zurueck (in WSL gemessen, Installer-Pruefer Faelle D1 und D2,
# Code-Pruefer t7). Die Selbstheilung der Bibliothek liest .alt nie; die
# Deinstallation raeumt es ab.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-saugrobo}"
BASE="${ARGV5:-$LBHOMEDIR}"

# Ohne config/plugins, data/plugins UND config/system/general.json wird nichts
# angefasst (Regeln/06, Raumklima-Vorfall; wie die uebrigen Haken).
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
# Der Ordnername darf keinen Pfadtrenner tragen, sonst griffe mv/rm daneben.
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    # Aktualisierung: nichts zu tun, postupgrade.sh spielt zurueck.
    exit 0
fi

BK="$BASE/config/plugins/$PFOLDER.backup.json"
BEISEITE=""
FEST=""
if [ -e "$BK" ] || [ -L "$BK" ]; then
    rm -rf "${BK:?}.alt" 2>/dev/null
    if mv -f "$BK" "$BK.alt" 2>/dev/null; then
        BEISEITE="$BK.alt"
    else
        FEST="$BK"
    fi
fi
[ -f "$BK.alt" ] && [ ! -L "$BK.alt" ] && chmod 600 "$BK.alt" 2>/dev/null

if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    RO_TEXT="<WARNING> Neuinstallation: Einstellungen einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && RO_TEXT="$RO_TEXT Beiseitegelegt: $BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && RO_TEXT="$RO_TEXT Nicht zu verschieben, bitte von Hand entfernen: $FEST"
    echo "$RO_TEXT"
fi
exit 0
