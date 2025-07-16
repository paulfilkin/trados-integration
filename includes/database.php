<?php
// SQLite database configuration - no MySQL/server required!

// Set timezone to Germany
date_default_timezone_set('Europe/Berlin');

$database_file = __DIR__ . '/../data/trados.db';

// Create data directory if it doesn't exist
$data_dir = dirname($database_file);
if (!is_dir($data_dir)) {
    mkdir($data_dir, 0755, true);
}

try {
    $pdo = new PDO("sqlite:$database_file");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Create tables if they don't exist
    createDatabaseTables($pdo);
    
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die(json_encode(['error' => 'Database connection failed', 'message' => $e->getMessage()]));
}

function createDatabaseTables($pdo) {
    // Create integration_controls table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS integration_controls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            instance_id TEXT NOT NULL UNIQUE,
            tenant_id TEXT NOT NULL,
            client_id TEXT NOT NULL,
            client_secret TEXT NOT NULL,
            configuration_data TEXT,
            status TEXT DEFAULT 'pending' CHECK(status IN ('active', 'pending', 'error', 'inactive')),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_activity DATETIME,
            metadata TEXT
        )
    ");
    
    // Create activity_logs table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS activity_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            instance_id TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            level TEXT DEFAULT 'info' CHECK(level IN ('success', 'info', 'warning', 'error')),
            message TEXT NOT NULL,
            details TEXT,
            request_data TEXT,
            response_data TEXT,
            user_agent TEXT,
            ip_address TEXT
        )
    ");
    
    // Create webhook_events table for tracking webhook calls
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS webhook_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            instance_id TEXT,
            event_type TEXT NOT NULL,
            payload TEXT,
            headers TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            processed INTEGER DEFAULT 0,
            processing_result TEXT
        )
    ");
    
    // Create system_config table for storing configuration
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_config (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            config_key TEXT NOT NULL UNIQUE,
            config_value TEXT,
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // Insert default configuration values if they don't exist
    $configs = [
        ['hmac_secret_key', 'your-super-secret-hmac-key-here', 'HMAC secret key for request validation'],
        ['proxy_enabled', '1', 'Enable/disable proxy functionality'],
        ['log_retention_days', '30', 'Number of days to retain activity logs'],
        ['auto_cleanup_enabled', '1', 'Enable automatic cleanup of old logs'],
        ['dashboard_refresh_interval', '30', 'Dashboard auto-refresh interval in seconds'],
        ['webhook_timeout', '30', 'Webhook request timeout in seconds'],
        ['max_retry_attempts', '3', 'Maximum retry attempts for failed webhooks']
    ];
    
    foreach ($configs as $config) {
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO system_config (config_key, config_value, description) VALUES (?, ?, ?)");
        $stmt->execute($config);
    }
}

function getConfigValue($key, $default = null) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        
        return $result ? $result['config_value'] : $default;
    } catch (PDOException $e) {
        error_log("Error getting config value: " . $e->getMessage());
        return $default;
    }
}

function setConfigValue($key, $value, $description = null) {
    global $pdo;
    
    try {
        if ($description) {
            $stmt = $pdo->prepare("
                INSERT OR REPLACE INTO system_config (config_key, config_value, description, updated_at) 
                VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$key, $value, $description]);
        } else {
            $stmt = $pdo->prepare("
                INSERT OR REPLACE INTO system_config (config_key, config_value, updated_at) 
                VALUES (?, ?, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$key, $value]);
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Error setting config value: " . $e->getMessage());
        return false;
    }
}

function testDatabaseConnection() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("SELECT 1 as test");
        $result = $stmt->fetch();
        return $result['test'] === 1;
    } catch (PDOException $e) {
        error_log("Database connection test failed: " . $e->getMessage());
        return false;
    }
}

function getDatabaseInfo() {
    global $pdo, $database_file;
    
    $info = [];
    
    try {
        // Get SQLite version
        $stmt = $pdo->query("SELECT sqlite_version() as version");
        $info['version'] = 'SQLite ' . $stmt->fetch()['version'];
        
        // Get table counts
        $tables = ['integration_controls', 'activity_logs', 'webhook_events', 'system_config'];
        foreach ($tables as $table) {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
            $info['table_counts'][$table] = $stmt->fetch()['count'];
        }
        
        // Get database file size
        if (file_exists($database_file)) {
            $info['size_mb'] = round(filesize($database_file) / 1024 / 1024, 2);
        } else {
            $info['size_mb'] = 0;
        }
        
    } catch (PDOException $e) {
        error_log("Error getting database info: " . $e->getMessage());
        $info['error'] = $e->getMessage();
    }
    
    return $info;
}

function optimizeTables() {
    global $pdo;
    
    try {
        $pdo->exec("VACUUM");
        return ['status' => 'success', 'message' => 'Database optimized'];
    } catch (PDOException $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}
?>