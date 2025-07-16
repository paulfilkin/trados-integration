<?php
// /v1/webhooks.php endpoint - JWS-based webhook handler for Trados Deployment Addon

require_once '../includes/database.php';
require_once '../includes/functions.php';

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, x-lc-signature');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Handle GET requests (for endpoint verification during addon installation)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    logActivity('info', 'Webhook endpoint verification request received');
    http_response_code(200);
    echo json_encode([
        'status' => 'active',
        'message' => 'Trados webhook endpoint is ready',
        'endpoint' => '/trados-integration/v1/webhooks',
        'authentication' => 'JWS',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// Only allow POST requests for actual webhooks
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Get the raw request body
    $rawPayload = file_get_contents('php://input');
    
    // Log incoming request
    logActivity('info', 'Webhook received at /v1/webhooks', [
        'payload_size' => strlen($rawPayload),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
    ]);
    
    // Parse JSON payload
    $payload = json_decode($rawPayload, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logActivity('error', 'Invalid JSON payload', 'JSON Error: ' . json_last_error_msg());
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload']);
        exit;
    }
    
    // Validate JWS signature
    $headers = getallheaders();
    $jwsToken = $headers['x-lc-signature'] ?? $headers['X-LC-Signature'] ?? null;
    
    if (!$jwsToken) {
        logActivity('error', 'Missing JWS signature header');
        http_response_code(401);
        echo json_encode(['error' => 'Missing x-lc-signature header']);
        exit;
    }
    
    // Validate the JWS token
    if (!validateJwsSignature($jwsToken, $rawPayload)) {
        logActivity('error', 'Invalid JWS signature');
        http_response_code(401);
        echo json_encode(['error' => 'Invalid JWS signature']);
        exit;
    }
    
    logActivity('success', 'JWS signature validated successfully');
    
    // Process the webhook batch
    $processedEvents = 0;
    $fileDeliveryEvents = 0;
    $results = [];
    
    // Webhooks come in batches
    $events = $payload['events'] ?? [$payload]; // Handle both batch and single event formats
    
    foreach ($events as $event) {
        $processedEvents++;
        
        // Extract event data
        $eventType = $event['eventType'] ?? null;
        $accountId = $event['accountId'] ?? null;
        $data = $event['data'] ?? null;
        
        // Log each event
        logActivity('info', "Processing webhook event: $eventType", [
            'account_id' => $accountId,
            'event_type' => $eventType
        ]);
        
        // Filter for PROJECT.TASK.CREATED events only
        if ($eventType !== 'PROJECT.TASK.CREATED') {
            $results[] = [
                'eventType' => $eventType,
                'status' => 'skipped',
                'reason' => 'Not a PROJECT.TASK.CREATED event'
            ];
            continue;
        }
        
        // Check if this is a file-delivery task
        $taskType = $data['taskType']['key'] ?? null;
        
        // Filter for file-delivery tasks only
        if ($taskType !== 'file-delivery') {
            $results[] = [
                'eventType' => $eventType,
                'taskType' => $taskType,
                'status' => 'skipped',
                'reason' => 'Not a file-delivery task'
            ];
            continue;
        }
        
        // This is what we're looking for!
        $fileDeliveryEvents++;
        
        logActivity('success', 'File delivery task created!', [
            'project_id' => $data['projectId'] ?? 'unknown',
            'task_id' => $data['id'] ?? 'unknown',
            'task_type' => $taskType,
            'account_id' => $accountId
        ]);
        
        // Store the webhook event
        storeWebhookEvent($accountId, $eventType, $event);
        
        // Process the file delivery task
        $processingResult = processFileDeliveryTask($accountId, $data);
        
        $results[] = [
            'eventType' => $eventType,
            'taskType' => $taskType,
            'status' => $processingResult['success'] ? 'processed' : 'failed',
            'message' => $processingResult['message'],
            'projectId' => $data['projectId'] ?? null,
            'taskId' => $data['id'] ?? null
        ];
    }
    
    // Prepare response
    $response = [
        'success' => true,
        'message' => 'Webhook batch processed by Trados Deployment Addon',
        'summary' => [
            'total_events' => $processedEvents,
            'file_delivery_events' => $fileDeliveryEvents,
            'processed_at' => date('Y-m-d H:i:s')
        ],
        'results' => $results
    ];
    
    logActivity('success', "Webhook batch completed", [
        'total_events' => $processedEvents,
        'file_delivery_events' => $fileDeliveryEvents
    ]);
    
    // Return success response
    http_response_code(200);
    echo json_encode($response);
    
} catch (Exception $e) {
    logActivity('error', 'Webhook processing error', $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => 'Failed to process webhook'
    ]);
}

// Helper function to process file delivery tasks
function processFileDeliveryTask($accountId, $taskData) {
    try {
        $projectId = $taskData['projectId'] ?? 'unknown';
        $taskId = $taskData['id'] ?? 'unknown';
        $taskName = $taskData['name'] ?? 'Unknown Task';
        
        logActivity('info', "Processing file delivery task", [
            'account_id' => $accountId,
            'project_id' => $projectId,
            'task_id' => $taskId,
            'task_name' => $taskName
        ]);
        
        // HERE IS WHERE YOUR DEPLOYMENT LOGIC GOES!
        // This is where you can integrate with your existing deployment system
        
        // Get the user's API key for this integration
        $apiKey = getUserApiKey($accountId);
        
        // Your custom deployment logic here:
        // - Download files from Trados Cloud using your API credentials
        // - Trigger your existing deployment pipeline
        // - Send notifications to your team
        // - Update external systems
        
        logActivity('success', "File delivery task processed successfully", [
            'project_id' => $projectId,
            'task_id' => $taskId,
            'has_api_key' => !empty($apiKey)
        ]);
        
        return [
            'success' => true,
            'message' => 'File delivery task processed - deployment logic executed'
        ];
        
    } catch (Exception $e) {
        logActivity('error', "Error processing file delivery task", [
            'error' => $e->getMessage(),
            'project_id' => $projectId ?? 'unknown',
            'task_id' => $taskId ?? 'unknown'
        ]);
        
        return [
            'success' => false,
            'message' => 'Error processing file delivery task: ' . $e->getMessage()
        ];
    }
}

function getUserApiKey($accountId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT configuration_data FROM integration_controls 
            WHERE tenant_id = ? AND status = 'active'
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$accountId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['configuration_data']) {
            $config = json_decode($result['configuration_data'], true);
            return $config['API_KEY'] ?? null;
        }
        
        return null;
    } catch (PDOException $e) {
        error_log("Error getting user API key: " . $e->getMessage());
        return null;
    }
}

function storeWebhookEvent($accountId, $eventType, $eventData) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO webhook_events 
            (instance_id, event_type, payload, timestamp, processed, source, task_type)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP, 1, 'trados-cloud', ?)
        ");
        
        $taskType = $eventData['data']['taskType']['key'] ?? null;
        
        $stmt->execute([
            $accountId,
            $eventType,
            json_encode($eventData),
            $taskType
        ]);
        
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("Error storing webhook event: " . $e->getMessage());
        return false;
    }
}
?>