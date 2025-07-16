<?php


// Add this to the top of your provision-instance.php file
// to capture the exact request body being sent

error_log("=== REQUEST BODY CAPTURE ===");
error_log("Method: " . $_SERVER['REQUEST_METHOD']);
error_log("Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'none'));
error_log("Content-Length: " . ($_SERVER['CONTENT_LENGTH'] ?? '0'));

// Capture the raw request body
$rawBody = file_get_contents('php://input');
error_log("Raw body length: " . strlen($rawBody));
error_log("Raw body content: " . ($rawBody ?: '[EMPTY]'));

// Log all headers
error_log("=== ALL HEADERS ===");
foreach (getallheaders() as $name => $value) {
    error_log("Header $name: $value");
}

// Calculate what the hash should be
$bodyHash = base64_encode(hash('sha256', $rawBody, true));
$bodyHashUrl = rtrim(strtr($bodyHash, '+/', '-_'), '=');
error_log("Body SHA256 hash (base64): $bodyHash");
error_log("Body SHA256 hash (base64url): $bodyHashUrl");

// If it's empty
if (strlen($rawBody) === 0) {
    $emptyHash = base64_encode(hash('sha256', '', true));
    $emptyHashUrl = rtrim(strtr($emptyHash, '+/', '-_'), '=');
    error_log("Empty body hash (base64): $emptyHash");
    error_log("Empty body hash (base64url): $emptyHashUrl");
    error_log("Expected empty constant: 47DEQpj8HBSa-_TImW-5JCeuQeRkm5NMpJWZG3hSuFU");
}

error_log("=== END CAPTURE ===");


// Simplified Trados integration provisioning endpoint (JWS-based)

require_once 'includes/database.php';
require_once 'includes/functions.php';

// Set content type and CORS headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, x-lc-signature');

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
    
    // Log the incoming request
    logActivity('info', 'Provisioning request received', [
        'payload_size' => strlen($rawPayload),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
    ]);
    
    // Parse the JSON payload
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
        logActivity('error', 'Invalid JWS signature for provisioning');
        http_response_code(401);
        echo json_encode(['error' => 'Invalid JWS signature']);
        exit;
    }
    
    logActivity('success', 'JWS signature validated for provisioning');
    
    // Extract required data from payload
    $accountId = $payload['accountId'] ?? null;
    $clientCredentials = $payload['clientCredentials'] ?? null;
    $configurationData = $payload['configurationData'] ?? null;
    $eventType = $payload['eventType'] ?? 'INSTALLED';
    
    // Validate required fields
    if (!$accountId) {
        logActivity('error', 'Missing account ID in payload');
        http_response_code(400);
        echo json_encode(['error' => 'Missing account ID']);
        exit;
    }
    
    if (!$clientCredentials || !isset($clientCredentials['clientId']) || !isset($clientCredentials['clientSecret'])) {
        logActivity('error', 'Missing or invalid client credentials', "Account: $accountId");
        http_response_code(400);
        echo json_encode(['error' => 'Missing or invalid client credentials']);
        exit;
    }
    
    $clientId = $clientCredentials['clientId'];
    $clientSecret = $clientCredentials['clientSecret'];
    
    // Generate unique instance ID
    $instanceId = generateInstanceId($accountId);
    
    logActivity('info', 'Processing provisioning request', [
        'account_id' => $accountId,
        'client_id' => $clientId,
        'event_type' => $eventType,
        'instance_id' => $instanceId
    ]);
    
    // Handle different event types
    switch ($eventType) {
        case 'INSTALLED':
            // Check if instance already exists for this account
            $existingInstance = getInstanceByAccountId($accountId);
            if ($existingInstance) {
                // Update existing instance
                $result = updateExistingInstance($existingInstance['instance_id'], $clientId, $clientSecret, $configurationData);
                $instanceId = $existingInstance['instance_id'];
                logActivity('info', 'Updated existing instance', "Instance: $instanceId");
            } else {
                // Create new instance
                $result = createIntegrationControl($instanceId, $accountId, $clientId, $clientSecret, $configurationData);
                logActivity('success', 'Created new Integration Control instance', [
                    'instance_id' => $instanceId,
                    'account_id' => $accountId
                ]);
            }
            break;
            
        case 'UNINSTALLED':
            $existingInstance = getInstanceByAccountId($accountId);
            if ($existingInstance) {
                $result = deactivateInstance($existingInstance['instance_id']);
                $instanceId = $existingInstance['instance_id'];
                logActivity('info', 'Instance deactivated for uninstall', "Instance: $instanceId");
            } else {
                logActivity('warning', 'Uninstall requested for non-existent instance', "Account: $accountId");
                $result = true; // Not an error if instance doesn't exist
            }
            break;
            
        case 'UPDATED':
            $existingInstance = getInstanceByAccountId($accountId);
            if ($existingInstance) {
                $result = updateExistingInstance($existingInstance['instance_id'], $clientId, $clientSecret, $configurationData);
                $instanceId = $existingInstance['instance_id'];
                logActivity('info', 'Instance updated', "Instance: $instanceId");
            } else {
                logActivity('error', 'Update requested for non-existent instance', "Account: $accountId");
                http_response_code(404);
                echo json_encode(['error' => 'Instance not found for update']);
                exit;
            }
            break;
            
        default:
            logActivity('warning', 'Unknown event type received', "Event: $eventType, Account: $accountId");
            $result = true; // Don't fail on unknown events
            break;
    }
    
    if (!$result) {
        logActivity('error', 'Failed to process provisioning request', [
            'event_type' => $eventType,
            'account_id' => $accountId,
            'instance_id' => $instanceId
        ]);
        http_response_code(500);
        echo json_encode(['error' => 'Failed to process request']);
        exit;
    }
    
    // Store provisioning event for tracking
    storeProvisioningEvent($instanceId, $eventType, $payload);
    
    // Prepare response
    $response = [
        'success' => true,
        'instanceId' => $instanceId,
        'accountId' => $accountId,
        'eventType' => $eventType,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Integration provisioning processed successfully',
        'webhookEndpoint' => 'https://api.filkin.com/trados-integration/v1/webhooks'
    ];
    
    logActivity('success', 'Provisioning completed successfully', [
        'instance_id' => $instanceId,
        'event_type' => $eventType
    ]);
    
    // Return success response
    http_response_code(200);
    echo json_encode($response);
    
} catch (Exception $e) {
    logActivity('error', 'Unexpected error in provisioning', $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => 'An unexpected error occurred while processing the request'
    ]);
}

// Helper functions specific to provisioning

function getInstanceByAccountId($accountId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM integration_controls 
            WHERE tenant_id = ? AND status != 'inactive'
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$accountId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching instance by account ID: " . $e->getMessage());
        return false;
    }
}

function updateExistingInstance($instanceId, $clientId, $clientSecret, $configData = null) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE integration_controls 
            SET client_id = ?, client_secret = ?, configuration_data = ?, 
                last_activity = CURRENT_TIMESTAMP, status = 'active'
            WHERE instance_id = ?
        ");
        
        $result = $stmt->execute([
            $clientId,
            $clientSecret,
            $configData ? json_encode($configData) : null,
            $instanceId
        ]);
        
        if ($result) {
            logActivity('info', 'Instance credentials updated', [
                'instance_id' => $instanceId,
                'client_id' => $clientId
            ], $instanceId);
        }
        
        return $result;
    } catch (PDOException $e) {
        error_log("Error updating existing instance: " . $e->getMessage());
        logActivity('error', 'Failed to update instance', $e->getMessage(), $instanceId);
        return false;
    }
}

function deactivateInstance($instanceId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE integration_controls 
            SET status = 'inactive', last_activity = CURRENT_TIMESTAMP
            WHERE instance_id = ?
        ");
        
        $result = $stmt->execute([$instanceId]);
        
        if ($result) {
            logActivity('info', 'Instance deactivated', null, $instanceId);
        }
        
        return $result;
    } catch (PDOException $e) {
        error_log("Error deactivating instance: " . $e->getMessage());
        logActivity('error', 'Failed to deactivate instance', $e->getMessage(), $instanceId);
        return false;
    }
}

function storeProvisioningEvent($instanceId, $eventType, $payload) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO webhook_events 
            (instance_id, event_type, payload, timestamp, processed, source)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP, 1, 'provisioning')
        ");
        
        $stmt->execute([
            $instanceId,
            $eventType,
            json_encode($payload)
        ]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Error storing provisioning event: " . $e->getMessage());
        return false;
    }
}
?>