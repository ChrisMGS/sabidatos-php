<?php

declare(strict_types=1);

namespace Trelintech\Sabidatos\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Trelintech\Sabidatos\Instalador;

/**
 * php artisan sabidatos:uninstall — quita las cuatro variables de Sabidatos del .env
 * (con copia en storage/). El sistema queda como antes de instalar. No toca
 * ningún dato. Para cortar también la clave en Sabidatos: «Revocar la conexión»
 * en el portal.
 */
final class DesinstalarCommand extends Command
{
    protected $signature = 'sabidatos:uninstall {--si : no preguntar}';

    protected $description = 'Apaga el asistente de Sabidatos y quita sus variables del .env (con copia)';

    public function handle(): int
    {
        $instalador = new Instalador($this->laravel->environmentFilePath(), Instalador::URL_POR_DEFECTO, storage_path('app/sabidatos'));
        if (!$instalador->existentes()) {
            $this->info('No hay variables de Sabidatos en el .env: nada que quitar.');

            return self::SUCCESS;
        }
        if (!$this->option('si') && !$this->confirm('¿Quitar las variables de Sabidatos del .env?', true)) {
            return self::FAILURE;
        }
        try {
            $copia = $instalador->desinstalar();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $cacheada = $this->laravel->configurationIsCached();
        Artisan::call('config:clear');
        if ($cacheada) {
            Artisan::call('config:cache');
        }
        if ($this->laravel->routesAreCached()) {
            Artisan::call('route:cache');
        }
        $this->info('Asistente apagado; el sistema queda como antes de instalar.');
        if ($copia) {
            $this->line("  Copia del .env anterior: {$copia}");
        }
        $this->line('Para cortar también la clave: «Revocar la conexión» en el portal de Sabidatos.');

        return self::SUCCESS;
    }
}
