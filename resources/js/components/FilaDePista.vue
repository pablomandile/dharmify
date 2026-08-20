<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import {
    Check,
    Cloud,
    Download,
    HardDrive,
    Heart,
    ListPlus,
    Loader2,
    Pause,
    Play,
    Plus,
    Smartphone,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useOffline } from '@/composables/useOffline';
import { useReproductor } from '@/composables/useReproductor';
import type { FichaDePista, ListaBreve } from '@/types/biblioteca';

/**
 * Una enseñanza en una lista.
 *
 * Vive en un componente y no en cada pantalla porque son cuatro las que muestran
 * pistas —la serie, los favoritos, una lista y las descargas— y cada botón que
 * se agregue tendría que copiarse cuatro veces. Ya pasó con la duración.
 */
const props = defineProps<{
    pista: FichaDePista;
    /** Lo que suena después cuando ésta termina. */
    cola: FichaDePista[];
    indice?: number;
    /** En favoritos y en una lista, de qué serie salió cada enseñanza. */
    mostrarSerie?: boolean;
}>();

/*
 * Las listas se buscan acá y no llegan por prop: el menú de "agregar a" cuelga
 * de cada enseñanza, y hacer que cuatro pantallas las pasen hacia abajo es la
 * clase de cableado que después se olvida en la quinta.
 */
const page = usePage();
const listas = computed(() => (page.props.listas ?? []) as ListaBreve[]);

// Renombrada: la prop `cola` es lo que ESTA pantalla quiere que suene después,
// y `colaDelReproductor` es lo que el reproductor tiene cargado ahora. Tenerlas
// con el mismo nombre en el mismo archivo es pedir confundirlas.
const {
    actual,
    sonando,
    avisos,
    enServer,
    cola: colaDelReproductor,
    reproducir,
    alternar,
} = useReproductor();
const { guardadas, progreso, guardar, borrar } = useOffline();

const paraReproducir = (p: FichaDePista) => ({
    id: p.id,
    titulo: p.titulo,
    serie: p.serie,
    serieId: p.serieId,
    en_server: p.en_server,
    en_nube: p.en_nube,
});

const tocar = () => {
    colaDelReproductor.value = props.cola.map(paraReproducir);

    if (actual.value?.id === props.pista.id) {
        alternar();

        return;
    }

    reproducir(paraReproducir(props.pista));
};

const esActual = () => actual.value?.id === props.pista.id;
const estaSonando = () => esActual() && sonando.value;
const estaEnServer = () =>
    props.pista.en_server || enServer[props.pista.id] === true;

const ficha = () => ({
    titulo: props.pista.titulo,
    serie: props.pista.serie,
    serieId: props.pista.serieId,
    portada: props.pista.portada,
    duracion_seg: props.pista.duracion_seg,
    bytes: props.pista.bytes,
});

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
 * `preserveScroll` en todo lo que toca el corazón o una lista: sin eso, marcar
 * una enseñanza en el puesto veinte de un retiro devuelve la página al principio
 * y hay que volver a buscar dónde se estaba.
 */
const alternarFavorita = () =>
    router.post(
        `/favoritos/${props.pista.id}`,
        {},
        { preserveScroll: true, preserveState: true },
    );

const agregarA = (listaId: number) =>
    router.post(
        `/listas/${listaId}/pistas`,
        { pista_id: props.pista.id },
        { preserveScroll: true, preserveState: true },
    );

const creando = ref(false);
const nombreNuevo = ref('');

const crearYAgregar = () => {
    if (!nombreNuevo.value.trim()) {
        return;
    }

    router.post(
        '/listas',
        { nombre: nombreNuevo.value.trim(), pista_id: props.pista.id },
        {
            preserveScroll: true,
            onFinish: () => {
                nombreNuevo.value = '';
                creando.value = false;
            },
        },
    );
};
</script>

<template>
    <div
        class="flex items-center gap-3 px-3 py-2.5 transition-colors"
        :class="esActual() ? 'bg-primary/10' : 'hover:bg-accent/40'"
    >
        <button
            class="grid size-9 shrink-0 place-items-center rounded-full border transition-colors"
            :class="
                esActual()
                    ? 'border-primary bg-primary text-primary-foreground'
                    : 'hover:border-primary hover:text-primary'
            "
            :aria-label="
                estaSonando() ? 'Pausar' : `Reproducir ${pista.titulo}`
            "
            @click="tocar"
        >
            <Loader2
                v-if="avisos[pista.id] === 'Trayendo de la nube…'"
                class="size-4 animate-spin"
            />
            <Pause v-else-if="estaSonando()" class="size-4" />
            <Play v-else class="size-4" />
        </button>

        <span
            v-if="indice !== undefined"
            class="w-5 shrink-0 text-right text-xs text-muted-foreground tabular-nums"
        >
            {{ indice }}
        </span>

        <button class="min-w-0 flex-1 text-left" @click="tocar">
            <p
                class="truncate text-sm"
                :class="esActual() && 'font-medium text-primary'"
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
                    <template v-if="mostrarSerie">
                        {{ pista.serie }} ·
                    </template>
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
            Dónde está el archivo. Cambia lo que pasa al darle play: lo que está
            en el servidor arranca al toque; lo que sólo está en la nube se trae
            primero, y la pastilla pasa a "En el server" sin recargar la página.
        -->
        <span
            class="hidden shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] sm:inline-flex"
            :class="
                estaEnServer()
                    ? 'border-primary/40 text-primary'
                    : 'text-muted-foreground'
            "
        >
            <HardDrive v-if="estaEnServer()" class="size-3" />
            <Cloud v-else class="size-3" />
            {{ estaEnServer() ? 'En el server' : 'En la nube' }}
        </span>

        <div class="flex shrink-0 items-center gap-0.5">
            <button
                class="rounded-md p-2 transition-colors hover:bg-accent"
                :class="
                    pista.favorita
                        ? 'text-primary'
                        : 'text-muted-foreground hover:text-foreground'
                "
                :aria-label="
                    pista.favorita
                        ? 'Quitar de favoritos'
                        : 'Marcar como favorita'
                "
                :title="
                    pista.favorita
                        ? 'Quitar de favoritos'
                        : 'Marcar como favorita'
                "
                @click="alternarFavorita"
            >
                <Heart
                    class="size-4"
                    :class="pista.favorita && 'fill-current'"
                />
            </button>

            <DropdownMenu>
                <DropdownMenuTrigger
                    class="rounded-md p-2 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                    aria-label="Agregar a una lista"
                    title="Agregar a una lista"
                >
                    <ListPlus class="size-4" />
                </DropdownMenuTrigger>

                <DropdownMenuContent align="end" class="w-56">
                    <DropdownMenuLabel>Agregar a una lista</DropdownMenuLabel>

                    <DropdownMenuItem
                        v-for="lista in listas"
                        :key="lista.id"
                        @select="agregarA(lista.id)"
                    >
                        {{ lista.nombre }}
                    </DropdownMenuItem>

                    <DropdownMenuSeparator v-if="listas.length" />

                    <!--
                        El campo se queda abierto al elegir "Lista nueva": si el
                        menú se cerrara habría que volver a abrirlo para escribir,
                        que es justo lo que uno no espera.
                    -->
                    <DropdownMenuItem
                        v-if="!creando"
                        @select="
                            (e: Event) => {
                                e.preventDefault();
                                creando = true;
                            }
                        "
                    >
                        <Plus class="size-4" />
                        Lista nueva…
                    </DropdownMenuItem>

                    <div v-else class="p-1">
                        <input
                            v-model="nombreNuevo"
                            class="w-full rounded-md border bg-background px-2 py-1.5 text-sm"
                            placeholder="Nombre de la lista"
                            autofocus
                            @keydown.enter.prevent="crearYAgregar"
                            @keydown.stop
                        />
                    </div>
                </DropdownMenuContent>
            </DropdownMenu>

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
                        : guardar(pista.id, ficha())
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

            <!-- Lo que sólo tiene sentido en una pantalla: quitar de la lista,
                 subirla o bajarla. -->
            <slot name="acciones" />
        </div>
    </div>
</template>
