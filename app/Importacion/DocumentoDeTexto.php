<?php

namespace App\Importacion;

use Throwable;
use ZipArchive;

/**
 * Escribe un documento de transcripción. Es el inverso de TextoDeDocumento.
 *
 * Hace falta para poder corregir el texto desde la app y que la corrección
 * llegue a OneDrive: la nube es la única copia que importa, así que editar sin
 * volver a escribir el documento sería editar en un papelito.
 *
 * Los dos formatos van juntos en una clase porque son la misma operación con
 * distinta salida, y porque así el ida y vuelta contra TextoDeDocumento se
 * prueba en un solo lugar. Ese ida y vuelta es lo que sostiene todo lo demás:
 * lo que se escribe acá se vuelve a leer con el importador de siempre, y si las
 * dos mitades no coincidieran, se vería en el acto.
 */
class DocumentoDeTexto
{
    /** El espacio de nombres del cuerpo de un .docx. */
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * Un .docx con el mismo formato que ya usan los 644 de la biblioteca.
     *
     * O sea: el encabezado arriba, y después cada tramo precedido por un párrafo
     * con su rango, "(0:03 - 4:39)". No es una decisión estética — es lo que
     * hace que TextoDeDocumento lo vuelva a leer sin cambiar una línea, y que
     * abrirlo en Word se vea igual que antes.
     *
     * Se arma a mano y sin librerías: un .docx es un ZIP con tres archivos, y
     * `ext-zip` ya está en el hosting. Traer una dependencia para escribir tres
     * XML en un hosting donde no se puede instalar nada sería el problema, no la
     * solución.
     *
     * @param  list<array{inicio: float, fin: float, texto: string}>  $tramos
     */
    public function docx(?string $encabezado, array $tramos, string $textoPlano = ''): ?string
    {
        $parrafos = [];

        foreach ($this->lineasDe($encabezado) as $linea) {
            $parrafos[] = $linea;
        }

        if ($tramos === []) {
            // Sin marcas —64 de las 644— el documento es texto y nada más.
            foreach ($this->lineasDe($textoPlano) as $linea) {
                $parrafos[] = $linea;
            }
        }

        foreach ($tramos as $tramo) {
            $parrafos[] = sprintf(
                '(%s - %s)',
                $this->aReloj($tramo['inicio']),
                $this->aReloj($tramo['fin']),
            );

            foreach ($this->lineasDe($tramo['texto']) as $linea) {
                $parrafos[] = $linea;
            }
        }

        if ($parrafos === []) {
            return null;
        }

        return $this->empaquetar($this->cuerpo($parrafos));
    }

    /**
     * Un .srt, que es lo mismo pero en el formato estándar de subtítulos.
     *
     * @param  list<array{inicio: float, fin: float, texto: string}>  $tramos
     */
    public function srt(array $tramos): ?string
    {
        if ($tramos === []) {
            return null;
        }

        $bloques = [];

        foreach ($tramos as $i => $tramo) {
            $bloques[] = ($i + 1)."\n"
                .$this->aRelojLargo($tramo['inicio']).' --> '.$this->aRelojLargo($tramo['fin'])."\n"
                .trim($tramo['texto']);
        }

        // Los .srt terminan con una línea en blanco; algunos lectores viejos se
        // comen el último bloque si no está.
        return implode("\n\n", $bloques)."\n";
    }

    /**
     * Un .txt: encabezado y tramos, uno por párrafo.
     *
     * Sin las señales de tiempo: un .txt no tiene forma de llevarlas, y meterlas
     * como texto lo volvería ilegible.
     *
     * @param  list<array{inicio: float, fin: float, texto: string}>  $tramos
     */
    public function txt(?string $encabezado, array $tramos, string $textoPlano = ''): ?string
    {
        $partes = array_merge(
            $this->lineasDe($encabezado),
            $tramos === []
                ? $this->lineasDe($textoPlano)
                : array_map(fn (array $t) => trim($t['texto']), $tramos),
        );

        return $partes === [] ? null : implode("\n\n", $partes)."\n";
    }

    /**
     * Un .vtt: el mismo .srt con encabezado y punto en las milésimas.
     *
     * Se genera aunque hoy no haya ninguno en la biblioteca, y no por
     * completitud: un .vtt le gana al .docx en el orden de formatos, así que si
     * existiera uno y no lo actualizáramos, el próximo barrido lo preferiría y
     * desharía la corrección que alguien acaba de hacer.
     *
     * @param  list<array{inicio: float, fin: float, texto: string}>  $tramos
     */
    public function vtt(array $tramos): ?string
    {
        $srt = $this->srt($tramos);

        if ($srt === null) {
            return null;
        }

        return "WEBVTT\n\n".str_replace(',', '.', $srt);
    }

    /**
     * El XML del cuerpo del documento.
     *
     * @param  list<string>  $parrafos
     */
    private function cuerpo(array $parrafos): string
    {
        $xml = '';

        foreach ($parrafos as $parrafo) {
            /*
             * `xml:space="preserve"` para que Word no se coma los espacios de
             * los extremos, que en una transcripción cambian el sentido de una
             * frase cortada.
             */
            $xml .= '<w:p><w:r><w:t xml:space="preserve">'
                .htmlspecialchars($parrafo, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                .'</w:t></w:r></w:p>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="'.self::W.'"><w:body>'.$xml.'</w:body></w:document>';
    }

    /** Los tres archivos que Word necesita para abrir un .docx. */
    private function empaquetar(string $documento): ?string
    {
        $temporal = tempnam(sys_get_temp_dir(), 'dharmify-escribir-');

        if ($temporal === false) {
            return null;
        }

        try {
            $zip = new ZipArchive;

            if ($zip->open($temporal, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                return null;
            }

            $zip->addFromString('[Content_Types].xml',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                .'</Types>');

            $zip->addFromString('_rels/.rels',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Target="word/document.xml" '
                .'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"/>'
                .'</Relationships>');

            $zip->addFromString('word/document.xml', $documento);
            $zip->close();

            $bytes = file_get_contents($temporal);

            return $bytes === false ? null : $bytes;
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($temporal);
        }
    }

    /**
     * Un texto en párrafos, saltándose los vacíos.
     *
     * @return list<string>
     */
    private function lineasDe(?string $texto): array
    {
        if ($texto === null || trim($texto) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/\R{2,}/u', trim($texto)) ?: []),
            fn (string $linea) => $linea !== '',
        ));
    }

    /** 279 segundos a "4:39", y 3753 a "1:02:33". */
    private function aReloj(float $segundos): string
    {
        $total = (int) round($segundos);
        $h = intdiv($total, 3600);
        $m = intdiv($total % 3600, 60);
        $s = $total % 60;

        return $h > 0
            ? sprintf('%d:%02d:%02d', $h, $m, $s)
            : sprintf('%d:%02d', $m, $s);
    }

    /** El reloj de un .srt, que siempre lleva horas y milésimas. */
    private function aRelojLargo(float $segundos): string
    {
        $total = max(0.0, $segundos);
        $enteros = (int) floor($total);

        return sprintf(
            '%02d:%02d:%02d,%03d',
            intdiv($enteros, 3600),
            intdiv($enteros % 3600, 60),
            $enteros % 60,
            (int) round(($total - $enteros) * 1000),
        );
    }
}
