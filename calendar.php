<?php
/**
 * calendar.php
 * - GET: returns available 1-hour slots for next 7 days (08:00–18:00, Europe/Tallinn)
 * - POST: optionally creates an event (returns event_id)
 *
 * Requires:
 * - vendor/autoload.php (google/apiclient)
 * - calendar-token.json created by calendar-auth.php (with refresh_token)
 */

declare(strict_types=1);

error_reporting(0);
ini_set('display_errors', '0');

// CORS
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://agwe.biz');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

// ---- Config ----
const CALENDAR_ID = 'primary';
const TOKEN_FILE  = __DIR__ . '/calendar-token.json';
const TZ          = 'Europe/Tallinn';

const WORK_HOUR_START = 8;   // 08:00
const WORK_HOUR_END   = 18;  // last slot starts at 17:00
const DAYS_AHEAD      = 7;

function respond(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Put your Google OAuth creds here as fallback if ENV is not set.
 * IMPORTANT: Rotate secrets in Google Cloud if they were exposed.
 */
function getGoogleClient(): Google_Client {
    // --- FALLBACK credentials (edit these) ---
    $FALLBACK_CLIENT_ID = 'PASTE_GOOGLE_CLIENT_ID_HERE';
    $FALLBACK_CLIENT_SECRET = 'PASTE_GOOGLE_CLIENT_SECRET_HERE';

    $clientId = getenv('GOOGLE_CLIENT_ID') ?: $FALLBACK_CLIENT_ID;
    $clientSecret = getenv('GOOGLE_CLIENT_SECRET') ?: $FALLBACK_CLIENT_SECRET;

    if ($clientId === '' || $clientSecret === '' ||
        $clientId === 'PASTE_GOOGLE_CLIENT_ID_HERE' ||
        $clientSecret === 'PASTE_GOOGLE_CLIENT_SECRET_HERE') {
        respond(['error' => 'Google credentials are not configured in calendar.php'], 500);
    }

    if (!file_exists(TOKEN_FILE)) {
        respond(['error' => 'calendar-token.json not found. Run calendar-auth.php first.'], 500);
    }

    $token = json_decode((string)file_get_contents(TOKEN_FILE), true);
    if (!is_array($token) || empty($token['access_token'])) {
        respond(['error' => 'Invalid calendar-token.json. Re-run calendar-auth.php.'], 500);
    }

    $client = new Google_Client();
    $client->setClientId($clientId);
    $client->setClientSecret($clientSecret);
    $client->setAccessType('offline');
    $client->setPrompt('consent');
    $client->addScope(Google_Service_Calendar::CALENDAR);

    $client->setAccessToken($token);

    // Refresh token if expired and persist
    if ($client->isAccessTokenExpired()) {
        $refreshToken = $client->getRefreshToken();
        if (!$refreshToken && !empty($token['refresh_token'])) {
            $refreshToken = $token['refresh_token'];
        }
        if (!$refreshToken) {
            respond(['error' => 'refresh_token missing. Re-run calendar-auth.php with consent/offline.'], 500);
        }

        $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
        if (isset($newToken['error'])) {
            respond([
                'error' => 'Token refresh failed',
                'details' => $newToken['error_description'] ?? $newToken['error']
            ], 500);
        }

        if (empty($newToken['refresh_token']) && !empty($token['refresh_token'])) {
            $newToken['refresh_token'] = $token['refresh_token'];
        }

        file_put_contents(TOKEN_FILE, json_encode($newToken, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod(TOKEN_FILE, 0600);
        $client->setAccessToken($newToken);
    }

    return $client;
}

function getBusyIntervals(Google_Service_Calendar $service, DateTime $timeMin, DateTime $timeMax, DateTimeZone $tz): array {
    $fbReq = new Google_Service_Calendar_FreeBusyRequest([
        'timeMin'  => $timeMin->format(DateTime::RFC3339),
        'timeMax'  => $timeMax->format(DateTime::RFC3339),
        'timeZone' => TZ,
        'items'    => [['id' => CALENDAR_ID]],
    ]);

    try {
        $fbResp = $service->freebusy->query($fbReq);
    } catch (Exception $e) {
        respond([
            'error' => 'Google Calendar API error (freebusy)',
            'details' => $e->getMessage()
        ], 502);
    }

    $busy = [];
    $calendars = $fbResp->getCalendars();
    $cal = $calendars[CALENDAR_ID] ?? null;

    if ($cal && method_exists($cal, 'getBusy')) {
        foreach ((array)$cal->getBusy() as $b) {
            $bs = new DateTime($b->getStart(), $tz);
            $be = new DateTime($b->getEnd(), $tz);
            $busy[] = [$bs, $be];
        }
    }
    return $busy;
}

function overlaps(DateTime $slotStart, DateTime $slotEnd, array $busyIntervals): bool {
    foreach ($busyIntervals as $pair) {
        [$bs, $be] = $pair;
        if ($slotStart < $be && $slotEnd > $bs) {
            return true;
        }
    }
    return false;
}

// ---- Main ----
$client = getGoogleClient();
$service = new Google_Service_Calendar($client);
$tz = new DateTimeZone(TZ);

$method = $_SERVER['REQUEST_METHOD'];

// POST: create event (optional)
if ($method === 'POST') {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        respond(['error' => 'Invalid JSON body'], 400);
    }

    $startStr = trim((string)($input['start_time'] ?? ''));
    $name     = trim((string)($input['name'] ?? ''));
    $serviceName = trim((string)($input['service'] ?? ''));
    $phone    = trim((string)($input['phone'] ?? ''));
    $durationMin = (int)($input['duration_minutes'] ?? 60);

    if ($startStr === '' || $name === '' || $serviceName === '') {
        respond(['error' => 'Missing required fields: start_time, name, service'], 400);
    }
    if ($durationMin < 15 || $durationMin > 480) {
        respond(['error' => 'duration_minutes must be between 15 and 480'], 400);
    }

    $startTime = DateTime::createFromFormat('Y-m-d H:i', $startStr, $tz);
    if (!$startTime) {
        respond(['error' => 'Invalid start_time format. Use "YYYY-MM-DD HH:MM"'], 400);
    }
    $endTime = (clone $startTime)->modify("+{$durationMin} minutes");

    try {
        $event = new Google_Service_Calendar_Event([
            'summary' => "AGWE: {$serviceName}",
            'description' =>
                "Клиент: {$name}\n" .
                ($phone !== '' ? "Телефон: {$phone}\n" : "") .
                "Услуга: {$serviceName}\n" .
                "Источник: чат-бот",
            'start' => [
                'dateTime' => $startTime->format(DateTime::RFC3339),
                'timeZone' => TZ,
            ],
            'end' => [
                'dateTime' => $endTime->format(DateTime::RFC3339),
                'timeZone' => TZ,
            ],
        ]);

        $created = $service->events->insert(CALENDAR_ID, $event);
        respond(['success' => true, 'event_id' => $created->getId()]);
    } catch (Exception $e) {
        respond(['error' => 'Failed to create event', 'details' => $e->getMessage()], 500);
    }
}

// GET: return slots
if ($method === 'GET') {
    $now = new DateTime('now', $tz);
    $minStart = (clone $now)->modify('+1 hour');

    $timeMin = (clone $now)->setTime(0, 0, 0);
    $timeMax = (clone $now)->modify('+' . DAYS_AHEAD . ' days')->setTime(23, 59, 59);

    $busy = getBusyIntervals($service, $timeMin, $timeMax, $tz);

    $availableSlots = [];
    for ($day = 0; $day < DAYS_AHEAD; $day++) {
        $d = (clone $now)->modify("+{$day} days");
        for ($hour = WORK_HOUR_START; $hour < WORK_HOUR_END; $hour++) {
            $slotStart = (clone $d)->setTime($hour, 0, 0);
            $slotEnd   = (clone $slotStart)->modify('+1 hour');

            if ($slotStart < $minStart) continue;
            if (!overlaps($slotStart, $slotEnd, $busy)) {
                $availableSlots[] = $slotStart->format('Y-m-d H:i');
            }
        }
    }

    respond($availableSlots);
}

respond(['error' => 'Method not allowed'], 405);