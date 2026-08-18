<?php

declare(strict_types=1);

// Los valores reales viven en config.ini -> [google] (mismo patrón que [anthropic]/[elevenlabs]).
$ini = parse_ini_file(__DIR__ . '/../config.ini', true);
$cfg = $ini['google'] ?? [];

return [
    'client_id'            => (string) ($cfg['client_id'] ?? ''),
    'client_secret'        => (string) ($cfg['client_secret'] ?? ''),
    'redirect_uri'         => (string) ($cfg['redirect_uri'] ?? ''),
    'token_encryption_key' => (string) ($cfg['token_encryption_key'] ?? ''),
    'calendar_id'          => 'primary',
    'timezone'             => 'America/Bogota',
];
