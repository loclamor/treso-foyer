import http from './http';

// Endpoints « Statistiques (étape 8) » du contrat (docs/api.md).
// Chaque appel reçoit le périmètre du store scope (accountId[] / memberId) + la plage from/to.

function buildParams(scopeParams, range, extra = {}) {
    const params = { ...scopeParams, ...range, ...extra };
    for (const key of Object.keys(params)) {
        if (params[key] === null || params[key] === undefined || params[key] === '') delete params[key];
    }
    return params;
}

// {from,to,income,expense,net,savingsRate,previous,average12,…}
export async function getOverview(scopeParams, range) {
    const { data } = await http.get('/stats/overview', { params: buildParams(scopeParams, range) });
    return data;
}

// {months, income, expense, net, byRoot, uncategorized}
export async function getMonthly(scopeParams, range) {
    const { data } = await http.get('/stats/monthly', { params: buildParams(scopeParams, range) });
    return data;
}

// {rows: [{category, total, count, share, compareTotal, comparableTotal}], meta} — parentId null = racines,
// compare = 'previous'|'year'. meta = plages réellement comparées, lues dans les en-têtes
// X-Comparable-From/To et X-Compare-From/To (présentes seulement quand compare est fourni) :
// le mois en cours incomplet en est exclu (comparable = mois complets ou « mois à date »).
export async function getCategoriesStats(scopeParams, range, { direction = 'expense', parentId = null, compare = null } = {}) {
    const { data, headers } = await http.get('/stats/categories', {
        params: buildParams(scopeParams, range, { direction, parentId, compare }),
    });
    return {
        rows: data,
        meta: {
            comparableFrom: headers['x-comparable-from'] ?? null,
            comparableTo: headers['x-comparable-to'] ?? null,
            compareFrom: headers['x-compare-from'] ?? null,
            compareTo: headers['x-compare-to'] ?? null,
        },
    };
}

// [{key, merchant, total, count, category}]
// keys : restreint aux clés données (≤ 50), ex. période précédente des commerçants du top courant.
export async function getMerchantsStats(scopeParams, range, { direction = 'expense', limit = 15, keys = null } = {}) {
    const { data } = await http.get('/stats/merchants', {
        params: buildParams(scopeParams, range, { direction, limit, keys }),
    });
    return data;
}

// {dates, total, accounts:[{account, values}]}
export async function getBalance(scopeParams, range, granularity = 'month') {
    const { data } = await http.get('/stats/balance', {
        params: buildParams(scopeParams, range, { granularity }),
    });
    return data;
}

// {nodes, links} — flux revenus → hub → postes (+ épargne / déficit)
export async function getSankey(scopeParams, range) {
    const { data } = await http.get('/stats/sankey', { params: buildParams(scopeParams, range) });
    return data;
}

// [{date, expense, count}]
export async function getCalendar(scopeParams, range) {
    const { data } = await http.get('/stats/calendar', { params: buildParams(scopeParams, range) });
    return data;
}

// {category, months, values, counts}
export async function getCategoryTrend(scopeParams, range, categoryId) {
    const { data } = await http.get('/stats/category-trend', {
        params: buildParams(scopeParams, range, { categoryId }),
    });
    return data;
}

// {vehicles: [{vehicle, total, count, monthlyAverage, byCategory, distance, costPerKm}], unassigned: {total, count}}
// (lot 4) — véhicules ayant au moins une dépense sur la période, triés par total desc.
export async function getVehiclesStats(scopeParams, range) {
    const { data } = await http.get('/stats/vehicles', { params: buildParams(scopeParams, range) });
    return data;
}
