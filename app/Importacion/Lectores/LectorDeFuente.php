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

    /** Para avisar con claridad cuando la fuente no existe o no se puede leer. */
    public function verificar(string $raiz): ?string;
}
