<script setup>
import { computed } from 'vue';
import { VEHICLE_TYPE_ICONS, VEHICLE_SOURCE_LABELS, VEHICLE_SOURCE_ICONS } from '@/utils/vehicles';

// Pastille véhicule (VehicleRef) avec indicateur de source d'affectation (manual/rule/auto).
const props = defineProps({
    vehicle: { type: Object, default: null }, // VehicleRef {id, name, type, archived}
    source: { type: String, default: null }, // vehicleSource : manual|rule|auto|null
    clickable: { type: Boolean, default: false },
});
const emit = defineEmits(['click']);

const icon = computed(() => (props.vehicle ? VEHICLE_TYPE_ICONS[props.vehicle.type] ?? 'pi pi-car' : 'pi pi-car'));
const sourceLabel = computed(() => (props.source ? VEHICLE_SOURCE_LABELS[props.source] ?? null : null));
const sourceIcon = computed(() => (props.source ? VEHICLE_SOURCE_ICONS[props.source] ?? 'pi pi-info-circle' : null));

function onClick() {
    if (props.clickable) emit('click');
}
</script>

<template>
    <span
        v-if="vehicle"
        class="veh-tag"
        :class="{ clickable }"
        :title="(vehicle.archived ? 'Archivé — ' : '') + vehicle.name"
        role="button"
        :tabindex="clickable ? 0 : undefined"
        @click="onClick"
        @keydown.enter="onClick"
    >
        <i :class="icon" aria-hidden="true" />
        {{ vehicle.name }}
        <i
            v-if="sourceLabel"
            :class="sourceIcon"
            class="veh-src"
            v-tooltip.top="sourceLabel"
            :aria-label="sourceLabel"
        />
    </span>
    <span v-else class="veh-tag veh-none">—</span>
</template>

<style scoped>
.veh-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    border: 1px solid var(--p-content-border-color);
    border-radius: 999px;
    padding: 0.1rem 0.6rem 0.1rem 0.45rem;
    font-size: 0.85rem;
    white-space: nowrap;
}
.veh-tag.clickable {
    cursor: pointer;
}
.veh-tag.clickable:hover {
    background: var(--p-highlight-background);
}
.veh-none {
    color: var(--p-text-muted-color);
    border-style: dashed;
}
.veh-src {
    font-size: 0.7rem;
    color: var(--p-text-muted-color);
}
</style>
