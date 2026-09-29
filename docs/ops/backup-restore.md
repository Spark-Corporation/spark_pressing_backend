# Sauvegardes & restauration — Spark Pressing (ENF12)

## Objectif

Sauvegardes **quotidiennes** de la base et procédure de restauration documentée.

## Fréquence

- **Quotidien** à 02:00 (fuseau du serveur), rétention **14 jours**.
- Avant chaque déploiement staging/prod : snapshot manuel.

## PostgreSQL (recommandé en prod)

```bash
# Backup
pg_dump -Fc -h "$DB_HOST" -U "$DB_USERNAME" "$DB_DATABASE" \
  > "/var/backups/spark/spark_$(date +%F).dump"

# Restauration (arrêter l'app / mode maintenance d'abord)
php artisan down
pg_restore --clean --if-exists -h "$DB_HOST" -U "$DB_USERNAME" -d "$DB_DATABASE" \
  /var/backups/spark/spark_YYYY-MM-DD.dump
php artisan migrate --force
php artisan up
```

Cron exemple :

```cron
0 2 * * * /usr/local/bin/backup-spark.sh >> /var/log/spark-backup.log 2>&1
```

## SQLite (dev / petits déploiements)

```bash
# Backup à froid (app en maintenance)
php artisan down
cp database/database.sqlite "/var/backups/spark/database_$(date +%F).sqlite"
php artisan up

# Restauration
php artisan down
cp /var/backups/spark/database_YYYY-MM-DD.sqlite database/database.sqlite
php artisan up
```

## Vérification post-restauration

1. `GET /api/v1/health` → `status: ok`, `database: ok`
2. Login staff + lecture d'un dépôt connu
3. Contrôle `audit_logs` récents

## Stockage

- Copier les dumps hors machine (S3 / stockage objet / autre datacenter).
- Chiffrer les archives au repos si le volume contient des PII clients.
