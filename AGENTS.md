# AGENTS.md

## Goal

Implement a new provider in the `db` project, using the existing conventions found in the `ui`, `auth`, and `database` projects, to integrate Cycle ORM and automatic development-time schema/migration management for the main application.

Do not start coding immediately. First inspect the repository and infer the provider architecture, boot lifecycle, container bindings, filesystem conventions, configuration loading, and UI notification mechanism from the existing `ui`, `auth`, and `database` projects.

## Non-negotiable working method

1. Inspect `ui`, `auth`, `database`, and `db` before editing anything.
2. Reuse existing provider conventions, naming, registration style, boot hooks, and helper patterns.
3. Keep the implementation minimal and cohesive.
4. Do not invent framework APIs if existing project APIs already solve the problem.
5. If repository conventions differ from these instructions, follow repository conventions unless that would break the functional requirements below.

## Functional target

Create a provider in `db` that allows a main application to:

1. Use Cycle ORM through this provider.
2. Use Cycle Annotated / PHP attributes on entities.
3. Read entities from the main application directory `src/Entity`.
4. Build a deterministic JSON schema snapshot from those entities.
5. Store that snapshot as an "AST JSON" artifact for developer inspection and future comparison.
6. Detect entity additions, removals, and modifications.
7. Generate a new migration file in `./migrations` at the root of the main application every time the entity schema changes.
8. Preserve all previous migration files. Never rewrite or delete old migrations automatically.
9. Apply database updates automatically, with no CLI command and no manual developer action, but only when the main application is in dev mode.
10. Display a global temporary success notification in the main application's UI when a migration was generated and/or applied.

## Critical interpretation decisions

Treat these as resolved product decisions. Do not reopen them unless the codebase makes them impossible.

### 1) "AST JSON" means a deterministic schema snapshot, not a raw PHP parser dump

The requested output is called "AST JSON", but the real need is a normalized machine-readable snapshot of the entity mapping state.

Implement a JSON snapshot derived from the effective Cycle mapping metadata, not a raw PHP syntax tree.

The JSON must be deterministic and structured for diff/comparison. Include at least:

- entity FQCN
- short name
- source file path
- source file hash or mtime fingerprint
- table name
- columns
- column types
- nullability
- defaults
- primary key information
- generated / autoincrement information
- indexes if available
- foreign keys if available
- relations with enough metadata to understand cardinality and target entity

### 2) No background watcher

Do not implement an OS-level file watcher, daemon, queue worker, cron, or CLI-dependent workflow.

The provider must detect changes during normal application boot/request flow in `dev` mode.

### 3) Automatic in dev, inert outside dev

Only in `impulse.php` when `['env'] === 'dev'`:

- scan entities
- rebuild schema snapshot if needed
- compare with previous snapshot
- generate a migration if needed
- apply pending generated migrations automatically during the normal HTTP lifecycle
- require no CLI command and no manual developer step
- show developer notification

In any non-dev environment, none of the above automatic generation/application behavior must run.

### 4) Migration history is mandatory

Every detected schema change must create a new migration file after the previous ones.

Do not squash.
Do not replace old files.
Do not directly mutate the schema without producing a migration file.

### 5) No direct SyncTables-style production behavior

Prefer migration generation plus migration execution, not one-shot destructive sync logic.

## Configuration source of truth

Use the main application's root `impulse.php` as the source of truth.

At minimum, read:

- `env`
- `database`

The `database` array determines the database driver and database identifier/path.

Treat `database.name` as mandatory for database creation logic.

Rules:

- if `database.name` is missing, emit a developer-visible error alert and do not attempt automatic database creation
- do not guess the database name from the file path or any other key
- `database.database` and `database.name` do not serve the same purpose; preserve that distinction

Example:

```php
'database' => [
    'name' => 'database',
    'driver' => 'sqlite',
    'database' => '/Users/guillaume/Sites/ImpulsePHP/project_test/var/storage/database.sqlite',
],
```

Supported drivers:

- `sqlite`
- `mysql`
- `postgres`

Do not hardcode a single database engine.

## Database handling requirements

### General

Cycle ORM / Cycle Database must be the connection and schema management layer.

Infer the full connection strategy from the repository and the existing `database` project.
Do not invent config keys if the repository already defines them.

Configuration validation requirement:

- validate that `database.name` exists before any database creation workflow
- if it is absent, stop the creation workflow and show a developer-visible alert explaining that the database cannot be created without a name
- do not silently continue

### SQLite

- Use the configured sqlite file path.
- Ensure the parent directory exists.
- Ensure the sqlite database file exists or can be created before migrations are executed.

### MySQL / Postgres

- Read connection details from the existing application configuration conventions.
- Reuse the existing `database` project's connection strategy.
- If the target database does not exist yet, create it before applying migrations, using the same configured credentials when possible.
- If safe database creation is impossible with the available config, fail explicitly with a developer-visible error instead of guessing.

## Entity discovery

Only scan the main application's `src/Entity` directory.

Do not scan the full project tree.
Do not scan vendor.
Do not scan unrelated source folders.

All entities must remain autoloadable by Composer.

## Performance requirement

Entity discovery and schema compilation are expensive.

In `dev`, avoid heavy work on every request by implementing a stable fingerprint/cache strategy based on the contents of `src/Entity`.

A valid approach is:

- compute a fingerprint from all PHP files under `src/Entity`
- compare it with the previous stored fingerprint
- only rebuild the schema snapshot and migration pipeline when the fingerprint changed

Persist enough metadata so the provider can skip unnecessary work on most requests.

## Snapshot file requirements

Create and maintain a deterministic JSON file representing the current entity schema snapshot.

Store this file in the main application's `var` directory.

Required behavior:

- if the root `var` directory does not exist, create it
- store the snapshot at a stable path inside `var`
- prefer a simple explicit file name such as `var/entities.ast.json` unless the repository already has a stronger naming convention inside `var`
- on each normal HTTP refresh in `dev`, if the AST JSON file does not exist, generate it immediately
- if the AST JSON file exists, do not rewrite it blindly; recompute and update it only when the entity fingerprint or normalized schema snapshot changed
- keep the JSON deterministic so it can be diffed reliably

The file must be overwritten only when the current computed snapshot changes.

## Migration generation requirements

Generate migration files in:

- `./migrations` at the root of the main application

Requirements:

- create the directory if it does not exist
- generate a new file only when the schema changed
- keep ordering stable
- keep file names monotonic and reviewable, ideally timestamp-based
- include all SQL statements required to move from previous schema state to next schema state
- preserve execution order correctness for tables, constraints, and foreign keys
- include rollback/down logic if the migration system in this repository supports it cleanly

If Cycle provides a migration generator compatible with the repository, use it.
If not, implement a thin repository-aligned wrapper around the schema diff/migration generation path.

## Automatic application requirements

In `dev` only, once a migration is generated or when pending local migrations exist:

- apply them automatically during application boot/request flow
- do not require any CLI command
- do not require any manual developer action
- execute migrations only once
- prevent infinite re-generation loops
- make the process idempotent across refreshes

If a migration application fails:

- stop the automatic update process for that request
- do not silently ignore the error
- show a developer-visible error notification
- leave existing migration files intact

## UI notification requirements

When a migration is generated and/or applied successfully in `dev`, show a transient success notification on the main application's web UI.

Requirements:

- visible on any page
- global overlay or top-level notification
- disappears automatically after a few seconds
- informs the developer what happened
- examples: entity added, entity removed, schema updated, migration generated, migration applied

Before implementing a new notification system, inspect the `ui` project and reuse any existing alert, toast, flash, or global message mechanism.

If none exists, implement the smallest repository-consistent solution.

## Safety rules

- Never run automatic schema generation or migration application outside `dev`.
- Never delete old migration files automatically.
- Never guess database credentials.
- Never silently drop data.
- Be conservative with destructive schema changes.
- If entity deletion implies destructive SQL, make the generated migration explicit and reviewable.

## Expected package integration

Use repository-compatible versions and only add missing packages if they are truly required by the current composer constraints.

Likely required Cycle packages include the ORM, database layer, annotated mapping support, and migration/schema generation support.

Do not pin arbitrary versions without checking the current repository constraints first.

## Implementation plan you should follow

1. Inspect `ui`, `auth`, `database`, and `db`.
2. Identify how providers are declared, registered, and bootstrapped.
3. Identify how `impulse.php` is loaded.
4. Identify whether a cache directory / storage directory convention already exists.
5. Identify whether the UI project already has a global alert/toast/flash system.
6. Integrate Cycle DBAL/ORM using repository conventions.
7. Implement entity discovery limited to `src/Entity`.
8. Build deterministic schema snapshot JSON.
9. Implement snapshot comparison/fingerprinting.
10. Implement migration generation into root `migrations`.
11. Implement automatic migration application in `dev` only.
12. Implement developer notification UI.
13. Add or update tests.
14. Update documentation if the repository has package docs or README sections for providers.

## Deliverables

At the end, provide a final summary containing:

1. what was added
2. which files changed
3. how entity change detection works
4. where the AST/snapshot JSON is stored
5. where migration files are stored
6. how automatic migration application works in `dev`
7. how the UI notification works
8. what tests were added or run
9. remaining limitations or risks

## Acceptance criteria

The work is only complete if all of the following are true:

- the `db` project contains a provider aligned with repository conventions
- the provider integrates Cycle ORM
- entity classes in main app `src/Entity` are discovered
- a deterministic schema snapshot JSON is generated
- schema changes produce new migration files in root `migrations`
- previous migration files remain untouched
- database creation/availability is handled for the configured driver when possible
- migrations are auto-applied without CLI and without manual developer action only in `dev`
- no automatic migration generation/application occurs outside `dev`
- a visible success notification appears in the web UI after successful schema update work

## What to avoid

- no repository-wide refactor
- no vendor scanning
- no production auto-migration behavior
- no custom daemon or watcher process
- no command-line-only solution
- no solution that generates migrations but leaves execution to a manual developer step in `dev`
- no SQLite-only solution
- no fake success messages when nothing happened

