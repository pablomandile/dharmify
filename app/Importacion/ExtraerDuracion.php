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
     * Cuánto encabezado se pide de entrada.
     *
     * Medido contra la nube: el tamaño del pedido casi no cambia lo que tarda,
     * porque el costo es la ida y vuelta. Sobre el mismo archivo, 400 KB
     * tardaron 8,1 s; 1 MB, 9,2 s; 1,5 MB, 9,5 s; 2,5 MB, 7,8 s. Siendo así,
     * pedir de más sale gratis y pedir de menos se paga carísimo: con 400 KB una
     * serie entera quedó sin duración porque su carátula embebida ocupa 748 KB
     * y el audio recién empieza en el byte 758.794.
     */
    private const CABECERA = 1500000;

    /**
     * Tope del segundo intento. Una etiqueta que dice medir más que esto está
     * rota, y bajar el archivo entero por una duración no vale la pena.
     */
    private const CABECERA_MAXIMA = 8000000;

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
        $cuenta = ['con' => 0, 'sin' => 0, 'ilegibles' => 0];

        $porFuente = $pistas->groupBy(fn (Pista $p) => $p->serie->fuente_id);
        $fuentes = Fuente::findMany($porFuente->keys()->all())->keyBy('id');

        foreach ($porFuente as $fuenteId => $delaFuente) {
            $fuente = $fuentes->get($fuenteId);

            // Una fuente borrada mientras corría el barrido: sus pistas quedan
            // para la próxima, sin marcarlas como revisadas.
            if (! $fuente instanceof Fuente) {
                continue;
            }

            $this->deUnaFuente($fuente, $delaFuente, $paralelo, $cuenta);
        }

        return $cuenta;
    }

    /**
     * @param  Collection<int, Pista>  $pistas
     * @param  array{con: int, sin: int, ilegibles: int}  $cuenta
     */
    private function deUnaFuente(Fuente $fuente, Collection $pistas, int $paralelo, array &$cuenta): void
    {
        $lector = $this->escanear->lectorPara($fuente);

        // Dos pistas pueden compartir ruta si alguien apuntó dos fuentes a
        // carpetas que se solapan; el índice las junta a todas.
        /** @var array<string, list<Pista>> $porRuta */
        $porRuta = [];

        foreach ($pistas as $pista) {
            $porRuta[$pista->ruta][] = $pista;
        }

        /** @var list<string> $sinRespuesta */
        $sinRespuesta = [];

        /** @var array<string, int> $agrandar */
        $agrandar = [];

        $lector->cabeceras(
            $fuente->ruta,
            array_keys($porRuta),
            function (string $ruta, ?string $cabecera) use ($porRuta, &$cuenta, &$sinRespuesta, &$agrandar) {
                if ($cabecera === null) {
                    $sinRespuesta[] = $ruta;

                    return;
                }

                $falta = $this->cuantoHaceFalta($cabecera);

                /*
                 * Si la etiqueta declara ser más larga que lo que pedimos, no es
                 * que la duración no se pueda calcular: es que el audio todavía
                 * no empezó. Pedir de nuevo sale más barato que perder la pista.
                 */
                if ($falta !== null && ! $this->tieneDuracion($porRuta[$ruta] ?? [], $cabecera)) {
                    $agrandar[$ruta] = $falta;

                    return;
                }

                $this->guardar($porRuta[$ruta] ?? [], $cabecera, $cuenta);
            },
            self::CABECERA,
            $paralelo,
        );

        /*
         * Segundo intento de lo que no contestó, de a uno. Casi todo lo que
         * falla es la nube que no respondió o el hosting que se quedó sin
         * hilos: sin competencia, la misma lectura suele salir bien.
         */
        if ($sinRespuesta !== []) {
            $lector->cabeceras(
                $fuente->ruta,
                $sinRespuesta,
                function (string $ruta, ?string $cabecera) use ($porRuta, &$cuenta) {
                    if ($cabecera === null) {
                        $cuenta['ilegibles'] += count($porRuta[$ruta] ?? []);

                        return;
                    }

                    $this->guardar($porRuta[$ruta] ?? [], $cabecera, $cuenta);
                },
                self::CABECERA,
                paralelo: 1,
            );
        }

        // Y las que necesitan más encabezado, pidiendo exactamente lo que su
        // propia etiqueta dice medir.
        foreach ($agrandar as $ruta => $falta) {
            $cabecera = $lector->cabecera($fuente->ruta, $ruta, min($falta, self::CABECERA_MAXIMA));

            if ($cabecera === null) {
                $cuenta['ilegibles'] += count($porRuta[$ruta] ?? []);

                continue;
            }

            $this->guardar($porRuta[$ruta] ?? [], $cabecera, $cuenta);
        }
    }

    /**
     * @param  list<Pista>  $pistas
     */
    private function tieneDuracion(array $pistas, string $cabecera): bool
    {
        foreach ($pistas as $pista) {
            if ($this->segundosDe($cabecera, $pista->bytes) === null) {
                return false;
            }
        }

        return $pistas !== [];
    }

    /**
     * @param  list<Pista>  $pistas
     * @param  array{con: int, sin: int, ilegibles: int}  $cuenta
     */
    private function guardar(array $pistas, string $cabecera, array &$cuenta): void
    {
        foreach ($pistas as $pista) {
            $segundos = $this->segundosDe($cabecera, $pista->bytes);

            $cambios = ['duracion_revisada_en' => now()];

            if ($segundos !== null) {
                $cambios['duracion_seg'] = $segundos;
                $cuenta['con']++;
            } else {
                $cuenta['sin']++;
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
     * Cuánto encabezado haría falta, según lo que declara la etiqueta ID3v2.
     *
     * Devuelve null si con lo que ya tenemos alcanzaba —o si el archivo ni
     * siquiera empieza con una etiqueta—, así no se gasta una segunda lectura
     * en algo que no la necesita.
     */
    private function cuantoHaceFalta(string $cabecera): ?int
    {
        if (strlen($cabecera) < 10 || substr($cabecera, 0, 3) !== 'ID3') {
            return null;
        }

        /*
         * El largo son cuatro bytes "syncsafe": siete bits útiles cada uno, con
         * el octavo siempre en cero para que la etiqueta nunca se parezca al
         * comienzo de un cuadro de audio.
         */
        $bytes = array_map(ord(...), str_split(substr($cabecera, 6, 4)));

        $largo = ($bytes[0] << 21) | ($bytes[1] << 14) | ($bytes[2] << 7) | $bytes[3];

        // La etiqueta entera más unos cuantos cuadros de audio, que es lo que
        // getID3 necesita ver para contar.
        $necesario = 10 + $largo + 65536;

        return $necesario > strlen($cabecera) ? $necesario : null;
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
            // mide lo que le pasamos y contesta la duración de ese pedacito.
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
