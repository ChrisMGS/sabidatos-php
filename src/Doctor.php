<?php

declare(strict_types=1);

namespace Trelintech\Sabidatos;

/**
 * «¿Está bien instalado?», sin revisar seis cosas a mano (guía 19 §7).
 *
 * Sólo dos resultados: READY o NOT READY. Nunca «parece funcionar». Tres de las
 * comprobaciones son negativas a propósito: una firma mala y una petición
 * repetida TIENEN que rechazarse; si pasaran, la instalación sería peor que no
 * tenerla.
 */
final class Doctor
{
    /** @var list<array{0: string, 1: bool, 2: string}> */
    private array $filas = [];

    public function __construct(private readonly Config $config, private readonly string $adaptador = 'sabidatos-php/' . Config::VERSION)
    {
    }

    public function anotar(string $que, bool $ok, string $detalle = ''): void
    {
        $this->filas[] = [$que, $ok, $detalle];
    }

    /** @return list<array{0: string, 1: bool, 2: string}> */
    public function comprobar(): array
    {
        $this->anotar('SABIDATOS_ENABLED=true', $this->config->activo, $this->config->activo ? '' : 'el asistente está apagado');
        $problemas = $this->config->problemas();
        $this->anotar('variables completas', !$problemas, implode('; ', $problemas));
        if ($problemas) {
            return $this->filas;
        }

        $cliente = new Cliente($this->config, $this->adaptador);
        [$codigo, $texto, $cab] = $cliente->pedir('GET', '/health');
        $this->anotar('Sabidatos responde con la firma de esta instalación', $codigo === 200,
            match (true) {
                $codigo === 200 => '',
                $codigo === 0 => "sin conexión: {$texto}",
                $codigo === 401 => 'clave revocada o secreto incorrecto',
                default => "HTTP {$codigo}",
            });
        $contrato = $cab['x-sabidatos-contract'] ?? '';
        $this->anotar('contrato v2', $contrato === 'v2', $contrato === 'v2' ? '' : ($contrato ?: 'sin cabecera'));
        if (isset($cab['date']) && ($hora = strtotime($cab['date'])) !== false) {
            $desfase = abs(time() - $hora);
            $this->anotar('reloj en hora (menos de 60 s de desfase)', $desfase < 60, "{$desfase} s");
        }

        $falsa = new Cliente(new Config(true, $this->config->url, $this->config->keyId, str_repeat('0', 64)), $this->adaptador);
        [$codigo] = $falsa->pedir('GET', '/health');
        $this->anotar('una firma incorrecta se rechaza', $codigo === 401, "HTTP {$codigo}");

        $desconocida = new Cliente(new Config(true, $this->config->url, 'svc_desconocido_' . bin2hex(random_bytes(3)),
            $this->config->secreto), $this->adaptador);
        [$codigo] = $desconocida->pedir('GET', '/health');
        $this->anotar('un key id desconocido se rechaza', $codigo === 401, "HTTP {$codigo}");

        $nonce = bin2hex(random_bytes(16));
        $cliente->pedir('GET', '/health', null, $nonce);
        [$codigo] = $cliente->pedir('GET', '/health', null, $nonce);
        $this->anotar('una petición repetida se rechaza', $codigo === 401, "HTTP {$codigo}");

        return $this->filas;
    }

    /** @return list<array{0: string, 1: bool, 2: string}> */
    public function filas(): array
    {
        return $this->filas;
    }

    public function listo(): bool
    {
        return $this->filas !== [] && !in_array(false, array_column($this->filas, 1), true);
    }
}
