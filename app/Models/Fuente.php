<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * De dónde sale la biblioteca.
 *
 * Son varias a propósito: la privada y la pública viven en carpetas distintas de
 * OneDrive, y puede haber más.
 *
 * @property int $id
 * @property string $nombre
 * @property string $tipo
 * @property string $ruta
 * @property string $visibilidad
 * @property bool $activa
 * @property CarbonImmutable|null $revisada_en
 */
#[Fillable(['nombre', 'tipo', 'ruta', 'visibilidad', 'activa'])]
class Fuente extends Model
{
    protected $table = 'fuentes';

    /** El server no tiene la carpeta sincronizada: habla con OneDrive por la API. */
    public const TIPO_RCLONE = 'rclone';

    /**
     * Una carpeta del disco. Es lo que permite trabajar contra el OneDrive que
     * sincroniza Windows: leer nombres, tamaños y rutas de ahí NO baja los
     * archivos, así que el catálogo se arma sin descargar decenas de gigas.
     */
    public const TIPO_LOCAL = 'local';

    public const VISIBILIDAD_PRIVADA = 'privada';

    public const VISIBILIDAD_PUBLICA = 'publica';

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
            'revisada_en' => 'datetime',
        ];
    }

    /** @return HasMany<Serie, $this> */
    public function series(): HasMany
    {
        return $this->hasMany(Serie::class);
    }

    /**
     * Los ids de las fuentes que esta persona puede ver.
     *
     * Vive acá y no en cada controlador porque la respuesta tiene que ser la
     * misma en los cuatro lugares que preguntan —la biblioteca, el audio, las
     * carátulas y el menú—. Con una copia por controlador, agregar una fuente
     * nueva significa acordarse de cuatro archivos.
     *
     * El administrador ve todo; quien fue invitado, sólo lo público. Es el
     * sentido de que la fuente tenga visibilidad: hay enseñanzas que no se
     * comparten.
     *
     * @return Collection<int, int>
     */
    public static function visiblesPara(?User $persona): Collection
    {
        return self::query()
            ->when(
                ! $persona?->esAdmin(),
                fn (Builder $q) => $q->where('visibilidad', self::VISIBILIDAD_PUBLICA),
            )
            ->pluck('id');
    }

    public function esLocal(): bool
    {
        return $this->tipo === self::TIPO_LOCAL;
    }
}
