<?php
// Production & Local Database Direct Auto-Configuration
$conn = @new mysqli("localhost", "profilei_Hari", "Rishidar123@", "profilei_website");
if ($conn->connect_error) {
    $conn = @new mysqli("localhost", "root", "", "studio");
}

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Debug api.php errors live
if (isset($_GET['debug_api'])) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $_GET['uri'] ?? '/api/freelancers/status?email=&userCode=ggve0002';
    require __DIR__ . '/api.php';
    exit();
}

// Instant JSON API Endpoints for freelancers and videos (Fail-safe API fallback)
if (isset($_GET['api'])) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Content-Type: application/json; charset=utf-8');

    if ($_GET['api'] === 'freelancers') {
        $res = $conn->query("SELECT * FROM freelancers ORDER BY id DESC");
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                if (!empty($r['portfolio_data']) && strpos($r['portfolio_data'], 'data:video') !== false) {
                    $r['portfolio_data'] = preg_replace('/"videoUrl"\s*:\s*"data:video\/[^"]+"/', '"videoUrl":""', $r['portfolio_data']);
                }
                $rows[] = $r;
            }
        }
        echo json_encode($rows);
        $conn->close();
        exit();
    }
    if ($_GET['api'] === 'migrate_base64_videos') {
        $res = $conn->query("SELECT id, email, portfolio_data FROM freelancers WHERE portfolio_data LIKE '%data:video%'");
        $migrated = 0;
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $pd = json_decode($row['portfolio_data'], true);
                if ($pd && !empty($pd['videos']) && is_array($pd['videos'])) {
                    $changed = false;
                    foreach ($pd['videos'] as &$v) {
                        if (!empty($v['videoUrl']) && strpos($v['videoUrl'], 'data:video') === 0) {
                            $vidId = 'vid_' . time() . '_' . substr(md5($v['id'] ?? uniqid()), 0, 6);
                            $videoData = $v['videoUrl'];
                            $fileType = 'video/mp4';
                            if (preg_match('#^data:(video/[^;]+);base64,#', $videoData, $m)) {
                                $fileType = $m[1];
                            }
                            $filename = ($v['title'] ?? 'video') . '.mp4';
                            $ins = $conn->prepare("INSERT INTO portfolio_videos (video_id, email, filename, file_type, video_data) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE video_data = VALUES(video_data)");
                            if ($ins) {
                                $ins->bind_param("sssss", $vidId, $row['email'], $filename, $fileType, $videoData);
                                $ins->execute();
                                $ins->close();
                                $v['videoUrl'] = "https://studio.gogangs.com/api/videos/{$vidId}";
                                $changed = true;
                            }
                        }
                    }
                    if ($changed) {
                        $newPd = json_encode($pd);
                        $upd = $conn->prepare("UPDATE freelancers SET portfolio_data = ? WHERE id = ?");
                        if ($upd) {
                            $upd->bind_param("si", $newPd, $row['id']);
                            $upd->execute();
                            $upd->close();
                            $migrated++;
                        }
                    }
                }
            }
        }
        echo json_encode(['status' => 'success', 'migrated' => $migrated]);
        $conn->close();
        exit();
    }
    if ($_GET['api'] === 'videos') {
        $res = $conn->query("SELECT id, video_id, email, filename, file_type, created_at FROM portfolio_videos ORDER BY id DESC");
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) $rows[] = $r;
        }
        echo json_encode($rows);
        $conn->close();
        exit();
    }
    if ($_GET['api'] === 'by-email' && !empty($_GET['email'])) {
        $targetEmail = strtolower(trim($_GET['email']));
        $stmt = $conn->prepare("SELECT * FROM freelancers WHERE LOWER(email) = ? LIMIT 1");
        $stmt->bind_param("s", $targetEmail);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        if ($row) {
            $parsed = !empty($row['portfolio_data']) ? json_decode($row['portfolio_data'], true) : [];
            $out = $parsed ?: [];
            $out['email'] = $row['email'];
            $out['username'] = $row['username'];
            $out['fullName'] = $row['name'] ?? ($parsed['fullName'] ?? '');
            $out['userCode'] = $row['member_id'] ?? ($parsed['userCode'] ?? '');
            $hasCompleted = (bool)$row['has_completed_onboarding'] || !empty($parsed['hasCompletedOnboarding']);
            $out['hasCompletedOnboarding'] = $hasCompleted;
            $out['approvalStatus'] = $row['approval_status'] ?: ($parsed['approvalStatus'] ?? 'pending');
            $out['approvedAt'] = $row['approved_at'] ?: ($parsed['approvedAt'] ?? null);
            echo json_encode($out);
        } else {
            echo json_encode(null);
        }
        $conn->close();
        exit();
    }
    if ($_GET['api'] === 'by-username' && !empty($_GET['identifier'])) {
        $id = strtolower(trim($_GET['identifier']));
        $stmt = $conn->prepare("SELECT * FROM freelancers WHERE LOWER(member_id) = ? OR LOWER(username) = ? OR LOWER(email) = ? LIMIT 1");
        $stmt->bind_param("sss", $id, $id, $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        if ($row) {
            $parsed = !empty($row['portfolio_data']) ? json_decode($row['portfolio_data'], true) : [];
            $out = $parsed ?: [];
            $out['email'] = $row['email'];
            $out['username'] = $row['username'];
            $out['fullName'] = $row['name'] ?? ($parsed['fullName'] ?? '');
            $out['userCode'] = $row['member_id'] ?? ($parsed['userCode'] ?? '');
            $hasCompleted = (bool)$row['has_completed_onboarding'] || !empty($parsed['hasCompletedOnboarding']);
            $out['hasCompletedOnboarding'] = $hasCompleted;
            $out['approvalStatus'] = $row['approval_status'] ?: ($parsed['approvalStatus'] ?? 'pending');
            $out['approvedAt'] = $row['approved_at'] ?: ($parsed['approvedAt'] ?? null);
            echo json_encode($out);
        } else {
            echo json_encode(null);
        }
        $conn->close();
        exit();
    }
    if ($_GET['api'] === 'status') {
        $email = strtolower(trim($_GET['email'] ?? ''));
        $userCode = strtolower(trim($_GET['userCode'] ?? ($_GET['user_code'] ?? '')));
        $stmt = $conn->prepare("SELECT id, email, member_id, username, approval_status, approved_at, portfolio_data FROM freelancers WHERE (email != '' AND LOWER(email) = ?) OR (member_id != '' AND LOWER(member_id) = ?) LIMIT 1");
        $stmt->bind_param("ss", $email, $userCode);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        if ($row) {
            $pData = !empty($row['portfolio_data']) ? json_decode($row['portfolio_data'], true) : [];
            $appStatus = $row['approval_status'] ?: ($pData['approvalStatus'] ?? 'pending');
            $appAt = $row['approved_at'] ?: ($pData['approvedAt'] ?? null);
            echo json_encode([
                'status' => $appStatus,
                'approvalStatus' => $appStatus,
                'approvedAt' => $appAt,
                'email' => $row['email'],
                'userCode' => $row['member_id']
            ]);
        } else {
            echo json_encode(['status' => 'not_found', 'message' => 'Account not found']);
        }
        $conn->close();
        exit();
    }
    if ($_GET['api'] === 'settings') {
        $res = $conn->query("SELECT setting_key, setting_value FROM app_settings");
        $settings = ['max_allowed_users' => 50, 'registration_open' => 1];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $val = $r['setting_value'];
                if (is_numeric($val)) $val = (int)$val;
                $settings[$r['setting_key']] = $val;
            }
        }
        echo json_encode($settings);
        $conn->close();
        exit();
    }
    if ($_GET['api'] === 'approve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true) ?: [];

        $email = strtolower(trim($input['email'] ?? ''));
        $status = strtolower(trim($input['status'] ?? 'approved'));
        if (!in_array($status, ['approved', 'pending', 'rejected'])) {
            $status = 'approved';
        }
        $approvedAt = ($status === 'approved') ? date('Y-m-d H:i:s') : null;

        if (empty($email)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing email']);
            $conn->close();
            exit();
        }

        $stmt = $conn->prepare("SELECT id, portfolio_data FROM freelancers WHERE LOWER(email) = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if ($row) {
            $pData = !empty($row['portfolio_data']) ? json_decode($row['portfolio_data'], true) : [];
            $pData['approvalStatus'] = $status;
            $pData['approvedAt'] = $approvedAt;
            $pJson = json_encode($pData);

            $uStmt = $conn->prepare("UPDATE freelancers SET approval_status = ?, approved_at = ?, portfolio_data = ? WHERE id = ?");
            $uStmt->bind_param("sssi", $status, $approvedAt, $pJson, $row['id']);
            $uStmt->execute();
            $uStmt->close();

            echo json_encode([
                'status' => 'success',
                'email' => $email,
                'approval_status' => $status,
                'approved_at' => $approvedAt
            ]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Freelancer not found']);
        }
        $conn->close();
        exit();
    }
    if ($_GET['api'] === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (!$input || empty($input['email'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing email in payload']);
            $conn->close();
            exit();
        }

        $email = strtolower(trim($input['email']));
        $name = !empty($input['name']) ? trim($input['name']) : (!empty($input['portfolio']['fullName']) ? trim($input['portfolio']['fullName']) : 'Freelancer');

        $customUsername = '';
        if (!empty($input['username'])) {
            $customUsername = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($input['username'])));
        } else if (!empty($input['portfolio']['username'])) {
            $customUsername = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($input['portfolio']['username'])));
        }
        if (!empty($customUsername) && strlen($customUsername) >= 2) {
            $username = $customUsername;
        } else if (!empty($name) && strtolower($name) !== 'freelancer') {
            $username = preg_replace('/[^a-z0-9_-]/', '', strtolower($name));
        } else {
            $username = preg_replace('/[^a-z0-9_-]/', '', explode('@', $email)[0]);
        }

        $has_completed = !empty($input['has_completed_onboarding']) ? 1 : 0;

        // Determine member code (GGVE0001, GGVE0002, ...)
        $assignedMemberId = 'GGVE0001';
        if ($email !== 'rishidar27@gmail.com') {
            $sStmt = $conn->prepare("SELECT member_id FROM freelancers WHERE LOWER(email) = ? LIMIT 1");
            $sStmt->bind_param("s", $email);
            $sStmt->execute();
            $sRow = $sStmt->get_result()->fetch_assoc();
            $sStmt->close();

            if ($sRow && !empty($sRow['member_id']) && $sRow['member_id'] !== 'GGVE0001') {
                $assignedMemberId = strtoupper(trim($sRow['member_id']));
            } else {
                $maxRes = $conn->query("SELECT member_id FROM freelancers WHERE member_id LIKE 'GGVE%'");
                $maxNum = 1;
                if ($maxRes) {
                    while ($mr = $maxRes->fetch_assoc()) {
                        if (preg_match('/GGVE(\d+)/i', $mr['member_id'] ?? '', $m)) {
                            $n = (int)$m[1];
                            if ($n > $maxNum) $maxNum = $n;
                        }
                    }
                }
                $assignedMemberId = sprintf("GGVE%04d", $maxNum + 1);
            }
        }

        $portfolioArray = [];
        if (isset($input['portfolio']) && is_array($input['portfolio'])) {
            $portfolioArray = $input['portfolio'];
        } else if (isset($input['portfolio_data']) && is_array($input['portfolio_data'])) {
            $portfolioArray = $input['portfolio_data'];
        } else if (is_array($input)) {
            $portfolioArray = $input;
        }
        $portfolioArray['userCode'] = $assignedMemberId;
        $portfolioArray['member_id'] = $assignedMemberId;
        $portfolioArray['email'] = $email;
        $portfolioArray['username'] = $username;
        $portfolioArray['fullName'] = $name;

        // Check if user already exists
        $chkStmt = $conn->prepare("SELECT id, approval_status, approved_at FROM freelancers WHERE LOWER(email) = ? LIMIT 1");
        $chkStmt->bind_param("s", $email);
        $chkStmt->execute();
        $existingRow = $chkStmt->get_result()->fetch_assoc();
        $chkStmt->close();

        $incomingApproval = !empty($input['portfolio']['approvalStatus']) ? trim($input['portfolio']['approvalStatus']) : (!empty($input['approval_status']) ? trim($input['approval_status']) : '');

        if ($email === 'rishidar27@gmail.com') {
            $approvalStatusCol = 'approved';
            $approvedAtCol = date('Y-m-d H:i:s');
        } else if (!empty($incomingApproval) && in_array($incomingApproval, ['approved', 'rejected', 'pending'])) {
            $approvalStatusCol = $incomingApproval;
            $approvedAtCol = ($approvalStatusCol === 'approved') ? date('Y-m-d H:i:s') : null;
        } else if ($existingRow) {
            $approvalStatusCol = $existingRow['approval_status'] ?: 'pending';
            $approvedAtCol = $existingRow['approved_at'] ?? null;
        } else {
            $approvalStatusCol = 'pending';
            $approvedAtCol = null;
        }

        $portfolioArray['approvalStatus'] = $approvalStatusCol;
        $portfolioArray['approvedAt'] = $approvedAtCol;

        if ($existingRow) {
            $portfolioData = json_encode($portfolioArray);
            $stmt = $conn->prepare("UPDATE freelancers SET username = ?, name = ?, member_id = ?, portfolio_data = ?, has_completed_onboarding = ?, approval_status = ?, approved_at = ? WHERE id = ?");
            $stmt->bind_param("ssssissi", $username, $name, $assignedMemberId, $portfolioData, $has_completed, $approvalStatusCol, $approvedAtCol, $existingRow['id']);
        } else {
            if (isset($input['portfolio']['videos']) && is_array($input['portfolio']['videos'])) {
                $portfolioArray['videos'] = $input['portfolio']['videos'];
            }
            $portfolioData = json_encode($portfolioArray);

            $stmt = $conn->prepare("INSERT INTO freelancers (member_id, email, username, name, portfolio_data, has_completed_onboarding, approval_status, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssiss", $assignedMemberId, $email, $username, $name, $portfolioData, $has_completed, $approvalStatusCol, $approvedAtCol);
        }

        if ($stmt && $stmt->execute()) {
            $stmt->close();
            $cleanEmail = $conn->real_escape_string($email);
            $conn->query("DELETE FROM deleted_accounts WHERE LOWER(email) = '{$cleanEmail}'");
            echo json_encode(['status' => 'success', 'email' => $email, 'username' => $username, 'member_id' => $assignedMemberId, 'userCode' => $assignedMemberId]);
        } else {
            $err = $stmt ? $stmt->error : $conn->error;
            if ($stmt) $stmt->close();
            http_response_code(500);
            echo json_encode(['error' => 'MySQL save failed: ' . $err]);
        }
        $conn->close();
        exit();
    }
    if ($_GET['api'] === 'update_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $key = $_GET['key'] ?? '';
        if ($key !== 'gogangs_secret_2026') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit();
        }
        $target = $_GET['target'] ?? '';
        if ($target === 'api.php') {
            $raw = file_get_contents('php://input');
            if (!empty($raw) && strlen($raw) > 500) {
                file_put_contents(__DIR__ . '/api.php', $raw);
                echo json_encode(['success' => true, 'bytes' => strlen($raw)]);
                exit();
            }
        }
    }
}

// 1. Ensure Table: freelancers
$conn->query("
    CREATE TABLE IF NOT EXISTS freelancers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        member_id VARCHAR(50),
        email VARCHAR(255) UNIQUE NOT NULL,
        username VARCHAR(255),
        name VARCHAR(255),
        portfolio_data LONGTEXT,
        has_completed_onboarding TINYINT(1) DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// 2. Ensure Table: portfolio_videos
$conn->query("
    CREATE TABLE IF NOT EXISTS portfolio_videos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        video_id VARCHAR(100) NOT NULL UNIQUE,
        email VARCHAR(255) NOT NULL,
        filename VARCHAR(255) NOT NULL,
        file_type VARCHAR(100) DEFAULT 'video/mp4',
        video_data LONGTEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_video_id (video_id),
        INDEX idx_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// DIRECT VIDEO STREAMING ROUTE (?stream=vid_xxx)
if (isset($_GET['stream']) && !empty($_GET['stream'])) {
    $videoId = trim($_GET['stream']);
    $stmt = $conn->prepare("SELECT video_data, file_type FROM portfolio_videos WHERE video_id = ? LIMIT 1");
    $stmt->bind_param("s", $videoId);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->bind_result($videoDataB64, $fileType);
        $stmt->fetch();
        $stmt->close();

        $binary = base64_decode($videoDataB64);
        header('Content-Type: ' . ($fileType ?: 'video/mp4'));
        header('Content-Length: ' . strlen($binary));
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=31536000');
        echo $binary;
        $conn->close();
        exit();
    }
    http_response_code(404);
    echo "Video not found in portfolio_videos database table.";
    $conn->close();
    exit();
}

header('Content-Type: text/html; charset=utf-8');
$msg = '';
$msgType = 'info';

// HANDLE MANUAL VIDEO FILE UPLOAD TEST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_test_video') {
    $testEmail = strtolower(trim($_POST['email'] ?? 'dummy_editor@gogangs.com'));
    $testUser = preg_replace('/[^a-z0-9_]/', '', explode('@', $testEmail)[0]);
    $testName = trim($_POST['name'] ?? 'Test Video Editor');
    $testTitle = trim($_POST['title'] ?? 'Uploaded Reel Video');

    $binaryData = '';
    $fileName = 'sample_video.mp4';
    $fileType = 'video/mp4';

    if (isset($_FILES['video_file']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK) {
        $fileName = $_FILES['video_file']['name'];
        $fileType = $_FILES['video_file']['type'] ?: 'video/mp4';
        $binaryData = file_get_contents($_FILES['video_file']['tmp_name']);
    } elseif (!empty($_POST['generate_dummy'])) {
        // Generate simulated video buffer payload
        $binaryData = "SIMULATED_VIDEO_BINARY_DATA_BUFFER_" . time() . "_" . str_repeat("01010101", 100000);
        $fileName = "auto_dummy_reel.mp4";
    }

    if (!empty($binaryData)) {
        $videoId = 'vid_' . time() . '_' . substr(md5(uniqid()), 0, 6);
        $b64 = base64_encode($binaryData);
        $sizeMB = round(strlen($binaryData) / (1024 * 1024), 2);
        $sizeBytes = strlen($binaryData);

        // 1. Insert directly into portfolio_videos MySQL table
        $stmtVid = $conn->prepare("
            INSERT INTO portfolio_videos (video_id, email, filename, file_type, video_data) 
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE video_data = VALUES(video_data)
        ");
        $stmtVid->bind_param("sssss", $videoId, $testEmail, $fileName, $fileType, $b64);
        
        if ($stmtVid->execute()) {
            $stmtVid->close();

            // 2. Also update or create freelancer portfolio in freelancers table
            $streamUrl = "/api/videos/stream?id=" . $videoId;
            $testStreamLocal = "test.php?stream=" . $videoId;

            // Fetch existing portfolio if any
            $stmtF = $conn->prepare("SELECT portfolio_data FROM freelancers WHERE email = ? LIMIT 1");
            $stmtF->bind_param("s", $testEmail);
            $stmtF->execute();
            $fRes = $stmtF->get_result()->fetch_assoc();
            $stmtF->close();

            $pData = [];
            if ($fRes && !empty($fRes['portfolio_data'])) {
                $pData = json_decode($fRes['portfolio_data'], true) ?: [];
            }

            $newVideoObj = [
                'id' => 'proj-' . time(),
                'title' => $testTitle,
                'videoUrl' => $streamUrl,
                'category' => 'Reels / Shorts',
                'orientation' => 'vertical',
                'type' => 'short-form',
                'duration' => '00:30',
                'fileSizeMB' => $sizeMB,
                'fileSizeBytes' => $sizeBytes
            ];

            $existingVideos = isset($pData['videos']) && is_array($pData['videos']) ? $pData['videos'] : [];
            $pData['id'] = $pData['id'] ?? ('freelancer-' . time());
            $pData['email'] = $testEmail;
            $pData['username'] = $testUser;
            $pData['fullName'] = $testName;
            $pData['userCode'] = $pData['userCode'] ?? 'GGVE0009';
            $pData['title'] = 'Professional Video Editor';
            $pData['hasCompletedOnboarding'] = true;
            $pData['videos'] = array_merge([$newVideoObj], $existingVideos);

            $pDataJson = json_encode($pData);

            $stmtSave = $conn->prepare("
                INSERT INTO freelancers (email, username, name, member_id, portfolio_data, has_completed_onboarding)
                VALUES (?, ?, ?, 'GGVE0009', ?, 1)
                ON DUPLICATE KEY UPDATE
                    portfolio_data = VALUES(portfolio_data),
                    has_completed_onboarding = 1,
                    name = VALUES(name)
            ");
            $stmtSave->bind_param("ssss", $testEmail, $testUser, $testName, $pDataJson);
            $stmtSave->execute();
            $stmtSave->close();

            $msg = "🎉 SUCCESS! Video file (<b>" . htmlspecialchars($fileName) . "</b>, <b>{$sizeMB} MB</b>) was stored directly into MySQL <code>portfolio_videos</code> table and linked to <code>freelancers</code> table for <b>$testEmail</b>!";
            $msgType = 'success';
            $latestStreamId = $videoId;
        } else {
            $msg = "❌ Failed to insert video into portfolio_videos: " . $stmtVid->error;
            $msgType = 'danger';
        }
    } else {
        $msg = "⚠️ Please select a video file or check 'Generate Simulated Video Payload'.";
        $msgType = 'warning';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Studio GoGangs — MySQL Video Storage & Streaming Tester</title>
  <style>
    body { background: #090a0f; color: #e4e4e7; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, monospace; padding: 25px; margin: 0; }
    .container { max-width: 1300px; margin: 0 auto; }
    h1, h2, h3 { color: #f4f4f5; font-weight: 800; }
    h1 span { color: #a855f7; }
    .card { background: #141622; border: 1px solid #27272a; border-radius: 16px; padding: 24px; margin-bottom: 20px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.5); }
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 25px; }
    .stat-box { background: #18181b; border: 1px solid #27272a; border-radius: 12px; padding: 18px; text-align: center; }
    .stat-num { font-size: 32px; font-weight: 800; font-family: monospace; }
    .stat-num.purple { color: #c084fc; }
    .stat-num.green { color: #4ade80; }
    .stat-num.amber { color: #fbbf24; }
    .stat-label { color: #a1a1aa; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; margin-top: 5px; }
    
    table { border-collapse: collapse; width: 100%; margin-top: 10px; background: #090a0f; border-radius: 12px; overflow: hidden; }
    th, td { border: 1px solid #27272a; padding: 12px 15px; text-align: left; font-size: 13px; }
    th { background: #18181b; color: #fbbf24; font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: 1px; }
    
    .badge { display: inline-block; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; font-family: monospace; margin-bottom: 15px; width: 100%; box-sizing: border-box; }
    .badge-success { background: rgba(34, 197, 94, 0.15); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.3); }
    .badge-warning { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
    .badge-danger { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }
    .badge-info { background: rgba(168, 85, 247, 0.15); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.3); }
    
    .btn { background: #7c3aed; color: #fff; border: none; padding: 10px 20px; border-radius: 10px; cursor: pointer; font-weight: 700; font-size: 13px; transition: all 0.2s; }
    .btn:hover { background: #6d28d9; transform: translateY(-1px); }
    .btn-green { background: #16a34a; }
    .btn-green:hover { background: #15803d; }
    
    input[type='text'], input[type='email'], input[type='file'] { background: #090a0f; color: #fff; border: 1px solid #3f3f46; padding: 10px 14px; border-radius: 8px; font-size: 13px; font-family: monospace; width: 100%; box-sizing: border-box; }
    .form-group { margin-bottom: 14px; }
    label { display: block; font-size: 12px; color: #a1a1aa; font-weight: 700; margin-bottom: 5px; text-transform: uppercase; }
  </style>
</head>
<body>
<div class="container">
  <h1><span>Studio GoGangs</span> — MySQL Video Storage & Database Streaming Tester</h1>

  <?php if (!empty($msg)): ?>
    <div class="badge badge-<?= $msgType ?>"><?= $msg ?></div>
  <?php endif; ?>

  <div class="card badge-success">
    ✅ MySQL Database Connected: <b><?= htmlspecialchars($db_name) ?></b> (User: <code><?= htmlspecialchars($db_user) ?></code> on Host: <code><?= htmlspecialchars($db_host) ?></code>)
  </div>

  <?php
  // Query counts
  $vCountRes = $conn->query("SELECT COUNT(*) as cnt, IFNULL(SUM(LENGTH(video_data)), 0) as total_bytes FROM portfolio_videos");
  $vCount = $vCountRes->fetch_assoc();
  $totalVideos = $vCount['cnt'];
  $totalVideoMB = round($vCount['total_bytes'] / (1024 * 1024), 2);

  $fCountRes = $conn->query("SELECT COUNT(*) as cnt FROM freelancers");
  $totalFreelancers = $fCountRes->fetch_assoc()['cnt'];
  ?>

  <div class="stats-grid">
    <div class="stat-box">
      <div class="stat-num purple"><?= $totalVideos ?></div>
      <div class="stat-label">Videos in MySQL (portfolio_videos)</div>
    </div>
    <div class="stat-box">
      <div class="stat-num green"><?= $totalVideoMB ?> MB</div>
      <div class="stat-label">Total Video Binary in MySQL</div>
    </div>
    <div class="stat-box">
      <div class="stat-num amber"><?= $totalFreelancers ?></div>
      <div class="stat-label">Freelancer Accounts in MySQL</div>
    </div>
  </div>

  <?php if (isset($latestStreamId)): ?>
    <div class="card" style="border: 2px solid #a855f7;">
      <h2 style="color:#c084fc;">🎬 Live MySQL Video Stream Test (ID: <?= htmlspecialchars($latestStreamId) ?>)</h2>
      <p style="color:#a1a1aa; font-size:13px;">This video is playing <b>directly out of the MySQL database table</b> (<code>portfolio_videos.video_data</code>):</p>
      <video controls autoplay muted playsinline style="max-width: 480px; width: 100%; border-radius: 12px; background: #000; border: 1px solid #3f3f46;">
        <source src="test.php?stream=<?= htmlspecialchars($latestStreamId) ?>" type="video/mp4">
        Your browser does not support HTML5 video streaming.
      </video>
      <p style="margin-top:10px;"><a href="test.php?stream=<?= htmlspecialchars($latestStreamId) ?>" target="_blank" style="color:#38bdf8;">Open Direct Stream Link: <code>test.php?stream=<?= htmlspecialchars($latestStreamId) ?></code></a></p>
    </div>
  <?php endif; ?>

  <!-- 1. Interactive Video Upload Form -->
  <div class="card">
    <h2>1. Upload Video Directly into MySQL Database Table</h2>
    <p style="color:#a1a1aa; font-size:13px;">Select any real video file (.mp4, .mov, .webm) from your computer or generate a test payload to test saving directly into the <code>portfolio_videos</code> database table.</p>

    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="upload_test_video">
      
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
        <div class="form-group">
          <label>Test User Email ID</label>
          <input type="email" name="email" value="dummy_editor@gogangs.com" required>
        </div>
        <div class="form-group">
          <label>Video Project Title</label>
          <input type="text" name="title" value="My Cinematic Showreel" required>
        </div>
      </div>

      <div class="form-group">
        <label>Select Real Video File from Computer (.mp4, .mov, .webm)</label>
        <input type="file" name="video_file" accept="video/*">
      </div>

      <div class="form-group" style="display:flex; align-items:center; gap: 10px;">
        <input type="checkbox" id="gen_dummy" name="generate_dummy" value="1">
        <label for="gen_dummy" style="margin:0; cursor:pointer; text-transform:none; color:#e4e4e7;">Or Check here to generate simulated 1 MB video payload in memory (if no file chosen)</label>
      </div>

      <button type="submit" class="btn btn-green" style="font-size:14px; padding: 12px 24px;">🚀 Upload Video Directly to MySQL Database</button>
    </form>
  </div>

  <!-- 2. Live portfolio_videos Table Contents -->
  <div class="card">
    <h2>2. Videos Stored in <code>portfolio_videos</code> Database Table</h2>
    <?php
    $vRes = $conn->query("SELECT id, video_id, email, filename, file_type, LENGTH(video_data) as b64_len, created_at FROM portfolio_videos ORDER BY id DESC LIMIT 20");
    if ($vRes && $vRes->num_rows > 0):
    ?>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Video ID</th>
          <th>Owner Email</th>
          <th>File Name</th>
          <th>File Type</th>
          <th>Stored Size</th>
          <th>Created At</th>
          <th>Direct Stream Action</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($v = $vRes->fetch_assoc()): ?>
          <?php $sizeKB = round($v['b64_len'] * 0.75 / 1024, 1); $sizeDisplay = ($sizeKB > 1024) ? round($sizeKB / 1024, 2) . " MB" : $sizeKB . " KB"; ?>
          <tr>
            <td><?= $v['id'] ?></td>
            <td><code><?= htmlspecialchars($v['video_id']) ?></code></td>
            <td><b style="color:#fbbf24;"><?= htmlspecialchars($v['email']) ?></b></td>
            <td><?= htmlspecialchars($v['filename']) ?></td>
            <td><?= htmlspecialchars($v['file_type']) ?></td>
            <td><code><?= $sizeDisplay ?></code></td>
            <td><?= htmlspecialchars($v['created_at']) ?></td>
            <td>
              <a href="test.php?stream=<?= urlencode($v['video_id']) ?>" target="_blank" class="btn" style="padding: 4px 10px; font-size: 11px;">▶ Stream from DB</a>
            </td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
    <?php else: ?>
      <div class="badge badge-warning">No videos stored in portfolio_videos table yet. Use the upload form above to add a video!</div>
    <?php endif; ?>
  </div>

  <!-- 3. Live freelancers Table Contents -->
  <div class="card">
    <h2>3. Freelancer Portfolios in <code>freelancers</code> Database Table</h2>
    <?php
    $fRes = $conn->query("SELECT id, member_id, email, username, name, portfolio_data, has_completed_onboarding, updated_at FROM freelancers ORDER BY id DESC LIMIT 20");
    if ($fRes && $fRes->num_rows > 0):
    ?>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Email</th>
          <th>Name / User Code</th>
          <th>Onboarding</th>
          <th>Videos Count</th>
          <th>Portfolio Data Size</th>
          <th>Updated At</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($f = $fRes->fetch_assoc()): ?>
          <?php 
            $p = json_decode($f['portfolio_data'], true) ?: [];
            $vCount = isset($p['videos']) && is_array($p['videos']) ? count($p['videos']) : 0;
            $len = strlen($f['portfolio_data']);
            $lenDisplay = ($len > 1024) ? round($len / 1024, 1) . " KB" : $len . " B";
          ?>
          <tr>
            <td><?= $f['id'] ?></td>
            <td><b style="color:#fbbf24;"><?= htmlspecialchars($f['email']) ?></b></td>
            <td><?= htmlspecialchars($f['name'] ?? '') ?> (<code>#<?= htmlspecialchars($f['member_id'] ?? 'GGVE0001') ?></code>)</td>
            <td><?= ($f['has_completed_onboarding'] == 1) ? '<span style="color:#4ade80;">✅ 1</span>' : '<span style="color:#fbbf24;">⚠️ 0</span>' ?></td>
            <td><b style="color:#c084fc;"><?= $vCount ?> video(s)</b></td>
            <td><code><?= $lenDisplay ?></code></td>
            <td><?= htmlspecialchars($f['updated_at']) ?></td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
    <?php else: ?>
      <div class="badge badge-warning">No freelancer records in database yet.</div>
    <?php endif; ?>
  </div>

</div>
</body>
</html>
<?php $conn->close(); ?>
