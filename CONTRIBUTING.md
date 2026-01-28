# Contributing to Laravel Continuous Delivery

Thank you for your interest in contributing to Laravel Continuous Delivery! This document provides guidelines and best practices for contributing to this package.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Development Environment Setup](#development-environment-setup)
- [Coding Standards](#coding-standards)
- [Architecture Guidelines](#architecture-guidelines)
- [Git Workflow](#git-workflow)
- [Versioning Rules](#versioning-rules)
- [Pull Request Process](#pull-request-process)
- [Testing Requirements](#testing-requirements)
- [Documentation](#documentation)

## Code of Conduct

- Be respectful and inclusive in all communications
- Focus on constructive feedback
- Help maintain a welcoming environment for all contributors

## Development Environment Setup

### Prerequisites

- PHP 8.2 or higher
- Composer 2.x
- Git

### Installation

1. Fork the repository on GitHub
2. Clone your fork locally:

```bash
git clone git@github.com:YOUR_USERNAME/laravel-continuous-delivery.git
cd laravel-continuous-delivery
```

3. Install dependencies:

```bash
composer install
```

4. Run tests to verify your setup:

```bash
./vendor/bin/phpunit
```

## Coding Standards

### PHP Style Guide

This package follows PSR-12 coding standards with additional conventions:

#### Naming Conventions

| Element | Convention | Example |
|---------|------------|---------|
| Classes | PascalCase | `DeployerDeployment`, `AdvancedDeployer` |
| Methods | camelCase | `createDeployment()`, `markAsSuccess()` |
| Properties | camelCase | `$deploymentId`, `$triggerRef` |
| Database columns | snake_case | `commit_sha`, `trigger_ref` |
| Config keys | snake_case | `webhook_secret`, `auto_deploy` |
| Commands | kebab-case | `deployer:trigger`, `deployer:status` |
| Enums | PascalCase with backed strings | `DeploymentStatus::Success` |

#### Method Naming Prefixes

- `create*` - Factory methods that create new instances
- `mark*` - State transition methods (e.g., `markAsSuccess()`)
- `is*` / `has*` - Boolean query methods
- `get*` - Getter methods (use sparingly; prefer direct property access)

#### Type Declarations

Always use strict types and full type declarations:

```php
<?php

declare(strict_types=1);

namespace SageGrids\ContinuousDelivery\Services;

final class DeploymentDispatcher
{
    public function dispatch(DeployerDeployment $deployment): void
    {
        // Implementation
    }
}
```

#### Logging

Use consistent logging with the `[continuous-delivery]` prefix:

```php
Log::info('[continuous-delivery] Deployment started', [
    'uuid' => $deployment->uuid,
    'app' => $deployment->app_key,
]);
```

#### Global Functions

Call PHP built-in functions in the global namespace for optimization:

```php
// Correct
\count($items);
\sprintf('Message: %s', $value);

// Incorrect
count($items);
sprintf('Message: %s', $value);
```

### Directory Structure

Follow the established package structure:

```
src/
├── Config/          # Configuration classes (immutable value objects)
├── Console/         # Artisan commands
├── Contracts/       # Interfaces and contracts
├── Deployers/       # Strategy implementations
│   └── Concerns/    # Shared deployment traits
├── Enums/           # Type-safe enums
├── Events/          # Domain events
├── Exceptions/      # Custom exceptions
├── Http/Controllers/# API controllers
├── Jobs/            # Queue jobs
├── Models/          # Eloquent models
├── Notifications/   # Notification classes
│   └── Concerns/    # Shared notification traits
├── Services/        # Business logic services
└── Support/         # Utility classes
```

## Architecture Guidelines

### Design Patterns

This package uses several design patterns. Follow these when extending functionality:

#### Strategy Pattern

For new deployment strategies, implement the `DeployerStrategy` interface:

```php
<?php

namespace SageGrids\ContinuousDelivery\Deployers;

use SageGrids\ContinuousDelivery\Contracts\DeployerStrategy;
use SageGrids\ContinuousDelivery\Config\DeployerResult;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;

final class CustomDeployer implements DeployerStrategy
{
    public function deploy(DeployerDeployment $deployment): DeployerResult
    {
        // Implementation
    }

    public function rollback(DeployerDeployment $deployment): DeployerResult
    {
        // Implementation
    }
}
```

#### Event-Driven Architecture

Dispatch domain events at key lifecycle points:

```php
use SageGrids\ContinuousDelivery\Events\DeploymentCreated;

event(new DeploymentCreated($deployment));
```

### Eloquent Models

#### Required Model Properties

```php
protected $fillable = [
    // Explicitly list all mass-assignable attributes
];

protected $casts = [
    // Use casts for enums and dates
    'status' => DeploymentStatus::class,
    'created_at' => 'datetime',
];
```

#### Query Scopes

Use query scopes for reusable filters:

```php
public function scopePending(Builder $query): Builder
{
    return $query->where('status', DeploymentStatus::Pending);
}

public function scopeForApp(Builder $query, string $appKey): Builder
{
    return $query->where('app_key', $appKey);
}
```

### Security Considerations

- Never store plain-text tokens; always hash sensitive data
- Use HMAC-SHA256 for signature verification
- Implement token expiration for approval workflows
- Validate all user input

## Git Workflow

### Branch Naming

Use descriptive branch names with prefixes:

| Prefix | Purpose | Example |
|--------|---------|---------|
| `feature/` | New features | `feature/slack-notifications` |
| `fix/` | Bug fixes | `fix/webhook-signature-validation` |
| `docs/` | Documentation changes | `docs/update-quickstart-guide` |
| `refactor/` | Code refactoring | `refactor/deployment-dispatcher` |
| `test/` | Test additions/fixes | `test/approval-workflow` |
| `chore/` | Maintenance tasks | `chore/update-dependencies` |

### Commit Messages

Follow the Conventional Commits specification:

```
<type>(<scope>): <description>

[optional body]

[optional footer]
```

#### Types

- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation changes
- `style`: Code style changes (formatting, no logic change)
- `refactor`: Code refactoring
- `test`: Adding or updating tests
- `chore`: Maintenance tasks

#### Examples

```bash
# Feature
feat(notifications): add webhook notification channel

# Bug fix
fix(deployer): resolve race condition in release cleanup

# Documentation
docs(readme): update installation instructions

# With scope and body
feat(approval): implement approval timeout notifications

Adds automatic notification when an approval request expires.
Includes Telegram and Slack notification support.

Closes #123
```

### Git Best Practices

1. **Keep commits atomic**: Each commit should represent one logical change
2. **Write meaningful messages**: Describe *what* and *why*, not *how*
3. **Rebase before merging**: Keep a clean, linear history
4. **Sign your commits** (optional but recommended):

```bash
git config --global commit.gpgsign true
```

## Versioning Rules

This package follows [Semantic Versioning 2.0.0](https://semver.org/):

```
MAJOR.MINOR.PATCH
```

### Version Increment Rules

#### MAJOR Version (X.0.0)

Increment for breaking changes:

- Removing public methods or classes
- Changing method signatures
- Modifying database schema in incompatible ways
- Changing configuration structure
- Dropping PHP/Laravel version support

Examples:
- Removing `DeployerStrategy::deploy()` method
- Changing `config('continuous-delivery.apps')` structure
- Dropping PHP 8.2 support

#### MINOR Version (0.X.0)

Increment for backward-compatible features:

- Adding new public methods or classes
- Adding new configuration options (with defaults)
- Adding new Artisan commands
- Adding new notification channels
- New optional parameters with defaults

Examples:
- Adding `DeployerDeployment::getMetrics()` method
- Adding `config('continuous-delivery.metrics')` option
- Adding `deployer:metrics` command

#### PATCH Version (0.0.X)

Increment for backward-compatible fixes:

- Bug fixes
- Performance improvements
- Documentation updates
- Internal refactoring (no public API changes)
- Security patches

Examples:
- Fixing webhook signature validation
- Improving deployment job performance
- Updating documentation

### Pre-release Versions

For testing before stable release:

```
1.0.0-alpha.1
1.0.0-beta.1
1.0.0-rc.1
```

### Creating Releases

1. Update `CHANGELOG.md` with all changes
2. Create a release branch:

```bash
git checkout -b release/v1.2.0
```

3. Update version references if needed
4. Create a pull request to `main`
5. After merge, tag the release:

```bash
git tag -a v1.2.0 -m "Release v1.2.0"
git push origin v1.2.0
```

## Pull Request Process

### Before Submitting

1. **Create an issue first** for significant changes to discuss the approach
2. **Fork and create a branch** from `main`
3. **Write/update tests** for your changes
4. **Run the test suite**:

```bash
./vendor/bin/phpunit
```

5. **Update documentation** if needed
6. **Update CHANGELOG.md** under "Unreleased" section

### Pull Request Template

```markdown
## Description

Brief description of the changes.

## Type of Change

- [ ] Bug fix (non-breaking change fixing an issue)
- [ ] New feature (non-breaking change adding functionality)
- [ ] Breaking change (fix or feature causing existing functionality to change)
- [ ] Documentation update

## Testing

Describe the tests you ran and how to reproduce.

## Checklist

- [ ] My code follows the project's coding standards
- [ ] I have added tests covering my changes
- [ ] All new and existing tests pass
- [ ] I have updated the documentation accordingly
- [ ] I have updated the CHANGELOG.md
```

### Review Process

1. Maintainers will review your PR
2. Address any requested changes
3. Once approved, a maintainer will merge your PR
4. Your contribution will be included in the next release

## Testing Requirements

### Test Coverage

All new features and bug fixes must include tests:

- **Unit tests** for isolated components (`tests/Unit/`)
- **Feature tests** for integration scenarios (`tests/Feature/`)

### Running Tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run specific test suite
./vendor/bin/phpunit --testsuite=Unit
./vendor/bin/phpunit --testsuite=Feature

# Run specific test file
./vendor/bin/phpunit tests/Unit/SignatureTest.php

# Run with coverage report
./vendor/bin/phpunit --coverage-html coverage/
```

### Test Structure

```php
<?php

namespace SageGrids\ContinuousDelivery\Tests\Unit;

use SageGrids\ContinuousDelivery\Tests\TestCase;

final class ExampleTest extends TestCase
{
    public function test_it_performs_expected_behavior(): void
    {
        // Arrange
        $deployment = $this->createDeployment();

        // Act
        $result = $deployment->markAsStarted();

        // Assert
        $this->assertEquals(DeploymentStatus::Running, $deployment->status);
    }
}
```

### Test Helpers

The `TestCase` class provides helper methods:

```php
$this->createDeployment();          // Create test deployment
$this->createGithubPushPayload();   // Generate GitHub push payload
$this->createGithubReleasePayload();// Generate GitHub release payload
$this->generateGithubSignature();   // Create HMAC signatures
```

## Documentation

### When to Update Documentation

- Adding new features
- Changing existing behavior
- Adding new configuration options
- Adding new Artisan commands

### Documentation Locations

- `README.md` - Package overview and quick start
- `docs/` - Detailed documentation
- Code comments - Complex logic explanation
- `CHANGELOG.md` - Version history

### Documentation Style

- Use clear, concise language
- Include code examples
- Keep examples up-to-date with the codebase
- Use proper markdown formatting

---

## Questions?

If you have questions about contributing, please:

1. Check existing issues and documentation
2. Open a new issue with the "question" label

Thank you for contributing!
