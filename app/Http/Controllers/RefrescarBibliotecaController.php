<?php

namespace App\Http\Controllers;

use App\Importacion\EscanearFuente;
use App\Models\Fuente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Vuelve a mirar las fuentes y suma lo que haya de nuevo.
 *
 * Corre en la propia petición y no en la cola, por ahora, a propósito: en el
 * hosting compartido la cola necesita un cron creado a mano desde el panel, y
 * hasta que exista, un job encolado se quedaría esperando para siempre — el
 * botón parecería no hacer nada, que es exactamente el peor resultado posible.
 *
 * Cuando el cron esté, esto pasa a `dispatch()` y la pantalla pollea el estado.
 * El escaneo ya es idempotente, así que el cambio no arrastra nada.
 */
class RefrescarBibliotecaController extends Controller
{
    public function __invoke(EscanearFuente $escanear): RedirectResponse
    {
        $fuentes = Fuente::query()->where('activa', true)->get();

        if ($fuentes->isEmpty()) {
            return back()->with('error', 'No hay ninguna fuente activa. Agregá una carpeta de OneDrive primero.');
        }

        // Listar una biblioteca grande por la API de la nube puede tardar.
        @set_time_limit(600);

        $totales = ['series_nuevas' => 0, 'pistas_nuevas' => 0, 'pistas_actualizadas' => 0, 'desaparecidas' => 0];
        $fallaron = [];

        foreach ($fuentes as $fuente) {
            try {
                foreach ($escanear($fuente) as $clave => $valor) {
                    if (array_key_exists($clave, $totales)) {
                        $totales[$clave] += $valor;
                    }
                }
            } catch (Throwable $e) {
                /*
                 * Una fuente rota no puede cancelar el refresco de las demás: si
                 * OneDrive no responde para la biblioteca pública, la privada
                 * igual tiene que actualizarse.
                 */
                Log::warning('Falló el refresco de una fuente.', [
                    'fuente' => $fuente->nombre,
                    'excepcion' => $e->getMessage(),
                ]);

                $fallaron[] = "{$fuente->nombre}: {$e->getMessage()}";
            }
        }

        if ($fallaron !== []) {
            return back()->with('error', implode(' · ', $fallaron));
        }

        return back()->with('estado', $this->resumen($totales));
    }

    /**
     * @param  array<string, int>  $t
     */
    private function resumen(array $t): string
    {
        if (array_sum($t) === 0) {
            return 'Biblioteca al día: no había nada nuevo.';
        }

        $partes = [];

        if ($t['pistas_nuevas'] > 0) {
            $partes[] = "{$t['pistas_nuevas']} audios nuevos";
        }

        if ($t['series_nuevas'] > 0) {
            $partes[] = "{$t['series_nuevas']} series nuevas";
        }

        if ($t['pistas_actualizadas'] > 0) {
            $partes[] = "{$t['pistas_actualizadas']} actualizados";
        }

        if ($t['desaparecidas'] > 0) {
            $partes[] = "{$t['desaparecidas']} ya no están en la nube";
        }

        return 'Listo: '.implode(', ', $partes).'.';
    }
}
