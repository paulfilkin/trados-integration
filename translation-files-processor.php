<?php

class TradosProjectManager {
    private $instanceId;
    private $projectTemplateId;
    private $apiBaseUrl;
    private $accessToken;
    private $tenantId;
    private $clientId;
    private $clientSecret;
    
    public function __construct($instanceId, $templateId = null) {
        $this->instanceId = $instanceId;
        $this->projectTemplateId = $templateId;
        $this->apiBaseUrl = 'https://lc-api.sdl.com/public-api/v1';
        
        // Get instance credentials
        $instance = $this->getInstance($instanceId);
        if (!$instance) {
            throw new Exception('Instance not found');
        }
        
        $this->tenantId = $instance['tenant_id'];
        $this->clientId = $instance['client_id'];
        $this->clientSecret = $instance['client_secret'];
    }
    
    /**
     * SIMPLIFIED PROJECT CREATION - Just use the template!
     */
    private function createProject($files) {
        try {
            $projectName = 'TranslationProject_' . date('Y-m-d_H-i-s');
            
            if (!$this->projectTemplateId) {
                throw new Exception('Project template ID is required');
            }
            
            logActivity('info', 'Creating project WITHOUT template reference', [
                'template_id' => $this->projectTemplateId,
                'project_name' => $projectName,
                'files_count' => count($files)
            ]);
            
            // Get the template details - use the working method
            $template = $this->testTemplateRetrieval($this->projectTemplateId);
            
            if (!$template) {
                throw new Exception('Could not retrieve project template details');
            }
            
            // Extract language directions from template
            $languageDirections = [];
            if (isset($template['languageDirections']) && is_array($template['languageDirections'])) {
                foreach ($template['languageDirections'] as $langDir) {
                    $languageDirections[] = [
                        'sourceLanguage' => [
                            'languageCode' => $langDir['sourceLanguage']['languageCode']
                        ],
                        'targetLanguage' => [
                            'languageCode' => $langDir['targetLanguage']['languageCode']
                        ]
                    ];
                }
            }
            
            if (empty($languageDirections)) {
                throw new Exception('No language directions found in template');
            }
            
            // Build project data with individual components (NO template reference)
            $projectData = [
                'name' => $projectName,
                'description' => 'Created via translationfiles folder processor',
                'dueBy' => gmdate('Y-m-d\TH:i:s\Z', strtotime('+30 days')),
                'location' => $template['location']['id'],
                'languageDirections' => $languageDirections
            ];
            
            // Include individual components from template
            if (isset($template['fileProcessingConfiguration']['id'])) {
                $projectData['fileProcessingConfiguration'] = [
                    'id' => $template['fileProcessingConfiguration']['id'],
                    'strategy' => 'copy'
                ];
            }
            
            if (isset($template['translationEngine']['id'])) {
                $projectData['translationEngine'] = [
                    'id' => $template['translationEngine']['id'],
                    'strategy' => 'copy'
                ];
            }
            
            if (isset($template['workflow']['id'])) {
                $projectData['workflow'] = [
                    'id' => $template['workflow']['id'],
                    'strategy' => 'copy'
                ];
            }
            
            logActivity('info', 'Project data being sent (no template ref)', $projectData);
            
            // ADD DEBUG LOGGING BEFORE API CALL
            logActivity('debug', 'About to make project creation API call', [
                'url' => $this->apiBaseUrl . '/projects',
                'method' => 'POST',
                'data_size' => strlen(json_encode($projectData))
            ]);
            
            $response = $this->makeApiCall($this->apiBaseUrl . '/projects', 'POST', $projectData);
            
            // ADD DEBUG LOGGING AFTER API CALL
            logActivity('debug', 'Project creation API call completed', [
                'response_type' => gettype($response),
                'has_error' => isset($response['error']) ? 'yes' : 'no',
                'has_id' => isset($response['id']) ? 'yes' : 'no'
            ]);
            
            // Rest of your existing error handling...
            if ($response && !isset($response['error']) && isset($response['id'])) {
                logActivity('success', 'Project created successfully without template reference', [
                    'project_id' => $response['id'],
                    'template_components_used' => $this->projectTemplateId,
                    'project_name' => $response['name'] ?? $projectName
                ]);
                return $response;
            } else {
                $errorMessage = 'Unknown error';
                if (isset($response['decoded']['message'])) {
                    $errorMessage = $response['decoded']['message'];
                } elseif (isset($response['message'])) {
                    $errorMessage = $response['message'];
                }
                
                throw new Exception('Failed to create project: ' . $errorMessage);
            }
            
        } catch (Exception $e) {
            logActivity('error', 'Exception in createProject', [
                'error' => $e->getMessage(),
                'template_id' => $this->projectTemplateId
            ]);
            return [
                'success' => false,
                'message' => 'Error creating project: ' . $e->getMessage(),
                'files_found' => count($files)
            ];
        }
    }

    private function getFullProjectTemplate($templateId) {
        try {
            logActivity('info', 'About to retrieve template details', [
                'template_id' => $templateId,
                'api_url' => $this->apiBaseUrl . "/project-templates/{$templateId}"
            ]);
            
            // Add debug logging before the API call
            logActivity('debug', 'Making template API call', [
                'template_id' => $templateId,
                'has_access_token' => !empty($this->accessToken) ? 'yes' : 'no',
                'has_tenant_id' => !empty($this->tenantId) ? 'yes' : 'no'
            ]);
            
            $apiUrl = $this->apiBaseUrl . "/project-templates/{$templateId}?fields=id,name,description,languageDirections,location,fileProcessingConfiguration,translationEngine,workflow,pricingModel,scheduleTemplate";
            
            logActivity('debug', 'Full API URL constructed', [
                'url' => $apiUrl,
                'url_length' => strlen($apiUrl)
            ]);
            
            $response = $this->makeApiCall($apiUrl, 'GET');
            
            logActivity('info', 'Template API call completed', [
                'template_id' => $templateId,
                'response_type' => gettype($response),
                'has_error' => isset($response['error']) ? 'yes' : 'no',
                'response_keys' => is_array($response) ? array_keys($response) : 'not_array'
            ]);
            
            if ($response && !isset($response['error'])) {
                logActivity('info', 'Retrieved full project template details', [
                    'template_id' => $templateId,
                    'name' => $response['name'] ?? 'Unknown',
                    'language_directions_count' => count($response['languageDirections'] ?? []),
                    'has_file_config' => isset($response['fileProcessingConfiguration']),
                    'has_translation_engine' => isset($response['translationEngine']),
                    'has_workflow' => isset($response['workflow'])
                ]);
                return $response;
            }
            
            logActivity('error', 'Failed to retrieve full project template', [
                'template_id' => $templateId,
                'response' => $response
            ]);
            return null;
            
        } catch (Exception $e) {
            logActivity('error', 'Exception getting full project template', [
                'template_id' => $templateId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    private function testTemplateRetrieval($templateId) {
        try {
            logActivity('info', 'Testing basic template retrieval', [
                'template_id' => $templateId
            ]);
            
            // Try with fields parameter
            $response = $this->makeApiCall(
                $this->apiBaseUrl . "/project-templates/{$templateId}?fields=id,name,description,languageDirections,location,fileProcessingConfiguration,translationEngine,workflow",
                'GET'
            );
            
            logActivity('info', 'Basic template call completed', [
                'template_id' => $templateId,
                'response_type' => gettype($response),
                'has_error' => isset($response['error']) ? 'yes' : 'no',
                'has_language_directions' => isset($response['languageDirections']) ? 'yes' : 'no',
                'language_directions_count' => isset($response['languageDirections']) ? count($response['languageDirections']) : 0
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            logActivity('error', 'Exception in test template retrieval', [
                'template_id' => $templateId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * CHECK IF PROJECT CAN BE STARTED
     */
    private function canStartProject($projectId) {
        try {
            logActivity('info', 'Checking if project can be started', ['project_id' => $projectId]);
            
            // Add debug logging before API call
            logActivity('debug', 'About to call project status API', [
                'project_id' => $projectId,
                'url' => $this->apiBaseUrl . "/projects/{$projectId}"
            ]);
            
            // Add fields parameter to get the status field
            $response = $this->makeApiCall(
                $this->apiBaseUrl . "/projects/{$projectId}?fields=id,name,status,languageDirections",
                'GET'
            );
            
            logActivity('debug', 'Project status API call completed', [
                'project_id' => $projectId,
                'response_type' => gettype($response),
                'has_error' => isset($response['error']) ? 'yes' : 'no',
                'has_status' => isset($response['status']) ? 'yes' : 'no',
                'actual_status' => $response['status'] ?? 'not_found',
                'response_keys' => is_array($response) ? array_keys($response) : 'not_array'
            ]);
            
            if ($response && !isset($response['error']) && isset($response['status'])) {
                $status = $response['status'];
                logActivity('info', 'Project status check result', [
                    'project_id' => $projectId,
                    'status' => $status
                ]);
                
                // Check if project is in a state that can be started
                $startableStates = ['created', 'pending', 'ready', 'draft'];
                $canStart = in_array(strtolower($status), $startableStates);
                
                logActivity('info', 'Project startability determined', [
                    'project_id' => $projectId,
                    'current_status' => $status,
                    'can_start' => $canStart ? 'yes' : 'no',
                    'startable_states' => $startableStates
                ]);
                
                return $canStart;
            }
            
            logActivity('error', 'Could not determine project status', [
                'project_id' => $projectId,
                'response' => $response,
                'missing_status_field' => !isset($response['status']) ? 'yes' : 'no'
            ]);
            return false;
            
        } catch (Exception $e) {
            logActivity('error', 'Error checking project status', [
                'project_id' => $projectId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * FIXED PROJECT STARTING WITH PROPER ERROR HANDLING
     */
    private function startProject($projectId) {
        try {
            logActivity('info', 'Starting project', ['project_id' => $projectId]);
            
            $response = $this->makeApiCall(
                $this->apiBaseUrl . "/projects/{$projectId}/start",
                'PUT'
            );
            
            // FIXED: Check for specific success indicators instead of !== false
            if ($response && !isset($response['error'])) {
                logActivity('success', 'Project started successfully', [
                    'project_id' => $projectId,
                    'response' => $response
                ]);
                return true;
            } else {
                $errorMsg = 'Unknown error';
                if (isset($response['decoded']['message'])) {
                    $errorMsg = $response['decoded']['message'];
                } elseif (isset($response['message'])) {
                    $errorMsg = $response['message'];
                }
                
                logActivity('error', 'Failed to start project', [
                    'project_id' => $projectId,
                    'error' => $errorMsg,
                    'full_response' => $response
                ]);
                return false;
            }
            
        } catch (Exception $e) {
            logActivity('error', 'Error starting project', [
                'project_id' => $projectId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * MAIN PROCESSING METHOD WITH IMPROVED FLOW
     */
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
            
            // Step 4: Create project using simplified template approach
            $project = $this->createProject($files);
            
            if (!$project || !is_array($project) || !isset($project['id'])) {
                logActivity('error', 'Project creation failed', [
                    'project_result' => $project,
                    'files_found' => count($files)
                ]);
                
                return [
                    'success' => false,
                    'message' => 'Failed to create project: ' . (is_array($project) && isset($project['message']) ? $project['message'] : 'Invalid response'),
                    'files_found' => count($files)
                ];
            }
            
            // Step 5: Upload files to project
            $uploadResults = $this->uploadFilesToProject($project['id'], $files);
            
        // Step 6: Only start if files uploaded successfully
        $startResult = false;

        logActivity('debug', 'About to check if project should be started', [
            'project_id' => $project['id'],
            'upload_results' => $uploadResults
        ]);

        if (isset($uploadResults['successful']) && count($uploadResults['successful']) > 0) {
            logActivity('info', 'Files uploaded successfully, attempting to start project', [
                'project_id' => $project['id'],
                'files_uploaded' => count($uploadResults['successful'])
            ]);
            
            // Check if project can be started
            if ($this->canStartProject($project['id'])) {
                logActivity('info', 'Project can be started, starting now', [
                    'project_id' => $project['id']
                ]);
                
                $startResult = $this->startProject($project['id']);
                
                if (!$startResult) {
                    logActivity('warning', 'Project created and files uploaded but failed to start', [
                        'project_id' => $project['id']
                    ]);
                }
            } else {
                logActivity('warning', 'Project not in startable state', [
                    'project_id' => $project['id']
                ]);
            }
        } else {
            logActivity('warning', 'No files uploaded successfully, not starting project', [
                'project_id' => $project['id'],
                'upload_results' => $uploadResults
            ]);
        }
            
            // Final success report
            logActivity('success', 'Translation project processing completed', [
                'project_id' => $project['id'],
                'project_name' => $project['name'] ?? 'Unknown',
                'files_found' => count($files),
                'files_uploaded' => count($uploadResults['successful'] ?? []),
                'files_failed' => count($uploadResults['failed'] ?? []),
                'project_started' => $startResult
            ]);
            
            return [
                'success' => true,
                'message' => 'Project created and processed successfully',
                'project' => $project,
                'files_found' => count($files),
                'files_uploaded' => count($uploadResults['successful'] ?? []),
                'files_failed' => count($uploadResults['failed'] ?? []),
                'project_started' => $startResult,
                'start_attempted' => isset($uploadResults['successful']) && count($uploadResults['successful']) > 0
            ];
            
        } catch (Exception $e) {
            logActivity('error', 'Exception in processTranslationFiles', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => 'Error processing translation files: ' . $e->getMessage()
            ];
        }
    }
    
    // Keep all your existing helper methods unchanged
    private function ensureTranslationFilesDirectory() {
        $dir = __DIR__ . '/translationfiles';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            logActivity('info', 'Created translationfiles directory', ['directory' => $dir]);
        }
    }
    
    private function scanTranslationFiles() {
        $dir = __DIR__ . '/translationfiles';
        $files = [];
        
        if (is_dir($dir)) {
            $items = scandir($dir);
            foreach ($items as $item) {
                if ($item != '.' && $item != '..' && is_file($dir . '/' . $item)) {
                    $files[$item] = $dir . '/' . $item;
                }
            }
        }
        
        return $files;
    }
    
    // FIXED: Use the working Auth0 domain and audience from your successful code
    private function authenticate() {
        try {
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
                'audience' => 'https://api.sdl.com'
            ]);
            
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
                throw new Exception("CURL Error: $curlError");
            }
            
            if ($httpCode !== 200) {
                logActivity('error', 'Auth0 authentication failed', "HTTP $httpCode: $response");
                $errorData = json_decode($response, true);
                $errorMsg = $errorData['error_description'] ?? $errorData['error'] ?? "HTTP $httpCode";
                throw new Exception("Auth0 Error: $errorMsg - Response: $response");
            }
            
            $data = json_decode($response, true);
            if (!$data || !isset($data['access_token'])) {
                logActivity('error', 'Invalid Auth0 response', $response);
                throw new Exception('Invalid response from Auth0');
            }
            
            $this->accessToken = $data['access_token'];
            
            logActivity('success', 'Auth0 authentication successful', [
                'expires_in' => $data['expires_in'] ?? 'unknown',
                'token_type' => $data['token_type'] ?? 'unknown'
            ]);
            
            return true;
            
        } catch (Exception $e) {
            logActivity('error', 'Authentication failed', $e->getMessage());
            return false;
        }
    }
    
    // Your existing upload and API methods (unchanged but will work better with fixed error handling)
    private function uploadFilesToProject($projectId, $files) {
        $successful = [];
        $failed = [];
        
        foreach ($files as $filename => $filepath) {
            logActivity('info', "Uploading file: $filename", ['filepath' => $filepath]);
            
            $result = $this->uploadSingleFile($projectId, $filename, $filepath);
            
            if ($result) {
                $successful[] = $filename;
                logActivity('success', "File uploaded successfully: $filename");
            } else {
                $failed[] = $filename;
                logActivity('error', "File upload failed: $filename");
            }
        }
        
        return [
            'successful' => $successful,
            'failed' => $failed
        ];
    }
    
    private function uploadSingleFile($projectId, $filename, $filepath) {
        try {
            logActivity('info', "Starting file upload", [
                'project_id' => $projectId,
                'filename' => $filename,
                'filepath' => $filepath
            ]);
            
            $fileContent = file_get_contents($filepath);
            if ($fileContent === false) {
                throw new Exception("Could not read file: $filepath");
            }
            
            logActivity('debug', "File content read successfully", [
                'filename' => $filename,
                'file_size' => strlen($fileContent),
                'file_exists' => file_exists($filepath) ? 'yes' : 'no'
            ]);
            
            // Create properties JSON (like PowerShell does)
            $properties = [
                'name' => $filename,
                'role' => 'translatable',  // or 'reference' for reference files
                'type' => 'native',        // or 'sdlxliff' for SDLXLIFF files
                'language' => 'en-US'      // source language from your template
            ];
            
            $boundary = '----formdata-' . uniqid();
            
            // Create multipart form data with BOTH properties and file
            $postData = "";
            
            // Add properties section
            $postData .= "--{$boundary}\r\n";
            $postData .= "Content-Disposition: form-data; name=\"properties\"\r\n";
            $postData .= "Content-Type: application/json\r\n\r\n";
            $postData .= json_encode($properties);
            $postData .= "\r\n";
            
            // Add file section
            $postData .= "--{$boundary}\r\n";
            $postData .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
            $postData .= "Content-Type: application/octet-stream\r\n\r\n";
            $postData .= $fileContent;
            $postData .= "\r\n";
            $postData .= "--{$boundary}--\r\n";
            
            $headers = [
                'Content-Type: multipart/form-data; boundary=' . $boundary,
                'Authorization: Bearer ' . $this->accessToken,
                'X-LC-Tenant: ' . $this->tenantId
            ];
            
            $uploadUrl = $this->apiBaseUrl . "/projects/{$projectId}/source-files";
            
            logActivity('debug', "About to make upload API call with properties", [
                'filename' => $filename,
                'upload_url' => $uploadUrl,
                'properties' => $properties,
                'post_data_size' => strlen($postData),
                'boundary' => $boundary
            ]);
            
            $response = $this->makeRawApiCall($uploadUrl, 'POST', $postData, $headers);
            
            logActivity('debug', "Upload API call completed", [
                'filename' => $filename,
                'response_type' => gettype($response),
                'response' => $response
            ]);
            
            // Check if upload was successful (no error in response)
            if ($response && !isset($response['errorCode'])) {
                logActivity('success', "File upload successful", [
                    'filename' => $filename,
                    'response' => $response
                ]);
                return $response;
            } else {
                logActivity('error', "File upload failed", [
                    'filename' => $filename,
                    'error_response' => $response
                ]);
                return null;
            }
            
        } catch (Exception $e) {
            logActivity('error', "Exception during file upload", [
                'filename' => $filename,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    // Your existing API call methods (the fixed error handling will work with these)
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
                'timeout' => 30, // Changed from 60 to 30
                'ignore_errors' => true
            ]
        ]);
        
        // ADD THIS NEW LOGGING:
        logActivity('debug', "Making API call: $method $url", [
            'has_auth' => $requireAuth && $this->accessToken ? 'yes' : 'no',
            'data_length' => $data ? strlen(json_encode($data)) : 0
        ]);
        
        $response = file_get_contents($url, false, $context);
        
        if ($response === false) {
            // UPDATE THIS ERROR HANDLING:
            logActivity('error', "API call failed completely: $url", [
                'method' => $method,
                'last_error' => error_get_last()
            ]);
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
            'response_preview' => substr($response, 0, 500),
            'decoded_preview' => is_array($decoded) ? array_slice($decoded, 0, 5, true) : $decoded
        ]);
        
        // Check if it's a success response
        if (strpos($httpCode, '200') !== false || strpos($httpCode, '201') !== false) {
            return $decoded;
        } else {
            // For error responses, return the decoded error response
            logActivity('error', "API call failed: $method $url", [
                'http_code' => $httpCode,
                'response' => $response,
                'decoded_error' => $decoded
            ]);
            
            return [
                'error' => true,
                'http_code' => $httpCode,
                'response' => $response,
                'decoded' => $decoded
            ];
        }
    }        

    
    
    private function makeRawApiCall($url, $method, $data, $headers) {
        logActivity('debug', "Making raw API call", [
            'url' => $url,
            'method' => $method,
            'data_size' => strlen($data),
            'headers_count' => count($headers)
        ]);
        
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
            logActivity('error', "Raw API call failed completely", [
                'url' => $url,
                'method' => $method,
                'last_error' => error_get_last()
            ]);
            throw new Exception("Raw API call failed: $url");
        }
        
        // Get HTTP response code
        $httpCode = null;
        if (isset($http_response_header)) {
            $httpCode = $http_response_header[0];
        }
        
        logActivity('debug', "Raw API call completed", [
            'url' => $url,
            'http_code' => $httpCode,
            'response_preview' => substr($response, 0, 200)
        ]);
        
        return json_decode($response, true);
    }
    
    private function getInstance($instanceId) {
        try {
            require_once __DIR__ . '/includes/database.php';
            global $pdo;
            
            $stmt = $pdo->prepare("
                SELECT * FROM integration_controls 
                WHERE instance_id = ? AND status = 'active'
            ");
            $stmt->execute([$instanceId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            logActivity('error', 'Failed to get instance', $e->getMessage());
            return false;
        }
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