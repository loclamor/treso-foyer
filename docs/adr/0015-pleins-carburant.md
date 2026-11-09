# 0015 — Pleins de carburant : volume, prix au litre, kilométrage, consommation

## Contexte
Une opération Carburant ne porte qu'un montant et un véhicule ([ADR 0011](0011-vehicules.md)). Pour suivre le prix au litre et la consommation (L/100 km), il faut saisir le volume du plein et, si possible, le kilométrage au compteur, à la saisie manuelle ([ADR 0014](0014-saisie-manuelle-rapprochement.md)) comme à la catégorisation d'une ligne de relevé. L'utilisateur fait presque toujours le plein complet, sauf quand le plafond de carte est atteint.

## Décision
- **Données du plein sur `Operation`** (pas d'entité séparée : une opération = au plus un plein) :
  - `fuelVolumeMl` (entier > 0, millilitres — entiers comme les montants, [ADR 0004](0004-montants-en-centimes.md)) ;
  - `fuelFullTank` (booléen, **vrai par défaut**) — non nul si et seulement si `fuelVolumeMl` est non nul ;
  - `odometerKm` (entier ≥ 0, optionnel) — kilométrage au compteur lors du plein ; n'existe qu'avec un volume.
  - Le **prix au litre** n'est pas stocké : `|amount| / volume`, calculé à la présentation (`pricePerLiter`, euros, 3 décimales). Côté front, l'utilisateur saisit deux grandeurs parmi montant / volume / prix au litre, la troisième est déduite ; sur une ligne de relevé le montant est fixé.
  - Un plein n'est possible que sur un **débit**.
- **Catégorie carburant** : nouveau drapeau `Category.fuel` (comme `vehicleRelated`), qui fait apparaître les champs du plein dans le front. Posé sur la sous-catégorie « Carburant » par `app:setup` et par la migration (catégories nommées « Carburant » et `vehicleRelated`) ; modifiable dans l'écran Catégories. Le back n'exige pas la catégorie (le drapeau ne pilote que l'affichage).
- **Kilométrage d'un plein = point de compteur** : `OdometerReadingRepository::pointsByVehicleIds()` / `latestByVehicleIds()` fusionnent les relevés `odometer_reading` et les pleins (`vehicle_id` non nul, `odometer_km` non nul, date = date effective `COALESCE(spent_at, date)`). À date égale, le relevé saisi prime (il est listé en premier ; `OdometerCalculator` garde le premier point d'une date). Aucune ligne `odometer_reading` n'est créée : changer le véhicule ou supprimer l'opération déplace ou retire le point sans synchronisation. La distance, le coût au km et le dernier kilométrage connu en profitent directement.
- **Consommation, méthode du plein complet** (`App\Vehicle\FuelConsumptionCalculator`, calcul pur) : pleins du véhicule triés par date effective puis id. Pour chaque plein complet `Pj` précédé d'un plein complet `Pi`, segment = volumes des pleins `i+1..j` (partiels compris) ÷ (km(`Pj`) − km(`Pi`)) × 100. km d'un plein = son `odometerKm`, sinon interpolé sur les points de compteur (`OdometerCalculator::kmAt`, null hors de l'intervalle couvert). Segment ignoré si une borne n'a pas de km ou si la distance est ≤ 0. Un plein complet à moins de 50 km du précédent (remise à niveau) ne ferme pas de segment : son volume passe dans le segment suivant, pour éviter une consommation aberrante. Les pleins antérieurs au premier plein complet ne comptent pas. Consommation d'une période = Σ volumes ÷ Σ km des segments **se terminant** dans la période (le plein complet précédent peut être antérieur).
- **API** : `PUT /api/operations/{id}/fuel` `{volumeMl, fullTank?=true, odometerKm?}` (remplace), `DELETE /api/operations/{id}/fuel`, champs optionnels `fuelVolumeMl`/`fuelFullTank`/`odometerKm` dans `POST /api/operations`, objet `fuel` dans la présentation d'une opération, `GET /api/vehicles/{id}/fuel?from&to` (pleins + synthèse). Un kilométrage incohérent avec les points voisins renvoie un `warning` (pas une erreur), comme un relevé.
- **Rapprochement / doublons** : `ManualEntryReconciler::merge()` copie le plein de la saisie sur la ligne du relevé si celle-ci n'en a pas ; la résolution d'un doublon (`POST /api/operations/{id}/duplicate`, « même opération ») fait de même vers l'opération conservée.

## Conséquences
- Un plein sans kilométrage reste utile (prix au litre, volume) et entre dans la consommation dès que des relevés encadrent sa date.
- Un plein partiel (plafond de carte) décoché « plein complet » n'ouvre ni ne ferme un segment : son volume est compté dans le segment suivant.
- Les pleins d'une opération sans véhicule sont conservés mais n'entrent dans aucune statistique véhicule tant qu'elle n'est pas affectée.
- Schéma : colonnes `operation.fuel_volume_ml`, `operation.fuel_full_tank`, `operation.odometer_km`, `category.fuel` ; contrainte `CHECK` de cohérence volume / plein complet / kilométrage.
