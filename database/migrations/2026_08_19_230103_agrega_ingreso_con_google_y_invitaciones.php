<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->unique()->after('email');
            $table->string('avatar_url')->nullable()->after('google_id');

            // Sólo dos: el administrador (que importa, edita y invita) y el
            // invitado (que escucha y arma sus propias listas).
            $table->string('rol')->default('invitado')->after('avatar_url');

            /*
             * Quien entra con Google nunca elige una contraseña, así que la
             * columna tiene que aceptar nulo. Sin esto, el primer ingreso falla
             * al insertar el usuario y el error no dice nada de Google.
             */
            $table->string('password')->nullable()->change();
        });

        Schema::create('invitaciones', function (Blueprint $table) {
            $table->id();

            /*
             * El email es la llave del acuerdo: es lo que se compara contra la
             * cuenta de Google al volver del callback. Único, porque invitar dos
             * veces a la misma persona no significa nada distinto que invitarla
             * una vez.
             */
            $table->string('email')->unique();
            $table->foreignId('invitada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aceptada_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitaciones');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn(['google_id', 'avatar_url', 'rol']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
