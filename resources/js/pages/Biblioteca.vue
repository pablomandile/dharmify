<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Search, X } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { biblioteca } from '@/routes';

type Maestro = { nombre: string; slug: string };

type Serie = {
    id: number;
    slug: string;
    titulo: string;
    tipo: string | null;
    anio: number | null;
    idioma: string;
    portada: string | null;
    pistas: number;
    segundos: number | null;
    maestros: Maestro[];
};

const props = defineProps<{
    series: {
        data: Serie[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filtros: {
        buscar?: string;
        maestro?: string;
        tipo?: string;
        anio?: number;
    };
    maestros: Maestro[];
    tipos: string[];
    anios: number[];
    totales: { series: number; pistas: number };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dharma', href: '/biblioteca' }],
    },
});

const buscar = ref(props.filtros.buscar ?? '');

/*
 * Se espera a que deje de tipear antes de pedir. Sin esto, cada tecla dispara
 * una visita y la lista parpadea mientras escribe.
 */
let temporizador: ReturnType<typeof setTimeout>;

watch(buscar, (valor) => {
    clearTimeout(temporizador);
    temporizador = setTimeout(
        () => aplicar({ buscar: valor || undefined }),
        350,
    );
});

const aplicar = (cambios: Record<string, unknown>) => {
    router.get(
        biblioteca().url,
        { ...props.filtros, ...cambios },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const limpiar = () => {
    buscar.value = '';
    router.get(biblioteca().url, {}, { preserveState: true });
};

const hayFiltros = () =>
    !!(
        props.filtros.buscar ||
        props.filtros.maestro ||
        props.filtros.tipo ||
        props.filtros.anio
    );

/*
 * Las etiquetas del paginador de Laravel vienen en inglés y con entidades HTML
 * ("&laquo; Previous"). Se traducen y se muestran como TEXTO: pintarlas con
 * v-html sobre un componente rompe su contenido, además de meter HTML del
 * servidor en el DOM sin necesidad.
 */
const etiquetaPagina = (label: string) => {
    const limpio = label.replace(/&laquo;|&raquo;|&hellip;/g, '').trim();

    if (/previous/i.test(limpio)) {
        return '‹ Anterior';
    }

    if (/next/i.test(limpio)) {
        return 'Siguiente ›';
    }

    return limpio || '…';
};

/*
 * El total viene en null cuando alguna pista de la serie todavía no fue medida:
 * mostrar una suma incompleta diría que un retiro de doce horas dura nueve.
 */
const duracion = (segundos: number | null) => {
    if (!segundos) {
        return null;
    }

    const h = Math.floor(segundos / 3600);
    const m = Math.round((segundos % 3600) / 60);

    return h ? `${h} h ${m} min` : `${m} min`;
};

const etiquetaTipo: Record<string, string> = {
    retiro: 'Retiro',
    curso: 'Curso',
    festival: 'Festival',
    programa: 'Programa',
    iniciacion: 'Iniciación',
    charla: 'Charla',
    entrevista: 'Entrevista',
    visita: 'Visita',
    oraciones: 'Oraciones',
    practica: 'Práctica',
};
</script>

<template>
    <Head title="Dharma" />

    <div class="space-y-6 p-4">
        <div>
            <h1 class="text-2xl font-semibold">Tu biblioteca</h1>
            <p class="text-sm text-muted-foreground">
                {{ totales.series }} series · {{ totales.pistas }} enseñanzas
            </p>
        </div>

        <div class="space-y-3">
            <div class="relative">
                <Search
                    class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="buscar"
                    class="pl-9"
                    placeholder="Buscar por título, retiro o maestro…"
                />
            </div>

            <div class="flex flex-wrap gap-2">
                <select
                    class="rounded-md border bg-background px-3 py-1.5 text-sm"
                    :value="filtros.maestro ?? ''"
                    @change="
                        aplicar({
                            maestro:
                                ($event.target as HTMLSelectElement).value ||
                                undefined,
                        })
                    "
                >
                    <option value="">Todos los maestros</option>
                    <option v-for="m in maestros" :key="m.slug" :value="m.slug">
                        {{ m.nombre }}
                    </option>
                </select>

                <select
                    class="rounded-md border bg-background px-3 py-1.5 text-sm"
                    :value="filtros.tipo ?? ''"
                    @change="
                        aplicar({
                            tipo:
                                ($event.target as HTMLSelectElement).value ||
                                undefined,
                        })
                    "
                >
                    <option value="">Todo tipo</option>
                    <option v-for="t in tipos" :key="t" :value="t">
                        {{ etiquetaTipo[t] ?? t }}
                    </option>
                </select>

                <select
                    class="rounded-md border bg-background px-3 py-1.5 text-sm"
                    :value="filtros.anio ?? ''"
                    @change="
                        aplicar({
                            anio:
                                ($event.target as HTMLSelectElement).value ||
                                undefined,
                        })
                    "
                >
                    <option value="">Todos los años</option>
                    <option v-for="a in anios" :key="a" :value="a">
                        {{ a }}
                    </option>
                </select>

                <Button
                    v-if="hayFiltros()"
                    variant="ghost"
                    size="sm"
                    @click="limpiar"
                >
                    <X class="size-4" />
                    Limpiar
                </Button>
            </div>
        </div>

        <div
            v-if="!series.data.length"
            class="rounded-lg border border-dashed p-10 text-center"
        >
            <p class="text-sm text-muted-foreground">
                <template v-if="hayFiltros()">
                    No hay nada que coincida con esa búsqueda.
                </template>
                <template v-else>
                    La biblioteca está vacía. Agregá una fuente de OneDrive en
                    Configuración y tocá «Refrescar biblioteca».
                </template>
            </p>
        </div>

        <!--
            Seis por fila en escritorio. Con fichas tan angostas la carátula pasa
            a ser cuadrada: el recorte 16/10 le comía la mitad de arriba y de
            abajo a las genéricas, que son cuadradas y llevan el título adentro.
        -->
        <div
            v-else
            class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6"
        >
            <Link
                v-for="serie in series.data"
                :key="serie.id"
                :href="`/series/${serie.id}`"
                class="group overflow-hidden rounded-xl border transition-colors hover:border-primary/60 hover:bg-accent/30"
            >
                <!--
                    La carátula sale del primer audio de la serie. Sólo 1 de cada
                    3 grabaciones la trae embebida, así que el degradé de abajo no
                    es decorativo: es lo que ve la mayoría, y evita que la grilla
                    quede llena de huecos grises.
                -->
                <div
                    class="relative aspect-square overflow-hidden bg-linear-to-br from-primary/30 via-primary/10 to-transparent"
                >
                    <img
                        v-if="serie.portada"
                        :src="serie.portada"
                        alt=""
                        loading="lazy"
                        class="size-full object-cover transition-transform duration-300 group-hover:scale-[1.03]"
                        onerror="this.style.display = 'none'"
                    />

                    <!-- Despega el año del dibujo sin taparlo. -->
                    <div
                        class="pointer-events-none absolute inset-x-0 top-0 h-16 bg-linear-to-b from-black/55 to-transparent"
                    />

                    <Badge
                        v-if="serie.anio"
                        variant="secondary"
                        class="absolute top-2 right-2"
                    >
                        {{ serie.anio }}
                    </Badge>
                </div>

                <div class="p-4">
                    <h2 class="font-medium group-hover:text-primary">
                        {{ serie.titulo }}
                    </h2>

                    <p
                        v-if="serie.maestros.length"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        {{ serie.maestros.map((m) => m.nombre).join(' · ') }}
                    </p>

                    <div
                        class="mt-3 flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                    >
                        <Badge v-if="serie.tipo" variant="outline">
                            {{ etiquetaTipo[serie.tipo] ?? serie.tipo }}
                        </Badge>
                        <span
                            >{{ serie.pistas }}
                            {{ serie.pistas === 1 ? 'audio' : 'audios' }}</span
                        >
                        <span v-if="duracion(serie.segundos)">
                            {{ duracion(serie.segundos) }}
                        </span>
                        <Badge v-if="serie.idioma === 'en'" variant="outline">
                            Inglés
                        </Badge>
                    </div>
                </div>
            </Link>
        </div>

        <div
            v-if="series.links.length > 3"
            class="flex flex-wrap justify-center gap-1"
        >
            <Link
                v-for="link in series.links"
                :key="link.label"
                :href="link.url ?? '#'"
                :class="[
                    'rounded-md px-3 py-1.5 text-sm',
                    link.active
                        ? 'bg-primary text-primary-foreground'
                        : 'hover:bg-accent',
                    !link.url && 'pointer-events-none opacity-40',
                ]"
                preserve-scroll
            >
                {{ etiquetaPagina(link.label) }}
            </Link>
        </div>
    </div>
</template>
