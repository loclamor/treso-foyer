# 0016 — AdBlue : journal des remplissages, coût réel ou estimé, consommation

## Contexte
Un diesel récent consomme de l'AdBlue. Il est versé de deux façons : à la pompe en station (payé à part, une ligne de relevé), ou depuis un **bidon** acheté en grande surface, le plus souvent dans le ticket de courses (pas de ligne de relevé à lui) et versé en une ou plusieurs fois, plus tard. Le modèle des pleins de carburant ([ADR 0015](0015-pleins-carburant.md)) — volume porté par l'opération du paiement — ne couvre pas le bidon : le geste (remplir) et l'achat sont découplés.

## Décision
- **Journal par véhicule** : entité `AdblueFill` (table `adblue_fill`) — `date`, `volumeMl` (> 0, millilitres comme les pleins), `odometerKm` (facultatif), `source` (`station` / `can`), `note`. Suppression du véhicule ⇒ suppression des remplissages (comme les relevés).
- **Station** : `operation` = la ligne de relevé du paiement (débit du foyer, au plus un remplissage par opération, facultative tant que le relevé n'est pas importé). Coût **réel** = montant de l'opération. Lier l'opération l'affecte au véhicule (affectation manuelle, sauf affectation manuelle existante) : elle entre dans les dépenses du véhicule comme toute opération. Catégorie par défaut « Transport › AdBlue » (liée à un véhicule), posée par `app:setup` et la migration ; la catégorisation reste celle de l'utilisateur (rien n'est forcé au lien).
- **Bidon** : `canPrice` (centimes) + `canVolumeMl` (contenance), ensemble ou pas du tout. Coût **estimé** = prix × volume versé ÷ contenance. Il est affiché à part et **jamais ajouté aux dépenses du véhicule** : l'achat est déjà compté dans les courses côté banque (sinon double comptage). Le front pré-remplit le dernier bidon saisi.
- **Kilométrage = point de compteur** : un remplissage avec `odometerKm` rejoint les relevés et les pleins dans `OdometerReadingRepository` (priorité à date égale : relevé, puis plein, puis AdBlue) ; avertissement d'incohérence comme un relevé.
- **Consommation** (`App\Vehicle\AdblueConsumptionCalculator`, calcul pur) : les remplissages au bidon sont partiels, pas de méthode du plein complet. Consommation = Σ volumes des remplissages datés dans ]coveredFrom, coveredTo] ÷ km parcourus sur cette fenêtre (la même que le coût au km : période ∩ points de compteur), en **L/1000 km**. Le remplissage du jour de coveredFrom est exclu (il compense ce qui a été consommé avant). Approximation fiable sur une période longue.
- **Fusion d'opérations** : rapprochement d'une saisie ([ADR 0014](0014-saisie-manuelle-rapprochement.md)) et doublon « même opération » reportent le lien sur l'opération conservée (si elle n'en a pas déjà un).

## Conséquences
- Un remplissage en station payé avec le gasoil (une seule ligne) ne peut pas avoir de coût réel : le saisir sans opération, ou au bidon avec un prix estimé.
- Le coût des bidons n'apparaît que dans la section AdBlue de la fiche véhicule, pas dans le coût au km du véhicule.
- La section AdBlue de la fiche véhicule s'affiche pour un véhicule diesel, ou dès qu'un remplissage existe.
- Schéma : table `adblue_fill` (clé étrangère `operation_id` unique, `ON DELETE SET NULL`), contrainte `CHECK ck_adblue_fill` (SQL brut, hors mapping) : volume > 0, km ≥ 0, prix et contenance du bidon ensemble et > 0, opération seulement en station, bidon seulement au bidon.
