<?php

namespace SageGrids\ContinuousDelivery\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;
use SageGrids\ContinuousDelivery\Notifications\DeploymentApproved;
use SageGrids\ContinuousDelivery\Notifications\DeploymentRejected;
use SageGrids\ContinuousDelivery\Services\DeploymentDispatcher;

class ApprovalController extends Controller
{
    public function __construct(
        protected DeploymentDispatcher $dispatcher
    ) {}

    /**
     * Show approval confirmation.
     */
    public function confirmApprove(string $token, Request $request): Response
    {
        if (! $request->hasValidSignature()) {
            return $this->renderError(
                'Invalid Link',
                'This approval link is invalid or has expired.'
            );
        }

        $deployment = $this->findDeployment($token, $request);

        if (! $deployment) {
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

        if (! $deployment->canBeApproved()) {
            return $this->renderError(
                'Cannot Approve',
                "This deployment cannot be approved. Current status: {$deployment->status->value}"
            );
        }

        return response()->view('continuous-delivery::confirm-approval', [
            'deployment' => $deployment,
            'token' => $token,
        ]);
    }

    /**
     * Approve a deployment.
     */
    public function approve(string $token, Request $request): Response
    {
        // For POST actions, signature might strictly not be needed if we assume they come from the form which we just loaded.
        // However, if the user bookmarks the form URL (without signature) it fails on GET.
        // But for POST, the signature query param should still be there if the form action preserves query params.
        // The form action usually is just the current URL.
        // Let's enforce it for safety.
        if (! $request->hasValidSignature()) {
             return $this->renderError(
                'Invalid Link',
                'This action link is invalid or has expired.'
            );
        }

        $deployment = $this->findDeployment($token, $request);

        if (! $deployment) {
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

        if (! $deployment->canBeApproved()) {
            return $this->renderError(
                'Cannot Approve',
                "This deployment cannot be approved. Current status: {$deployment->status->value}"
            );
        }

        $approvedBy = $this->getApproverIdentifier($request);

        try {
            $deployment->approve($approvedBy);

            Log::info('[continuous-delivery] Deployment approved', [
                'uuid' => $deployment->uuid,
                'approved_by' => $approvedBy,
            ]);

            $this->dispatchDeployment($deployment);
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
     * Show rejection confirmation.
     */
    public function confirmReject(string $token, Request $request): Response
    {
        if (! $request->hasValidSignature()) {
            return $this->renderError(
                'Invalid Link',
                'This rejection link is invalid or has expired.'
            );
        }

        $deployment = $this->findDeployment($token, $request);

        if (! $deployment) {
            return $this->renderError(
                'Deployment Not Found',
                'This deployment request was not found or has already been processed.'
            );
        }

        if (! $deployment->canBeRejected()) {
            return $this->renderError(
                'Cannot Reject',
                "This deployment cannot be rejected. Current status: {$deployment->status->value}"
            );
        }

        return response()->view('continuous-delivery::confirm-rejection', [
            'deployment' => $deployment,
            'token' => $token,
        ]);
    }

    /**
     * Reject a deployment.
     */
    public function reject(string $token, Request $request): Response
    {
        if (! $request->hasValidSignature()) {
             return $this->renderError(
                'Invalid Link',
                'This action link is invalid or has expired.'
            );
        }

        $deployment = $this->findDeployment($token, $request);

        if (! $deployment) {
            return $this->renderError(
                'Deployment Not Found',
                'This deployment request was not found or has already been processed.'
            );
        }

        if (! $deployment->canBeRejected()) {
            return $this->renderError(
                'Cannot Reject',
                "This deployment cannot be rejected. Current status: {$deployment->status->value}"
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
     * Find deployment by approval token using hash lookup.
     */
    protected function findDeployment(string $token, Request $request): ?DeployerDeployment
    {
        $tokenLength = config('continuous-delivery.approval.token_length', 64);

        if (strlen($token) !== $tokenLength) {
            $this->logFailedAttempt($request, 'invalid_token_length', $token);

            return null;
        }

        $deployment = DeployerDeployment::findByApprovalToken($token);

        if (! $deployment) {
            $this->logFailedAttempt($request, 'token_not_found', $token);
        }

        return $deployment;
    }

    /**
     * Log failed approval/rejection attempts.
     */
    protected function logFailedAttempt(Request $request, string $reason, string $token): void
    {
        Log::warning('[continuous-delivery] Failed approval attempt', [
            'reason' => $reason,
            'token_prefix' => substr($token, 0, 8).'...',
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'referer' => $request->header('Referer'),
        ]);
    }

    /**
     * Get identifier for the approver.
     */
    protected function getApproverIdentifier(Request $request): string
    {
        if ($user = $request->user()) {
            return $user->email ?? $user->name ?? "user:{$user->id}";
        }

        return "ip:{$request->ip()}";
    }

    /**
     * Dispatch deployment job.
     */
    protected function dispatchDeployment(DeployerDeployment $deployment): void
    {
        $this->dispatcher->dispatch($deployment);
    }

    /**
     * Render success view.
     */
    protected function renderSuccess(DeployerDeployment $deployment): Response
    {
        return response()->view('continuous-delivery::approved', [
            'deployment' => $deployment,
        ]);
    }

    /**
     * Render rejected view.
     */
    protected function renderRejected(DeployerDeployment $deployment): Response
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
    protected function notifyApproved(DeployerDeployment $deployment): void
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
    protected function notifyRejected(DeployerDeployment $deployment): void
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
