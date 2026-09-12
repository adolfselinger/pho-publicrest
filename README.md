# pho-publicrest

Digital-Signage-Anzeige für Raumbelegungen der PH Burgenland, eingebettet als iFrame in
Xibo unter `intern.ph-burgenland.at/screen/pho_publicrest3.php`. Holt Termine, Kurse und
Kursgruppen live über die öffentliche CAMPUSonline-REST-API von PH-Online.

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

## Setup

1. `pho_publicrest3.php` und den leeren `cache/`-Ordner in `/screen/` auf dem Server ablegen.
2. `config.example.php` zu `config.php` kopieren und mit den echten CAMPUSonline-Zugangsdaten
   (`clientId`, `clientSecret`, `tokenUrl`) befüllen. **`config.php` gehört nicht ins Git-Repo.**
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

Im `$raeume`-Array in `pho_publicrest3.php` einen Eintrag ergänzen:

```php
['resID' => ..., 'roomUid' => ..., 'roomKey' => '...', 'roomInfo' => '...', 'zone' => 'orange'],
```

- `resID` = `resource_uid` aus der Appointments-API (z.B. über `room_uid`-Suche an einem
  bekannten Termin des Raums herausfinden)
- `zone` muss einer der Werte aus `$zonenFarben` sein (`gruen`, `blau`, `orange`, `lila`)
