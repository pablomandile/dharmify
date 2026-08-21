<?php

namespace App\Importacion;

use App\Models\Fuente;
use App\Models\Serie;
use getID3;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Lee la etiqueta de álbum del primer audio de cada serie.
 *
 * Sirve porque el nombre de la carpeta a veces está mal escrito y la etiqueta
 * no: medido sobre quince series, una carpeta decía "Talk at Cambrigde
 * University" y su álbum, "Talk at the University of Cambridge".
 *
 * Pero no siempre conviene usarla, y por eso esto sólo LEE y no decide: de esas
 * quince, ocho traían álbum, seis ya coincidían con el título que teníamos, una
 * mejoraba y una lo empeoraba —una serie de Tantra cuyo álbum decía "Programa
 * General"—. Quién se queda con cuál lo resuelve el comando, mirando todo junto.
 */
class ExtraerTitulo
{
    /** Lo mismo que para las carátulas, y por el mismo motivo. */
    private const CABECERA = 1500000;

    public function __construct(private readonly EscanearFuente $escanear) {}

    /**
     * @param  Collection<int, Serie>  $series
     * @return array<int, string> serie_id => álbum, sólo las que traen uno
     */
    public function deLote(Collection $series, int $paralelo = 3): array
    {
        $albumes = [];

        $porFuente = $series->groupBy(fn (Serie $s) => $s->fuente_id);
        $fuentes = Fuente::findMany($porFuente->keys()->all())->keyBy('id');

        foreach ($porFuente as $fuenteId => $deLaFuente) {
            $fuente = $fuentes->get($fuenteId);

            if (! $fuente instanceof Fuente) {
                continue;
            }

            /** @var array<string, Serie> $porRuta */
            $porRuta = [];

            foreach ($deLaFuente as $serie) {
                $pista = $serie->pistas()->where('en_nube', true)->orderBy('orden')->first();

                if ($pista) {
                    $porRuta[$pista->ruta] = $serie;
                }
            }

            if ($porRuta === []) {
                continue;
            }

            $this->escanear->lectorPara($fuente)->cabeceras(
                $fuente->ruta,
                array_keys($porRuta),
                function (string $ruta, ?string $cabecera) use ($porRuta, &$albumes) {
                    $serie = $porRuta[$ruta] ?? null;
                    $album = $cabecera === null ? null : $this->albumDe($cabecera);

                    if ($serie instanceof Serie && $album !== null) {
                        $albumes[$serie->id] = $album;
                    }
                },
                self::CABECERA,
                $paralelo,
            );
        }

        return $albumes;
    }

    private function albumDe(string $cabecera): ?string
    {
        $temporal = tempnam(sys_get_temp_dir(), 'dharmify-album-');

        if ($temporal === false) {
            return null;
        }

        try {
            file_put_contents($temporal, $cabecera);

            $datos = (new getID3)->analyze($temporal);
            /*
             * Los tres lugares donde puede estar, en orden de confianza.
             *
             * `comments` es la vista unificada que arma getID3 y ya viene en
             * UTF-8, así que es la primera opción — pero NO siempre la llena:
             * medido sobre esta biblioteca, hay archivos donde `comments` trae
             * únicamente la carátula y el álbum sólo aparece en `tags`. Mirar
             * sólo ahí devolvía cero álbumes sobre 145 series.
             */
            $album = $datos['comments']['album'][0]
                ?? $datos['tags']['id3v2']['album'][0]
                ?? $datos['tags']['id3v1']['album'][0]
                ?? null;

            if (! is_string($album)) {
                return null;
            }

            // Los reproductores viejos rellenan los campos ID3v1 con ceros hasta
            // completar los 30 bytes fijos, y eso llega hasta acá como basura.
            $album = trim(str_replace("\0", '', $album));

            /*
             * ID3v1 no declara codificación y en la práctica es latin-1. Sin
             * esto, un álbum con acentos leído de ahí llega con bytes inválidos
             * y rompe el JSON de la respuesta.
             */
            if (! mb_check_encoding($album, 'UTF-8')) {
                $album = (string) mb_convert_encoding($album, 'UTF-8', 'ISO-8859-1');
            }

            return $album === '' ? null : $album;
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($temporal);
        }
    }
}
