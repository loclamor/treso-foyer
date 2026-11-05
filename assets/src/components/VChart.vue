<script setup>
// Enveloppe vue-echarts 8 : registration tree-shakée (charts/echarts.js), autoresize,
// re-render complet à chaque changement d'option (notMerge) pour suivre le mode sombre.
import VChart from 'vue-echarts';
import '@/charts/echarts';

defineProps({
    option: { type: Object, required: true },
    ariaLabel: { type: String, default: '' },
});
const emit = defineEmits(['click']);
</script>

<template>
    <VChart
        class="vchart"
        :option="option"
        autoresize
        :update-options="{ notMerge: true }"
        role="img"
        :aria-label="ariaLabel"
        @click="emit('click', $event)"
    />
</template>

<style scoped>
.vchart {
    display: block;
    width: 100%;
    height: 100%;
}
</style>
