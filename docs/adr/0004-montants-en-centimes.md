# 0004 — Montants stockés en centimes

## Décision
Tous les montants sont des entiers signés en centimes (`bigint`, `int` PHP). Négatif = débit. Conversion uniquement aux frontières (parsing `-27,56` → `-2756`, affichage via un filtre Twig).

## Pourquoi
Pas d'erreur d'arrondi flottant ; sommes et contrôles de solde exacts ; empreinte de dédoublonnage stable.
