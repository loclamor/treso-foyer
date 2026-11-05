<script setup>
import { computed } from 'vue';
import Select from 'primevue/select';
import { useScopeStore } from '@/stores/scope';
import { useMembersStore } from '@/stores/members';
import { useAccountsStore } from '@/stores/accounts';

const scope = useScopeStore();
const members = useMembersStore();
const accounts = useAccountsStore();

function same(a, b) {
    return a.type === b.type && a.id === b.id;
}

const options = computed(() => [
    { label: 'Foyer (tout le monde)', value: { type: 'household' } },
    ...members.list.map((m) => ({ label: m.displayName, value: { type: 'member', id: m.id } })),
    ...accounts.list.map((a) => ({ label: `${a.alias} — ${a.numberMasked}`, value: { type: 'account', id: a.id } })),
]);

const selected = computed({
    get: () => options.value.find((o) => same(o.value, scope.current)) ?? options.value[0],
    set: (option) => scope.set(option.value),
});
</script>

<template>
    <Select
        v-model="selected"
        :options="options"
        option-label="label"
        class="scope-selector"
        aria-label="Périmètre (foyer, personne ou compte)"
    />
</template>
