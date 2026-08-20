<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

type Carpeta = { id: number; titulo: string; anio: number | null };

defineProps<{
    items: NavItem[];
}>();

const { isCurrentUrl } = useCurrentUrl();

const page = usePage();

/*
 * Las carpetas vienen compartidas desde el servidor, no de un pedido aparte:
 * así el desplegable también se abre sin conexión, porque viaja dentro del HTML
 * que el service worker ya tiene guardado.
 */
const carpetas = computed(() => (page.props.carpetas ?? []) as Carpeta[]);

const abierto = ref(false);
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>Biblioteca</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <!--
                    "Dharma" es a la vez enlace y desplegable: el botón lleva a
                    la biblioteca entera y el chevrón abre las carpetas. Van
                    separados a propósito — si abrir la lista navegara, entrar a
                    una carpeta serían siempre dos pantallas.
                -->
                <Collapsible
                    v-if="item.desplegable && carpetas.length"
                    v-model:open="abierto"
                    class="group/collapsible"
                >
                    <div class="flex items-center">
                        <SidebarMenuButton
                            as-child
                            class="flex-1"
                            :is-active="isCurrentUrl(item.href)"
                            :tooltip="item.title"
                        >
                            <Link :href="item.href">
                                <component :is="item.icon" />
                                <span>{{ item.title }}</span>
                            </Link>
                        </SidebarMenuButton>

                        <CollapsibleTrigger
                            class="mr-1 grid size-7 shrink-0 place-items-center rounded-md text-sidebar-foreground/70 transition-colors group-data-[collapsible=icon]:hidden hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
                            :aria-label="
                                abierto
                                    ? 'Ocultar las carpetas'
                                    : 'Ver las carpetas'
                            "
                        >
                            <ChevronRight
                                class="size-4 transition-transform duration-200"
                                :class="abierto && 'rotate-90'"
                            />
                        </CollapsibleTrigger>
                    </div>

                    <CollapsibleContent>
                        <!--
                            Son 145 carpetas: sin el alto máximo, la lista empuja
                            la cuenta y el botón de instalar fuera de la pantalla
                            y no hay forma de llegar a ellos.
                        -->
                        <SidebarMenuSub
                            class="max-h-[50vh] overflow-y-auto pr-1"
                        >
                            <SidebarMenuSubItem
                                v-for="carpeta in carpetas"
                                :key="carpeta.id"
                            >
                                <SidebarMenuSubButton
                                    as-child
                                    :is-active="
                                        isCurrentUrl(`/series/${carpeta.id}`)
                                    "
                                >
                                    <Link :href="`/series/${carpeta.id}`">
                                        <span class="truncate">
                                            {{ carpeta.titulo }}
                                        </span>
                                        <span
                                            v-if="carpeta.anio"
                                            class="ml-auto shrink-0 text-xs text-sidebar-foreground/50"
                                        >
                                            {{ carpeta.anio }}
                                        </span>
                                    </Link>
                                </SidebarMenuSubButton>
                            </SidebarMenuSubItem>
                        </SidebarMenuSub>
                    </CollapsibleContent>
                </Collapsible>

                <SidebarMenuButton
                    v-else
                    as-child
                    :is-active="isCurrentUrl(item.href)"
                    :tooltip="item.title"
                >
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
