<?php

namespace App\Console\Commands;

use App\Importacion\EscanearFuente;
use App\Importacion\ExtraerDuracion;
use App\Importacion\Lectores\LectorLocal;
use App\Models\Fuente;
use App\Models\Pista;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Completa las duraciones que falten, leyendo encabezados.
 *
 * Va por tandas y se puede volver a correr: cada pista se marca apenas se
 * resuelve, así que si el proceso muere a la mitad, relanzarlo retoma donde
 * quedó en vez de empezar de cero.
 */
class ExtraerDuraciones extends Command
{
    protected $signature = 'dharma:duraciones
        {--limite=200 : Cuántas pistas leer en esta tanda}
        {--paralelo=4 : Cuántas lecturas simultáneas contra la nube}
        {--todas : Releer también las que ya tienen duración}
        {--igual : Correr aunque la fuente sea una carpeta local (baja los archivos enteros)}';

    protected $description = 'Lee la duración real de cada pista desde el encabezado, sin descargar los audios';

    public function handle(ExtraerDuracion $extraer, EscanearFuente $escanear): int
    {
        $pistas = Pista::query()
            ->with('serie.fuente')
            ->where('en_nube', true)
            ->when(! $this->option('todas'), fn ($q) => $q->whereNull('duracion_revisada_en'))
            ->orderBy('id')
            ->limit((int) $this->option('limite'))
            ->get();

        if ($pistas->isEmpty()) {
            $this->info('No queda ninguna pista sin duración.');

            return self::SUCCESS;
        }

        if (! $this->option('igual') && $this->hayFuenteLocal($pistas, $escanear)) {
            /*
             * En la carpeta que sincroniza Windows los archivos son marcadores
             * de "Archivos a pedido": leerles el primer byte descarga el archivo
             * ENTERO. Sobre la biblioteca real eso son 50 GB y horas de espera,
             * sin avisar. Contra una fuente rclone se leen sólo 400 KB.
             */
            $this->error('Esta tanda incluye una fuente local, y leer sus encabezados descargaría los archivos completos (~50 GB).');
            $this->line('Corré esto en el servidor, contra una fuente de rclone. Si igual querés hacerlo acá, agregá --igual.');

            return self::FAILURE;
        }

        $this->info("Leyendo {$pistas->count()} encabezados de a {$this->option('paralelo')}…");

        $resultado = $extraer->deLote($pistas, (int) $this->option('paralelo'));

        $faltan = Pista::where('en_nube', true)->whereNull('duracion_revisada_en')->count();

        $this->newLine();
        $this->info("Duraciones nuevas: {$resultado['con']} · sin resolver: {$resultado['sin']} · quedan por revisar: {$faltan}");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Pista>  $pistas
     */
    private function hayFuenteLocal(Collection $pistas, EscanearFuente $escanear): bool
    {
        $ids = $pistas->map(fn (Pista $p) => $p->serie->fuente_id)->unique()->all();

        return Fuente::findMany($ids)
            ->contains(fn (Fuente $f) => $escanear->lectorPara($f) instanceof LectorLocal);
    }
}
