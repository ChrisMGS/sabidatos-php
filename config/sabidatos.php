<?php

// Configuración del asistente de Sabidatos. Las cuatro primeras las escribe
// `php artisan sabidatos:install`; el resto tiene valores que sirven sin tocarlos.

return [
    // Con false, el paquete no registra rutas ni toca ninguna página: el
    // sistema se comporta exactamente como antes de instalarlo.
    'enabled' => env('SABIDATOS_ENABLED', false),
    'url' => env('SABIDATOS_URL', 'https://blackfrankia.com/embedded/v2'),
    'key_id' => env('SABIDATOS_KEY_ID', ''),
    'secret' => env('SABIDATOS_SECRET', ''),

    // Dónde habla el botón con este servidor. Mismo dominio, con la sesión de
    // siempre: el navegador nunca habla con Sabidatos.
    'ruta' => 'api/sabidatos',

    // Guard de la sesión (null = el de por defecto).
    'guard' => null,

    // El título del panel del asistente.
    'titulo' => env('SABIDATOS_TITULO', 'Asistente'),

    // true: el botón aparece solo en todas las páginas de quien tiene sesión.
    // false: se pone a mano con @sabidatos en la plantilla.
    'inyectar' => true,

    // Roles de cada usuario. null: los de spatie/laravel-permission si está
    // instalado. Si no, una clase invocable: fn (Authenticatable $u): iterable.
    'roles' => null,

    // Registros de pantalla que el asistente puede recibir, con su modelo. Se
    // autorizan con la Policy del modelo («view») del usuario de la sesión:
    // quien no puede verlo en el sistema, tampoco se lo puede preguntar.
    //     'production_order' => App\Models\ProductionOrder::class,
    'recursos' => [],
];
