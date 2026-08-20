<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { Menu, X } from "@lucide/vue"
import { cn } from "@/lib/utils"
import { Button } from '@/components/ui/button'
import { useSidebar } from "./utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()

const { isMobile, openMobile, toggleSidebar } = useSidebar()
</script>

<template>
  <Button
    data-sidebar="trigger"
    data-slot="sidebar-trigger"
    variant="ghost"
    size="icon"
    :class="cn('h-7 w-7', props.class)"
    @click="toggleSidebar"
  >
    <!--
      La hamburguesa de siempre. En el celular, con el menú abierto pasa a ser
      una cruz: ahí la barra tapa la pantalla entera y lo que la persona necesita
      es cerrarla, no "alternarla". En escritorio no cambia nunca, porque la
      barra no tapa nada y el ícono bailando a cada clic sólo distrae.
    -->
    <X v-if="isMobile && openMobile" />
    <Menu v-else />
    <span class="sr-only">
      {{ isMobile && openMobile ? 'Cerrar el menú' : 'Abrir el menú' }}
    </span>
  </Button>
</template>
