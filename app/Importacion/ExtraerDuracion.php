<?php

namespace App\Importacion;

use App\Models\Fuente;
use App\Models\Pista;
use getID3;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Saca la duración real de cada pista leyendo sólo el encabezado.
 *
 * El truco está en una sola línea: a getID3 hay que DECIRLE el tamaño real del
 * archivo. Si no, mide el pedazo que le dimos y contesta cualquier cosa —sobre
 * tres grabaciones de una hora largamente medidas, contestó 46, 23 y 12
 * segundos—. Con el tamaño verdadero, las mismas tres dieron 1:03:47, 1:03:05 y
 * 1:24:28: exactas al segundo contra el archivo entero.
 *
 * Funciona porque un mp3 lleva su propia contabilidad en el encabezado: los VBR
 * traen el conteo de cuadros (Xing/VBRI) y los CBR, el bitrate constante, que
 * con el tamaño total da la duración sin leer un byte de audio.
 *
 * Cuando el encabezado no alcanza, getID3 devuelve null y no un número
 * plausible. Eso importa más que el acierto: no hay forma de que la biblioteca
 * muestre una duración inventada.
 */
class ExtraerDuracion
{
    /**
     * 400 KB, igual que las carátulas, y por el mismo motivo.
     *
     * Medido: un archivo con una imagen embebida grande tiene la etiqueta ID3
     * ocupando 188 KB, y hasta 200 KB de encabezado getID3 todavía contestaba
     * null. Recién con 400 KB apareció la duración. Leer menos no ahorra tiempo
     * —contra OneDrive, 32 KB tardan 7,5 s y 400 KB, 9 s: el costo es la ida y
     * vuelta, no los bytes— así que no hay nada que ganar recortando.
     */
    private const CABECERA = 400000;

    /** Más de un día de grabación es un dato roto, no una enseñanza larga. */
    private const TOPE_SEGUNDOS = 86400;

    public function __construct(private readonly EscanearFuente $escanear) {}

    /**
     * Procesa un lote, agrupado por fuente para poder leer en paralelo.
     *
     * Cada pista se guarda apenas se resuelve, no al final: si el hosting mata
     * el proceso a la mitad —pasó, a la 1 h 48 min y sin una línea en el log—,
     * lo hecho queda hecho y relanzar retoma donde iba.
     *
     * @param  Collection<int, Pista>  $pistas
     * @return array{con: int, sin: int, ilegibles: int}
     */
    public function deLote(Collection $pistas, int $paralelo = 3): array
    {
        $con = 0;
        $sin = 0;
        $ilegibles = 0;

        $porFuente = $pistas->groupBy(fn (Pista $p) => $p->serie->fuente_id);
        $fuentes = Fuente::findMany($porFuente->keys()->all())->keyBy('id');

        foreach ($porFuente as $fuenteId => $delaFuente) {
            $fuente = $fuentes->get($fuenteId);

            // Una fuente borrada mientras corría el barrido: sus pistas quedan
            // para la próxima, sin marcarlas como revisadas.
            if (! $fuente instanceof Fuente) {
                continue;
            }

            // Dos pistas pueden compartir ruta si alguien apuntó dos fuentes a
            // carpetas que se solapan; el índice las junta a todas.
            /** @var array<string, list<Pista>> $porRuta */
            $porRuta = [];

            foreach ($delaFuente as $pista) {
                $porRuta[$pista->ruta][] = $pista;
            }

            $lector = $this->escanear->lectorPara($fuente);

            /** @var list<string> $fallaron */
            $fallaron = [];

            $lector->cabeceras(
                $fuente->ruta,
                array_keys($porRuta),
                function (string $ruta, ?string $cabecera) use ($porRuta, &$con, &$sin, &$fallaron) {
                    if ($cabecera === null) {
                        $fallaron[] = $ruta;

                        return;
                    }

                    $this->guardar($porRuta[$ruta] ?? [], $cabecera, $con, $sin);
                },
                self::CABECERA,
                $paralelo,
            );

            if ($fallaron !== []) {
                /*
                 * Segundo intento, de a uno. Casi todo lo que falla es la nube
                 * que no contestó o el hosting que se quedó sin hilos: sin
                 * competencia, la misma lectura suele salir bien.
                 */
                $lector->cabeceras(
                    $fuente->ruta,
                    $fallaron,
                    function (string $ruta, ?string $cabecera) use ($porRuta, &$con, &$sin, &$ilegibles) {
                        if ($cabecera === null) {
                            $ilegibles += count($porRuta[$ruta] ?? []);

                            return;
                        }

                        $this->guardar($porRuta[$ruta] ?? [], $cabecera, $con, $sin);
                    },
                    self::CABECERA,
                    paralelo: 1,
                );
            }
        }

        return ['con' => $con, 'sin' => $sin, 'ilegibles' => $ilegibles];
    }

    /**
     * @param  list<Pista>  $pistas
     */
    private function guardar(array $pistas, string $cabecera, int &$con, int &$sin): void
    {
        foreach ($pistas as $pista) {
            $segundos = $this->segundosDe($cabecera, $pista->bytes);

            $cambios = ['duracion_revisada_en' => now()];

            if ($segundos) {
                $cambios['duracion_seg'] = $segundos;
                $con++;
            } else {
                $sin++;
            }

            /*
             * La marca se guarda leamos o no la duración, PERO sólo cuando el
             * encabezado llegó. Son dos cosas distintas y confundirlas cuesta
             * caro en las dos direcciones:
             *
             * - Si no se marcara lo que se leyó y no dio duración, la consulta
             *   volvería a elegir esas pistas en cada tanda y el barrido giraría
             *   sobre las primeras sin avanzar nunca. Eso ya pasó con las
             *   carátulas.
             * - Si se marcara lo que ni siquiera se pudo leer, un rato sin red
             *   dejaría cientos de pistas dadas por revisadas y sin duración,
             *   sin forma de distinguirlas de las que de verdad no la tienen.
             */
            $pista->forceFill($cambios)->save();
        }
    }

    /**
     * getID3 trabaja sobre un archivo, no sobre una cadena, así que el pedazo
     * de encabezado va a un temporal. Se borra siempre, incluso si el análisis
     * explota: sin eso, un barrido de 928 pistas deja 928 archivos sueltos.
     */
    private function segundosDe(string $cabecera, int $bytesReales): ?int
    {
        $temporal = tempnam(sys_get_temp_dir(), 'dharmify-duracion-');

        if ($temporal === false) {
            return null;
        }

        try {
            file_put_contents($temporal, $cabecera);

            // El segundo argumento es todo: sin él getID3 cree que el archivo
            // mide 400 KB y contesta la duración de ese pedacito.
            $datos = (new getID3)->analyze($temporal, max($bytesReales, strlen($cabecera)));

            $segundos = $datos['playtime_seconds'] ?? null;

            // Algunas grabaciones no dejan calcular nada pero sí declaran su
            // largo en la etiqueta TLEN, en milisegundos.
            if (! is_numeric($segundos)) {
                $tlen = $datos['id3v2']['comments']['length'][0] ?? null;

                $segundos = is_numeric($tlen) ? ((float) $tlen) / 1000 : null;
            }

            if (! is_numeric($segundos)) {
                return null;
            }

            $segundos = (int) round((float) $segundos);

            return $segundos > 0 && $segundos < self::TOPE_SEGUNDOS ? $segundos : null;
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($temporal);
        }
    }
}
