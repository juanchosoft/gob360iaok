<?php

require_once __DIR__ . '/GoogleOAuthService.php';
require_once __DIR__ . '/GmailApi.php';
require_once __DIR__ . '/../../../lib/google_calendar.php';

/**
 * Mantiene la caché de metadatos (tbl_google_correo_cache / tbl_google_calendario_cache) al
 * día. Pensada para correr desde cron (admin/cron/google_sync.php, sin sesión HTTP) pero
 * también reutilizable para escritura directa ("write-through") desde las tools de ALMA
 * cuando hacen un cambio (marcar leído, crear/cancelar evento) para no dejar la caché
 * desactualizada hasta el siguiente ciclo de cron.
 *
 * El CUERPO del correo y el detalle completo de un evento NUNCA se guardan aquí -- solo
 * metadatos, ver docs/PLAN_ALMA_GOOGLE_WORKSPACE.md §3.
 */
final class GoogleSyncService
{
    private const SEMILLA_MAX = 50;

    // ── Tokens ───────────────────────────────────────────────────────────────

    /** true si el usuario sigue conectado (y su access_token quedó fresco). */
    public static function refrescarTokenUsuario(int $usuarioId): bool
    {
        try {
            GoogleOAuthService::obtenerAccessTokenValido($usuarioId);
            return true;
        } catch (GoogleNoConectadoException $e) {
            return false;
        }
    }

    // ── Correo (Gmail History API) ──────────────────────────────────────────

    public static function sincronizarCorreoUsuario(int $usuarioId): array
    {
        $token = GoogleOAuthService::obtenerAccessTokenValido($usuarioId);

        $db  = new DbConection();
        $pdo = $db->openConect();
        $st  = $pdo->prepare('SELECT ultimo_history_id FROM tbl_google_correo_sync WHERE tbl_usuario_id = :uid');
        $st->execute([':uid' => $usuarioId]);
        $historyId = $st->fetchColumn();
        $db->closeConect();

        if (empty($historyId)) {
            return self::reseedCorreo($usuarioId, $token);
        }

        try {
            $cambios = 0;
            $resp    = GmailApi::obtenerHistorial($token, (string) $historyId);

            foreach ($resp['history'] ?? [] as $entrada) {
                $tocados = array_merge(
                    $entrada['messagesAdded'] ?? [],
                    $entrada['labelsAdded'] ?? [],
                    $entrada['labelsRemoved'] ?? []
                );
                foreach ($tocados as $cambio) {
                    $id = $cambio['message']['id'] ?? null;
                    if ($id) {
                        self::actualizarCacheCorreo($usuarioId, GmailApi::obtenerMetadatos($token, $id));
                        $cambios++;
                    }
                }
            }

            self::guardarSyncCorreo($usuarioId, (string) ($resp['historyId'] ?? $historyId));
            return ['ok' => true, 'cambios' => $cambios];
        } catch (RuntimeException $e) {
            if ((int) $e->getCode() === 404) {
                // historyId demasiado viejo (Gmail solo lo retiene un tiempo) -- resembrar.
                return self::reseedCorreo($usuarioId, $token);
            }
            self::registrarErrorCorreo($usuarioId, $e->getMessage());
            throw $e;
        }
    }

    private static function reseedCorreo(int $usuarioId, string $token): array
    {
        $ids = GmailApi::listarIds($token, 'in:inbox', self::SEMILLA_MAX);
        foreach ($ids as $ref) {
            if (!empty($ref['id'])) {
                self::actualizarCacheCorreo($usuarioId, GmailApi::obtenerMetadatos($token, $ref['id']));
            }
        }
        $perfil = GmailApi::obtenerPerfil($token);
        self::guardarSyncCorreo($usuarioId, (string) ($perfil['historyId'] ?? ''));
        return ['ok' => true, 'reseed' => true, 'mensajes' => count($ids)];
    }

    public static function actualizarCacheCorreo(int $usuarioId, array $metadatos): void
    {
        if (empty($metadatos['id'])) {
            return;
        }
        $db  = new DbConection();
        $pdo = $db->openConect();
        $st  = $pdo->prepare(
            "INSERT INTO tbl_google_correo_cache
                (tbl_usuario_id, gmail_message_id, gmail_thread_id, remitente, destinatarios, asunto, fragmento, etiquetas, leido, fecha)
             VALUES (:uid, :mid, :tid, :de, :para, :asunto, :fragmento, :etiquetas, :leido, :fecha)
             ON DUPLICATE KEY UPDATE
                gmail_thread_id = VALUES(gmail_thread_id),
                remitente = VALUES(remitente),
                destinatarios = VALUES(destinatarios),
                asunto = VALUES(asunto),
                fragmento = VALUES(fragmento),
                etiquetas = VALUES(etiquetas),
                leido = VALUES(leido),
                fecha = VALUES(fecha)"
        );
        $st->execute([
            ':uid'       => $usuarioId,
            ':mid'       => $metadatos['id'],
            ':tid'       => $metadatos['thread_id'] ?? '',
            ':de'        => mb_substr((string) ($metadatos['remitente'] ?? ''), 0, 320),
            ':para'      => mb_substr((string) ($metadatos['destinatario'] ?? ''), 0, 1000),
            ':asunto'    => mb_substr((string) ($metadatos['asunto'] ?? ''), 0, 500),
            ':fragmento' => mb_substr((string) ($metadatos['snippet'] ?? ''), 0, 500),
            ':etiquetas' => implode(',', $metadatos['etiquetas'] ?? []),
            ':leido'     => empty($metadatos['no_leido']) ? 1 : 0,
            ':fecha'     => self::fechaAMysql($metadatos['fecha'] ?? ''),
        ]);
        $db->closeConect();
    }

    public static function eliminarCacheCorreo(int $usuarioId, string $messageId): void
    {
        $db  = new DbConection();
        $pdo = $db->openConect();
        $pdo->prepare('DELETE FROM tbl_google_correo_cache WHERE tbl_usuario_id = :uid AND gmail_message_id = :mid')
            ->execute([':uid' => $usuarioId, ':mid' => $messageId]);
        $db->closeConect();
    }

    /** Write-through liviano tras gmail_marcar_leido -- no espera al próximo ciclo de cron. */
    public static function actualizarLeidoCorreo(int $usuarioId, string $messageId, bool $leido): void
    {
        $db  = new DbConection();
        $pdo = $db->openConect();
        $pdo->prepare('UPDATE tbl_google_correo_cache SET leido = :leido WHERE tbl_usuario_id = :uid AND gmail_message_id = :mid')
            ->execute([':leido' => $leido ? 1 : 0, ':uid' => $usuarioId, ':mid' => $messageId]);
        $db->closeConect();
    }

    private static function guardarSyncCorreo(int $usuarioId, string $historyId): void
    {
        $db  = new DbConection();
        $pdo = $db->openConect();
        $pdo->prepare(
            "INSERT INTO tbl_google_correo_sync (tbl_usuario_id, ultimo_history_id, ultima_sync_en, ultimo_error)
             VALUES (:uid, :hid, NOW(), NULL)
             ON DUPLICATE KEY UPDATE ultimo_history_id = VALUES(ultimo_history_id), ultima_sync_en = NOW(), ultimo_error = NULL"
        )->execute([':uid' => $usuarioId, ':hid' => $historyId]);
        $db->closeConect();
    }

    private static function registrarErrorCorreo(int $usuarioId, string $mensaje): void
    {
        $db  = new DbConection();
        $pdo = $db->openConect();
        $pdo->prepare(
            "INSERT INTO tbl_google_correo_sync (tbl_usuario_id, ultima_sync_en, ultimo_error)
             VALUES (:uid, NOW(), :err)
             ON DUPLICATE KEY UPDATE ultima_sync_en = NOW(), ultimo_error = VALUES(ultimo_error)"
        )->execute([':uid' => $usuarioId, ':err' => substr($mensaje, 0, 255)]);
        $db->closeConect();
    }

    // ── Calendario (Calendar syncToken) ─────────────────────────────────────

    public static function sincronizarCalendarioUsuario(int $usuarioId): array
    {
        $token = GoogleOAuthService::obtenerAccessTokenValido($usuarioId);

        $db  = new DbConection();
        $pdo = $db->openConect();
        $st  = $pdo->prepare('SELECT sync_token FROM tbl_google_calendario_sync WHERE tbl_usuario_id = :uid');
        $st->execute([':uid' => $usuarioId]);
        $syncToken = $st->fetchColumn();
        $db->closeConect();

        $query = ['singleEvents' => 'true', 'maxResults' => 250];
        if (!empty($syncToken)) {
            $query['syncToken'] = $syncToken;
        } else {
            $query['orderBy'] = 'startTime';
            $query['timeMin'] = gmdate('c', strtotime('-3 months'));
            $query['timeMax'] = gmdate('c', strtotime('+6 months'));
        }

        try {
            $vistos        = 0;
            $nextSyncToken = null;
            $pageToken     = null;

            do {
                $q = $query;
                if ($pageToken) {
                    $q['pageToken'] = $pageToken;
                }
                $resp = calendarHttp('GET', calendarApiUrl('', $q), null, $token);

                foreach ($resp['items'] ?? [] as $evento) {
                    if (($evento['status'] ?? '') === 'cancelled') {
                        self::eliminarCacheEvento($usuarioId, (string) $evento['id']);
                    } else {
                        self::actualizarCacheEvento($usuarioId, $evento);
                    }
                    $vistos++;
                }
                $pageToken     = $resp['nextPageToken'] ?? null;
                $nextSyncToken = $resp['nextSyncToken'] ?? $nextSyncToken;
            } while ($pageToken);

            self::guardarSyncCalendario($usuarioId, (string) $nextSyncToken);
            return ['ok' => true, 'eventos' => $vistos];
        } catch (RuntimeException $e) {
            if ((int) $e->getCode() === 410) {
                // syncToken vencido/inválido -- limpiar y resincronizar completo una vez.
                self::limpiarSyncCalendario($usuarioId);
                return self::sincronizarCalendarioUsuario($usuarioId);
            }
            self::registrarErrorCalendario($usuarioId, $e->getMessage());
            throw $e;
        }
    }

    public static function actualizarCacheEvento(int $usuarioId, array $evento): void
    {
        $inicio = $evento['start']['dateTime'] ?? $evento['start']['date'] ?? null;
        if (empty($evento['id']) || !$inicio) {
            return;
        }
        $fin       = $evento['end']['dateTime'] ?? $evento['end']['date'] ?? null;
        $todoElDia = isset($evento['start']['date']);

        $db  = new DbConection();
        $pdo = $db->openConect();
        $st  = $pdo->prepare(
            "INSERT INTO tbl_google_calendario_cache
                (tbl_usuario_id, google_event_id, titulo, inicio, fin, todo_el_dia, ubicacion, descripcion)
             VALUES (:uid, :eid, :titulo, :inicio, :fin, :todo, :ubicacion, :descripcion)
             ON DUPLICATE KEY UPDATE
                titulo = VALUES(titulo), inicio = VALUES(inicio), fin = VALUES(fin),
                todo_el_dia = VALUES(todo_el_dia), ubicacion = VALUES(ubicacion), descripcion = VALUES(descripcion)"
        );
        $st->execute([
            ':uid'         => $usuarioId,
            ':eid'         => $evento['id'],
            ':titulo'      => mb_substr((string) ($evento['summary'] ?? '(Sin título)'), 0, 255),
            ':inicio'      => self::fechaAMysql($inicio),
            ':fin'         => $fin ? self::fechaAMysql($fin) : null,
            ':todo'        => $todoElDia ? 1 : 0,
            ':ubicacion'   => mb_substr((string) ($evento['location'] ?? ''), 0, 500),
            ':descripcion' => mb_substr((string) ($evento['description'] ?? ''), 0, 2000),
        ]);
        $db->closeConect();
    }

    public static function eliminarCacheEvento(int $usuarioId, string $eventId): void
    {
        $db  = new DbConection();
        $pdo = $db->openConect();
        $pdo->prepare('DELETE FROM tbl_google_calendario_cache WHERE tbl_usuario_id = :uid AND google_event_id = :eid')
            ->execute([':uid' => $usuarioId, ':eid' => $eventId]);
        $db->closeConect();
    }

    private static function guardarSyncCalendario(int $usuarioId, string $syncToken): void
    {
        $db  = new DbConection();
        $pdo = $db->openConect();
        $pdo->prepare(
            "INSERT INTO tbl_google_calendario_sync (tbl_usuario_id, sync_token, ultima_sync_en, ultimo_error)
             VALUES (:uid, :token, NOW(), NULL)
             ON DUPLICATE KEY UPDATE sync_token = VALUES(sync_token), ultima_sync_en = NOW(), ultimo_error = NULL"
        )->execute([':uid' => $usuarioId, ':token' => $syncToken]);
        $db->closeConect();
    }

    private static function limpiarSyncCalendario(int $usuarioId): void
    {
        $db  = new DbConection();
        $pdo = $db->openConect();
        $pdo->prepare('UPDATE tbl_google_calendario_sync SET sync_token = NULL WHERE tbl_usuario_id = :uid')
            ->execute([':uid' => $usuarioId]);
        $db->closeConect();
    }

    private static function registrarErrorCalendario(int $usuarioId, string $mensaje): void
    {
        $db  = new DbConection();
        $pdo = $db->openConect();
        $pdo->prepare(
            "INSERT INTO tbl_google_calendario_sync (tbl_usuario_id, ultima_sync_en, ultimo_error)
             VALUES (:uid, NOW(), :err)
             ON DUPLICATE KEY UPDATE ultima_sync_en = NOW(), ultimo_error = VALUES(ultimo_error)"
        )->execute([':uid' => $usuarioId, ':err' => substr($mensaje, 0, 255)]);
        $db->closeConect();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function fechaAMysql(string $valor): string
    {
        $ts = $valor !== '' ? strtotime($valor) : false;
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
    }
}
