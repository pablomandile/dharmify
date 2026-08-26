<?php

namespace App\Http\Controllers;

use App\Models\Fuente;
use App\Models\Lista;
use App\Models\Pista;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Listas de reproducción armadas a mano.
 *
 * Cada una es de quien la armó. No hay una policy con reglas: la consulta
 * arranca siempre desde `$request->user()->listas()`, así que una lista ajena no
 * existe —da 404, no 403—. Es más difícil equivocarse que con un permiso que hay
 * que acordarse de chequear en cada método.
 */
class ListaController extends Controller
{
    public function index(Request $request): Response
    {
        $listas = $request->user()
            ->listas()
            ->withCount('pistas')
            ->get()
            ->map(fn (Lista $l) => [
                'id' => $l->id,
                'nombre' => $l->nombre,
                'pistas' => $l->pistas_count,
            ]);

        return Inertia::render('listas/Index', ['listas' => $listas]);
    }

    public function show(Request $request, int $lista): Response
    {
        $suya = $this->suya($request, $lista);
        $visibles = Fuente::visiblesPara($request->user());
        $favoritas = $request->user()->favoritos()->pluck('pistas.id');

        $pistas = $suya->pistas()
            ->with('serie:id,titulo,fuente_id,portada,portada_revisada_en')
            ->withExists('transcripcion')
            ->get()
            ->filter(fn (Pista $p) => $visibles->contains($p->serie->fuente_id))
            ->map(fn (Pista $p) => $p->ficha($favoritas->contains($p->id)))
            ->values();

        return Inertia::render('listas/Show', [
            'lista' => ['id' => $suya->id, 'nombre' => $suya->nombre],
            'pistas' => $pistas,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:80'],
            'pista_id' => ['nullable', 'integer'],
        ], [
            'nombre.required' => 'Poné un nombre para la lista.',
            'nombre.max' => 'El nombre no puede pasar de 80 caracteres.',
        ]);

        $lista = $request->user()->listas()->create(['nombre' => $datos['nombre']]);

        // Crear la lista desde el botón "agregar a" de una enseñanza: se crea y
        // se agrega de una, que es lo que la persona quiso hacer.
        if ($datos['pista_id'] ?? null) {
            $this->sumar($lista, (int) $datos['pista_id'], $request);
        }

        return back(fallback: '/listas')->with('estado', "Lista «{$lista->nombre}» creada.");
    }

    public function update(Request $request, int $lista): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:80'],
        ]);

        $this->suya($request, $lista)->update(['nombre' => $datos['nombre']]);

        return back()->with('estado', 'Lista renombrada.');
    }

    public function destroy(Request $request, int $lista): RedirectResponse
    {
        $this->suya($request, $lista)->delete();

        return redirect('/listas')->with('estado', 'Lista borrada.');
    }

    public function agregar(Request $request, int $lista): RedirectResponse
    {
        $datos = $request->validate([
            'pista_id' => ['required', 'integer'],
        ]);

        $suya = $this->suya($request, $lista);
        $this->sumar($suya, (int) $datos['pista_id'], $request);

        return back()->with('estado', "Agregada a «{$suya->nombre}».");
    }

    public function quitar(Request $request, int $lista, Pista $pista): RedirectResponse
    {
        $this->suya($request, $lista)->pistas()->detach($pista->id);

        return back()->with('estado', 'Quitada de la lista.');
    }

    /**
     * Sube o baja una pista un lugar.
     *
     * Se renumera la lista entera y no sólo las dos que se cruzan: los `orden`
     * pueden venir con huecos o repetidos de altas viejas, y renumerar deja el
     * invariante sano en cada movimiento. Son treinta escrituras en el peor
     * caso, sobre una tabla de dos columnas.
     */
    public function mover(Request $request, int $lista, Pista $pista): RedirectResponse
    {
        $datos = $request->validate([
            'hacia' => ['required', 'in:arriba,abajo'],
        ]);

        $suya = $this->suya($request, $lista);
        $orden = $suya->pistas()->pluck('pistas.id')->all();
        $desde = array_search($pista->id, $orden, strict: true);

        // `is_int` y no `!== false`: array_search también puede devolver una
        // clave de texto, y sumarle uno a eso no significa nada.
        if (! is_int($desde)) {
            return back();
        }

        $hasta = $datos['hacia'] === 'arriba' ? $desde - 1 : $desde + 1;

        if ($hasta < 0 || $hasta >= count($orden)) {
            return back();
        }

        [$orden[$desde], $orden[$hasta]] = [$orden[$hasta], $orden[$desde]];

        foreach ($orden as $posicion => $id) {
            $suya->pistas()->updateExistingPivot($id, ['orden' => $posicion]);
        }

        return back();
    }

    private function sumar(Lista $lista, int $pistaId, Request $request): void
    {
        $pista = Pista::with('serie:id,fuente_id')->find($pistaId);

        abort_unless(
            $pista instanceof Pista
                && Fuente::visiblesPara($request->user())->contains($pista->serie->fuente_id),
            404,
        );

        // `syncWithoutDetaching` y no `attach`: agregar dos veces la misma
        // enseñanza no es un error que haya que manejar, es que ya estaba.
        $lista->pistas()->syncWithoutDetaching([
            $pista->id => ['orden' => $lista->proximoOrden()],
        ]);
    }

    /** Una lista ajena no existe: 404 y no 403, que ya sería contar de más. */
    private function suya(Request $request, int $lista): Lista
    {
        return $request->user()->listas()->findOrFail($lista);
    }
}
