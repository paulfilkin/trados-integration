<?php
// Webhook endpoint to receive project and task events from Trados Cloud via your addon

require_once 'includes/database.php';
require_once 'includes/functions.php';

// Set content type and CORS headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Signature, X-Timestamp, X-Nonce, X-Source');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Get the raw request body
    $rawPayload = file_get_contents('php://input');
    
    // Log the incoming webhook request
    $requestData = [
        'method' => $_SERVER['REQUEST_METHOD'],
        'payload_size' => strlen($rawPayload),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    logActivity('info', 'Webhook event received from Trados addon', json_encode($requestData));
    
    // Parse the JSON payload
    $payload = json_decode($rawPayload, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logActivity('error', 'Invalid JSON in webhook payload', 'JSON Error: ' . json_last_error_msg());
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload']);
        exit;
    }
    
    // Validate HMAC signature
    $headers = getallheaders();
    $signature = $headers['X-Signature'] ?? $headers['x-signature'] ?? null;
    $timestamp = $headers['X-Timestamp'] ?? $headers['x-timestamp'] ?? null;
    $nonce = $headers['X-Nonce'] ?? $headers['x-nonce'] ?? null;
    $source = $headers['X-Source'] ?? $headers['x-source'] ?? 'unknown';
    
    if (!$signature || !$timestamp || !$nonce) {
        logActivity('error', 'Missing HMAC headers in webhook', 'Required: X-Signature, X-Timestamp, X-Nonce');
        http_response_code(401);
        echo json_encode(['error' => 'Missing authentication headers']);
        exit;
    }
    
    // Get HMAC secret from your generated API key
    $hmacSecret = getHmacSecretKey();
    if (!$hmacSecret) {
        logActivity('error', 'No API key configured for webhook validation');
        http_response_code(500);
        echo json_encode(['error' => 'Server configuration error']);
        exit;
    }
    
    // Validate the signature
    if (!validateHmacSignature($rawPayload, $signature, $timestamp, $nonce, $hmacSecret)) {
        logActivity('error', 'Invalid HMAC signature for webhook', 
                   "Source: $source, Signature validation failed");
        http_response_code(401);
        echo json_encode(['error' => 'Invalid authentication signature']);
        exit;
    }
    
    logActivity('success', 'Webhook HMAC signature validated successfully');
    
    // Extract event data
    $tenantId = $payload['tenantId'] ?? null;
    $eventType = $payload['eventType'] ?? null;
    $timestamp = $payload['timestamp'] ?? date('Y-m-d H:i:s');
    $data = $payload['data'] ?? null;
    $originalPayload = $payload['originalPayload'] ?? null;
    
    // Validate required fields
    if (!$tenantId || !$eventType) {
        logActivity('error', 'Missing required fields in webhook payload', 
                   "Tenant: $tenantId, Event: $eventType");
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: tenantId, eventType']);
        exit;
    }
    
    // Find the integration instance for this tenant
    $instance = getInstanceByTenantId($tenantId);
    if (!$instance) {
        logActivity('warning', 'Webhook received for unknown tenant', 
                   "Tenant: $tenantId, Event: $eventType");
        
        // This might be normal if the instance was removed but webhooks are still being sent
        // Return success to avoid webhook retries
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Webhook received but no active instance found',
            'tenantId' => $tenantId
        ]);
        exit;
    }
    
    $instanceId = $instance['instance_id'];
    
    logActivity('info', 'Processing webhook event', 
               "Tenant: $tenantId, Event: $eventType, Instance: $instanceId", $instanceId);
    
    // Store the webhook event in database
    $eventId = storeWebhookEvent($instanceId, $eventType, $payload, $headers);
    
    // Process the webhook based on event type
    $processingResult = processWebhookEvent($instanceId, $eventType, $data, $originalPayload);
    
    // Update instance activity
    updateInstanceLastActivity($instanceId);
    
    // Prepare response
    $response = [
        'success' => true,
        'eventId' => $eventId,
        'instanceId' => $instanceId,
        'tenantId' => $tenantId,
        'eventType' => $eventType,
        'processed' => $processingResult['success'],
        'message' => $processingResult['message'],
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    logActivity('success', 'Webhook processed successfully', 
               "Event: $eventType, Instance: $instanceId", $instanceId);
    
    // Return success response
    http_response_code(200);
    echo json_encode($response);
    
} catch (Exception $e) {
    logActivity('error', 'Unexpected error processing webhook', 
               "Error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => 'An unexpected error occurred while processing the webhook'
    ]);
}

// Helper functions for webhook processing

function processWebhookEvent($instanceId, $eventType, $data, $originalPayload) {
    try {
        switch ($eventType) {
            case 'PROJECT.CREATED':
                return processProjectCreated($instanceId, $data);
                
            case 'PROJECT.DELETED':
                return processProjectDeleted($instanceId, $data);
                
            case 'PROJECT.TASK.CREATED':
                return processTaskCreated($instanceId, $data);
                
            case 'PROJECT.TASK.ACCEPTED':
                return processTaskAccepted($instanceId, $data);
                
            case 'PROJECT.TASK.COMPLETED':
                return processTaskCompleted($instanceId, $data);
                
            case 'PROJECT.TASK.STARTED':
                return processTaskStarted($instanceId, $data);
                
            case 'PROJECT.TASK.FINISHED':
                return processTaskFinished($instanceId, $data);
                
            case 'PROJECT.ERROR.TASK.ACCEPTED':
            case 'PROJECT.ERROR.TASK.COMPLETED':
                return processErrorTask($instanceId, $data);
                
            default:
                logActivity('info', "Unknown webhook event type: $eventType", 
                           json_encode($data), $instanceId);
                return [
                    'success' => true,
                    'message' => 'Event received but no specific processing implemented'
                ];
        }
    } catch (Exception $e) {
        logActivity('error', "Error processing webhook event: $eventType", 
                   $e->getMessage(), $instanceId);
        return [
            'success' => false,
            'message' => 'Error processing event: ' . $e->getMessage()
        ];
    }
}

function processProjectCreated($instanceId, $data) {
    logActivity('info', 'Processing PROJECT.CREATED event', 
               json_encode($data), $instanceId);
    
    // YOUR CUSTOM LOGIC HERE
    // Example: Send notification to your team, update project management system, etc.
    
    return [
        'success' => true,
        'message' => 'Project creation event processed'
    ];
}

function processProjectDeleted($instanceId, $data) {
    logActivity('info', 'Processing PROJECT.DELETED event', 
               json_encode($data), $instanceId);
    
    // YOUR CUSTOM LOGIC HERE
    
    return [
        'success' => true,
        'message' => 'Project deletion event processed'
    ];
}

function processTaskCreated($instanceId, $data) {
    logActivity('info', 'Processing PROJECT.TASK.CREATED event', 
               json_encode($data), $instanceId);
    
    // YOUR CUSTOM LOGIC HERE
    
    return [
        'success' => true,
        'message' => 'Task creation event processed'
    ];
}

function processTaskAccepted($instanceId, $data) {
    logActivity('info', 'Processing PROJECT.TASK.ACCEPTED event', 
               json_encode($data), $instanceId);
    
    // YOUR CUSTOM LOGIC HERE
    
    return [
        'success' => true,
        'message' => 'Task acceptance event processed'
    ];
}

function processTaskCompleted($instanceId, $data) {
    logActivity('success', 'Processing PROJECT.TASK.COMPLETED event', 
               json_encode($data), $instanceId);
    
    // THIS IS WHERE YOUR MAIN DEPLOYMENT LOGIC GOES!
    // Examples:
    // - Download completed files from Trados Cloud
    // - Trigger your deployment pipeline
    // - Send notifications to stakeholders
    // - Update project status in your system
    
    return [
        'success' => true,
        'message' => 'Task completion event processed - deployment logic executed'
    ];
}

function processTaskStarted($instanceId, $data) {
    logActivity('info', 'Processing PROJECT.TASK.STARTED event', 
               json_encode($data), $instanceId);
    
    // YOUR CUSTOM LOGIC HERE
    
    return [
        'success' => true,
        'message' => 'Task start event processed'
    ];
}

function processTaskFinished($instanceId, $data) {
    logActivity('info', 'Processing PROJECT.TASK.FINISHED event', 
               json_encode($data), $instanceId);
    
    // YOUR CUSTOM LOGIC HERE
    
    return [
        'success' => true,
        'message' => 'Task finish event processed'
    ];
}

function processErrorTask($instanceId, $data) {
    logActivity('warning', 'Processing error task event', 
               json_encode($data), $instanceId);
    
    // YOUR CUSTOM ERROR HANDLING LOGIC HERE
    
    return [
        'success' => true,
        'message' => 'Error task event processed'
    ];
}

function updateInstanceLastActivity($instanceId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE integration_controls 
            SET last_activity = CURRENT_TIMESTAMP 
            WHERE instance_id = ?
        ");
        $stmt->execute([$instanceId]);
        return true;
    } catch (PDOException $e) {
        error_log("Error updating instance activity: " . $e->getMessage());
        return false;
    }
}

function storeWebhookEvent($instanceId, $eventType, $payload, $headers) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO webhook_events 
            (instance_id, event_type, payload, headers, timestamp, processed)
            VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, 1)
        ");
        
        $stmt->execute([
            $instanceId,
            $eventType,
            json_encode($payload),
            json_encode($headers)
        ]);
        
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("Error storing webhook event: " . $e->getMessage());
        return false;
    }
}

function getInstanceByTenantId($tenantId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM integration_controls 
            WHERE tenant_id = ? AND status != 'inactive'
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching instance by tenant ID: " . $e->getMessage());
        return false;
    }
}
?>