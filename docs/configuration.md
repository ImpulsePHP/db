# Configuration

Le package lit sa configuration depuis le fichier `impulse.php` a la racine de l'application.

## Exemple SQLite

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

## Exemple MySQL

```php
<?php

return [
    'env' => 'dev',
    'database' => [
        'name' => 'app',
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'user' => 'root',
        'password' => '',
        'database' => 'app',
        'charset' => 'utf8mb4',
    ],
];
```

## Exemple Postgres

```php
<?php

return [
    'env' => 'dev',
    'database' => [
        'name' => 'app',
        'driver' => 'postgres',
        'host' => '127.0.0.1',
        'port' => 5432,
        'user' => 'postgres',
        'password' => 'secret',
        'database' => 'app',
        'schema' => 'public',
    ],
];
```

## Clés importantes

- `env`
  - `dev` active la detection automatique des changements, la generation des migrations et leur application.
  - tout autre environnement desactive ces automatismes.

- `database.name`
  - obligatoire pour la logique de creation / verification de base ;
  - si la clé est absente, le package emet une notification d'erreur visible.

- `database.driver`
  - valeurs supportees : `sqlite`, `mysql`, `postgres`.

- `database.database`
  - pour SQLite : chemin absolu ou relatif vers le fichier `.sqlite` ;
  - pour MySQL / Postgres : nom de la base cible.

## Comportement par driver

### SQLite

- le repertoire parent est cree si necessaire ;
- le fichier SQLite est cree si besoin ;
- les migrations sont ensuite appliquees sur ce fichier.

### MySQL / Postgres

- le package tente d'ouvrir la connexion selon la configuration fournie ;
- si la base cible n'existe pas encore et que la creation est possible avec les identifiants fournis, elle est creee ;
- en cas d'information insuffisante ou invalide, le package echoue explicitement avec une notification developpeur.
