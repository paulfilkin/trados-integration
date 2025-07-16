<?php
// Database viewer script - shows all tables and their contents
require_once 'includes/database.php';

$selectedTable = $_GET['table'] ?? null;
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Viewer - Trados Integration</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .nav {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .nav a {
            display: inline-block;
            margin: 5px 10px;
            padding: 8px 15px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            transition: all 0.2s ease;
        }

        .nav a:hover, .nav a.active {
            background: #0056b3;
            transform: translateY(-2px);
        }

        .content {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        th, td {
            text-align: left;
            padding: 8px 12px;
            border-bottom: 1px solid #e5e7eb;
            word-break: break-word;
            max-width: 300px;
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #374151;
            position: sticky;
            top: 0;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .json-cell {
            max-width: 200px;
            overflow: hidden;
            position: relative;
        }

        .json-preview {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #6b7280;
            font-family: monospace;
            font-size: 0.8rem;
        }

        .json-full {
            display: none;
            background: #f3f4f6;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 10px;
            margin-top: 5px;
            white-space: pre-wrap;
            font-family: monospace;
            font-size: 0.8rem;
            max-height: 200px;
            overflow: auto;
        }

        .expand-btn {
            background: #6b7280;
            color: white;
            border: none;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.7rem;
            cursor: pointer;
            margin-left: 5px;
        }

        .expand-btn:hover {
            background: #4b5563;
        }

        .pagination {
            margin-top: 20px;
            text-align: center;
        }

        .pagination a {
            display: inline-block;
            margin: 0 5px;
            padding: 8px 12px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
        }

        .pagination a:hover {
            background: #0056b3;
        }

        .pagination .current {
            background: #28a745;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #007bff;
        }

        .timestamp {
            font-size: 0.8rem;
            color: #6b7280;
        }

        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .status-active { background: #d1fae5; color: #065f46; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-error { background: #fee2e2; color: #991b1b; }
        .status-inactive { background: #f3f4f6; color: #374151; }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            padding: 10px 20px;
            background: #6c757d;
            color: white;
            text-decoration: none;
            border-radius: 6px;
        }

        .back-link:hover {
            background: #5a6268;
        }

        .error {
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🗄️ Database Viewer</h1>
            <p>Browse all tables and data in your Trados integration database</p>
            <a href="index.php" class="back-link">← Back to Dashboard</a>
        </div>

        <div class="nav">
            <strong>Tables:</strong>
            <a href="?table=integration_controls" <?php echo $selectedTable === 'integration_controls' ? 'class="active"' : ''; ?>>
                Integration Controls
            </a>
            <a href="?table=activity_logs" <?php echo $selectedTable === 'activity_logs' ? 'class="active"' : ''; ?>>
                Activity Logs
            </a>
            <a href="?table=webhook_events" <?php echo $selectedTable === 'webhook_events' ? 'class="active"' : ''; ?>>
                Webhook Events
            </a>
            <a href="?table=system_config" <?php echo $selectedTable === 'system_config' ? 'class="active"' : ''; ?>>
                System Config
            </a>
            <a href="?" <?php echo !$selectedTable ? 'class="active"' : ''; ?>>
                Overview
            </a>
        </div>

        <div class="content">
            <?php if (!$selectedTable): ?>
                <!-- Database Overview -->
                <h2>Database Overview</h2>
                
                <?php
                // Get table stats with error handling
                $tables = ['integration_controls', 'activity_logs', 'webhook_events', 'system_config'];
                $stats = [];
                
                foreach ($tables as $table) {
                    try {
                        $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$table`");
                        $stats[$table] = $stmt->fetch()['count'];
                    } catch (PDOException $e) {
                        $stats[$table] = 'Error';
                        echo "<div class='error'>Error getting count for table '$table': " . htmlspecialchars($e->getMessage()) . "</div>";
                    }
                }
                
                // Get database info with error handling
                try {
                    $stmt = $pdo->query("SELECT sqlite_version() as version");
                    $version = 'SQLite ' . $stmt->fetch()['version'];
                    $database_file = __DIR__ . '/data/trados.db';
                    $size_mb = file_exists($database_file) ? round(filesize($database_file) / 1024 / 1024, 2) : 0;
                } catch (Exception $e) {
                    $version = 'Unknown';
                    $size_mb = 0;
                    echo "<div class='error'>Error getting database info: " . htmlspecialchars($e->getMessage()) . "</div>";
                }
                ?>
                
                <div class="stats">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['integration_controls']; ?></div>
                        <div>Integration Controls</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['activity_logs']; ?></div>
                        <div>Activity Logs</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['webhook_events']; ?></div>
                        <div>Webhook Events</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['system_config']; ?></div>
                        <div>Config Settings</div>
                    </div>
                </div>

                <h3>Database Information</h3>
                <table>
                    <tr><td><strong>Version:</strong></td><td><?php echo htmlspecialchars($version); ?></td></tr>
                    <tr><td><strong>Size:</strong></td><td><?php echo $size_mb; ?> MB</td></tr>
                    <tr><td><strong>Location:</strong></td><td><?php echo htmlspecialchars(realpath(__DIR__ . '/data/trados.db') ?: 'File not found'); ?></td></tr>
                </table>

            <?php else: ?>
                <!-- Table Content -->
                <?php
                try {
                    // Validate table name (security)
                    $allowedTables = ['integration_controls', 'activity_logs', 'webhook_events', 'system_config', 'webhook_subscriptions', 'webhook_delivery_log'];
                    if (!in_array($selectedTable, $allowedTables)) {
                        throw new Exception("Invalid table name");
                    }

                    // Get total count for pagination
                    $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM `$selectedTable`");
                    $countStmt->execute();
                    $totalRows = $countStmt->fetch()['total'];
                    $totalPages = ceil($totalRows / $perPage);

                    // Get table structure to determine what columns exist
                    $structStmt = $pdo->query("PRAGMA table_info(`$selectedTable`)");
                    $columns = $structStmt->fetchAll(PDO::FETCH_ASSOC);
                    $columnNames = array_column($columns, 'name');

                    // Determine ORDER BY clause based on available columns
                    $orderBy = 'rowid'; // Default fallback
                    if (in_array('timestamp', $columnNames)) {
                        $orderBy = 'timestamp';
                    } elseif (in_array('created_at', $columnNames)) {
                        $orderBy = 'created_at';
                    } elseif (in_array('id', $columnNames)) {
                        $orderBy = 'id';
                    }

                    // Get table data with pagination and safe ordering
                    $stmt = $pdo->prepare("SELECT * FROM `$selectedTable` ORDER BY `$orderBy` DESC LIMIT ? OFFSET ?");
                    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
                    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
                    $stmt->execute();
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if (empty($rows)) {
                        echo "<p>No data found in table: <strong>" . htmlspecialchars($selectedTable) . "</strong></p>";
                    } else {
                        echo "<h2>Table: " . htmlspecialchars($selectedTable) . "</h2>";
                        echo "<p>Showing " . count($rows) . " of $totalRows total rows (ordered by $orderBy)</p>";
                        
                        // Get column names
                        $tableColumns = array_keys($rows[0]);
                        
                        echo "<table>";
                        echo "<thead><tr>";
                        foreach ($tableColumns as $column) {
                            echo "<th>" . htmlspecialchars($column) . "</th>";
                        }
                        echo "</tr></thead>";
                        echo "<tbody>";
                        
                        foreach ($rows as $row) {
                            echo "<tr>";
                            foreach ($tableColumns as $column) {
                                $value = $row[$column];
                                echo "<td>";
                                
                                if ($value === null) {
                                    echo "<em style='color: #9ca3af;'>NULL</em>";
                                } elseif ($column === 'status') {
                                    echo "<span class='status-badge status-$value'>" . ucfirst(htmlspecialchars($value)) . "</span>";
                                } elseif (in_array($column, ['timestamp', 'created_at', 'last_activity', 'updated_at']) && $value) {
                                    echo "<span class='timestamp'>" . date('M j, Y H:i:s', strtotime($value)) . "</span>";
                                } elseif (in_array($column, ['payload', 'headers', 'configuration_data', 'metadata']) && $value) {
                                    // JSON fields
                                    $jsonData = json_decode($value, true);
                                    if ($jsonData) {
                                        $preview = json_encode($jsonData);
                                        $shortPreview = strlen($preview) > 50 ? substr($preview, 0, 50) . '...' : $preview;
                                        
                                        echo "<div class='json-cell'>";
                                        echo "<span class='json-preview'>" . htmlspecialchars($shortPreview) . "</span>";
                                        echo "<button class='expand-btn' onclick='toggleJson(this)'>+</button>";
                                        echo "<div class='json-full'>" . htmlspecialchars(json_encode($jsonData, JSON_PRETTY_PRINT)) . "</div>";
                                        echo "</div>";
                                    } else {
                                        echo htmlspecialchars($value);
                                    }
                                } elseif (in_array($column, ['client_secret']) && $value) {
                                    // Hide sensitive data
                                    echo "<code>" . substr($value, 0, 10) . "..." . substr($value, -4) . "</code>";
                                } elseif (strlen($value) > 100) {
                                    // Long text fields
                                    echo "<span title='" . htmlspecialchars($value) . "'>" . 
                                         htmlspecialchars(substr($value, 0, 100)) . "...</span>";
                                } else {
                                    echo htmlspecialchars($value);
                                }
                                
                                echo "</td>";
                            }
                            echo "</tr>";
                        }
                        
                        echo "</tbody></table>";
                        
                        // Pagination
                        if ($totalPages > 1) {
                            echo "<div class='pagination'>";
                            
                            if ($page > 1) {
                                echo "<a href='?table=" . urlencode($selectedTable) . "&page=" . ($page - 1) . "'>← Previous</a>";
                            }
                            
                            for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++) {
                                $class = ($i == $page) ? 'current' : '';
                                echo "<a href='?table=" . urlencode($selectedTable) . "&page=$i' class='$class'>$i</a>";
                            }
                            
                            if ($page < $totalPages) {
                                echo "<a href='?table=" . urlencode($selectedTable) . "&page=" . ($page + 1) . "'>Next →</a>";
                            }
                            
                            echo "</div>";
                        }
                    }
                    
                } catch (PDOException $e) {
                    echo "<div class='error'>Database error: " . htmlspecialchars($e->getMessage()) . "</div>";
                } catch (Exception $e) {
                    echo "<div class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
                }
                ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleJson(button) {
            const jsonFull = button.nextElementSibling;
            if (jsonFull.style.display === 'none' || !jsonFull.style.display) {
                jsonFull.style.display = 'block';
                button.textContent = '−';
            } else {
                jsonFull.style.display = 'none';
                button.textContent = '+';
            }
        }
    </script>
</body>
</html>