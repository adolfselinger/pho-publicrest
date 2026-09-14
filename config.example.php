<?php
// Vorlage für config.php.
// Datei zu config.php kopieren und mit den echten CAMPUSonline-Zugangsdaten sowie der
// eigenen Standort-Konfiguration (Räume, Zonen-Farben) befüllen.
// config.php selbst wird per .gitignore NICHT ins Repo übernommen.

$clientId     = 'DEIN_CLIENT_ID';
$clientSecret = 'DEIN_CLIENT_SECRET';
$tokenUrl     = 'https://www.ph-online.ac.at/ph-bgld/co/public/sec/auth/realms/CAMPUSonline_SP/protocol/openid-connect/token';

// PHP-Zeitzonenbezeichner für alle Datums-/Uhrzeitberechnungen (u.a. den "Aktualisiert"-
// Zeitstempel und den "schon vorbei"-Filter). Optional - Default ist 'Europe/Vienna'.
// Nur anpassen, wenn der Standort in einer anderen Zeitzone liegt.
// $zeitzone = 'Europe/Vienna';

// Vorlaufzeit in Minuten für den GET-Parameter ?aktuell=1 (zeigt nur Termine, die gerade
// laufen oder innerhalb dieser Zeit starten - z.B. für einen Flur-Screen direkt vor den
// Räumen). Optional - Default ist 30.
// $aktuellSchwelleMinuten = 30;

// Akzentfarbe der Oberfläche (Uhrzeit, aktiver Seiten-Punkt, Fortschrittsbalken, Uhr).
// Optional - fällt ohne Angabe auf ein neutrales Blau zurück.
$akzentFarbe = '#63b9e9';

// Räume: resID = Ressourcen-ID der Buchung (aus der CAMPUSonline Appointments-API),
// roomUid = interne CO-Uid, roomKey = Raumcode, roomInfo = Anzeigename,
// zone = frei wählbarer Schlüssel, muss zu $zonenFarben passen.
// Über den GET-Parameter ?bereich=<zone>[,<zone>...] lässt sich die Anzeige auf einzelne
// Zonen einschränken (z.B. für mehrere Screens an unterschiedlichen Standorten).
$raeume = [
    ['resID' => 10001, 'roomUid' => 2001, 'roomKey' => 'A.0.01', 'roomInfo' => 'Seminarraum 1', 'zone' => 'blau'],
    ['resID' => 10002, 'roomUid' => 2002, 'roomKey' => 'A.0.02', 'roomInfo' => 'Seminarraum 2', 'zone' => 'orange'],
];

// Zonen-Keys (frei wählbar) und ihre Anzeigefarbe - muss zum "zone"-Feld in $raeume passen.
$zonenFarben = [
    'blau'   => '#63b9e9',
    'orange' => '#ef7c00',
];
