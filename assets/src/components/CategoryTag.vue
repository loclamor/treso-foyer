<script setup>
import { computed } from 'vue';

const props = defineProps({
    category: { type: Object, default: null }, // CategoryRef | null
    clickable: { type: Boolean, default: false },
});
const emit = defineEmits(['click']);

const label = computed(() => props.category?.name ?? 'Non catégorisée');

function onClick() {
    if (props.clickable) emit('click');
}
</script>

<template>
    <span
        v-if="category"
        class="cat-tag"
        :class="{ clickable }"
        :title="category.fullName || category.name"
        role="button"
        :tabindex="clickable ? 0 : undefined"
        @click="onClick"
        @keydown.enter="onClick"
    >
        <i v-if="category.icon" :class="category.icon" aria-hidden="true" />
        <span class="cat-dot" :style="{ background: category.color || 'var(--p-text-muted-color)' }" aria-hidden="true" />
        {{ label }}
    </span>
    <span v-else class="cat-tag cat-none" :class="{ clickable }" role="button" :tabindex="clickable ? 0 : undefined" @click="onClick" @keydown.enter="onClick">
        {{ label }}
    </span>
</template>
