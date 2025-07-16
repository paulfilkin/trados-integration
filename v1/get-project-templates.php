<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!isset($data['instanceId'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'instanceId is required']);
    exit;
}

$instanceId = $data['instanceId'];

try {
    require_once '../includes/database.php';
    require_once '../includes/functions.php';
    
    $stmt = $pdo->prepare("SELECT * FROM integration_controls WHERE instance_id = ?");
    $stmt->execute([$instanceId]);
    $instance = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$instance) {
        echo json_encode(['success' => false, 'error' => 'Instance not found']);
        exit;
    }
    
    $accessToken = getAuth0AccessToken($instance['client_id'], $instance['client_secret']);
    
    if (!$accessToken) {
        echo json_encode(['success' => false, 'error' => 'Failed to authenticate with Auth0']);
        exit;
    }
    
    $tradosApiUrl = 'https://api.eu.cloud.trados.com/public-api/v1/project-templates';
    $queryParams = '?fields=id,name,description,languageDirections,location,settings,workflow,pricingModel,translationEngine';
    
    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
        'X-LC-Tenant: ' . $instance['tenant_id']
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $tradosApiUrl . $queryParams,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'TradosIntegration/1.0'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        logActivity('error', 'cURL error in get-project-templates', $curlError, $instanceId);
        echo json_encode(['success' => false, 'error' => 'cURL error: ' . $curlError]);
        exit;
    }
    
    if ($httpCode !== 200) {
        logActivity('error', 'Trados API error in get-project-templates', "HTTP $httpCode: $response", $instanceId);
        echo json_encode(['success' => false, 'error' => 'Trados API error: HTTP ' . $httpCode]);
        exit;
    }
    
    $tradosResponse = json_decode($response, true);
    
    if (!$tradosResponse) {
        logActivity('error', 'Failed to parse Trados API response', $response, $instanceId);
        echo json_encode(['success' => false, 'error' => 'Failed to parse Trados API response']);
        exit;
    }
    
    if (isset($tradosResponse['items'])) {
        $templates = $tradosResponse['items'];
    } else {
        $templates = is_array($tradosResponse) ? $tradosResponse : [];
    }
    
    $formattedTemplates = [];
    foreach ($templates as $template) {
        $formattedTemplates[] = [
            'id' => $template['id'] ?? '',
            'name' => $template['name'] ?? 'Unnamed Template',
            'description' => $template['description'] ?? '',
            'languageDirections' => $template['languageDirections'] ?? [],
            'location' => $template['location'] ?? null,
            'rawData' => $template
        ];
    }
    
    logActivity('success', 'Project templates retrieved successfully', 
               count($formattedTemplates) . ' templates found', $instanceId);
    
    echo json_encode([
        'success' => true,
        'templates' => $formattedTemplates,
        'count' => count($formattedTemplates)
    ]);
    
} catch (Exception $e) {
    logActivity('error', 'Error in get-project-templates.php', $e->getMessage(), $instanceId ?? null);
    echo json_encode(['success' => false, 'error' => 'Internal server error: ' . $e->getMessage()]);
}

function getAuth0AccessToken($clientId, $clientSecret) {
    try {
        $auth0Domain = 'https://sdl-prod.eu.auth0.com';
        $tokenUrl = $auth0Domain . '/oauth/token';
        
        $postData = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'audience' => 'https://api.sdl.com',
            'grant_type' => 'client_credentials'
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $tokenUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'TradosIntegration/1.0'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            logActivity('error', 'Auth0 cURL error', $curlError);
            return false;
        }
        
        if ($httpCode !== 200) {
            logActivity('error', 'Auth0 authentication failed', "HTTP $httpCode: $response");
            return false;
        }
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['access_token'])) {
            logActivity('error', 'Invalid Auth0 response', $response);
            return false;
        }
        
        return $data['access_token'];
        
    } catch (Exception $e) {
        logActivity('error', 'Auth0 authentication error', $e->getMessage());
        return false;
    }
}
?>