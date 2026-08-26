<?php

namespace App\Console\Commands;

use App\Importacion\EscanearFuente;
use App\Importacion\ExtraerTranscripcion;
use App\Models\Fuente;
use App\Models\Pista;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Trae las transcripciones que ya viven al lado de los audios en la nube.
 *
 * Por omisión sólo MUESTRA qué documento se asociaría a qué audio. Escribir 709
 * filas a ciegas es la clase de cosa que se nota tarde: si el cruce por nombre
 * fallara, quedarían cientos de enseñanzas con el texto de otra.
 */
class ExtraerTranscripciones extends Command
{
    protected $signature = 'dharma:transcripciones
        {--limite=200 : Cuántas pistas se miran por corrida}
        {--paralelo=3 : Cuántas lecturas simultáneas contra la nube}
        {--todas : Incluye las que ya se revisaron antes}
        {--aplicar : Guardar los cambios en vez de sólo mostrarlos}';

    protected $description = 'Asocia a cada audio el documento de transcripción que tiene al lado';

    public function handle(ExtraerTranscripcion $extraer, EscanearFuente $escanear): int
    {
        $pistas = $this->pistas();

        if ($pistas->isEmpty()) {
            $this->info('No hay pistas para revisar.');

            return self::SUCCESS;
        }

        $total = 0;

        foreach ($pistas->groupBy('serie.fuente_id') as $fuenteId => $deLaFuente) {
            $fuente = Fuente::find($fuenteId);

            if (! $fuente instanceof Fuente) {
                continue;
            }

            $total += $this->deUnaFuente($extraer, $escanear, $fuente, $deLaFuente);
        }

        $this->newLine();

        if (! $this->option('aplicar') && $total > 0) {
            $this->warn('Nada se guardó. Volvé a correrlo con --aplicar si la lista está bien.');
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, Pista> */
    private function pistas(): Collection
    {
        return Pista::query()
            ->with('serie:id,fuente_id,titulo')
            ->when(
                ! $this->option('todas'),
                fn ($q) => $q->whereNull('transcripcion_revisada_en'),
            )
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limite')))
            ->get();
    }

    /**
     * @param  Collection<int, Pista>  $pistas
     */
    private function deUnaFuente(
        ExtraerTranscripcion $extraer,
        EscanearFuente $escanear,
        Fuente $fuente,
        Collection $pistas,
    ): int {
        $this->info("Listando los documentos de «{$fuente->nombre}»…");

        $candidatos = $extraer->candidatosDe($fuente);

        $this->line('  '.count($candidatos).' documentos en la fuente.');

        /** @var array<string, Pista> $porRuta */
        $porRuta = [];
        $sinDocumento = 0;

        foreach ($pistas as $pista) {
            $doc = $candidatos[ExtraerTranscripcion::claveDe($pista->ruta)] ?? null;

            if ($doc === null) {
                $sinDocumento++;

                // Se marca igual: si no, vuelve a la cola en cada corrida.
                if ($this->option('aplicar')) {
                    $extraer->marcarRevisada($pista);
                }

                continue;
            }

            $porRuta[$doc->ruta] = $pista;
            $this->line("  «{$pista->titulo}»");
            $this->line("    → {$doc->nombre}");
        }

        $this->newLine();
        $this->info(sprintf(
            'Con documento: %d de %d · sin documento: %d',
            count($porRuta), $pistas->count(), $sinDocumento,
        ));

        if (! $this->option('aplicar') || $porRuta === []) {
            return count($porRuta);
        }

        $this->newLine();
        $this->info('Trayendo los documentos…');

        $guardadas = 0;
        $barra = $this->output->createProgressBar(count($porRuta));

        $escanear->lectorPara($fuente)->cabeceras(
            $fuente->ruta,
            array_keys($porRuta),
            function (string $ruta, ?string $bytes) use ($porRuta, $extraer, &$guardadas, $barra) {
                $pista = $porRuta[$ruta] ?? null;

                if ($pista instanceof Pista) {
                    if ($bytes !== null && $bytes !== '') {
                        $extraer->guardar($pista, basename($ruta), $bytes, 'nube');
                        $guardadas++;
                    }

                    /*
                     * Se marca aunque la lectura haya vuelto vacía. Reintentar
                     * para siempre un archivo que la nube no entrega es cómo se
                     * traba un barrido de 709.
                     */
                    $extraer->marcarRevisada($pista);
                }

                $barra->advance();
            },
            ExtraerTranscripcion::TAMANIO,
            (int) $this->option('paralelo'),
        );

        $barra->finish();
        $this->newLine(2);
        $this->info("Guardadas: {$guardadas}.");

        return count($porRuta);
    }
}
