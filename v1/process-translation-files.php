<?php
// process-translation-files.php - API to process files and create Trados project

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
require_once '../translation-files-processor.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $instanceId = $input['instanceId'] ?? null;
    $templateId = $input['templateId'] ?? null;

    if (!$instanceId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Instance ID required']);
        exit;
    }
    
    // Verify instance exists and is active
    $stmt = $pdo->prepare("
        SELECT instance_id FROM integration_controls 
        WHERE instance_id = ? AND status = 'active'
    ");
    $stmt->execute([$instanceId]);
    
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Instance not found or inactive']);
        exit;
    }
    
    logActivity('info', 'Starting translation files processing', [
        'instance_id' => $instanceId,
        'template_id' => $templateId ?: 'none (manual configuration)'
    ], $instanceId);
    
    // Process the translation files
    $result = processTranslationFilesForInstance($instanceId, $templateId);
    
    if ($result['success']) {
        logActivity('success', 'Translation files processed successfully', [
            'project_id' => $result['project']['id'] ?? 'unknown',
            'files_uploaded' => $result['files_uploaded'] ?? 0
        ], $instanceId);
    } else {
        logActivity('error', 'Translation files processing failed', $result['message'], $instanceId);
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    logActivity('error', 'Error in translation files processing', $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}
?>