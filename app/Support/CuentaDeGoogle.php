<?php

namespace App\Support;

use Laravel\Socialite\Contracts\User as UsuarioDeSocialite;

/**
 * Los datos de Google que a Dharmify le interesan, ya normalizados.
 *
 * Existe para que el servicio de ingreso no dependa de la forma exacta del
 * objeto de Socialite: así se puede construir a mano en los tests sin simular
 * media librería.
 */
class CuentaDeGoogle
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly string $nombre,
        public readonly ?string $avatar = null,
    ) {}

    public static function desdeSocialite(UsuarioDeSocialite $usuario): self
    {
        return new self(
            id: (string) $usuario->getId(),
            // Google devuelve el email tal como lo escribió el usuario; se
            // normaliza acá porque después se compara con las invitaciones.
            email: mb_strtolower(trim((string) $usuario->getEmail())),
            nombre: (string) ($usuario->getName() ?: $usuario->getNickname() ?: 'Sin nombre'),
            avatar: $usuario->getAvatar() ?: null,
        );
    }
}
