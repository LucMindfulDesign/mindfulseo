/**
 * Batch Optimizer JavaScript
 * 
 * Handles batch optimization UI and AJAX
 *
 * @package MindfulSEO
 * @since 1.1.0
 */

(function($) {
    'use strict';
    
    const COLUMN_STORAGE_KEY = 'mindfulseoBatchColumns';
    const COLUMN_ORDER_STORAGE_KEY = 'mindfulseoBatchColumnOrder';
    const DEFAULT_COLUMNS = {
        current_keyword: true,
        search_volume: true,
        difficulty: true,
        current_rank: true,
        seo_title: true,
        meta_description: true,
        slug: true,
        type: true,
        status: true,
        seo_score: true,
        optimization: true,
        actions: true
    };
    const DEFAULT_COLUMN_ORDER = [
        'current_keyword',
        'search_volume',
        'difficulty',
        'current_rank',
        'seo_title',
        'meta_description',
        'slug',
        'type',
        'status',
        'seo_score',
        'optimization',
        'actions'
    ];
    
    let columnVisibilityState = $.extend({}, DEFAULT_COLUMNS);
    let columnOrderState = DEFAULT_COLUMN_ORDER.slice();
    let currentSortState = {
        column: null,
        direction: 'asc'
    };
    
    let selectedPosts = [];
    let isOptimizing = false;
    let optimizationQueue = [];
    let currentIndex = 0;
    let stats = {
        total: 0,
        success: 0,
        errors: 0
    };
    
    // Initialize immediately if DOM is ready, otherwise wait
    function initAll() {
        initAutoSelect(); // Check for auto-select from URL
        initFilters();
        initSelection();
        initBatchOptimize();
        initSingleOptimize();
        initInlineEditing();
        initColumnToggles();
        initColumnSorting();
    }
    
    /**
     * Auto-select posts from URL parameter
     */
    function initAutoSelect() {
        const urlParams = new URLSearchParams(window.location.search);
        const autoSelect = urlParams.get('auto_select');
        const issueType = urlParams.get('issue_type');
        
        if (autoSelect) {
            const postIds = autoSelect.split(',').map(id => id.trim());
            const totalToSelect = postIds.length;
            
            console.log('Auto-select activated for post IDs:', postIds);
            
            // Wait a moment for table to render
            setTimeout(function() {
                let checkedCount = 0;
                
                console.log('Checking checkboxes...');
                
                // Check all checkboxes (since we've filtered the query to show only these posts)
                $('input[type="checkbox"].post-checkbox').each(function() {
                    $(this).prop('checked', true);
                    $(this).closest('tr').css('background-color', '#fff3cd');
                    checkedCount++;
                    console.log('Checked post:', $(this).val());
                });
                
                console.log('Total checked:', checkedCount);
                
                // IMPORTANT: Update the selectedPosts array and enable the button
                updateSelection();
                
                console.log('After updateSelection, selectedPosts:', selectedPosts);
                console.log('Batch optimize button disabled?', $('#batch-optimize-btn').prop('disabled'));
                
                if (checkedCount > 0) {
                    // Show prominent action panel
                    showAutoSelectPanel(totalToSelect, checkedCount, issueType);
                    
                    // Scroll to top of table
                    $('html, body').animate({
                        scrollTop: $('#posts-table').offset().top - 100
                    }, 500);
                }
            }, 800); // Increase timeout to 800ms
        }
    }
    
    /**
     * Show prominent auto-select action panel
     */
    function showAutoSelectPanel(totalCount, visibleCount, issueType) {
        const issueLabels = {
            'no_meta_description': 'Missing Meta Descriptions',
            'no_focus_keyword': 'Missing Focus Keywords',
            'low_seo_score': 'Low SEO Scores',
            'title_too_long': 'Titles Too Long',
            'thin_content': 'Thin Content'
        };
        
        const issueLabel = issueLabels[issueType] || 'SEO Issues';
        
        const panel = $(`
            <div id="mindfulseo-auto-select-panel" style="
                position: fixed;
                top: 100px;
                right: 30px;
                background: linear-gradient(135deg, #2271b1 0%, #135e96 100%);
                color: white;
                padding: 25px;
                border-radius: 12px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.3);
                z-index: 9999;
                max-width: 420px;
                animation: slideInRight 0.4s ease-out;
            ">
                <div style="display: flex; align-items: start; gap: 15px;">
                    <div style="
                        background: rgba(255,255,255,0.2);
                        border-radius: 50%;
                        width: 48px;
                        height: 48px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        flex-shrink: 0;
                    ">
                        <svg viewBox="0 0 24 24" fill="white" style="width: 28px; height: 28px;">
                            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                        </svg>
                    </div>
                    <div style="flex: 1;">
                        <h3 style="margin: 0 0 10px 0; font-size: 18px; font-weight: 600; color: white;">
                            ${visibleCount} Post${visibleCount > 1 ? 's' : ''} Selected
                        </h3>
                        <p style="margin: 0 0 15px 0; font-size: 13px; line-height: 1.6; color: rgba(255,255,255,0.9);">
                            Ready to fix: <strong>${issueLabel}</strong>
                        </p>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <button id="mindfulseo-start-optimization" class="button button-primary" style="
                                background: white;
                                color: #2271b1;
                                border: none;
                                padding: 10px 20px;
                                font-weight: 600;
                                font-size: 14px;
                                cursor: pointer;
                                border-radius: 6px;
                                box-shadow: 0 2px 8px rgba(0,0,0,0.15);
                                flex: 1;
                            ">
                                ⚡ Optimize ${visibleCount} Post${visibleCount > 1 ? 's' : ''}
                            </button>
                            <button id="mindfulseo-dismiss-panel" class="button" style="
                                background: transparent;
                                color: white;
                                border: 1px solid rgba(255,255,255,0.3);
                                padding: 10px 15px;
                                cursor: pointer;
                                border-radius: 6px;
                            ">
                                Cancel
                            </button>
                        </div>
                        <p style="margin: 15px 0 0; font-size: 11px; color: rgba(255,255,255,0.7);">
                            💡 AI will optimize in the best order: Keyword → Title → Description
                        </p>
                    </div>
                </div>
            </div>
        `);
        
        // Add animation
        if (!$('#mindfulseo-animations').length) {
            $('head').append(`
                <style id="mindfulseo-animations">
                    @keyframes slideInRight {
                        from {
                            transform: translateX(400px);
                            opacity: 0;
                        }
                        to {
                            transform: translateX(0);
                            opacity: 1;
                        }
                    }
                </style>
            `);
        }
        
        $('body').append(panel);
        
        // "Optimize Now" button triggers batch optimization
        $('#mindfulseo-start-optimization').on('click', function() {
            console.log('Optimize button clicked, selectedPosts:', selectedPosts);
            
            // Update panel to show "Starting..."
            $(this).text('⚡ Starting...').prop('disabled', true);
            
            // Double-check selection is populated
            if (selectedPosts.length === 0) {
                console.error('selectedPosts array is empty, forcing update...');
                updateSelection();
            }
            
            console.log('Triggering batch optimize button, selectedPosts:', selectedPosts);
            
            // Remove panel after a brief delay
            setTimeout(function() {
                $('#mindfulseo-auto-select-panel').fadeOut(300, function() {
                    $(this).remove();
                });
            }, 500);
            
            // Trigger the batch optimize button (no confirmation dialog)
            $('#batch-optimize-btn').trigger('click');
        });
        
        // Dismiss button - go back to SEO Audit
        $('#mindfulseo-dismiss-panel').on('click', function() {
            window.location.href = 'admin.php?page=mindfulseo-seo-audit';
        });
    }
    
    /**
     * Show admin notice
     */
    function showNotice(type, message) {
        const noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
        const notice = $('<div class="notice ' + noticeClass + ' is-dismissible" style="margin: 20px 0;"><p>' + message + '</p></div>');
        $('.mindfulseo-content').prepend(notice);
        
        // Make dismissible
        notice.on('click', '.notice-dismiss', function() {
            notice.remove();
        });
        
        // Add dismiss button
        notice.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>');
    }
    
    if (document.readyState === 'loading') {
        $(document).ready(initAll);
    } else {
        initAll();
    }
    
    /**
     * Initialize filter functionality
     */
    function initFilters() {
        $('#apply-filters').on('click', applyFilters);
        $('#reset-filters').on('click', resetFilters);
        
        // Apply filters on Enter key
        $('.mindfulseo-filters select').on('keypress', function(e) {
            if (e.which === 13) {
                applyFilters();
            }
        });
    }
    
    /**
     * Apply filters to posts table
     */
    function applyFilters() {
        const postType = $('#post-type-filter').val();
        const status = $('#status-filter').val();
        const dateFilter = $('#date-filter').val();
        const perPage = $('#per-page-filter').val();

        const url = new URL(mfseoBatchOptimizer.baseUrl);
        url.searchParams.set('page', mfseoBatchOptimizer.pageSlug);

        if (postType && postType !== 'all') {
            url.searchParams.set('filter_post_type', postType);
        } else {
            url.searchParams.delete('filter_post_type');
        }

        if (status && status !== 'all') {
            url.searchParams.set('filter_status', status);
        } else {
            url.searchParams.delete('filter_status');
        }

        if (dateFilter && dateFilter !== 'all') {
            url.searchParams.set('filter_date', dateFilter);
        } else {
            url.searchParams.delete('filter_date');
        }

        if (perPage && parseInt(perPage, 10) !== parseInt(mfseoBatchOptimizer.defaultPerPage, 10)) {
            url.searchParams.set('per_page', perPage);
        } else {
            url.searchParams.delete('per_page');
        }

        // Reset to first page whenever filters change
        url.searchParams.set('paged', '1');

        window.location.href = url.toString();
    }
    
    /**
     * Reset all filters
     */
    function resetFilters() {
        const url = new URL(mfseoBatchOptimizer.baseUrl);
        url.searchParams.set('page', mfseoBatchOptimizer.pageSlug);
        window.location.href = url.toString();
    }
    
    /**
     * Initialize selection functionality
     */
    function initSelection() {
        // Select all checkbox (header)
        $('#select-all-header').on('change', function() {
            const isChecked = $(this).prop('checked');
            $('#posts-table tbody tr:visible .post-checkbox').prop('checked', isChecked);
            updateSelection();
        });
        
        // Individual checkboxes
        $(document).on('change', '.post-checkbox', function() {
            updateSelection();
        });

        // Initialize count state on load
        updateSelection();
    }
    
    /**
     * Update selected posts array and UI
     */
    function updateSelection() {
        selectedPosts = [];
        $('.post-checkbox:checked').each(function() {
            selectedPosts.push(parseInt($(this).val()));
        });
        
        updateSelectedCount();
        
        // Enable/disable batch button
        $('#batch-optimize-btn').prop('disabled', selectedPosts.length === 0);
    }
    
    /**
     * Update selected count display
     */
    function updateSelectedCount() {
        $('.selected-count strong').text(selectedPosts.length);
    }
    
    /**
     * Initialize batch optimize functionality
     */
    function initBatchOptimize() {
        $('#batch-optimize-btn').on('click', function(e) {
            console.log('=== BATCH OPTIMIZE BUTTON CLICKED ===');
            console.log('Event:', e);
            console.log('selectedPosts:', selectedPosts);
            console.log('selectedPosts.length:', selectedPosts.length);
            console.log('Button disabled?', $(this).prop('disabled'));
            
            if (selectedPosts.length === 0) {
                console.error('No posts selected!');
                // Show a visual notice instead of alert
                showNotice('error', 'Please select at least one post to optimize.');
                return;
            }
            
            // REMOVED CONFIRMATION DIALOG - Start optimization immediately
            console.log('Starting batch optimization...');
            startBatchOptimization();
        });
        
        $('#close-progress-modal').on('click', function() {
            $('#batch-progress-modal').hide();
            // Don't reload - table already updated in real-time
        });
    }
    
    /**
     * Start batch optimization process
     */
    function startBatchOptimization() {
        isOptimizing = true;
        optimizationQueue = [...selectedPosts];
        currentIndex = 0;
        stats = {
            total: selectedPosts.length,
            success: 0,
            errors: 0
        };
        
        // Show progress modal
        $('#batch-progress-modal').show();
        $('#batch-progress-total').text(stats.total);
        $('#close-progress-modal').prop('disabled', true);
        $('#batch-progress-log').html('');
        
        // Disable all controls
        $('.post-checkbox, #select-all-header, #batch-optimize-btn').prop('disabled', true);
        
        // Start processing
        processNextPost();
    }
    
    /**
     * Process next post in queue
     */
    function processNextPost() {
        if (currentIndex >= optimizationQueue.length) {
            // All done!
            finishBatchOptimization();
            return;
        }
        
        const postId = optimizationQueue[currentIndex];
        const $row = $(`tr[data-post-id="${postId}"]`);
        const postTitle = $row.find('td:nth-child(2) strong').text().trim();
        
        // Update UI
        $('#current-post-title').text(postTitle);
        logProgress(`Optimizing: ${postTitle}...`, 'info');
        
        // Call AJAX to optimize post
        $.ajax({
            url: mfseoBatchOptimizer.ajaxUrl,
            method: 'POST',
            data: {
                action: 'mindfulseo_batch_optimize_single',
                nonce: mfseoBatchOptimizer.nonce,
                post_id: postId
            },
            success: function(response) {
                if (response.success) {
                    stats.success++;
                    logProgress(`✅ ${postTitle} - Optimized successfully`, 'success');
                    
                    // Update table row with real-time SEO data
                    if (response.data && response.data.seo_data) {
                        const seoData = response.data.seo_data;
                        
                        // Update optimization status badge
                        $row.find('.opt-badge').html('✅ Optimized<br><small>Just now</small>');
                        $row.data('status', 'approved');
                        
                        // Update SEO fields in the table (columns 3-6)
                        $row.find('td:nth-child(3) .editable').text(seoData.keyword !== '—' ? seoData.keyword : '');
                        $row.find('td:nth-child(4) .editable').text(seoData.title !== '—' ? seoData.title.substring(0, 60) : '');
                        $row.find('td:nth-child(5) .editable').text(seoData.description !== '—' ? seoData.description.substring(0, 160) : '');
                        $row.find('td:nth-child(6) .editable').text(seoData.slug !== '—' ? seoData.slug : '');
                    }
                } else {
                    stats.errors++;
                    logProgress(`❌ ${postTitle} - ${response.data || 'Optimization failed'}`, 'error');
                }
                
                currentIndex++;
                updateProgress();
                
                // Continue to next post
                setTimeout(processNextPost, 500); // Small delay between posts
            },
            error: function() {
                stats.errors++;
                logProgress(`❌ ${postTitle} - Network error`, 'error');
                
                currentIndex++;
                updateProgress();
                
                // Continue despite error
                setTimeout(processNextPost, 500);
            }
        });
    }
    
    /**
     * Update progress bar and counts
     */
    function updateProgress() {
        const percent = Math.round((currentIndex / stats.total) * 100);
        
        $('#batch-progress-fill').css('width', percent + '%');
        $('#batch-progress-percent').text(percent + '%');
        $('#progress-current').text(currentIndex);
        $('#progress-success').text(stats.success);
        $('#progress-errors').text(stats.errors);
    }
    
    /**
     * Log progress message
     */
    function logProgress(message, type = 'info') {
        const timestamp = new Date().toLocaleTimeString();
        const typeClass = `log-${type}`;
        const icon = type === 'success' ? '✅' : type === 'error' ? '❌' : 'ℹ️';
        
        const logEntry = `
            <div class="progress-log-entry ${typeClass}">
                <span class="log-time">${timestamp}</span>
                <span class="log-icon">${icon}</span>
                <span class="log-message">${message}</span>
            </div>
        `;
        
        $('#batch-progress-log').prepend(logEntry);
        
        // Scroll to top
        $('#batch-progress-log').scrollTop(0);
    }
    
    /**
     * Finish batch optimization
     */
    function finishBatchOptimization() {
        isOptimizing = false;
        $('#close-progress-modal').prop('disabled', false);
        
        logProgress(`Batch optimization complete! ${stats.success} successful, ${stats.errors} errors.`, 'success');
        
        // Re-enable controls
        $('.post-checkbox, #select-all-header, #batch-optimize-btn').prop('disabled', false);
    }
    
    /**
     * Initialize single post optimize buttons
     */
    function initSingleOptimize() {
        $(document).on('click', '.optimize-single', function() {
            const postId = $(this).data('post-id');
            const $btn = $(this);
            const originalText = $btn.text();
            
            $btn.prop('disabled', true).text('Optimizing...');
            
            $.ajax({
                url: mfseoBatchOptimizer.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'mindfulseo_batch_optimize_single',
                    nonce: mfseoBatchOptimizer.nonce,
                    post_id: postId
                },
                success: function(response) {
                    if (response.success) {
                        const $row = $(`tr[data-post-id="${postId}"]`);
                        
                        // Update table row with real-time SEO data
                        if (response.data && response.data.seo_data) {
                            const seoData = response.data.seo_data;
                            
                            // Update optimization status badge
                            $row.find('.opt-badge').html('✅ Optimized<br><small>Just now</small>');
                            $row.data('status', 'approved');
                            
                            // Update SEO fields in the table (columns 3-6)
                            $row.find('td:nth-child(3) .editable').text(seoData.keyword !== '—' ? seoData.keyword : '');
                            $row.find('td:nth-child(4) .editable').text(seoData.title !== '—' ? seoData.title.substring(0, 60) : '');
                            $row.find('td:nth-child(5) .editable').text(seoData.description !== '—' ? seoData.description.substring(0, 160) : '');
                            $row.find('td:nth-child(6) .editable').text(seoData.slug !== '—' ? seoData.slug : '');
                            
                            // Update button text
                            $btn.text('Re-Optimize');
                        }
                        
                        alert('Post optimized successfully!');
                        $btn.prop('disabled', false);
                    } else {
                        alert('Optimization failed: ' + (response.data || 'Unknown error'));
                        $btn.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    alert('Network error. Please try again.');
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        });
    }
    
    /**
     * Initialize inline editing for table cells
     */
    function initInlineEditing() {
        let originalValue = '';
        let isProcessing = false; // Prevent multiple simultaneous saves
        
        // Save original value on focus
        $(document).on('focus', '.editable[contenteditable="true"]', function() {
            originalValue = $(this).text().trim();
            isProcessing = false; // Reset processing flag on new focus
        });
        
        // Handle blur (save on focus out)
        $(document).on('blur', '.editable[contenteditable="true"]', function() {
            const $cell = $(this);
            const newValue = $cell.text().trim();
            const postId = $cell.data('post-id');
            const field = $cell.data('field');
            
            // Only save if value changed and not already processing
            if (newValue !== originalValue && !isProcessing) {
                isProcessing = true; // Set flag to prevent duplicate saves
                saveInlineEdit(postId, field, newValue, $cell, function() {
                    isProcessing = false; // Reset flag after save completes
                });
            }
        });
        
        // Handle Enter key (save and move to next)
        $(document).on('keydown', '.editable[contenteditable="true"]', function(e) {
            if (e.which === 13) {  // Enter key
                e.preventDefault();
                $(this).blur();  // Trigger save
            } else if (e.which === 27) {  // Escape key
                e.preventDefault();
                $(this).text(originalValue);  // Restore original value
                $(this).blur();
            }
        });
    }
    
    /**
     * Initialize column visibility toggles
     */
    function initColumnToggles() {
        const $controls = $('.mindfulseo-column-controls');
        if (!$controls.length) {
            return;
        }

        // Load visibility state
        try {
            const storedVisibility = JSON.parse(localStorage.getItem(COLUMN_STORAGE_KEY));
            if (storedVisibility && typeof storedVisibility === 'object') {
                columnVisibilityState = $.extend({}, DEFAULT_COLUMNS, storedVisibility);
            }
        } catch (error) {
            console.warn('MindfulSEO: Failed to parse column visibility state', error);
        }

        // Load column order
        try {
            const storedOrder = JSON.parse(localStorage.getItem(COLUMN_ORDER_STORAGE_KEY));
            if (Array.isArray(storedOrder)) {
                columnOrderState = normalizeColumnOrder(storedOrder);
            }
        } catch (error) {
            console.warn('MindfulSEO: Failed to parse column order state', error);
        }

        // Sync checkbox state with stored preferences
        $controls.find('input[type="checkbox"]').each(function() {
            const column = $(this).data('column');
            const isVisible = columnVisibilityState[column] !== false;
            $(this).prop('checked', isVisible);
        });

        reorderTogglePanel(columnOrderState);
        reorderColumns(columnOrderState);
        applyColumnVisibility(columnVisibilityState);

        // Toggle column panel visibility
        $controls.on('click', '.column-toggle-button', function(e) {
            e.preventDefault();
            $controls.toggleClass('is-open');
        });

        $(document).on('click', function(e) {
            if (!$(e.target).closest('.mindfulseo-column-controls').length) {
                $controls.removeClass('is-open');
            }
        });

        // Handle checkbox changes
        $controls.on('change', 'input[type="checkbox"]', function() {
            const column = $(this).data('column');
            columnVisibilityState[column] = $(this).is(':checked');

            try {
                localStorage.setItem(COLUMN_STORAGE_KEY, JSON.stringify(columnVisibilityState));
            } catch (error) {
                console.warn('MindfulSEO: Unable to persist column visibility state', error);
            }

            applyColumnVisibility(columnVisibilityState);
        });

        // Enable drag-and-drop ordering for column toggles
        const $optionsContainer = $controls.find('.column-toggle-options');
        if ($optionsContainer.length) {
            $optionsContainer.sortable({
                items: 'label',
                axis: 'y',
                containment: 'parent',
                update: function() {
                    columnOrderState = $optionsContainer.find('input[type="checkbox"]').map(function() {
                        return $(this).data('column');
                    }).get();

                    persistColumnOrder();
                    reorderColumns(columnOrderState);
                    applyColumnVisibility(columnVisibilityState);
                }
            }).disableSelection();
        }

        persistColumnOrder();
    }

    /**
     * Apply column visibility classes based on state
     *
     * @param {Object} state Column visibility state
     */
    function applyColumnVisibility(state) {
        Object.keys(DEFAULT_COLUMNS).forEach(function(column) {
            const visible = state[column] !== false;
            const selector = '[data-column="' + column + '"]';
            const elements = document.querySelectorAll(selector);

            elements.forEach(function(element) {
                if (visible) {
                    element.classList.remove('column-hidden');
                } else {
                    element.classList.add('column-hidden');
                }
            });

            if (!visible && currentSortState.column === column) {
                currentSortState.column = null;
                currentSortState.direction = 'asc';
                updateSortIndicators(null);
            }
        });
    }

    /**
     * Normalize stored column order with current defaults
     */
    function normalizeColumnOrder(order) {
        const normalized = Array.isArray(order) ? order.filter(function(column) {
            return DEFAULT_COLUMN_ORDER.includes(column);
        }) : [];

        DEFAULT_COLUMN_ORDER.forEach(function(column) {
            if (!normalized.includes(column)) {
                normalized.push(column);
            }
        });

        return normalized;
    }

    /**
     * Persist column order to localStorage
     */
    function persistColumnOrder() {
        try {
            localStorage.setItem(COLUMN_ORDER_STORAGE_KEY, JSON.stringify(columnOrderState));
        } catch (error) {
            console.warn('MindfulSEO: Unable to persist column order state', error);
        }
    }

    /**
     * Reorder toggle panel labels to match column order
     */
    function reorderTogglePanel(order) {
        const container = document.querySelector('.mindfulseo-column-controls .column-toggle-options');
        if (!container) {
            return;
        }

        order.forEach(function(column) {
            const label = container.querySelector('input[data-column="' + column + '"]');
            if (label && label.parentElement) {
                container.appendChild(label.parentElement);
            }
        });
    }

    /**
     * Reorder table columns to reflect the desired order
     */
    function reorderColumns(order) {
        const table = document.getElementById('posts-table');
        if (!table || !table.tHead || !table.tBodies.length) {
            return;
        }

        const headerRow = table.tHead.rows[0];
        const bodyRows = Array.from(table.tBodies[0].rows);

        order.forEach(function(column) {
            const headerCell = headerRow.querySelector('th[data-column="' + column + '"]');
            if (headerCell) {
                headerRow.appendChild(headerCell);
            }

            bodyRows.forEach(function(row) {
                const cell = row.querySelector('td[data-column="' + column + '"]');
                if (cell) {
                    row.appendChild(cell);
                }
            });
        });

        if (currentSortState.column) {
            updateSortIndicators(currentSortState.column, currentSortState.direction);
        }
    }

    /**
     * Initialize table header sorting
     */
    function initColumnSorting() {
        const $table = $('#posts-table');
        if (!$table.length) {
            return;
        }

        $table.on('click', 'th.sortable-column', function() {
            const $header = $(this);
            const column = $header.data('column');
            const sortType = $header.data('sortType') || 'text';
            const sortable = $header.data('sortable');

            if (
                $header.hasClass('column-hidden') ||
                sortable === false ||
                sortable === 'false'
            ) {
                return;
            }

            if (currentSortState.column === column) {
                currentSortState.direction = currentSortState.direction === 'asc' ? 'desc' : 'asc';
            } else {
                currentSortState.column = column;
                currentSortState.direction = 'asc';
            }

            sortTableByColumn(column, sortType, currentSortState.direction);
            updateSortIndicators(column, currentSortState.direction);
        });
    }

    /**
     * Sort table rows by column
     */
    function sortTableByColumn(column, sortType, direction) {
        const table = document.getElementById('posts-table');
        if (!table || !table.tBodies.length) {
            return;
        }

        const tbody = table.tBodies[0];
        const rows = Array.from(tbody.rows);
        const multiplier = direction === 'asc' ? 1 : -1;

        rows.sort(function(rowA, rowB) {
            const cellA = rowA.querySelector('td[data-column="' + column + '"]');
            const cellB = rowB.querySelector('td[data-column="' + column + '"]');

            const valueA = getSortableValue(cellA, sortType);
            const valueB = getSortableValue(cellB, sortType);

            if (valueA < valueB) {
                return -1 * multiplier;
            }
            if (valueA > valueB) {
                return 1 * multiplier;
            }
            return 0;
        });

        rows.forEach(function(row) {
            tbody.appendChild(row);
        });
    }

    /**
     * Extract comparable value from a table cell
     */
    function getSortableValue(cell, sortType) {
        if (!cell) {
            return sortType === 'numeric' ? 0 : '';
        }

        const rawValue = cell.getAttribute('data-sort-value') || cell.textContent || '';
        if (sortType === 'numeric') {
            const numeric = parseFloat(String(rawValue).replace(/[^0-9.-]/g, ''));
            return isNaN(numeric) ? 0 : numeric;
        }

        return String(rawValue).toLowerCase().trim();
    }

    /**
     * Update header sort indicators
     */
    function updateSortIndicators(column, direction) {
        const headers = document.querySelectorAll('#posts-table th.sortable-column');
        headers.forEach(function(header) {
            header.classList.remove('sorted-asc', 'sorted-desc');
        });

        if (!column) {
            return;
        }

        const activeHeader = document.querySelector('#posts-table th.sortable-column[data-column="' + column + '"]');
        if (activeHeader) {
            activeHeader.classList.add(direction === 'asc' ? 'sorted-asc' : 'sorted-desc');
        }
    }
    
    /**
     * Save inline edit via AJAX
     */
    function saveInlineEdit(postId, field, value, $cell, callback) {
        const originalText = $cell.text();
        $cell.css('opacity', '0.5');
        
        $.ajax({
            url: mfseoBatchOptimizer.ajaxUrl,
            method: 'POST',
            data: {
                action: 'mindfulseo_update_post_seo',
                nonce: mfseoBatchOptimizer.nonce,
                post_id: postId,
                field: field,
                value: value
            },
            success: function(response) {
                $cell.css('opacity', '1');
                if (response.success) {
                    // Visual feedback - flash green
                    $cell.css('background-color', '#d4edda');
                    setTimeout(function() {
                        $cell.css('background-color', '');
                    }, 1000);
                } else {
                    // Show error but don't restore original text so user can retry
                    console.error('MindfulSEO inline edit error:', response.data);
                    $cell.css('background-color', '#f8d7da');
                    setTimeout(function() {
                        $cell.css('background-color', '');
                    }, 2000);
                }
                if (callback) callback();
            },
            error: function(xhr, status, error) {
                $cell.css('opacity', '1');
                console.error('MindfulSEO inline edit network error:', error, xhr.responseText);
                // Show error indication
                $cell.css('background-color', '#f8d7da');
                setTimeout(function() {
                    $cell.css('background-color', '');
                }, 2000);
                if (callback) callback();
            }
        });
    }
    
    // Refresh Metrics button - fetch fresh data from DataForSEO for keywords on this page
    $('#refresh-page-btn').on('click', function(e) {
        e.preventDefault();
        console.log('Refresh Metrics button clicked');
        
        var $button = $(this);
        
        // Collect all unique keywords from the current page
        var keywords = [];
        $('#posts-table tbody tr').each(function() {
            var keyword = $(this).find('[data-column="current_keyword"] .editable').text().trim();
            if (keyword && keyword !== '—' && keyword !== '') {
                keywords.push(keyword);
            }
        });
        
        // Remove duplicates
        keywords = [...new Set(keywords)];
        
        console.log('Collected ' + keywords.length + ' unique keywords from page:', keywords.slice(0, 5));
        
        if (keywords.length === 0) {
            alert('No keywords found on this page to refresh.');
            return;
        }
        
        $button.prop('disabled', true).html('<span class="dashicons dashicons-update dashicons-spin" style="vertical-align: middle;"></span> Refreshing ' + keywords.length + ' Keywords...');
        
        console.log('AJAX URL:', mfseoBatchOptimizer.ajaxUrl);
        console.log('Nonce:', mfseoBatchOptimizer.nonce);
        
        $.ajax({
            url: mfseoBatchOptimizer.ajaxUrl,
            method: 'POST',
            data: {
                action: 'mindfulseo_recalculate_rankmath_scores',
                nonce: mfseoBatchOptimizer.nonce,
                keywords: keywords  // Send the actual keywords from this page
            },
            success: function(response) {
                console.log('AJAX Success:', response);
                
                if (response.success) {
                    $button.html('<span class="dashicons dashicons-update dashicons-spin" style="vertical-align: middle;"></span> Reloading...');
                    
                    // Show success message
                    if (response.data.message) {
                        alert(response.data.message);
                    }
                    
                    // Reload page to show updated data
                    if (response.data.reload) {
                        location.reload();
                    } else {
                        $button.prop('disabled', false).html('<span class="dashicons dashicons-update" style="vertical-align: middle;"></span> Refresh Metrics');
                    }
                } else {
                    console.error('AJAX Error:', response.data);
                    alert('Error: ' + (response.data.message || 'Failed to refresh metrics'));
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-update" style="vertical-align: middle;"></span> Refresh Metrics');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Network Error:', xhr.responseText, status, error);
                alert('Network error. Please try again. Check console for details.');
                $button.prop('disabled', false).html('<span class="dashicons dashicons-update" style="vertical-align: middle;"></span> Refresh Metrics');
            }
        });
    });
    
    // ===================
    // ANALYZE SITE RANKINGS (Batch Optimizer)
    // ===================
    
    $('#mindfulseo-analyze-rankings-batch-btn').on('click', function() {
        var $button = $(this);
        
        // Create custom confirmation dialog
        if ($('#mindfulseo-analyze-confirm-dialog').length === 0) {
            var $dialog = $('<div id="mindfulseo-analyze-confirm-dialog" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.3); z-index: 999999; max-width: 500px;">' +
                '<h2 style="margin: 0 0 15px 0; color: #2271b1; font-size: 20px;">📊 Analyze Site Rankings?</h2>' +
                '<p style="margin: 0 0 20px 0; line-height: 1.6;">This will analyze what keywords your site currently ranks for in Google and update the <strong>Current Rank</strong> column.<br><br>This may take a few moments and will <strong>consume API credits</strong>.</p>' +
                '<div style="display: flex; gap: 10px; justify-content: flex-end;">' +
                '<button id="mindfulseo-analyze-cancel" class="button" style="padding: 8px 20px;">Cancel</button>' +
                '<button id="mindfulseo-analyze-confirm" class="button button-primary" style="padding: 8px 20px;">Analyze Now</button>' +
                '</div>' +
                '</div>');
            
            var $overlay = $('<div id="mindfulseo-analyze-overlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999998;"></div>');
            
            $('body').append($overlay, $dialog);
            
            $('#mindfulseo-analyze-confirm').on('click', function() {
                console.log('MindfulSEO: Analyze Site Rankings confirmed (Batch Optimizer)');
                $('#mindfulseo-analyze-confirm-dialog, #mindfulseo-analyze-overlay').remove();
                
                // Disable button and show loading state
                $button.prop('disabled', true)
                       .html('<span class="dashicons dashicons-update dashicons-spin" style="vertical-align: middle;"></span> Analyzing...');
                
                $.ajax({
                    url: mfseoBatchOptimizer.ajaxUrl,
                    type: 'POST',
                    timeout: 300000, // 5 minutes timeout
                    data: {
                        action: 'mindfulseo_analyze_site_rankings',
                        nonce: mfseoBatchOptimizer.ajaxNonce
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            // Reload the page to show updated data
                            location.reload();
                        } else {
                            alert('Error: ' + response.data.message);
                            $button.prop('disabled', false)
                                   .html('<span class="dashicons dashicons-chart-line" style="vertical-align: middle;"></span> Analyze Site Rankings');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Analyze Site Rankings error:', status, error);
                        alert('Request failed: ' + (status === 'timeout' ? 'Request timed out. Please try again.' : 'Please try again.'));
                        $button.prop('disabled', false)
                               .html('<span class="dashicons dashicons-chart-line" style="vertical-align: middle;"></span> Analyze Site Rankings');
                    }
                });
            });
            
            $('#mindfulseo-analyze-cancel, #mindfulseo-analyze-overlay').on('click', function() {
                console.log('MindfulSEO: Analyze cancelled');
                $('#mindfulseo-analyze-confirm-dialog, #mindfulseo-analyze-overlay').remove();
            });
        }
    });
    
    // ================================================
    // Custom Prompts Section
    // ================================================
    
    // Toggle prompts section
    $('.toggle-prompts-btn, .mindfulseo-section-header').on('click', function(e) {
        e.preventDefault();
        const $button = $('.toggle-prompts-btn');
        const $content = $('.prompts-content');
        const $icon = $button.find('.dashicons');
        
        if ($content.is(':visible')) {
            $content.slideUp(200);
            $icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
            $button.find('text').text('Show');
        } else {
            $content.slideDown(200);
            $icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
            $button.find('text').text('Hide');
        }
    });
    
    // Save custom prompt
    $('.save-prompt-btn').on('click', function() {
        const $button = $(this);
        const promptType = $button.data('prompt-type');
        const promptValue = $('#batch-optimizer-prompt').val();
        const $status = $('.prompt-save-status');
        
        $button.prop('disabled', true).html('<span class="dashicons dashicons-update dashicons-spin"></span> Saving...');
        
        $.ajax({
            url: mfseoBatchOptimizer.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mindfulseo_save_custom_prompt',
                nonce: mfseoBatchOptimizer.ajaxNonce,
                prompt_type: promptType,
                prompt_value: promptValue
            },
            success: function(response) {
                if (response.success) {
                    $status.fadeIn().delay(3000).fadeOut();
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> Save Prompt');
                } else {
                    alert('Error saving prompt: ' + response.data.message);
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> Save Prompt');
                }
            },
            error: function() {
                alert('Error saving prompt. Please try again.');
                $button.prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> Save Prompt');
            }
        });
    });
    
    // Reset to default prompt
    $('.reset-prompt-btn').on('click', function() {
        if (!confirm('Are you sure you want to reset to the default prompt? This will remove your custom instructions.')) {
            return;
        }
        
        const $button = $(this);
        const promptType = $button.data('prompt-type');
        
        $button.prop('disabled', true).html('<span class="dashicons dashicons-update dashicons-spin"></span> Resetting...');
        
        $.ajax({
            url: mfseoBatchOptimizer.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mindfulseo_save_custom_prompt',
                nonce: mfseoBatchOptimizer.ajaxNonce,
                prompt_type: promptType,
                prompt_value: ''
            },
            success: function(response) {
                if (response.success) {
                    $('#batch-optimizer-prompt').val('');
                    alert('Prompt reset to default successfully!');
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Reset to Default');
                } else {
                    alert('Error resetting prompt: ' + response.data.message);
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Reset to Default');
                }
            },
            error: function() {
                alert('Error resetting prompt. Please try again.');
                $button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Reset to Default');
            }
        });
    });
    
})(jQuery);

