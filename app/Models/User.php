<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $google_id
 * @property string|null $avatar_url
 * @property string $rol
 * @property CarbonImmutable|null $email_verified_at
 * @property string|null $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'google_id', 'avatar_url', 'rol'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * El administrador importa la biblioteca, edita la taxonomía e invita gente.
     * El invitado escucha y arma sus propias listas, nada más.
     */
    public const ROL_ADMIN = 'admin';

    public const ROL_INVITADO = 'invitado';

    /**
     * Las enseñanzas marcadas como favoritas.
     *
     * @return BelongsToMany<Pista, $this>
     */
    public function favoritos(): BelongsToMany
    {
        return $this->belongsToMany(Pista::class, 'favoritos')
            ->withPivot('created_at')
            ->orderByPivotDesc('created_at');
    }

    /** @return HasMany<Lista, $this> */
    public function listas(): HasMany
    {
        return $this->hasMany(Lista::class)->orderBy('nombre');
    }

    /**
     * Las invitaciones que esta persona repartió.
     *
     * @return HasMany<Invitacion, $this>
     */
    public function invitacionesEnviadas(): HasMany
    {
        return $this->hasMany(Invitacion::class, 'invitada_por');
    }

    public function esAdmin(): bool
    {
        return $this->rol === self::ROL_ADMIN;
    }

    /**
     * Si esta persona tiene hoy permiso de ver la biblioteca.
     *
     * La invitación no es un ticket que se usa una vez y se tira: es el permiso
     * mismo, y vale mientras siga vigente. Por eso se pregunta en cada pedido y
     * no sólo al darse de alta — sin esto, revocar no le sacaría el acceso a
     * nadie que ya hubiera entrado.
     *
     * Se memoiza porque Fuente::visiblesPara() la consulta varias veces por
     * pedido: la biblioteca, el audio y el desplegable de carpetas del menú
     * preguntan cada uno por su lado, y la respuesta no puede cambiar en el
     * medio de un mismo pedido.
     */
    public function puedeVerLaBiblioteca(): bool
    {
        if ($this->esAdmin()) {
            return true;
        }

        return $this->accesoVigente ??= Invitacion::query()
            ->where('email', $this->email)
            ->vigentes()
            ->exists();
    }

    /** El memo de puedeVerLaBiblioteca(), sólo para lo que dure el pedido. */
    private ?bool $accesoVigente = null;
}
