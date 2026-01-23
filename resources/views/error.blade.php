<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Error' }}</title>
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
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
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
        .content {
            padding: 30px;
            text-align: center;
        }
        .content p {
            color: #4b5563;
            font-size: 15px;
            line-height: 1.6;
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
        .footer code {
            background: #e5e7eb;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div class="icon">!</div>
            <h1>{{ $title ?? 'Error' }}</h1>
        </div>
        <div class="content">
            <p>{{ $message ?? 'An unexpected error occurred.' }}</p>
        </div>
        <div class="footer">
            <p>Use CLI fallback: <code>php artisan deploy:pending</code></p>
        </div>
    </div>
</body>
</html>
