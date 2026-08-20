<?php

namespace App\Importacion;

use App\Importacion\Lectores\LectorDeFuente;
use App\Importacion\Lectores\LectorLocal;
use App\Importacion\Lectores\LectorRclone;
use App\Models\Fuente;
use App\Models\Maestro;
use App\Models\Pista;
use App\Models\Serie;
use Illuminate\Support\Str;

/**
 * Recorre una fuente y arma el catálogo, respetando la separación en carpetas
 * que ya existe en OneDrive: cada carpeta de primer nivel es una serie.
 *
 * Es idempotente por diseño. "Refrescar la biblioteca" se va a apretar muchas
 * veces, casi siempre para sumar unos pocos audios nuevos, y tiene que poder
 * correr sobre lo que ya está sin duplicar ni pisar correcciones a mano.
 */
class EscanearFuente
{
    /** @var array<string, int> */
    private array $resumen = [
        'archivos' => 0,
        'series_nuevas' => 0,
        'pistas_nuevas' => 0,
        'pistas_actualizadas' => 0,
        'desaparecidas' => 0,
    ];

    public function lectorPara(Fuente $fuente): LectorDeFuente
    {
        return $fuente->esLocal() ? new LectorLocal : new LectorRclone;
    }

    /**
     * @return array<string, int>
     */
    public function __invoke(Fuente $fuente): array
    {
        $lector = $this->lectorPara($fuente);

        if ($problema = $lector->verificar($fuente->ruta)) {
            throw new \RuntimeException($problema);
        }

        /** @var array<string, list<ArchivoDeAudio>> $porSerie */
        $porSerie = [];

        foreach ($lector->listar($fuente->ruta) as $archivo) {
            $this->resumen['archivos']++;
            $porSerie[$archivo->serie()][] = $archivo;
        }

        $clavesVistas = [];

        foreach ($porSerie as $carpeta => $archivos) {
            $serie = $this->serieDe($fuente, $carpeta);

            /*
             * Orden natural por ruta: "10 clase" tiene que ir después de
             * "9 clase", y un sort de texto los pone al revés.
             */
            usort($archivos, fn (ArchivoDeAudio $a, ArchivoDeAudio $b) => strnatcasecmp($a->ruta, $b->ruta));

            foreach ($archivos as $i => $archivo) {
                $clavesVistas[] = $this->pistaDe($fuente, $serie, $archivo, $i + 1);
            }
        }

        $this->marcarDesaparecidas($fuente, $clavesVistas);

        $fuente->forceFill(['revisada_en' => now()])->save();

        return $this->resumen;
    }

    private function serieDe(Fuente $fuente, string $carpeta): Serie
    {
        // Los archivos sueltos en la raíz no tienen carpeta propia: se agrupan
        // bajo el nombre de la fuente en vez de inventarles una serie.
        $nombre = $carpeta !== '' ? $carpeta : $fuente->nombre;
        $hash = Serie::hashDe($carpeta);

        $serie = Serie::query()
            ->where('fuente_id', $fuente->id)
            ->where('carpeta_hash', $hash)
            ->first();

        $taxonomia = TaxonomiaDeCarpeta::desde($nombre);
        $titulo = $taxonomia->titulo !== '' ? $taxonomia->titulo : $nombre;

        if (! $serie) {
            $serie = Serie::create([
                'fuente_id' => $fuente->id,
                'carpeta' => $carpeta,
                'carpeta_hash' => $hash,
                'titulo' => $titulo,
                'slug' => Str::slug($titulo).'-'.mb_substr($hash, 0, 6),
                'tipo' => $taxonomia->tipo,
                'anio' => $taxonomia->anio,
                'idioma' => $taxonomia->idioma,
            ]);

            $this->resumen['series_nuevas']++;
        } elseif (! $serie->editada_a_mano) {
            /*
             * Lo que el administrador corrigió a mano no se vuelve a pisar. El
             * parser resuelve bien la enorme mayoría, pero el resto se arregla
             * una vez y tiene que quedar así.
             */
            $serie->update([
                'titulo' => $titulo,
                'tipo' => $taxonomia->tipo,
                'anio' => $taxonomia->anio,
                'idioma' => $taxonomia->idioma,
            ]);
        }

        if (! $serie->editada_a_mano && $taxonomia->maestros !== []) {
            $serie->maestros()->sync(
                collect($taxonomia->maestros)
                    ->map(fn (string $nombre) => Maestro::porNombre($nombre)->id)
                    ->all(),
            );
        }

        return $serie;
    }

    private function pistaDe(Fuente $fuente, Serie $serie, ArchivoDeAudio $archivo, int $orden): string
    {
        $clave = Pista::claveDe($fuente->id, $archivo->ruta);

        $pista = Pista::query()->where('clave', $clave)->first();

        $datos = [
            'serie_id' => $serie->id,
            'titulo' => TituloDeArchivo::desde($archivo->nombre),
            'archivo' => $archivo->nombre,
            'ruta' => $archivo->ruta,
            'bytes' => $archivo->bytes,
            'orden' => $orden,
            'grabada_el' => TituloDeArchivo::fechaDe($archivo->nombre),
            'en_nube' => true,
        ];

        if (! $pista) {
            Pista::create($datos + ['clave' => $clave]);
            $this->resumen['pistas_nuevas']++;
        } else {
            $pista->fill($datos);

            if ($pista->isDirty()) {
                $pista->save();
                $this->resumen['pistas_actualizadas']++;
            }
        }

        return $clave;
    }

    /**
     * Lo que ya no está en la nube se marca, NO se borra.
     *
     * Si el archivo desapareció por un movimiento de carpetas o un error de red,
     * borrar la ficha se lleva puesto lo que el usuario haya escuchado o marcado.
     * Marcarlo deja el problema a la vista y es reversible.
     *
     * @param  list<string>  $clavesVistas
     */
    private function marcarDesaparecidas(Fuente $fuente, array $clavesVistas): void
    {
        $consulta = Pista::query()
            ->whereIn('serie_id', $fuente->series()->select('id'))
            ->where('en_nube', true);

        // chunk: la lista de claves puede tener miles y no entra en un IN solo.
        foreach (array_chunk($clavesVistas, 1000) as $lote) {
            $consulta->whereNotIn('clave', $lote);
        }

        $this->resumen['desaparecidas'] = $clavesVistas === []
            ? 0
            : (int) $consulta->update(['en_nube' => false]);
    }
}
