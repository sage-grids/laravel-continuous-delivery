# Task 10: Update Routes

**Phase:** 3 - Approval Workflow
**Priority:** P1
**Estimated Effort:** Small
**Depends On:** 06, 09

---

## Objective

Update the package routes to include approval endpoints and status check.

---

## File: `routes/api.php`

```php
<?php

use Illuminate\Support\Facades\Route;
use SageGrids\ContinuousDelivery\Http\Controllers\ApprovalController;
use SageGrids\ContinuousDelivery\Http\Controllers\DeployController;

/*
|--------------------------------------------------------------------------
| Continuous Delivery API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the ContinuousDeliveryServiceProvider within
| an "api" middleware group with throttling enabled.
|
*/

$path = config('continuous-delivery.route.path', '/deploy/github');

Route::prefix('deploy')->name('continuous-delivery.')->group(function () use ($path) {

    /*
    |--------------------------------------------------------------------------
    | GitHub Webhook Endpoint
    |--------------------------------------------------------------------------
    |
    | Receives webhook events from GitHub for push and release events.
    | Protected by HMAC-SHA256 signature verification.
    |
    */
    Route::post('/github', [DeployController::class, 'github'])
        ->name('webhook');

    /*
    |--------------------------------------------------------------------------
    | Deployment Status
    |--------------------------------------------------------------------------
    |
    | Check the status of a deployment by UUID.
    |
    */
    Route::get('/status/{uuid}', [DeployController::class, 'status'])
        ->name('status')
        ->where('uuid', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');

    /*
    |--------------------------------------------------------------------------
    | Approval Endpoints (Signed URLs)
    |--------------------------------------------------------------------------
    |
    | These endpoints use a 64-character random token for authentication.
    | Tokens are single-use and expire after the configured timeout.
    |
    */
    Route::get('/approve/{token}', [ApprovalController::class, 'approve'])
        ->name('approve')
        ->where('token', '[A-Za-z0-9]{64}');

    Route::get('/reject/{token}', [ApprovalController::class, 'reject'])
        ->name('reject')
        ->where('token', '[A-Za-z0-9]{64}');

    // Optional: Rejection form with reason input
    Route::get('/reject/{token}/form', [ApprovalController::class, 'rejectForm'])
        ->name('reject.form')
        ->where('token', '[A-Za-z0-9]{64}');

    Route::post('/reject/{token}', [ApprovalController::class, 'reject'])
        ->name('reject.submit')
        ->where('token', '[A-Za-z0-9]{64}');

});
```

---

## Route Summary

| Method | URI | Name | Purpose |
|--------|-----|------|---------|
| POST | `/api/deploy/github` | `continuous-delivery.webhook` | GitHub webhook |
| GET | `/api/deploy/status/{uuid}` | `continuous-delivery.status` | Check deployment status |
| GET | `/api/deploy/approve/{token}` | `continuous-delivery.approve` | Approve deployment |
| GET | `/api/deploy/reject/{token}` | `continuous-delivery.reject` | Quick reject |
| GET | `/api/deploy/reject/{token}/form` | `continuous-delivery.reject.form` | Reject with reason form |
| POST | `/api/deploy/reject/{token}` | `continuous-delivery.reject.submit` | Submit rejection with reason |

---

## URL Examples

```
# Webhook (GitHub calls this)
POST https://example.com/api/deploy/github

# Status check
GET https://example.com/api/deploy/status/550e8400-e29b-41d4-a716-446655440000

# Approve (from notification link)
GET https://example.com/api/deploy/approve/aBcD1234...64chars...

# Reject (quick)
GET https://example.com/api/deploy/reject/aBcD1234...64chars...

# Reject with reason form
GET https://example.com/api/deploy/reject/aBcD1234...64chars.../form
```

---

## Route Constraints

- **UUID**: Standard UUID format with hyphens
- **Token**: Exactly 64 alphanumeric characters

These constraints prevent invalid tokens from hitting the database.

---

## Acceptance Criteria

- [ ] All routes are registered correctly
- [ ] Route names follow `continuous-delivery.*` pattern
- [ ] URL constraints work as expected
- [ ] Routes work with API middleware group
- [ ] Named routes can be generated with `route()` helper

---

## Notes

- Routes are prefixed with `/api` by the service provider
- Throttling is applied via middleware config
- Token constraint `[A-Za-z0-9]{64}` matches `Str::random(64)` output
