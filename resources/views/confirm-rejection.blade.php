<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Rejection</title>
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
            background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%);
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
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; color: #6b7280; font-size: 14px; margin-bottom: 8px; }
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
        }
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
        .btn-confirm { background: #ef4444; color: white; }
        .btn-cancel { background: #e5e7eb; color: #4b5563; }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div class="icon">&#10005;</div>
            <h1>Reject Deployment</h1>
            <p>Are you sure you want to reject this deployment?</p>
        </div>
        <form action="{{ route('continuous-delivery.reject', $deployment->approval_token) }}" method="POST">
            @csrf
            <div class="content">
                <div class="form-group">
                    <label class="form-label" for="reason">Reason for rejection (optional)</label>
                    <textarea id="reason" name="reason" rows="3" class="form-control" placeholder="e.g., Critical bug found in staging"></textarea>
                </div>
            </div>
            <div class="actions">
                <button type="submit" class="btn btn-confirm">Reject Deployment</button>
            </div>
        </form>
    </div>
</body>
</html>
