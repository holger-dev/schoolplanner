# Planung importieren – Markdown & JSON

School Planner kann eine komplette Kursplanung aus Dateien einlesen. Das ist
ideal, um Stunden mit einer KI (egal welcher) vorzubereiten: Du lässt dir
Markdown- oder JSON-Dateien erzeugen und liest sie im Kurs ein.

Geöffnet wird das Ganze im Kurs über **Planung importieren**.

## Zwei Wege

1. **Markdown aus einem Nextcloud-Ordner** – eine `.md`-Datei pro Stunde. Du
   legst die Dateien in einen Ordner deiner Nextcloud und wählst im Dialog
   „Ordner / Datei wählen".
2. **JSON einfügen** – eine einzelne JSON-Datei mit dem kompletten Kurs (siehe
   ganz unten). Praktisch für den vollständigen Export/Import.

Beide Wege nutzen dieselbe Logik: Stunden werden über **Datum + Slot** mit dem
gewählten Kurs zusammengeführt. Gibt es bereits eine Stunde mit gleichem Datum
und Slot, wird sie **überschrieben**; sonst wird sie **neu angelegt**. Vor dem
Import siehst du immer eine Vorschau (neu / überschreiben). Der Kurs selbst muss
bereits existieren.

## Markdown-Format

Jede Stunde beginnt mit ein paar Kopfzeilen. Pflicht sind nur `date` und `slot`:

```markdown
date: 2026-09-01
slot: 1
title: Einführung Python
goal: SuS verstehen Variablen

Heute steigen wir in Python ein. (optionaler Beschreibungstext)

## Warm-up
Inhalt des ersten Elements …

## Übung
Inhalt des zweiten Elements …
```

| Feld    | Pflicht | Bedeutung                                  |
|---------|---------|--------------------------------------------|
| `date`  | ja      | Datum der Stunde, Format `JJJJ-MM-TT`      |
| `slot`  | ja      | Stundenplan-Slot (1–8)                     |
| `title` | nein    | Titel der Stunde (sonst Dateiname)         |
| `goal`  | nein    | Ziel der Stunde                            |

Regeln für den Text darunter:

- Der Text **vor der ersten `##`-Überschrift** ist die (optionale) Beschreibung
  der Stunde. Lässt du ihn weg, bleibt die Beschreibung leer.
- Jede **`## Überschrift`** wird zu einem Ablauf-Element. Die Überschrift ist der
  Titel des Elements, der Text darunter (Markdown) sein Inhalt.

### Interne Hinweise für Lehrer:innen

Zeilen, die mit `Hinweis:` (oder `Lehrer:`, `Lehrerhinweis:`, `Note:`) beginnen,
landen **nicht** im Schüler-Text, sondern im internen Feld „Hinweise für
Lehrer:in" des jeweiligen Elements. Ein vorangestelltes `>` oder `-` ist erlaubt.

```markdown
## Warm-up
Was ist eine Variable? Beispiele sammeln.
Hinweis: Nur 5 Minuten, sonst wird es zu lang.
```

Steht so eine Zeile **vor** dem ersten `##`, landet sie im internen Feld „Fazit
der Stunde". Alternativ lässt sich das Fazit auch als Kopfzeile setzen:
`reflection:` bzw. `fazit:`.

**Mehrere Stunden pro Datei** sind erlaubt: Schreib einfach die nächste Stunde
darunter – jede neue Stunde beginnt wieder mit einer `date:`-Zeile.

Die früher nötigen `---`-Zeilen sind **nicht mehr erforderlich** (sie werden aber
weiterhin toleriert, falls dein Editor sie einfügt). Das vermeidet Probleme mit
der Nextcloud-Text-App, die `---` als Titel-Block interpretiert.

Fehlt `date` oder `slot`, wird die betroffene Stunde beim Prüfen mit Dateiname
als Fehler angezeigt und der Import so lange blockiert, bis es passt.

## Links, Bilder und Dateien

Die Beschreibungen von Stunde und Elementen sind **Markdown** und erscheinen so
auch auf der veröffentlichten Schüler-Seite.

### Links

```markdown
[Python-Doku](https://docs.python.org/3/)
```

Links auf der veröffentlichten Seite öffnen automatisch in einem neuen Tab.

### Bilder

Bilder bindest du per URL ein:

```markdown
![Alt-Text](https://example.org/bild.png)
```

Wichtig: Die Bild-URL muss öffentlich erreichbar sein. Für ein Bild aus deiner
Nextcloud erstellst du einen **öffentlichen Freigabe-Link** und hängst
`/preview` bzw. `/download` an – am einfachsten ein Bild nutzen, das ohnehin im
Web liegt.

### Dateien (Downloads)

Auf Dateien verlinkst du wie auf normale Links – z. B. auf eine Nextcloud-Freigabe:

```markdown
[Arbeitsblatt (PDF)](https://cloud.example.org/s/abVariablen)
```

Hinweis zu **echten Datei-Anhängen**: Absolute URLs bleiben einfache Links. Wenn
du dagegen **relativ** auf eine Datei im selben Ordner wie die `.md` verweist
(`![Schaubild](schaubild.png)`, `[Arbeitsblatt](ab.pdf)`), wird sie beim Import
als **echter Anhang** am Element angelegt – siehe „Bilder und Dateien aus dem
Kurs-Ordner". Beim JSON-Import (ohne Ordner) werden nur Text, Links und
Bild-URLs übertragen; Anhänge fügst du dort nach dem Import am Element hinzu.

### Wichtige Links pro Kurs

Unabhängig von einzelnen Stunden kannst du zentrale Kurs-Links über
**Wichtige Links** pflegen (im JSON-Export stehen sie unter `course.links`).

## Kurs-Ordner mit MD-Datei verknüpfen (empfohlener Workflow)

Lege pro Kurs einen Ordner in der Nextcloud an mit **einer** `.md`-Datei und
allen zugehörigen Dateien (Bilder, PDFs …). Dann:

1. **Kurseinstellungen** öffnen → „Angebundene Markdown-Datei" → Datei wählen.
2. Datei beliebig bearbeiten (gern mit einer KI) und Dateien in denselben Ordner legen.
3. Links **„MD-Files aktualisieren"** klicken → Vorschau über **alle** angebundenen
   Kurse → prüfen → **Übernehmen**.

Dabei gilt:

- Stunden werden über **Datum + Slot** zusammengeführt: gleiche Kombination wird
  aktualisiert, neue wird angelegt.
- **Der Veröffentlicht-Status bleibt erhalten.** Elemente, die du schon
  freigegeben hast, werden beim erneuten Import nicht zurückgesetzt (Abgleich
  über den Element-Titel).
- Stunden, die in der App existieren, aber nicht mehr in der MD-Datei stehen,
  werden **nicht automatisch gelöscht** – sie erscheinen in der Vorschau zum
  Anhaken.

### Bilder und Dateien aus dem Kurs-Ordner

Verlinkst du relativ auf eine Datei im selben Ordner, wird sie beim Import als
**echter Anhang** übernommen und beim Veröffentlichen mit hochgeladen:

```markdown
![Schaubild](schaubild.png)
[Arbeitsblatt](ab-variablen.pdf)
```

Relative Links werden beim Veröffentlichen automatisch auf den richtigen
Asset-Pfad umgeschrieben – Bilder erscheinen also direkt auf der Schüler-Seite.
Absolute URLs (`https://…`) bleiben unverändert.

### Tabellen

Markdown-Tabellen werden unterstützt und auf der veröffentlichten Seite sauber
formatiert:

```markdown
| Zeit | Phase   | Material |
|------|---------|----------|
| 5'   | Einstieg| Beamer   |
| 20'  | Übung   | AB 1     |
```

## Mit einer KI arbeiten

Du kannst jeder KI dieses Format beschreiben. Eine Vorlage zum Kopieren:

> Erstelle mir Unterrichtsplanungen als Markdown. Jede Stunde beginnt mit den
> Zeilen `date: JJJJ-MM-TT` und `slot:` (1–8), optional `title` und `goal`. Danach
> optional eine kurze Beschreibung, dann je Ablaufschritt eine `## Überschrift` mit
> Inhalt. Mehrere Stunden einfach untereinander (jede beginnt wieder mit `date:`).
> Links als `[Text](URL)`, Bilder als `![Alt](URL)`. Dateien im selben Ordner
> relativ verlinken (`![Alt](bild.png)`), sie werden als Anhang übernommen.
> Interne Notizen für mich als Lehrkraft als eigene Zeile mit `Hinweis: …` –
> die erscheinen nicht im Schüler-Text. Markdown-Tabellen sind erlaubt.
> Thema: «… dein Thema …», «… Anzahl Stunden, Klassenstufe, Rahmen …».

Speichere die erzeugten Dateien in einem Nextcloud-Ordner und lies sie im Kurs
über **Planung importieren → Ordner / Datei wählen** ein.

Ein vollständiges Beispiel liegt unter [`examples/`](../examples/).

## JSON-Format (Referenz)

```json
{
  "schoolplanner": "course-plan",
  "version": 1,
  "course": {
    "name": "Mathe 9a",
    "description": "…",
    "links": [{ "label": "Lehrbuch", "url": "https://…" }],
    "students": [{ "name": "Max Muster", "note": "sitzt vorne" }],
    "lessons": [
      {
        "date": "2026-09-01",
        "slot": 1,
        "title": "Einführung",
        "goal": "…",
        "description": "Markdown … mit [Link](https://…) und ![Bild](https://…)",
        "items": [
          {
            "title": "Warm-up",
            "description": "Markdown …",
            "teacherNote": "Nur intern sichtbar",
            "published": false
          }
        ]
      }
    ]
  }
}
```
