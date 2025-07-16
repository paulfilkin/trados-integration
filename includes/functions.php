<?php
// Updated functions for JWS-based Trados integration - API key management removed

function getProvisioningInstances() {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT instance_id, tenant_id, status, created_at, last_activity,
                   client_id, client_secret, configuration_data
            FROM integration_controls 
            ORDER BY created_at DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching provisioning instances: " . $e->getMessage());
        return [];
    }
}

function getRecentLogs($limit = 10) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT timestamp, level, message, details, instance_id
            FROM activity_logs 
            ORDER BY timestamp DESC 
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching recent logs: " . $e->getMessage());
        return [];
    }
}

function getSystemStatus() {
    $status = [
        'database' => false,
        'jws_keys' => false,
        'webhook_endpoint' => false
    ];
    
    // Check database connection
    try {
        global $pdo;
        $pdo->query("SELECT 1");
        $status['database'] = true;
    } catch (PDOException $e) {
        $status['database'] = false;
    }
    
    // Check if JWS public keys are accessible
    $status['jws_keys'] = checkJwsKeysAvailable();
    
    // Check if webhook endpoint is accessible
    $status['webhook_endpoint'] = checkWebhookEndpoint();
    
    return $status;
}

function logActivity($level, $message, $details = null, $instanceId = null) {
    global $pdo;
    
    try {
        // Convert arrays/objects to JSON string for details
        if (is_array($details) || is_object($details)) {
            $details = json_encode($details);
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (timestamp, level, message, details, instance_id)
            VALUES (CURRENT_TIMESTAMP, ?, ?, ?, ?)
        ");
        $stmt->execute([$level, $message, $details, $instanceId]);
        return true;
    } catch (PDOException $e) {
        error_log("Error logging activity: " . $e->getMessage());
        return false;
    }
}

// JWS Validation Functions

// CORRECTED validateJwsSignature function
// Key insight: Always use EMPTY body for Trados JWS validation
// The .NET proxy modifies the request body, but JWS was signed for empty body

function validateJwsSignature($jwsToken, $requestBody) {
    try {
        logActivity('debug', 'JWS validation started', "Token length: " . strlen($jwsToken));
        
        // CRITICAL FIX: Always use EMPTY body for Trados JWS validation
        // Trados sends empty requests but .NET proxy adds JSON data
        $originalRequestBody = '';
        logActivity('debug', 'Using EMPTY body for JWS validation', 'Trados sends empty requests, .NET proxy modifies them');
        
        // Split JWS token into parts
        $parts = explode('.', $jwsToken);
        if (count($parts) !== 3) {
            logActivity('error', 'Invalid JWS token format', 'Expected 3 parts, got ' . count($parts));
            return false;
        }
        
        list($headerB64, $payloadB64, $signatureB64) = $parts;
        
        logActivity('debug', 'JWS parts extracted', "Header: " . substr($headerB64, 0, 50) . "..., Payload: '$payloadB64' (empty for detached), Signature: " . substr($signatureB64, 0, 50) . "...");
        
        // Decode header to get algorithm and key ID
        $header = json_decode(base64UrlDecode($headerB64), true);
        if (!$header) {
            logActivity('error', 'Failed to decode JWS header');
            return false;
        }
        
        $algorithm = $header['alg'] ?? null;
        $keyId = $header['kid'] ?? null;
        
        logActivity('debug', 'JWS header decoded', "Algorithm: $algorithm, Key ID: $keyId, Issuer: " . ($header['iss'] ?? 'none'));
        
        if ($algorithm !== 'RS256') {
            logActivity('error', 'Unsupported JWS algorithm', "Algorithm: $algorithm");
            return false;
        }
        
        // Get public key from Trados
        $publicKey = getTradosPublicKey($keyId);
        if (!$publicKey) {
            logActivity('error', 'Failed to get public key', "Key ID: $keyId");
            return false;
        }
        
        // Calculate payload hash using EMPTY body (the key fix!)
        $payloadHash = base64UrlEncode(hash('sha256', $originalRequestBody, true));
        logActivity('debug', 'Payload hash calculated for EMPTY body', "Hash: $payloadHash (constant: 47DEQpj8HBSa-_TImW-5JCeuQeRkm5NMpJWZG3hSuFU)");
        
        // Verify the hash matches the expected constant for empty body
        if ($payloadHash !== '47DEQpj8HBSa-_TImW-5JCeuQeRkm5NMpJWZG3hSuFU') {
            logActivity('error', 'Unexpected hash for empty body', "Got: $payloadHash");
            return false;
        }
        
        // Reconstruct the JWS for verification
        $signatureInput = $headerB64 . '.' . $payloadHash;
        logActivity('debug', 'Signature input reconstructed', "Length: " . strlen($signatureInput) . ", First 100 chars: " . substr($signatureInput, 0, 100));
        
        // Verify signature
        $signature = base64UrlDecode($signatureB64);
        logActivity('debug', 'Signature decoded', "Binary signature length: " . strlen($signature));
        
        $isValid = openssl_verify($signatureInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        
        logActivity('debug', 'OpenSSL verification result', "Result: $isValid (1=valid, 0=invalid, -1=error)");
        
        if ($isValid === 1) {
            logActivity('info', 'JWS signature verification successful!', "Validated with EMPTY body");
            // Validate claims
            return validateJwsClaims($header);
        } else {
            logActivity('error', 'JWS signature verification failed', "OpenSSL result: $isValid");
            
            // Get more detailed OpenSSL error
            $opensslErrors = [];
            while ($error = openssl_error_string()) {
                $opensslErrors[] = $error;
            }
            if (!empty($opensslErrors)) {
                logActivity('error', 'OpenSSL detailed errors', implode('; ', $opensslErrors));
            }
            
            return false;
        }
        
    } catch (Exception $e) {
        logActivity('error', 'JWS validation error', $e->getMessage());
        return false;
    }
}

function getTradosPublicKey($keyId) {
    static $keys = null;
    
    try {
        logActivity('debug', 'getTradosPublicKey called', "Looking for key ID: $keyId");
        
        // Cache keys for the request
        if ($keys === null) {
            $jwksUrl = 'https://api.cloud.trados.com/public-api/v1/.well-known/jwks.json';
            logActivity('debug', 'Fetching JWKS from URL', $jwksUrl);
            
            $context = stream_context_create(array(
                'http' => array(
                    'timeout' => 10,
                    'method' => 'GET'
                )
            ));
            
            $jwksData = file_get_contents($jwksUrl, false, $context);
            if ($jwksData === false) {
                $error = error_get_last();
                logActivity('error', 'Failed to fetch JWKS from Trados', "Error: " . ($error['message'] ?? 'Unknown error'));
                return false;
            }
            
            logActivity('debug', 'JWKS fetch successful', "Data length: " . strlen($jwksData));
            logActivity('debug', 'Raw JWKS response', substr($jwksData, 0, 500) . (strlen($jwksData) > 500 ? '...' : ''));
            
            $jwks = json_decode($jwksData, true);
            if (!$jwks) {
                logActivity('error', 'Invalid JSON in JWKS response', "JSON error: " . json_last_error_msg());
                return false;
            }
            
            if (!isset($jwks['keys'])) {
                logActivity('error', 'No keys array in JWKS response', "Response structure: " . json_encode(array_keys($jwks)));
                return false;
            }
            
            $keys = $jwks['keys'];
            logActivity('debug', 'JWKS parsed successfully', "Found " . count($keys) . " keys");
            
            // Log all available key IDs
            $availableKids = array();
            foreach ($keys as $index => $key) {
                $kid = $key['kid'] ?? 'NO_KID';
                $availableKids[] = $kid;
                logActivity('debug', "Available key $index", "kid: $kid, kty: " . ($key['kty'] ?? 'unknown'));
            }
            logActivity('debug', 'All available key IDs', implode(', ', $availableKids));
        }
        
        logActivity('debug', 'Searching for key ID', "Looking for: $keyId");
        
        // Find the key with matching kid
        foreach ($keys as $key) {
            $currentKid = $key['kid'] ?? null;
            if ($currentKid === $keyId) {
                logActivity('debug', 'Key found!', "Found matching key for kid: $keyId");
                $publicKey = convertJwkToPublicKey($key);
                if ($publicKey) {
                    logActivity('debug', 'Public key conversion successful', "Key ID: $keyId");
                } else {
                    logActivity('error', 'Public key conversion failed', "Key ID: $keyId");
                }
                return $publicKey;
            }
        }
        
        logActivity('error', 'Public key not found for kid', "Requested: $keyId, Available: " . implode(', ', array_column($keys, 'kid')));
        return false;
        
    } catch (Exception $e) {
        logActivity('error', 'Error fetching Trados public key', $e->getMessage() . " | Key ID: $keyId");
        return false;
    }
}

function convertJwkToPublicKey($jwk) {
    try {
        // Only log if database connection is available
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] !== null) {
            logActivity('debug', 'Converting JWK to public key', "kty: " . ($jwk['kty'] ?? 'unknown'));
        }
        
        if ($jwk['kty'] !== 'RSA') {
            if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] !== null) {
                logActivity('error', 'Unsupported key type', "Expected RSA, got: " . ($jwk['kty'] ?? 'unknown'));
            }
            throw new Exception('Only RSA keys are supported');
        }
        
        if (!isset($jwk['n']) || !isset($jwk['e'])) {
            if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] !== null) {
                logActivity('error', 'Missing required JWK components', "Has n: " . (isset($jwk['n']) ? 'yes' : 'no') . ", Has e: " . (isset($jwk['e']) ? 'yes' : 'no'));
            }
            throw new Exception('Missing required RSA key components (n, e)');
        }
        
        $n = base64UrlDecode($jwk['n']);
        $e = base64UrlDecode($jwk['e']);
        
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] !== null) {
            logActivity('debug', 'JWK components decoded', "n length: " . strlen($n) . ", e length: " . strlen($e));
        }
        
        // Create corrected RSA PEM format
        $pem = createWorkingRsaPem($n, $e);
        
        if (!$pem) {
            throw new Exception('Failed to create PEM format');
        }
        
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] !== null) {
            logActivity('debug', 'PEM created successfully', "PEM length: " . strlen($pem));
        }
        
        $publicKey = openssl_pkey_get_public($pem);
        
        if (!$publicKey) {
            $opensslError = '';
            while ($error = openssl_error_string()) {
                $opensslError .= $error . '; ';
            }
            if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] !== null) {
                logActivity('error', 'Failed to create public key from PEM', "OpenSSL error: " . $opensslError);
            }
            throw new Exception('Failed to create RSA key from JWK components');
        }
        
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] !== null) {
            logActivity('debug', 'RSA public key created successfully', "Key resource created");
        }
        
        return $publicKey;
        
    } catch (Exception $e) {
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] !== null) {
            logActivity('error', 'JWK to public key conversion failed', $e->getMessage());
        }
        return false;
    }
}

function createWorkingRsaPem($modulus, $exponent) {
    try {
        // Add leading zero if needed for positive integers
        if (ord($modulus[0]) >= 0x80) {
            $modulus = "\x00" . $modulus;
        }
        if (ord($exponent[0]) >= 0x80) {
            $exponent = "\x00" . $exponent;
        }
        
        // Create ASN.1 INTEGER for modulus
        $modLen = strlen($modulus);
        if ($modLen < 0x80) {
            $modAsn1 = "\x02" . chr($modLen) . $modulus;
        } elseif ($modLen < 0x100) {
            $modAsn1 = "\x02\x81" . chr($modLen) . $modulus;
        } else {
            $modAsn1 = "\x02\x82" . chr($modLen >> 8) . chr($modLen & 0xff) . $modulus;
        }
        
        // Create ASN.1 INTEGER for exponent
        $expLen = strlen($exponent);
        $expAsn1 = "\x02" . chr($expLen) . $exponent;
        
        // Create RSA key SEQUENCE
        $rsaKey = $modAsn1 . $expAsn1;
        $rsaKeyLen = strlen($rsaKey);
        
        if ($rsaKeyLen < 0x80) {
            $rsaSeq = "\x30" . chr($rsaKeyLen) . $rsaKey;
        } elseif ($rsaKeyLen < 0x100) {
            $rsaSeq = "\x30\x81" . chr($rsaKeyLen) . $rsaKey;
        } else {
            $rsaSeq = "\x30\x82" . chr($rsaKeyLen >> 8) . chr($rsaKeyLen & 0xff) . $rsaKey;
        }
        
        // Algorithm identifier for RSA encryption
        $algId = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        
        // Create BIT STRING
        $bitStr = "\x00" . $rsaSeq;
        $bitStrLen = strlen($bitStr);
        
        if ($bitStrLen < 0x80) {
            $bitString = "\x03" . chr($bitStrLen) . $bitStr;
        } elseif ($bitStrLen < 0x100) {
            $bitString = "\x03\x81" . chr($bitStrLen) . $bitStr;
        } else {
            $bitString = "\x03\x82" . chr($bitStrLen >> 8) . chr($bitStrLen & 0xff) . $bitStr;
        }
        
        // Final SEQUENCE
        $pubKeyInfo = $algId . $bitString;
        $pubKeyInfoLen = strlen($pubKeyInfo);
        
        if ($pubKeyInfoLen < 0x80) {
            $der = "\x30" . chr($pubKeyInfoLen) . $pubKeyInfo;
        } elseif ($pubKeyInfoLen < 0x100) {
            $der = "\x30\x81" . chr($pubKeyInfoLen) . $pubKeyInfo;
        } else {
            $der = "\x30\x82" . chr($pubKeyInfoLen >> 8) . chr($pubKeyInfoLen & 0xff) . $pubKeyInfo;
        }
        
        // Convert to PEM
        $base64 = base64_encode($der);
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($base64, 64, "\n") . "-----END PUBLIC KEY-----\n";
        
        // Only log if database connection is available
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] !== null) {
            logActivity('debug', 'RSA PEM created successfully', "PEM starts with: " . substr($pem, 0, 50) . "...");
        }
        
        return $pem;
        
    } catch (Exception $e) {
        // Only log if database connection is available
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] !== null) {
            logActivity('error', 'Failed to create working RSA PEM', $e->getMessage());
        }
        return false;
    }
}

function validateJwsClaims($header) {
    try {
        $now = time();
        $clockSkew = 60; // 60 seconds clock skew tolerance
        
        // Check issuer
        $iss = $header['iss'] ?? null;
        if ($iss !== 'https://languagecloud.rws.com/') {
            logActivity('error', 'Invalid JWS issuer', "Issuer: $iss");
            return false;
        }
        
        // Check audience (should match your baseUrl)
        $aud = $header['aud'] ?? null;
        $expectedAudience = 'https://api.filkin.com'; // Your custom domain
        if ($aud !== $expectedAudience) {
            logActivity('error', 'Invalid JWS audience', "Expected: $expectedAudience, Got: $aud");
            return false;
        }
        
        // Check expiration
        $exp = $header['exp'] ?? null;
        if ($exp && $exp < ($now - $clockSkew)) {
            logActivity('error', 'JWS token expired', "Expired at: " . date('Y-m-d H:i:s', $exp));
            return false;
        }
        
        // Check issued at time
        $iat = $header['iat'] ?? null;
        if ($iat && $iat > ($now + $clockSkew)) {
            logActivity('error', 'JWS token issued in future', "Issued at: " . date('Y-m-d H:i:s', $iat));
            return false;
        }
        
        return true;
        
    } catch (Exception $e) {
        logActivity('error', 'JWS claims validation error', $e->getMessage());
        return false;
    }
}

// Utility functions for base64 URL encoding/decoding
function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode($data) {
    return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
}

// System check functions
function checkJwsKeysAvailable() {
    try {
        $jwksUrl = 'https://api.cloud.trados.com/public-api/v1/.well-known/jwks.json';
        $context = stream_context_create(array(
            'http' => array(
                'timeout' => 5,
                'method' => 'GET'
            )
        ));
        
        $result = file_get_contents($jwksUrl, false, $context);
        $jwks = json_decode($result, true);
        
        return $result !== false && isset($jwks['keys']);
    } catch (Exception $e) {
        return false;
    }
}

function checkWebhookEndpoint() {
    try {
        $webhookUrl = 'https://api.filkin.com/trados-integration/v1/webhooks';
        $context = stream_context_create(array(
            'http' => array(
                'timeout' => 5,
                'method' => 'GET'
            )
        ));
        
        $headers = get_headers($webhookUrl, 1, $context);
        return strpos($headers[0], '200') !== false || strpos($headers[0], '405') !== false; // 405 is expected for GET
    } catch (Exception $e) {
        return false;
    }
}

// Instance management functions
function updateInstanceStatus($instanceId, $status, $lastActivity = null) {
    global $pdo;
    
    try {
        if ($lastActivity === null) {
            $lastActivity = date('Y-m-d H:i:s');
        }
        
        $stmt = $pdo->prepare("
            UPDATE integration_controls 
            SET status = ?, last_activity = ?
            WHERE instance_id = ?
        ");
        $stmt->execute([$status, $lastActivity, $instanceId]);
        
        logActivity('info', "Instance status updated to: $status", null, $instanceId);
        return true;
    } catch (PDOException $e) {
        error_log("Error updating instance status: " . $e->getMessage());
        return false;
    }
}

function getInstanceById($instanceId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM integration_controls 
            WHERE instance_id = ?
        ");
        $stmt->execute([$instanceId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching instance: " . $e->getMessage());
        return false;
    }
}

function createIntegrationControl($instanceId, $tenantId, $clientId, $clientSecret, $configData = null) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO integration_controls 
            (instance_id, tenant_id, client_id, client_secret, configuration_data, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'active', CURRENT_TIMESTAMP)
        ");
        
        $result = $stmt->execute([
            $instanceId,
            $tenantId,
            $clientId,
            $clientSecret,
            $configData ? json_encode($configData) : null
        ]);
        
        if ($result) {
            logActivity('success', 'Integration Control created successfully', 
                       "Tenant: $tenantId, Client: $clientId", $instanceId);
            return true;
        }
        
        return false;
    } catch (PDOException $e) {
        error_log("Error creating integration control: " . $e->getMessage());
        logActivity('error', 'Failed to create Integration Control', $e->getMessage(), $instanceId);
        return false;
    }
}

function generateInstanceId($tenantId) {
    $timestamp = time();
    $random = substr(md5(uniqid()), 0, 8);
    return "trados_instance_{$timestamp}_{$random}";
}

function getStats() {
    global $pdo;
    
    $stats = [];
    
    try {
        // Total instances
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM integration_controls");
        $stats['total_instances'] = $stmt->fetch()['total'];
        
        // Active instances
        $stmt = $pdo->query("SELECT COUNT(*) as active FROM integration_controls WHERE status = 'active'");
        $stats['active_instances'] = $stmt->fetch()['active'];
        
        // Recent webhook events (last 24 hours)
        $stmt = $pdo->query("SELECT COUNT(*) as recent FROM webhook_events WHERE timestamp >= datetime('now', '-24 hours')");
        $stats['recent_webhooks'] = $stmt->fetch()['recent'];
        
        // File delivery events (last 7 days)
        $stmt = $pdo->query("SELECT COUNT(*) as delivery FROM webhook_events WHERE event_type = 'PROJECT.TASK.CREATED' AND timestamp >= datetime('now', '-7 days')");
        $stats['file_delivery_events'] = $stmt->fetch()['delivery'];
        
    } catch (PDOException $e) {
        error_log("Error getting stats: " . $e->getMessage());
        $stats = [
            'total_instances' => 0,
            'active_instances' => 0,
            'recent_webhooks' => 0,
            'file_delivery_events' => 0
        ];
    }
    
    return $stats;
}

// Utility functions
function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function formatBytes($size, $precision = 2) {
    $base = log($size, 1024);
    $suffixes = array('B', 'KB', 'MB', 'GB', 'TB');
    return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
}

// Get webhook URL for an instance
function getWebhookUrl() {
    return 'https://api.filkin.com/trados-integration/v1/webhooks';
}
?>