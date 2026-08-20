<?php

namespace App\Importacion;

use Throwable;

/**
 * Deja cualquier imagen como un jpg de tamaño razonable.
 *
 * Hace falta porque las carátulas entran por tres puertas distintas: el flyer de
 * la carpeta, la imagen embebida en un mp3 y lo que alguien suba a mano. Vienen
 * en png, en jpg y de hasta 2,7 MB, y todas se guardan con nombre .jpg y se
 * sirven como image/jpeg. Sin este paso, un png quedaría mintiendo sobre su
 * propio tipo y una foto de 12 megapíxeles ocuparía disco para nada.
 */
class NormalizarImagen
{
    /** El lado más largo. Alcanza y sobra para la grilla y para la pantalla de bloqueo. */
    private const LADO_MAXIMO = 1400;

    /** Menos que esto no es una carátula: es basura que rompería la grilla. */
    private const MINIMO = 1024;

    public function __invoke(string $bytes): ?string
    {
        try {
            $imagen = @imagecreatefromstring($bytes);

            /*
             * Si GD no puede con ella se devuelve tal cual: una carátula rara es
             * mejor que ninguna, y el navegador suele arreglárselas con formatos
             * que GD no compila.
             */
            if ($imagen === false) {
                return strlen($bytes) > self::MINIMO ? $bytes : null;
            }

            $ancho = imagesx($imagen);
            $alto = imagesy($imagen);
            $lado = max($ancho, $alto);

            if ($lado > self::LADO_MAXIMO) {
                $escala = self::LADO_MAXIMO / $lado;
                $achicada = imagescale($imagen, (int) round($ancho * $escala), (int) round($alto * $escala));

                if ($achicada !== false) {
                    imagedestroy($imagen);
                    $imagen = $achicada;
                }
            }

            ob_start();
            imagejpeg($imagen, null, 82);
            $jpg = (string) ob_get_clean();
            imagedestroy($imagen);

            return strlen($jpg) > self::MINIMO ? $jpg : null;
        } catch (Throwable) {
            return strlen($bytes) > self::MINIMO ? $bytes : null;
        }
    }
}
