# Déploiement d'une instance

Ce document décrit comment déployer l'application sur un serveur en ligne (cible `prod`).
Pour le développement local, voir le `README.md` (`make start`, https://localhost:8543).

**Portée importante** : une instance = **un seul foyer**. Tous les membres connectés voient
l'ensemble des données du foyer (comptes, opérations, budgets). Ne pas ouvrir l'instance à des
personnes de confiance différentes sans filtrage réseau (voir « Sécurité réseau »).

## 1. Prérequis serveur

- Docker + Docker Compose v2 (rien d'autre à installer sur le serveur : PHP 8.5, `pdftotext`
  et Python 3 sont embarqués dans l'image).
- Ports ouverts sur le pare-feu : **80 TCP, 443 TCP, 443 UDP** (HTTP/3).
- Un nom de domaine avec un enregistrement DNS A/AAAA pointant sur le serveur (nécessaire pour
  le certificat HTTPS Let's Encrypt automatique de Caddy).
- Ressources : aucune limite imposée par les fichiers Compose ; prévoir de la place disque pour
  les volumes `database_data`, `caddy_data`, `caddy_config` et le dossier `data/` (relevés, backups).

## 2. Préparation

Cloner le dépôt sur le serveur, puis créer un fichier `.env.local` (non versionné) à la racine :

```bash
APP_SECRET=$(openssl rand -hex 32)
POSTGRES_PASSWORD=$(openssl rand -hex 32)
CADDY_MERCURE_JWT_SECRET=$(openssl rand -hex 32)
# Pepper HMAC des numéros de compte : ne JAMAIS changer après le premier import
ACCOUNT_NUMBER_PEPPER=$(openssl rand -hex 32)
# Licence PrimeVue Community (gratuite) : https://primeui.dev/licenses/community
VITE_PRIMEUI_LICENSE=<ta-cle>
```

Variables optionnelles :

| Variable | Rôle |
|---|---|
| `VEHICLE_LOOKUP_PROVIDER=rapidapi` + `VEHICLE_LOOKUP_TOKEN` | Pré-remplissage fiche véhicule par plaque (désactivé par défaut) |
| `SERVER_NAME=banque.example.fr` | Nom de domaine public (HTTPS Let's Encrypt auto) |
| `HTTP_PORT=80` / `HTTPS_PORT=443` | Ports publiés sur l'hôte (défaut 8180/8543) |
| `BIND_ADDRESS=0.0.0.0` | Exposer au-delà de localhost (défaut `127.0.0.1`) |

En cible `prod`, `APP_SECRET`, `POSTGRES_PASSWORD` et `CADDY_MERCURE_JWT_SECRET` sont
**obligatoires** : le démarrage échoue sinon (`compose.prod.yaml`). La valeur par défaut
versionnée de `ACCOUNT_NUMBER_PEPPER` est refusée au démarrage.

## 3. Build et démarrage (prod)

```bash
docker compose -f compose.yaml -f compose.prod.yaml build --pull
docker compose -f compose.yaml -f compose.prod.yaml up --detach --wait
```

- L'image `app-php-prod` embarque le front buildé (Vite) et tourne en `APP_ENV=prod`
  (opcache preload, HSTS + CSP injectés par Caddy, utilisateur non-root `www-data` UID 33).
- Le conteneur `php` attend la base, puis joue automatiquement les migrations Doctrine
  (`frankenphp/docker-entrypoint.sh`).
- `database` (Postgres 17) n'est **pas** exposé sur l'hôte en prod.
- HTTPS : si `SERVER_NAME` est un domaine public joignable, Caddy obtient automatiquement un
  certificat Let's Encrypt. Sinon (IP, réseau local), certificat auto-signé à valider dans le
  navigateur.

Application disponible sur `https://<SERVER_NAME>` (ou `https://<SERVER_NAME>:<HTTPS_PORT>`).

## 4. Initialisation

```bash
# Foyer + catégories par défaut (idempotent)
docker compose -f compose.yaml -f compose.prod.yaml exec php bin/console app:setup

# Un compte de connexion par personne du foyer (pas d'inscription en ligne)
docker compose -f compose.yaml -f compose.prod.yaml exec php bin/console app:member:add <identifiant> "<Nom affiché>"
```

## 5. Données et sauvegardes

- `data/` (relevés PDF/CSV, `inbox/`, `comptes.ini`, `backups/`) est exclu des images
  (`.dockerignore`). En dev il est monté via `./:/app` ; **en prod, montez-le explicitement**,
  par exemple via un fichier `compose.prod.local.yaml` :

  ```yaml
  services:
    php:
      volumes:
        - ./data:/app/data
  ```

  puis ajoutez `-f compose.prod.local.yaml` à toutes les commandes Compose.
- Rangement des relevés côté serveur (l'image embarque `python3`, `pdftotext` et `tools/`) :

  ```bash
  docker compose -f compose.yaml -f compose.prod.yaml exec php python3 -I tools/ranger_releves.py            # simulation
  docker compose -f compose.yaml -f compose.prod.yaml exec php python3 -I tools/ranger_releves.py --apply    # réel
  ```

- Sauvegarde de la base : `make backup` (dump dans `data/backups/banque-<date>.sql.gz`) —
  en prod, préfixer par les mêmes `-f` Compose. Restauration : `zcat <dump> | docker compose ... exec -T database psql -U banque banque`.
- Planifier la sauvegarde (cron) et la rotation des dumps hors du serveur si possible.

## 6. Sécurité réseau

- L'API exige une session authentifiée (`^/api` → `ROLE_USER`, `config/packages/security.yaml`) ;
  login par cookie de session avec limitation à 5 tentatives/minute. Les pages hors `/api` servent
  la coquille SPA publique (le front redirige vers la page de connexion).
- En prod, HSTS et une CSP stricte sont actifs (`compose.prod.yaml`).
- Recommandations réseau : restreindre l'accès à des IP de confiance (pare-feu / VPN) ou ajouter
  un `fail2ban` sur les 401 de `/api/login`, car 1 instance = 1 foyer et tous les membres voient tout.
- Ne jamais committer `.env.local` ni de secrets ; ne jamais exposer le port Postgres.

## 7. Commandes utiles

```bash
# Logs
docker compose -f compose.yaml -f compose.prod.yaml logs --tail=100 --follow php

# Console Symfony
docker compose -f compose.yaml -f compose.prod.yaml exec php bin/console <commande>

# État des conteneurs
docker compose -f compose.yaml -f compose.prod.yaml ps
```

## 8. Limites connues

- Mono-foyer : pas de création de foyer ni d'inscription depuis l'interface web (CLI uniquement).
- L'ADR 0001 indique un usage visé « local » ; la cible `prod` existe (image dédiée) mais le
  multi-foyer nécessiterait de filtrer toutes les requêtes par le foyer du membre connecté
  (aujourd'hui : foyer unique global via `App\Api\HouseholdProvider`).

## 9. Mise à jour d'une instance déployée

Procédure complète, à exécuter depuis la racine du dépôt sur le serveur (ajoutez
`-f compose.prod.local.yaml` partout si vous montez `data/` via ce fichier) :

```bash
# 0. Sauvegarde de précaution (avant toute mise à jour)
make backup        # dump dans data/backups/banque-<date>.sql.gz

# 1. Récupérer la nouvelle version
git pull

# 2. Reconstruire l'image et redémarrer
docker compose -f compose.yaml -f compose.prod.yaml build --pull
docker compose -f compose.yaml -f compose.prod.yaml up --detach --wait
#    -> les migrations Doctrine sont jouées automatiquement au démarrage
#       (frankenphp/docker-entrypoint.sh), --wait attend que le conteneur soit sain

# 3. Vérifier
docker compose -f compose.yaml -f compose.prod.yaml ps                 # php + database healthy
curl -s https://<SERVER_NAME>/api/health                               # endpoint public
docker compose -f compose.yaml -f compose.prod.yaml logs --tail=50 php
# puis se connecter sur l'interface pour contrôler visuellement
```

**En cas de problème** (rollback) :

```bash
# revenir au commit précédent et reconstruire
docker compose -f compose.yaml -f compose.prod.yaml down
git checkout <commit-précédent>
docker compose -f compose.yaml -f compose.prod.yaml build --pull
docker compose -f compose.yaml -f compose.prod.yaml up --detach --wait

# si la base a été migrée et doit être restaurée :
docker compose -f compose.yaml -f compose.prod.yaml down
docker volume rm <projet>_database_data   # ou restaurer le volume
# recréer la base puis restaurer le dump :
zcat data/backups/banque-<date>.sql.gz | docker compose -f compose.yaml -f compose.prod.yaml exec -T database psql -U banque banque
```

Règles :
- toujours `make backup` **avant** `git pull` + rebuild, jamais après ;
- ne jamais changer `ACCOUNT_NUMBER_PEPPER` (les empreintes des numéros de compte deviendraient illisibles) ;
- les migrations sont idempotentes et jouées automatiquement ; en cas de migration destructive,
  restaurer le dump sur une base vierge plutôt que de tenter un retour arrière de schéma.
