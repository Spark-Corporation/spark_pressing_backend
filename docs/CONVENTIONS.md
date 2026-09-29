# Conventions Spark Pressing API (ENF13)

## Stack

- PHP 8.1+ / Laravel 10, Sanctum, Spatie Permission, DomPDF, Scribe (OpenAPI).
- Montants : **entiers en unité mineure** (`App\Support\Money\Currency`), devise ISO 4217 par agence.
- Multi-tenant : `BelongsToTenant` + headers `X-Pressing-Id` / `X-Agency-Id`.

## Style

- Actions métier dans `app/Actions/**` (pas de logique lourde dans les controllers).
- Validation inline dans les controllers (pas de FormRequest pour l’instant).
- Réponses via `ApiResponse` (`data` / `meta` / `errors`).
- Formatage : `vendor/bin/pint`.

## Idempotence & rollback

- Header optionnel `Idempotency-Key` (ou `X-Idempotency-Key`) sur POST dépôt / paiement / retrait / sync POS / caisse / livraison.
- Rejoue la **même** réponse si succès (`Idempotent-Replay: true`) ; libère la clé si erreur (retry autorisé).
- Domaine : `client_uuid` unique sur dépôts, transactions et mouvements de caisse.
- Toute écriture critique est dans `DB::transaction` : validation / portefeuille insuffisant → **rollback** complet.

- Mots de passe hashés (bcrypt).
- Rate limit login : `throttle:login` (5/min).
- CORS via `CORS_ALLOWED_ORIGINS` (pas de `*` en prod).
- HTTPS forcé si `SPARK_FORCE_HTTPS=true` derrière un reverse proxy (`TrustProxies`).

## Observabilité (ENF09)

- Header `X-Request-Id` (middleware).
- Prod : `LOG_CHANNEL=stderr_json` pour logs structurés.
- Audit métier : `AuditLog::record(...)`.

## Tests (ENF10)

```bash
php artisan test
```

Flux critiques : auth, dépôt, paiement, retrait, isolation tenant, devise/FX, livreur.

## Docs OpenAPI

```bash
composer docs   # artisan scribe:generate
```

## CI (ENF14)

GitHub Actions : `.github/workflows/ci.yml` (Composer, Pint, PHPUnit).
