<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { FolderPlus, RefreshCw, Trash2, Wifi } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';

type Fuente = {
    id: number;
    nombre: string;
    tipo: 'rclone' | 'local';
    ruta: string;
    visibilidad: 'privada' | 'publica';
    activa: boolean;
    series_count: number;
    pistas: number;
    revisada_en: string | null;
};

defineProps<{ fuentes: Fuente[] }>();

const page = usePage();
const estado = computed(() => page.props.estado as string | null);
const error = computed(() => page.props.error as string | null);

const abierto = ref(false);
const refrescando = ref(false);

const form = useForm({
    nombre: '',
    tipo: 'rclone' as 'rclone' | 'local',
    ruta: '',
    visibilidad: 'privada' as 'privada' | 'publica',
    activa: true,
});

const agregar = () => {
    form.post('/settings/fuentes', {
        preserveScroll: true,
        // El diálogo se cierra en el éxito. Sin esto queda abierto sobre la
        // pantalla ya actualizada, mostrando el formulario que se acaba de
        // enviar: la navegación del cliente no desmonta los overlays.
        onSuccess: () => {
            form.reset();
            abierto.value = false;
        },
    });
};

const refrescar = () => {
    refrescando.value = true;
    router.post(
        '/biblioteca/refrescar',
        {},
        {
            preserveScroll: true,
            onFinish: () => (refrescando.value = false),
        },
    );
};

const probar = (fuente: Fuente) =>
    router.post(
        `/settings/fuentes/${fuente.id}/probar`,
        {},
        { preserveScroll: true },
    );

const eliminar = (fuente: Fuente) => {
    if (
        confirm(
            `¿Sacar «${fuente.nombre}» del catálogo? Los archivos en OneDrive no se tocan.`,
        )
    ) {
        router.delete(`/settings/fuentes/${fuente.id}`, {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <Head title="Fuentes" />

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                variant="small"
                title="Fuentes"
                description="Las carpetas de OneDrive de donde sale la biblioteca"
            />

            <Button :disabled="refrescando" @click="refrescar">
                <Spinner v-if="refrescando" />
                <RefreshCw v-else class="size-4" />
                Refrescar biblioteca
            </Button>
        </div>

        <div
            v-if="estado"
            class="rounded-md border border-primary/40 bg-primary/10 px-4 py-3 text-sm"
        >
            {{ estado }}
        </div>

        <div
            v-if="error"
            class="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive"
        >
            {{ error }}
        </div>

        <div
            v-if="!fuentes.length"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <p class="text-sm text-muted-foreground">
                Todavía no hay ninguna fuente. Agregá la carpeta de OneDrive
                donde están tus enseñanzas.
            </p>
        </div>

        <div v-else class="space-y-3">
            <div
                v-for="fuente in fuentes"
                :key="fuente.id"
                class="rounded-lg border p-4"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ fuente.nombre }}</span>
                            <Badge variant="secondary">
                                {{
                                    fuente.tipo === 'local'
                                        ? 'Carpeta local'
                                        : 'OneDrive (rclone)'
                                }}
                            </Badge>
                            <Badge v-if="fuente.visibilidad === 'publica'"
                                >Pública</Badge
                            >
                            <Badge v-if="!fuente.activa" variant="outline"
                                >Pausada</Badge
                            >
                        </div>

                        <p
                            class="mt-1 truncate font-mono text-xs text-muted-foreground"
                        >
                            {{ fuente.ruta }}
                        </p>

                        <p class="mt-2 text-sm text-muted-foreground">
                            {{ fuente.series_count }} series ·
                            {{ fuente.pistas }} audios ·
                            <template v-if="fuente.revisada_en">
                                revisada {{ fuente.revisada_en }}
                            </template>
                            <template v-else>nunca revisada</template>
                        </p>
                    </div>

                    <div class="flex shrink-0 gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="probar(fuente)"
                        >
                            <Wifi class="size-4" />
                            Probar
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="eliminar(fuente)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <Dialog v-model:open="abierto">
            <Button variant="outline" @click="abierto = true">
                <FolderPlus class="size-4" />
                Agregar fuente
            </Button>

            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Agregar fuente</DialogTitle>
                    <DialogDescription>
                        Una carpeta de OneDrive. Cada subcarpeta de adentro se
                        convierte en una serie.
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="agregar">
                    <div class="grid gap-2">
                        <Label for="nombre">Nombre</Label>
                        <Input
                            id="nombre"
                            v-model="form.nombre"
                            placeholder="Enseñanzas privadas"
                            required
                        />
                        <InputError :message="form.errors.nombre" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="tipo">Tipo</Label>
                        <Select v-model="form.tipo">
                            <SelectTrigger id="tipo">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="rclone">
                                    OneDrive por rclone (el servidor)
                                </SelectItem>
                                <SelectItem value="local">
                                    Carpeta del disco (desarrollo)
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="grid gap-2">
                        <Label for="ruta">Ruta</Label>
                        <Input
                            id="ruta"
                            v-model="form.ruta"
                            class="font-mono text-xs"
                            :placeholder="
                                form.tipo === 'local'
                                    ? 'C:/Users/vos/OneDrive/Budismo Kadampa/Dharma/Enseñanzas'
                                    : 'onedrive:Budismo Kadampa/Dharma/Enseñanzas'
                            "
                            required
                        />
                        <InputError :message="form.errors.ruta" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="visibilidad">Quién puede escucharla</Label>
                        <Select v-model="form.visibilidad">
                            <SelectTrigger id="visibilidad">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="privada">Sólo yo</SelectItem>
                                <SelectItem value="publica">
                                    Todos los invitados
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="abierto = false"
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            <Spinner v-if="form.processing" />
                            Agregar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
