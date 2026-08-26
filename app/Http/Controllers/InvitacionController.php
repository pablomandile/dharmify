<?php

namespace App\Http\Controllers;

use App\Models\Invitacion;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Con quién compartís la biblioteca.
 *
 * No manda mails: el servidor no tiene un mailer configurado y encolar uno sin
 * el cron del worker sería mandarlo a un pozo. Acá se administra quién está
 * habilitado; el link se lo pasás vos.
 */
class InvitacionController extends Controller
{
    public function index(): Response
    {
        $invitaciones = Invitacion::query()
            ->with('invitadaPor:id,name')
            ->orderByDesc('created_at')
            ->get();

        /*
         * Los usuarios se buscan por email y no por una relación: `users` e
         * `invitaciones` no están unidas por clave foránea a propósito —una
         * invitación existe antes que la persona—, y el email es lo único que
         * las cruza. Es el mismo cruce que hace el ingreso con Google.
         */
        $usuarios = User::query()
            ->whereIn('email', $invitaciones->pluck('email'))
            ->withCount(['favoritos', 'listas'])
            ->get()
            ->keyBy('email');

        $actividad = $this->ultimaActividad();

        return Inertia::render('settings/Invitaciones', [
            'invitaciones' => $invitaciones->map(function (Invitacion $i) use ($usuarios, $actividad) {
                $usuario = $usuarios->get($i->email);

                return [
                    'id' => $i->id,
                    'email' => $i->email,
                    'estado' => $i->estado(),
                    'invitada_el' => $i->created_at?->isoFormat('D [de] MMMM [de] YYYY'),
                    'expira_en' => $i->expira_en?->isoFormat('D [de] MMMM [de] YYYY'),
                    'invitada_por' => $i->invitadaPor?->name,
                    'usuario' => $usuario === null ? null : [
                        'nombre' => $usuario->name,
                        'avatar' => $usuario->avatar_url,
                        'favoritos' => $usuario->favoritos_count,
                        'listas' => $usuario->listas_count,
                        /*
                         * Puede venir en null aunque la persona haya entrado:
                         * las sesiones se podan. La pantalla dice "sin datos
                         * recientes", que es la verdad, y no "nunca entró".
                         */
                        'ultima_actividad' => $this->cuandoFue($actividad->get($usuario->id)),
                    ],
                ];
            }),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'vence_en_dias' => ['nullable', 'integer', 'min:1', 'max:365'],
        ], [
            'email.email' => 'Eso no parece una dirección de correo.',
        ]);

        // El modelo normaliza el email al guardarlo; acá hay que normalizarlo
        // también para BUSCARLO, o "Pablo@Gmail.com" crearía una fila nueva
        // junto a la que ya existe y chocaría contra el índice unique.
        $email = mb_strtolower(trim($datos['email']));
        $dias = $datos['vence_en_dias'] ?? null;

        $invitacion = Invitacion::query()->firstOrNew(['email' => $email]);

        /*
         * Volver a invitar a alguien revocado lo restaura en vez de fallar por
         * el unique: es lo que uno espera al escribir de nuevo su dirección.
         */
        $invitacion->forceFill([
            'invitada_por' => $invitacion->invitada_por ?? $request->user()?->id,
            'expira_en' => $dias === null ? null : Carbon::now()->addDays($dias),
            'revocada_en' => null,
        ])->save();

        return back()->with('estado', "Ya podés pasarle el link a {$email}.");
    }

    /**
     * Cancela una invitación que nadie usó.
     *
     * Se borra de verdad y no se revoca porque no hay nada que recordar: si la
     * persona nunca entró, la fila no es el rastro de nada.
     */
    public function destroy(Invitacion $invitacion): RedirectResponse
    {
        abort_unless($invitacion->aceptada_en === null, 404);

        $invitacion->delete();

        return back()->with('estado', 'Invitación cancelada.');
    }

    /**
     * Deja de compartir la biblioteca con alguien que ya entró.
     *
     * No le toca la cuenta ni sus favoritos ni sus listas: invitar es compartir
     * la biblioteca, y esto es dejar de compartirla. Si volvés a compartir,
     * encuentra todo como lo dejó.
     */
    public function revocar(Invitacion $invitacion): RedirectResponse
    {
        $invitacion->forceFill(['revocada_en' => now()])->save();

        return back()->with('estado', "{$invitacion->email} ya no ve la biblioteca. Sus favoritos y listas quedaron guardados.");
    }

    public function restaurar(Invitacion $invitacion): RedirectResponse
    {
        $invitacion->forceFill(['revocada_en' => null, 'expira_en' => null])->save();

        return back()->with('estado', "{$invitacion->email} vuelve a ver la biblioteca.");
    }

    /**
     * La última vez que cada persona usó la app.
     *
     * Sale de la tabla de sesiones, que es lo único que hay: no existe registro
     * de escuchas por usuario —`reproducciones` vive en la pista y es global—.
     * Una sola consulta para todos, y no una por fila.
     *
     * @return Collection<int, int>
     */
    private function ultimaActividad(): Collection
    {
        if (config('session.driver') !== 'database') {
            return collect();
        }

        return DB::table('sessions')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->selectRaw('user_id, MAX(last_activity) as ultima')
            ->pluck('ultima', 'user_id');
    }

    /** `last_activity` es un entero unix, no una fecha. */
    private function cuandoFue(?int $marca): ?string
    {
        return $marca === null ? null : Carbon::createFromTimestamp($marca)->diffForHumans();
    }
}
