# 0006 — Catégorisation locale, LLM optionnel

## Décision
Pipeline appliqué à chaque opération non catégorisée manuellement :
1. **Règles** utilisateur (contient / regex / exact, bornes de montant, compte optionnel, priorité).
2. **Similarité** avec les opérations déjà catégorisées à la main (`pg_trgm` sur le commerçant normalisé).
3. Sinon : « à catégoriser ». Une catégorisation manuelle propose de créer une règle et s'applique aux opérations similaires.

**LLM optionnel** (Claude API, désactivé par défaut, `LLM_ENABLED=1`) : uniquement pour les commerçants inconnus, n'envoie que le *nom de commerçant normalisé* (sans n° de carte, date, montant, ni identité) et la liste des catégories ; résultat proposé comme suggestion, jamais appliqué sans validation ; réponses mises en cache.

## Mise en œuvre (étape 6)

Pipeline `App\Categorization\Categorizer`, appliqué par `app:categorize` et après chaque `app:import` (hors `--dry-run`, option `--no-post-process` pour sauter) :

1. **Règles** activées du foyer, triées `priority` DESC puis `id` ASC ; la première dont `CategorizationRule::matches()` est vrai gagne → source `rule`.
2. **Similarité** `pg_trgm` (SQL natif) : opération du même foyer catégorisée **manuellement**, de **même signe**, la plus similaire — `similarity(merchant, …)` si les deux ont un commerçant, sinon `similarity(normalized_label, …)` ; seuil **0.6** (`--threshold`) → source `similarity`.
3. Sinon : rien ; avec `--reset-unmatched`, une catégorie `rule`/`similarity`/`llm` que plus rien ne correspond est retirée.

Les opérations catégorisées **manuellement** (`source = manual`) et les opérations **liées à un virement interne** ne sont jamais modifiées. Traitement par lots (flush + clear tous les 500). Rapport = compteurs uniquement (`processed`, `byRule`, `bySimilarity`, `unchanged`, `stillUncategorized`) : aucune sortie console n'affiche de libellé ni de numéro de compte.

**Priorités** : règles apprises par catégorisation manuelle (`ManualCategorizer`, merchant exact) = **100** ; règles bancaires sur libellé = **80** ; carburant = **60** ; restaurants = **55** ; enseignes = **50** ; passerelles/achats divers génériques (`PAYPAL`, `AMAZON`, `AMZN`) = **10**.

**Restriction paiements carte / prélèvements** : les règles « enseignes » (`src/Setup/DefaultRules.php`, insérées par `app:rules:seed`, idempotent) sont des regex sur le libellé normalisé de la forme `^(ACHAT CB|[A-Z]+ CARTE|PRELEVEMENT DE)\b.*\b(<enseignes>)\b`. Ainsi un `VIREMENT DE PAUL MARTIN` n'est jamais classé « enseigne » même si un prénom coïncide avec une enseigne. Les libellés normalisés étant repliés en majuscules sans accents, les regex sont évaluées avec les flags `iu`.

**Catégorisation manuelle** (`ManualCategorizer::assign`) : pose la catégorie (source `manual`), peut apprendre une règle (merchant exact, priorité 100) et applique immédiatement cette règle — ou à défaut la même catégorie en source `similarity` — aux autres opérations non manuelles du foyer de même commerçant exact et même signe. `clear()` retire la catégorie manuelle puis re-catégorise automatiquement cette seule opération.

**Virements internes** : source dédiée `CategorizationSource::Transfer` (« Virement interne détecté »), voir ADR 0009.

## Axe véhicule

L'affectation d'une opération à un véhicule est un axe **indépendant de la catégorie**, synchronisé par `App\Vehicle\VehicleAssigner::sync()` après chaque catégorisation (une fois les changements flushés — le `Categorizer` fait des `clear()`, le `VehicleAssigner` travaille donc en DBAL pur, hors UnitOfWork) : post-import (`PostImportProcessor`), `app:categorize`, `POST /api/categorization/run`, catégorisation manuelle (`ManualCategorizer`, point central couvrant opération et commerçant), catégorisation en masse, fusion de doublon et suppression de règle.

Règle appliquée à chaque opération dont `vehicleSource` ≠ `manual` (les affectations manuelles ne sont **jamais** modifiées), décidée par la classe pure `App\Vehicle\VehicleAssignmentPolicy` :

1. la règle appliquée (`appliedRule`) désigne un véhicule ⇒ ce véhicule, source `rule` ;
2. sinon, catégorie `vehicleRelated` (`Category::vehicleRelated`) et **exactement un** véhicule du foyer actif à la date de l'opération (`Vehicle::isActiveOn`, bornes `acquiredAt`/`disposedAt` inclusives) ⇒ ce véhicule, source `auto` ;
3. sinon ⇒ aucune affectation (une ancienne affectation `rule`/`auto` est retirée).

Le traitement est **idempotent**. Efficace sur ~10 000 opérations : une seule lecture scalaire des opérations candidates (catégorie `vehicleRelated`, ou règle appliquée avec véhicule, ou véhicule déjà affecté — hors `manual`), puis `UPDATE … WHERE id IN (…)` groupés par (véhicule, source) et un `UPDATE` de remise à null. Rapport = compteurs uniquement (`processed`, `byRule`, `auto`, `cleared`, `unchanged`) : aucune sortie n'affiche de nom ni de plaque de véhicule.

## Pourquoi
Les données restent locales par défaut ; règles explicables et corrigeables ; le LLM n'est qu'un accélérateur pour l'amorçage.

## Résultats sur données réelles (étape 6)
- 31 règles par défaut ⇒ **46 %** des 4 546 opérations catégorisées automatiquement (2 089), sans aucune catégorisation manuelle.
- Carburant distingué des courses pour une même enseigne : les automates de carburant apparaissent avec le marqueur `DAC` (« CARREFOUR DAC », « E.LECLERC DAC ») ; la règle Carburant (priorité 60) l'emporte sur Courses (50). Résultat : 91 pleins en Carburant, le reste de Carrefour (magasins, drive `CARREFOUR.FR`, `MP*CARREFOUR`) en Courses.
- Formes collées du libellé carte (`CARREFOURMARKE…`) : motif `CARREFOUR\w*`.
- Reste à classer : 2 457 opérations ; **22 commerçants couvrent 50 %** de ce reste, 128 en couvrent 80 % ⇒ la catégorisation manuelle (une fois par commerçant, règle apprise priorité 100) est l'outil principal de l'étape 7.
- Correctif : le `Categorizer` recharge règles et catégories après chaque `clear()` de lot (régression constatée au-delà de 500 opérations, test dédié).
