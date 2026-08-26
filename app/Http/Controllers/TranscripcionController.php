<?php

namespace App\Http\Controllers;

use App\Importacion\DocumentoDeTexto;
use App\Importacion\EscanearFuente;
use App\Importacion\ExtraerTranscripcion;
use App\Importacion\Lectores\LectorDeFuente;
use App\Models\Fuente;
use App\Models\Pista;
use App\Models\Transcripcion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El texto de una enseñanza, para leerlo mientras suena.
 *
 * Todo pasa por el mismo `autorizar()` que el audio, o sea por
 * `Fuente::visiblesPara()`. Eso lo alinea solo con las invitaciones: a quien le
 * dejaron de compartir la biblioteca tampoco le aparecen las transcripciones,
 * sin que haya hecho falta escribir una sola regla nueva.
 */
class TranscripcionController extends Controller
{
    /** 10 MB. El documento más grande de la biblioteca pesa 0,2. */
    private const MAXIMO_KB = 10240;

    /**
     * Los formatos que sabemos volver a escribir.
     *
     * Importa que estén TODOS los que le ganan al .docx en
     * `Transcripcion::FORMATOS`: si al lado del audio existiera un archivo mejor
     * que no supiéramos actualizar, el próximo barrido lo preferiría y desharía
     * la corrección. Los que quedan afuera —.pdf y .doc— pierden contra todos
     * éstos, así que no pueden pisar nada.
     *
     * @var list<string>
     */
    private const SE_ESCRIBEN = ['srt', 'vtt', 'docx', 'txt'];

    /**
     * Lo que consume el panel lateral.
     *
     * No va bajo /api/ a propósito: el service worker tiene prohibido cachear
     * esa ruta —una respuesta vieja diciendo "descargando 40%" es peor que un
     * error— y esto sí tiene que poder guardarse junto al audio para leerlo en
     * el colectivo.
     */
    public function json(Pista $pista): JsonResponse
    {
        $this->autorizar($pista);

        $transcripcion = $pista->transcripcion;

        if (! $transcripcion instanceof Transcripcion) {
            return response()->json(['estado' => 'no_hay'], 404);
        }

        return response()->json($this->ficha($pista, $transcripcion));
    }

    public function mostrar(Pista $pista): Response
    {
        $this->autorizar($pista);

        $transcripcion = $pista->transcripcion;

        abort_unless($transcripcion instanceof Transcripcion, 404);

        return Inertia::render('Transcripcion', [
            'transcripcion' => $this->ficha($pista, $transcripcion),
        ]);
    }

    /**
     * El documento tal cual está en la nube.
     *
     * Es la única salida para los 12 .doc viejos, de los que no se puede sacar
     * el texto sin una librería que el hosting no tiene.
     */
    public function bajar(Pista $pista): StreamedResponse
    {
        $this->autorizar($pista);

        $transcripcion = $pista->transcripcion;

        abort_unless($transcripcion instanceof Transcripcion, 404);

        $archivo = $pista->rutaDeLaTranscripcion($transcripcion->formato);

        abort_unless(is_file($archivo), 404);

        $limpio = preg_replace('/[^\x20-\x7e]/', '_', $transcripcion->archivo) ?? 'transcripcion';

        return response()->stream(function () use ($archivo) {
            readfile($archivo);
        }, 200, [
            'Content-Type' => $this->tipoDe($transcripcion->formato),
            'Content-Length' => (string) filesize($archivo),
            'Content-Disposition' => 'attachment; filename="'.$limpio
                .'"; filename*=UTF-8\'\''.rawurlencode($transcripcion->archivo),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * El mismo documento pero para mostrar, no para bajar.
     *
     * Existe sólo para los PDF, que el navegador dibuja nativamente en un
     * iframe: es la única forma de leerlos sin una librería para extraerles el
     * texto, y para dos archivos no vale la pena traer una.
     */
    public function ver(Pista $pista): StreamedResponse
    {
        $this->autorizar($pista);

        $transcripcion = $pista->transcripcion;

        abort_unless($transcripcion instanceof Transcripcion && $transcripcion->seEmbebe(), 404);

        $archivo = $pista->rutaDeLaTranscripcion($transcripcion->formato);

        abort_unless(is_file($archivo), 404);

        return response()->stream(function () use ($archivo) {
            readfile($archivo);
        }, 200, [
            'Content-Type' => $this->tipoDe($transcripcion->formato),
            'Content-Length' => (string) filesize($archivo),
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Sube una transcripción, y la manda a la nube.
     *
     * Va a la nube y no sólo al server por la regla número uno del proyecto: la
     * nube es la única copia que importa y el server es descartable. Una
     * transcripción que viviera nada más que acá abajo se perdería en la próxima
     * purga sin que nadie se entere.
     *
     * Es la única escritura que la app hace sobre los archivos de OneDrive, así
     * que va con freno de mano: sólo el administrador (por el middleware de la
     * ruta), la ruta destino se arma con datos de la base y nunca con algo que
     * venga del navegador, no borra nada, y no se da por buena hasta
     * preguntarle a la nube si el archivo llegó.
     */
    public function subir(Request $request, Pista $pista, ExtraerTranscripcion $extraer, EscanearFuente $escanear): RedirectResponse
    {
        $this->autorizar($pista);

        $request->validate([
            'documento' => [
                'required',
                'file',
                'extensions:'.implode(',', Transcripcion::FORMATOS),
                'max:'.self::MAXIMO_KB,
            ],
        ], [
            'documento.extensions' => 'Tiene que ser un .docx, .srt, .vtt, .txt, .pdf o .doc.',
            'documento.max' => 'El documento no puede pesar más de 10 MB.',
        ]);

        $subido = $request->file('documento');

        if (! $subido instanceof UploadedFile) {
            return back()->withErrors(['documento' => 'No pude leer ese archivo. Probá de nuevo.']);
        }

        $formato = mb_strtolower($subido->getClientOriginalExtension());
        $bytes = (string) file_get_contents($subido->getRealPath());

        $fuente = $pista->serie->fuente;
        $destino = $this->rutaEnLaNube($pista, $formato);
        $lector = $escanear->lectorPara($fuente);

        if (! $lector->subir($fuente->ruta, $destino, $subido->getRealPath())) {
            return back()->withErrors(['documento' => 'No pude guardar el documento en la nube. Probá de nuevo.']);
        }

        /*
         * Se pregunta si llegó en vez de confiar en que el comando anterior
         * salió bien. Una verificación que siempre dice que sí es peor que no
         * tener ninguna: da la tranquilidad sin dar la garantía.
         */
        if (! $lector->existe($fuente->ruta, $destino)) {
            return back()->withErrors([
                'documento' => 'El documento no llegó a la nube. No se guardó nada.',
            ]);
        }

        $extraer->guardar($pista, basename($destino), $bytes, Transcripcion::ORIGEN_MANUAL);
        $extraer->marcarRevisada($pista);

        return back()->with('estado', 'Transcripción guardada, y también en la nube junto al audio.');
    }

    /**
     * Guarda una corrección hecha desde el panel.
     *
     * Va a la nube y no sólo a la base: la nube es la única copia que importa, y
     * una corrección que viviera nada más que acá abajo la borraría el próximo
     * barrido, releyendo el documento sin corregir.
     *
     * Se reescriben TODOS los documentos que ya existan al lado del audio entre
     * los que sabemos escribir, y ninguno más. Nunca se crea uno nuevo: si hay
     * un .docx se reescribe el .docx; si además hay un .srt, los dos.
     */
    public function editar(
        Request $request,
        Pista $pista,
        ExtraerTranscripcion $extraer,
        EscanearFuente $escanear,
        DocumentoDeTexto $escribir,
    ): RedirectResponse {
        $this->autorizar($pista);

        $transcripcion = $pista->transcripcion;

        // Sin texto no hay nada que editar: los .doc viejos y los .pdf.
        abort_unless($transcripcion instanceof Transcripcion && $transcripcion->tieneTexto(), 404);

        $datos = $request->validate([
            'encabezado' => ['nullable', 'string', 'max:4000'],
            'texto' => ['nullable', 'string', 'max:2000000'],
            'tramos' => ['nullable', 'array', 'max:5000'],
            'tramos.*.inicio' => ['required', 'numeric', 'min:0', 'max:86400'],
            'tramos.*.fin' => ['required', 'numeric', 'min:0', 'max:86400'],
            'tramos.*.texto' => ['required', 'string', 'max:100000'],
        ]);

        /** @var list<array{inicio: float, fin: float, texto: string}> $tramos */
        $tramos = array_map(fn (array $t) => [
            'inicio' => (float) $t['inicio'],
            'fin' => (float) $t['fin'],
            'texto' => trim((string) $t['texto']),
        ], array_values($datos['tramos'] ?? []));

        $encabezado = trim((string) ($datos['encabezado'] ?? '')) ?: null;
        $plano = trim((string) ($datos['texto'] ?? ''));

        if ($tramos === [] && $plano === '') {
            return back()->withErrors(['texto' => 'La transcripción no puede quedar vacía.']);
        }

        $fuente = $pista->serie->fuente;
        $lector = $escanear->lectorPara($fuente);

        /*
         * El original se guarda antes de tocar nada, y SÓLO la primera vez: si
         * se guardara en cada edición, la segunda pisaría el respaldo con la
         * primera corrección y dejaría de ser el original.
         */
        $this->respaldar($pista, $transcripcion);

        /** @var list<string> $escritos */
        $escritos = [];

        /*
         * El primero que se escriba es el mejor, porque SE_ESCRIBEN va en el
         * mismo orden de calidad que `Transcripcion::FORMATOS`. Es el que se usa
         * para rellenar la base, así que ésta queda diciendo lo mismo que diría
         * un barrido posterior en vez de quedar en desacuerdo con él.
         */
        $mejor = null;

        foreach (self::SE_ESCRIBEN as $formato) {
            $destino = $this->rutaEnLaNube($pista, $formato);

            if (! $lector->existe($fuente->ruta, $destino)) {
                continue;
            }

            $bytes = match ($formato) {
                'docx' => $escribir->docx($encabezado, $tramos, $plano),
                'srt' => $escribir->srt($tramos),
                'vtt' => $escribir->vtt($tramos),
                default => $escribir->txt($encabezado, $tramos, $plano),
            };

            if ($bytes === null) {
                continue;
            }

            if (! $this->guardarEnLaNube($lector, $fuente->ruta, $destino, $bytes)) {
                return back()->withErrors([
                    'texto' => "No pude guardar el {$formato} en la nube. No se cambió nada.",
                ]);
            }

            $escritos[] = $formato;
            $mejor ??= ['formato' => $formato, 'bytes' => $bytes];
        }

        if ($mejor === null) {
            return back()->withErrors([
                'texto' => 'No encontré el documento en la nube. No se cambió nada.',
            ]);
        }

        /*
         * La base se llena releyendo lo que se acaba de escribir, con el mismo
         * `guardar()` del barrido y de la subida. Así el texto se saca siempre
         * igual y no hay dos verdades sobre qué es una transcripción — y si el
         * generador y el lector no coincidieran, se vería acá mismo.
         */
        $extraer->guardar(
            $pista,
            basename($this->rutaEnLaNube($pista, $mejor['formato'])),
            $mejor['bytes'],
            Transcripcion::ORIGEN_MANUAL,
        );

        $extraer->marcarRevisada($pista);

        return back()->with('estado', count($escritos) > 1
            ? 'Corrección guardada, y en la nube en los '.count($escritos).' archivos del audio.'
            : 'Corrección guardada, y también en la nube junto al audio.');
    }

    /**
     * Escribe un archivo en la nube y confirma que llegó.
     *
     * `subir()` recibe una ruta de disco y no bytes, así que hay que pasar por un
     * temporal. Y se pregunta si llegó en vez de confiar en que el comando
     * anterior salió bien: una verificación que siempre dice que sí es peor que
     * no tener ninguna.
     */
    private function guardarEnLaNube(LectorDeFuente $lector, string $raiz, string $destino, string $bytes): bool
    {
        $temporal = tempnam(sys_get_temp_dir(), 'dharmify-editar-');

        if ($temporal === false) {
            return false;
        }

        try {
            file_put_contents($temporal, $bytes);

            return $lector->subir($raiz, $destino, $temporal)
                && $lector->existe($raiz, $destino);
        } finally {
            @unlink($temporal);
        }
    }

    /**
     * Guarda el documento tal como estaba, la primera vez que se lo corrige.
     *
     * OneDrive tiene su propio historial de versiones, pero eso hay que ir a
     * buscarlo y saber que existe. Esto es una red debajo de la red.
     */
    private function respaldar(Pista $pista, Transcripcion $transcripcion): void
    {
        $original = $pista->rutaDeLaTranscripcion($transcripcion->formato);
        $copia = 'transcripciones/originales/'.$pista->clave.'.'.$transcripcion->formato;

        if (! is_file($original) || Storage::disk('local')->exists($copia)) {
            return;
        }

        Storage::disk('local')->put($copia, (string) file_get_contents($original));
    }

    /**
     * Dónde va el documento en la nube: al lado del audio y con su mismo nombre.
     *
     * Ni la carpeta ni el nombre salen del navegador —los dos se arman con lo
     * que hay en la base— así que no hay forma de que una subida termine
     * escribiendo fuera de la carpeta de su propia serie.
     */
    private function rutaEnLaNube(Pista $pista, string $formato): string
    {
        $carpeta = pathinfo($pista->ruta, PATHINFO_DIRNAME);
        $base = pathinfo($pista->ruta, PATHINFO_FILENAME);

        return ($carpeta === '.' || $carpeta === '' ? '' : $carpeta.'/').$base.'.'.$formato;
    }

    /**
     * @return array<string, mixed>
     */
    private function ficha(Pista $pista, Transcripcion $transcripcion): array
    {
        return [
            'pista' => $pista->id,
            'titulo' => $pista->titulo,
            'serie' => $pista->serie->titulo,
            'serieId' => $pista->serie->id,
            'formato' => $transcripcion->formato,
            // Lo que va antes de la primera marca. Viaja porque al corregir
            // hay que volver a escribirlo en el documento.
            'encabezado' => $transcripcion->encabezado,
            'palabras' => $transcripcion->palabras,
            'texto' => $transcripcion->texto,
            /*
             * Las marcas viajan sólo si las hay. Hoy no las usa nadie: están
             * para cuando se resalte el texto al ritmo del audio, y se guardan
             * desde ahora para no tener que reimportar 709 archivos ese día.
             */
            'marcas' => $transcripcion->marcas,
            'seEdita' => $transcripcion->tieneTexto(),
            'seEmbebe' => $transcripcion->seEmbebe(),
            'urlVer' => $transcripcion->seEmbebe() ? "/pistas/{$pista->id}/transcripcion/ver" : null,
            'urlBajar' => "/pistas/{$pista->id}/transcripcion/bajar",
            'urlCompleta' => "/pistas/{$pista->id}/transcripcion",
            'archivo' => $transcripcion->archivo,
        ];
    }

    private function tipoDe(string $formato): string
    {
        return match ($formato) {
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc' => 'application/msword',
            'srt', 'vtt', 'txt' => 'text/plain; charset=UTF-8',
            default => 'application/octet-stream',
        };
    }

    private function autorizar(Pista $pista): void
    {
        abort_unless($this->fuentesVisibles()->contains($pista->serie->fuente_id), 404);
    }

    /** @return Collection<int, int> */
    private function fuentesVisibles(): Collection
    {
        return Fuente::visiblesPara(request()->user());
    }
}
