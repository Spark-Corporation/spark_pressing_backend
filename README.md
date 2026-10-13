# Spark Pressing API

Backend Laravel 10 (API-only) pour le POS pressing multi-agence — conforme CDC v3.0.

## Démarrage

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve
```

Docs OpenAPI : `/docs` (`composer docs`).

**Contrat API ↔ frontend** : [docs/api/CONTRACT.md](docs/api/CONTRACT.md) + spécification versionnée [docs/api/openapi.yaml](docs/api/openapi.yaml) (`composer docs` pour régénérer).

## Exigences non fonctionnelles (ENF)

| ID | Couverture API |
|----|----------------|
| ENF01 Perf | Listes paginées ; dépôt ≤20 lignes préchargé (< 2s testé) |
| ENF02 Dispo | `GET /api/v1/health` (DB) |
| ENF03 Sécu | bcrypt, throttle login, CORS configurable, HTTPS optionnel |
| ENF04 Isolation | Scope pressing/agence + tests |
| ENF07 i18n | Locale `fr` ; multi-devise prête |
| ENF08 Devise | ISO 4217 par agence + FX |
| ENF09 Observabilité | `X-Request-Id`, logs JSON (`stderr_json`) |
| ENF10 Qualité | PHPUnit flux critiques + CI |
| ENF12 Backup | [docs/ops/backup-restore.md](docs/ops/backup-restore.md) |
| ENF13 Maintenabilité | [docs/CONVENTIONS.md](docs/CONVENTIONS.md) + Scribe |
| ENF14 CI/CD | [.github/workflows/ci.yml](.github/workflows/ci.yml) |

ENF05/06/11 (UX POS, responsive, a11y) : côté clients web/mobile, hors ce repo.

## Déploiement FTP (GitHub Actions)

Le push sur `main` lance [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml) : `composer install --no-dev`, envoi FTP vers `public_html/pressing-api`, puis migrations.

Les identifiants ne sont pas dans le dépôt. Secrets du repo GitHub (`Settings` → `Secrets` → `Actions`) :

| Secret | Valeur |
|--------|--------|
| `FTP_HOST` | `pressing-api.pressingelegance.com` |
| `FTP_USER` | compte FTP |
| `FTP_PASSWORD` | mot de passe FTP |
| `APP_KEY` | sortie de `php artisan key:generate --show` |
| `DB_PASSWORD` | mot de passe MySQL |
| `APP_URL` | optionnel, défaut `https://pressing-api.pressingelegance.com` |
| `DB_HOST` | optionnel, défaut `localhost` |
| `DB_DATABASE` | optionnel, défaut `sparqrqm_pressing_api` |
| `DB_USERNAME` | optionnel, défaut `sparqrqm_api` |
| `CORS_ALLOWED_ORIGINS` | optionnel, défaut `*` |

Après l’envoi FTP, le workflow exécute `php artisan migrate --force` sur MySQL. L’hébergeur doit exposer PHP 8.1+ avec `pdo_mysql`, et le document root du sous-domaine doit être `/home/sparqrqm/public_html/pressing-api` (le `.htaccess` racine renvoie vers `public/`).
