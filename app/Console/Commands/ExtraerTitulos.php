<?php

namespace App\Console\Commands;

use App\Importacion\ExtraerTitulo;
use App\Models\Serie;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Usa la etiqueta de álbum del primer audio como título de la serie.
 *
 * Por omisión sólo MUESTRA lo que cambiaría. Reescribir los títulos de la
 * biblioteca a ciegas es la clase de cosa que se nota tarde y a mano; con
 * `--aplicar` se confirma después de mirar la lista.
 */
class ExtraerTitulos extends Command
{
    protected $signature = 'dharma:titulos
        {--umbral=3 : Desde cuántas series un mismo álbum se toma como nombre de colección y se descarta}
        {--paralelo=3 : Cuántas lecturas simultáneas contra la nube}
        {--aplicar : Guardar los cambios en vez de sólo mostrarlos}';

    protected $description = 'Toma el título de cada serie de la etiqueta de álbum de su primer audio';

    public function handle(ExtraerTitulo $extraer): int
    {
        /*
         * Se leen TODAS de una y no por tandas: la regla que descarta los
         * nombres de colección necesita contar cuántas series comparten cada
         * álbum, y eso no se puede saber mirando de a veinte.
         */
        $series = Serie::query()
            ->has('pistas')
            ->where('editada_a_mano', false)
            ->orderBy('id')
            ->get();

        if ($series->isEmpty()) {
            $this->info('No hay series para revisar.');

            return self::SUCCESS;
        }

        $this->info("Leyendo el encabezado del primer audio de {$series->count()} series…");

        $albumes = $extraer->deLote($series, (int) $this->option('paralelo'));

        $this->newLine();

        return $this->decidir($series, $albumes);
    }

    /**
     * @param  Collection<int, Serie>  $series
     * @param  array<int, string>  $albumes
     */
    private function decidir(Collection $series, array $albumes): int
    {
        $umbral = max(2, (int) $this->option('umbral'));

        /*
         * Un álbum que se repite en varias series no es el nombre de ninguna:
         * es el de la colección entera. Pasó de verdad —una serie de Tantra con
         * el álbum "Programa General"— y sin esta regla el título bueno se
         * habría perdido.
         *
         * Se cuenta en vez de tener una lista de nombres prohibidos porque la
         * lista habría que adivinarla, y los datos ya saben cuáles son.
         */
        $repetidos = collect($albumes)
            ->countBy()
            ->filter(fn (int $veces) => $veces >= $umbral);

        $cambios = 0;
        $iguales = 0;
        $descartados = 0;

        foreach ($series as $serie) {
            $album = $albumes[$serie->id] ?? null;

            if ($album === null) {
                continue;
            }

            if ($repetidos->has($album)) {
                $descartados++;

                continue;
            }

            if ($this->mismoTexto($album, $serie->titulo)) {
                $iguales++;

                continue;
            }

            $cambios++;
            $this->line("  «{$serie->titulo}»");
            $this->line("    → «{$album}»");

            if ($this->option('aplicar')) {
                $serie->forceFill([
                    'titulo' => $album,
                    'titulo_origen' => Serie::TITULO_ETIQUETA,
                ])->save();
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Con álbum: %d de %d · ya coincidían: %d · cambian: %d · descartados por repetirse: %d',
            count($albumes), $series->count(), $iguales, $cambios, $descartados,
        ));

        if ($repetidos->isNotEmpty()) {
            $this->line('  Álbumes tomados como nombre de colección: '
                .$repetidos->map(fn (int $n, string $a) => "«{$a}» ({$n})")->implode(', '));
        }

        if (! $this->option('aplicar') && $cambios > 0) {
            $this->newLine();
            $this->warn('Nada se guardó. Volvé a correrlo con --aplicar si la lista está bien.');
        }

        return self::SUCCESS;
    }

    /**
     * Compara sin acentos ni mayúsculas.
     *
     * "Tango emoción y meditación" y "Tango Emoción y Meditación" son el mismo
     * título con otra capitalización: cambiarlo sería ruido en la lista y una
     * escritura al pedo.
     */
    private function mismoTexto(string $a, string $b): bool
    {
        $limpiar = fn (string $t) => mb_strtolower(
            preg_replace('/\s+/u', ' ', trim(
                (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t),
            )) ?? '',
        );

        return $limpiar($a) === $limpiar($b);
    }
}
