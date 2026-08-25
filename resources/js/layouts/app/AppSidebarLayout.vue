<script setup lang="ts">
import { onMounted } from 'vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import Reproductor from '@/components/Reproductor.vue';
import { Toaster } from '@/components/ui/sonner';
import { useOffline } from '@/composables/useOffline';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

/*
 * Qué hay bajado en este dispositivo se averigua una vez acá, en el layout, y no
 * en cada pantalla: el reproductor lo consulta antes de salir a la red, y una
 * pantalla que se olvide de preguntarlo hace que darle play a algo ya bajado se
 * vaya a buscar a la nube. Al vivir en el layout persistente, corre una sola vez
 * por sesión y sigue valiendo mientras se navega.
 */
const { revisar } = useOffline();

onMounted(revisar);
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="overflow-x-hidden">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <!-- Espacio para que el reproductor fijo no tape la última fila. -->
            <div class="pb-24">
                <slot />
            </div>
        </AppContent>

        <!--
            El reproductor va acá, en el layout, y no dentro de una página: en una
            SPA la navegación reemplaza la página pero conserva el layout, y es lo
            único que hace que la charla siga sonando mientras se recorre la
            biblioteca.
        -->
        <Reproductor />
        <Toaster />
    </AppShell>
</template>
