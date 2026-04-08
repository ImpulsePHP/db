# Installation

## Prerequis

- PHP 8.2 ou superieur
- extension `pdo`
- une application ImpulsePHP fonctionnelle

## Installation avec Composer

```bash
composer require impulsephp/db
```

Le package declare son provider dans `extra.impulse-provider`. Si votre application ne gere pas l'auto-decouverte, ajoutez manuellement :

```php
Impulse\Db\DbProvider::class
```

## Ce que le package enregistre

Une fois charge, le provider rend disponibles :

- `Impulse\Db\Contracts\DbInterface`
- `Impulse\Db\Db`
- `Cycle\Database\DatabaseManager`
- `Cycle\ORM\ORMInterface`
- `PDO`

Si `impulsephp/database` est installe, le package fournit aussi un bridge vers `Impulse\Database\Contrats\DatabaseInterface`.

## Premiere verification

Avec une configuration valide et au moins une entite dans `src/Entity`, un premier chargement de page en `dev` doit produire :

- `var/db/entities.ast.json`
- `var/db/entities.ast.meta.json`
- `var/db/entities.schema.php`
- un dossier `migrations/` si une migration est necessaire

Pour verifier rapidement le package en local :

```bash
composer test
```
