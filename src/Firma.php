<?php

declare(strict_types=1);

namespace Trelintech\Sabidatos;

/**
 * El sobre firmado de cada petición a Sabidatos (contrato 22 §3).
 *
 * Se firma la cadena
 *
 *     KEY_ID \n MÉTODO \n RUTA \n TIMESTAMP \n NONCE \n sha256(cuerpo)
 *
 * con HMAC-SHA256 y el secreto de la instalación. El método y la ruta van
 * dentro: una firma de GET /health no sirve para POST /chat. El nonce y la hora
 * impiden reenviar una petición capturada.
 *
 * Cambiar una sola letra de la cadena rompe la compatibilidad con Sabidatos.
 */
final class Firma
{
    public const VERSION = 'v1';

    /** @return array<string, string> */
    public static function cabeceras(string $secreto, string $keyId, string $metodo, string $ruta,
                                     string $cuerpo = '', ?int $hora = null, ?string $nonce = null): array
    {
        $hora = (string) ($hora ?? time());
        $nonce = $nonce ?? bin2hex(random_bytes(16));
        $cadena = implode("\n", [$keyId, strtoupper($metodo), $ruta, $hora, $nonce, hash('sha256', $cuerpo)]);

        return [
            'X-Operia-Key-Id' => $keyId,
            'X-Operia-Timestamp' => $hora,
            'X-Operia-Nonce' => $nonce,
            'X-Operia-Signature' => self::VERSION . '=' . hash_hmac('sha256', $cadena, $secreto),
        ];
    }
}
