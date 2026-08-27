# API HTTP du middleware SpeakDo v0.1.0

Toutes les réponses sont JSON. Les erreurs ont la forme :

```json
{
  "ok": false,
  "error": {
    "code": "error_code",
    "message": "Message lisible",
    "details": {},
    "request_id": "uuid"
  }
}
```

## 1. Santé

### `GET /health/live`

Vérifie uniquement que le processus PHP répond.

### `GET /health/ready`

Vérifie MariaDB et la présence des paramètres indispensables.

## 2. Activation technique d'un tenant

### `POST /internal/v1/tenants`

> **Déprécié — compatibilité seulement.** Cette route exige
> `X-SpeakDo-Admin-Token` et reste temporairement disponible pour les anciens modules.
> `TENANT_BOOTSTRAP_LEGACY_ENABLED=false` la désactive avec le code
> `tenant_bootstrap_legacy_disabled`. Chaque succès est audité avec
> `tenant.bootstrap.legacy` sans enregistrer le jeton ni le secret HMAC.

Elle ne constitue pas le bootstrap recommandé : elle dépend d'un secret global embarqué. Le
bootstrap v2 est implémenté et documenté dans `TENANT_BOOTSTRAP.md` ; il exige une preuve
one-shot récupérée par le middleware auprès du module Dolibarr avant toute création.

Exemple :

```json
{
  "slug": "entreprise-demo",
  "display_name": "Entreprise Démo",
  "dolibarr_base_url": "https://erp.entreprise-demo.fr",
  "dolibarr_hmac_secret": "secret-long-partage-avec-le-module",
  "billing_status": "trialing",
  "eligible_until": "2026-08-17 00:00:00"
}
```

Si `tenant_id` est absent, le middleware en génère un. Si `dolibarr_hmac_secret` est absent, il en génère un et le retourne une seule fois dans la réponse.

Pour modifier l’URL d’un tenant existant, la requête doit contenir `"confirm_url_change": true`. Le middleware normalise et revalide la destination, puis révoque les sessions actives et expire les propositions en attente. Pour une rotation de secret, utiliser `"rotate_secret": true`; le nouveau secret généré n’est renvoyé qu’une fois.

## 3. Enrôlement

### `POST /api/v1/enrollments`

Route non authentifiée, protégée par le jeton QR consommé auprès de Dolibarr.

```json
{
  "tenant_id": "uuid-tenant",
  "enrollment_token": "jeton-opaque-du-qr",
  "terminal_label": "Téléphone chantier Aurélien",
  "public_key_pem": "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----",
  "auth_type": "ecdsa"
}
```

`auth_type` est optionnel, absent → `ecdsa` (compatibilité ascendante avec toute PWA actuelle).
C'est le client qui le déclare ici, jamais le module Dolibarr (voir `DOLIBARR_MODULE_CONTRACT.md`,
section « Le champ `channel` », et `architecture.md` §3.3 quater pour la distinction avec
`channel`). Cette route reste aujourd'hui, mécaniquement, un enrôlement uniquement ECDSA — elle
exige et valide toujours `public_key_pem` — donc toute valeur autre que `ecdsa` est rejetée
(`mcp_auth_type_not_supported`, HTTP 501), pas encore un enrôlement OAuth/Bearer réel.

La réponse contient le token de session opaque. Il n'est jamais renvoyé ultérieurement.

### OAuth MCP interactif

`POST /api/v1/mcp/enrollments` accepte `auth_type: "oauth"` uniquement si l'enrollment ERP
retourne `channel: "mcp"` et `mcp_enabled: true`. Il crée un terminal OAuth et retourne un
`pairing_code` unique et court, jamais une clé ERP.

Les endpoints OAuth standards sont `GET /.well-known/oauth-protected-resource/mcp`,
`GET /.well-known/oauth-authorization-server`, `GET|POST /oauth/authorize` et
`POST /oauth/token`. Le token endpoint accepte seulement `authorization_code` ou
`refresh_token`, exige `resource` et PKCE `S256`; les redirect URI sont exactes.

### `POST /api/v1/mcp/enrollments`

Enrôle un client MCP distant avec un credential SpeakDo. Le client peut déclarer `client_type` et
`auth_type` (`bearer` ou `oauth`), mais ne fournit jamais `channel` : le module
Dolibarr le décide lors de la consommation atomique du token et doit retourner
`channel: "mcp"` avec `mcp_enabled: true`.

```json
{
  "tenant_id": "uuid-tenant",
  "enrollment_token": "jeton-mcp-opaque",
  "terminal_label": "Connecteur distant",
  "client_type": "standard-mcp-client",
  "auth_type": "bearer"
}
```

La réponse HTTP 201 contient une fois `credential`, au format
`mcp_v1_<credential-id>.<secret>`. SpeakDo ne conserve que son SHA-256. Ce secret n'est jamais un
`DOLAPIKEY`, ne possède pas d'expiration absolue dans ce lot (compatibilité clients sans
renouvellement automatique) et suit `issued` (émis), `active` (premier usage réussi), `revoked`.
Il devient inutilisable par révocation du terminal.

## 3 bis. Serveur MCP Streamable HTTP

`POST /mcp` implémente Streamable HTTP avec une réponse JSON synchrone. Les révisions
`2025-11-25` (legacy avec `initialize`) et `2026-07-28` (stateless) sont acceptées. Il exige
`Authorization: Bearer <credential SpeakDo>` avant tout dispatch; `GET /mcp` retourne 405 car ce
catalogue stateless n'ouvre pas de flux SSE. Chaque appel force une revalidation live du terminal,
de l'utilisateur et de `mcp_enabled` côté Dolibarr. `MCP_ENABLED=false` est un kill-switch global;
une origine HTTP fournie doit appartenir à `MCP_ALLOWED_ORIGINS`.

| Tool MCP | action_id SpeakDo |
| --- | --- |
| `thirdparty.search` | `thirdparty.search` |
| `object.get` | `object.get` |
| `intervention.create` | `intervention.create` |
| `action.confirm` / `action.cancel` | confirmation/annulation terminal-bound d'une proposition MCP |

Tous ces parcours passent par `ActionService`, le catalogue fermé, les schémas et les contrôles de
capacités existants. Une écriture crée une proposition figée puis exige `action.confirm` avec son
`confirmation_id`; le terminal authentifié doit être celui qui possède cette proposition. Dolibarr
est appelé avec le credential ERP chiffré du terminal, jamais avec le credential SpeakDo.

## 3 ter. Provisioning ERP des accès MCP

Trois routes réservées au module Dolibarr (`docs/audit-mcp-provisioning-2026-08-25.md`),
authentifiées exactement comme `GET /profiles` (HMAC tenant, `X-SpeakDo-Tenant`/`Timestamp`/
`Nonce`/`Signature`) — jamais par un Bearer MCP ni un token OAuth.

### `POST /api/v1/mcp/accesses`

En-tête requis `Idempotency-Key` (même format que `POST /api/v1/proposals/{id}/confirm`).

```json
{
  "erp_user_id": 17,
  "client_name": "Claude",
  "client_type": "claude",
  "auth_type": "oauth",
  "mcp_enabled": true,
  "dolibarr_apikey": "clé API personnelle de l'utilisateur ci-dessus",
  "terminal_status": "active"
}
```

`auth_type` vaut `bearer` ou `oauth`. Pour `oauth`, `client_type` doit correspondre à un
`oauth_clients.client_id` déjà enregistré (`bin/create-oauth-client.php`) — sinon rejeté en
`oauth_client_not_registered` (422), jamais de client créé à la volée. Réponse 201 :

```json
{
  "access_id": "uuid-terminal",
  "terminal_id": "uuid-terminal",
  "channel": "mcp",
  "auth_type": "oauth",
  "client_type": "claude",
  "client_name": "Claude",
  "mcp_url": "https://api.speakdo.fr/mcp",
  "status": "active",
  "credentials": { "oauth_client_id": "claude", "pairing_code": "sdo_pair_...", "pairing_expires_in": 900 }
}
```

Pour `auth_type=bearer`, `credentials` contient `bearer_token`/`credential_id` et `status` vaut
`issued` (jamais encore utilisé). Le secret n'est rendu qu'une seule fois, dans cette réponse.
Rejouer la même requête avec la même `Idempotency-Key` renvoie exactement le même accès
(`idempotent_replay: true`), sans jamais créer un second terminal ni un second credential.

### `GET /api/v1/mcp/accesses`

Paramètre optionnel `?erp_user_id=`. Retourne la liste des accès MCP du tenant appelant, sans
jamais aucun secret — `credential_available` vaut toujours `false` (le secret n'est plus
reconstructible après sa remise unique).

### `POST /api/v1/mcp/accesses/{terminalId}/revoke`

Révoque le terminal, son credential Bearer et ses tokens OAuth (selon `auth_type`), quel que soit
le mécanisme — un seul service orchestre les deux. Un `terminalId` d'un autre tenant répond 404
(jamais 403, pour ne pas confirmer l'existence de l'accès chez un autre tenant).

## 3 quater. Politique sémantique tenant

Les routes suivantes sont réservées au module Dolibarr et utilisent le même HMAC tenant que
`GET /profiles`. Elles administrent une configuration SpeakDo commune à la PWA et à MCP ; elles
ne modifient ni les droits Dolibarr, ni les manifestes, ni le catalogue fermé d'actions.

### `GET /semantic-policy`

Retourne la révision active et sa policy structurée. Un tenant sans personnalisation reçoit
`revision: 0` et une policy vide sûre.

### `GET /semantic-actions`

Retourne le catalogue fermé d'actions SpeakDo référencables par une policy. Il ne constitue pas
une décision de droit : les capacités sont toujours revérifiées pour l'utilisateur et le terminal
au moment de l'exécution.

### `PUT /semantic-policy`

Publie atomiquement une nouvelle révision. `expected_revision` protège contre l'écrasement d'une
modification concurrente ; une valeur obsolète retourne `409 semantic_policy_revision_conflict`.

```json
{
  "expected_revision": 3,
  "reason": "Ajout des abréviations métier",
  "policy": {
    "schema_version": 1,
    "actions": {"exclude": [], "priority": ["intervention.create"]},
    "lexicon": [
      {"type": "lexical_alias", "locale": "fr", "expression": "DI", "canonical": "demande intervention"},
      {"type": "intent_alias", "locale": "fr", "expression": "faire un DI", "canonical": "créer une intervention", "preferred_action": "intervention.create"}
    ],
    "ui": {"shortcuts": ["intervention.create"]}
  }
}
```

Seuls `lexical_alias` et `intent_alias` sont admis. Toute clé inconnue, URL, caractère de
contrôle, HTML, prompt libre ou action inconnue est rejeté avec `422 invalid_semantic_policy`.

## 4. Authentification des requêtes PWA

Toutes les routes authentifiées utilisent :

```text
Authorization: Bearer TOKEN_SESSION_OPAQUE
X-SpeakDo-Terminal-Id: UUID_TERMINAL
X-SpeakDo-Timestamp: UNIX_SECONDS
X-SpeakDo-Nonce: TOKEN_ALEATOIRE_UNIQUE
X-SpeakDo-Signature: SIGNATURE_BASE64
```

La PWA signe avec la clé privée ECDSA P-256 non extractible. Le middleware conserve uniquement la clé publique.

Chaîne canonique :

```text
METHOD\n
PATH\n
TIMESTAMP\n
NONCE\n
SHA256(BODY_UTF8)\n
SESSION_UUID
```

Le hash du corps vide est celui de la chaîne vide. Le nonce ne peut être utilisé qu'une fois. Le timestamp doit rester dans la fenêtre configurée.

### Exemple Web Crypto simplifié

```javascript
async function signRequest({ privateKey, method, path, body, sessionId }) {
  const encoder = new TextEncoder();
  const timestamp = Math.floor(Date.now() / 1000).toString();
  const nonceBytes = crypto.getRandomValues(new Uint8Array(24));
  const nonce = btoa(String.fromCharCode(...nonceBytes))
    .replaceAll('+', '-')
    .replaceAll('/', '_')
    .replaceAll('=', '');
  const bodyBytes = encoder.encode(body ?? '');
  const bodyHash = await crypto.subtle.digest('SHA-256', bodyBytes);
  const bodyHashHex = [...new Uint8Array(bodyHash)]
    .map((value) => value.toString(16).padStart(2, '0'))
    .join('');
  const canonical = [method.toUpperCase(), path, timestamp, nonce, bodyHashHex, sessionId].join('\n');
  const signature = await crypto.subtle.sign(
    { name: 'ECDSA', hash: 'SHA-256' },
    privateKey,
    encoder.encode(canonical)
  );
  const signatureBase64 = btoa(String.fromCharCode(...new Uint8Array(signature)));

  return { timestamp, nonce, signatureBase64 };
}
```

## 5. Session

### `GET /api/v1/session`

Retourne le tenant, le terminal, l'utilisateur, les capacités et l'expiration du bail courant.

### `POST /api/v1/session/refresh`

Force un contrôle live du terminal et des capacités auprès de Dolibarr.

### `DELETE /api/v1/session`

Révoque la session middleware courante.

## 6. Catalogue

### `GET /api/v1/actions`

Retourne uniquement les actions compatibles avec les capacités de la session.

## 7. Compréhension LLM

### `POST /api/v1/intents`

```json
{
  "utterance": "Crée une tâche remplacer le vase sur le projet 42 vendredi",
  "previous_turns": [
    {"role": "user", "content": "Je suis chez Dupont à Lens"}
  ],
  "candidates": [
    {
      "candidate_ref": "thirdparty:123",
      "display_label": "Jean Dupont",
      "city": "Lens",
      "object_type": "thirdparty",
      "status": "active"
    }
  ],
  "current_object": {
    "candidate_ref": "project:42",
    "display_label": "Entretien chaudière",
    "object_type": "project",
    "status": "open"
  }
}
```

Le middleware ne transmet au LLM que les champs autorisés et tronqués.

Réponses possibles :

- `clarification` ;
- `unsupported` ;
- `executed` pour une lecture ;
- `confirmation_required` avec une proposition figée pour une écriture.

## 8. Exécution déterministe d'une lecture

### `POST /api/v1/actions/{actionId}/execute`

```json
{
  "action_version": 1,
  "arguments": {
    "query": "Dupont Lens",
    "limit": 5
  }
}
```

La route refuse les actions d'écriture.

## 9. Confirmation d'une écriture

### `POST /api/v1/proposals/{proposalId}/confirm`

Header obligatoire :

```text
Idempotency-Key: 019b2b7f-6d44-7f3a-b99e-1f83e8c0e95d
```

Le middleware exécute exactement le snapshot stocké. Aucun argument métier n'est relu depuis la requête de confirmation.

### `POST /api/v1/proposals/{proposalId}/cancel`

Annule une proposition encore en attente.

## 10. Transcription

### `POST /api/v1/transcriptions`

Requête `multipart/form-data` avec un champ fichier `audio`. Cette route possède une limite dédiée fondée sur `AUDIO_MAX_BYTES`, distincte de la limite des corps JSON.

La réponse ne renvoie que le texte, la langue et la durée. Le fichier temporaire est supprimé immédiatement après l'appel OVHcloud.

## 11. Webhook de facturation générique

### `POST /webhooks/billing`

Header :

```text
X-SpeakDo-Billing-Signature: sha256=HMAC_HEX_DU_CORPS_BRUT
```

Corps :

```json
{
  "event_id": "evt_123",
  "event_type": "subscription.updated",
  "tenant_id": "uuid-tenant",
  "billing_status": "active",
  "eligible_until": "2026-09-01 00:00:00",
  "grace_until": null,
  "customer_ref": "customer_x",
  "subscription_ref": "subscription_y"
}
```

Le traitement est idempotent par `event_id`.
