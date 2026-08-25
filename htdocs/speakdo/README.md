# SpeakDo — mini-module Dolibarr

Ce module est la liaison entre app.speakdo.fr : le compagnon terrain et dolibbarr. Il permet d’enrôler un périphérique mobile pour un utilisateur Dolibarr, et de lui fournir une clé API Dolibarr propre à son compte. Le périphérique peut ensuite interroger l’API Dolibarr pour effectuer des actions métier SpeakDo normalisées. Il permet également d’administrer, directement depuis Dolibarr, les accès MCP (Claude, ChatGPT, Yeastar, agents/automatisations…) associés à un utilisateur.

Version **1.0.0** — compatibilité : Dolibarr 18 à 23, PHP 7.4+ et MariaDB/MySQL.

## Fonctions

### Application SpeakDo (PWA)

- onglet **SpeakDo** sur chaque fiche utilisateur ;
- génération d’un QR code éphémère, à usage unique, lié à l’utilisateur ;
- création automatique d’une clé API Dolibarr propre à l’utilisateur si elle n’existe pas ;
- page globale d’administration des périphériques ;
- révocation d’un périphérique ;
- suppression d’un périphérique déjà révoqué ;
- endpoints REST `speakdo/health`, `speakdo/enrollments/{token}/claim`, `speakdo/devices/{id}` et `touch` ;
- contrôle de l’activation du module REST API Dolibarr ;
- échange d’enrôlement signé par HMAC et protégé contre les rejeux par nonce.

### Accès MCP

- bascule **Accès MCP** par utilisateur (désactivée par défaut), indépendante des droits Dolibarr : elle n’autorise que la création de nouveaux accès MCP, elle n’accorde aucun droit métier ;
- section **Accès MCP** sur la fiche utilisateur : liste en direct (aucun stockage local), création (OAuth ou Bearer), révocation individuelle ;
- provisioning direct auprès du middleware via `POST /api/v1/mcp/accesses`, `GET /api/v1/mcp/accesses`, `POST /api/v1/mcp/accesses/{id}/revoke`, signés avec le même mécanisme HMAC tenant que les autres appels module → middleware ;
- affichage unique (« one-shot ») du secret Bearer ou du code d’association OAuth au moment de la création — jamais stocké côté Dolibarr, jamais récupérable ensuite.

## Installation

1. Copier le dossier `speakdo` dans `htdocs/custom/`.
2. Vérifier que `htdocs/conf/conf.php` autorise le dossier custom.
3. Dans **Accueil > Configuration > Modules/Applications**, activer **API REST**, puis **SpeakDo**.
4. Ouvrir la configuration de SpeakDo.
5. Renseigner l’URL HTTPS de la PWA, par exemple `https://app.speakdo.fr/enroll`.

## Application SpeakDo (PWA) — contrat d’enrôlement

Inchangé. Le QR contient uniquement :

```text
https://app.speakdo.example/enroll?tenant_id=<uuid>&token=<jeton-opaque>&channel=pwa
```

Il ne contient ni URL Dolibarr ni clé API. Le middleware appelle ensuite :

```text
POST /api/index.php/speakdo/enrollments/{token}/claim
```

Corps JSON attendu :

```json
{
  "label": "Pixel 9 de Thomas",
  "platform": "android",
  "pwa_version": "0.1.0",
  "public_key": "-----BEGIN PUBLIC KEY-----..."
}
```

En-têtes requis :

```text
X-SpeakDo-Tenant: tenant_xxx
X-SpeakDo-Timestamp: 1784275200
X-SpeakDo-Nonce: valeur-aleatoire-unique
X-SpeakDo-Signature: hmac_sha256
Content-Type: application/json
```

Chaîne canonique :

```text
méthode_HTTP + "\n" + chemin_URL + "\n" + timestamp + "\n" + nonce + "\n" + sha256_hex(corps_json_brut)
```

L’appel `claim` est strictement un échange **middleware → Dolibarr**. La réponse n'est jamais relayée à la PWA. Elle contient le `device_id` et la clé API Dolibarr de l’utilisateur. Le middleware chiffre cette clé au repos, et ne la déchiffre que pour utilisation temporaire :

```text
DOLAPIKEY: <clé-utilisateur>
```

### Révocation (PWA)

Le middleware contrôle régulièrement :

```text
GET /api/index.php/speakdo/devices/{device_id}
DOLAPIKEY: <clé-utilisateur>
```

Une révocation de périphérique n’invalide pas la clé API de l’utilisateur, car plusieurs périphériques peuvent partager le même utilisateur. Le middleware refuse une session dès que le statut du terminal n’est plus `ACTIVE`.

Pour invalider absolument tous les accès d’un utilisateur, il faut également régénérer sa clé API Dolibarr depuis sa fiche utilisateur. Cette opération coupe tous ses clients API, pas seulement SpeakDo.

## Accès MCP — provisioning direct

Contrairement à la PWA, un accès MCP n'est **pas** créé via un QR/jeton d'enrôlement consommé par un tiers : la fiche utilisateur Dolibarr crée, liste et révoque directement les accès MCP auprès du middleware. Le contrat exact (headers, canonicalisation, payloads, codes d'erreur, vecteurs de signature d'exemple) est documenté dans `mcp-provisioning-contract-dolibarr-module.md`, extrait du code source du middleware (commit `737695a`).

Un ancien mécanisme d'enrôlement MCP par QR (`channel=mcp` sur `llx_speakdo_enrollment`/`llx_speakdo_device`) a existé brièvement dans une version antérieure de ce module. Il est retiré de l'interface nominale (aucun bouton ne le déclenche plus) au profit du provisioning direct décrit ci-dessous. La colonne `channel` reste présente en base par prudence (changement de schéma non annulé) mais aucune nouvelle ligne `channel=mcp` n'est plus créée par le module.

### Authentification

Même mécanisme HMAC tenant que tous les autres appels module → middleware (`speakdo_middleware_signed_request()`), réutilisé sans modification de la canonicalisation :

```text
canonique = MÉTHODE + "\n" + chemin_seul_sans_query_string + "\n" + timestamp + "\n" + nonce + "\n" + sha256_hex(corps)
signature = base64(hmac_sha256(canonique, secret_tenant))
```

### Création — `POST /api/v1/mcp/accesses`

Requiert un en-tête `Idempotency-Key` (16-190 caractères). Le module génère une clé stable pour une même intention (même utilisateur + mêmes nom/type/authentification) — une nouvelle soumission identique du même formulaire réutilise la même clé (retry réseau sûr). Après une erreur métier (ex. `mcp_not_enabled`), une **nouvelle** clé est générée à la tentative suivante, car le middleware considère la clé déjà consommée pour 24h dans ce cas.

Corps JSON :

```json
{
  "erp_user_id": 42,
  "client_name": "Claude Desktop - Comptabilite",
  "client_type": "claude",
  "auth_type": "oauth",
  "mcp_enabled": true,
  "dolibarr_apikey": "<clé API Dolibarr de l'utilisateur>",
  "terminal_status": "active"
}
```

`auth_type` vaut `oauth` (assistant interactif : Claude, ChatGPT…) ou `bearer` (agent/service : Yeastar, automatisation…). Pour `oauth`, `client_type` doit correspondre à un client OAuth déjà enregistré une fois pour toutes côté middleware (`bin/create-oauth-client.php`) — le module n'en crée jamais.

La réponse contient un secret **affiché une seule fois** :
- `credentials.bearer_token` pour un accès Bearer ;
- `credentials.pairing_code` pour un accès OAuth (code de consentement, pas un jeton d'accès).

Le module ne les stocke jamais : ils sont affichés à l'écran immédiatement après création puis disparaissent définitivement de portée du module.

### Liste — `GET /api/v1/mcp/accesses`

Interrogée en direct à chaque affichage de la fiche utilisateur (aucune donnée MCP stockée localement). `credential_available` vaut toujours `false` ; `last_activity_at` est une chaîne MariaDB `YYYY-MM-DD HH:MM:SS.ffffff` (UTC, pas ISO-8601) reflétant la dernière validation technique du terminal, pas une activité MCP en temps réel.

### Révocation — `POST /api/v1/mcp/accesses/{terminal_id}/revoke`

Individuelle, idempotente côté middleware (rappeler sur un accès déjà révoqué renvoie de nouveau `200`, jamais d'erreur). Révoquer un accès MCP n'affecte ni les autres accès MCP de l'utilisateur, ni son terminal PWA.

## Limites actuelles

- l’approbation différée d’un téléphone enrôlé par un administrateur pour un tiers n’est pas encore implémentée ;
- les actions métier SpeakDo normalisées seront ajoutées dans une version ultérieure ;
- la suppression est volontairement autorisée seulement après révocation ;
- le catalogue de `client_type` OAuth proposé dans l'UI (Claude, ChatGPT) est indicatif : seul le middleware fait autorité sur les clients réellement enregistrés.

## Enrôlement du tenant (bootstrap)

`speakdo_enroll_tenant($db, $entity)` (appelé à l'activation du module et depuis la page de configuration) est un no-op si le tenant est déjà enrôlé, sinon dispatche selon `SPEAKDO_TENANT_BOOTSTRAP_MODE` (`auto` par défaut, `v2` ou `legacy` — réglable sur la page de configuration) :

- **`v2`** (`speakdo_enroll_tenant_v2()`) — sans secret global : preuve de contrôle de cette instance Dolibarr par challenge/réponse (`POST /api/v1/tenants/bootstrap/start` puis `POST /api/v1/tenants/bootstrap/{id}/finalize`), le middleware vérifiant lui-même la preuve en appelant `GET /api/index.php/speakdo/bootstrap-proofs/{bootstrap_id}` (`Speakdo::bootstrapProof()`, endpoint public, ne révèle que `bootstrap_id`/`challenge`/`installation_id`, jamais de secret). Contrat exact : `tenant_boostratp.md`.
- **`legacy`** (`speakdo_enroll_tenant_legacy()`, `@deprecated`) — `POST /internal/v1/tenants` authentifié par `X-SpeakDo-Admin-Token`, un secret partagé codé en dur dans le module (`SPEAKDO_BUILT_IN_ADMIN_TOKEN`), identique sur toutes les installations. Conservé uniquement pour les déploiements middleware n'ayant pas encore v2.
- **`auto`** — tente v2 en premier ; ne bascule sur legacy **que** si le middleware indique explicitement ne pas supporter v2 (réponse 404/501 dont le corps ne correspond à aucune forme du contrat). Toute autre erreur (sécurité, preuve invalide, tenant déjà existant, erreur métier) est remontée telle quelle, sans repli — voir `SpeakDoTenantBootstrapUnsupportedException` dans `lib/speakdo.lib.php`.

Chaque installation génère et conserve un `SPEAKDO_INSTALLATION_UUID` (UUID v4, non secret, jamais régénéré), requis par le bootstrap v2 et visible sur la page de configuration. La validation TLS (`CURLOPT_SSL_VERIFYPEER`/`VERIFYHOST`) est active sur les deux chemins (v2 et legacy) — le chemin legacy la désactivait auparavant sans raison contre un domaine HTTPS public réel.

## Dette technique connue

- `speakdo_ensure_user_api_key()` (déclenché depuis la fiche utilisateur) et `Speakdo::doClaimEnrollment()` (déclenché à la consommation du QR PWA) génèrent la clé API Dolibarr de l’utilisateur par deux chemins différents (`bin2hex(random_bytes(32))` vs `getRandomPassword(false)`). À unifier.
- `SPEAKDO_BUILT_IN_ADMIN_TOKEN` (`lib/speakdo.lib.php`) reste un secret d'auto-enrôlement tenant codé en dur dans le module, identique sur toutes les installations — utilisé uniquement par `speakdo_enroll_tenant_legacy()`, en repli ou en mode `legacy` explicite.
- la colonne `channel` sur `llx_speakdo_enrollment`/`llx_speakdo_device` et le paramètre `$channel` de `speakdo_create_enrollment()`/`doClaimEnrollment()` sont désormais vestigiaux (plus jamais alimentés en `mcp` par l'UI). À retirer dans une passe de nettoyage ultérieure si le mécanisme QR-MCP historique est définitivement abandonné.
- la détection « v2 non supporté » (404/501 sans corps conforme au contrat) est une interprétation de ce module, `tenant_boostratp.md` ne nommant pas explicitement de signal dédié — à confirmer/ajuster si le middleware documente un signal différent.
