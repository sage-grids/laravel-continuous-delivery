# Code Review: Laravel Continuous Delivery

## Executive Summary

The library provides a solid foundation for a self-hosted continuous delivery system, featuring a multi-app architecture, approval workflows, and GitHub integration. The code is generally clean, well-typed, and leverages Laravel's features (Queues, Notifications, Events) effectively.

However, there is a **critical architectural limitation** regarding remote deployments. The current implementation heavily favors "local" deployments (where the deployment agent is the target server). Deploying to remote servers via SSH requires significant manual configuration by the user in `Envoy.blade.php`, which is not dynamically bridgeable from the package's configuration.

## Critical Architectural Issues

### 1. Local-Only Deployment Restriction
**Severity:** High
**Location:** `resources/Envoy.blade.php`, `SimpleDeployer.php`, `AdvancedDeployer.php`

The default `Envoy.blade.php` defines:
```blade
@servers(['localhost' => '127.0.0.1'])
```
The `Deployer` classes run `envoy run ...` without passing a `--on` flag or dynamically injecting server configurations. This means the package **cannot deploy to remote servers** out of the box. It assumes the queue worker is running on the target server.

**Recommendation:**
*   Update `AppConfig` to accept a `servers` array.
*   Update `DeployerStrategy` implementations to pass these hosts to Envoy.
*   Update `Envoy.blade.php` to accept a `$servers` variable or use a dynamic server list passed via command line arguments (though Envoy's CLI argument handling for servers is limited, typically requiring a temporary Envoy file or `@setup` logic that parses a passed JSON string).

### 2. Deployment Concurrency & Locking
**Severity:** Medium
**Location:** `RunDeployJob.php`, `DeployController.php`

While `DeployController` uses `lockForUpdate` to prevent *creating* duplicate deployment records, there is no distributed lock preventing multiple queue workers from *executing* deployments for the same app simultaneously. If the queue has multiple workers, two `RunDeployJob` instances for the same app could run concurrently, causing race conditions in git operations or symlinking.

**Recommendation:**
*   Implement `ShouldBeUnique` on `RunDeployJob` (using `uniqueId` based on `app_key`).
*   Alternatively, use `Cache::lock()` within `RunDeployJob::handle` to ensure exclusive access to the deployment target for a specific app.

### 3. "Check Previous Release" Logic relies on Local Filesystem
**Severity:** Medium
**Location:** `SimpleDeployer::getAvailableReleases`, `AdvancedDeployer::getDirectorySize`

These methods use `Process::run()` directly, executing commands on the *worker* machine. If the user *does* configure remote servers in Envoy, these methods will fail or return incorrect data (analyzing the worker's filesystem, not the remote server's).

**Recommendation:**
*   These inspection commands must also be run via Envoy or an SSH wrapper if the target is remote.
*   Clearly document that these features are only available for local deployments if fixing them is out of scope.

## Security Improvements

### 1. Approval Token Handling
**Severity:** Medium
**Location:** `ApprovalController.php`

Approval tokens are passed in the URL. If these URLs are leaked (e.g., via Referrer headers to third-party assets if views included them, or server logs), deployments could be approved by unauthorized parties.

**Recommendation:**
*   Consider using signed URLs (`URL::signedRoute`) which add an expiration and signature, though you already have a token and expiration logic.
*   Ensure the approval views do not load external assets that could leak the URL via Referrer.
*   The `cd-approval` rate limiter uses `$request->ip()`. Ensure users are advised to configure `TrustedProxy` if running behind a load balancer (common in production), otherwise `ip()` might return the LB IP, effectively blocking valid approvals globally after 10 requests.

### 2. Command Injection Risks
**Severity:** Low
**Location:** `Deployer` classes

You correctly use `escapeshellarg` for most variables. However, reliance on string concatenation for shell commands is always risky.

**Recommendation:**
*   Ensure `AppConfig` validation prevents spaces or shell metacharacters in `app_path`, `repository`, etc.
*   Consider using `Process::run(['command', 'arg1', ...])` array syntax where possible, though Envoy requires a single string command.

## Robustness & Reliability

### 1. Hardcoded SQLite Connection
**Severity:** Low
**Location:** `ContinuousDeliveryServiceProvider.php`

The provider forces a `sqlite` connection for the `continuous-delivery` connection name unless configured otherwise. It constructs the config array at runtime. This makes it hard for users to use a shared MySQL database for deployment history (useful for high availability).

**Recommendation:**
*   Allow the user to define a full connection array in the config, defaulting to the SQLite preset.

### 2. Stuck Deployments
**Severity:** Medium
**Location:** `RunDeployJob.php`

If the worker process dies (OOM or crash) during a deployment, the deployment stays in `running` status forever. `CleanupCommand` deletes *old* records but doesn't seem to reset "stuck" running jobs.

**Recommendation:**
*   Add a `deployer:rescue` command or logic to `CleanupCommand` to mark deployments as "failed" if they have been "running" for longer than the timeout (e.g., > 1 hour) and the job is no longer in the queue.

## Code Quality & Maintainability

### 1. Envoy.blade.php Complexity
The `Envoy.blade.php` file is becoming a monolith. It mixes Simple and Advanced strategies.
**Recommendation:**
*   Consider splitting it into `Envoy.simple.blade.php` and `Envoy.advanced.blade.php` and selecting the file in the `Deployer` class.

### 2. Dependency on `laravel/envoy`
The package requires `laravel/envoy`.
**Recommendation:**
*   Ensure `laravel/envoy` is a production dependency (it is in `require`), not `require-dev`. (Checked: It is correct).

## Specific File Observations

*   **`src/Config/AppRegistry.php`**: `findByRepository` normalization is good, but `preg_match` usage for GitHub URLs handles SSH and HTTPS. Does it handle `git://` or non-GitHub URLs?
*   **`src/Support/Signature.php`**: Not reviewed, but assuming standard HMAC comparison using `hash_equals` (timing attack safe).
*   **`database/migrations/...deployments_table.php`**: The `output` column is `longText`. Ensure this doesn't hit row size limits on some DB engines if output is massive, though SQLite/MySQL `longtext` is usually fine.

## Conclusion

The library is well-written and functional for its primary use case (self-deploying apps). To become a robust, general-purpose CD tool, it needs to address the remote deployment architecture and concurrency handling.
