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
- Écoute limitée à `127.0.0.1` par défaut (l'appli tourne en `dev`, profiler compris) ; `BIND_ADDRESS=0.0.0.0` pour l'exposer au réseau local, à éviter hors cible `prod`
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
`.env.local` doit définir `ACCOUNT_NUMBER_PEPPER` (secret aléatoire, `openssl rand -hex 32`), **à ne plus changer** une fois des comptes importés. La valeur par défaut versionnée est refusée au démarrage. Pour la cible prod (`compose.prod.yaml`), `APP_SECRET`, `POSTGRES_PASSWORD` et `CADDY_MERCURE_JWT_SECRET` sont obligatoires.
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
| Accueil | période au choix ; dépenses, revenus, solde net, taux d'épargne (vs période précédente, moyenne 12 mois) ; dépenses mensuelles par poste + revenus ; répartition par poste (clic ⇒ sous-catégories ⇒ opérations) ; solde reconstitué ; compteurs ; **saisies sans relevé depuis plus de 15 jours** (widget d'alerte, ADR 0014) ; bouton « Saisir une dépense » |
| Statistiques | comparaison par poste (période précédente / année précédente), top commerçants, flux revenus → postes (Sankey), calendrier des dépenses, évolution d'un poste (moyenne mobile 3 mois), solde quotidien — chaque graphique a une vue « Tableau » |
| Opérations | recherche et filtres (période, catégorie, texte, montant, sens, source, doublons, **en attente de relevé**), totaux, catégorisation d'une opération ou de tout un commerçant, catégorisation de la sélection, notes ; **bouton « Saisir une dépense »** (saisie manuelle, rapprochée automatiquement du relevé à l'import, ADR 0014) ; sur une saisie en attente : modifier / rapprocher / supprimer |
| À catégoriser | opérations non classées regroupées par commerçant (nombre, total, fourchette de montants, période, suggestion) ; « Classer » crée une règle pour le commerçant ; « Voir les opérations » pour classer individuellement un commerçant mixte (ex. courses et carburant au même magasin) |
| Imports | glisser-déposer de relevés PDF / exports CSV, rapport par fichier, historique |
| Récurrences | séries détectées automatiquement (dépenses et revenus récurrents : loyer, abonnements, salaire…) : statut, fréquence, montant attendu, prochaine échéance, opérations liées ; corrections manuelles possibles |
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

## Saisir une dépense manuellement
Une dépense par carte peut être saisie dès qu'elle a lieu (bouton « Saisir une dépense », ou `POST /api/operations`) pour qu'elle pèse tout de suite sur le budget du mois. Quand le relevé contenant la ligne correspondante est importé, la saisie est **rapprochée automatiquement** (montant exact, fenêtre −2/+10 jours, paire univoque) : la ligne du relevé est conservée et reçoit catégorie, véhicule, note et date réelle de la saisie ; la saisie disparaît (voir [ADR 0014](docs/adr/0014-saisie-manuelle-rapprochement.md)). Une saisie ambiguë reste en attente et peut être rapprochée à la main (candidats proposés, les valeurs du relevé font foi). Les saisies en attente depuis plus de 15 jours remontent en alerte sur l'accueil.
- La date réelle de la dépense (`spentAt`) est extraite du libellé CB (`ACHAT CB … JJ.MM.AA`) à l'import ; elle sert de date effective pour les stats, budgets, liste des opérations et commerçants (le solde reconstitué garde la date du relevé).
- Commandes :
  ```bash
  make sf c='app:entries:reconcile --dry-run'      # rapprochement automatique des saisies en attente (aussi lancé après chaque import)
  make sf c='app:operations:spent-at --dry-run'    # recalcule spent_at des opérations importées depuis leur libellé brut
  ```
  **Après la migration `Version20261106140000`, lancer une fois `make sf c='app:operations:spent-at'`** pour dater les opérations CB déjà importées.
  `app:operations:spent-at` ne touche jamais les saisies en attente ni les lignes déjà rapprochées ; `app:operations:rebuild-fingerprints` ignore les saisies en attente (empreinte `manual:…` conservée).

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
- Les virements internes appariés (montants opposés, ±3 jours, deux comptes du foyer) sont catégorisés « Mouvements internes › Virements entre comptes du foyer » (source « Virement interne détecté »), voir [ADR 0009](docs/adr/0009-multi-comptes-foyer.md). Ils sont « à vérifier » jusqu'à validation (Réglages › Virements internes : « Valider » ou « Tout valider ») ; « Défaire » dissocie un appariement faux.

## Véhicules
- Réglages › Véhicules : créer les véhicules du foyer (dates d'acquisition / de cession, plaque, caractéristiques) et saisir les relevés kilométriques depuis la fiche du véhicule.
- Les opérations des catégories « liées à un véhicule » (par défaut Transport › Carburant, Entretien véhicule, Assurance auto, Péages & parking) sont affectées automatiquement au véhicule s'il est le seul actif à leur date ; sinon via une règle désignant un véhicule, ou à la main (Opérations, unitaire ou en masse). Une opération impossible à affecter peut être « ignorée » (même sélecteur) : elle sort du compteur « à affecter » ; filtre « Ignorées » pour les retrouver, « Aucun » pour les réactiver. Détails : [ADR 0011](docs/adr/0011-vehicules.md).
- Pleins de carburant : pour une catégorie « carburant », la saisie manuelle et la catégorisation d'un débit permettent de renseigner le volume du plein (plein complet par défaut), le kilométrage au compteur ; le prix au litre est déduit du montant. La fiche véhicule récapitule les pleins de la période : prix au litre, consommation (méthode du plein complet, L/100 km), volume total. Détails : [ADR 0015](docs/adr/0015-pleins-carburant.md).
- AdBlue (véhicules diesel) : section AdBlue de la fiche véhicule. En station, saisir le volume et lier la ligne du relevé (coût réel, opération affectée au véhicule) ; au bidon, saisir le volume versé avec le prix et la contenance du bidon (coût estimé au prorata, affiché à part car déjà compté dans les courses). Consommation en L/1000 km sur la fenêtre couverte par les relevés. Détails : [ADR 0016](docs/adr/0016-adblue.md).
- Financement : les prélèvements d'un crédit auto vont dans Transport › Crédit véhicule (catégorie « financement », affectée au véhicule comme les autres postes). Ils comptent dans le coût total du véhicule mais pas dans le **coût d'usage au km** ; la fiche véhicule et les statistiques affichent les deux (coût d'usage/km et coût total/km). Pour un crédit déjà prélevé, créer une règle sur le libellé du prélèvement vers cette catégorie. Après mise à jour : `make db-migrate` crée la catégorie dans les foyers existants.
- Pré-remplissage depuis la plaque (optionnel, service externe) : dans `.env.local`, `VEHICLE_LOOKUP_PROVIDER=rapidapi` et `VEHICLE_LOOKUP_TOKEN=<clé RapidAPI « API Plaque Immatriculation France »>`, puis `make cc`. Désactivé par défaut.

## Récurrences
- Les dépenses et revenus récurrents (loyer, énergie, abonnements, impôts mensualisés, salaire…) sont **détectés automatiquement** à partir de l'historique (36 mois) : `make sf c='app:recurrences:detect'` (ajoutez `--dry-run` pour simuler), relancé automatiquement après chaque import (`app:import` / `POST /api/imports`). Détails : [ADR 0012](docs/adr/0012-recurrences.md).
- Les variantes de libellé d'un même commerçant (export CSV ≠ relevé PDF, ponctuation, troncature) sont regroupées pour le top commerçants et les récurrences : `make sf c='app:merchants:group'` (`--dry-run` pour simuler), relancé automatiquement après chaque import. Détails : [ADR 0013](docs/adr/0013-regroupement-commercants.md).
- Les séries détectées alimentent l'estimation du mois en cours du graphe mensuel (échéances restantes + partie variable) et sont gérables via l'API (`GET/PATCH /api/recurrences`, `POST /api/recurrences/detect`) — l'écran dédié arrive dans un lot front séparé.

## Tests et analyse statique
```bash
make test          # PHPUnit
make test-tools    # tests des outils Python (tools/)
make lint          # PHPStan (niveau 5) + ESLint (front)
```
PHPStan part d'une baseline (`phpstan-baseline.neon`) : le code existant y est toléré, tout nouveau
problème fait échouer `make phpstan`. Après une correction, régénérer la baseline :
`make phpstan c='--generate-baseline'`.
