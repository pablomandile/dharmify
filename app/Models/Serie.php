<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una carpeta de primer nivel dentro de la fuente: un retiro, un curso, un
 * festival. Es la separación que ya existe en OneDrive, respetada tal cual.
 *
 * @property int $id
 * @property int $fuente_id
 * @property string $carpeta
 * @property string $carpeta_hash
 * @property string $titulo
 * @property string|null $tipo
 * @property int|null $anio
 * @property string $idioma
 * @property bool $editada_a_mano
 * @property string|null $portada
 * @property string|null $portada_origen
 * @property string $titulo_origen
 * @property CarbonImmutable|null $portada_revisada_en
 *
 * Lo que agrega `withSum('pistas', 'duracion_seg')` en la biblioteca. Va acá
 * por lo mismo que las columnas: es una propiedad real que ningún analizador
 * puede adivinar leyendo la clase.
 * @property-read int|null $pistas_sum_duracion_seg
 */
#[Fillable([
    'fuente_id', 'carpeta', 'carpeta_hash', 'titulo', 'slug',
    'titulo_origen', 'tipo', 'anio', 'idioma',
    'portada', 'portada_revisada_en', 'portada_origen', 'editada_a_mano',
])]
class Serie extends Model
{
    /** El título salió del nombre de la carpeta, que es el caso normal. */
    public const TITULO_CARPETA = 'carpeta';

    /**
     * El título salió de la etiqueta de álbum del primer audio.
     *
     * Se marca para que el escaneo no lo pise: el título se recalcula desde la
     * carpeta en cada refresco, y sin la marca el cambio duraría una vuelta.
     */
    public const TITULO_ETIQUETA = 'etiqueta';

    /** Del flyer de la carpeta o del encabezado de un audio. */
    public const PORTADA_ARCHIVO = 'archivo';

    /** Dibujada por nosotros porque no existe ninguna imagen de la serie. */
    public const PORTADA_GENERADA = 'generada';

    /** Subida por una persona. No la pisa ningún barrido. */
    public const PORTADA_MANUAL = 'manual';

    protected $table = 'series';

    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'editada_a_mano' => 'boolean',
            'portada_revisada_en' => 'datetime',
        ];
    }

    /**
     * La URL de la carátula, con la versión pegada.
     *
     * El `?v=` no es adorno: la carátula se sirve con una semana de caché, así
     * que sin él, cambiarla a mano no se vería hasta dentro de siete días.
     */
    public function urlPortada(): ?string
    {
        if (! $this->portada) {
            return null;
        }

        // Los tres caminos que escriben una carátula —el barrido, el generador
        // y la subida a mano— guardan la marca en el mismo save, así que si hay
        // imagen hay fecha.
        return '/series/'.$this->id.'/portada?v='.$this->portada_revisada_en->timestamp;
    }

    /**
     * Las pistas que ya tienen la duración medida.
     *
     * Es una relación aparte y no un `withCount` con alias porque así se puede
     * contar en la misma consulta y el tipo queda claro: la biblioteca necesita
     * saber si el total de una serie está completo antes de mostrarlo.
     *
     * @return HasMany<Pista, $this>
     */
    public function pistasMedidas(): HasMany
    {
        return $this->pistas()->whereNotNull('duracion_seg');
    }

    /** @return BelongsTo<Fuente, $this> */
    public function fuente(): BelongsTo
    {
        return $this->belongsTo(Fuente::class);
    }

    /** @return BelongsToMany<Maestro, $this> */
    public function maestros(): BelongsToMany
    {
        return $this->belongsToMany(Maestro::class);
    }

    /** @return HasMany<Pista, $this> */
    public function pistas(): HasMany
    {
        return $this->hasMany(Pista::class);
    }

    /**
     * La carpeta identifica a la serie entre revisiones. Se guarda su hash
     * porque la ruta puede ser larga y MySQL no indexa 1024 caracteres.
     */
    public static function hashDe(string $carpeta): string
    {
        return hash('sha256', $carpeta);
    }
}
