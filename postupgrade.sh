#!/bin/bash
ARGV1=$1; ARGV3=$3; ARGV5=$5
PFOLDER="${ARGV3:-saugrobo}"; BASE="${ARGV5:-$LBHOMEDIR}"
mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/log/plugins/$PFOLDER" "$BASE/data/plugins/$PFOLDER" 2>/dev/null
[ -f "$ARGV1/robo.json" ] && cp -p "$ARGV1/robo.json" "$BASE/config/plugins/$PFOLDER/robo.json"
[ -f "$ARGV1/robo.log" ] && cp -p "$ARGV1/robo.log" "$BASE/log/plugins/$PFOLDER/robo.log"
BK="$BASE/config/plugins/$PFOLDER.backup.json"; CF="$BASE/config/plugins/$PFOLDER/robo.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then cp -p "$BK" "$CF"; fi
fi

# Altlast bis 1.0.3: cron.php lag im UNANGEMELDETEN Webordner und war damit
# fuer jeden erreichbar, der die LoxBerry-Oberflaeche im Netz sieht. Ein
# Aufruf kann eine Ansage ueber den Musicserver ausloesen. Seit 1.0.4 liegt
# die Datei unter bin/ und wird nur noch vom Cron ueber das Dateisystem
# aufgerufen.
#
# Diese Zeilen stehen hier, weil sie nichts kosten und der Zweck des Umzugs
# sonst davon abhinge, dass das Update das alte HTML-Verzeichnis restlos
# ersetzt.
ALT="$BASE/webfrontend/html/plugins/$PFOLDER/cron.php"
if [ -f "$ALT" ]; then
    rm -f "$ALT"
    echo "<OK> Alte, ueber HTTP erreichbare cron.php entfernt."
fi

exit 0
