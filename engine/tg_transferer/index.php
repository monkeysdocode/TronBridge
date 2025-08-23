<?php
// Load appropriate transferer based on Enhanced Model availability
require_once __DIR__. '/TransfererDetection.php';

$useEnhanced = TransfererDetection::hasEnhancedModel();

if ($useEnhanced) {
    require_once 'EnhancedTransferer.php';
    $transferer = new EnhancedTransferer();
} else {
    require_once 'Transferer.php';
    $transferer = new Transferer();
}

$current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] .  $_SERVER['REQUEST_URI'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transferer->process_post();
    die();
}

// Get enhanced capabilities if available
$capabilities = $useEnhanced ? $transferer->getCapabilities() : ['enhanced_model_available' => false];

foreach ($files as &$file) {
    $file = str_replace('../modules/', APPPATH . 'modules/', $file);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Trongate SQL Transferer</title>
</head>

<body>

    <?php
    // Build file information with enhanced analysis
    if (count($files) === 1) {
        $info = '<p>The following SQL file was found within the module directory:</p>';
    } else {
        $info = '<p>The following SQL files were found within the module directory:</p>';
    }

    $info .= '<div class="file-list" role="list">';
    foreach ($files as $index => $file) {
        // Display last segment only
        $bits = explode('/', $file);
        $target_file = $bits[count($bits) - 1];

        $filesize = filesize($file); // bytes
        $filesize_kb = round($filesize / 1024, 2); // kilobytes with two digits
        $filesize_mb = round($filesize / 1048576, 2); // megabytes
        
        $info .= '<div class="file-list-item" role="listitem" data-file="' . htmlspecialchars($file) . '">';
        
        // File header with name and size
        $info .= '<div class="file-header">';
        $info .= '<div class="file-name">' . htmlspecialchars($target_file) . '</div>';
        
        if ($filesize_mb >= 1) {
            $info .= '<div class="file-size">' . $filesize_mb . ' MB</div>';
        } else {
            $info .= '<div class="file-size">' . $filesize_kb . ' KB</div>';
        }
        $info .= '</div>';
        
        // Enhanced analysis for Enhanced Model
        $analysis = null;
        if ($useEnhanced && $capabilities['translation_supported']) {
            $analysis = TransfererDetection::analyzeSQLFile($file);
            
            if ($analysis['exists']) {
                $sourceTypeName = $analysis['source_type_name'];
                $targetTypeName = $analysis['target_type_name'];
                $translationRequired = $analysis['translation_required'];
                
                $info .= '<div class="database-badges">';
                $info .= '<span class="db-badge">' . htmlspecialchars($sourceTypeName) . '</span>';
                $info .= '<span class="arrow">→</span>';
                $info .= '<span class="db-badge">' . htmlspecialchars($targetTypeName) . '</span>';
                
                if ($translationRequired) {
                    $info .= '<span class="translation-badge">Translation Required</span>';
                }
                $info .= '</div>';
            }
        }
        
        // Action buttons based on file size and security
        $info .= '<div class="action-buttons">';
        
        if ($filesize > 1000000) { // 1MB limit
            $info .= '<button class="danger" onclick="transferer.explainTooBig(\'' . 
                    htmlspecialchars($target_file) . '\', \'' . htmlspecialchars($file) . '\')" ' .
                    'aria-label="File too big: ' . htmlspecialchars($target_file) . '">' .
                    '⚠️ TOO BIG!</button>';
        } else {
            // Check for dangerous SQL
            $file_contents = file_get_contents($file);
            $all_clear = $transferer->check_sql($file_contents);
            
            if ($all_clear === true) {
                if ($useEnhanced && $capabilities['translation_supported']) {
                    $info .= '<button class="info" onclick="transferer.viewSqlEnhanced(\'' . 
                            htmlspecialchars($file) . '\', false)" ' .
                            'aria-label="View and process SQL file: ' . htmlspecialchars($target_file) . '">' .
                            '📄 View & Process SQL</button>';
                } else {
                    $info .= '<button onclick="transferer.viewSql(\'' . 
                            htmlspecialchars($file) . '\', false)" ' .
                            'aria-label="View SQL file: ' . htmlspecialchars($target_file) . '">' .
                            '📄 VIEW SQL</button>';
                }
            } else {
                $securityWarning = 'Contains potentially dangerous SQL commands';
                if ($useEnhanced && $capabilities['translation_supported']) {
                    $info .= '<button class="warning" onclick="transferer.viewSqlEnhanced(\'' . 
                            htmlspecialchars($file) . '\', true)" ' .
                            'aria-label="' . $securityWarning . ': ' . htmlspecialchars($target_file) . '">' .
                            '⚠️ SUSPICIOUS - Review Carefully</button>';
                } else {
                    $info .= '<button class="warning" onclick="transferer.viewSql(\'' . 
                            htmlspecialchars($file) . '\', true)" ' .
                            'aria-label="' . $securityWarning . ': ' . htmlspecialchars($target_file) . '">' .
                            '⚠️ SUSPICIOUS!</button>';
                }
            }
            
            // Add analysis button for enhanced mode
            if ($useEnhanced && $capabilities['translation_supported'] && $analysis && $analysis['exists']) {
                $info .= '<button class="success" onclick="transferer.showFileAnalysis(\'' . 
                        htmlspecialchars($file) . '\')" ' .
                        'aria-label="Show detailed analysis for: ' . htmlspecialchars($target_file) . '">' .
                        '🔍 Analyze</button>';
            }
        }
        
        $info .= '</div>'; // Close action-buttons
        $info .= '</div>'; // Close file-list-item
    }
    $info .= '</div>'; // Close file-list
    
    // Add Enhanced Model status
    if ($useEnhanced) {
        $info .= '<div class="enhanced-status">';
        $info .= '<p style="color: #4CAF50; font-size: 0.8em;">✅ Enhanced Model Available - Cross-database translation supported</p>';
        $info .= '</div>';
    }
    ?>

    <h1 id="headline">SQL Files Found</h1>
    <div id="info"><?= $info ?></div>

    <style>
        /* Critical styles for immediate rendering - minimal and high contrast */
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: #ffffff;
            margin: 0;
            padding: 1rem;
            min-height: 100vh;
            line-height: 1.6;
        }
        
        .loading-fallback {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 50vh;
            font-size: 1.2rem;
            color: #ecf0f1;
        }

        /* Immediate file list styling with proper contrast */
        .file-list-item {
            background: rgba(255, 255, 255, 0.95);
            border: 2px solid #d1d5db;
            border-radius: 8px;
            margin-bottom: 1rem;
            padding: 1.5rem;
            transition: all 0.25s ease;
            color: #2c3e50;
        }

        .file-list-item:hover {
            background: rgba(255, 255, 255, 1);
            border-color: #1890ff;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .file-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .file-name {
            font-weight: 700;
            font-size: 1.1rem;
            color: #2c3e50;
            margin: 0;
        }

        .file-size {
            color: #7f8c8d;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .database-badges {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin: 1rem 0;
            flex-wrap: wrap;
        }

        .db-badge {
            background: #e6f7ff;
            color: #1890ff;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            font-weight: 600;
            border: 2px solid #1890ff;
            font-size: 0.85rem;
        }

        .arrow {
            color: #7f8c8d;
            font-weight: bold;
            font-size: 1.2rem;
        }

        .translation-badge {
            background: #fef9e7;
            color: #faad14;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            font-weight: 700;
            font-size: 0.8rem;
            border: 2px solid #faad14;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            justify-content: center;
            align-items: center;
            margin-top: 1rem;
        }

        button {
            font-size: 0.9rem;
            font-weight: 600;
            padding: 0.5rem 1.5rem;
            margin: 0.25rem;
            text-transform: uppercase;
            border: 2px solid transparent;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.15s ease;
            letter-spacing: 0.025em;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            min-width: 120px;
            font-family: inherit;
        }

        .success {
            background: #27ae60;
            color: white;
            border-color: #27ae60;
        }

        .warning {
            background: #f39c12;
            color: white;
            border-color: #f39c12;
        }

        .danger {
            background: #e74c3c;
            color: white;
            border-color: #e74c3c;
        }

        .info {
            background: #3498db;
            color: white;
            border-color: #3498db;
        }

        button:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        /* Enhanced status with proper contrast */
        .enhanced-status {
            background: #d5f4e6;
            border: 2px solid #52c41a;
            color: #27ae60;
            border-radius: 8px;
            padding: 1.5rem;
            margin: 1.5rem auto;
            max-width: 600px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .enhanced-status p {
            margin: 0;
            font-size: 0.95rem;
            color: #27ae60;
            font-weight: 600;
        }

        /* Help hint with better contrast */
        .help-hint {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.95);
            color: #2c3e50;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.8rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border: 2px solid #d1d5db;
            z-index: 100;
            transition: all 0.25s ease;
            cursor: pointer;
            max-width: 250px;
        }

        .help-hint:hover {
            background: rgba(255, 255, 255, 1);
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
        }

        .help-hint-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }

        .help-hint-close {
            background: none;
            border: none;
            color: #7f8c8d;
            font-size: 1.2rem;
            cursor: pointer;
            padding: 0;
            margin: 0;
            min-width: auto;
            line-height: 1;
        }

        .help-hint-close:hover {
            color: #e74c3c;
        }

        kbd {
            background: rgba(0, 0, 0, 0.05);
            border: 2px solid #d1d5db;
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
            font-family: monospace;
            font-size: 0.85em;
            font-weight: bold;
            color: #2c3e50;
        }
    </style>
    <style><?= file_get_contents(__DIR__ . '/enhanced-transferer.css');?></style>  

    <script><?= file_get_contents(__DIR__ . '/enhanced-transferer.js');?></script>
    <script type="text/javascript">
        // Global configuration for enhanced transferer
        window.enhancedMode = <?= $useEnhanced ? 'true' : 'false' ?>;
        window.translationSupported = <?= ($useEnhanced && $capabilities['translation_supported']) ? 'true' : 'false' ?>;
        window.baseUrl = '<?= defined('BASE_URL') ? BASE_URL : '' ?>';
        window.firstSampleFile = '<?= isset($files[0]) ? $files[0] : '' ?>';
        window.capabilities = <?= json_encode($capabilities) ?>;

        // Legacy function compatibility for inline handlers
        function viewSqlEnhanced(file, warning) {
            if (window.transferer) {
                window.transferer.viewSqlEnhanced(file, warning);
            }
        }

        function viewSql(file, warning) {
            if (window.transferer) {
                window.transferer.viewSql(file, warning);
            }
        }

        function explainTooBig(target_file, filePath) {
            if (window.transferer) {
                window.transferer.explainTooBig(target_file, filePath);
            }
        }

        function translateAndPreview(file) {
            if (window.transferer) {
                window.transferer.translateAndPreview(file);
            }
        }

        function runTranslatedSQL() {
            if (window.transferer) {
                window.transferer.runTranslatedSQL();
            }
        }

        function showComparison() {
            if (window.transferer) {
                window.transferer.showComparison();
            }
        }

        function drawConfRun() {
            if (window.transferer) {
                window.transferer.drawConfRun();
            }
        }

        function drawConfDelete() {
            if (window.transferer) {
                window.transferer.drawConfDelete();
            }
        }

        function runSql() {
            if (window.transferer) {
                window.transferer.runSql();
            }
        }

        function deleteSqlFile() {
            if (window.transferer) {
                window.transferer.deleteSqlFile();
            }
        }

        function previewSql() {
            if (window.transferer) {
                window.transferer.previewSql();
            }
        }

        function clickOkay() {
            if (window.transferer) {
                window.transferer.clickOkay();
            }
        }

        // Enhanced features - file analysis
        function showFileAnalysis(file) {
            if (window.transferer && window.transferer.showFileAnalysis) {
                window.transferer.showFileAnalysis(file);
            }
        }

        // Initialize enhanced features on load
        document.addEventListener('DOMContentLoaded', function() {
            // Add file count and summary information
            const fileCount = <?= count($files) ?>;
            const totalSize = <?= array_sum(array_map('filesize', $files)) ?>;
            const totalSizeKB = Math.round(totalSize / 1024 * 100) / 100;
            
            if (window.enhancedMode) {
                console.log('Enhanced Transferer initialized');
                console.log('Files:', fileCount, 'Total size:', totalSizeKB, 'KB');
                
                // Add summary information
                const headline = document.getElementById('headline');
                if (headline && fileCount > 1) {
                    const summary = document.createElement('div');
                    summary.className = 'file-summary';
                    summary.innerHTML = `
                        <p style="font-size: 0.8rem; color: #bbbbbb; margin-top: 0.5rem;">
                            ${fileCount} files • ${totalSizeKB} KB total
                        </p>
                    `;
                    headline.appendChild(summary);
                }
            }

            // Add keyboard shortcut hints if translation is supported
            if (window.translationSupported && window.transferer) {
                // Show help hint after a short delay
                setTimeout(() => {
                    window.transferer.createHelpHint();
                }, 2000);
            }
        });

        // Enhanced error handling
        window.addEventListener('unhandledrejection', function(event) {
            console.error('Unhandled promise rejection:', event.reason);
            if (window.transferer && window.transferer.handleError) {
                window.transferer.handleError('System Error', event.reason.message || 'An unexpected error occurred');
            }
        });

        // Performance monitoring
        if (window.performance && window.performance.mark) {
            window.addEventListener('load', function() {
                window.performance.mark('transferer-loaded');
                if (window.enhancedMode) {
                    const loadTime = window.performance.now();
                    console.log(`Enhanced Transferer loaded in ${Math.round(loadTime)}ms`);
                }
            });
        }
    </script>

</body>
</html>