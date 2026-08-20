<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Una lista de reproducción armada a mano.
 *
 * Es de quien la armó, no del catálogo: la biblioteca es compartida pero lo que
 * cada uno junta es suyo.
 *
 * @property int $id
 * @property int $user_id
 * @property string $nombre
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'nombre'])]
class Lista extends Model
{
    protected $table = 'listas';

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Las pistas, en el orden que la persona les dio.
     *
     * El `orderByPivot` no es un detalle de presentación: sin él, MySQL las
     * devuelve en el orden que se le antoje y una serie de clases aparecería
     * mezclada, que es exactamente lo que una lista viene a resolver.
     *
     * @return BelongsToMany<Pista, $this>
     */
    public function pistas(): BelongsToMany
    {
        return $this->belongsToMany(Pista::class, 'lista_pista')
            ->withPivot('orden')
            ->orderByPivot('orden');
    }

    /**
     * El lugar que le toca a la próxima que se agregue.
     *
     * La tabla va nombrada: `pistas` tiene su propio `orden` —el que trae dentro
     * de la serie— y sin decir cuál, la base contesta "ambiguous column name" y
     * agregar a una lista falla con un 500.
     */
    public function proximoOrden(): int
    {
        return (int) $this->pistas()->max('lista_pista.orden') + 1;
    }
}
