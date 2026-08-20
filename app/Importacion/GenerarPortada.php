<?php

namespace App\Importacion;

use App\Models\Maestro;
use App\Models\Serie;
use GdImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Dibuja una carátula para las series que no tienen ninguna imagen.
 *
 * Medido sobre la biblioteca real: 62 de 145 series no traen carátula en ningún
 * lado —ni el flyer en la carpeta ni una imagen adentro del mp3—. Sin esto, la
 * grilla queda con más de un tercio de las fichas idénticas y no se distinguen
 * de un vistazo.
 *
 * El color no es al azar: sale del título, así que la misma serie tiene siempre
 * el mismo tono y uno termina reconociéndola por el color antes de leerla.
 */
class GenerarPortada
{
    private const DISCO = 'local';

    private const CARPETA = 'portadas';

    private const LADO = 1200;

    /** El tamaño al que se calcula el fondo antes de ampliarlo. */
    private const BOCETO = 150;

    /**
     * El texto vive en la franja del medio.
     *
     * La grilla recorta las carátulas a 16/10 y la ficha de la serie las muestra
     * cuadradas. Si el título llegara hasta el borde, en la grilla aparecería
     * cortado por la mitad. Esta franja es lo que sobrevive a los dos formatos.
     */
    private const MARGEN_VERTICAL = 250;

    private const MARGEN_LATERAL = 110;

    public function __invoke(Serie $serie): void
    {
        $lienzo = $this->fondo($this->tonoDe($serie->titulo));

        $this->texto($lienzo, $serie);

        ob_start();
        imagejpeg($lienzo, null, 86);
        $jpg = (string) ob_get_clean();
        imagedestroy($lienzo);

        $ruta = self::CARPETA.'/serie-'.$serie->id.'.jpg';
        Storage::disk(self::DISCO)->put($ruta, $jpg);

        $serie->forceFill([
            'portada' => $ruta,
            'portada_origen' => Serie::PORTADA_GENERADA,
            'portada_revisada_en' => now(),
        ])->save();
    }

    /**
     * El tono, sacado del título.
     *
     * Se queda dentro del celeste de la app —de 186° a 224°— en vez de recorrer
     * la rueda entera: una grilla con series verdes, rojas y violetas dejaría de
     * parecer la misma biblioteca.
     */
    private function tonoDe(string $titulo): int
    {
        return 186 + (int) (hexdec(substr(md5($titulo), 0, 6)) % 39);
    }

    /**
     * El fondo: degradé celeste con un resplandor arriba a la izquierda.
     *
     * Se dibuja en 150x150 y se amplía a 1200.
     *
     * No es una economía: es la forma de que salga suave. Pintar el degradé
     * directamente a tamaño real, franja por franja, deja bandas visibles —cada
     * fila es un color plano— y el resplandor hecho con elipses concéntricas
     * queda como un manchón con anillos. Ampliar con interpolación bilineal
     * mezcla los píxeles y da la transición continua que un degradé necesita.
     * La resolución no hace falta: un degradé no tiene detalle que perder. El
     * texto se dibuja después, a tamaño real, así que sale nítido.
     */
    private function fondo(int $tono): GdImage
    {
        $chico = imagecreatetruecolor(self::BOCETO, self::BOCETO);

        if ($chico === false) {
            throw new RuntimeException('GD no pudo crear el fondo.');
        }

        for ($y = 0; $y < self::BOCETO; $y++) {
            for ($x = 0; $x < self::BOCETO; $x++) {
                $paso = $y / self::BOCETO;

                [$r, $g, $b] = $this->hsl(
                    $tono + ($paso * 16),
                    0.70 - ($paso * 0.12),
                    0.44 - ($paso * 0.30),
                );

                // El resplandor: un halo blando centrado arriba a la izquierda,
                // que se apaga con el cuadrado de la distancia. Sin el cuadrado
                // el borde se nota como una línea.
                $dx = ($x / self::BOCETO) - 0.24;
                $dy = ($y / self::BOCETO) - 0.16;
                $lejos = sqrt($dx * $dx + $dy * $dy) / 0.62;
                $luz = $lejos >= 1 ? 0 : (1 - $lejos) ** 2 * 0.30;

                imagesetpixel($chico, $x, $y, $this->color(
                    $chico,
                    (int) round($r + (255 - $r) * $luz),
                    (int) round($g + (255 - $g) * $luz),
                    (int) round($b + (255 - $b) * $luz),
                ));
            }
        }

        $grande = imagescale($chico, self::LADO, self::LADO, IMG_BILINEAR_FIXED);
        imagedestroy($chico);

        if ($grande === false) {
            throw new RuntimeException('GD no pudo ampliar el fondo.');
        }

        return $grande;
    }

    private function texto(GdImage $lienzo, Serie $serie): void
    {
        $fuente = $this->fuente();
        $blanco = $this->color($lienzo, 255, 255, 255);
        $tenue = $this->color($lienzo, 214, 234, 246);

        $ancho = self::LADO - (self::MARGEN_LATERAL * 2);

        // El título, con el cuerpo elegido según cuánto texto haya: un título de
        // dos palabras merece letras grandes, uno de doce no entra.
        $cuerpo = mb_strlen($serie->titulo) > 46 ? 58 : (mb_strlen($serie->titulo) > 24 ? 70 : 86);
        $lineas = array_slice($this->partir($serie->titulo, $fuente, $cuerpo, $ancho), 0, 4);

        $altoLinea = (int) ($cuerpo * 1.32);
        $altoTitulo = count($lineas) * $altoLinea;

        $y = (int) ((self::LADO - $altoTitulo) / 2) + $cuerpo;

        foreach ($lineas as $linea) {
            $this->centrado($lienzo, $linea, $fuente, $cuerpo, $y, $blanco, negrita: true);
            $y += $altoLinea;
        }

        // Arriba del título, el tipo de enseñanza; abajo, quién la dio y cuándo.
        $arriba = (int) ((self::LADO - $altoTitulo) / 2) - 34;

        if ($arriba > self::MARGEN_VERTICAL && $serie->tipo) {
            $this->centrado($lienzo, mb_strtoupper($serie->tipo), $fuente, 30, $arriba, $tenue);
        }

        $pie = array_filter([
            $serie->maestros->map(fn (Maestro $m) => $m->nombre)->take(2)->implode(' · '),
            $serie->anio ? (string) $serie->anio : null,
        ]);

        if ($pie !== []) {
            $abajo = $y + 24;

            if ($abajo < self::LADO - self::MARGEN_VERTICAL) {
                $this->centrado($lienzo, implode('  ·  ', $pie), $fuente, 34, $abajo, $tenue);
            }
        }
    }

    /**
     * Parte el título en líneas que entren en el ancho disponible.
     *
     * Se mide con la fuente de verdad y no contando caracteres: "Mahamudra" y
     * "iiiiiiiii" tienen las mismas letras y ocupan menos de la mitad una que
     * la otra.
     *
     * @return list<string>
     */
    private function partir(string $texto, string $fuente, int $cuerpo, int $ancho): array
    {
        $lineas = [];
        $actual = '';

        foreach (preg_split('/\s+/u', trim($texto)) ?: [] as $palabra) {
            $prueba = $actual === '' ? $palabra : $actual.' '.$palabra;

            if ($this->ancho($prueba, $fuente, $cuerpo) <= $ancho || $actual === '') {
                $actual = $prueba;

                continue;
            }

            $lineas[] = $actual;
            $actual = $palabra;
        }

        if ($actual !== '') {
            $lineas[] = $actual;
        }

        return $lineas;
    }

    private function ancho(string $texto, string $fuente, int $cuerpo): int
    {
        $caja = imagettfbbox($cuerpo, 0, $fuente, $texto);

        return $caja === false ? 0 : $caja[2] - $caja[0];
    }

    private function centrado(GdImage $lienzo, string $texto, string $fuente, int $cuerpo, int $y, int $color, bool $negrita = false): void
    {
        $x = (int) ((self::LADO - $this->ancho($texto, $fuente, $cuerpo)) / 2);

        imagettftext($lienzo, $cuerpo, 0, $x, $y, $color, $fuente, $texto);

        /*
         * Instrument Sans viene como fuente variable y FreeType la dibuja en su
         * peso normal. Repetir el trazo corrido un píxel la engorda lo justo
         * para que el título pese más que el resto, sin cargar un segundo
         * archivo sólo para eso.
         */
        if ($negrita) {
            imagettftext($lienzo, $cuerpo, 0, $x + 1, $y, $color, $fuente, $texto);
        }
    }

    /**
     * Asigna un color, acotando los valores al rango que GD acepta.
     *
     * El recorte no es defensa por si acaso: el degradé calcula los canales con
     * aritmética de punto flotante y redondear 255,4 da 255, pero redondear
     * 255,6 daría 256 y GD lo rechaza.
     */
    private function color(GdImage $lienzo, int $r, int $g, int $b, int $alpha = 0): int
    {
        $color = imagecolorallocatealpha(
            $lienzo,
            max(0, min(255, $r)),
            max(0, min(255, $g)),
            max(0, min(255, $b)),
            max(0, min(127, $alpha)),
        );

        // Sólo falla si la paleta está llena, cosa que en una imagen truecolor
        // no pasa. Si pasara, negro es mejor que dejar de dibujar.
        return $color === false ? 0 : $color;
    }

    private function fuente(): string
    {
        $ruta = resource_path('fonts/InstrumentSans.ttf');

        if (! is_file($ruta)) {
            throw new RuntimeException("No encontré la tipografía en {$ruta}.");
        }

        return $ruta;
    }

    /**
     * HSL a RGB. GD sólo entiende RGB, pero pensar el degradé en tonos es lo que
     * permite variar el color por serie sin recalcular tres canales a mano.
     *
     * @return array{int, int, int}
     */
    private function hsl(float $tono, float $saturacion, float $luz): array
    {
        $tono = fmod($tono, 360) / 360;

        $c = (1 - abs(2 * $luz - 1)) * $saturacion;
        $x = $c * (1 - abs(fmod($tono * 6, 2) - 1));
        $m = $luz - $c / 2;

        [$r, $g, $b] = match ((int) floor($tono * 6)) {
            0 => [$c, $x, 0],
            1 => [$x, $c, 0],
            2 => [0, $c, $x],
            3 => [0, $x, $c],
            4 => [$x, 0, $c],
            default => [$c, 0, $x],
        };

        return [
            (int) round(($r + $m) * 255),
            (int) round(($g + $m) * 255),
            (int) round(($b + $m) * 255),
        ];
    }
}
