<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deployment Rejected</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 480px;
            width: 100%;
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
            padding: 40px 30px;
            text-align: center;
            color: white;
        }
        .icon {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 40px;
        }
        .header h1 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .header p {
            font-size: 14px;
            opacity: 0.9;
        }
        .content {
            padding: 30px;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            color: #6b7280;
            font-size: 14px;
        }
        .detail-value {
            color: #1f2937;
            font-weight: 500;
            font-size: 14px;
        }
        .status-badge {
            background: #f3f4f6;
            color: #6b7280;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .footer {
            background: #f9fafb;
            padding: 20px 30px;
            text-align: center;
        }
        .footer p {
            color: #6b7280;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div class="icon">&#10005;</div>
            <h1>Deployment Rejected</h1>
            <p>The deployment has been cancelled.</p>
        </div>
        <div class="content">
            <div class="detail-row">
                <span class="detail-label">Environment</span>
                <span class="detail-value">{{ $deployment->environment }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Trigger</span>
                <span class="detail-value">{{ $deployment->trigger_type }}:{{ $deployment->trigger_ref }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Commit</span>
                <span class="detail-value">{{ $deployment->short_commit_sha }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Author</span>
                <span class="detail-value">{{ $deployment->author }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Status</span>
                <span class="status-badge">Rejected</span>
            </div>
            @if($deployment->rejection_reason)
            <div class="detail-row">
                <span class="detail-label">Reason</span>
                <span class="detail-value">{{ $deployment->rejection_reason }}</span>
            </div>
            @endif
        </div>
        <div class="footer">
            <p>Create a new release to trigger another deployment.</p>
        </div>
    </div>
</body>
</html>
