# GLPI NinjaOne Connector

Plugin GLPI 11 pour synchroniser l'inventaire NinjaOne vers les assets natifs GLPI.

L'objectif est de garder GLPI comme referentiel ITSM / asset management, tout en utilisant NinjaOne comme source d'inventaire endpoint, sans installer GLPI Agent sur les postes.

## Fonctionnalites

- Connexion a l'API NinjaOne via OAuth2.
- Support du flux `Client Credentials` pour les applications Services API / machine-to-machine.
- Decouverte des organisations et locations NinjaOne.
- Mapping explicite des organisations NinjaOne vers les entites GLPI.
- Mapping explicite des locations NinjaOne vers les lieux GLPI.
- Creation et mise a jour des ordinateurs GLPI natifs.
- Synchronisation de donnees inventaire utiles : fabricant, modele, type, numero de serie, UUID, asset tag, OS, reseau, logiciels et utilisateur principal quand les donnees sont disponibles.
- Journalisation des synchronisations.
- Action automatique GLPI `NinjaoneSync` pour les synchronisations planifiees.
- Onglet NinjaOne sur les ordinateurs lies, avec lien direct vers NinjaOne et payload brut de reference.

## Garde-fous

Le plugin ne synchronise pas automatiquement toutes les organisations NinjaOne.

La sequence attendue est :

1. Configurer la connexion NinjaOne.
2. Lancer une premiere synchronisation pour decouvrir les organisations.
3. Mapper et activer explicitement les organisations a synchroniser.
4. Mapper les locations si necessaire.
5. Lancer la synchronisation des assets.

Les organisations et locations decouvertes sont desactivees par defaut tant qu'un administrateur GLPI ne les active pas.

## Prerequis

- GLPI 11.x
- PHP 8.2+
- Extensions PHP : `curl`, `json`, `openssl`
- Acces HTTPS sortant depuis le serveur GLPI vers NinjaOne
- Application NinjaOne Services API avec scope `Monitoring` / `Surveillance`

## Installation

Copier le dossier du plugin dans l'instance GLPI :

```text
GLPI/
  plugins/
    ninjaone/
      setup.php
      hook.php
      src/
      front/
      locales/
```

Le nom du dossier doit rester `ninjaone`.

Depuis GLPI :

1. Aller dans `Configuration > Plugins`.
2. Installer `NinjaOne connector`.
3. Activer le plugin.
4. Ouvrir la page de configuration du plugin.
5. Ajouter une connexion NinjaOne.

## Configuration NinjaOne

Configuration recommandee :

- Application platform : `Services API` ou `API Services (machine-to-machine)`
- Grant type : `Client Credentials`
- Scope : `Monitoring` / `Surveillance`
- Base URL selon la region NinjaOne, par exemple `https://eu.ninjarmm.com`

Si NinjaOne demande une URI de redirection, utiliser :

```text
https://votre-glpi.example/plugins/ninjaone/front/oauth.callback.php
```

## Synchronisation automatique

Le plugin declare l'action automatique GLPI :

- itemtype : `GlpiPlugin\Ninjaone\Cron\NinjaOneSync`
- nom : `NinjaoneSync`
- methode : `cronNinjaoneSync`

En production, configurer le cron GLPI de maniere reguliere :

```bash
php GLPI/front/cron.php
```

La planification fine se configure ensuite par connexion NinjaOne dans la page du plugin.

## Donnees GLPI creees ou mises a jour

Le plugin cible les objets GLPI natifs quand c'est possible :

- `Computer`
- systeme d'exploitation lie a l'ordinateur
- ports reseau, noms reseau, IP et FQDN quand le schema GLPI le permet
- logiciels, versions et liaisons ordinateur-version
- composants materiels quand les tables/champs GLPI attendus sont disponibles

Les donnees NinjaOne sans cible GLPI fiable restent conservees dans les tables du plugin, notamment dans le payload du device, pour diagnostic et enrichissements futurs.

## Limites connues

- Les suppressions NinjaOne ne suppriment pas automatiquement les assets GLPI.
- Les tickets GLPI depuis les alertes NinjaOne ne sont pas crees automatiquement.
- Le chiffrement applicatif du `client_secret` reste a durcir avant une diffusion large.
- Une validation sur instance GLPI 11 et tenant NinjaOne reel est recommandee avant production.

## Licence

GPL-3.0-or-later.
