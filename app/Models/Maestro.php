<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Quien dio la enseñanza.
 *
 * El nombre es el canónico que devuelve TaxonomiaDeCarpeta: la misma persona
 * aparece en las carpetas como "Dekyong", "Guenla Dekyong" y "G.Dekyong", y esa
 * unificación pasa antes de llegar acá.
 *
 * @property int $id
 * @property string $nombre
 * @property string $slug
 */
#[Fillable(['nombre', 'slug'])]
class Maestro extends Model
{
    protected $table = 'maestros';

    /** @return BelongsToMany<Serie, $this> */
    public function series(): BelongsToMany
    {
        return $this->belongsToMany(Serie::class);
    }

    public static function porNombre(string $nombre): self
    {
        return self::firstOrCreate(
            ['nombre' => $nombre],
            ['slug' => Str::slug($nombre)],
        );
    }
}
