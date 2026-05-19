# GLPI NinjaOne Connector

Plugin GLPI 11 permettant de connecter NinjaOne a GLPI pour decouvrir les organisations, mapper les environnements et synchroniser les postes dans les assets natifs GLPI.

Le plugin est concu pour garder GLPI comme referentiel ITSM / asset management, tout en utilisant NinjaOne comme source RMM et comme point d'automatisation.

## Fonctions principales

- Connexion a NinjaOne via une application API.
- Tableau de bord avec vignettes : connexions declarees, connexions actives, organisations actives, lieux mappes, ordinateurs lies et liaisons en attente.
- Decouverte des organisations et locations NinjaOne.
- Mode organisation unique ou multi-organisation.
- Mapping explicite des organisations NinjaOne vers les entites GLPI.
- Mapping explicite des locations NinjaOne vers les lieux GLPI.
- Creation et mise a jour des ordinateurs GLPI natifs.
- Deux modes de synchronisation d'inventaire :
  - **Synchronisation avancee** : GLPI Agent portable est lance via NinjaOne Automation et GLPI Agent reste la source d'inventaire materiel, OS et logiciels.
  - **Synchronisation minimale** : l'inventaire est base sur les donnees NinjaOne.
- Onglet NinjaOne sur les fiches ordinateur liees.
- Bouton direct pour ouvrir l'ordinateur dans NinjaOne.
- Logs de synchronisation et purge automatique des anciens logs.
- Synchronisation automatique deux fois par jour, avec bouton de synchronisation forcee.

## Prerequis

- GLPI 11.x.
- PHP 8.2 ou superieur.
- Extensions PHP : `curl`, `json`, `openssl`.
- Une instance NinjaOne accessible.
- Une application API NinjaOne avec les droits de lecture necessaires.
- Acces HTTPS sortant du serveur GLPI vers NinjaOne.
- Droits administrateur GLPI pour installer et configurer le plugin.

## Installation rapide

1. Dezipper le plugin dans le repertoire `marketplace` de GLPI :

```text
GLPI/
  marketplace/
    ninjaone/
      setup.php
      hook.php
      src/
      front/
      locales/
```

2. Aller dans `Configuration > Plugins`.
3. Installer puis activer **NinjaOne connector**.
4. Ouvrir la page de configuration du plugin.

## Configuration NinjaOne

Dans NinjaOne, creer une application API dediee au connecteur GLPI.

Configuration recommandee :

- Type d'application : `Services API` ou `API Services`.
- Mode d'authentification : `Client Credentials`.
- Scopes : au minimum les droits de lecture/inventaire necessaires, selon votre tenant NinjaOne.
- Conserver le `Client ID`.
- Generer et conserver le `Client secret`.
- Noter l'URL de base de votre region NinjaOne, par exemple :

```text
https://eu.ninjarmm.com
```

Si NinjaOne demande une URI de redirection OAuth, utiliser :

```text
https://votre-glpi.example/marketplace/ninjaone/front/oauth.callback.php
```
<img width="686" height="487" alt="image" src="https://github.com/user-attachments/assets/0aa90821-f13c-455a-9d66-eeed5c25ba96" />

Adaptez le chemin si votre instance expose les plugins via `/plugins/ninjaone/`.

## Connexion GLPI

Dans GLPI, ouvrir la page du plugin puis cliquer sur **Ajouter une connexion NinjaOne**.
<img width="1624" height="156" alt="image" src="https://github.com/user-attachments/assets/60bed12a-a059-4cf3-a3b1-8be88ca8c0c1" />

Renseigner :

- nom de la connexion ;
- URL de base NinjaOne ;
- scopes ;
- `Client ID` ;
- `Client secret` ;
- URL de redirection si necessaire ;
- etat actif ou inactif.
<img width="1634" height="480" alt="image" src="https://github.com/user-attachments/assets/9361e663-d3b1-4f02-bbf3-4f7f6a67b676" />

Le bloc **Connexion NinjaOne** permet ensuite de :

- sauvegarder la connexion ;
- tester la connexion API ;
- lancer une synchronisation manuelle.
<img width="685" height="139" alt="image" src="https://github.com/user-attachments/assets/328cc14a-8f4e-4d41-a5ff-5bd0ca8e31a6" />

## Organisations

Le plugin ne synchronise pas automatiquement toutes les organisations NinjaOne.

La sequence recommandee est :

1. Creer la connexion NinjaOne.
2. Lancer une premiere synchronisation pour decouvrir les organisations.
3. Choisir le mode d'organisation.
4. Mapper les organisations vers les entites GLPI.
5. Activer uniquement les organisations a synchroniser.

Deux modes sont disponibles :

- **Organisation unique** : une seule organisation NinjaOne est associee a une entite GLPI.
- **Multi-organisation** : plusieurs organisations NinjaOne peuvent etre mappees separement vers des entites GLPI.

En mode multi-organisation, les boutons **Mapper les organisations** et **Mapper les lieux** apparaissent quand des organisations ont ete decouvertes.

Les organisations nouvellement decouvertes sont creees desactivees par defaut. Elles doivent etre explicitement activees par un administrateur.

## Mapping des lieux

Les locations NinjaOne peuvent etre associees a des lieux GLPI.

Le mapping des lieux sert notamment a renseigner le champ lieu des ordinateurs GLPI lors de la synchronisation. Un lieu peut etre selectionne manuellement ou cree depuis la page de mapping lorsque l'organisation parent est deja associee a une entite GLPI.
<img width="1632" height="659" alt="image" src="https://github.com/user-attachments/assets/e277cfe1-62d1-4b38-87c0-acfdc988c959" />

## Source d'inventaire

Le bloc **Source d'inventaire** permet de choisir le comportement du connecteur.

### Synchronisation avancee

Libelle dans GLPI :

```text
Synchronisation avancee - l'inventaire GLPI Agent via NinjaOne Automation
```

Dans ce mode :

- NinjaOne sert a identifier le poste, l'organisation, le lieu et le dernier contact.
- GLPI Agent portable est lance via NinjaOne Automation.
- GLPI Agent reste responsable de l'inventaire materiel, OS et logiciels.
- Le plugin expose un generateur de script PowerShell pour NinjaOne Automation.

Ce mode est recommande si vous voulez garder une qualite d'inventaire GLPI Agent sans installer un agent permanent sur chaque poste.
En activant ce mode le bouton **Générer le script d'automation NinjaOne** apparait, il vous permet de preparer les variables pour créer un script PowerShell qui pourra être executé ou planifié comme tâche d'automation sur NinjaOne. Pour eviter d'installer un agent supplementaire sur un poste client ce mode de fonctionnement telecharge depuis la source de votre choix le client portable de l'agent GLPI et execute simple un inventaire et un envoi sur votre serveur glpi du resultat pour faire l'association avec equipement.

<img width="1621" height="573" alt="image" src="https://github.com/user-attachments/assets/0aba7b3f-bd52-46ea-a324-53892c820d9a" />

### Synchronisation minimale

Libelle dans GLPI :

```text
Synchronisation minimale - l'inventaire base sur NinjaOne
```

Dans ce mode :

- le plugin utilise les donnees NinjaOne pour creer ou mettre a jour les ordinateurs GLPI ;
- les donnees disponibles dependent des endpoints et rapports NinjaOne accessibles sur le tenant.

## Synchronisation automatique et logs

Le plugin declare deux actions automatiques GLPI.

### Synchronisation

- Action automatique : `NinjaoneSync`.
- Classe : `GlpiPlugin\Ninjaone\Cron\NinjaOneSync`.
- Methode : `cronNinjaoneSync`.
- Frequence : toutes les 12 heures, soit deux fois par jour.

Une synchronisation immediate peut aussi etre forcee :

- depuis la page principale du plugin ;
- depuis la fiche d'une connexion NinjaOne.

### Purge des logs

- Action automatique : `NinjaoneLogPurge`.
- Classe : `GlpiPlugin\Ninjaone\Cron\NinjaOneLogPurge`.
- Methode : `cronNinjaoneLogPurge`.
- Frequence : tous les 3 jours.
- Retention : suppression des logs de synchronisation de plus de 30 jours.

La page de configuration donne acces aux logs et aux actions automatiques GLPI associees.

En production, le cron systeme de GLPI doit etre configure correctement, par exemple :

```bash
php GLPI/front/cron.php
```

## Tableau de bord

La page principale du plugin affiche des vignettes de synthese :

- connexions declarees ;
- connexions actives ;
- organisations actives ;
- lieux mappes ;
- ordinateurs lies ;
- liaisons en attente.

Sous ces vignettes, l'administrateur peut :

- ajouter une connexion NinjaOne ;
- lancer une synchronisation immediate si au moins une connexion existe.

## Fiche ordinateur

Lorsqu'un ordinateur GLPI est lie a un device NinjaOne, le plugin ajoute un bloc et un onglet NinjaOne sur la fiche ordinateur.

Le bloc affiche notamment :

- l'ID NinjaOne ;
- l'etat de liaison ;
- la derniere synchronisation ;
- le dernier contact NinjaOne ;
- la configuration utilisee.

Un bouton **Ouvrir dans NinjaOne** permet d'ouvrir directement la fiche du device dans NinjaOne.
<img width="1255" height="375" alt="image" src="https://github.com/user-attachments/assets/229de702-0559-4455-a7f4-a807e3f7707f" />

La pastille d'etat est volontairement centree sur le mapping de l'ordinateur :

- erreur de liaison si le mapping de ce poste est en echec ;
- liaison en attente si le poste n'est pas encore correctement associe ;
- lie a NinjaOne en mode GLPI Agent ;
- inventaire NinjaOne en mode synchronisation minimale ;
- synchro ancienne uniquement en mode inventaire NinjaOne, selon le seuil configure.

## Securite

- Restreindre l'acces au plugin aux administrateurs GLPI autorises.
- Restreindre l'acces base de donnees aux comptes techniques strictement necessaires.
- Les secrets NinjaOne (`client_secret`, jetons OAuth) sont stockes dans les tables du plugin.
- Utiliser HTTPS entre GLPI, les navigateurs administrateurs et NinjaOne.
- Verifier les scopes NinjaOne pour n'accorder que les permissions necessaires.

## Limites connues

- Les suppressions NinjaOne ne suppriment pas automatiquement les assets GLPI.
- Les tickets GLPI depuis les alertes NinjaOne ne sont pas crees automatiquement.
- Le chiffrement applicatif des secrets NinjaOne reste a durcir avant une diffusion large.
- Une validation sur instance GLPI 11 et tenant NinjaOne reel est recommandee avant production.

## Licence

GPL-3.0-or-later.
