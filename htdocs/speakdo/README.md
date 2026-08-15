# SpeakDo — mini-module Dolibarr 

Ce module est la liaison entre app.speakdo.fr : le compagnon terrain et dolibbarr. Il permet d’enrôler un périphérique mobile pour un utilisateur Dolibarr, et de lui fournir une clé API Dolibarr propre à son compte. Le périphérique peut ensuite interroger l’API Dolibarr pour effectuer des actions métier SpeakDo normalisées.

Version **1.0.0** — compatibilité : Dolibarr 18 à 23, PHP 7.4+ et MariaDB/MySQL..

## Fonctions

- onglet **SpeakDo** sur chaque fiche utilisateur ;
- génération d’un QR code éphémère, à usage unique, lié à l’utilisateur ;
- création automatique d’une clé API Dolibarr propre à l’utilisateur si elle n’existe pas ;
- page globale d’administration des périphériques ;
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
https://app.speakdo.example/enroll/<jeton-opaque>
```

Il ne contient ni URL Dolibarr ni clé API.

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
