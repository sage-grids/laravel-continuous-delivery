# Task 09: Create Approval Controller

**Phase:** 3 - Approval Workflow
**Priority:** P1
**Estimated Effort:** Medium
**Depends On:** 04, 08

---

## Objective

Create the `ApprovalController` to handle signed URL approval and rejection of production deployments.

---

## File: `src/Http/Controllers/ApprovalController.php`

```php
<?php

namespace SageGrids\ContinuousDelivery\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use SageGrids\ContinuousDelivery\Jobs\RunDeployJob;
use SageGrids\ContinuousDelivery\Models\Deployment;
use SageGrids\ContinuousDelivery\Notifications\DeploymentApproved;
use SageGrids\ContinuousDelivery\Notifications\DeploymentRejected;

class ApprovalController extends Controller
{
    /**
     * Approve a deployment.
     */
    public function approve(string $token, Request $request): Response
    {
        $deployment = $this->findDeployment($token);

        if (!$deployment) {
            return $this->renderError(
                'Deployment Not Found',
                'This deployment request was not found or has already been processed.'
            );
        }

        if ($deployment->hasExpired()) {
            return $this->renderError(
                'Approval Expired',
                'This deployment approval request has expired. Please create a new release to deploy.'
            );
        }

        if (!$deployment->canBeApproved()) {
            return $this->renderError(
                'Cannot Approve',
                "This deployment cannot be approved. Current status: {$deployment->status}"
            );
        }

        // Record approver info
        $approvedBy = $this->getApproverIdentifier($request);

        try {
            $deployment->approve($approvedBy);

            Log::info('[continuous-delivery] Deployment approved', [
                'uuid' => $deployment->uuid,
                'approved_by' => $approvedBy,
            ]);

            // Dispatch the deployment job
            $this->dispatchDeployment($deployment);

            // Send approval notification
            $this->notifyApproved($deployment);

            return $this->renderSuccess($deployment);

        } catch (\Throwable $e) {
            Log::error('[continuous-delivery] Approval failed', [
                'uuid' => $deployment->uuid,
                'error' => $e->getMessage(),
            ]);

            return $this->renderError(
                'Approval Failed',
                'An error occurred while processing the approval. Please try again or use the CLI.'
            );
        }
    }

    /**
     * Reject a deployment.
     */
    public function reject(string $token, Request $request): Response
    {
        $deployment = $this->findDeployment($token);

        if (!$deployment) {
            return $this->renderError(
                'Deployment Not Found',
                'This deployment request was not found or has already been processed.'
            );
        }

        if (!$deployment->canBeRejected()) {
            return $this->renderError(
                'Cannot Reject',
                "This deployment cannot be rejected. Current status: {$deployment->status}"
            );
        }

        $rejectedBy = $this->getApproverIdentifier($request);
        $reason = $request->get('reason', 'Rejected via web interface');

        try {
            $deployment->reject($rejectedBy, $reason);

            Log::info('[continuous-delivery] Deployment rejected', [
                'uuid' => $deployment->uuid,
                'rejected_by' => $rejectedBy,
                'reason' => $reason,
            ]);

            // Send rejection notification
            $this->notifyRejected($deployment);

            return $this->renderRejected($deployment);

        } catch (\Throwable $e) {
            Log::error('[continuous-delivery] Rejection failed', [
                'uuid' => $deployment->uuid,
                'error' => $e->getMessage(),
            ]);

            return $this->renderError(
                'Rejection Failed',
                'An error occurred while processing the rejection.'
            );
        }
    }

    /**
     * Show rejection form (optional - allows adding reason).
     */
    public function rejectForm(string $token): Response
    {
        $deployment = $this->findDeployment($token);

        if (!$deployment || !$deployment->canBeRejected()) {
            return $this->renderError(
                'Cannot Reject',
                'This deployment cannot be rejected.'
            );
        }

        return response()->view('continuous-delivery::reject-form', [
            'deployment' => $deployment,
            'token' => $token,
        ]);
    }

    /**
     * Find deployment by approval token.
     */
    protected function findDeployment(string $token): ?Deployment
    {
        if (strlen($token) !== 64) {
            return null;
        }

        return Deployment::where('approval_token', $token)->first();
    }

    /**
     * Get identifier for the approver.
     */
    protected function getApproverIdentifier(Request $request): string
    {
        // If user is authenticated, use their identifier
        if ($user = $request->user()) {
            return $user->email ?? $user->name ?? "user:{$user->id}";
        }

        // Fall back to IP address
        return "ip:{$request->ip()}";
    }

    /**
     * Dispatch deployment job.
     */
    protected function dispatchDeployment(Deployment $deployment): void
    {
        $job = new RunDeployJob($deployment);

        $connection = config('continuous-delivery.queue.connection');
        $queue = config('continuous-delivery.queue.queue');

        if ($connection) {
            $job->onConnection($connection);
        }

        if ($queue) {
            $job->onQueue($queue);
        }

        dispatch($job);
    }

    /**
     * Render success view.
     */
    protected function renderSuccess(Deployment $deployment): Response
    {
        return response()->view('continuous-delivery::approved', [
            'deployment' => $deployment,
        ]);
    }

    /**
     * Render rejected view.
     */
    protected function renderRejected(Deployment $deployment): Response
    {
        return response()->view('continuous-delivery::rejected', [
            'deployment' => $deployment,
        ]);
    }

    /**
     * Render error view.
     */
    protected function renderError(string $title, string $message): Response
    {
        return response()->view('continuous-delivery::error', [
            'title' => $title,
            'message' => $message,
        ], 400);
    }

    /**
     * Send approval notification.
     */
    protected function notifyApproved(Deployment $deployment): void
    {
        try {
            $deployment->notify(new DeploymentApproved($deployment));
        } catch (\Throwable $e) {
            Log::warning('[continuous-delivery] Failed to send approved notification', [
                'uuid' => $deployment->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send rejection notification.
     */
    protected function notifyRejected(Deployment $deployment): void
    {
        try {
            $deployment->notify(new DeploymentRejected($deployment));
        } catch (\Throwable $e) {
            Log::warning('[continuous-delivery] Failed to send rejected notification', [
                'uuid' => $deployment->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

---

## Views Required

### `resources/views/approved.blade.php`

```blade
<!DOCTYPE html>
<html>
<head>
    <title>Deployment Approved</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: system-ui, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
        .success { color: #059669; }
        .info { background: #f3f4f6; padding: 15px; border-radius: 8px; margin: 20px 0; }
        code { background: #e5e7eb; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
    <h1 class="success">✓ Deployment Approved</h1>

    <div class="info">
        <p><strong>Environment:</strong> {{ $deployment->environment }}</p>
        <p><strong>Version:</strong> {{ $deployment->trigger_ref }}</p>
        <p><strong>Commit:</strong> <code>{{ $deployment->short_commit_sha }}</code></p>
    </div>

    <p>The deployment has been queued and will begin shortly.</p>
    <p>You can close this window.</p>
</body>
</html>
```

### `resources/views/rejected.blade.php`

```blade
<!DOCTYPE html>
<html>
<head>
    <title>Deployment Rejected</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: system-ui, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
        .warning { color: #d97706; }
        .info { background: #f3f4f6; padding: 15px; border-radius: 8px; margin: 20px 0; }
    </style>
</head>
<body>
    <h1 class="warning">✗ Deployment Rejected</h1>

    <div class="info">
        <p><strong>Environment:</strong> {{ $deployment->environment }}</p>
        <p><strong>Version:</strong> {{ $deployment->trigger_ref }}</p>
        @if($deployment->rejection_reason)
            <p><strong>Reason:</strong> {{ $deployment->rejection_reason }}</p>
        @endif
    </div>

    <p>The deployment has been rejected and will not proceed.</p>
    <p>You can close this window.</p>
</body>
</html>
```

### `resources/views/error.blade.php`

```blade
<!DOCTYPE html>
<html>
<head>
    <title>{{ $title }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: system-ui, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
        .error { color: #dc2626; }
    </style>
</head>
<body>
    <h1 class="error">{{ $title }}</h1>
    <p>{{ $message }}</p>
</body>
</html>
```

---

## Acceptance Criteria

- [ ] Approve endpoint updates deployment and dispatches job
- [ ] Reject endpoint updates deployment status
- [ ] Expired deployments cannot be approved
- [ ] Already processed deployments show appropriate message
- [ ] Notifications are sent on approve/reject
- [ ] Views render correctly
- [ ] Approver identifier is recorded

---

## Notes

- Token length check (64 chars) prevents unnecessary DB queries
- Views are minimal HTML - no external dependencies
- Approver can be authenticated user or IP address
