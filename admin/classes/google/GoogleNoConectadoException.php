<?php

/**
 * El usuario en sesión no tiene su cuenta de Google conectada (o la conexión fue revocada).
 * Las tools de ALMA la capturan para responder con una invitación a conectar la cuenta en vez
 * de un error crudo.
 */
final class GoogleNoConectadoException extends RuntimeException
{
}
