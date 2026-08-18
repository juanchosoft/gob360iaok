<?php

/**
 * Herramienta IA: directorio de contactos PERSONAL del usuario en sesión (nunca el de otro
 * usuario, sin importar si tiene contactos.todos.* en la interfaz web) -- para que ALMA pueda
 * resolver un nombre a un correo antes de enviar/responder un email o invitar a alguien a un
 * evento de calendario.
 */
final class ToolContactos
{
    /** Tool: contactos_buscar */
    public static function buscar(array $input): array
    {
        $nombre = trim((string) ($input['nombre'] ?? ''));
        if ($nombre === '') {
            return ['error' => 'Se requiere un nombre o parte del nombre para buscar.'];
        }

        $usuarioId = (int) SessionData::getUserId();
        $contactos = Contactos::buscarPropios($nombre, $usuarioId);

        if (empty($contactos)) {
            return [
                'nota'      => "No se encontró ningún contacto tuyo que coincida con '{$nombre}'. "
                             . "Puedes pedirle al usuario el correo directamente, o sugerirle "
                             . "agregarlo en el módulo de Contactos.",
                'contactos' => [],
            ];
        }

        return ['contactos' => $contactos];
    }
}
