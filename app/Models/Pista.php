<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

/**
 * Una enseñanza.
 *
 * El catálogo es la fuente de verdad, no la carpeta del server: una pista sigue
 * existiendo en la biblioteca aunque la purga se haya llevado su archivo. Por
 * eso `en_server` y `en_nube` son estado, no supuestos.
 *
 * @property int $id
 * @property int $serie_id
 * @property string $clave
 * @property string $titulo
 * @property string $archivo
 * @property string $ruta
 * @property int $bytes
 * @property int|null $duracion_seg
 * @property CarbonImmutable|null $duracion_revisada_en
 * @property CarbonImmutable|null $transcripcion_revisada_en
 * @property int $orden
 * @property CarbonImmutable|null $grabada_el
 * @property bool $en_server
 * @property bool $en_nube
 * @property CarbonImmutable|null $ultimo_acceso_en
 */
#[Fillable([
    'serie_id', 'clave', 'titulo', 'archivo', 'ruta', 'bytes',
    'duracion_seg', 'orden', 'grabada_el', 'en_server', 'en_nube',
])]
class Pista extends Model
{
    protected $table = 'pistas';

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'duracion_seg' => 'integer',
            'duracion_revisada_en' => 'datetime',
            'transcripcion_revisada_en' => 'datetime',
            'orden' => 'integer',
            'grabada_el' => 'date',
            'en_server' => 'boolean',
            'en_nube' => 'boolean',
            'ultimo_acceso_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<Serie, $this> */
    public function serie(): BelongsTo
    {
        return $this->belongsTo(Serie::class);
    }

    /**
     * Dónde vive el archivo cuando está en el server.
     *
     * Fuera del docroot a propósito: la biblioteca es privada y no puede quedar
     * colgando de una URL adivinable. El nombre es la clave y no el original
     * porque en la biblioteca hay 157 nombres de archivo repetidos.
     */
    public function rutaEnElServer(): string
    {
        return storage_path('app/private/audio/'.$this->clave.'.mp3');
    }

    /**
     * Se pregunta al disco, no a la columna `en_server`.
     *
     * La purga borra archivos sin pasar por el modelo, así que la columna puede
     * estar desactualizada y la pastilla de la pantalla mentiría.
     */
    public function estaEnElServer(): bool
    {
        return is_file($this->rutaEnElServer());
    }

    /**
     * Dónde queda guardado el documento original de la transcripción.
     *
     * Por `clave` y no por nombre, igual que el audio y por el mismo motivo: en
     * la biblioteca hay 157 nombres de archivo repetidos.
     */
    public function rutaDeLaTranscripcion(string $formato): string
    {
        /*
         * Por el disco y no por `storage_path()` como hace el audio: el
         * documento se ESCRIBE con `Storage::disk('local')`, y preguntar por
         * dónde quedó usando otro camino es cómo se termina leyendo de un lugar
         * distinto del que se escribió.
         */
        return Storage::disk('local')->path('transcripciones/'.$this->clave.'.'.$formato);
    }

    /** @return HasOne<Transcripcion, $this> */
    public function transcripcion(): HasOne
    {
        return $this->hasOne(Transcripcion::class);
    }

    /**
     * La ficha que consumen todas las pantallas que muestran pistas.
     *
     * Vive acá y no en cada controlador porque son cuatro —la serie, los
     * favoritos, las listas y las descargas— y cuando se agregó la duración
     * hubo que tocar los cuatro. Con una sola forma, agregar un campo es un
     * lugar.
     *
     * @return array<string, mixed>
     */
    public function ficha(bool $favorita = false): array
    {
        return [
            'id' => $this->id,
            'titulo' => $this->titulo,
            'serie' => $this->serie->titulo,
            'serieId' => $this->serie->id,
            'portada' => $this->serie->urlPortada(),
            'duracion_seg' => $this->duracion_seg,
            'bytes' => $this->bytes,
            'grabada_el' => $this->grabada_el?->format('d/m/Y'),
            'en_server' => $this->estaEnElServer(),
            'en_nube' => $this->en_nube,
            /*
             * Sólo si hay transcripción; el texto NO viaja acá.
             *
             * Se lee de `withExists('transcripcion')` cuando la consulta lo
             * pidió, y si no se cae a preguntar por la relación. Ese respaldo
             * evita el N+1 silencioso: una pantalla que se olvide de agregarlo
             * sigue funcionando bien, sólo que con una consulta por fila.
             */
            'transcripcion' => $this->transcripcion_exists ?? $this->transcripcion()->exists(),
            'favorita' => $favorita,
        ];
    }

    /**
     * La clave estable.
     *
     * Sale del hash de la ruta completa dentro de la fuente. Medido sobre la
     * biblioteca real: 157 nombres de archivo se repiten —el mismo canto en 30
     * álbumes distintos— pero ninguna ruta completa se repite.
     */
    public static function claveDe(int $fuenteId, string $ruta): string
    {
        return hash('sha256', $fuenteId.'|'.$ruta);
    }
}
