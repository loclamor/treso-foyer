# API JSON — contrat (v1)

Référence unique entre le back (Symfony, `src/Controller/Api/`) et le front (Vue, `assets/src/api/`).

## Conventions
- Préfixe `/api`, JSON uniquement (`Content-Type: application/json` exigé sur `POST`/`PATCH`/`PUT`/`DELETE` avec corps, sauf upload `multipart/form-data` de `/api/imports`). Une requête d'écriture sans ce type ⇒ `415`.
- **Montants en centimes** (entiers signés, négatif = débit). **Dates** `YYYY-MM-DD`, horodatages ISO 8601.
- Authentification par **session/cookie** (même origine, `SameSite=Lax`). Non connecté ⇒ `401 {"error":"unauthenticated"}`.
- Erreurs : `{"error": "<code>", "message": "<texte FR>", "violations"?: [{"field","message"}]}` avec `400` (requête invalide), `404`, `409` (conflit), `422` (validation).
- Pagination : `?page=1&perPage=50` (max 200) ⇒ `{"items": [...], "total": n, "page": p, "perPage": pp}`.
- **Périmètre** (filtre commun aux listes et agrégats) : `accountId` (répétable `accountId[]=1&accountId[]=2`) ou `memberId` (comptes dont la personne est titulaire). Absent ⇒ tout le foyer.

## Objets
```jsonc
// Member
{"id": 1, "username": "alice", "displayName": "Alice"}
// AccountRef
{"id": 3, "alias": "perso-a", "label": "Compte ****R999", "numberMasked": "****R999"}
// Account
{"id": 3, "alias": "perso-a", "label": "…", "numberMasked": "****R999", "type": "personal|joint", "bank": "La Banque Postale",
 "holders": [Member], "operationsCount": 4546, "firstDate": "2018-01-15", "lastDate": "2026-11-03",
 "balance": {"amount": 136514, "date": "2026-11-04"} | null}  // dernier solde connu (relevé ou export)
// CategoryRef
{"id": 12, "name": "Courses", "fullName": "Alimentation › Courses", "kind": "expense|income|transfer", "color": "#4caf50", "icon": "pi pi-shopping-cart", "chartSlot": 1 | null}  // chartSlot : celui de la racine
// Category (arbre)
{"id": 1, "name": "Alimentation", "fullName": "Alimentation", "kind": "expense", "color": "#…", "icon": "…", "position": 0, "archived": false,
 "vehicleRelated": false, "fuel": false, "financing": false, "parentId": null, "operationsCount": 1234, "children": [Category]}  // fuel : poste carburant (ADR 0015) ; financing : financement d'un véhicule, hors coût d'usage (ADR 0011)
// VehicleRef
{"id": 3, "name": "Clio", "type": "car|motorcycle|bike|other", "archived": false}
// Vehicle
{"id": 3, "name": "Clio", "type": "car", "archived": false, "plate": "AB-123-CD" | null, "make": "Renault" | null,
 "model": "Clio 5" | null, "energy": "petrol|diesel|electric|hybrid|plugin_hybrid|lpg|other" | null, "fiscalPower": 5 | null,
 "firstRegistrationAt": "2020-05-10" | null, "vin": "…" | null, "acquiredAt": "2020-06-01" | null, "disposedAt": null,
 "purchasePrice": 1500000 | null, "mainDriver": Member | null, "lookupAt": "…" | null,
 "operationsCount": 42, "lastOdometer": {"date": "2026-03-15", "km": 48200} | null}  // lookupData (réponse brute) jamais exposé
// OdometerReading (relevé kilométrique d'un véhicule)
{"id": 12, "date": "2026-03-15", "km": 48200, "note": "…" | null}
// Operation
{"id": 42, "date": "2026-08-26", "amount": -2756, "rawLabel": "…", "normalizedLabel": "…", "merchant": "…" | null,
 "account": AccountRef, "category": CategoryRef | null,
 "categorizationSource": "manual|rule|similarity|llm|transfer" | null, "appliedRuleId": 7 | null,
 "duplicateStatus": "none|probable|confirmed_distinct", "duplicateOfId": 41 | null,
 "internalTransferId": 5 | null, "recurringSeriesId": 3 | null, "vehicle": VehicleRef | null, "vehicleSource": "manual|rule|auto|ignored" | null,
 "note": "…" | null, "labelSource": "pdf|csv" | null, "enrichedAt": "…" | null,
 // Saisie manuelle / rapprochement (ADR 0014) : date effective = spentAt ?? date
 "spentAt": "2026-08-25" | null, "effectiveDate": "2026-08-25", "awaitingStatement": false, "reconciledAt": "…" | null,
 // Plein de carburant (ADR 0015) : null, ou {volumeMl, fullTank, odometerKm, pricePerLiter} (prix au litre calculé, euros)
 "fuel": null | {"volumeMl": 45000, "fullTank": true, "odometerKm": 48200, "pricePerLiter": 1.511}}
// Rule
{"id": 7, "category": CategoryRef, "matchField": "merchant|label", "matchType": "contains|starts_with|exact|regex", "pattern": "…",
 "direction": "any|debit|credit", "amountMin": 4000 | null, "amountMax": 8000 | null, "account": AccountRef | null,
 "vehicle": VehicleRef | null, "priority": 100, "enabled": true, "matchCount": 91}
// MerchantGroup (regroupement par commerçant)
{"key": "INTERMARCHE", "merchant": "INTERMARCHE" | null, "direction": "debit|credit", "count": 40, "total": -292000,
 "minAmount": -14900, "maxAmount": -800, "firstDate": "2018-03-19", "lastDate": "2026-11-03",
 "categories": [{"category": CategoryRef | null, "count": 38}],     // répartition actuelle
 "suggestion": {"category": CategoryRef, "source": "similarity"} | null,
 "sampleLabel": "…"}
// ImportBatch
{"id": 9, "filename": "2026-10_releve.pdf", "format": "pdf|csv", "status": "completed|failed", "account": AccountRef | null,
 "periodStart": "…", "periodEnd": "…", "added": 35, "skipped": 0, "enriched": 0, "probableDuplicates": 0,
 "error": null, "importedAt": "…", "importedBy": Member | null}
// ImportReport (résultat d'un fichier envoyé)
{"filename": "…", "status": "imported|already_imported|failed|ignored", "account": AccountRef | null,
 "periodStart": "…", "periodEnd": "…", "added": 0, "skipped": 0, "enriched": 0, "probableDuplicates": 0, "error": null}
// InternalTransfer
{"id": 5, "status": "auto|confirmed", "operations": [Operation, Operation]}
// Recurrence (série récurrente détectée, lot récurrences)
{"id": 3, "account": AccountRef, "merchant": "EDF", "direction": "expense|income",
 "category": CategoryRef | null, "frequency": "weekly|monthly|bimonthly|quarterly|semiannual|yearly",
 "frequencyLabel": "Mensuelle", "expectedAmount": 90000, "amountTolerance": 13500,
 "lastOccurrence": "2026-11-03", "nextExpected": "2026-12-03" | null,
 "activeMonths": [1, 2, 3] | null, "status": "detected|confirmed|ignored|ended", "statusLabel": "Détectée",
 "occurrences": 6, "overdue": false}  // overdue = status detected/confirmed et nextExpected + tolérance < aujourd'hui
// Budget
{"id": 2, "category": CategoryRef, "monthlyAmount": 40000, "scope": "household|member|account",
 "member": Member | null, "account": AccountRef | null, "startMonth": "2026-01-01", "endMonth": null}
```

## Authentification
| Méthode | Route | Corps / réponse |
|---|---|---|
| POST | `/api/login` | `{"username","password"}` ⇒ `200 Member` ; échec `401 {"error":"invalid_credentials"}` |
| POST | `/api/logout` | ⇒ `204` |
| GET | `/api/me` | ⇒ `200 Member` ou `401` |
| GET | `/api/health` | public ⇒ `{"status":"ok","database":true}` |

## Tableau de bord minimal
`GET /api/summary` (+ périmètre) ⇒
```json
{"operationsCount": 4546, "categorizedCount": 2089, "uncategorizedCount": 2457, "probableDuplicatesCount": 0,
 "pendingTransfersCount": 0, "vehicleUnassignedCount": 12, "awaitingStatementCount": 2,
 "staleManualEntries": [{"id": 42, "spentAt": "2026-10-01", "amount": -100000, "label": "…", "account": AccountRef, "ageDays": 36}],
 "lastImportAt": "…", "firstDate": "2018-01-15", "lastDate": "2026-11-03",
 "accounts": [Account]}
```
`staleManualEntries` = saisies manuelles en attente depuis plus de 15 jours (`spentAt < aujourd’hui − 15 j`), triées par `spentAt` croissant (ADR 0014) ; `firstDate`/`lastDate` portent sur la date effective.
```

## Référentiels
| Méthode | Route | Notes |
|---|---|---|
| GET | `/api/members` | `[Member]` |
| GET | `/api/accounts` | `[Account]` |
| PATCH | `/api/accounts/{id}` | `{"alias"?, "label"?, "type"?, "holderIds"?: [int]}` ⇒ `Account` (alias unique ⇒ `409`) |
| GET | `/api/categories` | arbre `[Category]` (racines triées par `position`) |
| POST | `/api/categories` | `{"name","parentId"?,"kind","color"?,"icon"?,"vehicleRelated"?,"fuel"?,"financing"?}` ⇒ `201 Category` (doublon ⇒ `409`) |
| PATCH | `/api/categories/{id}` | `{"name"?,"parentId"?,"kind"?,"color"?,"icon"?,"position"?,"archived"?,"vehicleRelated"?,"fuel"?,"financing"?,"chartSlot"?}` (`fuel`, `financing` : sans resynchro véhicule) |
| DELETE | `/api/categories/{id}` | `204` ; refus `409` si opérations, règles ou budgets rattachés (proposer l'archivage) |

## Véhicules (lot 3)
| Méthode | Route | Notes |
|---|---|---|
| GET | `/api/vehicles` | `[Vehicle]` triés par id ; défaut : non archivés uniquement, `?archived=1` les inclut tous |
| POST | `/api/vehicles` | corps = champs de `Vehicle` (`type` défaut `car`) ⇒ `201 Vehicle`. `plate` normalisée (SIV `AA-123-AA`, FNI sans séparateurs) : format invalide ⇒ `422` sur `plate`, doublon (foyer) ⇒ `409 plate_exists`, nom en doublon ⇒ `409 name_exists`, `mainDriverId` hors foyer ⇒ `422` |
| PATCH | `/api/vehicles/{id}` | idem partiel ; champ absent = inchangé, chaîne vide = remise à null (dates et textes), `energy`, `fiscalPower`, `purchasePrice`, `mainDriverId` à `null` explicite (clé présente) = remise à null ; `plate: ""` retire la plaque |
| DELETE | `/api/vehicles/{id}` | `204` ; refus `409 vehicle_in_use` si des opérations ou des règles y sont rattachées (message proposant l'archivage) |
| POST | `/api/vehicles/lookup` | `{"plate", "vehicleId"?}` ⇒ `200 {"plate": normalisée, "suggestion": VehicleLookupResult sans « raw »}`. Service désactivé ⇒ `503 lookup_disabled` ; `VehicleLookupException` ⇒ `404 not_found`, `429 quota`, `502 unavailable`/`invalid_response`. `vehicleId` fourni : enregistre `lookupData` (raw) + `lookupAt` sur ce véhicule sans toucher à ses autres champs |
| GET | `/api/vehicles/lookup/status` | ⇒ `{"enabled": bool}` |

Après création/modification/suppression d'un véhicule, d'une règle (champ `vehicleId`) ou d'une catégorie (champ `vehicleRelated`), l'affectation automatique des opérations est resynchronisée (`VehicleAssigner::sync()`) : les affectations `manual` ne sont jamais modifiées, une règle appliquée avec véhicule prime (source `rule`), sinon catégorie `vehicleRelated` + exactement un véhicule actif à la date de l'opération (source `auto`).

## Relevés kilométriques (lot 4)
| Méthode | Route | Notes |
|---|---|---|
| GET | `/api/vehicles/{id}/odometer` | `[OdometerReading]` triés par date desc (puis id desc) |
| POST | `/api/vehicles/{id}/odometer` | `{"date", "km", "note"?}` ⇒ `201 OdometerReading`. Doublon de date pour ce véhicule ⇒ `409 reading_date_exists` ; `km < 0` ⇒ `422` sur `km` ; date invalide ⇒ `422` sur `date`. Relevé incohérent avec les relevés voisins (km strictement inférieur au relevé précédent ou strictement supérieur au suivant) ⇒ toujours `201`, avec un champ `"warning"` (avertissement, pas une erreur) |
| PATCH | `/api/odometer-readings/{id}` | `{"date"?, "km"?, "note"?}` ⇒ `204`. Champ absent = inchangé ; `note: null` explicite (clé présente) efface la note ; doublon de date ⇒ `409 reading_date_exists` ; `km < 0` ⇒ `422` |
| DELETE | `/api/odometer-readings/{id}` | `204` |

Véhicule ou relevé hors foyer (vérifié via le véhicule du relevé) ⇒ `404`. Un relevé incohérent (km décroissant avec la date) reste stocké mais est **ignoré** par le calcul de distance.

## Pleins de carburant (ADR 0015)

Un plein est porté par l'opération elle-même (au plus un plein par opération, uniquement sur un **débit**) : `fuelVolumeMl` (mL, > 0), `fuelFullTank` (booléen, défaut `true`), `odometerKm` (≥ 0, optionnel). Le prix au litre n'est pas stocké : `|amount| × 10 / volumeMl` (euros, 3 décimales). Le kilométrage d'un plein est un **point de compteur** : `OdometerReadingRepository` fusionne relevés et pleins (date effective `COALESCE(spent_at, date)` ; à date égale le relevé saisi prime).

| Méthode | Route | Notes |
|---|---|---|
| PUT | `/api/operations/{id}/fuel` | `{"volumeMl": int > 0, "fullTank"?: bool (défaut true), "odometerKm"?: int ≥ 0}` ⇒ `200 {"operation": Operation, "warning"?}` (remplace le plein existant). Crédit ⇒ `400 not_a_debit`. `odometerKm` fourni + opération avec véhicule ⇒ `warning` si le km est incohérent avec les points de compteur voisins (point de l'opération exclu ; avertissement, pas une erreur) |
| DELETE | `/api/operations/{id}/fuel` | ⇒ `200 {"operation": Operation}` avec `fuel: null` |
| GET | `/api/vehicles/{id}/fuel?from=AAAA-MM-JJ&to=AAAA-MM-JJ` | Bornes optionnelles (absentes = tout l'historique), `from > to` ou date invalide ⇒ `400 invalid_query`. ⇒ `{"fills": [{"operationId", "date" (effective), "amount", "volumeMl", "fullTank", "odometerKm", "pricePerLiter", "consumption", "segmentKm", "label" (rawLabel), "awaitingStatement"}], "summary": {"count", "volumeMl", "amount", "avgPricePerLiter", "consumption", "distanceKm"}}` — `fills` triés date desc, filtrés sur la période ; `consumption` (L/100 km, 2 déc.) = segment se terminant sur ce plein (méthode du plein complet : volume des pleins depuis le plein complet précédent ÷ distance), null sans segment ; `summary` sur la période (segments s'y terminant, le plein complet précédent pouvant être hors période) : `avgPricePerLiter` = Σ\|amount\| × 10 / ΣvolumeMl (euros, 3 déc., null si 0), `consumption` = Σ volumes segments / Σ km segments × 100 (2 déc., null si aucun segment), `distanceKm` = Σ km segments arrondi à l'entier (null si aucun) |

`POST /api/operations` accepte en plus `fuelVolumeMl` (?int > 0), `fuelFullTank` (?bool, défaut `true` si volume fourni) et `odometerKm` (?int ≥ 0, **refusé 422 sans `fuelVolumeMl`**) ; volume avec montant positif ⇒ `422` sur `fuelVolumeMl`. `POST /api/operations/{id}/duplicate` (`merge`) reporte le plein de l'opération supprimée sur l'opération conservée si elle n'en a pas (et qu'elle est un débit) ; le rapprochement (`ManualEntryReconciler::merge`) copie de même le plein de la saisie sur la ligne de relevé.

## AdBlue (ADR 0016)

`AdblueFill` = `{"id", "date", "volumeMl", "odometerKm", "source": "station"|"can", "operation": {"id", "date" (effective), "amount", "label", "awaitingStatement"} | null, "canPrice", "canVolumeMl", "cost" (centimes, positif, null si inconnu), "costEstimated" (true au bidon), "pricePerLiter" (€/L, 3 déc., null sans coût), "note"}`. Coût : station = `-operation.amount` ; bidon = `round(canPrice × volumeMl / canVolumeMl)` (estimé, jamais ajouté aux dépenses du véhicule).

| Méthode | Route | Notes |
|---|---|---|
| GET | `/api/vehicles/{id}/adblue?from=AAAA-MM-JJ&to=AAAA-MM-JJ` | Bornes optionnelles (absentes = tout l'historique), `from > to` ⇒ `400 invalid_query`. ⇒ `{"fills": [AdblueFill] (date desc, période), "summary": {"count", "volumeMl", "cost" (station), "estimatedCost" (bidons), "avgPricePerLiter" (remplissages au coût connu, null sinon), "consumption" (L/1000 km, 2 déc. : Σ volumes datés dans ]coveredFrom, coveredTo] ÷ km de cette fenêtre, null sans distance ou sans remplissage), "distanceKm"}}` |
| POST | `/api/vehicles/{id}/adblue` | `{"date", "volumeMl": int > 0, "source", "odometerKm"?: int ≥ 0, "operationId"? (station), "canPrice"? + "canVolumeMl"? (bidon, ensemble), "note"?}` ⇒ `201 AdblueFill` (+ `"warning"` si km incohérent). `operationId` au bidon, prix du bidon en station, prix sans contenance, opération hors foyer ou crédit ⇒ `422` ; opération déjà liée ⇒ `409 operation_already_linked`. L'opération liée est affectée au véhicule (manuelle) sauf affectation manuelle existante |
| PATCH | `/api/adblue-fills/{id}` | Mêmes champs, absents = inchangés ; `odometerKm`, `operationId`, `canPrice`/`canVolumeMl`, `note` : clé présente (même `null`) = appliquée ; changer `source` sans préciser les champs de l'autre origine les efface ⇒ `200 AdblueFill` (+ `"warning"`) |
| DELETE | `/api/adblue-fills/{id}` | ⇒ `204` (l'opération liée et son affectation sont conservées) |
| GET | `/api/vehicles/{id}/adblue/candidates?date=AAAA-MM-JJ&fillId=N?` | Débits du foyer à ± 10 jours (date effective), sans plein de carburant, non liés à un autre remplissage (`fillId` : garde celui de ce remplissage), triés par proximité, 30 max ⇒ `[{"id", "date", "amount", "label", "merchant", "category": CategoryRef \| null, "awaitingStatement"}]` ; `date` absente ⇒ `400 invalid_query` |

## Opérations
| Méthode | Route | Notes |
|---|---|---|
| GET | `/api/operations` | Filtres : périmètre, `from`, `to` (**date effective** `COALESCE(spentAt, date)`, ADR 0014), `categoryId` (inclut les sous-catégories), `uncategorized=1`, `q` (texte, insensible casse/accents, sur libellés et commerçant), `merchant` (égalité exacte sur la clé brute `coalesce(merchant, normalizedLabel)`, cf. `/api/merchants`, **ou** sur la clé de groupe de variantes `merchantGroup` — toutes les variantes, cf. top commerçants et [ADR 0013](adr/0013-regroupement-commercants.md)), `direction=debit|credit`, `minAmount`/`maxAmount` (valeur absolue, centimes), `duplicateStatus`, `source` (categorizationSource), `vehicleId` (véhicule du foyer, inconnu ⇒ `400`), `vehicleUnassigned=1` (hors « ignorées »), `vehicleIgnored=1` (catégories `vehicleRelated` sans véhicule), `recurringSeriesId` (série du foyer, inconnue ⇒ `400`), `awaitingStatement=1` (saisies manuelles en attente de relevé). Tri `sort=date|amount|merchant` `order=asc|desc` (défaut date desc — sur la date effective — puis id desc). Réponse paginée + `"sums": {"debit": -n, "credit": n}` sur l'ensemble filtré. |
| GET | `/api/operations/{id}` | `Operation` |
| POST | `/api/operations` | **Saisie manuelle d'une dépense** (ADR 0014). Corps : `{"accountId": int, "spentAt": "AAAA-MM-JJ", "amount": int (centimes signés, ≠ 0), "label": string (1..255), "categoryId"?: int|null, "vehicleId"?: int|null, "note"?: string|null, "fuelVolumeMl"?: int > 0, "fuelFullTank"?: bool, "odometerKm"?: int ≥ 0}` (plein ADR 0015 : `odometerKm` refusé 422 sans `fuelVolumeMl`, volume refusé 422 sur un crédit). Compte du foyer **non clos**, catégorie/véhicule du foyer (422 sinon). `label` normalisé (`normalizedLabel` + `merchant`) ; catégorie fournie ⇒ source `manual`, véhicule fourni ⇒ source `manual` ; puis `VehicleAssigner::sync()` et, si aucune catégorie fournie, catégorisation automatique de cette seule opération. Puis tentative immédiate de rapprochement (relevé déjà importé). ⇒ `201 {"operation": Operation, "reconciled": bool}` — si `reconciled` est true, `operation` est la **ligne du relevé** qui a absorbé la saisie |
| PATCH | `/api/operations/{id}` | `{"note": "…"|null}` ⇒ `Operation` (null = efface la note). En plus, **sur une saisie en attente uniquement** : `accountId`, `spentAt` (modifie aussi `date`), `amount`, `label` (champ présent = modifié ; ces champs sur une opération importée ⇒ `400 not_a_manual_entry`) |
| DELETE | `/api/operations/{id}` | Uniquement une saisie en attente (sinon `400 not_a_manual_entry`) ⇒ `204` |
| GET | `/api/operations/{id}/reconciliation-candidates` | Saisie en attente (sinon `400 not_a_manual_entry`) ⇒ `{"items": [Operation]}` : lignes de relevé du foyer, même signe, non rapprochées, fenêtre [spentAt − 5 j, spentAt + 30 j], tri montant exact → même compte → |écart montant| → |écart date| (20 max) |
| POST | `/api/operations/{id}/reconcile` | `{"operationId": int}` : appariement manuel d'une saisie en attente avec une ligne de relevé ⇒ `200 {"operation": <ligne conservée>}` (400 `not_a_manual_entry` si la saisie n'est pas en attente ou si la cible est une saisie, 400 `already_reconciled` si la ligne est déjà rapprochée). Les valeurs du relevé font foi (date, montant, compte, libellé) |
| POST | `/api/operations/{id}/vehicle` | `{"vehicleId": int|null}` : affectation manuelle (`vehicleSource: "manual"`) ; `null` retire l'affectation manuelle puis resynchronise (`sync()` peut remettre une affectation `auto`/`rule`). ⇒ `Operation` ; `{"vehicleId": null, "ignore": true}` marque l'opération « ignorée » (`vehicle: null`, `vehicleSource: "ignored"`) : exclue de `vehicleUnassigned`/`vehicleUnassignedCount`/`unassigned`, jamais resynchronisée ; `vehicleId: null` sans `ignore` la réactive. `ignore` + `vehicleId` ⇒ 422 |
| POST | `/api/operations/bulk-vehicle` | `{"ids": [int], "vehicleId": int|null, "ignore": bool}` (même sémantique) ⇒ `{"updatedCount": n}` |
| POST | `/api/operations/{id}/categorize` | `{"categoryId": int|null, "scope": "operation|merchant"}`. `operation` : catégorie manuelle sur cette seule opération (aucune règle). `merchant` : catégorie manuelle + règle apprise (commerçant exact, sens, priorité 100) appliquée aux opérations non manuelles du même commerçant. `categoryId=null` : retire la catégorie manuelle puis recatégorise automatiquement. ⇒ `{"operation": Operation, "updatedCount": n, "rule": Rule|null}` |
| POST | `/api/operations/bulk-categorize` | `{"ids": [int], "categoryId": int}` (manuel, sans règle) ⇒ `{"updatedCount": n}` |
| POST | `/api/operations/{id}/duplicate` | `{"action": "merge|distinct"}`. `merge` : conserve l'opération de référence (celle dont le libellé vient du PDF, sinon `duplicateOf`), lui reporte catégorie manuelle, note et plein (ADR 0015) si elle n'en a pas, supprime l'autre. `distinct` : `duplicateStatus=confirmed_distinct`, `duplicateOf=null`. ⇒ `{"kept": Operation}` |

## Commerçants (écran « à catégoriser »)
| Méthode | Route | Notes |
|---|---|---|
| GET | `/api/merchants` | `[MerchantGroup]` paginé. Regroupement par `coalesce(merchant, normalizedLabel)` + sens. Filtres : périmètre, `uncategorized=1` (groupes ayant au moins une opération non catégorisée ; compteurs alors limités aux non catégorisées), `q`, `from`/`to`. Tri `count` desc (défaut) ou `total`. `suggestion` : catégorie de l'opération manuelle la plus similaire (même logique que le Categorizer, seuil 0.6), sinon null. |
| POST | `/api/merchants/categorize` | `{"key","direction","categoryId","createRule": true}` : toutes les opérations non manuelles du groupe (périmètre ignoré) prennent la catégorie (source `manual` pour celles du groupe) ; si `createRule`, règle apprise (commerçant exact, sens, priorité 100). ⇒ `{"updatedCount": n, "rule": Rule|null}` |

## Récurrences (détection des dépenses/revenus récurrents)
Détection automatique (voir [ADR 0012](adr/0012-recurrences.md)) : groupes (compte, `coalesce(merchant, normalizedLabel)`, sens), analysés sur les 36 derniers mois (doublons probables exclus, classification `AmountClassifier`) ; upsert idempotent par (compte, clé, sens), liaison `operation.recurringSeriesId`. Relancée automatiquement après chaque import (post-traitement) et disponible à la demande.

| Méthode | Route | Notes |
|---|---|---|
| GET | `/api/recurrences` | `?status=detected,confirmed` (liste séparée par virgules, optionnel), `?direction=expense\|income` (optionnel), + périmètre. ⇒ `{"items": [Recurrence], "counts": {"detected": n, "confirmed": n, "ignored": n, "ended": n}}` (counts sur le périmètre, avant filtre). Tri : status (confirmed, detected, ended, ignored), puis `nextExpected` croissant (null en dernier), puis id |
| GET | `/api/recurrences/{id}` | ⇒ `Recurrence` ; hors foyer ⇒ `404` |
| PATCH | `/api/recurrences/{id}` | Corps partiel : `status` (detected\|confirmed\|ignored\|ended), `expectedAmount` (int > 0, centimes), `amountTolerance` (int ≥ 0), `frequency`, `activeMonths` (liste de mois 1..12 sans doublon, triée, ou `null` explicite = tous les mois). Modifier un champ autre que `status` sur une série « detected » la passe en « confirmed » (sauf si `status` est fourni explicitement). `frequency`/`activeMonths` modifiés ⇒ `nextExpected` recalculé. `status=ignored` délie les opérations. Valeurs invalides ⇒ `422` avec `violations` |
| POST | `/api/recurrences/detect` | Lance la détection sur le foyer ⇒ `{"created": n, "updated": n, "ended": n, "removed": n, "linked": n}` — `removed` = séries « detected » supprimées car leur motif n'est plus reconnu (opérations déliées) |

## Règles et catégorisation| Méthode | Route | Notes |
|---|---|---|
| GET | `/api/rules` | `[Rule]` triées priorité desc, id asc ; `matchCount` = opérations dont `appliedRule` est cette règle |
| POST | `/api/rules` | champs de `Rule` avec `categoryId`, `accountId`, `vehicleId` ⇒ `201 Rule` (regex invalide ⇒ `422`) ; `vehicleId` désigne le véhicule affecté aux opérations de la règle |
| PATCH | `/api/rules/{id}` | idem partiel ; `vehicleId: null` explicite (clé présente) retire le véhicule |
| DELETE | `/api/rules/{id}` | `204` (les opérations catégorisées par cette règle perdent leur catégorie puis sont recatégorisées) |
| POST | `/api/rules/preview` | corps = règle (sans id) ⇒ `{"matchCount": n, "conflicts": n, "sample": [Operation]}` (10 max ; `conflicts` = opérations correspondantes déjà catégorisées autrement, hors manuelles comptées à part dans `"manualCount"`) |
| POST | `/api/categorization/run` | `{"all": bool}` ⇒ `{"processed","byRule","bySimilarity","stillUncategorized","categorizedPercent","vehicles":{"processed","byRule","auto","cleared","unchanged"}}` |

## Imports
| Méthode | Route | Notes |
|---|---|---|
| GET | `/api/imports` | `[ImportBatch]` paginé, plus récent d'abord |
| POST | `/api/imports` | `multipart/form-data`, champ `files[]` (PDF/CSV, 20 Mo max chacun). Chaque fichier est conservé sous `data/uploads/<sha256>.<ext>`, importé (création automatique du compte si inconnu), puis post-traitement (rapprochement des saisies manuelles + virements internes + catégorisation + affectation des véhicules + détection des récurrences). ⇒ `{"reports": [ImportReport], "postProcessing": {"reconciliation": {"matched": n, "ambiguous": n, "pending": n}, "transfersMatched": n, "categorized": n, "vehiclesAssigned": n, "recurrences": {"created": n, "updated": n, "ended": n, "removed": n, "linked": n}}}` |

## Virements internes
| Méthode | Route | Notes |
|---|---|---|
| GET | `/api/transfers` | `[InternalTransfer]` paginé ; `status=auto` (à vérifier) ou `confirmed`, absent = tous (400 sinon) |
| POST | `/api/transfers/match` | ⇒ `{"matched": n, "ambiguous": n}` |
| POST | `/api/transfers/{id}/confirm` | valide un virement détecté (`status: "confirmed"`) ⇒ `InternalTransfer` ; il sort de `pendingTransfersCount` |
| POST | `/api/transfers/confirm-all` | valide tous les virements `auto` du foyer ⇒ `{"confirmedCount": n}` (idempotent) |
| DELETE | `/api/transfers/{id}` | défait l'appariement (validé ou non) ⇒ `204` |

## Budgets (utilisés à l'étape 9)
`GET/POST /api/budgets`, `PATCH/DELETE /api/budgets/{id}` (corps : champs de `Budget` avec `categoryId`, `memberId`, `accountId`).

## Statistiques (étape 8)
Tous les endpoints acceptent le **périmètre** (`accountId[]` / `memberId`) et `from` / `to` (inclus ; défaut : 12 derniers mois complets + mois en cours). Les périodes portent sur la **date effective** `COALESCE(spent_at, date)` (ADR 0014), sauf le solde reconstitué `/api/stats/balance` qui garde la date du relevé (la saisie compte à sa date réelle).

**Règles de calcul communes** (implémentées une seule fois côté back, `src/Stats/`) :
- **Dépense** = opération catégorisée dans une catégorie `kind=expense`, ou non catégorisée de montant < 0. **Revenu** = catégorie `kind=income`, ou non catégorisée de montant > 0. Les montants de dépense sont renvoyés **positifs** (somme de `-amount` : un remboursement dans une catégorie de dépense la diminue).
- **Exclus** des dépenses/revenus : catégories `kind=transfer` (mouvements internes, épargne) et opérations liées à un virement interne. En périmètre compte/personne, ces montants sont renvoyés à part dans `transfersIn` / `transfersOut` quand l'objet le prévoit.
- Agrégation par **catégorie racine** (une opération d'une sous-catégorie compte pour sa racine) sauf mention contraire.
- `CategoryRef` des statistiques inclut `chartSlot` (1-8 ou null) : le front colore par emplacement et regroupe en « Autres » les racines sans emplacement.
- **Mois en cours incomplet** (salaires vers le 26-29, relevés importés vers le 8 du mois suivant) : exclu des comparaisons et du taux d'épargne. Définitions centralisées dans `src/Stats/StatsPeriod.php` avec horloge injectable (`Symfony\Component\Clock\ClockInterface`, `MockClock`/`TestClock` en test) : `isIncomplete(now)` (to >= 1er du mois de `now`), `completeMonths(now)` (période complète ⇒ elle-même ; sinon [from, dernier jour du mois précédent] ; sinon null), `comparable(now)` (completeMonths sinon « mois à date » [from, min(to, aujourd'hui)]), `previousAligned(now)` (N mois calendaires précédents si comparable = mois complets ; mêmes jours du mois précédent bornés si « mois à date » ; sinon `comparable->previous()`), `sameLastYear()` inchangé (s'applique à `comparable`).

| Méthode | Route | Réponse |
|---|---|---|
| GET | `/api/stats/overview` | `{"from","to","income","expense","net","savingsRate": 0.18 \| null, "savingsRateFrom": "AAAA-MM-JJ", "savingsRateTo": "AAAA-MM-JJ", "operationsCount","uncategorizedExpense","transfersIn","transfersOut", "previous": {"from","to","income","expense","net"}, "comparison": {"current": {"from","to","income","expense","net"}, "previous": {"from","to","income","expense","net"}}, "average12": {"income","expense"}}` — `income`/`expense`/`net`/compteurs = toute la période demandée ; `savingsRate` calculé sur les **mois complets** (`completeMonths(now)`, repli sur le dernier mois complet si la période est entièrement dans le mois en cours), `savingsRateFrom`/`savingsRateTo` = plage réellement utilisée ; `previous` = période de même durée qui précède (**déprécié**, sémantique ancienne conservée) ; `comparison` = `comparable(now)` vs `previousAligned(now)` (mois en cours incomplet exclu des deux côtés) ; `average12` = moyenne mensuelle des 12 mois complets précédant `to`. |
| GET | `/api/stats/monthly` | `{"months": ["2026-01", …], "income": [n…], "expense": [n…], "net": [n…], "byRoot": [{"category": CategoryRef, "values": [n…]}], "uncategorized": [n…], "estimate": {"month": "AAAA-MM", "basisMonths": k, "spent": n, "received": n, "average": n, "incomeAverage": n, "recurring": {"expense": {"remaining": n, "byRoot": [{"rootId": int|null, "remaining": n}], "items": [RecurrenceItem…]}, "income": {"remaining": n, "items": [RecurrenceItem…]}}, "variable": {"expenseAverage": n, "expenseSpent": n, "expenseRemaining": n, "incomeAverage": n, "incomeReceived": n, "incomeRemaining": n}, "expense": n, "income": n} \| null}` — `byRoot` : toutes les racines de dépense ayant au moins une valeur non nulle, valeurs = dépense du mois ; `uncategorized` = dépenses non catégorisées. Mois sans opération = 0. `estimate` présent seulement si le mois en cours fait partie de `months` et k > 0 : `spent` = dépense du mois en cours à date, `received` = revenu à date, `average` = moyenne mensuelle des dépenses des k derniers mois complets précédant le mois en cours (k ≤ 12, bornés au premier mois ayant au moins une opération dans le périmètre ; même périmètre comptes et même classification que `expense`), `incomeAverage` = idem revenus. `recurring` = prévision des échéances récurrentes du mois (RecurrenceForecaster ; `RecurrenceItem` = `{"seriesId", "merchant", "categoryId": int|null, "rootId": int|null, "expectedDate": "AAAA-MM-JJ", "amount": n, "paid": bool}`, triés par `expectedDate`) : `remaining` = montant des échéances non encore payées du mois. `variable` = partie NON récurrente (opérations non liées à une série non ignorée) : moyennes des k mois complets (`expenseAverage`/`incomeAverage`), montants à date du mois en cours (`expenseSpent`/`incomeReceived`), reste = `max(0, moyenne − à date)`. `expense` = `spent + recurring.expense.remaining + variable.expenseRemaining` ; `income` = `received + recurring.income.remaining + variable.incomeRemaining`. `null` si k = 0 ou si le mois en cours n'est pas dans `months`. |
| GET | `/api/stats/categories` | `?direction=expense\|income&parentId=&compare=previous\|year` ⇒ `[{"category": CategoryRef \| null, "total", "count", "share", "compareTotal", "comparableTotal"}]` triés par total desc. Sans `parentId` : racines (+ ligne `category: null` = non catégorisé) ; avec `parentId` : sous-catégories de cette racine (+ la racine elle-même si des opérations y sont directement rattachées). `total` = période demandée. Quand `compare` est fourni (mois en cours incomplet exclu) : `comparableTotal` = total sur `comparable(now)` ; `compareTotal` = total sur `previousAligned(now)` (`previous`) ou `comparable(now)->sameLastYear()` (`year`) ; les plages réellement utilisées sont dans les en-têtes `X-Comparable-From`, `X-Comparable-To`, `X-Compare-From`, `X-Compare-To` (AAAA-MM-JJ, absents sans `compare`). Sans `compare` : `compareTotal`/`comparableTotal` = null. `share` ∈ [0,1]. |
| GET | `/api/stats/merchants` | `?direction=expense\|income&limit=10` (max 50) `&keys[]=…` (optionnel, 1 à 50 clés : restreint aux groupes donnés, ex. période précédente des commerçants du top courant) ⇒ `[{"key","merchant","total","count","category": CategoryRef \| null}]` (clé de groupe `coalesce(merchant_group, merchant, normalized_label)` : variantes d'un même commerçant regroupées, [ADR 0013](adr/0013-regroupement-commercants.md) ; catégorie majoritaire). |
| GET | `/api/stats/balance` | `?granularity=month\|day` ⇒ `{"dates": ["2026-01-31", …], "total": [n \| null…], "accounts": [{"account": AccountRef, "values": [n \| null…]}]}` — solde de fin de période **reconstitué** : ancrage sur les soldes des relevés (`Statement`) + opérations ; `null` avant la première donnée d'un compte ; `total` = somme des comptes du périmètre (null si aucun). |
| GET | `/api/stats/sankey` | `{"nodes": [{"id","name","kind": "income\|expense\|hub\|savings\|deficit", "category": CategoryRef \| null}], "links": [{"source","target","value"}]}` — racines de revenu → nœud `hub` « Budget » → racines de dépense ; nœud `savings` « Épargne / excédent » si revenus > dépenses, sinon nœud source `deficit` « Déficit ». Non catégorisé = nœud dédié de chaque côté. |
| GET | `/api/stats/calendar` | `[{"date": "2026-03-14", "expense": n, "count": n}]` — dépenses par jour (jours sans dépense omis). |
| GET | `/api/stats/category-trend` | `?categoryId=` (racine ou feuille, sous-catégories incluses) ⇒ `{"category": CategoryRef, "months": [...], "values": [...], "counts": [...]}` |
| GET | `/api/stats/vehicles` | ⇒ `{"vehicles": [{"vehicle": VehicleRef, "total", "usageTotal", "financingTotal", "count", "monthlyAverage", "usageMonthlyAverage", "byCategory": [{"category": CategoryRef \| null, "total"}], "distance": {"km", "coveredFrom", "coveredTo"} \| null, "costPerKm": n \| null, "costPerKmTotal": n \| null}], "unassigned": {"total", "count"}}` — véhicules ayant au moins une dépense sur la période, triés par `total` desc ; `total` positif (somme de `-amount`, kind `expense` uniquement, virements internes exclus, même convention que `/api/stats/categories`) ; `monthlyAverage` = total / nombre de mois de la période ; `unassigned` = opérations de catégories `vehicleRelated` sans véhicule. **Lot 4** : `distance` = distance parcourue **sur la période demandée** (période ramenée à l'intersection avec [premier relevé, dernier relevé] du véhicule, km aux bornes interpolés linéairement entre les relevés encadrants, relevés à km décroissant ignorés) ⇒ `null` si moins de 2 relevés ou intersection vide ; `costPerKm` = dépense du véhicule (même convention que `total`, périmètre comptes inclus) restreinte à `[coveredFrom, coveredTo]` divisée par `distance.km`, en centimes par km arrondi à 1 décimale ⇒ `null` si `distance` null ou `km` nul. **Financement** (ADR 0011) : `financingTotal` = part des catégories `financing` (crédit véhicule…) dans `total`, `usageTotal` = `total - financingTotal`, `usageMonthlyAverage` = `usageTotal` / mois ; `costPerKm` porte sur l'**usage seul** (hors `financing`), `costPerKmTotal` = usage + financement sur la même fenêtre (mêmes règles d'arrondi et de `null`) |

`Category` (référentiel, `/api/categories`) gagne aussi `chartSlot` ; `PATCH /api/categories/{id}` accepte `chartSlot` (1-8 ou null, **racines de dépense uniquement**, unique dans le foyer ⇒ `409` si déjà pris, `422` sinon).

## Notes d'implémentation (étape 7, back)

Fichiers clés :
- Contrôleurs : `src/Controller/Api/` (un par ressource ; `AuthController` déclare aussi les routes `api_login`/`api_logout` interceptées par le firewall).
- Sérialisation explicite : `src/Api/Presenter/*Presenter.php` (`present(Entity): array`).
- Entrées validées : `src/Api/Input/*` (`#[MapRequestPayload]` + contraintes Validator ⇒ `422` avec `violations`).
- Filtres/périmètre : `src/Api/Filter/OperationFilter.php`, `src/Api/Scope.php` (`accountId[]`/`memberId`), pagination `src/Api/Pagination.php`, compteurs agrégés `src/Api/Query/StatsQuery.php` (GROUP BY, aucun N+1).
- Sécurité : `config/packages/security.yaml` (`json_login` sur `/api/login` avec handlers `App\Security\JsonLogin*Handler`, `logout` sur `/api/logout` + `App\Security\ApiLogoutListener` (LogoutEvent ⇒ 204), `login_throttling` 5/min, entry point `App\Security\ApiAuthenticationEntryPoint` ⇒ 401 JSON, `access_control` `^/api/(login|health)` public puis `^/api` `ROLE_USER`, cookie `SameSite=Lax`/`HttpOnly` dans `framework.yaml`). Listener `App\EventListener\JsonContentTypeListener` (415) et `App\EventListener\ApiExceptionSubscriber` (404/422/400/409/500 contrat).
- Réutilisations : `App\Categorization\SimilarityFinder` (extrait de `Categorizer`, comportement inchangé — tests verts) pour les suggestions commerçants ; `App\Import\PostImportProcessor` (extrait de `ImportCommand`) pour le post-traitement commun commande/API.
- Uploads : paramètre `app.uploads_dir` (`data/uploads`, surchargé en `var/test-uploads` en test), stockage `<sha256>.<ext>`. Paquets ajoutés : `symfony/serializer` (MapRequestPayload) et `symfony/rate-limiter` (login_throttling).

Choix et écarts par rapport au contrat :
- `PATCH` : un champ absent ou `null` ne modifie pas la ressource, sauf `note` sur opération où `null` efface la note. Conséquence : `parentId: null` ne remonte pas une catégorie à la racine (on archive plutôt).
- `POST /api/merchants/categorize` : `updatedCount` inclut toutes les opérations non manuelles du groupe (y compris l'opération représentative utilisée pour apprendre la règle) ; groupe sans commerçant (clé = libellé normalisé) ⇒ règle apprise sur `label` exact au lieu de `merchant`.
- `POST /api/operations/{id}/duplicate` avec `merge` sur une opération sans `duplicateOf` et sans libellé PDF ⇒ `400 not_a_duplicate`.
- `POST /api/categorization/run` : `categorizedPercent` = pourcentage des opérations traitées ayant reçu une catégorie (règle + similarité). `vehicles` = rapport de la synchronisation de l'axe véhicule (`byRule` + `auto` affectations posées, `cleared` retirées) exécutée après la catégorisation.
- `POST /api/imports` : un fichier invalide (format, > 20 Mo, erreur de parse) produit un rapport `failed` avec `error`, sans empêcher les autres fichiers ; `postProcessing` n'est lancé que si `added + enriched > 0` ; `vehiclesAssigned` = opérations ayant reçu un véhicule (règle + auto) lors du post-traitement.
- Erreur `500` : message détaillé uniquement si `kernel.debug`, sinon « Une erreur interne est survenue. ».
- `DELETE /api/transfers/{id}` : dissocie les opérations et retire leur catégorie « virement interne » (elles seront recatégorisées au prochain `run`/import).

## Notes d'implémentation (étape 8, back)

Fichiers clés :
- `src/Stats/StatsPeriod.php` (from/to, défauts « 12 derniers mois complets + mois en cours », `previous()`, `sameLastYear()` — 29 février → 28 février, `last12FullMonths()`, `months()`, + `isIncomplete`/`completeMonths`/`comparable`/`previousAligned` avec `$now`), `src/Stats/AmountClassifier.php` (expression CASE unique de classification : `internal_transfer_id` puis `coalesce(root.kind, cat.kind)`, non catégorisé ⇒ signe), `src/Stats/StatsRepository.php` (une méthode par endpoint, 1 à 3 requêtes agrégées DBAL : CTE `params`, `generate_series` pour les mois/jours, CTE pour le solde reconstitué `balance` ancré sur les `Statement` ; `overview()` prend une période de taux dédiée (`savingsRateFrom`/`savingsRateTo`), `categories()` calcule aussi `comparableTotal`, `monthlyAverages()` alimente `estimate`).
- Contrôleur `src/Controller/Api/StatsController.php` (8 routes `/api/stats/*`, périmètre `Scope` + période `StatsPeriod::fromRequest($request, $clock->now())` avec `ClockInterface` injecté ⇒ 400 `invalid_query` si dates invalides, `from > to`, `direction`/`compare`/`granularity`/`limit` hors bornes, `categoryId` absent/introuvable). En test, `ClockInterface` est remplacé par `App\Tests\Support\TestClock` (freeze statique, `config/services_test.yaml`).
- `chartSlot` : colonne `category.chart_slot` + contrainte unique **partielle** déclarée dans le mapping via `#[ORM\UniqueConstraint(..., options: ['where' => '(chart_slot IS NOT NULL)'])]` (ORM 3.7 ; parenthèses reprises telles quelles depuis `pg_indexes`) ⇒ `doctrine:migrations:diff` reste vide. Migration `Version20261104190000` : crée l'index partiel et initialise les 8 racines de dépense existantes par nom (slots 1-8 + palette). `DefaultCategories` porte les mêmes slots/couleurs (autres racines : `#898781`, slot null) appliqués par `app:setup` à la création. `PATCH /api/categories/{id}` : clé `chartSlot` présente dans le corps (même `null` = retire) ⇒ racine `expense` sinon 422, unicité foyer ⇒ 409 `chart_slot_taken` ; `CategoryPresenter` expose `chartSlot` sur `Category` et `CategoryRef` (hérité de la racine via `Category::getRootChartSlot()`).

Choix et écarts par rapport au contrat :
- `savingsRate` = `round(net / income, 4)` sur la plage `savingsRateFrom`..`savingsRateTo` (mois complets), `null` si income = 0 ; `share` = `round(total / totalDirection, 4)` ; `average12` = moyennes arrondies en centimes entiers (`round(sum / 12)`) ; `estimate.average` / `estimate.incomeAverage` = `round(sum / k)` en centimes entiers.
- `overview.previous` est **déprécié** (période de même durée qui précède, mois en cours inclus) : conservé pour compatibilité, le front lit `overview.comparison` (`comparable` vs `previousAligned`).
- `categories` avec `compare` : plages exposées uniquement en en-têtes `X-Comparable-From/To` et `X-Compare-From/To` (la réponse reste un tableau) ; sans `compare`, `compareTotal`/`comparableTotal` = null.
- Défaut de période : `to` = dernier jour du mois en cours, `from` = premier jour du mois de `to` moins 12 mois (12 mois complets + mois en cours).
- `transfersIn`/`transfersOut` = montants exclus des dépenses/revenus (virement interne apparié ou catégorie `transfer`), par sens, positifs. Au foyer un virement apparié donne in = out ; en périmètre compte/personne seul le sens du compte apparaît.
- `monthly.byRoot` ne contient que les racines catégorisées (le non catégorisé est dans `uncategorized`) ; `calendar` inclut les remboursements (valeurs négatives possibles, même classification que partout).
- `balance` : point `null` avant la première donnée du compte (pas de relevé ⇒ `null` partout ; avant l'opening du premier relevé ⇒ `null`) ; granularité `month` = fins de mois + `to` en dernier point.
- `merchants` (stats) groupe par clé seule (sans le sens, contrairement à `/api/merchants`), `total` positif en dépense, tri `total` desc ; catégorie = catégorie majoritaire des opérations du groupe (la catégorie exacte, pas forcément une racine).

## Notes d'implémentation (lot 3, véhicules)

Fichiers clés :
- `src/Controller/Api/VehicleController.php` : CRUD + `POST /api/vehicles/lookup` + `GET /api/vehicles/lookup/status`. Plaque normalisée via `App\Util\PlateNormalizer` (invalide ⇒ `422` sur `plate`, le message ne contient jamais la plaque) ; unicité nom/plaque vérifiée avant flush ⇒ `409` (`name_exists`/`plate_exists`). `DELETE` ⇒ `409 vehicle_in_use` si opérations ou règles rattachées. `lookup` : `lookupData` (raw) enregistré uniquement si `vehicleId` fourni ; `lookupData` n'est jamais exposé par l'API (seul `lookupAt` l'est). Erreurs lookup : `ServiceUnavailableException` (503 `lookup_disabled`, 502 `unavailable`/`invalid_response`), `TooManyRequestsException` (429 `quota`), `NotFoundException` (404 `not_found`).
- Entrées `src/Api/Input/VehicleCreateInput|VehiclePatchInput|VehicleLookupInput|VehicleAssignInput|BulkVehicleAssignInput.php` ; présentation `src/Api/Presenter/VehiclePresenter.php` (`present()` complet + `presentRef()` = `{id, name, type, archived}`).
- `OperationController` : `POST /api/operations/{id}/vehicle` et `POST /api/operations/bulk-vehicle` (affectation manuelle, `vehicleId: null` ⇒ retrait + `sync()`). **Piège UnitOfWork** : `VehicleAssigner::sync()` écrit en DBAL hors UnitOfWork ⇒ `$entityManager->refresh($operation)` avant de présenter l'opération après un `sync()` (déjà en place dans `categorize`, couvert par un test).
- `OperationFilter` : filtres `vehicleId`, `vehicleUnassigned=1` (hors « ignorées ») et `vehicleIgnored=1` (jointure propre `fvc` sur la catégorie pour ne pas heurter l'alias `c` du select), appliqués aussi aux `sums`.
- `RuleCreateInput`/`RulePatchInput`/`RulePresenter` : `vehicleId` (`null` explicite en PATCH = retirer, détecté sur le corps brut comme `chartSlot`) ; `sync()` après création/modification de règle. Idem `CategoryCreateInput`/`CategoryPatchInput`/`CategoryPresenter` pour `vehicleRelated`.
- `src/Stats/StatsRepository::vehicles()` (3 requêtes agrégées DBAL sur la base commune `base()`/`fromWhere()`, kind `expense` via `AmountClassifier`, virements internes exclus) + route `GET /api/stats/vehicles` dans `StatsController` ; `monthlyAverage` calculé côté contrôleur (total / mois de la période). Compteurs liste véhicules : `StatsQuery::vehicleOperationCounts()`, dernier relevé `OdometerReadingRepository::latestByVehicleIds()` (DISTINCT ON). `GET /api/summary` gagne `vehicleUnassignedCount`.
- Tests : `tests/Functional/Api/VehicleApiTest.php` + compléments dans `OperationApiTest`, `RuleApiTest`, `CategoryApiTest`, `StatsApiTest`. Le service `App\Vehicle\Lookup\VehicleLookup` est remplacé en test par `App\Tests\Support\FakeVehicleLookup` (lié dans `config/services_test.yaml`, état statique car le noyau est reboooté entre les requêtes).

Choix et écarts par rapport au contrat :
- `GET /api/stats/vehicles` ne liste que les véhicules ayant au moins une dépense (kind `expense`) sur la période, comme `/api/stats/categories` ne liste que les catégories ayant des opérations.
- `monthlyAverage` = `round(total / nombre de mois de la période)` (0 si période sans mois, impossible en pratique).
- Pas de `GET /api/vehicles/{id}` (non demandé) ; le véhicule complet est renvoyé par `POST`/`PATCH` et la liste.
- Aucune migration : le schéma véhicules existe déjà (`Version20261105170000`), `doctrine:schema:validate` est vert.

## Notes d'implémentation (lot 4, relevés kilométriques et coût au km)

Fichiers clés :
- `src/Vehicle/OdometerCalculator.php` (pur, sans dépendance) : `distance(list<OdometerReading|array{date, km}> $readings, \DateTimeImmutable $from, \DateTimeImmutable $to): ?OdometerDistance`. Relevés triés par date (doublons de date dédoublonnés, premier conservé) ; relevé incohérent (km strictement inférieur au dernier relevé cohérent précédent) ignoré ; période ramenée à l'intersection avec [premier, dernier relevé] ; km aux bornes par interpolation linéaire (timestamps) entre les relevés encadrants ; `null` si moins de 2 relevés cohérents ou intersection vide. Retour `src/Vehicle/OdometerDistance.php` (`{km: float, coveredFrom, coveredTo}` + `toArray()` en `AAAA-MM-JJ`).
- `src/Controller/Api/VehicleController.php` : `GET /api/vehicles/{id}/odometer` (date desc, `OdometerReadingRepository::findByVehicleDesc()`) et `POST /api/vehicles/{id}/odometer` ⇒ `201` ; doublon de date vérifié avant flush ⇒ `409 reading_date_exists` ; `km < 0` ⇒ `422` via `Assert\PositiveOrZero` sur `OdometerReadingCreateInput` ; avertissement `"warning"` si le relevé est incohérent avec ses voisins (km < relevé précédent ou km > relevé suivant, comparaison sur les dates strictes).
- `src/Controller/Api/OdometerReadingController.php` : `PATCH`/`DELETE /api/odometer-readings/{id}` ⇒ `204` ; périmètre foyer vérifié via `vehicle.household` (relève hors foyer ⇒ `404`) ; PATCH partiel (`OdometerReadingPatchInput`, absent = inchangé, `note: null` explicite = efface), doublon de date ⇒ `409`.
- `src/Api/Presenter/OdometerReadingPresenter.php` : `{id, date, km, note}`.
- `src/Repository/OdometerReadingRepository.php` : `pointsByVehicleIds()` (points `{date, km}` groupés par véhicule, une requête DBAL, ordre date asc) pour le calcul de distance et l'avertissement.
- `src/Stats/StatsRepository::vehicleExpenseBetween()` : dépense d'un véhicule (mêmes CTE `base()`/`fromWhere()`, kind `expense`, virements exclus, périmètre comptes) restreinte à `[coveredFrom, coveredTo]` ; `StatsController::vehicles()` ajoute par véhicule `"distance"` (`OdometerCalculator::distance()` sur la période demandée, `null` si moins de 2 relevés ou intersection vide) et `"costPerKm"` = `round(dépense[coveredFrom, coveredTo] / distance.km, 1)`, `null` si distance null ou `km` nul.
- Tests : `tests/Unit/Vehicle/OdometerCalculatorTest.php` (interpolation, bornes, 0/1 relevé, période hors relevés, incohérence) + `tests/Functional/Api/VehicleApiTest.php` (CRUD relevés, 409, 422, warning, isolement foyer) + `tests/Functional/Api/StatsApiTest.php` (distance/costPerKm).

Choix et écarts par rapport au contrat :
- Aucune migration : la table `odometer_reading` existe déjà (`Version20261105170000`), `doctrine:schema:validate` reste vert.
- `distance.km` est un float (interpolation) ; `costPerKm` est le seul champ arrondi (1 décimale, comme demandé).
- L'avertissement d'incohérence n'est émis que sur `POST` (création) ; `PATCH` applique sans avertir.
- `costPerKm` respecte le périmètre comptes (`accountId[]`/`memberId`) comme le `total` de la ligne.

## Notes d'implémentation (lot récurrences)

Fichiers clés :
- `src/Recurrence/RecurrencePattern.php` (classe PURE, testée unitairement) : analyse d'un groupe d'opérations (une occurrence par jour, fusionnées) — fréquence candidate = durée nominale la plus proche de la médiane des écarts (± tolérance), ≥ 75 % d'écarts réguliers dont ≥ 60 % à un seul cycle (k ∈ {1,2,3} pour weekly/monthly/bimonthly : cycles sautés), nombre minimal d'occurrences (6/4/3/3/2/2), récence (dernière occurrence > today − (2 × nominal + tolérance)), second essai sur le sous-ensemble à ±15 % de la médiane des montants ; montant attendu = médiane des 6 dernières occurrences, tolérance = max(100, 15 % du montant, 2 × écart absolu médian) ; `activeMonths` (weekly/monthly/bimonthly, historique ≥ 12 mois et 6..11 mois actifs) ; `nextExpected` via `RecurrenceCalendar` (jour de l'ancre conservé, borné au dernier jour du mois, mois hors `activeMonths` sautés).
- `src/Recurrence/RecurrenceDetector.php` : synchronisation DBAL idempotente (fenêtre 36 mois, doublons probables exclus, classification `AmountClassifier` — virements internes et catégories « transfer » exclus), upsert par (compte, matchKey, direction), liaison groupée `operation.recurring_series_id`, fin automatique des séries « detected » dont `nextExpected + nominal + tolérance < today` (« confirmed » jamais). Appelé après chaque import (`PostImportProcessor`) et par `POST /api/recurrences/detect` / `app:recurrences:detect [--dry-run]` (compteurs uniquement).
- `src/Recurrence/RecurrenceForecaster.php` : échéances du mois en cours (séries detected/confirmed du périmètre) — dates de la suite depuis la dernière occurrence antérieure au mois (ou `lastOccurrence`), opérations liées du mois = payées, reste × montant attendu.
- `src/Controller/Api/RecurrenceController.php` + `src/Api/Presenter/RecurrencePresenter.php` + `src/Api/Input/RecurrencePatchInput.php` : liste/filtres (périmètre `Scope`, `status`, `direction`), lecture, PATCH partiel (correction ⇒ « confirmed », `ignored` délie les opérations, 422 avec `violations`), `POST /api/recurrences/detect`.
- `OperationFilter` : filtre `recurringSeriesId` ; `OperationPresenter` expose `recurringSeriesId`.
- `StatsRepository::monthlyAverages()` renvoie aussi `expenseVariable`/`incomeVariable` (moyennes des opérations non liées à une série non ignorée, même requête) et `currentMonthVariable()` (à date du mois en cours, non récurrent) ; `StatsController::monthly()` calcule le nouvel `estimate` (`recurring` + `variable` + totaux).
- Migration `Version20261106100000` : `recurring_series` gagne `match_key`, `direction`, `active_months`, `updated_at` + index unique `(account_id, match_key, direction)`.
- Tests : `tests/Unit/Recurrence/RecurrencePatternTest.php` + `tests/Functional/Api/RecurrenceApiTest.php` (détection/idempotence, confirmed non écrasée, ignored délie, ended auto, API, filtre opérations, estimate payée/non payée/variable) ; `StatsApiTest` à jour du nouveau format `estimate`.

Choix et écarts par rapport au contrat :
- La détection écrit en DBAL hors UnitOfWork (comme `VehicleAssigner`) : à appeler après un flush ; `PATCH /api/recurrences/{id}` qui délie (`ignored`) fait un `refresh()` de la série avant de la présenter.
- Les opérations d'une série passée « ended » restent liées (historique) ; seules les séries « ignored » sont déliées, ainsi que les opérations d'un groupe détecté qui ne sont plus retenues (sous-ensemble ±15 %).
- `estimate.recurring` est toujours présent (à 0) quand `estimate` est présent, même sans aucune série.

## Notes d'implémentation (lot saisie manuelle + rapprochement, ADR 0014)

Fichiers clés :
- `src/Entity/Operation.php` : `spentAt`, `awaitingStatement`, `reconciledAt`, constructeur nommé `manualEntry()` (empreinte `manual:…` aléatoire, jamais un sha256), `getEffectiveDate()`, `Operation::effectiveDateSql()` (SQL brut) / `effectiveDateDql()` (CASE WHEN — `COALESCE` n'est pas une fonction DQL enregistrée) ; migration `Version20261106140000` (`spent_at` sans backfill, index partiel `WHERE awaiting_statement`, index fonctionnel `(account_id, COALESCE(spent_at, date))` en SQL brut, déclaré dans le mapping sur `account_id` seul — ainsi que pg_index le relit — pour garder `doctrine:schema:validate` vert).
- `src/Import/CardDateExtractor.php` : extraction du `JJ.MM.AA` des libellés CB (année 20AA) + garde-fous (`checkdate`, `spentAt <= date`, écart ≤ 31 jours ; date égale à la date du relevé conservée). Posé à l'import (`StatementImporter`, création + enrichissement sauf ligne déjà rapprochée) et recalculable via `app:operations:spent-at [--dry-run]` (jamais les saisies en attente ni les lignes rapprochées) ; `app:operations:rebuild-fingerprints` ignore les saisies en attente.
- Exclusions `awaiting_statement` : dédoublonnage flou de `StatementImporter` (`AND NOT o.awaiting_statement`), `InternalTransferMatcher`, `RecurrenceDetector`.
- `src/Reconciliation/ManualEntryReconciler.php` + `ReconciliationReport.php` : `reconcile()` (fenêtre −2/+10 j, montant exact, même compte, paire univoque), `candidates()` (fenêtre −5/+30 j, tri montant exact → compte → écarts), `merge()` (valeurs du relevé conservées ; spentAt de la ligne prioritaire, catégorie/véhicule/note de la saisie copiés sous conditions). Branché en premier dans `PostImportProcessor` ⇒ `PostImportReport::reconciliation`, affiché par `app:import`, exposé dans `postProcessing.reconciliation` de `POST /api/imports` ; commande `app:entries:reconcile [--dry-run]`.
- `OperationController` : `POST /api/operations` (201 `{operation, reconciled}`, rapprochement immédiat), `PATCH` (champs de saisie détectés sur le corps brut, 400 `not_a_manual_entry` sur opération importée), `DELETE` (204, saisie en attente seule), `GET …/reconciliation-candidates`, `POST …/reconcile`. Entrées `OperationCreateInput`, `OperationPatchInput` (étendu), `ReconcileInput`.
- `OperationFilter` : `awaitingStatement=1`, `from`/`to` et tri `date` sur la date effective. `OperationPresenter` : `spentAt`, `effectiveDate`, `awaitingStatement`, `reconciledAt`.
- `SummaryController` : `awaitingStatementCount` + `staleManualEntries` (spentAt < aujourd'hui − 15 j, `ManualEntryReconciler::STALE_AFTER_DAYS`, horloge `ClockInterface`), `firstDate`/`lastDate` sur date effective. `StatsRepository` (sauf `balance()`), `MerchantController`, `StatsQuery::accountStats` (sauf solde), `VehicleAssigner` : date effective.
- Tests : `tests/Unit/Import/CardDateExtractorTest.php`, `tests/Unit/Entity/OperationManualEntryTest.php`, `tests/Integration/Reconciliation/ManualEntryReconcilerTest.php`, `tests/Integration/Command/ManualEntryCommandsTest.php`, `tests/Functional/Api/ManualEntryApiTest.php`.

Choix et écarts par rapport au contrat :
- `POST /api/operations` renvoie **toujours** 201 avec `{operation, reconciled}` ; si la saisie a été absorbée immédiatement, `operation` est la ligne de relevé conservée (`reconciled: true`).
- `GET /api/operations/{id}/reconciliation-candidates` renvoie `{"items": [Operation]}` (pas de pagination, 20 max).
- Empreinte `manual:` + 56 caractères hexadécimaux (63 au total) : jamais égale à un sha256 d'import (64 hex), l'unicité vient de `random_bytes`.
- Budgets : aucune consommation n'est calculée côté back (les budgets sont des CRUD ; le front lit les stats, déjà à date effective).

## Notes d'implémentation (lot pleins de carburant, ADR 0015)

Fichiers clés :
- `src/Entity/Operation.php` : `fuelVolumeMl` (?int), `fuelFullTank` (?bool), `odometerKm` (?int), `recordFuel()` (lève `\InvalidArgumentException` si volume ≤ 0, km < 0 ou crédit), `clearFuel()`, `hasFuel()`, `getFuelPricePerLiter()` (calculé : `|amount| × 10 / volumeMl`, euros 3 déc.). `src/Entity/Category.php` : `fuel` (bool, défaut false).
- Migration `Version20261109120000` : colonnes + `category.fuel` (+ `UPDATE … WHERE name = 'Carburant' AND vehicle_related`) + contrainte `CHECK ck_operation_fuel` posée en SQL brut (non déclarée dans le mapping : DBAL ne relit pas les CHECK PostgreSQL, le diff de schéma reste vide).
- `src/Setup/DefaultCategories.php` : `FUEL_CHILD = 'Carburant'` + `isFuel()` ; posé par `app:setup` (et `CategorizationFixtures::seedCategories`) là où `vehicleRelated` est posé.
- Présentations/entrées : `OperationPresenter` (`fuel` = null ou `{volumeMl, fullTank, odometerKm, pricePerLiter}`), `CategoryPresenter` (`fuel`), `CategoryCreateInput`/`CategoryPatchInput` (`fuel`, même traitement que `vehicleRelated` sans resynchro), `FuelInput` (`volumeMl` > 0 obligatoire, `fullTank` défaut true, `odometerKm` ≥ 0), `OperationCreateInput` (`fuelVolumeMl`/`fuelFullTank`/`odometerKm` optionnels).
- `src/Controller/Api/OperationController.php` : `PUT`/`DELETE /api/operations/{id}/fuel` (200 `{"operation", "warning"?}`, 400 `not_a_debit` sur crédit, warning d'incohérence du km), champs plein de `POST /api/operations` (422 `odometerKm` sans volume, 422 `fuelVolumeMl` sur crédit), copie du plein au `merge` de doublon. `src/Controller/Api/VehicleController.php` : `GET /api/vehicles/{id}/fuel?from&to` (parse via `StatsPeriod::dateParam`, bornes libres = tout l'historique ; tout l'historique chargé pour le plein complet précédent, affichage filtré sur la période).
- `src/Vehicle/OdometerCoherence.php` : avertissement d'incohérence extrait de `VehicleController::incoherenceWarning` (partagé relevé/plein). `src/Repository/OdometerReadingRepository.php` : `pointsByVehicleIds()`/`latestByVehicleIds()`/`findPointsByVehicle()` en UNION ALL (relevés source 0 + pleins source 1, date effective, tri `date ASC, source ASC` — relevé prioritaire à date égale ; `latestByVehicleIds` : `date DESC, source ASC`), paramètre optionnel `?int $excludeOperationId`. `src/Vehicle/OdometerCalculator.php` : `kmAt()` publique (interpolation, null hors [premier, dernier], un seul point exact à la date accepté) ; l'ancien `kmAt` privé devient `kmAtPoints`.
- `src/Vehicle/FuelConsumptionCalculator.php` (pur) : méthode du plein complet (segments entre pleins complets consécutifs, partiels comptés dans le segment suivant, km d'un plein = saisi sinon interpolé, segment ignoré si km ou distance manquant ; synthèse de période sur les segments s'y terminant).
- `src/Reconciliation/ManualEntryReconciler.php` : `merge()` copie le plein de la saisie sur la ligne si elle n'en a pas (et que la ligne est un débit).
- Tests : `tests/Unit/Vehicle/FuelConsumptionCalculatorTest.php`, `tests/Unit/Vehicle/OdometerCalculatorTest.php` (kmAt), `tests/Unit/Entity/OperationFuelTest.php`, `tests/Functional/Api/FuelApiTest.php`, `tests/Integration/Reconciliation/ManualEntryReconcilerTest.php` (plein suivi au merge), `tests/Integration/Command/SetupCommandTest.php` (flag fuel de « Carburant »).

Choix et écarts par rapport au contrat :
- `PUT`/`DELETE /api/operations/{id}/fuel` répondent `{"operation": Operation}` (DELETE : `fuel: null`), PUT ajoute `"warning"` quand le km est incohérent ; `POST /api/operations` ne renvoie pas de warning (non demandé).
- Au `merge` de doublon, le plein n'est copié que si l'opération conservée est un débit (sinon `recordFuel` lèverait) : un crédit conservé perd le plein du doublon supprimé.
- `GET /api/vehicles/{id}/fuel` : `segmentKm` est un float (interpolation possible), `consumption` arrondi à 2 décimales ; `distanceKm` arrondi à l'entier, `null` sans segment.
- Le CHECK `ck_operation_fuel` n'existe qu'en base (pas dans le mapping) : `doctrine:schema:validate` reste vert.
