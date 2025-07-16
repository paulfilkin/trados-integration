<?php
// get-instances.php - API to get available integration instances

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../includes/database.php';
require_once '../includes/functions.php';

try {
    // Add some debugging
    logActivity('info', 'get-instances.php called');
    
    $stmt = $pdo->prepare("
        SELECT instance_id, tenant_id, status, created_at, last_activity
        FROM integration_controls 
        WHERE status = 'active'
        ORDER BY created_at DESC
    ");
    $stmt->execute();
    $instances = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    logActivity('info', 'Found instances', 'Count: ' . count($instances));
    
    echo json_encode([
        'success' => true,
        'instances' => $instances,
        'count' => count($instances),
        'debug' => 'Query executed successfully'
    ]);
    
} catch (Exception $e) {
    logActivity('error', 'get-instances.php error', $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve instances',
        'debug' => $e->getMessage()
    ]);
}
?>