# ImpulsePHP DB

`impulsephp/db` fournit l'integration Cycle ORM pour ImpulsePHP, avec decouverte automatique des entites, generation d'un snapshot de schema deterministe et gestion automatique des migrations en environnement de developpement.

## Ce que fait le package

- enregistre `Cycle\Database\DatabaseManager`, `Cycle\ORM\ORMInterface`, `PDO` et `Impulse\Db\Contracts\DbInterface` dans le conteneur ;
- scanne les entites de l'application uniquement dans `src/Entity` ;
- compile un snapshot JSON deterministe de la structure ORM ;
- stocke les artefacts techniques dans `var/db` ;
- genere ou met a jour les migrations dans `migrations/` a la racine de l'application ;
- applique automatiquement les migrations pendantes en `dev` ;
- affiche des notifications developpeur de succes ou d'erreur dans l'interface.

## Prerequis

- PHP 8.2 ou superieur ;
- `ext-pdo` ;
- `impulsephp/core` ;
- une application ImpulsePHP avec un fichier `impulse.php` a la racine.

## Installation

```bash
composer require impulsephp/db
```

Le provider est declare via `extra.impulse-provider`. Si votre application n'utilise pas l'auto-decouverte, ajoutez `Impulse\Db\DbProvider` a votre configuration.

## Configuration minimale

Le package lit la configuration de l'application dans `impulse.php`.

Exemple SQLite :

```php
<?php

return [
    'env' => 'dev',
    'database' => [
        'name' => 'database',
        'driver' => 'sqlite',
        'database' => __DIR__ . '/var/storage/database.sqlite',
    ],
];
```

Clés importantes :

- `env` : active ou non les automatismes de developpement ;
- `database.name` : obligatoire pour le workflow de creation / verification de base ;
- `database.driver` : `sqlite`, `mysql` ou `postgres` ;
- `database.database` : chemin SQLite ou nom logique de base selon le driver.

## Ce que fait le provider

Au boot, `DbProvider` :

- initialise le service `Db` ;
- prepare la connexion a la base ;
- applique les migrations pendantes en `dev` ;
- compile ou recharge le schema Cycle depuis le cache ;
- injecte les notifications dans la reponse HTML ou AJAX.

Services exposes dans le conteneur :

- `Impulse\Db\Contracts\DbInterface`
- `Impulse\Db\Db`
- `Cycle\Database\DatabaseManager`
- `Cycle\ORM\ORMInterface`
- `PDO`

Si `impulsephp/database` est present, le provider expose aussi `Impulse\Database\Contrats\DatabaseInterface` pour rester compatible avec les autres packages.

## Entites et snapshot

Le package scanne uniquement :

```text
src/Entity
```

Les artefacts generes sont stockes dans :

```text
var/db/entities.ast.json
var/db/entities.ast.meta.json
var/db/entities.schema.php
```

Le snapshot JSON contient un etat normalise et deterministe du mapping Cycle, utile pour :

- detecter les ajouts, modifications et suppressions d'entites ;
- comprendre l'etat compile du schema ;
- eviter une recompilation complete a chaque requete en `dev`.

## Migrations

Les migrations sont stockees dans :

```text
migrations/
```

Comportement actuel en `dev` :

- ajout d'une nouvelle entite : creation d'un nouveau fichier de migration ;
- modification ou suppression d'une entite existante : mise a jour de la derniere migration existante ;
- application automatique des migrations pendantes pendant le cycle HTTP.

## Notifications

Le package emet des notifications developpeur globales :

- succes : entites creees / modifiees / supprimees, migration generee ou mise a jour, migration appliquee ;
- erreur : configuration invalide, echec de connexion, echec d'application d'une migration.

Pour les applications majoritairement AJAX, l'overlay de succes suppose que votre runtime `core/js` transporte et execute aussi les scripts collectes dans les reponses AJAX.

## Exemple d'usage

Recuperer l'ORM dans votre application :

```php
use Cycle\ORM\ORMInterface;

$orm = $container->get(ORMInterface::class);
$userRepository = $orm->getRepository(\App\Entity\User::class);
```

Recuperer le gestionnaire de bases :

```php
use Cycle\Database\DatabaseManager;

$dbal = $container->get(DatabaseManager::class);
$database = $dbal->database('default');
```

## Documentation complementaire

Des guides plus detailles sont disponibles dans `docs/` :

- `docs/README.md`
- `docs/installation.md`
- `docs/configuration.md`
- `docs/usage.md`
- `docs/dev_workflow.md`

## Tests

```bash
composer test
```

## Licence

MIT
