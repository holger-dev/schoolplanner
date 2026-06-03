# Changelog

Alle nennenswerten Änderungen an School Planner. Das Format orientiert sich an
[Keep a Changelog](https://keepachangelog.com/de/), die Versionierung folgt
[SemVer](https://semver.org/lang/de/).

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
