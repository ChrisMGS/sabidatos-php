<?php

declare(strict_types=1);

namespace Trelintech\Sabidatos\Tests;

use PHPUnit\Framework\TestCase;
use Trelintech\Sabidatos\Firma;

/**
 * La firma tiene que salir idéntica, byte a byte, a la de Sabidatos
 * (app/connectors/signing.py). Los valores esperados se calcularon allí.
 */
final class FirmaTest extends TestCase
{
    public function testCoincideConSabidatosEnUnPostConCuerpo(): void
    {
        $c = Firma::cabeceras(str_repeat('a', 64), 'svc_ejemplo_k1', 'POST', '/embedded/v2/chat',
            '{"message":"hola"}', 1790000000, '00112233445566778899aabbccddeeff');
        $this->assertSame('v1=c29e966863d22d79257318776993dddbc09bc2d10862dd8d55e80d95a02379df', $c['X-Operia-Signature']);
        $this->assertSame('svc_ejemplo_k1', $c['X-Operia-Key-Id']);
        $this->assertSame('1790000000', $c['X-Operia-Timestamp']);
        $this->assertSame('00112233445566778899aabbccddeeff', $c['X-Operia-Nonce']);
    }

    public function testCoincideConSabidatosEnUnGetSinCuerpo(): void
    {
        $c = Firma::cabeceras(str_repeat('b', 64), 'svc_ejemplo_k1', 'get', '/embedded/v2/health',
            '', 1790000001, 'ffeeddccbbaa99887766554433221100');
        $this->assertSame('v1=ab7b9b536c411216e0dc20173e1214baae812d160250e1ad04c2bb6ba9a5fac7', $c['X-Operia-Signature']);
    }

    public function testCadaPeticionLlevaUnNonceDistinto(): void
    {
        $a = Firma::cabeceras('s', 'k', 'GET', '/x');
        $b = Firma::cabeceras('s', 'k', 'GET', '/x');
        $this->assertNotSame($a['X-Operia-Nonce'], $b['X-Operia-Nonce']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $a['X-Operia-Nonce']);
    }
}
