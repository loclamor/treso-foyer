<script setup>
import { computed } from 'vue';

const props = defineProps({
    source: { type: String, default: null }, // manual|rule|similarity|llm|transfer|null
});

const LABELS = {
    manual: { label: 'Manuelle', icon: 'pi pi-user', cls: 'src-manual' },
    rule: { label: 'Règle', icon: 'pi pi-bolt', cls: 'src-rule' },
    similarity: { label: 'Similarité', icon: 'pi pi-clone', cls: 'src-similarity' },
    llm: { label: 'IA', icon: 'pi pi-sparkles', cls: 'src-llm' },
    transfer: { label: 'Virement', icon: 'pi pi-arrow-right-arrow-left', cls: 'src-transfer' },
};

const info = computed(() => (props.source ? LABELS[props.source] : null));
</script>

<template>
    <span v-if="info" class="src-badge" :class="info.cls" v-tooltip.top="'Source de catégorisation : ' + info.label">
        <i :class="info.icon" aria-hidden="true" /> {{ info.label }}
    </span>
</template>
