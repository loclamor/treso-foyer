# Mise en route et utilisation au quotidien

Guide pratique pour initialiser l'application et s'en servir ensuite. Pour le déploiement
sur un serveur, voir `DEPLOY.md` ; pour le détail technique, voir `README.md` et `docs/`.

## 1. Première mise en route (poste local)

```bash
make start          # build + démarre la stack (Docker requis)
```

- Application : https://localhost:8543 (accepter le certificat local auto-signé).
- Avant le premier démarrage, créer `.env.local` (non versionné) :
  - `ACCOUNT_NUMBER_PEPPER=$(openssl rand -hex 32)` — **ne plus jamais changer** après le premier import ;
  - `VITE_PRIMEUI_LICENSE=<clé>` — licence Community gratuite PrimeVue (sans clé, un bandeau s'affiche).

Initialisation (une fois) :

```bash
make sf c='app:setup'                                    # crée le foyer + catégories par défaut (idempotent)
make sf c='app:member:add <identifiant> "<Nom affiché>"'  # une fois par personne du foyer
```

Puis se connecter sur https://localhost:8543 avec son identifiant (mot de passe demandé de façon masquée).

## 2. Premier import de relevés

1. Télécharger les relevés PDF ou exports CSV depuis l'espace client La Banque Postale.
2. Les déposer dans `data/inbox/`, puis ranger : `make ranger` (simulation) puis `make ranger-apply`
   (tournent dans le conteneur `php` : `python3` et `pdftotext` y sont embarqués, rien à installer sur le poste).
   Les fichiers sont déplacés dans `data/releves/<alias>/<AAAA>/…`. L'alias se règle dans `data/comptes.ini`.
3. Importer :

```bash
make sf c='app:import data/releves --dry-run'                 # simulation, rien n'est écrit
make sf c='app:import data/releves'                           # import réel
make sf c='app:import data/releves --auto-create-account'     # nécessaire au premier import d'un compte inconnu
```

- Un fichier déjà importé est ignoré ; les opérations connues ne sont jamais dupliquées.
- Les doublons probables sont marqués « à valider », jamais supprimés automatiquement.
- Après chaque import réel : appariement des virements internes, catégorisation, détection des
  récurrences et regroupement des commerçants se lancent automatiquement.

## 3. Utilisation au quotidien

Le sélecteur en haut à droite restreint toutes les vues au **foyer**, à **une personne** ou à **un compte**.

| Écran | À quoi ça sert |
|---|---|
| Accueil | Période au choix : dépenses, revenus, solde net, taux d'épargne ; répartition par poste ; solde reconstitué ; alerte « saisies sans relevé depuis plus de 15 jours » ; bouton « Saisir une dépense » |
| Opérations | Recherche et filtres (période, catégorie, texte, montant, sens, doublons, en attente) ; catégoriser une opération ou un commerçant entier ; notes |
| À catégoriser | Opérations non classées regroupées par commerçant, avec suggestion ; « Classer » crée une règle pour ce commerçant |
| Imports | Glisser-déposer de relevés PDF / exports CSV, rapport par fichier, historique |
| Récurrences | Séries détectées automatiquement (loyer, abonnements, salaire…) : fréquence, montant, prochaine échéance ; corrections possibles |
| Statistiques | Comparaisons par poste, top commerçants, flux revenus → postes (Sankey), calendrier, évolution d'un poste, solde quotidien (chaque graphique a une vue « Tableau ») |
| Réglages | Catégories (arbre, couleurs), règles de catégorisation (avec aperçu), comptes (alias, titulaires), véhicules, virements internes à valider |

### Saisir une dépense tout de suite

Bouton « Saisir une dépense » (ou `POST /api/operations`) : la dépense pèse immédiatement sur le
budget du mois. À l'import du relevé contenant la ligne, la saisie est **rapprochée automatiquement**
(montant exact, fenêtre −2/+10 jours) : catégorie, véhicule, note et date réelle transférés sur la
ligne du relevé, la saisie disparaît. Une saisie ambiguë reste « en attente » (rapprochement manuel
avec candidats proposés).

### Véhicules

Dans Réglages › Véhicules : créer les véhicules (plaques, dates), saisir les relevés kilométriques.
Les opérations des catégories « liées à un véhicule » (carburant, entretien, assurance, péages) y
sont affectées automatiquement quand un seul véhicule est actif à leur date. Sur un plein, renseigner
volume + kilométrage : prix au litre et consommation (L/100 km) sont déduits. Section AdBlue pour les
diesel.

## 4. Tâches régulières (CLI)

La plupart se lancent seules après chaque import, mais restent disponibles à la main :

```bash
make sf c='app:categorize'              # catégorise les opérations non classées
make sf c='app:transfers:match'         # apparie les virements entre comptes du foyer (±3 jours)
make sf c='app:recurrences:detect'      # détecte les séries récurrentes (historique 36 mois)
make sf c='app:merchants:group'         # regroupe les variantes de libellé d'un commerçant
make sf c='app:entries:reconcile'       # rapproche les saisies en attente des relevés importés
```

Ajouter `--dry-run` à ces commandes pour simuler sans écrire.

## 5. Maintenance

```bash
make backup        # dump de la base dans data/backups/banque-<date>.sql.gz
make test          # tests PHPUnit ; make test-front (Vitest) ; make lint (PHPStan + ESLint)
```

- Ne jamais changer `ACCOUNT_NUMBER_PEPPER` une fois des comptes importés.
- Sauvegarder `data/` (relevés, backups) et le volume `database_data` hors du serveur si possible.
- Voir `DEPLOY.md` pour la procédure de mise à jour d'une instance déployée.
