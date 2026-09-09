# Sync-Schnittstelle für den Offline-Client

Ab **School Planner 1.3.0** gibt es eine schmale Schnittstelle, über die ein
externes Programm die Planung lesen und Mitarbeit zurückschreiben kann. Gebaut
für [School Planner Offline](https://github.com/holger-dev/schoolplanneroffline),
nutzbar aber von jedem Programm, das HTTP sprechen kann.

- **API-Version:** 1
- **Basis:** `https://DEINE-CLOUD/index.php/apps/schoolplanner/api/v1/`
- **Anmeldung:** Benutzername + **App-Passwort** (HTTP Basic)

---

## 1. App-Passwort anlegen

Ein App-Passwort ist ein eigenes Kennwort nur für ein Programm. Vorteile
gegenüber dem normalen Passwort: Es funktioniert auch mit
Zwei-Faktor-Anmeldung, und du kannst es **einzeln widerrufen**, ohne dein
Hauptpasswort zu ändern.

1. In der Nextcloud oben rechts auf dein Bild klicken → **Einstellungen**.
2. Links unter *Persönlich* auf **Sicherheit**.
3. Ganz unten der Abschnitt **Geräte & Sitzungen**.
4. In das Feld **App-Name** etwas Wiedererkennbares eintragen, zum Beispiel
   `School Planner Offline – Laptop`, dann auf **Neues App-Passwort erstellen**.
5. Nextcloud zeigt jetzt **Benutzername** und **Passwort** an.
   Das Passwort sieht aus wie `abcde-fghij-klmno-pqrst-uvwxy` und wird
   **nur dieses eine Mal angezeigt** – jetzt kopieren.
6. Beides im Offline-Client eintragen, zusammen mit der Adresse deiner Cloud.

Zugang später entziehen: dieselbe Seite, beim Eintrag auf das Papierkorbsymbol.
Der Client meldet sich danach mit „nicht autorisiert" – gespeicherte Daten auf
dem Gerät bleiben davon unberührt.

> Ein App-Passwort hat **dieselben Rechte wie dein Konto**. Lege es nicht auf
> fremden Rechnern an und gib es nicht weiter.

### Kurz geprüft

```bash
curl -u 'BENUTZER:APP-PASSWORT' \
  'https://DEINE-CLOUD/index.php/apps/schoolplanner/api/v1/info'
```

Antwort:

```json
{ "apiVersion": 1, "appVersion": "1.3.0", "serverTime": "2026-09-09T12:00:00+02:00",
  "user": "holger", "courses": 4 }
```

Kommt stattdessen HTML zurück, stimmt meist die Adresse nicht – achte auf
`/index.php/apps/schoolplanner/...`. Kommt `401`, stimmt das App-Passwort nicht.

---

## 2. Planung lesen

```
GET /api/v1/sync?from=2026-09-01&to=2026-10-15
```

Beide Parameter sind optional. Ohne Angabe liefert der Server **14 Tage zurück
bis 42 Tage voraus**. Mehr als 400 Tage werden abgeschnitten.

```bash
curl -u 'BENUTZER:APP-PASSWORT' \
  'https://DEINE-CLOUD/index.php/apps/schoolplanner/api/v1/sync?from=2026-09-01&to=2026-09-30'
```

```json
{
  "apiVersion": 1,
  "serverTime": "2026-09-09T12:00:00+02:00",
  "window": { "from": "2026-09-01", "to": "2026-09-30" },
  "courses": [
    {
      "id": 3,
      "name": "Informatik 9b",
      "participationScale": "scale5",
      "students": [{ "id": 12, "name": "Amelie Berg", "note": "" }],
      "lessons": [
        {
          "id": 41, "lessonDate": "2026-09-10", "lessonSlot": 2,
          "title": "Schleifen: while", "goal": "…", "description": "…",
          "updatedAt": "2026-09-08T19:22:41+02:00",
          "items": [
            {
              "id": 87, "title": "Einstieg", "description": "…",
              "teacherNote": "Nur 5 Minuten.",
              "published": false, "isCurrent": false, "sortOrder": 0,
              "updatedAt": "2026-09-08T19:22:41+02:00",
              "attachments": [{ "id": 5, "fileName": "ab.pdf", "mimeType": "application/pdf", "size": 81234 }]
            }
          ]
        }
      ]
    }
  ],
  "participation": [
    { "lessonId": 41, "studentId": 12, "status": "present", "scale": "scale5",
      "grade": "+", "note": "", "updatedAt": "2026-09-10T09:14:02+02:00" }
  ]
}
```

**Bewusst kein Delta.** Es wird immer das vollständige Fenster geliefert, nicht
nur das seit einem Zeitpunkt Geänderte. Das kostet ein paar hundert Kilobyte und
spart dafür Grabsteine für gelöschte Stunden, einen Cursor und jede Abhängigkeit
von der Uhr des Endgeräts. Gelöschte Stunden verschwinden implizit, weil sie im
Ausschnitt fehlen.

---

## 3. Zustände zurückschreiben

```
POST /api/v1/state
Content-Type: application/json
```

Schreibbar sind **ausschließlich Zustände**: Mitarbeit, die Freigabe eines
Elements und der aktuelle Schritt. Inhalte – Stunden, Texte, Schüler:innen –
sind nur lesbar und werden weiterhin in der Weboberfläche gepflegt.

```bash
curl -u 'BENUTZER:APP-PASSWORT' -H 'Content-Type: application/json' \
  -d '{
        "participation": [
          { "lessonId": 41, "studentId": 12, "status": "present",
            "grade": "++", "note": "hat moderiert",
            "updatedAt": "2026-09-10T09:14:02+02:00" }
        ],
        "items": [
          { "id": 87, "published": true, "isCurrent": true,
            "updatedAt": "2026-09-10T09:05:00+02:00" }
        ]
      }' \
  'https://DEINE-CLOUD/index.php/apps/schoolplanner/api/v1/state'
```

Antwort je Zeile:

```json
{
  "apiVersion": 1,
  "serverTime": "2026-09-10T18:02:00+02:00",
  "participation": [
    { "lessonId": 41, "studentId": 12, "result": "applied",
      "updatedAt": "2026-09-10T18:02:00+02:00" }
  ],
  "items": [
    { "id": 87, "result": "skipped",
      "server": { "published": true, "isCurrent": false,
                  "updatedAt": "2026-09-10T17:40:11+02:00" } }
  ]
}
```

| `result`  | Bedeutung                                                             |
|-----------|-----------------------------------------------------------------------|
| `applied` | geschrieben                                                           |
| `skipped` | der Serverstand ist neuer – verworfen, aktueller Stand liegt bei      |
| `unknown` | Stunde, Element oder Schüler:in gehört nicht zu diesem Konto          |

Ab 1.3.1 enthält die Antwort zusätzlich `published`: Für jeden Kurs, in dem ein
Element geändert wurde, wird die **Schüler-Seite neu erzeugt** – sonst bliebe
eine Freigabe ohne Wirkung. Schlägt das fehl (typisch: fehlende
SFTP-Zugangsdaten), bricht der Abgleich nicht ab, meldet es aber:

```json
"published": [ { "courseId": 3, "ok": false, "error": "…" } ]
```

**Konfliktregel:** Ist der mitgelieferte `updatedAt` **neuer** als der
gespeicherte, wird geschrieben; bei Gleichstand gewinnt der Server. Verglichen
werden dabei nur Serverzeiten – `updatedAt` stammt aus einer früheren Antwort
dieser Schnittstelle. Der Client soll `skipped` **nicht stillschweigend
verwerfen**, sondern beide Fassungen zeigen und die Lehrkraft entscheiden
lassen.

---

## 4. Erlaubte Werte

| Feld                 | Werte                                                        |
|----------------------|--------------------------------------------------------------|
| `status`             | `''`, `present`, `excused`, `unexcused`                      |
| `participationScale` | `''` (keine Note), `scale3`, `scale5`, `note`                |
| `grade` bei `scale3` | `+`, `+/-`, `-`                                              |
| `grade` bei `scale5` | `++`, `+`, `+/-`, `-`, `--`                                  |
| `grade` bei `note`   | `1` bis `6`                                                  |

Die Skala ist eine **Kurseinstellung**. Der Server übernimmt sie beim Schreiben
aus dem Kurs; ein vom Client mitgeschicktes `scale` wird ignoriert. Unbekannte
Werte für `status` landen als leerer Wert.

Einen Stundenstatus („geplant / läuft / fertig") gibt es bewusst **nicht** als
Feld – er lässt sich aus `isCurrent` und `published` der Elemente ableiten.

---

## 5. Hinweise zur Umsetzung

**Reihenfolge:** erst `POST /state`, dann `GET /sync`. So gehen eigene
Änderungen nicht durch einen Abruf verloren, der sie noch nicht kennt.

**Zeitstempel beim Erfassen setzen, nicht beim Senden.** Sonst gewinnt beim
Abgleich das Gerät, das zufällig zuletzt Netz hatte – nicht die zuletzt
getroffene Entscheidung.

**„Muss übertragen werden" nicht über Zeitstempel bestimmen.** Ein Client sollte
sich lokal merken, welche Datensätze noch offen sind. Geht die Uhr des Servers
vor, würden lokale Änderungen sonst als älter gelten und still nie gesendet.

**Offline ist der Normalfall.** Fehlgeschlagene Abrufe dürfen nichts löschen und
nichts blockieren.

**CORS:** Die Endpunkte antworten auf Vorabanfragen. Ein Programm ohne Browser
(etwa die Electron-Fassung, die im Hauptprozess anfragt) braucht das nicht.

---

## 6. Fehler

| Code  | Ursache                                                       |
|-------|----------------------------------------------------------------|
| `401` | App-Passwort falsch oder widerrufen                            |
| `404` | Adresse falsch, oder die App ist in der Nextcloud deaktiviert  |
| `500` | siehe `data/nextcloud.log`                                     |

Vor der Fehlersuche lohnt `GET /api/v1/info` – geht das durch, stimmen Adresse
und Anmeldung, und das Problem liegt woanders.
