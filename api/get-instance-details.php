<?php
// API endpoint to get detailed instance information

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../includes/database.php';

try {
    $instanceId = $_GET['id'] ?? null;
    
    if (!$instanceId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Instance ID required']);
        exit;
    }
    
    $stmt = $pdo->prepare("
        SELECT instance_id, tenant_id, client_id, client_secret, status, 
               created_at, last_activity, configuration_data
        FROM integration_controls 
        WHERE instance_id = ?
    ");
    $stmt->execute([$instanceId]);
    $instance = $stmt->fetch();
    
    if (!$instance) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Instance not found']);
        exit;
    }
    
    // Don't expose full client secret for security
    if ($instance['client_secret']) {
        $instance['client_secret_preview'] = substr($instance['client_secret'], 0, 10) . '...';
        $instance['has_client_secret'] = true;
        unset($instance['client_secret']); // Remove full secret from response
    } else {
        $instance['has_client_secret'] = false;
    }
    
    echo json_encode([
        'success' => true,
        'instance' => $instance
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve instance details'
    ]);
}
?>