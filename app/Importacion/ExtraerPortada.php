<?php

namespace App\Importacion;

use App\Models\Serie;
use getID3;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Busca la carátula de una serie, por dos caminos y en este orden.
 *
 * 1. El flyer suelto en la carpeta del evento. Medido sobre la biblioteca real:
 *    hay 110 jpg conviviendo con los mp3, y para varias series ésa es la ÚNICA
 *    imagen que existe, porque sus grabaciones no traen ninguna adentro.
 * 2. La carátula embebida en los audios. No sólo en el primero: hay series
 *    donde la primera grabación viene pelada y la segunda sí la trae, y mirar
 *    únicamente la primera las daba por perdidas.
 *
 * Una por serie y no una por pista, a propósito: en una biblioteca de retiros
 * todas las grabaciones de un mismo evento comparten la ilustración, y sacar 928
 * imágenes iguales cuesta 20 veces más y no muestra nada nuevo.
 *
 * Los mp3 no se descargan: la carátula vive en el encabezado, así que alcanza
 * con el primer megabyte y medio. Bajarlos enteros para esto serían ~23 GB.
 */
class ExtraerPortada
{
    private const DISCO = 'local';

    private const CARPETA = 'portadas';

    /**
     * Cuánto encabezado se pide.
     *
     * Empezó en 400 KB y se quedó corto: hay series cuya carátula embebida pesa
     * 748 KB, así que el pedido las cortaba justo a la mitad y quedaban sin
     * imagen. Pedir de más no cuesta: medido contra la nube, 400 KB tardan 8,1 s
     * y 2,5 MB tardan 7,8 s, porque lo que se paga es la ida y vuelta.
     */
    private const CABECERA = 1500000;

    /**
     * Cuántos audios de la serie se miran antes de darse por vencido.
     *
     * Cada lectura son unos diez segundos, así que no se puede recorrer un
     * retiro de treinta clases entero. Con seis alcanza: cuando la carátula
     * está, aparece en las primeras.
     */
    private const AUDIOS_A_MIRAR = 6;

    /** Una imagen suelta más grande que esto no es un flyer, es una foto en crudo. */
    private const IMAGEN_MAXIMA = 12000000;

    /** El lado más largo de la carátula guardada. Alcanza y sobra para la grilla. */
    private const LADO_MAXIMO = 1400;

    public function __construct(private readonly EscanearFuente $escanear) {}

    /**
     * @param  list<ArchivoEnLaFuente>  $imagenesDeLaCarpeta  las que ya listó quien llama
     * @return bool si quedó una carátula nueva
     */
    public function __invoke(Serie $serie, array $imagenesDeLaCarpeta = []): bool
    {
        $imagen = $this->deLaCarpeta($serie, $imagenesDeLaCarpeta)
            ?? $this->deLosAudios($serie);

        $cambios = ['portada_revisada_en' => now()];

        if ($imagen !== null) {
            $ruta = self::CARPETA.'/serie-'.$serie->id.'.jpg';
            Storage::disk(self::DISCO)->put($ruta, $imagen);
            $cambios['portada'] = $ruta;
        }

        /*
         * La marca de revisión se guarda SIEMPRE, haya imagen o no. Sin eso, una
         * serie cuyo audio no trae carátula vuelve a la cola en cada tanda y el
         * trabajo nunca avanza más allá de las primeras.
         */
        $serie->forceFill($cambios)->save();

        return $imagen !== null;
    }

    /**
     * El flyer que está al lado de los mp3.
     *
     * @param  list<ArchivoEnLaFuente>  $imagenes
     */
    private function deLaCarpeta(Serie $serie, array $imagenes): ?string
    {
        $elegida = $this->mejorImagen($imagenes);

        if ($elegida === null) {
            return null;
        }

        $fuente = $serie->fuente;

        // `cabecera` con el tamaño exacto trae el archivo entero sin necesidad
        // de escribirlo en disco primero, que es lo que hace `traer`.
        $bytes = $this->escanear->lectorPara($fuente)->cabecera(
            $fuente->ruta,
            $elegida->ruta,
            $elegida->bytes + 1024,
        );

        return $bytes === null ? null : $this->normalizar($bytes);
    }

    /**
     * Cuál de las imágenes de la carpeta se usa.
     *
     * Primero la que se llama como una carátula. Si ninguna lo hace —hay
     * carpetas con quince fotos del curso— se toma la más pesada, que suele ser
     * la de mejor resolución. Es una regla arbitraria, pero explicable y
     * estable: la misma carpeta elige siempre lo mismo.
     *
     * @param  list<ArchivoEnLaFuente>  $imagenes
     */
    private function mejorImagen(array $imagenes): ?ArchivoEnLaFuente
    {
        $imagenes = array_values(array_filter(
            $imagenes,
            fn (ArchivoEnLaFuente $i) => $i->bytes > 1024 && $i->bytes <= self::IMAGEN_MAXIMA,
        ));

        if ($imagenes === []) {
            return null;
        }

        foreach ($imagenes as $imagen) {
            if (preg_match('/(portada|caratula|carátula|cover|folder|front)/i', $imagen->nombre)) {
                return $imagen;
            }
        }

        usort($imagenes, fn (ArchivoEnLaFuente $a, ArchivoEnLaFuente $b) => $b->bytes <=> $a->bytes);

        return $imagenes[0];
    }

    /** La carátula embebida, mirando varios audios y no sólo el primero. */
    private function deLosAudios(Serie $serie): ?string
    {
        $fuente = $serie->fuente;
        $lector = $this->escanear->lectorPara($fuente);

        $pistas = $serie->pistas()
            ->where('en_nube', true)
            ->orderBy('orden')
            ->limit(self::AUDIOS_A_MIRAR)
            ->get();

        foreach ($pistas as $pista) {
            $cabecera = $lector->cabecera($fuente->ruta, $pista->ruta, self::CABECERA);

            if ($cabecera === null) {
                continue;
            }

            $imagen = $this->imagenDe($cabecera);

            if ($imagen !== null) {
                return $this->normalizar($imagen);
            }
        }

        return null;
    }

    /**
     * getID3 trabaja sobre un archivo, no sobre una cadena, así que el pedazo
     * de encabezado se escribe en un temporal. Se borra siempre, incluso si el
     * análisis explota: sin eso, una biblioteca grande deja cientos de archivos
     * sueltos en el temporal del sistema.
     */
    private function imagenDe(string $cabecera): ?string
    {
        $temporal = tempnam(sys_get_temp_dir(), 'dharmify-portada-');

        if ($temporal === false) {
            return null;
        }

        try {
            file_put_contents($temporal, $cabecera);

            $datos = (new getID3)->analyze($temporal);
            $imagen = $datos['comments']['picture'][0]['data'] ?? null;

            // Un encabezado cortado puede dejar una imagen a medias. Menos de
            // 1 KB no es una carátula: es basura que rompería la grilla.
            return is_string($imagen) && strlen($imagen) > 1024 ? $imagen : null;
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($temporal);
        }
    }

    /**
     * Deja todo como un jpg de tamaño razonable.
     *
     * Hace falta porque las dos fuentes traen cosas distintas: los flyers de las
     * carpetas son png y jpg de hasta 2,7 MB, y todo se guarda con nombre .jpg y
     * se sirve como image/jpeg. Sin normalizar, un png quedaría mintiendo sobre
     * su propio tipo.
     *
     * Si GD no puede con la imagen se devuelve tal cual: una carátula rara es
     * mejor que ninguna, y el navegador suele arreglárselas.
     */
    private function normalizar(string $bytes): ?string
    {
        try {
            $imagen = @imagecreatefromstring($bytes);

            if ($imagen === false) {
                return strlen($bytes) > 1024 ? $bytes : null;
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

            return strlen($jpg) > 1024 ? $jpg : null;
        } catch (Throwable) {
            return strlen($bytes) > 1024 ? $bytes : null;
        }
    }
}
