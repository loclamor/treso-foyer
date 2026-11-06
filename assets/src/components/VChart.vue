<script setup>
// Enveloppe vue-echarts 8 : registration tree-shakée (charts/echarts.js), autoresize,
// re-render complet à chaque changement d'option (notMerge) pour suivre le mode sombre.
// Infobulle : rendue dans <body> (jamais rognée par la carte) et positionnée pour rester
// entièrement dans la fenêtre (à droite du curseur, sinon à gauche ; bornée en haut/bas).
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import VChart from 'vue-echarts';
import '@/charts/echarts';

const props = defineProps({
    option: { type: Object, required: true },
    ariaLabel: { type: String, default: '' },
});
const emit = defineEmits(['click']);

const root = ref(null);
const chart = ref(null);

// L'infobulle vit dans <body> : la masquer quand la page défile (sinon elle reste figée).
function hideTip() {
    chart.value?.dispatchAction?.({ type: 'hideTip' });
}
onMounted(() => window.addEventListener('scroll', hideTip, { passive: true, capture: true }));
onBeforeUnmount(() => {
    window.removeEventListener('scroll', hideTip, { capture: true });
    hideTip();
});
const MARGIN = 8; // marge minimale au bord de la fenêtre
const OFFSET = 16; // écart au curseur

function clamp(v, min, max) {
    return Math.max(min, Math.min(v, max));
}

// point : position du curseur relative au graphe ; retour : position relative au graphe.
function viewportPosition(point, params, dom, rect, size) {
    const box = root.value?.getBoundingClientRect();
    if (!box) return null;
    const [w, h] = size.contentSize;
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    const cx = box.left + point[0];
    const cy = box.top + point[1];
    let x = cx + OFFSET;
    if (x + w > vw - MARGIN) x = cx - OFFSET - w; // pas la place à droite ⇒ à gauche
    x = clamp(x, MARGIN, Math.max(MARGIN, vw - w - MARGIN));
    const y = clamp(cy - h / 2, MARGIN, Math.max(MARGIN, vh - h - MARGIN));
    return [x - box.left, y - box.top];
}

const effectiveOption = computed(() => {
    const o = props.option;
    if (!o?.tooltip || o.tooltip.position) return o;
    return {
        ...o,
        tooltip: {
            appendToBody: true,
            position: viewportPosition,
            // Filet de sécurité si le contenu dépasse la hauteur de la fenêtre.
            extraCssText: 'max-height: calc(100vh - 16px); overflow: hidden;',
            ...o.tooltip,
        },
    };
});
</script>

<template>
    <div ref="root" class="vchart">
        <VChart
            ref="chart"
            class="vchart-inner"
            :option="effectiveOption"
            autoresize
            :update-options="{ notMerge: true }"
            role="img"
            :aria-label="ariaLabel"
            @click="emit('click', $event)"
        />
    </div>
</template>

<style scoped>
.vchart,
.vchart-inner {
    display: block;
    width: 100%;
    height: 100%;
}
</style>
