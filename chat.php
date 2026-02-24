<?php
declare(strict_types=1);

error_reporting(0);
ini_set('display_errors', '0');

// CORS
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://agwe.biz');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$SERVICES = [
    "ремонт тента" => [
        "price" => "от 50 €",
        "description" => "Срочный ремонт тентов для грузовиков 24/7",
        "duration" => "1-2 часа",
        "duration_minutes" => 120
    ],
    "ремонт подвески" => [
        "price" => "от 100 €",
        "description" => "Ремонт подвески грузовых автомобилей",
        "duration" => "2-4 часа",
        "duration_minutes" => 180
    ],
    "шиномонтаж" => [
        "price" => "от 30 €",
        "description" => "Профессиональный шиномонтаж для грузовых автомобилей",
        "duration" => "30-60 минут",
        "duration_minutes" => 60
    ]
];

$BOOKINGS_FILE = __DIR__ . '/bookings.json';
$DEBUG_FILE    = __DIR__ . '/calendar-debug.txt';

if (!file_exists($BOOKINGS_FILE)) {
    file_put_contents($BOOKINGS_FILE, json_encode(new stdClass(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_services') {
    echo json_encode($SERVICES, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Use POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

handleChatRequest($SERVICES, $BOOKINGS_FILE, $DEBUG_FILE);

function respond(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function extractLastUserMessage(array $data): string {
    if (isset($data['message']) && is_string($data['message'])) {
        return trim($data['message']);
    }
    if (!isset($data['messages']) || !is_array($data['messages'])) {
        return '';
    }
    for ($i = count($data['messages']) - 1; $i >= 0; $i--) {
        $m = $data['messages'][$i];
        if (is_array($m) && ($m['role'] ?? '') === 'user') {
            return trim((string)($m['content'] ?? ''));
        }
    }
    return '';
}

function loadBookings(string $file): array {
    $raw = file_get_contents($file);
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : [];
}

function saveBookings(string $file, array $bookings): void {
    file_put_contents($file, json_encode($bookings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function newBookingState(): array {
    return [
        'step' => 0,
        'status' => 'pending',
        'service' => '',
        'name' => '',
        'phone' => '',
        'date' => '',
        'slots' => [],
        'eventId' => ''
    ];
}

function createSession(array &$bookings): string {
    $id = bin2hex(random_bytes(8));
    $bookings[$id] = newBookingState();
    return $id;
}

function normalizeServiceSelection(string $text, array $SERVICES): ?string {
    $t = trim(mb_strtolower($text));

    if (in_array($t, ['1','ремонт тента'], true)) return 'ремонт тента';
    if (in_array($t, ['2','ремонт подвески'], true)) return 'ремонт подвески';
    if (in_array($t, ['3','шиномонтаж'], true)) return 'шиномонтаж';

    foreach (array_keys($SERVICES) as $s) {
        if (mb_strpos($t, mb_strtolower($s)) !== false) return $s;
    }
    return null;
}

function normalizePhone(string $text): ?string {
    $t = trim($text);
    $clean = preg_replace('~[^\d\+]~', '', $t);
    if ($clean === null) return null;

    $digits = preg_replace('~\D~', '', $clean);
    if ($digits === null) return null;

    if (mb_strlen($digits) < 7 || mb_strlen($digits) > 15) return null;
    return $clean;
}

function fetchSlots(string $DEBUG_FILE): ?array {
    $url = 'https://agwe.biz/calendar.php';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($http !== 200 || !$body) {
        file_put_contents($DEBUG_FILE, "SLOTS ERROR HTTP={$http}\nCURL={$err}\nBODY={$body}\n");
        return null;
    }

    $slots = json_decode((string)$body, true);
    if (!is_array($slots)) {
        file_put_contents($DEBUG_FILE, "SLOTS JSON ERROR: " . json_last_error_msg() . "\nBODY={$body}\n");
        return null;
    }

    $out = [];
    foreach ($slots as $s) {
        if (is_string($s) && $s !== '') $out[] = $s;
    }
    return $out;
}

function formatSlotsAsList(array $slots, int $limit = 20): string {
    $lines = [];
    $n = min(count($slots), $limit);
    for ($i = 0; $i < $n; $i++) {
        $lines[] = ($i+1) . ") " . date('d.m.Y H:i', strtotime((string)$slots[$i]));
    }
    return implode("\n", $lines);
}

function selectSlotFromUserInput(string $text, array $slots): ?string {
    $t = trim($text);

    if (preg_match('~^\d{1,3}$~', $t)) {
        $idx = (int)$t - 1;
        if ($idx >= 0 && $idx < count($slots)) return (string)$slots[$idx];
    }

    foreach ($slots as $s) {
        $human = date('d.m.Y H:i', strtotime((string)$s));
        if ($t === $human) return (string)$s;
        if (trim((string)$s) === $t) return (string)$s;
    }

    return null;
}

/**
 * Create Google Calendar event and return eventId
 * Uses fallback credentials in code if ENV is not set.
 */
function addEventToCalendar(string $dateTime, array $bookingData, string $DEBUG_FILE, int $durationMin): string {
    require_once __DIR__ . '/vendor/autoload.php';

    // --- FALLBACK credentials (edit these) ---
    $FALLBACK_CLIENT_ID = 'PASTE_GOOGLE_CLIENT_ID_HERE';
    $FALLBACK_CLIENT_SECRET = 'PASTE_GOOGLE_CLIENT_SECRET_HERE';

    $CLIENT_ID     = getenv('GOOGLE_CLIENT_ID') ?: $FALLBACK_CLIENT_ID;
    $CLIENT_SECRET = getenv('GOOGLE_CLIENT_SECRET') ?: $FALLBACK_CLIENT_SECRET;

    if ($CLIENT_ID === '' || $CLIENT_SECRET === '' ||
        $CLIENT_ID === 'PASTE_GOOGLE_CLIENT_ID_HERE' ||
        $CLIENT_SECRET === 'PASTE_GOOGLE_CLIENT_SECRET_HERE') {
        throw new Exception('Google credentials not configured in chat.php');
    }

    $CALENDAR_ID = 'primary';
    $TOKEN_FILE  = __DIR__ . '/calendar-token.json';
    $TZ          = 'Europe/Tallinn';

    if (!file_exists($TOKEN_FILE)) {
        throw new Exception('calendar-token.json not found. Run /calendar-auth.php once.');
    }

    $token = json_decode((string)file_get_contents($TOKEN_FILE), true);
    if (!is_array($token) || empty($token['access_token'])) {
        throw new Exception('Invalid calendar-token.json. Re-run /calendar-auth.php.');
    }

    $client = new Google_Client();
    $client->setClientId($CLIENT_ID);
    $client->setClientSecret($CLIENT_SECRET);
    $client->setAccessType('offline');
    $client->setPrompt('consent');
    $client->addScope(Google_Service_Calendar::CALENDAR);
    $client->setAccessToken($token);

    if ($client->isAccessTokenExpired()) {
        $refreshToken = $client->getRefreshToken();
        if (!$refreshToken && !empty($token['refresh_token'])) {
            $refreshToken = $token['refresh_token'];
        }
        if (!$refreshToken) {
            throw new Exception('refresh_token missing. Re-run /calendar-auth.php with consent.');
        }

        $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
        if (isset($newToken['error'])) {
            throw new Exception('Token refresh failed: ' . ($newToken['error_description'] ?? $newToken['error']));
        }
        if (empty($newToken['refresh_token']) && !empty($token['refresh_token'])) {
            $newToken['refresh_token'] = $token['refresh_token'];
        }
        file_put_contents($TOKEN_FILE, json_encode($newToken, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($TOKEN_FILE, 0600);
        $client->setAccessToken($newToken);
    }

    $service = new Google_Service_Calendar($client);

    $tz = new DateTimeZone($TZ);
    $startDt = DateTime::createFromFormat('Y-m-d H:i', $dateTime, $tz);
    if (!$startDt) {
        $startDt = new DateTime($dateTime, $tz);
    }
    $endDt = (clone $startDt)->modify("+{$durationMin} minutes");

    $serviceName = (string)($bookingData['service'] ?? 'сервис');

    $event = new Google_Service_Calendar_Event([
        'summary' => "AGWE: {$serviceName}",
        'description' =>
            "Клиент: " . ($bookingData['name'] ?? '') . "\n" .
            "Телефон: " . ($bookingData['phone'] ?? '') . "\n" .
            "Услуга: {$serviceName}",
        'start' => ['dateTime' => $startDt->format(DateTime::RFC3339), 'timeZone' => $TZ],
        'end'   => ['dateTime' => $endDt->format(DateTime::RFC3339), 'timeZone' => $TZ],
    ]);

    try {
        $created = $service->events->insert($CALENDAR_ID, $event);
        $eventId = (string)$created->getId();
        file_put_contents($DEBUG_FILE, "SUCCESS EVENT_ID={$eventId}\n" . print_r($created, true));
        return $eventId;
    } catch (Exception $e) {
        file_put_contents($DEBUG_FILE, "ERROR CREATE EVENT\n" . $e->getMessage());
        throw $e;
    }
}

function handleChatRequest(array $SERVICES, string $BOOKINGS_FILE, string $DEBUG_FILE): void {
    $data = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($data)) {
        respond(['error' => 'Invalid JSON'], 400);
    }

    $userText = extractLastUserMessage($data);
    $sessionId = (string)($data['sessionId'] ?? '');

    $bookings = loadBookings($BOOKINGS_FILE);

    if ($sessionId === '' || !isset($bookings[$sessionId])) {
        $sessionId = createSession($bookings);
        saveBookings($BOOKINGS_FILE, $bookings);
    }

    $state = $bookings[$sessionId];
    $step = (int)($state['step'] ?? 0);

    // Start booking if user says "запиши" etc.
    $wantsBooking = preg_match('~\b(запиш(и|ите)|запись|хочу записаться|бронь|забронируй)\b~iu', $userText) === 1;

    if ($step === 0 && !$wantsBooking) {
        // Simple service/price answer
        $t = mb_strtolower($userText);
        foreach ($SERVICES as $name => $s) {
            if (mb_strpos($t, mb_strtolower($name)) !== false) {
                respond([
                    'action' => 'show_price',
                    'message' => "Цена на «{$name}»: {$s['price']}.\n{$s['description']}\nОриентировочно: {$s['duration']}\n\nДля записи напишите: «Хочу записаться».\nТел: +372 555 88 048",
                    'service' => $name,
                    'price' => $s['price'],
                ]);
            }
        }
        respond([
            'action' => 'info',
            'message' => "Я могу подсказать цены и записать вас.\nНапишите: «Хочу записаться».\nТел: +372 555 88 048, email: shalterjuri@gmail.com",
            'sessionId' => $sessionId
        ]);
    }

    if ($step === 0 && $wantsBooking) {
        $bookings[$sessionId] = newBookingState();
        $bookings[$sessionId]['step'] = 1;
        saveBookings($BOOKINGS_FILE, $bookings);

        respond([
            'action' => 'booking',
            'sessionId' => $sessionId,
            'step' => 1,
            'message' => "Выберите услугу:\n1) ремонт тента\n2) ремонт подвески\n3) шиномонтаж",
            'expected' => 'service'
        ]);
    }

    // Step 1: service
    if ($step === 1) {
        $service = normalizeServiceSelection($userText, $SERVICES);
        if ($service === null) {
            respond([
                'action' => 'booking',
                'sessionId' => $sessionId,
                'step' => 1,
                'message' => "Не понял услугу. Напишите 1/2/3 или словами.",
                'expected' => 'service'
            ]);
        }
        $bookings[$sessionId]['service'] = $service;
        $bookings[$sessionId]['step'] = 2;
        saveBookings($BOOKINGS_FILE, $bookings);

        respond([
            'action' => 'booking',
            'sessionId' => $sessionId,
            'step' => 2,
            'message' => "Как вас зовут?",
            'expected' => 'name'
        ]);
    }

    // Step 2: name
    if ($step === 2) {
        $name = trim($userText);
        if ($name === '' || mb_strlen($name) < 2) {
            respond([
                'action' => 'booking',
                'sessionId' => $sessionId,
                'step' => 2,
                'message' => "Напишите имя (минимум 2 символа).",
                'expected' => 'name'
            ]);
        }
        $bookings[$sessionId]['name'] = $name;
        $bookings[$sessionId]['step'] = 3;
        saveBookings($BOOKINGS_FILE, $bookings);

        respond([
            'action' => 'booking',
            'sessionId' => $sessionId,
            'step' => 3,
            'message' => "Введите телефон (например: +372 555 88 048).",
            'expected' => 'phone'
        ]);
    }

    // Step 3: phone + fetch slots
    if ($step === 3) {
        $phone = normalizePhone($userText);
        if ($phone === null) {
            respond([
                'action' => 'booking',
                'sessionId' => $sessionId,
                'step' => 3,
                'message' => "Телефон некорректный. Пример: +372 555 88 048",
                'expected' => 'phone'
            ]);
        }

        $bookings[$sessionId]['phone'] = $phone;

        $slots = fetchSlots($DEBUG_FILE);
        if (!$slots || count($slots) === 0) {
            $bookings[$sessionId]['step'] = 3;
            saveBookings($BOOKINGS_FILE, $bookings);
            respond([
                'action' => 'booking_error',
                'sessionId' => $sessionId,
                'message' => "Не удалось получить свободные слоты. Позвоните +372 555 88 048.",
            ]);
        }

        $bookings[$sessionId]['slots'] = $slots;
        $bookings[$sessionId]['step'] = 4;
        saveBookings($BOOKINGS_FILE, $bookings);

        respond([
            'action' => 'booking',
            'sessionId' => $sessionId,
            'step' => 4,
            'message' => "Выберите время: отправьте номер слота (например 1).",
            'expected' => 'slot',
            'timeOptions' => formatSlotsAsList($slots, 20),
            'slots' => $slots
        ]);
    }

    // Step 4: choose slot + create event
    if ($step === 4) {
        $slots = is_array($state['slots'] ?? null) ? $state['slots'] : [];
        $selected = selectSlotFromUserInput($userText, $slots);
        if ($selected === null) {
            respond([
                'action' => 'booking',
                'sessionId' => $sessionId,
                'step' => 4,
                'message' => "Не понял выбор. Напишите номер слота (1,2,3...).",
                'expected' => 'slot',
                'timeOptions' => formatSlotsAsList($slots, 20),
            ]);
        }

        $bookings[$sessionId]['date'] = $selected;

        $serviceName = (string)($bookings[$sessionId]['service'] ?? '');
        $durationMin = (int)($SERVICES[$serviceName]['duration_minutes'] ?? 120);

        try {
            $eventId = addEventToCalendar($selected, $bookings[$sessionId], $DEBUG_FILE, $durationMin);
            $bookings[$sessionId]['status'] = 'confirmed';
            $bookings[$sessionId]['eventId'] = $eventId;
            $bookings[$sessionId]['step'] = 5;
            saveBookings($BOOKINGS_FILE, $bookings);

            respond([
                'action' => 'booking_confirmed',
                'sessionId' => $sessionId,
                'step' => 5,
                'message' => "Готово! Вы записаны.\nДата: " . date('d.m.Y H:i', strtotime($selected)) .
                             "\nУслуга: {$serviceName}\n\nМы свяжемся для подтверждения.\nТел: +372 555 88 048\nEmail: shalterjuri@gmail.com",
                'eventId' => $eventId
            ]);
        } catch (Exception $e) {
            saveBookings($BOOKINGS_FILE, $bookings);
            respond([
                'action' => 'booking_error',
                'sessionId' => $sessionId,
                'message' => "Не удалось создать событие в календаре. Откройте calendar-debug.txt для деталей.\nТел: +372 555 88 048",
                'error' => $e->getMessage()
            ], 500);
        }
    }

    respond([
        'action' => 'info',
        'sessionId' => $sessionId,
        'message' => "Напишите: «Хочу записаться». Тел: +372 555 88 048"
    ]);
}