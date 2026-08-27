# Changelog

Toutes les modifications notables du module Dolibarr **SpeakDo** sont documentées dans ce fichier.

Le versionnage suit le principe `MAJEUR.MINEUR.CORRECTIF`.
Depuis la version initiale `1.0.0`, chaque évolution fonctionnelle ou corrective actée pour le module incrémente le dernier numéro de version.

---

## [1.0.4] - 2026-08-27

### Ajouté

* Ajout de la gestion des **profils métier SpeakDo** directement depuis Dolibarr.
* Ajout d'un profil SpeakDo par utilisateur via l'extrafield `speakdo_profile`.
* Ajout d'un **profil par défaut du tenant** via `SPEAKDO_DEFAULT_PROFILE`.
* Ajout d'une page d'administration **Personnalisation** permettant :

  * de choisir le profil par défaut du tenant ;
  * d'affecter un profil spécifique à chaque utilisateur ;
  * de consulter le catalogue de profils exposé par le middleware ;
  * d'administrer le vocabulaire et la politique sémantique du tenant.
* Ajout de la gestion du vocabulaire métier via les contrats middleware `semantic-policy` et `semantic-actions`.
* Ajout de la résolution du profil effectif selon l'ordre :

  1. profil propre à l'utilisateur ;
  2. profil par défaut du tenant ;
  3. profil `generic`.
* Ajout du contexte de profil :

  * `profile_id` ;
  * `profile_version` ;
  * `profile_scope` ;
  * `profile_missing_modules`.

### Modifié

* L'endpoint `GET /v1/users/{user_id}/capabilities` transmet désormais explicitement le profil effectif au middleware.
* `profile_id` est maintenant toujours présent dans la réponse de capacités.
* Le module peut déterminer si un profil est utilisable en mode :

  * `full` lorsque les modules Dolibarr requis sont disponibles ;
  * `restricted` lorsque certains modules requis sont absents.
* La fiche utilisateur SpeakDo permet désormais de sélectionner le profil métier de l'utilisateur.

### Corrigé

* Correction du cas où un utilisateur disposant d'un profil spécialisé, par exemple `receptionist`, pouvait être interprété côté middleware comme utilisant le profil `generic` faute de `profile_id`.
* Le module n'invente plus de version ou de périmètre de profil lorsque le catalogue middleware est indisponible.
* La validation d'un profil reste possible en mode dégradé sur son format lorsque le middleware est temporairement inaccessible.

---

## [1.0.3] - 2026-08-25

### Ajouté

* Ajout du **bootstrap tenant v2** sans secret administrateur global partagé.
* Ajout d'une preuve de contrôle de l'instance Dolibarr par mécanisme challenge/réponse.
* Ajout de l'endpoint public :

  * `GET /api/index.php/speakdo/bootstrap-proofs/{bootstrap_id}`
* Ajout d'un identifiant d'installation permanent :

  * `SPEAKDO_INSTALLATION_UUID`
* Ajout de trois modes de bootstrap configurables :

  * `auto` ;
  * `v2` ;
  * `legacy`.
* Ajout de l'affichage de l'UUID d'installation et du mode de bootstrap dans la configuration du module.
* Ajout des structures SQL nécessaires au bootstrap tenant v2.

### Modifié

* L'enrôlement automatique du tenant utilise désormais prioritairement le protocole v2.
* En mode `auto`, le repli vers le protocole legacy n'est effectué que lorsque le middleware indique explicitement que le bootstrap v2 n'est pas supporté.
* Le bootstrap legacy est conservé uniquement pour compatibilité avec les anciens déploiements middleware.

### Sécurité

* Suppression de la dépendance nominale à un secret administrateur global embarqué dans le module pour l'enrôlement des nouveaux tenants.
* Activation de la validation TLS (`CURLOPT_SSL_VERIFYPEER` et `CURLOPT_SSL_VERIFYHOST`) sur les échanges de bootstrap.
* Une erreur de sécurité, de preuve, de tenant ou une erreur métier ne provoque plus de repli silencieux vers le mode legacy.
* Le secret legacy reste uniquement disponible comme mécanisme de compatibilité explicitement identifié comme déprécié.

---

## [1.0.2] - 2026-08-24

### Ajouté

* Ajout du **provisioning direct des accès MCP depuis la fiche utilisateur Dolibarr**.
* Ajout d'une bascule **Accès MCP** par utilisateur, désactivée par défaut.
* Ajout d'une section MCP permettant :

  * de lister les accès MCP existants ;
  * de créer un nouvel accès ;
  * de révoquer individuellement un accès.
* Support de deux modes d'authentification MCP :

  * `oauth` pour les assistants interactifs tels que Claude ou ChatGPT ;
  * `bearer` pour les agents, services ou intégrations telles que Yeastar et les automatisations.
* Ajout des appels signés vers le middleware :

  * `POST /api/v1/mcp/accesses` ;
  * `GET /api/v1/mcp/accesses` ;
  * `POST /api/v1/mcp/accesses/{id}/revoke`.
* Ajout d'une gestion d'`Idempotency-Key` pour rendre les créations d'accès résistantes aux retries réseau.
* Ajout de l'affichage **one-shot** :

  * du Bearer Token lors d'une création Bearer ;
  * du code d'association lors d'une création OAuth.

### Modifié

* Le provisioning MCP est désormais distinct du mécanisme d'enrôlement PWA.
* L'ancien mécanisme expérimental d'enrôlement MCP par QR code n'est plus utilisé par l'interface.
* Le QR d'enrôlement est à nouveau réservé au canal PWA.
* La liste des accès MCP est interrogée en direct auprès du middleware et n'est pas dupliquée dans la base Dolibarr.
* La révocation d'un accès MCP est indépendante de la révocation des terminaux PWA de l'utilisateur.

### Sécurité

* Les Bearer Tokens et codes d'association OAuth ne sont jamais stockés dans Dolibarr.
* Les échanges module → middleware réutilisent la signature HMAC tenant avec timestamp et nonce.
* Une nouvelle tentative après une erreur métier reçoit une nouvelle clé d'idempotence.
* Une tentative répétée après une erreur de transport peut réutiliser la même clé d'idempotence afin d'éviter une création en double.
* La bascule MCP n'accorde aucun droit métier supplémentaire : les droits restent ceux du compte Dolibarr et des restrictions SpeakDo associées.

---

## [1.0.1] - 2026-08-24

### Ajouté

* Première prise en charge du **canal MCP** dans le module Dolibarr.
* Ajout de la notion de canal `PWA` / `MCP` dans les structures d'enrôlement et de terminal.
* Ajout d'un indicateur d'autorisation MCP par utilisateur.
* Ajout de `mcp_enabled` dans les capacités utilisateur exposées au middleware.
* Ajout de l'affichage du canal dans l'administration des périphériques.
* Première séparation entre l'autorisation d'utiliser le canal MCP et les droits métier Dolibarr.

### Modifié

* Le module prépare la coexistence entre :

  * l'application SpeakDo PWA ;
  * les clients MCP externes.
* Les valeurs de canal sont déterminées côté serveur et ne peuvent pas être imposées par un paramètre fourni par le client.

### Corrigé

* Correction de compatibilité avec **Dolibarr 18 et 19** lors du chargement des droits utilisateur.
* Le module utilise désormais :

  * `User::loadRights()` lorsque la méthode existe ;
  * `User::getrights()` sur les versions plus anciennes.
* Correction de l'erreur :

  * `Call to undefined method User::loadRights()`
    rencontrée notamment sous Dolibarr 18.

---

## [1.0.0] - 2026-08-15

### Version initiale

Première version publique du module Dolibarr SpeakDo.

### Ajouté

* Compatibilité annoncée avec Dolibarr 18 à 23.
* Compatibilité PHP 7.4+.
* Ajout d'un onglet **SpeakDo** sur les fiches utilisateur Dolibarr.
* Enrôlement d'un terminal PWA par QR code temporaire et à usage unique.
* Association d'un terminal à un utilisateur Dolibarr.
* Création d'une clé API Dolibarr propre à l'utilisateur lorsqu'elle n'existe pas.
* Aucun secret ou clé API Dolibarr exposé dans le QR code.
* Administration globale des périphériques SpeakDo.
* Révocation d'un périphérique.
* Suppression d'un périphérique après révocation.
* Révocation de tous les périphériques d'un utilisateur.
* Endpoint public de santé du module :

  * `GET /api/index.php/speakdo/health`
* Endpoints d'enrôlement, de contrôle des terminaux et de mise à jour d'activité.
* Endpoints versionnés pour :

  * le contrôle de santé ;
  * la vérification d'enrôlement ;
  * l'état des terminaux ;
  * les capacités utilisateur ;
  * l'exécution des actions SpeakDo.
* Signature HMAC des échanges sensibles entre le middleware et le module.
* Protection contre le rejeu par timestamp et nonce.
* Contrôle des droits métier par Dolibarr.
* Catalogue d'actions fermé : le module n'exécute pas de code libre généré par un LLM.
* Page de configuration du module et contrôle de disponibilité de l'API REST Dolibarr.

---

## Convention de version

Pour les versions `1.0.x`, le dernier chiffre est incrémenté à chaque évolution fonctionnelle ou corrective validée du module.

La version correspondant à l'état actuel du code documenté dans ce changelog est donc :

**`1.0.4`**
