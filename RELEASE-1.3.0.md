# School Planner 1.3.0

**Release-Titel:** `1.3.0 – Schnittstelle für die Offline-App`
**Tag:** `v1.3.0` · **Target:** `main`

---

## Neu

**Sync-Schnittstelle für den Offline-Client**
School Planner kann seine Planung jetzt an ein externes Programm ausliefern und
erfasste Mitarbeit zurücknehmen. Gebaut für den Begleiter
[School Planner Offline](https://github.com/holger-dev/schoolplanneroffline),
mit dem sich Unterricht ohne Netz durchführen und abends abgleichen lässt.

Drei Endpunkte unter `/api/v1/`:

- `GET /info` – Verbindungstest: Wer bin ich, welche Fassung läuft hier?
- `GET /sync` – Planung im Zeitfenster lesen (Vorgabe: 14 Tage zurück bis
  42 Tage voraus), inklusive Kursen, Stunden, Ablaufelementen, Lehrerhinweisen,
  Schüler:innen und bereits erfasster Mitarbeit.
- `POST /state` – Mitarbeit, Freigabe eines Elements und aktuellen Schritt
  zurückschreiben.

**Anmeldung per App-Passwort**
Der Client meldet sich mit einem Nextcloud-App-Passwort an. Das funktioniert
auch mit Zwei-Faktor-Anmeldung und lässt sich einzeln widerrufen, ohne das
eigene Passwort zu ändern. Schritt-für-Schritt-Anleitung in
[`docs/api-sync.md`](docs/api-sync.md).

**Zeitstempel in der Antwort**
Stunden und Ablaufelemente liefern zusätzlich `updatedAt`. Darüber entscheidet
der Abgleich, welche Fassung bei einem Konflikt gewinnt.

## Wie der Abgleich gedacht ist

**Schreibbar sind ausschließlich Zustände** – Mitarbeit, `published` und
`isCurrent`. Inhalte wie Stunden, Texte und Schüler:innen sind über die
Schnittstelle nur lesbar und werden weiterhin in der Weboberfläche gepflegt.
Damit legt ein Client nie Datensätze mit unbekannter ID an, und der Abgleich
kommt ohne UUID-Zuordnung, Grabsteine und Text-Zusammenführung aus.

**Konfliktregel je Zeile:** Ist der mitgelieferte Zeitstempel neuer als der
gespeicherte, wird geschrieben; bei Gleichstand gewinnt der Server. Verworfene
Zeilen kommen als `skipped` samt aktuellem Serverstand zurück – der Client soll
beide Fassungen zeigen, statt still zu überschreiben.

**Kein Delta.** Es wird immer das vollständige Zeitfenster geliefert, nicht nur
das seit einem Zeitpunkt Geänderte. Das kostet ein paar hundert Kilobyte und
spart dafür Grabsteine für gelöschte Stunden, einen Cursor und jede
Abhängigkeit von der Uhr des Endgeräts.

## Upgrade-Hinweise

- **Keine Datenbankänderung.** Alle benötigten Spalten waren bereits vorhanden,
  es läuft keine neue Migration.
- Die neuen Endpunkte liegen in einem eigenen Controller mit
  `@NoCSRFRequired`; die Routen der Weboberfläche behalten ihre
  CSRF-Absicherung unverändert.
- Wer die Offline-App nicht nutzt, merkt von diesem Release nichts – es ändert
  sich nichts an der Bedienung.

**Kompatibilität:** Nextcloud 32–33
**Voller Changelog:** https://github.com/holger-dev/schoolplanner/compare/v1.2.5...v1.3.0
