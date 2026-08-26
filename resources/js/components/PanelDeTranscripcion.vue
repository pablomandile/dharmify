<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Download, FileUp, Maximize2 } from '@lucide/vue';
import { computed, onUnmounted, ref, useTemplateRef } from 'vue';
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
 * En una SPA, navegar no desmonta los overlays: sin esto, el panel queda abierto
 * tapando la pantalla nueva. Es el mismo arreglo que ya tiene el menú lateral.
 */
onUnmounted(router.on('navigate', cerrar));
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
                        v-if="ficha.texto"
                        :as="Link"
                        :href="ficha.urlCompleta"
                        variant="outline"
                        size="sm"
                    >
                        <Maximize2 class="size-4" />
                        Pantalla completa
                    </Button>

                    <Button
                        :as="'a'"
                        :href="ficha.urlBajar"
                        variant="ghost"
                        size="sm"
                    >
                        <Download class="size-4" />
                        Descargar
                    </Button>

                    <Button
                        v-if="esAdmin"
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
