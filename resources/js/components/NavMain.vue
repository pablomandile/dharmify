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
type Lista = { id: number; nombre: string; pistas: number };

/** Lo que cuelga de cada opción desplegable del menú. */
type Sub = { id: number; texto: string; href: string; al_costado?: string };

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
const carpetas = computed(() =>
    ((page.props.carpetas ?? []) as Carpeta[]).map((c): Sub => ({
        id: c.id,
        texto: c.titulo,
        href: `/series/${c.id}`,
        al_costado: c.anio ? String(c.anio) : undefined,
    })),
);

const listas = computed(() =>
    ((page.props.listas ?? []) as Lista[]).map((l): Sub => ({
        id: l.id,
        texto: l.nombre,
        href: `/listas/${l.id}`,
        al_costado: String(l.pistas),
    })),
);

const sub = (item: NavItem): Sub[] =>
    item.desplegable === 'carpetas' ? carpetas.value : listas.value;

/*
 * Un abierto por opción y no uno solo: si "Dharma" y "Mis listas" compartieran
 * el estado, abrir una cerraría la otra sin que nadie lo haya pedido.
 */
const abiertos = ref<Record<string, boolean>>({});
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
                    v-if="item.desplegable && sub(item).length"
                    :open="abiertos[item.title] === true"
                    class="group/collapsible"
                    @update:open="(v: boolean) => (abiertos[item.title] = v)"
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
                            :aria-label="`Ver ${item.title}`"
                        >
                            <ChevronRight
                                class="size-4 transition-transform duration-200"
                                :class="abiertos[item.title] && 'rotate-90'"
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
                                v-for="fila in sub(item)"
                                :key="fila.id"
                            >
                                <SidebarMenuSubButton
                                    as-child
                                    :is-active="isCurrentUrl(fila.href)"
                                >
                                    <Link :href="fila.href">
                                        <span class="truncate">
                                            {{ fila.texto }}
                                        </span>
                                        <span
                                            v-if="fila.al_costado"
                                            class="ml-auto shrink-0 text-xs text-sidebar-foreground/50"
                                        >
                                            {{ fila.al_costado }}
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
