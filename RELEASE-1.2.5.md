# School Planner 1.2.5

**Release-Titel:** `1.2.5 – Markdown-Workflow & globale Deck-Karten`
**Tag:** `v1.2.5` · **Target:** `main`

> Enthält auch die nie separat veröffentlichte Version 1.2.4.

---

## Neu

**Markdown-Datei pro Kurs anbinden**
In den Kurseinstellungen lässt sich eine `.md`-Datei aus der Nextcloud auswählen.
Über den Button **„MD-Files aktualisieren"** links werden alle angebundenen Kurse
auf einmal neu eingelesen – mit Vorschau, bevor etwas geschrieben wird.

**Vorschau mit Konfliktabfrage**
Pro Kurs wird gezeigt, welche Stunden neu angelegt und welche aktualisiert werden.
Stunden, die es in der App, aber nicht mehr in der Datei gibt, lassen sich einzeln
zum Löschen anhaken – ohne Häkchen bleiben sie erhalten.

**Bilder und Dateien aus dem Kurs-Ordner**
Relative Verweise (`![Schaubild](schaubild.png)`, `[Arbeitsblatt](ab.pdf)`) werden
beim Import als echte Anhänge übernommen und beim Veröffentlichen mit hochgeladen.
Die Links werden automatisch auf den Asset-Pfad umgeschrieben.

**Interne Lehrerhinweise im Markdown**
Zeilen, die mit `Hinweis:` (auch `Lehrerhinweis:`, `Lehrer:`, `Note:`) beginnen,
landen im internen Feld des Elements statt im Schüler-Text.

**Deckkarte anlegen (global)**
Neuer Button in der linken Navigation. Im Dialog werden Titel, Text und
Fälligkeitsdatum eingetragen – Board und Liste sind aus den Einstellungen
vorbelegt und dort einmalig definierbar.

## Geändert

- Die **kursspezifische Deck-Zuordnung** entfällt; Deck wird global konfiguriert.
- **Markdown-Tabellen** werden korrekt gerendert und auf der Schüler-Seite sauber
  formatiert.
- Das **Live-Modus-Fenster** nutzt die volle Bildschirmbreite.
- Beim Öffnen von „Deckkarte anlegen" liegt der Cursor direkt im Titel-Feld.
- Dokumentation und Beispieldateien decken jetzt Lehrerhinweise, Tabellen und
  Anhänge aus dem Kurs-Ordner ab.

## Behoben

- **Beim erneuten Import bleibt der Veröffentlicht-Status der Elemente erhalten.**
  Der Abgleich erfolgt über den Element-Titel.
- Die **Deck-Auswahl in den Einstellungen blieb leer**, solange noch kein Board
  gespeichert war. Boards werden jetzt immer geladen; bei Problemen erscheint ein
  Hinweis mit „Erneut laden".

---

## Upgrade-Hinweise

- Enthält die Migration **`Version000014`** (neue Spalte `md_file_path` in
  `schoolplanner_courses`). Sie läuft beim Upgrade automatisch; getestet unter
  Nextcloud 32 und 33.
- Die Spalten `deck_board_id` / `deck_stack_id` bleiben bestehen, werden aber nicht
  mehr genutzt. Eine bereits gesetzte kursspezifische Deck-Zuordnung wird beim
  Upgrade nicht übernommen – Board und Liste bitte einmalig unter
  **Einstellungen → Deck-Standard** setzen.
- Keine weiteren manuellen Schritte nötig.

**Kompatibilität:** Nextcloud 32–33
**Voller Changelog:** https://github.com/holger-dev/schoolplanner/compare/v1.2.3...v1.2.5
