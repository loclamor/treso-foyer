# 0009 — Modèle foyer multi-comptes

## Contexte
Trois comptes La Banque Postale à suivre : deux comptes personnels et un compte commun. Les virements de chaque compte perso vers le commun apparaissent sur deux comptes.

## Décision
- Entités `Household` → `Member` (aussi utilisateur de connexion) → `Account` (titulaires 1..n, type perso/commun). Compte détecté automatiquement depuis le fichier importé.
- **Virements internes** appariés (montants opposés, ±3 jours, deux comptes du foyer) dans `InternalTransfer`, exclus des statistiques consolidées du foyer, affichés comme « contributions » en vue compte/personne.
- Statistiques, budgets et prévisions à trois niveaux : compte, personne, foyer.

## Algorithme d'appariement (étape 6)

`App\Categorization\InternalTransferMatcher` (`app:transfers:match [--dry-run] [--days=3]`, aussi enchaîné après chaque `app:import`) :

- Une requête SQL unique (jointure `operation` débit × `operation` crédit sur `c.amount = -d.amount`, comptes du même foyer et différents, fenêtre `d.date` à `± days` de `c.date`) produit toutes les paires candidates — pas de boucle N² en PHP.
- Sont exclues les opérations déjà liées (`internal_transfer_id IS NOT NULL`) et celles catégorisées manuellement dans une autre catégorie que « Mouvements internes › Virements entre comptes du foyer ».
- **Ambigüité** : si une opération a plusieurs candidats, on ne retient que les libellés commençant par `VIREMENT` ; s'il en reste plusieurs (ou aucun filtre ne tranche), la paire est comptée `ambiguous` et non appariée. Un crédit convoité par plusieurs débits rend les paires concernées ambigües.
- Paire retenue : `InternalTransfer(status = auto)`, les deux opérations liées et catégorisées « Mouvements internes › Virements entre comptes du foyer » avec la source `transfer`. `unlink()` défait la paire (opérations décatégorisées si source `transfer`).

## Conséquences
Pas de double comptage au niveau foyer ; un virement dont la contrepartie n'est pas encore importée reste « en attente » jusqu'au prochain import.
