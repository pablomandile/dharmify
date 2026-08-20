<?php

namespace App\Console\Commands;

use GdImage;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Arma todos los íconos de la app a partir del logo.
 *
 * Es un comando y no un script suelto porque el logo ya cambió una vez y va a
 * volver a cambiar: cada vez hay que rehacer seis archivos de cinco tamaños
 * distintos, y hacerlo a mano es la forma segura de que uno quede viejo.
 *
 * Lo que NO hace es acordarse de subir el `?v=`. Eso vive en tres lugares —el
 * blade, el manifest y el sw.js— y el comando lo recuerda al terminar.
 */
class GenerarIconos extends Command
{
    protected $signature = 'dharma:iconos
        {logo=storage/app/public/img/logo1.png : El archivo del que se recortan}';

    protected $description = 'Rehace el favicon y los íconos de la PWA a partir del logo';

    /**
     * El logo viene apaisado y con la palabra "Dharmify" abajo. Para un ícono
     * hace falta sólo la marca, así que se busca dónde está lo que brilla.
     *
     * El número sale de medir el logo, no de probar a ojo: la flor y los
     * auriculares llegan a 255 y el 85% de sus píxeles pasa de 100; la palabra
     * NO supera 100 en ningún píxel y el fondo no pasa de 1. Con 110 quedan
     * separadas con margen. Con 70 —el primer valor que puse— la palabra entraba
     * en el recorte y aparecía cortada al pie del ícono.
     */
    private const UMBRAL = 110;

    /** Aire alrededor de la marca, en proporción a su lado. */
    private const AIRE = 0.12;

    /**
     * El recorte extra del ícono maskable.
     *
     * Android le pasa una máscara por encima —círculo, cuadrado redondeado, gota
     * según el teléfono— y se queda con el 80% del centro. Sin este aire, en un
     * lanzador circular la flor aparecería con los pétalos cortados.
     */
    private const AIRE_MASCARA = 0.30;

    public function handle(): int
    {
        $origen = base_path((string) $this->argument('logo'));

        if (! is_file($origen)) {
            $this->error("No encontré el logo en {$origen}.");

            return self::FAILURE;
        }

        $logo = @imagecreatefrompng($origen);

        if ($logo === false) {
            $this->error('No pude abrir el logo. Tiene que ser un png.');

            return self::FAILURE;
        }

        $marca = $this->recortarLaMarca($logo);
        imagedestroy($logo);

        $salidas = [
            'public/icons/icon-192.png' => [192, self::AIRE],
            'public/icons/icon-512.png' => [512, self::AIRE],
            'public/icons/icon-512-maskable.png' => [512, self::AIRE_MASCARA],
            'public/apple-touch-icon.png' => [180, self::AIRE],
        ];

        foreach ($salidas as $ruta => [$lado, $aire]) {
            $icono = $this->cuadrado($marca, $lado, $aire);
            imagepng($icono, base_path($ruta), 9);
            imagedestroy($icono);

            $this->line('  ✓ '.$ruta.'  ('.$lado.'px)');
        }

        $this->escribirIco($marca);
        $this->line('  ✓ public/favicon.ico  (32px)');

        imagedestroy($marca);

        $this->newLine();
        $this->warn('Falta subir el ?v= en los tres lugares: el blade, manifest.webmanifest y CACHE_APP de sw.js.');

        return self::SUCCESS;
    }

    /** Busca el rectángulo que ocupa lo que brilla y lo recorta cuadrado. */
    private function recortarLaMarca(GdImage $logo): GdImage
    {
        $ancho = imagesx($logo);
        $alto = imagesy($logo);

        $x1 = $ancho;
        $y1 = $alto;
        $x2 = 0;
        $y2 = 0;

        // De a dos píxeles: para encontrar un borde no hace falta mirarlos todos,
        // y sobre un logo de 1536x1024 son un millón y medio de lecturas.
        for ($y = 0; $y < $alto; $y += 2) {
            for ($x = 0; $x < $ancho; $x += 2) {
                $rgb = imagecolorat($logo, $x, $y);

                $luz = 0.2126 * (($rgb >> 16) & 0xFF)
                    + 0.7152 * (($rgb >> 8) & 0xFF)
                    + 0.0722 * ($rgb & 0xFF);

                if ($luz < self::UMBRAL) {
                    continue;
                }

                $x1 = min($x1, $x);
                $y1 = min($y1, $y);
                $x2 = max($x2, $x);
                $y2 = max($y2, $y);
            }
        }

        if ($x2 <= $x1 || $y2 <= $y1) {
            throw new RuntimeException('No encontré nada que brille en el logo.');
        }

        // El recorte es cuadrado y centrado en la marca, para que la flor no
        // quede corrida en un ícono redondo.
        $lado = max($x2 - $x1, $y2 - $y1);
        $cx = (int) (($x1 + $x2) / 2);
        $cy = (int) (($y1 + $y2) / 2);

        $marca = imagecreatetruecolor(max(1, $lado), max(1, $lado));

        if ($marca === false) {
            throw new RuntimeException('GD no pudo crear el recorte.');
        }

        // El fondo del logo es negro y el resplandor se funde con él: recortar
        // sobre negro evita el halo que dejaría cualquier otro color.
        imagefilledrectangle($marca, 0, 0, $lado, $lado, (int) imagecolorallocate($marca, 0, 0, 0));
        imagecopy($marca, $logo, 0, 0, $cx - (int) ($lado / 2), $cy - (int) ($lado / 2), $lado, $lado);

        $this->apagarLosBordes($marca, ($x2 - $x1) / 2, ($y2 - $y1) / 2);

        return $marca;
    }

    /**
     * Apaga lo que queda fuera de la marca, con una caída suave.
     *
     * Hace falta porque el recorte cuadrado de una marca apaisada sobra por
     * arriba y por abajo, y ahí abajo está la palabra "Dharmify", que aparecía
     * cortada al pie del ícono.
     *
     * Cortar en seco no sirve: midiendo el logo franja por franja, del pie de la
     * marca hacia abajo el brillo baja 165 → 116 → 90 → 69 y recién ahí se
     * aplana en el 47 de la palabra. No hay valle donde tijeretear —el
     * resplandor de la marca y el de la palabra se funden—, así que un corte
     * dejaría una costura visible.
     *
     * La caída es elíptica y no circular porque la marca es más ancha que alta
     * (824 x 616): un círculo que respetara los auriculares no llegaría a tapar
     * la palabra, y uno que tapara la palabra comería los auriculares.
     */
    private function apagarLosBordes(GdImage $marca, float $radioX, float $radioY): void
    {
        $lado = imagesx($marca);
        $centro = $lado / 2;

        for ($y = 0; $y < $lado; $y++) {
            for ($x = 0; $x < $lado; $x++) {
                $dx = ($x - $centro) / max(1, $radioX);
                $dy = ($y - $centro) / max(1, $radioY);
                $r = sqrt($dx * $dx + $dy * $dy);

                // Entero hasta el borde de la marca, apagado del todo un cuarto
                // más allá. La palabra cae en 1,23 y se va casi entera.
                if ($r <= 1.0) {
                    continue;
                }

                $factor = $r >= 1.25 ? 0.0 : 1 - (($r - 1.0) / 0.25);

                $rgb = imagecolorat($marca, $x, $y);

                imagesetpixel($marca, $x, $y, (int) imagecolorallocate(
                    $marca,
                    max(0, min(255, (int) ((($rgb >> 16) & 0xFF) * $factor))),
                    max(0, min(255, (int) ((($rgb >> 8) & 0xFF) * $factor))),
                    max(0, min(255, (int) (($rgb & 0xFF) * $factor))),
                ));
            }
        }
    }

    private function cuadrado(GdImage $marca, int $lado, float $aire): GdImage
    {
        $icono = imagecreatetruecolor(max(1, $lado), max(1, $lado));

        if ($icono === false) {
            throw new RuntimeException('GD no pudo crear el ícono.');
        }

        imagefilledrectangle($icono, 0, 0, $lado, $lado, (int) imagecolorallocate($icono, 0, 0, 0));

        $dentro = (int) round($lado * (1 - $aire * 2));
        $margen = (int) round(($lado - $dentro) / 2);

        imagecopyresampled($icono, $marca, $margen, $margen, 0, 0, $dentro, $dentro, imagesx($marca), imagesy($marca));

        return $icono;
    }

    /**
     * El favicon.ico, armado a mano.
     *
     * GD no sabe escribir .ico, pero el formato acepta un PNG adentro desde hace
     * casi veinte años: son 22 bytes de encabezado y el png pegado atrás. Sigue
     * haciendo falta porque el navegador pide /favicon.ico solo, sin mirar el
     * HTML, y ése es el ícono que aparece antes de que la página cargue.
     */
    private function escribirIco(GdImage $marca): void
    {
        $icono = $this->cuadrado($marca, 32, self::AIRE);

        ob_start();
        imagepng($icono, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($icono);

        $ico = pack('vvv', 0, 1, 1)                       // reservado, tipo 1 = ícono, un solo tamaño
            .pack('CCCC', 32, 32, 0, 0)                   // ancho, alto, colores de paleta (0 = sin paleta), reservado
            .pack('vv', 1, 32)                            // planos, bits por píxel
            .pack('VV', strlen($png), 22)                 // cuánto mide y dónde empieza
            .$png;

        file_put_contents(base_path('public/favicon.ico'), $ico);
    }
}
