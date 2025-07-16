<?php
// API endpoint to get webhook events for an instance

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../includes/database.php';

try {
    $instanceId = $_GET['id'] ?? null;
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    
    if (!$instanceId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Instance ID required']);
        exit;
    }
    
    $stmt = $pdo->prepare("
        SELECT id, event_type, timestamp, processed, processing_result
        FROM webhook_events 
        WHERE instance_id = ?
        ORDER BY timestamp DESC 
        LIMIT ?
    ");
    $stmt->execute([$instanceId, $limit]);
    $events = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'events' => $events,
        'instanceId' => $instanceId,
        'count' => count($events)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve webhook events'
    ]);
}
?>