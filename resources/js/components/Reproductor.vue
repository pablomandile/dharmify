<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Pause,
    Play,
    RotateCcw,
    RotateCw,
    SkipBack,
    SkipForward,
    X,
} from '@lucide/vue';
import { computed } from 'vue';
import { useReproductor } from '@/composables/useReproductor';

const {
    actual,
    sonando,
    posicion,
    duracion,
    velocidad,
    alternar,
    saltar,
    irA,
    cambiarVelocidad,
    siguiente,
    anterior,
    cerrar,
    hayAnterior,
    haySiguiente,
} = useReproductor();

const reloj = (segundos: number) => {
    if (!segundos || !isFinite(segundos)) {
        return '0:00';
    }

    const h = Math.floor(segundos / 3600);
    const m = Math.floor((segundos % 3600) / 60);
    const s = Math.floor(segundos % 60);

    return h
        ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
        : `${m}:${String(s).padStart(2, '0')}`;
};

const progreso = computed(() =>
    duracion.value ? (posicion.value / duracion.value) * 100 : 0,
);

const VELOCIDADES = [0.75, 1, 1.25, 1.5, 1.75, 2];

const siguienteVelocidad = () => {
    const i = VELOCIDADES.indexOf(velocidad.value);
    cambiarVelocidad(VELOCIDADES[(i + 1) % VELOCIDADES.length]);
};
</script>

<!--
    El reproductor fijo.

    Se monta en el layout, NO en una página: en una SPA la navegación reemplaza
    la página pero no el layout, y es lo único que hace que la charla siga
    sonando mientras se recorre la biblioteca.
-->
<template>
    <div
        v-if="actual"
        class="fixed inset-x-0 bottom-0 z-50 border-t border-border/60 bg-card/95 backdrop-blur supports-[backdrop-filter]:bg-card/80"
    >
        <!-- Barra de progreso: es también el control para adelantar. -->
        <div class="relative h-1 w-full bg-border/60">
            <div
                class="h-full bg-primary transition-[width] duration-150"
                :style="{ width: `${progreso}%` }"
            />
            <input
                type="range"
                min="0"
                :max="duracion || 0"
                :value="posicion"
                class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                aria-label="Posición"
                @input="irA(Number(($event.target as HTMLInputElement).value))"
            />
        </div>

        <div class="mx-auto flex max-w-5xl items-center gap-3 px-3 py-2.5">
            <img
                :src="`/series/${actual.serieId}/portada`"
                alt=""
                class="hidden size-11 shrink-0 rounded-md object-cover sm:block"
                onerror="this.style.visibility = 'hidden'"
            />

            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium">{{ actual.titulo }}</p>
                <Link
                    :href="`/series/${actual.serieId}`"
                    class="block truncate text-xs text-muted-foreground hover:text-foreground"
                >
                    {{ actual.serie }}
                </Link>
            </div>

            <div
                class="hidden items-center gap-1 text-xs text-muted-foreground tabular-nums sm:flex"
            >
                {{ reloj(posicion) }} / {{ reloj(duracion) }}
            </div>

            <div class="flex items-center gap-0.5">
                <button
                    v-if="hayAnterior"
                    class="rounded-md p-2 hover:bg-accent"
                    aria-label="Anterior"
                    @click="anterior"
                >
                    <SkipBack class="size-4" />
                </button>

                <button
                    class="rounded-md p-2 hover:bg-accent"
                    aria-label="Retroceder 15 segundos"
                    @click="saltar(-15)"
                >
                    <RotateCcw class="size-4" />
                </button>

                <button
                    class="mx-1 grid size-10 place-items-center rounded-full bg-primary text-primary-foreground hover:opacity-90"
                    :aria-label="sonando ? 'Pausar' : 'Reproducir'"
                    @click="alternar"
                >
                    <Pause v-if="sonando" class="size-5" />
                    <Play v-else class="size-5" />
                </button>

                <button
                    class="rounded-md p-2 hover:bg-accent"
                    aria-label="Adelantar 30 segundos"
                    @click="saltar(30)"
                >
                    <RotateCw class="size-4" />
                </button>

                <button
                    v-if="haySiguiente"
                    class="rounded-md p-2 hover:bg-accent"
                    aria-label="Siguiente"
                    @click="siguiente"
                >
                    <SkipForward class="size-4" />
                </button>

                <!-- Para una charla de dos horas, la velocidad no es un lujo. -->
                <button
                    class="ml-1 rounded-md px-2 py-1 text-xs font-medium tabular-nums hover:bg-accent"
                    aria-label="Cambiar la velocidad"
                    @click="siguienteVelocidad"
                >
                    {{ velocidad }}×
                </button>

                <button
                    class="rounded-md p-2 text-muted-foreground hover:bg-accent"
                    aria-label="Cerrar el reproductor"
                    @click="cerrar"
                >
                    <X class="size-4" />
                </button>
            </div>
        </div>
    </div>
</template>
