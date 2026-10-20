<?php

declare(strict_types=1);

namespace Trelintech\Sabidatos;

/**
 * Quién pregunta, según la sesión del sistema del cliente. Nunca según el
 * navegador.
 *
 * Sabidatos acepta ids de hasta 64 caracteres de un alfabeto corto. Un id que no
 * encaja (con espacios, largo…) se sustituye por su huella, «h-…»: sigue siendo
 * estable para esa persona y no dice quién es.
 *
 * Los roles son los códigos del sistema (`almacen`, `super-admin`). Un nombre
 * con espacios o tildes («Compras y Ventas») se convierte en un código
 * (`Compras-y-Ventas`): el mismo que se envía al instalar (`codigo()`), así que
 * en el portal aparece igual. Sabidatos decide con ellos qué puede usar cada
 * persona; el sistema sigue decidiendo qué registros ve.
 *
 * Van como mucho 10: más, y Sabidatos rechaza la petición. Si una persona tiene
 * más, se envían los 10 primeros y `recortados` dice cuántos se quedaron fuera.
 */
final class Identidad
{
    public const MAX_ROLES = 10;

    public readonly string $id;
    /** @var list<string> */
    public readonly array $roles;
    public readonly int $recortados;

    /** @param iterable<mixed> $roles */
    public function __construct(string|int $id, iterable $roles)
    {
        $id = (string) $id;
        // La huella lleva un prefijo que ningún id propio puede tener: si no, alguien
        // podría elegir como id la huella del de otra persona y compartir su historial.
        $this->id = preg_match('/^[A-Za-z0-9._@-]{1,64}$/D', $id) && !str_starts_with($id, 'h-')
            ? $id : 'h-' . substr(hash('sha256', $id), 0, 32);
        $lista = [];
        foreach ($roles as $r) {
            // Siempre texto: un rol «1» es el código "1", no el número 1.
            $codigo = is_string($r) || is_int($r) ? self::codigo((string) $r) : null;
            if ($codigo !== null && !in_array($codigo, $lista, true)) {
                $lista[] = $codigo;
            }
        }
        $this->roles = array_slice($lista, 0, self::MAX_ROLES);
        $this->recortados = max(0, count($lista) - self::MAX_ROLES);
    }

    /** El código de un rol a partir de su nombre, o null si no queda nada. */
    public static function codigo(string $nombre): ?string
    {
        $t = trim($nombre);
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $t)) {
            return $t;
        }
        if (class_exists(\Normalizer::class)) {
            $t = (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($t, \Normalizer::FORM_D));
        } else {
            $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
                'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
        }
        $t = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $t), '-');
        $t = substr($t, 0, 64);

        return $t === '' ? null : $t;
    }
}
