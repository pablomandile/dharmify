<?php

namespace App\Services;

use App\Exceptions\AccesoNoAutorizado;
use App\Models\Invitacion;
use App\Models\User;
use App\Support\CuentaDeGoogle;
use Illuminate\Support\Facades\DB;

/**
 * Resuelve una cuenta de Google en un usuario de Dharmify.
 *
 * La biblioteca es privada: entrar bien a Google NO alcanza. Sólo pasan el
 * administrador —definido por configuración— y quien tenga una invitación a ese
 * mismo email. Cualquier otro rebota, aunque su cuenta de Google sea impecable.
 */
class IngresoConGoogleService
{
    /**
     * Sin credenciales, las rutas de /auth/google contestan 404 en vez de
     * explotar con un error de Socialite. Así el código puede estar desplegado
     * antes de que exista el proyecto en Google Cloud.
     */
    public static function configurado(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    /**
     * @throws AccesoNoAutorizado si esa persona no está habilitada.
     */
    public function resolver(CuentaDeGoogle $cuenta): User
    {
        return DB::transaction(function () use ($cuenta) {
            // 1. Ya entró antes con esta misma cuenta de Google.
            $usuario = User::query()->where('google_id', $cuenta->id)->first();

            if ($usuario) {
                return $this->refrescar($usuario, $cuenta);
            }

            /*
             * 2. Existe por email pero sin google_id: es el caso de una cuenta
             *    creada a mano (por ejemplo, el administrador sembrado por
             *    consola) que entra con Google por primera vez. Se vinculan.
             */
            $usuario = User::query()->where('email', $cuenta->email)->first();

            if ($usuario) {
                $usuario->google_id = $cuenta->id;

                return $this->refrescar($usuario, $cuenta);
            }

            // 3. Alta nueva: sólo si es el administrador o está invitada.
            $rol = $this->rolPara($cuenta->email);

            $usuario = new User([
                'name' => $cuenta->nombre,
                'email' => $cuenta->email,
                'google_id' => $cuenta->id,
                'avatar_url' => $cuenta->avatar,
                'rol' => $rol,
            ]);

            /*
             * El email lo verificó Google, no hace falta pedirlo de nuevo. Sin
             * esto, el middleware `verified` deja al usuario recién creado
             * dando vueltas en la pantalla de "confirmá tu correo" sin que
             * exista forma de confirmarlo.
             */
            $usuario->email_verified_at = now();
            $usuario->save();

            Invitacion::query()
                ->where('email', $cuenta->email)
                ->whereNull('aceptada_en')
                ->update(['aceptada_en' => now()]);

            return $usuario;
        });
    }

    private function refrescar(User $usuario, CuentaDeGoogle $cuenta): User
    {
        // La foto de Google caduca cada tanto y el nombre puede cambiar: se
        // refrescan en cada ingreso, que es la única vez que los tenemos.
        $usuario->avatar_url = $cuenta->avatar;
        $usuario->name = $cuenta->nombre;
        $usuario->email_verified_at ??= now();
        $usuario->save();

        return $usuario;
    }

    private function rolPara(string $email): string
    {
        $admin = config('dharmify.admin_email');

        if (filled($admin) && mb_strtolower(trim((string) $admin)) === $email) {
            return User::ROL_ADMIN;
        }

        $invitada = Invitacion::query()->where('email', $email)->exists();

        if (! $invitada) {
            throw new AccesoNoAutorizado("Sin invitación para {$email}.");
        }

        return User::ROL_INVITADO;
    }
}
