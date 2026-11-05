<script setup>
import { computed } from 'vue';
import { amountClass, formatMoney } from '@/utils/format';

const props = defineProps({
    amount: { type: Number, default: null }, // centimes, signé
});

const cls = computed(() => amountClass(props.amount));
</script>

<template>
    <span class="money" :class="cls">{{ formatMoney(amount) }}</span>
</template>
