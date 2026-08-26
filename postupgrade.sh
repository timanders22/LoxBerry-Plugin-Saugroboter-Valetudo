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

if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    echo "<WARNING> Das LoxBerry-Wurzelverzeichnis liess sich nicht bestimmen."
    exit 1
fi

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
if [ -f "$TMPDIR/robo.json" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        cp -p "$TMPDIR/robo.json" "$CF" && chmod 640 "$CF" 2>/dev/null
        echo "<OK> Konfiguration aus dem Upgrade uebernommen."
    fi
fi
if [ -f "$TMPDIR/robo.log" ] && [ ! -s "$LDIR/robo.log" ]; then
    cp -p "$TMPDIR/robo.log" "$LDIR/robo.log" 2>/dev/null
    echo "<OK> Protokoll aus dem Upgrade uebernommen."
fi

if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        cp -p "$BK" "$CF" && chmod 640 "$CF" 2>/dev/null
    fi
fi
chmod 640 "$CF" 2>/dev/null

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
if [ -d "/tmp/saugrobo" ] && [ "$PFOLDER" != "saugrobo" ]; then
    rm -rf "/tmp/saugrobo"
    echo "<OK> Alter, gemeinsam benutzter Zwischenspeicher /tmp/saugrobo entfernt."
fi

# Die Zustaende im Zwischenspeicher stammen aus der alten Fassung und tragen
# die alten Feldnamen. Sie werden verworfen, damit der erste Abruf nach dem
# Upgrade wirklich misst statt einen halben Datensatz zu wiederholen.
rm -f "/tmp/$PFOLDER"/state_*.json "/tmp/$PFOLDER"/caps_*.json \
      "/tmp/$PFOLDER"/segments_*.json "/tmp/$PFOLDER"/info_*.json 2>/dev/null

echo "<INFO> Neu in 1.1.0: die Verbrauchsmaterialien werden jetzt in der"
echo "<INFO> richtigen Einheit gelesen, und es sind Felder dazugekommen."
echo "<INFO> Die Loxone-Vorlage gehoert deshalb NEU erzeugt und eingelesen -"
echo "<INFO> Reiter 'Einbindung in Loxone'."
exit 0
