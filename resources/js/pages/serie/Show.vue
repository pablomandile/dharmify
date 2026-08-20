<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    Cloud,
    Download,
    HardDrive,
    Loader2,
    Pause,
    ImageUp,
    Play,
    Smartphone,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useOffline } from '@/composables/useOffline';
import { useReproductor } from '@/composables/useReproductor';

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
        portada: string | null;
        portada_origen: string | null;
        maestros: { nombre: string; slug: string }[];
    };
    pistas: Pista[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dharma', href: '/biblioteca' }],
    },
});

const { actual, sonando, avisos, enServer, cola, reproducir, alternar } =
    useReproductor();
const { guardadas, progreso, revisar, guardar, borrar } = useOffline();

onMounted(revisar);

const paraReproducir = (p: Pista) => ({
    id: p.id,
    titulo: p.titulo,
    serie: props.serie.titulo,
    serieId: props.serie.id,
    en_server: p.en_server,
    en_nube: p.en_nube,
});

/*
 * La ficha se guarda junto al audio, en la misma caché.
 *
 * Es lo que hace que la pantalla de Descargas abra sin conexión: si el título y
 * la carátula hubiera que pedírselos al servidor, la lista de lo que bajaste
 * para escuchar en el colectivo estaría vacía justo en el colectivo.
 */
const ficha = (p: Pista) => ({
    titulo: p.titulo,
    serie: props.serie.titulo,
    serieId: props.serie.id,
    portada: props.serie.portada,
    duracion_seg: p.duracion_seg,
    bytes: p.bytes,
});

const tocar = (p: Pista) => {
    // La cola es la serie entera: al terminar una charla sigue la que va, que
    // es como se escucha un retiro.
    cola.value = props.pistas.map(paraReproducir);

    if (actual.value?.id === p.id) {
        alternar();

        return;
    }

    reproducir(paraReproducir(p));
};

const estaSonando = (p: Pista) => actual.value?.id === p.id && sonando.value;
const esActual = (p: Pista) => actual.value?.id === p.id;
const estaEnServer = (p: Pista) => p.en_server || enServer[p.id] === true;

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

                <h1 class="mt-2 text-2xl font-semibold">{{ serie.titulo }}</h1>

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
            <div
                v-for="(pista, i) in pistas"
                :key="pista.id"
                class="flex items-center gap-3 px-3 py-2.5 transition-colors"
                :class="
                    esActual(pista) ? 'bg-primary/10' : 'hover:bg-accent/40'
                "
            >
                <button
                    class="grid size-9 shrink-0 place-items-center rounded-full border transition-colors"
                    :class="
                        esActual(pista)
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'hover:border-primary hover:text-primary'
                    "
                    :aria-label="
                        estaSonando(pista)
                            ? 'Pausar'
                            : `Reproducir ${pista.titulo}`
                    "
                    @click="tocar(pista)"
                >
                    <Loader2
                        v-if="avisos[pista.id] === 'Trayendo de la nube…'"
                        class="size-4 animate-spin"
                    />
                    <Pause v-else-if="estaSonando(pista)" class="size-4" />
                    <Play v-else class="size-4" />
                </button>

                <span
                    class="w-5 shrink-0 text-right text-xs text-muted-foreground tabular-nums"
                >
                    {{ i + 1 }}
                </span>

                <button class="min-w-0 flex-1 text-left" @click="tocar(pista)">
                    <p
                        class="truncate text-sm"
                        :class="esActual(pista) && 'font-medium text-primary'"
                    >
                        {{ pista.titulo }}
                    </p>
                    <p class="truncate text-xs text-muted-foreground">
                        <template v-if="avisos[pista.id]">
                            {{ avisos[pista.id] }}
                        </template>
                        <template v-else-if="progreso[pista.id]">
                            Guardando… {{ progreso[pista.id] }}
                        </template>
                        <template v-else>
                            <template v-if="pista.grabada_el">
                                {{ pista.grabada_el }} ·
                            </template>
                            <template v-if="duracion(pista.duracion_seg)">
                                {{ duracion(pista.duracion_seg) }} ·
                            </template>
                            {{ enMegas(pista.bytes) }}
                        </template>
                    </p>
                </button>

                <!--
                    Dónde está el archivo. Cambia lo que pasa al darle play: lo
                    que está en el servidor arranca al toque; lo que sólo está en
                    la nube se trae primero, y la pastilla pasa a "En el server"
                    sin recargar la página.
                -->
                <span
                    class="hidden shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] sm:inline-flex"
                    :class="
                        estaEnServer(pista)
                            ? 'border-primary/40 text-primary'
                            : 'text-muted-foreground'
                    "
                >
                    <HardDrive v-if="estaEnServer(pista)" class="size-3" />
                    <Cloud v-else class="size-3" />
                    {{ estaEnServer(pista) ? 'En el server' : 'En la nube' }}
                </span>

                <div class="flex shrink-0 items-center gap-0.5">
                    <button
                        class="rounded-md p-2 text-muted-foreground hover:bg-accent hover:text-foreground"
                        :class="guardadas.has(pista.id) && 'text-primary'"
                        :title="
                            guardadas.has(pista.id)
                                ? 'Guardado en este dispositivo — tocá para quitarlo'
                                : 'Guardar en este dispositivo para escuchar sin conexión'
                        "
                        :aria-label="
                            guardadas.has(pista.id)
                                ? 'Quitar de este dispositivo'
                                : 'Guardar para escuchar sin conexión'
                        "
                        @click="
                            guardadas.has(pista.id)
                                ? borrar(pista.id)
                                : guardar(pista.id, ficha(pista))
                        "
                    >
                        <Check v-if="guardadas.has(pista.id)" class="size-4" />
                        <Smartphone v-else class="size-4" />
                    </button>

                    <a
                        :href="`/pistas/${pista.id}/bajar`"
                        class="rounded-md p-2 text-muted-foreground hover:bg-accent hover:text-foreground"
                        title="Descargar el audio"
                        aria-label="Descargar"
                    >
                        <Download class="size-4" />
                    </a>
                </div>
            </div>
        </div>
    </div>
</template>
