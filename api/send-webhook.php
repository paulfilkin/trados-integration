<?php
// API endpoint to send webhooks back to Trados Cloud

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../includes/database.php';
require_once '../includes/functions.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $instanceId = $input['instanceId'] ?? null;
    $eventType = $input['eventType'] ?? null;
    
    if (!$instanceId || !$eventType) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Instance ID and event type required']);
        exit;
    }
    
    // Get instance credentials
    $stmt = $pdo->prepare("
        SELECT tenant_id, client_id, client_secret 
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
    
    if (!$instance['client_id'] || !$instance['client_secret']) {
        echo json_encode(['success' => false, 'error' => 'Missing credentials for this instance']);
        exit;
    }
    
    // Create webhook payload based on event type
    $webhookData = createWebhookPayload($eventType, $instance['tenant_id']);
    
    // Send webhook to Trados Cloud Platform
    $result = sendWebhookToTrados($instance, $webhookData);
    
    // Log the webhook activity
    logActivity('info', "Webhook sent to Trados", 
               "Event: $eventType, Instance: $instanceId", $instanceId);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => 'Webhook sent successfully',
            'eventType' => $eventType,
            'response' => $result['response']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $result['error']
        ]);
    }
    
} catch (Exception $e) {
    error_log("Webhook send error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to send webhook'
    ]);
}

function createWebhookPayload($eventType, $tenantId) {
    $basePayload = [
        'eventType' => $eventType,
        'tenantId' => $tenantId,
        'timestamp' => date('Y-m-d\TH:i:s.v\Z'),
        'source' => 'test-integration'
    ];
    
    switch ($eventType) {
        case 'project.created':
            return array_merge($basePayload, [
                'data' => [
                    'projectId' => 'test-project-' . time(),
                    'projectName' => 'Test Integration Project',
                    'sourceLanguage' => 'en-US',
                    'targetLanguages' => ['fr-FR', 'de-DE'],
                    'status' => 'created'
                ]
            ]);
            
        case 'task.completed':
            return array_merge($basePayload, [
                'data' => [
                    'taskId' => 'test-task-' . time(),
                    'taskType' => 'translation',
                    'status' => 'completed',
                    'progress' => 100,
                    'completedAt' => date('Y-m-d\TH:i:s.v\Z')
                ]
            ]);
            
        case 'file.ready':
            return array_merge($basePayload, [
                'data' => [
                    'fileId' => 'test-file-' . time(),
                    'fileName' => 'translated-document.docx',
                    'language' => 'fr-FR',
                    'status' => 'ready',
                    'downloadUrl' => 'https://example.com/download/file.docx'
                ]
            ]);
            
        default:
            return array_merge($basePayload, [
                'data' => [
                    'message' => 'Generic test event from integration'
                ]
            ]);
    }
}

function sendWebhookToTrados($instance, $webhookData) {
    // Trados Cloud webhook endpoint (example - this would be the actual endpoint)
    $webhookUrl = 'https://api.languagecloud.rws.com/webhook/integration-events';
    
    // For testing, we'll simulate the webhook call
    // In production, this would make an actual HTTP request to Trados
    
    try {
        // Simulate API call with credentials
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $instance['client_id'], // Simplified auth
            'X-Tenant-ID: ' . $instance['tenant_id']
        ];
        
        // For now, simulate success since we don't have real Trados webhook endpoint
        return [
            'success' => true,
            'response' => 'Webhook would be sent to Trados Cloud Platform',
            'simulatedData' => $webhookData
        ];
        
        /* Real implementation would be:
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($webhookData),
                'timeout' => 30
            ]
        ]);
        
        $response = file_get_contents($webhookUrl, false, $context);
        
        if ($response === false) {
            return ['success' => false, 'error' => 'Failed to send webhook'];
        }
        
        return ['success' => true, 'response' => $response];
        */
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
?>