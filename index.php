<?php
// Trados Integration Dashboard with JWS support - Updated without API key management
require_once 'includes/database.php';
require_once 'includes/functions.php';

// Get all provisioning instances from the database
$instances = getProvisioningInstances();
$totalInstances = count($instances);
$activeInstances = array_filter($instances, function($instance) {
    return $instance['status'] === 'active';
});
$totalActiveInstances = count($activeInstances);

// Get recent activity logs from database
$recentLogs = getRecentLogs(10);

// Get system status
$systemStatus = getSystemStatus();

// Get statistics from database
$stats = getStats();

// Get detailed statistics from database
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM activity_logs WHERE timestamp >= datetime('now', '-24 hours')");
    $recentWebhooks = $stmt->fetch()['count'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM webhook_events WHERE timestamp >= datetime('now', '-7 days')");
    $fileDeliveryEvents = $stmt->fetch()['count'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM activity_logs WHERE level = 'error' AND timestamp >= datetime('now', '-24 hours')");
    $recentErrors = $stmt->fetch()['count'] ?? 0;
    
} catch (Exception $e) {
    $recentWebhooks = 0;
    $fileDeliveryEvents = 0;
    $recentErrors = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trados Integration Dashboard</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .header h1 {
            color: #333;
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .header p {
            color: #666;
            font-size: 1.1rem;
            margin-bottom: 20px;
        }

        .badge {
            background: #28a745;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            margin: 0 5px;
        }

        .badge.error {
            background: #dc3545;
        }

        .header-actions {
            margin-top: 20px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card .icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            display: block;
        }

        .stat-card.total .icon { color: #4f46e5; }
        .stat-card.active .icon { color: #10b981; }
        .stat-card.webhooks .icon { color: #f59e0b; }
        .stat-card.errors .icon { color: #ef4444; }

        .stat-card h3 {
            color: #333;
            font-size: 2rem;
            margin-bottom: 5px;
        }

        .stat-card p {
            color: #666;
            font-size: 0.9rem;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .panel {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .panel h2 {
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e5e7eb;
        }

        .instances-table {
            width: 100%;
            border-collapse: collapse;
        }

        .instances-table th,
        .instances-table td {
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
        }

        .instances-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: uppercase;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-error {
            background: #fee2e2;
            color: #991b1b;
        }

        .system-status {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .status-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 15px;
            background: #f9fafb;
            border-radius: 8px;
        }

        .status-icon {
            font-size: 1.2rem;
        }

        .status-icon.healthy { color: #10b981; }
        .status-icon.warning { color: #f59e0b; }
        .status-icon.error { color: #ef4444; }

        .log-entry {
            padding: 15px;
            border-left: 4px solid #e5e7eb;
            margin-bottom: 15px;
            background: #f9fafb;
            border-radius: 0 8px 8px 0;
        }

        .log-entry.info {
            border-left-color: #10b981;
            background: #f0fdf4;
        }

        .log-entry.error {
            border-left-color: #ef4444;
            background: #fef2f2;
        }

        .log-entry.warning {
            border-left-color: #f59e0b;
            background: #fffbeb;
        }

        .log-entry.debug {
            border-left-color: #6b7280;
            background: #f9fafb;
        }

        .log-time {
            font-size: 0.8rem;
            color: #6b7280;
            margin-bottom: 5px;
        }

        .log-message {
            color: #374151;
            font-weight: 500;
        }

        .refresh-button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .refresh-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .refresh-button.secondary {
            background: #6b7280;
        }

        .refresh-button.secondary:hover {
            background: #4b5563;
        }

        .quick-actions {
            margin-top: 20px;
            text-align: center;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 20px;
            display: block;
            opacity: 0.5;
        }

        .instance-link {
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            padding: 0;
            text-decoration: underline;
            text-decoration-color: #6b7280;
        }

        .instance-link:hover {
            color: #4f46e5;
            text-decoration-color: #4f46e5;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 30px;
            border-radius: 15px 15px 0 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.5rem;
        }

        .modal-body {
            padding: 30px;
        }

        .detail-grid {
            display: grid;
            gap: 20px;
        }

        .detail-card {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
        }

        .detail-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .detail-value {
            font-family: 'Monaco', 'Menlo', monospace;
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 12px;
            font-size: 0.85rem;
            word-break: break-all;
            position: relative;
        }

        .copy-button {
            position: absolute;
            top: 8px;
            right: 8px;
            background: #6b7280;
            color: white;
            border: none;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            cursor: pointer;
            opacity: 0.7;
        }

        .copy-button:hover {
            opacity: 1;
            background: #4b5563;
        }

        .close {
            color: white;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            opacity: 0.8;
        }

        .close:hover {
            opacity: 1;
        }

        .webhook-info {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }

        .webhook-info h4 {
            color: #1e40af;
            margin-bottom: 10px;
            font-size: 1rem;
        }

        .webhook-info p {
            color: #1e40af;
            font-size: 0.9rem;
            margin: 0;
        }
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }

            .header-actions {
                display: flex;
                flex-direction: column;
                gap: 10px;
                align-items: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-plug"></i> Trados Integration Dashboard</h1>
            <p>JWS-based webhook integration for file delivery automation</p>
            <div>
                <span class="badge">JWS Authentication</span>
                <?php if ($recentErrors > 0): ?>
                    <span class="badge error"><?php echo $recentErrors; ?> Recent Errors</span>
                <?php endif; ?>
            </div>
            
            <div class="header-actions">
                <button class="refresh-button" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i> Refresh Data
                </button>
                <a href="database-viewer.php" class="refresh-button secondary">
                    <i class="fas fa-database"></i> View Database Details
                </a>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card total">
                <i class="fas fa-database icon"></i>
                <h3><?php echo $totalInstances; ?></h3>
                <p>Total Integrations</p>
            </div>
            
            <div class="stat-card active">
                <i class="fas fa-check-circle icon"></i>
                <h3><?php echo $totalActiveInstances; ?></h3>
                <p>Active Instances</p>
            </div>
            
            <div class="stat-card webhooks">
                <i class="fas fa-bolt icon"></i>
                <h3><?php echo $recentWebhooks; ?></h3>
                <p>Recent Activity (24h)</p>
            </div>
            
            <div class="stat-card errors">
                <i class="fas fa-exclamation-triangle icon"></i>
                <h3><?php echo $recentErrors; ?></h3>
                <p>Recent Errors (24h)</p>
            </div>
        </div>

        <div class="content-grid">
            <div class="panel">
                <h2><i class="fas fa-list"></i> Integration Instances</h2>
                
                <?php if (empty($instances)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p><strong>No integration instances found yet.</strong></p>
                        <p style="font-size: 0.9rem; margin-top: 10px;">Install your Trados addon to create your first integration.</p>
                    </div>
                <?php else: ?>
                    <table class="instances-table">
                        <thead>
                            <tr>
                                <th>Instance ID</th>
                                <th>Tenant ID</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Last Activity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($instances as $instance): ?>
                                <tr>
                                    <td>
                                        <button class="instance-link" onclick="showInstanceDetails('<?php echo htmlspecialchars($instance['instance_id']); ?>')">
                                            <code style="font-size: 0.9rem;"><?php echo htmlspecialchars(substr($instance['instance_id'], 0, 20)) . '...'; ?></code>
                                        </button>
                                    </td>
                                    <td><code style="font-size: 0.9rem;"><?php echo htmlspecialchars($instance['tenant_id']); ?></code></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $instance['status']; ?>">
                                            <?php echo ucfirst($instance['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y H:i', strtotime($instance['created_at'])); ?></td>
                                    <td><?php echo $instance['last_activity'] ? date('M j, Y H:i', strtotime($instance['last_activity'])) : 'Never'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="panel">
                <h2><i class="fas fa-chart-line"></i> System Status</h2>
                
                <div class="system-status">
                    <div class="status-item">
                        <i class="fas fa-server status-icon <?php echo $systemStatus['database'] ? 'healthy' : 'error'; ?>"></i>
                        <div>
                            <div style="font-weight: 500;">Database</div>
                            <div style="font-size: 0.8rem; color: #6b7280;">
                                <?php echo $systemStatus['database'] ? 'Connected' : 'Disconnected'; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="status-item">
                        <i class="fas fa-key status-icon <?php echo $systemStatus['jws_keys'] ? 'healthy' : 'warning'; ?>"></i>
                        <div>
                            <div style="font-weight: 500;">JWS Keys</div>
                            <div style="font-size: 0.8rem; color: #6b7280;">
                                <?php echo $systemStatus['jws_keys'] ? 'Available' : 'Check connectivity'; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="status-item">
                        <i class="fas fa-globe status-icon <?php echo $systemStatus['webhook_endpoint'] ? 'healthy' : 'warning'; ?>"></i>
                        <div>
                            <div style="font-weight: 500;">Webhook Endpoint</div>
                            <div style="font-size: 0.8rem; color: #6b7280;">
                                <?php echo $systemStatus['webhook_endpoint'] ? 'Accessible' : 'Check connectivity'; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <h2 style="margin-top: 30px;"><i class="fas fa-history"></i> Recent Activity</h2>
                
                <div style="max-height: 400px; overflow-y: auto;">
                    <?php if (empty($recentLogs)): ?>
                        <div class="empty-state">
                            <i class="fas fa-file-alt"></i>
                            <p>No recent activity logs.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentLogs as $log): ?>
                            <div class="log-entry <?php echo $log['level']; ?>">
                                <div class="log-time">
                                    <?php echo date('M j, Y H:i:s', strtotime($log['timestamp'])); ?>
                                </div>
                                <div class="log-message">
                                    <?php echo htmlspecialchars($log['message']); ?>
                                </div>
                                <?php if (!empty($log['details'])): ?>
                                    <div style="font-size: 0.8rem; color: #6b7280; margin-top: 5px;">
                                        <?php echo htmlspecialchars(substr($log['details'], 0, 100)) . (strlen($log['details']) > 100 ? '...' : ''); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        
                        <div class="quick-actions">
                            <a href="database-viewer.php?table=activity_logs" class="refresh-button secondary">
                                <i class="fas fa-search"></i> View All Logs
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Instance Details Modal -->
    <div id="instanceModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span class="close" onclick="closeInstanceModal()">&times;</span>
                <h3><i class="fas fa-cog"></i> Instance Details</h3>
            </div>
            <div class="modal-body">
                <div class="detail-grid">
                    <div class="detail-card">
                        <div class="detail-label">Instance ID</div>
                        <div class="detail-value" id="modal-instance-id">
                            <button class="copy-button" onclick="copyToClipboard('modal-instance-id')">Copy</button>
                        </div>
                    </div>
                    
                    <div class="detail-card">
                        <div class="detail-label">Tenant ID</div>
                        <div class="detail-value" id="modal-tenant-id">
                            <button class="copy-button" onclick="copyToClipboard('modal-tenant-id')">Copy</button>
                        </div>
                    </div>
                    
                    <div class="detail-card">
                        <div class="detail-label">Client ID</div>
                        <div class="detail-value" id="modal-client-id">
                            <button class="copy-button" onclick="copyToClipboard('modal-client-id')">Copy</button>
                        </div>
                    </div>
                    
                    <div class="detail-card">
                        <div class="detail-label">Client Secret</div>
                        <div class="detail-value" id="modal-client-secret">
                            <button class="copy-button" onclick="copyToClipboard('modal-client-secret')">Copy</button>
                        </div>
                    </div>
                    
                    <div class="detail-card">
                        <div class="detail-label">Webhook URL</div>
                        <div class="detail-value" id="modal-webhook-url">
                            <button class="copy-button" onclick="copyToClipboard('modal-webhook-url')">Copy</button>
                        </div>
                        <div class="webhook-info">
                            <h4><i class="fas fa-info-circle"></i> Webhook Configuration</h4>
                            <p>This is the webhook URL configured in your Trados addon for receiving PROJECT.TASK.CREATED events.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Instance data for modal
        const instanceData = {
            <?php foreach ($instances as $instance): ?>
            '<?php echo addslashes($instance['instance_id']); ?>': {
                instanceId: '<?php echo addslashes($instance['instance_id']); ?>',
                tenantId: '<?php echo addslashes($instance['tenant_id']); ?>',
                clientId: '<?php echo addslashes($instance['client_id']); ?>',
                clientSecret: '<?php echo addslashes($instance['client_secret']); ?>',
                webhookUrl: '<?php echo addslashes(getWebhookUrl()); ?>'
            },
            <?php endforeach; ?>
        };

        function showInstanceDetails(instanceId) {
            const data = instanceData[instanceId];
            if (!data) return;

            document.getElementById('modal-instance-id').innerHTML = data.instanceId + '<button class="copy-button" onclick="copyToClipboard(\'modal-instance-id\')">Copy</button>';
            document.getElementById('modal-tenant-id').innerHTML = data.tenantId + '<button class="copy-button" onclick="copyToClipboard(\'modal-tenant-id\')">Copy</button>';
            document.getElementById('modal-client-id').innerHTML = data.clientId + '<button class="copy-button" onclick="copyToClipboard(\'modal-client-id\')">Copy</button>';
            document.getElementById('modal-client-secret').innerHTML = data.clientSecret + '<button class="copy-button" onclick="copyToClipboard(\'modal-client-secret\')">Copy</button>';
            document.getElementById('modal-webhook-url').innerHTML = data.webhookUrl + '<button class="copy-button" onclick="copyToClipboard(\'modal-webhook-url\')">Copy</button>';

            document.getElementById('instanceModal').style.display = 'block';
        }

        function closeInstanceModal() {
            document.getElementById('instanceModal').style.display = 'none';
        }

        function copyToClipboard(elementId) {
            const element = document.getElementById(elementId);
            const text = element.textContent.replace('Copy', '').trim();
            
            navigator.clipboard.writeText(text).then(function() {
                // Visual feedback
                const button = element.querySelector('.copy-button');
                const originalText = button.textContent;
                button.textContent = 'Copied!';
                button.style.background = '#10b981';
                
                setTimeout(() => {
                    button.textContent = originalText;
                    button.style.background = '#6b7280';
                }, 1000);
            }).catch(function() {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                alert('Copied to clipboard!');
            });
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('instanceModal');
            if (event.target == modal) {
                closeInstanceModal();
            }
        }

        // Auto-refresh every 30 seconds
        setTimeout(function() {
            location.reload();
        }, 30000);
        
        // Add some interactivity
        document.querySelectorAll('.stat-card').forEach(card => {
            card.addEventListener('click', function() {
                this.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    this.style.transform = 'translateY(-5px)';
                }, 100);
            });
        });
    </script>
</body>
</html>