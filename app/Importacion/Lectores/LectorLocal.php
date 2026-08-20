<?php

namespace App\Importacion\Lectores;

use App\Importacion\ArchivoDeAudio;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Lee una carpeta del disco.
 *
 * Es lo que permite trabajar contra el OneDrive que sincroniza Windows. Detalle
 * que importa: los archivos ahí suelen ser marcadores de "Archivos a pedido",
 * no copias reales. Listar nombres, tamaños y rutas NO los descarga; abrirlos
 * sí, y descarga el archivo ENTERO. Por eso este lector nunca lee contenido.
 */
class LectorLocal implements LectorDeFuente
{
    private const EXTENSIONES = ['mp3', 'm4a', 'wav', 'ogg', 'opus', 'flac', 'aac', 'wma'];

    public function verificar(string $raiz): ?string
    {
        if (! is_dir($raiz)) {
            return 'La carpeta no existe o no se puede leer desde el servidor.';
        }

        return null;
    }

    /**
     * OJO: sobre una carpeta de OneDrive sincronizada, leer aunque sea el primer
     * byte HIDRATA el archivo entero. Para sacar carátulas conviene correr esto
     * contra una fuente rclone, que sí trae sólo el encabezado.
     */
    public function cabecera(string $raiz, string $ruta, int $bytes = 400000): ?string
    {
        $completa = rtrim(str_replace('\\', '/', $raiz), '/').'/'.$ruta;

        if (! is_file($completa)) {
            return null;
        }

        $fh = @fopen($completa, 'rb');

        if (! $fh) {
            return null;
        }

        $datos = fread($fh, max(1, $bytes));
        fclose($fh);

        return $datos !== false ? $datos : null;
    }

    /**
     * En disco no hay nada que paralelizar: el costo no es la espera de red
     * sino la hidratación del archivo, y lanzar cuatro a la vez sólo bajaría
     * cuatro archivos enteros en vez de uno.
     */
    public function cabeceras(string $raiz, array $rutas, callable $alLlegar, int $bytes = 400000, int $paralelo = 4): void
    {
        foreach ($rutas as $ruta) {
            $alLlegar($ruta, $this->cabecera($raiz, $ruta, $bytes));
        }
    }

    public function traer(string $raiz, string $ruta, string $destino): bool
    {
        $origen = rtrim(str_replace('\\', '/', $raiz), '/').'/'.$ruta;

        if (! is_file($origen)) {
            return false;
        }

        @mkdir(dirname($destino), 0775, true);

        // copy() sobre un marcador de OneDrive lo hidrata primero: es lento la
        // primera vez, pero es exactamente lo que se está pidiendo.
        return copy($origen, $destino);
    }

    public function listar(string $raiz): iterable
    {
        $raiz = rtrim(str_replace('\\', '/', $raiz), '/');

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($it as $archivo) {
            if (! $archivo->isFile()) {
                continue;
            }

            if (! in_array(mb_strtolower($archivo->getExtension()), self::EXTENSIONES, strict: true)) {
                continue;
            }

            $completa = str_replace('\\', '/', $archivo->getPathname());

            yield new ArchivoDeAudio(
                ruta: ltrim(mb_substr($completa, mb_strlen($raiz)), '/'),
                nombre: $archivo->getFilename(),
                // getSize() sobre un marcador devuelve el tamaño real del
                // original en la nube, sin traerlo.
                bytes: (int) $archivo->getSize(),
            );
        }
    }
}
