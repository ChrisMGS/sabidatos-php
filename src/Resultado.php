<?php

declare(strict_types=1);

namespace Trelintech\Sabidatos;

/**
 * Lo que devuelve la puerta: o un JSON con su código, o un flujo.
 *
 * El flujo no fija el código de antemano: se sabe cuando Sabidatos contesta. Por
 * eso quien lo emite llama a `$alEmpezar` (enviar 200 y las cabeceras del
 * flujo) justo antes del primer trozo, y si Sabidatos falla antes de empezar,
 * recibe un Resultado JSON con el error en lugar de nada.
 */
final class Resultado
{
    /**
     * @param array<string, mixed>|null $datos
     * @param array<string, string> $cabeceras
     */
    private function __construct(
        public readonly int $codigo,
        public readonly ?array $datos,
        public readonly array $cabeceras,
        private readonly ?\Closure $flujo,
    ) {
    }

    /**
     * @param array<string, mixed> $datos
     * @param array<string, string> $cabeceras
     */
    public static function json(int $codigo, array $datos, array $cabeceras = []): self
    {
        return new self($codigo, $datos, $cabeceras + ['Cache-Control' => 'no-store'], null);
    }

    public static function flujo(\Closure $flujo): self
    {
        return new self(200, null, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ], $flujo);
    }

    public function esFlujo(): bool
    {
        return $this->flujo !== null;
    }

    /**
     * @param callable(): void $alEmpezar
     * @param callable(string): void $alTrozo
     */
    public function emitir(callable $alEmpezar, callable $alTrozo): ?self
    {
        return ($this->flujo)($alEmpezar, $alTrozo);
    }
}
