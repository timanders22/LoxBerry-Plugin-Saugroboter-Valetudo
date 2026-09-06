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
# Das Plugin selbst schreibt sie mit denselben Rechten (ro_write_atomic),
# sonst hoebe der naechste Speichervorgang das hier auf.
chmod 600 "$CF" 2>/dev/null
chmod 600 "$BK" 2>/dev/null

if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        cp -p "$BK" "$CF" && chmod 600 "$CF" 2>/dev/null
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
