# pho-publicrest

Digital-Signage-Anzeige für Raumbelegungen, eingebettet als iFrame in Xibo (im Einsatz
bei PH Burgenland unter `intern.ph-burgenland.at/screen/pho_publicrest3.php`). Holt
Termine, Kurse und Kursgruppen live über die öffentliche CAMPUSonline-REST-API von
PH-Online.

`pho_publicrest3.php` selbst enthält keine standortspezifischen Daten mehr - Zugangsdaten,
Raumliste, Zonen-Farben und Akzentfarbe kommen komplett aus `config.php` (siehe
`config.example.php`). Andere Standorte/Institutionen mit eigenem CAMPUSonline-Zugang
können die Datei also unverändert übernehmen und nur ihre eigene `config.php` anlegen.

## Features

- Automatische Paginierung mit 12s-Umblätterung, dunkles Signage-Design
- Farbcodierte Räume nach Gebäude-/Stockwerk-Zone (siehe Leitsystem der PH)
- Zeigt sowohl Lehrveranstaltungstermine als auch Direktbuchungen ohne Kursbezug
- Noch nicht genehmigte LVs (Status ≠ `BF`) werden bewusst nicht angezeigt
- Datei-Cache (2 Min. TTL) mit Fallback auf den letzten bekannten Stand, falls
  CAMPUSonline nicht erreichbar ist (z.B. während des Dienstags-Wartungsfensters)
- `?date=YYYY-MM-DD` – anderen Tag testen (nur zu Testzwecken)
- `?bereich=orange,lila,...` – nur bestimmte Gebäude-/Stockwerk-Zonen anzeigen (für
  unterschiedliche Screens an unterschiedlichen Standorten)
- `?aktuell=1` – nur Termine anzeigen, die gerade laufen oder in den nächsten
  `aktuellSchwelleMinuten` (Default 30, in `config.php` einstellbar) starten - z.B. für
  einen Flur-Screen direkt vor den Räumen

## Setup

1. `pho_publicrest3.php` und den leeren `cache/`-Ordner in `/screen/` auf dem Server ablegen.
2. `config.example.php` zu `config.php` kopieren und befüllen:
   - `clientId`, `clientSecret`, `tokenUrl` - CAMPUSonline-Zugangsdaten
   - `akzentFarbe` - optionale UI-Akzentfarbe (Uhr, aktive Seite, Fortschrittsbalken)
   - `raeume` - die eigene Raumliste (siehe Abschnitt "Neuen Raum hinzufügen")
   - `zonenFarben` - Anzeigefarbe je Zonen-Schlüssel, frei wählbar
   - `aktuellSchwelleMinuten` - optionale Vorlaufzeit in Minuten für `?aktuell=1` (Default 30)
   - `logoUrl` - optionales Logo oben mittig im Header (Default PH-Burgenland-Logo, leerer
     String blendet es aus)

   **`config.php` gehört nicht ins Git-Repo** (steht in `.gitignore`).
3. `cache/` muss für den PHP-Prozess (i.d.R. `www-data`) beschreibbar sein:
   ```bash
   chown -R www-data:www-data cache
   chmod 750 cache
   ```
4. Auf nginx den Cache-Ordner zusätzlich per Server-Config sperren (Datei-Rechte allein
   reichen nicht, falls der Ordner direkt über HTTP erreichbar wäre):
   ```nginx
   location ^~ /screen/cache/ {
       deny all;
       return 404;
   }
   ```

## Neuen Raum hinzufügen

Im `$raeume`-Array in `config.php` einen Eintrag ergänzen:

```php
['resID' => ..., 'roomUid' => ..., 'roomKey' => '...', 'roomInfo' => '...', 'zone' => 'orange'],
```

- `resID` = `resource_uid` aus der Appointments-API (z.B. über `room_uid`-Suche an einem
  bekannten Termin des Raums herausfinden)
- `zone` muss einer der Schlüssel aus `$zonenFarben` in `config.php` sein
