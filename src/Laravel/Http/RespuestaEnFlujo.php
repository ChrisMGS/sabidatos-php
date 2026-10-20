<?php

declare(strict_types=1);

namespace Trelintech\Sabidatos\Laravel\Http;

use Symfony\Component\HttpFoundation\Response;
use Trelintech\Sabidatos\Puerta;
use Trelintech\Sabidatos\Resultado;

/**
 * La respuesta del chat. No es un StreamedResponse porque éste manda un 200
 * antes de saber qué contesta Sabidatos: si Sabidatos dice 403 o 429, el botón tiene
 * que recibir ese código, no un 200 vacío. Aquí las cabeceras salen justo
 * antes del primer trozo, o con el error si no llega ninguno.
 */
final class RespuestaEnFlujo extends Response
{
    public function __construct(private readonly Resultado $resultado)
    {
        parent::__construct('', 200);
    }

    public function send(bool $flush = true): static
    {
        // Una respuesta del modelo puede durar más que el límite de PHP.
        @set_time_limit(0);
        $empezado = false;
        $error = $this->resultado->emitir(function () use (&$empezado): void {
            $empezado = true;
            foreach ($this->resultado->cabeceras as $k => $v) {
                $this->headers->set($k, $v);
            }
            $this->setStatusCode(200);
            $this->sendHeaders();
            while (ob_get_level() > 0) {
                // Un búfer que no se deja cerrar (lo pone el hosting) haría que el bucle no terminara nunca.
                if (!@ob_end_flush()) {
                    break;
                }
            }
        }, static function (string $trozo): void {
            echo $trozo;
            flush();
        });

        if (!$empezado) {
            $e = $error ?? Resultado::json(502, ['message' => Puerta::AVISO_FALLO]);
            $this->setStatusCode($e->codigo);
            foreach ($e->cabeceras as $k => $v) {
                $this->headers->set($k, $v);
            }
            $this->headers->set('Content-Type', 'application/json');
            $this->setContent((string) json_encode($e->datos, JSON_UNESCAPED_UNICODE));
            $this->sendHeaders();
            $this->sendContent();
        }
        if (\function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        return $this;
    }
}
