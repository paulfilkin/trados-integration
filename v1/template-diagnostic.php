<?php
// v1/template-diagnostic.php - Comprehensive template access diagnostic

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

class TemplateDiagnostic {
    private $apiBaseUrl = 'https://api.eu.cloud.trados.com/public-api/v1';
    private $accessToken;
    private $tenantId;
    private $instanceId;
    private $results = [];
    
    public function __construct($instanceId) {
        $this->instanceId = $instanceId;
        $this->results['diagnostic_info'] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'instance_id' => $instanceId,
            'target_template_id' => '68752414e45edf18699728e1'
        ];
    }
    
    public function runDiagnostic() {
        try {
            // Step 1: Load instance credentials
            $this->loadInstanceCredentials();
            
            // Step 2: Test authentication
            $this->testAuthentication();
            
            // Step 3: Get all available templates
            $this->getAllTemplates();
            
            // Step 4: Test specific template access
            $this->testSpecificTemplate();
            
            // Step 5: Get available locations
            $this->getAvailableLocations();
            
            // Step 6: Test project creation without template
            $this->testProjectCreationWithoutTemplate();
            
            // Step 7: Analyze and provide recommendations
            $this->analyzeResults();
            
            return $this->results;
            
        } catch (Exception $e) {
            $this->results['error'] = [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
            return $this->results;
        }
    }
    
    private function loadInstanceCredentials() {
        global $pdo;
        
        $stmt = $pdo->prepare("SELECT * FROM integration_controls WHERE instance_id = ? AND status = 'active'");
        $stmt->execute([$this->instanceId]);
        $instance = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$instance) {
            throw new Exception("Instance not found or inactive: {$this->instanceId}");
        }
        
        $this->tenantId = $instance['tenant_id'];
        $this->results['credentials'] = [
            'tenant_id' => $this->tenantId,
            'has_client_id' => !empty($instance['client_id']),
            'has_client_secret' => !empty($instance['client_secret']),
            'status' => $instance['status']
        ];
        
        // Get access token
        $tokenData = $this->getAuth0AccessToken($instance['client_id'], $instance['client_secret']);
        $this->accessToken = $tokenData['access_token'];
        $this->results['authentication'] = [
            'token_obtained' => true,
            'token_type' => $tokenData['token_type'] ?? 'bearer',
            'expires_in' => $tokenData['expires_in'] ?? 'unknown'
        ];
    }
    
    private function testAuthentication() {
        // Test with a simple API call
        $response = $this->makeApiCall('/customers', 'GET');
        
        $this->results['auth_test'] = [
            'success' => !isset($response['error']),
            'http_code' => $response['http_code'] ?? 'unknown',
            'has_data' => isset($response['items']) || isset($response['id']),
            'error' => $response['error'] ?? null
        ];
    }
    
    private function getAllTemplates() {
        $url = '/project-templates?fields=id,name,description,location,languageDirections,settings';
        $response = $this->makeApiCall($url, 'GET');
        
        $this->results['available_templates'] = [
            'api_call_success' => !isset($response['error']),
            'http_code' => $response['http_code'] ?? 'unknown',
            'total_templates' => 0,
            'templates' => [],
            'target_template_found' => false
        ];
        
        if (isset($response['items']) && is_array($response['items'])) {
            $this->results['available_templates']['total_templates'] = count($response['items']);
            
            foreach ($response['items'] as $template) {
                $templateInfo = [
                    'id' => $template['id'] ?? 'unknown',
                    'name' => $template['name'] ?? 'unnamed',
                    'description' => $template['description'] ?? '',
                    'location_id' => $template['location']['id'] ?? null,
                    'location_name' => $template['location']['name'] ?? null,
                    'language_directions_count' => isset($template['languageDirections']) ? count($template['languageDirections']) : 0,
                    'has_settings' => isset($template['settings']),
                    'is_target_template' => ($template['id'] ?? '') === '68752414e45edf18699728e1'
                ];
                
                if ($templateInfo['is_target_template']) {
                    $this->results['available_templates']['target_template_found'] = true;
                    $this->results['target_template_details'] = $templateInfo;
                }
                
                $this->results['available_templates']['templates'][] = $templateInfo;
            }
        } else {
            $this->results['available_templates']['error'] = $response['error'] ?? 'No templates returned';
        }
    }
    
    private function testSpecificTemplate() {
        $targetTemplateId = '68752414e45edf18699728e1';
        $url = "/project-templates/{$targetTemplateId}?fields=id,name,description,location,languageDirections,settings,workflow,pricingModel";
        
        $response = $this->makeApiCall($url, 'GET');
        
        $this->results['specific_template_test'] = [
            'template_id' => $targetTemplateId,
            'api_call_success' => !isset($response['error']),
            'http_code' => $response['http_code'] ?? 'unknown',
            'template_accessible' => false,
            'template_data' => null,
            'error' => $response['error'] ?? null
        ];
        
        if (!isset($response['error']) && isset($response['id'])) {
            $this->results['specific_template_test']['template_accessible'] = true;
            $this->results['specific_template_test']['template_data'] = [
                'id' => $response['id'],
                'name' => $response['name'] ?? 'unnamed',
                'location_id' => $response['location']['id'] ?? null,
                'location_name' => $response['location']['name'] ?? null,
                'language_directions' => $response['languageDirections'] ?? [],
                'has_workflow' => isset($response['workflow']),
                'has_pricing_model' => isset($response['pricingModel']),
                'full_response' => $response
            ];
        }
    }
    
    private function getAvailableLocations() {
        $response = $this->makeApiCall('/locations', 'GET');
        
        $this->results['available_locations'] = [
            'api_call_success' => !isset($response['error']),
            'http_code' => $response['http_code'] ?? 'unknown',
            'total_locations' => 0,
            'locations' => [],
            'template_location_accessible' => false
        ];
        
        if (isset($response['items']) && is_array($response['items'])) {
            $this->results['available_locations']['total_locations'] = count($response['items']);
            
            foreach ($response['items'] as $location) {
                $locationInfo = [
                    'id' => $location['id'] ?? 'unknown',
                    'name' => $location['name'] ?? 'unnamed',
                    'description' => $location['description'] ?? '',
                    'is_template_location' => ($location['id'] ?? '') === '654b099d0bd44756945fb6cae4713a75',
                    'is_your_target_location' => ($location['id'] ?? '') === '6875209da02fda97ba600ae8'
                ];
                
                if ($locationInfo['is_template_location']) {
                    $this->results['available_locations']['template_location_accessible'] = true;
                }
                
                $this->results['available_locations']['locations'][] = $locationInfo;
            }
        }
    }
    
    private function testProjectCreationWithoutTemplate() {
        // Try creating a minimal project without template to test basic project creation
        $testProjectData = [
            'name' => 'DiagnosticTest_' . date('Y-m-d_H-i-s'),
            'description' => 'Template diagnostic test project',
            'dueBy' => gmdate('Y-m-d\TH:i:s\Z', strtotime('+30 days')),
            'languageDirections' => [
                [
                    'sourceLanguage' => ['languageCode' => 'en-US'],
                    'targetLanguage' => ['languageCode' => 'de-DE']
                ]
            ]
        ];
        
        // Try with first available location
        if (!empty($this->results['available_locations']['locations'])) {
            $firstLocation = $this->results['available_locations']['locations'][0];
            $testProjectData['location'] = $firstLocation['id'];
        }
        
        $response = $this->makeApiCall('/projects', 'POST', $testProjectData);
        
        $this->results['test_project_creation'] = [
            'attempted_without_template' => true,
            'success' => !isset($response['error']) && isset($response['id']),
            'http_code' => $response['http_code'] ?? 'unknown',
            'project_data_sent' => $testProjectData,
            'error' => $response['error'] ?? null
        ];
        
        // If successful, try to delete the test project
        if (isset($response['id'])) {
            $this->results['test_project_creation']['test_project_id'] = $response['id'];
            $deleteResponse = $this->makeApiCall("/projects/{$response['id']}", 'DELETE');
            $this->results['test_project_creation']['cleanup_attempted'] = true;
            $this->results['test_project_creation']['cleanup_success'] = !isset($deleteResponse['error']);
        }
    }
    
    private function analyzeResults() {
        $analysis = [
            'summary' => [],
            'issues_found' => [],
            'recommendations' => []
        ];
        
        // Authentication analysis
        if ($this->results['auth_test']['success']) {
            $analysis['summary'][] = "Authentication: SUCCESS - API connection working";
        } else {
            $analysis['issues_found'][] = "Authentication failed - cannot connect to Trados API";
            $analysis['recommendations'][] = "Check client credentials and tenant ID";
        }
        
        // Template availability analysis
        if ($this->results['available_templates']['total_templates'] > 0) {
            $analysis['summary'][] = "Templates found: {$this->results['available_templates']['total_templates']} available";
            
            if ($this->results['available_templates']['target_template_found']) {
                $analysis['summary'][] = "Target template: FOUND in available templates list";
            } else {
                $analysis['issues_found'][] = "Target template 68752414e45edf18699728e1 NOT found in available templates";
                $analysis['recommendations'][] = "Use a different template ID from the available list, or create project without template";
            }
        } else {
            $analysis['issues_found'][] = "No templates available to this tenant/location";
            $analysis['recommendations'][] = "Create projects without templates or check template permissions";
        }
        
        // Specific template access analysis
        if (isset($this->results['specific_template_test'])) {
            if ($this->results['specific_template_test']['template_accessible']) {
                $analysis['summary'][] = "Specific template: ACCESSIBLE directly";
            } else {
                $analysis['issues_found'][] = "Target template not directly accessible (HTTP {$this->results['specific_template_test']['http_code']})";
                
                if ($this->results['specific_template_test']['http_code'] === 404) {
                    $analysis['recommendations'][] = "Template ID 68752414e45edf18699728e1 does not exist or is not accessible to your tenant";
                } else if ($this->results['specific_template_test']['http_code'] === 403) {
                    $analysis['recommendations'][] = "Template exists but access is forbidden - check permissions";
                }
            }
        }
        
        // Location analysis
        if ($this->results['available_locations']['total_locations'] > 0) {
            $analysis['summary'][] = "Locations available: {$this->results['available_locations']['total_locations']}";
            
            if ($this->results['available_locations']['template_location_accessible']) {
                $analysis['summary'][] = "Template location (654b099d0bd44756945fb6cae4713a75) is accessible";
            } else {
                $analysis['issues_found'][] = "Template location not accessible to your tenant";
                $analysis['recommendations'][] = "Use a different location or template that matches your accessible locations";
            }
        }
        
        // Project creation analysis
        if (isset($this->results['test_project_creation']) && $this->results['test_project_creation']['success']) {
            $analysis['summary'][] = "Basic project creation: SUCCESS (without template)";
            $analysis['recommendations'][] = "Consider creating projects without templates, then configuring settings manually";
        } else {
            $analysis['issues_found'][] = "Basic project creation failed even without template";
            $analysis['recommendations'][] = "Check location permissions and basic API access";
        }
        
        // Final recommendation
        if (empty($analysis['issues_found'])) {
            $analysis['recommendations'][] = "All tests passed - investigate other factors in project creation";
        } else {
            $analysis['recommendations'][] = "IMMEDIATE FIX: Remove template from project creation or use a template from the available list";
        }
        
        $this->results['analysis'] = $analysis;
    }
    
    private function makeApiCall($endpoint, $method = 'GET', $data = null) {
        $url = $this->apiBaseUrl . $endpoint;
        
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
            'X-LC-Tenant: ' . $this->tenantId
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'TradosDiagnostic/1.0'
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        $result = [
            'http_code' => $httpCode,
            'raw_response' => $response
        ];
        
        if ($curlError) {
            $result['error'] = "cURL error: $curlError";
            return $result;
        }
        
        if ($httpCode >= 400) {
            $result['error'] = "HTTP $httpCode error";
            $decoded = json_decode($response, true);
            if ($decoded) {
                $result['decoded'] = $decoded;
                $result['error'] = $decoded['message'] ?? $result['error'];
            }
            return $result;
        }
        
        $decoded = json_decode($response, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            $result['error'] = 'Invalid JSON response';
            return $result;
        }
        
        return array_merge($result, $decoded ?: []);
    }
    
    private function getAuth0AccessToken($clientId, $clientSecret) {
        $auth0Domain = 'https://sdl-prod.eu.auth0.com';
        $tokenUrl = $auth0Domain . '/oauth/token';
        
        $data = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'audience' => 'https://api.sdl.com',
            'grant_type' => 'client_credentials'
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $tokenUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            throw new Exception("Auth0 cURL Error: $curlError");
        }
        
        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            $errorMsg = is_array($errorData) ? $errorData['error'] ?? "HTTP $httpCode" : "HTTP $httpCode";
            throw new Exception("Auth0 Error: $errorMsg - Response: $response");
        }
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['access_token'])) {
            throw new Exception('Invalid response from Auth0');
        }
        
        return $data;
    }
}

// Main execution
try {
    $input = json_decode(file_get_contents('php://input'), true);
    $instanceId = $input['instanceId'] ?? null;
    
    if (!$instanceId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'instanceId is required']);
        exit;
    }
    
    $diagnostic = new TemplateDiagnostic($instanceId);
    $results = $diagnostic->runDiagnostic();
    
    echo json_encode([
        'success' => true,
        'diagnostic_results' => $results
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Diagnostic failed',
        'message' => $e->getMessage()
    ]);
}
?>