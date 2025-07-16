<?php
// get-recent-webhook-events.php - API to get recent webhook events

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../includes/database.php';
require_once '../includes/functions.php';

try {
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    
    // Get recent activity logs (which include webhook events)
    $stmt = $pdo->prepare("
        SELECT timestamp, level, message, details, instance_id
        FROM activity_logs 
        WHERE message LIKE '%webhook%' OR message LIKE '%project%' OR message LIKE '%task%'
        ORDER BY timestamp DESC 
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Also get webhook events from the webhook_events table
    $stmt = $pdo->prepare("
        SELECT event_type as message, timestamp, 'info' as level, 
               'Webhook: ' || event_type as details, instance_id
        FROM webhook_events 
        ORDER BY timestamp DESC 
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $webhookEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Combine and sort events
    $allEvents = array_merge($events, $webhookEvents);
    
    // Sort by timestamp (newest first)
    usort($allEvents, function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });
    
    // Limit the results
    $allEvents = array_slice($allEvents, 0, $limit);
    
    echo json_encode([
        'success' => true,
        'events' => $allEvents,
        'count' => count($allEvents)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve webhook events'
    ]);
}
?>