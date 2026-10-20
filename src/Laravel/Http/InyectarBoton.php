<?php

declare(strict_types=1);

namespace Trelintech\Sabidatos\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Trelintech\Sabidatos\Laravel\Boton;

/**
 * Pone el botón en las páginas HTML de quien tiene sesión, sin tocar ninguna
 * plantilla. Sólo en respuestas 200 completas con </body>; nunca en JSON, en
 * descargas ni en flujos. Si la página ya lo tiene (@sabidatos), no lo repite.
 */
final class InyectarBoton
{
    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        if ($respuesta->getStatusCode() !== 200 || !$request->isMethod('GET')
            || !str_contains((string) $respuesta->headers->get('Content-Type', ''), 'text/html')
            || $respuesta instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            || $respuesta instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
            // Ni en descargas, ni en páginas que una caché compartida pueda
            // servir a otra persona: llevarían el testigo CSRF de ésta.
            || str_contains(strtolower((string) $respuesta->headers->get('Content-Disposition', '')), 'attachment')
            || $respuesta->headers->hasCacheControlDirective('public')
            || $respuesta->headers->hasCacheControlDirective('s-maxage')
            || !Auth::guard(config('sabidatos.guard'))->check()) {
            return $respuesta;
        }
        $html = $respuesta->getContent();
        if (!is_string($html) || str_contains($html, '<operia-assistant') || ($fin = strripos($html, '</body>')) === false) {
            return $respuesta;
        }
        $extra = Boton::html();
        if ($extra !== '') {
            $respuesta->setContent(substr($html, 0, $fin) . $extra . substr($html, $fin));
        }

        return $respuesta;
    }
}
