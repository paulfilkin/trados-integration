<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    // Simple API call function
    function makeApiCall($endpoint, $method = 'GET', $data = null) {
        $url = 'https://api.eu.cloud.trados.com/public-api/v1' . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        return [
            'response' => $response,
            'httpCode' => $httpCode,
            'error' => $curlError,
            'data' => $response ? json_decode($response, true) : null
        ];
    }
    
    $results = [];
    
    // Test basic connectivity first
    $results['connectivity_test'] = [
        'api_base_url' => 'https://api.eu.cloud.trados.com/public-api/v1',
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // Try to get customers (this will fail without auth but show us the error)
    $customersResult = makeApiCall('/customers');
    $results['customers_test'] = [
        'httpCode' => $customersResult['httpCode'],
        'error' => $customersResult['error'],
        'response_preview' => substr($customersResult['response'], 0, 200)
    ];
    
    // Try to get project templates 
    $templatesResult = makeApiCall('/project-templates');
    $results['templates_test'] = [
        'httpCode' => $templatesResult['httpCode'],
        'error' => $templatesResult['error'],
        'response_preview' => substr($templatesResult['response'], 0, 200)
    ];
    
    // Try to get folders
    $foldersResult = makeApiCall('/folders');
    $results['folders_test'] = [
        'httpCode' => $foldersResult['httpCode'],
        'error' => $foldersResult['error'],
        'response_preview' => substr($foldersResult['response'], 0, 200)
    ];
    
    // Analysis
    $results['analysis'] = [
        'message' => 'This is a connectivity test without authentication',
        'expected_result' => 'HTTP 401 (Unauthorized) responses are normal',
        'next_steps' => [
            'Need to add authentication headers',
            'Check your existing working files for auth token',
            'Or provide auth credentials to complete the resource listing'
        ]
    ];
    
    // Location info we know
    $results['known_locations'] = [
        'your_target_location' => '6875209da02fda97ba600ae8',
        'template_location' => '654b099d0bd44756945fb6cae4713a75',
        'template_id' => '68752414e45edf18699728e1',
        'issue' => 'Location mismatch between template and target location'
    ];
    
    echo json_encode([
        'success' => true,
        'message' => 'Basic connectivity test completed',
        'timestamp' => date('Y-m-d H:i:s'),
        'data' => $results
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error: ' . $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
}
?>