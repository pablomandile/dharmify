<?php

namespace App\Http\Controllers;

use App\Importacion\EscanearFuente;
use App\Models\Fuente;
use App\Models\Pista;
use App\Models\Serie;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Sirve el audio.
 *
 * Los archivos viven FUERA del docroot: la biblioteca es privada y no puede
 * quedar colgando de una URL adivinable. Como no los sirve el web server
 * directo, el 206 / Partial Content hay que implementarlo acá — y sin él el
 * audio suena pero no se puede adelantar, que es la mitad de un reproductor.
 */
class PistaController extends Controller
{
    private const CHUNK = 262144;   // 256 KB por vuelta

    public function audio(Pista $pista): StreamedResponse|JsonResponse
    {
        $this->autorizar($pista);

        $archivo = $this->rutaLocal($pista);

        if (! is_file($archivo)) {
            /*
             * No está en el server. La respuesta correcta NO es 404: el ítem
             * existe, sólo hay que traerlo. Es la diferencia entre "la
             * biblioteca tiene 928 clases" y "tiene 20 y 908 links rotos".
             */
            if ($pista->en_nube) {
                return response()->json([
                    'estado' => 'en_nube',
                    'mensaje' => 'Trayendo de la nube…',
                ], 202);
            }

            return response()->json(['estado' => 'no_disponible'], 404);
        }

        $pista->forceFill([
            'ultimo_acceso_en' => now(),
            'reproducciones' => $pista->reproducciones + 1,
        ])->save();

        return $this->enviar($archivo);
    }

    /**
     * Trae el archivo de la nube.
     *
     * Corre dentro de la petición: la cola necesita un cron creado a mano en el
     * panel y, sin él, un job encolado se quedaría esperando para siempre — el
     * play parecería colgado. La pantalla muestra "Trayendo de la nube…"
     * mientras esta petición está en vuelo.
     */
    public function restaurar(Pista $pista, EscanearFuente $escanear): JsonResponse
    {
        $this->autorizar($pista);

        if (is_file($this->rutaLocal($pista))) {
            return response()->json(['estado' => 'listo']);
        }

        if (! $pista->en_nube) {
            return response()->json(['estado' => 'no_disponible'], 404);
        }

        @set_time_limit(900);

        $fuente = $pista->serie->fuente;

        try {
            $traido = $escanear->lectorPara($fuente)->traer(
                $fuente->ruta,
                $pista->ruta,
                $this->rutaLocal($pista),
            );
        } catch (Throwable $e) {
            return response()->json(['estado' => 'error', 'mensaje' => $e->getMessage()], 500);
        }

        if (! $traido) {
            return response()->json([
                'estado' => 'error',
                'mensaje' => 'No se pudo traer el archivo de la nube.',
            ], 500);
        }

        $pista->forceFill(['en_server' => true])->save();

        return response()->json(['estado' => 'listo']);
    }

    /** Lo que la pantalla pollea. Nunca se cachea (va bajo /api). */
    public function estado(Pista $pista): JsonResponse
    {
        $this->autorizar($pista);

        return response()->json([
            'en_server' => is_file($this->rutaLocal($pista)),
            'en_nube' => $pista->en_nube,
        ]);
    }

    public function descargar(Pista $pista): StreamedResponse|JsonResponse
    {
        $this->autorizar($pista);

        $archivo = $this->rutaLocal($pista);

        if (! is_file($archivo)) {
            return response()->json(['estado' => 'en_nube'], 202);
        }

        return $this->enviar($archivo, descargar: true, nombre: $pista->archivo);
    }

    public function portada(Serie $serie): StreamedResponse|JsonResponse
    {
        abort_unless($this->fuentesVisibles()->contains($serie->fuente_id), 404);

        if (! $serie->portada || ! is_file(storage_path('app/private/'.$serie->portada))) {
            abort(404);
        }

        $ruta = storage_path('app/private/'.$serie->portada);

        return response()->stream(function () use ($ruta) {
            readfile($ruta);
        }, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) filesize($ruta),
            // El contenido de una carátula no cambia nunca: se cachea fuerte.
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    /**
     * El 206 a mano.
     *
     * Dos detalles que se olvidan y duelen: apagar el buffering (si PHP acumula
     * 25 MB antes de mandar, el reproductor tarda una eternidad en arrancar) y
     * cortar con connection_aborted() (si no, cada vez que alguien cambia de
     * pista queda un proceso leyendo el archivo entero al vacío).
     */
    private function enviar(string $archivo, bool $descargar = false, string $nombre = ''): StreamedResponse
    {
        $tam = filesize($archivo);
        $inicio = 0;
        $fin = $tam - 1;
        $parcial = false;

        $rango = request()->header('Range', '');

        if ($rango !== '' && preg_match('/bytes=(\d*)-(\d*)/i', $rango, $m)) {
            [$desde, $hasta] = [$m[1], $m[2]];

            if ($desde === '' && $hasta !== '') {
                $inicio = max(0, $tam - (int) $hasta);   // "bytes=-500"
            } else {
                $inicio = (int) $desde;

                if ($hasta !== '') {
                    $fin = min((int) $hasta, $tam - 1);
                }
            }

            if ($inicio > $fin || $inicio >= $tam) {
                abort(416, headers: ['Content-Range' => "bytes */{$tam}"]);
            }

            $parcial = true;
        }

        $largo = $fin - $inicio + 1;

        $cabeceras = [
            'Content-Type' => 'audio/mpeg',
            'Accept-Ranges' => 'bytes',
            'Content-Length' => (string) $largo,
            'Cache-Control' => 'private, max-age=0, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($parcial) {
            $cabeceras['Content-Range'] = "bytes {$inicio}-{$fin}/{$tam}";
        }

        if ($descargar) {
            $limpio = preg_replace('/[^\x20-\x7e]/', '_', $nombre ?: basename($archivo));
            $cabeceras['Content-Disposition'] = 'attachment; filename="'.$limpio
                .'"; filename*=UTF-8\'\''.rawurlencode($nombre ?: basename($archivo));
        }

        return response()->stream(function () use ($archivo, $inicio, $largo) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            @ini_set('zlib.output_compression', '0');
            @set_time_limit(0);

            $fh = fopen($archivo, 'rb');

            // Entre el is_file() y esta apertura el archivo pudo desaparecer:
            // justo lo que hace la purga. Sin este chequeo, el stream revienta.
            if ($fh === false) {
                return;
            }

            fseek($fh, $inicio);
            $restante = $largo;

            while ($restante > 0 && ! feof($fh)) {
                if (connection_aborted()) {
                    break;
                }

                $datos = fread($fh, max(1, (int) min(self::CHUNK, $restante)));

                if ($datos === false || $datos === '') {
                    break;
                }

                echo $datos;
                flush();
                $restante -= strlen($datos);
            }

            fclose($fh);
        }, $parcial ? 206 : 200, $cabeceras);
    }

    /** Flat y por clave estable: en la biblioteca hay 157 nombres repetidos. */
    private function rutaLocal(Pista $pista): string
    {
        return storage_path('app/private/audio/'.$pista->clave.'.mp3');
    }

    private function autorizar(Pista $pista): void
    {
        abort_unless($this->fuentesVisibles()->contains($pista->serie->fuente_id), 404);
    }

    /** @return Collection<int, int> */
    private function fuentesVisibles()
    {
        return Fuente::query()
            ->when(
                ! request()->user()?->esAdmin(),
                fn ($q) => $q->where('visibilidad', Fuente::VISIBILIDAD_PUBLICA),
            )
            ->pluck('id');
    }
}
