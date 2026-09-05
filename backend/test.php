<?php
header('Content-Type: text/html; charset=utf-8');

// Production Database Credentials
$db_host = "localhost";
$db_user = "profilei_Hari";
$db_pass = "Rishidar123@";
$db_name = "profilei_website";

echo "<h2>🧪 Studio GoGangs Database Diagnostic Test</h2>";

// 1. Test MySQL Connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("<div style='color:red; font-weight:bold;'>❌ Connection Failed: " . $conn->connect_error . "</div>");
}
echo "<div style='color:green; font-weight:bold;'>✅ Successfully Connected to MySQL Database: '$db_name'</div><br>";

// 2. Check Existing Database Tables
$tables = ['projects', 'live_status', 'clients', 'freelancers', 'direct_messages', 'project_files'];
echo "<h3>📊 Database Table Check:</h3><ul>";

foreach ($tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result && $result->num_rows > 0) {
        $countRes = $conn->query("SELECT COUNT(*) as cnt FROM `$table`");
        $count = $countRes ? $countRes->fetch_assoc()['cnt'] : 0;
        echo "<li style='color:green;'>✅ Table <b>$table</b> exists ($count records found)</li>";
    } else {
        echo "<li style='color:red;'>❌ Table <b>$table</b> DOES NOT EXIST</li>";
    }
}
echo "</ul>";

// 3. Test Writing & Inserting Sample Data into `direct_messages`
echo "<h3>📝 Testing Data Write (INSERT into `direct_messages`):</h3>";
$test_email = "hello@gogangs.com";
$test_msg = "Test message generated on " . date('Y-m-d H:i:s');

$stmt = $conn->prepare("INSERT INTO direct_messages (sender_email, sender_role, sender_name, recipient_email, message) VALUES (?, 'client', 'Diagnostic Tester', 'admin@gogangs.com', ?)");
if ($stmt) {
    $stmt->bind_param("ss", $test_email, $test_msg);
    if ($stmt->execute()) {
        $inserted_id = $stmt->insert_id;
        echo "<div style='color:green;'>✅ Test record written successfully! (Inserted ID: $inserted_id)</div>";
    } else {
        echo "<div style='color:red;'>❌ Insert failed: " . $stmt->error . "</div>";
    }
    $stmt->close();
} else {
    echo "<div style='color:red;'>❌ Prepare statement failed: " . $conn->error . "</div>";
}

// 4. Test Querying Data Back
echo "<h3>📖 Testing Data Read (SELECT from `direct_messages`):</h3>";
$readResult = $conn->query("SELECT * FROM direct_messages ORDER BY id DESC LIMIT 5");

if ($readResult && $readResult->num_rows > 0) {
    echo "<table border='1' cellpadding='8' style='border-collapse:collapse; background:#111; color:#fff;'>";
    echo "<tr><th>ID</th><th>Sender Email</th><th>Name</th><th>Message</th><th>Created At</th></tr>";
    while ($row = $readResult->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['sender_email']) . "</td>";
        echo "<td>" . htmlspecialchars($row['sender_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['message']) . "</td>";
        echo "<td>" . $row['created_at'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<div style='color:orange;'>No records found in direct_messages.</div>";
}

$conn->close();
echo "<br><hr><p style='color:gray;'>Diagnostic Complete - Studio GoGangs</p>";
?>
