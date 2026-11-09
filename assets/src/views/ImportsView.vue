<script setup>
import { onMounted, ref } from 'vue';
import Card from 'primevue/card';
import FileUpload from 'primevue/fileupload';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import { useToast } from 'primevue/usetoast';
import { listImports, uploadImports } from '@/api/imports';
import { apiMessage } from '@/api/http';
import { formatDate, formatDateTime } from '@/utils/format';

const toast = useToast();

const uploading = ref(false);
const lastResult = ref(null); // {reports, postProcessing}

const batches = ref([]);
const total = ref(0);
const loading = ref(false);
const first = ref(0);
const rows = ref(20);

const STATUS = {
    imported: { label: 'Importé', severity: 'success' },
    already_imported: { label: 'Déjà importé', severity: 'warn' },
    ignored: { label: 'Ignoré', severity: 'secondary' },
    failed: { label: 'Échec', severity: 'danger' },
};
const BATCH_STATUS = {
    completed: { label: 'Importé', severity: 'success' },
    failed: { label: 'Échec', severity: 'danger' },
};

async function upload(event) {
    const files = Array.isArray(event.files) ? event.files : [event.files];
    if (!files.length) return;
    uploading.value = true;
    lastResult.value = null;
    try {
        lastResult.value = await uploadImports(files);
        toast.add({ severity: 'success', summary: 'Envoi terminé', detail: 'Le post-traitement (virements + catégorisation) a été lancé.', life: 5000 });
        load();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Envoi impossible', detail: apiMessage(e, 'Les fichiers n’ont pas pu être envoyés.'), life: 6000 });
    } finally {
        uploading.value = false;
    }
}

async function load() {
    loading.value = true;
    try {
        const res = await listImports({ page: Math.floor(first.value / rows.value) + 1, perPage: rows.value });
        batches.value = res.items;
        total.value = res.total;
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Historique illisible', detail: apiMessage(e, 'Les imports n’ont pas pu être chargés.'), life: 6000 });
    } finally {
        loading.value = false;
    }
}

function onPage(event) {
    first.value = event.first;
    rows.value = event.rows;
    load();
}

onMounted(load);
</script>

<template>
    <div>
        <h1>Imports</h1>

        <Card class="section">
            <template #title>Envoyer des relevés</template>
            <template #content>
                <FileUpload
                    mode="advanced"
                    custom-upload
                    :auto="true"
                    multiple
                    accept=".pdf,.csv"
                    :max-file-size="20 * 1024 * 1024"
                    choose-label="Choisir des fichiers"
                    upload-label="Envoyer"
                    cancel-label="Annuler"
                    :disabled="uploading"
                    @uploader="upload"
                >
                    <template #empty>
                        <p class="text-muted">Glissez-déposez des relevés <strong>PDF</strong> ou <strong>CSV</strong> ici (20 Mo max par fichier), ou cliquez sur « Choisir des fichiers ».</p>
                    </template>
                </FileUpload>
                <p v-if="uploading" class="text-muted upload-progress" role="status"><i class="pi pi-spin pi-spinner" aria-hidden="true" /> Envoi en cours…</p>
            </template>
        </Card>

        <Card v-if="lastResult" class="section">
            <template #title>Résultat du dernier envoi</template>
            <template #content>
                <ul class="reports">
                    <li v-for="(r, i) in lastResult.reports" :key="i">
                        <Tag :severity="STATUS[r.status]?.severity ?? 'secondary'" :value="STATUS[r.status]?.label ?? r.status" />
                        <strong>{{ r.filename }}</strong>
                        <span class="text-muted">
                            <template v-if="r.account">· {{ r.account.alias ?? r.account.numberMasked }}</template>
                            <template v-if="r.periodStart">· {{ formatDate(r.periodStart) }} → {{ formatDate(r.periodEnd) }}</template>
                            · +{{ r.added }} / ignorées {{ r.skipped }} / enrichies {{ r.enriched }} / doublons {{ r.probableDuplicates }}
                        </span>
                        <span v-if="r.error" class="error-text">{{ r.error }}</span>
                    </li>
                </ul>
                <p class="text-muted">
                    Post-traitement : {{ lastResult.postProcessing?.transfersMatched ?? 0 }} virement(s) apparié(s),
                    {{ lastResult.postProcessing?.categorized ?? 0 }} opération(s) catégorisée(s).
                </p>
            </template>
        </Card>

        <Card class="section">
            <template #title>Historique des imports</template>
            <template #content>
                <DataTable
                    :value="batches"
                    lazy
                    paginator
                    :rows="rows"
                    :first="first"
                    :total-records="total"
                    :loading="loading"
                    data-key="id"
                    @page="onPage"
                >
                    <Column header="Date" style="width: 10rem">
                        <template #body="{ data }">{{ formatDateTime(data.importedAt) }}</template>
                    </Column>
                    <Column field="filename" header="Fichier" />
                    <Column field="format" header="Format" style="width: 6rem">
                        <template #body="{ data }">{{ String(data.format).toUpperCase() }}</template>
                    </Column>
                    <Column header="Statut" style="width: 8rem">
                        <template #body="{ data }">
                            <Tag :severity="BATCH_STATUS[data.status]?.severity ?? 'secondary'" :value="BATCH_STATUS[data.status]?.label ?? data.status" />
                        </template>
                    </Column>
                    <Column header="Compte" style="width: 9rem">
                        <template #body="{ data }">{{ data.account?.alias ?? data.account?.numberMasked ?? '—' }}</template>
                    </Column>
                    <Column header="Période" style="width: 12rem">
                        <template #body="{ data }">{{ formatDate(data.periodStart) }} → {{ formatDate(data.periodEnd) }}</template>
                    </Column>
                    <Column header="Ajoutées" field="added" style="width: 6rem" />
                    <Column header="Ignorées" field="skipped" style="width: 6rem" />
                    <Column header="Enrichies" field="enriched" style="width: 6rem" />
                    <Column header="Doublons" field="probableDuplicates" style="width: 6rem" />
                    <Column header="Par" style="width: 8rem">
                        <template #body="{ data }">{{ data.importedBy?.displayName ?? '—' }}</template>
                    </Column>
                    <Column header="Erreur">
                        <template #body="{ data }">
                            <span v-if="data.error" class="error-text">{{ data.error }}</span>
                        </template>
                    </Column>
                    <template #empty><p class="empty-state">Aucun import pour le moment.</p></template>
                </DataTable>
            </template>
        </Card>
    </div>
</template>

<style scoped>
.section {
    margin-bottom: 1rem;
}
.reports {
    list-style: none;
    padding: 0;
    margin: 0 0 0.5rem;
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}
.reports li {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
}
.error-text {
    color: var(--p-red-500);
}
.upload-progress {
    margin-top: 0.5rem;
}
.empty-state {
    text-align: center;
    color: var(--p-text-muted-color);
    padding: 1rem;
}
</style>
