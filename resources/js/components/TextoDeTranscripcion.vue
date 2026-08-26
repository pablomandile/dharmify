<script setup lang="ts">
import { computed } from 'vue';
import type { Marca } from '@/composables/useTranscripcion';

/**
 * El texto de una transcripción, en párrafos legibles.
 *
 * Lo usan el panel lateral y la pantalla completa, que muestran exactamente lo
 * mismo con distinto ancho.
 */
const props = defineProps<{
    texto: string | null;
    marcas: Marca[] | null;
}>();

/**
 * Desde qué silencio entre dos líneas se corta el párrafo.
 *
 * El mismo criterio que usa el servidor al aplanar el texto. Está acá otra vez
 * porque cuando hay marcas el texto se dibuja desde ellas y no desde la versión
 * aplanada: es lo que deja cada línea en su propio <span> con el segundo en que
 * se dice, y lo que va a hacer que resaltar al ritmo del audio sea agregar una
 * clase en vez de andar contando caracteres.
 */
const PAUSA = 2;

/** Con marcas, párrafos de líneas; sin marcas, párrafos de texto pelado. */
const parrafos = computed<Marca[][]>(() => {
    if (!props.marcas?.length) {
        return [];
    }

    const armados: Marca[][] = [];
    let actual: Marca[] = [];
    let finAnterior: number | null = null;

    for (const marca of props.marcas) {
        if (
            actual.length &&
            finAnterior !== null &&
            marca.inicio - finAnterior > PAUSA
        ) {
            armados.push(actual);
            actual = [];
        }

        actual.push(marca);
        finAnterior = marca.fin;
    }

    if (actual.length) {
        armados.push(actual);
    }

    return armados;
});

const sueltos = computed(() =>
    (props.texto ?? '').split(/\n{2,}/).filter((p) => p.trim() !== ''),
);
</script>

<template>
    <!--
        `leading-relaxed` y el ancho acotado no son adorno: esto se lee de
        corrido durante una charla de dos horas.
    -->
    <div class="space-y-4 text-sm leading-relaxed">
        <template v-if="parrafos.length">
            <p v-for="(parrafo, i) in parrafos" :key="i">
                <span
                    v-for="marca in parrafo"
                    :key="marca.inicio"
                    :data-inicio="marca.inicio"
                >
                    {{ marca.texto }}
                </span>
            </p>
        </template>

        <template v-else>
            <p
                v-for="(parrafo, i) in sueltos"
                :key="i"
                class="whitespace-pre-line"
            >
                {{ parrafo }}
            </p>
        </template>
    </div>
</template>
