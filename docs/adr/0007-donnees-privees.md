# 0007 — Données bancaires hors git et hors images

## Décision
- Relevés, exports et base : dans `data/` (ignoré par git via `.gitignore`, exclu des images via `.dockerignore`).
- Numéros de compte jamais affichés en clair (masqués `****R999`) ; correspondance numéro → alias dans `data/comptes.ini`.
- Fixtures de test **anonymisées** et fabriquées à la main.
- Sauvegardes `pg_dump` dans `data/backups/`.

## Conséquences
Le dépôt git peut être poussé sans risque ; la sauvegarde de `data/` est à la charge de l'utilisateur (disque externe, etc.).
