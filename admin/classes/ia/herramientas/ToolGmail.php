<?php

/**
 * Herramientas IA: Gmail PERSONAL del usuario en sesión (no un buzón institucional). El cuerpo
 * de un correo solo se trae en vivo (gmail_leer_correo) -- nunca se persiste en la base de
 * datos de GOB360, ver docs/PLAN_ALMA_GOOGLE_WORKSPACE.md §3.
 */
final class ToolGmail
{
    private const MAX_CUERPO = 4000;

    /**
     * Tool: gmail_listar_correos
     * Lee primero de la caché de metadatos (rápida, la mantiene fresca el cron de
     * admin/cron/google_sync.php) y solo va en vivo a Gmail si todavía no hay nada cacheado
     * para este usuario (ej. recién conectó su cuenta y el cron no ha corrido aún) -- en ese
     * caso, además, siembra la caché con lo que trae para no repetir la llamada en vivo la
     * próxima vez.
     */
    public static function listarCorreos(array $input): array
    {
        return self::conToken(static function (string $token, int $usuarioId) use ($input): array {
            $soloNoLeidos = ($input['filtro'] ?? 'no_leidos') !== 'todos';
            $limite       = max(1, min((int) ($input['limite'] ?? 10), 25));

            $desdeCache = self::listarDesdeCache($usuarioId, $soloNoLeidos, $limite);
            if ($desdeCache !== null) {
                return empty($desdeCache)
                    ? ['nota' => 'No hay correos que coincidan.', 'correos' => []]
                    : ['correos' => $desdeCache];
            }

            $query = 'in:inbox' . ($soloNoLeidos ? ' is:unread' : '');
            $ids   = GmailApi::listarIds($token, $query, $limite);
            if (empty($ids)) {
                return ['nota' => 'No hay correos que coincidan.', 'correos' => []];
            }

            $correos = [];
            foreach ($ids as $ref) {
                $metadatos = GmailApi::obtenerMetadatos($token, $ref['id']);
                GoogleSyncService::actualizarCacheCorreo($usuarioId, $metadatos);
                $correos[] = self::resumen($metadatos);
            }
            return ['correos' => $correos];
        });
    }

    /** Tool: gmail_buscar_correos */
    public static function buscarCorreos(array $input): array
    {
        return self::conToken(static function (string $token, int $usuarioId) use ($input): array {
            $consulta = trim((string) ($input['consulta'] ?? ''));
            if ($consulta === '') {
                return ['error' => 'Se requiere el texto o filtro de búsqueda (ej. "from:juan asunto")).'];
            }
            $limite = max(1, min((int) ($input['limite'] ?? 10), 25));

            $ids = GmailApi::listarIds($token, $consulta, $limite);
            if (empty($ids)) {
                return ['nota' => "No se encontraron correos para: {$consulta}", 'correos' => []];
            }

            $correos = [];
            foreach ($ids as $ref) {
                $correos[] = self::resumen(GmailApi::obtenerMetadatos($token, $ref['id']));
            }
            return ['correos' => $correos];
        });
    }

    /** Tool: gmail_leer_correo (siempre en vivo, con el cuerpo completo -- nunca desde caché) */
    public static function leerCorreo(array $input): array
    {
        return self::conToken(static function (string $token, int $usuarioId) use ($input): array {
            $mensajeId = trim((string) ($input['mensaje_id'] ?? ''));
            if ($mensajeId === '') {
                return ['error' => 'Se requiere mensaje_id.'];
            }

            $mensaje = GmailApi::obtenerCompleto($token, $mensajeId);
            $cuerpo  = trim($mensaje['cuerpo']);
            $truncado = false;
            if (mb_strlen($cuerpo) > self::MAX_CUERPO) {
                $cuerpo   = mb_substr($cuerpo, 0, self::MAX_CUERPO);
                $truncado = true;
            }

            return [
                'id'          => $mensaje['id'],
                'remitente'   => $mensaje['remitente'],
                'destinatario'=> $mensaje['destinatario'],
                'asunto'      => $mensaje['asunto'],
                'fecha'       => $mensaje['fecha'],
                'no_leido'    => $mensaje['no_leido'],
                'cuerpo'      => $cuerpo !== '' ? $cuerpo : '(Sin contenido de texto legible)',
                'truncado'    => $truncado,
            ];
        });
    }

    /** Tool: gmail_marcar_leido */
    public static function marcarLeido(array $input): array
    {
        return self::conToken(static function (string $token, int $usuarioId) use ($input): array {
            $mensajeId = trim((string) ($input['mensaje_id'] ?? ''));
            if ($mensajeId === '') {
                return ['error' => 'Se requiere mensaje_id.'];
            }
            $leido = (bool) ($input['leido'] ?? true);

            GmailApi::modificarEtiquetas(
                $token,
                $mensajeId,
                $leido ? [] : ['UNREAD'],
                $leido ? ['UNREAD'] : []
            );
            GoogleSyncService::actualizarLeidoCorreo($usuarioId, $mensajeId, $leido);

            return ['actualizado' => true, 'mensaje' => $leido ? 'Marcado como leído.' : 'Marcado como no leído.'];
        });
    }

    /** Tool: gmail_enviar_correo */
    public static function enviarCorreo(array $input): array
    {
        return self::conToken(static function (string $token, int $usuarioId) use ($input): array {
            $para   = trim((string) ($input['para'] ?? ''));
            $asunto = trim((string) ($input['asunto'] ?? ''));
            $cuerpo = trim((string) ($input['cuerpo'] ?? ''));
            if ($para === '' || !filter_var($para, FILTER_VALIDATE_EMAIL)) {
                return ['error' => 'Se requiere un destinatario (para) con correo válido.'];
            }
            if ($asunto === '' || $cuerpo === '') {
                return ['error' => 'Se requieren asunto y cuerpo del correo.'];
            }

            $de   = GoogleOAuthService::emailConectado($usuarioId) ?? '';
            $mime = GmailApi::construirMime($de, $para, $asunto, $cuerpo);
            GmailApi::enviarCrudo($token, $mime);

            return ['enviado' => true, 'para' => $para, 'asunto' => $asunto, 'mensaje' => 'Correo enviado correctamente.'];
        });
    }

    /** Tool: gmail_responder_correo (responde dentro del mismo hilo) */
    public static function responderCorreo(array $input): array
    {
        return self::conToken(static function (string $token, int $usuarioId) use ($input): array {
            $mensajeId = trim((string) ($input['mensaje_id'] ?? ''));
            $cuerpo    = trim((string) ($input['cuerpo'] ?? ''));
            if ($mensajeId === '' || $cuerpo === '') {
                return ['error' => 'Se requieren mensaje_id y cuerpo.'];
            }

            $original = GmailApi::obtenerMetadatos($token, $mensajeId);
            if (empty($original['remitente'])) {
                return ['error' => 'No fue posible ubicar el correo original para responder.'];
            }

            $asunto = $original['asunto'];
            if (!preg_match('/^re:/i', $asunto)) {
                $asunto = 'Re: ' . $asunto;
            }

            $de   = GoogleOAuthService::emailConectado($usuarioId) ?? '';
            $mime = GmailApi::construirMime(
                $de,
                $original['remitente'],
                $asunto,
                $cuerpo,
                $original['message_id_header'] ?: null,
                $original['message_id_header'] ?: null
            );
            GmailApi::enviarCrudo($token, $mime, $original['thread_id'] ?: null);

            return ['enviado' => true, 'para' => $original['remitente'], 'asunto' => $asunto, 'mensaje' => 'Respuesta enviada en el mismo hilo.'];
        });
    }

    // ── Helpers privados ─────────────────────────────────────────────────────

    /**
     * Devuelve la lista desde tbl_google_correo_cache, o null si el usuario todavía no tiene
     * nada cacheado (para que el llamador sepa que debe ir en vivo esta vez).
     */
    private static function listarDesdeCache(int $usuarioId, bool $soloNoLeidos, int $limite): ?array
    {
        $db  = new DbConection();
        $pdo = $db->openConect();

        $stCount = $pdo->prepare('SELECT COUNT(*) FROM tbl_google_correo_cache WHERE tbl_usuario_id = :uid');
        $stCount->execute([':uid' => $usuarioId]);
        if ((int) $stCount->fetchColumn() === 0) {
            $db->closeConect();
            return null;
        }

        $sql = 'SELECT gmail_message_id, remitente, asunto, fragmento, leido, fecha
                  FROM tbl_google_correo_cache
                 WHERE tbl_usuario_id = :uid' . ($soloNoLeidos ? ' AND leido = 0' : '') . '
                 ORDER BY fecha DESC
                 LIMIT ' . $limite;
        $st = $pdo->prepare($sql);
        $st->execute([':uid' => $usuarioId]);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        $db->closeConect();

        return array_map(static fn(array $f): array => [
            'id'        => $f['gmail_message_id'],
            'remitente' => $f['remitente'],
            'asunto'    => $f['asunto'],
            'fecha'     => $f['fecha'],
            'fragmento' => $f['fragmento'],
            'no_leido'  => !((bool) $f['leido']),
        ], $filas);
    }

    private static function resumen(array $mensaje): array
    {
        return [
            'id'        => $mensaje['id'],
            'remitente' => $mensaje['remitente'],
            'asunto'    => $mensaje['asunto'],
            'fecha'     => $mensaje['fecha'],
            'fragmento' => $mensaje['snippet'],
            'no_leido'  => $mensaje['no_leido'],
        ];
    }

    /**
     * Resuelve el access_token del usuario en sesión y lo pasa a $fn junto al usuario_id. Si
     * no tiene su cuenta de Google conectada, devuelve una respuesta estructurada (no un error
     * crudo) para que ALMA le pida al usuario conectarla.
     */
    private static function conToken(callable $fn): array
    {
        try {
            $usuarioId = (int) SessionData::getUserId();
            $token     = GoogleOAuthService::obtenerAccessTokenValido($usuarioId);
            return $fn($token, $usuarioId);
        } catch (GoogleNoConectadoException $e) {
            return [
                'requiere_conexion' => true,
                'mensaje' => 'Aún no tienes tu cuenta de Google conectada (o la conexión expiró). '
                           . 'Ve al módulo de Calendario y pulsa "Conectar con Google" para que pueda '
                           . 'gestionar tu correo.',
            ];
        } catch (\Throwable $e) {
            return ['error' => 'No fue posible comunicarse con Gmail: ' . $e->getMessage()];
        }
    }
}
