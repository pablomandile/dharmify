<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El texto de una enseñanza.
 *
 * La biblioteca ya venía con 709 documentos al lado de los audios —692 .docx,
 * 12 .doc, 3 .txt y 2 .pdf— con exactamente el mismo nombre que el mp3. Casi
 * todas estas filas salen de ahí; `manual` es lo que alguien sube después.
 *
 * @property int $id
 * @property int $pista_id
 * @property string $archivo
 * @property string $formato
 * @property string|null $encabezado
 * @property string $origen
 * @property string|null $texto
 * @property array<int, array{inicio: float, fin: float, texto: string}>|null $marcas
 * @property int $palabras
 * @property int $bytes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['pista_id', 'archivo', 'formato', 'encabezado', 'origen', 'texto', 'marcas', 'palabras', 'bytes'])]
class Transcripcion extends Model
{
    protected $table = 'transcripciones';

    public const ORIGEN_NUBE = 'nube';

    public const ORIGEN_MANUAL = 'manual';

    /**
     * Los formatos que se aceptan, del más rico al más pobre.
     *
     * El orden no es decorativo: si un audio termina con dos documentos al lado
     * —porque alguien sube un .srt donde ya había un .docx— este arreglo decide
     * cuál gana. Un .srt trae texto Y tiempos, así que reemplazar con él es
     * siempre una mejora; un .doc no se puede ni leer.
     *
     * @var list<string>
     */
    public const FORMATOS = ['srt', 'vtt', 'docx', 'txt', 'pdf', 'doc'];

    /** Los que el navegador muestra tal cual, sin que saquemos el texto. */
    public const FORMATOS_EMBEBIBLES = ['pdf'];

    protected function casts(): array
    {
        return [
            'marcas' => 'array',
        ];
    }

    /**
     * Qué tan bueno es este formato: más chico, mejor.
     *
     * Devuelve el tope + 1 para lo desconocido, así cualquier formato que no
     * esté en la lista pierde contra todos los que sí.
     */
    public static function calidadDe(string $formato): int
    {
        $puesto = array_search(mb_strtolower($formato), self::FORMATOS, strict: true);

        return $puesto === false ? count(self::FORMATOS) : $puesto;
    }

    /** Si hay texto para mostrar, que es lo que decide qué dibuja el panel. */
    public function tieneTexto(): bool
    {
        return $this->texto !== null && $this->texto !== '';
    }

    /** Si el navegador lo muestra solo, como un PDF en un iframe. */
    public function seEmbebe(): bool
    {
        return in_array($this->formato, self::FORMATOS_EMBEBIBLES, strict: true);
    }

    /** @return BelongsTo<Pista, $this> */
    public function pista(): BelongsTo
    {
        return $this->belongsTo(Pista::class);
    }
}
