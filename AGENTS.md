# AGENTS.md for OpenCode / AI Coding Agents

## Project Overview

`sage-grids/laravel-continuous-delivery` — Multi-app continuous delivery Laravel package with GitHub webhooks, Laravel Envoy deployment scripts, and human approval workflows (Telegram/Slack/CLI).

**Namespace:** `SageGrids\ContinuousDelivery` (PSR-4: `src/`)

**Author:** Ilyas Serter

## Key Architecture

- **Strategy Pattern**: `DeployerStrategy` interface → `SimpleDeployer` (in-place git pull) / `AdvancedDeployer` (releases + symlinks)
- **Event-Driven**: Domain events dispatched at lifecycle points (`DeploymentCreated`, `DeploymentStarted`, `DeploymentCompleted`, `DeploymentFailed`, `DeploymentApproved`, `DeploymentRejected`)
- **Singleton Services**: `AppRegistry` (app config) and `DeployerFactory` (strategy resolver) registered as singletons in service provider
- **Isolated Storage**: Separate SQLite database (`continuous-delivery` connection) for deployment history; survives `migrate:fresh`
- **Laravel Envoy**: Blade-syntax deployment scripts in `resources/Envoy.blade.php`; stories for simple and advanced strategies

## Directory Structure

```
src/
├── Config/          # AppConfig (immutable value object), AppRegistry, DeployerResult
├── Console/         # 14 Artisan commands (setup, trigger, approve, reject, rollback, etc.)
├── Contracts/       # DeployerStrategy interface
├── Deployers/       # SimpleDeployer, AdvancedDeployer, DeployerFactory
│   └── Concerns/    # ResolvesEnvoyBinary trait
├── Enums/           # DeploymentStatus, DeploymentStrategy, TriggerType (backed string enums)
├── Events/          # 6 domain events
├── Exceptions/      # DeploymentException, DeploymentConflictException, InvalidConfigurationException
├── Http/Controllers/ # DeployController (webhook), ApprovalController (approve/reject), HealthController
├── Jobs/            # RunDeployJob (ShouldBeUnique, per-app)
├── Models/          # DeployerDeployment, DeployerRelease
├── Notifications/   # 6 notification classes (Telegram/Slack)
│   └── Concerns/    # DeploymentNotification trait
├── Services/        # DeploymentDispatcher
└── Support/         # Signature utility (HMAC-SHA256 verification)
database/migrations/ # 2 migration files (deployer_deployments, deployer_releases)
routes/api.php      # 5 webhook + status + approval routes
resources/          # Envoy.blade.php + Blade views for approval UI
config/             # continuous-delivery.php (single config file)
tests/              # Unit/ + Feature/ tests using Orchestra Testbench
```

## Database

- **Models**: `DeployerDeployment` and `DeployerRelease` use dynamic `getConnectionName()` — either main app DB or isolated `continuous-delivery` SQLite.
- **Connection** configured via `config('continuous-delivery.database.connection')` (default: `sqlite`).
- Migrations run only via `deployer:migrate` (NOT `php artisan migrate`).

## Testing

- **Framework**: PHPUnit via Orchestra Testbench
- **Base class**: `tests/TestCase.php` extends `Orchestra\Testbench\TestCase`
- **Helper methods**: `createDeployment()`, `createGithubPushPayload()`, `createGithubReleasePayload()`, `generateGithubSignature()`
- **Test DB**: SQLite `:memory:` with `testing` connection
- **Command**: `./vendor/bin/phpunit` (or `phpunit`)
- **Test suites**: `Unit` and `Feature`

## CI

- **GitHub Actions**: `tests.yml` — matrix across PHP 8.2/8.3 × Laravel 11/12 + code style check via Pint
- **Release**: `release-please.yml` — automated semantic releases via googleapis/release-please-action

## Key Conventions

- `php artisan deployer:*` for all CLI commands
- `[continuous-delivery]` prefix in all log messages
- Backed string enums with `label()`, `color()`, `is*()` helper methods
- Immutable `AppConfig` constructed via `fromArray()` with validation
- `DeployerResult` value object returned by deployers
- Security: HMAC-SHA256 webhook verification, SHA-256 hashed approval tokens, configurable token length (default 64), signed approval URLs with expiry

## Build Commands

```bash
composer install              # Install dependencies
./vendor/bin/phpunit          # Run all tests
./vendor/bin/phpunit --testsuite=Unit    # Unit tests only
./vendor/bin/phpunit --testsuite=Feature # Feature tests only
vendor/bin/pint --test        # Code style check
```
