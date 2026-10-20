<?php

declare(strict_types=1);

namespace Trelintech\Sabidatos\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Se registra solo (auto-discovery de Laravel). Con SABIDATOS_ENABLED=false no
 * registra rutas ni toca ninguna página: el sistema queda como antes de
 * instalar. Los comandos sí están siempre, para poder instalar y diagnosticar.
 */
final class SabidatosServiceProvider extends ServiceProvider
{
    /** Si este proceso arrancó con el asistente activo y registró sus rutas. */
    public static bool $rutas = false;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/sabidatos.php', 'sabidatos');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([Console\InstalarCommand::class, Console\DiagnosticoCommand::class,
                Console\RolesCommand::class, Console\DesinstalarCommand::class]);
            $this->publishes([__DIR__ . '/../../config/sabidatos.php' => config_path('sabidatos.php')], 'sabidatos-config');
        }

        Blade::directive('sabidatos', static fn () => '<?php echo \\Trelintech\\Sabidatos\\Laravel\\Boton::html(); ?>');

        if (!filter_var(config('sabidatos.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $ruta = trim((string) config('sabidatos.ruta', 'api/sabidatos'), '/');
        // `web`: la sesión y el CSRF del propio sistema. Sin `auth`: una sesión
        // caducada responde 401 en JSON, no una redirección al login.
        Route::middleware('web')->prefix($ruta)->group(static function (): void {
            Route::post('chat', [Http\SabidatosController::class, 'chat'])->name('sabidatos.chat');
            Route::post('conversations', [Http\SabidatosController::class, 'conversaciones'])->name('sabidatos.conversaciones');
            Route::post('conversations/messages', [Http\SabidatosController::class, 'mensajes'])->name('sabidatos.mensajes');
        });
        // El archivo del botón, servido desde ESTE dominio (nunca desde Sabidatos).
        Route::get('sabidatos/operia-assistant.js', [Http\SabidatosController::class, 'componente'])->name('sabidatos.componente');
        self::$rutas = true;

        // Sin sesión, el CSRF de Laravel corta antes con un 419. El botón
        // necesita un 401 para decir «Tu sesión caducó» en vez de un error
        // genérico (guía 23 §5). Sólo en las rutas del botón.
        $this->callAfterResolving(ExceptionHandler::class, static function ($handler): void {
            if (!method_exists($handler, 'renderable')) {
                return;
            }
            $handler->renderable(static function (HttpException $e, $request) {
                if ($e->getStatusCode() === 419 && $request->routeIs('sabidatos.chat', 'sabidatos.conversaciones', 'sabidatos.mensajes')) {
                    return new JsonResponse(['message' => 'Tu sesión caducó. Vuelve a entrar.'], 401,
                        ['Cache-Control' => 'no-store'], JSON_UNESCAPED_UNICODE);
                }

                return null;
            });
        });

        if (config('sabidatos.inyectar', true)) {
            $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', Http\InyectarBoton::class);
        }
    }
}
