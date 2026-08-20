<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import Reproductor from '@/components/Reproductor.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
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
