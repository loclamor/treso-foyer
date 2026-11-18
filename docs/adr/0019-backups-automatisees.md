# 0019 — Backups automatisés de la base + upload externe optionnel (rclone)

## Contexte
Jusqu'ici, la sauvegarde était manuelle (`make backup` → `docker compose exec database pg_dump | gzip` dans `data/backups/`). Aucun mécanisme automatique ni copie externe : perte des données bancaires en cas de panne du serveur.

## Décision
- **Commande Symfony `app:backup`** (`App\Backup\BackupManager` + `App\Command\BackupCommand`) : `pg_dump --format=plain --no-owner --no-privileges` lancé **depuis le conteneur php** (connexion parsée depuis `DATABASE_URL`, mot de passe passé via `PGPASSWORD`), flux compressé en gzip à la volée (`gzwrite`) dans `data/backups/banque-<AAAAMMJJ-HHMMSS>.sql.gz` via un fichier temporaire renommé atomiquement. `postgresql-client` est embarqué dans l'image (base + prod).
- **Rotation** : les `BACKUP_KEEP` dumps les plus récents sont conservés (noms horodatés = ordre chrono), les autres supprimés ; les `.tmp` abandonnés sont nettoyés.
- **Planification in-conteneur** : si `BACKUP_ENABLED=true`, l'entrypoint FrankenPHP lance `app:backup --watch` en arrière-plan ; la boucle fait un dump, attend `BACKUP_INTERVAL` (secondes, ou suffixe `m/h/d/w`, ex. `1d`), recommence. Une erreur n'interrompt pas la boucle (loguée). Pas de cron externe ni de service supplémentaire pour le backup local.
- **Upload externe optionnel** : service Compose `backup-remote` (profil Docker `backup-remote`, image `rclone/rclone`), qui `rclone sync` le dossier `data/backups/` vers `BACKUP_REMOTE` (ex. `gdrive:treso-foyer/backups`) toutes les `BACKUP_REMOTE_INTERVAL`. Identifiants rclone passés en variables d'environnement `RCLONE_CONFIG_<REMOTE>_*` (ex. `RCLONE_CONFIG_GDRIVE_TYPE=drive` + `RCLONE_CONFIG_GDRIVE_TOKEN={...}` dans `.env.local`, token obtenu via `rclone authorize "drive"`).
- `make backup` délègue désormais à `app:backup` (un seul code path).

## Alternatives écartées
- Cron hôte / systemd timer : couple le scheduling à la machine hôte, moins portable que l'entrypoint Compose.
- Service Compose dédié pour le dump local : l'entrypoint du conteneur php suffit, évite un second conteneur et un second build target.
- `pg_dump -Fc` (format custom) : le format plain gzipé se restaure avec un simple `zcat | psql`, lisible et diffable ; le custom est plus compact mais impose `pg_restore`.
- API Google Drive en direct (service account JSON) : rclone gère déjà l'OAuth, le refresh token et la reprise sur erreur.

## Conséquences
- Variables d'environnement : `BACKUP_ENABLED` (défaut `false`), `BACKUP_INTERVAL` (défaut `1d`), `BACKUP_KEEP` (défaut `14`), `BACKUP_REMOTE` (vide = désactivé), `BACKUP_REMOTE_INTERVAL` (défaut `1d`), `RCLONE_CONFIG_*` pour le remote. Le conteneur php reçoit `BACKUP_*` via `compose.yaml` ; le service remote n'est démarré que si `COMPOSE_PROFILES=backup-remote`.
- Le dump tourne dans le conteneur php : `pg_dump` doit rester embarqué dans l'image prod (binaire + libpq copiés explicitement, l'image prod étant un `debian:13-slim` allégé).
- Restauration inchangée : `zcat data/backups/banque-....sql.gz | docker compose exec -T database psql -U banque banque`.
- Le service `backup-remote` monte `data/backups/` en lecture seule ; aucune donnée bancaire n'entre dans l'image rclone.
