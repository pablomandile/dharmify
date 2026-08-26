<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Download, Play } from '@lucide/vue';
import TextoDeTranscripcion from '@/components/TextoDeTranscripcion.vue';
import { Button } from '@/components/ui/button';
import type { FichaDeTranscripcion } from '@/composables/useTranscripcion';

const props = defineProps<{ transcripcion: FichaDeTranscripcion }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Transcripción', href: '#' }],
    },
});

/*
 * El audio no se toca desde acá: si ya venía sonando, sigue sonando —el
 * reproductor vive en el layout— y si no, para eso está el botón que lleva a la
 * serie. Arrancar la reproducción sola al abrir una pantalla de lectura sería
 * una sorpresa desagradable en un colectivo.
 */
const volver = `/series/${props.transcripcion.serieId}`;
</script>

<template>
    <Head :title="transcripcion.titulo" />

    <div class="p-4">
        <div class="mx-auto max-w-2xl space-y-6">
            <Link
                :href="volver"
                class="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
            >
                <ArrowLeft class="size-4" />
                Volver a {{ transcripcion.serie }}
            </Link>

            <div>
                <h1 class="text-2xl font-semibold">
                    {{ transcripcion.titulo }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ transcripcion.serie }}
                    <template v-if="transcripcion.palabras">
                        · unas
                        {{ transcripcion.palabras.toLocaleString('es-AR') }}
                        palabras
                    </template>
                </p>

                <div class="mt-4 flex flex-wrap gap-2">
                    <Button
                        :as="Link"
                        :href="volver"
                        variant="outline"
                        size="sm"
                    >
                        <Play class="size-4" />
                        Ir al audio
                    </Button>
                    <Button
                        :as="'a'"
                        :href="transcripcion.urlBajar"
                        variant="ghost"
                        size="sm"
                    >
                        <Download class="size-4" />
                        Descargar el original
                    </Button>
                </div>
            </div>

            <!--
                Ancho acotado y texto un punto más grande que en el panel: acá se
                viene a leer en serio, y una línea de 120 caracteres cansa a los
                cinco minutos.
            -->
            <article class="border-t pt-6 text-base">
                <TextoDeTranscripcion
                    :texto="transcripcion.texto"
                    :marcas="transcripcion.marcas"
                />
            </article>
        </div>
    </div>
</template>
