<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const name = usePage().props.name;
</script>

<template>
    <!--
        Sin recuadro de color detrás. Ese fondo existía para darle contraste a un
        logo que era una silueta monocroma; el loto trae sus propios azules, y
        sobre el celeste de `bg-sidebar-primary` simplemente desaparecía.

        Y la caja va apaisada, no cuadrada: la marca es 1,74:1.
    -->
    <div class="flex h-8 items-center justify-center">
        <AppLogoIcon class="h-8 w-auto" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold">{{
            name
        }}</span>
    </div>
</template>
