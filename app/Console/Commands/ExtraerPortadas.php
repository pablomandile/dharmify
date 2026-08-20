<?php

namespace App\Console\Commands;

use App\Importacion\ArchivoEnLaFuente;
use App\Importacion\EscanearFuente;
use App\Importacion\ExtraerPortada;
use App\Models\Fuente;
use App\Models\Serie;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
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
    /** Las que conviven con los mp3 en las carpetas de los eventos. */
    private const IMAGENES = ['jpg', 'jpeg', 'png', 'webp'];

    protected $signature = 'dharma:portadas
        {--limite=40 : Cuántas series procesar en esta tanda}
        {--todas : Rehacer también las que ya tienen carátula}
        {--sin-portada : Reintentar sólo las que se revisaron y quedaron sin imagen}';

    protected $description = 'Busca la carátula de cada serie: el flyer de la carpeta o la imagen embebida en los audios';

    public function handle(ExtraerPortada $extraer, EscanearFuente $escanear): int
    {
        $series = Serie::query()
            ->with('fuente')
            /*
             * Tres modos: lo que falta revisar (lo normal), todo de nuevo, o
             * sólo lo que se revisó y no dio imagen. El tercero existe porque el
             * primer barrido pedía 400 KB de encabezado y las carátulas grandes
             * no entraban: esas series quedaron marcadas y sin imagen, y hay que
             * poder volver sobre ellas sin releer las 145.
             */
            ->when(
                $this->option('sin-portada'),
                fn ($q) => $q->whereNotNull('portada_revisada_en')
                    ->where(fn ($q) => $q->whereNull('portada')->orWhere('portada_origen', Serie::PORTADA_GENERADA)),
            )
            ->when(
                ! $this->option('todas') && ! $this->option('sin-portada'),
                fn ($q) => $q->whereNull('portada_revisada_en'),
            )
            /*
             * Lo que subió una persona no lo pisa ningún barrido, en ningún
             * modo. Es la única imagen que alguien eligió a propósito.
             */
            ->where(fn ($q) => $q->whereNull('portada_origen')->orWhere('portada_origen', '!=', Serie::PORTADA_MANUAL))
            ->has('pistas')
            ->orderBy($this->option('sin-portada') ? 'portada_revisada_en' : 'id')
            ->limit((int) $this->option('limite'))
            ->get();

        if ($series->isEmpty()) {
            $this->info('No queda ninguna serie sin carátula.');

            return self::SUCCESS;
        }

        $imagenes = $this->imagenesPorCarpeta($series, $escanear);

        $con = 0;
        $sin = 0;

        foreach ($series as $serie) {
            try {
                if ($extraer($serie, $imagenes[$serie->fuente_id][$serie->carpeta] ?? [])) {
                    $con++;
                    $this->line("  ✓ {$serie->titulo}");
                } else {
                    $sin++;
                    $this->line("  · sin imagen en ninguna parte: {$serie->titulo}");
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

    /**
     * Las imágenes sueltas de cada carpeta, listadas UNA vez por fuente.
     *
     * Preguntarle a la nube carpeta por carpeta serían 145 llamadas de diez
     * segundos cada una; un solo listado recursivo trae los 1.751 archivos en
     * unos segundos. Por eso el listado vive acá y no dentro del extractor.
     *
     * @param  Collection<int, Serie>  $series
     * @return array<int, array<string, list<ArchivoEnLaFuente>>> fuente → carpeta → imágenes
     */
    private function imagenesPorCarpeta(Collection $series, EscanearFuente $escanear): array
    {
        $porFuente = [];

        foreach (Fuente::findMany($series->pluck('fuente_id')->unique()->all()) as $fuente) {
            $encontradas = [];

            try {
                foreach ($escanear->lectorPara($fuente)->listar($fuente->ruta, self::IMAGENES) as $imagen) {
                    $encontradas[$imagen->serie()][] = $imagen;
                }
            } catch (Throwable $e) {
                // Que no se pueda listar no es motivo para no mirar dentro de
                // los audios, que es el otro camino.
                $this->warn("No pude listar las imágenes de «{$fuente->nombre}»: {$e->getMessage()}");
            }

            $porFuente[$fuente->id] = $encontradas;
        }

        return $porFuente;
    }
}
