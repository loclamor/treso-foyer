// Garde « dernière requête gagnante » : quand un chargement est relancé (filtre, pagination,
// période) avant la fin du précédent, la réponse la plus ancienne ne doit pas écraser la plus récente.
//
//   const ordersGuard = latestGuard();
//   async function load() {
//       const isLatest = ordersGuard();
//       loading.value = true;
//       try {
//           const res = await fetchSomething();
//           if (!isLatest()) return;
//           data.value = res;
//       } catch (e) {
//           if (isLatest()) error.value = apiMessage(e);
//       } finally {
//           if (isLatest()) loading.value = false;
//       }
//   }
export function latestGuard() {
    let current = 0;
    return () => {
        const id = ++current;
        return () => id === current;
    };
}
