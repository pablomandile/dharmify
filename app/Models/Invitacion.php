<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * El permiso de ver la biblioteca, mientras siga vigente.
 *
 * No es un ticket de entrada de una sola vez: invitar a alguien es compartirle
 * la biblioteca, y revocar es dejar de compartírsela. La persona conserva su
 * cuenta, sus favoritos y sus listas; lo que pierde es el contenido. Quien
 * decide eso mirando esta tabla es Fuente::visiblesPara().
 *
 * Se invita de dos maneras, y las dos terminan en una fila igual:
 *
 * - Por email, cuando sabés la dirección: la fila nace con `email` y lo que
 *   autoriza es que coincida con la cuenta de Google.
 * - Por link, cuando no la sabés: la fila nace con `token` y sin email, y al
 *   entrar la persona el token se consume y su dirección queda escrita ahí
 *   mismo. Desde ese momento es indistinguible de una invitación por email.
 *
 * Que las dos converjan en "una fila = una persona" es lo que hace que el
 * panel, el vencimiento y la revocación funcionen igual para ambas sin ningún
 * caso especial.
 *
 * @property int $id
 * @property string|null $email
 * @property string|null $token
 * @property int|null $invitada_por
 * @property CarbonImmutable|null $aceptada_en
 * @property CarbonImmutable|null $expira_en
 * @property CarbonImmutable|null $revocada_en
 */
#[Fillable(['email', 'token', 'invitada_por', 'aceptada_en', 'expira_en', 'revocada_en'])]
class Invitacion extends Model
{
    protected $table = 'invitaciones';

    public const PENDIENTE = 'pendiente';

    public const ACEPTADA = 'aceptada';

    public const VENCIDA = 'vencida';

    public const REVOCADA = 'revocada';

    /** Un link creado que todavía nadie usó: no tiene dueño. */
    public const LINK = 'link';

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
     * @return Attribute<string|null, string|null>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $valor) => $valor === null ? null : mb_strtolower(trim($valor)),
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
            $this->email === null => self::LINK,
            default => self::PENDIENTE,
        };
    }

    /** La URL que se le pasa a la persona. */
    public function url(): ?string
    {
        return $this->token === null ? null : route('invitacion.aceptar', $this->token);
    }

    /**
     * Convierte un link en la invitación de quien lo usó.
     *
     * Es lo que hace que el link sea de un solo uso: la fila deja de tener
     * token y pasa a tener dueño, así que a nadie más le sirve. Desde acá en
     * adelante es indistinguible de una invitación por email, y por eso el
     * panel, el vencimiento y la revocación no necesitan saber que los links
     * existen.
     *
     * Vive en el modelo y no en el servicio de ingreso porque hay dos caminos
     * que llegan: quien entra con Google por primera vez, y quien ya tiene la
     * sesión abierta y recibe un link nuevo.
     */
    public static function reclamar(string $token, string $email): bool
    {
        $link = self::query()
            ->where('token', $token)
            ->whereNull('email')
            ->vigentes()
            ->first();

        if (! $link) {
            return false;
        }

        $existente = self::query()->where('email', $email)->first();

        /*
         * Esa dirección ya tenía invitación —típicamente una revocada, a quien
         * le estás volviendo a compartir con un link nuevo—. Se reactiva la que
         * ya estaba en vez de crear una segunda, que chocaría contra el unique
         * del email. El link se consume igual: fue usado.
         */
        if ($existente) {
            $existente->forceFill(['revocada_en' => null, 'expira_en' => null])->save();
            $link->delete();

            return true;
        }

        $link->forceFill(['email' => $email, 'token' => null])->save();

        return true;
    }

    /**
     * Un token nuevo.
     *
     * 40 caracteres al azar: es lo único que protege el link, porque a
     * diferencia de la invitación por email acá no hay una dirección con la que
     * cotejar. Con ese largo, adivinarlo no es una posibilidad práctica.
     */
    public static function tokenNuevo(): string
    {
        return Str::random(40);
    }

    /** @return BelongsTo<User, $this> */
    public function invitadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitada_por');
    }
}
