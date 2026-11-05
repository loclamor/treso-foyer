# 0001 — Stack Symfony 7.4 LTS + FrankenPHP + Docker Compose

## Contexte
Application personnelle, maintenue sur le temps libre, par un développeur qui maîtrise Symfony et Docker. Rien à installer sur la machine hôte (PHP absent de l'hôte).

## Décision
- **Symfony 7.4 LTS** : support jusqu'à fin 2028 (bugs) / 2029 (sécurité) → peu de montées de version imposées.
- **PHP 8.5** sur **FrankenPHP** via le template officiel communautaire [`dunglas/symfony-docker`](https://github.com/dunglas/symfony-docker) : un seul conteneur web (Caddy + PHP), HTTPS local automatique, mode worker, rechargement à chaud en dev.
- **Docker Compose** : services `php`, `database` et, en dev, `node` (serveur Vite). Ports par défaut **8180 / 8543** (80/443/5432 déjà utilisés sur le poste). Base non exposée sur l'hôte.
- `poppler-utils` et `pdo_pgsql` ajoutés à l'image.
- Outil de rangement des fichiers (`tools/ranger_releves.py`) en Python côté hôte : one-shot, sans dépendance, antérieur à la stack.

## Conséquences
- `make up` suffit pour démarrer ; tout passe par `docker compose exec php …` (cibles Makefile).
- Image de prod disponible (`compose.prod.yaml`) mais l'usage visé est local.
