<?php

namespace App\Importacion;

use App\Models\Fuente;
use App\Models\Pista;
use App\Models\Transcripcion;
use Illuminate\Support\Facades\Storage;

/**
 * Encuentra el documento que acompaña a cada audio.
 *
 * La biblioteca ya venía con 709 documentos —692 .docx, 12 .doc, 3 .txt y 2
 * .pdf— guardados al lado de los mp3 y con exactamente el mismo nombre base:
 *
 *     Retiro de purificación día 1 sesión 1.docx
 *     Retiro de purificación día 1 sesión 1.mp3
 *
 * Así que esto no inventa nada: cruza por nombre, igual que ExtraerPortada
 * cruza los 110 flyers sueltos con sus series.
 *
 * La carpeta se lista UNA vez para toda la fuente y no una por pista. Listar es
 * barato —nombres y tamaños, sin bajar nada— y hacerlo 928 veces contra la nube
 * serían horas.
 */
class ExtraerTranscripcion
{
    private const DISCO = 'local';

    private const CARPETA = 'transcripciones';

    /**
     * Cuánto se pide de cada documento.
     *
     * El más grande de la biblioteca pesa 0,2 MB y el promedio es 19 KB, así que
     * con esto entran enteros de una sola lectura. Pedir de más no cuesta: lo
     * que se paga contra la nube es la ida y vuelta, no los bytes.
     */
    public const TAMANIO = 1500000;

    public function __construct(
        private readonly EscanearFuente $escanear,
        private readonly TextoDeDocumento $texto,
    ) {}

    /**
     * Los documentos de la fuente, indexados por carpeta y nombre base.
     *
     * Si un audio terminó con más de un documento al lado —pasa cuando alguien
     * sube un .srt donde ya había un .docx— gana el de mejor formato. Un .srt
     * trae texto Y tiempos, así que quedarse con él es siempre una mejora.
     *
     * @return array<string, ArchivoEnLaFuente>
     */
    public function candidatosDe(Fuente $fuente): array
    {
        $porClave = [];

        foreach ($this->escanear->lectorPara($fuente)->listar($fuente->ruta, Transcripcion::FORMATOS) as $doc) {
            $clave = self::claveDe($doc->ruta);
            $anterior = $porClave[$clave] ?? null;

            if ($anterior === null || $this->esMejor($doc->nombre, $anterior->nombre)) {
                $porClave[$clave] = $doc;
            }
        }

        return $porClave;
    }

    /**
     * La carpeta más el nombre sin extensión, normalizado.
     *
     * Dos normalizaciones, y las dos salieron de medir la biblioteca real en vez
     * de imaginarla:
     *
     * - **Minúsculas**, porque el documento se llama "Clase 1.DOCX" y el audio
     *   "clase 1.mp3": en Windows es el mismo nombre, en el server no.
     * - **Guión bajo igual que espacio**. Los audios usan "_" donde los
     *   documentos usan " " —"2020.01.10_19.27_01.MP3" contra "2020.01.10 19.27
     *   01.docx", y lo mismo con "(192kbit_AAC)" contra "(192kbit AAC)"—. Sin
     *   esto quedaban 197 transcripciones sin asociar: el cruce pasaba de 447 a
     *   644 de 709 al agregarla.
     *
     * Que no junte dos audios distintos está comprobado sobre la biblioteca
     * entera: después de normalizar, las 928 claves siguen siendo 928. Es la
     * condición que hace que esto sea seguro y no una forma elegante de
     * asociarle a una enseñanza el texto de otra.
     */
    public static function claveDe(string $ruta): string
    {
        $carpeta = pathinfo($ruta, PATHINFO_DIRNAME);
        $base = pathinfo($ruta, PATHINFO_FILENAME);

        $clave = mb_strtolower(($carpeta === '.' ? '' : $carpeta.'/').$base);

        return trim((string) preg_replace('/\s+/u', ' ', str_replace('_', ' ', $clave)));
    }

    private function esMejor(string $candidato, string $actual): bool
    {
        return Transcripcion::calidadDe(pathinfo($candidato, PATHINFO_EXTENSION))
            < Transcripcion::calidadDe(pathinfo($actual, PATHINFO_EXTENSION));
    }

    /**
     * Guarda el documento y su texto.
     *
     * Es el único camino que escribe una transcripción, y lo usan tanto el
     * barrido de la nube como la subida a mano: así el texto se saca igual, se
     * guarda igual y no hay dos verdades sobre qué es una transcripción.
     */
    public function guardar(Pista $pista, string $nombre, string $bytes, string $origen): Transcripcion
    {
        $formato = mb_strtolower(pathinfo($nombre, PATHINFO_EXTENSION));

        // El original siempre, aunque no se le haya podido sacar el texto: para
        // los 12 .doc viejos es lo único que va a haber para ofrecer.
        Storage::disk(self::DISCO)->put(
            self::CARPETA.'/'.$pista->clave.'.'.$formato,
            $bytes,
        );

        $extraido = ($this->texto)($bytes, $nombre);

        return Transcripcion::updateOrCreate(
            ['pista_id' => $pista->id],
            [
                'archivo' => $nombre,
                'formato' => $formato,
                'origen' => $origen,
                'texto' => $extraido?->texto,
                'marcas' => $extraido?->marcas,
                'palabras' => $extraido?->palabras() ?? 0,
                'bytes' => strlen($bytes),
            ],
        );
    }

    /**
     * Deja constancia de que esta pista ya se miró.
     *
     * Se llama tenga o no documento al lado. Sin esto, las pistas que no tienen
     * vuelven a la cola en cada corrida y el barrido nunca avanza más allá de
     * las primeras — el mismo error que ya se pagó con las carátulas.
     */
    public function marcarRevisada(Pista $pista): void
    {
        $pista->forceFill(['transcripcion_revisada_en' => now()])->save();
    }
}
