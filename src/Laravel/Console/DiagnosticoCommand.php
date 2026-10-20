<?php

declare(strict_types=1);

namespace Trelintech\Sabidatos\Laravel\Console;

use Illuminate\Console\Command;
use Trelintech\Sabidatos\Doctor;
use Trelintech\Sabidatos\Laravel\Boton;
use Trelintech\Sabidatos\Laravel\Http\SabidatosController;

/**
 * php artisan sabidatos:doctor — READY o NOT READY, y qué revisar si falla.
 * No cambia nada.
 */
final class DiagnosticoCommand extends Command
{
    protected $signature = 'sabidatos:doctor';

    protected $description = 'Comprueba la instalación del asistente de Sabidatos. No cambia nada.';

    public function handle(): int
    {
        $doctor = new Doctor(SabidatosController::config(), SabidatosController::ADAPTADOR);
        $doctor->comprobar();

        $doctor->anotar('archivo del botón con su huella', is_file(Boton::ARCHIVO) && str_starts_with(Boton::huella(), 'sha384-'));
        $https = str_starts_with((string) config('app.url'), 'https://');
        $doctor->anotar('APP_URL con https', $https, $https ? '' : 'la sesión viajaría sin cifrar');
        $samesite = strtolower((string) config('session.same_site'));
        $doctor->anotar('cookie de sesión SameSite lax o strict', in_array($samesite, ['lax', 'strict'], true), $samesite ?: 'sin valor');
        $roles = config('sabidatos.roles') || class_exists(\Spatie\Permission\Models\Role::class);
        $doctor->anotar('de dónde salen los roles', $roles, $roles ? '' : 'ni spatie ni `sabidatos.roles`: nadie tendrá acceso');

        foreach ($doctor->filas() as [$que, $ok, $detalle]) {
            $this->line(sprintf('  %s  %s%s', $ok ? '<info>PASS</info>' : '<error>FAIL</error>', $que, $detalle ? "  — {$detalle}" : ''));
        }
        $this->line('');
        if ($doctor->listo()) {
            $this->info('READY');

            return self::SUCCESS;
        }
        $this->error('NOT READY');

        return self::FAILURE;
    }
}
