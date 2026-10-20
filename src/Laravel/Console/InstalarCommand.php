<?php

declare(strict_types=1);

namespace Trelintech\Sabidatos\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Trelintech\Sabidatos\Instalador;
use Trelintech\Sabidatos\Laravel\Http\SabidatosController;

/**
 * php artisan sabidatos:install OPIA-XXXX-XXXX-XXXX-XXXX
 *
 * Enseña lo que va a hacer, pide confirmación, canjea el código, escribe el
 * .env (con copia en storage/), manda los NOMBRES de los roles y comprueba la
 * conexión. Con --simular sólo enseña el plan.
 */
final class InstalarCommand extends Command
{
    protected $signature = 'sabidatos:install
        {codigo : el código de instalación del portal de Sabidatos}
        {--simular : sólo enseñar lo que se haría}
        {--si : no preguntar; para instalar desde un script}
        {--reemplazar : reinstalar aunque ya haya variables de Sabidatos}
        {--url= : dirección de Sabidatos, si no es la de siempre}';

    protected $description = 'Instala el asistente de Sabidatos con el código de un solo uso del portal';

    public function handle(): int
    {
        $instalador = new Instalador($this->laravel->environmentFilePath(),
            (string) ($this->option('url') ?: Instalador::URL_POR_DEFECTO), storage_path('app/sabidatos'));

        $this->info('Esto es lo que se va a hacer:');
        foreach ($instalador->plan() as $paso) {
            $this->line("  - {$paso}");
        }
        $this->line('  - Enviar a Sabidatos los NOMBRES de los roles de este sistema (sin usuarios ni datos)');
        $this->line('  - Comprobar la conexión');
        if ($this->option('simular')) {
            $this->warn('Simulación: no se ha cambiado nada.');

            return self::SUCCESS;
        }
        $reemplazar = (bool) $this->option('reemplazar');
        if ($instalador->existentes() && !$reemplazar) {
            $this->error('Ya está instalado. Para reinstalar: añade --reemplazar.');

            return self::FAILURE;
        }
        if (!$this->option('si') && !$this->confirm('¿Continuar?', true)) {
            $this->warn('No se ha cambiado nada.');

            return self::FAILURE;
        }

        try {
            $r = $instalador->aplicar((string) $this->argument('codigo'), SabidatosController::ADAPTADOR, $reemplazar);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Conectado' . ($r['cliente'] ? " como {$r['cliente']}" : '') . '.');
        if ($r['copia']) {
            $this->line("  Copia del .env anterior: {$r['copia']}");
        }

        // La configuración nueva, ya, y las cachés al día: con la caché de
        // rutas, las del botón no existirían hasta volver a generarla.
        $cacheada = $this->laravel->configurationIsCached();
        Artisan::call('config:clear');
        config(['sabidatos.enabled' => true, 'sabidatos.url' => $r['config']->url,
            'sabidatos.key_id' => $r['config']->keyId, 'sabidatos.secret' => $r['config']->secreto]);
        if ($cacheada) {
            Artisan::call('config:cache');
        }
        if ($this->laravel->routesAreCached()) {
            Artisan::call('route:cache');
        }

        try {
            $this->call('sabidatos:roles');
        } catch (\Throwable $e) {
            $this->warn('  No se pudieron enviar los roles (' . $e::class . '); aparecerán la primera vez que alguien pregunte.');
        }
        $codigo = $this->call('sabidatos:doctor');
        if ($codigo === self::SUCCESS) {
            $this->info('Siguiente paso, en el portal de Sabidatos: marca qué puede hacer cada rol.');
            $this->line('Mientras no se marque nada, el asistente responde «No tienes acceso a esa información.»');
        }

        return $codigo;
    }
}
