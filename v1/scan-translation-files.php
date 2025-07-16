<?php
// scan-translation-files.php - API to scan translationfiles directory

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
    
    // Scan the translationfiles directory
    $translationFilesDir = dirname(__DIR__) . '/translationfiles';
    $files = [];
    
    // Create directory if it doesn't exist
    if (!is_dir($translationFilesDir)) {
        mkdir($translationFilesDir, 0755, true);
        logActivity('info', 'Created translationfiles directory', $translationFilesDir, $instanceId);
    }
    
    if (is_dir($translationFilesDir)) {
        $allowedExtensions = ['txt', 'docx', 'xlsx', 'pptx', 'html', 'xml', 'json', 'csv'];
        
        $iterator = new DirectoryIterator($translationFilesDir);
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDot() || $fileInfo->isDir()) {
                continue;
            }
            
            $filename = $fileInfo->getFilename();
            $extension = strtolower($fileInfo->getExtension());
            
            // Skip hidden files and check allowed extensions
            if (substr($filename, 0, 1) === '.' || !in_array($extension, $allowedExtensions)) {
                continue;
            }
            
            $files[] = [
                'name' => $filename,
                'size' => $fileInfo->getSize(),
                'extension' => $extension,
                'modified' => date('Y-m-d H:i:s', $fileInfo->getMTime()),
                'path' => $fileInfo->getPathname()
            ];
        }
    }
    
    logActivity('info', 'Scanned translationfiles directory', [
        'files_found' => count($files),
        'directory' => $translationFilesDir
    ], $instanceId);
    
    echo json_encode([
        'success' => true,
        'files' => $files,
        'count' => count($files),
        'directory' => $translationFilesDir
    ]);
    
} catch (Exception $e) {
    logActivity('error', 'Error scanning translation files', $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to scan translation files'
    ]);
}
?>