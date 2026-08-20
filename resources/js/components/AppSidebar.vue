<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { HardDriveDownload, Heart, LayoutGrid, ListMusic } from '@lucide/vue';
import { onUnmounted } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import BotonInstalar from '@/components/BotonInstalar.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { biblioteca } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dharma',
        href: biblioteca(),
        icon: LayoutGrid,
        desplegable: 'carpetas',
    },
    {
        title: 'Favoritos',
        href: '/favoritos',
        icon: Heart,
    },
    {
        title: 'Mis listas',
        href: '/listas',
        icon: ListMusic,
        desplegable: 'listas',
    },
    {
        title: 'Descargas',
        href: '/descargas',
        icon: HardDriveDownload,
    },
];

/*
 * Acá venían "Repositorio" y "Documentación", que apuntaban al repo del starter
 * kit y a la doc de Laravel. Son andamiaje: en una biblioteca de enseñanzas no
 * significan nada para quien la usa.
 */

const { isMobile, setOpenMobile } = useSidebar();

/*
 * En el celular la barra lateral es una hoja a pantalla completa, y navegar por
 * el cliente no la desmonta: sin esto, elegís una opción, la pantalla nueva
 * carga detrás, y el menú queda encima tapándola con el scroll del body
 * bloqueado. En escritorio la barra es fija, así que cerrarla al navegar sería
 * quedarse sin menú a cada paso: por eso sólo en mobile.
 *
 * Va en el router y no en cada enlace, que es lo que se olvida en cuanto alguien
 * agrega el enlace número siete. Y en `navigate` y no en `start`, porque `start`
 * también dispara con cualquier recarga de fondo y cerraría el menú mientras la
 * persona lo está mirando.
 */
onUnmounted(
    router.on('navigate', () => {
        if (isMobile.value) {
            setOpenMobile(false);
        }
    }),
);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="biblioteca()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <BotonInstalar />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
