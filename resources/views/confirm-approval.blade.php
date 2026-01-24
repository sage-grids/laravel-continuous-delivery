<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Approval</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
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
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
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
        .header h1 { font-size: 24px; font-weight: 600; margin-bottom: 8px; }
        .header p { font-size: 14px; opacity: 0.9; }
        .content { padding: 30px; }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { color: #6b7280; font-size: 14px; }
        .detail-value { color: #1f2937; font-weight: 500; font-size: 14px; }
        .actions {
            padding: 0 30px 30px;
            display: flex;
            gap: 10px;
        }
        .btn {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            transition: opacity 0.2s;
        }
        .btn:hover { opacity: 0.9; }
        .btn-confirm { background: #2563eb; color: white; }
        .btn-cancel { background: #e5e7eb; color: #4b5563; }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div class="icon">&#63;</div>
            <h1>Confirm Deployment</h1>
            <p>Are you sure you want to approve this deployment?</p>
        </div>
        <div class="content">
            <div class="detail-row">
                <span class="detail-label">App</span>
                <span class="detail-value">{{ $deployment->app_name }}</span>
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
        </div>
        <form action="{{ \Illuminate\Support\Facades\URL::signedRoute('continuous-delivery.approve', ['token' => $deployment->approval_token], $deployment->approval_expires_at) }}" method="POST" class="actions">
            @csrf
            <button type="submit" class="btn btn-confirm">Yes, Approve Deployment</button>
        </form>
    </div>
</body>
</html>
