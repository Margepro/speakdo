# Tenant bootstrap

## Legacy — compatibilité temporaire

`POST /internal/v1/tenants` reste réservé aux anciens modules et requiert
`X-SpeakDo-Admin-Token`. Il est contrôlé par `TENANT_BOOTSTRAP_LEGACY_ENABLED` (défaut
`true`) et chaque succès produit l'événement d'audit `tenant.bootstrap.legacy`. Il ne faut
plus l'adopter dans un nouveau module : son jeton global est nécessairement extractible d'un
module distribué publiquement.

## Bootstrap v2 — contrat requis avant implémentation

> **Rien de ce qui suit n'est implémenté dans ce dépôt.** Aucune route `bootstrap/start` ni
> `bootstrap/{id}/finalize`, aucune table `tenant_bootstrap_challenges`, aucune colonne
> `tenants.installation_id` n'existent au moment de la rédaction. Ce document est le contrat
> complet que l'implémentation devra satisfaire — écrit avant le code, comme le reste des
> chantiers de ce dépôt.

Il n'existe pas encore de route bootstrap v2, volontairement : le module actuel n'expose pas
de preuve que le middleware puisse vérifier. Un `installation_id` ou une URL envoyés par le
demandeur ne prouvent pas son contrôle, et un endpoint `health` public ne change pas ce fait.
Le principe retenu est une validation de contrôle par défi-réponse (même famille que la
validation de domaine HTTP-01 d'ACME) : le middleware émet un secret à usage unique, le module
doit prouver qu'il peut l'exposer à l'URL Dolibarr qu'il revendique, et **c'est le middleware
qui va le chercher lui-même** — jamais le module qui l'affirme.

### Vue d'ensemble du flux

```text
Module Dolibarr                          Middleware SpeakDo
      |                                          |
      |  POST /api/v1/tenants/bootstrap/start    |
      |  {installation_id, dolibarr_base_url}    |
      |----------------------------------------->|
      |                                          | crée bootstrap_id + challenge,
      |                                          | stocke hash(challenge), TTL court
      |  201 {bootstrap_id, challenge,           |
      |       expires_at, expires_in_seconds}    |
      |<-----------------------------------------|
      |                                          |
      | expose GET .../speakdo/bootstrap-proofs/{bootstrap_id}
      | -> {bootstrap_id, challenge, installation_id}
      |                                          |
      |  POST .../bootstrap/{bootstrap_id}/finalize
      |----------------------------------------->|
      |                                          | consomme la ligne (one-shot),
      |                                          | GET sortant vers Dolibarr,
      |                                          | compare les 3 champs,
      |                                          | crée le tenant si OK
      |  201 {tenant_id, dolibarr_hmac_secret}   |
      |<-----------------------------------------|
```

### 1. Démarrage — `POST /api/v1/tenants/bootstrap/start`

Route publique (aucun secret préalable à présenter — c'est précisément ce que ce flux établit).
Corps JSON :

```json
{
  "installation_id": "3fa85f64-5717-4562-b3fc-2c963f66afa6",
  "dolibarr_base_url": "https://client.example.com/dolibarr"
}
```

- `installation_id` : UUID v4 généré et conservé **localement par le module** (non secret, sert
  uniquement à reconnaître une installation déjà enrôlée) — même regex de validation que
  `tenant_id` dans `TenantAdminController::upsert()`
  (`^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`), sinon
  `422 invalid_installation_id`.
- `dolibarr_base_url` : validée par `UrlGuard::validate()`, exactement comme dans
  `TenantAdminController::upsert()` (HTTPS obligatoire si `dolibarr.require_https`, pas d'IP
  privée/résolution vide sauf `dolibarr.allow_private_ips`, pas d'identifiants/query/fragment
  dans l'URL) — toute violation renvoie `422 invalid_dolibarr_url` avec le message de
  `UrlGuard`.
- Avant de créer quoi que ce soit, le middleware cherche `installation_id` dans
  `tenants.installation_id` (nouvelle colonne, voir §9). Si un tenant existant a déjà ce même
  `installation_id` **et** possède un `dolibarr_hmac_secret_ciphertext` actif : `409
  tenant_already_enrolled`, aucun `bootstrap_id`/challenge n'est émis, le secret historique
  n'est jamais renvoyé (récupération de secret hors périmètre de ce flux, cf. §8).

Réponse `201` :

```json
{
  "bootstrap_id": "9c858901-8a57-4791-81fe-4c455b099bc9",
  "challenge": "Kx2vQ8mN4pL7hT1wZeR6yUiO9aS3dFgH",
  "expires_at": "2026-08-24 12:35:00.000000",
  "expires_in_seconds": 300,
  "proof_url": "https://client.example.com/dolibarr/api/index.php/speakdo/bootstrap-proofs/9c858901-8a57-4791-81fe-4c455b099bc9"
}
```

`proof_url` est un simple confort (URL déjà assemblée par le middleware à partir de l'URL
validée) — le module doit de toute façon exposer le endpoint exact ci-dessous à sa propre URL,
`proof_url` n'est qu'un rappel, pas une valeur à republier telle quelle.

### 2. Génération du challenge

- `bootstrap_id` = `Ids::uuidV4()` — identifiant de la tentative de bootstrap, jamais secret
  (il transite en clair dans l'URL de preuve), sert de clé publique du flux.
- `challenge` = `Ids::opaqueToken(32)` — 32 octets aléatoires, base64url sans padding (même
  fonction que `bearer_token`/`pairing_code` du provisioning MCP), **c'est lui le secret** :
  seul quelqu'un ayant reçu la réponse du `start` (ou un administrateur qui l'y aura copié)
  peut le connaître et donc le republier à l'URL Dolibarr revendiquée.
- Stockage : uniquement `hash('sha256', $challenge)` en base (`challenge_hash`, `CHAR(64)`) —
  jamais le challenge en clair, même mécanisme que `mcp_credentials.secret_hash` ou
  `replay_nonces.nonce_hash`. La comparaison à la finalisation se fait en hashant la valeur
  reçue de Dolibarr et en comparant via `hash_equals()` au hash stocké.
- `dolibarr_base_url` (normalisée par `UrlGuard`) et `installation_id` sont stockés en clair
  sur la ligne de bootstrap — nécessaires pour le GET de vérification et la comparaison finale,
  non sensibles en eux-mêmes.

### 3. Format exact de la preuve attendue côté module

Le module doit exposer, dès qu'il a reçu et enregistré la réponse du `start` :

`GET {dolibarr_base_url}/api/index.php/speakdo/bootstrap-proofs/{bootstrap_id}`

- Route **publique**, sans authentification — le challenge lui-même est la preuve, imposer un
  secret supplémentaire pour la lire serait circulaire.
- `{bootstrap_id}` dans le chemin = valeur reçue au `start`, injectée par le module dans sa
  propre route (ex. `/speakdo/bootstrap-proofs/:id` côté Dolibarr) — sert à retrouver quel
  challenge republier si plusieurs bootstraps sont en cours.
- Réponse exigée : HTTP `200`, corps JSON, **exactement** ces trois champs (des champs
  supplémentaires sont tolérés mais ignorés) :

```json
{
  "bootstrap_id": "9c858901-8a57-4791-81fe-4c455b099bc9",
  "challenge": "Kx2vQ8mN4pL7hT1wZeR6yUiO9aS3dFgH",
  "installation_id": "3fa85f64-5717-4562-b3fc-2c963f66afa6"
}
```

  - `bootstrap_id` : recopié tel quel depuis le segment d'URL reçu par le module — comparé en
    égalité stricte à celui de la finalisation en cours (protège contre un mélange entre deux
    bootstraps concurrents sur la même installation).
  - `challenge` : recopié tel quel depuis la réponse du `start` — comparé via `hash_equals()`
    au hash stocké (§2).
  - `installation_id` : recopié tel quel depuis celui envoyé au `start` — comparé en égalité
    stricte à celui stocké sur la ligne de bootstrap.
- `Content-Type` non vérifié strictement par le middleware (cohérent avec le reste de ce
  contrat, cf. section HMAC des autres endpoints), mais `application/json` est la valeur
  attendue en pratique.
- Taille de réponse bornée côté middleware (recommandé : 8 Ko, cohérent avec le traitement des
  autres réponses Dolibarr dans `DolibarrClient`) — une réponse plus grande est un échec de
  preuve, jamais partiellement acceptée.
- Toute divergence sur un des trois champs, un statut HTTP différent de `200`, un corps non-JSON,
  ou un champ manquant produit un échec **indifférencié** côté module (voir §7) — le middleware
  ne précise jamais lequel des trois champs a échoué, pour ne donner aucune information utile à
  un attaquant qui ne contrôle pas réellement l'URL revendiquée.

### 4. Appel de vérification middleware → Dolibarr

Déclenché uniquement par la finalisation (§5), jamais par le `start`. Reproduit exactement le
pattern déjà utilisé par tous les appels sortants de `DolibarrClient` :

1. Re-résolution de l'URL stockée via `UrlGuard::validate()` — **une nouvelle résolution DNS**,
   pas celle mise en cache au `start` : si l'IP a changé entre `start` et `finalize`, c'est la
   nouvelle IP qui est épinglée pour la requête réelle (protection anti DNS-rebinding : l'IP
   contrôlée est celle effectivement contactée, pas une IP potentiellement obsolète).
2. URL assemblée : `{origin}{base_path}/api/index.php/speakdo/bootstrap-proofs/{bootstrap_id}`.
3. Requête `curl` avec les mêmes options que le reste de `DolibarrClient` :
   - `CURLOPT_CUSTOMREQUEST => 'GET'`, `CURLOPT_RETURNTRANSFER => true`
   - `CURLOPT_FOLLOWLOCATION => false`, `CURLOPT_REDIR_PROTOCOLS => 0` (aucune redirection
     suivie, jamais)
   - `CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS`
   - `CURLOPT_SSL_VERIFYPEER => true`, `CURLOPT_SSL_VERIFYHOST => 2`
   - `CURLOPT_CONNECTTIMEOUT` = `dolibarr.connect_timeout` (déf. **3s**)
   - `CURLOPT_TIMEOUT` = `dolibarr.timeout` (déf. **12s**)
   - `CURLOPT_RESOLVE => ["{host}:{port}:{ip fraîchement résolue}"]`
   - Aucun en-tête `DOLAPIKEY` — ce endpoint n'a, par construction, aucun credential Dolibarr à
     ce stade du flux.
4. Décodage JSON (`Json::decodeObject`) ; toute erreur réseau (`curl_errno`), tout statut hors
   `2xx`, tout corps non-JSON, ou toute réponse dépassant la taille bornée (§3) est traité comme
   un échec de vérification — jamais remonté en détail au module (cf. §7, un seul code d'erreur
   générique).
5. Comparaison des trois champs (§3). Un seul champ divergent suffit à l'échec.

### 5. Finalisation — `POST /api/v1/tenants/bootstrap/{bootstrap_id}/finalize`

Route publique (le `bootstrap_id` dans l'URL agit comme jeton de capacité — imprévisible en
pratique, UUID v4 — mais la sécurité réelle du flux repose sur l'appel sortant du middleware
vers l'URL revendiquée, §4, jamais sur la confidentialité de cette route elle-même : un
attaquant qui devinerait un `bootstrap_id` ne pourrait de toute façon rien en tirer sans
contrôler également la réponse HTTP de l'URL Dolibarr enregistrée au `start`). Pas de corps
requis — le `bootstrap_id` de l'URL suffit à retrouver `installation_id` et
`dolibarr_base_url` déjà stockés.

Étapes :

1. **Consommation atomique** de la ligne : `UPDATE tenant_bootstrap_challenges SET
   status='verifying' WHERE bootstrap_id=:id AND status='pending' AND expires_at >
   UTC_TIMESTAMP(6)`, `rowCount()` exigé à `1` (même motif que
   `IdempotencyRepository::begin()`/`McpCredentialRepository::issue()`). Empêche toute double
   finalisation concurrente d'aboutir deux fois. Si `rowCount()===0` : une lecture préalable
   distingue `404 bootstrap_not_found` (id inconnu ou déjà consommé — `status` différent de
   `pending`) de `410 bootstrap_expired` (`status='pending'` mais `expires_at` dépassé).
2. **Vérification** (§4). Échec → ligne passée à `status='failed'` (état **terminal** : un
   `bootstrap_id` en échec ne peut plus jamais être finalisé, même en le retentant — il faut un
   nouveau `start`, donc un nouveau challenge, avant de réessayer) → `422
   bootstrap_verification_failed`.
3. **Succès** → appelle le même chemin de création de tenant que
   `TenantAdminController::upsert()` (génération du secret HMAC via `Ids::opaqueToken(48)`,
   chiffrement `SecretBox`, octroi d'essai gratuit éventuel selon `dolibarr_host` si non déjà
   consommé, etc.), avec en plus la persistance de `installation_id` sur la ligne `tenants`
   nouvellement créée. `tenant_uuid` reste un UUID **indépendant** de `installation_id` — les
   deux ne sont jamais confondus, `installation_id` sert uniquement à la détection "déjà
   enrôlée" du §1. Ligne de bootstrap passée à `status='completed'`, `tenant_id` renseigné (pour
   traçabilité, jamais purgée par le nettoyage périodique, cf. §9).

### 6. TTL et one-shot

- TTL du challenge : `security.tenant_bootstrap_challenge_ttl` (env
  `TENANT_BOOTSTRAP_CHALLENGE_TTL_SECONDS`, défaut **300** — court car il s'agit d'un flux
  interactif déclenché manuellement lors de l'installation du module, à comparer aux 600s de
  `replay_nonce_ttl` pour un mécanisme automatique). Dépassé, `finalize` renvoie `410
  bootstrap_expired` (§5) ; un nouveau `start` est nécessaire.
- One-shot à deux niveaux : la ligne de bootstrap ne peut être consommée qu'une fois
  (`status='pending'` → `'verifying'` par une transition atomique unique, §5.1), et un
  `bootstrap_id` en état `'completed'` ou `'failed'` ne peut plus jamais redéclencher de
  vérification, quel que soit le nombre d'appels ultérieurs à `finalize` avec le même id
  (toujours `404 bootstrap_not_found`, puisque `status != 'pending'`).
- Le `challenge` lui-même n'est jamais réutilisable au-delà de cette unique vérification : il
  n'existe aucun endpoint qui le réémette, et sa valeur en clair n'est jamais conservée après le
  `start` (seul le hash persiste).

### 7. Retour `tenant_id` + `hmac_secret`

Réponse `201` de `finalize` en cas de succès :

```json
{
  "tenant_id": "d290f1ee-6c54-4b01-90e6-d701748f0851",
  "dolibarr_hmac_secret": "Zx8vN2qL...",
  "warning": "Secret affiché une seule fois pour configurer le module Dolibarr. Ne le journalisez pas.",
  "billing_status": "trialing",
  "eligible_until": "2026-09-23 12:35:00.000000",
  "free_trial_granted": 1500000
}
```

Champs strictement alignés sur ceux déjà renvoyés par `TenantAdminController::upsert()` pour un
nouveau tenant (`dolibarr_hmac_secret` + `warning` identiques mot pour mot ; `billing_status`,
`eligible_until`, `free_trial_granted`/`free_trial_already_used` selon la même logique d'essai
gratuit par `dolibarr_host`, cf. `docs/DOLIBARR_MODULE_CONTRACT.md`). Comme pour le
provisioning MCP, `dolibarr_hmac_secret` n'est **jamais** renvoyé par aucun autre endpoint après
cette réponse — le module doit le stocker immédiatement.

### 8. Ce que ce flux ne couvre pas

Chaque installation doit créer et conserver localement un `installation_id` UUID v4 non secret,
avant même le premier `start` (généré une fois, à l'installation du module, jamais régénéré).
Une installation déjà liée renverra `tenant_already_enrolled` (§1) : le secret HMAC historique
ne sera jamais renvoyé par ce flux. Une récupération sûre d'un secret déjà émis (perte du
`.env` du module, par exemple) nécessitera un flux distinct, authentifié et audité — hors
périmètre de ce contrat.

### 9. Schéma nécessaire (migration à venir)

```sql
-- migrations/014_add_tenant_bootstrap_v2.sql (proposé, pas encore écrit)
CREATE TABLE tenant_bootstrap_challenges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bootstrap_id CHAR(36) NOT NULL,
    installation_id CHAR(36) NOT NULL,
    dolibarr_base_url VARCHAR(255) NOT NULL,
    dolibarr_host VARCHAR(255) NOT NULL,
    challenge_hash CHAR(64) NOT NULL,
    status ENUM('pending','verifying','completed','failed') NOT NULL DEFAULT 'pending',
    tenant_id BIGINT UNSIGNED NULL,
    expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_bootstrap_id (bootstrap_id),
    KEY idx_bootstrap_installation (installation_id),
    KEY idx_bootstrap_expiry (expires_at),
    CONSTRAINT fk_bootstrap_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tenants
    ADD COLUMN installation_id CHAR(36) NULL AFTER tenant_uuid,
    ADD UNIQUE KEY uq_tenants_installation (installation_id);
```

`installation_id` nullable sur `tenants` : les tenants déjà provisionnés via le chemin legacy
(§ ci-dessus) n'en ont jamais eu — `NULL` ne viole pas la contrainte `UNIQUE` (comportement
MariaDB déjà exploité ailleurs dans ce schéma, cf. `public_key_fingerprint` de `terminals`).

### 10. Nettoyage

Ajouter une ligne à `bin/cleanup.php`, cohérente avec les entrées déjà présentes
(`replay_nonces`, `idempotency_records`, etc.) :

```php
'tenant_bootstrap_challenges' => "DELETE FROM tenant_bootstrap_challenges WHERE status IN ('pending','failed') AND expires_at < UTC_TIMESTAMP(6)",
```

Les lignes `status='completed'` ne sont **jamais** purgées par ce job : elles tracent quel
bootstrap a produit quel tenant (`tenant_id`), utile en cas d'incident.

### 11. Table des erreurs

| HTTP | code | endpoint | cause |
|---|---|---|---|
| 422 | `invalid_installation_id` | `start` | format UUID v4 invalide |
| 422 | `invalid_dolibarr_url` | `start` | rejetée par `UrlGuard` |
| 409 | `tenant_already_enrolled` | `start` | `installation_id` déjà lié à un tenant actif |
| 404 | `bootstrap_not_found` | `finalize` | `bootstrap_id` inconnu ou déjà consommé (`status != pending`) |
| 410 | `bootstrap_expired` | `finalize` | TTL dépassé, `status` encore `pending` |
| 422 | `bootstrap_verification_failed` | `finalize` | preuve absente/différente/erreur réseau — jamais détaillé plus finement (§3, §4) |

### 12. Audit

Les événements v2 prévus sont `tenant.bootstrap.started` (au `start` réussi, avant émission du
challenge), `tenant.bootstrap.completed` (au `finalize` réussi, avec `tenant_id` en métadonnée)
et `tenant.bootstrap.failed` (à toute finalisation échouée — expiration, non-trouvé, ou preuve
invalide — avec la cause générique mais jamais le challenge en clair ni le secret HMAC).