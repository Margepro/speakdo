# Contrat exact — API de provisioning MCP (module ERP → middleware)

> Document extrait par lecture directe du code livré au commit `737695a`, à l'usage d'un agent
> travaillant sur le module Dolibarr sans accès au dépôt middleware. Chaque champ ci-dessous est
> vérifié contre le code source cité (fichier + ligne) ; rien n'est reconstitué depuis une
> documentation ou une discussion antérieure. Aucun code n'a été modifié pour produire ce document,
> et aucun commit n'a été fait.
>
> Portée : les trois routes de provisioning direct ERP → middleware. Ne couvre pas
> `POST /api/v1/mcp/enrollments` (enrôlement à jeton, mécanisme différent, non concerné par cette
> demande).

```text
POST   /api/v1/mcp/accesses
GET    /api/v1/mcp/accesses
POST   /api/v1/mcp/accesses/{terminalId}/revoke
```

Sources vérifiées : `src/Http/TenantHmacMiddleware.php`, `src/Security/HmacSigner.php`,
`src/Http/ApiException.php`, `src/Http/JsonResponse.php`, `src/Http/RequestData.php`,
`src/Http/ApiErrorMiddleware.php`, `src/Controller/McpAccessController.php`,
`src/Service/McpEnrollmentService.php`, `src/Repository/TerminalRepository.php`,
`src/Repository/McpCredentialRepository.php`, `src/Repository/OAuthRepository.php`,
`src/Repository/IdempotencyRepository.php`, `src/Support/Ids.php`, `config/mcp.php`,
`bootstrap/app.php`, migrations `001`, `008`, `009`, `010`, `011`, `012`.

---

## 1. Authentification HMAC (module ERP → middleware)

Source : `src/Http/TenantHmacMiddleware.php`, `src/Security/HmacSigner.php`. Les trois routes
utilisent **exactement** ce mécanisme — c'est le même que celui déjà utilisé pour `GET /profiles`.
Il n'y a **aucun** en-tête `X-SpeakDo-Protocol` vérifié par ce middleware (ne pas l'ajouter, il
serait ignoré côté serveur — ne pas s'y fier).

### En-têtes requis (les 4, sinon `401 tenant_signature_missing`)

| En-tête | Contenu | Contrainte |
|---|---|---|
| `X-SpeakDo-Tenant` | UUID du tenant (`tenants.tenant_uuid`) | doit résoudre un tenant existant, sinon `401 invalid_tenant_signature` (même code qu'une signature fausse — jamais de distinction) |
| `X-SpeakDo-Timestamp` | timestamp Unix, chiffres uniquement (`ctype_digit`) | sinon `401 invalid_tenant_timestamp` ; écart toléré avec l'heure serveur : `security.terminal_clock_skew`, défaut **300s**, sinon `401 tenant_timestamp_expired` |
| `X-SpeakDo-Nonce` | chaîne aléatoire, regex `^[A-Za-z0-9_-]{16,190}$` | sinon `401 invalid_tenant_nonce` ; à usage unique, TTL `security.replay_nonce_ttl`, défaut **600s** ; réutilisation → `401 tenant_replay_detected` |
| `X-SpeakDo-Signature` | signature base64 (voir ci-dessous) | sinon `401 invalid_tenant_signature` |

### Canonicalisation exacte

```php
implode("\n", [
    strtoupper($method),           // "POST" ou "GET"
    $path,                          // chemin URI SEUL, sans query string, sans host/scheme
    $timestamp,                     // valeur brute de X-SpeakDo-Timestamp (string de chiffres)
    $nonce,                         // valeur brute de X-SpeakDo-Nonce
    hash('sha256', $body),          // SHA-256 du corps brut, encodé en HEXADÉCIMAL (64 chars), pas en base64
])
```

`$body` = corps brut de la requête tel qu'envoyé (chaîne vide `""` pour `GET` et pour `POST
.../revoke`, qui n'a pas de corps). `hash('sha256', $body)` retourne une chaîne hex de 64
caractères ; c'est **cette chaîne hex**, encore en texte, qui est jointe par `\n` — ne pas la
re-décoder en binaire avant la jointure.

### Signature

```php
$signature = base64_encode(hash_hmac('sha256', $canonical, $secret, true));
```

`$secret` = secret HMAC partagé, déjà connu du module (c'est le même secret que celui utilisé pour
signer les appels existants comme `GET /profiles` — aucun secret distinct n'est introduit par ces
routes). Le serveur essaie le secret courant puis, si présent, un secret précédent (fenêtre de
rotation) — le module n'a rien à faire de spécial pour ça, il signe toujours avec son secret actuel.

### Corps et Content-Type

- Le middleware HMAC ne vérifie **pas** le `Content-Type`.
- `RequestData::json()` (utilisé par `POST /accesses`) ne vérifie pas non plus le `Content-Type` —
  il tente juste de parser le corps comme JSON, et lève `400 invalid_json` si le parsing échoue.
- Envoyer `Content-Type: application/json` reste la pratique attendue, mais ce n'est pas une
  condition de validité de la signature ni de la requête.

### Codes d'erreur de cette couche (s'appliquent aux 3 routes, avant tout traitement métier)

| HTTP | code | cause |
|---|---|---|
| 401 | `tenant_signature_missing` | un des 4 en-têtes est vide/absent |
| 401 | `invalid_tenant_timestamp` | timestamp non numérique |
| 401 | `tenant_timestamp_expired` | écart d'horloge trop grand |
| 401 | `invalid_tenant_nonce` | nonce ne respecte pas la regex |
| 401 | `invalid_tenant_signature` | tenant inconnu OU signature invalide (indistingable, volontairement) |
| 401 | `tenant_replay_detected` | nonce déjà consommé pour ce tenant |

---

## 2. `POST /api/v1/mcp/accesses`

Source : `src/Controller/McpAccessController.php::create()`,
`src/Service/McpEnrollmentService.php::provisionFromErp()` + `createErpAccess()` (lignes
153-324).

### En-têtes

Les 4 en-têtes HMAC (§1) **+** `Idempotency-Key` (obligatoire, sinon `422
idempotency_key_required`, vérifié **avant** même d'appeler le service). Format exigé pour la
valeur de `Idempotency-Key`, vérifié ensuite dans le service : regex `^[A-Za-z0-9._:-]{16,190}$`,
sinon `422 invalid_idempotency_key`.

### Corps JSON — champs

| Champ | Obligatoire | Contrainte / valeurs possibles | Erreur si invalide |
|---|---|---|---|
| `erp_user_id` | oui | entier > 0 (coercition `(int)`, donc `"42"` accepté, `0`/absent/négatif refusé) | `422 invalid_erp_user_id` |
| `client_name` | oui | string, `trim()`, 1 à 190 caractères (`mb_strlen`) — devient l'étiquette affichée (`terminals.display_label`) | `422 invalid_mcp_client` |
| `client_type` | oui | string, `trim()`, 1 à 60 caractères — chaîne libre, **sauf** pour `auth_type=oauth` où elle doit correspondre à un `oauth_clients.client_id` déjà enregistré (voir §7) | `422 invalid_mcp_client` (même code que `client_name`, la validation ne distingue pas lequel des deux a échoué) |
| `auth_type` | oui | exactement `"bearer"` ou `"oauth"` | `422 invalid_mcp_auth_type` |
| `terminal_status` | non, défaut `"active"` | `"active"` ou `"pending_approval"` uniquement (**pas** `"revoked"` en valeur initiale) | `422 invalid_terminal_status` |
| `mcp_enabled` | oui | doit être **strictement** `true` (comparaison `=== true`) ; `mcp_enabled=false` (ou absent, ou `1`, ou `"true"`) → rejet | `403 mcp_not_enabled` |
| `dolibarr_apikey` | oui | string non vide ; chiffré immédiatement (`SecretBox::encrypt`) en `erp_credential_ciphertext`, **jamais renvoyé par aucun endpoint** | `422 missing_erp_credential` |
| `permissions_version` | non | si présent, casté en string ; sinon `null` | — |

Champs non lus par cet endpoint : rien d'autre n'est utilisé, tout champ additionnel envoyé est
silencieusement ignoré.

Ordre effectif des validations (important pour prévoir quel code d'erreur arrive en premier si
plusieurs champs sont invalides) : `Idempotency-Key` présent → format de la clé →
`erp_user_id` → `client_name`/`client_type` → `auth_type` → `terminal_status` → *(ouverture de
l'enregistrement d'idempotence, voir §4)* → `mcp_enabled` → `dolibarr_apikey` → *(si oauth)* OAuth
activé globalement → client OAuth enregistré.

### Idempotence et comportement en retry

Source : `provisionFromErp()` lignes 175-228, `IdempotencyRepository.php`.

- La clé d'idempotence est scoping par **tenant** (pas globale).
- Empreinte comparée entre tentatives (`request_hash`) = `sha256(json_encode([erp_user_id,
  client_name, client_type, auth_type]))`. **Seuls ces 4 champs comptent.** `terminal_status`,
  `mcp_enabled`, `dolibarr_apikey`, `permissions_version` peuvent varier entre deux appels avec la
  même clé sans déclencher de conflit — la valeur retenue sera toujours celle de la **première**
  tentative réussie (les tentatives suivantes avec la même clé ne sont jamais réellement
  ré-exécutées, voir plus bas).
- TTL de la clé : **86400 secondes (24h)** à partir de la première tentative. Passé ce délai, la
  même clé réutilisée n'est plus reconnue comme un retry : un **nouveau** terminal est créé.
- Comportements possibles pour un rejeu de la même clé (même tenant) :
  - **Même hash, succès précédent** → aucune nouvelle écriture en base. La réponse **originale**
    est renvoyée telle quelle, avec un champ supplémentaire `idempotent_replay: true` ajouté au
    JSON. Code HTTP renvoyé : **toujours 201** (le contrôleur force 201 sur le retour du service,
    qu'il s'agisse d'une création réelle ou d'un rejeu).
  - **Même hash, tentative précédente encore "processing"** (fenêtre théorique, appel concurrent) →
    `409 idempotency_in_progress`.
  - **Même hash, tentative précédente échouée définitivement** (ex. `mcp_not_enabled`,
    `oauth_client_not_registered`, etc.) → `409 idempotency_failed_final`, avec dans
    `error.details` le `code`/`message` de l'erreur **originale**. **Important** : une requête
    rejetée pour une raison métier (ex. `mcp_enabled=false`) **consomme quand même la clé**
    d'idempotence pour 24h — renvoyer la même clé après avoir corrigé `mcp_enabled` ne relance
    **pas** une vraie tentative, elle renvoie ce même `409 idempotency_failed_final`. **Le module
    doit générer une nouvelle `Idempotency-Key` pour retenter après correction d'une erreur
    métier**, pas réutiliser l'ancienne.
  - **Hash différent avec la même clé** → `409 idempotency_conflict` (la clé a déjà été utilisée
    pour une intention de provisioning différente).
- Il n'existe **aucun** scénario, dans ce code, produisant un état "échec transitoire"
  (`failed_transient`) — toute erreur métier de ce endpoint est traitée comme définitive. Le module
  n'a donc pas à gérer de logique de retry automatique après un échec réseau côté middleware au-delà
  de la sémantique ci-dessus (un simple retry avec la **même** clé après une vraie coupure réseau,
  où le middleware n'a jamais répondu, est sûr : soit rien n'a été inséré, soit `idempotency_conflict`/
  `idempotency_failed_final`/rejeu s'appliquent selon l'état atteint côté serveur).

### Réponse — `auth_type=bearer` (HTTP 201)

```json
{
  "access_id": "c9d4e2a1-5f3b-4a67-9c1d-2b8e6f7a0d3c",
  "terminal_id": "c9d4e2a1-5f3b-4a67-9c1d-2b8e6f7a0d3c",
  "channel": "mcp",
  "auth_type": "bearer",
  "client_type": "yeastar",
  "client_name": "Yeastar PBX - Standard",
  "mcp_url": "https://exemple.tld/mcp",
  "status": "issued",
  "credentials": {
    "bearer_token": "mcp_v1_d15c49ba076133d5.EW7X5KTKQWwXWo_Vb7J5aB8KbpCsU2zL8XVuAh9v0Xb3X9p5nov5mgLN9gvOQMMv",
    "credential_id": "d15c49ba076133d5"
  },
  "warning": "Ce Bearer SpeakDo n’est affiché qu’une fois. Il ne doit jamais être partagé ni confondu avec un credential ERP."
}
```

Points exacts à retenir :
- `access_id` et `terminal_id` sont **toujours identiques** — il n'existe pas d'entité "access"
  séparée en base ; les deux noms désignent le même `terminals.terminal_uuid`. Les deux clés
  existent dans le JSON, mais c'est une seule et même valeur.
- `status` vaut **toujours** littéralement `"issued"` dans cette branche — ce n'est **pas** le
  `terminal_status` que vous avez envoyé en entrée (qui est `"active"`/`"pending_approval"`).
  `"issued"` est l'état du **credential** (`mcp_credentials.status`), pas celui du terminal. C'est
  une valeur fixe codée en dur (`createErpAccess()` ligne 317), pas un écho.
- `credential_id` : 16 caractères hexadécimaux.
- `bearer_token` : format exact `mcp_v1_<credential_id 16 hex>.<secret opaque base64url, 48 octets
  aléatoires>`. La base ne stocke **que** le SHA-256 de ce token — **aucun endpoint ne le
  re-renverra jamais**. S'il est perdu, la seule option est de révoquer puis re-provisionner.
- `mcp_url` provient de la config serveur `mcp.oauth.resource` (env `MCP_OAUTH_RESOURCE`, sinon
  `APP_URL` + `/mcp`) — **identique** dans les réponses bearer et oauth ; ce n'est pas une valeur
  spécifique à l'accès créé, c'est l'URL unique du endpoint MCP du déploiement.

### Réponse — `auth_type=oauth` (HTTP 201)

```json
{
  "access_id": "f47ac10b-58cc-4372-a567-0e02b2c3d479",
  "terminal_id": "f47ac10b-58cc-4372-a567-0e02b2c3d479",
  "channel": "mcp",
  "auth_type": "oauth",
  "client_type": "claude",
  "client_name": "Claude Desktop - Comptabilite",
  "mcp_url": "https://exemple.tld/mcp",
  "status": "active",
  "credentials": {
    "oauth_client_id": "claude",
    "pairing_code": "sdo_pair_fef48f7f2e75e9eb.8MBTACwG_BCLz9utktcysdRtwfQ54wvCt_J9Ehi5tUg",
    "pairing_expires_in": 900
  },
  "warning": "Le code d’association OAuth est affiché une seule fois. Il sert uniquement au consentement du client MCP interactif."
}
```

Points exacts à retenir :
- `status` ici **échoe directement** le `terminal_status` envoyé en entrée (`"active"` ou
  `"pending_approval"`) — **contrairement** à la branche bearer où `status` est figé à `"issued"`.
  Ce sont deux sémantiques différentes du même nom de champ selon `auth_type` : à ne surtout pas
  confondre côté module.
- `oauth_client_id` == `client_type` envoyé (voir règle exacte en §7) — c'est le
  `oauth_clients.client_id` résolu.
- `pairing_code` : format `sdo_pair_<16 hex>.<secret opaque base64url, 32 octets>`. Montré
  **une seule fois** ici. Il n'est stocké nulle part côté module autrement que ce que le module
  choisit de conserver ; côté middleware il n'est jamais réémis (ni par cet endpoint, ni par
  `GET`). Il sert uniquement à faire consentir un client MCP interactif via `/oauth/authorize` —
  ce n'est pas un jeton d'accès.
- `pairing_expires_in` : secondes, depuis `mcp.oauth.pairing_ttl_seconds` (défaut **900**).

### Caractère one-shot (bearer **et** oauth)

Aucun des deux secrets (`bearer_token`, `pairing_code`) n'est stocké en clair côté middleware ni
récupérable ensuite par quelque endpoint que ce soit — `GET /api/v1/mcp/accesses` renvoie
systématiquement `credential_available: false` (valeur figée, voir §3) et ne contient jamais ces
champs. **Le module doit capturer et transmettre ce secret au moment de la réponse à `POST
/accesses`, il n'aura jamais d'autre occasion de le faire.**

---

## 3. `GET /api/v1/mcp/accesses`

Source : `McpAccessController::index()`, `McpEnrollmentService::listErpAccesses()` (lignes
326-364), `TerminalRepository::findByTenant()`.

### En-têtes

Les 4 en-têtes HMAC uniquement (§1). Pas d'`Idempotency-Key`.

### Query parameters

| Paramètre | Obligatoire | Comportement exact |
|---|---|---|
| `erp_user_id` | non | pris en compte **uniquement** si `ctype_digit((string) $params['erp_user_id'])` est vrai — c'est-à-dire une chaîne de chiffres purs, sans signe, sans espace, sans décimale. Toute autre valeur (`"abc"`, `"-5"`, `"3.0"`, absent) est **silencieusement ignorée**, pas d'erreur 400 : la requête renvoie alors **tous** les accès MCP du tenant, sans filtre. |

### Réponse (HTTP 200)

```json
{
  "accesses": [
    {
      "access_id": "f47ac10b-58cc-4372-a567-0e02b2c3d479",
      "terminal_id": "f47ac10b-58cc-4372-a567-0e02b2c3d479",
      "erp_user_id": 42,
      "client_name": "Claude Desktop - Comptabilite",
      "client_type": "claude",
      "channel": "mcp",
      "auth_type": "oauth",
      "status": "active",
      "last_activity_at": "2026-08-24 09:12:03.000000",
      "oauth_client_id": "claude",
      "credential_available": false
    }
  ]
}
```

Tri : `ORDER BY created_at DESC` (le plus récent en premier). Liste toujours scopée au tenant
authentifié et à `channel = 'mcp'` (les terminaux PWA n'apparaissent jamais ici).

Détail exact de chaque champ :
- `access_id` / `terminal_id` : identiques, `terminals.terminal_uuid`.
- `erp_user_id` : `(int) terminals.dolibarr_user_id`.
- `client_name` : **provient de la colonne `terminals.display_label`**, pas d'une colonne
  littéralement nommée `client_name` — c'est un renommage fait uniquement dans la construction de
  la réponse JSON.
- `client_type` : `terminals.client_type`, tel quel.
- `status` — **sémantique différente selon `auth_type`** :
  - si `auth_type=bearer` : le `status` du credential associé (`mcp_credentials.status`, valeurs
    `issued`|`active`|`revoked`), si un credential existe pour ce terminal ; à défaut (cas non
    attendu en pratique, `createErpAccess()` en crée toujours un), c'est `terminals.status`
    (`pending_approval`|`active`|`revoked`) qui est renvoyé à la place.
  - si `auth_type=oauth` : c'est **directement** `terminals.status`
    (`pending_approval`|`active`|`revoked`) — il n'y a pas de credential OAuth "ligne" séparée à
    ce niveau.
- `last_activity_at` : valeur brute de la colonne `terminals.last_activity_at`
  (`DATETIME(6)`), telle que renvoyée par PDO — **une chaîne native MariaDB, format
  `YYYY-MM-DD HH:MM:SS.ffffff`** (espace comme séparateur, 6 décimales de microsecondes, **pas**
  de `T`, **pas** de suffixe de fuseau, valeur en UTC car écrite via `UTC_TIMESTAMP(6)`). **Ce
  n'est pas de l'ISO-8601** — ne pas parser avec un parseur strict ISO-8601 sans conversion
  préalable. Peut être `null` en théorie (colonne nullable), mais en pratique toujours renseignée
  dès la création (`TerminalRepository::create()` la fixe systématiquement).
- `oauth_client_id` : renseigné **uniquement** si `auth_type=oauth` — et dans ce cas, résultat
  d'une **re-résolution en direct** de `oauth_clients` au moment du listing (pas une valeur
  stockée sur le terminal). Si le client OAuth a depuis été désactivé (`status='disabled'`) ou
  supprimé, ce champ redevient `null` **même si le terminal MCP existe toujours et n'est pas
  révoqué**. Pour `auth_type=bearer`, toujours `null`.
- `credential_available` : **toujours `false`**, sans exception, quel que soit `auth_type` ou
  `status`. C'est un champ figé dans le code actuel — ne pas essayer d'en déduire une signification
  variable.

Absent volontairement de cette réponse : `bearer_token`, `pairing_code`, `dolibarr_apikey`, tout
secret. Aucun endpoint ne les renvoie après leur émission initiale.

---

## 4. `POST /api/v1/mcp/accesses/{id}/revoke`

Source : `McpAccessController::revoke()`, `McpEnrollmentService::revokeErpAccess()` (lignes
371-393).

### Format de `{id}`

`{id}` = `terminal_uuid` (la même valeur que `access_id`/`terminal_id` reçue à la création).
Regex de validation : `^[0-9a-f-]{36}$` (insensible à la casse). **Un id mal formé donne le même
code d'erreur qu'un id inexistant : `404 mcp_access_not_found`** — pas de `400`/`422` distinct
pour un format invalide.

### Requête

Aucun corps requis ni lu — le contrôleur n'appelle jamais `RequestData::json()` pour cette route.
Les 4 en-têtes HMAC suffisent (aucun `Idempotency-Key`).

### Réponse (HTTP 200)

```json
{
  "access_id": "c9d4e2a1-5f3b-4a67-9c1d-2b8e6f7a0d3c",
  "terminal_id": "c9d4e2a1-5f3b-4a67-9c1d-2b8e6f7a0d3c",
  "status": "revoked"
}
```

### Comportement si déjà révoqué / inexistant

- **Inexistant, mauvais tenant, ou terminal non-MCP** (`channel != 'mcp'`) : `404
  mcp_access_not_found` dans les trois cas, **indistinguables** — un id valide appartenant à un
  autre tenant renvoie exactement la même erreur qu'un id qui n'existe pas du tout (jamais de
  `403`, pour ne jamais confirmer l'existence d'une ressource d'un autre tenant).
- **Déjà révoqué** : **aucune erreur**. L'appel réussit à nouveau, renvoie le même `200` avec
  `"status": "revoked"`. Toutes les opérations sous-jacentes (`terminals.status = 'revoked'`,
  `mcp_credentials` → `revoked`, jetons OAuth → révoqués) sont idempotentes par construction
  (leurs clauses `WHERE ... status IN (...)` ne correspondent simplement plus à aucune ligne la
  deuxième fois). **Le module peut appeler cet endpoint plusieurs fois sans risque et sans avoir à
  gérer de cas "déjà révoqué".**

Effets de bord (pour information, pas des champs de réponse) : `terminals.status='revoked'` +
`revoked_at`, credential bearer associé (si `issued`/`active`) → `revoked`, jetons OAuth
access/refresh associés → révoqués (peu importe `auth_type` réel du terminal, les deux
révocations sont tentées sans effet sur celle qui ne s'applique pas), écriture d'un
`audit_events` avec `event_type=mcp.access_revoked`.

---

## 5. Tous les codes HTTP et codes d'erreur applicatifs

Enveloppe d'erreur standard (`src/Http/ApiErrorMiddleware.php`), identique pour les 3 routes :

```json
{
  "ok": false,
  "error": {
    "code": "invalid_mcp_client",
    "message": "message localisé, ne pas parser",
    "details": {},
    "request_id": "..."
  }
}
```

**`error.code` est le seul identifiant stable à tester en code.** `error.message` est traduit
selon la langue de la requête et peut changer de formulation — ne jamais faire de logique dessus.
Les réponses de **succès** n'ont **pas** d'enveloppe `ok` — c'est le payload brut directement
(`access_id`, `accesses`, etc. à la racine du JSON).

| HTTP | code | Routes concernées | Cause |
|---|---|---|---|
| 401 | `tenant_signature_missing` | les 3 | en-tête HMAC manquant |
| 401 | `invalid_tenant_timestamp` | les 3 | timestamp non numérique |
| 401 | `tenant_timestamp_expired` | les 3 | horloge désynchronisée (> 300s par défaut) |
| 401 | `invalid_tenant_nonce` | les 3 | nonce malformé |
| 401 | `invalid_tenant_signature` | les 3 | tenant inconnu ou signature fausse |
| 401 | `tenant_replay_detected` | les 3 | nonce déjà utilisé |
| 400 | `invalid_json` | `POST /accesses` | corps non parsable en JSON |
| 422 | `idempotency_key_required` | `POST /accesses` | en-tête `Idempotency-Key` absent |
| 422 | `invalid_idempotency_key` | `POST /accesses` | format de clé invalide |
| 422 | `invalid_erp_user_id` | `POST /accesses` | `erp_user_id` absent/≤0 |
| 422 | `invalid_mcp_client` | `POST /accesses` | `client_name` ou `client_type` invalide |
| 422 | `invalid_mcp_auth_type` | `POST /accesses` | `auth_type` ni `bearer` ni `oauth` |
| 422 | `invalid_terminal_status` | `POST /accesses` | `terminal_status` hors `active`/`pending_approval` |
| 403 | `mcp_not_enabled` | `POST /accesses` | `mcp_enabled` pas strictement `true` |
| 422 | `missing_erp_credential` | `POST /accesses` | `dolibarr_apikey` absent/vide |
| 503 | `mcp_oauth_disabled` | `POST /accesses` | `auth_type=oauth` alors que `MCP_OAUTH_ENABLED=false` |
| 422 | `oauth_client_not_registered` | `POST /accesses` | `client_type` ne correspond à aucun `oauth_clients` actif |
| 503 | `oauth_not_configured` | `POST /accesses` | dépôt OAuth non câblé côté déploiement (config middleware, pas actionnable par le module) |
| 503 | `mcp_provisioning_not_configured` | `POST /accesses` | dépôts idempotence/audit non câblés (idem, config middleware) |
| 409 | `idempotency_conflict` | `POST /accesses` | même clé, contenu différent |
| 409 | `idempotency_in_progress` | `POST /accesses` | même clé, tentative concurrente en cours |
| 409 | `idempotency_failed_final` | `POST /accesses` | même clé, échec définitif précédent (rejeu de l'erreur d'origine dans `details`) |
| 404 | `mcp_access_not_found` | `POST .../revoke` | id malformé, inexistant, autre tenant, ou non-MCP |

---

## 6. Comportements spécifiques demandés

### `mcp_enabled=false`

Rejet immédiat : `403 mcp_not_enabled`. **Aucun terminal n'est créé.** Mais l'enregistrement
d'idempotence est déjà ouvert à ce stade (l'appel à `begin()` a lieu avant cette vérification) et
passe en `failed_final` : voir la mise en garde du §2 — rejouer la **même** `Idempotency-Key` après
avoir corrigé `mcp_enabled` ne relance pas la tentative, elle rejoue `409
idempotency_failed_final`. Il faut une **nouvelle** clé pour retenter.

### `client_type` OAuth inexistant

`422 oauth_client_not_registered`, message incluant la valeur de `client_type` fournie. Aucun
terminal créé. Ce n'est **pas** un `404` — c'est traité comme une erreur de validation de la
requête. Un client OAuth **désactivé** (`oauth_clients.status='disabled'`) produit exactement la
même erreur qu'un client jamais enregistré (la requête SQL de résolution filtre déjà sur
`status='active'`, donc désactivé ⇔ introuvable de ce point de vue). Résolution : uniquement via
`bin/create-oauth-client.php`, exécuté une fois côté middleware par produit — jamais par cet
endpoint, qui ne crée jamais de nouveau `oauth_clients`.

### OAuth globalement désactivé (`MCP_OAUTH_ENABLED=false`)

`503 mcp_oauth_disabled`, uniquement si `auth_type=oauth` dans la requête. N'affecte en rien les
requêtes `auth_type=bearer`, qui fonctionnent indépendamment de ce flag.

---

## 7. La règle exacte `client_id == client_type`

Il n'existe **aucun champ `client_id`** dans le corps de requête ni dans le modèle `terminals`. Le
module envoie uniquement `client_type` (chaîne libre de son choix, ex. `"claude"`, `"yeastar"`).

Pour `auth_type=oauth` uniquement : ce `client_type` est utilisé **tel quel, verbatim**, comme clé
de recherche dans `oauth_clients.client_id` :

```php
$client = $this->oauth->client($clientType);   // SELECT * FROM oauth_clients WHERE client_id = :client_id AND status = 'active'
```

Autrement dit, **par convention de code (pas par contrainte de schéma), `client_type` EST le
`client_id` OAuth** pour les accès OAuth. Ce n'est jamais un nouveau client créé par cet endpoint —
uniquement une référence résolue vers un client déjà enregistré une fois pour toutes (par produit,
pas par accès) via `bin/create-oauth-client.php`, un outil qui vit côté middleware et n'est jamais
invoqué par ce flux de provisioning. La valeur résolue est renvoyée telle quelle dans
`credentials.oauth_client_id` (création) et `oauth_client_id` (listing).

Pour `auth_type=bearer`, `client_type` n'a **aucune signification spéciale** au-delà d'être stocké
et réaffiché — aucune résolution `oauth_clients` n'a lieu.

---

## 8. Ce que le module peut / ne doit jamais stocker

**Peut stocker** (non sensible, utile pour un écran d'administration des accès MCP) :
`access_id`/`terminal_id`, `client_type`, `client_name`, `auth_type`, `status`, `mcp_url`,
`oauth_client_id`, `credential_id` (l'identifiant de 16 caractères hex — **pas** un secret, il ne
permet aucune authentification à lui seul).

**Doit stocker immédiatement et de façon sécurisée, car irrécupérable ensuite** :
`credentials.bearer_token` (accès bearer) ou `credentials.pairing_code` (accès oauth) — reçus
**une seule fois**, à l'instant de la création. Aucun endpoint de ce contrat ne les renverra
jamais à nouveau (`GET` renvoie systématiquement `credential_available: false`).

**Ne doit jamais faire reposer une logique métier sur** : `last_activity_at` comme signal
d'activité MCP en temps réel — d'après le code (`TerminalRepository`), cette colonne n'est mise à
jour qu'à la création du terminal et lors d'une revalidation live explicite du canal PWA/session ;
rien dans le chemin de traitement MCP actuel (bearer/oauth) ne la met à jour à chaque appel MCP.
Ne pas la présenter à un administrateur comme "dernière utilisation MCP" sans cette réserve.
`credential_available` — toujours `false`, n'encode aucune information exploitable dans ce code.

---

## 9. Isolation tenant / utilisateur

- Le tenant est résolu **exclusivement** via la vérification de signature HMAC
  (`X-SpeakDo-Tenant` + secret partagé) — jamais depuis un champ du corps de la requête. Un
  `tenant_id` qui serait envoyé dans le JSON body n'existe d'ailleurs dans aucun des champs
  acceptés par ces 3 endpoints.
- Les 3 routes scopent systématiquement au tenant ainsi résolu : création sous ce tenant, listing
  filtré `WHERE tenant_id = <résolu>`, révocation qui vérifie `terminal.tenant_id ===
  <résolu>` avant toute action (sinon `404` neutre, jamais `403`, cf. §4).
- **`erp_user_id` (l'utilisateur ERP) n'est PAS revérifié auprès de Dolibarr par ce flux.** C'est
  une différence importante avec `POST /api/v1/mcp/enrollments` (qui, lui, appelle
  `verifyEnrollment()` côté Dolibarr avant de créer quoi que ce soit). Ici, la signature HMAC du
  tenant est considérée comme une preuve suffisante que le module a déjà vérifié lui-même que cet
  `erp_user_id` est légitime et que `mcp_enabled` est vrai pour lui — le middleware fait confiance
  à ce que le module signe. Le module est donc **responsable** de n'appeler `POST /accesses` qu'après
  avoir vérifié côté Dolibarr que l'utilisateur est actif et autorisé.
- Le filtre `erp_user_id` sur `GET` est un simple filtre de confort, pas une frontière
  d'autorisation supplémentaire.

---

## 10. Trois exemples complets, réellement conformes au code

Les valeurs de secret, timestamp et nonce ci-dessous sont des exemples calculés (secret HMAC
factice `example-tenant-hmac-secret-do-not-use-in-prod`) — l'algorithme et le format sont exacts,
seul le secret est fictif. Ils permettent de vérifier bit à bit une implémentation côté module.

### Exemple 1 — Création d'un accès Claude / OAuth

Requête :

```http
POST /api/v1/mcp/accesses HTTP/1.1
Host: exemple.tld
Content-Type: application/json
X-SpeakDo-Tenant: <uuid-du-tenant>
X-SpeakDo-Timestamp: 1755878400
X-SpeakDo-Nonce: b3f1c9a2d4e5f60718293a4b5c6d7e8f
X-SpeakDo-Signature: P5uRScUbbKdUtMQ9Cgw5seMYBfP50qrYYQGuN0p/e/s=
Idempotency-Key: mcp-provision-user42-claude-v1

{"erp_user_id":42,"client_name":"Claude Desktop - Comptabilite","client_type":"claude","auth_type":"oauth","mcp_enabled":true,"dolibarr_apikey":"dolibarr_apikey_value_for_user_42","terminal_status":"active"}
```

Chaîne canonique correspondante (à titre de vérification) :

```text
POST
/api/v1/mcp/accesses
1755878400
b3f1c9a2d4e5f60718293a4b5c6d7e8f
fc3b8bd6536ece2588ea79f8648308ce73dd4e27c3b4134ee71513cec5088503
```

Réponse `201` :

```json
{
  "access_id": "f47ac10b-58cc-4372-a567-0e02b2c3d479",
  "terminal_id": "f47ac10b-58cc-4372-a567-0e02b2c3d479",
  "channel": "mcp",
  "auth_type": "oauth",
  "client_type": "claude",
  "client_name": "Claude Desktop - Comptabilite",
  "mcp_url": "https://exemple.tld/mcp",
  "status": "active",
  "credentials": {
    "oauth_client_id": "claude",
    "pairing_code": "sdo_pair_fef48f7f2e75e9eb.8MBTACwG_BCLz9utktcysdRtwfQ54wvCt_J9Ehi5tUg",
    "pairing_expires_in": 900
  },
  "warning": "Le code d’association OAuth est affiché une seule fois. Il sert uniquement au consentement du client MCP interactif."
}
```

(nécessite qu'un `oauth_clients.client_id = "claude"` actif ait déjà été enregistré une fois côté
middleware via `bin/create-oauth-client.php`, sinon `422 oauth_client_not_registered`).

### Exemple 2 — Création d'un accès Bearer type Yeastar

Requête :

```http
POST /api/v1/mcp/accesses HTTP/1.1
Host: exemple.tld
Content-Type: application/json
X-SpeakDo-Tenant: <uuid-du-tenant>
X-SpeakDo-Timestamp: 1755878460
X-SpeakDo-Nonce: 7c2e9f4a1b8d3560e7f2a9c4b5d6e7f8
X-SpeakDo-Signature: lGZYVJpMhXCdKku8VW8N1vAMgKjZ1EQDaClPBeP2/DI=
Idempotency-Key: mcp-provision-user17-yeastar-v1

{"erp_user_id":17,"client_name":"Yeastar PBX - Standard","client_type":"yeastar","auth_type":"bearer","mcp_enabled":true,"dolibarr_apikey":"dolibarr_apikey_value_for_user_17"}
```

(`terminal_status` omis volontairement dans cet exemple → défaut serveur `"active"`.)

Réponse `201` :

```json
{
  "access_id": "c9d4e2a1-5f3b-4a67-9c1d-2b8e6f7a0d3c",
  "terminal_id": "c9d4e2a1-5f3b-4a67-9c1d-2b8e6f7a0d3c",
  "channel": "mcp",
  "auth_type": "bearer",
  "client_type": "yeastar",
  "client_name": "Yeastar PBX - Standard",
  "mcp_url": "https://exemple.tld/mcp",
  "status": "issued",
  "credentials": {
    "bearer_token": "mcp_v1_d15c49ba076133d5.EW7X5KTKQWwXWo_Vb7J5aB8KbpCsU2zL8XVuAh9v0Xb3X9p5nov5mgLN9gvOQMMv",
    "credential_id": "d15c49ba076133d5"
  },
  "warning": "Ce Bearer SpeakDo n’est affiché qu’une fois. Il ne doit jamais être partagé ni confondu avec un credential ERP."
}
```

### Exemple 3 — Listing puis révocation

Requête (listing filtré sur l'utilisateur 42) :

```http
GET /api/v1/mcp/accesses?erp_user_id=42 HTTP/1.1
Host: exemple.tld
X-SpeakDo-Tenant: <uuid-du-tenant>
X-SpeakDo-Timestamp: 1755878520
X-SpeakDo-Nonce: 4d8a1f6c9b3e0572d4a7f1c8b9e2d5a6
X-SpeakDo-Signature: EhLE1pOtEk5xvIMEHb4GX2LmVdJ0rtdGOwjCmnyWLL8=
```

Canonicalisation : le `path` signé est `/api/v1/mcp/accesses` (la query string `?erp_user_id=42`
**n'entre pas** dans la signature — seul le chemin compte).

Réponse `200` :

```json
{
  "accesses": [
    {
      "access_id": "f47ac10b-58cc-4372-a567-0e02b2c3d479",
      "terminal_id": "f47ac10b-58cc-4372-a567-0e02b2c3d479",
      "erp_user_id": 42,
      "client_name": "Claude Desktop - Comptabilite",
      "client_type": "claude",
      "channel": "mcp",
      "auth_type": "oauth",
      "status": "active",
      "last_activity_at": "2026-08-24 09:12:03.000000",
      "oauth_client_id": "claude",
      "credential_available": false
    }
  ]
}
```

Requête de révocation (sur l'accès `c9d4e2a1-...` créé à l'exemple 2) :

```http
POST /api/v1/mcp/accesses/c9d4e2a1-5f3b-4a67-9c1d-2b8e6f7a0d3c/revoke HTTP/1.1
Host: exemple.tld
X-SpeakDo-Tenant: <uuid-du-tenant>
X-SpeakDo-Timestamp: 1755878580
X-SpeakDo-Nonce: 9e1b4d7a2c5f8036e9a2c5d8b1e4f7a0
X-SpeakDo-Signature: PCVIB7T/Ys01+Hidfs4XRZF65/08XlFIHsoBQa5FbgQ=
```

(pas de corps ; le `{terminalId}` fait partie du `path` signé :
`/api/v1/mcp/accesses/c9d4e2a1-5f3b-4a67-9c1d-2b8e6f7a0d3c/revoke`.)

Réponse `200` :

```json
{
  "access_id": "c9d4e2a1-5f3b-4a67-9c1d-2b8e6f7a0d3c",
  "terminal_id": "c9d4e2a1-5f3b-4a67-9c1d-2b8e6f7a0d3c",
  "status": "revoked"
}
```

Un second appel identique renverrait exactement la même réponse `200` (voir §4).

---

## 11. Informations nécessaires au module Dolibarr et rien de plus

- **Auth** : signer chaque appel avec le secret HMAC tenant déjà partagé (même mécanisme que
  `GET /profiles`) — en-têtes `X-SpeakDo-Tenant`, `X-SpeakDo-Timestamp`, `X-SpeakDo-Nonce`,
  `X-SpeakDo-Signature` ; canonique = `METHOD\nPATH\nTIMESTAMP\nNONCE\nSHA256_HEX(BODY)` ; signature
  = `base64(HMAC_SHA256(canonique, secret))`. `PATH` = chemin seul, sans query string. Pas de
  `Content-Type` imposé, mais utiliser `application/json`.
- **Créer un accès** : `POST /api/v1/mcp/accesses` avec en-tête `Idempotency-Key` (obligatoire,
  16-190 caractères `[A-Za-z0-9._:-]`, stable par intention métier, jamais régénérée pour un simple
  retry réseau — mais **une nouvelle clé après une erreur métier corrigée**). Corps :
  `erp_user_id` (int), `client_name` (1-190 car.), `client_type` (1-60 car., == `client_id` OAuth
  pour `auth_type=oauth`), `auth_type` (`bearer`|`oauth`), `mcp_enabled` (doit être `true`),
  `dolibarr_apikey` (string), `terminal_status` optionnel (`active`|`pending_approval`, défaut
  `active`).
- **Récupérer et stocker immédiatement** `credentials.bearer_token` (bearer) ou
  `credentials.pairing_code` (oauth) — affiché une seule fois, jamais récupérable ensuite.
- **`status`** dans la réponse de création a un sens différent selon `auth_type` : toujours
  `"issued"` pour bearer (état du credential), écho du `terminal_status` envoyé pour oauth (état
  du terminal).
- **Lister** : `GET /api/v1/mcp/accesses?erp_user_id=<int>` (filtre optionnel, silencieusement
  ignoré si non numérique). Ne contient jamais de secret ; `client_name` vient de
  `display_label` ; `oauth_client_id` peut redevenir `null` si le client OAuth a été désactivé
  entre-temps ; `credential_available` est toujours `false` (sans signification exploitable) ;
  `last_activity_at` est une chaîne MariaDB `YYYY-MM-DD HH:MM:SS.ffffff` (pas ISO-8601), et ne
  reflète pas une activité MCP en temps réel.
- **Révoquer** : `POST /api/v1/mcp/accesses/{terminal_id}/revoke`, aucun corps. Idempotent :
  révoquer un accès déjà révoqué ou déjà inexistant renvoie respectivement `200` à nouveau ou
  `404 mcp_access_not_found` — jamais d'erreur "déjà révoqué" spécifique.
- **Erreurs à afficher tel quel à l'administrateur** (via `error.code`, jamais `error.message`) :
  `mcp_not_enabled` (l'utilisateur n'a pas MCP activé), `oauth_client_not_registered` (produit
  OAuth pas encore onboardé côté middleware — contacter l'exploitant du middleware),
  `mcp_oauth_disabled` (OAuth désactivé globalement sur ce déploiement), `missing_erp_credential`
  (la clé API Dolibarr transmise est vide), `idempotency_conflict`/`idempotency_failed_final`
  (retenter avec une nouvelle `Idempotency-Key`).
- **Ne jamais** envoyer de `tenant_id`/`client_id` OAuth séparé dans le corps — il n'existe pas de
  tel champ ; l'isolation tenant vient uniquement de la signature HMAC, et le "client OAuth" est
  désigné uniquement via `client_type`.
