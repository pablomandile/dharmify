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
     * Los encabezados de muchos archivos, avisando por cada uno apenas llega.
     *
     * Es un método aparte y no un `foreach` sobre `cabecera()` por una razón
     * medida contra la biblioteca real: cada lectura de OneDrive tarda unos 9
     * segundos y casi todo es espera de red, no transferencia (32 KB tardan
     * 7,5 s; 400 KB, 9 s). De a cuatro en paralelo, ocho archivos pasaron de
     * 81 a 10 segundos. Sobre 928 pistas es la diferencia entre dos horas y
     * media y media hora.
     *
     * El tope de tres tampoco es arbitrario: con cuatro, el hosting se queda
     * sin hilos para la cuenta y rclone devuelve encabezados vacíos. Con dos y
     * con tres, las seis lecturas de prueba salieron enteras.
     *
     * Se entrega por callback y no como arreglo para no juntar cientos de
     * encabezados de 400 KB en memoria: cada uno se procesa y se descarta. De
     * paso, el trabajo queda guardado aunque el proceso muera a la mitad.
     *
     * @param  list<string>  $rutas
     * @param  callable(string, ?string): void  $alLlegar  recibe (ruta, encabezado o null)
     */
    public function cabeceras(string $raiz, array $rutas, callable $alLlegar, int $bytes = 400000, int $paralelo = 3): void;

    /**
     * Trae un archivo completo al server.
     *
     * Es lo que pasa cuando alguien le da play a algo que está sólo en la nube.
     */
    public function traer(string $raiz, string $ruta, string $destino): bool;

    /** Para avisar con claridad cuando la fuente no existe o no se puede leer. */
    public function verificar(string $raiz): ?string;
}
