<?php

namespace App\Http\Controllers;

use App\Models\Fuente;
use App\Models\Maestro;
use App\Models\Pista;
use App\Models\Serie;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
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
             * Alfabético por título.
             *
             * Antes iba por año descendente, que servía cuando lo último subido
             * era lo que uno venía a buscar. Con 145 series y sus nombres ya
             * corregidos, lo que uno hace es buscar una en particular, y para
             * eso el orden alfabético es el único en el que se puede adivinar
             * dónde mirar. Para lo demás están el buscador y los filtros.
             *
             * En producción el cotejo es utf8mb4_unicode_ci, que ignora
             * mayúsculas y acentos: "Ángeles" cae donde uno lo busca y no al
             * final de todo. (En las pruebas, sobre SQLite, el cotejo es binario
             * y eso no vale.)
             */
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
                'portada' => $s->urlPortada(),
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

    public function serie(Request $request, Serie $serie): Response
    {
        // Que exista no alcanza: tiene que venir de una fuente que esta persona
        // puede ver.
        abort_unless($this->fuentesVisibles()->contains($serie->fuente_id), 404);

        $serie->load('maestros:id,nombre,slug', 'fuente:id,nombre');

        // De una sola consulta: preguntar pista por pista serían treinta
        // consultas en un retiro largo.
        $favoritas = $request->user()
            ?->favoritos()
            ->whereIn('pistas.id', $serie->pistas()->pluck('id'))
            ->pluck('pistas.id') ?? collect();

        return Inertia::render('serie/Show', [
            'serie' => [
                'id' => $serie->id,
                'titulo' => $serie->titulo,
                'carpeta' => $serie->carpeta,
                'tipo' => $serie->tipo,
                'anio' => $serie->anio,
                'idioma' => $serie->idioma,
                'portada' => $serie->urlPortada(),
                'portada_origen' => $serie->portada_origen,
                'maestros' => $serie->maestros->map(fn (Maestro $m) => ['nombre' => $m->nombre, 'slug' => $m->slug]),
            ],
            'pistas' => $serie->pistas()
                ->orderBy('orden')
                ->get()
                ->map(fn (Pista $p) => $p->ficha($favoritas->contains($p->id))),
        ]);
    }

    /** @return Collection<int, int> */
    private function fuentesVisibles(): Collection
    {
        return Fuente::visiblesPara(request()->user());
    }

    /**
     * Corregir el título de una serie a mano.
     *
     * Hace falta porque las dos fuentes automáticas fallan de maneras que ningún
     * programa puede arreglar: el nombre de la carpeta a veces está mal escrito,
     * y la etiqueta del mp3 también —hay un álbum que dice "Desapeo" y otro que
     * dice "Inicición"—. Con esto se arregla en diez segundos en vez de por SQL.
     *
     * Queda marcado como manual, que es lo que lo protege: ni el escaneo ni el
     * barrido de etiquetas vuelven a tocarlo.
     */
    public function renombrar(Request $request, Serie $serie): RedirectResponse
    {
        abort_unless($this->fuentesVisibles()->contains($serie->fuente_id), 404);

        $datos = $request->validate([
            'titulo' => ['required', 'string', 'max:200'],
        ], [
            'titulo.required' => 'El título no puede quedar vacío.',
            'titulo.max' => 'El título no puede pasar de 200 caracteres.',
        ]);

        $serie->forceFill([
            'titulo' => trim($datos['titulo']),
            'titulo_origen' => Serie::TITULO_MANUAL,
        ])->save();

        return back()->with('estado', 'Título actualizado.');
    }

    /** @return Builder<Serie> */
    private function visibles(): Builder
    {
        return Serie::query()->whereIn('fuente_id', $this->fuentesVisibles());
    }
}
