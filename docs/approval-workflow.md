# Approval Workflow

Human approval workflow for production deployments.

## Overview

When a GitHub release is published and matches your tag pattern, the package:

1. Creates a deployment record with status `pending_approval`
2. Generates a unique 64-character approval token
3. Sends notification with Approve/Reject links
4. Waits for human approval (up to configured timeout)
5. On approval: queues deployment job
6. On rejection/timeout: marks deployment as rejected/expired

---

## Approval Methods

### 1. Telegram/Slack Links (Primary)

Click the link in the notification:

```
🚀 Production Deploy Request

Version: v1.2.3
Commit: abc1234

[✅ Approve] [❌ Reject]

⏰ Expires in 2 hours
```

**Pros:** Quick, works from mobile
**Cons:** Anyone with the link can approve

### 2. CLI Commands (Fallback)

```bash
# List pending deployments
php artisan deployer:pending

# Approve by UUID (partial match supported)
php artisan deployer:approve abc123

# Reject with reason
php artisan deployer:reject abc123 --reason="Not ready for release"

# Check status
php artisan deployer:status abc123 --output
```

**Pros:** Audit trail shows shell user, can add rejection reason
**Cons:** Requires SSH access

### 3. Web Interface (Direct URL)

Open the approval URL directly:

```
https://your-app.com/api/deploy/approve/TOKEN_HERE
```

Shows confirmation page with deployment details.

---

## Approval Flow Diagram

```
                    ┌─────────────────┐
                    │  GitHub Release │
                    │   Published     │
                    └────────┬────────┘
                             │
                             ▼
                    ┌─────────────────┐
                    │  Webhook Call   │
                    │  to your app    │
                    └────────┬────────┘
                             │
                             ▼
                    ┌─────────────────┐
                    │ Create Pending  │
                    │  Deployment     │
                    │ (token, expiry) │
                    └────────┬────────┘
                             │
                             ▼
                    ┌─────────────────┐
                    │ Send Approval   │
                    │  Notification   │
                    └────────┬────────┘
                             │
         ┌───────────────────┼───────────────────┐
         │                   │                   │
         ▼                   ▼                   ▼
┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐
│    APPROVE      │ │    REJECT       │ │    TIMEOUT      │
│  (click link    │ │  (click link    │ │  (scheduled     │
│   or CLI)       │ │   or CLI)       │ │   expiry)       │
└────────┬────────┘ └────────┬────────┘ └────────┬────────┘
         │                   │                   │
         ▼                   ▼                   ▼
┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐
│ Status: queued  │ │ Status: rejected│ │ Status: expired │
│ Dispatch job    │ │ Notify team     │ │ Notify team     │
│ Notify approval │ │                 │ │                 │
└────────┬────────┘ └─────────────────┘ └─────────────────┘
         │
         ▼
┌─────────────────┐
│ Execute Envoy   │
│ (production)    │
└────────┬────────┘
         │
    ┌────┴────┐
    │         │
    ▼         ▼
┌────────┐ ┌────────┐
│ Success│ │ Failed │
│ Notify │ │ Notify │
└────────┘ └────────┘
```

---

## Configuration

Approval settings are defined in your `config/continuous-delivery.php` triggers:

```php
'triggers' => [
    [
        'name' => 'production',
        'on' => 'release',
        'auto_deploy' => false,  // This enables approval requirement
        'approval_timeout' => 2, // Timeout in hours
    ],
],
```

### Global Options

```env
# Auto-expire pending deployments
CD_AUTO_EXPIRE=true
CD_NOTIFY_ON_EXPIRE=true
```

---

## Security Considerations

### Token Security

- Tokens are 64 random alphanumeric characters
- Statistically unique (62^64 combinations)
- Single-use: token is consumed on approve/reject
- Time-limited: expires after configured timeout

### Approval Recording

Every approval/rejection records:

- Timestamp
- Method (IP address or CLI username)
- Reason (for rejections)

Example audit log:

```
Approved by: ip:192.168.1.100
Approved at: 2024-01-15 14:30:00
```

```
Rejected by: cli:admin
Rejected at: 2024-01-15 14:25:00
Reason: Missing database migration
```

### Recommendations

1. **Use short timeouts**: 2-4 hours is reasonable
2. **Enable notifications**: Know when approvals are pending
3. **Monitor expired deployments**: Set up alerting
4. **Use VPN**: Restrict webhook URLs to your network

---

## CLI Commands Reference

### List Pending

```bash
php artisan deployer:pending
```

Output:
```
+----------+-------------+--------+-----------------+---------+------------+
| UUID     | Environment | Ref    | Status          | Expires | Created    |
+----------+-------------+--------+-----------------+---------+------------+
| abc123...| production  | v1.2.3 | pending_approval| 1h 45m  | 15 min ago |
+----------+-------------+--------+-----------------+---------+------------+

To approve: php artisan deployer:approve {uuid}
To reject:  php artisan deployer:reject {uuid}
```

### Approve

```bash
# Full UUID
php artisan deployer:approve 550e8400-e29b-41d4-a716-446655440000

# Partial UUID (matches start)
php artisan deployer:approve 550e84

# Skip confirmation
php artisan deployer:approve 550e84 --force
```

### Reject

```bash
# With reason
php artisan deployer:reject 550e84 --reason="Not ready"

# Interactive (prompts for reason)
php artisan deployer:reject 550e84

# Skip confirmation
php artisan deployer:reject 550e84 --reason="Not ready" --force
```

### Check Status

```bash
# Basic status
php artisan deployer:status 550e84

# With full output
php artisan deployer:status 550e84 --output
```

---

## Disabling Approval

For staging-like production (auto-deploy):

```env
CD_PRODUCTION_APPROVAL=false
```

**Warning:** This removes the safety net. Use only if:
- You have comprehensive CI/CD testing
- You trust your release process
- You have quick rollback capability
