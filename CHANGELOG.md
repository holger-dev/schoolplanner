# Changelog

Alle nennenswerten Änderungen an School Planner. Das Format orientiert sich an
[Keep a Changelog](https://keepachangelog.com/de/), die Versionierung folgt
[SemVer](https://semver.org/lang/de/).

## [1.3.1] – 2026-09-09

### Behoben
- **Freigaben aus dem Offline-Client erreichten die Schüler-Seite nicht.** Die
  Weboberfläche stößt nach einer Änderung ein erneutes Veröffentlichen an; die
  Sync-Schnittstelle schrieb dagegen nur in die Datenbank. Betroffene Kurse
  werden jetzt nach jedem erfolgreichen `POST /api/v1/state` neu erzeugt.
- Die Antwort enthält dazu ein Feld `published` mit dem Ergebnis je Kurs. Fehler
  (etwa fehlende SFTP-Zugangsdaten) brechen den Abgleich nicht ab, werden dem
  Client aber gemeldet, statt unbemerkt zu bleiben.

## [1.3.0] – 2026-09-09

### Hinzugefügt
- **Sync-Schnittstelle für den Offline-Client** unter `/api/v1/`, angemeldet per
  App-Passwort. Drei Endpunkte: `GET /info` (Verbindungstest), `GET /sync`
  (Planung im Zeitfenster lesen) und `POST /state` (Mitarbeit, Freigabe und
  aktuellen Schritt zurückschreiben).
- Anleitung inklusive **App-Passwort einrichten** in
  [`docs/api-sync.md`](docs/api-sync.md).
- Stunden und Elemente liefern jetzt zusätzlich `updatedAt`; darüber entscheidet
  der Abgleich, welche Fassung bei einem Konflikt gewinnt.

### Hinweise
- **Keine Datenbankänderung.** Alle benötigten Spalten waren bereits vorhanden.
- Schreibbar sind über die Schnittstelle ausschließlich **Zustände** (Mitarbeit,
  `published`, `isCurrent`). Inhalte werden nur gelesen und weiterhin in der
  Weboberfläche gepflegt.
- Die Endpunkte liegen bewusst in einem eigenen Controller mit
  `@NoCSRFRequired`; die Routen der Weboberfläche behalten ihre
  CSRF-Absicherung unverändert.

## [1.2.5] – 2026-09-09

### Hinzugefügt
- **„Deckkarte anlegen"** als eigener Button in der linken Navigation (zwischen
  „Blockansicht" und „Planung veröffentlichen"). Im Modal werden Titel, Text und
  Fälligkeitsdatum eingetragen – Board und Liste sind bereits vorausgewählt.
- **Deck-Standard in den Einstellungen**: Board und Liste werden einmal
  ausgewählt und für jede neue Karte automatisch übernommen.

### Geändert
- Die **kursspezifische Deck-Zuordnung** wurde entfernt; Deck wird jetzt global
  konfiguriert. Der Eintrag „Deck" im Kurs-Menü entfällt.
- Beim Öffnen von „Deckkarte anlegen" liegt der Cursor direkt im **Titel-Feld**,
  statt das Board-Dropdown zu öffnen.
- Doku und Beispieldateien beschreiben jetzt Lehrerhinweise (`Hinweis:`),
  Markdown-Tabellen und Anhänge aus dem Kurs-Ordner.

### Behoben
- Die **Deck-Auswahl in den Einstellungen blieb leer**, solange noch kein Board
  gespeichert war – die Boards werden jetzt immer geladen; bei Problemen
  erscheint ein Hinweis mit „Erneut laden".

## [1.2.4] – 2026-06-04

### Hinzugefügt
- **Markdown-Datei an einen Kurs binden** (Kurseinstellungen → „Angebundene
  Markdown-Datei"). Über den neuen Button **„MD-Files aktualisieren"** links
  werden alle angebundenen Dateien auf einmal neu eingelesen.
- **Vorschau mit Konfliktabfrage** vor dem Aktualisieren: pro Kurs wird gezeigt,
  welche Stunden neu angelegt bzw. aktualisiert werden. Stunden, die es in der
  App, aber nicht mehr in der MD-Datei gibt, lassen sich einzeln zum Löschen
  anhaken – ohne Häkchen bleiben sie erhalten.
- **Verlinkte Bilder, PDFs und andere Dateien** aus dem Kurs-Ordner werden beim
  Import als echte Anhänge übernommen und beim Veröffentlichen mit hochgeladen;
  relative Links im Markdown werden automatisch auf den Asset-Pfad umgeschrieben,
  sodass Bilder direkt auf der Schüler-Seite erscheinen.

### Geändert
- **Markdown-Tabellen** werden jetzt korrekt gerendert (Tabellen-Erweiterung
  aktiviert) und auf der veröffentlichten Seite sauber gestaltet.
- Das **Live-Modus-Fenster** nutzt jetzt die volle Bildschirmbreite.

### Behoben
- **Beim erneuten Import bleibt der Status der Elemente erhalten**: bereits
  veröffentlichte (und als „aktuell" markierte) Elemente werden nicht mehr
  zurückgesetzt. Der Abgleich erfolgt über den Element-Titel.

## [1.2.3] – 2026-06-04

### Hinzugefügt
- **Lehrer-Hinweise im Markdown-Import**: Zeilen, die mit `Hinweis:` (auch
  `Lehrer:`, `Lehrerhinweis:`, `Note:`) beginnen, landen im internen Feld
  „Hinweise für Lehrer:in" des Elements statt im Schüler-Text. Vor dem ersten
  `##` gesetzt, füllen sie das „Fazit der Stunde"; alternativ als Kopfzeile
  `reflection:` bzw. `fazit:`.
- **Export als ODF-Präsentationen (alle Kurse auf einmal)**: Ein Button
  „Planung als ODP herunterladen" im Einstellungs-Menü (links). Ein Klick
  erzeugt ein einziges ZIP `Schoolplanner_<Datum_Zeit>.zip` mit je einem Ordner
  pro Kurs; darin pro Stunde eine `.odp`-Präsentation (Titelfolie + eine Folie je
  Ablauf-Element) im dunklen Design der Webseite, plus alle hochgeladenen Dateien
  – benannt nach der zugehörigen Stunde (`<stunde>__<datei>`). Ohne Server/SFTP.

## [1.2.2] – 2026-06-04

### Hinzugefügt
- **SFTP-Zielverzeichnis** in den Einstellungen (z. B. `html`): Veröffentlichte
  Dateien landen nun im gewählten Verzeichnis statt zwingend im Server-Root.
  Relative Pfade werden vom SFTP-Login-Verzeichnis aus aufgelöst – damit
  funktioniert das Publishing auf Hostern wie Uberspace.
- **Optionales SFTP-Host-Feld**: Falls der SFTP-Host nicht der Webadresse
  entspricht (z. B. `user.uber.space`), lässt er sich jetzt separat angeben.
  Leer = wie bisher aus der Webadresse abgeleitet.

### Behoben
- Verzeichnisanlage auf dem Server respektiert relative Zielpfade (legte vorher
  immer absolute Pfade ab Server-Wurzel an).

## [1.2.1] – 2026-06-04

### Behoben
- **Upgrade-Absturz auf Nextcloud 32/33**: Die Tabelle `sp_student_group_members`
  erzeugte einen zu langen Primärschlüssel-Namen (`oc_sp_student_group_members_pkey`,
  32 Zeichen > NC-Limit von 30) und ließ die Migration – und damit das
  App-Upgrade – fehlschlagen. Tabelle umbenannt zu `sp_group_members`.
- Bootstrap härter: `vendor/autoload.php` wird nur noch geladen, wenn die Datei
  existiert, damit ein unvollständiges Paket nicht die ganze Instanz lahmlegt.

### Hinweis
- Es gehen keine Daten verloren: Die Migration brach vor dem Anlegen der Tabellen
  ab. Nach dem Update auf 1.2.1 laufen die Migrationen sauber durch.

## [1.2.0] – 2026-06-03

### Hinzugefügt
- **Planung als JSON** exportieren und importieren. Stunden werden über
  Datum + Slot mit dem bestehenden Kurs zusammengeführt (gleiche Kombination
  wird überschrieben, neue angelegt), mit Vorschau vor dem Import.
- **Import aus Markdown-Dateien** in einem Nextcloud-Ordner: eine Stunde je
  `date:`-Block, mehrere Stunden pro Datei möglich, `## Überschriften` werden zu
  Ablauf-Elementen. Keine `---`-Zeilen nötig. Anleitung in
  [`docs/markdown-import.md`](docs/markdown-import.md), Beispiele in
  [`examples/`](examples/).
- **Schüler:innen und Gruppen** je Kurs inkl. flexiblem Schnell-Import (#7).
- **Zentrale „Wichtige Links" pro Kurs** (#9).
- **Deck-Anbindung**: pro Kurs ein Deck-Board/Liste hinterlegen und eine Stunde
  als Karte anlegen (#2).
- **Mitarbeit pro Stunde erfassen**: Status (Anwesend / Entschuldigt /
  Unentschuldigt), Note und Notiz je Schüler:in. „Anwesend" ist Standard.
- **Schnelle Noteneingabe**: +/−-Buttons für Skala 1–3 (`+`, `+/-`, `-`) und
  Skala 1–5 (`++`, `+`, `+/-`, `-`, `--`), Zahlen-Dropdown für Note 1–6.
- **Mitarbeit-Übersicht**: Tabelle Schüler:innen × Stunden mit automatischer
  Durchschnittsberechnung (Ø-Spalte, fixiert), schmalen, scrollbaren
  Stunden-Spalten und klickbaren Notiz-Icons je Zelle.
- **Bewertungsskala pro Kurs** in den Kurseinstellungen (Keine Note / 1–3 /
  1–5 / Note 1–6), gilt für alle Stunden.

### Geändert
- Links auf der veröffentlichten Schüler-Seite öffnen in einem neuen Tab (#1)
  und stehen jetzt im Kurs-Kopf oben rechts als kompakte Chips – immer präsent,
  ohne Platzverlust für die Stunde.
- Neue Elemente lassen sich an beliebiger Position einfügen (#6), in die nächste
  Stunde verschieben (#5) und per Drag & Drop umsortieren (#4).
- Speicherverhalten stabilisiert (#3): keine verlorenen Eingaben durch parallele
  Autosaves; zusätzlich speichert jetzt der gesamte Stundenkopf (Datum, Slot,
  Thema, Ziel, Beschreibung) automatisch.
- Kurs-Werkzeuge in zwei aufgeräumte Aktionsmenüs gruppiert; „Element einfügen /
  hinzufügen" als einheitliche Streifen; kompaktere Schüler:innen-Verwaltung;
  „Live-Modus"-Button orange mit „Mitarbeit erfassen" daneben.

### Entfernt
- Die anbieterabhängige KI-Schnittstelle (Nextcloud TextProcessing) zugunsten
  des offline nutzbaren JSON-/Markdown-Imports, der mit jeder beliebigen KI
  funktioniert (#8).

### Behoben
- Aktive Bewertungs-Buttons werden zuverlässig blau (globale `:focus`-Styles
  hatten die Markierung überschrieben).
- Das Drei-Punkte-Aktionsmenü schließt sich nach der Auswahl.
- Status-Auswahl im Mitarbeit-Dialog überlappt nicht mehr die Notenspalte.
- Elemente löschen und Dateien hochladen funktioniert auch ohne konfiguriertes
  SFTP – das automatische Veröffentlichen ist jetzt ausfallsicher.

### Hinweise
- Diese Version bringt neue Datenbanktabellen/-spalten mit. Die Migrationen
  laufen beim App-Upgrade automatisch – dafür muss die App-Version erhöht sein
  (ggf. `occ upgrade` bzw. App deaktivieren/aktivieren).

## [1.0.6] – 2026-03-23

- Letzter Stand vor den oben genannten Erweiterungen (Kurse, Stunden, Elemente,
  Live-Modus, Veröffentlichung via SFTP, ZIP-Export/-Import).
