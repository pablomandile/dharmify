<?php

namespace App\Importacion;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Throwable;
use ZipArchive;

/**
 * Le saca el texto a un documento de transcripción.
 *
 * Hace falta porque ningún navegador sabe mostrar un `.docx`, y 692 de los 709
 * documentos de la biblioteca lo son. Sacar el texto acá no es un mal necesario:
 * el resultado se lee mejor en el teléfono que el original, pesa 8 KB en vez de
 * 19 y viaja con el audio a la caché del dispositivo.
 *
 * No lanza nunca. Un documento que no se puede leer devuelve null, y arriba se
 * decide qué hacer con eso — en la práctica, ofrecer el original para bajar.
 */
class TextoDeDocumento
{
    /** El espacio de nombres del cuerpo de un .docx. */
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * Desde qué silencio entre dos líneas de un .srt se corta el párrafo.
     *
     * Los subtítulos vienen cortados cada 40 caracteres, así que pegarlos de
     * corrido da un muro de renglones sueltos que no hay forma de leer. Dos
     * segundos es lo que separa una frase de la siguiente cuando alguien habla
     * pausado, que es como se enseña Dharma.
     */
    private const PAUSA = 2.0;

    /** A partir de acá, un punto final es buen lugar para cortar el párrafo. */
    private const PARRAFO_LARGO = 500;

    /**
     * Un párrafo que es sólo una señal de tiempo: "(0:03 - 4:39)".
     *
     * Estas transcripciones salieron de un pasador automático que deja el rango
     * de cada tramo en su propio párrafo. Medido sobre la biblioteca real: 584
     * de 643 los traen. Es lo que hace que resaltar el texto al ritmo del audio
     * no dependa de convertir nada a .srt.
     */
    private const SENAL_EN_PARRAFO = '/^\(\s*(\d{1,2}:\d{2}(?::\d{2})?)\s*[-\x{2013}\x{2014}]\s*(\d{1,2}:\d{2}(?::\d{2})?)\s*\)$/u';

    /**
     * Cuántas señales hacen falta para creer que el documento está marcado.
     *
     * Con menos, lo más probable es que sea una mención suelta de un horario en
     * medio del texto, y tomarla por una marca partiría la transcripción en un
     * lugar arbitrario.
     */
    private const SENALES_MINIMAS = 4;

    public function __invoke(string $bytes, string $nombre): ?TextoExtraido
    {
        $formato = mb_strtolower(pathinfo($nombre, PATHINFO_EXTENSION));

        try {
            return match ($formato) {
                'docx' => $this->deWord($bytes),
                'srt', 'vtt' => $this->deSubtitulos($bytes),
                'txt' => $this->dePlano($bytes),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Un .docx es un ZIP con el texto en `word/document.xml`.
     *
     * Por eso no hace falta ninguna librería: `ext-zip` y `ext-dom` ya están en
     * el server, y en un hosting compartido donde no se puede instalar nada eso
     * es la diferencia entre poder y no poder.
     */
    private function deWord(string $bytes): ?TextoExtraido
    {
        $temporal = tempnam(sys_get_temp_dir(), 'dharmify-docx-');

        if ($temporal === false) {
            return null;
        }

        try {
            file_put_contents($temporal, $bytes);

            $zip = new ZipArchive;

            if ($zip->open($temporal) !== true) {
                return null;
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            if ($xml === false) {
                return null;
            }

            $documento = new DOMDocument;

            // Los .docx del mundo real traen entidades y rarezas que no vale la
            // pena reportar: lo que importa es el texto que sí se pudo leer.
            if (! @$documento->loadXML($xml)) {
                return null;
            }

            $xpath = new DOMXPath($documento);
            $xpath->registerNamespace('w', self::W);

            $parrafos = [];

            /*
             * Un <w:p> es un párrafo de Word; adentro, los <w:t> son los
             * pedacitos en que Word parte el texto al aplicarle formato. Una
             * palabra en negrita en medio de una oración ya son tres <w:t>.
             */
            foreach ($xpath->query('//w:p') ?: [] as $parrafo) {
                if (! $parrafo instanceof DOMElement) {
                    continue;
                }

                $texto = '';

                foreach ($xpath->query('.//w:t|.//w:tab|.//w:br', $parrafo) ?: [] as $nodo) {
                    if (! $nodo instanceof DOMElement) {
                        continue;
                    }

                    $texto .= match ($nodo->localName) {
                        'tab' => "\t",
                        'br' => "\n",
                        default => $nodo->textContent,
                    };
                }

                $texto = trim($texto);

                if ($texto !== '') {
                    $parrafos[] = $texto;
                }
            }

            return $parrafos === []
                ? null
                : $this->conSusSenales($parrafos);
        } finally {
            @unlink($temporal);
        }
    }

    /**
     * Separa las señales de tiempo del texto de un documento de Word.
     *
     * Las señales salen del texto —leerlas de corrido es ruido— y se guardan
     * aparte con sus segundos, igual que si el archivo hubiera sido un .srt.
     * Para todo lo que está más arriba, un .docx marcado y un .srt son la misma
     * cosa, y por eso el panel ya sabe dibujarlos sin cambiar una línea.
     *
     * Si el documento no está marcado, devuelve el texto tal cual: de las 643
     * de la biblioteca, 59 son transcripciones escritas a mano y no lo están.
     *
     * @param  list<string>  $parrafos
     */
    private function conSusSenales(array $parrafos): TextoExtraido
    {
        /** @var list<array{inicio: float, fin: float, texto: string}> $marcas */
        $marcas = [];
        $sueltos = [];
        $abierta = null;
        $acumulado = [];

        foreach ($parrafos as $parrafo) {
            if (preg_match(self::SENAL_EN_PARRAFO, trim($parrafo), $m) === 1) {
                $marcas = $this->cerrando($marcas, $abierta, $acumulado);
                $abierta = [$this->deReloj($m[1]), $this->deReloj($m[2])];
                $acumulado = [];

                continue;
            }

            if ($abierta === null) {
                /*
                 * Lo que viene antes de la primera señal: casi siempre el nombre
                 * del archivo repetido arriba de todo. Se conserva en el texto
                 * pero fuera de las marcas, porque no se dice en ningún minuto.
                 */
                $sueltos[] = $parrafo;

                continue;
            }

            $acumulado[] = $parrafo;
        }

        $marcas = $this->cerrando($marcas, $abierta, $acumulado);

        if (count($marcas) < self::SENALES_MINIMAS) {
            return new TextoExtraido(implode("\n\n", $parrafos));
        }

        return new TextoExtraido(
            implode("\n\n", array_merge($sueltos, array_column($marcas, 'texto'))),
            $marcas,
        );
    }

    /**
     * Guarda el tramo que se venía juntando, si hay algo que guardar.
     *
     * @param  list<array{inicio: float, fin: float, texto: string}>  $marcas
     * @param  array{0: float, 1: float}|null  $abierta
     * @param  list<string>  $acumulado
     * @return list<array{inicio: float, fin: float, texto: string}>
     */
    private function cerrando(array $marcas, ?array $abierta, array $acumulado): array
    {
        $junto = trim(implode("\n\n", $acumulado));

        if ($abierta !== null && $junto !== '') {
            $marcas[] = ['inicio' => $abierta[0], 'fin' => $abierta[1], 'texto' => $junto];
        }

        return $marcas;
    }

    /** "4:39" o "1:05:00" a segundos. */
    private function deReloj(string $reloj): float
    {
        $partes = array_map('intval', explode(':', $reloj));

        return count($partes) === 3
            ? $partes[0] * 3600 + $partes[1] * 60 + $partes[2]
            : $partes[0] * 60 + $partes[1];
    }

    /**
     * Un .srt o un .vtt: texto con los tiempos adentro.
     *
     * Es el único formato que permite resaltar el texto al ritmo del audio, y
     * por eso le gana a cualquier otro cuando un audio termina con más de un
     * documento al lado.
     */
    private function deSubtitulos(string $bytes): ?TextoExtraido
    {
        /*
         * Los dos formatos se leen igual: bloques separados por una línea en
         * blanco, cada uno con su señal de tiempo y su texto debajo. Recorrer
         * bloques y no líneas sueltas evita ir arrastrando estado a mano
         * mientras se avanza, que es donde esta clase de parser se enreda.
         */
        $bloques = preg_split('/\R\s*\R/u', trim($this->aUtf8($bytes))) ?: [];

        /** @var list<array{inicio: float, fin: float, texto: string}> $marcas */
        $marcas = [];

        foreach ($bloques as $bloque) {
            $marca = $this->marcaDe($bloque);

            if ($marca !== null) {
                $marcas[] = $marca;
            }
        }

        return $marcas === []
            ? null
            : new TextoExtraido($this->enParrafos($marcas), $marcas);
    }

    /**
     * Un bloque de subtítulo: cuándo se dice y qué se dice.
     *
     * @return array{inicio: float, fin: float, texto: string}|null
     */
    private function marcaDe(string $bloque): ?array
    {
        /*
         * Las señales llevan coma en .srt y punto en .vtt. El mismo patrón sirve
         * para los dos, que es lo que hace casi gratis aceptar también .vtt — el
         * formato que entiende el <track> nativo del navegador.
         */
        $senal = '/(\d{1,2}):(\d{2}):(\d{2})[,.](\d{1,3})\s*-->\s*(\d{1,2}):(\d{2}):(\d{2})[,.](\d{1,3})/';

        $tiempos = null;
        $texto = [];

        foreach (preg_split('/\R/u', $bloque) ?: [] as $linea) {
            if ($tiempos === null && preg_match($senal, $linea, $m) === 1) {
                $tiempos = [
                    $this->enSegundos($m[1], $m[2], $m[3], $m[4]),
                    $this->enSegundos($m[5], $m[6], $m[7], $m[8]),
                ];

                continue;
            }

            $limpia = trim($linea);

            /*
             * Antes de la señal sólo hay ruido: el número de orden del bloque, o
             * el encabezado WEBVTT si es el primero del archivo.
             */
            if ($tiempos === null || $limpia === '') {
                continue;
            }

            // Las etiquetas de karaoke y de voz que a veces traen los .vtt.
            $texto[] = trim(strip_tags($limpia));
        }

        $junto = trim(implode(' ', $texto));

        if ($tiempos === null || $junto === '') {
            return null;
        }

        return ['inicio' => $tiempos[0], 'fin' => $tiempos[1], 'texto' => $junto];
    }

    /**
     * Junta las líneas sueltas de un subtítulo en párrafos que se puedan leer.
     *
     * @param  list<array{inicio: float, fin: float, texto: string}>  $marcas
     */
    private function enParrafos(array $marcas): string
    {
        $parrafos = [];
        $actual = '';
        $finAnterior = null;

        foreach ($marcas as $marca) {
            $silencio = $finAnterior !== null && ($marca['inicio'] - $finAnterior) > self::PAUSA;

            $yaEsLargo = mb_strlen($actual) > self::PARRAFO_LARGO
                && preg_match('/[.!?\x{2026}]["\x{00BB}]?$/u', $actual) === 1;

            if ($actual !== '' && ($silencio || $yaEsLargo)) {
                $parrafos[] = $actual;
                $actual = '';
            }

            $actual = $actual === '' ? $marca['texto'] : $actual.' '.$marca['texto'];
            $finAnterior = $marca['fin'];
        }

        if ($actual !== '') {
            $parrafos[] = $actual;
        }

        return implode("\n\n", $parrafos);
    }

    private function dePlano(string $bytes): ?TextoExtraido
    {
        $texto = trim($this->aUtf8($bytes));

        return $texto === '' ? null : new TextoExtraido($texto);
    }

    /**
     * Los .txt y .srt viejos vienen en latin-1 y sin declararlo.
     *
     * Sin esto, un texto con acentos llega con bytes inválidos y rompe el JSON
     * de la respuesta. Es el mismo problema —y la misma solución— que apareció
     * al leer las etiquetas ID3v1 de los mp3.
     */
    private function aUtf8(string $bytes): string
    {
        /*
         * El BOM de UTF-8, que el Bloc de notas de Windows agrega solo y que
         * después aparece como un símbolo raro al principio del texto.
         */
        $bytes = preg_replace('/^\xEF\xBB\xBF/', '', $bytes) ?? $bytes;

        if (! mb_check_encoding($bytes, 'UTF-8')) {
            $bytes = (string) mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
        }

        return $bytes;
    }

    private function enSegundos(string $h, string $m, string $s, string $ms): float
    {
        return (int) $h * 3600
            + (int) $m * 60
            + (int) $s
            + ((int) str_pad($ms, 3, '0') / 1000);
    }
}
