<?php

declare(strict_types=1);

namespace Trelintech\Sabidatos;

/**
 * Las tres rutas que el sistema del cliente ofrece a SU navegador, y que
 * reenvían a Sabidatos (contrato 22 §2 y guía 23 §5):
 *
 *     POST {ruta}/chat                     { message, conversation_id?, context? }
 *     POST {ruta}/conversations            { }
 *     POST {ruta}/conversations/messages   { conversation_id }
 *
 * Sin framework: recibe lo que ya sabe el sistema (quién es el usuario y sus
 * roles, de SU sesión) y el cuerpo tal como llegó. La capa de Laravel, o un
 * archivo PHP de 20 líneas, sólo la conectan.
 *
 * Lo que decide aquí, y por qué:
 *   - El navegador no manda identidad: usuario, roles, cliente, agente... se
 *     rechazan (422), no se ignoran (MANDATORY §21.16).
 *   - El usuario y sus roles salen de la sesión del sistema, nunca del cuerpo.
 *   - El registro de la pantalla sólo viaja si el sistema confirma que existe y
 *     que ESTE usuario puede verlo. Sin quien lo compruebe, no viaja.
 *   - Los fallos de Sabidatos llegan al usuario como una frase; el detalle, al log.
 */
final class Puerta
{
    public const AVISO_FALLO = 'No pude contactar con el asistente. Inténtalo más tarde.';
    private const NO_VALIDA = 'Petición no válida.';
    private const RE_CONVERSACION = '/^[A-Za-z0-9_-]{8,64}$/D';
    private const RE_CONTEXTO = '/^[A-Za-z0-9._:-]{1,64}$/D';
    private const RE_PANTALLA = '/^[a-z_]{1,64}$/D';
    private const MAX_CUERPO = 32_000;

    /** @var (callable(string): void)|null */
    private $registrar;

    /** @param (callable(string): void)|null $registrar  a dónde va el detalle de los fallos */
    public function __construct(private readonly Cliente $cliente, ?callable $registrar = null)
    {
        $this->registrar = $registrar;
    }

    /**
     * CSRF para rutas que usan la cookie de sesión (guía 23 §5): sólo JSON y
     * sólo desde el mismo sitio. Null si pasa; si no, el rechazo.
     */
    /**
     * @param string|list<string> $propios  el origen del sistema. Detrás de un proxy
     *        con TLS, el que ve PHP puede ser http://: por eso se admite también el
     *        de su configuración (en Laravel, APP_URL).
     */
    public static function mismoSitio(string $tipo, ?string $origen, ?string $secFetchSite, string|array $propios): ?Resultado
    {
        if (!str_starts_with(strtolower($tipo), 'application/json')) {
            return Resultado::json(415, ['message' => self::NO_VALIDA]);
        }
        $propios = array_map(static fn ($o) => rtrim((string) $o, '/'), (array) $propios);
        if (($secFetchSite !== null && $secFetchSite !== '' && $secFetchSite !== 'same-origin')
            || ($origen !== null && $origen !== '' && !in_array(rtrim($origen, '/'), $propios, true))) {
            return Resultado::json(403, ['message' => self::NO_VALIDA]);
        }

        return null;
    }

    /**
     * @param (callable(string, string): string)|null $autorizar
     *        (tipo, id) → 'permitido' | 'no_existe' | 'sin_acceso' | 'omitir'.
     *        Sin él, el registro de la pantalla no se envía.
     */
    public function chat(?Identidad $u, string $crudo, ?callable $autorizar = null): Resultado
    {
        if ($u === null) {
            return Resultado::json(401, ['message' => 'Tu sesión caducó. Vuelve a entrar.']);
        }
        $datos = $this->leer($crudo);
        if ($datos === null || array_diff(array_keys($datos), ['message', 'conversation_id', 'context'])) {
            return Resultado::json(422, ['message' => self::NO_VALIDA]);
        }
        $mensaje = $datos['message'] ?? null;
        if (!is_string($mensaje) || trim($mensaje) === '') {
            return Resultado::json(422, ['message' => 'Escribe una pregunta.']);
        }

        $contexto = $datos['context'] ?? [];
        if (!is_array($contexto) || array_diff(array_keys($contexto), ['screen', 'resource_type', 'resource_id'])) {
            return Resultado::json(422, ['message' => self::NO_VALIDA]);
        }
        $normalizado = [];
        if (isset($contexto['resource_id']) || isset($contexto['resource_type'])) {
            $tipo = $contexto['resource_type'] ?? '';
            $id = $contexto['resource_id'] ?? '';
            if (!is_string($tipo) || !(is_string($id) || is_int($id))) {
                return Resultado::json(422, ['message' => self::NO_VALIDA]);
            }
            $id = (string) $id;
            if (!preg_match(self::RE_CONTEXTO, $tipo) || !preg_match(self::RE_CONTEXTO, $id)) {
                return Resultado::json(422, ['message' => self::NO_VALIDA]);
            }
            $veredicto = $autorizar !== null ? $autorizar($tipo, $id) : 'omitir';
            // 'omitir': el sistema no sabe comprobar ese tipo de registro. No
            // viaja, y la pregunta sigue sin él.
            if ($veredicto !== 'omitir') {
                if ($veredicto === 'no_existe') {
                    return Resultado::json(404, ['message' => 'No encontramos ese registro.']);
                }
                if ($veredicto !== 'permitido') {
                    return Resultado::json(403, ['message' => 'No tienes acceso a ese registro.']);
                }
                $normalizado = ['resource_type' => $tipo, 'resource_id' => $id];
            }
        }
        if (isset($contexto['screen']) && is_string($contexto['screen']) && preg_match(self::RE_PANTALLA, $contexto['screen'])) {
            $normalizado['screen'] = $contexto['screen'];
        }

        $conversacion = $datos['conversation_id'] ?? null;
        if ($conversacion !== null && (!is_string($conversacion) || !preg_match(self::RE_CONVERSACION, $conversacion))) {
            return Resultado::json(422, ['message' => self::NO_VALIDA]);
        }

        $cuerpo = ['external_user_id' => $u->id, 'erp_roles' => $u->roles, 'message' => $mensaje];
        if ($normalizado) {
            $cuerpo['context'] = $normalizado;
        }
        if ($conversacion !== null) {
            $cuerpo['conversation_id'] = $conversacion;
        }

        return Resultado::flujo(function (callable $alEmpezar, callable $alTrozo) use ($cuerpo): ?Resultado {
            $empezado = false;
            [$codigo, $error, $cab] = $this->cliente->chat($cuerpo, function (string $trozo) use (&$empezado, $alEmpezar, $alTrozo): void {
                if (!$empezado) {
                    $empezado = true;
                    $alEmpezar();
                }
                $alTrozo($trozo);
            });
            if ($codigo === 200) {
                return null;
            }

            return $this->fallo($codigo, $error, $cab);
        });
    }

    public function conversaciones(?Identidad $u, string $crudo): Resultado
    {
        return $this->historial($u, $crudo, false);
    }

    public function mensajes(?Identidad $u, string $crudo): Resultado
    {
        return $this->historial($u, $crudo, true);
    }

    /** El historial, siempre del usuario de SU sesión: el navegador sólo elige qué conversación. */
    private function historial(?Identidad $u, string $crudo, bool $conConversacion): Resultado
    {
        if ($u === null) {
            return Resultado::json(401, ['message' => 'Tu sesión caducó. Vuelve a entrar.']);
        }
        $datos = $this->leer($crudo);
        $esperados = $conConversacion ? ['conversation_id'] : [];
        if ($datos === null || array_diff(array_keys($datos), $esperados)) {
            return Resultado::json(422, ['message' => self::NO_VALIDA]);
        }
        $cuerpo = ['external_user_id' => $u->id, 'erp_roles' => $u->roles];
        if ($conConversacion) {
            $conv = $datos['conversation_id'] ?? null;
            if (!is_string($conv) || !preg_match(self::RE_CONVERSACION, $conv)) {
                return Resultado::json(422, ['message' => self::NO_VALIDA]);
            }
            $cuerpo['conversation_id'] = $conv;
        }
        [$codigo, $texto, $cab] = $this->cliente->pedir('POST', $conConversacion ? '/conversations/messages' : '/conversations', $cuerpo);
        if ($codigo === 200) {
            $json = json_decode($texto, true);

            return is_array($json) ? Resultado::json(200, $json) : Resultado::json(502, ['message' => self::AVISO_FALLO]);
        }

        return $this->fallo($codigo, $texto, $cab);
    }

    /**
     * El código real, al log. Al usuario, lo que el componente necesita
     * distinguir: 403, 404, 429 y 503 con la frase de Sabidatos; 401 y 422 son
     * fallos de ESTE servidor (firma, cuerpo), así que 502 y una frase
     * genérica. Sin respuesta de Sabidatos, 503.
     *
     * @param array<string, string> $cab
     */
    private function fallo(int $codigo, string $texto, array $cab): Resultado
    {
        if ($this->registrar) {
            ($this->registrar)("Sabidatos respondió {$codigo}");
        }
        if (in_array($codigo, [403, 404, 429, 503], true)) {
            $frase = json_decode($texto, true)['message'] ?? null;
            $extra = $codigo === 429 ? ['Retry-After' => $cab['retry-after'] ?? '60'] : [];

            return Resultado::json($codigo, ['message' => is_string($frase) ? $frase : self::AVISO_FALLO], $extra);
        }

        return Resultado::json($codigo === 0 ? 503 : 502, ['message' => self::AVISO_FALLO]);
    }

    /** @return array<string, mixed>|null */
    private function leer(string $crudo): ?array
    {
        if (strlen($crudo) > self::MAX_CUERPO) {
            return null;
        }
        try {
            $datos = json_decode($crudo === '' ? '{}' : $crudo, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        // Un objeto JSON. «[]» también se decodifica como array vacío: sólo vale
        // si el texto era «{}» o vacío.
        if ($datos === []) {
            return trim($crudo) === '[]' ? null : [];
        }

        return is_array($datos) && !array_is_list($datos) ? $datos : null;
    }
}
