# Workflow de developpement

## Ce qui se passe en `dev`

A chaque boot utile, le package suit ce flux :

1. calcule un fingerprint stable du dossier `src/Entity` ;
2. recharge le schema depuis `var/db/entities.schema.php` si rien n'a change ;
3. sinon recompile le schema Cycle ;
4. compare le snapshot courant a `var/db/entities.ast.json` ;
5. genere ou met a jour une migration si le schema a change ;
6. applique automatiquement les migrations pendantes ;
7. affiche une notification developpeur.

## Strategie actuelle de migration

Le comportement actuel du package est le suivant :

- ajout d'une nouvelle entite :
  - creation d'un nouveau fichier de migration ;

- modification ou suppression d'une entite existante :
  - mise a jour de la derniere migration existante ;
  - reapplication de cette migration dans le suivi local.

Les migrations sont stockees dans :

```text
migrations/
```

## Quand rien ne se passe

Plusieurs cas sont normaux :

- environnement different de `dev` ;
- aucun changement detecte dans `src/Entity` ;
- fingerprint identique ;
- snapshot identique ;
- migration deja appliquee et aucun nouveau diff.

## Depannage rapide

### Le snapshot n'apparait pas

Verifier :

- que l'application a bien un `impulse.php` a la racine ;
- que les entites sont dans `src/Entity` ;
- que `var/` est accessible en ecriture.

### La migration est creee mais la base ne bouge pas

Verifier :

- que la requete s'execute bien en `dev` ;
- que la migration generee contient des instructions SQL dans `up` ;
- que le package utilise bien la derniere version de `impulsephp/db` dans `vendor`.

### Les notifications de succes n'apparaissent pas en AJAX

Verifier que le runtime AJAX de l'application :

- renvoie les scripts collectes dans la reponse JSON ;
- execute `scripts` cote navigateur apres reception.

## Test du package

Le smoke test du package couvre :

- la creation initiale du snapshot ;
- la generation de migration ;
- l'application automatique ;
- la modification d'une entite ;
- la suppression d'une entite ;
- les notifications de succes.

Execution :

```bash
composer test
```
