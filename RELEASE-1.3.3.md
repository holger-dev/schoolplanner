# School Planner 1.3.3

**Release-Titel:** `1.3.3 – Ruhigere Schüler-Seite`
**Tag:** `v1.3.3` · **Target:** `main`

---

## Geändert

**Die Seite springt nicht mehr dauernd.**
Das automatische Scrollen zum aktuellen Element lief bei jedem Stundenwechsel
und nach jedem Neuladen – also alle paar Sekunden, sobald die Seite im
Hintergrund aktualisiert wurde. Jetzt passiert es **genau einmal**: dann, wenn
gerade ein neues Element freigegeben wurde.

**Wichtige Links sind jederzeit erreichbar.**
Kursname und Links sitzen in einer schmalen Leiste, die beim Scrollen oben
stehen bleibt. Der große Kopfbereich mit der riesigen Überschrift entfällt
dafür – das spart gleich mehrere Bildschirmhöhen.

**Die Stundenliste scrollt unabhängig vom Inhalt.**
Vorher musste man erst durch die gesamte aktuelle Stunde scrollen, um die
nächste anzuklicken. Die Liste hat jetzt ihren eigenen Scrollbereich und bleibt
stehen.

**Kompakte Stundenliste.**
Eine Zeile je Termin: Wochentag, Datum, Titel und die Anzahl der freigegebenen
Elemente. Vorher war jede Stunde eine Kachel mit Datum, Titel, Zielsatz und
Fußzeile – bei 20 bis 30 Terminen nicht mehr zu überblicken.

**Weniger Leerraum.**
Unter der Stunde standen 28 % der Fensterhöhe leer. Das war nur nötig, damit das
automatische Scrollen mittig ausrichten konnte – mit dem Scrollen fällt auch der
Leerraum weg.

## Upgrade-Hinweise

- **Keine Datenbankänderung**, keine Migration.
- Die Änderungen betreffen ausschließlich die **veröffentlichte Schüler-Seite**.
  An der Bedienung in der Nextcloud ändert sich nichts.
- Die Seiten werden beim nächsten Veröffentlichen neu erzeugt. Wer das sofort
  sehen will: einmal **Planung veröffentlichen**.

**Kompatibilität:** Nextcloud 32–33
**Voller Changelog:** https://github.com/holger-dev/schoolplanner/compare/v1.3.2...v1.3.3
