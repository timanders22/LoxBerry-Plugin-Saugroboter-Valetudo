#!/bin/bash
# Saugroboter (Valetudo) - postinstall
# command <TEMPFILE> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# Laeuft IMMER, auch beim Upgrade - dort unmittelbar nachdem der Installer
# config/plugins/<ordner>/ und data/plugins/<ordner>/ geloescht und die
# mitgelieferte Konfiguration hineinkopiert hat. Die einzige Quelle, die den
# Loeschschritt uebersteht, ist die Sicherung NEBEN dem Konfigordner.
#
# Bis 1.0.14 meldete dieses Skript <OK> und gab 0 zurueck, auch wenn gar
# nichts angelegt werden konnte. Gemessen ohne fuenftes Argument und ohne
# LBHOMEDIR:
#   postinstall.sh: line 5: /config/plugins/saugrobo/robo.json: No such file
#   <OK> Installation abgeschlossen.       Rueckgabewert: 0

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-saugrobo}"
BASE="${ARGV5:-$LBHOMEDIR}"

if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    echo "<FAIL> Das LoxBerry-Wurzelverzeichnis liess sich nicht bestimmen."
    exit 1
fi

CDIR="$BASE/config/plugins/$PFOLDER"
DDIR="$BASE/data/plugins/$PFOLDER"
LDIR="$BASE/log/plugins/$PFOLDER"
CF="$CDIR/robo.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"

mkdir -p "$CDIR" "$DDIR" "$LDIR" 2>/dev/null
if [ ! -d "$CDIR" ]; then
    echo "<FAIL> $CDIR liess sich nicht anlegen."
    exit 1
fi

if [ ! -f "$CF" ]; then
    echo '{}' > "$CF" || { echo "<FAIL> $CF liess sich nicht schreiben."; exit 1; }
fi
# In robo.json steht das Aktionstoken, das ?cmd=start freischaltet - und
# gegebenenfalls die Anmeldung an Valetudo. Die Datei geht niemanden ausser
# loxberry etwas an. Das Plugin selbst schreibt sie mit denselben Rechten
# (ro_write_atomic), sonst hoebe der naechste Speichervorgang das hier auf.
chmod 640 "$CF" 2>/dev/null
chmod 640 "$BK" 2>/dev/null

if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        cp -p "$BK" "$CF" && chmod 640 "$CF" 2>/dev/null
        echo "<OK> Konfiguration aus der Sicherung wiederhergestellt."
    fi
fi

echo "<OK> Installation abgeschlossen."
echo "<INFO> Bitte die Plugin-Oberflaeche oeffnen und im Reiter Einstellungen"
echo "<INFO> die Adresse der Valetudo-Oberflaeche eintragen. Der Reiter Test"
echo "<INFO> beantwortet danach mit Haken und Kreuzen, ob die Einrichtung traegt."
echo "<INFO> Wer MQTT benutzt: unter Gateway V1 muss das Abo von Hand"
echo "<INFO> eingetragen werden - der Reiter MQTT nennt den Wert und misst,"
echo "<INFO> welche Gateway-Fassung installiert ist."
exit 0
