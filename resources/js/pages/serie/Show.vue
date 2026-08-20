<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Cloud, HardDrive, Music } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';

type Pista = {
    id: number;
    titulo: string;
    duracion_seg: number | null;
    bytes: number;
    grabada_el: string | null;
    en_server: boolean;
    en_nube: boolean;
};

const props = defineProps<{
    serie: {
        id: number;
        titulo: string;
        carpeta: string;
        tipo: string | null;
        anio: number | null;
        idioma: string;
        maestros: { nombre: string; slug: string }[];
    };
    pistas: Pista[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dharma', href: '/biblioteca' }],
    },
});

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

const enMegas = (bytes: number) => `${Math.round(bytes / 1048576)} MB`;

const duracion = (segundos: number | null) => {
    if (!segundos) {
        return null;
    }

    const h = Math.floor(segundos / 3600);
    const m = Math.round((segundos % 3600) / 60);

    return h ? `${h} h ${m} min` : `${m} min`;
};

const pesoTotal = props.pistas.reduce((suma, p) => suma + p.bytes, 0);
</script>

<template>
    <Head :title="serie.titulo" />

    <div class="space-y-6 p-4">
        <Link
            href="/biblioteca"
            class="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" />
            Volver a la biblioteca
        </Link>

        <div>
            <div class="flex flex-wrap items-center gap-2">
                <Badge v-if="serie.tipo" variant="secondary">
                    {{ etiquetaTipo[serie.tipo] ?? serie.tipo }}
                </Badge>
                <Badge v-if="serie.anio" variant="outline">{{
                    serie.anio
                }}</Badge>
                <Badge v-if="serie.idioma === 'en'" variant="outline"
                    >Inglés</Badge
                >
            </div>

            <h1 class="mt-2 text-2xl font-semibold">{{ serie.titulo }}</h1>

            <p v-if="serie.maestros.length" class="mt-1 text-muted-foreground">
                {{ serie.maestros.map((m) => m.nombre).join(' · ') }}
            </p>

            <p class="mt-2 text-sm text-muted-foreground">
                {{ pistas.length }}
                {{ pistas.length === 1 ? 'audio' : 'audios' }} ·
                {{ enMegas(pesoTotal) }}
            </p>

            <!--
                El nombre original de la carpeta, que suele traer sede, semana o
                el evento completo: datos que el título ya no muestra y que a
                veces son la única forma de reconocer una grabación.
            -->
            <p class="mt-3 font-mono text-xs text-muted-foreground/70">
                {{ serie.carpeta }}
            </p>
        </div>

        <div class="divide-y rounded-lg border">
            <div
                v-for="(pista, i) in pistas"
                :key="pista.id"
                class="flex items-center gap-3 px-4 py-3"
            >
                <span
                    class="w-6 shrink-0 text-right text-sm text-muted-foreground"
                >
                    {{ i + 1 }}
                </span>

                <Music class="size-4 shrink-0 text-muted-foreground" />

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm">{{ pista.titulo }}</p>
                    <p class="text-xs text-muted-foreground">
                        <template v-if="pista.grabada_el">
                            {{ pista.grabada_el }} ·
                        </template>
                        <template v-if="duracion(pista.duracion_seg)">
                            {{ duracion(pista.duracion_seg) }} ·
                        </template>
                        {{ enMegas(pista.bytes) }}
                    </p>
                </div>

                <!--
                    Dónde está el archivo. Se muestra porque cambia lo que pasa
                    al darle play: lo que está en el servidor arranca al toque,
                    lo que sólo está en la nube hay que traerlo primero.
                -->
                <span
                    class="shrink-0 text-muted-foreground"
                    :title="
                        pista.en_server ? 'Listo en el servidor' : 'En la nube'
                    "
                >
                    <HardDrive v-if="pista.en_server" class="size-4" />
                    <Cloud v-else class="size-4" />
                </span>
            </div>
        </div>
    </div>
</template>
