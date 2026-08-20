<?php

namespace App\Http\Controllers;

use App\Models\Fuente;
use App\Models\Maestro;
use App\Models\Pista;
use App\Models\Serie;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La biblioteca: todas las series que la persona puede escuchar.
 */
class BibliotecaController extends Controller
{
    public function index(Request $request): Response
    {
        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:120'],
            'maestro' => ['nullable', 'string', 'max:120'],
            'tipo' => ['nullable', 'string', 'max:40'],
            'anio' => ['nullable', 'integer'],
        ]);

        $series = $this->visibles()
            ->with('maestros:id,nombre,slug')
            ->withCount('pistas')
            /*
             * El total de la serie se muestra sólo si TODAS sus pistas tienen
             * duración medida: con una sola sin medir, la suma queda corta y
             * diría que un retiro de doce horas dura nueve. Por eso además del
             * total se cuenta cuántas están medidas.
             */
            ->withSum('pistas', 'duracion_seg')
            ->withCount('pistasMedidas')
            ->when(
                $filtros['buscar'] ?? null,
                fn (Builder $q, string $texto) => $q->where(function (Builder $q) use ($texto) {
                    $q->where('titulo', 'like', "%{$texto}%")
                        // También por carpeta: conserva el nombre original completo
                        // (evento, sede, maestro) que el título ya no tiene.
                        ->orWhere('carpeta', 'like', "%{$texto}%")
                        ->orWhereHas('maestros', fn (Builder $m) => $m->where('nombre', 'like', "%{$texto}%"));
                }),
            )
            ->when(
                $filtros['maestro'] ?? null,
                fn (Builder $q, string $slug) => $q->whereHas('maestros', fn (Builder $m) => $m->where('slug', $slug)),
            )
            ->when($filtros['tipo'] ?? null, fn (Builder $q, string $tipo) => $q->where('tipo', $tipo))
            ->when($filtros['anio'] ?? null, fn (Builder $q, int $anio) => $q->where('anio', $anio))
            /*
             * Las series sin año van al final y no al principio: un `ORDER BY
             * anio DESC` pone los NULL adelante en MySQL, y el Programa General
             * —que no tiene año porque corre todo el tiempo— le taparía la
             * portada a todo lo demás.
             */
            ->orderByRaw('anio IS NULL, anio DESC')
            ->orderBy('titulo')
            ->paginate(24)
            ->withQueryString()
            ->through(fn (Serie $s) => [
                'id' => $s->id,
                'slug' => $s->slug,
                'titulo' => $s->titulo,
                'tipo' => $s->tipo,
                'anio' => $s->anio,
                'idioma' => $s->idioma,
                'pistas' => $s->pistas_count,
                'segundos' => $s->pistas_count > 0 && $s->pistas_medidas_count === $s->pistas_count
                    ? (int) $s->pistas_sum_duracion_seg
                    : null,
                'maestros' => $s->maestros->map(fn (Maestro $m) => ['nombre' => $m->nombre, 'slug' => $m->slug]),
            ]);

        return Inertia::render('Biblioteca', [
            'series' => $series,
            'filtros' => $filtros,
            'maestros' => Maestro::query()
                ->whereHas('series', fn (Builder $q) => $q->whereIn('fuente_id', $this->fuentesVisibles()))
                ->orderBy('nombre')
                ->get(['nombre', 'slug']),
            'tipos' => $this->visibles()->whereNotNull('tipo')->distinct()->orderBy('tipo')->pluck('tipo'),
            'anios' => $this->visibles()->whereNotNull('anio')->distinct()->orderByDesc('anio')->pluck('anio'),
            'totales' => [
                'series' => $this->visibles()->count(),
                'pistas' => (int) $this->visibles()->withCount('pistas')->get()->sum('pistas_count'),
            ],
        ]);
    }

    public function serie(Serie $serie): Response
    {
        // Que exista no alcanza: tiene que venir de una fuente que esta persona
        // puede ver.
        abort_unless($this->fuentesVisibles()->contains($serie->fuente_id), 404);

        $serie->load('maestros:id,nombre,slug', 'fuente:id,nombre');

        return Inertia::render('serie/Show', [
            'serie' => [
                'id' => $serie->id,
                'titulo' => $serie->titulo,
                'carpeta' => $serie->carpeta,
                'tipo' => $serie->tipo,
                'anio' => $serie->anio,
                'idioma' => $serie->idioma,
                'maestros' => $serie->maestros->map(fn (Maestro $m) => ['nombre' => $m->nombre, 'slug' => $m->slug]),
            ],
            'pistas' => $serie->pistas()
                ->orderBy('orden')
                ->get()
                ->map(fn (Pista $p) => [
                    'id' => $p->id,
                    'titulo' => $p->titulo,
                    'duracion_seg' => $p->duracion_seg,
                    'bytes' => $p->bytes,
                    'grabada_el' => $p->grabada_el?->format('d/m/Y'),
                    /*
                     * El estado real del disco, no la columna: la purga borra
                     * archivos sin pasar por el modelo, así que `en_server`
                     * puede estar desactualizada y la pastilla mentiría.
                     */
                    'en_server' => is_file(storage_path('app/private/audio/'.$p->clave.'.mp3')),
                    'en_nube' => $p->en_nube,
                ]),
        ]);
    }

    /**
     * Las fuentes que esta persona puede ver.
     *
     * El administrador ve todo; quien fue invitado, sólo lo público. Es el
     * sentido de que la fuente tenga visibilidad: hay enseñanzas que no se
     * comparten.
     *
     * @return Collection<int, int>
     */
    private function fuentesVisibles()
    {
        return Fuente::query()
            ->when(
                ! request()->user()?->esAdmin(),
                fn (Builder $q) => $q->where('visibilidad', Fuente::VISIBILIDAD_PUBLICA),
            )
            ->pluck('id');
    }

    /** @return Builder<Serie> */
    private function visibles(): Builder
    {
        return Serie::query()->whereIn('fuente_id', $this->fuentesVisibles());
    }
}
