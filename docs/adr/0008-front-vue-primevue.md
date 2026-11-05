# 0008 — Front : SPA Vue 3 + PrimeVue, API JSON Symfony

> Remplace la décision initiale « Twig + AssetMapper + Symfony UX » (abandonnée avant tout développement d'écran).

## Contexte
L'essentiel de l'application est fait d'écrans riches : tableau d'opérations filtrable avec catégorisation en ligne, boîte « à catégoriser », tableaux de bord multi-graphiques, sélecteur de périmètre (compte / personne / foyer) global. Le même schéma (SPA Vue + API JSON Symfony) est déjà pratiqué sur un autre projet personnel.

## Décision
- **Back** : Symfony expose une API JSON sous `/api` (contrôleurs écrits à la main, sérialisation explicite en tableaux) ; `SpaController` sert la coquille `templates/spa.html.twig` pour toute autre URL (catch-all, priorité basse).
- **Front** (`assets/`) : **Vue 3**, **vue-router**, **Pinia**, **axios** (`src/api/http.js`, base `/api`, cookie de session).
- **Build** : **Vite** intégré via `pentatrion/vite-bundle` + `vite-plugin-symfony` ; sortie `public/build/`. En dev, conteneur `node` lançant le serveur Vite (HMR) sur `http://localhost:5173`.
- **Composants** : **PrimeVue 5** (thème Aura, locale FR) — DataTable avec filtres/pagination, DatePicker, TreeSelect pour les catégories, Toast, ConfirmDialog.
- **Graphiques** : **ECharts** via `vue-echarts` (séries temporelles avec zoom, donuts, Sankey revenus → postes, calendrier).
- **Authentification** : session/cookie Symfony (`/api/login`, `/api/logout`, `/api/me`), pas de JWT.

## Licence PrimeVue
Depuis la v5, PrimeVue (PrimeUI) n'est plus MIT : une clé est requise. La **licence Community** est gratuite pour un particulier (renouvellement annuel de l'éligibilité). La clé est fournie par `VITE_PRIMEUI_LICENSE` dans `.env.local` (non versionné) et passée à l'option `license` de PrimeVue ; sans clé, un bandeau « Invalid PrimeUI License » s'affiche. Repli possible : PrimeVue 4.5.5 (dernière version MIT), API quasi identique.
La vérification est locale (sans appel réseau) ; la clé se retrouve dans le bundle JS, ce qui est sans conséquence pour un usage local.

## Alternatives écartées
- Twig + Symfony UX : moins adapté aux écrans très interactifs.
- API Platform : surdimensionné ; les endpoints de statistiques sont de toute façon sur mesure.
- Chart.js : moins riche qu'ECharts pour l'analyse financière (Sankey, dataZoom, calendrier).
- Bootstrap : pas de DataTable/DatePicker/TreeSelect prêts à l'emploi.

## Conséquences
- Deux toolchains (Composer + npm), mais tout reste conteneurisé.
- Tests : PHPUnit fonctionnels sur l'API (`tests/`) ; contrôle visuel du front par navigateur headless.
