<?php

require_once __DIR__ . '/GoogleHttpOptions.php';

/**
 * Helpers REST puros (cURL, sin SDK) para la Gmail API -- mismo patrón que
 * lib/google_calendar.php para Calendar. Todo el trabajo de token (obtenerlo, refrescarlo)
 * queda fuera de esta clase; aquí solo entra un $accessToken ya válido.
 */
final class GmailApi
{
    private const BASE = 'https://gmail.googleapis.com/gmail/v1/users/me';

    /** Lista IDs de mensajes que matchean una búsqueda estilo Gmail (q=). Sin metadatos. */
    public static function listarIds(string $token, string $query, int $maxResultados): array
    {
        $url = self::BASE . '/messages?' . http_build_query([
            'q'          => $query,
            'maxResults' => max(1, min($maxResultados, 50)),
        ]);
        $resultado = self::http('GET', $url, null, $token);
        return $resultado['messages'] ?? [];
    }

    /** historyId actual del buzón -- punto de partida para la sincronización incremental. */
    public static function obtenerPerfil(string $token): array
    {
        return self::http('GET', self::BASE . '/profile', null, $token);
    }

    /**
     * Cambios en el buzón desde $historyId (mensajes agregados/etiquetas cambiadas). Si el
     * historyId ya es demasiado viejo, Gmail responde 404 -- el llamador debe interpretarlo
     * como "hay que resincronizar completo" (ver GoogleSyncService).
     */
    public static function obtenerHistorial(string $token, string $historyId, int $maxResultados = 100): array
    {
        $url = self::BASE . '/history?' . http_build_query([
            'startHistoryId' => $historyId,
            'maxResults'     => max(1, min($maxResultados, 500)),
        ]) . '&historyTypes=messageAdded&historyTypes=labelAdded&historyTypes=labelRemoved';

        return self::http('GET', $url, null, $token);
    }

    /** Metadatos livianos de un mensaje (asunto, remitente, fecha, snippet, etiquetas) -- sin cuerpo. */
    public static function obtenerMetadatos(string $token, string $id): array
    {
        $headers = ['Subject', 'From', 'To', 'Date'];
        $query   = ['format' => 'metadata'];
        $url     = self::BASE . '/messages/' . rawurlencode($id) . '?' . http_build_query($query)
                 . '&' . implode('&', array_map(static fn(string $h) => 'metadataHeaders=' . rawurlencode($h), $headers));

        $mensaje = self::http('GET', $url, null, $token);
        return self::normalizarMensaje($mensaje);
    }

    /** Mensaje completo (con cuerpo de texto) -- siempre en vivo, nunca se cachea (ver plan). */
    public static function obtenerCompleto(string $token, string $id): array
    {
        $url     = self::BASE . '/messages/' . rawurlencode($id) . '?format=full';
        $mensaje = self::http('GET', $url, null, $token);

        $normalizado           = self::normalizarMensaje($mensaje);
        $normalizado['cuerpo'] = self::extraerCuerpoTexto($mensaje['payload'] ?? []);
        return $normalizado;
    }

    /** Agrega/quita etiquetas (ej. UNREAD para marcar leído/no leído). */
    public static function modificarEtiquetas(string $token, string $id, array $agregar, array $quitar): array
    {
        $url = self::BASE . '/messages/' . rawurlencode($id) . '/modify';
        return self::http('POST', $url, ['addLabelIds' => $agregar, 'removeLabelIds' => $quitar], $token);
    }

    /** Envía un mensaje ya armado en crudo (RFC 2822, base64url). $threadId solo para respuestas. */
    public static function enviarCrudo(string $token, string $rawBase64Url, ?string $threadId = null): array
    {
        $body = ['raw' => $rawBase64Url];
        if ($threadId !== null) {
            $body['threadId'] = $threadId;
        }
        return self::http('POST', self::BASE . '/messages/send', $body, $token);
    }

    /**
     * Arma un mensaje RFC 2822 simple (texto plano, UTF-8) y lo codifica en base64url, listo
     * para enviarCrudo(). Si se pasan $inReplyTo/$references, queda enlazado al hilo original.
     */
    public static function construirMime(
        string $de,
        string $para,
        string $asunto,
        string $cuerpo,
        ?string $inReplyTo = null,
        ?string $references = null
    ): string {
        $lineas = [
            'From: ' . $de,
            'To: ' . $para,
            'Subject: =?UTF-8?B?' . base64_encode($asunto) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        if ($inReplyTo) {
            $lineas[] = 'In-Reply-To: ' . $inReplyTo;
        }
        if ($references) {
            $lineas[] = 'References: ' . $references;
        }
        $mime = implode("\r\n", $lineas) . "\r\n\r\n" . chunk_split(base64_encode($cuerpo));

        return rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');
    }

    // ── Helpers privados ─────────────────────────────────────────────────────

    private static function normalizarMensaje(array $mensaje): array
    {
        $cabeceras = [];
        foreach ($mensaje['payload']['headers'] ?? [] as $h) {
            $cabeceras[$h['name']] = $h['value'];
        }

        return [
            'id'         => $mensaje['id'] ?? '',
            'thread_id'  => $mensaje['threadId'] ?? '',
            'remitente'  => $cabeceras['From'] ?? '',
            'destinatario' => $cabeceras['To'] ?? '',
            'asunto'     => $cabeceras['Subject'] ?? '(Sin asunto)',
            'fecha'      => $cabeceras['Date'] ?? '',
            'message_id_header' => $cabeceras['Message-ID'] ?? ($cabeceras['Message-Id'] ?? ''),
            'snippet'    => $mensaje['snippet'] ?? '',
            'etiquetas'  => $mensaje['labelIds'] ?? [],
            'no_leido'   => in_array('UNREAD', $mensaje['labelIds'] ?? [], true),
        ];
    }

    /** Camina payload.parts buscando text/plain (o text/html sin etiquetas como respaldo). */
    private static function extraerCuerpoTexto(array $payload): string
    {
        $mimeType = $payload['mimeType'] ?? '';

        if (($mimeType === 'text/plain' || $mimeType === 'text/html') && !empty($payload['body']['data'])) {
            $texto = self::decodificarBase64Url($payload['body']['data']);
            return $mimeType === 'text/html' ? trim(strip_tags($texto)) : $texto;
        }

        $html = '';
        foreach ($payload['parts'] ?? [] as $parte) {
            $tipo = $parte['mimeType'] ?? '';
            if ($tipo === 'text/plain' && !empty($parte['body']['data'])) {
                return self::decodificarBase64Url($parte['body']['data']);
            }
            if ($tipo === 'text/html' && !empty($parte['body']['data']) && $html === '') {
                $html = self::decodificarBase64Url($parte['body']['data']);
            }
            if (str_starts_with($tipo, 'multipart/') && !empty($parte['parts'])) {
                $anidado = self::extraerCuerpoTexto($parte);
                if ($anidado !== '') {
                    return $anidado;
                }
            }
        }

        return $html !== '' ? trim(strip_tags($html)) : '';
    }

    private static function decodificarBase64Url(string $valor): string
    {
        $normalizado = strtr($valor, '-_', '+/');
        return (string) base64_decode($normalizado);
    }

    private static function http(string $method, string $url, ?array $body, string $accessToken): array
    {
        $headers = ['Authorization: Bearer ' . $accessToken, 'Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $curl = curl_init($url);
        GoogleHttpOptions::aplicarCaBundle($curl);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_POSTFIELDS     => $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);
        $raw    = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($curl);
        curl_close($curl);

        if ($raw === false) {
            throw new RuntimeException('No fue posible comunicarse con Gmail: ' . $error);
        }
        $decoded = $raw === '' ? [] : json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $mensaje = $decoded['error']['message'] ?? 'Error de Gmail';
            throw new RuntimeException((string) $mensaje, $status);
        }
        return is_array($decoded) ? $decoded : [];
    }
}
