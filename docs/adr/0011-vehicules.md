# 0011 — Véhicules : axe d'analyse, plaque, recherche externe

## Contexte
Suivre le coût de chaque véhicule du foyer (carburant, entretien, assurance, péages) et le coût au km. Les catégories `Transport › …` disent *quoi* a été payé, pas *pour quel véhicule*.

## Décision
- **Axe indépendant de la catégorie** : `Operation.vehicle` (+ `vehicleSource` : `manual` / `rule` / `auto` / `ignored`) plutôt que des sous-catégories par véhicule. L'arbre des catégories et les statistiques par poste restent inchangés ; un véhicule se rajoute sans toucher aux catégories ni aux règles.
- **Affectation** (`App\Vehicle\VehicleAssigner`, voir aussi [ADR 0006](0006-categorisation.md)) : règle désignant un véhicule, sinon catégorie `vehicleRelated` + véhicule unique actif à la date (`acquiredAt`/`disposedAt`), sinon rien. Les affectations manuelles ne sont jamais modifiées ; synchronisation idempotente relancée après toute catégorisation et toute modification de véhicule, règle ou catégorie.
- **Kilométrage** : relevés saisis à la main (`OdometerReading`) ; distance d'une période par interpolation linéaire, limitée à l'intervalle couvert par les relevés (pas d'extrapolation) ; coût au km calculé sur ce même intervalle.
- **Usage et financement séparés** : une catégorie peut porter le drapeau `financing` (par défaut « Transport › Crédit véhicule », liée à un véhicule). Ses opérations sont rattachées au véhicule et comptées dans son coût total, mais exclues du **coût d'usage** : `costPerKm` = usage seul, `costPerKmTotal` = usage + financement. Une mensualité est un coût fixe : mêlée au coût au km, elle le rendrait dépendant du kilométrage du mois (un mois peu roulé paraîtrait très cher). La mensualité est comptée entière (capital + intérêts) : séparer le capital — paiement étalé du véhicule — des intérêts demanderait le tableau d'amortissement, et n'aurait de sens qu'avec une valeur d'achat ou une décote, non suivies.
- **Plaque stockée en clair**, normalisée (`AA-123-AA` ou format FNI). Elle n'est jamais écrite dans les logs, sorties de commande ni messages d'erreur ; fixtures de test fictives ([ADR 0007](0007-donnees-privees.md)).
- **Recherche par plaque optionnelle** (`VehicleLookup`) : désactivée par défaut (`VEHICLE_LOOKUP_PROVIDER=none`). Fournisseur prévu : « API Plaque Immatriculation France » via RapidAPI (`rapidapi`, clé dans `.env.local`). L'API Particulier (ANTS) est réservée aux administrations habilitées. La plaque n'est envoyée qu'après un clic explicite et une confirmation ; le résultat est une suggestion appliquée par l'utilisateur ; la réponse brute est conservée (`lookupData`) pour ne pas repayer l'appel.

## Conséquences
- Une opération de carburant payée par une carte commune à plusieurs véhicules actifs reste non affectée : compteur « à affecter » et affectation manuelle (unitaire ou en masse).
- Une opération impossible à affecter (historique ancien, véhicule inconnu) peut être marquée « ignorée » (`vehicleSource: ignored`, sans véhicule) : elle sort du compteur « à affecter » et des « non affectées » des statistiques, et la synchronisation ne la touche plus ; « Aucun » la réactive.
- Le format de réponse du fournisseur n'est pas documenté publiquement : le mappage est défensif et devra être vérifié au premier appel réel.
- Envoyer une plaque à un tiers (fournisseur + RapidAPI) est un choix assumé, limité au clic « Pré-remplir ».
