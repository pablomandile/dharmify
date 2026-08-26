<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El permiso de ver la biblioteca, mientras siga vigente.
 *
 * No es un ticket de entrada de una sola vez: invitar a alguien es compartirle
 * la biblioteca, y revocar es dejar de compartírsela. La persona conserva su
 * cuenta, sus favoritos y sus listas; lo que pierde es el contenido. Quien
 * decide eso mirando esta tabla es Fuente::visiblesPara().
 *
 * No lleva token: lo que autoriza es que el email de la cuenta de Google
 * coincida con uno invitado. Un enlace con token no agregaría seguridad —quien
 * recibe el mail ya controla esa casilla— y sí un estado más que puede vencerse
 * justo cuando la persona lo va a usar.
 *
 * @property int $id
 * @property string $email
 * @property int|null $invitada_por
 * @property CarbonImmutable|null $aceptada_en
 * @property CarbonImmutable|null $expira_en
 * @property CarbonImmutable|null $revocada_en
 */
#[Fillable(['email', 'invitada_por', 'aceptada_en', 'expira_en', 'revocada_en'])]
class Invitacion extends Model
{
    protected $table = 'invitaciones';

    public const PENDIENTE = 'pendiente';

    public const ACEPTADA = 'aceptada';

    public const VENCIDA = 'vencida';

    public const REVOCADA = 'revocada';

    protected function casts(): array
    {
        return [
            'aceptada_en' => 'datetime',
            'expira_en' => 'datetime',
            'revocada_en' => 'datetime',
        ];
    }

    /**
     * El email siempre en minúsculas y sin espacios.
     *
     * No es cosmético y por eso vive en el modelo y no en el controlador: el
     * cruce contra Google es un `where('email', $email)` exacto, y lo que llega
     * de Google ya viene normalizado por CuentaDeGoogle. Una invitación
     * guardada como "Pablo@Gmail.com" no serviría para nada, y nada lo avisaría
     * — la persona rebotaría en el login sin que se entienda por qué.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $valor) => mb_strtolower(trim($valor)),
        );
    }

    /**
     * Las invitaciones que hoy habilitan a ver la biblioteca: ni revocadas ni
     * vencidas. Es lo que consultan User::puedeVerLaBiblioteca() y el ingreso
     * con Google para decidir si alguien entra.
     *
     * @param  Builder<Invitacion>  $query
     */
    public function scopeVigentes(Builder $query): void
    {
        $query->whereNull('revocada_en')
            ->where(fn (Builder $q) => $q->whereNull('expira_en')->orWhere('expira_en', '>', now()));
    }

    /** Para la pastilla del panel. */
    public function estado(): string
    {
        return match (true) {
            $this->revocada_en !== null => self::REVOCADA,
            $this->expira_en !== null && $this->expira_en->isPast() => self::VENCIDA,
            $this->aceptada_en !== null => self::ACEPTADA,
            default => self::PENDIENTE,
        };
    }

    /** @return BelongsTo<User, $this> */
    public function invitadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitada_por');
    }
}
