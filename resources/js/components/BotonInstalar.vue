<script setup lang="ts">
import { Download } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { usePwaInstall } from '@/composables/usePwaInstall';

// `sePuedeInstalar` no se usa a propósito: el botón se muestra igual aunque el
// evento no haya llegado, porque en iOS no llega nunca.
const { yaInstalada, esIOS, instalar } = usePwaInstall();

const mostrarInstructivo = ref(false);

const alTocar = async () => {
    // Si no hay diálogo nativo disponible —iOS, o un prompt ya consumido— se
    // explica cómo hacerlo a mano en vez de no hacer nada.
    if (!(await instalar())) {
        mostrarInstructivo.value = true;
    }
};
</script>

<!--
    "Instalar app".

    Se muestra también en iOS, donde `beforeinstallprompt` no existe: si el botón
    dependiera de que ese evento llegue, en iPhone no aparecería nunca. Y NO se
    esconde después de usarlo, porque el prompt se consume una sola vez aunque la
    persona lo descarte; a partir del segundo toque ofrece el instructivo manual.
-->
<template>
    <template v-if="!yaInstalada">
        <Button
            variant="outline"
            size="sm"
            class="w-full justify-start gap-2"
            data-test="instalar-app"
            @click="alTocar"
        >
            <Download class="size-4" />
            <span>Instalar app</span>
        </Button>

        <Dialog v-model:open="mostrarInstructivo">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Instalar Dharmify</DialogTitle>
                    <DialogDescription>
                        Queda como una app más, con su ícono, y abre sin la
                        barra del navegador.
                    </DialogDescription>
                </DialogHeader>

                <ol
                    v-if="esIOS"
                    class="list-decimal space-y-2 pl-5 text-sm text-muted-foreground"
                >
                    <li>Tocá el botón <strong>Compartir</strong> de Safari.</li>
                    <li>
                        Elegí <strong>Agregar a pantalla de inicio</strong>.
                    </li>
                    <li>Confirmá con <strong>Agregar</strong>.</li>
                </ol>

                <ol
                    v-else
                    class="list-decimal space-y-2 pl-5 text-sm text-muted-foreground"
                >
                    <li>Abrí el menú del navegador (los tres puntos).</li>
                    <li>
                        Elegí <strong>Instalar</strong> o
                        <strong>Agregar a pantalla de inicio</strong>.
                    </li>
                </ol>
            </DialogContent>
        </Dialog>
    </template>
</template>
