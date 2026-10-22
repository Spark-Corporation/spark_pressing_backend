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
| Multi-agences | `GET /network/overview` (+ détail `GET /network/agencies/{id}`) |
| Équipe & rôles | `GET /team/overview`, `GET /roles` |
| Sync | `GET /sync/center`, `GET /pos/sync/status` + `POST /sync` |
| Hub superadmin | `GET /superadmin/overview` |

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

### 7.0a Vue d’ensemble & Atelier (Figma 00, 04)

| Écran / bloc | Endpoint | Champs |
|--------------|----------|--------|
| KPI « Retraits effectués · N commandes à notifier », « Clients fidélité · +x % » | `GET /dashboard` | `kpis.ready_to_notify`, `kpis.loyalty_clients_delta_pct` |
| Revenus du réseau (7 jours) | idem | `series_7d`, `week.revenue`, `week.revenue_delta_pct` |
| Comparaison agences (CA cumulé 7 j) | idem | `agencies[].revenue_week`, `revenue_week_delta_pct`, `revenue_week_share_pct` |
| Pipeline atelier + « N commandes approchent leur délai » | idem | `workshop_pipeline.columns[].title`, `due_soon_count`, `due_soon[]` |
| Flux de caisse | idem | `cash.agency`, `cash.status`, `cash.inflows`, `cash.outflows`, `cash.by_payment_method` |
| Impayés · « N à traiter aujourd’hui » | idem | `unpaid_aging.due_today_count` |
| Activité récente | idem | `recent_activity[]` : `title`, `subtitle`, `amount`, `amount_type` (credit/debit/points/status), `status_label`, `kind` |
| Atelier Kanban | `GET /deposits/workshop?q&agency_id&service&responsable_id&due_date&period` | `capacity` (« 18 / 24 · Charge modérée »), `columns[]` (`title`, `count`, `overdue_count`, `next_due_at`, `average_stage_minutes`), cartes (`priority`, `overdue_minutes`, `stage_since`, `defects_count`, `responsable.initials`), `filters` |
| Aperçu du dépôt | `GET /deposits/{id}/workshop` | `articles[]` (note couleur/défauts), `remarks`, `responsables`, `timeline`, `next_action` |
| « Passer à … » | `POST /deposits/{id}/workshop` (`etat`, `storage_location?` ex. « Rayon B · casier 08 ») | — |

Le pipeline du dashboard et le kanban utilisent les 4 états serveur (`waiting`, `in_progress`, `treated`, `classed`) ; la maquette du dashboard en dessine 5 (À trier, Lavage, Repassage, Contrôle, Prêt).

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

### 7.0d Retraits (Figma 05)

| Écran / bloc | Endpoint | Champs |
|--------------|----------|--------|
| Centre de retrait — KPI, retraits du jour, dépôts prêts | `GET /retrievals/center?q&date&agency_id&payment_status=paid\|unpaid&etat` | `meta.kpis` (prêts + pièces, à notifier SMS/WhatsApp, retraits + évolution, soldes impayés), `meta.today_schedule[]`, lignes + `notification` (À notifier / SMS envoyé / SMS lu / Relance requise) |
| « Notifier le client » | `POST /deposits/{id}/notify` (`channel=sms\|whatsapp`), `POST /deposits/{id}/notification-read` | `open_url` wa.me pour WhatsApp |
| Vêtements classés depuis 30+ jours | `GET /retrievals/aged` | `buckets` 30–60 / 61–90 / 90+, `items[]` (`waiting_days`, `decision`) |
| Relancer / Frais / Escalade / Traité | `POST /deposits/{id}/aged-action` (`action`, `amount` pour les frais de garde) | — |
| Traiter le retrait — écran | `GET /deposits/{id}/checkout` | `header`, `steps[]`, `units[]` (restant, vérification), `balance` (`blocked`, `can_override`), `receiver`, `conditions`, `after_confirm` |
| « Confirmer le retrait » | `POST /deposits/{id}/checkout` (`units[]`, `payment{amount,method,received}`, `condition`, `remarks`, `receiver_type`, `receiver_name`, `identity_confirmed`, `override_reason`, `notify_sms`) | `partial`, `change_due`, `deposit`, `slip_url` |

La dérogation « solde impayé » exige la permission `deposits.override` (manager, admin) : relancer `RolePermissionSeeder` après déploiement.

### 7.0e Caisse (Figma 06)

| Écran / bloc | Endpoint | Champs |
|--------------|----------|--------|
| Centre de caisse — KPI, situation, solde théorique / compté, ventilation, flux horaire | `GET /cash/center?date` | `session`, `kpis` (recettes + évolution, dépenses, impayés, écart), `situation` (ouverture, encaissements dépôts, paiements de soldes, entrées/sorties hors dépôt), `theoretical`, `counted`, `breakdown.methods[]`, `hourly.series[]` (7 h–18 h) |
| Journal de caisse | `GET /cash/journal?q&date&period&category_id&payment_method&status&flow` (`format=csv` pour « Exporter ») | lignes `reference` (code dépôt, SOL-…, MVT-…), `category`, `operator`, `payment_method`, `flow`, `amount`, `status` ; `meta.totals` |
| Ouverture de caisse | `POST /cash/sessions` (`opening_balance`), `GET /cash/sessions/current` | `code` CAI-…, `available_cash`, `double_control_threshold` |
| Nouveau mouvement | `POST /cash-movements` (+ `payment_method`, `beneficiary`, `reference`, `comment`, `proof` multipart) | au-delà du seuil (250 000 par défaut) : catégorie + justificatif requis, statut `pending_approval` ; sortie espèces refusée si solde insuffisant |
| Détail / traçabilité / double contrôle | `GET /cash-movements/{id}`, `POST /cash-movements/{id}/validate` (manager ≠ initiateur, permission `cash.approve`) | `double_control`, `traceability[]`, `cash_session` |
| Clôture et rapprochement | `GET /cash/sessions/{id}/closing` | `status` (En cours / Prête à clôturer / Clôturée), `summary`, `reconciliation[]` par moyen, `result`, `checklist` (6 étapes), `operators[]`, `anomalies`, `report` |
| Comptage / contrôle / clôture | `POST /cash/sessions/{id}/count` (`counted{cash,mobile_money,card}`, `difference_reason`), `/control`, `/close` | clôture refusée tant que la checklist n’est pas complète |
| Rapport de clôture | `GET /cash/sessions/{id}/report` | PDF |

### 7.0f Articles & tarifs (Figma 07)

| Écran / bloc | Endpoint | Champs |
|--------------|----------|--------|
| Catalogue — KPI | `GET /articles` | `meta.kpis` (articles actifs + nouveaux, catégories + partagées, tarif moyen + évolution, à réviser > 12 mois) |
| Onglets / filtres | `GET /articles?q&category_id&billing_mode=piece\|kilo\|mixed&agency_id&tab=kilo\|unavailable&active_only` | lignes : `code`, `icon`, `category`, `billing_mode_label`, prix classique/express/repassage (pièce et kilo), `scope` (Actif · Partagé / N agences / agence) |
| Onglet Catégories | `GET/POST/PUT/DELETE /article-categories` | `articles_count`, `shared` |
| Onglet Historique | `GET /articles/history` | qui, quand, champs modifiés |
| Création / édition d’article | `POST /articles`, `PUT /articles/{id}`, `GET /articles/{id}` | `code`, `category_id`, `billing_mode`, `allow_discount`, `round_to`, `price_editable`, `classic_hours`/`express_hours`/`repass_hours`, `priority`, `accepted_defects[]`, `compatible_renders[]`, `services[]`, `kilo_tiers[]`, `availability[]` (agence, disponible, `price_adjust_percent`) ; réponse : `preview`, `checks[]`, `recommendations[]`, `last_modification`, `options` |
| Catalogue local du POS | `GET /pos/bootstrap` | `catalog.items[]` (prix ajustés de l’agence, mode, délais, défauts acceptés), `catalog.count` |

### 7.0g Rapports & bilans (Figma 08)

| Écran / bloc | Endpoint | Champs |
|--------------|----------|--------|
| Rapports et bilans (période, agence, comparaison) | `GET /reports/overview?date_from&date_to&preset=today\|7d\|30d&agency_id` | `kpis` (CA, ventes + panier, remises + % CA, impayés, évolutions vs période précédente), `revenue_chart` (série journalière, totaux, projection, `objective` si `monthly_revenue_target`), `payments`, `non_sales_movements`, `agencies[]`, `loyalty`, `reports[]` |
| Bilan journalier et performance caissiers | `GET /reports/daily?date&agency_id` | `kpis` (recettes, ventes soldées, impayés ouverts, écart de caisse + seuil), `receipts_chart` (tranches de 2 h + moyens), `non_deposit_movements`, `reconciliation`, `settlement`, `discounts` (+ sensibles), `cashiers[]` (session, recettes, ventes, panier, productivité) |
| « Exporter CSV / Excel / PDF » | mêmes routes avec `format=csv\|pdf` | CSV `;` + BOM (ouvrable dans Excel) |
| « Programmer » | `GET/POST /reports/schedules`, `DELETE /reports/schedules/{id}` | envoi e-mail par `php artisan reports:send-scheduled` (planifié toutes les heures) |

### 7.0h Paramètres (Figma 09)

Chaque catégorie est décrite dans `config/settings_schema.php` (valeurs par défaut = maquette). `PUT` fusionne partiellement `values`, ignore les clés inconnues et refuse un type incorrect (422). Certaines clés restent stockées dans `pressings` (délais, frais, express, fidélité, seuils caisse) : le reste de l’API les lit donc directement.

| Écran / bloc | Endpoint | Champs |
|--------------|----------|--------|
| Hub « Paramètres » (recherche, filtres, état global) | `GET /settings?group=structure\|operations\|finance\|platform&q` | `summary` (16 catégories, à jour / à vérifier), `filters[]`, `groups[].cards[]` (`key`, `label`, `summary`, `status` up_to_date\|modified\|action_required, `updated_relative`, `endpoint`), `recent_changes[]` |
| Opérationnels, Fidélité, Collecte & livraison, Paiements, Notifications, Sécurité, Mode hors ligne, Audit & conservation | `GET/PUT /settings/{operations\|loyalty\|delivery\|payments\|notifications\|security\|offline\|audit}` | `values`, `updated_at/by`, `context` (aperçu codes dépôt et workflow atelier, impact sécurité par rôle, appareils et cache hors ligne, volume d’audit…), `journal` (5 dernières) ; réponse PUT : `changes[]` (« Frais livraison : 1 500 → 2 000 ») |
| Branding, Configuration des reçus (brouillon / publication) | `GET/PUT /settings/{branding\|receipts}`, `POST …/publish`, `POST …/restore` (`version_id` facultatif = défaut) | `values` (brouillon), `published`, `has_draft`, `unsaved_changes`, `versions[]`, `impact.agencies` |
| Journal des modifications | `GET /settings/journal?category&limit` | `summary`, `changes[]`, `by`, `scope` |
| Appareils de caisse autorisés | `GET /settings/devices?agency_id&status`, `POST /settings/devices` (`pending=true` = à approuver), `PUT /settings/devices/{id}`, `POST …/approve`, `POST …/revoke` | `code`, `agency`, `responsible`, `status` + libellé (Autorisé, À approuver, Révoqué, Expiré), `offline_enabled`, `expires_at` (validité `security.devices.validity_days`) ; `meta.summary` |
| Promotions et fidélité | `GET /promos/overview?agency_id&status`, `GET /promos/check-code?code`, `POST/PUT /promos` (`name`, `type=percent\|amount`, `rate_percent`/`amount`, `quota`, `per_client_limit`, `agency_ids[]`, `min_amount`, `max_discount`, `stack_with_loyalty`) | `kpis` (membres, points émis / consommés, remises du mois, campagnes actives + utilisations), `program` (+ libellés), `tiers`, `history[]`, `auto_groups[]`, `promotions[]` (`discount_label`, `usage_percent`, `agencies`, `status_label`) ; un code refusé au dépôt renvoie 422 `promo_code` avec le motif |
| Collecte & livraison : zones, estimation | `GET /deliveries/zones?all=1`, `POST/PUT /deliveries/zones` (`quarters[]`, `radius_km`, `departure_address`, `collection_fee`, `delivery_fee`, `verified`), `POST /deliveries/estimate` (`zone_id`, `amount`) | zone : `status` validated\|to_verify ; estimation : frais, `total`, `free_delivery` (seuil, reste), `minimum_order` |
| Notifications : logs, test | `GET /settings/notifications/logs?days&channel&status&format=csv`, `POST /settings/notifications/test` (`event`, `channel`, `recipient`, `message`) | logs : `event_label`, destinataire masqué, `status_label` ; test : message rendu + `stats` (caractères, SMS estimés) ; `GET /settings/notifications` → `context.providers` (taux 7 jours), `events`, `variables`, `templates[].stats` |

Envois automatiques : dépôt enregistré, paiement reçu, retrait effectué (et « Prévenir par SMS » au retrait) selon `notifications.events`, avec horaires silencieux (les confirmations partent immédiatement). Le fournisseur SMS n’est pas encore branché : les SMS sont journalisés.

Effets appliqués : `security.lockout` (échecs / durée) et `security.sessions.max_hours` à la connexion staff, `receipts.footer_message` sur le ticket, `loyalty.program.minimum_points` pour « Convertir en remise ».

### 7.0i Multi-agences (Figma 10)

| Écran / bloc | Endpoint | Champs |
|--------------|----------|--------|
| Vue consolidée multi-agences (période, Comparer) | `GET /network/overview?date_from&date_to` (mois en cours par défaut ; admin/manager) | `kpis` (CA + évolution, dépôts + pièces, retraits + % finalisés, cash-flow net, impayés + % CA, fidélité, `sync_health` (disponibilité 30 j, agences en ligne), `network_objective` (+ agences ≥ 85 %)), `revenue_chart`, `ranking[]` (rang, CA, évolution, part), `comparison[]` (dépôts, retraits, impayés + `unpaid_level`, `objective` percent/remaining/level), `alerts[]` (impayés élevés, retards, opérations hors ligne, objectif proche ; `severity`, `endpoint`), `agencies[]` (en ligne, dépôts, objectif) |
| Détail agence | `GET /network/agencies/{id}?date_from&date_to` | `agency` (code, adresse, horaires du jour), `kpis` (CA + rang réseau, caisse du jour + écart, dépôts/retraits + taux, objectif + reste), `performance_chart` (cumulé), `network_comparison[]` (CA, panier, impayés, retards atelier ; satisfaction = null tant que les avis ne sont pas collectés), `workshop` (colonnes + pièces, délais proches, retards), `clients` (actifs, nouveaux, membres fidélité, points du mois, VIP, répartition), `delays_unpaid` (à recouvrer, retards > 24 h, impayés 30 j+, lignes), `team` (présents = connectés aujourd’hui), `history[]`, `sync` |
| Gérer l’agence (horaires, objectif) | `POST /org/agencies`, `PUT /settings/agencies/{id}` | `opens_at`, `closes_at` (HH:MM), `monthly_revenue_target` (sinon objectif du pressing réparti entre agences) |

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

### 7.0j Équipe, profils & permissions (Figma 11)

| Écran / bloc | Endpoint | Champs |
|--------------|----------|--------|
| « Utilisateurs et équipe » | `GET /team/overview` | `kpis`, `mfa`, `tabs`, `filters`, `recent_activity` |
| Annuaire | `GET /team/users?q&agency_id&role&status&period` | ligne : `initials`, `role_label`, `agencies_label`, `mfa_enabled`, `last_login_label` |
| Fiche membre | `GET/PUT /team/users/{id}` | profil, `agency_ids`, `mfa_required`, `sessions` |
| Désactiver / réactiver | `POST /team/users/{id}/disable\|enable` | — |
| Sessions | `GET /team/users/{id}/sessions`, `DELETE …/sessions/{tokenId}` | ip, user_agent, expires |
| Invitations (72 h) | `GET/POST /team/invitations`, `POST …/resend\|revoke` | `require_mfa`, `status` |
| Accepter l’invitation | `GET/POST /invitations/{token}` (+ `/accept`) | public ; `password` confirmé |
| Activité sensible 24 h | `GET /team/activity?hours` | messages FR |
| MFA (compte courant) | `POST /team/mfa/setup\|confirm\|disable` | TOTP + codes de secours |
| Profil et sécurité staff (compte courant) | `GET/PUT /auth/staff/profile` | `identity` (prénom, nom, e-mail, téléphone, fonction, matricule), `role` (lecture seule : rôle, agence principale, agences complémentaires), `password.changed_at`, `mfa` (activée, configurée le, codes de secours restants), `sessions[]` (appareil, IP, dernière activité, `current`), `authorized_devices[]`, `preferences` (langue, fuseau, résumé quotidien, alertes sonores atelier, WhatsApp) |
| Changer le mot de passe | `PUT /auth/staff/password` (`current_password`, `password` + confirmation) | 12 caractères min., une majuscule, un chiffre |
| Sessions actives | `DELETE /auth/staff/sessions/{id}`, `POST /auth/staff/sessions/revoke-others` | « Déconnecter » / « Déconnecter les autres » |
| Connexion avec MFA | `POST /auth/staff/login` + `otp` | sans `otp` : 422 `mfa_required` (`meta.mfa_required=true`) ; code TOTP ou code de secours (usage unique) ; échecs comptés dans le verrouillage |
| Matrice des rôles | `GET /roles` | `columns`, `domains`, `roles[].cells` (`all\|partial\|none`), `summary` |
| « Créer un rôle » | `GET /roles/catalog`, `POST /roles` | droits fins ; `confirm_sensitive` si droit sensible |
| Détail / édition rôle custom | `GET/PUT /roles/{id}` | rôles système non éditables |

Permissions fines : `config/permission_matrix.php` (domaines × colonnes). Les permissions techniques des routes sont dérivées via `grants`.

### 7.0k Synchronisation (Figma 12)

| Écran / bloc | Endpoint | Champs |
|--------------|----------|--------|
| Centre de sync | `GET /sync/center?agency_id&days` | `kpis` (disponibilité, agences en ligne, ops en attente), `agencies[]`, `batches[]`, `devices[]`, `alerts[]` |
| Détail lot | `GET /sync/batches/{id}` | `reference`, compteurs, `status` |
| Statut POS | `GET /pos/sync/status?device_code` | `online`, `pending_ops`, `last_batch` |
| Envoi retour en ligne | `POST /sync` (`operations[]`, `device_code?`, `reference?`) | crée un `SyncBatch` ; `data.batch` + `data.operations` |

### 7.0l Hub superadmin (Figma 13–13B)

| Écran / bloc | Endpoint | Champs |
|--------------|----------|--------|
| Hub (13) | `GET /superadmin/overview` | `kpis` (pressings, agences, users, licences), `attention`, `shortcuts` |
| Pressings (13A) | `GET /superadmin/pressings` | `initials`, compteurs, `license` |
| Agences / utilisateurs (13B) | `GET /superadmin/agencies`, `GET /superadmin/users?q` | rattachement pressing, statut, MFA |

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
