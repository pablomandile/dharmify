<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * La cuenta de Google es válida, pero esa persona no tiene acceso a la
 * biblioteca. No es un error técnico: es la respuesta correcta a alguien que
 * entró bien a Google y no está invitado.
 */
class AccesoNoAutorizado extends RuntimeException {}
