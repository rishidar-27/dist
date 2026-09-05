<?php
/**
 * GoGangs Studio - Database Reset & Cleanup Utility
 * 
 * Safely clears test users, old portfolios, and uploaded videos from the database
 * to provide a clean slate for new updates without overlapping old data.
 */

header('Content-Type: text/html; charset=utf-8');

// Production Database Credentials
$db_host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? "localhost");
$db_user = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? "profilei_Hari");
$db_pass = getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? "Rishidar123@");
$db_name = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? "profilei_website");

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("<div style='font-family:sans-serif;padding:30px;background:#1a1012;color:#ff5555;border-radius:12px;max-width:600px;margin:40px auto;'>
        <h2>Database Connection Error</h2>
        <p>" . htmlspecialchars($conn->connect_error) . "</p>
    </div>");
}

$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'clear_all_freelancers_and_videos') {
        // 1. Truncate / delete all records from freelancers table
        $conn->query("TRUNCATE TABLE freelancers");
        
        // 2. Truncate / delete all records from portfolio_videos table
        $conn->query("TRUNCATE TABLE portfolio_videos");

        // 3. Reset app_settings if requested
        if (!empty($_POST['reset_settings'])) {
            $conn->query("TRUNCATE TABLE app_settings");
            $conn->query("INSERT INTO app_settings (setting_key, setting_value) VALUES ('max_allowed_users', '50'), ('registration_open', '1')");
        }

        $message = "SUCCESS! All freelancers, portfolios, and uploaded video records have been completely wiped from the database. Clean slate ready.";
        $messageType = 'success';
    } elseif ($_POST['action'] === 'clear_keep_admin') {
        // Keep only the primary admin (rishidar27@gmail.com) and clear all test accounts
        $conn->query("DELETE FROM freelancers WHERE LOWER(email) != 'rishidar27@gmail.com'");
        $conn->query("DELETE FROM portfolio_videos WHERE LOWER(email) != 'rishidar27@gmail.com'");
        
        $message = "SUCCESS! All test accounts and test videos have been wiped. Admin account preserved.";
        $messageType = 'success';
    }
}

// Fetch current counts
$freelancerCount = 0;
$videoCount = 0;

$res1 = $conn->query("SELECT COUNT(*) as cnt FROM freelancers");
if ($res1 && $row = $res1->fetch_assoc()) {
    $freelancerCount = (int)$row['cnt'];
}

$res2 = $conn->query("SELECT COUNT(*) as cnt FROM portfolio_videos");
if ($res2 && $row = $res2->fetch_assoc()) {
    $videoCount = (int)$row['cnt'];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GoGangs Studio - Database Reset Tool</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #0b0c10;
            color: #f1f1f1;
            padding: 40px 20px;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
        }
        .container {
            max-width: 680px;
            width: 100%;
            background: #14161f;
            border: 1px solid #282a36;
            border-radius: 20px;
            padding: 36px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.7);
        }
        h1 {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 8px;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        p.subtitle {
            color: #8a8d9b;
            font-size: 14px;
            margin-bottom: 24px;
            line-height: 1.5;
        }
        .badge-bar {
            display: flex;
            gap: 16px;
            margin-bottom: 28px;
        }
        .badge-box {
            flex: 1;
            background: #1c1e2b;
            border: 1px solid #2d3044;
            padding: 16px;
            border-radius: 14px;
            text-align: center;
        }
        .badge-box .num {
            font-size: 28px;
            font-weight: 800;
            font-family: monospace;
            color: #a855f7;
        }
        .badge-box .label {
            font-size: 12px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            font-size: 14px;
            line-height: 1.5;
            margin-bottom: 24px;
        }
        .alert-success {
            background: #0f2d1e;
            border: 1px solid #10b981;
            color: #34d399;
        }
        .btn-danger {
            width: 100%;
            padding: 16px;
            background: #dc2626;
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 16px rgba(220, 38, 38, 0.3);
            margin-top: 12px;
        }
        .btn-danger:hover {
            background: #b91c1c;
            transform: translateY(-1px);
        }
        .btn-secondary {
            width: 100%;
            padding: 14px;
            background: #27272a;
            color: #e4e4e7;
            font-size: 14px;
            font-weight: 600;
            border: 1px solid #3f3f46;
            border-radius: 12px;
            cursor: pointer;
            margin-top: 10px;
            transition: background 0.2s;
        }
        .btn-secondary:hover {
            background: #3f3f46;
        }
        .sql-box {
            background: #090a0f;
            border: 1px solid #232533;
            border-radius: 12px;
            padding: 16px;
            margin-top: 24px;
        }
        .sql-box h3 {
            font-size: 13px;
            color: #94a3b8;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        pre {
            background: #000;
            color: #22c55e;
            padding: 12px;
            border-radius: 8px;
            font-size: 13px;
            overflow-x: auto;
            font-family: Consolas, monospace;
        }
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #cbd5e1;
            margin-top: 12px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🗑️ Database History Reset Tool</h1>
        <p class="subtitle">Use this tool or the SQL queries below to wipe test records and cached history so fresh creator updates won't conflict with old data.</p>

        <?php if (!empty($message)): ?>
            <div class="alert alert-success">
                <strong>✓</strong> <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="badge-bar">
            <div class="badge-box">
                <div class="num"><?= $freelancerCount ?></div>
                <div class="label">Stored Freelancers</div>
            </div>
            <div class="badge-box">
                <div class="num"><?= $videoCount ?></div>
                <div class="label">Stored Videos</div>
            </div>
        </div>

        <form method="POST" onsubmit="return confirm('⚠️ WARNING: This will permanently delete all existing freelancer portfolios and video records from MySQL. Are you sure you want to proceed?');">
            <input type="hidden" name="action" value="clear_all_freelancers_and_videos">
            
            <label class="checkbox-label">
                <input type="checkbox" name="reset_settings" value="1" checked>
                Reset App Settings (Max creators limit to 50)
            </label>

            <button type="submit" class="btn-danger">
                ⚡ Wipe All Database Records (Fresh Start)
            </button>
        </form>

        <form method="POST" onsubmit="return confirm('Wipe all test creators while keeping admin account?');">
            <input type="hidden" name="action" value="clear_keep_admin">
            <button type="submit" class="btn-secondary">
                🛡️ Clear Test Users (Preserve Admin: rishidar27@gmail.com)
            </button>
        </form>

        <div class="sql-box">
            <h3>Manual phpMyAdmin SQL Queries (Copy & Run in cPanel)</h3>
            <pre>-- 1. Wipe all freelancer portfolios
TRUNCATE TABLE freelancers;

-- 2. Wipe all stored videos
TRUNCATE TABLE portfolio_videos;

-- 3. Reset app settings
TRUNCATE TABLE app_settings;
INSERT INTO app_settings (setting_key, setting_value) 
VALUES ('max_allowed_users', '50'), ('registration_open', '1');</pre>
        </div>
    </div>
</body>
</html>
