<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Los favoritos y las listas son de cada persona, no del catálogo.
         *
         * Por eso cuelgan del usuario y no de la serie: la biblioteca es
         * compartida —el mismo retiro lo escuchan varios— pero lo que cada uno
         * marca es suyo. Es también lo que permite invitar a alguien sin que
         * pueda tocar nada de lo que otro armó.
         */
        Schema::create('favoritos', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pista_id')->constrained('pistas')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            // La clave es el par: marcar dos veces la misma enseñanza no es un
            // error que haya que manejar, es que ya estaba marcada.
            $table->primary(['user_id', 'pista_id']);

            // Para "mis favoritos, los últimos primero", que es como se miran.
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('listas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nombre');
            $table->timestamps();

            $table->index(['user_id', 'nombre']);
        });

        Schema::create('lista_pista', function (Blueprint $table) {
            $table->foreignId('lista_id')->constrained('listas')->cascadeOnDelete();
            $table->foreignId('pista_id')->constrained('pistas')->cascadeOnDelete();

            /*
             * El orden es explícito y no la fecha en que se agregó: en una lista
             * de enseñanzas el orden importa —una serie de clases se escucha en
             * secuencia— y tiene que poder cambiarse sin borrar y volver a
             * agregar.
             */
            $table->unsignedInteger('orden')->default(0);

            $table->primary(['lista_id', 'pista_id']);
            $table->index(['lista_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lista_pista');
        Schema::dropIfExists('listas');
        Schema::dropIfExists('favoritos');
    }
};
