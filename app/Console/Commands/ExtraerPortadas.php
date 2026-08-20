<?php

namespace App\Console\Commands;

use App\Importacion\ExtraerPortada;
use App\Models\Serie;
use Illuminate\Console\Command;
use Throwable;

/**
 * Saca las carátulas que falten.
 *
 * Va por tandas y se puede volver a correr: cada serie se marca apenas se
 * confirma, así que si el hosting mata el proceso a la mitad, relanzarlo retoma
 * donde quedó en vez de empezar de cero.
 */
class ExtraerPortadas extends Command
{
    protected $signature = 'dharma:portadas
        {--limite=40 : Cuántas series procesar en esta tanda}
        {--todas : Rehacer también las que ya tienen carátula}';

    protected $description = 'Extrae la carátula de cada serie desde el primer audio, sin descargar los archivos';

    public function handle(ExtraerPortada $extraer): int
    {
        $series = Serie::query()
            ->with('fuente')
            ->when(! $this->option('todas'), fn ($q) => $q->whereNull('portada_revisada_en'))
            ->has('pistas')
            ->orderBy('id')
            ->limit((int) $this->option('limite'))
            ->get();

        if ($series->isEmpty()) {
            $this->info('No queda ninguna serie sin carátula.');

            return self::SUCCESS;
        }

        $con = 0;
        $sin = 0;

        foreach ($series as $serie) {
            try {
                if ($extraer($serie)) {
                    $con++;
                    $this->line("  ✓ {$serie->titulo}");
                } else {
                    $sin++;
                    $this->line("  · sin carátula en el archivo: {$serie->titulo}");
                }
            } catch (Throwable $e) {
                $sin++;
                $this->line("  ✗ {$serie->titulo}: {$e->getMessage()}");
            }
        }

        $faltan = Serie::whereNull('portada_revisada_en')->has('pistas')->count();

        $this->newLine();
        $this->info("Carátulas nuevas: {$con} · sin imagen: {$sin} · quedan por revisar: {$faltan}");

        return self::SUCCESS;
    }
}
