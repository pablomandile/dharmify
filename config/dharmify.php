<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Administradores
    |--------------------------------------------------------------------------
    |
    | Los emails que, al entrar con Google, quedan como administradores. Son los
    | dueños de la biblioteca: importan, editan la taxonomía e invitan al resto.
    |
    | Va por configuración y no por una fila en la base a propósito: es lo que
    | permite que una instalación nueva —o una base recreada desde cero— tenga
    | un administrador sin ningún paso manual.
    |
    | Acepta varios separados por coma. No es capricho: es normal tener la
    | cuenta personal y la del trabajo, y Google elige por vos con cuál entrás
    | según qué sesión tengas abierta. Con un solo email permitido, entrar con
    | la otra cuenta te deja afuera de tu propia biblioteca.
    |
    */

    'admin_emails' => collect(explode(',', (string) env('ADMIN_EMAIL')))
        ->map(fn (string $email) => mb_strtolower(trim($email)))
        ->filter()
        ->values()
        ->all(),

];
