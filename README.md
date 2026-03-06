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
