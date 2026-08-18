<?php

/**
 * Herramientas IA: Google Calendar PERSONAL del usuario en sesión (no institucional, no
 * compartido). Reutiliza el REST de lib/google_calendar.php (ya usado por el módulo
 * calendario.php) y GoogleOAuthService para el token, ambos ya con la sesión de GOB360
 * resuelta -- estas tools nunca acceden al calendario de otro usuario.
 */
final class ToolCalendarGoogle
{
    /**
     * Tool: calendario_listar_eventos
     * Lee primero de la caché (rápida, la mantiene fresca el cron de
     * admin/cron/google_sync.php) y solo va en vivo si todavía no hay nada cacheado para este
     * usuario -- en ese caso siembra la caché con lo que trae.
     */
    public static function listarEventos(array $input): array
    {
        return self::conToken(static function (string $token, int $usuarioId) use ($input): array {
            $desde = trim((string) ($input['desde'] ?? '')) ?: gmdate('c');
            $hasta = trim((string) ($input['hasta'] ?? '')) ?: gmdate('c', strtotime('+7 days'));

            $desdeCache = self::listarDesdeCache($usuarioId, $desde, $hasta);
            if ($desdeCache !== null) {
                return empty($desdeCache)
                    ? ['nota' => 'No hay eventos en ese rango de fechas.', 'eventos' => []]
                    : ['eventos' => $desdeCache];
            }

            $resultado = calendarHttp('GET', calendarApiUrl('', [
                'singleEvents' => 'true',
                'orderBy'      => 'startTime',
                'timeMin'      => $desde,
                'timeMax'      => $hasta,
                'maxResults'   => 50,
            ]), null, $token);

            $eventos = [];
            foreach ($resultado['items'] ?? [] as $e) {
                GoogleSyncService::actualizarCacheEvento($usuarioId, $e);
                $eventos[] = [
                    'id'          => $e['id'],
                    'titulo'      => $e['summary'] ?? '(Sin título)',
                    'inicio'      => $e['start']['dateTime'] ?? $e['start']['date'] ?? null,
                    'fin'         => $e['end']['dateTime'] ?? $e['end']['date'] ?? null,
                    'todo_el_dia' => isset($e['start']['date']),
                    'ubicacion'   => $e['location'] ?? '',
                    'descripcion' => $e['description'] ?? '',
                ];
            }

            if (empty($eventos)) {
                return ['nota' => 'No hay eventos en ese rango de fechas.', 'eventos' => []];
            }
            return ['eventos' => $eventos];
        });
    }

    /** Tool: calendario_crear_evento */
    public static function crearEvento(array $input): array
    {
        return self::conToken(static function (string $token, int $usuarioId) use ($input): array {
            $titulo = trim((string) ($input['titulo'] ?? ''));
            $inicio = trim((string) ($input['inicio'] ?? ''));
            if ($titulo === '' || $inicio === '') {
                return ['error' => 'Se requieren título e inicio para crear el evento.'];
            }

            try {
                $payload = calendarEventPayload([
                    'title'       => $titulo,
                    'start'       => $inicio,
                    'end'         => trim((string) ($input['fin'] ?? '')) ?: $inicio,
                    'allDay'      => !empty($input['todo_el_dia']),
                    'location'    => (string) ($input['ubicacion'] ?? ''),
                    'description' => (string) ($input['descripcion'] ?? ''),
                ]);
            } catch (InvalidArgumentException $e) {
                return ['error' => $e->getMessage()];
            }

            $invitados = self::emailsValidos($input['invitados'] ?? []);
            if (!empty($invitados)) {
                $payload['attendees'] = array_map(static fn(string $email): array => ['email' => $email], $invitados);
            }

            $evento = calendarHttp('POST', calendarApiUrl(), $payload, $token);
            if (!empty($evento['id'])) {
                GoogleSyncService::actualizarCacheEvento($usuarioId, $evento);
            }
            return [
                'creado'  => true,
                'id'      => $evento['id'] ?? null,
                'titulo'  => $evento['summary'] ?? $titulo,
                'enlace'  => $evento['htmlLink'] ?? null,
                'mensaje' => 'Evento creado en el calendario de Google del usuario.',
            ];
        });
    }

    /** Tool: calendario_mover_evento (cambia inicio/fin de un evento existente) */
    public static function moverEvento(array $input): array
    {
        return self::conToken(static function (string $token, int $usuarioId) use ($input): array {
            $eventoId = trim((string) ($input['evento_id'] ?? ''));
            $inicio   = trim((string) ($input['nuevo_inicio'] ?? ''));
            if ($eventoId === '' || $inicio === '') {
                return ['error' => 'Se requieren evento_id y nuevo_inicio.'];
            }
            $fin = trim((string) ($input['nuevo_fin'] ?? '')) ?: $inicio;
            $timezone = calendarConfig()['timezone'] ?? 'America/Bogota';

            $cambios = [
                'start' => ['dateTime' => $inicio, 'timeZone' => $timezone],
                'end'   => ['dateTime' => $fin, 'timeZone' => $timezone],
            ];

            $evento = calendarHttp('PATCH', calendarApiUrl($eventoId), $cambios, $token);
            if (!empty($evento['id'])) {
                GoogleSyncService::actualizarCacheEvento($usuarioId, $evento);
            }
            return [
                'movido'  => true,
                'id'      => $evento['id'] ?? $eventoId,
                'titulo'  => $evento['summary'] ?? null,
                'mensaje' => 'El evento se movió a la nueva fecha/hora.',
            ];
        });
    }

    /** Tool: calendario_cancelar_evento */
    public static function cancelarEvento(array $input): array
    {
        return self::conToken(static function (string $token, int $usuarioId) use ($input): array {
            $eventoId = trim((string) ($input['evento_id'] ?? ''));
            if ($eventoId === '') {
                return ['error' => 'Se requiere evento_id.'];
            }
            calendarHttp('DELETE', calendarApiUrl($eventoId), null, $token);
            GoogleSyncService::eliminarCacheEvento($usuarioId, $eventoId);
            return ['cancelado' => true, 'mensaje' => 'El evento fue eliminado del calendario.'];
        });
    }

    // ── Helpers privados ─────────────────────────────────────────────────────

    /**
     * Lee eventos desde tbl_google_calendario_cache para el rango pedido, o null si el
     * usuario todavía no tiene nada cacheado (para que el llamador sepa que debe ir en vivo).
     */
    private static function listarDesdeCache(int $usuarioId, string $desde, string $hasta): ?array
    {
        $db  = new DbConection();
        $pdo = $db->openConect();

        $stCount = $pdo->prepare('SELECT COUNT(*) FROM tbl_google_calendario_cache WHERE tbl_usuario_id = :uid');
        $stCount->execute([':uid' => $usuarioId]);
        if ((int) $stCount->fetchColumn() === 0) {
            $db->closeConect();
            return null;
        }

        $st = $pdo->prepare(
            'SELECT google_event_id, titulo, inicio, fin, todo_el_dia, ubicacion, descripcion
               FROM tbl_google_calendario_cache
              WHERE tbl_usuario_id = :uid AND inicio BETWEEN :desde AND :hasta
              ORDER BY inicio ASC
              LIMIT 50'
        );
        $st->execute([
            ':uid'   => $usuarioId,
            ':desde' => date('Y-m-d H:i:s', strtotime($desde) ?: time()),
            ':hasta' => date('Y-m-d H:i:s', strtotime($hasta) ?: strtotime('+7 days')),
        ]);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        $db->closeConect();

        return array_map(static fn(array $f): array => [
            'id'          => $f['google_event_id'],
            'titulo'      => $f['titulo'],
            'inicio'      => $f['inicio'],
            'fin'         => $f['fin'],
            'todo_el_dia' => (bool) $f['todo_el_dia'],
            'ubicacion'   => $f['ubicacion'],
            'descripcion' => $f['descripcion'],
        ], $filas);
    }

    /**
     * Resuelve el access_token del usuario en sesión y lo pasa a $fn junto al usuario_id. Si
     * no tiene su cuenta de Google conectada, devuelve una respuesta estructurada (no un error
     * crudo) para que ALMA le pida al usuario conectarla, en vez de fallar en silencio.
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
                           . 'gestionar tu calendario.',
            ];
        } catch (\Throwable $e) {
            return ['error' => 'No fue posible comunicarse con Google Calendar: ' . $e->getMessage()];
        }
    }

    private static function emailsValidos($valor): array
    {
        if (!is_array($valor)) {
            return [];
        }
        $emails = [];
        foreach ($valor as $email) {
            $email = trim((string) $email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }
        return $emails;
    }
}
