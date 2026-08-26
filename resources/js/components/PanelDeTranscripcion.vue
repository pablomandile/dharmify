<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Check, Download, FileUp, Maximize2, Pencil, X } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref, useTemplateRef } from 'vue';
import TextoDeTranscripcion from '@/components/TextoDeTranscripcion.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import type { Marca } from '@/composables/useTranscripcion';
import { useTranscripcion } from '@/composables/useTranscripcion';

/**
 * El texto de la enseñanza, al costado y sin salir de donde estabas.
 *
 * Se monta una sola vez en el layout, igual que el reproductor: el audio sigue
 * sonando abajo mientras se lee, que es todo el punto de esto.
 */
const {
    abierto,
    cargando,
    error,
    ficha,
    sinTranscripcion,
    pistaAbierta,
    titulo,
    recargar,
    cerrar,
} = useTranscripcion();

const page = usePage();
const esAdmin = computed(() => Boolean(page.props.auth?.esAdmin));

const archivo = useTemplateRef<HTMLInputElement>('archivo');
const subiendo = ref(false);
const problema = ref<string | null>(null);

const elegir = () => archivo.value?.click();

/**
 * Sube el documento, que va a parar a la nube junto al audio.
 *
 * Al terminar se recarga la ficha —el panel muestra el texto recién subido— y
 * se refresca la página, porque la fila de la lista tiene que enterarse de que
 * ahora sí hay transcripción.
 */
const subir = (evento: Event) => {
    const elegido = (evento.target as HTMLInputElement).files?.[0];
    const pista = pistaAbierta.value;

    if (!elegido || pista === null) {
        return;
    }

    problema.value = null;
    subiendo.value = true;

    useForm({ documento: elegido }).post(`/pistas/${pista}/transcripcion`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => recargar(),
        onError: (errores) => {
            problema.value = errores.documento ?? 'No se pudo subir.';
        },
        onFinish: () => {
            subiendo.value = false;

            if (archivo.value) {
                archivo.value.value = '';
            }
        },
    });
};

/*
 * Corregir escribe en OneDrive, así que sin conexión no se puede: el lápiz no
 * aparece. Decirlo con el botón ausente es más claro que dejarlo y fallar al
 * guardar, cuando el trabajo ya está hecho.
 */
const enLinea = ref(true);
const mirarLaRed = () => (enLinea.value = navigator.onLine);

onMounted(() => {
    mirarLaRed();
    window.addEventListener('online', mirarLaRed);
    window.addEventListener('offline', mirarLaRed);
});

onUnmounted(() => {
    window.removeEventListener('online', mirarLaRed);
    window.removeEventListener('offline', mirarLaRed);
});

const puedeEditar = computed(
    () => esAdmin.value && enLinea.value && Boolean(ficha.value?.seEdita),
);

const editando = ref(false);
const guardando = ref(false);

/*
 * El borrador es una copia: mientras se corrige, lo que se ve en pantalla no es
 * lo guardado. Cancelar tiene que devolver todo como estaba, y sin la copia
 * habría que volver a pedirlo al servidor para deshacer.
 */
const borrador = ref<{ encabezado: string; tramos: Marca[]; texto: string }>({
    encabezado: '',
    tramos: [],
    texto: '',
});

const empezarAEditar = () => {
    borrador.value = {
        encabezado: ficha.value?.encabezado ?? '',
        tramos: (ficha.value?.marcas ?? []).map((m) => ({ ...m })),
        texto: ficha.value?.texto ?? '',
    };

    problema.value = null;
    editando.value = true;
};

/** Cuántos segundos, en el reloj de siempre: 279 → "4:39". */
const reloj = (segundos: number) => {
    const total = Math.round(segundos);
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    const dos = (n: number) => String(n).padStart(2, '0');

    return h > 0 ? `${h}:${dos(m)}:${dos(s)}` : `${m}:${dos(s)}`;
};

/**
 * Guarda la corrección, que va a parar a los documentos de OneDrive.
 *
 * Con marcas van los tramos, cada uno con su tiempo intacto; sin marcas —64 de
 * las 644— va el texto y nada más.
 */
const guardar = () => {
    const pista = pistaAbierta.value;

    if (pista === null) {
        return;
    }

    problema.value = null;
    guardando.value = true;

    const conTramos = borrador.value.tramos.length > 0;

    useForm(
        conTramos
            ? {
                  encabezado: borrador.value.encabezado,
                  tramos: borrador.value.tramos,
              }
            : { texto: borrador.value.texto },
    ).put(`/pistas/${pista}/transcripcion`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: async () => {
            editando.value = false;
            await recargar();
        },
        onError: (errores) => {
            problema.value =
                errores.texto ?? errores.tramos ?? 'No se pudo guardar.';
        },
        onFinish: () => (guardando.value = false),
    });
};

/*
 * En una SPA, navegar no desmonta los overlays: sin esto, el panel queda abierto
 * tapando la pantalla nueva. Es el mismo arreglo que ya tiene el menú lateral.
 */
onUnmounted(
    router.on('navigate', () => {
        editando.value = false;
        cerrar();
    }),
);
</script>

<template>
    <Sheet v-model:open="abierto">
        <SheetContent
            side="right"
            class="flex w-full flex-col gap-0 p-0 sm:max-w-xl"
        >
            <SheetHeader class="border-b px-5 py-4 text-left">
                <SheetTitle class="pr-6 text-base">
                    {{ ficha?.titulo ?? titulo }}
                </SheetTitle>
                <SheetDescription>
                    <template v-if="ficha?.palabras">
                        {{ ficha.serie }} · unas
                        {{ ficha.palabras.toLocaleString('es-AR') }} palabras
                    </template>
                    <template v-else>Transcripción</template>
                </SheetDescription>

                <div v-if="ficha" class="flex flex-wrap gap-2 pt-1">
                    <Button
                        v-if="ficha.texto && !editando"
                        :as="Link"
                        :href="ficha.urlCompleta"
                        variant="outline"
                        size="sm"
                    >
                        <Maximize2 class="size-4" />
                        Pantalla completa
                    </Button>

                    <Button
                        v-if="!editando"
                        :as="'a'"
                        :href="ficha.urlBajar"
                        variant="ghost"
                        size="sm"
                    >
                        <Download class="size-4" />
                        Descargar
                    </Button>

                    <Button
                        v-if="puedeEditar && !editando"
                        variant="ghost"
                        size="sm"
                        @click="empezarAEditar"
                    >
                        <Pencil class="size-4" />
                        Corregir
                    </Button>

                    <template v-if="editando">
                        <Button
                            size="sm"
                            :disabled="guardando"
                            @click="guardar"
                        >
                            <Spinner v-if="guardando" />
                            <Check v-else class="size-4" />
                            Guardar
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            :disabled="guardando"
                            @click="editando = false"
                        >
                            <X class="size-4" />
                            Cancelar
                        </Button>
                    </template>

                    <Button
                        v-if="esAdmin && !editando"
                        variant="ghost"
                        size="sm"
                        :disabled="subiendo"
                        @click="elegir"
                    >
                        <Spinner v-if="subiendo" />
                        <FileUp v-else class="size-4" />
                        Reemplazar
                    </Button>
                </div>
            </SheetHeader>

            <!--
                El scroll va acá adentro y no en la página: el reproductor sigue
                fijo abajo y la lista de atrás no se mueve mientras se lee.
            -->
            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                <div v-if="cargando" class="flex justify-center py-10">
                    <Spinner />
                </div>

                <p v-else-if="error" class="text-sm text-muted-foreground">
                    {{ error }}
                </p>

                <div v-else-if="sinTranscripcion" class="space-y-4 py-6">
                    <p class="text-sm text-muted-foreground">
                        Esta enseñanza todavía no tiene transcripción.
                    </p>

                    <div v-if="esAdmin" class="space-y-2">
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="subiendo"
                            @click="elegir"
                        >
                            <Spinner v-if="subiendo" />
                            <FileUp v-else class="size-4" />
                            {{ subiendo ? 'Subiendo…' : 'Subir una' }}
                        </Button>

                        <p class="text-xs text-muted-foreground">
                            Se guarda también en OneDrive, en la carpeta del
                            audio. Un <code>.srt</code> es el mejor formato:
                            trae las marcas de tiempo.
                        </p>
                    </div>
                </div>

                <!--
                    Un cuadro por tramo, con su minuto al costado. Corregir tramo
                    por tramo y no todo de una es lo que hace que los tiempos
                    sigan pegados a su texto sin recalcular nada — y de paso es
                    lo que uno quiere: estás escuchando el minuto 4 y arreglás
                    justo ese pedazo.
                -->
                <div v-else-if="editando" class="space-y-4">
                    <div class="grid gap-1.5">
                        <label class="text-xs text-muted-foreground">
                            Encabezado
                        </label>
                        <textarea
                            v-model="borrador.encabezado"
                            rows="1"
                            class="w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        />
                    </div>

                    <div
                        v-for="(tramo, i) in borrador.tramos"
                        :key="i"
                        class="grid gap-1.5"
                    >
                        <label class="font-mono text-xs text-muted-foreground">
                            {{ reloj(tramo.inicio) }} – {{ reloj(tramo.fin) }}
                        </label>
                        <textarea
                            v-model="tramo.texto"
                            rows="4"
                            class="w-full rounded-md border bg-transparent px-3 py-2 text-sm leading-relaxed shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        />
                    </div>

                    <!-- Las 64 sin marcas: no hay tramos que separar. -->
                    <textarea
                        v-if="!borrador.tramos.length"
                        v-model="borrador.texto"
                        rows="24"
                        class="w-full rounded-md border bg-transparent px-3 py-2 text-sm leading-relaxed shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                </div>

                <template v-else-if="ficha">
                    <TextoDeTranscripcion
                        v-if="ficha.texto"
                        :texto="ficha.texto"
                        :marcas="ficha.marcas"
                    />

                    <!--
                        Un PDF lo dibuja el navegador solo. Son dos en toda la
                        biblioteca: no vale la pena una librería para sacarles
                        el texto.
                    -->
                    <iframe
                        v-else-if="ficha.seEmbebe && ficha.urlVer"
                        :src="ficha.urlVer"
                        class="h-[70vh] w-full rounded-md border"
                        :title="`Transcripción de ${ficha.titulo}`"
                    />

                    <!--
                        Los .doc viejos: 12 en la biblioteca, y no se les puede
                        sacar el texto sin una librería que el hosting no tiene.
                    -->
                    <p v-else class="text-sm text-muted-foreground">
                        Este documento está en un formato viejo de Word que no
                        podemos mostrar acá. Descargalo para leerlo, o subí una
                        versión en <code>.docx</code> y queda para siempre.
                    </p>
                </template>

                <p v-if="problema" class="mt-3 text-sm text-destructive">
                    {{ problema }}
                </p>

                <input
                    v-if="esAdmin"
                    ref="archivo"
                    type="file"
                    accept=".docx,.doc,.txt,.pdf,.srt,.vtt"
                    class="hidden"
                    @change="subir"
                />
            </div>
        </SheetContent>
    </Sheet>
</template>
