# School Planner Offline-App – Konzept

Stand: 09.09.2026 · Bezug: School Planner 1.2.5

Ziel: eine schlanke App fürs Klassenzimmer, die **ohne Netz** funktioniert und
ihre Daten in beide Richtungen mit der Nextcloud-App abgleicht. Kein zweiter
voller Editor – ein Begleiter für den Unterricht.

---

## 1. Die zentrale Entwurfsentscheidung

**Die App schreibt genau eine Sache: Mitarbeit. Alles andere ist ein lesender Cache.**

Das ist der wichtigste Punkt des ganzen Konzepts. Sobald die App auch Stunden,
Elemente und Beschreibungen offline bearbeiten darf, wird aus einem einfachen
Abgleich ein echtes verteiltes System: Tombstones für Löschungen, ID-Mapping für
offline angelegte Datensätze, feldweises Merging von Markdown-Texten und eine
Konflikt-Oberfläche, die niemand bedienen will. Das ist Monatsarbeit und eine
dauerhafte Fehlerquelle.

Mitarbeit dagegen ist **natürlich konfliktarm**: Ein Eintrag hängt an dem Paar
`(lesson_id, student_id)` – beides existiert auf dem Server bereits, bevor die App
etwas schreibt. Die App legt also **nie** Datensätze mit unbekannter ID an.
Daraus folgt für Version 1:

- kein ID-Mapping
- keine UUIDs
- keine Tombstones
- keine Merge-Oberfläche

Der Push ist ein simples, idempotentes Upsert. Genau deshalb ist das Vorhaben
überhaupt an einem Wochenende machbar.

Planung bearbeiten bleibt in der Nextcloud-App. Das ist keine Einschränkung,
sondern die Arbeitsteilung: **planen am Rechner, durchführen und bewerten in der
Klasse.**

---

## 2. Framework: Flutter

**Empfehlung: Flutter** (Dart) mit `drift` (SQLite) als lokaler Datenbank.

Begründung – der entscheidende Punkt ist Datensicherheit, nicht Komfort:
Eine PWA wäre billiger (dein Vue-Wissen, kein App Store), aber unter iOS kann
Safari IndexedDB bei Speicherdruck **löschen**, und verlässliche
Hintergrund-Synchronisation gibt es dort nicht. Eine Lehrkraft, die nach einer
Doppelstunde ihre Mitarbeitsnoten verliert, benutzt die App nie wieder. SQLite in
einer nativen App hat dieses Problem nicht.

Dazu kommt: eine Codebasis für iOS, Android und – falls später gewünscht –
Desktop.

Preis der Entscheidung, ehrlich benannt: Dart ist neu für dich, es gibt keine
Wiederverwendung aus dem Vue-Code, und du brauchst Apple-Developer-Account und
Signing. Wenn du erst herausfinden willst, **ob** die App überhaupt genutzt wird,
ist ein PWA-Prototyp der schnellere Weg zur Antwort – aber nicht das Ziel.

Bausteine:

- `drift` – lokale SQLite-Datenbank, typsicher
- `dio` oder `http` – REST
- `flutter_secure_storage` – App-Passwort in Keychain/Keystore
- `flutter_web_auth_2` – Login-Flow im Systembrowser
- `riverpod` – Zustandsverwaltung

---

## 3. Authentifizierung: Login Flow v2

**Niemals Benutzername und Passwort in der App abfragen.** Nextcloud bringt genau
dafür den Login Flow v2 mit – so machen es auch die offiziellen Apps:

1. `POST /index.php/login/v2` → liefert `login`-URL und `poll`-Token
2. App öffnet die `login`-URL im **Systembrowser**; die Lehrkraft meldet sich
   dort ganz normal an (inklusive 2FA und SSO)
3. App pollt `POST /index.php/login/v2/poll` → erhält `server`, `loginName`,
   `appPassword`
4. App-Passwort in `flutter_secure_storage` ablegen, danach jeder Request per
   HTTP Basic Auth

Vorteile: kein Klartext-Passwort in der App, 2FA funktioniert, und die Lehrkraft
kann den Zugang jederzeit in den Nextcloud-Sicherheitseinstellungen einzeln
widerrufen.

---

## 4. Was der Server dazu braucht

Der bestehende Zustand ist günstig:

- `created_at` / `updated_at` liegen bereits auf **allen** relevanten Tabellen
  (`schoolplanner_courses`, `_lessons`, `_items`, `sp_students`,
  `sp_student_groups`, `sp_participation`) und werden bei jedem Schreibvorgang
  gesetzt.
- `sp_participation` hat bereits einen Unique-Index auf `(lesson_id, student_id)` –
  exakt der Schlüssel, den der Abgleich braucht.

Zu ergänzen ist wenig:

### 4.1 Ein eigener `SyncController`

Die bestehenden Endpunkte sind auf die Web-Session ausgelegt. Für einen externen
Client braucht es einen getrennten Controller mit `@NoAdminRequired` **und**
`@NoCSRFRequired`, unter eigenem, versioniertem Präfix `/api/v1/`. Bewusst nicht
die vorhandenen Routen aufbohren – die Weboberfläche soll ihre CSRF-Absicherung
behalten.

### 4.2 `GET /api/v1/sync?since=<ISO8601>&from=<date>&to=<date>`

Liefert alles, was sich seit `since` geändert hat – eingeschränkt auf ein
Zeitfenster von Stunden (Vorschlag: −2 bis +6 Wochen um heute; die App braucht
keine Planung aus dem letzten Schuljahr).

```json
{
  "apiVersion": 1,
  "serverTime": "2026-09-09T10:14:03+00:00",
  "courses":      [ { "id": 3, "name": "Informatik 9b", "participationScale": "1-5", "updatedAt": "…" } ],
  "lessons":      [ { "id": 41, "courseId": 3, "date": "2026-09-10", "slot": 2, "title": "…", "goal": "…", "description": "…", "updatedAt": "…" } ],
  "items":        [ { "id": 87, "lessonId": 41, "title": "…", "description": "…", "teacherNote": "…", "sortOrder": 0, "updatedAt": "…" } ],
  "students":     [ { "id": 12, "courseId": 3, "name": "…", "note": "…", "updatedAt": "…" } ],
  "participation":[ { "lessonId": 41, "studentId": 12, "status": "present", "scale": "1-5", "grade": "+", "note": "…", "updatedAt": "…" } ]
}
```

### 4.3 `POST /api/v1/participation/batch`

```json
{ "entries": [
  { "lessonId": 41, "studentId": 12, "status": "present", "grade": "+", "note": "…", "updatedAt": "2026-09-10T09:12:00+00:00" }
] }
```

Regel auf dem Server, pro Zeile: Ist das mitgelieferte `updatedAt` **neuer** als
das gespeicherte `updated_at`, wird geschrieben; sonst nicht. Gleichstand → Server
gewinnt. Die Antwort meldet je Zeile `applied`, `skipped` (mit dem aktuellen
Serverstand) oder `missing` (Stunde oder Schüler:in existiert nicht mehr).

Das ist bewusst **zeilenweise** Last-Write-Wins, nicht feldweise. Didaktisch
vertretbar: Was zuletzt getippt wurde, war gemeint.

### 4.4 Optional, später

`ETag`/`If-None-Match` auf dem Sync-Endpunkt spart Datenvolumen. Anhänge und
Bilder bleiben in Version 1 außen vor – die App zeigt sie als Link, der online
geöffnet wird.

---

## 5. Der Abgleich – drei Fallen

**1. Niemals die Gerätezeit als Cursor benutzen.** Die App speichert den
`serverTime`-Wert der letzten Antwort und schickt ihn beim nächsten Mal als
`since`. Die Uhr des Tablets kann falsch gehen; die des Servers ist die einzige
Wahrheit.

**2. Sekundengenauigkeit verliert Datensätze.** Wird ein Datensatz in derselben
Sekunde geschrieben, in der der Sync läuft, fällt er durchs Raster. Lösung:
vom Cursor **zwei Sekunden abziehen** und clientseitig idempotent per `upsert`
einspielen. Kostet ein paar überflüssige Zeilen, verhindert stillen Datenverlust.

**3. `updatedAt` beim Erfassen setzen, nicht beim Senden.** Sonst gewinnt beim
Abgleich das Gerät, das zufällig zuletzt Netz hatte – nicht die zuletzt getroffene
Entscheidung.

Ablauf in der App: beim Start, beim Wechsel in den Vordergrund und auf Knopfdruck
erst **pushen**, dann **pullen**. Kein Hintergrund-Sync unter iOS – das ist nicht
verlässlich und der manuelle Knopf reicht.

Unsynchronisierte Einträge bekommen ein sichtbares Abzeichen („3 Änderungen nicht
übertragen"), damit niemand im Glauben ist, alles sei in der Cloud.

---

## 6. Funktionsumfang Version 1

Drin:

- Login über Login Flow v2
- Abgleich (Kurse, Stunden im Zeitfenster, Elemente, Schüler:innen, Mitarbeit)
- Startbildschirm **„Heute"**: alle Stunden des Tages über alle Kurse
- Stundenansicht: Ablauf-Elemente lesen, inklusive interner Lehrerhinweise –
  im Grunde der Live-Modus offline
- **Mitarbeit erfassen**: Anwesenheit, Bewertungsskala des Kurses, Notiz
- Kursübersicht Mitarbeit mit Durchschnitt
- Sync-Knopf mit Status und Zähler offener Änderungen

Bewusst draußen:

Planung bearbeiten, Markdown-Import, Veröffentlichen, ODP-Export, Deck, Anhänge
hochladen. Alles davon gehört an den Rechner und würde die App aufblähen.

---

## 7. Aufteilung der Repositories

Neues Repo **`schoolplanner-app`** für die Flutter-App.

Der API-Vertrag lebt aber im **bestehenden** Repo unter `docs/api-sync.md`, weil
er zusammen mit dem Server versioniert werden muss. Die App prüft beim Login das
Feld `apiVersion` und sagt der Lehrkraft im Klartext, wenn die Nextcloud-App zu
alt ist – statt an unverständlichen Fehlern zu scheitern.

Serverseitig wandert der Sync-Endpunkt in die Nextcloud-App (Version 1.3.0), denn
ohne ihn hat die App keine Gegenstelle.

---

## 8. Reihenfolge

1. `SyncController` mit beiden Endpunkten in der Nextcloud-App, gegen `curl`
   getestet – **bevor** eine Zeile Dart entsteht
2. `docs/api-sync.md` schreiben, Release 1.3.0
3. Flutter-Projekt, Login Flow v2, App-Passwort sicher ablegen
4. Lokales Schema mit `drift`, Pull-Sync, „Heute"-Ansicht (nur lesen)
5. Mitarbeit offline erfassen, Push-Sync, Konfliktmeldung
6. Feldtest über zwei Wochen im eigenen Unterricht, dann erst Store

Aufwand, grob geschätzt: Server ein bis zwei Tage. App zwei bis vier Wochenenden,
wenn Dart neu ist – der Login-Flow und das lokale Schema kosten dabei mehr Zeit
als die Oberfläche.

---

## 9. Was schiefgehen kann

- **Eine Stunde wird am Rechner gelöscht**, während auf dem Tablet noch nicht
  übertragene Mitarbeit dazu liegt. Der Server antwortet `missing`; die App darf
  den Eintrag dann **nicht stillschweigend verwerfen**, sondern zeigt ihn als
  verwaist an. Datenverlust ohne Hinweis ist das Schlimmste, was passieren kann.
- **Zwei Geräte gleichzeitig.** Durch Last-Write-Wins pro Zeile gewinnt die
  spätere Eingabe. Die App zeigt beim Verlieren einen Hinweis statt still zu
  überschreiben.
- **Bewertungsskala pro Kurs geändert**, während offline erfasst wurde. Die App
  speichert die Skala **mit** dem Eintrag (`scale` ist bereits eine Spalte) und
  zeigt alte Einträge in ihrer ursprünglichen Skala.
- **Schulnetz mit Portal-Anmeldung** liefert HTTP 200 mit einer HTML-Seite. Die
  App muss auf `Content-Type: application/json` prüfen, sonst „synchronisiert" sie
  gegen eine Anmeldeseite.
