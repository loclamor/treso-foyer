# Architecture Decision Records

Chaque décision technique structurante est consignée ici (format court : contexte, décision, conséquences).

| N° | Décision | Statut |
|----|----------|--------|
| [0001](0001-stack-symfony-frankenphp-docker.md) | Stack Symfony 7.4 LTS + FrankenPHP + Docker Compose (+ node pour Vite) | Acceptée |
| [0002](0002-postgresql-pg-trgm.md) | PostgreSQL 17 avec `pg_trgm` | Acceptée |
| [0003](0003-import-pdf-pdftotext.md) | Lecture des PDF avec poppler `pdftotext -layout` | Acceptée |
| [0004](0004-montants-en-centimes.md) | Montants stockés en centimes (entiers) | Acceptée |
| [0005](0005-dedoublonnage.md) | Dédoublonnage des imports à 3 niveaux | Acceptée |
| [0006](0006-categorisation.md) | Catégorisation locale, LLM optionnel | Acceptée |
| [0007](0007-donnees-privees.md) | Données bancaires hors git et hors images | Acceptée |
| [0008](0008-front-vue-primevue.md) | Front SPA Vue 3 + PrimeVue + ECharts, API JSON | Acceptée |
| [0009](0009-multi-comptes-foyer.md) | Modèle foyer multi-comptes, virements internes | Acceptée |
| [0010](0010-visualisation.md) | Visualisation : ECharts, couleurs par catégorie (8 emplacements validés), vue tableau | Acceptée |
| [0011](0011-vehicules.md) | Véhicules : axe d'analyse indépendant, plaque, recherche externe optionnelle | Acceptée |
| [0012](0012-recurrences.md) | Récurrences : détection automatique (dépenses/revenus), séries gérées, prévision du mois en cours | Acceptée |
| [0013](0013-regroupement-commercants.md) | Regroupement des variantes de commerçant (CSV/PDF, ponctuation, troncature) | Acceptée |
| [0014](0014-saisie-manuelle-rapprochement.md) | Saisie manuelle de dépenses + rapprochement avec les relevés (deux dates, appariement univoque) | Acceptée |
