# Documentation DB

Cette documentation detaille l'integration de `impulsephp/db` dans une application ImpulsePHP.

## Guides disponibles

- [Installation](./installation.md)
- [Configuration](./configuration.md)
- [Utilisation](./usage.md)
- [Workflow de developpement](./dev_workflow.md)

## En resume

Le package fournit :

- l'integration Cycle ORM / DBAL ;
- la decouverte des entites sous `src/Entity` ;
- un snapshot de schema dans `var/db` ;
- des migrations dans `migrations/` ;
- l'application automatique des migrations en environnement `dev` ;
- des notifications developpeur dans l'interface.
