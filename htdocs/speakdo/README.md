# SpeakDo — mini-module Dolibarr 

Ce module est la liaison entre app.speakdo.fr : le compagnon terrain et dolibbarr. Il permet d’enrôler un périphérique mobile pour un utilisateur Dolibarr, et de lui fournir une clé API Dolibarr propre à son compte. Le périphérique peut ensuite interroger l’API Dolibarr pour effectuer des actions métier SpeakDo normalisées.

Version **1.0.0** — compatibilité : Dolibarr 18 à 23, PHP 7.4+ et MariaDB/MySQL..

## Fonctions

- onglet **SpeakDo** sur chaque fiche utilisateur ;
- génération d’un QR code éphémère, à usage unique, lié à l’utilisateur, pour le canal **PWA** ou le canal **MCP** ;
- bascule **Accès MCP** par utilisateur (désactivée par défaut), indépendante des droits Dolibarr : elle n’autorise qu’un canal d’accès, elle n’accorde aucun droit métier ;
- création automatique d’une clé API Dolibarr propre à l’utilisateur si elle n’existe pas ;
- page globale d’administration des périphériques, avec le canal (PWA/MCP) affiché pour chacun ;
- révocation d’un périphérique ;
- suppression d’un périphérique déjà révoqué ;
- endpoints REST `speakdo/health`, `speakdo/enrollments/{token}/claim`, `speakdo/devices/{id}` et `touch` ;
- contrôle de l’activation du module REST API Dolibarr ;
- échange d’enrôlement signé par HMAC et protégé contre les rejeux par nonce.

## Installation

1. Copier le dossier `speakdo` dans `htdocs/custom/`.
2. Vérifier que `htdocs/conf/conf.php` autorise le dossier custom.
3. Dans **Accueil > Configuration > Modules/Applications**, activer **API REST**, puis **SpeakDo**.
4. Ouvrir la configuration de SpeakDo.
5. Renseigner l’URL HTTPS de la PWA, par exemple `https://app.speakdo.fr/enroll`.

## Contrat d’enrôlement

Le QR contient uniquement :

```text
https://app.speakdo.example/enroll?tenant_id=<uuid>&token=<jeton-opaque>&channel=pwa|mcp
```

Il ne contient ni URL Dolibarr ni clé API. Le paramètre `channel` est **informatif** (il permet à la PWA/au middleware de choisir le bon flux avant même d’appeler `claim`) : il n’est jamais lu par Dolibarr lors de la consommation du jeton. La valeur qui fait autorité est celle enregistrée dans `llx_speakdo_enrollment.channel` au moment de la génération du QR, fixée server-side et jamais modifiable ensuite ; toute valeur de canal présente dans l’URL, le corps de requête ou envoyée par le middleware est ignorée par `claim`/`verify`.

Un enrôlement `channel=mcp` ne peut être généré que si l’accès MCP est activé pour l’utilisateur cible (bascule sur sa fiche SpeakDo). Cet état est revérifié une seconde fois, sans effet de bord, au moment de la consommation du jeton (fenêtre du TTL du QR) — ce contrôle ponctuel ne remplace pas une vérification d’autorisation par le middleware à chaque usage MCP réel : c’est au middleware de relire l’état courant via `GET /speakdo/v1/users/{id}/capabilities` (champ `mcp_enabled`) avant d’accorder ou de maintenir un accès MCP.

Le middleware appelle ensuite :

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
tenant_id + "\n" + timestamp + "\n" + nonce + "\n" + méthode_HTTP + "\n" + chemin_URL + "\n" + sha256(corps_json_brut)
```

L’appel `claim` est strictement un échange **middleware → Dolibarr**. La réponse n'est jamais relayée à la PWA. Elle contient le `device_id` et la clé API Dolibarr de l’utilisateur. Le middleware chiffre cette clé au repos, et la déchiffre que pour utilisation temporaire. :

```text
DOLAPIKEY: <clé-utilisateur>
```

## Révocation

Le middleware contrôle régulièrement :

```text
GET /api/index.php/speakdo/devices/{device_id}
DOLAPIKEY: <clé-utilisateur>
```

Une révocation de périphérique n’invalide pas la clé API de l’utilisateur, car plusieurs périphériques peuvent partager le même utilisateur. Le middleware refuse une session dès que le statut du terminal n’est plus `ACTIVE`.

Pour invalider absolument tous les accès d’un utilisateur, il faut également régénérer sa clé API Dolibarr depuis sa fiche utilisateur. Cette opération coupe tous ses clients API, pas seulement SpeakDo.

## Limites actuelles

- l’approbation différée d’un téléphone enrôlé par un administrateur pour un tiers n’est pas encore implémentée ;
- les actions métier SpeakDo normalisées seront ajoutées dans une version ultérieure ;
- la suppression est volontairement autorisée seulement après révocation.

## Dette technique connue (hors périmètre de ce commit)

- `speakdo_ensure_user_api_key()` (déclenché depuis la fiche utilisateur) et `Speakdo::doClaimEnrollment()` (déclenché à la consommation du QR) génèrent la clé API Dolibarr de l’utilisateur par deux chemins différents (`bin2hex(random_bytes(32))` vs `getRandomPassword(false)`). À unifier.
- `SPEAKDO_BUILT_IN_ADMIN_TOKEN` (`lib/speakdo.lib.php`) est un secret d’auto-enrôlement tenant codé en dur dans le module, identique sur toutes les installations. À remplacer par un secret propre à chaque installation.
