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

                // Sin esto, quien ya existía por email entra pero su invitación
                // queda para siempre como "pendiente" en el panel.
                $this->marcarAceptada($cuenta->email);

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

            $this->marcarAceptada($cuenta->email);

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
        // El email ya viene normalizado desde CuentaDeGoogle, y los de la
        // configuración también: comparar en minúsculas es lo único que hace
        // que "Pablo@Gmail.com" y "pablo@gmail.com" sean la misma persona.
        if (in_array($email, config('dharmify.admin_emails'), strict: true)) {
            return User::ROL_ADMIN;
        }

        /*
         * `vigentes()` y no un `exists()` pelado: una invitación revocada o
         * vencida es un permiso que ya no está, y tiene que rebotar igual que
         * si nunca hubiera existido. Sin esto, dejar de compartir con alguien
         * seguiría permitiéndole crearse la cuenta.
         */
        $invitada = Invitacion::query()->where('email', $email)->vigentes()->exists();

        if (! $invitada) {
            throw new AccesoNoAutorizado("Sin invitación vigente para {$email}.");
        }

        return User::ROL_INVITADO;
    }

    /**
     * Deja constancia de cuándo entró por primera vez.
     *
     * Sólo la primera: `aceptada_en` responde "desde cuándo usa la biblioteca",
     * no "cuándo entró la última vez" —eso lo contesta la sesión—.
     */
    private function marcarAceptada(string $email): void
    {
        Invitacion::query()
            ->where('email', $email)
            ->whereNull('aceptada_en')
            ->update(['aceptada_en' => now()]);
    }
}
