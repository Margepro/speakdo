# SpeakDo pour Dolibarr / SpeakDo for Dolibarr

**SpeakDo — Ce qui se passe sur le terrain finit dans Dolibarr.**
**SpeakDo — What happens in the field ends up in Dolibarr.**

SpeakDo est un compagnon mobile pour Dolibarr permettant d'utiliser son ERP depuis le terrain **par la voix ou le texte**.

SpeakDo is a mobile companion for Dolibarr that lets users interact with their ERP from the field **using voice or text**.

---

# 🇫🇷 Français

## Présentation

SpeakDo permet aux utilisateurs Dolibarr d'effectuer rapidement des actions depuis un smartphone sans avoir à naviguer dans l'ensemble de l'interface ERP.

Depuis la PWA SpeakDo, l'utilisateur peut formuler naturellement une demande, par exemple :

> « Crée une intervention demain à 14 h chez Dupont pour remplacer le routeur. »

> « Ajoute une note sur le client Martin : rappeler lundi pour le devis. »

> « Montre-moi les dernières interventions de Toto Informatique. »

SpeakDo interprète la demande, identifie l'action métier correspondante et utilise l'API REST de Dolibarr pour l'exécuter.

**Dolibarr reste la source de vérité et conserve la maîtrise des utilisateurs, des données et des droits.**

---

## Le rôle de ce module

Ce dépôt contient le **module de liaison officiel entre Dolibarr et SpeakDo**.

Il assure notamment :

* l'enregistrement de l'instance Dolibarr auprès de SpeakDo ;
* l'association de l'installation avec le service SpeakDo ;
* l'association des utilisateurs et de leurs téléphones ;
* la génération des QR codes d'enrôlement ;
* la communication avec l'API REST native de Dolibarr ;
* l'application des droits propres à chaque utilisateur Dolibarr ;
* la gestion et la révocation des terminaux associés ;
* les fonctions nécessaires au dialogue entre Dolibarr et SpeakDo.

Le module ne contient pas le moteur d'intelligence artificielle, le middleware complet ni la PWA SpeakDo.

Ces composants sont fournis par le service SpeakDo.

---

## Fonctionnement

Le principe général est :

```text
Utilisateur
    │
    │ Voix ou texte
    ▼
PWA SpeakDo
    │
    ▼
Service SpeakDo
    │
    │ Compréhension de l'intention
    ▼
Action métier structurée
    │
    ▼
API REST Dolibarr
    │
    ▼
Dolibarr
```

SpeakDo n'est pas un agent disposant d'un accès libre à la base Dolibarr.

Le système utilise un **catalogue d'actions métier défini et contrôlé**.

Le moteur de langage sert principalement à comprendre ce que l'utilisateur souhaite faire et à transformer cette demande en paramètres structurés.

---

## Dolibarr conserve les droits

SpeakDo ne crée pas un second système de permissions.

Un utilisateur SpeakDo reste avant tout un **utilisateur Dolibarr**.

Les actions effectuées utilisent son contexte Dolibarr et sont soumises aux droits qui lui sont accordés dans l'ERP.

Par exemple, si un utilisateur ne possède pas le droit de créer une facture dans Dolibarr, SpeakDo ne doit pas lui permettre de créer cette facture.

```text
Utilisateur Dolibarr
        │
        ▼
      SpeakDo
        │
        ▼
API REST Dolibarr
        │
        ▼
Contrôle des droits Dolibarr
```

---

## Pas de clé SpeakDo à configurer

Une nouvelle installation du module peut s'enregistrer automatiquement auprès de SpeakDo.

Il n'est pas nécessaire de saisir ou d'embarquer une **clé API SpeakDo globale** dans le module.

L'installation suit simplement le processus d'auto-enrôlement prévu par SpeakDo.

```text
Installation du module
        ↓
Activation dans Dolibarr
        ↓
Auto-enrôlement
        ↓
Instance liée à SpeakDo
        ↓
Association des utilisateurs
```

L'administrateur n'a donc pas à demander ou copier manuellement un secret SpeakDo pour commencer.

---

## Association d'un utilisateur

Une fois l'installation configurée, un utilisateur peut associer son téléphone depuis Dolibarr.

```text
Utilisateur Dolibarr
        ↓
Onglet SpeakDo
        ↓
QR code d'enrôlement
        ↓
Téléphone
        ↓
PWA SpeakDo
```

L'utilisateur n'a pas besoin de créer un second compte indépendant avec un nouveau couple identifiant/mot de passe SpeakDo.

---

## Utilisation par la voix et le texte

SpeakDo est conçu avant tout pour les situations où utiliser l'interface complète de Dolibarr serait trop lent ou peu pratique.

Exemples :

### Intervention

> « Crée une intervention mardi à 10 h chez Dupont pour remplacer le switch. »

### Note

> « Ajoute une note : routeur remplacé, VPN testé, tout fonctionne. »

### Rappel

> « Rappelle-moi d'appeler Martin vendredi matin. »

### Recherche

> « Montre-moi les dernières interventions de Dupont Chauffage. »

### Contexte

Une fois un client ou un objet identifié, SpeakDo peut conserver ce contexte pour les demandes suivantes.

---

## Plusieurs langues

SpeakDo peut être utilisé directement dans plusieurs langues :

* 🇫🇷 **Français**
* 🇬🇧 **Anglais**
* 🇪🇸 **Espagnol**
* 🇮🇹 **Italien**
* 🇩🇪 **Allemand**

L'utilisateur peut donc s'adresser à SpeakDo dans la langue correspondant à son usage.

Cette prise en charge concerne notamment l'interaction vocale et textuelle avec SpeakDo.

---

## Confirmation des actions

Pour les opérations qui nécessitent une validation, SpeakDo peut présenter à l'utilisateur ce qu'il a compris avant de réaliser l'écriture dans Dolibarr.

Exemple :

```text
Créer une intervention

Client :
Dupont Chauffage

Date :
18 août 2026 à 14 h

Objet :
Remplacement du routeur

[ Confirmer ]
```

L'objectif est que l'utilisateur puisse vérifier l'action avec des informations métier compréhensibles plutôt qu'avec de simples identifiants techniques.

---

## Sécurité

SpeakDo repose sur quelques principes simples.

### Dolibarr reste l'autorité

Les données métier et les droits utilisateurs restent gérés par Dolibarr.

### Pas de secret global dans le module

Une installation neuve ne nécessite pas de clé SpeakDo commune à toutes les installations.

### Identité propre à chaque installation

Chaque installation SpeakDo est identifiée indépendamment.

### Catalogue d'actions contrôlé

Le modèle de langage ne dispose pas d'un accès arbitraire permettant d'inventer librement des écritures Dolibarr.

### Confirmation

Les actions qui le nécessitent peuvent être confirmées avant leur exécution.

### Révocation

Un terminal précédemment associé peut être révoqué.

---

## Traitement des données

Le module Dolibarr n'embarque pas lui-même de modèle de langage.

Il n'est donc pas nécessaire d'installer localement :

* un LLM ;
* Ollama ;
* un serveur GPU ;
* un moteur de transcription ;
* une clé API d'un fournisseur d'IA.

Ces fonctions sont fournies par l'infrastructure SpeakDo.

SpeakDo cherche à limiter les informations transmises au traitement linguistique à celles nécessaires pour comprendre et exécuter l'action demandée.

Pour les informations détaillées relatives au traitement et à la protection des données, consultez la documentation et les informations légales publiées sur le site SpeakDo.

---

## Prérequis

Le module nécessite notamment :

* une installation Dolibarr compatible ;
* PHP compatible avec la version du module ;
* l'API REST Dolibarr activée ;
* une instance Dolibarr accessible en HTTPS ;
* un navigateur mobile récent.

Pour la version `1.0.0` actuellement publiée :

```text
Dolibarr : V18 à V24
PHP      : >= 8.2
```

Consultez toujours le descripteur du module ou sa fiche DoliStore pour connaître la compatibilité exacte de la version téléchargée.

---

## Installation

Le package Dolibarr respecte la structure :

```text
htdocs/
└── speakdo/
    ├── admin/
    ├── class/
    ├── core/
    ├── langs/
    └── ...
```

Le module doit donc être installé dans :

```text
htdocs/speakdo/
```

Activez ensuite SpeakDo depuis :

```text
Accueil
→ Configuration
→ Modules / Applications
→ SpeakDo
```

---

## DoliStore

Le module peut être téléchargé depuis sa fiche officielle DoliStore :

[SpeakDo — Utilisez Dolibarr par la voix et le texte depuis le terrain](https://www.dolistore.com/product.php?id=3360&title=speakdo-utilisez-dolibarr-par-la-voix-et-le-texte-depuis-le-terrain&l=fr)

---

## Liens officiels

**Site SpeakDo**
https://speakdo.fr

**Application SpeakDo**
https://app.speakdo.fr

**Documentation**
https://docs.speakdo.fr

**API / Middleware SpeakDo**
https://api.speakdo.fr

**DoliStore**
https://www.dolistore.com/product.php?id=3360&title=speakdo-utilisez-dolibarr-par-la-voix-et-le-texte-depuis-le-terrain&l=fr

---

## Construction du package

Le dépôt conserve la structure Dolibarr :

```text
.
├── htdocs/
│   └── speakdo/
├── .github/
├── build-package.sh
└── README.md
```

Le script :

```bash
./build-package.sh
```

récupère automatiquement le numéro de version défini dans le descripteur du module :

```php
$this->version = '1.0.0';
```

et génère :

```text
module_speakdo-1.0.0.zip
```

Le package distribué conserve :

```text
htdocs/speakdo/
```

et exclut les fichiers liés au dépôt Git tels que :

```text
.git/
.github/
.gitignore
.gitattributes
```

---

## Licence

Le module SpeakDo pour Dolibarr contenu dans ce dépôt est distribué sous licence :

**GNU General Public License v3.0 ou ultérieure — GPL-3.0-or-later**

Le module libre et le service SpeakDo hébergé sont deux éléments distincts.

La licence du module ne constitue pas un droit d'accès illimité au service SpeakDo.

SpeakDo est un produit indépendant conçu pour fonctionner avec Dolibarr.

---

# 🇬🇧 English

## Overview

SpeakDo allows Dolibarr users to quickly perform actions from a smartphone without navigating through the complete ERP interface.

From the SpeakDo PWA, users can simply describe what they want to do.

For example:

> “Create an intervention tomorrow at 2 PM for Dupont to replace the router.”

> “Add a note to Martin: call back on Monday about the quotation.”

> “Show me the latest interventions for Toto Informatique.”

SpeakDo interprets the request, identifies the appropriate business action and uses the Dolibarr REST API to perform it.

**Dolibarr remains the source of truth and retains control over users, data and permissions.**

---

## What this module does

This repository contains the **official Dolibarr connector module for SpeakDo**.

It provides the functions required to connect a Dolibarr installation to the SpeakDo service, including:

* registration of the Dolibarr installation;
* connection of the installation to SpeakDo;
* user and device enrolment;
* generation of enrolment QR codes;
* communication with the native Dolibarr REST API;
* enforcement of each user's Dolibarr permissions;
* management and revocation of associated devices;
* the technical integration required between Dolibarr and SpeakDo.

The module does not contain the language model, the complete middleware or the SpeakDo PWA.

Those components are provided by the SpeakDo service.

---

## How it works

The general architecture is:

```text
User
    │
    │ Voice or text
    ▼
SpeakDo PWA
    │
    ▼
SpeakDo service
    │
    │ Intent understanding
    ▼
Structured business action
    │
    ▼
Dolibarr REST API
    │
    ▼
Dolibarr
```

SpeakDo is not an AI agent with unrestricted access to the Dolibarr database.

The system relies on a **defined and controlled catalogue of business actions**.

The language model is primarily used to understand what the user wants to do and convert that request into structured parameters.

---

## Dolibarr remains in control of permissions

SpeakDo does not create a second permission system.

A SpeakDo user remains a **Dolibarr user**.

Actions are performed in the user's Dolibarr context and remain subject to the permissions granted to that user inside the ERP.

For example, if a user is not allowed to create an invoice in Dolibarr, SpeakDo must not give that user the ability to create one.

```text
Dolibarr user
      │
      ▼
   SpeakDo
      │
      ▼
Dolibarr REST API
      │
      ▼
Dolibarr permission checks
```

---

## No SpeakDo API key to configure

A new module installation can automatically register itself with SpeakDo.

There is no global SpeakDo API key that must be embedded in or manually configured in the module before installation.

The installation simply follows the SpeakDo self-enrolment process.

```text
Module installation
        ↓
Activation in Dolibarr
        ↓
Self-enrolment
        ↓
Installation linked to SpeakDo
        ↓
User enrolment
```

The administrator therefore does not need to request or manually copy a SpeakDo secret before getting started.

---

## User enrolment

Once the installation is configured, each user can associate their phone directly from Dolibarr.

```text
Dolibarr user
      ↓
SpeakDo tab
      ↓
Enrolment QR code
      ↓
Smartphone
      ↓
SpeakDo PWA
```

Users do not need to maintain a separate SpeakDo username and password.

---

## Voice and text usage

SpeakDo is designed primarily for situations where using the complete Dolibarr interface would be too slow or inconvenient.

Examples:

### Intervention

> “Create an intervention on Tuesday at 10 AM for Dupont to replace the switch.”

### Note

> “Add a note: router replaced, VPN tested, everything is working.”

### Reminder

> “Remind me to call Martin on Friday morning.”

### Search

> “Show me the latest interventions for Dupont Chauffage.”

### Context

Once a customer or business object has been identified, SpeakDo can retain that context for subsequent requests.

---

## Multiple languages

SpeakDo can be used directly in several languages:

* 🇫🇷 **French**
* 🇬🇧 **English**
* 🇪🇸 **Spanish**
* 🇮🇹 **Italian**
* 🇩🇪 **German**

Users can therefore interact with SpeakDo in the language that best matches their working environment.

This multilingual support includes voice and text interaction with SpeakDo.

---

## Action confirmation

For operations requiring validation, SpeakDo can present what it has understood before writing anything to Dolibarr.

Example:

```text
Create an intervention

Customer:
Dupont Chauffage

Date:
18 August 2026 at 2 PM

Subject:
Router replacement

[ Confirm ]
```

The objective is to show information that users can understand and verify instead of exposing only internal technical identifiers.

---

## Security principles

SpeakDo follows several core principles.

### Dolibarr remains the authority

Business data and user permissions remain managed by Dolibarr.

### No global secret inside the module

A new installation does not require a common SpeakDo key shared by all installations.

### Per-installation identity

Each SpeakDo installation is independently identified.

### Controlled action catalogue

The language model does not have arbitrary access allowing it to freely generate Dolibarr writes.

### Confirmation

Actions can be explicitly confirmed before execution when appropriate.

### Revocation

A previously enrolled device can be revoked.

---

## Data processing

The Dolibarr module does not embed a language model.

There is therefore no need to install locally:

* an LLM;
* Ollama;
* a GPU server;
* a speech-to-text engine;
* an API key from an AI provider.

These capabilities are provided by the SpeakDo infrastructure.

SpeakDo aims to limit information sent for language processing to the context required to understand and execute the requested action.

For detailed information about data processing and privacy, please refer to the documentation and legal information published by SpeakDo.

---

## Requirements

The module requires, among other things:

* a compatible Dolibarr installation;
* a compatible PHP version;
* the Dolibarr REST API enabled;
* a Dolibarr instance accessible over HTTPS;
* a recent mobile browser.

For the currently published `1.0.0` release:

```text
Dolibarr : V18 to V24
PHP      : >= 8.2
```

Always check the module descriptor or the DoliStore product page for the exact compatibility of the version being installed.

---

## Installation

The package follows the standard Dolibarr directory structure:

```text
htdocs/
└── speakdo/
    ├── admin/
    ├── class/
    ├── core/
    ├── langs/
    └── ...
```

The module must therefore be installed under:

```text
htdocs/speakdo/
```

Then enable SpeakDo from:

```text
Home
→ Setup
→ Modules / Applications
→ SpeakDo
```

---

## DoliStore

The module can be downloaded from its official DoliStore page:

[SpeakDo — Use Dolibarr by voice and text from the field](https://www.dolistore.com/product.php?id=3360&title=speakdo-utilisez-dolibarr-par-la-voix-et-le-texte-depuis-le-terrain&l=fr)

---

## Official links

**SpeakDo website**
https://speakdo.fr

**SpeakDo application**
https://app.speakdo.fr

**Documentation**
https://docs.speakdo.fr

**SpeakDo API / Middleware**
https://api.speakdo.fr

**DoliStore**
https://www.dolistore.com/product.php?id=3360&title=speakdo-utilisez-dolibarr-par-la-voix-et-le-texte-depuis-le-terrain&l=fr

---

## Building the package

The repository keeps the Dolibarr directory structure:

```text
.
├── htdocs/
│   └── speakdo/
├── .github/
├── build-package.sh
└── README.md
```

Running:

```bash
./build-package.sh
```

automatically reads the module version from the Dolibarr module descriptor:

```php
$this->version = '1.0.0';
```

and creates:

```text
module_speakdo-1.0.0.zip
```

The distributed archive contains:

```text
htdocs/speakdo/
```

while Git-related development files are excluded, including:

```text
.git/
.github/
.gitignore
.gitattributes
```

---

## License

The SpeakDo module for Dolibarr contained in this repository is distributed under:

**GNU General Public License v3.0 or later — GPL-3.0-or-later**

The open-source Dolibarr module and the hosted SpeakDo service are separate components.

The module licence does not provide unlimited access to the hosted SpeakDo service.

SpeakDo is an independent product designed to work with Dolibarr.

---

© 2026 MargePro — SpeakDo
