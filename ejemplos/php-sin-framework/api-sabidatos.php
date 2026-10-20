<?php

// Ejemplo: las tres rutas del asistente en un sistema PHP sin framework.
//
// Poner este archivo de modo que responda en /api/sabidatos/chat,
// /api/sabidatos/conversations y /api/sabidatos/conversations/messages (por ejemplo
// con una regla del servidor web hacia él), y el botón en la plantilla:
//
//     <operia-assistant endpoint="/api/sabidatos" heading="Asistente"></operia-assistant>
//     <script src="/sabidatos/operia-assistant.js" integrity="(recursos/operia-assistant.js.sri)"
//             crossorigin="anonymous"></script>
//
// Lo único que cambia de un sistema a otro son las TRES respuestas marcadas:
// quién es el usuario, qué roles tiene y si puede ver un registro.

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php'; // el autoload de su proyecto

use Trelintech\Sabidatos\Cliente;
use Trelintech\Sabidatos\Config;
use Trelintech\Sabidatos\Identidad;
use Trelintech\Sabidatos\Puerta;

// La cookie de sesión, cerrada: sólo por https, sin acceso desde JavaScript y
// sin viajar en peticiones de otros sitios.
session_set_cookie_params(['secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

$config = Config::delEntorno();
if (!$config->activo) {
    http_response_code(404);
    exit;
}

// 1. ¿Quién es el usuario? De SU sesión, nunca del navegador.
$usuario = $_SESSION['usuario_id'] ?? null;
// 2. ¿Qué roles tiene en este sistema? Sus códigos.
$roles = $_SESSION['roles'] ?? [];
// Ya se leyó lo que hacía falta: se suelta la sesión. Si no, mientras el
// asistente responde, las demás páginas de esta persona se quedan esperando.
session_write_close();
// 3. ¿Puede ver este registro? Con las reglas de este sistema.
$autorizar = static function (string $tipo, string $id) use ($usuario): string {
    // p. ej.: if ($tipo === 'pedido') { return puede_ver_pedido($usuario, $id) ? 'permitido' : 'sin_acceso'; }
    return 'omitir';
};

$esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$propio = $esquema . '://' . ($_SERVER['HTTP_HOST'] ?? '');
$rechazo = $_SERVER['REQUEST_METHOD'] !== 'POST'
    ? \Trelintech\Sabidatos\Resultado::json(405, ['message' => 'Petición no válida.'])
    : Puerta::mismoSitio($_SERVER['CONTENT_TYPE'] ?? '', $_SERVER['HTTP_ORIGIN'] ?? null,
        $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null, $propio);

$u = $usuario !== null ? new Identidad($usuario, $roles) : null;
$puerta = new Puerta(new Cliente($config), static fn (string $d) => error_log("sabidatos: {$d}"));
$cuerpo = (string) file_get_contents('php://input');
$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

$resultado = $rechazo ?? match (true) {
    str_ends_with($ruta, '/conversations/messages') => $puerta->mensajes($u, $cuerpo),
    str_ends_with($ruta, '/conversations') => $puerta->conversaciones($u, $cuerpo),
    str_ends_with($ruta, '/chat') => $puerta->chat($u, $cuerpo, $autorizar),
    default => \Trelintech\Sabidatos\Resultado::json(404, ['message' => 'Petición no válida.']),
};

$enviarJson = static function ($r): void {
    http_response_code($r->codigo);
    header('Content-Type: application/json');
    foreach ($r->cabeceras as $k => $v) {
        header("{$k}: {$v}");
    }
    echo json_encode($r->datos, JSON_UNESCAPED_UNICODE);
};

if (!$resultado->esFlujo()) {
    $enviarJson($resultado);
    exit;
}
@set_time_limit(0);
$empezado = false;
$error = $resultado->emitir(static function () use (&$empezado, $resultado): void {
    $empezado = true;
    http_response_code(200);
    foreach ($resultado->cabeceras as $k => $v) {
        header("{$k}: {$v}");
    }
    while (ob_get_level() > 0 && @ob_end_flush()) {
    }
}, static function (string $trozo): void {
    echo $trozo;
    flush();
});
if (!$empezado) {
    $enviarJson($error ?? \Trelintech\Sabidatos\Resultado::json(502, ['message' => Puerta::AVISO_FALLO]));
}
