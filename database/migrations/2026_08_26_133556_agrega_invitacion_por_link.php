<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitaciones', function (Blueprint $table) {
            /*
             * El link de invitación, para cuando no sabés de antemano con qué
             * dirección va a entrar la persona.
             *
             * Es de un solo uso: al entrar, esta misma fila deja de ser "un
             * link suelto" y pasa a ser la invitación de quien lo usó, con su
             * email adentro. Así una fila sigue siendo una persona, que es lo
             * que hace que el panel y la revocación funcionen igual para los
             * dos caminos.
             *
             * Se guarda en claro y no hasheado, a diferencia de un token de
             * recuperación de contraseña, porque el panel tiene que poder
             * volver a mostrar el link: quien lo reparte por WhatsApp lo pierde
             * y necesita copiarlo de nuevo. Lo que protege es el largo (40
             * caracteres al azar), que sea de un solo uso y que se pueda
             * revocar en cualquier momento.
             */
            $table->string('token', 64)->nullable()->unique()->after('email');
        });

        /*
         * El email pasa a ser opcional: un link recién creado todavía no tiene
         * dueño. El índice unique se conserva —MySQL y SQLite admiten varios
         * NULL en una columna unique—, así que sigue siendo imposible tener dos
         * invitaciones para la misma dirección.
         */
        Schema::table('invitaciones', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('invitaciones', function (Blueprint $table) {
            $table->dropColumn('token');
        });
    }
};
