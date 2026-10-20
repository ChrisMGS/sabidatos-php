<?php

declare(strict_types=1);

namespace Trelintech\Sabidatos\Laravel\Http;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Trelintech\Sabidatos\Cliente;
use Trelintech\Sabidatos\Config;
use Trelintech\Sabidatos\Identidad;
use Trelintech\Sabidatos\Laravel\Boton;
use Trelintech\Sabidatos\Puerta;
use Trelintech\Sabidatos\Resultado;

/**
 * Las tres rutas del botón. Quién es el usuario y qué roles tiene salen de la
 * sesión de ESTE sistema; el navegador sólo manda la pregunta.
 */
final class SabidatosController extends Controller
{
    public const ADAPTADOR = 'sabidatos-laravel/' . Config::VERSION;
    // Una respuesta puede tardar minutos y ocupa un proceso de PHP mientras
    // dura: una persona, una pregunta a la vez. Sin esto, abrir muchas agota
    // los procesos del servidor (sobre todo en hosting compartido).
    private const SEGUNDOS_BLOQUEO = 1800;

    public function chat(Request $r): Response
    {
        return $this->atender($r, function (Puerta $p, ?Identidad $u, string $c) use ($r) {
            $resultado = $p->chat($u, $c, $this->autorizador());
            if ($u === null || !$resultado->esFlujo()) {
                return $resultado;
            }
            $cerrojo = $this->cerrojo($u);
            if ($cerrojo === false) {
                return Resultado::json(429, ['message' => 'Ya tienes una pregunta en curso. Espera a que termine.'],
                    ['Retry-After' => '10']);
            }

            return $cerrojo === null ? $resultado : Resultado::flujo(function (callable $alEmpezar, callable $alTrozo) use ($resultado, $cerrojo) {
                try {
                    return $resultado->emitir($alEmpezar, $alTrozo);
                } finally {
                    $cerrojo->release();
                }
            });
        });
    }

    public function conversaciones(Request $r): Response
    {
        return $this->atender($r, static fn (Puerta $p, ?Identidad $u, string $c) => $p->conversaciones($u, $c));
    }

    public function mensajes(Request $r): Response
    {
        return $this->atender($r, static fn (Puerta $p, ?Identidad $u, string $c) => $p->mensajes($u, $c));
    }

    public function componente(): Response
    {
        if (!self::activo()) {
            abort(404);
        }

        return response()->file(Boton::ARCHIVO, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    public static function activo(): bool
    {
        return filter_var(config('sabidatos.enabled'), FILTER_VALIDATE_BOOLEAN);
    }

    private function atender(Request $r, callable $f): Response
    {
        // Con SABIDATOS_ENABLED=false, nada: también si las rutas quedaron en la
        // caché de rutas de cuando estaba activo (§21.14).
        if (!self::activo()) {
            abort(404);
        }
        $config = self::config();
        if ($config->problemas()) {
            Log::warning('sabidatos: configuración incompleta (php artisan sabidatos:doctor)');

            return self::json(Resultado::json(503, ['message' => Puerta::AVISO_FALLO]));
        }
        $propios = [$r->getSchemeAndHttpHost()];
        $url = parse_url((string) config('app.url'));
        if (isset($url['scheme'], $url['host'])) {
            $propios[] = $url['scheme'] . '://' . $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
        }
        $rechazo = Puerta::mismoSitio((string) $r->header('Content-Type', ''), $r->header('Origin'),
            $r->header('Sec-Fetch-Site'), $propios);
        if ($rechazo) {
            return self::json($rechazo);
        }
        $puerta = new Puerta(new Cliente($config, self::ADAPTADOR),
            static fn (string $detalle) => Log::warning("sabidatos: {$detalle}"));
        $resultado = $f($puerta, $this->identidad(), $r->getContent());

        return $resultado->esFlujo() ? new RespuestaEnFlujo($resultado) : self::json($resultado);
    }

    /** El cerrojo de la persona; false si ya tiene una pregunta en curso; null si la caché no los admite. */
    private function cerrojo(Identidad $u): mixed
    {
        try {
            $c = Cache::lock('sabidatos:chat:' . hash('sha256', $u->id), self::SEGUNDOS_BLOQUEO);

            return $c->get() ? $c : false;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function config(): Config
    {
        return Config::desde(['enabled' => config('sabidatos.enabled'), 'url' => config('sabidatos.url'),
            'key_id' => config('sabidatos.key_id'), 'secret' => config('sabidatos.secret')]);
    }

    public static function json(Resultado $r): JsonResponse
    {
        return new JsonResponse($r->datos, $r->codigo, $r->cabeceras, JSON_UNESCAPED_UNICODE);
    }

    private function identidad(): ?Identidad
    {
        $u = Auth::guard(config('sabidatos.guard'))->user();
        if (!$u) {
            return null;
        }
        $identidad = new Identidad($u->getAuthIdentifier(), self::rolesDe($u));
        if ($identidad->recortados > 0) {
            // Sabidatos admite 10 roles por persona: el que da acceso podría quedar fuera.
            Log::warning("sabidatos: un usuario tiene {$identidad->recortados} roles de más; se envían los 10 primeros");
        }

        return $identidad;
    }

    /** @return iterable<mixed> */
    public static function rolesDe(Authenticatable $u): iterable
    {
        $propia = config('sabidatos.roles');
        if (is_string($propia) && class_exists($propia)) {
            return app($propia)($u);
        }
        if (method_exists($u, 'getRoleNames')) {
            return $u->getRoleNames()->all();
        }

        return [];
    }

    /** El registro de la pantalla, autorizado con la Policy del propio sistema. */
    private function autorizador(): ?callable
    {
        $mapa = (array) config('sabidatos.recursos', []);
        if (!$mapa) {
            return null;
        }
        $u = Auth::guard(config('sabidatos.guard'))->user();

        return static function (string $tipo, string $id) use ($mapa, $u): string {
            $clase = $mapa[$tipo] ?? null;
            if (!is_string($clase) || !class_exists($clase) || $u === null) {
                return 'omitir';
            }
            $modelo = new $clase();
            // El id tiene que ser exactamente el del registro. Con claves
            // numéricas, «583abc» o «1e3» se convertirían en otro número en la
            // base, y se autorizaría un registro distinto del que viaja.
            if (in_array($modelo->getKeyType(), ['int', 'integer'], true) && !ctype_digit($id)) {
                return 'no_existe';
            }
            $registro = $clase::query()->find($id);
            if ($registro === null || (string) $registro->getKey() !== $id) {
                return 'no_existe';
            }

            return Gate::forUser($u)->allows('view', $registro) ? 'permitido' : 'sin_acceso';
        };
    }
}
