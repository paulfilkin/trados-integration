<?php
// Save this as check_logs.php in your trados-integration directory
// Run it at: http://localhost/trados-integration/check_logs.php

require_once 'includes/database.php';
require_once 'includes/functions.php';

echo "<h2>Recent Activity Logs (Last 10)</h2>";
echo "<style>table{border-collapse:collapse;width:100%} th,td{border:1px solid #ddd;padding:8px;text-align:left} th{background:#f2f2f2}</style>";

try {
    $stmt = $pdo->prepare("
        SELECT timestamp, level, message, details
        FROM activity_logs 
        ORDER BY timestamp DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($logs) {
        echo "<table>";
        echo "<tr><th>Timestamp</th><th>Level</th><th>Message</th><th>Details</th></tr>";
        
        foreach ($logs as $log) {
            $bgColor = '';
            if ($log['level'] === 'error') $bgColor = 'background-color: #ffebee;';
            if ($log['level'] === 'info') $bgColor = 'background-color: #e8f5e8;';
            if ($log['level'] === 'debug') $bgColor = 'background-color: #fff3e0;';
            
            echo "<tr style='$bgColor'>";
            echo "<td>" . htmlspecialchars($log['timestamp']) . "</td>";
            echo "<td>" . htmlspecialchars($log['level']) . "</td>";
            echo "<td>" . htmlspecialchars($log['message']) . "</td>";
            echo "<td>" . htmlspecialchars(substr($log['details'] ?? '', 0, 200)) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No logs found.</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Also test if the functions are updated
echo "<hr><h2>Function Test</h2>";
echo "<p><strong>Testing if updated validateJwsSignature function is loaded...</strong></p>";

// Check if the function contains our fix
$reflection = new ReflectionFunction('validateJwsSignature');
$source = file($reflection->getFileName());
$functionSource = '';
for ($i = $reflection->getStartLine() - 1; $i < $reflection->getEndLine(); $i++) {
    $functionSource .= $source[$i];
}

if (strpos($functionSource, 'EMPTY body for JWS validation') !== false) {
    echo "<p style='color:green'>✅ UPDATED function is loaded (contains fix for empty body)</p>";
} else {
    echo "<p style='color:red'>❌ OLD function is still loaded (does not contain empty body fix)</p>";
    echo "<p><strong>You need to update your functions.php file!</strong></p>";
}
?>