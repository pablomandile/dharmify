<?php

namespace App\Console\Commands;

use App\Importacion\GenerarPortada;
use App\Models\Serie;
use Illuminate\Console\Command;
use Throwable;

/**
 * Dibuja carátulas para las series que no tienen ninguna imagen.
 *
 * No toca la nube: es todo GD, así que corre en segundos y se puede repetir sin
 * costo cada vez que cambie un título.
 */
class GenerarPortadas extends Command
{
    protected $signature = 'dharma:portadas-genericas
        {--rehacer : Redibujar también las que ya tienen una generada}';

    protected $description = 'Dibuja una carátula para cada serie que no tiene ninguna imagen propia';

    public function handle(GenerarPortada $generar): int
    {
        $series = Serie::query()
            ->with('maestros')
            ->has('pistas')
            /*
             * Nunca las que tienen una imagen de verdad ni las subidas a mano.
             * Con `--rehacer` entran también las generadas, que es lo que hace
             * falta cuando se cambia el diseño o se corrige un título.
             */
            ->where(function ($q) {
                $q->whereNull('portada');

                if ($this->option('rehacer')) {
                    $q->orWhere('portada_origen', Serie::PORTADA_GENERADA);
                }
            })
            ->orderBy('id')
            ->get();

        if ($series->isEmpty()) {
            $this->info('Todas las series ya tienen carátula.');

            return self::SUCCESS;
        }

        $hechas = 0;

        foreach ($series as $serie) {
            try {
                $generar($serie);
                $hechas++;
            } catch (Throwable $e) {
                $this->line("  ✗ {$serie->titulo}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Carátulas dibujadas: {$hechas} de {$series->count()}.");

        return self::SUCCESS;
    }
}
