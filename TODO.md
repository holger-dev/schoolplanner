# TODO

## Phase 1: Fundament
- [x] Repository-Basis mit `.gitignore` und Projektplan anlegen
- [x] Docker-Setup fuer lokale Nextcloud-Entwicklung vorbereiten
- [x] Nextcloud-App-Skelett mit Frontend- und Backend-Struktur anlegen

## Phase 2: Datenmodell
- [ ] Tabellen fuer Kurse, Stunden und Ablauf-Elemente finalisieren
- [ ] Persistenzschicht mit Mappern und Services vervollstaendigen
- [ ] Benutzerbezogene Einstellungen fuer Publishing hinterlegen

## Phase 3: UI
- [ ] Navigation fuer Kurse mit Anlegen und Umbenennen
- [ ] Stundenliste mit Datum, Thema und Beschreibung
- [ ] Detailansicht fuer Stundenablauf mit Markdown-Feldern
- [ ] Publish-Schalter und Statusanzeige fuer Ablauf-Elemente

## Phase 4: Publishing
- [ ] HTTP-Sync auf externen Webserver absichern
- [ ] Oeffentlichen JSON-Vertrag dokumentieren
- [ ] Mock-Publish-Server fuer lokale Tests anbinden

## Phase 5: Qualitaet
- [ ] Build/Test-Befehle dokumentieren
- [ ] App in Docker verifizieren
- [ ] Offene UX- und Sicherheitsfragen festhalten

## Offene Produktfragen
- [ ] Sollen Kurse nur dem Ersteller gehoeren oder teilbar sein?
- [ ] Soll der externe Publish-Server per Token, Basic Auth oder Signatur abgesichert werden?
- [ ] Sollen unveroeffentlichte Stunden fuer Schueler komplett unsichtbar sein?

