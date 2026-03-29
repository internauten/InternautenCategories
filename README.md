# Internauten Categories Module for Prestashop

[![Sponsor](https://img.shields.io/badge/Sponsor-GitHub%20Sponsors-ea4aaa?logo=githubsponsors)](https://github.com/sponsors/internauten)
[![Stars](https://img.shields.io/github/stars/internauten/InternautenCategories?style=social)](https://github.com/internauten/InternautenCategories/stargazers)
[![Release](https://img.shields.io/github/v/release/internauten/InternautenCategories)](https://github.com/internauten/InternautenCategories/releases)
[![License](https://img.shields.io/github/license/internauten/InternautenCategories)](https://github.com/internauten/InternautenCategories/blob/main/LICENSE)

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

### Sprachdateien (de/en)

- Das Modul enthaelt Sprachdateien unter `internautencategories/translations/de.php` und `internautencategories/translations/en.php`.
- Bei Aenderungen an den Sprachdateien im Backoffice unter `International > Uebersetzungen` die Modul-Uebersetzungen neu laden/synchronisieren.
- Danach den PrestaShop-Cache leeren, damit die geaenderten Texte im Moduldialog sichtbar werden.

#### Troubleshooting: Uebersetzungen werden nicht angezeigt

- Pruefen, ob im Backoffice die richtige Sprache aktiv ist (z. B. Deutsch vs. Englisch).
- Modul-Uebersetzungen erneut synchronisieren und danach Seite hart neu laden (Browser-Reload ohne Cache).
- Sicherstellen, dass das Modul korrekt `internautencategories` heisst und die Dateien unter `internautencategories/translations/` liegen.
- PrestaShop-Cache erneut leeren (`var/cache/*`) und ggf. kurz ab- und wieder anmelden.

##### Optional: Cache im Docker-Container leeren

Wenn PrestaShop in Docker laeuft, kannst du den Cache direkt im laufenden Container leeren:

```bash
docker compose exec prestashop sh -lc 'rm -rf /var/www/html/var/cache/*'
```

Falls der Service-Name in deiner `docker-compose.yml` anders heisst, ersetze `prestashop` entsprechend.

Oder bash auf container im VSCode öffnen.

## Entwicklung

### In einem lokalen Docker Container

#### Voraussetzungen Windows

- WSL2 (mit Umbuntu) installiert und konfiguriert
- Docker Desktop installiert mit WSL2 aktiv und Ubuntu als WSL Integration
- VS Code installiert

#### Docker Compose

- Ubuntu (WSL) starten
- Git Repo holen (oder ein fork davon) und VS Code starten
  ```bash
  git clone https://github.com/internauten/InternautenCategories.git
  cd InternautenCategories
  code .
  ```
- [docker compose File](docker-compose.yml) anpassen (xyz mit ihren Werten ersetzen)
- Container erstellen
  ```bash
  docker compose up
  ```
- Das Modul sollte sogleich im Prestashop Admin verfügbar sein.

### oder Azur VM (Kopie einer produktiven Umgebung)

Das bereitstellen der Kopie einer VM ist im [README des Repositoris InternautenB2BOffer](https://github.com/internauten/InternautenB2BOffer?tab=readme-ov-file#development) beschrieben.

### Get Module and install it

1. git clone yor fork of this repo
   ```bash
   cd ~
   git clone https://github.com/yourgithub/InternautenCategories.git
   ```
2. set owner, goup and rights
   ```bash
   sudo chown -R www-data:www-data ~/InternautenCategories/internautencategories
   ```
3. Create symlink and set group:owner
   ```bash
   sudo ln -s ~/InternautenCategories/internautencategories /var/www/prestashop/modules/internautencategories
   sudo chown -h www-data:www-data ~/InternautenCategories/internautencategories
   sudo chown -h www-data:www-data /var/www/prestashop/modules/internautencategories
   ```
4. Activate and configure Module in Prestashop  
   In Prestashop backend go to Module Manager / not installed Modules and install the module.

### Nach Änderungen immer cache leeren

```bash
cd /var/www/html/
sudo rm -rf var/cache/*
```

## Anwendung Create Subcategoiries

Erstellt Subkategorien zu Kategorien.

Muster Subkategorie:InKategorie

Beispiel Input:

```text
Sorte:Startseite
Diverses:Startseite
Abfüller:Startseite
Destillerien:Startseite
Länder:Startseite
Single Malt Whisky:Sorte
Irish Whiskey:Sorte
Blended Whisky:Sorte
Whisky Liqueur:Sorte
World Whisky:Sorte
Blended Malt:Sorte
American Whiskey:Sorte
Canadian Whisky:Sorte
Grain Whisky:Sorte
Japanese Whisky:Sorte
Schweizer Whisky:Sorte
RUM:Sorte
VODKA:Sorte
COGNAC:Sorte
GIN:Sorte
Rotwein:Sorte
RYE WHISKY:Sorte
Food:Diverses
WoW Club:Diverses
Literatur:Diverses
Gutscheine:Diverses
Gläser:Diverses
```

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

## Develope

Dammit die Container bei jedem neuen Modul nicht jedesmal neu erstellt werden müssen, versuchen wir es mit symlinks.

Voraussetzungen: im compose hat es unter volumes einen Eintrag - /home/dmo/internauten:/internauten

Bash ins WSL2 und holen des Repos:

```bash
cd ~/internauten
git clone https://github.com/internauten/InternautenCategories.git
```

Bash in den Container und dann

```bash
ln -s /internauten/InternautenCategories/internautencategories /var/www/html/modules/internautencategories
```

## License

This project is licensed under the MIT License. See details [`LICENSE`](LICENSE).

Copyright (c) 2026 die.internauten.ch GmbH

