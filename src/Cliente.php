<?php

declare(strict_types=1);

namespace Trelintech\Sabidatos;

/**
 * Las peticiones de este servidor a Sabidatos, firmadas. Sin dependencias: curl.
 *
 * El certificado de Sabidatos se verifica siempre. No hay opción para apagarlo.
 */
final class Cliente
{
    private const CONEXION_S = 10;
    private const CORTA_S = 30;
    // Una respuesta del modelo puede tardar; el contrato corta a los 30 minutos.
    private const LARGA_S = 1800;

    public function __construct(private readonly Config $config, private readonly string $adaptador = 'sabidatos-php/' . Config::VERSION)
    {
    }

    /**
     * Una petición firmada con respuesta JSON.
     *
     * @param array<string, mixed>|null $datos
     * @return array{0: int, 1: string, 2: array<string, string>}  código, cuerpo, cabeceras
     */
    public function pedir(string $metodo, string $ruta, ?array $datos = null, ?string $nonce = null): array
    {
        $cuerpo = $datos === null ? '' : json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $cab = Firma::cabeceras($this->config->secreto, $this->config->keyId, $metodo, $this->rutaFirmada($ruta), $cuerpo,
            null, $nonce);

        return $this->http($metodo, $this->config->url . $ruta, $cuerpo, $cab + ($datos === null ? [] : ['Content-Type' => 'application/json']), self::CORTA_S);
    }

    /**
     * El chat: si Sabidatos responde 200, cada trozo va a `$alTrozo` según llega.
     * Si no, se devuelve el cuerpo del error sin haber escrito nada.
     *
     * @param array<string, mixed> $datos
     * @param callable(string): void $alTrozo
     * @return array{0: int, 1: string, 2: array<string, string>}
     */
    public function chat(array $datos, callable $alTrozo): array
    {
        $cuerpo = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $cab = Firma::cabeceras($this->config->secreto, $this->config->keyId, 'POST', $this->rutaFirmada('/chat'), $cuerpo);

        return $this->http('POST', $this->config->url . '/chat', $cuerpo, $cab + ['Content-Type' => 'application/json'],
            self::LARGA_S, $alTrozo);
    }

    /**
     * El canje del código de instalación. Sin firma: el código es la credencial.
     *
     * @return array{0: int, 1: string, 2: array<string, string>}
     */
    public static function canjear(string $url, string $codigo, string $adaptador): array
    {
        $cuerpo = json_encode(['code' => $codigo, 'adapter' => $adaptador], JSON_THROW_ON_ERROR);

        return (new self(new Config(true, rtrim($url, '/'), '', ''), $adaptador))
            ->http('POST', rtrim($url, '/') . '/install/claim', $cuerpo, ['Content-Type' => 'application/json'], self::CORTA_S);
    }

    /** La ruta tal como la ve Sabidatos, que es la que se firma: /embedded/v2/chat. */
    private function rutaFirmada(string $ruta): string
    {
        return rtrim((string) parse_url($this->config->url, PHP_URL_PATH), '/') . $ruta;
    }

    /**
     * @param array<string, string> $cabeceras
     * @return array{0: int, 1: string, 2: array<string, string>}
     */
    private function http(string $metodo, string $url, string $cuerpo, array $cabeceras, int $limite, ?callable $alTrozo = null): array
    {
        $cabeceras['X-Operia-Adapter'] = $this->adaptador;
        $cabeceras['Accept'] = $alTrozo ? 'text/event-stream' : 'application/json';
        $recibidas = [];
        $codigo = 0;
        $guardado = '';
        $c = curl_init($url);
        curl_setopt_array($c, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_HTTPHEADER => array_map(static fn ($k, $v) => "$k: $v", array_keys($cabeceras), $cabeceras),
            CURLOPT_CONNECTTIMEOUT => self::CONEXION_S,
            CURLOPT_TIMEOUT => $limite,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($c, string $linea) use (&$recibidas, &$codigo): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linea, $m)) {
                    $codigo = (int) $m[1];
                    $recibidas = [];
                } elseif (str_contains($linea, ':')) {
                    [$k, $v] = explode(':', $linea, 2);
                    $recibidas[strtolower(trim($k))] = trim($v);
                }

                return strlen($linea);
            },
            CURLOPT_WRITEFUNCTION => static function ($c, string $trozo) use (&$guardado, &$codigo, $alTrozo): int {
                if ($alTrozo !== null && $codigo === 200) {
                    $alTrozo($trozo);
                } elseif (strlen($guardado) < 1_048_576) {
                    $guardado .= $trozo;
                }

                return strlen($trozo);
            },
        ]);
        if ($metodo !== 'GET' && $cuerpo !== '') {
            curl_setopt($c, CURLOPT_POSTFIELDS, $cuerpo);
        }
        $ok = curl_exec($c);
        // El código de la respuesta final, no el de un CONNECT de proxy ni un 100.
        $final = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        if ($ok === false || curl_errno($c) !== 0) {
            $error = curl_error($c);
            curl_close($c);
            // Si el flujo ya había empezado y se corta, lo que llegó, llegó.
            return $codigo === 200 && $alTrozo !== null && $final === 200 ? [200, '', $recibidas] : [0, $error, []];
        }
        curl_close($c);
        $codigo = $final ?: $codigo;

        return [$codigo, $guardado, $recibidas];
    }
}
