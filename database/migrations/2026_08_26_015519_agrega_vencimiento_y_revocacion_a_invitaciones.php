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
             * Estas dos columnas cambian lo que ES una invitación.
             *
             * Hasta acá funcionaba como un ticket de entrada: se consultaba una
             * sola vez, al darse de alta, y después la persona entraba por su
             * `google_id` sin que la lista se volviera a mirar. Consecuencia:
             * borrar una invitación no le sacaba el acceso a nadie.
             *
             * Ahora es el permiso VIGENTE de ver la biblioteca. Invitar es
             * compartirla; revocar es dejar de compartirla, y se nota en el
             * próximo pedido.
             */
            $table->timestamp('expira_en')->nullable()->after('aceptada_en');

            /*
             * Se revoca en vez de borrar la fila por dos motivos: `email` es
             * unique, así que volver a compartir reusa la misma fila en vez de
             * chocar; y queda el rastro de que esa persona estuvo invitada,
             * que es justo lo que uno quiere saber después.
             */
            $table->timestamp('revocada_en')->nullable()->after('expira_en');
        });
    }

    public function down(): void
    {
        Schema::table('invitaciones', function (Blueprint $table) {
            $table->dropColumn(['expira_en', 'revocada_en']);
        });
    }
};
