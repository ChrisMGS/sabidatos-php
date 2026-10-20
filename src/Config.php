<?php

declare(strict_types=1);

namespace Trelintech\Sabidatos;

/**
 * Las cuatro variables de la instalación. Las escribe `sabidatos install`; nadie
 * tiene que copiarlas a mano.
 *
 *     SABIDATOS_ENABLED   true | false. Con false, nada del paquete participa.
 *     SABIDATOS_URL       https://blackfrankia.com/embedded/v2
 *     SABIDATOS_KEY_ID    el identificador de la clave
 *     SABIDATOS_SECRET    el secreto; nunca sale del servidor
 */
final class Config
{
    public const VERSION = '0.1.0';
    // https, un dominio (y puerto), y una ruta simple. Sin usuario, sin
    // parámetros, sin fragmento: nada que desvíe o componga otra dirección.
    private const RE_URL = '#^https://[a-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~-]+)*$#D';

    public function __construct(
        public readonly bool $activo,
        public readonly string $url,
        public readonly string $keyId,
        #[\SensitiveParameter] public readonly string $secreto,
    ) {
    }

    /** @param array<string, mixed> $valores */
    public static function desde(#[\SensitiveParameter] array $valores): self
    {
        $activo = filter_var($valores['SABIDATOS_ENABLED'] ?? $valores['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return new self(
            $activo,
            rtrim((string) ($valores['SABIDATOS_URL'] ?? $valores['url'] ?? ''), '/'),
            (string) ($valores['SABIDATOS_KEY_ID'] ?? $valores['key_id'] ?? ''),
            (string) ($valores['SABIDATOS_SECRET'] ?? $valores['secret'] ?? ''),
        );
    }

    public static function delEntorno(): self
    {
        $leer = static fn (string $k) => $_ENV[$k] ?? $_SERVER[$k] ?? (getenv($k) === false ? null : getenv($k));

        return self::desde([
            'SABIDATOS_ENABLED' => $leer('SABIDATOS_ENABLED'),
            'SABIDATOS_URL' => $leer('SABIDATOS_URL'),
            'SABIDATOS_KEY_ID' => $leer('SABIDATOS_KEY_ID'),
            'SABIDATOS_SECRET' => $leer('SABIDATOS_SECRET'),
        ]);
    }

    public static function urlValida(string $url): bool
    {
        return (bool) preg_match(self::RE_URL, rtrim($url, '/'));
    }

    /** Lo que falta o está mal, en frases. Vacío = completa. @return list<string> */
    public function problemas(): array
    {
        $p = [];
        if (!self::urlValida($this->url)) {
            $p[] = 'SABIDATOS_URL tiene que ser https://dominio/ruta, sin nada más';
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_]{2,63}$/D', $this->keyId)) {
            $p[] = 'SABIDATOS_KEY_ID falta o no tiene el formato esperado';
        }
        if (!preg_match('/^[0-9a-f]{64}$/D', $this->secreto)) {
            $p[] = 'SABIDATOS_SECRET falta o no tiene el formato esperado';
        }

        return $p;
    }

    /** Para var_dump, dump() y trazas: el secreto no aparece. @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['activo' => $this->activo, 'url' => $this->url, 'keyId' => $this->keyId, 'secreto' => '(oculto)'];
    }
}
