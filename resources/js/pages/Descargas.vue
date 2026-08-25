<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { HardDriveDownload, Pause, Play, Trash2 } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import type { FichaGuardada } from '@/composables/useOffline';
import { useOffline } from '@/composables/useOffline';
import { useReproductor } from '@/composables/useReproductor';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Descargas', href: '/descargas' }],
    },
});

const { listar, borrar, espacio } = useOffline();
const { actual, sonando, cola, reproducir, alternar } = useReproductor();

const fichas = ref<FichaGuardada[]>([]);
const cargando = ref(true);
const ocupado = ref<{ usado: number; total: number } | null>(null);

const cargar = async () => {
    cargando.value = true;
    fichas.value = await listar();
    ocupado.value = await espacio();
    cargando.value = false;
};

onMounted(cargar);

/*
 * Agrupadas por serie, que es como se escucha un retiro: lo que se bajó son
 * ocho clases del mismo evento, no ocho archivos sueltos.
 */
const porSerie = computed(() => {
    const grupos = new Map<
        number,
        {
            serieId: number;
            serie: string;
            portada: string | null;
            pistas: FichaGuardada[];
        }
    >();

    for (const ficha of fichas.value) {
        const grupo = grupos.get(ficha.serieId) ?? {
            serieId: ficha.serieId,
            serie: ficha.serie || 'Sin identificar',
            portada: ficha.portada,
            pistas: [],
        };

        grupo.pistas.push(ficha);
        grupos.set(ficha.serieId, grupo);
    }

    return [...grupos.values()]
        .map((g) => ({
            ...g,
            pistas: [...g.pistas].sort((a, b) =>
                a.titulo.localeCompare(b.titulo),
            ),
            bytes: g.pistas.reduce((suma, p) => suma + p.bytes, 0),
        }))
        .sort((a, b) => a.serie.localeCompare(b.serie));
});

const bytesTotales = computed(() =>
    fichas.value.reduce((suma, f) => suma + f.bytes, 0),
);

const enMegas = (bytes: number) =>
    bytes >= 1073741824
        ? `${(bytes / 1073741824).toFixed(1)} GB`
        : `${Math.round(bytes / 1048576)} MB`;

const duracion = (segundos: number | null) => {
    if (!segundos) {
        return null;
    }

    const h = Math.floor(segundos / 3600);
    const m = Math.round((segundos % 3600) / 60);

    return h ? `${h} h ${m} min` : `${m} min`;
};

const paraReproducir = (f: FichaGuardada) => ({
    id: f.id,
    titulo: f.titulo || `Audio ${f.id}`,
    serie: f.serie,
    serieId: f.serieId,
    /*
     * Las dos en false y no es un descuido: esta ficha salió de la caché del
     * dispositivo, así que el audio está acá, y el reproductor mira la copia
     * local antes que el server y que la nube. Antes esto decía `en_server:
     * true` para esquivar justo eso, y era la única pantalla donde sonaba lo
     * bajado.
     */
    en_server: false,
    en_nube: false,
});

const tocar = (grupo: { pistas: FichaGuardada[] }, ficha: FichaGuardada) => {
    cola.value = grupo.pistas.map(paraReproducir);

    if (actual.value?.id === ficha.id) {
        alternar();

        return;
    }

    reproducir(paraReproducir(ficha));
};

const quitar = async (id: number) => {
    await borrar(id);
    await cargar();
};

const quitarLaSerie = async (pistas: FichaGuardada[]) => {
    await Promise.all(pistas.map((p) => borrar(p.id)));
    await cargar();
};

const estaSonando = (f: FichaGuardada) =>
    actual.value?.id === f.id && sonando.value;
</script>

<template>
    <Head title="Descargas" />

    <div class="space-y-6 p-4">
        <div>
            <h1 class="text-2xl font-semibold">Descargas</h1>
            <p class="text-sm text-muted-foreground">
                <template v-if="fichas.length">
                    {{ fichas.length }}
                    {{ fichas.length === 1 ? 'audio' : 'audios' }} en este
                    dispositivo · {{ enMegas(bytesTotales) }}
                    <template v-if="ocupado?.total">
                        de {{ enMegas(ocupado.total) }} disponibles
                    </template>
                </template>
                <template v-else>
                    Lo que guardes para escuchar sin conexión aparece acá.
                </template>
            </p>
        </div>

        <div
            v-if="cargando"
            class="rounded-lg border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            Revisando lo que hay guardado…
        </div>

        <!--
            Es lo único de la app que no sale del servidor: la lista vive en la
            caché del navegador, porque lo que bajaste en el teléfono no está en
            la computadora y el servidor no tiene forma de saberlo.
        -->
        <div
            v-else-if="!fichas.length"
            class="rounded-lg border border-dashed p-10 text-center"
        >
            <HardDriveDownload
                class="mx-auto size-8 text-muted-foreground/60"
            />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no bajaste ningún audio a este dispositivo.
            </p>
            <p class="mt-1 text-sm text-muted-foreground">
                Entrá a una serie y tocá el ícono del teléfono en la enseñanza
                que quieras llevarte.
            </p>
            <Button as-child variant="outline" size="sm" class="mt-4">
                <Link href="/biblioteca">Ir a la biblioteca</Link>
            </Button>
        </div>

        <div v-else class="space-y-4">
            <div
                v-for="grupo in porSerie"
                :key="grupo.serieId"
                class="overflow-hidden rounded-xl border"
            >
                <div class="flex items-center gap-3 border-b bg-muted/30 p-3">
                    <div
                        class="size-12 shrink-0 overflow-hidden rounded-lg bg-linear-to-br from-primary/30 to-transparent"
                    >
                        <img
                            v-if="grupo.portada"
                            :src="grupo.portada"
                            alt=""
                            class="size-full object-cover"
                            onerror="this.style.display = 'none'"
                        />
                    </div>

                    <div class="min-w-0 flex-1">
                        <Link
                            v-if="grupo.serieId"
                            :href="`/series/${grupo.serieId}`"
                            class="font-medium hover:text-primary"
                        >
                            {{ grupo.serie }}
                        </Link>
                        <span v-else class="font-medium">{{
                            grupo.serie
                        }}</span>

                        <p class="text-xs text-muted-foreground">
                            {{ grupo.pistas.length }}
                            {{ grupo.pistas.length === 1 ? 'audio' : 'audios' }}
                            · {{ enMegas(grupo.bytes) }}
                        </p>
                    </div>

                    <Button
                        variant="ghost"
                        size="sm"
                        :aria-label="`Borrar los audios de ${grupo.serie}`"
                        @click="quitarLaSerie(grupo.pistas)"
                    >
                        <Trash2 class="size-4" />
                        <span class="hidden sm:inline">Liberar espacio</span>
                    </Button>
                </div>

                <div class="divide-y">
                    <div
                        v-for="ficha in grupo.pistas"
                        :key="ficha.id"
                        class="flex items-center gap-3 px-3 py-2.5 transition-colors"
                        :class="
                            actual?.id === ficha.id
                                ? 'bg-primary/10'
                                : 'hover:bg-accent/40'
                        "
                    >
                        <button
                            class="grid size-9 shrink-0 place-items-center rounded-full border transition-colors"
                            :class="
                                actual?.id === ficha.id
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'hover:border-primary hover:text-primary'
                            "
                            :aria-label="
                                estaSonando(ficha)
                                    ? 'Pausar'
                                    : `Reproducir ${ficha.titulo}`
                            "
                            @click="tocar(grupo, ficha)"
                        >
                            <Pause v-if="estaSonando(ficha)" class="size-4" />
                            <Play v-else class="size-4" />
                        </button>

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm">
                                {{ ficha.titulo || `Audio ${ficha.id}` }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                <template v-if="duracion(ficha.duracion_seg)">
                                    {{ duracion(ficha.duracion_seg) }} ·
                                </template>
                                {{ enMegas(ficha.bytes) }}
                            </p>
                        </div>

                        <Button
                            variant="ghost"
                            size="icon"
                            :aria-label="`Borrar ${ficha.titulo}`"
                            @click="quitar(ficha.id)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
