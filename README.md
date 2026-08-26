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

## Neu in 1.1.0

Diese Fassung ist zur Hälfte eine Reparatur. Für sie ist zum ersten Mal der
**Quelltext von Valetudo selbst** gelesen worden (Hypfer/Valetudo, Zweig
`master`, 678 Dateien) statt Angaben aus zweiter Hand zu übernehmen — und
dabei kam heraus, dass zwei beworbene Befehle nie funktioniert haben und die
wichtigste Zahl des Wartungsteils um den Faktor 60 danebenlag.

### Was nicht funktioniert hat

**`?cmd=fan` antwortete HTTP 404.** Das Plugin schickte
`PUT .../FanSpeedControlCapability` mit `{"name":"max"}`. Valetudo hängt an
diese Fähigkeit den `PresetSelectionCapabilityRouter`, und der kennt nur
`GET /presets` und `PUT /preset` — ein PUT auf die Wurzel trifft keine Route.
Richtig ist `PUT .../FanSpeedControlCapability/preset`. Gegen eine Attrappe
gemessen, deren Routen eins zu eins aus dem Valetudo-Quelltext übernommen
sind: vorher 404, nachher 200.

**`?cmd=goto` antwortete HTTP 400.** Gesendet wurde
`{"action":"goto","goToLocationId":"<id>"}`. Der Schlüssel `goToLocationId`
kommt im gesamten Valetudo-Quelltext nicht vor; der Router verlangt
`coordinates` mit `x` und `y`. Gespeicherte Positionen gab es bis zum
15.04.2022 (`feat!: Remove ZonePresets and GoToLocationPresets`), und selbst
damals lautete die Route anders. Diese Befehlsform hat also zu **keiner**
Valetudo-Fassung gepasst. Der Befehl nimmt jetzt Kartenkoordinaten:
`?cmd=goto&p=2500,1800`.

**Das Verbrauchsmaterial kam in Minuten und war als Stunden beschriftet.**
Valetudo liefert je Teil `remaining: {value, unit}`, und `unit` ist `minutes`
oder `percent`. Gemessen an der Gestalt, die Valetudos Roborock-Umsetzung mit
einer Ultra-Station liefert:

| Valetudo liefert | 1.0.14 machte daraus |
|---|---|
| `brush/main` 18000 min = 300 h | `BHAUPT = 18000 h` |
| `brush/side_right` 12000 min = 200 h | `BSEITE = 87 h` — das war die **Dock**-Bürste in Prozent |
| `filter/main` 9000 min = 150 h | `FILTER = 64 h` — das war der **Dock**-Filter in Prozent |
| `cleaning/sensor` 1800 min = 30 h | `SENSOR = -1` — nie geliefert |

Vier Fehler in vier Zeilen: der Faktor 60 fehlte; `sensor` ist ein *subType*,
der Typ heißt `cleaning`; die Prozentwerte der Station überschrieben die
Minutenwerte des Roboters; und `MaxVal="10000"` in der Loxone-Vorlage klemmte
die 18 000 zusätzlich ab. Die Wartungswarnung `MATWARN` war damit praktisch
tot. Jetzt wird die Einheit mitgelesen, Typ **und** Untertyp ausgewertet, und
die Teile der Station haben eigene Felder. Was die Zuordnung nicht kennt, wird
nicht verschluckt, sondern im Reiter Test genannt.

**`FEHLER` war nie ein Fehlercode.** Gelesen wurde `metaData.error_code`; das
`metaData` der `StatusStateAttribute` trägt in ganz Valetudo genau zwei
Schlüssel, `zoned` und `segment_cleaning`. `FEHLER` war deshalb immer 0 oder 1,
während die Vorlage ihn als 0…10000 beschrieb. Die richtige Quelle ist
`error.vendorErrorCode`; dazu kommen `FSTUFE` (Schwere) und `FTEIL`
(betroffenes Teil).

**Der Knopf „Einstellungen sichern“ lieferte keine Datei.** Der Block stand
hinter `LBWeb::lbheader()`, der Seitenkopf war also schon geschrieben, und
`header('Content-Type: application/json')` kam zu spät. Gemessen mit PHP 8.4
gegen eine SDK-Attrappe, deren `lbheader()` wirklich ausgibt: zwei Warnungen
`Cannot modify header information` und ein Rumpf aus Seitenkopf plus JSON. Am
PHP-CLI ist der Fehler unsichtbar — `header()` ist dort wirkungslos —, weshalb
alle drei Hauswerkzeuge für die Sicherung grün meldeten. Die Reihenfolge in
`index.php` ist jetzt Bauvorschrift: Bibliothek, Konfiguration, Wachposten,
Reiterwahl, **alle Handler samt Downloads**, dann erst `lbheader()`.

**Und das Zurückspielen machte sich selbst rückgängig.** Die Seite zeichnete
sich danach aus einer Variablen, die 92 Zeilen vor dem Handler gelesen worden
war: der Anwender sah seine alten Werte, drückte „Speichern“ — und der
Speicherzweig schrieb sie zurück. Dazu wurde die Sicherungskopie
`<ordner>.backup.json` nicht nachgezogen und der Zwischenspeicher nicht
geleert. Alles drei ist behoben; `ro_config_speichern()` erledigt es an einer
Stelle.

**Die eigene Sicherung wurde abgelehnt**, wenn die Anlage aus der
Einzel-IP-Fassung hochgezogen war: der Altschlüssel `ip` blieb in der
Konfiguration, wanderte in die Datei — und die Leseseite kannte ihn nicht.
Der Schlüssel wird jetzt bei der Migration entfernt, die Datei wird aus den
Vorgaben gesiebt, und ein lesbarer Kopf (`_hinweis`, `_stand`, `_fassung`)
wird beim Lesen übergangen statt beanstandet.

**Die Sicherung prüfte nur Schlüssel, keine Werte.** Gemessen gingen
`robots="kein Feld"`, `cache_sec="abc"`, `tts=null` und `notify=5` mit null
Beanstandungen durch. Und weil `ro_mqtt_publish()` zwar den Wert säuberte, aber
nicht das **Thema**, ließ sich über eine präparierte Sicherung eine zweite
`publish`-Zeile in jedes UDP-Datagramm einschleusen. Jetzt prüfen zwei Wachen
jeden Wert gegen dieselbe Positivliste, die auch das Formular benutzt, und der
Themenpräfix wird gesäubert.

### Was dazugekommen ist

- **Valetudo-Ereignisse.** `EVENT` (Anzahl), `EVTYP` (Art) und `EVMUELL`
  (Staubbehälter voll). Valetudo führt eine Ereignisliste — Staubbehälter voll,
  Verbrauchsteil aufgebraucht, Wischmodul prüfen, Störung —, die das Plugin
  bisher gar nicht angesehen hat. Mit `?cmd=evquittieren` lässt sich ein
  Ereignis vom Loxone-Taster aus wegdrücken.
- **Anbauteile und Ladestation.** `BEHAELTER`, `WASSERTANK`, `WISCHER`, `DOCK`.
  Alle vier stecken schon in der Antwort, die das Plugin ohnehin holt, und
  wurden bisher weggeworfen — sie kosten keinen zusätzlichen Abruf. „Roboter
  startet, aber der Staubbehälter ist raus“ ist damit in Loxone erkennbar.
- **Stufen lesen und setzen.** `SAUGST`, `WASSER`, `MODUS`; dazu `?cmd=wasser`
  und `?cmd=modus`. Bisher ließ sich die Saugstufe (theoretisch) setzen, aber
  nicht ablesen.
- **Verbrauchsmaterial zurücksetzen** — fünf Knöpfe im Reiter Test und
  `?cmd=reset&p=filter/main`. Bisher musste man dafür in Valetudos Oberfläche
  wechseln.
- **Absaugstation und Wischstation von Hand auslösen** — `?cmd=absaugen`,
  `?cmd=wischwaschen`, `?cmd=wischtrocknen`. „Absaugen, aber nicht nachts“ ist
  damit eine Loxone-Schaltuhr.
- **Nicht-stören-Zeit** — `?cmd=ruhezeit&p=22:00-07:00` bzw. `p=aus`.
- **Zonenreinigung** — `?cmd=zone&p=X1,Y1,X2,Y2`.
- **Anmeldung an Valetudo.** Zwei Felder je Roboter. Wer in Valetudo HTTP Basic
  Auth einschaltet, verlor das Plugin bisher kommentarlos.
- **Lebenszeichen.** `ALTER`, `ZAEHLER` und über MQTT `status/ok`, `status/ts`,
  `status/zaehler`. Ein virtueller Eingang behält seinen letzten Wert; fällt der
  Cron aus, steht in Loxone weiter „in der Ladestation, 100 %“. Das ist keine
  fehlende Auskunft, sondern eine Falschaussage.
- **Selbstprüfung** im Reiter Test — Haken, Kreuz, Strich. Sie beantwortet ohne
  Loxone, ob die Einrichtung trägt: Gerät erreichbar, Modell und
  Valetudo-Fassung, welche Fähigkeiten das Gerät meldet, ob der Cron läuft, ob
  die Konfiguration vollständig ist, ob jeder Suchtext genau einmal trifft.
- **Steckbrief und Fähigkeitsliste.** `GET /api/v2/robot`,
  `/api/v2/valetudo/version` und `/api/v2/robot/capabilities`. Die Oberfläche
  zeigt jetzt, was *dieses* Gerät kann — was fehlt, kann es nicht, und der
  Fehler liegt dann nicht beim Anwender.
- **Der Abo-Hinweis für das MQTT-Gateway.** Das Plugin liest
  `Mqtt.Gatewayversion` und zeigt den Satz, der zur installierten Fassung passt.
  Unter **V1** muss das Abo von Hand eingetragen werden, sonst kommt am
  Miniserver nichts an — bisher stand dazu **gar nichts** in der Oberfläche.
  Ist die Fassung nicht lesbar, werden beide Fälle genannt und keiner behauptet.
- **Wachposten gegen fremde Absender.** Alle handelnden Formulare tragen ein
  Merkmal, das aus dem Aktionstoken abgeleitet ist. Bisher genügte ein fremdes
  Formular im Browser eines angemeldeten Bedieners, um mit „Neues Token
  erzeugen“ sämtliche Loxone-Adressen unbrauchbar zu machen.
- **Ein Suchtext, eine Stelle.** `ro_check()` liefert `\i;NAME=\i\v` mit
  führendem Semikolon. Das war bisher folgenlos — kein Feldnamenpaar
  kollidierte —, mit den neuen Feldern wäre es das nicht mehr: `FILTER=` träfe
  zuerst in `DOCKFILTER=` und `BEHAELTER=` in `DOCKBEHAELTER=`.

### Kleineres, aber nicht folgenlos

- Der **unangemeldete Endpunkt legt nichts mehr an.** Gemessen: eine einzige
  tokenlose Anfrage `?cmd=start` erzeugte den Konfigordner samt `robo.json`,
  weil die Tokenprüfung die Konfiguration aus der Sicherungskopie
  wiederherstellte.
- `?p=` wird gegen ein enges Muster geprüft. Ein Zeilenumbruch darin ging
  bisher bis ins Protokoll durch und erzeugte dort einen frei erfundenen, echt
  aussehenden Eintrag.
- `?json=1` liefert jetzt auch `audio` und `push` — der dritte Weg war beim
  Zusammenführen in 1.0.13 übersehen worden.
- Die **Raumliste wird zwischengespeichert** und respektiert den Stumm-Merker.
  Bisher kostete `?json=1` gegen einen stummen Roboter jedes Mal 2,1 s, ohne
  Token und ohne Ende wiederholbar; mit `&dev=2` waren es 4,2 s.
- `socket_create()` ist durch `stream_socket_client()` ersetzt. Ohne die
  Erweiterung `sockets` starb der Cron mit `Call to undefined function`,
  Rückgabewert 255 — und weil er nach `/dev/null` schreibt, sah das niemand.
- Der Zwischenspeicher heißt jetzt `/tmp/<ordner>` statt fest `/tmp/saugrobo`.
  Zwei Installationen teilten sich sonst `cron.lock` — dann lief je Minute nur
  **eine** von beiden.
- Die **MQTT-Signatur** trägt alle Werte. Bisher blieben das Ende eines
  Ladevorgangs, jede Änderung am Verbrauchsmaterial und die Dauer der letzten
  Reinigung bis zum halbstündlichen Lebenszeichen liegen.
- Fehlermeldungen und Erfolgsmeldungen sind getrennt. Eine abgelehnte Sicherung
  erschien bisher in einem **grünen** Kasten, und die Auszeichnung des Wortes
  „nicht“ stand als sichtbares `<b>`-Markup da.
- `.sm-warnung` ist im Stilblock definiert. Ausgerechnet der Satz, dass die
  Sicherungsdatei ein Geheimnis trägt, stand bisher als nackter Fließtext da.
- Der Sicherungsblock liegt im Reiter Einstellungen. Bisher stand er außerhalb
  jeder Reiterfläche und erschien damit auf **jedem** Reiter.
- Eine falsche Adresse beim zweiten Roboter verwirft nicht mehr die ganze
  Eingabe.
- Die Upgrade-Skripte benutzen das **sechste** Argument. `$1` ist laut
  `plugininstall.pl` eine zehnstellige Zufallskennung, kein Pfad.
- `postinstall.sh` meldet `<FAIL>` und bricht ab, wenn es nichts anlegen kann,
  statt `<OK>` zu melden. `robo.json` bekommt `640` — dort steht das
  Aktionstoken.
- `cron.01min` verschluckt die Fehlerausgabe nicht mehr, sondern schreibt sie
  ins Protokoll des Plugins.

> **Nach dem Update:** Die Loxone-Vorlage gehört **neu erzeugt und eingelesen**.
> Einheiten und Grenzen der Verbrauchsmaterial-Felder haben sich geändert, und
> es sind Felder dazugekommen. Wer nur einen Teil braucht, setzt im Reiter
> „Einbindung in Loxone“ den Haken *nur Felder, die dieser Roboter gerade
> liefert*.

> **Was nicht gemessen ist:** Zum Zeitpunkt des Umbaus stand kein Valetudo-Gerät
> zur Verfügung. Alle Aussagen über die Schnittstelle sind am Quelltext von
> Valetudo belegt und gegen eine daraus abgeleitete Attrappe gefahren — das ist
> die Umsetzung selbst, aber kein Gerät. Die Befehle `absaugen`,
> `wischwaschen`, `wischtrocknen`, `ruhezeit`, `zone` und `evquittieren` sowie
> die neuen Lesefelder sind **am Gerät noch nicht bestätigt**.

## Neu in 1.0.13

**Der MQTT-Weg liefert jetzt alles, was der HTTP-Weg liefert.** Bisher
veröffentlichte das Plugin über MQTT 16 Werte, über HTTP aber 20: es fehlten
die vier Melde-Merker `ann` (Meldefenster), `audio` und `push` (Freigaben aus
der Konfiguration) sowie `ptest` (Test-Push). Wer auf MQTT umstellte, verlor
damit genau die Werte, mit denen sich Ansage und Pushnachricht im Miniserver
steuern und **prüfen** lassen — der Test-Push löste über MQTT schlicht nicht
mehr aus.

Drei Änderungen, damit das wirklich wirkt:

- Die vier Merker kommen aus **einer** Funktion (`ro_meldeflags()`), die
  beide Wege benutzen. HTTP und MQTT können nicht mehr auseinanderlaufen.
- Sie stehen jetzt auch in der **Signatur** des Cron-Laufs. Ohne das wären
  sie zwar in der Nachricht gewesen, die Nachricht aber nicht verschickt
  worden: `ann` und `ptest` ändern sich allein durch Zeitablauf, nicht durch
  einen Zustandswechsel — ein gesetzter `ptest` wäre bis zum halbstündlichen
  Lebenszeichen liegengeblieben, sein Fenster ist aber nur fünf Minuten breit.
- `?ptest=1&token=…` veröffentlicht **sofort**, statt bis zu eine Minute auf
  den nächsten Cron-Lauf zu warten. Ein Test, der erst eine Minute später
  wirkt, sieht aus wie ein Test, der nicht wirkt.

## Neu in 1.0.12

**Token pruefbar, ohne etwas auszuloesen.** Neuer Aufruf
`?selftest=1&token=…` — antwortet `SELFTEST;OK=1;TOKEN=OK` beziehungsweise
HTTP 403 mit `SELFTEST;OK=0;ERR=TOKEN`. Es wird dabei nichts geschaltet und
nichts angefahren. Hausstandard fuer alle Aktionsendpunkte.

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

- **Ein Endpunkt** fasst Zustand, Anbauteile, Ladestation, Stufen, Statistik
  (aktuell und gesamt), Verbrauchsmaterial und Valetudos Ereignisliste
  zusammen — ein virtueller Eingang alle 30 Sekunden genügt
- **Statuszahl** für Loxone: 0 in der Ladestation, 1 bereit, 2 reinigt,
  3 pausiert, 4 fährt zur Station, 5 fährt, 8 unbekannt, 9 Fehler
- Batterie, Ladezustand, Herstellerfehlercode mit **Schwere** und **betroffenem
  Teil**
- **Anbauteile**: Staubbehälter, Wassertank, Wischmodul — und der Zustand der
  Absaugstation (bereit, saugt ab, reinigt, trocknet, Fehler)
- **Stufen**: Saugstärke, Wischwassermenge und Betriebsart, lesbar *und*
  setzbar
- Letzte Reinigung (m²/Minuten) und Gesamtwerte (m², Stunden, Anzahl)
- **Verbrauchsmaterial** in Reststunden (Filter, Haupt- und Seitenbürsten,
  Sensoren, Räder, Wischbezug) und in Prozent für die Teile der Station
  (Filter, Bürste, Staubbeutel, Reinigungsmittel) — mit eigener Warnschwelle je
  Einheit → `MATWARN`, dazu Knöpfe zum Zurücksetzen nach dem Wechsel
- **Valetudo-Ereignisse**: Staubbehälter voll, Verbrauchsteil aufgebraucht,
  Wischmodul prüfen, Störung, Karte geändert
- **Steuerung** per GET: `start`, `stop`, `pause`, `home`, `locate`,
  `segments`, `zone`, `goto`, `fan`, `wasser`, `modus`, `absaugen`,
  `wischwaschen`, `wischtrocknen`, `reset`, `ruhezeit`, `evquittieren`
- **Meldungen**: Reinigung fertig (mit Fläche und Dauer), Störung, Wartung
  fällig, Valetudo-Ereignis — als Ansage (TTS) und/oder Push über Loxone
- **Lebenszeichen**: `ALTER` und `ZAEHLER`, über MQTT `status/ok`, `status/ts`,
  `status/zaehler`
- **Selbstprüfung** im Reiter Test, **Sicherung** der Einstellungen über zwei
  Knöpfe, **Anmeldung** an Valetudo (HTTP Basic Auth)
- Bis zu **2 Roboter**, MQTT, JSON, Protokoll mit Rotation
- Reiter: Einstellungen, MQTT, Einbindung in Loxone (mit kompletter
  Baustein-Liste und zwei Vorlagen-Knöpfen), Test, Protokoll

## Endpunkte

Alle schaltenden Aufrufe brauchen `&token=`. Das aktuelle Token steht im Reiter
„Einbindung in Loxone“; ohne passendes Token antwortet der Endpunkt mit
HTTP 403.

| Aufruf | Zweck |
|---|---|
| `/plugins/saugrobo/robo.php` | Loxone-Zeile `ROBO;OK=..;CODE=..;BATT=..;…` |
| `/plugins/saugrobo/robo.php?dev=2` | derselbe Abruf für den zweiten Roboter |
| `/plugins/saugrobo/robo.php?json=1` | kompletter Zustand als JSON, inkl. Raumliste |
| `/plugins/saugrobo/robo.php?debug=1` | Klartext inkl. Fähigkeiten und Raumliste |
| `/plugins/saugrobo/robo.php?refresh=1` | Zwischenspeicher übergehen und wirklich messen |
| `/plugins/saugrobo/robo.php?selftest=1&token=…` | prüft das Token, löst nichts aus |
| `/plugins/saugrobo/robo.php?ptest=1&token=…` | setzt `PTEST=1` für fünf Minuten |
| `/plugins/saugrobo/robo.php?cmd=start&token=…` | Reinigung starten (auch `stop`, `pause`, `home`, `locate`) |
| `/plugins/saugrobo/robo.php?cmd=segments&p=1,4&token=…` | nur bestimmte Räume; `1,4x2` = zwei Durchgänge |
| `/plugins/saugrobo/robo.php?cmd=zone&p=2000,2000,3000,3000&token=…` | Zone reinigen (Kartenkoordinaten) |
| `/plugins/saugrobo/robo.php?cmd=goto&p=2500,1800&token=…` | Position anfahren (Kartenkoordinaten) |
| `/plugins/saugrobo/robo.php?cmd=fan&p=max&token=…` | Saugstärke (`off, min, low, medium, high, max, turbo`) |
| `/plugins/saugrobo/robo.php?cmd=wasser&p=low&token=…` | Wischwassermenge |
| `/plugins/saugrobo/robo.php?cmd=modus&p=vacuum&token=…` | Betriebsart (`vacuum, mop, vacuum_and_mop, vacuum_then_mop`) |
| `/plugins/saugrobo/robo.php?cmd=absaugen&token=…` | Absaugstation von Hand auslösen |
| `/plugins/saugrobo/robo.php?cmd=wischwaschen&token=…` | Wischmodul in der Station waschen |
| `/plugins/saugrobo/robo.php?cmd=wischtrocknen&token=…` | Wischmodul in der Station trocknen |
| `/plugins/saugrobo/robo.php?cmd=reset&p=filter/main&token=…` | Verbrauchsteil zurücksetzen |
| `/plugins/saugrobo/robo.php?cmd=ruhezeit&p=22:00-07:00&token=…` | Nicht-stören-Zeit setzen; `p=aus` schaltet sie ab |
| `/plugins/saugrobo/robo.php?cmd=evquittieren&token=…` | offenes Valetudo-Ereignis wegdrücken |

Die Antwortzeile trägt 41 Felder. Die vollständige Liste mit Einheit, Grenzen
und Befehlserkennung steht im Reiter „Einbindung in Loxone“ — und lässt sich
dort mit einem Knopf als fertige Datei für Loxone Config erzeugen.

## MQTT

Optional, im eigenen Reiter. Veröffentlicht wird unter dem eingestellten
Themenpräfix (Vorgabe `saugrobo`), ein zweiter Roboter unter `saugrobo/2/…`.
Neben den 41 Feldern gehen vier Klartexte hinaus (`status`, `fehlertext`,
`ereignistext`, `meldung`) und das Lebenszeichen unter `saugrobo/status/`.

**Unter MQTT-Gateway V1 muss das Abo von Hand eingetragen werden**
(System → MQTT Gateway → Subscriptions, Wert `saugrobo/#`). Ohne diesen
Eintrag kommt am Miniserver nichts an. Unter V2 erscheint die Themengruppe von
selbst. Das Plugin misst `Mqtt.Gatewayversion` und zeigt nur den Satz, der zur
installierten Fassung passt.

## Voraussetzung

Auf dem Roboter läuft **Valetudo** (Fassung mit `/api/v2`). Es werden keine
Cloud-Dienste benötigt — die Kommunikation bleibt im eigenen Netz. Hat Valetudo
eine Anmeldung eingeschaltet (Settings → Connectivity → HTTP Basic Auth),
gehören Benutzer und Kennwort in den Reiter Einstellungen.

## Datenschutz

Es sind **keine persönlichen Daten** im Plugin enthalten. Adressen und
Einstellungen liegen ausschließlich lokal
(`config/plugins/saugrobo/robo.json`, Rechte `640`).

Diese Datei trägt allerdings **das Aktionstoken** und — falls eingerichtet —
die Anmeldung an Valetudo. Dasselbe gilt für die Sicherungsdatei, die der Knopf
im Reiter Einstellungen erzeugt: ohne das Token stünden nach dem Zurückspielen
alle Felder richtig, und das Plugin käme trotzdem nicht an die Anlage. Beide
Dateien gehören behandelt wie ein Passwort — nicht in ein Forum hängen und
nicht an einen Fehlerbericht heften.

## Lizenz

MIT — siehe [LICENSE](LICENSE).
