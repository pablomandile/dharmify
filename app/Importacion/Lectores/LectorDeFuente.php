<?php

namespace App\Importacion\Lectores;

use App\Importacion\ArchivoDeAudio;

/**
 * Lista los audios de una fuente.
 *
 * Existe con dos implementaciones porque el server y la máquina de desarrollo
 * ven la misma biblioteca de formas distintas: allá por la API de OneDrive,
 * acá por la carpeta que sincroniza Windows. El escaneo no sabe cuál le tocó.
 */
interface LectorDeFuente
{
    /**
     * @return iterable<ArchivoDeAudio>
     */
    public function listar(string $raiz): iterable;

    /**
     * Los primeros bytes de un archivo, sin traerlo entero.
     *
     * Es lo que permite sacar la carátula y los tags: viven en el encabezado.
     * Bajar los archivos completos para leerlos serían decenas de gigas.
     */
    public function cabecera(string $raiz, string $ruta, int $bytes = 400000): ?string;

    /**
     * Trae un archivo completo al server.
     *
     * Es lo que pasa cuando alguien le da play a algo que está sólo en la nube.
     */
    public function traer(string $raiz, string $ruta, string $destino): bool;

    /** Para avisar con claridad cuando la fuente no existe o no se puede leer. */
    public function verificar(string $raiz): ?string;
}
