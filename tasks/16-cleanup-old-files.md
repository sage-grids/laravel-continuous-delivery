# Task 16: Cleanup Old Files

**Phase:** 5 - Documentation & Cleanup
**Priority:** P2
**Estimated Effort:** Small
**Depends On:** All implementation tasks complete

---

## Objective

Remove deprecated files and clean up the package structure after migration to Envoy.

---

## Files to Delete

### Bash Scripts (Replaced by Envoy)

```
resources/scripts/
├── fresh-install-from-scratch.sh   # DELETE
└── simple-pull-and-migrate.sh      # DELETE
```

### Application-Specific Scripts (Hardcoded paths)

```
scripts/
└── deploy/
    ├── fresh-install-from-scratch.sh   # DELETE
    └── simple-pull-and-migrate.sh      # DELETE
```

---

## Files to Review

### Keep or Refactor

| File | Decision | Reason |
|------|----------|--------|
| `src/Support/Signature.php` | Keep | Still used for webhook verification |
| `src/Console/InstallCommand.php` | Update | Needs to publish Envoy instead of bash scripts |

---

## InstallCommand Updates

The install command should be updated to:

1. Remove bash script publishing
2. Add Envoy template publishing
3. Add database migration
4. Provide updated next steps

```php
// Updated InstallCommand handle() method
public function handle(): void
{
    $this->info('Installing Continuous Delivery...');

    // Publish config
    if ($this->option('config') || $this->option('all')) {
        $this->call('vendor:publish', [
            '--tag' => 'continuous-delivery-config',
        ]);
        $this->info('✓ Published configuration');
    }

    // Publish Envoy template
    if ($this->option('envoy') || $this->option('all')) {
        $this->call('vendor:publish', [
            '--tag' => 'continuous-delivery-envoy',
        ]);
        $this->info('✓ Published Envoy.blade.php');
    }

    // Run migrations
    if ($this->option('migrate') || $this->option('all')) {
        $this->setupDatabase();
        $this->info('✓ Database configured');
    }

    $this->newLine();
    $this->info('Next steps:');
    $this->line('1. Set GITHUB_WEBHOOK_SECRET in .env');
    $this->line('2. Set CD_APP_DIR to your application path');
    $this->line('3. Configure notifications (Telegram/Slack)');
    $this->line('4. Set up GitHub webhook');
    $this->line('5. Ensure queue worker is running');
    $this->newLine();
    $this->line('See documentation: docs/server-setup.md');
}

protected function setupDatabase(): void
{
    $dbPath = config('continuous-delivery.storage.database');

    if (!$dbPath) {
        return;
    }

    $dir = dirname($dbPath);

    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true)) {
            $this->warn("Could not create directory: {$dir}");
            $this->line('Create it manually: sudo mkdir -p ' . $dir);
            return;
        }
    }

    if (!file_exists($dbPath)) {
        touch($dbPath);
    }

    // Run package migrations
    $this->call('migrate', [
        '--database' => 'continuous-delivery',
        '--path' => 'vendor/sage-grids/continuous-delivery/database/migrations',
        '--force' => true,
    ]);
}
```

---

## Service Provider Updates

Remove bash script publishing from service provider:

```php
// REMOVE this from registerPublishables()
$this->publishes([
    __DIR__ . '/../resources/scripts' => base_path('deploy'),
], 'continuous-delivery-scripts');
```

---

## Directory Structure After Cleanup

```
packages/sage-grids/continuous-delivery/
├── composer.json
├── LICENSE
├── README.md
├── config/
│   └── continuous-delivery.php
├── database/
│   └── migrations/
│       └── 2024_01_01_000000_create_deployments_table.php
├── docs/
│   ├── approval-workflow.md
│   ├── configuration.md
│   ├── notifications.md
│   ├── review.md
│   └── server-setup.md
├── resources/
│   ├── Envoy.blade.php
│   └── views/
│       ├── approved.blade.php
│       ├── error.blade.php
│       ├── reject-form.blade.php
│       └── rejected.blade.php
├── routes/
│   └── api.php
├── src/
│   ├── Console/
│   │   ├── ApproveCommand.php
│   │   ├── ExpireCommand.php
│   │   ├── InstallCommand.php
│   │   ├── PendingCommand.php
│   │   ├── RejectCommand.php
│   │   └── StatusCommand.php
│   ├── Http/
│   │   └── Controllers/
│   │       ├── ApprovalController.php
│   │       └── DeployController.php
│   ├── Jobs/
│   │   └── RunDeployJob.php
│   ├── Models/
│   │   └── Deployment.php
│   ├── Notifications/
│   │   ├── Concerns/
│   │   │   └── DeploymentNotification.php
│   │   ├── DeploymentApprovalRequired.php
│   │   ├── DeploymentApproved.php
│   │   ├── DeploymentExpired.php
│   │   ├── DeploymentFailed.php
│   │   ├── DeploymentRejected.php
│   │   ├── DeploymentStarted.php
│   │   └── DeploymentSucceeded.php
│   ├── Support/
│   │   └── Signature.php
│   └── ContinuousDeliveryServiceProvider.php
└── tasks/
    └── (task files for reference)
```

---

## Cleanup Commands

```bash
# Delete old bash scripts
rm -rf packages/sage-grids/continuous-delivery/resources/scripts
rm -rf packages/sage-grids/continuous-delivery/scripts

# Verify structure
find packages/sage-grids/continuous-delivery -type f -name "*.sh"
# Should return nothing
```

---

## Acceptance Criteria

- [ ] All bash scripts removed
- [ ] `scripts/` directory deleted
- [ ] `resources/scripts/` directory deleted
- [ ] InstallCommand updated
- [ ] Service provider cleaned up
- [ ] Package still functions correctly
- [ ] No references to deleted files remain

---

## Notes

- Keep the `tasks/` directory as implementation reference
- Consider adding `.gitignore` entries for old paths
- Update any tests that reference deleted files
