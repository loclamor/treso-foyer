import http from './http';

// Véhicules et relevés kilométriques (lots 3-4) — contrat docs/api.md.
// Montants en centimes, dates 'AAAA-MM-JJ'. La plaque n'est jamais journalisée côté front.

// ?archived=1 pour inclure les archivés ; défaut : non archivés uniquement.
export async function listVehicles({ archived = false } = {}) {
    const { data } = await http.get('/vehicles', { params: archived ? { archived: 1 } : {} });
    return data; // [Vehicle] triés par id
}

// Corps = champs de Vehicle (type défaut 'car', mainDriverId, prix en centimes…).
export async function createVehicle(payload) {
    const { data } = await http.post('/vehicles', payload);
    return data; // Vehicle (201) ; 409 name_exists/plate_exists, 422 violations
}

// PATCH partiel : champ absent = inchangé, chaîne vide = remise à null, mainDriverId null explicite = retrait.
export async function updateVehicle(id, payload) {
    const { data } = await http.patch(`/vehicles/${id}`, payload);
    return data; // Vehicle
}

export async function deleteVehicle(id) {
    await http.delete(`/vehicles/${id}`); // 204 ; 409 vehicle_in_use si opérations ou règles rattachés
}

// Statut du service de recherche par plaque ⇒ {enabled: bool}
export async function lookupStatus() {
    const { data } = await http.get('/vehicles/lookup/status');
    return data;
}

// {plate, vehicleId?} ⇒ {plate: normalisée, suggestion: {make, model, version, energy, fiscalPower, firstRegistrationAt, vin}}
// (la réponse brute « raw » n'est jamais exposée). vehicleId : enregistre lookupData + lookupAt sur le véhicule.
// 404 not_found, 429 quota, 502 unavailable/invalid_response, 503 lookup_disabled, 422 sur plate.
export async function lookupVehicle(plate, vehicleId = null) {
    const payload = { plate };
    if (vehicleId) payload.vehicleId = vehicleId;
    const { data } = await http.post('/vehicles/lookup', payload);
    return data;
}

// ---------- relevés kilométriques ----------

// [OdometerReading] triés date desc, puis id desc.
export async function listOdometerReadings(vehicleId) {
    const { data } = await http.get(`/vehicles/${vehicleId}/odometer`);
    return data;
}

// {date, km, note?} ⇒ 201 OdometerReading, parfois avec un champ « warning » (relevé incohérent, pas une erreur).
// 409 reading_date_exists, 422 sur date/km.
export async function createOdometerReading(vehicleId, payload) {
    const { data } = await http.post(`/vehicles/${vehicleId}/odometer`, payload);
    return data;
}

// PATCH partiel (204) : absent = inchangé, note: null explicite = efface la note.
export async function updateOdometerReading(id, payload) {
    await http.patch(`/odometer-readings/${id}`, payload);
}

export async function deleteOdometerReading(id) {
    await http.delete(`/odometer-readings/${id}`); // 204
}

// ---------- affectation des opérations ----------

// Affectation manuelle (vehicleSource 'manual') ; vehicleId null = retrait (ou réactivation d'une
// opération ignorée) puis resynchronisation auto ; vehicleId 'ignored' = marquage « ignorée ».
function vehiclePayload(vehicleId) {
    return vehicleId === 'ignored' ? { vehicleId: null, ignore: true } : { vehicleId };
}

export async function assignOperationVehicle(id, vehicleId) {
    const { data } = await http.post(`/operations/${id}/vehicle`, vehiclePayload(vehicleId));
    return data; // Operation
}

// {ids, vehicleId} ⇒ {updatedCount} (même sémantique que l'affectation unitaire).
export async function bulkAssignVehicle(ids, vehicleId) {
    const { data } = await http.post('/operations/bulk-vehicle', { ids, ...vehiclePayload(vehicleId) });
    return data;
}
