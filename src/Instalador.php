<?php

declare(strict_types=1);

namespace Trelintech\Sabidatos;

/**
 * Canjea el código de instalación y deja las cuatro variables en el `.env`.
 *
 * Dos reglas (guía 19 §8):
 *   - enseña lo que va a hacer ANTES de hacerlo (`plan`);
 *   - no sobrescribe nada en silencio: si ya hay variables de Sabidatos, sólo las
 *     reemplaza si se le pide expresamente, y siempre guarda una copia.
 *
 * Lo que escribe en el `.env` no lo decide la respuesta del servidor: la
 * dirección es la que se usó para canjear, y cada valor se comprueba carácter a
 * carácter. Una respuesta manipulada no puede meter otras variables, ni
 * `${...}`, ni desviar el tráfico a otro sitio.
 *
 * El secreto va de Sabidatos a este archivo sin pasar por la pantalla.
 */
final class Instalador
{
    public const URL_POR_DEFECTO = 'https://blackfrankia.com/embedded/v2';
    public const RE_CODIGO = '/^OPIA-[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{4}){3}$/D';
    private const VARIABLES = ['SABIDATOS_ENABLED', 'SABIDATOS_URL', 'SABIDATOS_KEY_ID', 'SABIDATOS_SECRET'];
    private const MARCA = '# Sabidatos: asistente. Lo escribió `sabidatos install`; el secreto no se comparte.';

    private readonly string $url;

    /**
     * @param string|null $dirCopias dónde dejar la copia del `.env` (Laravel: storage/). Por
     *                               defecto, junto al `.env`.
     */
    public function __construct(private readonly string $archivoEnv, string $url = self::URL_POR_DEFECTO,
                                private readonly ?string $dirCopias = null)
    {
        $this->url = rtrim($url, '/');
    }

    /** Las variables de Sabidatos que ya hay en el archivo. @return list<string> */
    public function existentes(): array
    {
        $texto = is_file($this->archivoEnv) ? (string) file_get_contents($this->archivoEnv) : '';

        return array_values(array_filter(self::VARIABLES, static fn (string $v) => (bool) preg_match(self::linea($v) . 'm', $texto)));
    }

    /** Lo que va a pasar, en frases. @return list<string> */
    public function plan(): array
    {
        $p = [];
        $p[] = is_file($this->archivoEnv)
            ? 'Guardar una copia de ' . $this->archivoEnv . ' en ' . ($this->dirCopias ?? dirname($this->archivoEnv))
            : "Crear {$this->archivoEnv}";
        $ya = $this->existentes();
        $p[] = $ya
            ? 'Reemplazar ' . implode(', ', $ya) . ' (ya estaban: esto es una reinstalación)'
            : 'Añadir ' . implode(', ', self::VARIABLES);
        $p[] = "Canjear el código en {$this->url}. Si ya había una clave, sigue valiendo hasta que se use la nueva";

        return $p;
    }

    /**
     * @return array{config: Config, cliente: string, copia: ?string}
     * @throws \RuntimeException con una frase para la persona
     */
    public function aplicar(string $codigo, string $adaptador, bool $reemplazar = false): array
    {
        $codigo = strtoupper(trim($codigo));
        if (!preg_match(self::RE_CODIGO, $codigo)) {
            throw new \RuntimeException('El código no tiene el formato OPIA-XXXX-XXXX-XXXX-XXXX.');
        }
        if (!Config::urlValida($this->url)) {
            throw new \RuntimeException('La dirección de Sabidatos no es válida: tiene que ser https://dominio/ruta, sin nada más.');
        }
        if ($this->existentes() && !$reemplazar) {
            throw new \RuntimeException('Ya hay variables de Sabidatos en ' . $this->archivoEnv
                . '. Para reinstalar, repite con la opción de reemplazar.');
        }
        // Todo lo que hace falta para escribir, comprobado ANTES de gastar el
        // código: después, un fallo dejaría el código usado y el secreto perdido.
        $destino = $this->destino();
        $this->comprobarEscritura($destino);

        [$codigoHttp, $texto] = Cliente::canjear($this->url, $codigo, $adaptador);
        $datos = json_decode($texto, true);
        if ($codigoHttp !== 200 || !is_array($datos)) {
            throw new \RuntimeException(match ($codigoHttp) {
                0 => 'No hay conexión con Sabidatos: ' . $texto,
                403 => 'El código no vale: ya se usó, caducó o no existe. Pide uno nuevo en el portal de Sabidatos.',
                415, 422 => 'Sabidatos no entendió la petición. Actualiza el paquete e inténtalo de nuevo.',
                429 => 'Demasiados intentos. Espera un minuto.',
                503 => 'Sabidatos no puede instalar ahora mismo. Inténtalo en unos minutos.',
                default => "Sabidatos respondió {$codigoHttp}.",
            });
        }
        // La dirección que se guarda es la que se usó para canjear. La que diga
        // la respuesta sólo se acepta si es exactamente la misma.
        $propuesta = rtrim((string) ($datos['base_url'] ?? $this->url), '/');
        $config = Config::desde([
            'SABIDATOS_ENABLED' => true,
            'SABIDATOS_URL' => $this->url,
            'SABIDATOS_KEY_ID' => (string) ($datos['key_id'] ?? ''),
            'SABIDATOS_SECRET' => (string) ($datos['secret'] ?? ''),
        ]);
        if ($propuesta !== $this->url || $config->problemas()) {
            throw new \RuntimeException('La respuesta de Sabidatos no es la esperada; no he cambiado nada. '
                . 'El código ya se usó: pide otro en el portal de Sabidatos.');
        }
        try {
            $copia = $this->escribir($destino, $config);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($e->getMessage() . ' El código ya se usó: pide otro en el portal de Sabidatos.', 0, $e);
        }

        return ['config' => $config, 'cliente' => (string) ($datos['client_name'] ?? ''), 'copia' => $copia];
    }

    /**
     * Quita las cuatro variables (con copia). Con eso el asistente queda apagado
     * y el sistema como antes de instalar. @return string|null la copia
     */
    public function desinstalar(): ?string
    {
        if (!$this->existentes()) {
            return null;
        }
        $destino = $this->destino();
        $this->comprobarEscritura($destino);

        return $this->escribir($destino, null);
    }

    /** El archivo real: si `.env` es un enlace (despliegues con `shared/.env`), se escribe su destino. */
    private function destino(): string
    {
        if (is_link($this->archivoEnv)) {
            $real = realpath($this->archivoEnv);
            if ($real === false) {
                throw new \RuntimeException("{$this->archivoEnv} es un enlace roto.");
            }

            return $real;
        }

        return $this->archivoEnv;
    }

    private function comprobarEscritura(string $destino): void
    {
        $dir = dirname($destino);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new \RuntimeException("No puedo escribir en la carpeta {$dir}.");
        }
        if (is_file($destino) && (!is_writable($destino) || !is_readable($destino))) {
            throw new \RuntimeException("No puedo leer y escribir {$destino}.");
        }
        $copias = $this->dirCopias ?? $dir;
        if (!is_dir($copias) && !@mkdir($copias, 0700, true)) {
            throw new \RuntimeException("No puedo crear la carpeta de copias {$copias}.");
        }
        if (!is_writable($copias)) {
            throw new \RuntimeException("No puedo escribir en la carpeta de copias {$copias}.");
        }
    }

    /** Escribe las cuatro variables (o las quita, con null). Copia primero; temporal y renombrado. */
    private function escribir(string $destino, ?Config $c): ?string
    {
        if ($c !== null) {
            foreach ([$c->url, $c->keyId, $c->secreto] as $valor) {
                // Nada que el lector del .env pueda interpretar: ni saltos de
                // línea, ni espacios, ni comillas, ni `$`, ni `#`.
                if (!preg_match('#^[A-Za-z0-9:/._~-]+$#D', $valor)) {
                    throw new \RuntimeException('Un valor para el .env tiene caracteres no válidos; no he cambiado nada.');
                }
            }
        }
        $previo = is_file($destino) ? (string) file_get_contents($destino) : '';
        $copia = $previo === '' ? null : $this->copiar($previo);

        $lineas = preg_split('/\R/', $previo) ?: [];
        $lineas = array_values(array_filter($lineas, static function (string $l): bool {
            foreach (self::VARIABLES as $v) {
                if (preg_match(self::linea($v), $l)) {
                    return false;
                }
            }

            return trim($l) !== self::MARCA;
        }));
        while ($lineas && trim((string) end($lineas)) === '') {
            array_pop($lineas);
        }
        if ($c !== null) {
            array_push($lineas, '', self::MARCA, 'SABIDATOS_ENABLED=true', 'SABIDATOS_URL=' . $c->url,
                'SABIDATOS_KEY_ID=' . $c->keyId, 'SABIDATOS_SECRET=' . $c->secreto);
        }

        $dir = dirname($destino);
        $temporal = tempnam($dir, '.sabidatos-');
        if ($temporal === false) {
            throw new \RuntimeException("No pude crear un temporal en {$dir}; no he cambiado nada.");
        }
        try {
            if (file_put_contents($temporal, implode(PHP_EOL, $lineas) . PHP_EOL, LOCK_EX) === false) {
                throw new \RuntimeException("No pude escribir {$destino}; no he cambiado nada.");
            }
            if (is_file($destino)) {
                @chmod($temporal, fileperms($destino) & 0777);
                // Si esto corre como root, el archivo sigue siendo de quien era:
                // si no, el servidor web dejaría de poder leerlo.
                if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                    @chown($temporal, fileowner($destino));
                    @chgrp($temporal, filegroup($destino));
                }
            } else {
                @chmod($temporal, 0600);
            }
            if (!@rename($temporal, $destino)) {
                throw new \RuntimeException("No pude reemplazar {$destino}; no he cambiado nada.");
            }
        } finally {
            if (is_file($temporal)) {
                @unlink($temporal);
            }
        }

        return $copia;
    }

    /** La copia del `.env`, creada ya con permisos 600 (nunca legible por otros ni un instante). */
    private function copiar(string $contenido): string
    {
        $dir = rtrim($this->dirCopias ?? dirname($this->archivoEnv), '/\\');
        $ruta = $dir . DIRECTORY_SEPARATOR . '.env.sabidatos-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3));
        $antes = umask(0077);
        try {
            $f = @fopen($ruta, 'x');
            if ($f === false || fwrite($f, $contenido) !== strlen($contenido)) {
                throw new \RuntimeException("No pude guardar la copia en {$dir}; no he cambiado nada.");
            }
            fclose($f);
        } finally {
            umask($antes);
        }

        return $ruta;
    }

    /** La línea de una variable, también con `export` delante. */
    private static function linea(string $variable): string
    {
        return '/^\s*(export\s+)?' . $variable . '\s*=/';
    }
}
