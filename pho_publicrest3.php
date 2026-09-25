<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Zugangsdaten + Standort-Konfiguration (Räume, Zonen-Farben) liegen in config.php
// (nicht im Git-Repo, siehe config.example.php als Vorlage) - das macht diese Datei
// selbst generisch wiederverwendbar für andere Standorte/Screens.
// Erwartet aus config.php: $clientId, $clientSecret, $tokenUrl, $raeume, $zonenFarben.
//
// $raeume: Liste von ['resID' => ..., 'roomUid' => ..., 'roomKey' => ..., 'roomInfo' => ...,
//          'zone' => ...] - resID = Ressourcen-ID der Buchung, roomUid = interne CO-Uid,
//          roomKey = Raumcode, roomInfo = Anzeigename, zone = Schlüssel aus $zonenFarben.
//          Über den GET-Parameter ?bereich=... lässt sich die Anzeige auf einzelne Zonen
//          einschränken.
// $zonenFarben: ['zonen-key' => '#hexfarbe', ...] - Anzeigefarbe je Zone.
// Optional aus config.php: $zeitzone (PHP-Zeitzonenbezeichner, Default 'Europe/Vienna').
// Optional aus config.php: $aktuellSchwelleMinuten (Vorlaufzeit für ?aktuell=1, Default 30).
// Optional aus config.php: $logoUrl (Logo oben mittig, Default PH-Burgenland-Logo; leerer
// String blendet das Logo aus).
require __DIR__ . '/config.php';

// Vorlaufzeit (in Minuten) für den ?aktuell=1-Filter - über config.php steuerbar, damit
// sie nicht pro Screen im Xibo-iFrame-Link, sondern zentral am Standort gepflegt wird.
$aktuellSchwelleMinuten = $aktuellSchwelleMinuten ?? 30;

// Logo oben mittig im Header - über config.php austauschbar, damit andere
// Standorte/Institutionen die Datei unverändert mit ihrem eigenen Logo nutzen können.
$logoUrl = $logoUrl ?? 'https://www.ph-burgenland.at/fileadmin/template/logo_2023.svg';

// Explizit setzen statt auf die PHP-Default-Zeitzone des Servers zu vertrauen - sonst
// kann z.B. der "Aktualisiert"-Zeitstempel um eine Stunde (Winterzeit) oder zwei Stunden
// (Sommerzeit) daneben liegen, wenn der Server z.B. mit UTC statt Europe/Vienna läuft.
date_default_timezone_set($zeitzone ?? 'Europe/Vienna');

/**
 * Holt einen OAuth2-Token per Client-Credentials-Flow.
 * Gibt bei jedem Fehler (Netzwerk, HTTP-Status, kaputtes JSON, fehlendes Feld) null zurück,
 * statt eine Warning zu werfen oder mit einem leeren Bearer-Header weiterzumachen.
 */
function getToken(string $clientId, string $clientSecret, string $tokenUrl): ?string
{
    $ch = curl_init($tokenUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlErr !== '' || $httpCode >= 400) {
        return null;
    }

    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}

/**
 * Ruft eine JSON-API mit Bearer-Token ab.
 * Liefert im Fehlerfall immer ein leeres 'items'-Array statt das Programm abzubrechen -
 * ein einzelner API-Ausfall (z.B. ein Raum mit abweichendem Antwortschema) soll nie die
 * ganze Anzeige zerstören, sondern nur den betroffenen Teil leer lassen.
 */
function apiGet(string $url, ?string $token): array
{
    if ($token === null) {
        return ['ok' => false, 'items' => [], 'raw' => null];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'accept: application/json',
        'Authorization: Bearer ' . $token,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlErr !== '' || $httpCode >= 400) {
        return ['ok' => false, 'items' => [], 'raw' => null];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'items' => [], 'raw' => null];
    }

    // Manche Endpunkte liefern direkt eine Liste, andere ein {"items": [...]}-Objekt
    // (das ist vermutlich die Quelle der "anderen Schemas" bei Sonderbuchungen).
    $items = $decoded['items'] ?? (array_is_list($decoded) ? $decoded : []);

    return ['ok' => true, 'items' => is_array($items) ? $items : [], 'raw' => $decoded];
}

/** Versucht ein Datum zu parsen; liefert null statt eine Exception zu werfen. */
function safeDate(?string $value): ?DateTime
{
    if (!$value) {
        return null;
    }
    try {
        return new DateTime($value);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Holt Termine (über alle Räume) + Kursgruppen + Kurse für einen Tag und cacht das Ergebnis
 * für $cacheTtl Sekunden in einer Datei. So gehen mehrere Xibo-Screens (die sich nur im
 * ?bereich=-Filter unterscheiden) sich einen gemeinsamen Cache-Eintrag pro Tag statt jeder
 * für sich live gegen CAMPUSonline zu laufen.
 *
 * Schlägt der Live-Abruf fehl (Token/Netzwerk), wird - falls vorhanden - auf einen auch
 * schon abgelaufenen Cache-Stand zurückgegriffen, statt eine leere Seite zu zeigen. Ein
 * fehlgeschlagener Versuch überschreibt einen funktionierenden Cache-Stand nie.
 */
function ladeRohdaten(array $raeumeAlle, string $today, string $clientId, string $clientSecret, string $tokenUrl, int $cacheTtl): array
{
    // Eigener Cache-Ordner statt System-Temp: der wird auf manchen Servern regelmäßig
    // automatisch geleert, was den Notfall-Fallback (z.B. beim Dienstags-Wartungsfenster
    // von CAMPUSonline) gerade dann zunichtemachen könnte, wenn er am nötigsten ist.
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $cacheFile = $cacheDir . '/rohdaten_' . md5($today) . '.json';

    $cached = null;
    if (is_file($cacheFile)) {
        $decoded = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($decoded) && isset($decoded['cachedAt'])) {
            $cached = $decoded;
        }
    }

    if ($cached !== null && (time() - $cached['cachedAt']) < $cacheTtl) {
        $cached['fromCache']   = true;
        $cached['fetchErrors'] = [];
        return $cached;
    }

    // --- Cache leer/abgelaufen: live abrufen ---
    $errors = [];
    $token  = getToken($clientId, $clientSecret, $tokenUrl);
    if ($token === null) {
        $errors[] = 'Anmeldung bei CAMPUSonline fehlgeschlagen.';
    }

    $appointmentsUrl = 'https://www.ph-online.ac.at/ph-bgld/co/co-res-core/appointment/api/appointments'
        . '?end_at=' . $today . '&start_at=' . $today;
    foreach ($raeumeAlle as $eintrag) {
        $appointmentsUrl .= '&resource_uid=' . $eintrag['resID'];
    }
    $appointmentsUrl .= '&status_key=CONFIRMED';

    $appointmentsResult = apiGet($appointmentsUrl, $token);
    if (!$appointmentsResult['ok'] && $token !== null) {
        $errors[] = 'Terminliste konnte nicht geladen werden.';
    }
    $rawAppointments = $appointmentsResult['items'];
    $liveOk = $token !== null && $appointmentsResult['ok'];

    $gruppenArray = [];
    $courseUids   = [];
    foreach ($rawAppointments as $termin) {
        $courseUid = $termin['courseUid'] ?? null;
        if ($courseUid === null) {
            continue;
        }
        $courseUids[$courseUid] = true;

        $gruppenUrl    = 'https://www.ph-online.ac.at/ph-bgld/co/co-tm-core/course/api/course-groups/?course_uid=' . $courseUid;
        $gruppenResult = apiGet($gruppenUrl, $token);
        foreach ($gruppenResult['items'] as $gruppenWert) {
            $uid  = $gruppenWert['uid'] ?? null;
            $name = $gruppenWert['name']['value']['de'] ?? null;
            if ($uid !== null && $name !== null) {
                $gruppenArray[$uid] = $name;
            }
        }
    }

    $coursesByUid = [];
    if (!empty($courseUids)) {
        $courseUrl = 'https://www.ph-online.ac.at/ph-bgld/co/co-tm-core/course/api/courses?';
        foreach (array_keys($courseUids) as $uid) {
            $courseUrl .= '&course_uids=' . $uid;
        }
        $coursesResult = apiGet($courseUrl, $token);
        if (!$coursesResult['ok']) {
            $errors[] = 'Kursdaten konnten nicht geladen werden.';
            $liveOk   = false;
        }
        foreach ($coursesResult['items'] as $course) {
            if (isset($course['uid'])) {
                $coursesByUid[$course['uid']] = $course;
            }
        }
    }

    $result = [
        'cachedAt'        => time(),
        'rawAppointments' => $rawAppointments,
        'gruppenArray'    => $gruppenArray,
        'coursesByUid'    => $coursesByUid,
        'fetchErrors'     => $errors,
        'fromCache'       => false,
    ];

    if ($liveOk) {
        // Cache nur bei erfolgreichem Abruf aktualisieren - ein Fehlversuch soll einen
        // funktionierenden alten Stand nicht überschreiben.
        @file_put_contents($cacheFile, json_encode($result));
        return $result;
    }

    if ($cached !== null) {
        // Live-Abruf fehlgeschlagen: lieber der alte (ggf. abgelaufene) Cache-Stand als eine leere Seite.
        $cached['fromCache']   = true;
        $cached['fetchErrors'] = array_merge($errors, ['Zeige zwischengespeicherten Stand, da aktuelle Daten nicht abrufbar waren.']);
        return $cached;
    }

    return $result;
}

$errors       = [];   // menschenlesbare Fehlermeldungen für die Fehlerleiste
$appointments = [];

// Für Tests per GET-Parameter überschreibbar, z.B. ?date=2025-06-22
// (nur zum manuellen Durchprobieren gedacht - im Xibo-iFrame bleibt der Parameter einfach weg)
$today = date('Y-m-d');
if (!empty($_GET['date'])) {
    $requested = DateTime::createFromFormat('Y-m-d', $_GET['date']);
    if ($requested && $requested->format('Y-m-d') === $_GET['date']) {
        $today = $_GET['date'];
    } else {
        $errors[] = 'Ungültiges Datum im Parameter "date", zeige heutigen Tag.';
    }
}
$isTestDate = ($today !== date('Y-m-d'));

// Für unterschiedliche Screens: über ?bereich=orange (oder kommagetrennt z.B.
// ?bereich=orange,lila) nur Räume einer/mehrerer Zonen anzeigen. Ohne Parameter werden
// wie bisher alle Räume berücksichtigt. Unbekannte Zonen-Namen werden ignoriert und als
// Hinweis in der Fehlerleiste angezeigt, statt die ganze Seite leer zu lassen.
//
// Der Bereichsfilter wirkt erst auf die ANZEIGE, nicht auf die Abfrage selbst - abgefragt
// (und gecacht) werden immer alle Räume, damit sich unterschiedliche Bereichs-Screens
// einen gemeinsamen Cache-Eintrag pro Tag teilen können, statt jeder für sich extra gegen
// CAMPUSonline zu laufen.
$aktiveZonen = [];
if (!empty($_GET['bereich'])) {
    $angefragt = array_filter(array_map('trim', explode(',', strtolower($_GET['bereich']))));
    foreach ($angefragt as $zone) {
        if (isset($zonenFarben[$zone])) {
            $aktiveZonen[] = $zone;
        } else {
            $errors[] = 'Unbekannter Bereich "' . htmlspecialchars($zone, ENT_QUOTES, 'UTF-8') . '" wird ignoriert.';
        }
    }
}
$raeumeSichtbar = empty($aktiveZonen)
    ? $raeume
    : array_values(array_filter($raeume, fn($r) => in_array($r['zone'], $aktiveZonen, true)));
$sichtbareResIds = array_map('strval', array_column($raeumeSichtbar, 'resID'));

// Über ?aktuell=1 nur Termine anzeigen, die gerade laufen oder in den nächsten
// $aktuellSchwelleMinuten Minuten starten (z.B. für einen Flur-Screen direkt vor den
// Räumen, auf dem eine ganze Tagesliste zu viel wäre). Ohne den Parameter unverändert
// wie bisher alle noch nicht vorbeigegangenen Termine des Tages.
$aktuellModus = (($_GET['aktuell'] ?? '') === '1');

// --- Termine, Kursgruppen und Kurse holen (gecacht, siehe ladeRohdaten()) --------------
$cacheTtlSekunden = 120;
$rohdaten = ladeRohdaten($raeume, $today, $clientId, $clientSecret, $tokenUrl, $cacheTtlSekunden);
$errors        = array_merge($errors, $rohdaten['fetchErrors']);
$gruppenArray  = $rohdaten['gruppenArray'];
$coursesByUid  = $rohdaten['coursesByUid'];
$zuletztAktualisiert = $rohdaten['cachedAt'];

// Alle bestätigten Termine der sichtbaren Räume, die noch nicht vorbei sind - nicht nur
// Lehrveranstaltungen (applicationTypeKey=LEH), sondern auch Direktbuchungen ohne
// Kursbezug (NONE) und andere Typen (PV, VA, WBKV, MOD_MNG, ORG, EV, ICE laut offizieller
// API-Doku). Termine mit kaputtem/fehlendem endAt werden NICHT verworfen (wir können ihre
// Gültigkeit nicht prüfen) - lieber einmal zu viel anzeigen als eine echte Buchung verschlucken.
//
// Bei einem Test-Datum (?date=...) wird "jetzt" auf diesen Tag + die aktuelle Uhrzeit
// gelegt (statt das echte Tagesdatum zu verwenden) - sonst würden an einem Test-Tag in
// der Zukunft nie Termine gefiltert (alles gilt als "noch nicht vorbei") und an einem
// Test-Tag in der Vergangenheit immer alle gefiltert.
$jetzt = $isTestDate
    ? DateTime::createFromFormat('Y-m-d H:i:s', $today . ' ' . date('H:i:s'))
    : new DateTime();
// Obergrenze für den ?aktuell=1-Filter: alles was bis dahin startet gilt als "läuft schon
// oder startet bald genug". Einmal außerhalb der Schleife berechnet statt pro Termin neu.
$aktuellGrenze = $aktuellModus ? (clone $jetzt)->modify('+' . $aktuellSchwelleMinuten . ' minutes') : null;

foreach ($rohdaten['rawAppointments'] as $eintrag) {
    if (!in_array((string) ($eintrag['resourceUId'] ?? ''), $sichtbareResIds, true)) {
        continue;
    }
    $ende = safeDate($eintrag['endAt'] ?? null);
    if ($ende !== null && $ende <= $jetzt) {
        continue;
    }
    if ($aktuellGrenze !== null) {
        // Termine mit kaputtem/fehlendem startAt werden NICHT verworfen (siehe endAt oben) -
        // lieber einmal zu viel anzeigen als eine echte Buchung verschlucken.
        $start = safeDate($eintrag['startAt'] ?? null);
        if ($start !== null && $start > $aktuellGrenze) {
            continue;
        }
    }
    $appointments[] = $eintrag;
}

// --- Termine mit Titel/Gruppe/Uhrzeiten anreichern - mit Fallback statt Absturz ----------
foreach ($appointments as $index => $termin) {
    $courseUid = $termin['courseUid'] ?? null;

    if ($courseUid !== null) {
        // Lehrveranstaltungstermin (oder anderer kursgebundener Typ) - Titel/Kurscode
        // kommen über die Kurs-API. Die öffentliche API liefert einen Kurs erst ab
        // Genehmigungsstatus "BF" (genehmigt) zurück - noch nicht genehmigte LVs kommen
        // hier also gar nicht erst an. Solche Termine werden bewusst nicht angezeigt,
        // statt mit einem Fallback-Text aufzutauchen.
        $course = $coursesByUid[$courseUid] ?? null;
        if ($course === null) {
            unset($appointments[$index]);
            continue;
        }
        $appointments[$index]['courseCode'] = $course['courseCode'] ?? '';
        $appointments[$index]['title']      = $course['title']['value']['de'] ?? 'Unbekannte Veranstaltung';

        $groupUid = $termin['courseGroupUid'] ?? null;
        $appointments[$index]['groupName'] = $gruppenArray[$groupUid] ?? '';
    } else {
        // Direktbuchung ohne Kursbezug (z.B. "Welcome-Days 2026") - der Titel steht laut
        // API-Schema direkt am Termin selbst, ein Kurs-Lookup ist hier weder nötig noch möglich.
        $appointments[$index]['courseCode'] = '';
        $appointments[$index]['title']      = $termin['title'] ?? 'Unbekannte Veranstaltung';
        $appointments[$index]['groupName']  = '';
    }

    $von = safeDate($termin['startAt'] ?? null);
    $bis = safeDate($termin['endAt'] ?? null);
    $appointments[$index]['von'] = $von ? $von->format('H:i') : '--:--';
    $appointments[$index]['bis'] = $bis ? $bis->format('H:i') : '--:--';
    $appointments[$index]['_sort'] = $von ? $von->getTimestamp() : PHP_INT_MAX;
}

usort($appointments, fn($a, $b) => $a['_sort'] <=> $b['_sort']);
foreach ($appointments as $index => $termin) {
    unset($appointments[$index]['_sort']);
}
$appointments = array_values($appointments);

$todayFormatted = (new DateTime($today))->format('d.m.Y');

?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Raumbelegung</title>
<style>
@font-face {
    font-family: "Raleway";
    src: url(https://intern.ph-burgenland.at/screen/Raleway-VariableFont_wght.ttf) format("truetype");
    font-display: swap;
}

:root {
    --bg: #12151c;
    --bg-alt: #1b1f29;
    --border: #2a2f3b;
    --text: #f2f3f5;
    --muted: #9aa2b1;
    --accent: <?php echo htmlspecialchars($akzentFarbe ?? '#63b9e9', ENT_QUOTES, 'UTF-8'); ?>;
    --room-default: #4b5563;
}

* { box-sizing: border-box; }

html, body {
    margin: 0;
    padding: 0;
    height: 100%;
    overflow: hidden;
    background: var(--bg);
    color: var(--text);
    font-family: "Raleway", "Segoe UI", Arial, sans-serif;
}

body {
    display: flex;
    flex-direction: column;
    padding: clamp(12px, 2vw, 28px);
}

.header {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: flex-start;
    gap: clamp(10px, 1.5vw, 20px);
    margin-bottom: clamp(10px, 1.5vw, 20px);
}

.header h1 {
    margin: 0;
    font-size: clamp(1.4rem, 2.6vw, 2.2rem);
    font-weight: 700;
    letter-spacing: 0.02em;
}

.header .logo {
    grid-column: 2;
    justify-self: center;
    height: clamp(24px, 3.4vw, 40px);
    width: auto;
    /* Seitenverhältnis des PH-Burgenland-Logos (283.465x49.544) fix vorgeben, damit der
       Browser die Spaltenbreite schon aus HTML/CSS kennt, statt bis zum Laden des Bilds
       mit Breite 0 zu rechnen. Sonst verschiebt sich die Spaltenaufteilung im Header erst
       nachträglich (sobald das - ggf. externe - Bild fertig geladen ist) und der Titel
       kann dadurch spät auf eine zweite Zeile umbrechen; genau das würde buildPages() in
       pho_publicrest3.php aus dem Tritt bringen, weil es die Höhe schon vorher gemessen hat. */
    aspect-ratio: 283.465 / 49.544;
}

.header .clock-wrap {
    grid-column: 3;
}

.header .clock {
    font-size: clamp(1rem, 1.8vw, 1.5rem);
    color: var(--muted);
    font-variant-numeric: tabular-nums;
    text-align: right;
}

.header .updated {
    font-size: clamp(0.65rem, 1vw, 0.85rem);
    color: var(--muted);
    opacity: 0.7;
    text-align: right;
    margin-top: 0.2em;
}

.error-banner {
    background: #3a2222;
    border: 1px solid #7a3b3b;
    color: #ffd9d9;
    border-radius: 8px;
    padding: 10px 16px;
    margin-bottom: 14px;
    font-size: clamp(0.85rem, 1.2vw, 1.05rem);
}

.table-wrap {
    flex: 1;
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--bg-alt);
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: clamp(1rem, 1.7vw, 1.5rem);
}

th, td {
    text-align: left;
    padding: clamp(8px, 1.1vw, 16px) clamp(10px, 1.4vw, 20px);
    border-bottom: 1px solid var(--border);
}

th {
    color: var(--muted);
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.7em;
    letter-spacing: 0.08em;
    background: rgba(255, 255, 255, 0.02);
}

tbody tr:last-child td {
    border-bottom: none;
}

td.zeit {
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
    color: var(--accent);
    font-weight: 700;
}

td.lv .group {
    color: var(--muted);
    font-weight: 400;
}

.room-badge {
    display: inline-block;
    padding: 0.25em 0.75em;
    border-radius: 999px;
    font-weight: 700;
    font-size: 0.9em;
    white-space: nowrap;
    color: #fff;
    background: var(--room-default);
}

.empty-state {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: var(--muted);
    font-size: clamp(1.1rem, 2vw, 1.8rem);
    text-align: center;
    padding: 20px;
}

.footer {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-top: clamp(10px, 1.5vw, 18px);
    /* Immer reservieren, auch ohne Punkte (nur 1 Seite) - sonst wüchse der Footer erst
       nachträglich, sobald setupPagination() die Punkte einfügt, und der Tabellenbereich
       (.table-wrap, flex:1) würde entsprechend nachträglich schrumpfen. buildPages() misst
       den Tabellenbereich aber VOR setupPagination() (die Seitenanzahl steht ja erst nach
       buildPages() fest) - mit variabler Footer-Höhe wäre das ein Zirkelschluss. */
    min-height: 10px;
}

.dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--border);
    cursor: pointer;
    transition: background 0.2s ease;
}

.dot.active {
    background: var(--accent);
}

.progress {
    position: absolute;
    left: 0;
    bottom: 0;
    height: 3px;
    background: var(--accent);
    width: 0%;
}

.zone-badge {
    display: inline-block;
    margin-left: 0.6em;
    padding: 0.15em 0.6em;
    border-radius: 999px;
    background: #1e2a3a;
    color: #a9d6f5;
    font-size: 0.5em;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    vertical-align: middle;
}

.test-badge {
    display: inline-block;
    margin-left: 0.6em;
    padding: 0.15em 0.6em;
    border-radius: 999px;
    background: #4a3a12;
    color: #ffd98a;
    font-size: 0.5em;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    vertical-align: middle;
}

.table-wrap {
    position: relative;
}
</style>
</head>
<body>

<div class="header">
    <h1>
        <?php if ($isTestDate): ?>
            Raumbelegung am <?php echo htmlspecialchars($todayFormatted, ENT_QUOTES, 'UTF-8'); ?>
            <span class="test-badge">Testansicht</span>
        <?php else: ?>
            Raumbelegung heute
        <?php endif; ?>
        <?php if (!empty($aktiveZonen)): ?>
            <span class="zone-badge">Bereich: <?php echo htmlspecialchars(implode(', ', $aktiveZonen), ENT_QUOTES, 'UTF-8'); ?></span>
        <?php else: ?>
            <span class="zone-badge">Alle Bereiche</span>
        <?php endif; ?>
        <?php if ($aktuellModus): ?>
            <span class="zone-badge">Nur aktuell &amp; in <?php echo (int) $aktuellSchwelleMinuten; ?> Min.</span>
        <?php endif; ?>
    </h1>
    <?php if (!empty($logoUrl)): ?>
        <img class="logo" src="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo">
    <?php endif; ?>
    <div class="clock-wrap">
        <div class="clock" id="clock"></div>
        <div class="updated">Aktualisiert: <?php echo htmlspecialchars((new DateTime())->setTimestamp($zuletztAktualisiert)->format('H:i:s'), ENT_QUOTES, 'UTF-8'); ?> Uhr</div>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="error-banner">
    ⚠ <?php echo htmlspecialchars(implode(' ', $errors), ENT_QUOTES, 'UTF-8'); ?>
    Es werden ggf. nicht alle Buchungen angezeigt.
</div>
<?php endif; ?>

<div class="table-wrap">
    <table id="dataTable">
        <thead>
            <tr>
                <th style="width:16%">Zeit</th>
                <th>Lehrveranstaltung</th>
                <th style="width:18%">Raum</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
    <div class="progress" id="progress"></div>
</div>

<div class="footer" id="pagination"></div>

<script>
const data = <?php echo json_encode($appointments, JSON_UNESCAPED_UNICODE); ?>;
const raeume = <?php echo json_encode($raeumeSichtbar); ?>;
// true, wenn beim Laden etwas schiefging (z.B. CAMPUSonline down und kein Cache mehr
// verfügbar) - dann heißt eine leere Liste "wir wissen es nicht", nicht "nichts gebucht".
const datenUnsicher = <?php echo json_encode(!empty($errors)); ?>;
const aktuellModus = <?php echo json_encode($aktuellModus); ?>;
const flipMs = 12000;
let currentPage = 1;
let flipTimer = null;
// Von buildPages() berechnete Seiteneinteilung: [{start, count}, ...] - Indizes in data.
// Keine feste "X Zeilen pro Seite", weil lange Titel/Gruppennamen auf mehrere Zeilen
// umbrechen können (siehe buildPages()); jede Seite bekommt so viele Zeilen, wie mit ihrer
// tatsächlich gerenderten Höhe in den Tabellenbereich passen.
let pages = [{ start: 0, count: 0 }];

// Farbe kommt direkt aus der Zone des Raums (siehe $zonenFarben in config.php) statt aus
// dem Raumcode geraten zu werden - das war vorher fehleranfällig (z.B. S3.0.01 vs. S3.2.xx
// haben laut Leitsystem unterschiedliche Farben, obwohl beide mit "S3" beginnen).
const zonenFarben = <?php echo json_encode($zonenFarben); ?>;

function getRoom(resId) {
    const raum = raeume.find(r => r.resID === resId);
    return raum ? { key: raum.roomKey, info: raum.roomInfo, zone: raum.zone, known: true }
                : { key: 'Raum ' + resId, info: '', zone: null, known: false };
}

function renderRoomBadge(item) {
    const resId = parseInt(item.resourceUId, 10);
    const room = getRoom(resId);
    const color = zonenFarben[room.zone] || null; // null -> Default-Grau aus CSS (unbekannter Raum)
    const label = room.info ? `${room.key} (${room.info})` : room.key;
    const style = color ? ` style="background:${color}"` : '';
    return `<span class="room-badge"${style}>${label}</span>`;
}

function displayEmptyTable() {
    const text = datenUnsicher
        ? 'Daten derzeit nicht verfügbar'
        : (aktuellModus
            ? 'Aktuell keine laufenden oder bald startenden Lehrveranstaltungen'
            : 'Keine Lehrveranstaltungen mehr geplant');
    document.querySelector('.table-wrap').innerHTML =
        `<div class="empty-state">${text}</div>`;
    document.getElementById('pagination').innerHTML = '';
}

function buildRow(item) {
    const row = document.createElement('tr');
    const group = item.groupName ? ` <span class="group">(${item.groupName})</span>` : '';
    row.innerHTML =
        `<td class="zeit">${item.von}–${item.bis}</td>` +
        `<td class="lv">${item.title}${group}</td>` +
        `<td>${renderRoomBadge(item)}</td>`;
    return row;
}

function displayTable(page) {
    const tableBody = document.querySelector('#dataTable tbody');
    if (!tableBody) return;
    tableBody.innerHTML = '';

    const seite = pages[page - 1] || { start: 0, count: 0 };
    data.slice(seite.start, seite.start + seite.count).forEach(item => {
        tableBody.appendChild(buildRow(item));
    });
}

function pageCount() {
    return Math.max(1, pages.length);
}

// Teilt data in Seiten ein, die tatsächlich in den Tabellenbereich passen. Dafür wird pro
// Seite Zeile für Zeile ins echte <tbody> eingefügt und nach jeder Zeile die tatsächliche
// Gesamthöhe gemessen - erst wenn sie überläuft, wandert die zuletzt eingefügte Zeile auf
// die nächste Seite. Eine isolierte Messung einzelner Zeilen (z.B. mit einer Testzeile)
// würde nicht abbilden, dass lange Titel/Gruppennamen abhängig von Bildschirmbreite und der
// per clamp() skalierten Schriftgröße auf mehrere Zeilen umbrechen können und dass die
// letzte Zeile im <tbody> laut CSS keinen unteren Rand hat (siehe "tbody tr:last-child") -
// beides würde die Höhe pro Zeile leicht verfälschen. Die tatsächliche Höhe im echten
// Mehrzeilen-Layout zu messen umgeht das und verhindert, dass die letzte Zeile einer Seite
// abgeschnitten wird (.table-wrap { overflow: hidden }).
function buildPages() {
    const tableWrap = document.querySelector('.table-wrap');
    const table = document.getElementById('dataTable');
    const thead = table ? table.querySelector('thead') : null;
    const tbody = table ? table.querySelector('tbody') : null;
    if (!tableWrap || !thead || !tbody || !Array.isArray(data) || data.length === 0) {
        return [{ start: 0, count: data.length || 0 }];
    }

    const verfuegbar = tableWrap.clientHeight - thead.getBoundingClientRect().height;
    const result = [];
    let index = 0;

    while (index < data.length) {
        tbody.innerHTML = '';
        const seitenStart = index;
        let count = 0;

        while (index < data.length) {
            tbody.appendChild(buildRow(data[index]));
            index++;
            count++;
            // Mindestens eine Zeile pro Seite (count > 1 als Bedingung), auch wenn eine
            // einzelne Zeile (z.B. durch einen sehr langen Titel) allein schon mehr Platz
            // braucht als verfügbar - lieber einmal überlaufen als eine Seite leer lassen.
            if (count > 1 && tbody.getBoundingClientRect().height > verfuegbar) {
                tbody.lastElementChild.remove();
                index--;
                count--;
                break;
            }
        }

        result.push({ start: seitenStart, count });
    }

    tbody.innerHTML = '';
    return result;
}

function setupPagination() {
    const pagination = document.getElementById('pagination');
    pagination.innerHTML = '';
    const count = pageCount();
    if (count <= 1) return; // alles passt auf eine Seite -> keine Steuerung, kein Autoflip nötig

    for (let i = 1; i <= count; i++) {
        const dot = document.createElement('span');
        dot.className = 'dot';
        dot.title = 'Seite ' + i;
        dot.addEventListener('click', () => goToPage(i));
        pagination.appendChild(dot);
    }
}

function updatePagination() {
    document.querySelectorAll('.dot').forEach((dot, i) => {
        dot.classList.toggle('active', i === currentPage - 1);
    });
}

function goToPage(page) {
    currentPage = page;
    displayTable(currentPage);
    updatePagination();
    restartProgress();
}

function restartProgress() {
    const bar = document.getElementById('progress');
    if (!bar) return;
    bar.style.transition = 'none';
    bar.style.width = '0%';
    // reflow erzwingen, damit die neue Transition greift
    void bar.offsetWidth;
    if (pageCount() > 1) {
        bar.style.transition = `width ${flipMs}ms linear`;
        bar.style.width = '100%';
    }
}

function autoFlipPages() {
    if (flipTimer) clearInterval(flipTimer);
    if (pageCount() <= 1) return;
    restartProgress();
    flipTimer = setInterval(() => {
        currentPage = (currentPage % pageCount()) + 1;
        displayTable(currentPage);
        updatePagination();
        restartProgress();
    }, flipMs);
}

function tickClock() {
    const el = document.getElementById('clock');
    if (!el) return;
    const now = new Date();
    el.textContent = now.toLocaleDateString('de-AT', { weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric' })
        + ' · ' + now.toLocaleTimeString('de-AT', { hour: '2-digit', minute: '2-digit' });
}

function initTable() {
    if (!Array.isArray(data) || data.length === 0) {
        displayEmptyTable();
        return;
    }

    pages = buildPages();
    currentPage = 1;
    displayTable(currentPage);
    setupPagination();
    updatePagination();
    autoFlipPages();
}

document.addEventListener('DOMContentLoaded', () => {
    tickClock();
    setInterval(tickClock, 15000);

    // Erst initialisieren, wenn Webfont (Raleway) UND alle Ressourcen (insbesondere das
    // Logo-Bild) geladen sind oder endgültig fehlgeschlagen sind - vorher gemessene
    // Höhen wären noch nicht endgültig (Browser rendert bis dahin z.B. mit der
    // Fallback-Schrift) und buildPages() würde dadurch zu viele Zeilen pro Seite
    // einplanen; ein späterer Font-Tausch oder eine späte Bild-Breite (siehe
    // .header .logo { aspect-ratio: ... }, das behebt die Ursache - hier nur ein
    // zusätzliches Sicherheitsnetz) käme erst danach und würde die letzte Zeile einer
    // Seite abschneiden (.table-wrap { overflow: hidden }).
    const seiteFertigGeladen = document.readyState === 'complete'
        ? Promise.resolve()
        : new Promise(resolve => window.addEventListener('load', resolve, { once: true }));
    const schriftBereit = (document.fonts && document.fonts.ready) ? document.fonts.ready : Promise.resolve();
    Promise.all([seiteFertigGeladen, schriftBereit]).then(initTable, initTable);

    // Bei Größenänderung (anderer Screen/Auflösung, Fenster im Test-Browser) die
    // Zeilenanzahl neu berechnen - mit kurzem Debounce, damit ein laufendes Resize nicht
    // ständig neu rendert.
    let resizeTimer = null;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(initTable, 200);
    });
});
</script>
</body>
</html>
