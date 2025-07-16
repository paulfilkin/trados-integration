<?php
// API endpoint to provide statistics for the integration dashboard

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../includes/database.php';
require_once '../includes/functions.php';

try {
    $stats = getStats();
    $systemStatus = getSystemStatus();
    $dbInfo = getDatabaseInfo();
    
    $response = [
        'status' => 'success',
        'timestamp' => date('Y-m-d H:i:s'),
        'statistics' => [
            'total_instances' => $stats['total_instances'] ?? 0,
            'active_instances' => $stats['active_instances'] ?? 0,
            'pending_instances' => $stats['pending_instances'] ?? 0,
            'error_instances' => $stats['error_instances'] ?? 0,
            'recent_activity_24h' => $stats['recent_activity'] ?? 0
        ],
        'system_health' => [
            'database' => $systemStatus['database'],
            'jws_keys' => $systemStatus['jws_keys'],
            'webhook_endpoint' => $systemStatus['webhook_endpoint'],
            'overall_status' => ($systemStatus['database'] && $systemStatus['jws_keys']) ? 'healthy' : 'degraded'
        ],
        'database_info' => [
            'version' => $dbInfo['version'] ?? 'unknown',
            'size_mb' => $dbInfo['size_mb'] ?? 0,
            'table_counts' => $dbInfo['table_counts'] ?? []
        ],
        'integration_info' => [
            'automatic_provisioning' => true,
            'jws_authentication' => true,
            'webhook_processing' => true,
            'real_time_logging' => true
        ]
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to retrieve statistics',
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
}
?>