<?php

namespace App\Importacion;

use App\Models\Serie;
use getID3;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Saca la carátula de una serie del primer audio que tenga.
 *
 * Una por serie y no una por pista, a propósito: en una biblioteca de retiros
 * todas las grabaciones de un mismo evento comparten la ilustración, y sacar 928
 * imágenes iguales cuesta 20 veces más y no muestra nada nuevo.
 *
 * No se descarga el archivo: la carátula vive en el encabezado, así que alcanza
 * con los primeros cientos de KB. Bajar los mp3 enteros para esto serían ~23 GB.
 */
class ExtraerPortada
{
    private const DISCO = 'local';

    private const CARPETA = 'portadas';

    public function __construct(private readonly EscanearFuente $escanear) {}

    /**
     * @return bool si quedó una carátula nueva
     */
    public function __invoke(Serie $serie): bool
    {
        $pista = $serie->pistas()->where('en_nube', true)->orderBy('orden')->first();

        if (! $pista) {
            return false;
        }

        $fuente = $serie->fuente;
        $cabecera = $this->escanear->lectorPara($fuente)->cabecera($fuente->ruta, $pista->ruta);
        $imagen = $cabecera ? $this->imagenDe($cabecera) : null;

        $cambios = ['portada_revisada_en' => now()];

        if ($imagen) {
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

        return (bool) $imagen;
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
}
