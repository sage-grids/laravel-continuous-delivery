# Task 01: Update composer.json

**Phase:** 1 - Foundation
**Priority:** P0
**Estimated Effort:** Small

---

## Objective

Add Laravel Envoy as a dependency and update package metadata for v2.0.

---

## Changes Required

### File: `composer.json`

```json
{
    "name": "sage-grids/laravel-continuous-delivery",
    "description": "Multi-environment continuous delivery for Laravel with GitHub webhooks, Envoy deployment, and human approval workflows",
    "version": "2.0.0",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.2",
        "illuminate/support": "^10.0|^11.0|^12.0",
        "illuminate/queue": "^10.0|^11.0|^12.0",
        "illuminate/http": "^10.0|^11.0|^12.0",
        "illuminate/notifications": "^10.0|^11.0|^12.0",
        "laravel/envoy": "^2.0"
    },
    "require-dev": {
        "orchestra/testbench": "^8.0|^9.0|^10.0",
        "phpunit/phpunit": "^10.0|^11.0"
    },
    "autoload": {
        "psr-4": {
            "SageGrids\\ContinuousDelivery\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "SageGrids\\ContinuousDelivery\\Tests\\": "tests/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "SageGrids\\ContinuousDelivery\\ContinuousDeliveryServiceProvider"
            ]
        }
    },
    "config": {
        "sort-packages": true
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

---

## Key Changes

1. **Add `laravel/envoy`** - Required for deployment task execution
2. **Add `illuminate/notifications`** - Required for Telegram/Slack notifications
3. **Update description** - Reflect new multi-environment capabilities
4. **Bump PHP requirement** - PHP 8.2+ for modern features
5. **Add dev dependencies** - For testing

---

## Acceptance Criteria

- [ ] `composer validate` passes
- [ ] `laravel/envoy` is listed in require
- [ ] Package can be installed in a fresh Laravel 12 project
- [ ] No dependency conflicts with Laravel 10, 11, or 12

---

## Notes

- Envoy is a runtime dependency (not dev-only) because it's executed by the queue job
- The `illuminate/notifications` facade is used for Telegram/Slack channels
