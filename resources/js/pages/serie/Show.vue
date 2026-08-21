<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ImageUp, Loader2, Pencil } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import FilaDePista from '@/components/FilaDePista.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useOffline } from '@/composables/useOffline';
import type { FichaDePista } from '@/types/biblioteca';

const props = defineProps<{
    serie: {
        id: number;
        titulo: string;
        carpeta: string;
        tipo: string | null;
        anio: number | null;
        idioma: string;
        portada: string | null;
        portada_origen: string | null;
        maestros: { nombre: string; slug: string }[];
    };
    pistas: FichaDePista[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dharma', href: '/biblioteca' }],
    },
});

const { revisar } = useOffline();

onMounted(revisar);

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

const pesoTotal = computed(() =>
    props.pistas.reduce((suma, p) => suma + p.bytes, 0),
);

/*
 * El total sólo se muestra si todas las pistas están medidas: con una sola sin
 * medir, la suma queda corta y diría que un retiro de doce horas dura nueve.
 */
const duracionTotal = computed(() =>
    props.pistas.length && props.pistas.every((p) => p.duracion_seg)
        ? props.pistas.reduce((suma, p) => suma + (p.duracion_seg ?? 0), 0)
        : null,
);

/*
 * Cambiar la carátula.
 *
 * Sólo el administrador: es el catálogo compartido, no una preferencia de cada
 * persona. La imagen queda marcada como puesta a mano y ningún barrido posterior
 * la reemplaza.
 */
const page = usePage();
const esAdmin = computed(() => page.props.auth?.esAdmin === true);
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string>,
);

/*
 * Corregir el título a mano.
 *
 * Las dos fuentes automáticas fallan de maneras que ningún programa arregla: el
 * nombre de la carpeta a veces está mal escrito y la etiqueta del mp3 también.
 * Queda marcado como manual y ningún barrido posterior lo pisa.
 */
const renombrando = ref(false);
const titulo = useForm({ titulo: props.serie.titulo });

const renombrar = () =>
    titulo.patch(`/series/${props.serie.id}`, {
        preserveScroll: true,
        onSuccess: () => (renombrando.value = false),
    });

const archivo = ref<HTMLInputElement | null>(null);
const subiendo = ref(false);

const elegirPortada = () => archivo.value?.click();

const subirPortada = (evento: Event) => {
    const elegido = (evento.target as HTMLInputElement).files?.[0];

    if (!elegido) {
        return;
    }

    subiendo.value = true;

    router.post(
        `/series/${props.serie.id}/portada`,
        { imagen: elegido },
        {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                subiendo.value = false;

                // Sin esto, elegir el mismo archivo dos veces seguidas no
                // dispara el evento y parece que el botón dejó de andar.
                if (archivo.value) {
                    archivo.value.value = '';
                }
            },
        },
    );
};
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

        <div class="flex flex-col gap-5 sm:flex-row sm:items-end">
            <!--
                La carátula: el flyer de la carpeta, la imagen embebida en un
                audio, o una que dibujamos nosotros cuando no existe ninguna.
            -->
            <div class="w-40 shrink-0 space-y-2">
                <div
                    class="relative aspect-square overflow-hidden rounded-xl border bg-linear-to-br from-primary/25 via-primary/5 to-transparent"
                >
                    <img
                        v-if="serie.portada"
                        :src="serie.portada"
                        alt=""
                        class="size-full object-cover"
                        onerror="this.style.display = 'none'"
                    />
                </div>

                <template v-if="esAdmin">
                    <input
                        ref="archivo"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        class="hidden"
                        @change="subirPortada"
                    />

                    <Button
                        variant="outline"
                        size="sm"
                        class="w-full"
                        :disabled="subiendo"
                        @click="elegirPortada"
                    >
                        <Loader2 v-if="subiendo" class="size-4 animate-spin" />
                        <ImageUp v-else class="size-4" />
                        {{ subiendo ? 'Subiendo…' : 'Cambiar carátula' }}
                    </Button>

                    <p v-if="errors.imagen" class="text-xs text-destructive">
                        {{ errors.imagen }}
                    </p>
                    <p
                        v-else-if="serie.portada_origen === 'generada'"
                        class="text-xs text-muted-foreground"
                    >
                        Esta serie no trae imagen propia: la carátula la
                        dibujamos nosotros.
                    </p>
                </template>
            </div>

            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge v-if="serie.tipo" variant="secondary">
                        {{ etiquetaTipo[serie.tipo] ?? serie.tipo }}
                    </Badge>
                    <Badge v-if="serie.anio" variant="outline">
                        {{ serie.anio }}
                    </Badge>
                    <Badge v-if="serie.idioma === 'en'" variant="outline">
                        Inglés
                    </Badge>
                </div>

                <form
                    v-if="renombrando"
                    class="mt-2 flex max-w-xl flex-wrap gap-2"
                    @submit.prevent="renombrar"
                >
                    <Input
                        v-model="titulo.titulo"
                        class="min-w-0 flex-1"
                        maxlength="200"
                        autofocus
                    />
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="titulo.processing"
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

                <div v-else class="mt-2 flex items-start gap-2">
                    <h1 class="text-2xl font-semibold">{{ serie.titulo }}</h1>

                    <button
                        v-if="esAdmin"
                        class="mt-1 rounded-md p-1.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                        aria-label="Corregir el título"
                        title="Corregir el título"
                        @click="renombrando = true"
                    >
                        <Pencil class="size-4" />
                    </button>
                </div>

                <p
                    v-if="titulo.errors.titulo"
                    class="mt-1 text-sm text-destructive"
                >
                    {{ titulo.errors.titulo }}
                </p>

                <p
                    v-if="serie.maestros.length"
                    class="mt-1 text-muted-foreground"
                >
                    {{ serie.maestros.map((m) => m.nombre).join(' · ') }}
                </p>

                <p class="mt-2 text-sm text-muted-foreground">
                    {{ pistas.length }}
                    {{ pistas.length === 1 ? 'audio' : 'audios' }}
                    <template v-if="duracion(duracionTotal)">
                        · {{ duracion(duracionTotal) }}
                    </template>
                    · {{ enMegas(pesoTotal) }}
                </p>

                <!--
                    El nombre original de la carpeta, que suele traer sede, semana
                    o el evento completo: datos que el título ya no muestra y que
                    a veces son la única forma de reconocer una grabación.
                -->
                <p class="mt-3 font-mono text-xs text-muted-foreground/70">
                    {{ serie.carpeta }}
                </p>
            </div>
        </div>

        <div class="divide-y overflow-hidden rounded-xl border">
            <FilaDePista
                v-for="(pista, i) in pistas"
                :key="pista.id"
                :pista="pista"
                :cola="pistas"
                :indice="i + 1"
            />
        </div>
    </div>
</template>
