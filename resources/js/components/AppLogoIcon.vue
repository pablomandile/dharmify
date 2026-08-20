<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<!--
    El loto con auriculares.

    Es una imagen y no un SVG en línea, así que las clases `fill-current` y
    `text-*` que le pasan quienes lo usan no tienen efecto acá: el logo trae sus
    propios colores y funciona igual en tema claro y oscuro porque el fondo es
    transparente.

    `object-contain` no es opcional: la marca es apaisada (968x556) y casi todos
    los usos le imponen una caja cuadrada (`size-9`), que sin esto la deformaría.
-->
<template>
    <img
        src="/img/logo.png?v=2"
        alt="Dharmify"
        class="object-contain"
        :class="className"
        v-bind="$attrs"
    />
</template>
