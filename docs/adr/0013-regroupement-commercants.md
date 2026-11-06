# 0013 — Regroupement des variantes de commerçant

## Contexte
La clé « commerçant » d'une opération est `coalesce(merchant, normalized_label)`. Un même commerçant apparaît sous plusieurs libellés : export CSV et relevé PDF ne découpent pas le libellé de la même façon (`MP*CARREFOUR D` / `MP CARREFOUR D`, `EDF CLIENTS PARTI IERS` / `EDF CLIENTS PARTICUL IERS`, troncatures), et le format des relevés a changé au fil des années. Conséquences : doublons dans le top commerçants, séries récurrentes scindées, paiement du mois (CSV) non rattaché à sa série (PDF).

## Décision
- **Colonne `operation.merchant_group`** : clé représentative du groupe de variantes (la clé la plus fréquente, puis la plus longue), `NULL` pour un commerçant sans variante. Clé effective : `coalesce(merchant_group, merchant, normalized_label)`.
- **Calcul** (`App\Merchant\MerchantGrouper`, pur + synchronisation DBAL idempotente), règles volontairement prudentes — des commerçants distincts aux libellés proches ne doivent pas fusionner :
  - même forme compacte (majuscules, lettres et chiffres seulement) ⇒ même groupe (ponctuation, espaces) ;
  - même premier mot, forme compacte ≥ 12 caractères, préfixe d'**une seule** autre forme ⇒ même groupe (troncature) ; une clé préfixe de plusieurs libellés est générique (« LA BANQUE POSTALE » → crédit, assurance…) et ne regroupe rien ;
  - même premier mot, formes compactes ≥ 16 caractères, similarité trigramme ≥ 0.8 ⇒ même groupe (mot coupé) ; la longueur minimale écarte les libellés courts distincts (« PHARMACIE DU L » / « PHARMACIE DU G »).
- **Utilisation** : top commerçants (`/api/stats/merchants`), filtre `merchant` des opérations (clé brute **ou** clé de groupe), détection des récurrences (`matchKey`). Les séries indexées sur une variante sont ré-indexées sur la clé du groupe (statut et corrections conservés).
- **Non utilisé** par l'écran « À catégoriser » ni par les règles : une règle porte sur le commerçant exact de l'opération.
- **Déclenchement** : après chaque import réel (avant la détection des récurrences) et `app:merchants:group [--dry-run]` ; compteurs uniquement (ADR 0007).

## Conséquences
- Le libellé affiché d'un groupe est celui de la variante la plus fréquente.
- Les fusions manquées (variantes trop courtes ou trop différentes) restent séparées ; les regroupements manuels ne sont pas prévus à ce stade.
