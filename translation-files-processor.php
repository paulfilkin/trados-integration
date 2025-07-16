<?php
// Translation Files Processor - Scans translationfiles folder and creates Trados projects
// Uses your existing authentication system and project template

require_once 'includes/database.php';
require_once 'includes/functions.php';

class TradosProjectManager {
    private $apiBaseUrl = 'https://api.eu.cloud.trados.com/public-api/v1';
    private $projectTemplateId;
    private $translationFilesDir;
    private $clientId;
    private $clientSecret;
    private $tenantId;
    private $accessToken;
    
    public function __construct($instanceId, $templateId = null) {
        $this->translationFilesDir = __DIR__ . '/translationfiles';
        $this->projectTemplateId = $templateId;
        
        // Get credentials from your database
        $instance = $this->getInstance($instanceId);
        if (!$instance) {
            throw new Exception("Instance not found: $instanceId");
        }
        
        $this->clientId = $instance['client_id'];
        $this->clientSecret = $instance['client_secret'];
        $this->tenantId = $instance['tenant_id'];
        
        logActivity('info', 'TradosProjectManager initialized', [
            'instance_id' => $instanceId,
            'tenant_id' => $this->tenantId,
            'template_id' => $this->projectTemplateId
        ], $instanceId);
    }
    
    private function getInstance($instanceId) {
        global $pdo;
        
        try {
            $stmt = $pdo->prepare("
                SELECT instance_id, tenant_id, client_id, client_secret, status
                FROM integration_controls 
                WHERE instance_id = ? AND status = 'active'
            ");
            $stmt->execute([$instanceId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            logActivity('error', 'Failed to get instance', $e->getMessage());
            return false;
        }
    }
    
    public function processTranslationFiles() {
        try {
            // Step 1: Create translationfiles directory if it doesn't exist
            $this->ensureTranslationFilesDirectory();
            
            // Step 2: Scan for files
            $files = $this->scanTranslationFiles();
            
            if (empty($files)) {
                return [
                    'success' => false,
                    'message' => 'No files found in translationfiles directory',
                    'files_found' => 0
                ];
            }
            
            logActivity('info', 'Found files for processing', [
                'file_count' => count($files),
                'files' => array_keys($files)
            ]);
            
            // Step 3: Authenticate with Trados API
            if (!$this->authenticate()) {
                return [
                    'success' => false,
                    'message' => 'Authentication failed',
                    'files_found' => count($files)
                ];
            }
            
            // Step 4: Create project using your template
            $project = $this->createProject($files);
            
            if (!$project || !is_array($project) || !isset($project['id'])) {
                logActivity('error', 'Project creation failed', [
                    'project_result' => $project,
                    'files_found' => count($files)
                ]);
                
                return [
                    'success' => false,
                    'message' => 'Failed to create project: ' . (is_array($project) ? json_encode($project) : 'Invalid response'),
                    'files_found' => count($files)
                ];
            }
            
            // Step 5: Upload files to project
            $uploadResults = $this->uploadFilesToProject($project['id'], $files);
            
            // Step 6: Start the project
            $startResult = $this->startProject($project['id']);
            
            logActivity('success', 'Translation project created and started', [
                'project_id' => $project['id'],
                'project_name' => $project['name'] ?? 'Unknown',
                'files_uploaded' => count($uploadResults['successful'] ?? []),
                'files_failed' => count($uploadResults['failed'] ?? [])
            ]);
            
            return [
                'success' => true,
                'message' => 'Project created and files uploaded successfully',
                'project' => $project,
                'files_found' => count($files),
                'files_uploaded' => count($uploadResults['successful'] ?? []),
                'files_failed' => count($uploadResults['failed'] ?? []),
                'upload_results' => $uploadResults,
                'project_started' => $startResult
            ];
            
        } catch (Exception $e) {
            logActivity('error', 'Error processing translation files', $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'files_found' => 0
            ];
        }
    }
    
    private function ensureTranslationFilesDirectory() {
        if (!is_dir($this->translationFilesDir)) {
            mkdir($this->translationFilesDir, 0755, true);
            logActivity('info', 'Created translationfiles directory', $this->translationFilesDir);
        }
    }
    
    private function scanTranslationFiles() {
        $files = [];
        
        if (!is_dir($this->translationFilesDir)) {
            return $files;
        }
        
        $allowedExtensions = ['txt', 'docx', 'xlsx', 'pptx', 'html', 'xml', 'json', 'csv'];
        
        $iterator = new DirectoryIterator($this->translationFilesDir);
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
            
            $files[$filename] = [
                'path' => $fileInfo->getPathname(),
                'size' => $fileInfo->getSize(),
                'extension' => $extension,
                'modified' => $fileInfo->getMTime()
            ];
        }
        
        return $files;
    }
    
    private function authenticate() {
        try {
            // Use Auth0 authentication like your working system
            $auth0Domain = 'https://sdl-prod.eu.auth0.com';
            $tokenUrl = $auth0Domain . '/oauth/token';
            
            $postData = [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'audience' => 'https://api.sdl.com',
                'grant_type' => 'client_credentials'
            ];
            
            logActivity('info', 'Authenticating with Auth0', [
                'auth0_domain' => $auth0Domain,
                'client_id' => $this->clientId,
                'audience' => 'https://api.sdl.com',
            ]);
            
            $response = $this->makeAuth0Call($tokenUrl, $postData);
            
            if (isset($response['access_token'])) {
                $this->accessToken = $response['access_token'];
                logActivity('success', 'Auth0 authentication successful', [
                    'expires_in' => $response['expires_in'] ?? 'unknown',
                    'token_type' => $response['token_type'] ?? 'unknown'
                ]);
                return true;
            } else {
                logActivity('error', 'Auth0 authentication failed', json_encode($response));
                return false;
            }
            
        } catch (Exception $e) {
            logActivity('error', 'Authentication error', $e->getMessage());
            return false;
        }
    }
    
    private function makeAuth0Call($url, $postData) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
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
            throw new Exception("CURL Error: $curlError");
        }
        
        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            $errorMsg = $errorData['error_description'] ?? $errorData['error'] ?? "HTTP $httpCode";
            throw new Exception("Auth0 Error: $errorMsg - Response: $response");
        }
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['access_token'])) {
            throw new Exception('Invalid response from Auth0');
        }
        
        return $data;
    }
    
    private function createProject($files) {
        try {
            $projectName = 'TranslationProject_' . date('Y-m-d_H-i-s');
            $projectDescription = 'Created via translationfiles folder processor. Files: ' . implode(', ', array_keys($files));
            
            logActivity('info', 'Starting project creation', [
                'template_id' => $this->projectTemplateId,
                'files_count' => count($files),
                'project_name' => $projectName
            ]);
            
            // Step 1: Try to get location and language directions from template
            $locationId = null;
            $templateResponse = null;

            if ($this->projectTemplateId) {
                logActivity('info', 'Attempting to get template with language directions', [
                    'template_id' => $this->projectTemplateId
                ]);
                
                // Get template with ALL fields we need
                $templateUrl = $this->apiBaseUrl . '/project-templates/' . $this->projectTemplateId . '?fields=id,name,location,languageDirections,settings,workflow,pricingModel';
                logActivity('debug', 'Template API URL with full fields', ['url' => $templateUrl]);
                
                $templateResponse = $this->makeApiCall($templateUrl, 'GET');
                
                logActivity('debug', 'Full template API response', [
                    'response' => $templateResponse,
                    'has_location' => isset($templateResponse['location']),
                    'has_language_directions' => isset($templateResponse['languageDirections']),
                    'language_directions_count' => isset($templateResponse['languageDirections']) ? count($templateResponse['languageDirections']) : 0,
                    'location_id' => isset($templateResponse['location']['id']) ? $templateResponse['location']['id'] : 'NOT FOUND'
                ]);
                
                if ($templateResponse && isset($templateResponse['location']['id'])) {
                    $locationId = $templateResponse['location']['id'];
                    logActivity('success', 'Found location from template', [
                        'location_id' => $locationId,
                        'location_name' => $templateResponse['location']['name'] ?? 'unknown'
                    ]);
                } else {
                    logActivity('error', 'Template API call failed or no location in template', [
                        'template_response' => $templateResponse
                    ]);
                }
            }
            
            // Step 2: If no location from template, try customers
            if (!$locationId) {
                logActivity('info', 'No location from template, trying customers');
                
                $customersResponse = $this->makeApiCall($this->apiBaseUrl . '/customers', 'GET');
                
                logActivity('debug', 'Customers API response', [
                    'response' => $customersResponse,
                    'has_items' => isset($customersResponse['items']),
                    'customer_count' => isset($customersResponse['items']) ? count($customersResponse['items']) : 0
                ]);
                
                if (isset($customersResponse['items']) && is_array($customersResponse['items'])) {
                    foreach ($customersResponse['items'] as $customer) {
                        if (isset($customer['location']['id'])) {
                            $locationId = $customer['location']['id'];
                            logActivity('success', 'Found location from customer', [
                                'location_id' => $locationId,
                                'customer_name' => $customer['name'] ?? 'unknown'
                            ]);
                            break;
                        }
                    }
                }
            }
            
            // Step 3: If still no location, return error
            if (!$locationId) {
                logActivity('error', 'No valid location found anywhere');
                return [
                    'success' => false,
                    'message' => 'No valid location found. Please ensure you have access to at least one location in Trados Cloud.',
                    'files_found' => count($files)
                ];
            }

            // Step 4: Create project data with language directions from template
            $projectData = [
                'name' => $projectName,
                'description' => $projectDescription,
                'dueBy' => gmdate('Y-m-d\TH:i:s\Z', strtotime('+30 days')),
                'location' => $locationId
            ];

            // Get language directions from template or use default
            $languageDirections = [];
            if ($templateResponse && isset($templateResponse['languageDirections']) && !empty($templateResponse['languageDirections'])) {
                // Use ALL language directions from template
                $languageDirections = $templateResponse['languageDirections'];
                logActivity('info', 'Using ALL language directions from template', [
                    'directions' => $languageDirections,
                    'count' => count($languageDirections)
                ]);
            } else {
                // Use default language directions
                $languageDirections = [
                    [
                        'sourceLanguage' => [
                            'languageCode' => 'en-US'
                        ],
                        'targetLanguage' => [
                            'languageCode' => 'de-DE'
                        ]
                    ]
                ];
                logActivity('warning', 'Using default language directions - template may not have language directions', [
                    'directions' => $languageDirections,
                    'template_response' => $templateResponse
                ]);
            }

            // Add language directions to project data
            $projectData['languageDirections'] = $languageDirections;

            // Add workflow from template if available
            if ($templateResponse && isset($templateResponse['workflow']['id'])) {
                $projectData['workflow'] = [
                    'id' => $templateResponse['workflow']['id']
                ];
                logActivity('info', 'Including workflow from template', [
                    'workflow_id' => $templateResponse['workflow']['id'],
                    'workflow_name' => $templateResponse['workflow']['name'] ?? 'unknown'
                ]);
            } else {
                logActivity('warning', 'No workflow found in template', [
                    'template_response' => $templateResponse
                ]);
            }

            // Get template data but don't include template reference in project creation
            if ($this->projectTemplateId && $templateResponse && isset($templateResponse['id'])) {
                // We have template data - use location and language directions
                // But DON'T include the projectTemplate reference
                logActivity('info', 'Using template data without template reference to avoid API validation error', [
                    'template_id' => $this->projectTemplateId,
                    'location_from_template' => $locationId,
                    'language_directions_from_template' => count($languageDirections)
                ]);
            } else {
                logActivity('warning', 'Not including project template - invalid template response', [
                    'template_id' => $this->projectTemplateId,
                    'template_response' => $templateResponse
                ]);
            }

            logActivity('info', 'Creating project with data', [
                'project_data' => $projectData,
                'api_url' => $this->apiBaseUrl . '/projects'
            ]);
            
        // Step 5: Make API call to create project
        $response = $this->makeApiCall($this->apiBaseUrl . '/projects', 'POST', $projectData);

        logActivity('debug', 'Project creation API response', [
            'response' => $response,
            'response_type' => gettype($response),
            'has_error' => is_array($response) && isset($response['error']),
            'has_id' => is_array($response) && isset($response['id']),
            'project_id' => is_array($response) && isset($response['id']) ? $response['id'] : 'NOT FOUND'
        ]);

        // Step 6: Check if project was created successfully
        if ($response && is_array($response) && isset($response['id'])) {
            logActivity('success', 'Project created successfully', [
                'project_id' => $response['id'],
                'project_name' => $response['name'] ?? $projectName
            ]);
            return $response;
        } else if ($response && is_array($response) && isset($response['error'])) {
            // Handle detailed error response
            $errorMessage = 'API Error';
            if (isset($response['decoded']['message'])) {
                $errorMessage = $response['decoded']['message'];
            }
            
            logActivity('error', 'Project creation failed with API error', [
                'http_code' => $response['http_code'],
                'error_message' => $errorMessage,
                'full_error_response' => $response['decoded'],
                'project_data_sent' => $projectData
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to create project: ' . $errorMessage,
                'api_error' => $response['decoded'],
                'files_found' => count($files)
            ];
        } else {
            logActivity('error', 'Project creation failed - unexpected response', [
                'response' => $response,
                'project_data_sent' => $projectData,
                'response_type' => gettype($response)
            ]);
            return [
                'success' => false,
                'message' => 'Failed to create project: ' . json_encode($response),
                'files_found' => count($files)
            ];
        }
            
        } catch (Exception $e) {
            logActivity('error', 'Exception in createProject', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'message' => 'Error creating project: ' . $e->getMessage(),
                'files_found' => count($files)
            ];
        }
    }
    
    private function uploadFilesToProject($projectId, $files) {
        $results = [
            'successful' => [],
            'failed' => []
        ];
        
        foreach ($files as $filename => $fileInfo) {
            try {
                logActivity('info', "Uploading file: $filename", [
                    'file_size' => $fileInfo['size'],
                    'project_id' => $projectId
                ]);
                
                $uploadResult = $this->uploadSingleFile($projectId, $filename, $fileInfo);
                
                if ($uploadResult) {
                    $results['successful'][] = [
                        'filename' => $filename,
                        'file_id' => $uploadResult['id'] ?? 'unknown',
                        'size' => $fileInfo['size']
                    ];
                    logActivity('success', "File uploaded successfully: $filename");
                } else {
                    $results['failed'][] = [
                        'filename' => $filename,
                        'error' => 'Upload failed'
                    ];
                    logActivity('error', "File upload failed: $filename");
                }
                
            } catch (Exception $e) {
                $results['failed'][] = [
                    'filename' => $filename,
                    'error' => $e->getMessage()
                ];
                logActivity('error', "File upload error: $filename", $e->getMessage());
            }
        }
        
        return $results;
    }
    
    private function uploadSingleFile($projectId, $filename, $fileInfo) {
        try {
            // Read file content
            $fileContent = file_get_contents($fileInfo['path']);
            if ($fileContent === false) {
                throw new Exception("Cannot read file: {$fileInfo['path']}");
            }
            
            // Prepare multipart form data
            $boundary = uniqid();
            $fileData = [
                'role' => 'translatable', // All files are translatable as requested
                'type' => 'native',
                'language' => 'auto-detect', // Let Trados detect the source language
                'name' => $filename
            ];
            
            $postData = '';
            
            // Add form fields
            foreach ($fileData as $key => $value) {
                $postData .= "--{$boundary}\r\n";
                $postData .= "Content-Disposition: form-data; name=\"{$key}\"\r\n\r\n";
                $postData .= "{$value}\r\n";
            }
            
            // Add file content
            $postData .= "--{$boundary}\r\n";
            $postData .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
            $postData .= "Content-Type: application/octet-stream\r\n\r\n";
            $postData .= $fileContent . "\r\n";
            $postData .= "--{$boundary}--\r\n";
            
            $headers = [
                'Content-Type: multipart/form-data; boundary=' . $boundary,
                'Authorization: Bearer ' . $this->accessToken,
                'X-LC-Tenant: ' . $this->tenantId
            ];
            
            $uploadUrl = $this->apiBaseUrl . "/projects/{$projectId}/source-files";
            
            $response = $this->makeRawApiCall($uploadUrl, 'POST', $postData, $headers);
            
            return $response;
            
        } catch (Exception $e) {
            logActivity('error', "Single file upload error: $filename", $e->getMessage());
            return null;
        }
    }
    
    private function startProject($projectId) {
        try {
            logActivity('info', 'Starting project', ['project_id' => $projectId]);
            
            $response = $this->makeApiCall(
                $this->apiBaseUrl . "/projects/{$projectId}/start",
                'PUT'
            );
            
            if ($response !== false) {
                logActivity('success', 'Project started successfully', ['project_id' => $projectId]);
                return true;
            } else {
                logActivity('error', 'Failed to start project', ['project_id' => $projectId]);
                return false;
            }
            
        } catch (Exception $e) {
            logActivity('error', 'Error starting project', $e->getMessage());
            return false;
        }
    }
    
    private function makeApiCall($url, $method = 'GET', $data = null, $additionalHeaders = [], $requireAuth = true) {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        if ($requireAuth && $this->accessToken) {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
            $headers[] = 'X-LC-Tenant: ' . $this->tenantId;
        }
        
        $headers = array_merge($headers, $additionalHeaders);
        
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $data ? json_encode($data) : null,
                'timeout' => 60,
                'ignore_errors' => true
            ]
        ]);
        
        $response = file_get_contents($url, false, $context);
        
        if ($response === false) {
            throw new Exception("API call failed: $url");
        }
        
        $httpCode = null;
        if (isset($http_response_header)) {
            $httpCode = $http_response_header[0];
        }
        
        $decoded = json_decode($response, true);
        
        // Log ALL API responses for debugging
        logActivity('debug', "API call response: $method $url", [
            'http_code' => $httpCode,
            'response' => $response,
            'decoded' => $decoded
        ]);
        
        // Check if it's a success response
        if (strpos($httpCode, '200') !== false || strpos($httpCode, '201') !== false) {
            return $decoded;
        } else {
            // For error responses, log detailed error and return the decoded error response
            logActivity('error', "API call failed: $method $url", [
                'http_code' => $httpCode,
                'response' => $response,
                'decoded_error' => $decoded
            ]);
            
            // Return the decoded error response instead of false
            // This allows the caller to access detailed error information
            return [
                'error' => true,
                'http_code' => $httpCode,
                'response' => $response,
                'decoded' => $decoded
            ];
        }
    }
    
    private function makeRawApiCall($url, $method, $data, $headers) {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $data,
                'timeout' => 60,
                'ignore_errors' => true
            ]
        ]);
        
        $response = file_get_contents($url, false, $context);
        
        if ($response === false) {
            throw new Exception("Raw API call failed: $url");
        }
        
        return json_decode($response, true);
    }
}

// Function to be called from the web interface
function processTranslationFilesForInstance($instanceId, $templateId = null) {
    try {
        $manager = new TradosProjectManager($instanceId, $templateId);
        return $manager->processTranslationFiles();
    } catch (Exception $e) {
        logActivity('error', 'Failed to process translation files', $e->getMessage());
        return [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ];
    }
}
?>