<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una persona habilitada a entrar a la biblioteca.
 *
 * No lleva token ni vencimiento: lo que autoriza es que el email de la cuenta de
 * Google coincida con uno invitado. Un enlace con token no agregaría seguridad
 * —quien recibe el mail ya controla esa casilla— y sí agregaría un estado más
 * que puede vencerse justo cuando la persona lo va a usar.
 *
 * @property int $id
 * @property string $email
 * @property int|null $invitada_por
 * @property CarbonImmutable|null $aceptada_en
 */
#[Fillable(['email', 'invitada_por', 'aceptada_en'])]
class Invitacion extends Model
{
    protected $table = 'invitaciones';

    protected function casts(): array
    {
        return [
            'aceptada_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function invitadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitada_por');
    }
}
