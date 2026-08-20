<?php

namespace App\Importacion\Lectores;

use App\Importacion\ArchivoDeAudio;
use Symfony\Component\Process\Process;

/**
 * Lee una carpeta de la nube con rclone.
 *
 * Es el modo de producción: el server no tiene la biblioteca sincronizada.
 *
 * Se usa `lsjson`, que trae nombre, ruta y tamaño de todo en UNA llamada, en vez
 * de recorrer carpeta por carpeta. Nunca se descarga un archivo para listar: la
 * biblioteca real son ~63 GB y bajarla para armar el catálogo no es una opción.
 */
class LectorRclone implements LectorDeFuente
{
    private const EXTENSIONES = ['mp3', 'm4a', 'wav', 'ogg', 'opus', 'flac', 'aac', 'wma'];

    /** Listar una biblioteca grande puede tardar minutos. */
    private const TIMEOUT = 900;

    public function verificar(string $raiz): ?string
    {
        if (! $this->binario()) {
            return 'No encontré el binario de rclone. Tiene que estar en bin/rclone del proyecto o en el PATH.';
        }

        $proceso = new Process([$this->binario(), 'lsjson', $raiz, '--max-depth', '1'], timeout: 60);
        $proceso->run();

        if (! $proceso->isSuccessful()) {
            // El error de rclone es más útil que cualquier cosa que inventemos:
            // dice si falta autorizar, si la carpeta no existe o si no hay red.
            return trim($proceso->getErrorOutput()) ?: 'rclone no pudo leer la carpeta.';
        }

        return null;
    }

    public function listar(string $raiz): iterable
    {
        $proceso = new Process(
            [$this->binario(), 'lsjson', $raiz, '--recursive', '--files-only', '--no-modtime'],
            timeout: self::TIMEOUT,
        );
        $proceso->run();

        if (! $proceso->isSuccessful()) {
            throw new \RuntimeException(trim($proceso->getErrorOutput()) ?: 'rclone falló al listar.');
        }

        $items = json_decode($proceso->getOutput(), true) ?: [];

        foreach ($items as $item) {
            $ruta = (string) ($item['Path'] ?? '');
            $extension = mb_strtolower(pathinfo($ruta, PATHINFO_EXTENSION));

            if (! in_array($extension, self::EXTENSIONES, strict: true)) {
                continue;
            }

            yield new ArchivoDeAudio(
                ruta: $ruta,
                nombre: (string) ($item['Name'] ?? basename($ruta)),
                bytes: (int) ($item['Size'] ?? 0),
            );
        }
    }

    /**
     * Primero el binario del proyecto y después el del PATH: así el mismo código
     * anda en Windows y en el hosting, donde rclone es un archivo suelto que se
     * sube a mano porque no hay gestor de paquetes.
     */
    private function binario(): ?string
    {
        foreach (['bin/rclone.exe', 'bin/rclone'] as $relativo) {
            $ruta = base_path($relativo);

            if (is_file($ruta)) {
                return $ruta;
            }
        }

        $enPath = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where rclone 2>NUL' : 'command -v rclone 2>/dev/null'));

        return $enPath !== '' ? explode("\n", $enPath)[0] : null;
    }
}
