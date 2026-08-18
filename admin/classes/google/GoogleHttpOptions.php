<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

use Composer\CaBundle\CaBundle;

/**
 * Ruta al bundle de certificados CA para las llamadas cURL a Google (OAuth, Gmail, Calendar).
 * En Windows/WAMP `curl.cainfo` suele estar vacío en php.ini y el handshake TLS falla con
 * "SSL certificate problem: unable to get local issuer certificate" -- mismo problema ya
 * resuelto para el SDK de Anthropic en ClaudeService.php, aquí con cURL crudo en vez de Guzzle.
 */
final class GoogleHttpOptions
{
    private static ?string $caBundle = null;
    private static bool $resuelto = false;

    /** Aplica CURLOPT_CAINFO al handle si hay un bundle disponible en este sistema. */
    public static function aplicarCaBundle($curl): void
    {
        if (!self::$resuelto) {
            self::$caBundle = CaBundle::getSystemCaRootBundlePath() ?: null;
            self::$resuelto = true;
        }
        if (self::$caBundle !== null) {
            curl_setopt($curl, CURLOPT_CAINFO, self::$caBundle);
        }
    }
}
