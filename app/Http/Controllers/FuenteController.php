<?php

namespace App\Http\Controllers;

use App\Importacion\EscanearFuente;
use App\Models\Fuente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las carpetas de OneDrive de donde sale la biblioteca.
 *
 * Sólo el administrador: quien invitás escucha, no configura de dónde viene el
 * contenido.
 */
class FuenteController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/Fuentes', [
            'fuentes' => Fuente::query()
                ->withCount('series')
                ->orderBy('nombre')
                ->get()
                ->map(fn (Fuente $f) => [
                    'id' => $f->id,
                    'nombre' => $f->nombre,
                    'tipo' => $f->tipo,
                    'ruta' => $f->ruta,
                    'visibilidad' => $f->visibilidad,
                    'activa' => $f->activa,
                    'series_count' => $f->series_count,
                    'pistas' => $f->series()->withCount('pistas')->get()->sum('pistas_count'),
                    'revisada_en' => $f->revisada_en?->diffForHumans(),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        Fuente::create($datos);

        return back()->with('estado', 'Fuente agregada. Tocá «Refrescar biblioteca» para traer sus audios.');
    }

    public function update(Request $request, Fuente $fuente): RedirectResponse
    {
        $fuente->update($this->validar($request));

        return back()->with('estado', 'Fuente actualizada.');
    }

    /**
     * Borrar la fuente se lleva sus series y pistas (cascada). No borra nada de
     * OneDrive: el original siempre queda.
     */
    public function destroy(Fuente $fuente): RedirectResponse
    {
        $fuente->delete();

        return back()->with('estado', 'Fuente eliminada del catálogo. Los archivos en OneDrive quedaron intactos.');
    }

    /**
     * Prueba la conexión sin importar nada.
     *
     * Existe para que "no anda" tenga una respuesta concreta —falta autorizar
     * rclone, la carpeta no existe, no hay red— en vez de un refresco que no
     * trae nada y no explica por qué.
     */
    public function probar(Fuente $fuente, EscanearFuente $escanear): RedirectResponse
    {
        $problema = $escanear->lectorPara($fuente)->verificar($fuente->ruta);

        return back()->with(
            $problema ? 'error' : 'estado',
            $problema ?? 'La fuente responde bien.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'tipo' => ['required', Rule::in([Fuente::TIPO_RCLONE, Fuente::TIPO_LOCAL])],
            'ruta' => ['required', 'string', 'max:1024'],
            'visibilidad' => ['required', Rule::in([Fuente::VISIBILIDAD_PRIVADA, Fuente::VISIBILIDAD_PUBLICA])],
            'activa' => ['boolean'],
        ]);
    }
}
