<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Administrador
    |--------------------------------------------------------------------------
    |
    | El email que, al entrar con Google, queda como administrador. Es el dueño
    | de la biblioteca: importa, edita la taxonomía e invita al resto.
    |
    | Va por configuración y no por una fila en la base a propósito: es lo que
    | permite que una instalación nueva —o una base recreada desde cero— tenga
    | un administrador sin ningún paso manual.
    |
    */

    'admin_email' => env('ADMIN_EMAIL'),

];
