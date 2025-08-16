/**
 * Enhanced Transferer JavaScript
 * 
 * Provides enhanced user interactions, validation, and progressive enhancement
 * for the SQL transferer system with cross-database translation support.
 */

class EnhancedTransferer {
    constructor() {
        this.currentUrl = window.location.href;
        this.targetFile = '';
        this.sqlCode = '';
        this.enhancedMode = window.enhancedMode || false;
        this.translationSupported = window.translationSupported || false;
        this.currentAnalysis = null;
        this.translatedSQL = '';
        this.tempTranslationFile = '';
        this.progressCallback = null;
        
        this.init();
    }

    init() {
        this.setupEventListeners();
        this.setupAccessibility();
        //this.showInitialStatus();
    }

    setupEventListeners() {
        // Global error handler
        window.addEventListener('error', (e) => {
            this.handleError('JavaScript Error', e.message);
        });

        // Keyboard navigation
        document.addEventListener('keydown', (e) => {
            this.handleKeyboardNavigation(e);
        });

        // Form validation
        document.addEventListener('beforeunload', (e) => {
            if (this.isProcessing()) {
                e.preventDefault();
                e.returnValue = 'SQL processing in progress. Are you sure you want to leave?';
            }
        });
    }

    setupAccessibility() {
        // Add ARIA labels and roles
        const buttons = document.querySelectorAll('button');
        buttons.forEach((button, index) => {
            if (!button.hasAttribute('aria-label')) {
                button.setAttribute('aria-label', button.textContent.trim());
            }
            button.setAttribute('tabindex', '0');
        });

        // Add landmarks
        const main = document.querySelector('body > div') || document.body;
        main.setAttribute('role', 'main');
    }

    showInitialStatus() {
        if (this.enhancedMode) {
            this.showNotification('Enhanced Model detected - Cross-database translation available', 'success');
        }
    }

    // Enhanced SQL viewing with sophisticated error handling
    async viewSqlEnhanced(file, warning) {
        try {
            this.setLoadingState('Analyzing SQL file...', 'Analyzing SQL');
            
            const analysis = await this.analyzeSQLFile(file);
            
            // Validate analysis result
            if (!analysis || typeof analysis !== 'object') {
                throw new Error('Invalid analysis result received');
            }
            
            this.currentAnalysis = analysis;
            
            if (analysis.translation_required) {
                this.drawTranslationRequiredPage(file, warning, analysis);
            } else {
                await this.viewSql(file, warning);
            }
        } catch (error) {
            console.error('Analysis failed:', error);
            this.handleError('Analysis Failed', error.message);
            
            // Clear any partial analysis
            this.currentAnalysis = null;
            
            // Fallback to standard view
            await this.viewSql(file, warning);
        }
    }

    // Analyze SQL file with enhanced error handling
    async analyzeSQLFile(file) {
        if (!file) {
            throw new Error('No file specified for analysis');
        }
        
        const params = {
            controllerPath: file,
            action: 'analyzeSql'
        };

        try {
            const response = await this.makeRequest(params);
            
            if (!response.ok) {
                throw new Error(`Analysis request failed: ${response.status} ${response.statusText}`);
            }

            const analysisResult = await response.json();
            
            // Validate the analysis result structure
            if (!analysisResult || typeof analysisResult !== 'object') {
                throw new Error('Invalid analysis response format');
            }
            
            // Check for required properties
            const requiredProperties = ['exists', 'source_type', 'target_type', 'translation_required'];
            const missingProperties = requiredProperties.filter(prop => !(prop in analysisResult));
            
            if (missingProperties.length > 0) {
                console.warn('Analysis missing properties:', missingProperties);
                
                // Provide fallback values for missing properties
                if (!analysisResult.source_type) {
                    analysisResult.source_type = 'mysql'; // Default fallback
                    analysisResult.source_type_name = 'MySQL';
                }
                
                if (!analysisResult.target_type) {
                    analysisResult.target_type = 'mysql'; // Safe fallback
                    analysisResult.target_type_name = 'MySQL';
                }
                
                if (typeof analysisResult.translation_required === 'undefined') {
                    analysisResult.translation_required = analysisResult.source_type !== analysisResult.target_type;
                }
                
                if (typeof analysisResult.exists === 'undefined') {
                    analysisResult.exists = true;
                }
            }
            
            // Log successful analysis for debugging
            console.log('Analysis completed:', {
                source: analysisResult.source_type,
                target: analysisResult.target_type,
                translationRequired: analysisResult.translation_required,
                fileExists: analysisResult.exists
            });
            
            return analysisResult;
            
        } catch (error) {
            console.error('Analysis failed:', error);
            
            // Create a fallback analysis result
            const fallbackAnalysis = {
                exists: true,
                source_type: 'mysql',
                target_type: 'mysql', 
                source_type_name: 'MySQL',
                target_type_name: 'MySQL',
                translation_required: false,
                enhanced_model_available: window.enhancedMode || false,
                filesize: 0,
                filesize_kb: 0,
                content_preview: '',
                error: error.message
            };
            
            console.warn('Using fallback analysis:', fallbackAnalysis);
            throw error; // Re-throw to let caller handle
        }
    }

    // Enhanced request handler with retry logic
    async makeRequest(params, options = {}) {
        const defaultOptions = {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(params)
        };

        const requestOptions = { ...defaultOptions, ...options };
        let lastError;

        // Retry logic for failed requests
        for (let attempt = 1; attempt <= 3; attempt++) {
            try {
                const response = await fetch(this.currentUrl, requestOptions);
                
                if (response.ok) {
                    return response;
                }
                
                lastError = new Error(`HTTP ${response.status}: ${response.statusText}`);
                
                if (attempt < 3 && response.status >= 500) {
                    // Retry server errors with exponential backoff
                    const delayMs = Math.pow(2, attempt) * 1000;
                    await new Promise(resolve => setTimeout(resolve, delayMs));
                    continue;
                }
                
                throw lastError;
            } catch (error) {
                lastError = error;
                
                if (attempt < 3 && (error.name === 'NetworkError' || error.name === 'TypeError')) {
                    const delayMs = Math.pow(2, attempt) * 1000;
                    await new Promise(resolve => setTimeout(resolve, delayMs));
                    continue;
                }
                
                throw error;
            }
        }
        
        throw lastError;
    }

    // Standard SQL viewing with progress tracking
    async viewSql(file, warning) {
        try {
            this.setLoadingState('Reading SQL file...', 'Reading SQL');
            
            const params = {
                controllerPath: file,
                action: 'viewSql'
            };

            const response = await this.makeRequest(params);
            const sqlContent = await response.text();
            
            this.sqlCode = sqlContent;
            this.drawShowSQLPage(sqlContent, file, warning);
        } catch (error) {
            this.handleError('Failed to load SQL file', error.message);
        }
    }

    // Enhanced translation workflow
    async translateAndPreview(file) {
        try {
            // Store the file for future reference
            this.targetFile = file;
            
            // Validate that we have analysis data
            if (!this.currentAnalysis) {
                this.showNotification('Analyzing file first...', 'info');
                // Re-run analysis if missing
                await this.viewSqlEnhanced(file, false);
                return;
            }
            
            // Validate analysis structure
            if (!this.currentAnalysis.source_type || !this.currentAnalysis.target_type) {
                throw new Error('Invalid analysis data - missing database types');
            }
            
            this.setLoadingState('Translating SQL dump...', 'Translating SQL', true);
            
            const params = {
                filepath: file,
                sourceType: this.currentAnalysis.source_type,
                targetType: this.currentAnalysis.target_type,
                action: 'translateSql'
            };

            const response = await this.makeRequest(params);
            
            if (!response.ok) {
                throw new Error(`Translation request failed: ${response.status} ${response.statusText}`);
            }
            
            const result = await response.json();
            
            if (result.success) {
                this.translatedSQL = result.translated_sql;
                this.tempTranslationFile = result.temp_file;
                this.drawTranslationPreview(file, result);
            } else {
                throw new Error(result.error || 'Translation failed without specific error');
            }
        } catch (error) {
            console.error('Translation error:', error);
            this.handleError('Translation Failed', error.message);
            this.showNotification('Falling back to original SQL view', 'warning');
            
            // Clear invalid analysis and fall back
            this.currentAnalysis = null;
            await this.viewSql(file, false);
        }
    }

    // Enhanced page drawing with sophisticated UI
    drawTranslationRequiredPage(file, warning, analysis) {
        this.targetFile = file;

        if (warning) {
            this.showWarningAlert('Potentially dangerous SQL code detected. Review carefully!');
        }

        const content = this.createTranslationRequiredContent(analysis, warning);
        this.updatePageContent('Translation Required', content);
        this.setupTranslationPageHandlers(file, warning);
    }

    createTranslationRequiredContent(analysis, warning = false) {
        return `
            <div class="translation-preview">
                <h2>🔄 Cross-Database Translation Required</h2>
                <div class="translation-stats">
                    <div class="stat-item">
                        <div class="stat-value">${analysis.source_type_name}</div>
                        <div class="stat-label">Source Database</div>
                    </div>
                    <div class="stat-item">
                        <div class="arrow">→</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">${analysis.target_type_name}</div>
                        <div class="stat-label">Target Database</div>
                    </div>
                </div>
                <p>This SQL dump contains <strong>${analysis.source_type_name}</strong>-specific syntax that needs to be translated for compatibility with your <strong>${analysis.target_type_name}</strong> database.</p>
                <div class="file-details">
                    <p><strong>File Size:</strong> ${analysis.filesize_kb || 'Unknown'} KB</p>
                    <p><strong>Translation:</strong> Automatic with validation</p>
                    ${warning ? '<p><strong>⚠️ Security Warning:</strong> This file contains potentially dangerous SQL operations</p>' : ''}
                </div>
            </div>
            
            <div class="action-buttons">
                <button onclick="transferer.goBack()" aria-label="Go back to file list">
                    ← Go Back
                </button>
                <button class="info" onclick="transferer.translateAndPreview('${this.targetFile}')" aria-label="Translate SQL and show preview">
                    🔄 Translate & Preview
                </button>
                <button onclick="transferer.viewSql('${this.targetFile}', ${warning})" aria-label="View original SQL without translation">
                    📄 View Original SQL
                </button>
            </div>
        `;
    }

    setupTranslationPageHandlers(file, warning) {
        // Add keyboard shortcuts
        const handleKeyPress = (e) => {
            if (e.ctrlKey || e.metaKey) {
                switch (e.key) {
                    case 't':
                        e.preventDefault();
                        this.translateAndPreview(file);
                        break;
                    case 'o':
                        e.preventDefault();
                        this.viewSql(file, warning);
                        break;
                }
            }
        };

        document.addEventListener('keydown', handleKeyPress);
        
        // Cleanup on page change
        this.addCleanupHandler(() => {
            document.removeEventListener('keydown', handleKeyPress);
        });
    }

    // Enhanced translation preview with statistics
    drawTranslationPreview(file, result) {
        const warningsHtml = this.createWarningsSection(result.warnings);
        const statisticsHtml = this.createStatisticsSection(result.statistics);
        
        const content = `
            <div class="translation-preview">
                <h2>✅ Translation Complete</h2>
                <div class="translation-stats">
                    <div class="stat-item">
                        <div class="stat-value">${result.source_type.toUpperCase()}</div>
                        <div class="stat-label">Translated From</div>
                    </div>
                    <div class="stat-item">
                        <div class="arrow">→</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">${result.target_type.toUpperCase()}</div>
                        <div class="stat-label">Translated To</div>
                    </div>
                </div>
                
                ${statisticsHtml}
                ${warningsHtml}
            </div>

            <div class="action-buttons">
                <button onclick="transferer.goBack()" aria-label="Go back to file list">
                    ← Go Back
                </button>
                <button class="success" onclick="transferer.runTranslatedSQL()" aria-label="Execute the translated SQL">
                    ✅ Run Translated SQL
                </button>
                <button onclick="transferer.showComparison()" aria-label="Compare original and translated SQL">
                    📊 Compare Original vs Translated
                </button>
                <button onclick="transferer.downloadTranslatedSQL()" aria-label="Download translated SQL file">
                    💾 Download Translated SQL
                </button>
            </div>

            <div class="sql-preview-container">
                <h3>Translated SQL Preview</h3>
                <textarea id="sql-preview" readonly aria-label="Translated SQL content">${result.translated_sql}</textarea>
            </div>
        `;

        this.updatePageContent('Translation Preview', content);
        this.setupPreviewPageHandlers();
    }

    createWarningsSection(warnings) {
        if (!warnings || warnings.length === 0) {
            return '<div class="success-message">✅ No translation warnings</div>';
        }

        return `
            <div class="warning-list">
                <h3>⚠️ Translation Warnings (${warnings.length})</h3>
                <ul>
                    ${warnings.map(warning => `<li>${this.escapeHtml(warning)}</li>`).join('')}
                </ul>
                <p class="warning-note">These warnings indicate areas that may need manual review after import.</p>
            </div>
        `;
    }

    createStatisticsSection(statistics) {
        if (!statistics) return '';

        return `
            <div class="translation-stats">
                <div class="stat-item">
                    <div class="stat-value">${statistics.tables_processed || 0}</div>
                    <div class="stat-label">Tables Processed</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">${statistics.indexes_processed || 0}</div>
                    <div class="stat-label">Indexes Processed</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">${statistics.constraints_processed || 0}</div>
                    <div class="stat-label">Constraints Processed</div>
                </div>
            </div>
        `;
    }

    setupPreviewPageHandlers() {
        // Add keyboard shortcuts for preview page
        const handleKeyPress = (e) => {
            if (e.ctrlKey || e.metaKey) {
                switch (e.key) {
                    case 'Enter':
                        e.preventDefault();
                        this.runTranslatedSQL();
                        break;
                    case 'd':
                        e.preventDefault();
                        this.downloadTranslatedSQL();
                        break;
                    case 'c':
                        e.preventDefault();
                        this.showComparison();
                        break;
                }
            }
        };

        document.addEventListener('keydown', handleKeyPress);
        this.addCleanupHandler(() => {
            document.removeEventListener('keydown', handleKeyPress);
        });
    }

    // Enhanced comparison view
    async showComparison() {
        try {
            // Validate that we have the necessary data
            if (!this.currentAnalysis) {
                this.showNotification('Analysis data not available for comparison', 'warning');
                return;
            }
            
            if (!this.translatedSQL) {
                this.showNotification('No translated SQL available for comparison', 'warning');
                return;
            }
            
            this.setLoadingState('Loading comparison...', 'Comparison View');
            
            const params = {
                controllerPath: this.targetFile,
                action: 'viewSql'
            };

            const response = await this.makeRequest(params);
            
            if (!response.ok) {
                throw new Error(`Failed to load original SQL: ${response.status}`);
            }
            
            const originalSQL = await response.text();
            
            this.drawComparisonView(originalSQL, this.translatedSQL);
        } catch (error) {
            console.error('Comparison failed:', error);
            this.handleError('Failed to load comparison', error.message);
        }
    }

    drawComparisonView(originalSQL, translatedSQL) {
        // Provide fallback names if analysis is not available
        const sourceTypeName = this.currentAnalysis?.source_type_name || 'Original';
        const targetTypeName = this.currentAnalysis?.target_type_name || 'Translated';
        
        const content = `
            <div class="action-buttons">
                <button onclick="transferer.goBack()" aria-label="Go back to file list">
                    ← Go Back
                </button>
                <button class="success" onclick="transferer.runTranslatedSQL()" aria-label="Execute the translated SQL">
                    ✅ Run Translated SQL
                </button>
                <button onclick="transferer.downloadTranslatedSQL()" aria-label="Download translated SQL">
                    💾 Download Translated
                </button>
            </div>

            <div class="comparison-view">
                <div class="comparison-side">
                    <h3>📄 Original (${sourceTypeName})</h3>
                    <textarea readonly aria-label="Original SQL content">${this.escapeHtml(originalSQL)}</textarea>
                </div>
                <div class="comparison-side">
                    <h3>🔄 Translated (${targetTypeName})</h3>
                    <textarea readonly aria-label="Translated SQL content">${this.escapeHtml(translatedSQL)}</textarea>
                </div>
            </div>

            <div class="comparison-help">
                <h3>💡 What Changed?</h3>
                <p>The translation adapted the SQL for compatibility with ${targetTypeName}. Key changes may include:</p>
                <ul>
                    <li>Data type conversions (e.g., AUTO_INCREMENT → AUTOINCREMENT)</li>
                    <li>Syntax adaptations (e.g., ENGINE clauses removed for SQLite)</li>
                    <li>Function translations (e.g., database-specific functions)</li>
                    <li>Constraint and index adjustments</li>
                </ul>
            </div>
        `;

        this.updatePageContent('SQL Comparison', content);
        this.setupComparisonHandlers();
    }

    setupComparisonHandlers() {
        // Sync scrolling between comparison textareas
        const textareas = document.querySelectorAll('.comparison-side textarea');
        if (textareas.length === 2) {
            textareas[0].addEventListener('scroll', (e) => {
                textareas[1].scrollTop = e.target.scrollTop;
                textareas[1].scrollLeft = e.target.scrollLeft;
            });

            textareas[1].addEventListener('scroll', (e) => {
                textareas[0].scrollTop = e.target.scrollTop;
                textareas[0].scrollLeft = e.target.scrollLeft;
            });
        }
    }

    // Enhanced SQL execution with progress tracking
    async runTranslatedSQL() {
        try {
            if (!await this.confirmExecution()) {
                return;
            }

            this.setLoadingState('Executing translated SQL...', 'Executing SQL', true);
            
            const params = {
                sqlCode: this.translatedSQL,
                action: 'runSql',
                targetFile: this.tempTranslationFile
            };

            const response = await this.makeRequest(params);
            const result = await response.text();
            
            this.handleExecutionResult(response.status, result);
        } catch (error) {
            this.handleError('Execution Failed', error.message);
        }
    }

    async confirmExecution() {
        return new Promise((resolve) => {
            const confirmed = confirm(
                'Are you sure you want to execute the translated SQL?\n\n' +
                'This will modify your database. Make sure you have a backup if needed.'
            );
            resolve(confirmed);
        });
    }

    handleExecutionResult(status, result) {
        // Provide fallback values if analysis is not available
        const sourceTypeName = this.currentAnalysis?.source_type_name || 'Source Database';
        const targetTypeName = this.currentAnalysis?.target_type_name || 'Target Database';
        
        if (status === 403) {
            this.updatePageContent('Finished', `
                <p>Please delete the file: ${result.replace('Finished.', '')}</p>
                <p>After you have deleted the file, press 'Okay'</p>
                <button class="success" onclick="transferer.clickOkay()">Okay</button>
            `);
        } else if (result === 'Finished.') {
            this.updatePageContent('✅ Success!', `
                <div class="success-message">
                    <h2>🎉 Translation and Import Complete!</h2>
                    <p>The translated SQL was successfully processed and your database has been updated.</p>
                    <div class="success-stats">
                        <p><strong>Source:</strong> ${sourceTypeName}</p>
                        <p><strong>Target:</strong> ${targetTypeName}</p>
                        <p><strong>Status:</strong> Import Successful</p>
                    </div>
                </div>
                <button class="success" onclick="transferer.clickOkay()">Continue</button>
            `);
        } else {
            this.showExecutionError(result);
        }
    }

    showExecutionError(errorMessage) {
        const content = `
            <div class="error-container">
                <h2>❌ SQL Execution Error</h2>
                <p>There was an error executing the translated SQL:</p>
                <div class="error-message">${this.escapeHtml(errorMessage)}</div>
                <div class="error-actions">
                    <button onclick="transferer.goBack()">← Go Back</button>
                    <button onclick="transferer.downloadTranslatedSQL()">💾 Download SQL for Manual Review</button>
                    <button onclick="transferer.showComparison()">📊 View Comparison</button>
                </div>
            </div>
        `;

        this.updatePageContent('SQL Error', content);
    }

    // Download functionality
    downloadTranslatedSQL() {
        if (!this.translatedSQL) {
            this.showNotification('No translated SQL available for download', 'warning');
            return;
        }

        const blob = new Blob([this.translatedSQL], { type: 'text/sql' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        
        a.href = url;
        a.download = `translated_${this.currentAnalysis.target_type}_${Date.now()}.sql`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);

        this.showNotification('Translated SQL downloaded successfully', 'success');
    }

    // Utility methods
    setLoadingState(message, headline = 'Processing', showProgress = false) {
        const progressHtml = showProgress ? '<div class="progress-bar"><div class="progress-fill" style="width: 0%"></div></div>' : '';
        
        this.updatePageContent(headline, `
            <div class="loading-container">
                <p class="blink">${message}</p>
                ${progressHtml}
            </div>
        `);

        if (showProgress) {
            this.animateProgress();
        }
    }

    animateProgress() {
        const progressFill = document.querySelector('.progress-fill');
        if (!progressFill) return;

        let width = 0;
        const interval = setInterval(() => {
            width += Math.random() * 15;
            if (width >= 90) {
                width = 90;
                clearInterval(interval);
            }
            progressFill.style.width = width + '%';
        }, 100);

        // Store interval for cleanup
        this.progressInterval = interval;
    }

    updatePageContent(headline, content) {
        const headlineEl = document.getElementById('headline');
        const infoEl = document.getElementById('info');
        
        if (headlineEl) {
            headlineEl.innerHTML = headline;
        }
        
        if (infoEl) {
            infoEl.innerHTML = content;
        }

        // Cleanup progress interval
        if (this.progressInterval) {
            clearInterval(this.progressInterval);
            this.progressInterval = null;
        }

        // Re-setup accessibility
        this.setupAccessibility();
    }

    showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        notification.innerHTML = `
            <span>${message}</span>
            <button onclick="this.parentNode.remove()" aria-label="Close notification">×</button>
        `;
        
        document.body.appendChild(notification);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 5000);
    }

    showWarningAlert(message) {
        // Create a more sophisticated warning display
        const warningEl = document.createElement('div');
        warningEl.className = 'warning-alert';
        warningEl.innerHTML = `
            <div class="warning-content">
                <strong>⚠️ Security Warning:</strong> ${message}
            </div>
        `;
        
        document.body.insertBefore(warningEl, document.body.firstChild);
        
        setTimeout(() => {
            if (warningEl.parentNode) {
                warningEl.remove();
            }
        }, 8000);
    }

    handleError(title, message) {
        console.error(`${title}:`, message);
        
        const content = `
            <div class="error-container">
                <h2>❌ ${title}</h2>
                <p>An error occurred while processing your request:</p>
                <div class="error-message">${this.escapeHtml(message)}</div>
                <div class="error-actions">
                    <button onclick="transferer.goBack()">← Go Back</button>
                    <button onclick="location.reload()">🔄 Refresh Page</button>
                </div>
            </div>
        `;

        this.updatePageContent('Error', content);
    }

    handleKeyboardNavigation(e) {
        // Global keyboard shortcuts
        if (e.altKey) {
            switch (e.key) {
                case 'b':
                    e.preventDefault();
                    this.goBack();
                    break;
                case 'h':
                    e.preventDefault();
                    this.showHelp();
                    break;
            }
        }
    }

    goBack() {
        window.location.href = this.currentUrl.split('?')[0];
    }

    showHelp() {
        const helpContent = `
            <div class="help-container">
                <h2>🔧 Enhanced Transferer Help</h2>
                <div class="help-section">
                    <h3>Keyboard Shortcuts</h3>
                    <ul>
                        <li><kbd>Ctrl/Cmd + T</kbd> - Translate SQL</li>
                        <li><kbd>Ctrl/Cmd + O</kbd> - View Original SQL</li>
                        <li><kbd>Ctrl/Cmd + C</kbd> - Show Comparison</li>
                        <li><kbd>Ctrl/Cmd + D</kbd> - Download Translated SQL</li>
                        <li><kbd>Ctrl/Cmd + Enter</kbd> - Execute SQL</li>
                        <li><kbd>Alt + B</kbd> - Go Back</li>
                        <li><kbd>Alt + H</kbd> - Show This Help</li>
                        <li><kbd>Esc</kbd> - Close Help Modal</li>
                    </ul>
                </div>
                <div class="help-section">
                    <h3>Translation Process</h3>
                    <ol>
                        <li>SQL file is analyzed for database type</li>
                        <li>Translation requirements are determined</li>
                        <li>SQL is converted for target database</li>
                        <li>Preview is shown with warnings</li>
                        <li>User confirms and executes</li>
                    </ol>
                </div>
                <div class="help-section">
                    <h3>Troubleshooting</h3>
                    <ul>
                        <li>If translation fails, the system falls back to standard SQL view</li>
                        <li>Check browser console for detailed error messages</li>
                        <li>Ensure Enhanced Model is properly configured</li>
                        <li>Verify file is a valid SQL dump</li>
                    </ul>
                </div>
                <div class="help-section">
                    <h3>Debug Commands (Console)</h3>
                    <ul>
                        <li><kbd>transferer.debugState()</kbd> - Show current state</li>
                        <li><kbd>transferer.getCapabilities()</kbd> - Show system capabilities</li>
                        <li><kbd>console.log(transferer.currentAnalysis)</kbd> - View analysis data</li>
                    </ul>
                </div>
                <button onclick="transferer.closeHelp()">Close Help</button>
            </div>
        `;

        const overlay = document.createElement('div');
        overlay.className = 'help-overlay';
        overlay.innerHTML = helpContent;
        
        // Add click outside to close
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                this.closeHelp();
            }
        });
        
        // Add escape key handler
        const escapeHandler = (e) => {
            if (e.key === 'Escape') {
                this.closeHelp();
                document.removeEventListener('keydown', escapeHandler);
            }
        };
        document.addEventListener('keydown', escapeHandler);
        
        // Store the escape handler for cleanup
        overlay.escapeHandler = escapeHandler;
        
        document.body.appendChild(overlay);
    }

    closeHelp() {
        const overlay = document.querySelector('.help-overlay');
        if (overlay) {
            // Remove escape key handler if it exists
            if (overlay.escapeHandler) {
                document.removeEventListener('keydown', overlay.escapeHandler);
            }
            
            // Add closing animation
            overlay.classList.add('closing');
            
            // Remove after animation
            setTimeout(() => {
                if (overlay.parentNode) {
                    overlay.remove();
                }
            }, 300);
        }
    }

    // Create and show help hint
    createHelpHint() {
        // Remove existing hint if present
        this.removeHelpHint();
        
        const helpHint = document.createElement('div');
        helpHint.className = 'help-hint';
        helpHint.id = 'help-hint';
        helpHint.innerHTML = `
            <div class="help-hint-content">
                <div class="help-hint-text">
                    💡 Press <kbd>Alt + H</kbd> for shortcuts
                </div>
                <button class="help-hint-close" onclick="transferer.removeHelpHint()" aria-label="Close help hint">
                    ×
                </button>
            </div>
        `;
        
        // Add click to show help
        helpHint.addEventListener('click', (e) => {
            // Don't trigger if clicking the close button
            if (!e.target.classList.contains('help-hint-close')) {
                this.showHelp();
            }
        });
        
        document.body.appendChild(helpHint);
        
        // Auto-hide after 10 seconds
        setTimeout(() => {
            this.removeHelpHint();
        }, 10000);
    }

    removeHelpHint() {
        const existingHint = document.getElementById('help-hint');
        if (existingHint) {
            existingHint.style.opacity = '0';
            existingHint.style.transform = 'translateX(20px)';
            setTimeout(() => {
                if (existingHint.parentNode) {
                    existingHint.remove();
                }
            }, 300);
        }
    }

    isProcessing() {
        const headline = document.getElementById('headline');
        return headline && (
            headline.textContent.includes('WAIT') ||
            headline.textContent.includes('Executing') ||
            headline.textContent.includes('Translating')
        );
    }

    addCleanupHandler(handler) {
        if (!this.cleanupHandlers) {
            this.cleanupHandlers = [];
        }
        this.cleanupHandlers.push(handler);
    }

    cleanup() {
        if (this.cleanupHandlers) {
            this.cleanupHandlers.forEach(handler => handler());
            this.cleanupHandlers = [];
        }

        if (this.progressInterval) {
            clearInterval(this.progressInterval);
        }
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Enhanced file analysis display
    async showFileAnalysis(file) {
        try {
            this.setLoadingState('Analyzing file details...', 'File Analysis');
            
            const analysis = await this.analyzeSQLFile(file);
            this.drawFileAnalysis(file, analysis);
        } catch (error) {
            this.handleError('Analysis Failed', error.message);
        }
    }

    drawFileAnalysis(file, analysis) {
        const fileName = file.split('/').pop();
        
        const content = `
            <div class="analysis-container">
                <h2>📊 File Analysis: ${this.escapeHtml(fileName)}</h2>
                
                <div class="analysis-grid">
                    <div class="analysis-section">
                        <h3>File Information</h3>
                        <div class="analysis-stats">
                            <div class="stat-item">
                                <div class="stat-value">${analysis.filesize_kb} KB</div>
                                <div class="stat-label">File Size</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">${analysis.source_type_name}</div>
                                <div class="stat-label">Detected Database</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">${analysis.target_type_name}</div>
                                <div class="stat-label">Target Database</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="analysis-section">
                        <h3>Compatibility</h3>
                        <div class="compatibility-status">
                            ${analysis.translation_required ? 
                                '<div class="status-warning">⚠️ Translation Required</div>' :
                                '<div class="status-success">✅ Direct Import Compatible</div>'
                            }
                        </div>
                        ${analysis.translation_required ? 
                            '<p>This SQL dump contains database-specific syntax that needs translation.</p>' :
                            '<p>This SQL dump can be imported directly without translation.</p>'
                        }
                    </div>
                    
                    <div class="analysis-section">
                        <h3>SQL Preview</h3>
                        <textarea readonly class="preview-content" aria-label="SQL content preview">${this.escapeHtml(analysis.content_preview)}...</textarea>
                    </div>
                </div>
                
                <div class="action-buttons">
                    <button onclick="transferer.goBack()">← Go Back</button>
                    ${analysis.translation_required ? 
                        `<button class="info" onclick="transferer.translateAndPreview('${file}')">🔄 Translate & Process</button>` :
                        `<button class="success" onclick="transferer.viewSql('${file}', false)">📄 Process SQL</button>`
                    }
                    <button onclick="transferer.downloadAnalysisReport('${file}')" class="info">💾 Download Report</button>
                </div>
            </div>
        `;

        this.updatePageContent('File Analysis', content);
    }

    downloadAnalysisReport(file) {
        // Create a detailed analysis report
        if (!this.currentAnalysis) {
            this.showNotification('No analysis data available for download', 'warning');
            return;
        }
        
        const fileName = file.split('/').pop();
        const analysis = this.currentAnalysis;
        
        const report = `
File Analysis Report
==================

File: ${fileName}
Analysis Date: ${new Date().toISOString()}
Enhanced Transferer Version: 2.0

File Information:
- Size: ${analysis.filesize_kb || 'Unknown'} KB
- Source Database: ${analysis.source_type_name || 'Unknown'}
- Target Database: ${analysis.target_type_name || 'Unknown'}
- Translation Required: ${analysis.translation_required ? 'Yes' : 'No'}

Compatibility Assessment:
${analysis.translation_required ? 
    '⚠️ This SQL dump requires translation for compatibility with your target database.' :
    '✅ This SQL dump is directly compatible with your target database.'
}

Next Steps:
${analysis.translation_required ? 
    '1. Use the "Translate & Preview" option to convert the SQL\n2. Review the translation warnings\n3. Execute the translated SQL' :
    '1. Use "Process SQL" to import directly\n2. Review the SQL content\n3. Execute when ready'
}

SQL Preview:
${analysis.content_preview || 'No preview available'}...

---
Generated by Enhanced Trongate Transferer
        `.trim();

        const blob = new Blob([report], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        
        a.href = url;
        a.download = `analysis_${fileName}_${Date.now()}.txt`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);

        this.showNotification('Analysis report downloaded', 'success');
    }

    // Enhanced "too big" file handler
    explainTooBig(target_file, filePath) {
        this.targetFile = filePath;
        
        const fileSizeKB = Math.round((this.getFileSize(filePath) || 0) / 1024 * 100) / 100;
        const fileSizeMB = Math.round(fileSizeKB / 1024 * 100) / 100;
        
        const content = `
            <div class="error-container">
                <h2>📁 File Too Large</h2>
                <div class="file-size-info">
                    <div class="size-display">
                        <div class="size-value">${fileSizeMB} MB</div>
                        <div class="size-label">File Size</div>
                    </div>
                    <div class="size-limit">
                        <div class="limit-value">1 MB</div>
                        <div class="limit-label">Maximum Allowed</div>
                    </div>
                </div>
                
                <p>The file <strong>${this.escapeHtml(target_file)}</strong> exceeds the automatic import limit of 1MB (1,000KB).</p>
                
                <div class="recommendations">
                    <h3>💡 Recommendations:</h3>
                    <ul>
                        <li><strong>Split the file:</strong> Break large dumps into smaller files</li>
                        <li><strong>Manual import:</strong> Use database tools for large imports</li>
                        <li><strong>Remove the file:</strong> Clean up and try with smaller files</li>
                        <li><strong>Optimize:</strong> Remove unnecessary data or comments</li>
                    </ul>
                </div>
                
                <div class="action-buttons">
                    <button onclick="transferer.goBack()">← Go Back</button>
                    <button class="danger" onclick="transferer.deleteSqlFile()">🗑️ Delete File</button>
                    <button onclick="transferer.showSplitHelp()" class="info">✂️ How to Split Files</button>
                </div>
            </div>
        `;

        this.updatePageContent('File Too Large', content);
    }

    showSplitHelp() {
        const helpContent = `
            <div class="help-container">
                <h2>✂️ How to Split Large SQL Files</h2>
                
                <div class="help-methods">
                    <div class="method">
                        <h3>Method 1: Command Line (Unix/Linux/Mac)</h3>
                        <code>split -l 1000 large_file.sql smaller_file_</code>
                        <p>Splits file into 1000-line chunks</p>
                    </div>
                    
                    <div class="method">
                        <h3>Method 2: Text Editor</h3>
                        <p>1. Open file in text editor<br>
                        2. Split at logical boundaries (between tables)<br>
                        3. Save each section as separate .sql file</p>
                    </div>
                    
                    <div class="method">
                        <h3>Method 3: Database Tools</h3>
                        <p>Use phpMyAdmin, MySQL Workbench, or similar tools with chunked import options</p>
                    </div>
                    
                    <div class="method">
                        <h3>Method 4: MySQL Dump Options</h3>
                        <code>mysqldump --single-transaction --quick --lock-tables=false</code>
                        <p>Generate more efficient dumps</p>
                    </div>
                </div>
                
                <button onclick="transferer.closeSplitHelp()" class="success">Got It</button>
            </div>
        `;

        const overlay = document.createElement('div');
        overlay.className = 'help-overlay';
        overlay.innerHTML = helpContent;
        document.body.appendChild(overlay);
    }

    closeSplitHelp() {
        const overlay = document.querySelector('.help-overlay');
        if (overlay) {
            overlay.remove();
        }
    }

    // Legacy method implementations for compatibility
    async deleteSqlFile() {
        try {
            if (!await this.confirmDeletion()) {
                return;
            }

            this.setLoadingState('Deleting file...', 'Please Wait');
            
            const params = {
                targetFile: this.targetFile,
                action: 'deleteFile'
            };

            const response = await this.makeRequest(params);
            const result = await response.text();

            if (result === 'Finished.') {
                this.updatePageContent('✅ File Deleted', `
                    <div class="success-message">
                        <h2>🗑️ File Successfully Deleted</h2>
                        <p>The SQL file has been removed from the module directory.</p>
                    </div>
                    <button class="success" onclick="transferer.clickOkay()">Continue</button>
                `);
            } else {
                throw new Error('Failed to delete file: ' + result);
            }
        } catch (error) {
            this.handleError('Delete Failed', error.message);
        }
    }

    async confirmDeletion() {
        return new Promise((resolve) => {
            const confirmed = confirm(
                'Are you sure you want to delete this SQL file?\n\n' +
                'This action cannot be undone.'
            );
            resolve(confirmed);
        });
    }

    drawShowSQLPage(sql, file, warning) {
        this.targetFile = file;
        this.sqlCode = sql;

        if (warning) {
            this.showWarningAlert('Potentially dangerous SQL code detected. Review the code carefully before execution!');
        }

        const content = `
            <div class="sql-display-container">
                <p>The contents of the SQL file are displayed below. Review the code before execution.</p>
                
                <div class="action-buttons">
                    <button onclick="transferer.goBack()">← Go Back</button>
                    <button class="success" onclick="transferer.drawConfRun()">✅ Run SQL</button>
                    <button class="danger" onclick="transferer.drawConfDelete()">🗑️ Delete File</button>
                    <button onclick="transferer.downloadOriginalSQL()" class="info">💾 Download</button>
                </div>
                
                <div class="sql-preview-container">
                    <h3>SQL Content</h3>
                    <textarea id="sql-preview" aria-label="SQL file content">${this.escapeHtml(sql)}</textarea>
                </div>
            </div>
        `;

        this.updatePageContent('SQL File Content', content);
    }

    downloadOriginalSQL() {
        if (!this.sqlCode) {
            this.showNotification('No SQL content available for download', 'warning');
            return;
        }

        const fileName = this.targetFile.split('/').pop() || 'sql_dump.sql';
        const blob = new Blob([this.sqlCode], { type: 'text/sql' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        
        a.href = url;
        a.download = `original_${fileName}`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);

        this.showNotification('Original SQL downloaded', 'success');
    }

    drawConfRun() {
        this.sqlCode = document.getElementById("sql-preview").value;

        const content = `
            <div class="confirmation-container">
                <h2>⚠️ Confirm SQL Execution</h2>
                <p>You are about to execute the SQL file:</p>
                <div class="file-info">
                    <strong>Location:</strong> ${this.escapeHtml(this.targetFile)}
                </div>
                
                <div class="warning-box">
                    <h3>⚠️ Important Warning:</h3>
                    <ul>
                        <li>This will modify your database</li>
                        <li>Make sure you have a backup</li>
                        <li>Review the SQL content carefully</li>
                        <li>Understand the risks involved</li>
                    </ul>
                </div>
                
                <div class="action-buttons">
                    <button onclick="transferer.goBack()">← Cancel</button>
                    <button onclick="transferer.previewSql()" class="info">👁️ Preview SQL</button>
                    <button class="success" onclick="transferer.runSql()">⚡ I Understand The Risks - Execute SQL</button>
                </div>
                
                <div id="sql-preview-section" style="display: none;">
                    <h3>SQL Preview</h3>
                    <textarea id="sql-preview" disabled aria-label="SQL preview for execution"></textarea>
                </div>
            </div>
        `;

        this.updatePageContent('Confirm Execution', content);
    }

    drawConfDelete() {
        const fileName = this.targetFile.split('/').pop();
        
        const content = `
            <div class="confirmation-container">
                <h2 class="danger">🗑️ Confirm File Deletion</h2>
                <p>You are about to permanently delete this SQL file:</p>
                <div class="file-info danger">
                    <strong>File:</strong> ${this.escapeHtml(fileName)}<br>
                    <strong>Location:</strong> ${this.escapeHtml(this.targetFile)}
                </div>
                
                <div class="warning-box danger">
                    <h3>⚠️ Warning:</h3>
                    <p>This action cannot be undone. The file will be permanently removed from the server.</p>
                </div>
                
                <div class="action-buttons">
                    <button onclick="transferer.goBack()">← Cancel</button>
                    <button class="danger" onclick="transferer.deleteSqlFile()">🗑️ Delete File</button>
                </div>
            </div>
        `;

        this.updatePageContent('Confirm Deletion', content);
    }

    previewSql() {
        const previewSection = document.getElementById("sql-preview-section");
        const previewTextarea = document.getElementById("sql-preview");
        
        if (previewSection && previewTextarea) {
            previewTextarea.value = this.sqlCode;
            previewSection.style.display = 'block';
            previewTextarea.scrollIntoView({ behavior: 'smooth' });
        }
    }

    async runSql() {
        try {
            this.setLoadingState('Executing SQL...', 'Please Wait', true);
            
            const params = {
                sqlCode: this.sqlCode,
                action: 'runSql',
                targetFile: this.targetFile
            };

            const response = await this.makeRequest(params);
            const result = await response.text();
            
            this.handleExecutionResult(response.status, result);
        } catch (error) {
            this.handleError('SQL Execution Failed', error.message);
        }
    }

    getFileSize(filePath) {
        // This would need to be implemented server-side
        // For now, return null to indicate unknown size
        return null;
    }

    // Legacy method compatibility
    async clickOkay() {
        try {
            const params = {
                sampleFile: window.firstSampleFile || '',
                action: 'getFinishUrl'
            };

            const response = await this.makeRequest(params);
            const result = await response.text();

            if (result === 'current_url') {
                location.reload();
            } else {
                window.location.href = window.baseUrl || '/';
            }
        } catch (error) {
            // Fallback behavior
            location.reload();
        }
    }
}

// Initialize enhanced transferer when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.transferer = new EnhancedTransferer();
});

// Export for use in inline handlers
window.EnhancedTransferer = EnhancedTransferer;