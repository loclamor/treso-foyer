# Modèle de données

Montants en **centimes** (entiers, négatif = débit, cf. ADR 0004). Numéros de compte jamais stockés en clair : seulement un HMAC (`numberHash`, secret `ACCOUNT_NUMBER_PEPPER`) et un masque (`****R999`).

```mermaid
erDiagram
    Household ||--o{ Member : membres
    Household ||--o{ Account : comptes
    Household ||--o{ Category : categories
    Household ||--o{ CategorizationRule : regles
    Household ||--o{ Budget : budgets
    Household ||--o{ Vehicle : vehicules
    Account }o--o{ Member : titulaires
    Account ||--o{ ImportBatch : imports
    Account ||--o{ Statement : soldes
    Account ||--o{ Operation : operations
    Account ||--o{ RecurringSeries : recurrences
    ImportBatch ||--o| Statement : releve
    ImportBatch ||--o{ Operation : source
    Category ||--o{ Category : enfants
    Category ||--o{ Operation : classe
    CategorizationRule }o--|| Category : cible
    Operation }o--o| CategorizationRule : regle_appliquee
    Operation }o--o| Operation : doublon_de
    Operation }o--o| InternalTransfer : virement_interne
    Operation }o--o| RecurringSeries : serie
    Budget }o--|| Category : poste
    Vehicle ||--o{ OdometerReading : releves_compteur
    Vehicle |o--o{ Operation : affecte
    CategorizationRule }o--o| Vehicle : vehicule
    Member |o--o{ Vehicle : conducteur_principal
```

| Entité | Rôle | Points clés |
|---|---|---|
| `Household` | Foyer (un seul) | racine de tout |
| `Member` | Personne + utilisateur de connexion | `username` unique, mot de passe optionnel jusqu'à l'étape 7 |
| `Account` | Compte bancaire | `alias` unique par foyer, type `personal`/`joint`, titulaires 1..n |
| `ImportBatch` | Un fichier importé | `sha256` unique → un fichier n'est jamais importé deux fois |
| `Statement` | Soldes d'un relevé | ouverture (PDF) / clôture ; sert au contrôle de cohérence et à l'historique de solde |
| `Operation` | Ligne de relevé | `fingerprint` unique (compte, date, montant, libellé normalisé, rang d'occurrence) ; index GIN `pg_trgm` sur `normalized_label` ; statut de doublon probable ; `merchant_group` = clé représentative des variantes du commerçant (ADR 0013) |
| `Category` | Poste de dépense/revenu, arbre à 2 niveaux | `kind` : `expense` / `income` / `transfer` (exclu des dépenses) ; unicité (foyer, parent, nom) avec `NULLS NOT DISTINCT` ; `vehicleRelated` = poste lié à un véhicule (carburant, entretien, assurance auto, péages & parking) |
| `CategorizationRule` | Règle automatique | champ commerçant/libellé, contient/commence par/exact/regex, sens, bornes de montant (valeur absolue), compte optionnel, priorité ; `matches()` pur et testé |
| `InternalTransfer` | Paire de virements entre comptes du foyer | exclue des stats consolidées |
| `Budget` | Budget mensuel d'un poste | portée foyer / personne / compte |
| `RecurringSeries` | Dépense/revenu récurrent détecté (ADR 0012) | unicité (compte, `matchKey` = `coalesce(merchant_group, merchant, normalized_label)`, `direction`) ; fréquence (ajout de `bimonthly`), montant attendu **toujours positif** ± tolérance, `lastOccurrence`/`nextExpected`, `activeMonths` (mois 1..12 actifs ou null), statut `detected`/`confirmed`/`ignored`/`ended` |
| `Vehicle` | Véhicule du foyer | plaque toujours stockée normalisée (SIV `AA-123-AA`, FNI sans séparateurs), unicité (foyer, nom) et (foyer, plaque) ; période de service `acquiredAt`/`disposedAt` (`isActiveOn()`), conducteur principal, données brutes d'une API de plaque (`lookupData`/`lookupAt`) |
| `OdometerReading` | Relevé de compteur d'un véhicule | un relevé par (véhicule, date), `km` ≥ 0 |

## Commandes
- `make sf c='app:setup'` : crée le foyer et l'arbre de 62 catégories par défaut (`src/Setup/DefaultCategories.php`). Idempotente.
- `make sf c='app:member:add <identifiant> "<Nom affiché>"'` : ajoute une personne (mot de passe demandé, ou plus tard).
