# Banque — suivi des dépenses du foyer

Application personnelle de suivi des dépenses à partir des relevés La Banque Postale (PDF et CSV) :
import sans doublons, catégorisation (règles + apprentissage), statistiques, récurrences, budgets, prévisions.
Plusieurs comptes (perso ×2 + commun) consolidés au niveau du foyer.

Choix techniques : voir [`docs/adr/`](docs/adr/README.md).

## Prérequis
- Docker + Docker Compose v2
- Python 3 et `pdftotext` (poppler-utils) sur l'hôte, uniquement pour l'outil de rangement des fichiers

## Démarrage
```bash
make start          # build + up
```
- Application : <https://localhost:8543> (certificat local à accepter)
- Serveur Vite (dev, rechargement à chaud) : lancé automatiquement par le conteneur `node` sur <http://localhost:5173>
- Ports modifiables via variables d'environnement : `HTTP_PORT` (8180), `HTTPS_PORT` (8543)
- `make help` liste toutes les commandes

### Clé PrimeVue
PrimeVue 5 demande une clé de licence (gratuite pour un particulier, licence Community : <https://primeui.dev/licenses/community>).
Créer `.env.local` à la racine (non versionné) :
```
VITE_PRIMEUI_LICENSE=<ta-clé>
```
puis `docker compose restart node`. Sans clé, l'appli fonctionne mais affiche un bandeau de licence.

### Initialisation
```bash
make sf c='app:setup'                                   # foyer + catégories par défaut (idempotent)
make sf c='app:member:add <identifiant> "<Nom affiché>"' # une fois par personne du foyer
```
`.env.local` doit définir `ACCOUNT_NUMBER_PEPPER` (secret aléatoire, `openssl rand -hex 32`), **à ne plus changer** une fois des comptes importés.
Modèle de données : [`docs/modele-donnees.md`](docs/modele-donnees.md).

### Se connecter
Chaque personne du foyer a un identifiant de connexion (le mot de passe est demandé de façon masquée) :
```bash
docker compose exec php bin/console app:member:add <identifiant> "<Nom affiché>"
```
Puis <https://localhost:8543> → page de connexion.

## Interface
| Écran | Usage |
|---|---|
| Accueil | période au choix ; dépenses, revenus, solde net, taux d'épargne (vs période précédente, moyenne 12 mois) ; dépenses mensuelles par poste + revenus ; répartition par poste (clic ⇒ sous-catégories ⇒ opérations) ; solde reconstitué ; compteurs |
| Statistiques | comparaison par poste (période précédente / année précédente), top commerçants, flux revenus → postes (Sankey), calendrier des dépenses, évolution d'un poste (moyenne mobile 3 mois), solde quotidien — chaque graphique a une vue « Tableau » |
| Opérations | recherche et filtres (période, catégorie, texte, montant, sens, source, doublons), totaux, catégorisation d'une opération ou de tout un commerçant, catégorisation de la sélection, notes |
| À catégoriser | opérations non classées regroupées par commerçant (nombre, total, fourchette de montants, période, suggestion) ; « Classer » crée une règle pour le commerçant ; « Voir les opérations » pour classer individuellement un commerçant mixte (ex. courses et carburant au même magasin) |
| Imports | glisser-déposer de relevés PDF / exports CSV, rapport par fichier, historique |
| Réglages | catégories (arbre, couleurs, **couleur des graphiques** : 8 emplacements), règles (avec aperçu), comptes (alias, titulaires) ; contrôles : doublons probables, virements internes |

Le sélecteur en haut à droite restreint toutes les vues au **foyer**, à **une personne** ou à **un compte**.

## Front
SPA Vue 3 + PrimeVue + ECharts dans `assets/` (voir [ADR 0008](docs/adr/0008-front-vue-primevue.md)), API JSON Symfony sous `/api` (contrat : [`docs/api.md`](docs/api.md)).
- `make front-logs` : logs du serveur Vite
- `make npm c='install <paquet>'` : ajouter une dépendance
- `make front-build` : build de production dans `public/build/` (à relancer `docker compose restart node` ensuite pour revenir au mode dev)

## Arborescence
```
data/                 # données privées (hors git, hors images)
  inbox/              # déposer ici les nouveaux relevés PDF / exports CSV
  releves/<alias>/    # relevés rangés : <AAAA>/<AAAA-MM>_releve.pdf, csv/, frais/
  comptes.ini         # numéro de compte -> alias
  _doublons/          # fichiers écartés car déjà présents
  backups/            # dumps de la base (make backup)
docs/adr/             # décisions techniques
tools/                # outils hôte (rangement des fichiers)
assets/               # front Vue (src/main.js, App.vue, router, stores, views, components)
src/Controller/Api/   # API JSON
src/, templates/, …   # application Symfony (templates/spa.html.twig = coquille du front)
```

## Ajouter de nouveaux relevés
1. Télécharger les relevés (PDF) ou exports (CSV) depuis l'espace client, les déposer dans `data/inbox/`.
2. `make ranger` (simulation) puis `make ranger-apply`.
3. Import dans l'application : voir « Importer des relevés » ci-dessous.

## Importer des relevés
```bash
make sf c='app:import data/releves --dry-run'                 # simulation : rien n'est écrit
make sf c='app:import data/releves'                           # import réel
make sf c='app:import data/releves --auto-create-account'     # au premier import d'un compte inconnu
```
- Chemins : fichiers ou dossiers (parcours récursif des `.csv` et `.pdf`, triés par nom). Les PDF sont lus via `pdftotext -layout` (voir [ADR 0003](docs/adr/0003-import-pdf-pdftotext.md)) ; un relevé PDF crée un relevé (`Statement`) avec soldes d'ouverture et de clôture.
- Les relevés annuels de frais (PDF « Décompte des frais », sans opérations) sont **ignorés** : statut « ignoré (pas un relevé d'opérations) », sans compter comme échec.
- Le premier import d'un compte inconnu échoue ; relancez avec `--auto-create-account` : le compte est créé (alias lu dans `data/comptes.ini` s'il y est, sinon `compte-xxxx`), son numéro n'est jamais stocké en clair (seul un masque `****R999` est affiché).
- Un fichier déjà importé (même contenu) est ignoré (`already_imported`) ; les opérations déjà connues sont comptées en « déjà connues » ; les doublons probables (même montant, date à ±1 jour, libellé similaire, seuil `--threshold=0.45` par défaut) sont marqués à valider, jamais supprimés automatiquement.
- Les relevés PDF et les exports CSV peuvent se chevaucher, dans n'importe quel ordre : une opération connue n'est jamais dupliquée, et un PDF remplace le libellé d'une opération venue d'un CSV par son libellé complet (colonne « Enrichies »), sans toucher à sa catégorie.
- Voir [ADR 0005](docs/adr/0005-dedoublonnage.md) pour la stratégie de dédoublonnage.
- Après un changement de normalisation des libellés (ex. clé passée à 24 caractères), recalculer les empreintes existantes :
  ```bash
  make sf c='app:operations:rebuild-fingerprints --dry-run'   # simulation
  make sf c='app:operations:rebuild-fingerprints --renormalize' # recalcule empreintes + libellés normalisés
  ```

## Catégoriser
```bash
make sf c='app:setup'                 # foyer + catégories + règles par défaut (idempotent)
make sf c='app:rules:seed'            # (re)insère les règles par défaut seules (idempotent)
make sf c='app:categorize'            # catégorise les opérations non catégorisées (règles + similarité)
make sf c='app:categorize --all'      # re-catégorise toutes les opérations non manuelles
make sf c='app:categorize --dry-run --threshold=0.6 --reset-unmatched --account=ID'
make sf c='app:transfers:match'       # apparie les virements internes entre comptes du foyer (±3 jours)
```
- Pipeline (voir [ADR 0006](docs/adr/0006-categorisation.md)) : règles du foyer (priorité décroissante), sinon similarité `pg_trgm` (seuil 0.6) avec les opérations catégorisées manuellement de même signe. Les opérations manuelles et les virements internes appariés ne sont jamais modifiés. Les sorties n'affichent que des compteurs.
- `app:import` enchaîne automatiquement l'appariement des virements internes puis la catégorisation des opérations non catégorisées (et des opérations enrichies non manuelles) après un import réel ; `--no-post-process` désactive ce post-traitement.
- Les virements internes appariés (montants opposés, ±3 jours, deux comptes du foyer) sont catégorisés « Mouvements internes › Virements entre comptes du foyer » (source « Virement interne détecté »), voir [ADR 0009](docs/adr/0009-multi-comptes-foyer.md).

## Tests
```bash
make test
```
