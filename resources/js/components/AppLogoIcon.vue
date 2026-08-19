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
    La rueda del Dharma, de ocho rayos. Es el mismo dibujo que los íconos de la
    PWA (public/icons), pero en trazo y con currentColor, para que herede el
    color del tema en vez de traer el suyo.

    El `style="fill: none"` va en línea y no como atributo `fill`: quienes usan
    este componente le pasan la clase `fill-current`, y una clase de CSS le gana
    a un atributo de presentación. Con el atributo, el relleno ganaba y la rueda
    se veía como un círculo blanco macizo.
-->
<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        stroke="currentColor"
        stroke-width="1.5"
        stroke-linecap="round"
        :class="className"
        v-bind="$attrs"
        style="fill: none"
    >
        <circle cx="12" cy="12" r="9.25" />
        <circle cx="12" cy="12" r="2.75" />
        <path d="M12 2.75V9.25M12 14.75V21.25M2.75 12H9.25M14.75 12H21.25" />
        <path
            d="M5.46 5.46 9.06 9.06M14.94 14.94l3.6 3.6M18.54 5.46l-3.6 3.6M9.06 14.94l-3.6 3.6"
        />
    </svg>
</template>
