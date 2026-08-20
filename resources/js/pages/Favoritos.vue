<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Heart, Play } from '@lucide/vue';
import { computed, onMounted } from 'vue';
import FilaDePista from '@/components/FilaDePista.vue';
import { Button } from '@/components/ui/button';
import { useOffline } from '@/composables/useOffline';
import { useReproductor } from '@/composables/useReproductor';
import type { FichaDePista } from '@/types/biblioteca';

const props = defineProps<{
    pistas: FichaDePista[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Favoritos', href: '/favoritos' }],
    },
});

const { revisar } = useOffline();
const { cola, reproducir } = useReproductor();

onMounted(revisar);

const duracionTotal = computed(() =>
    props.pistas.every((p) => p.duracion_seg)
        ? props.pistas.reduce((suma, p) => suma + (p.duracion_seg ?? 0), 0)
        : null,
);

const enHoras = (segundos: number | null) => {
    if (!segundos) {
        return null;
    }

    const h = Math.floor(segundos / 3600);
    const m = Math.round((segundos % 3600) / 60);

    return h ? `${h} h ${m} min` : `${m} min`;
};

const escucharTodo = () => {
    if (!props.pistas.length) {
        return;
    }

    cola.value = props.pistas.map((p) => ({
        id: p.id,
        titulo: p.titulo,
        serie: p.serie,
        serieId: p.serieId,
        en_server: p.en_server,
        en_nube: p.en_nube,
    }));

    reproducir(cola.value[0]);
};
</script>

<template>
    <Head title="Favoritos" />

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Favoritos</h1>
                <p class="text-sm text-muted-foreground">
                    <template v-if="pistas.length">
                        {{ pistas.length }}
                        {{ pistas.length === 1 ? 'enseñanza' : 'enseñanzas' }}
                        <template v-if="enHoras(duracionTotal)">
                            · {{ enHoras(duracionTotal) }}
                        </template>
                        · las últimas marcadas primero
                    </template>
                    <template v-else>
                        Lo que marques con el corazón aparece acá.
                    </template>
                </p>
            </div>

            <Button v-if="pistas.length" @click="escucharTodo">
                <Play class="size-4" />
                Escuchar todo
            </Button>
        </div>

        <div
            v-if="!pistas.length"
            class="rounded-lg border border-dashed p-10 text-center"
        >
            <Heart class="mx-auto size-8 text-muted-foreground/60" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no marcaste ninguna enseñanza.
            </p>
            <p class="mt-1 text-sm text-muted-foreground">
                El corazón está al costado de cada audio, dentro de una serie.
            </p>
            <Button as-child variant="outline" size="sm" class="mt-4">
                <Link href="/biblioteca">Ir a la biblioteca</Link>
            </Button>
        </div>

        <!--
            Con la serie a la vista: acá las enseñanzas vienen de retiros
            distintos, y "Sesión 3" sin más no le dice nada a nadie.
        -->
        <div v-else class="divide-y overflow-hidden rounded-xl border">
            <FilaDePista
                v-for="pista in pistas"
                :key="pista.id"
                :pista="pista"
                :cola="pistas"
                mostrar-serie
            />
        </div>
    </div>
</template>
