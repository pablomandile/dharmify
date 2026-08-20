<?php

namespace App\Importacion;

/**
 * Un archivo encontrado en una fuente, ya normalizado.
 *
 * `ruta` es relativa a la raíz de la fuente y usa siempre "/", venga de Windows
 * o de rclone: es lo que después identifica a la pista para siempre.
 */
class ArchivoDeAudio
{
    public function __construct(
        public readonly string $ruta,
        public readonly string $nombre,
        public readonly int $bytes,
    ) {}

    /**
     * La carpeta de primer nivel, que es la serie.
     *
     * Un archivo suelto en la raíz de la fuente devuelve cadena vacía; el
     * escaneo lo agrupa aparte en vez de inventarle una serie.
     */
    public function serie(): string
    {
        $partes = explode('/', $this->ruta);

        return count($partes) > 1 ? $partes[0] : '';
    }
}
