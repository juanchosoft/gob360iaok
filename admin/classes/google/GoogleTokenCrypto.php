<?php

/**
 * Cifrado simétrico de los tokens de Google (access/refresh token) antes de guardarlos en
 * tbl_google_conexiones. AES-256-GCM vía openssl (no depende de sodium, que no está instalado
 * en este entorno). La clave vive en config.ini -> [google] -> token_encryption_key.
 */
final class GoogleTokenCrypto
{
    private const CIFRADO = 'aes-256-gcm';

    public static function cifrar(string $texto): string
    {
        $clave = self::clave();
        $iv    = random_bytes(openssl_cipher_iv_length(self::CIFRADO));
        $tag   = '';

        $cifrado = openssl_encrypt($texto, self::CIFRADO, $clave, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cifrado === false) {
            throw new RuntimeException('No fue posible cifrar el token de Google.');
        }

        return base64_encode($iv . $tag . $cifrado);
    }

    public static function descifrar(string $valor): string
    {
        $clave = self::clave();
        $crudo = base64_decode($valor, true);
        if ($crudo === false) {
            throw new RuntimeException('Token de Google corrupto (base64 inválido).');
        }

        $ivLen = openssl_cipher_iv_length(self::CIFRADO);
        $iv    = substr($crudo, 0, $ivLen);
        $tag   = substr($crudo, $ivLen, 16);
        $texto = substr($crudo, $ivLen + 16);

        $resultado = openssl_decrypt($texto, self::CIFRADO, $clave, OPENSSL_RAW_DATA, $iv, $tag);
        if ($resultado === false) {
            throw new RuntimeException('No fue posible descifrar el token de Google (clave incorrecta o dato corrupto).');
        }

        return $resultado;
    }

    private static function clave(): string
    {
        $config = require __DIR__ . '/../../../config/google.php';
        $clave  = (string) ($config['token_encryption_key'] ?? '');
        if ($clave === '') {
            throw new RuntimeException(
                'Falta configurar token_encryption_key en config.ini -> [google]. '
                . 'Ver docs/CONFIGURACION_GOOGLE_CLOUD.md.'
            );
        }

        $binaria = base64_decode($clave, true);
        if ($binaria === false || strlen($binaria) !== 32) {
            throw new RuntimeException(
                'GOOGLE token_encryption_key inválida: debe ser 32 bytes en base64 '
                . '(generar con: php -r "echo base64_encode(random_bytes(32));").'
            );
        }

        return $binaria;
    }
}
