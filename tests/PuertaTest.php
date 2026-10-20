<?php

declare(strict_types=1);

namespace Trelintech\Sabidatos\Tests;

use PHPUnit\Framework\TestCase;
use Trelintech\Sabidatos\Cliente;
use Trelintech\Sabidatos\Config;
use Trelintech\Sabidatos\Identidad;
use Trelintech\Sabidatos\Instalador;
use Trelintech\Sabidatos\Puerta;

/**
 * Lo que la puerta decide ANTES de hablar con Sabidatos: sin red. El camino
 * completo se prueba contra Sabidatos real (tests de extremo a extremo).
 */
final class PuertaTest extends TestCase
{
    private function puerta(): Puerta
    {
        // Una dirección que no resuelve: si alguna prueba llegara a llamar, fallaría.
        return new Puerta(new Cliente(new Config(true, 'https://sabidatos.invalid/embedded/v2', 'svc_x_k1', str_repeat('0', 64))));
    }

    private function usuario(): Identidad
    {
        return new Identidad('ana', ['almacen']);
    }

    public function testSinSesion401(): void
    {
        $this->assertSame(401, $this->puerta()->chat(null, '{"message":"hola"}')->codigo);
        $this->assertSame(401, $this->puerta()->conversaciones(null, '{}')->codigo);
    }

    /** @dataProvider camposDeIdentidad */
    public function testElNavegadorNoMandaIdentidad(string $cuerpo): void
    {
        $this->assertSame(422, $this->puerta()->chat($this->usuario(), $cuerpo)->codigo);
    }

    /** @return array<string, array{string}> */
    public static function camposDeIdentidad(): array
    {
        return [
            'usuario' => ['{"message":"hola","external_user_id":"otro"}'],
            'roles' => ['{"message":"hola","erp_roles":["admin"]}'],
            'cliente' => ['{"message":"hola","tenant_id":"otro"}'],
            'agente' => ['{"message":"hola","agent":"x"}'],
            'sesion' => ['{"message":"hola","session_id":"x"}'],
        ];
    }

    public function testCuerposMalos(): void
    {
        $p = $this->puerta();
        $u = $this->usuario();
        $this->assertSame(422, $p->chat($u, 'no es json')->codigo);
        $this->assertSame(422, $p->chat($u, '["lista"]')->codigo);
        $this->assertSame(422, $p->chat($u, '{"message":"   "}')->codigo);
        $this->assertSame(422, $p->chat($u, '{"message":"hola","conversation_id":"corta"}')->codigo);
        $this->assertSame(422, $p->chat($u, '{"message":"hola","context":{"otro":1}}')->codigo);
        $this->assertSame(422, $p->chat($u, str_repeat(' ', 32_001))->codigo);
        $this->assertSame(422, $p->mensajes($u, '{"conversation_id":"../../x"}')->codigo);
        $this->assertSame(422, $p->conversaciones($u, '{"x":1}')->codigo);
        $this->assertSame(422, $p->conversaciones($u, '[]')->codigo);
        $this->assertSame(422, $p->chat($u, '{"message":"hola","context":{"resource_type":"pedido","resource_id":[1]}}')->codigo);
    }

    public function testElRegistroDeLaPantallaSeAutorizaConElSistema(): void
    {
        $p = $this->puerta();
        $u = $this->usuario();
        $cuerpo = '{"message":"hola","context":{"resource_type":"pedido","resource_id":"584"}}';
        $this->assertSame(404, $p->chat($u, $cuerpo, fn () => 'no_existe')->codigo);
        $this->assertSame(403, $p->chat($u, $cuerpo, fn () => 'sin_acceso')->codigo);
        $this->assertSame(403, $p->chat($u, $cuerpo, fn () => 'cualquier otra cosa')->codigo);
        // Permitido u omitido: la pregunta sigue (flujo), sin llamar todavía a Sabidatos.
        $this->assertTrue($p->chat($u, $cuerpo, fn () => 'permitido')->esFlujo());
        $this->assertTrue($p->chat($u, $cuerpo, fn () => 'omitir')->esFlujo());
        $this->assertTrue($p->chat($u, $cuerpo)->esFlujo());
        $this->assertSame(422, $p->chat($u, '{"message":"hola","context":{"resource_type":"pedido","resource_id":"a b"}}')->codigo);
    }

    public function testMismoSitio(): void
    {
        $propio = 'https://erp.ejemplo.com';
        $this->assertSame(415, Puerta::mismoSitio('text/plain', null, null, $propio)?->codigo);
        $this->assertSame(403, Puerta::mismoSitio('application/json', 'https://otra.com', null, $propio)?->codigo);
        $this->assertSame(403, Puerta::mismoSitio('application/json', null, 'cross-site', $propio)?->codigo);
        $this->assertNull(Puerta::mismoSitio('application/json; charset=utf-8', $propio, 'same-origin', $propio));
        $this->assertNull(Puerta::mismoSitio('application/json', null, null, $propio));
        $this->assertSame(403, Puerta::mismoSitio('application/json', null, 'none', $propio)?->codigo);
        // Detrás de un proxy con TLS: vale también el origen de la configuración.
        $this->assertNull(Puerta::mismoSitio('application/json', $propio, 'same-origin', ['http://erp.ejemplo.com', $propio]));
    }

    public function testIdentidad(): void
    {
        $u = new Identidad(42, ['b', 'a', 'a', '1', 7, ['x'], null]);
        $this->assertSame('42', $u->id);
        // Siempre texto, sin repetidos, en su orden: un rol «1» no se vuelve el número 1.
        $this->assertSame(['b', 'a', '1', '7'], $u->roles);
        $this->assertSame('{"r":["b","a","1","7"]}', json_encode(['r' => $u->roles]));
        // Nombres con espacios o tildes se convierten en código, como en el catálogo.
        $this->assertSame(['Compras-y-Ventas', 'Almacen'], (new Identidad('x', ['Compras y Ventas', 'Almacén']))->roles);
        $this->assertSame('Almacen', Identidad::codigo('Almacén'));
        $this->assertNull(Identidad::codigo(' !! '));
        $larga = new Identidad('correo con espacios@ejemplo.com', []);
        $this->assertMatchesRegularExpression('/^h-[0-9a-f]{32}$/', $larga->id);
        $this->assertNotSame('h-abc', (new Identidad('h-abc', []))->id);
        $muchos = new Identidad('x', array_map(fn ($i) => "r$i", range(1, 15)));
        $this->assertCount(Identidad::MAX_ROLES, $muchos->roles);
        $this->assertSame(5, $muchos->recortados);
    }

    public function testInstaladorNoTocaNadaSinCanjear(): void
    {
        $env = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($env, "APP_NAME=Ejemplo\nSABIDATOS_KEY_ID=svc_viejo_k1\n");
        $i = new Instalador($env);
        $this->assertSame(['SABIDATOS_KEY_ID'], $i->existentes());
        try {
            $i->aplicar('OPIA-0000-0000-0000-0000', 'prueba');
            $this->fail('Tenía que negarse: ya hay variables de Sabidatos');
        } catch (\RuntimeException) {
        }
        try {
            (new Instalador($env))->aplicar('no es un código', 'prueba', true);
            $this->fail('Tenía que rechazar el formato');
        } catch (\RuntimeException) {
        }
        try {
            (new Instalador($env, 'http://inseguro/embedded/v2'))->aplicar('OPIA-0000-0000-0000-0000', 'prueba', true);
            $this->fail('Tenía que exigir https');
        } catch (\RuntimeException) {
        }
        $this->assertSame("APP_NAME=Ejemplo\nSABIDATOS_KEY_ID=svc_viejo_k1\n", file_get_contents($env));
        unlink($env);
    }

    public function testConfig(): void
    {
        $bien = Config::desde(['SABIDATOS_ENABLED' => 'true', 'SABIDATOS_URL' => 'https://blackfrankia.com/embedded/v2/',
            'SABIDATOS_KEY_ID' => 'svc_x_k1', 'SABIDATOS_SECRET' => str_repeat('a', 64)]);
        $this->assertTrue($bien->activo);
        $this->assertSame('https://blackfrankia.com/embedded/v2', $bien->url);
        $this->assertSame([], $bien->problemas());
        $mal = Config::desde(['SABIDATOS_ENABLED' => 'false', 'SABIDATOS_URL' => 'http://x', 'SABIDATOS_SECRET' => 'corto']);
        $this->assertFalse($mal->activo);
        $this->assertCount(3, $mal->problemas());
    }
}
