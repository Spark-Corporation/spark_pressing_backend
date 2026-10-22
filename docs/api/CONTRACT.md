# Contrat d’API — Backend Spark Pressing ↔ Frontend

Ce fichier + [`openapi.yaml`](openapi.yaml) constituent le **contrat** entre l’API et le futur frontend (web / mobile).

Toute intégration frontend doit se baser **uniquement** sur ces artefacts.

---

## 1. Artefacts du contrat

| Fichier | Rôle |
|---------|------|
| [`openapi.yaml`](openapi.yaml) | Spécification OpenAPI 3.0.3 — **contrat machine** (paths, schémas, auth) |
| [`postman.collection.json`](postman.collection.json) | Collection Postman pour essais manuels |
| Ce document | Règles transverses non toujours exprimées dans OpenAPI |

Régénération côté backend :

```bash
composer docs
```

Cela met à jour `docs/api/openapi.yaml` (et Postman). Le frontend consomme **ce** fichier versionné dans le repo, pas une capture d’écran ni un message.

---

## 2. Base URL & version

```
{APP_URL}/api/v1
```

Exemple local : `http://localhost:8000/api/v1`

- Version actuelle : **v1**
- Breaking change → `v2` (ou dual-run documenté). Pas de casse silencieuse de `v1`.

---

## 3. Format de réponse (obligatoire)

Toutes les réponses JSON métier :

```ts
type ApiSuccess<T> = {
  data: T
  meta: Record<string, unknown>  // objet, éventuellement vide
  errors: null
}

type ApiError = {
  data: null
  meta: { message: string; [key: string]: unknown }
  errors: Array<{ code: string; detail: string; [key: string]: unknown }>
}
```

Pagination (dans `meta`) : `page`, `per_page`, `total`, `last_page`.

Le frontend **parse toujours** `data` / `meta` / `errors`. Ne pas attendre un JSON racine “plat”.

---

## 4. Authentification

```http
Authorization: Bearer {token}
```

| Frontend | Endpoint login |
|----------|----------------|
| POS staff | `POST /api/v1/auth/staff/login` |
| App client | `POST /api/v1/auth/client/login` |
| Back-office superadmin | `POST /api/v1/auth/superadmin/login` |

Réponse login (shape) :

```json
{
  "data": {
    "token": "...",
    "token_type": "Bearer",
    "actor": "staff|client|superadmin",
    "user": {}
  },
  "meta": {},
  "errors": null
}
```

- Staff `user` : `fullname`, `roles`, `agency`, `agencies`, `pressing`, `permissions` (sur `/me`)
- Superadmin `user` : `fullname`, `email`, `type`, `status`, `roles: ["superadmin"]` (pas d’accès POS `/dashboard`)
- `GET /api/v1/auth/me` — session courante
- `POST /api/v1/auth/logout` — invalide le token
- `401` → rediriger vers login
- Throttle login : 5/min → `429`

Écrans réseau / POS (réponses agrégées, un appel) :

| Écran | Endpoint |
|-------|----------|
| Dashboard | `GET /dashboard` (`kpis`, `series_7d`, `agencies`, `workshop_pipeline`, `cash.by_payment_method`, `unpaid_aging`, `recent_activity`, `sync`) |
| Boot POS | `GET /pos/bootstrap` (`user`, `agency`, `agencies`, `permissions`, `articles`, `services`, `clients`, `sync`) |
| Atelier | `GET /deposits/workshop` |
| Multi-agences | `GET /org/agencies/overview` |
| Sync | `GET /pos/sync/status` + `POST /sync` |

Détail des bodies : voir `openapi.yaml` (`paths./api/v1/auth/...`).

---

## 5. Multi-tenant (POS staff)

Sur les routes métier staff, envoyer :

```http
X-Pressing-Id: {id}
X-Agency-Id: {id}
```

Sans headers valides → `403`. Le frontend ne doit pas “cacher” une mauvaise agence : afficher l’erreur.

---

## 6. Idempotence (écritures POS)

Pour dépôt, paiement, retrait, sync POS, caisse, livraison :

```http
Idempotency-Key: {uuid-v4}
```

- Même clé + succès → même réponse (replay)
- Générer **une** clé par action utilisateur (bouton), la réutiliser sur retry réseau

---

## 7. Données métier critiques pour l’UI

| Sujet | Contrat |
|-------|---------|
| Montants | Entiers (unité mineure), **jamais** de float |
| Devise | ISO 4217 (`currency` sur dépôt / agence) |
| Dates | ISO 8601 dans les JSON |
| Listes | Paginated via `meta` |

États dépôt / livraison : valeurs documentées dans OpenAPI et ressources (`etat`, `delivery_status`, etc.) — le frontend mappe via un enum aligné sur l’API, pas des labels hardcodés divergents.

### 7.0 Authentification staff (Figma 01)

| Écran / bloc | Endpoint | Champs |
|--------------|----------|--------|
| « Bienvenue » — identifiant ou e-mail, agence ou contexte, se souvenir de moi | `POST /auth/staff/login` (`login`, `password`, `agency_id?`, `remember?`) | `token`, `expires_at` (12 h, 30 j si `remember`), `user.contexts[]` (`label` « Agence • Rôle »), `user.active_agency_id` |
| « Après 5 échecs, accès limité pendant 15 minutes » | idem | 422 + `meta.attempts_remaining` ; 429 + `meta.retry_after` une fois verrouillé |
| « Réinitialiser l’accès » — recevoir le code par E-mail / SMS •••• 42 | `POST /auth/staff/password/channels` | `channels[]` {`code`, `label`, `destination` masquée} |
| Étape 1/3 — envoyer le code sécurisé | `POST /auth/staff/forgot-password` (`email`, `channel`) | `expires_in` 600 ; 429 au-delà de 2 demandes / 15 min |
| Étape 2/3 — vérifier le code à 6 chiffres | `POST /auth/staff/verify-reset-code` | `reset_token` |
| Étape 3/3 — nouveau mot de passe | `POST /auth/staff/reset-password` (`reset_token`) | `reset` |

`GET /auth/me` renvoie aussi `meta.contexts`.

### 7.0b Dépôts & POS (Figma 02)

| Écran / bloc | Endpoint | Champs |
|--------------|----------|--------|
| Gestion des dépôts — KPI | `GET /deposits` | `meta.kpis.deposits_today` (+ `delta_percent` vs même jour S-1), `revenue_today` (+ `collected`), `to_retrieve_today` (+ `ready`), `left_to_collect` (+ `open_deposits`) |
| Filtres | `GET /deposits?q&period=day\|week\|month&date_from&date_to&payment_status=paid\|partial\|unpaid&etat=…\|retrieved&agency_id` | — |
| Colonnes du tableau | idem | `service` (Express/Classique/Repassage), `quantity_label` (« 7 pièces », « 4,8 kg »), `payment_status`, `workshop_status` (dont « Retiré »), `sync_status` |
| « Exporter » | `GET /deposits/export` (CSV, mêmes filtres) | — |
| Détail du dépôt | `GET /deposits/{id}` | `commercial_status`, `workshop_detail`, `treatment`, `pickup`, `client_summary`, `totals` (express, remise, points), `payments[]` (`received_by`), `timeline[]`, `audit[]`, `actions` |
| Nouveau dépôt — lignes | `POST /deposits` | `lines[].express`, `lines[].color`, `lines[].defects[]` (codes de `deposit_options.defects`), `lines[].internal_notes` ; `terminal`, `local_ref`, `discount_reason` |
| Options du formulaire | `GET /pos/bootstrap` | `deposit_options` (express ×multiplicateur + délai, délais, défauts, moyens de paiement) |
| Ticket et facture | `GET /deposits/{id}/document?type=ticket\|facture&layout=ticket\|a4` | `document` (numéro FAC-…, vendeur RCCM/CC, lignes dont « Majoration Express », totaux, conditions), `share_url` |
| « Envoyer au client » | `POST /deposits/{id}/document/send` (`channel=whatsapp\|sms\|email`) | `share_url` signé 7 j, `open_url` (wa.me) |
| « Annuler le dépôt » | `DELETE /deposits/{id}` (`reason`) | journalisé |

### 7.0c Clients & fidélité (Figma 03)

| Écran / bloc | Endpoint | Champs |
|--------------|----------|--------|
| CRM — KPI, répartition fidélité, puces de palier | `GET /clients` | `meta.kpis` (actifs, nouveaux + semaine, VIP + part, points + évolution), `meta.loyalty_distribution`, `meta.tier_filters` |
| Filtres | `GET /clients?q&tier=essentiel\|argent\|or\|platine&crm_status=active\|vip\|inactive\|archived&agency_id&period=day\|week\|month` | — |
| Répertoire | idem | `initials`, `code` (CLI-…), `agency`, `last_deposit_at`, `frequency_per_month`, `lifetime_value`, `loyalty_points`, `tier`, `crm_status` |
| « Exporter » | `GET /clients/export` | CSV |
| Nouveau / modifier client | `POST /clients`, `PUT /clients/{id}` | `last_name`, `first_name`, `phone_secondary`, `agency_id`, `contact_preference` (whatsapp/call/sms/email), `loyalty_tier`, `consent_sms`, `consent_email`, `pickup_slot`, `favorite_treatment`, `instructions`, `note` |
| Contrôle des doublons | `GET /clients/duplicates?phone&email&except_id` | `matches[]` (code, nom, agence) |
| Fiche client | `GET /clients/{id}` | `stats`, `loyalty` (valeur, palier suivant, progression, règle de conversion), `points_history`, `timeline`, `recent_orders`, `preferences`, `notes`, `account` |
| « Convertir en remise » | `POST /clients/{id}/loyalty/convert` (`points` ≥ 500) | crédit portefeuille |
| Notes internes | `GET/POST /clients/{id}/notes` | — |
| « Archiver le client » | `POST /clients/{id}/archive`, `/restore` | historique conservé |

Paliers dans `config/spark.php` (`loyalty_tiers`, `vip_tier`) : Essentiel 0, Argent 1 000, Or 2 500, Platine 4 000 points (valeurs par défaut à confirmer).

### 7.1 Centre des licences superadmin (Figma 13C)

| Écran / bloc | Endpoint | Champs |
|--------------|----------|--------|
| KPI, « Échéances à traiter », « Prévision de revenu » | `GET /superadmin/licenses/overview?period=day\|week\|month` | `kpis.*`, `deadlines`, `forecast`, `network` |
| Tableau « Licences multi-pressings » | `GET /superadmin/licenses?q&plan&country&status&expires_within&per_page` | `reference`, `pressing{initials,country}`, `plan`, `periodicity`, `days_left`, `period.consumed_percent` (colonne Usage), `usage`, `auto_renew`, `status`, `payment_status` |
| Bouton « Exporter » (liste) | `GET /superadmin/licenses/export` (CSV, mêmes filtres) | — |
| Page « Détail et renouvellement » | `GET /superadmin/licenses/{id}` | `license`, `renewal`, `coverage`, `limits`, `payments`, `audit`, `actions` |
| « Valider et renouveler » | `POST /superadmin/licenses/{id}/renew` (multipart si `proof`) | `periodicity`, `payment_method`, `reference`, `effective_at`, `auto_renew`, `plan?`, `mark_paid?` → renvoie le détail |
| « Suspendre » / « Réactiver » | `POST …/{id}/suspend` (`reason?`) / `POST …/{id}/reactivate` | ligne licence |
| « Changer de plan » | `PUT /superadmin/licenses/{id}` (`plan`, `periodicity`, `auto_renew`…) | ligne licence |
| Historique + « Exporter » | `GET …/{id}/payments` (`format=csv`) | paiements |
| Validation justificatif | `POST …/{id}/payments/{paymentId}/validate` | paiement |
| « Télécharger facture » / icône action | `GET …/{id}/payments/{paymentId}/invoice` (PDF) ; `actions.invoice_payment_id` donne la dernière facture | — |

Les statuts sont des objets `{code, label}` : afficher `label`, tester `code`. Licence : `active`, `expiring` (≤ 30 j, « À renouveler »), `expired`, `suspended`, `inactive`. Paiement licence : `paid`, `pending`, `upcoming`, `overdue`, `none`. Montants en `XOF` (FCFA), tarifs et limites des plans dans `config/licensing.php`.

---

## 8. Codes HTTP à gérer côté UI

| Code | Comportement frontend |
|------|------------------------|
| 200 / 201 | Afficher `data` |
| 401 | Session expirée → login |
| 403 | Message droits / tenant |
| 422 | Afficher `errors[].detail` (formulaire) |
| 429 | Retry avec backoff / message throttle |
| 5xx | Erreur technique + `X-Request-Id` si présent |

---

## 9. Comment brancher le frontend

1. Copier / référencer [`openapi.yaml`](openapi.yaml) (ou le fetch à `GET /docs.openapi` sur l’env déployé).
2. Générer le client (ex. `openapi-typescript`, Orval, openapi-generator) **depuis ce fichier**.
3. Typer les appels sur `ApiSuccess<T>` / `ApiError`.
4. Avant chaque sprint d’intégration : `git pull` + régénérer le client si `openapi.yaml` a changé.

---

## 10. Engagements

**Backend**

- Ne merge pas un endpoint breaking sans mettre à jour `openapi.yaml`
- `composer docs` après modification des routes / controllers documentés

**Frontend**

- N’invente pas de champs / headers hors contrat
- Traite OpenAPI comme la vérité ; en cas de doute, ouvrir un ticket avec path + example OpenAPI

---

## 11. Périmètre v1 couvert

Le fichier OpenAPI inclut notamment :

- Auth (staff / client / superadmin)
- POS bootstrap & sync
- Clients, catalogue, dépôts, atelier, livraison
- Caisse, reports, settings, organisation, portail client

Liste exhaustive : section `paths` de [`openapi.yaml`](openapi.yaml).
