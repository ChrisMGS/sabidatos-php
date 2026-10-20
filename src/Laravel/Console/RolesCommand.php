<?php

declare(strict_types=1);

namespace Trelintech\Sabidatos\Laravel\Console;

use Illuminate\Console\Command;
use Trelintech\Sabidatos\Cliente;
use Trelintech\Sabidatos\Identidad;
use Trelintech\Sabidatos\Laravel\Http\SabidatosController;

/**
 * php artisan sabidatos:roles — manda a Sabidatos los NOMBRES de los roles de este
 * sistema, para marcar en el portal qué puede hacer cada uno. Ningún usuario,
 * ningún dato. Los roles entran sin permisos.
 */
final class RolesCommand extends Command
{
    protected $signature = 'sabidatos:roles';

    protected $description = 'Envía a Sabidatos los nombres de los roles de este sistema (entran sin permisos)';

    public function handle(): int
    {
        $roles = [];
        if (class_exists(\Spatie\Permission\Models\Role::class)) {
            foreach (\Spatie\Permission\Models\Role::query()->pluck('name') as $nombre) {
                // El mismo código que viajará en cada pregunta (Identidad::codigo),
                // y el nombre tal cual para que en el portal se lea bien.
                $codigo = is_string($nombre) ? Identidad::codigo($nombre) : null;
                if ($codigo === null) {
                    $this->warn("  Se omite el rol «{$nombre}»: no queda ningún carácter válido para su código.");
                    continue;
                }
                $roles[$codigo] = ['code' => $codigo, 'name' => mb_substr((string) $nombre, 0, 120)];
            }
        }
        if (!$roles) {
            $this->line('  Sin lista de roles que enviar: aparecerán en el portal la primera vez que alguien pregunte.');

            return self::SUCCESS;
        }
        [$codigo, $texto] = (new Cliente(SabidatosController::config(), SabidatosController::ADAPTADOR))
            ->pedir('PUT', '/roles', ['roles' => array_values(array_slice($roles, 0, 200))]);
        if ($codigo !== 200) {
            $this->error("  No se pudieron enviar los roles (HTTP {$codigo}).");

            return self::FAILURE;
        }
        $nuevos = (int) (json_decode($texto, true)['new'] ?? 0);
        $this->info(sprintf('  %d roles enviados, %d nuevos. Entran sin permisos.', count($roles), $nuevos));

        return self::SUCCESS;
    }
}
