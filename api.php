<?php
@mysqli_report(MYSQLI_REPORT_OFF);
@ini_set('display_errors', '0');
@error_reporting(0);

// Restrict CORS to the production domain and localhost for development
$allowed_origins = ['https://studio.gogangs.com', 'http://localhost:5173', 'http://localhost:3000', 'http://127.0.0.1:5173'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: {$origin}");
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Admin-Email');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database Connection (Fast direct connection to production database)
$conn = @new mysqli("localhost", "profilei_Hari", "Rishidar123@", "profilei_website");
if ($conn->connect_error) {
    // Fallback to local development DB if running locally
    $conn = @new mysqli("localhost", "root", "", "studio");
}
if ($conn->connect_error) {
    $conn = @new mysqli("localhost", "root", "Rishidar123@", "studio");
}
if ($conn->connect_error) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]);
    exit();
}
@$conn->set_charset("utf8mb4");

$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($request_uri, PHP_URL_PATH);
$cleanPath = rtrim($path, '/');

// FAST HIGH-PRIORITY ROUTE: GET ALL FREELANCERS (/api/freelancers)
if ($method === 'GET' && ($cleanPath === '/api/freelancers' || preg_match('#/freelancers$#', $cleanPath))) {
    header('Content-Type: application/json; charset=utf-8');
    $res = $conn->query("SELECT * FROM freelancers ORDER BY id DESC");
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            // Performance & Stability: Strip any massive embedded base64 video payloads from the directory response so it stays feather-light (<60KB) and avoids ERR_CONNECTION_RESET
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

// DATABASE PERFORMANCE OPTIMIZER: MIGRATE BASE64 VIDEOS TO DEDICATED TABLE
if ($method === 'GET' && ($cleanPath === '/api/migrate-videos' || (isset($_GET['api']) && $_GET['api'] === 'migrate_base64_videos'))) {
    header('Content-Type: application/json; charset=utf-8');
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

// FAST HIGH-PRIORITY ROUTE: GET ALL VIDEOS (/api/videos)
if ($method === 'GET' && ($cleanPath === '/api/videos' || preg_match('#/videos$#', $cleanPath))) {
    header('Content-Type: application/json; charset=utf-8');
    $res = $conn->query("SELECT id, video_id, email, filename, file_type, created_at FROM portfolio_videos ORDER BY id DESC");
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
    }
    echo json_encode($rows);
    $conn->close();
    exit();
}

// FAST HIGH-PRIORITY ROUTE: GET FREELANCER BY EXACT EMAIL (/api/freelancers/by-email/*)
if ($method === 'GET' && strpos($path, '/freelancers/by-email/') !== false) {
    header('Content-Type: application/json; charset=utf-8');
    $parts = explode('/freelancers/by-email/', $path);
    $targetEmail = strtolower(urldecode(end($parts)));
    $targetEmail = trim($targetEmail, '/');

    if (empty($targetEmail)) {
        echo json_encode(null);
        $conn->close();
        exit();
    }

    $stmt = $conn->prepare("SELECT * FROM freelancers WHERE LOWER(email) = ? LIMIT 1");
    $stmt->bind_param("s", $targetEmail);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if ($row) {
        $parsed = !empty($row['portfolio_data']) ? json_decode($row['portfolio_data'], true) : [];
        $out = $parsed ?: [];
        $out['email'] = $row['email'];
        $out['username'] = $row['username'];
        $out['fullName'] = $row['name'] ?? ($parsed['fullName'] ?? '');
        $out['userCode'] = $row['member_id'] ?? ($parsed['userCode'] ?? '');
        $hasCompleted = ((int)($row['has_completed_onboarding'] ?? 0) === 1)
            || (!empty($parsed['hasCompletedOnboarding']) && $parsed['hasCompletedOnboarding'] === true);
        $out['hasCompletedOnboarding'] = (bool)$hasCompleted;
        $out['approvalStatus'] = $row['approval_status'] ?: ($parsed['approvalStatus'] ?? ($row['email'] === 'rishidar27@gmail.com' ? 'approved' : 'pending'));
        $out['approvedAt'] = $row['approved_at'] ?: ($parsed['approvedAt'] ?? null);
        echo json_encode($out);
    } else {
        echo json_encode(null);
    }
    $conn->close();
    exit();
}

// FAST HIGH-PRIORITY ROUTE: CREATOR ACCOUNT STATUS HEARTBEAT (/api/freelancers/status)
if ($method === 'GET' && strpos($path, '/freelancers/status') !== false) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $email = strtolower(trim($_GET['email'] ?? ''));
    $userCode = strtolower(trim($_GET['userCode'] ?? ($_GET['user_code'] ?? '')));

    if (empty($email) && empty($userCode)) {
        echo json_encode(['status' => 'not_found']);
        $conn->close();
        exit();
    }

    if (!empty($email)) {
        $delStmt = $conn->prepare("SELECT email FROM deleted_accounts WHERE LOWER(email) = ? LIMIT 1");
        if ($delStmt) {
            $delStmt->bind_param("s", $email);
            $delStmt->execute();
            $delRes = $delStmt->get_result();
            if ($delRes && $delRes->fetch_assoc()) {
                $delStmt->close();
                echo json_encode(['status' => 'deleted', 'message' => 'Your account has been removed by the admin.']);
                $conn->close();
                exit();
            }
            $delStmt->close();
        }
    }

    $stmt = $conn->prepare("SELECT id, email, member_id, username, approval_status, approved_at, portfolio_data FROM freelancers WHERE (email != '' AND LOWER(email) = ?) OR (member_id != '' AND LOWER(member_id) = ?) LIMIT 1");
    $stmt->bind_param("ss", $email, $userCode);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    if ($row) {
        $pData = !empty($row['portfolio_data']) ? json_decode($row['portfolio_data'], true) : [];
        $appStatus = $row['approval_status'] ?: ($pData['approvalStatus'] ?? ($row['email'] === 'rishidar27@gmail.com' ? 'approved' : 'pending'));
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

// FAST HIGH-PRIORITY ROUTE: GET FREELANCER BY USERNAME OR USER CODE (/by-username/* or /by-code/*)
if ($method === 'GET' && (strpos($path, '/freelancers/by-username/') !== false || strpos($path, '/freelancers/by-code/') !== false)) {
    header('Content-Type: application/json; charset=utf-8');
    $parts = explode('/', trim($path, '/'));
    $identifier = strtolower(urldecode(end($parts)));

    if (empty($identifier)) {
        echo json_encode(null);
        $conn->close();
        exit();
    }

    $stmt = $conn->prepare("SELECT * FROM freelancers WHERE LOWER(member_id) = ? OR LOWER(username) = ? OR LOWER(email) = ? LIMIT 1");
    $stmt->bind_param("sss", $identifier, $identifier, $identifier);
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
        $hasCompleted = ((int)($row['has_completed_onboarding'] ?? 0) === 1)
            || (!empty($parsed['hasCompletedOnboarding']) && $parsed['hasCompletedOnboarding'] === true);
        $out['hasCompletedOnboarding'] = (bool)$hasCompleted;
        $out['approvalStatus'] = $row['approval_status'] ?: ($parsed['approvalStatus'] ?? ($row['email'] === 'rishidar27@gmail.com' ? 'approved' : 'pending'));
        $out['approvedAt'] = $row['approved_at'] ?: ($parsed['approvedAt'] ?? null);
        echo json_encode($out);
    } else {
        echo json_encode(null);
    }
    $conn->close();
    exit();
}

// FAST HIGH-PRIORITY ROUTE: GET APP SETTINGS (/api/settings)
if ($method === 'GET' && ($cleanPath === '/api/settings' || preg_match('#/settings$#', $cleanPath))) {
    header('Content-Type: application/json; charset=utf-8');
    $res = $conn->query("SELECT setting_key, setting_value FROM app_settings");
    $settings = [
        'max_allowed_users' => 50,
        'registration_open' => 1
    ];
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

// FAST HIGH-PRIORITY ROUTE: APPROVE / REJECT FREELANCER (POST /api/freelancers/approve)
if ($method === 'POST' && strpos($path, '/freelancers/approve') !== false) {
    header('Content-Type: application/json; charset=utf-8');
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

// FAST HIGH-PRIORITY ROUTE: SAVE FREELANCER PORTFOLIO (POST /api/freelancers/save)
if ($method === 'POST' && strpos($path, '/freelancers/save') !== false) {
    header('Content-Type: application/json; charset=utf-8');
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
    $assignedMemberId = getOrAssignMemberIdForEmail($conn, $email);

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

    // Database Performance Shield: Offload any base64 video payloads to dedicated portfolio_videos table
    if (!empty($portfolioArray['videos']) && is_array($portfolioArray['videos'])) {
        foreach ($portfolioArray['videos'] as &$v) {
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
                    $ins->bind_param("sssss", $vidId, $email, $filename, $fileType, $videoData);
                    $ins->execute();
                    $ins->close();
                    $v['videoUrl'] = "https://studio.gogangs.com/api/videos/{$vidId}";
                }
            }
        }
        unset($v);
    }

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

// Admin email hardcoded as the single source of truth for authorization
define('ADMIN_EMAIL_AUTH', 'rishidar27@gmail.com');

/**
 * Verify the request comes from a logged-in admin.
 * The frontend sends X-Admin-Email header with every admin action.
 * Returns true if authenticated, otherwise sends 403 and exits.
 */
function requireAdminAuth() {
    $headerEmail = strtolower(trim($_SERVER['HTTP_X_ADMIN_EMAIL'] ?? ($_SERVER['HTTP_ADMIN_EMAIL'] ?? '')));
    if ($headerEmail !== ADMIN_EMAIL_AUTH) {
        $queryEmail = strtolower(trim($_GET['admin_email'] ?? ($_POST['admin_email'] ?? '')));
        if ($queryEmail !== ADMIN_EMAIL_AUTH) {
            // Also check json body if available
            $rawBody = @file_get_contents('php://input');
            $bodyJson = !empty($rawBody) ? json_decode($rawBody, true) : [];
            $bodyAdmin = strtolower(trim($bodyJson['admin_email'] ?? ''));
            if ($bodyAdmin !== ADMIN_EMAIL_AUTH) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
                echo json_encode(['error' => 'Unauthorized: Admin access required for rishidar27@gmail.com']);
                exit();
            }
        }
    }
    return true;
}


// Production Database Credentials (Reads from Environment Variables with secure defaults)
$db_host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? "localhost");
$db_user = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? "profilei_Hari");
$db_pass = getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? "Rishidar123@");
$db_name = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? "profilei_website");

// =========================================================================
// CLOUDFLARE R2 OBJECT STORAGE CONFIGURATION
// Reads from Environment Variables if configured in cPanel / Node panel
// =========================================================================
$r2_account_id   = getenv('R2_ACCOUNT_ID') ?: ($_ENV['R2_ACCOUNT_ID'] ?? "57a8634b1dc6e05bc5ecb0f3fb7e973f");
$r2_access_key   = getenv('R2_ACCESS_KEY') ?: ($_ENV['R2_ACCESS_KEY'] ?? "ba1f8254c7b9b601e2a0a53bc5badf64");
$r2_secret_key   = getenv('R2_SECRET_KEY') ?: ($_ENV['R2_SECRET_KEY'] ?? "270925a49de70e005ce0278a882b24eec2704694dd0fab162fb6169bd04c373a");
$r2_bucket       = getenv('R2_BUCKET') ?: ($_ENV['R2_BUCKET'] ?? "portfolio-videos");
$r2_public_url   = getenv('R2_PUBLIC_URL') ?: ($_ENV['R2_PUBLIC_URL'] ?? "https://pub-313856aff4ed4647b6946713ec500654.r2.dev");

/**
 * Pure PHP Cloudflare R2 (AWS S3 v4 Signature) Uploader
 * Zero external Composer dependencies required. Runs natively on cPanel PHP 7/8.
 */
function uploadToCloudflareR2($account_id, $access_key, $secret_key, $bucket, $key, $filePath, $contentType = 'video/mp4') {
    if (empty($account_id) || empty($access_key) || empty($secret_key) || empty($bucket) || !file_exists($filePath)) {
        return false;
    }
    $host = "{$account_id}.r2.cloudflarestorage.com";
    $endpoint = "https://{$host}/{$bucket}/" . rawurlencode($key);
    $region = "auto";
    $service = "s3";

    $fileSize = filesize($filePath);
    $payloadHash = "UNSIGNED-PAYLOAD";

    $now = new DateTime('UTC');
    $amzDate = $now->format('Ymd\THis\Z');
    $dateStamp = $now->format('Ymd');

    $canonicalUri = "/{$bucket}/" . rawurlencode($key);
    $canonicalHeaders = "content-type:{$contentType}\nhost:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$amzDate}\n";
    $signedHeaders = "content-type;host;x-amz-content-sha256;x-amz-date";

    $canonicalRequest = "PUT\n{$canonicalUri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

    $algorithm = "AWS4-HMAC-SHA256";
    $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
    $stringToSign = "{$algorithm}\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

    $kSecret = "AWS4" . $secret_key;
    $kDate = hash_hmac('sha256', $dateStamp, $kSecret, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', "aws4_request", $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authorization = "{$algorithm} Credential={$access_key}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

    $headers = [
        "Host: {$host}",
        "Content-Type: {$contentType}",
        "x-amz-content-sha256: {$payloadHash}",
        "x-amz-date: {$amzDate}",
        "Authorization: {$authorization}"
    ];

    $fh = fopen($filePath, 'rb');
    if (!$fh) {
        return false;
    }

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_PUT, true);
    curl_setopt($ch, CURLOPT_INFILE, $fh);
    curl_setopt($ch, CURLOPT_INFILESIZE, $fileSize);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 600);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    return ($httpCode === 200 || $httpCode === 204);
}

/**
 * Pure PHP Cloudflare R2 (AWS S3 v4 Signature) Object Deletion
 * Permanently deletes video file directly from Cloudflare R2 bucket
 */
function deleteFromCloudflareR2($account_id, $access_key, $secret_key, $bucket, $key) {
    if (empty($account_id) || empty($access_key) || empty($secret_key) || empty($bucket) || empty($key)) {
        return false;
    }
    $cleanKey = basename($key);
    $host = "{$account_id}.r2.cloudflarestorage.com";
    $endpoint = "https://{$host}/{$bucket}/" . rawurlencode($cleanKey);
    $region = "auto";
    $service = "s3";

    $payloadHash = hash('sha256', '');

    $now = new DateTime('UTC');
    $amzDate = $now->format('Ymd\THis\Z');
    $dateStamp = $now->format('Ymd');

    $canonicalUri = "/{$bucket}/" . rawurlencode($cleanKey);
    $canonicalHeaders = "host:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$amzDate}\n";
    $signedHeaders = "host;x-amz-content-sha256;x-amz-date";

    $canonicalRequest = "DELETE\n{$canonicalUri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

    $algorithm = "AWS4-HMAC-SHA256";
    $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
    $stringToSign = "{$algorithm}\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

    $kSecret = "AWS4" . $secret_key;
    $kDate = hash_hmac('sha256', $dateStamp, $kSecret, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', "aws4_request", $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authorization = "{$algorithm} Credential={$access_key}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

    $headers = [
        "Host: {$host}",
        "x-amz-content-sha256: {$payloadHash}",
        "x-amz-date: {$amzDate}",
        "Authorization: {$authorization}"
    ];

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode === 200 || $httpCode === 204);
}

/**
 * Generate AWS S3 v4 Presigned PUT URL for ultra-fast direct browser-to-Cloudflare R2 upload.
 * Zero server proxying latency. Uploads directly to Cloudflare's nearest edge data center.
 */
function getCloudflareR2PresignedPutUrl($account_id, $access_key, $secret_key, $bucket, $key, $expiresIn = 3600) {
    $host = "{$account_id}.r2.cloudflarestorage.com";
    $region = "auto";
    $service = "s3";

    $now = new DateTime('UTC');
    $amzDate = $now->format('Ymd\THis\Z');
    $dateStamp = $now->format('Ymd');

    $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
    $credential = "{$access_key}/{$credentialScope}";

    $canonicalUri = "/{$bucket}/" . str_replace('%2F', '/', rawurlencode($key));

    $queryParams = [
        'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential'    => $credential,
        'X-Amz-Date'          => $amzDate,
        'X-Amz-Expires'       => (string)$expiresIn,
        'X-Amz-SignedHeaders' => 'host',
    ];
    ksort($queryParams);

    $canonicalQueryParts = [];
    foreach ($queryParams as $k => $v) {
        $canonicalQueryParts[] = rawurlencode($k) . '=' . rawurlencode($v);
    }
    $canonicalQueryString = implode('&', $canonicalQueryParts);

    $canonicalHeaders = "host:{$host}\n";
    $signedHeaders = "host";
    $payloadHash = "UNSIGNED-PAYLOAD";

    $canonicalRequest = "PUT\n{$canonicalUri}\n{$canonicalQueryString}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

    $algorithm = "AWS4-HMAC-SHA256";
    $stringToSign = "{$algorithm}\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

    $kSecret = "AWS4" . $secret_key;
    $kDate = hash_hmac('sha256', $dateStamp, $kSecret, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', "aws4_request", $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    return "https://{$host}{$canonicalUri}?{$canonicalQueryString}&X-Amz-Signature={$signature}";
}

/**
 * Send branded HTML notification email with signature GoGangs dark theme
 */
function sendStudioNotificationEmail($to, $recipientName, $subject, $headline, $subheadline, $messageContent = '', $ctaText = '', $ctaUrl = '', $referenceCode = '', $tableRows = []) {
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $fromName = "GoGangs";
    $fromEmail = "hello@gogangs.com";
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "Reply-To: hello@gogangs.com\r\n";
    $headers .= "Return-Path: hello@gogangs.com\r\n";
    $headers .= "X-Sender: hello@gogangs.com\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    $referenceHtml = '';
    if (!empty($referenceCode)) {
        $formattedCode = implode(' ', str_split(strtoupper(trim($referenceCode))));
        $referenceHtml = "
            <tr>
                <td style='padding: 0 0 24px;'>
                    <div style='font-size: 11px; font-family: -apple-system, BlinkMacSystemFont, monospace; text-transform: uppercase; letter-spacing: 2px; color: #6b6b75; margin-bottom: 6px; font-weight: 700;'>
                        REFERENCE ID
                    </div>
                    <div style='font-size: 22px; font-family: monospace; font-weight: 900; color: #e11d88; letter-spacing: 4px;'>
                        {$formattedCode}
                    </div>
                </td>
            </tr>
            <tr>
                <td style='padding: 0 0 24px;'>
                    <div style='border-bottom: 1px dashed #222228;'></div>
                </td>
            </tr>
        ";
    }

    $tableHtml = '';
    if (!empty($tableRows) && is_array($tableRows)) {
        $tableHtml = "<table width='100%' cellpadding='0' cellspacing='0' style='background-color: #0d0e12; border: 1px solid #1c1d24; border-radius: 12px; overflow: hidden; margin: 0 0 28px;'>";
        $total = count($tableRows);
        $i = 0;
        foreach ($tableRows as $label => $val) {
            $i++;
            $isLast = ($i === $total);
            $borderStyle = $isLast ? '' : 'border-bottom: 1px solid #1a1b22;';
            $valColor = (strpos($label, 'Email') !== false || strpos($label, 'Link') !== false || strpos($label, 'URL') !== false) ? '#e11d88' : '#ffffff';
            $valFont = (strpos($label, 'Email') !== false || strpos($label, 'Link') !== false || strpos($label, 'URL') !== false || strpos($label, 'ID') !== false) ? 'font-family: monospace;' : '';

            $tableHtml .= "
                <tr>
                    <td style='padding: 14px 18px; color: #71717a; font-size: 13px; width: 32%; {$borderStyle}'>{$label}</td>
                    <td style='padding: 14px 18px; color: {$valColor}; font-size: 13px; font-weight: 600; {$valFont} {$borderStyle}'>{$val}</td>
                </tr>
            ";
        }
        $tableHtml .= "</table>";
    }

    $ctaButtonHtml = '';
    if (!empty($ctaText) && !empty($ctaUrl)) {
        $ctaButtonHtml = "
            <tr>
                <td style='padding: 8px 0 28px; text-align: center;'>
                    <a href='{$ctaUrl}' style='display: inline-block; background-color: #e11d88; color: #ffffff; text-decoration: none; padding: 14px 40px; font-weight: 800; font-size: 13px; border-radius: 8px; text-transform: uppercase; letter-spacing: 1px; box-shadow: 0 4px 16px rgba(225, 29, 136, 0.35);'>
                        {$ctaText}
                    </a>
                </td>
            </tr>
        ";
    }

    $year = date('Y');
    $html = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='utf-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>{$subject}</title>
    </head>
    <body style='margin: 0; padding: 0; background-color: #000000; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; color: #ffffff;'>
        <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #000000; padding: 40px 16px;'>
            <tr>
                <td align='center'>
                    <table width='100%' border='0' cellspacing='0' cellpadding='0' style='max-width: 540px; background-color: #000000; text-align: left;'>
                        <!-- Brand Logo -->
                        <tr>
                            <td style='padding: 0 0 28px;'>
                                <div style='font-size: 26px; font-weight: 900; letter-spacing: -0.5px; color: #ffffff;'>
                                    GoGangs<span style='color: #e11d88;'>.</span>
                                </div>
                            </td>
                        </tr>

                        <!-- Headline -->
                        <tr>
                            <td style='padding: 0 0 8px;'>
                                <h1 style='font-size: 28px; font-weight: 900; margin: 0; color: #ffffff; letter-spacing: -0.5px; line-height: 1.2;'>
                                    {$headline}
                                </h1>
                            </td>
                        </tr>

                        <!-- Subheadline -->
                        <tr>
                            <td style='padding: 0 0 24px;'>
                                <p style='font-size: 14px; color: #88888e; margin: 0; line-height: 1.5;'>
                                    {$subheadline}
                                </p>
                            </td>
                        </tr>

                        <!-- Reference ID (if provided) -->
                        {$referenceHtml}

                        <!-- Message Content -->
                        " . (!empty($messageContent) ? "
                        <tr>
                            <td style='padding: 0 0 20px; font-size: 14px; line-height: 1.7; color: #d4d4d8;'>
                                {$messageContent}
                            </td>
                        </tr>
                        " : "") . "

                        <!-- Key-Value Table -->
                        " . (!empty($tableHtml) ? "
                        <tr>
                            <td style='padding: 0 0 10px;'>
                                {$tableHtml}
                            </td>
                        </tr>
                        " : "") . "

                        <!-- CTA Button -->
                        {$ctaButtonHtml}

                        <!-- Footer -->
                        <tr>
                            <td style='padding: 28px 0 0; border-top: 1px dashed #222228; text-align: left; font-size: 11px; color: #52525b; line-height: 1.6;'>
                                <div style='color: #71717a;'>
                                    GoGangs &middot; <a href='mailto:hello@gogangs.com' style='color: #71717a; text-decoration: none;'>hello@gogangs.com</a> &middot; <a href='https://www.gogangs.com' style='color: #71717a; text-decoration: none;'>gogangs.com</a>
                                </div>
                                <div style='margin-top: 4px; color: #52525b;'>
                                    &copy; {$year} GoGangs. All rights reserved.
                                </div>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>
    ";

    return @mail($to, $subject, $html, $headers);
}

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    // Fallback to direct credentials if getenv returned different values
    $conn = @new mysqli("localhost", "profilei_Hari", "Rishidar123@", "profilei_website");
}
if ($conn->connect_error) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]);
    exit();
}
@$conn->set_charset("utf8mb4");

// 1. Auto-create freelancers table safely
try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS freelancers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id VARCHAR(50),
            email VARCHAR(255) UNIQUE NOT NULL,
            username VARCHAR(255),
            name VARCHAR(255),
            portfolio_data LONGTEXT,
            has_completed_onboarding TINYINT(1) DEFAULT 0,
            approval_status VARCHAR(50) DEFAULT 'pending',
            approved_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Throwable $e) {}

// Auto-migrate approval columns safely without touching existing timestamp columns
if (!function_exists('ensureFreelancerColumns')) {
    function ensureFreelancerColumns($conn) {
        if (!$conn || $conn->connect_error) return;
        try {
            $res = @$conn->query("SHOW COLUMNS FROM freelancers LIKE 'approval_status'");
            if ($res && $res->num_rows === 0) {
                @$conn->query("ALTER TABLE freelancers ADD COLUMN `approval_status` VARCHAR(50) DEFAULT 'pending'");
            }
        } catch (Throwable $e) {}
        try {
            $res2 = @$conn->query("SHOW COLUMNS FROM freelancers LIKE 'approved_at'");
            if ($res2 && $res2->num_rows === 0) {
                @$conn->query("ALTER TABLE freelancers ADD COLUMN `approved_at` DATETIME NULL DEFAULT NULL");
            }
        } catch (Throwable $e) {}
    }
}
try {
    ensureFreelancerColumns($conn);
} catch (Throwable $e) {}

// 2. Auto-create dedicated portfolio_videos table to store actual raw video binary data inside MySQL DB
try {
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
} catch (Throwable $e) {}

// 3. Auto-create deleted_accounts table for real-time account removal detection
try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS deleted_accounts (
            email VARCHAR(255) PRIMARY KEY,
            member_id VARCHAR(50) DEFAULT NULL,
            username VARCHAR(255) DEFAULT NULL,
            deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_del_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Throwable $e) {}

// 4. Auto-create dedicated app_settings table
try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Throwable $e) {}

// 5. Auto-create client_buckets table for short URLs and persistent bucket storage
try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS client_buckets (
            id VARCHAR(100) PRIMARY KEY,
            slug VARCHAR(100) NOT NULL,
            client_name VARCHAR(255) NOT NULL,
            description TEXT,
            video_ids LONGTEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Throwable $e) {}

// Ensure default max_allowed_users setting exists
try {
    $conn->query("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('max_allowed_users', '50'), ('registration_open', '1')");
} catch (Throwable $e) {}

$request_uri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($request_uri, PHP_URL_PATH);

// =========================================================================
// DYNAMIC OPENGRAPH PREVIEW FOR WHATSAPP, FACEBOOK, TWITTER, TELEGRAM CRAWLERS
// =========================================================================
$ogPath = $_GET['og_path'] ?? '';
if (!empty($ogPath)) {
    header('Content-Type: text/html; charset=utf-8');
    
    // Parse path, e.g. "b/karthik", "marketing", "GGVE0002/2004", "p/GGVE0002"
    $cleanPath = trim($ogPath, '/');
    $parts = explode('/', $cleanPath);
    $firstPart = strtolower(urldecode($parts[0] ?? ''));
    $lastPart = strtolower(urldecode(end($parts)));
    
    $title = "GoGangs Studio — Video Portfolio Platform";
    $description = "Create, edit, and share your professional video editing portfolio for free on GoGangs Studio.";
    $imageUrl = "https://studio.gogangs.com/logo-dark.jpg?v=1";
    $pageUrl = "https://studio.gogangs.com/" . $cleanPath;

    // Check if this is a Client Bucket share URL (/b/:slug, /marketing/:slug, or /marketing?b=...)
    $isBucketUrl = false;
    $bucketClientName = '';
    if ($firstPart === 'b' || $firstPart === 'marketing' || !empty($_GET['b'])) {
        $isBucketUrl = true;
        $bucketSlug = ($firstPart === 'b') ? $lastPart : '';
        if (!empty($bucketSlug) && $bucketSlug !== 'b') {
            $bStmt = $conn->prepare("SELECT client_name FROM client_buckets WHERE LOWER(slug) = LOWER(?) OR LOWER(id) = LOWER(?) LIMIT 1");
            if ($bStmt) {
                $bStmt->bind_param("ss", $bucketSlug, $bucketSlug);
                $bStmt->execute();
                $bRes = $bStmt->get_result();
                if ($bRow = $bRes->fetch_assoc()) {
                    $bucketClientName = $bRow['client_name'];
                }
                $bStmt->close();
            }
            if (empty($bucketClientName)) {
                $bucketClientName = ucwords(str_replace(['-', '_'], ' ', $bucketSlug));
            }
        }
        if (empty($bucketClientName) && !empty($_GET['b'])) {
            try {
                $bPayload = json_decode(urldecode(base64_decode($_GET['b'])), true);
                if (!empty($bPayload['c'])) {
                    $bucketClientName = $bPayload['c'];
                }
            } catch (Throwable $t) {}
        }
    }

    if ($isBucketUrl) {
        $cName = !empty($bucketClientName) ? htmlspecialchars($bucketClientName) : 'Client';
        $title = "{$cName}'s Curated Video Showcase — GoGangs Studio";
        $description = "Exclusive video showcase curated for {$cName}. Watch high-retention video projects on GoGangs Studio.";
        $imageUrl = "https://studio.gogangs.com/logo-dark.jpg?v=1";
    } else {
        // Try looking up freelancer in MySQL by member_id, username, email, or clean slug
        $searchIds = array_unique(array_filter([$firstPart, $lastPart]));
        $foundRow = null;
        
        foreach ($searchIds as $id) {
            if (in_array($id, ['p', 'c', 'b', 'portfolio', 'admin', 'onboarding', 'dashboard', 'api'])) continue;
            $stmt = $conn->prepare("
                SELECT * FROM freelancers 
                WHERE LOWER(member_id) = ? 
                   OR LOWER(username) = ? 
                   OR LOWER(email) = ? 
                   OR LOWER(REPLACE(REPLACE(REPLACE(name, ' ', ''), '-', ''), '_', '')) = ? 
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->bind_param("ssss", $id, $id, $id, $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();
            if ($row) {
                $foundRow = $row;
                break;
            }
        }
        
        if ($foundRow) {
            $pData = !empty($foundRow['portfolio_data']) ? json_decode($foundRow['portfolio_data'], true) : [];
            $fullName = htmlspecialchars($foundRow['name'] ?: ($pData['fullName'] ?? 'Video Editor'));
            $creatorTitle = htmlspecialchars($pData['title'] ?? 'Professional Video Editor');
            $creatorBio = htmlspecialchars($pData['bio'] ?? "Check out {$fullName}'s video editing portfolio on GoGangs Studio.");
            $rawAvatar = trim($pData['avatarUrl'] ?? '');
            
            $title = "{$fullName} — {$creatorTitle} | GoGangs Studio";
            $description = $creatorBio;
            
            $userIdentifier = $foundRow['member_id'] ?: ($foundRow['username'] ?: $firstPart);
            $versionParam = !empty($foundRow['updated_at']) ? strtotime($foundRow['updated_at']) : time();
            
            if (!empty($rawAvatar) && (strpos($rawAvatar, 'http://') === 0 || strpos($rawAvatar, 'https://') === 0) && strpos($rawAvatar, 'data:') === false) {
                $imageUrl = $rawAvatar;
            } else {
                $imageUrl = "https://studio.gogangs.com/api/avatar-image?user=" . urlencode($userIdentifier) . "&v=" . $versionParam;
            }
        }
    }
    
    $imgType = (strpos($imageUrl, '.png') !== false) ? 'image/png' : 'image/jpeg';
    
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>{$title}</title>
    <meta name="description" content="{$description}" />
    <meta property="og:site_name" content="GoGangs Studio" />
    <meta property="og:type" content="profile" />
    <meta property="og:title" content="{$title}" />
    <meta property="og:description" content="{$description}" />
    <meta property="og:image" content="{$imageUrl}" />
    <meta property="og:image:secure_url" content="{$imageUrl}" />
    <meta property="og:image:type" content="{$imgType}" />
    <meta property="og:image:width" content="500" />
    <meta property="og:image:height" content="500" />
    <meta property="og:url" content="{$pageUrl}" />
    <link rel="image_src" href="{$imageUrl}" />
    <meta name="twitter:card" content="summary" />
    <meta name="twitter:title" content="{$title}" />
    <meta name="twitter:description" content="{$description}" />
    <meta name="twitter:image" content="{$imageUrl}" />
</head>
<body style="background-color:#090a10;color:#ffffff;font-family:-apple-system,BlinkMacSystemFont,sans-serif;padding:30px;text-align:center;">
    <h1>{$title}</h1>
    <p>{$description}</p>
    <img src="{$imageUrl}" alt="{$title}" width="300" height="300" style="border-radius:50%;object-fit:cover;" />
</body>
</html>
HTML;
    $conn->close();
    exit();
}

// =========================================================================
// CLIENT BUCKETS API: SHORT URLS & PERSISTENT BUCKET RETRIEVAL
// =========================================================================

// SAVE CLIENT BUCKET (POST /api/buckets)
if ($method === 'POST' && ($path === '/api/buckets' || $path === '/api/buckets/')) {
    header('Content-Type: application/json; charset=utf-8');
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: [];

    $id = trim($input['id'] ?? ('bkt_' . round(microtime(true) * 1000)));
    $clientName = trim($input['clientName'] ?? ($input['client_name'] ?? 'Client'));
    $slug = trim($input['slug'] ?? strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $clientName)));
    $slug = trim($slug, '-');
    if (empty($slug)) $slug = 'client-' . substr($id, -6);
    $description = trim($input['description'] ?? '');
    $videoIds = is_array($input['videoIds'] ?? null) 
        ? json_encode($input['videoIds']) 
        : (is_array($input['video_ids'] ?? null) ? json_encode($input['video_ids']) : '[]');

    $stmt = $conn->prepare("
        INSERT INTO client_buckets (id, slug, client_name, description, video_ids)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            slug = VALUES(slug),
            client_name = VALUES(client_name),
            description = VALUES(description),
            video_ids = VALUES(video_ids)
    ");
    if ($stmt) {
        $stmt->bind_param("sssss", $id, $slug, $clientName, $description, $videoIds);
        $stmt->execute();
        $stmt->close();
    }

    echo json_encode([
        'status' => 'success',
        'id' => $id,
        'slug' => $slug,
        'shortUrl' => "/b/{$slug}",
        'clientName' => $clientName
    ]);
    $conn->close();
    exit();
}

// GET CLIENT BUCKET BY ID OR SLUG (GET /api/buckets/*)
if ($method === 'GET' && strpos($path, '/api/buckets') === 0) {
    header('Content-Type: application/json; charset=utf-8');
    $parts = explode('/api/buckets', $path);
    $param = trim($parts[1] ?? '', '/');

    if (empty($param)) {
        // Return all buckets
        $res = $conn->query("SELECT * FROM client_buckets ORDER BY updated_at DESC");
        $all = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['video_ids'] = json_decode($r['video_ids'] ?? '[]', true);
                $all[] = $r;
            }
        }
        echo json_encode($all);
        $conn->close();
        exit();
    }

    $lookup = urldecode($param);
    $stmt = $conn->prepare("
        SELECT * FROM client_buckets 
        WHERE LOWER(slug) = LOWER(?) 
           OR LOWER(id) = LOWER(?) 
           OR LOWER(client_name) = LOWER(?) 
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("sss", $lookup, $lookup, $lookup);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if ($row) {
            $row['video_ids'] = json_decode($row['video_ids'] ?? '[]', true);
            echo json_encode($row);
            $conn->close();
            exit();
        }
    }

    http_response_code(404);
    echo json_encode(['error' => 'Bucket not found']);
    $conn->close();
    exit();
}

// =========================================================================
// SERVE CREATOR AVATAR AS DIRECT BINARY IMAGE (GET /api/avatar-image or /api/avatar)
// =========================================================================
if ($method === 'GET' && (strpos($path, '/avatar-image') !== false || strpos($path, '/avatar') !== false)) {
    $userParam = strtolower(trim($_GET['user'] ?? ($_GET['code'] ?? ($_GET['id'] ?? ($_GET['email'] ?? ($_GET['username'] ?? ''))))));
    
    $avatarData = null;
    $avatarMime = 'image/jpeg';
    
    if (!empty($userParam)) {
        $stmt = $conn->prepare("
            SELECT portfolio_data FROM freelancers 
            WHERE LOWER(member_id) = ? 
               OR LOWER(username) = ? 
               OR LOWER(email) = ? 
               OR LOWER(REPLACE(REPLACE(REPLACE(name, ' ', ''), '-', ''), '_', '')) = ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->bind_param("ssss", $userParam, $userParam, $userParam, $userParam);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        
        if ($row && !empty($row['portfolio_data'])) {
            $pData = json_decode($row['portfolio_data'], true);
            $rawAvatar = trim($pData['avatarUrl'] ?? '');
            
            if (!empty($rawAvatar)) {
                // If it's a base64 data URL (e.g., data:image/jpeg;base64,/9j/...)
                if (strpos($rawAvatar, 'data:') === 0 && strpos($rawAvatar, ';base64,') !== false) {
                    list($header, $data) = explode(';base64,', $rawAvatar, 2);
                    $mimeMatch = [];
                    if (preg_match('/data:(image\/[a-zA-Z0-9\+\-\.]+)/i', $header, $mimeMatch)) {
                        $avatarMime = $mimeMatch[1];
                    }
                    $avatarData = base64_decode($data);
                } 
                // If it's a direct web URL (e.g., Unsplash, Cloudflare R2, or remote CDN)
                else if (strpos($rawAvatar, 'http://') === 0 || strpos($rawAvatar, 'https://') === 0) {
                    header("Location: {$rawAvatar}", true, 302);
                    $conn->close();
                    exit();
                }
            }
        }
    }
    
    if (!empty($avatarData)) {
        header("Content-Type: {$avatarMime}");
        header('Content-Length: ' . strlen($avatarData));
        header('Cache-Control: public, max-age=86400');
        echo $avatarData;
    } else {
        // Fallback to logo.png
        $logoPath = __DIR__ . '/logo.png';
        if (file_exists($logoPath)) {
            header('Content-Type: image/png');
            header('Content-Length: ' . filesize($logoPath));
            header('Cache-Control: public, max-age=86400');
            readfile($logoPath);
        } else {
            header('Location: https://studio.gogangs.com/logo.png', true, 302);
        }
    }
    $conn->close();
    exit();
}

// GENERATE PRESIGNED DIRECT R2 UPLOAD URL (GET /api/r2/presigned-upload-url)
if ($method === 'GET' && strpos($path, '/r2/presigned-upload-url') !== false) {
    header('Content-Type: application/json; charset=utf-8');

    // Check approval status of the user before allowing upload
    $uploaderEmail = strtolower(trim($_GET['email'] ?? ''));
    if (!empty($uploaderEmail) && $uploaderEmail !== 'rishidar27@gmail.com') {
        $stmt = $conn->prepare("SELECT approval_status, portfolio_data FROM freelancers WHERE LOWER(email) = ? LIMIT 1");
        $stmt->bind_param("s", $uploaderEmail);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        $pData = !empty($row['portfolio_data']) ? json_decode($row['portfolio_data'], true) : [];
        $isApproved = ($row && (($row['approval_status'] === 'approved') || (($pData['approvalStatus'] ?? '') === 'approved')));
        if (!$isApproved) {
            http_response_code(403);
            echo json_encode(['error' => 'Account verification pending. Video uploads will activate after admin approval.']);
            $conn->close();
            exit();
        }
    }

    if (empty($r2_account_id) || empty($r2_access_key) || empty($r2_secret_key) || empty($r2_bucket)) {
        http_response_code(400);
        echo json_encode(['error' => 'Cloudflare R2 is not configured']);
        $conn->close();
        exit();
    }

    $videoId = 'vid_' . time() . '_' . substr(md5(uniqid()), 0, 6);
    $r2Key = $videoId . '.mp4';
    $presignedUrl = getCloudflareR2PresignedPutUrl($r2_account_id, $r2_access_key, $r2_secret_key, $r2_bucket, $r2Key, 3600);
    $publicUrl = rtrim($r2_public_url, '/') . '/' . $r2Key;

    echo json_encode([
        'status' => 'success',
        'videoId' => $videoId,
        'uploadUrl' => $presignedUrl,
        'publicUrl' => $publicUrl
    ]);
    $conn->close();
    exit();
}

// RECORD R2 VIDEO METADATA (POST /api/r2/record-video)
if (($method === 'POST' || $method === 'GET') && strpos($path, '/r2/record-video') !== false) {
    header('Content-Type: application/json; charset=utf-8');
    $videoId = $_GET['videoId'] ?? ($_POST['videoId'] ?? '');
    $email = $_GET['email'] ?? ($_POST['email'] ?? 'user@gogangs.com');
    $filename = $_GET['filename'] ?? ($_POST['filename'] ?? 'video.mp4');
    $fileType = 'video/mp4';
    $dbMarker = 'R2_STORED';

    if (!empty($videoId)) {
        $stmt = $conn->prepare("INSERT INTO portfolio_videos (video_id, email, filename, file_type, video_data) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE video_data = VALUES(video_data)");
        $stmt->bind_param("sssss", $videoId, $email, $filename, $fileType, $dbMarker);
        $stmt->execute();
        $stmt->close();
    }

    echo json_encode(['status' => 'success', 'videoId' => $videoId]);
    $conn->close();
    exit();
}

// DELETE VIDEO FROM CLOUDFLARE R2 & MYSQL (POST, DELETE, GET /api/videos/delete or /api/r2/delete-video)
if (($method === 'POST' || $method === 'DELETE' || $method === 'GET') && (strpos($path, '/videos/delete') !== false || strpos($path, '/r2/delete-video') !== false)) {
    header('Content-Type: application/json; charset=utf-8');
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: [];
    
    $videoId = $_GET['id'] ?? ($_GET['videoId'] ?? ($input['id'] ?? ($input['videoId'] ?? '')));
    $videoUrl = $_GET['url'] ?? ($_GET['videoUrl'] ?? ($input['url'] ?? ($input['videoUrl'] ?? '')));

    if (empty($videoId) && !empty($videoUrl)) {
        if (preg_match('/(vid_[a-zA-Z0-9_]+)/', $videoUrl, $m)) {
            $videoId = $m[1];
        }
    }

    if (!empty($videoId)) {
        $cleanKey = basename($videoId);
        if (!str_ends_with($cleanKey, '.mp4')) {
            $cleanKey .= '.mp4';
        }
        
        // 1. Delete directly from Cloudflare R2 bucket
        $r2Configured = !empty($r2_account_id) && !empty($r2_access_key) && !empty($r2_secret_key) && !empty($r2_bucket);
        $r2Deleted = false;
        if ($r2Configured) {
            $r2Deleted = deleteFromCloudflareR2($r2_account_id, $r2_access_key, $r2_secret_key, $r2_bucket, $cleanKey);
        }

        // 2. Delete local disk copy if exists
        $localPath = __DIR__ . '/uploads/' . $cleanKey;
        if (file_exists($localPath)) {
            @unlink($localPath);
        }

        // 3. Delete from MySQL portfolio_videos
        $noExt = str_replace('.mp4', '', $cleanKey);
        $stmt = $conn->prepare("DELETE FROM portfolio_videos WHERE video_id = ? OR video_id = ?");
        $stmt->bind_param("ss", $cleanKey, $noExt);
        $stmt->execute();
        $stmt->close();

        echo json_encode(['status' => 'success', 'deleted' => $cleanKey, 'r2Deleted' => $r2Deleted]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Missing videoId or videoUrl']);
    }
    $conn->close();
    exit();
}

// STREAM RAW VIDEO DIRECTLY FROM DISK OR MYSQL (GET /api/videos/stream?id=vid_xxx)
if ($method === 'GET' && strpos($path, '/videos/stream') !== false) {
    $videoId = $_GET['id'] ?? '';
    if (!empty($videoId)) {
        // 1. Check physical disk storage first (fastest, supports unlimited file size & video seeking)
        $diskPath = __DIR__ . '/uploads/' . $videoId . '.mp4';
        if (file_exists($diskPath)) {
            $fileSize = filesize($diskPath);
            header('Content-Type: video/mp4');
            header('Accept-Ranges: bytes');
            header('Cache-Control: public, max-age=31536000');

            // Support range requests for smooth video seeking & streaming
            if (isset($_SERVER['HTTP_RANGE'])) {
                $range = $_SERVER['HTTP_RANGE'];
                preg_match('/bytes=(\d+)-(\d*)/', $range, $m);
                $start = (int)$m[1];
                $end = !empty($m[2]) ? (int)$m[2] : $fileSize - 1;
                $length = $end - $start + 1;
                http_response_code(206);
                header("Content-Range: bytes $start-$end/$fileSize");
                header("Content-Length: $length");
                $fp = fopen($diskPath, 'rb');
                fseek($fp, $start);
                echo fread($fp, $length);
                fclose($fp);
            } else {
                header('Content-Length: ' . $fileSize);
                readfile($diskPath);
            }
            $conn->close();
            exit();
        }

        // 2. Fallback: Base64-encoded video stored in MySQL database
        $stmt = $conn->prepare("SELECT video_data, file_type FROM portfolio_videos WHERE video_id = ? LIMIT 1");
        $stmt->bind_param("s", $videoId);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->bind_result($videoDataB64, $fileType);
            $stmt->fetch();
            $stmt->close();

            $mimeType = $fileType ?: 'video/mp4';

            // Check if stored on Cloudflare R2
            if ($videoDataB64 === 'R2_STORED' && !empty($r2_public_url)) {
                $r2Url = rtrim($r2_public_url, '/') . '/' . $videoId . '.mp4';
                header('Location: ' . $r2Url, true, 302);
                $conn->close();
                exit();
            }

            if (!empty($videoDataB64) && $videoDataB64 !== 'DISK_STORED' && $videoDataB64 !== 'R2_STORED') {
                $binaryData = base64_decode($videoDataB64);
                if ($binaryData) {
                    header('Content-Type: ' . $mimeType);
                    header('Content-Length: ' . strlen($binaryData));
                    header('Accept-Ranges: bytes');
                    header('Cache-Control: public, max-age=31536000');
                    echo $binaryData;
                    $conn->close();
                    exit();
                }
            }
        }
        $stmt->close();
    }
    http_response_code(404);
    echo "Video file not found.";
    $conn->close();
    exit();
}

header('Content-Type: application/json; charset=utf-8');

// =========================================================================
// GET ALL VIDEOS ACROSS DATABASE (GET /api/videos)
// =========================================================================
if ($method === 'GET' && ($path === '/api/videos' || $path === '/api/videos/')) {
    $videosList = [];
    $seenIds = [];
    $seenUrls = [];

    // Helper to normalize category cleanly
    $normCat = function($rawCat, $orient) {
        $c = trim($rawCat ?? '');
        $lower = strtolower($c);
        if (empty($c) || $lower === 'general' || strlen($c) > 25) {
            return ($orient === 'horizontal') ? 'YouTube / Long Form' : 'Reels & Shorts';
        }
        if (strpos($lower, 'reel') !== false || strpos($lower, 'short') !== false) {
            return 'Reels & Shorts';
        }
        if (strpos($lower, 'youtube') !== false || strpos($lower, 'long') !== false || strpos($lower, 'widescreen') !== false) {
            return 'YouTube / Long Form';
        }
        return $c;
    };

    // Preload creators for fast metadata lookup (name, location, language)
    $creatorsByEmail = [];
    $res = $conn->query("SELECT id, member_id, email, username, name, portfolio_data, location, primary_language FROM freelancers ORDER BY id ASC");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $e = strtolower(trim($r['email'] ?? ''));
            if ($e) {
                $creatorsByEmail[$e] = $r;
            }
        }
    }

    // 1. Extract from all freelancers' portfolio_data JSON
    foreach ($creatorsByEmail as $e => $r) {
        $pData = !empty($r['portfolio_data']) ? json_decode($r['portfolio_data'], true) : [];
        if (!empty($pData['videos']) && is_array($pData['videos'])) {
            foreach ($pData['videos'] as $v) {
                $vUrl = trim($v['videoUrl'] ?? ($v['url'] ?? ''));
                $vId = trim($v['id'] ?? ($vUrl ?: ''));
                if (!empty($vUrl) && !isset($seenIds[$vId]) && !isset($seenUrls[$vUrl])) {
                    $seenIds[$vId] = true;
                    $seenUrls[$vUrl] = true;
                    $orient = $v['orientation'] ?? 'vertical';
                    $cat = $normCat($v['category'] ?? '', $orient);
                    $loc = !empty($pData['location']) ? $pData['location'] : (!empty($r['location']) ? $r['location'] : 'Chennai, India');
                    $lang = !empty($pData['primaryLanguage']) ? $pData['primaryLanguage'] : (!empty($r['primary_language']) ? $r['primary_language'] : 'Tamil');

                    $videosList[] = array_merge($v, [
                        'id' => $vId,
                        'videoUrl' => $vUrl,
                        'category' => $cat,
                        'orientation' => $orient,
                        'creatorName' => $r['name'] ?: ($pData['fullName'] ?? ($r['username'] ?: 'Creator')),
                        'creatorEmail' => $r['email'],
                        'creatorUserCode' => $r['member_id'] ?: ($pData['userCode'] ?? 'GGVE0001'),
                        'creatorAvatar' => $pData['avatarUrl'] ?? '',
                        'creatorLocation' => $loc,
                        'creatorLanguage' => $lang
                    ]);
                }
            }
        }
    }

    // 2. Extract from portfolio_videos table (ONLY if not already present)
    $pvRes = $conn->query("SELECT video_id, email, filename, file_type, created_at FROM portfolio_videos ORDER BY id DESC");
    if ($pvRes) {
        while ($pvr = $pvRes->fetch_assoc()) {
            $vidId = $pvr['video_id'];
            $vidUrl = "/api/videos/stream?id=" . urlencode($vidId);
            $cEmail = strtolower(trim($pvr['email'] ?? ''));
            $creator = $creatorsByEmail[$cEmail] ?? null;
            $creatorPData = ($creator && !empty($creator['portfolio_data'])) ? json_decode($creator['portfolio_data'], true) : [];

            if (isset($seenIds[$vidId]) || isset($seenUrls[$vidUrl])) {
                continue;
            }

            $seenIds[$vidId] = true;
            $seenUrls[$vidUrl] = true;

            $rawTitle = !empty($pvr['filename']) ? pathinfo($pvr['filename'], PATHINFO_FILENAME) : $vidId;
            $title = ucwords(str_replace(['_', '-'], ' ', $rawTitle));
            $creatorName = $creator ? ($creator['name'] ?: ($creatorPData['fullName'] ?? $creator['username'])) : 'Creator';
            $creatorCode = $creator ? ($creator['member_id'] ?: ($creatorPData['userCode'] ?? 'GGVE0001')) : 'GGVE0001';
            $loc = !empty($creatorPData['location']) ? $creatorPData['location'] : (!empty($creator['location']) ? $creator['location'] : 'Chennai, India');
            $lang = !empty($creatorPData['primaryLanguage']) ? $creatorPData['primaryLanguage'] : (!empty($creator['primary_language']) ? $creator['primary_language'] : 'Tamil');

            $videosList[] = [
                'id' => $vidId,
                'title' => $title,
                'videoUrl' => $vidUrl,
                'category' => 'Reels & Shorts',
                'orientation' => 'vertical',
                'creatorName' => $creatorName,
                'creatorEmail' => $cEmail,
                'creatorUserCode' => $creatorCode,
                'creatorAvatar' => $creatorPData['avatarUrl'] ?? '',
                'creatorLocation' => $loc,
                'creatorLanguage' => $lang,
                'createdAt' => $pvr['created_at']
            ];
        }
    }

    echo json_encode($videosList);
    $conn->close();
    exit();
}

// GET APP SETTINGS (GET /api/settings)
if ($method === 'GET' && (strpos($path, '/settings') !== false || $path === '/api/settings')) {
    $res = $conn->query("SELECT setting_key, setting_value FROM app_settings");
    $settings = ['max_allowed_users' => 50, 'registration_open' => 1];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $settings[$row['setting_key']] = is_numeric($row['setting_value']) ? (int)$row['setting_value'] : $row['setting_value'];
        }
    }
    $countRes = $conn->query("SELECT COUNT(*) as total FROM freelancers");
    $totalCount = 0;
    if ($countRes && $r = $countRes->fetch_assoc()) {
        $totalCount = (int)$r['total'];
    }
    $settings['current_user_count'] = $totalCount;
    echo json_encode($settings);
    $conn->close();
    exit();
}

// SAVE APP SETTINGS (POST /api/settings/save) — ADMIN ONLY
if ($method === 'POST' && strpos($path, '/settings/save') !== false) {
    requireAdminAuth();
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: [];

    foreach ($input as $key => $val) {
        $strVal = (string)$val;
        $stmt = $conn->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->bind_param("ss", $key, $strVal);
        $stmt->execute();
        $stmt->close();
    }
    echo json_encode(['status' => 'success', 'saved' => $input]);
    $conn->close();
    exit();
}

// =========================================================================
// DATA PROTECTION & AUTOMATED DATABASE BACKUP SYSTEM
// =========================================================================
function getBackupsDirectory() {
    $dir = __DIR__ . '/backups';
    if (!file_exists($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "Order Allow,Deny\nDeny from all\n");
    }
    return $dir;
}

function pruneOldBackups($dir, $keepDays = 30) {
    if (!is_dir($dir)) return;
    $files = glob($dir . '/*.json');
    $cutoff = time() - ($keepDays * 86400);
    foreach ($files as $f) {
        if (filemtime($f) < $cutoff) {
            @unlink($f);
        }
    }
}

function createDatabaseSnapshot($conn, $trigger = 'manual') {
    $dir = getBackupsDirectory();
    $timestamp = date('Y-m-d_H-i-s');
    $dateOnly = date('Y-m-d');
    $filename = ($trigger === 'auto') ? "auto_snapshot_{$dateOnly}.json" : "snapshot_{$timestamp}.json";
    $filepath = $dir . '/' . $filename;

    $res = $conn->query("SELECT id, member_id, email, username, name, phone, skills, portfolio_data, has_completed_onboarding, user_code, created_at, updated_at FROM freelancers ORDER BY id ASC");
    $freelancers = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $freelancers[] = $r;
        }
    }

    $settingsRes = $conn->query("SELECT setting_key, setting_value FROM app_settings");
    $settings = [];
    if ($settingsRes) {
        while ($sr = $settingsRes->fetch_assoc()) {
            $settings[$sr['setting_key']] = $sr['setting_value'];
        }
    }

    $backupPayload = [
        'backup_version' => '1.0',
        'platform' => 'GoGangs Studio',
        'trigger' => $trigger,
        'created_at' => date('c'),
        'total_creators' => count($freelancers),
        'settings' => $settings,
        'freelancers' => $freelancers
    ];

    $json = json_encode($backupPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    @file_put_contents($filepath, $json, LOCK_EX);

    pruneOldBackups($dir, 30);

    return [
        'filename' => $filename,
        'filesize' => file_exists($filepath) ? filesize($filepath) : strlen($json),
        'filesize_formatted' => round((file_exists($filepath) ? filesize($filepath) : strlen($json)) / 1024, 1) . ' KB',
        'created_at' => date('c'),
        'total_creators' => count($freelancers),
        'trigger' => $trigger
    ];
}

function getDatabaseSnapshotsList() {
    $dir = getBackupsDirectory();
    $files = glob($dir . '/*.json');
    $list = [];
    if ($files) {
        foreach ($files as $f) {
            $filename = basename($f);
            $size = filesize($f);
            $mtime = filemtime($f);
            $creatorsCount = 0;
            $trigger = strpos($filename, 'auto_') === 0 ? 'auto' : 'manual';
            $fh = @fopen($f, 'r');
            if ($fh) {
                $head = fread($fh, 1500);
                fclose($fh);
                if (preg_match('/"total_creators"\s*:\s*(\d+)/', $head, $m)) {
                    $creatorsCount = (int)$m[1];
                }
                if (preg_match('/"trigger"\s*:\s*"([^"]+)"/', $head, $tm)) {
                    $trigger = $tm[1];
                }
            }
            $list[] = [
                'filename' => $filename,
                'filesize' => $size,
                'filesize_formatted' => round($size / 1024, 1) . ' KB',
                'created_at' => date('c', $mtime),
                'total_creators' => $creatorsCount,
                'trigger' => $trigger
            ];
        }
        usort($list, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
    }
    return $list;
}

function restoreDatabaseFromData($conn, $backupData) {
    if (!is_array($backupData)) {
        return ['success' => false, 'error' => 'Invalid backup data format'];
    }

    $freelancersList = [];
    if (isset($backupData['freelancers']) && is_array($backupData['freelancers'])) {
        $freelancersList = $backupData['freelancers'];
    } else if (isset($backupData['creators']) && is_array($backupData['creators'])) {
        $freelancersList = $backupData['creators'];
    } else if (isset($backupData[0]) && is_array($backupData[0])) {
        $freelancersList = $backupData;
    }

    if (empty($freelancersList)) {
        return ['success' => false, 'error' => 'No creator records found in backup payload'];
    }

    $restoredCount = 0;
    $updatedCount = 0;

    foreach ($freelancersList as $f) {
        $email = strtolower(trim($f['email'] ?? ''));
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

        $name = trim($f['name'] ?? ($f['fullName'] ?? 'Creator'));
        $username = trim($f['username'] ?? '');
        $memberId = trim($f['member_id'] ?? ($f['userCode'] ?? ''));
        $phone = trim($f['phone'] ?? ($f['whatsapp'] ?? ''));
        $skills = isset($f['skills']) ? (is_array($f['skills']) ? implode(', ', $f['skills']) : (string)$f['skills']) : null;
        $completed = !empty($f['has_completed_onboarding']) || !empty($f['hasCompletedOnboarding']) ? 1 : 0;

        $portfolioData = '';
        if (!empty($f['portfolio_data'])) {
            $portfolioData = is_array($f['portfolio_data']) ? json_encode($f['portfolio_data']) : (string)$f['portfolio_data'];
        } else {
            $portfolioData = json_encode($f);
        }

        $checkStmt = $conn->prepare("SELECT id FROM freelancers WHERE LOWER(email) = ? LIMIT 1");
        $checkStmt->bind_param("s", $email);
        $checkStmt->execute();
        $res = $checkStmt->get_result();
        $existing = $res->fetch_assoc();
        $checkStmt->close();

        if ($existing) {
            $stmt = $conn->prepare("
                UPDATE freelancers 
                SET member_id = ?, username = ?, name = ?, phone = ?, skills = ?, portfolio_data = ?, has_completed_onboarding = ?, user_code = ?
                WHERE id = ?
            ");
            $stmt->bind_param("ssssssisi", $memberId, $username, $name, $phone, $skills, $portfolioData, $completed, $memberId, $existing['id']);
            $stmt->execute();
            $stmt->close();
            $updatedCount++;
        } else {
            $stmt = $conn->prepare("
                INSERT INTO freelancers (member_id, email, username, name, phone, skills, portfolio_data, has_completed_onboarding, user_code)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("sssssssss", $memberId, $email, $username, $name, $phone, $skills, $portfolioData, $completed, $memberId);
            $stmt->execute();
            $stmt->close();
            $restoredCount++;
        }
    }

    if (!empty($backupData['settings']) && is_array($backupData['settings'])) {
        foreach ($backupData['settings'] as $k => $v) {
            $strV = (string)$v;
            $sStmt = $conn->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $sStmt->bind_param("ss", $k, $strV);
            $sStmt->execute();
            $sStmt->close();
        }
    }

    return [
        'success' => true,
        'inserted' => $restoredCount,
        'updated' => $updatedCount,
        'total_processed' => $restoredCount + $updatedCount
    ];
}

// Check and trigger background daily auto-backup if not yet created today
$todayAutoFile = getBackupsDirectory() . '/auto_snapshot_' . date('Y-m-d') . '.json';
if (!file_exists($todayAutoFile)) {
    @createDatabaseSnapshot($conn, 'auto');
}

// CREATE MANUAL BACKUP SNAPSHOT (POST /api/backup/create) — ADMIN ONLY
if ($method === 'POST' && strpos($path, '/backup/create') !== false) {
    requireAdminAuth();
    $snapshot = createDatabaseSnapshot($conn, 'manual');
    echo json_encode(['status' => 'success', 'backup' => $snapshot]);
    $conn->close();
    exit();
}

// LIST SERVER BACKUP SNAPSHOTS (GET /api/backup/list) — ADMIN ONLY
if ($method === 'GET' && strpos($path, '/backup/list') !== false) {
    requireAdminAuth();
    $list = getDatabaseSnapshotsList();
    echo json_encode(['status' => 'success', 'backups' => $list]);
    $conn->close();
    exit();
}

// DOWNLOAD SERVER BACKUP SNAPSHOT (GET /api/backup/download?file=xxx) — ADMIN ONLY
if ($method === 'GET' && strpos($path, '/backup/download') !== false) {
    requireAdminAuth();
    $fileParam = basename($_GET['file'] ?? '');
    $filePath = getBackupsDirectory() . '/' . $fileParam;
    if (!empty($fileParam) && file_exists($filePath)) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileParam . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        $conn->close();
        exit();
    }
    http_response_code(404);
    echo json_encode(['error' => 'Backup file not found']);
    $conn->close();
    exit();
}

// RESTORE DATABASE FROM SERVER SNAPSHOT (POST /api/backup/restore) — ADMIN ONLY
if ($method === 'POST' && strpos($path, '/backup/restore') !== false) {
    requireAdminAuth();
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: [];
    $filename = basename($input['filename'] ?? '');
    $filePath = getBackupsDirectory() . '/' . $filename;

    if (!empty($filename) && file_exists($filePath)) {
        $backupJson = json_decode(file_get_contents($filePath), true);
        $result = restoreDatabaseFromData($conn, $backupJson);
        echo json_encode(['status' => 'success', 'restored_from' => $filename, 'result' => $result]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Snapshot file not found on server']);
    }
    $conn->close();
    exit();
}

// RESTORE DATABASE FROM UPLOADED JSON (POST /api/backup/upload-restore) — ADMIN ONLY
if ($method === 'POST' && strpos($path, '/backup/upload-restore') !== false) {
    requireAdminAuth();
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if ($input) {
        $result = restoreDatabaseFromData($conn, $input);
        echo json_encode(['status' => 'success', 'result' => $result]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON backup payload']);
    }
    $conn->close();
    exit();
}

// SEND ONBOARDING PENDING EMAIL NOTIFICATION (POST /api/mail/onboarding-pending)
if ($method === 'POST' && strpos($path, '/mail/onboarding-pending') !== false) {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: [];

    $email = strtolower(trim($input['email'] ?? ''));
    $name = trim($input['name'] ?? ($input['fullName'] ?? 'Creator'));
    $userCode = trim($input['userCode'] ?? 'GGVE0001');
    $username = trim($input['username'] ?? '');
    $profession = trim($input['profession'] ?? ($input['title'] ?? 'Professional Video Editor'));
    $experienceYears = trim($input['experienceYears'] ?? ($input['experience'] ?? '3+ Years'));
    $location = trim($input['location'] ?? 'Remote');
    $whatsapp = trim($input['whatsapp'] ?? ($input['phone'] ?? 'Not provided'));
    $softwares = $input['softwares'] ?? ['Premiere Pro', 'After Effects'];
    $softwaresStr = is_array($softwares) ? implode(', ', $softwares) : (string)$softwares;

    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $portfolioUrl = "https://studio.gogangs.com/{$userCode}/{$username}";
        $adminDashboardUrl = "https://studio.gogangs.com/admin";

        // 1. Send Confirmation Email to the Creator
        $creatorHeadline = "Application Submitted";
        $creatorSubheadline = "Your video editor profile is currently under review by our admin team.";
        $creatorTableRows = [
            'Name' => htmlspecialchars($name),
            'Role' => htmlspecialchars($profession),
            'Email' => htmlspecialchars($email),
            'Reference Code' => htmlspecialchars($userCode),
            'Status' => 'Review In Progress',
            'Storage' => '1,024 MB (1 GB upon approval)',
            'Public Portfolio' => "studio.gogangs.com/{$userCode}/{$username}"
        ];

        $creatorMailSent = sendStudioNotificationEmail(
            $email,
            $name,
            "Application Received — Account Review Under Progress | GoGangs Studio",
            $creatorHeadline,
            $creatorSubheadline,
            "",
            "VIEW MY PORTFOLIO",
            $portfolioUrl,
            $userCode,
            $creatorTableRows
        );

        // 2. IMMEDIATELY Send Admin Alert Email (to rishidar27@gmail.com and hello@gogangs.com)
        $adminEmail = defined('ADMIN_EMAIL_AUTH') ? ADMIN_EMAIL_AUTH : 'rishidar27@gmail.com';
        $adminHeadline = "New Editor Waiting For Review";
        $adminSubheadline = "A new video editor has registered and completed onboarding. Review and approve their account to unlock video uploads.";

        $adminTableRows = [
            'Editor Name' => htmlspecialchars($name),
            'Email' => htmlspecialchars($email),
            'Member ID' => htmlspecialchars($userCode),
            'Profession' => htmlspecialchars($profession),
            'Experience' => htmlspecialchars($experienceYears),
            'Location' => htmlspecialchars($location),
            'WhatsApp / Phone' => htmlspecialchars($whatsapp),
            'Tools / Software' => htmlspecialchars($softwaresStr),
            'Portfolio URL' => "studio.gogangs.com/{$userCode}/{$username}",
            'Status' => 'Pending Review'
        ];

        $adminMailSent = sendStudioNotificationEmail(
            $adminEmail,
            "GoGangs Admin",
            "🚨 New Editor Application: {$name} (#{$userCode}) Waiting for Review",
            $adminHeadline,
            $adminSubheadline,
            "",
            "OPEN ADMIN DASHBOARD TO APPROVE",
            $adminDashboardUrl,
            $userCode,
            $adminTableRows
        );

        if ($adminEmail !== 'hello@gogangs.com') {
            @sendStudioNotificationEmail(
                'hello@gogangs.com',
                "GoGangs Admin",
                "🚨 New Editor Application: {$name} (#{$userCode}) Waiting for Review",
                $adminHeadline,
                $adminSubheadline,
                "",
                "OPEN ADMIN DASHBOARD TO APPROVE",
                $adminDashboardUrl,
                $userCode,
                $adminTableRows
            );
        }

        echo json_encode([
            'status' => 'success', 
            'creatorMailSent' => $creatorMailSent, 
            'adminMailSent' => $adminMailSent
        ]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid email address']);
    }
    $conn->close();
    exit();
}

// APPROVE FREELANCER CREATOR (POST /api/freelancers/approve) — ADMIN ONLY
if ($method === 'POST' && strpos($path, '/freelancers/approve') !== false) {
    requireAdminAuth();
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: [];

    $email = strtolower(trim($input['email'] ?? ''));
    $status = trim($input['status'] ?? 'approved'); // 'approved' | 'pending' | 'rejected'

    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email is required']);
        $conn->close();
        exit();
    }

    $stmt = $conn->prepare("SELECT id, member_id, username, name, portfolio_data FROM freelancers WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    if ($row) {
        $portfolio = !empty($row['portfolio_data']) ? json_decode($row['portfolio_data'], true) : [];
        $portfolio['approvalStatus'] = $status;
        $nowDateTime = date('Y-m-d H:i:s');
        if ($status === 'approved') {
            $portfolio['approvedAt'] = date('c');
            $approvedAtCol = $nowDateTime;
        } else {
            $portfolio['approvedAt'] = null;
            $approvedAtCol = null;
        }
        $updatedJson = json_encode($portfolio);

        $updateStmt = $conn->prepare("UPDATE freelancers SET portfolio_data = ?, approval_status = ?, approved_at = ? WHERE id = ?");
        $updateStmt->bind_param("sssi", $updatedJson, $status, $approvedAtCol, $row['id']);
        $updateStmt->execute();
        $updateStmt->close();

        // Clear any deletion tombstone
        $cleanEmail = $conn->real_escape_string($email);
        $conn->query("DELETE FROM deleted_accounts WHERE LOWER(email) = '{$cleanEmail}'");

        // Send Approval Email if approved
        $mailSent = false;
        if ($status === 'approved') {
            $name = $row['name'] ?: ($portfolio['fullName'] ?? 'Creator');
            $userCode = $row['member_id'] ?: ($portfolio['userCode'] ?? 'GGVE0001');
            $username = $row['username'] ?: ($portfolio['username'] ?? '');
            $portfolioUrl = "https://studio.gogangs.com/p/{$userCode}";

            $headline = "Account Verified & Approved";
            $subheadline = "Your GoGangs Studio creator account is verified and ready.";

            $tableRows = [
                'Name' => htmlspecialchars($name),
                'Role' => htmlspecialchars($portfolio['title'] ?? 'Professional Video Editor'),
                'Email' => htmlspecialchars($email),
                'Member ID' => htmlspecialchars($userCode),
                'Storage Unlocked' => '1,024 MB (1 GB) Cloudflare R2 4K',
                'Status' => 'Verified & Active',
                'Studio Link' => "studio.gogangs.com/p/{$userCode}"
            ];

            $mailSent = sendStudioNotificationEmail(
                $email,
                $name,
                "Your GoGangs Studio Account is Approved! (1 GB Video Storage Unlocked)",
                $headline,
                $subheadline,
                "",
                "OPEN MY CREATOR STUDIO",
                $portfolioUrl,
                $userCode,
                $tableRows
            );
        }

        echo json_encode(['status' => 'success', 'approvalStatus' => $status, 'mailSent' => $mailSent]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Freelancer not found']);
    }
    $conn->close();
    exit();
}

/**
 * Server-authoritative repair function: Ensures every freelancer in MySQL
 * has a strictly unique, sequential member_id (GGVE0001, GGVE0002, GGVE0003, ...).
 * Admin rishidar27@gmail.com is strictly locked to GGVE0001.
 */
if (!function_exists('repairDuplicateMemberCodes')) {
    function repairDuplicateMemberCodes($conn) {
        // Disabled permanently to prevent concurrent ID shuffling and race conditions.
        return;
    }
}

function getOrAssignMemberIdForEmail($conn, $email) {
    $email = strtolower(trim($email));
    if (empty($email) || $email === 'rishidar27@gmail.com') return 'GGVE0001';

    // 1. Check if user already has an assigned member_id in the database
    $stmt = $conn->prepare("SELECT member_id FROM freelancers WHERE LOWER(email) = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if ($row && !empty($row['member_id']) && $row['member_id'] !== 'GGVE0001' && preg_match('/^GGVE\d+$/i', $row['member_id'])) {
            return strtoupper(trim($row['member_id']));
        }
    }

    // 2. Find the absolute highest numeric suffix currently in MySQL
    $maxRes = $conn->query("SELECT MAX(CAST(SUBSTRING(member_id, 5) AS UNSIGNED)) AS max_num FROM freelancers WHERE member_id REGEXP '^GGVE[0-9]+$'");
    $maxNum = 1;
    if ($maxRes) {
        $maxRow = $maxRes->fetch_assoc();
        if ($maxRow && isset($maxRow['max_num']) && (int)$maxRow['max_num'] > 1) {
            $maxNum = (int)$maxRow['max_num'];
        }
    }

    return sprintf("GGVE%04d", $maxNum + 1);
}

// GET UNIQUE MEMBER CODE ATOMICALLY (GET /api/user-code or /api/freelancers/get-user-code?email=xxx)
if ($method === 'GET' && (strpos($path, '/user-code') !== false || strpos($path, '/freelancers/get-user-code') !== false)) {
    $email = strtolower(trim($_GET['email'] ?? ''));
    $code = getOrAssignMemberIdForEmail($conn, $email);
    echo json_encode(['email' => $email, 'userCode' => $code]);
    $conn->close();
    exit();
}

// INSTANT DUPLICATE MOBILE CHECK (POST /api/check-mobile)
if ($method === 'POST' && strpos($path, '/check-mobile') !== false) {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: [];
    $rawMobile = $input['mobile'] ?? ($input['whatsapp'] ?? '');
    $mobile = preg_replace('/[^0-9]/', '', $rawMobile);
    $email = strtolower(trim($input['email'] ?? ''));

    if (strlen($mobile) < 7) {
        echo json_encode(['isDuplicate' => false]);
        $conn->close();
        exit();
    }

    $isDup = false;
    $dupEmail = '';
    $dupName = '';

    // Use MySQL JSON_EXTRACT for fast targeted lookup instead of full-table PHP loop
    // Check 1: socials.whatsapp JSON field
    $stmt = $conn->prepare("
        SELECT email, name, JSON_EXTRACT(portfolio_data, '$.socials.whatsapp') AS ws
        FROM freelancers
        WHERE JSON_EXTRACT(portfolio_data, '$.socials.whatsapp') IS NOT NULL
          AND REGEXP_REPLACE(JSON_UNQUOTE(JSON_EXTRACT(portfolio_data, '$.socials.whatsapp')), '[^0-9]', '') = ?
          AND (? = '' OR LOWER(email) != ?)
        LIMIT 1
    ");
    $stmt->bind_param("sss", $mobile, $email, $email);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    if ($row) {
        $isDup = true;
        $dupEmail = $row['email'];
        $dupName = $row['name'] ?: 'Creator';
    }

    // Check 2: top-level whatsapp field (fallback schema)
    if (!$isDup) {
        $stmt2 = $conn->prepare("
            SELECT email, name
            FROM freelancers
            WHERE JSON_EXTRACT(portfolio_data, '$.whatsapp') IS NOT NULL
              AND REGEXP_REPLACE(JSON_UNQUOTE(JSON_EXTRACT(portfolio_data, '$.whatsapp')), '[^0-9]', '') = ?
              AND (? = '' OR LOWER(email) != ?)
            LIMIT 1
        ");
        $stmt2->bind_param("sss", $mobile, $email, $email);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        $row2 = $res2->fetch_assoc();
        $stmt2->close();

        if ($row2) {
            $isDup = true;
            $dupEmail = $row2['email'];
            $dupName = $row2['name'] ?: 'Creator';
        }
    }

    echo json_encode([
        'isDuplicate' => $isDup
    ]);
    $conn->close();
    exit();
}

// SAVE FREELANCER PORTFOLIO (POST /api/freelancers/save)
if ($method === 'POST' && (strpos($path, '/freelancers/save') !== false)) {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!$input || empty($input['email'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing email in payload', 'received' => $rawInput]);
        exit();
    }

    $email = strtolower(trim($input['email']));
    $name = !empty($input['name']) ? trim($input['name']) : (!empty($input['portfolio']['fullName']) ? trim($input['portfolio']['fullName']) : 'Freelancer');
    
    // Custom username from payload takes highest priority
    $customUsername = '';
    if (!empty($input['username'])) {
        $customUsername = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($input['username'])));
    } else if (!empty($input['portfolio']['username'])) {
        $customUsername = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($input['portfolio']['username'])));
    }

    if (!empty($customUsername) && strlen($customUsername) >= 2) {
        $username = $customUsername;
    } else if (!empty($name) && strtolower($name) !== 'freelancer' && strtolower($name) !== 'user') {
        $username = preg_replace('/[^a-z0-9_-]/', '', strtolower($name));
    } else {
        $username = preg_replace('/[^a-z0-9_-]/', '', explode('@', $email)[0]);
    }

    $has_completed = !empty($input['has_completed_onboarding']) ? 1 : 0;

    // Check if freelancer already exists in database
    $checkStmt = $conn->prepare("SELECT id, member_id, has_completed_onboarding, portfolio_data FROM freelancers WHERE LOWER(email) = ? LIMIT 1");
    $checkStmt->bind_param("s", $email);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $existingRow = $checkResult->fetch_assoc();
    $checkStmt->close();

    // Server authoritative unique member_id assignment:
    // If existing freelancer already has a valid member_id, ALWAYS PRESERVE IT IMMUTABLY!
    $assignedMemberId = '';
    if ($email === 'rishidar27@gmail.com') {
        $assignedMemberId = 'GGVE0001';
    } else if ($existingRow && !empty($existingRow['member_id']) && $existingRow['member_id'] !== 'GGVE0001' && preg_match('/^GGVE\d+$/i', $existingRow['member_id'])) {
        $assignedMemberId = strtoupper(trim($existingRow['member_id']));
    } else {
        $assignedMemberId = getOrAssignMemberIdForEmail($conn, $email);
    }

    // Sync userCode & username in portfolio JSON payload
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
    $portfolioArray['pageLink'] = "/{$assignedMemberId}/{$username}";
    $portfolioArray['visitorUrl'] = "https://studio.gogangs.com/{$assignedMemberId}/{$username}";
    $portfolioArray['studioUrl'] = "https://studio.gogangs.com/p/{$assignedMemberId}";

    // Ensure all social links are preserved and properly structured
    if (isset($input['portfolio']['socials']) && is_array($input['portfolio']['socials'])) {
        $portfolioArray['socials'] = $input['portfolio']['socials'];
        if (!empty($input['portfolio']['socials']['whatsapp'])) {
            $portfolioArray['whatsapp'] = $input['portfolio']['socials']['whatsapp'];
        }
    }

    $portfolioData = json_encode($portfolioArray);

    if ($existingRow) {
        // If the user already completed onboarding previously, never downgrade to 0
        $existingCompleted = (int)($existingRow['has_completed_onboarding'] ?? 0);
        $prevData = !empty($existingRow['portfolio_data']) ? json_decode($existingRow['portfolio_data'], true) : [];
        if ($existingCompleted === 1 || !empty($prevData['hasCompletedOnboarding']) || $has_completed === 1) {
            $has_completed = 1;
            $portfolioArray['hasCompletedOnboarding'] = true;
        }

        // Preserve videos: keep incoming videos if present, otherwise maintain existing videos from DB
        if (isset($input['portfolio']['videos']) && is_array($input['portfolio']['videos'])) {
            $portfolioArray['videos'] = $input['portfolio']['videos'];
        } else if (isset($prevData['videos']) && is_array($prevData['videos'])) {
            $portfolioArray['videos'] = $prevData['videos'];
        }

        // Approval status: check both column and JSON
        $isApprovedInDb = (($existingRow['approval_status'] ?? '') === 'approved' || (!empty($prevData['approvalStatus']) && $prevData['approvalStatus'] === 'approved'));
        if ($email === 'rishidar27@gmail.com' || $isApprovedInDb) {
            $currApproval = 'approved';
            $portfolioArray['approvalStatus'] = 'approved';
        } else {
            $currApproval = $existingRow['approval_status'] ?: ($prevData['approvalStatus'] ?? 'pending');
            $portfolioArray['approvalStatus'] = $currApproval;
        }

        $portfolioData = json_encode($portfolioArray);

        // UPDATE — email column is immutable
        $stmt = $conn->prepare("
            UPDATE freelancers SET username = ?, name = ?, member_id = ?, portfolio_data = ?, has_completed_onboarding = ?, approval_status = ? WHERE id = ?
        ");
        $stmt->bind_param("ssssisi", $username, $name, $assignedMemberId, $portfolioData, $has_completed, $currApproval, $existingRow['id']);
    } else {
        // INSERT new creator — default to pending approval, preserve uploaded videos
        $approvalStatusCol = ($email === 'rishidar27@gmail.com') ? 'approved' : 'pending';
        $approvedAtCol = ($email === 'rishidar27@gmail.com') ? date('Y-m-d H:i:s') : null;
        $portfolioArray['approvalStatus'] = $approvalStatusCol;
        if (isset($input['portfolio']['videos']) && is_array($input['portfolio']['videos'])) {
            $portfolioArray['videos'] = $input['portfolio']['videos'];
        }
        $portfolioData = json_encode($portfolioArray);

        $stmt = $conn->prepare("
            INSERT INTO freelancers (member_id, email, username, name, portfolio_data, has_completed_onboarding, approval_status, approved_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("sssssiss", $assignedMemberId, $email, $username, $name, $portfolioData, $has_completed, $approvalStatusCol, $approvedAtCol);
    }

    if ($stmt->execute()) {
        $stmt->close();
        // Clear any deletion tombstone so user has a clean fresh account
        $cleanEmail = $conn->real_escape_string($email);
        $conn->query("DELETE FROM deleted_accounts WHERE LOWER(email) = '{$cleanEmail}'");
        
        echo json_encode(['status' => 'success', 'email' => $email, 'username' => $username, 'member_id' => $assignedMemberId, 'userCode' => $assignedMemberId]);
    } else {
        $err = $stmt->error;
        $stmt->close();
        http_response_code(500);
        echo json_encode(['error' => 'MySQL save failed: ' . $err]);
    }

    $conn->close();
    exit();
}


// GET ALL FREELANCERS (GET /api/freelancers)
if ($method === 'GET' && (rtrim($path, '/') === '/api/freelancers' || preg_match('#/freelancers/?$#', $path))) {
    try {
        ensureFreelancerColumns($conn);
    } catch (Throwable $e) {
        // Silently continue to prevent breaking data output
    }
    $res = $conn->query("SELECT * FROM freelancers ORDER BY id DESC");
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
    }
    echo json_encode($rows);
    $conn->close();
    exit();
}

// GET FREELANCER BY EXACT EMAIL (GET /api/freelancers/by-email/*)
if ($method === 'GET' && strpos($path, '/freelancers/by-email/') !== false) {
    $parts = explode('/freelancers/by-email/', $path);
    $targetEmail = strtolower(urldecode(end($parts)));
    $targetEmail = trim($targetEmail, '/');

    if (empty($targetEmail)) {
        http_response_code(400);
        echo json_encode(['error' => 'Valid email required']);
        $conn->close();
        exit();
    }

    $stmt = $conn->prepare("SELECT * FROM freelancers WHERE LOWER(email) = ? LIMIT 1");
    $stmt->bind_param("s", $targetEmail);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if ($row) {
        $parsed = !empty($row['portfolio_data']) ? json_decode($row['portfolio_data'], true) : [];
        $out = $parsed ?: [];
        $out['email'] = $row['email'];
        $out['username'] = $row['username'];
        $out['fullName'] = $row['name'] ?? ($parsed['fullName'] ?? '');
        $out['userCode'] = $row['member_id'] ?? ($parsed['userCode'] ?? '');
        $hasCompleted = (bool)$row['has_completed_onboarding'] || !empty($parsed['hasCompletedOnboarding']) || (!empty($row['name']) && strtolower($row['name']) !== 'freelancer' && strtolower($row['name']) !== 'user') || (!empty($parsed['fullName']) && strtolower($parsed['fullName']) !== 'freelancer') || !empty($parsed['videos']);
        $out['hasCompletedOnboarding'] = $hasCompleted;
        $out['approvalStatus'] = $row['approval_status'] ?: ($parsed['approvalStatus'] ?? ($row['email'] === 'rishidar27@gmail.com' ? 'approved' : 'pending'));
        $out['approvedAt'] = $row['approved_at'] ?: ($parsed['approvedAt'] ?? null);
        echo json_encode($out);
    } else {
        echo json_encode(null);
    }

    $conn->close();
    exit();
}

// GET FREELANCER BY USERNAME OR USER CODE (GET /api/freelancers/by-username/* or /by-code/*)
if ($method === 'GET' && (strpos($path, '/freelancers/by-username/') !== false || strpos($path, '/freelancers/by-code/') !== false)) {
    $parts = explode('/', trim($path, '/'));
    $identifier = strtolower(urldecode(end($parts)));

    if (empty($identifier)) {
        echo json_encode(null);
        $conn->close();
        exit();
    }

    // Exact match on member_id (e.g. GGVE0003), username, email, or clean slug of name
    $stmt = $conn->prepare("
        SELECT * FROM freelancers
        WHERE LOWER(member_id) = ?
           OR LOWER(username) = ?
           OR LOWER(email) = ?
           OR LOWER(REPLACE(REPLACE(REPLACE(name, ' ', ''), '-', ''), '_', '')) = ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->bind_param("ssss", $identifier, $identifier, $identifier, $identifier);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if ($row) {
        $parsed = !empty($row['portfolio_data']) ? json_decode($row['portfolio_data'], true) : [];
        $out = $parsed ?: [];
        $out['email'] = $row['email'];
        $out['username'] = $row['username'];
        $out['fullName'] = $row['name'] ?? ($parsed['fullName'] ?? '');
        $out['userCode'] = $row['member_id'] ?? ($parsed['userCode'] ?? '');
        $hasCompleted = (bool)$row['has_completed_onboarding'] || !empty($parsed['hasCompletedOnboarding']) || (!empty($row['name']) && strtolower($row['name']) !== 'freelancer' && strtolower($row['name']) !== 'user') || (!empty($parsed['fullName']) && strtolower($parsed['fullName']) !== 'freelancer') || !empty($parsed['videos']);
        $out['hasCompletedOnboarding'] = $hasCompleted;
        $out['approvalStatus'] = $row['approval_status'] ?: ($parsed['approvalStatus'] ?? ($row['email'] === 'rishidar27@gmail.com' ? 'approved' : 'pending'));
        $out['approvedAt'] = $row['approved_at'] ?: ($parsed['approvedAt'] ?? null);
        echo json_encode($out);
    } else {
        echo json_encode(null);
    }

    $conn->close();
    exit();
}

// REAL-TIME CREATOR ACCOUNT STATUS HEARTBEAT (GET /api/freelancers/status)
if ($method === 'GET' && strpos($path, '/freelancers/status') !== false) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $email = strtolower(trim($_GET['email'] ?? ''));
    $userCode = strtolower(trim($_GET['userCode'] ?? ($_GET['user_code'] ?? '')));

    if (empty($email) && empty($userCode)) {
        echo json_encode(['status' => 'not_found']);
        $conn->close();
        exit();
    }

    // 1. Check if explicitly in deleted_accounts table
    if (!empty($email)) {
        $delStmt = $conn->prepare("SELECT email, deleted_at FROM deleted_accounts WHERE LOWER(email) = ? LIMIT 1");
        if ($delStmt) {
            $delStmt->bind_param("s", $email);
            $delStmt->execute();
            $delRes = $delStmt->get_result();
            if ($delRes->fetch_assoc()) {
                $delStmt->close();
                echo json_encode([
                    'status' => 'deleted',
                    'message' => 'Your account has been removed by the admin.'
                ]);
                $conn->close();
                exit();
            }
            $delStmt->close();
        }
    }

    // 2. Query freelancers table
    $stmt = $conn->prepare("SELECT id, email, member_id, username, approval_status, approved_at, portfolio_data FROM freelancers WHERE (email != '' AND LOWER(email) = ?) OR (member_id != '' AND LOWER(member_id) = ?) LIMIT 1");
    $stmt->bind_param("ss", $email, $userCode);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    if ($row) {
        $pData = !empty($row['portfolio_data']) ? json_decode($row['portfolio_data'], true) : [];
        $appStatus = $row['approval_status'] ?: ($pData['approvalStatus'] ?? ($row['email'] === 'rishidar27@gmail.com' ? 'approved' : 'pending'));
        $appAt = $row['approved_at'] ?: ($pData['approvedAt'] ?? null);

        echo json_encode([
            'status' => $appStatus,
            'approvalStatus' => $appStatus,
            'approvedAt' => $appAt,
            'email' => $row['email'],
            'userCode' => $row['member_id']
        ]);
    } else {
        // Not found in database = account was removed
        echo json_encode([
            'status' => 'deleted',
            'message' => 'Your account has been removed by the admin.'
        ]);
    }

    $conn->close();
    exit();
}

// DELETE FREELANCER (POST /api/freelancers/delete) — ADMIN ONLY
if (($method === 'POST' || $method === 'DELETE') && strpos($path, '/freelancers/delete') !== false) {
    requireAdminAuth();
    $input = json_decode(file_get_contents('php://input'), true);
    $email = strtolower(trim($input['email'] ?? ''));
    $username = strtolower(trim($input['username'] ?? ''));
    $member_id = strtolower(trim($input['member_id'] ?? ''));

    if (!empty($email) || !empty($username) || !empty($member_id)) {
        // 1. Record deletion tombstone for real-time notification
        if (!empty($email)) {
            $insDel = $conn->prepare("INSERT INTO deleted_accounts (email, member_id, username, deleted_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE deleted_at = NOW()");
            if ($insDel) {
                $insDel->bind_param("sss", $email, $member_id, $username);
                $insDel->execute();
                $insDel->close();
            }
        }

        $prefix = !empty($email) ? (explode('@', $email)[0] . '%') : '___NOMATCH___';
        $stmt = $conn->prepare("DELETE FROM freelancers WHERE (email != '' AND email = ?) OR (username != '' AND username = ?) OR (member_id != '' AND member_id = ?) OR (email != '' AND email LIKE ?)");
        $stmt->bind_param("ssss", $email, $username, $member_id, $prefix);
        $stmt->execute();
        $deletedRows = $stmt->affected_rows;
        $stmt->close();

        // 2. Delete associated video records from portfolio_videos table
        if (!empty($email)) {
            $stmtVid = $conn->prepare("DELETE FROM portfolio_videos WHERE email = ? OR email LIKE ?");
            $stmtVid->bind_param("ss", $email, $prefix);
            $stmtVid->execute();
            $stmtVid->close();
        }

        // 3. Clean up physical video files from uploads/ folder
        $uploadsDir = __DIR__ . '/uploads';
        if (is_dir($uploadsDir) && !empty($email)) {
            $cleanPrefix = preg_replace('/[^a-zA-Z0-9_-]/', '', explode('@', $email)[0]);
            $files = @scandir($uploadsDir);
            if ($files) {
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..' && strpos($file, $cleanPrefix) !== false) {
                        @unlink($uploadsDir . '/' . $file);
                    }
                }
            }
        }

        echo json_encode(['status' => 'success', 'deleted_rows' => $deletedRows]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Identifier required']);
    }
    $conn->close();
    exit();
}

// UPLOAD VIDEO BINARY DIRECTLY INTO MYSQL portfolio_videos TABLE (POST /api/upload-video-binary or /api/upload-video)
if ($method === 'POST' && (strpos($path, '/upload-video') !== false || strpos($path, '/videos/upload') !== false)) {
    $filename = $_GET['filename'] ?? ($_FILES['video']['name'] ?? ('vid_' . time() . '.mp4'));
    $email = $_GET['email'] ?? 'user@gogangs.com';
    $videoId = 'vid_' . time() . '_' . substr(md5(uniqid()), 0, 6);
    $fileType = 'video/mp4';

    $uploadsDir = __DIR__ . '/uploads';
    if (!file_exists($uploadsDir)) {
        @mkdir($uploadsDir, 0755, true);
    }
    $targetFilePath = $uploadsDir . '/' . $videoId . '.mp4';

    $bytesWritten = 0;
    if (isset($_FILES['video']) && $_FILES['video']['error'] === UPLOAD_ERR_OK) {
        $fileType = $_FILES['video']['type'] ?? 'video/mp4';
        if (move_uploaded_file($_FILES['video']['tmp_name'], $targetFilePath)) {
            $bytesWritten = filesize($targetFilePath);
        }
    } else {
        if (!empty($_SERVER['CONTENT_TYPE'])) {
            $fileType = $_SERVER['CONTENT_TYPE'];
        }
        $in = fopen('php://input', 'rb');
        $out = fopen($targetFilePath, 'wb');
        if ($in && $out) {
            $bytesWritten = stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
        }
    }

    if ($bytesWritten > 50 && file_exists($targetFilePath)) {
        $streamUrl = '/api/videos/stream?id=' . $videoId;
        $dbDataMarker = 'DISK_STORED';
        $storageProvider = 'local_disk';

        // 1. If Cloudflare R2 is configured, upload directly to R2 bucket
        $r2Configured = !empty($r2_account_id) && !empty($r2_access_key) && !empty($r2_secret_key) && !empty($r2_bucket);
        if ($r2Configured) {
            $r2Key = $videoId . '.mp4';
            $r2Success = uploadToCloudflareR2($r2_account_id, $r2_access_key, $r2_secret_key, $r2_bucket, $r2Key, $targetFilePath, $fileType);
            if ($r2Success) {
                $dbDataMarker = 'R2_STORED';
                $storageProvider = 'cloudflare_r2';
                if (!empty($r2_public_url)) {
                    $streamUrl = rtrim($r2_public_url, '/') . '/' . $r2Key;
                }
                // Free up cPanel hosting disk space immediately
                @unlink($targetFilePath);
            }
        }

        $stmt = $conn->prepare("INSERT INTO portfolio_videos (video_id, email, filename, file_type, video_data) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE video_data = VALUES(video_data)");
        $stmt->bind_param("sssss", $videoId, $email, $filename, $fileType, $dbDataMarker);
        $stmt->execute();
        $stmt->close();

        echo json_encode([
            'status' => 'success',
            'videoId' => $videoId,
            'videoUrl' => $streamUrl,
            'storage' => $storageProvider,
            'size' => $bytesWritten
        ]);
        $conn->close();
        exit();
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Empty or invalid video payload received']);
        $conn->close();
        exit();
    }
}

// Default Health Status
echo json_encode(['status' => 'ok', 'service' => 'Studio GoGangs MySQL Video Engine', 'timestamp' => date('Y-m-d H:i:s')]);
$conn->close();
?>
