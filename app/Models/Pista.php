<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
