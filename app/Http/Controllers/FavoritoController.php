<?php

namespace App\Http\Controllers;

use App\Models\Fuente;
use App\Models\Pista;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las enseñanzas que alguien marcó.
 *
 * Son de cada persona: la biblioteca es compartida, lo marcado no.
 */
class FavoritoController extends Controller
{
    public function index(Request $request): Response
    {
        $visibles = Fuente::visiblesPara($request->user());

        $pistas = $request->user()
            ->favoritos()
            ->with('serie:id,titulo,fuente_id,portada,portada_revisada_en')
            ->get()
            /*
             * El filtro por visibilidad va también acá y no sólo al marcar: si
             * una fuente pasa de pública a privada, lo que alguien marcó cuando
             * podía verlo tiene que dejar de aparecerle.
             */
            ->filter(fn (Pista $p) => $visibles->contains($p->serie->fuente_id))
            ->map(fn (Pista $p) => $p->ficha(favorita: true))
            ->values();

        return Inertia::render('Favoritos', ['pistas' => $pistas]);
    }

    /**
     * Marca o desmarca, según cómo esté.
     *
     * Un solo camino y no un alta y una baja separadas: el botón es uno solo y
     * la pantalla no tiene por qué saber en cuál de los dos estados está antes
     * de tocarlo.
     */
    public function alternar(Request $request, Pista $pista): RedirectResponse
    {
        abort_unless(
            Fuente::visiblesPara($request->user())->contains($pista->serie->fuente_id),
            404,
        );

        $cambio = $request->user()->favoritos()->toggle($pista->id);

        return back(fallback: '/biblioteca')->with(
            'estado',
            $cambio['attached'] !== [] ? 'Agregada a favoritos.' : 'Quitada de favoritos.',
        );
    }
}
