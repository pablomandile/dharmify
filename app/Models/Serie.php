<?php

namespace App\Models;

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
 *
 * Lo que agrega `withSum('pistas', 'duracion_seg')` en la biblioteca. Va acá
 * por lo mismo que las columnas: es una propiedad real que ningún analizador
 * puede adivinar leyendo la clase.
 * @property-read int|null $pistas_sum_duracion_seg
 */
#[Fillable([
    'fuente_id', 'carpeta', 'carpeta_hash', 'titulo', 'slug',
    'tipo', 'anio', 'idioma', 'portada', 'portada_revisada_en', 'editada_a_mano',
])]
class Serie extends Model
{
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
