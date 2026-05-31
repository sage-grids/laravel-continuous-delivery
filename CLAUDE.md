# CLAUDE.md for Claude Code

## Build/Test Commands

```bash
composer install                    # Install dependencies
./vendor/bin/phpunit                # Run all tests
./vendor/bin/phpunit --testsuite=Unit    # Unit tests only
./vendor/bin/phpunit --testsuite=Feature # Feature tests only
./vendor/bin/phpunit tests/Unit/SignatureTest.php  # Single test file
vendor/bin/pint --test              # Code style check (Laravel Pint)
```

## Code Style

- PSR-12 coding standards
- Strict types required on all files (`declare(strict_types=1)`)
- Namespace: `SageGrids\ContinuousDelivery` — PSR-4 from `src/`
- Test namespace: `SageGrids\ContinuousDelivery\Tests` — PSR-4 from `tests/`
- Class naming: PascalCase, Methods: camelCase, DB columns: snake_case, Config keys: snake_case, Commands: kebab-case (`deployer:*`)
- Log prefix: `[continuous-delivery]` in all log messages
- Method prefixes: `create*` (factory), `mark*` (state transitions), `is*/has*` (boolean queries)
- Fully qualified PHP function calls in global namespace: `\count()`, `\sprintf()`

## Architecture

- **Strategy Pattern**: `DeployerStrategy` interface → `SimpleDeployer` (in-place git pull) / `AdvancedDeployer` (releases + symlinks)
- **Singletons**: `AppRegistry` and `DeployerFactory` registered in service provider
- **Events**: `DeploymentCreated`, `DeploymentStarted`, `DeploymentCompleted`, `DeploymentFailed`, `DeploymentApproved`, `DeploymentRejected`
- **Value Objects**: `AppConfig` (immutable, `fromArray()`), `DeployerResult` (readonly)
- **Enums**: `DeploymentStatus`, `DeploymentStrategy`, `TriggerType` (all backed string enums)
- **Database**: Models use dynamic connection — either `continuous-delivery` (isolated SQLite) or main app DB
- **Migrations**: Run ONLY via `deployer:migrate` — never via `php artisan migrate`
- **Security**: HMAC-SHA256 webhook signatures, SHA-256 hashed approval tokens, signed approval URLs with expiry
- **Testing**: Orchestra Testbench, SQLite `:memory:`, base `TestCase` provides helpers (`createDeployment()`, `createGithubPushPayload()`, `createGithubReleasePayload()`, `generateGithubSignature()`)

## Key Files

- `config/continuous-delivery.php` — all configuration
- `src/ContinuousDeliveryServiceProvider.php` — registration, bootstrapping
- `src/Contracts/DeployerStrategy.php` — deployer interface
- `src/Config/AppConfig.php` — immutable app config value object
- `src/Config/AppRegistry.php` — singleton registry for all app configs
- `src/Models/DeployerDeployment.php` — main model (684 lines, factory methods, approval workflow)
- `src/Http/Controllers/DeployController.php` — GitHub webhook handler
- `src/Http/Controllers/ApprovalController.php` — approve/reject with signed URLs
- `src/Jobs/RunDeployJob.php` — queued deployment (ShouldBeUnique per app_key)
- `resources/Envoy.blade.php` — deployment script templates
- `routes/api.php` — 5 routes

## CI

- GitHub Actions matrix: PHP 8.2/8.3 × Laravel 11/12
- Automatic release via release-please-action
