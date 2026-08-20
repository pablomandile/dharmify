<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ChevronDown,
    ChevronUp,
    ListMusic,
    Play,
    Trash2,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import FilaDePista from '@/components/FilaDePista.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useOffline } from '@/composables/useOffline';
import { useReproductor } from '@/composables/useReproductor';
import type { FichaDePista, ListaBreve } from '@/types/biblioteca';

const props = defineProps<{
    lista: ListaBreve;
    pistas: FichaDePista[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Mis listas', href: '/listas' }],
    },
});

const { revisar } = useOffline();
const { cola, reproducir } = useReproductor();

onMounted(revisar);

const duracionTotal = computed(() =>
    props.pistas.length && props.pistas.every((p) => p.duracion_seg)
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

const renombrando = ref(false);
const nombre = useForm({ nombre: props.lista.nombre });

const renombrar = () =>
    nombre.patch(`/listas/${props.lista.id}`, {
        preserveScroll: true,
        onSuccess: () => (renombrando.value = false),
    });

const borrarLista = () => {
    if (
        confirm(
            `¿Borrar la lista «${props.lista.nombre}»? Las enseñanzas siguen en la biblioteca.`,
        )
    ) {
        router.delete(`/listas/${props.lista.id}`);
    }
};

const quitar = (pistaId: number) =>
    router.delete(`/listas/${props.lista.id}/pistas/${pistaId}`, {
        preserveScroll: true,
    });

const mover = (pistaId: number, hacia: 'arriba' | 'abajo') =>
    router.patch(
        `/listas/${props.lista.id}/pistas/${pistaId}`,
        { hacia },
        { preserveScroll: true },
    );
</script>

<template>
    <Head :title="lista.nombre" />

    <div class="space-y-6 p-4">
        <Link
            href="/listas"
            class="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" />
            Volver a mis listas
        </Link>

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0">
                <form
                    v-if="renombrando"
                    class="flex max-w-md gap-2"
                    @submit.prevent="renombrar"
                >
                    <Input v-model="nombre.nombre" maxlength="80" autofocus />
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="nombre.processing"
                    >
                        Guardar
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        @click="renombrando = false"
                    >
                        Cancelar
                    </Button>
                </form>

                <h1
                    v-else
                    class="cursor-text text-2xl font-semibold"
                    title="Tocá para renombrar"
                    @click="renombrando = true"
                >
                    {{ lista.nombre }}
                </h1>

                <p class="mt-1 text-sm text-muted-foreground">
                    {{ pistas.length }}
                    {{ pistas.length === 1 ? 'enseñanza' : 'enseñanzas' }}
                    <template v-if="enHoras(duracionTotal)">
                        · {{ enHoras(duracionTotal) }}
                    </template>
                </p>
            </div>

            <div class="flex gap-2">
                <Button v-if="pistas.length" @click="escucharTodo">
                    <Play class="size-4" />
                    Escuchar todo
                </Button>
                <Button variant="ghost" @click="borrarLista">
                    <Trash2 class="size-4" />
                    Borrar lista
                </Button>
            </div>
        </div>

        <div
            v-if="!pistas.length"
            class="rounded-lg border border-dashed p-10 text-center"
        >
            <ListMusic class="mx-auto size-8 text-muted-foreground/60" />
            <p class="mt-3 text-sm text-muted-foreground">
                La lista está vacía.
            </p>
            <p class="mt-1 text-sm text-muted-foreground">
                Agregá enseñanzas desde el botón «+» de cualquier audio.
            </p>
        </div>

        <div v-else class="divide-y overflow-hidden rounded-xl border">
            <FilaDePista
                v-for="(pista, i) in pistas"
                :key="pista.id"
                :pista="pista"
                :cola="pistas"
                :indice="i + 1"
                mostrar-serie
            >
                <!--
                    El orden importa: una serie de clases se escucha en secuencia.
                    Subir y bajar de a uno en vez de arrastrar — con el dedo, en
                    una lista larga y con el reproductor abajo, arrastrar es
                    exactamente lo que no funciona.
                -->
                <template #acciones>
                    <button
                        class="rounded-md p-2 text-muted-foreground hover:bg-accent hover:text-foreground disabled:opacity-30"
                        :disabled="i === 0"
                        aria-label="Subir un lugar"
                        title="Subir un lugar"
                        @click="mover(pista.id, 'arriba')"
                    >
                        <ChevronUp class="size-4" />
                    </button>

                    <button
                        class="rounded-md p-2 text-muted-foreground hover:bg-accent hover:text-foreground disabled:opacity-30"
                        :disabled="i === pistas.length - 1"
                        aria-label="Bajar un lugar"
                        title="Bajar un lugar"
                        @click="mover(pista.id, 'abajo')"
                    >
                        <ChevronDown class="size-4" />
                    </button>

                    <button
                        class="rounded-md p-2 text-muted-foreground hover:bg-accent hover:text-destructive"
                        aria-label="Quitar de la lista"
                        title="Quitar de la lista"
                        @click="quitar(pista.id)"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </template>
            </FilaDePista>
        </div>
    </div>
</template>
