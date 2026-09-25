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
# Bis 1.1.9 genuegte hier ein beliebiges Verzeichnis ([ -d "$BASE" ]): mit
# einem fuenften Argument ohne config/plugins legte dieses Skript dort
# config/, data/ und log/ an und meldete <OK> (in WSL gemessen,
# Pruefung-Saugroboter-Valetudo-1.1.10, Fall H8).
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

# SCHLUSSZEILE NACH INHALT.
# Dieses Skript laeuft bei der Erstinstallation UND bei jedem Upgrade
# (Kopf dieser Datei). Bis 1.1.8 stand danach jedes Mal "Adresse der
# Valetudo-Oberflaeche eintragen" - auch ueber einer eben zurueckgespielten
# Konfiguration mit eingetragenem Roboter. Wer das liest, haelt die
# Einstellungen fuer verloren, und der Fall, in dem sie es wirklich sind,
# sieht genauso aus.
# Entschieden wird nach dem, wozu die Anleitung auffordert: mindestens ein
# Roboter mit Adresse in robo.json (robots[].ip, oder das Einzelfeld ip
# aelterer Fassungen - ro_config() in robo_lib.php migriert es; ro_robots()
# ueberspringt jede Zeile ohne ip). Das Aktionstoken, nach dem ro_config()
# die Zweitschrift beurteilt, entsteht schon beim ersten Oeffnen der
# Oberflaeche und sagt nichts ueber einen Roboter. Ohne php ist das nicht
# pruefbar; dann steht die Anleitung.
# Gemessen am 24.09.2026: Pruefung-Saugroboter-Valetudo-1.1.9/postinstall_hinweis.md.
RO_EINGERICHTET=0
if command -v php >/dev/null 2>&1 && php -r '
    $d = json_decode((string) @file_get_contents($argv[1]), true);
    if (!is_array($d)) { exit(1); }
    if (isset($d["ip"]) && is_string($d["ip"]) && trim($d["ip"]) !== "") { exit(0); }
    $liste = (isset($d["robots"]) && is_array($d["robots"])) ? $d["robots"] : array();
    foreach ($liste as $r) {
        if (is_array($r) && isset($r["ip"]) && is_scalar($r["ip"]) && trim((string) $r["ip"]) !== "") { exit(0); }
    }
    exit(1);
' -- "$CF" 2>/dev/null; then
    RO_EINGERICHTET=1
fi

if [ "$RO_EINGERICHTET" = "1" ]; then
    echo "<OK> Aktualisierung abgeschlossen, Einstellungen uebernommen (Roboteradresse eingetragen)."
else
    echo "<OK> Installation abgeschlossen."
    echo "<INFO> Bitte die Plugin-Oberflaeche oeffnen und im Reiter Einstellungen"
    echo "<INFO> die Adresse der Valetudo-Oberflaeche eintragen. Der Reiter Test"
    echo "<INFO> beantwortet danach mit Haken und Kreuzen, ob die Einrichtung traegt."
    echo "<INFO> Wer MQTT benutzt: unter Gateway V1 muss das Abo von Hand"
    echo "<INFO> eingetragen werden - der Reiter MQTT nennt den Wert und misst,"
    echo "<INFO> welche Gateway-Fassung installiert ist."
fi
exit 0
