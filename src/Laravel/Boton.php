<?php

declare(strict_types=1);

namespace Trelintech\Sabidatos\Laravel;

/**
 * Las etiquetas del botón (guía 23 §2), con la huella del archivo para que el
 * navegador se niegue a ejecutarlo si alguien lo cambia.
 *
 * Trae su propio testigo CSRF en `<meta name="sabidatos-csrf">`: así funciona
 * aunque la plantilla no publique el de Laravel. Las direcciones se arman sin
 * `route()` —con la caché de rutas, una ruta del paquete puede no existir en
 * este proceso y `route()` rompería la página entera— y con la ruta base, para
 * sistemas instalados en una subcarpeta.
 */
final class Boton
{
    public const ARCHIVO = __DIR__ . '/../../recursos/operia-assistant.js';

    public static function huella(): string
    {
        return trim((string) @file_get_contents(self::ARCHIVO . '.sri'));
    }

    public static function html(): string
    {
        if (!filter_var(config('sabidatos.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            return '';
        }
        $huella = self::huella();
        if (!str_starts_with($huella, 'sha384-')) {
            return '';
        }
        $e = static fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $base = rtrim(request()->getBaseUrl(), '/');
        $endpoint = $base . '/' . trim((string) config('sabidatos.ruta', 'api/sabidatos'), '/');
        $src = $base . '/sabidatos/operia-assistant.js?v=' . substr(hash('sha256', $huella), 0, 12);

        return '<meta name="sabidatos-csrf" content="' . $e((string) csrf_token()) . '">'
            . '<operia-assistant endpoint="' . $e($endpoint) . '" csrf-meta="sabidatos-csrf" heading="'
            . $e((string) config('sabidatos.titulo', 'Asistente')) . '"></operia-assistant>'
            . '<script src="' . $e($src) . '" integrity="' . $e($huella) . '" crossorigin="anonymous"></script>';
    }
}
