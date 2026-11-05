# 0002 — PostgreSQL 17 avec `pg_trgm`

## Contexte
Besoins : retrouver des opérations aux libellés *proches* (suggestion de catégorie, doublons CSV/PDF aux libellés tronqués différemment), statistiques par période, détection de récurrences.

## Décision
PostgreSQL 17 (image `postgres:17-alpine`) avec l'extension **`pg_trgm`** (similarité par trigrammes, index GIN) et usage des CTE / window functions pour les stats.

## Alternatives écartées
- MySQL/MariaDB : pas d'équivalent natif simple à `similarity()` ; window functions OK mais moins riches.
- SQLite : suffisant en volume mais pas de `pg_trgm`, et moins proche des habitudes Doctrine/Postgres.
- Moteur de recherche externe (Meilisearch…) : surdimensionné.

## Conséquences
- Extension créée par une migration Doctrine (`CREATE EXTENSION IF NOT EXISTS pg_trgm`).
- Requêtes de similarité en SQL natif (DBAL) dans des repositories dédiés.
