# Internauten Categories Module

Dieses Repository enthaelt ein PrestaShop-Modul, das Unterkategorien innerhalb einer Kategorie alphabetisch sortiert.

## Modulordner

- `internautencategories/`

## Installation

1. Den Modulordner `internautencategories` in `modules/` deiner PrestaShop-Installation kopieren.
2. Im Backoffice unter `Module` nach `Internauten Categories` suchen und installieren.
3. In der Modulkonfiguration den Sortier-Button ausfuehren.

## Hinweise

- Die Sortierung wird ueber die Positionsfelder in der Datenbank gespeichert.
- Du kannst alle Unterkategorien oder gezielt die Unterkategorien einer bestimmten Kategorie-ID sortieren.
- Die Sortierung ist locale-aware (z. B. `de_DE`) und kann Umlaute/Akzente ueber PHP `intl`/`Collator` korrekt beruecksichtigen.
- Mehrsprachige Sortierung ist in einem Lauf moeglich: Primaersprache plus weitere aktive Sprachen als Tie-Breaker.
- Positionsupdates werden in Batches und pro Batch in einer DB-Transaktion ausgefuehrt.
- Die Batchgroesse ist im Modul konfigurierbar (Standard: 200, Bereich: 10-2000).
- Empfehlung zur Batchgroesse: `100` fuer Shared Hosting, `200-500` fuer die meisten Shops, `500+` fuer leistungsstarke Server.
- Das Modul zeigt in der Konfiguration zusaetzlich eine automatische Batch-Empfehlung auf Basis der erkannten Unterkategorie-Anzahl.
- Die automatische Empfehlung zeigt zusaetzlich eine farbliche Last-Stufe (`LOW`, `MEDIUM`, `HIGH`, `VERY HIGH`) zur schnelleren Einschaetzung.
- Neben der Last-Stufe wird ein Info-Tooltip mit den genauen Schwellenwerten angezeigt (`<=200`, `<=1000`, `<=3000`, `>3000`).

## GitHub Release Action

Im Repository ist eine GitHub Action vorhanden, die bei einem Tag-Push automatisch einen Release inklusive Installations-ZIP erstellt.

- Workflow-Datei: `.github/workflows/release-from-tag.yml`
- Trigger: Push auf ein Tag (z. B. `v1.0.0`)
- Artefakt: `internautencategories-<tag>.zip`

Die Release-Beschreibung wird aus zwei Quellen zusammengesetzt:

1. Inhalt aus `releasecomment.md`
2. Commit-Messages seit dem vorherigen Tag

### Verwendung

1. `releasecomment.md` aktualisieren.
2. Tag erstellen.
3. Tag pushen.

Beispiel:

```bash
git tag v1.0.0
git push origin v1.0.0
```

### Create and push tag from module version

You can create and push a release tag directly from the version in `internautenb2binfo/internautenb2binfo.php` (`$this->version`) with:

```bash
./scripts/tag-from-module-version.sh
```

The script reads the module version, creates an annotated tag in the format `v<version>`, and pushes it to `origin`.

Use a dry-run to preview the tag command without creating or pushing anything:

```bash
./scripts/tag-from-module-version.sh --dry-run
```

## License

This project is licensed under the MIT License. See details [`LICENSE`](LICENSE).

Copyright (c) 2026 die.internauten.ch GmbH