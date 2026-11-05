import http from './http';

// Historique paginé, plus récent d'abord.
export async function listImports(params) {
    const { data } = await http.get('/imports', { params });
    return data; // paginé : {items: [ImportBatch], total, page, perPage}
}

// Upload multipart : champ files[] (PDF/CSV, 20 Mo max par fichier).
// axios pose le Content-Type multipart avec boundary tout seul (ne pas le forcer).
export async function uploadImports(files) {
    const form = new FormData();
    for (const file of files) form.append('files[]', file, file.name);
    const { data } = await http.post('/imports', form);
    return data; // {reports: [ImportReport], postProcessing: {transfersMatched, categorized}}
}
