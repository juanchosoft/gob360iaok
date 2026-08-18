<?php

declare(strict_types=1);

require_once __DIR__ . '/../admin/classes/SessionData.php';
require_once __DIR__ . '/../admin/classes/google/GoogleOAuthService.php';
require_once __DIR__ . '/../admin/classes/google/GoogleHttpOptions.php';

const GOOGLE_CALENDAR_API = 'https://www.googleapis.com/calendar/v3';

function calendarConfig(): array
{
    $file = __DIR__ . '/../config/google.php';
    if (!is_file($file)) {
        throw new RuntimeException('Falta configurar config/google.php');
    }
    return require $file;
}

function calendarJsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function calendarHttp(string $method, string $url, ?array $body = null, ?string $accessToken = null): array
{
    $headers = ['Accept: application/json'];
    if ($accessToken) {
        $headers[] = 'Authorization: Bearer ' . $accessToken;
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $curl = curl_init($url);
    GoogleHttpOptions::aplicarCaBundle($curl);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($raw === false) {
        throw new RuntimeException('No fue posible comunicarse con Google: ' . $error);
    }
    $decoded = $raw === '' ? [] : json_decode($raw, true);
    if ($status < 200 || $status >= 300) {
        $message = $decoded['error']['message'] ?? $decoded['error_description'] ?? 'Error de Google Calendar';
        throw new RuntimeException((string) $message, $status);
    }
    return is_array($decoded) ? $decoded : [];
}

/**
 * Access token del usuario en sesión, vía la conexión persistente en BD (no ya de
 * $_SESSION -- ver GoogleOAuthService). Requiere que admin/classes/SessionData.php ya
 * tenga sesión activa (session_user seteado por el llamador).
 */
function calendarRefreshAccessToken(): string
{
    if (empty($_SESSION['session_user'])) {
        throw new RuntimeException('Sesión no iniciada', 401);
    }
    try {
        return GoogleOAuthService::obtenerAccessTokenValido((int) SessionData::getUserId());
    } catch (GoogleNoConectadoException $e) {
        throw new RuntimeException($e->getMessage(), 401);
    }
}

function calendarApiUrl(string $eventId = '', array $query = []): string
{
    $config = calendarConfig();
    $url = GOOGLE_CALENDAR_API . '/calendars/' . rawurlencode($config['calendar_id'] ?? 'primary') . '/events';
    if ($eventId !== '') {
        $url .= '/' . rawurlencode($eventId);
    }
    return $url . ($query ? '?' . http_build_query($query) : '');
}

function calendarEventPayload(array $input): array
{
    $title = trim((string) ($input['title'] ?? ''));
    $start = (string) ($input['start'] ?? '');
    $end = (string) ($input['end'] ?? '');
    if ($title === '' || $start === '') {
        throw new InvalidArgumentException('El título y la fecha inicial son obligatorios');
    }
    $timezone = calendarConfig()['timezone'] ?? 'America/Bogota';
    $allDay = !empty($input['allDay']);
    $payload = [
        'summary' => mb_substr($title, 0, 250),
        'description' => mb_substr(trim((string) ($input['description'] ?? '')), 0, 8000),
        'location' => mb_substr(trim((string) ($input['location'] ?? '')), 0, 1000),
    ];
    if ($allDay) {
        $startDate = substr($start, 0, 10);
        $endDate = substr($end ?: $start, 0, 10);
        if ($endDate <= $startDate) {
            $endDate = (new DateTimeImmutable($startDate))->modify('+1 day')->format('Y-m-d');
        }
        $payload['start'] = ['date' => $startDate];
        // Google usa una fecha final exclusiva para los eventos de todo el día.
        $payload['end'] = ['date' => $endDate];
    } else {
        $payload['start'] = ['dateTime' => $start, 'timeZone' => $timezone];
        $payload['end'] = ['dateTime' => $end ?: $start, 'timeZone' => $timezone];
    }
    return $payload;
}
