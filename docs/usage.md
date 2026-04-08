# Utilisation

## Placer les entites

Le package scanne uniquement le dossier suivant dans l'application hote :

```text
src/Entity
```

Les entites doivent etre autoloadables par Composer.

Exemple minimal :

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Cycle\Annotated\Annotation as Cycle;

#[Cycle\Entity(table: 'users')]
final class User
{
    #[Cycle\Column(type: 'primary')]
    private ?int $id = null;

    #[Cycle\Column(type: 'string')]
    private ?string $email = null;
}
```

## Recuperer les services

### ORM

```php
use Cycle\ORM\ORMInterface;

$orm = $container->get(ORMInterface::class);
$user = $orm->getRepository(\App\Entity\User::class)->findByPK(1);
```

### DatabaseManager

```php
use Cycle\Database\DatabaseManager;

$dbal = $container->get(DatabaseManager::class);
$rows = $dbal->database('default')
    ->query('SELECT * FROM users')
    ->fetchAll();
```

### PDO

```php
use PDO;

$pdo = $container->get(PDO::class);
$statement = $pdo->query('SELECT COUNT(*) FROM users');
```

## Artefacts generes

Le package genere et maintient :

```text
var/db/entities.ast.json
var/db/entities.ast.meta.json
var/db/entities.schema.php
migrations/*.php
```

### `entities.ast.json`

Snapshot lisible du schema compile. Il sert a comparer l'etat precedent et l'etat courant des entites.

### `entities.ast.meta.json`

Contient notamment le fingerprint des fichiers d'entites et un hash de schema pour court-circuiter les recompilations inutiles.

### `entities.schema.php`

Cache PHP du schema Cycle compile, reutilise pour accelerer le boot.

## Notifications

Le package affiche des notifications de succes ou d'erreur :

- `Entites creees`
- `Entites modifiees`
- `Entites supprimees`
- `Migration generee`
- `Migration mise a jour`
- `Migration appliquee`

Sur une application tres AJAX, verifiez que votre runtime renvoie et execute aussi les scripts collectes dans les reponses `/impulse.php`, sinon seules les erreurs transportees par le canal JSON standard peuvent apparaitre.
