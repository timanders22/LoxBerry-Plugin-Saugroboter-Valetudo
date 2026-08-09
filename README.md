# LoxBerry-Plugin: Saugroboter (Valetudo)

> **Hinweis zu 1.0.4:** Zwischen der veröffentlichten 1.0.2 und dieser Fassung
> liegen zwei Entwicklungsschritte. 1.0.3 ist nie einzeln veröffentlicht
> worden — die Änderungen beider Schritte stecken in 1.0.4 und sind unten
> getrennt beschrieben.

Bindet einen Saugroboter mit der cloudfreien Firmware **Valetudo** an Loxone an —
mit **einer** Abfrage statt vier und einer sauberen **Statuszahl** statt
zusammengesuchter Textbruchstücke. Steuerbefehle laufen als einfache GET-Aufrufe,
die Loxone direkt als virtuellen Ausgang senden kann (Valetudo verlangt sonst
PUT mit JSON-Rumpf).

Kompatibel mit LoxBerry 3.x und **LoxBerry 4** (reines PHP, PHP 7.4 und 8.x).

## Was 1.0.5 behebt

Nur eine Richtigstellung, kein Code. In 1.0.4 stand, der 404 beim Abruf der
`prerelease.cfg` komme daher, dass `raw.githubusercontent.com` einer
Umbenennung des Repositories nicht folge. Das ist **falsch** — es folgt ihr;
nachgeprüft am alten Repo-Namen. Die Datei existierte damals schlicht noch
nicht im Repository, sie kam erst mit 1.0.4 dazu. Die Adressen bleiben auf dem
heutigen Namen, aber aus dem richtigen Grund.

## Was 1.0.4 behebt

**`cron.php` liegt jetzt unter `bin/` statt unter `webfrontend/html/`.**

Aufgerufen wird die Datei ausschließlich vom Minutencron über die
PHP-Kommandozeile — nie über HTTP. Im HTML-Verzeichnis war sie zusätzlich für
jeden im Heimnetz abrufbar, und ein Aufruf ist nicht folgenlos:
`ro_events_check()` kann eine **Ansage über den Musicserver** auslösen und das
Meldefenster für Loxone setzen. Eine fremde Anfrage hätte also die Wohnung
sprechen lassen können.

- `cron/cron.01min` ruft die Datei jetzt über `REPLACELBPBINDIR` auf.
- `robo_lib.php` bleibt im HTML-Verzeichnis, weil dort auch `robo.php` liegt —
  der Endpunkt für den Miniserver. `cron.php` findet die Bibliothek über
  `REPLACELBPHTMLDIR`, mit Rückfall auf den Pfad relativ zur eigenen Datei für
  den Lauf aus dem ausgepackten Archiv. Bleibt beides erfolglos, bricht das
  Skript mit einer Meldung ab, statt still nichts zu tun.
- `postupgrade.sh` entfernt eine aus 1.0.3 stehengebliebene `cron.php` aus dem
  HTML-Verzeichnis — sonst hinge der Zweck des Umzugs davon ab, dass das Update
  das alte Verzeichnis restlos ersetzt.

**Nur noch ein Cron-Durchgang zur Zeit** (`flock` mit `LOCK_NB`). Nicht die
Rechenzeit war das Problem, sondern die Meldung: Zwei überlappende Durchgänge
lesen `ev_N.json` beide am Anfang und schreiben es beide erst am Ende — beide
sähen denselben Übergang „reinigt → fertig" und beide sagten ihn an.

**`?ptest=1` ist tokenpflichtig geworden**, wie `?cmd=` es seit 1.0.2 ist. Der
Aufruf setzt `PTEST=1` für fünf Minuten, woraufhin das Loxone-Programm eine
echte Pushnachricht schickt. Ohne Token konnte jedes Gerät im Netz dem Anwender
Meldungen aufs Telefon schicken. Die Prüfung fängt die leere Sollseite **vor**
`hash_equals` ab: `hash_equals('', '')` liefert `true`, der Endpunkt wäre sonst
gerade dann offen gewesen, wenn noch gar kein Token vergeben ist.

**Der Plugin-Ordner wird ermittelt, nicht geraten.** Bis 1.0.3 fiel
`ro_paths()` auf den festen Namen `saugrobo` zurück, sobald
`config/plugins/<ordner>` noch fehlte — etwa im Augenblick der Installation.
Hängt LoxBerry bei einer Zweitinstallation einen Zähler an (`saugrobo_01`, weil
der Name schon belegt war), schrieb diese dann in die Konfiguration der ersten.
Der feste Name greift jetzt nur noch dort, wo der ermittelte nachweislich kein
Plugin-Ordner sein kann: aus dem ausgepackten Archiv heraus heißt er `html`
bzw. `htmlauth`.

**`PRERELEASECFG` zeigte ins Leere.** In 1.0.2 war der Eintrag leer, obwohl
Auto-Update eingeschaltet ist; 1.0.3 füllte ihn — aber mit dem **alten**
Repo-Namen (ohne `-Valetudo`). Der Abruf ergab `404: Not Found`. Grund war
allerdings nicht der alte Name: `prerelease.cfg` existierte im Repository
schlicht noch gar nicht, sie kam erst mit dieser Fassung dazu. GitHub leitet
nach einer Umbenennung weiter, `raw.githubusercontent.com` ebenso — das ist
nachgeprüft. Die Adressen stehen jetzt trotzdem auf dem heutigen Namen: Auf
ein Weiterleitungsziel sollte sich ein Auto-Update nicht stützen, es
verschwindet in dem Augenblick, in dem jemand den alten Namen neu vergibt.

**An den Loxone-Adressen ändert sich nichts** — außer beim Test-Push, der nun
`&token=` braucht. `robo.php` bleibt, wo es ist, mit denselben Parametern.

## Was 1.0.3 behebt

Drei Meldungen eines Mitlesers. Alle drei treffen zu — bei der Zeitgrenze
stimmen die genannten Zahlen sogar auf die Sekunde.

### Vier Abrufe hintereinander, jeder mit sechs Sekunden Geduld

`ro_state()` holt Zustand, laufende Statistik, Gesamtstatistik und
Verbrauchsmaterial nacheinander. Nachgemessen gegen ein Gegenstück, das die
Verbindung annimmt und dann schweigt — der schlimmste Fall, denn ein
abgeschaltetes Gerät weist die Verbindung sofort ab und kostet nichts:

| | vorher | nachher |
|---|---|---|
| ein einzelner `ro_get()` | 6,0 s | 2,0 s |
| `ro_state()` (vier Abrufe) | **24,0 s** | 2,0 s |
| `robo.php` — was Loxone sieht | **24,1 s** | 2,1 s |
| derselbe Aufruf gleich danach | 24,1 s | **0,0 s** |
| `ro_events_check()`, zwei Roboter | **48,1 s** | 4,0 s |

Die 48 Sekunden sind mehr als der Cron-Takt: Der nächste minütliche Lauf
startet, bevor der vorige fertig ist. Und die 24,1 Sekunden in `robo.php`
bekommt Loxone gar nicht zu sehen — der Miniserver bricht einen virtuellen
HTTP-Eingang nach wenigen Sekunden ab, während auf dem LoxBerry ein Arbeiter
blockiert bleibt.

Drei Änderungen statt einer:

1. Zeitgrenze **6 → 2 s**, wie vorgeschlagen. Valetudo antwortet im eigenen
   Netz in Millisekunden.
2. **Abbruch nach dem ersten gescheiterten Abruf**, ebenfalls wie
   vorgeschlagen — die drei folgenden werden gar nicht mehr versucht.
3. Ein **Merker „antwortet gerade nicht"** (60 s). Solange er steht, kehrt
   `ro_get()` sofort zurück. Das ist die letzte Zeile der Tabelle: Bei
   Loxone, das im Sekundentakt pollt, ist das der Unterschied zwischen
   „hängt" und „antwortet". Befehle (`ro_put`) dürfen weiterhin etwas länger
   warten, aber nicht mehr acht Sekunden — jetzt vier.

Gegengeprüft mit einem *antwortenden* Roboter: alle vier Abrufe werden nach
wie vor gemacht, Fläche, Dauer, Gesamtstatistik, Bürsten, Filter, Sensor und
die Materialwarnung kommen unverändert an.

### Nicht atomar geschriebene Zwischenspeicher — zutreffend

`file_put_contents` kürzt die Datei zuerst auf null; wer in diesem Augenblick
liest, bekommt eine leere oder halbe Datei. Der Zwischenspeicher, die
Merkdatei für die letzte Reinigung und das Kürzen des Protokolls laufen jetzt
über Nebendatei plus `rename()`.

### Protokoll: Speicher ja, `tail` nein

Der Hinweis auf den RAM stimmt. Der empfohlene Weg über das Programm `tail`
ist aber die **langsamere** Lösung — an einer 522-kB-Datei gemessen, 200
Zeilen Ausgabe:

| Verfahren | Zeit | Speicher |
|---|---|---|
| `file()` + `array_reverse` (bisher) | 0,8 ms | 1436 KB |
| `exec("tail -n 200")` (empfohlen) | 1,7 ms | 34 KB |
| rückwärts mit `fseek` (jetzt) | **0,3 ms** | **34 KB** |

Rückwärts lesen ist bei beidem besser und kommt ohne fremden Prozess aus.

### Hausstandard

Die Reiter waren `<div>` ohne Verweis, `sm-active` vergab allein das
JavaScript — ohne JavaScript war die Seite leer und die Reiter nicht einmal
anklickbar. Jetzt echte Verweise mit serverseitigem `sm-active`, alle vier
über `?form=…` geprüft. Dazu `uninstall` und `prerelease.cfg` ergänzt
(`PRERELEASECFG` war leer bei eingeschaltetem Auto-Update), vier tote
Sprachschlüssel entfernt — 236 Schlüssel, deutsch und englisch deckungsgleich
— und das Symbol auf das neue Hausmuster gebracht. Beide PHP-Fassungen
liefern in beiden Sprachen zeichengleiche Ausgabe ohne eine Meldung.

## Funktionen

- **Ein Endpunkt** fasst Status, Statistik (aktuell und gesamt) und
  Verbrauchsmaterial zusammen — statt vier virtuellen Eingängen im 10-Sekunden-Takt
  genügt einer alle 30 Sekunden
- **Statuszahl** für Loxone: 0 Ladestation, 1 bereit, 2 reinigt, 3 pausiert,
  4 fährt zur Station, 5 fährt, 9 Fehler
- Batterie, Ladezustand, Fehlercode und Fehlertext
- Letzte Reinigung (m²/Minuten) und Gesamtwerte (m², Stunden, Anzahl)
- **Verbrauchsmaterial** in Reststunden (Filter, Haupt-/Seitenbürste, Sensoren)
  mit frei wählbarer Warnschwelle → `MATWARN`
- **Steuerung** per GET: `start`, `stop`, `pause`, `home`, `locate`,
  Raumreinigung (`segments`), Saugstärke (`fan`), gespeicherte Position (`goto`)
- **Meldungen**: Reinigung fertig (mit Fläche und Dauer), Störung, Wartung fällig
  — als Ansage (TTS) und/oder Push über Loxone
- Bis zu **2 Roboter**, MQTT, JSON (inkl. Raumliste), Protokoll mit Rotation
- Reiter: Einstellungen, Einbindung in Loxone (mit kompletter Baustein-Liste),
  Test (inkl. Segment-IDs und ungefährlichem „Piepsen"-Test), Protokoll

## Endpunkte

| Aufruf | Zweck |
|---|---|
| `/plugins/saugrobo/robo.php` | Loxone-Zeile `ROBO;OK=..;CODE=..;BATT=..;FILTER=..;MATWARN=..;…` |
| `/plugins/saugrobo/robo.php?debug=1` | Klartext inkl. Raumliste |
| `/plugins/saugrobo/robo.php?json=1` | kompletter Zustand als JSON |
| `/plugins/saugrobo/robo.php?cmd=start` | Reinigung starten (auch `stop`, `pause`, `home`, `locate`) |
| `/plugins/saugrobo/robo.php?cmd=segments&p=1,4` | nur bestimmte Räume reinigen |
| `/plugins/saugrobo/robo.php?cmd=fan&p=max` | Saugstärke setzen |

## Voraussetzung

Auf dem Roboter läuft **Valetudo** (Version mit `/api/v2`). Es werden keine
Cloud-Dienste und keine Zugangsdaten benötigt — die Kommunikation bleibt im
eigenen Netz.

## Datenschutz

Es sind **keine persönlichen Daten** im Plugin enthalten. Adresse und
Einstellungen liegen ausschließlich lokal (`config/plugins/saugrobo/robo.json`).

## Lizenz

MIT — siehe [LICENSE](LICENSE).
