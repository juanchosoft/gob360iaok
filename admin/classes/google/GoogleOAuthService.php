<?php

require_once __DIR__ . '/GoogleTokenCrypto.php';
require_once __DIR__ . '/GoogleNoConectadoException.php';
require_once __DIR__ . '/GoogleHttpOptions.php';

/**
 * Ciclo de vida completo de la conexión de un usuario con su propia cuenta de Google
 * (Gmail + Calendar): URL de autorización, intercambio de código, refresco de tokens y
 * desconexión. Los tokens se guardan cifrados en tbl_google_conexiones (una fila por
 * tbl_usuario_id), no en $_SESSION -- así sirve tanto para peticiones web como para cron.
 *
 * REGLA: cada usuario opera únicamente sobre SU PROPIA cuenta conectada. No existe (ni debe
 * agregarse) ninguna forma de que un usuario acceda a la conexión de otro.
 */
final class GoogleOAuthService
{
    private const URL_AUTORIZACION = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const URL_TOKEN        = 'https://oauth2.googleapis.com/token';
    private const URL_REVOCAR      = 'https://oauth2.googleapis.com/revoke';
    private const URL_USERINFO     = 'https://www.googleapis.com/oauth2/v2/userinfo';

    // userinfo.email es obligatorio: sin él, el endpoint de userinfo (obtenerEmail()) no
    // devuelve el correo de la cuenta aunque el resto de los scopes se hayan otorgado bien.
    public const SCOPES = 'https://www.googleapis.com/auth/calendar.events '
        . 'https://www.googleapis.com/auth/gmail.readonly '
        . 'https://www.googleapis.com/auth/gmail.send '
        . 'https://www.googleapis.com/auth/gmail.modify '
        . 'https://www.googleapis.com/auth/userinfo.email';

    public static function urlAutorizacion(string $state): string
    {
        $config = self::config();
        $query  = http_build_query([
            'client_id'              => $config['client_id'],
            'redirect_uri'           => $config['redirect_uri'],
            'response_type'          => 'code',
            'scope'                  => self::SCOPES,
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'state'                  => $state,
        ]);
        return self::URL_AUTORIZACION . '?' . $query;
    }

    /**
     * Intercambia el `code` de la redirección por tokens, resuelve el email de la cuenta
     * conectada y persiste (o reemplaza) la conexión del usuario en BD, cifrada.
     */
    public static function conectar(int $usuarioId, string $code): void
    {
        $config = self::config();
        $token  = self::httpToken([
            'code'          => $code,
            'client_id'     => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri'  => $config['redirect_uri'],
            'grant_type'    => 'authorization_code',
        ]);

        if (empty($token['access_token']) || empty($token['refresh_token'])) {
            // Sin refresh_token no sirve para uso posterior (ni para cron). Pasa típicamente
            // si el usuario ya había autorizado antes sin `prompt=consent` -- acá siempre se
            // pide, así que esto indica una respuesta inesperada de Google.
            throw new RuntimeException('Google no devolvió un refresh_token. Intenta conectar de nuevo.');
        }

        $email = self::obtenerEmail($token['access_token']);

        $db  = new DbConection();
        $pdo = $db->openConect();
        $st  = $pdo->prepare(
            "INSERT INTO tbl_google_conexiones
                (tbl_usuario_id, google_email, access_token_enc, refresh_token_enc, scope, expires_at, estado, ultimo_error, conectado_en)
             VALUES (:uid, :email, :access, :refresh, :scope, :expires, 'activo', NULL, NOW())
             ON DUPLICATE KEY UPDATE
                google_email = VALUES(google_email),
                access_token_enc = VALUES(access_token_enc),
                refresh_token_enc = VALUES(refresh_token_enc),
                scope = VALUES(scope),
                expires_at = VALUES(expires_at),
                estado = 'activo',
                ultimo_error = NULL"
        );
        $st->execute([
            ':uid'     => $usuarioId,
            ':email'   => $email,
            ':access'  => GoogleTokenCrypto::cifrar($token['access_token']),
            ':refresh' => GoogleTokenCrypto::cifrar($token['refresh_token']),
            ':scope'   => (string) ($token['scope'] ?? self::SCOPES),
            ':expires' => date('Y-m-d H:i:s', time() + (int) ($token['expires_in'] ?? 3600)),
        ]);
        $db->closeConect();
    }

    public static function estaConectado(int $usuarioId): bool
    {
        $db  = new DbConection();
        $pdo = $db->openConect();
        $st  = $pdo->prepare("SELECT 1 FROM tbl_google_conexiones WHERE tbl_usuario_id = :uid AND estado = 'activo' LIMIT 1");
        $st->execute([':uid' => $usuarioId]);
        $existe = (bool) $st->fetchColumn();
        $db->closeConect();
        return $existe;
    }

    /** Devuelve el email conectado, o null si no hay conexión activa. */
    public static function emailConectado(int $usuarioId): ?string
    {
        $db  = new DbConection();
        $pdo = $db->openConect();
        $st  = $pdo->prepare("SELECT google_email FROM tbl_google_conexiones WHERE tbl_usuario_id = :uid AND estado = 'activo' LIMIT 1");
        $st->execute([':uid' => $usuarioId]);
        $email = $st->fetchColumn();
        $db->closeConect();
        return $email !== false ? (string) $email : null;
    }

    /**
     * Devuelve un access_token utilizable para el usuario, refrescándolo si está por expirar.
     * Sirve igual desde una petición web con sesión que desde un script de cron sin sesión
     * (por eso recibe $usuarioId explícito en vez de leerlo de $_SESSION).
     *
     * @throws GoogleNoConectadoException si el usuario nunca conectó su cuenta o fue revocada
     */
    public static function obtenerAccessTokenValido(int $usuarioId): string
    {
        $db  = new DbConection();
        $pdo = $db->openConect();
        $st  = $pdo->prepare("SELECT * FROM tbl_google_conexiones WHERE tbl_usuario_id = :uid LIMIT 1");
        $st->execute([':uid' => $usuarioId]);
        $fila = $st->fetch(PDO::FETCH_ASSOC);

        if (!$fila || $fila['estado'] !== 'activo') {
            $db->closeConect();
            throw new GoogleNoConectadoException('El usuario no tiene una cuenta de Google conectada.');
        }

        if (strtotime($fila['expires_at']) > time() + 60) {
            $db->closeConect();
            return GoogleTokenCrypto::descifrar($fila['access_token_enc']);
        }

        // Access token vencido (o por vencer): refrescar con el refresh_token.
        $config       = self::config();
        $refreshToken = GoogleTokenCrypto::descifrar($fila['refresh_token_enc']);

        try {
            $nuevo = self::httpToken([
                'client_id'     => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'refresh_token' => $refreshToken,
                'grant_type'    => 'refresh_token',
            ]);
        } catch (RuntimeException $e) {
            // invalid_grant (revocado desde Google) u otro fallo -> marcar y pedir reconexión.
            $upd = $pdo->prepare("UPDATE tbl_google_conexiones SET estado = 'revocado', ultimo_error = :err WHERE tbl_usuario_id = :uid");
            $upd->execute([':err' => substr($e->getMessage(), 0, 255), ':uid' => $usuarioId]);
            $db->closeConect();
            throw new GoogleNoConectadoException('La conexión con Google expiró o fue revocada. Hay que reconectar la cuenta.');
        }

        if (empty($nuevo['access_token'])) {
            $db->closeConect();
            throw new GoogleNoConectadoException('No fue posible renovar la conexión con Google.');
        }

        $upd = $pdo->prepare(
            "UPDATE tbl_google_conexiones
                SET access_token_enc = :access, expires_at = :expires, estado = 'activo', ultimo_error = NULL
              WHERE tbl_usuario_id = :uid"
        );
        $upd->execute([
            ':access'  => GoogleTokenCrypto::cifrar($nuevo['access_token']),
            ':expires' => date('Y-m-d H:i:s', time() + (int) ($nuevo['expires_in'] ?? 3600)),
            ':uid'     => $usuarioId,
        ]);
        $db->closeConect();

        return $nuevo['access_token'];
    }

    /** Revoca el acceso en Google (best-effort) y borra la conexión local. */
    public static function desconectar(int $usuarioId): void
    {
        $db  = new DbConection();
        $pdo = $db->openConect();
        $st  = $pdo->prepare("SELECT refresh_token_enc FROM tbl_google_conexiones WHERE tbl_usuario_id = :uid LIMIT 1");
        $st->execute([':uid' => $usuarioId]);
        $fila = $st->fetch(PDO::FETCH_ASSOC);

        if ($fila) {
            try {
                $token = GoogleTokenCrypto::descifrar($fila['refresh_token_enc']);
                $curl  = curl_init(self::URL_REVOCAR);
                GoogleHttpOptions::aplicarCaBundle($curl);
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_POSTFIELDS     => http_build_query(['token' => $token]),
                ]);
                curl_exec($curl);
                curl_close($curl);
            } catch (\Throwable $e) {
                // No bloquear la desconexión local si Google no responde.
                error_log('GoogleOAuthService::desconectar (revoke best-effort): ' . $e->getMessage());
            }
        }

        $del = $pdo->prepare("DELETE FROM tbl_google_conexiones WHERE tbl_usuario_id = :uid");
        $del->execute([':uid' => $usuarioId]);
        $db->closeConect();
    }

    // ── Helpers privados ─────────────────────────────────────────────────────

    private static function obtenerEmail(string $accessToken): string
    {
        $curl = curl_init(self::URL_USERINFO);
        GoogleHttpOptions::aplicarCaBundle($curl);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        ]);
        $raw    = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        $data = $raw ? json_decode($raw, true) : null;
        if ($status !== 200 || empty($data['email'])) {
            throw new RuntimeException('No fue posible obtener el correo de la cuenta de Google conectada.');
        }
        return (string) $data['email'];
    }

    private static function httpToken(array $postFields): array
    {
        $curl = curl_init(self::URL_TOKEN);
        GoogleHttpOptions::aplicarCaBundle($curl);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_POSTFIELDS     => http_build_query($postFields),
        ]);
        $raw    = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($curl);
        curl_close($curl);

        if ($raw === false) {
            throw new RuntimeException('No fue posible comunicarse con Google: ' . $error);
        }
        $decoded = json_decode($raw, true);
        if ($status !== 200 || empty($decoded['access_token'])) {
            $mensaje = $decoded['error_description'] ?? $decoded['error'] ?? 'Error desconocido de Google OAuth';
            throw new RuntimeException((string) $mensaje);
        }
        return $decoded;
    }

    private static function config(): array
    {
        return require __DIR__ . '/../../../config/google.php';
    }
}
