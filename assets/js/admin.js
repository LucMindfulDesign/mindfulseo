/**
 * MindfulSEO Admin JavaScript
 * 
 * @package MindfulSEO
 * @since 1.0.0
 */

(function($) {
    'use strict';
    
    console.log('MindfulSEO admin.js loaded - START');
    
    $(document).ready(function() {
        console.log('MindfulSEO document.ready - START');
        
        // CRITICAL FIX: Move WordPress notices BEFORE the branding header
        // WordPress automatically injects notices into .wrap divs, but we want them above our header
        moveNoticesToTop();
        
        // Re-run when new notices are added (e.g., via AJAX)
        var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length) {
                    moveNoticesToTop();
                }
            });
        });
        
        var $wrap = $('.wrap.mindfulseo-dashboard, .wrap.mindfulseo-settings-wrap, .wrap.mindfulseo-keywords-wrap, .wrap.mindfulseo-guidelines-wrap, .wrap.mindfulseo-page, .wrap.mindfulseo-admin-wrap');
        if ($wrap.length) {
            observer.observe($wrap[0], { childList: true, subtree: false });
        }
        
        // Initialize color pickers if present
        if ($.fn.wpColorPicker) {
            $('.color-picker').wpColorPicker();
        }
        
        // Handle settings form submission
        $('#mindfulseo-settings-form').on('submit', function(e) {
            var $form = $(this);
            var $button = $form.find('input[type="submit"]');
            
            // Show loading state
            $button.prop('disabled', true).val(mindfulseoAdmin.strings.saving);
        });
        
        // Add success message handling
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('updated') === 'true') {
            // Show success notice
            var $notice = $('<div class="notice notice-success is-dismissible"><p>' + mindfulseoAdmin.strings.saved + '</p></div>');
            $('.wrap h1').after($notice);
            
            // Remove updated param from URL
            var newUrl = window.location.href.split('&updated=')[0];
            window.history.replaceState({}, document.title, newUrl);
        }
        
        // ===================
        // API CONNECTION TESTING
        // ===================
        
        // Test OpenAI Connection
        $('#test-openai-connection').on('click', function(e) {
            e.preventDefault();
            var $button = $(this);
            var $indicator = $('#openai-status-indicator');
            
            // Get current form values
            var apiKey = $('input[name="openai_api_key"]').val();
            var model = $('select[name="openai_model"]').val();
            
            // Show loading
            $button.prop('disabled', true).html('<span class="dashicons dashicons-update dashicons-spin" style="vertical-align: middle; margin-top: 3px;"></span> Testing...');
            $indicator.html('<span style="color: #666;">⏳ Testing...</span>');
            
            $.ajax({
                url: mindfulseoAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'mindfulseo_test_openai',
                    nonce: mindfulseoAdmin.nonces.test_api,
                    api_key: apiKey,
                    model: model
                },
                success: function(response) {
                    if (response.success) {
                        $indicator.html(
                            '<span style="color: #46b450; font-weight: 600;">' +
                            '✅ Connected (' + response.data.response_time + 'ms)' +
                            '</span>'
                        );
                    } else {
                        $indicator.html(
                            '<span style="color: #dc3232; font-weight: 600;">' +
                            '❌ Failed: ' + response.data.message +
                            '</span>'
                        );
                    }
                },
                error: function() {
                    $indicator.html(
                        '<span style="color: #dc3232;">❌ Request failed</span>'
                    );
                },
                complete: function() {
                    $button.prop('disabled', false).html(
                        '<span class="dashicons dashicons-admin-plugins" style="vertical-align: middle; margin-top: 3px;"></span> Test Connection'
                    );
                }
            });
        });
        
        // Test Claude Connection
        $('#test-claude-connection').on('click', function(e) {
            e.preventDefault();
            var $button = $(this);
            var $indicator = $('#claude-status-indicator');
            
            // Get current form values
            var apiKey = $('input[name="claude_api_key"]').val();
            var model = $('select[name="claude_model"]').val();
            
            // Show loading
            $button.prop('disabled', true).html('<span class="dashicons dashicons-update dashicons-spin" style="vertical-align: middle; margin-top: 3px;"></span> Testing...');
            $indicator.html('<span style="color: #666;">⏳ Testing...</span>');
            
            $.ajax({
                url: mindfulseoAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'mindfulseo_test_claude',
                    nonce: mindfulseoAdmin.nonces.test_api,
                    api_key: apiKey,
                    model: model
                },
                success: function(response) {
                    if (response.success) {
                        $indicator.html(
                            '<span style="color: #46b450; font-weight: 600;">' +
                            '✅ Connected (' + response.data.response_time + 'ms)' +
                            '</span>'
                        );
                    } else {
                        $indicator.html(
                            '<span style="color: #dc3232; font-weight: 600;">' +
                            '❌ Failed: ' + response.data.message +
                            '</span>'
                        );
                    }
                },
                error: function() {
                    $indicator.html(
                        '<span style="color: #dc3232;">❌ Request failed</span>'
                    );
                },
                complete: function() {
                    $button.prop('disabled', false).html(
                        '<span class="dashicons dashicons-admin-plugins" style="vertical-align: middle; margin-top: 3px;"></span> Test Connection'
                    );
                }
            });
        });
        
        // Test DataForSEO Connection
        $('#test-dataforseo-connection').on('click', function(e) {
            e.preventDefault();
            var $button = $(this);
            var $indicator = $('#dataforseo-status-indicator');
            
            // Get current form values
            var login = $('input[name="dataforseo_login"]').val();
            var password = $('input[name="dataforseo_password"]').val();
            
            // Show loading
            $button.prop('disabled', true).html('<span class="dashicons dashicons-update dashicons-spin" style="vertical-align: middle; margin-top: 3px;"></span> Testing...');
            $indicator.html('<span style="color: #666;">⏳ Testing...</span>');
            
            $.ajax({
                url: mindfulseoAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'mindfulseo_test_dataforseo',
                    nonce: mindfulseoAdmin.nonces.test_api,
                    login: login,
                    password: password
                },
                success: function(response) {
                    if (response.success) {
                        $indicator.html(
                            '<span style="color: #46b450; font-weight: 600;">' +
                            '✅ ' + response.data.message +
                            '</span>'
                        );
                    } else {
                        $indicator.html(
                            '<span style="color: #dc3232; font-weight: 600;">' +
                            '❌ Failed: ' + response.data.message +
                            '</span>'
                        );
                    }
                },
                error: function() {
                    $indicator.html(
                        '<span style="color: #dc3232;">❌ Request failed</span>'
                    );
                },
                complete: function() {
                    $button.prop('disabled', false).html(
                        '<span class="dashicons dashicons-admin-plugins" style="vertical-align: middle; margin-top: 3px;"></span> Test Connection'
                    );
                }
            });
        });
        
        // Auto-clear status indicators when user changes values
        $('input[name="openai_api_key"], select[name="openai_model"]').on('change', function() {
            $('#openai-status-indicator').html('');
        });
        
        $('input[name="claude_api_key"], select[name="claude_model"]').on('change', function() {
            $('#claude-status-indicator').html('');
        });
        
        $('input[name="dataforseo_login"], input[name="dataforseo_password"]').on('change', function() {
            $('#dataforseo-status-indicator').html('');
        });
        
        // Console log for debugging
        console.log('MindfulSEO Admin JS loaded');
    });
    
    /**
     * Move all WordPress admin notices to before the branding header
     */
    function moveNoticesToTop() {
        // Find all notices - both those inside wrap divs AND those rendered by WordPress before the wrap
        var $allNotices = $('#wpbody-content > .notice, #wpbody-content > .updated, #wpbody-content > .error, #wpbody-content > .fs-notice, ' +
                          '.wrap.mindfulseo-dashboard .notice, .wrap.mindfulseo-settings-wrap .notice, .wrap.mindfulseo-keywords-wrap .notice, .wrap.mindfulseo-guidelines-wrap .notice, .wrap.mindfulseo-page .notice, .wrap.mindfulseo-admin-wrap .notice, ' +
                          '.wrap.mindfulseo-dashboard .updated, .wrap.mindfulseo-settings-wrap .updated, .wrap.mindfulseo-keywords-wrap .updated, .wrap.mindfulseo-guidelines-wrap .updated, .wrap.mindfulseo-page .updated, .wrap.mindfulseo-admin-wrap .updated, ' +
                          '.wrap.mindfulseo-dashboard .error, .wrap.mindfulseo-settings-wrap .error, .wrap.mindfulseo-keywords-wrap .error, .wrap.mindfulseo-guidelines-wrap .error, .wrap.mindfulseo-page .error, .wrap.mindfulseo-admin-wrap .error')
                          .not('.inline, .notice-positioned');
        
        if ($allNotices.length) {
            // Find the branding header
            var $header = $('.mindfulseo-branding-header');
            
            if ($header.length) {
                // Move notices before the branding header (outside and above the white background)
                $allNotices.each(function() {
                    var $notice = $(this);
                    $notice.insertBefore($header);
                    $notice.addClass('notice-positioned');
                });
            }
        }
    }
    
    // ===================
    // INLINE EDITING
    // ===================
    
    // Handle contenteditable fields (blur = save)
    $(document).on('blur', '.editable[contenteditable="true"]', function() {
        var $this = $(this);
        var id = $this.data('id');
        var field = $this.data('field');
        var originalValue = $this.data('original');
        var newValue = $this.text().trim();
        
        // No change
        if (newValue === originalValue) {
            return;
        }
        
        // Empty value
        if (newValue === '') {
            alert('Value cannot be empty');
            $this.text(originalValue);
            return;
        }
        
        // Show loading
        $this.css('opacity', '0.5');
        
        // Determine if keyword or guideline
        var action = $this.closest('.mindfulseo-keywords-wrap').length > 0 
            ? 'mindfulseo_update_keyword' 
            : 'mindfulseo_update_guideline';
        
        var data = {
            action: action,
            nonce: mindfulseoAdmin.nonces.inline_edit,
            keyword_id: id,
            guideline_id: id,
            field: field,
            value: newValue
        };
        
        $.post(mindfulseoAdmin.ajaxurl, data, function(response) {
            $this.css('opacity', '1');
            
            if (response.success) {
                // Update original value
                $this.data('original', newValue);
                // Flash green
                $this.css('background-color', '#d4edda');
                setTimeout(function() {
                    $this.css('background-color', '');
                }, 1000);
            } else {
                // Revert
                $this.text(originalValue);
                alert('Update failed: ' + (response.data ? response.data.message : 'Unknown error'));
            }
        }).fail(function() {
            $this.css('opacity', '1');
            $this.text(originalValue);
            alert('Request failed');
        });
    });
    
    // Handle select dropdowns
    $(document).on('change', '.editable-select', function() {
        var $this = $(this);
        var id = $this.data('id');
        var field = $this.data('field');
        var newValue = $this.val();
        
        // Show loading
        $this.prop('disabled', true);
        
        // Determine if keyword or guideline based on parent container
        var $wrap = $this.closest('.wrap');
        var action = 'mindfulseo_update_keyword';
        
        if ($wrap.hasClass('mindfulseo-guidelines-wrap')) {
            action = 'mindfulseo_update_guideline';
        }
        
        var data = {
            action: action,
            nonce: mindfulseoAdmin.nonces.inline_edit,
            keyword_id: id,
            guideline_id: id,
            field: field,
            value: newValue
        };
        
        $.post(mindfulseoAdmin.ajaxurl, data, function(response) {
            $this.prop('disabled', false);
            
            if (response.success) {
                // Flash green
                $this.css('background-color', '#d4edda');
                setTimeout(function() {
                    $this.css('background-color', '');
                }, 1000);
            } else {
                alert('Update failed: ' + (response.data ? response.data.message : 'Unknown error'));
            }
        }).fail(function(jqXHR, textStatus, errorThrown) {
            $this.prop('disabled', false);
            console.error('AJAX error:', textStatus, errorThrown);
            alert('Request failed: ' + textStatus);
        });
    });
    
    // Enter key = save and blur
    $(document).on('keydown', '.editable[contenteditable="true"]', function(e) {
        if (e.which === 13) { // Enter
            e.preventDefault();
            $(this).blur();
        }
    });
    
    // ===================
    // ADD KEYWORD/GUIDELINE FORMS
    // ===================
    
    // Show add keyword form
    $('#mindfulseo-add-keyword-btn').on('click', function() {
        $('#mindfulseo-add-keyword-form').slideDown();
        $('#new_primary_keyword').focus();
    });
    
    // Hide add keyword form
    $('#mindfulseo-cancel-add-keyword').on('click', function() {
        $('#mindfulseo-add-keyword-form').slideUp();
        $('#mindfulseo-add-keyword-form form')[0].reset();
    });
    
    // Show add guideline form
    $('#mindfulseo-add-guideline-btn').on('click', function() {
        $('#mindfulseo-add-guideline-form').slideDown();
        $('#new_avoid_term').focus();
    });
    
    // Hide add guideline form
    $('#mindfulseo-cancel-add-guideline').on('click', function() {
        $('#mindfulseo-add-guideline-form').slideUp();
        $('#mindfulseo-add-guideline-form form')[0].reset();
    });
    
    // ===================
    // AI CLEANUP FEATURE
    // ===================
    
    var cleanupSuggestions = [];
    
    // Show cleanup modal and start analysis
    $('#mindfulseo-cleanup-keywords-btn').on('click', function() {
        $('#mindfulseo-cleanup-modal').show();
        $('#mindfulseo-cleanup-backdrop').show();
        $('#cleanup-progress').show();
        $('#cleanup-results').hide();
        $('#cleanup-progress-bar').css('width', '0%');
        $('#cleanup-status').text('Starting analysis...');
        
        // Simulate progress
        var progress = 0;
        var progressInterval = setInterval(function() {
            progress += Math.random() * 15;
            if (progress > 90) progress = 90;
            $('#cleanup-progress-bar').css('width', progress + '%');
        }, 500);
        
        // Call AI with extended timeout
        $.ajax({
            url: mindfulseoAdmin.ajaxurl,
            type: 'POST',
            timeout: 300000, // 5 minutes timeout
            data: {
                action: 'mindfulseo_cleanup_keywords',
                nonce: mindfulseoAdmin.nonces.cleanup
            },
            success: function(response) {
                clearInterval(progressInterval);
                $('#cleanup-progress-bar').css('width', '100%');
                
                if (response.success) {
                    cleanupSuggestions = response.data;
                    displayCleanupResults(response.data);
                } else {
                    alert('AI Cleanup failed: ' + response.data.message);
                    closeCleanupModal();
                }
            },
            error: function(xhr, status, error) {
                clearInterval(progressInterval);
                console.error('AI Cleanup error:', status, error);
                alert('Request failed: ' + (status === 'timeout' ? 'AI analysis timed out. This can happen with large keyword lists. Please try again or reduce the number of keywords.' : 'Please try again.'));
                closeCleanupModal();
            }
        });
    });
    
    // Display cleanup results
    function displayCleanupResults(data) {
        $('#cleanup-progress').hide();
        $('#cleanup-results').show();
        
        var summary = data.issues_found + ' issues found in ' + data.total_keywords + ' keywords';
        $('#cleanup-summary').text(summary);
        
        var $changes = $('#cleanup-changes');
        $changes.empty();
        
        if (data.issues_found === 0) {
            $changes.html('<p style="color: #46b450;"><strong>✅ All keywords look good! No changes needed.</strong></p>');
            $('#cleanup-apply-btn').hide();
            $('#cleanup-regenerate-btn').hide();
        } else {
            // Add select all / deselect all button
            var $selectAllBtn = $('<button type="button" class="button" id="cleanup-toggle-all" style="margin-bottom: 15px;">Deselect All</button>');
            $changes.append($selectAllBtn);
            
            $selectAllBtn.on('click', function() {
                var $checkboxes = $('#cleanup-changes input[type="checkbox"]');
                var allChecked = $checkboxes.filter(':checked').length === $checkboxes.length;
                
                if (allChecked) {
                    $checkboxes.prop('checked', false);
                    $(this).text('Select All');
                } else {
                    $checkboxes.prop('checked', true);
                    $(this).text('Deselect All');
                }
            });
            
            data.suggestions.forEach(function(suggestion, index) {
                var $item = $('<div class="cleanup-suggestion-item" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 4px; background: #fff;">');
                
                // Store suggestion data for later retrieval
                var suggestionData = {
                    keyword_id: suggestion.id,
                    action: suggestion.action
                };
                
                // Add improved data for replace actions
                if (suggestion.action === 'replace' && suggestion.replacement) {
                    if (suggestion.replacement.primary) suggestionData.improved_primary = suggestion.replacement.primary;
                    if (suggestion.replacement.longtail) suggestionData.improved_longtail = suggestion.replacement.longtail;
                    if (suggestion.replacement.intent) suggestionData.improved_intent = suggestion.replacement.intent;
                    if (suggestion.replacement.priority) suggestionData.improved_priority = suggestion.replacement.priority;
                }
                
                // Add merge target for merge actions
                if (suggestion.action === 'merge' && suggestion.merge_with) {
                    suggestionData.merge_with = suggestion.merge_with;
                }
                
                $item.data('suggestion', suggestionData);
                
                // Checkbox to select/deselect this suggestion
                var $checkbox = $('<input type="checkbox" checked style="float: right; width: 20px; height: 20px; margin-left: 10px;" data-suggestion-index="' + index + '">');
                $item.append($checkbox);
                
                // Issue header
                $item.append('<div style="font-weight: bold; margin-bottom: 10px; color: #333;">' + suggestion.issue + '</div>');
                
                // Action badge
                var actionBadge = '';
                if (suggestion.action === 'replace') {
                    actionBadge = '<span style="background: #2271b1; color: #fff; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600;">REPLACE</span>';
                } else if (suggestion.action === 'merge') {
                    actionBadge = '<span style="background: #2271b1; color: #fff; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600;">MERGE</span>';
                } else {
                    actionBadge = '<span style="background: #d63638; color: #fff; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600;">DELETE</span>';
                }
                $item.append('<div style="margin-bottom: 15px;">' + actionBadge + '</div>');
                
                // Before/After for REPLACE only (not merge)
                var originalKeyword = null;
                if (data.keyword_list) {
                    originalKeyword = data.keyword_list.find(function(kw) {
                        return kw.id == suggestion.id;
                    });
                }
                
                if (suggestion.action === 'replace' && originalKeyword && suggestion.replacement) {
                    $item.append('<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 10px 0;">');
                    
                    // BEFORE column
                    var beforeDiv = $('<div style="background: #f6f7f7; padding: 12px; border-radius: 4px;">');
                    beforeDiv.append('<div style="font-weight: 600; color: #646970; margin-bottom: 8px; font-size: 11px; text-transform: uppercase;">❌ Before</div>');
                    beforeDiv.append('<div style="margin-bottom: 5px;"><strong>Primary:</strong> ' + originalKeyword.primary + '</div>');
                    beforeDiv.append('<div style="margin-bottom: 5px;"><strong>Longtail:</strong> ' + originalKeyword.longtail + '</div>');
                    beforeDiv.append('<div style="margin-bottom: 5px;"><strong>Intent:</strong> ' + originalKeyword.intent + '</div>');
                    beforeDiv.append('<div><strong>Priority:</strong> ' + originalKeyword.priority + '</div>');
                    $item.append(beforeDiv);
                    
                    // AFTER column
                    var afterDiv = $('<div style="background: #f0f6fc; padding: 12px; border-radius: 4px; border-left: 3px solid #2271b1;">');
                    afterDiv.append('<div style="font-weight: 600; color: #2271b1; margin-bottom: 8px; font-size: 11px; text-transform: uppercase;">✅ After</div>');
                    afterDiv.append('<div style="margin-bottom: 5px;"><strong>Primary:</strong> ' + (suggestion.replacement.primary || originalKeyword.primary) + '</div>');
                    afterDiv.append('<div style="margin-bottom: 5px;"><strong>Longtail:</strong> ' + (suggestion.replacement.longtail || originalKeyword.longtail) + '</div>');
                    afterDiv.append('<div style="margin-bottom: 5px;"><strong>Intent:</strong> ' + (suggestion.replacement.intent || originalKeyword.intent) + '</div>');
                    afterDiv.append('<div><strong>Priority:</strong> ' + (suggestion.replacement.priority || originalKeyword.priority) + '</div>');
                    
                    $item.append(afterDiv);
                    $item.append('</div>');
                }
                
                // Merge - show what will be deleted and what will be kept
                if (suggestion.action === 'merge' && suggestion.merge_with && originalKeyword) {
                    // Find the target keyword that this will be merged into
                    var targetKeyword = null;
                    if (data.keyword_list) {
                        targetKeyword = data.keyword_list.find(function(kw) {
                            return kw.id == suggestion.merge_with;
                        });
                    }
                    
                    $item.append('<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 10px 0;">');
                    
                    // LEFT: This keyword will be DELETED
                    var deleteDiv = $('<div style="background: #fef2f2; padding: 12px; border-radius: 4px; border-left: 3px solid #d63638;">');
                    deleteDiv.append('<div style="font-weight: 600; color: #d63638; margin-bottom: 8px; font-size: 11px; text-transform: uppercase;">🗑️ Will be deleted</div>');
                    deleteDiv.append('<div style="margin-bottom: 5px;"><strong>Primary:</strong> ' + originalKeyword.primary + '</div>');
                    deleteDiv.append('<div style="margin-bottom: 5px;"><strong>Longtail:</strong> ' + originalKeyword.longtail + '</div>');
                    deleteDiv.append('<div style="margin-bottom: 5px;"><strong>Intent:</strong> ' + originalKeyword.intent + '</div>');
                    deleteDiv.append('<div><strong>Priority:</strong> ' + originalKeyword.priority + '</div>');
                    $item.append(deleteDiv);
                    
                    // RIGHT: Target keyword will be KEPT
                    var keepDiv = $('<div style="background: #f0f6fc; padding: 12px; border-radius: 4px; border-left: 3px solid #2271b1;">');
                    keepDiv.append('<div style="font-weight: 600; color: #2271b1; margin-bottom: 8px; font-size: 11px; text-transform: uppercase;">✅ Will be kept (merged into)</div>');
                    
                    if (targetKeyword) {
                        keepDiv.append('<div style="margin-bottom: 5px;"><strong>Primary:</strong> ' + targetKeyword.primary + '</div>');
                        keepDiv.append('<div style="margin-bottom: 5px;"><strong>Longtail:</strong> ' + targetKeyword.longtail + '</div>');
                        keepDiv.append('<div style="margin-bottom: 5px;"><strong>Intent:</strong> ' + targetKeyword.intent + '</div>');
                        keepDiv.append('<div><strong>Priority:</strong> ' + targetKeyword.priority + '</div>');
                    } else {
                        keepDiv.append('<div>Keyword ID: ' + suggestion.merge_with + '</div>');
                    }
                    
                    $item.append(keepDiv);
                    $item.append('</div>');
                }
                
                // Reasoning
                if (suggestion.reasoning) {
                    $item.append('<div style="margin-top: 12px; padding: 12px; background: #f6f7f7; border-radius: 4px; font-size: 13px; color: #646970;">');
                    $item.append('<strong>💡 Why:</strong> ' + suggestion.reasoning);
                    $item.append('</div>');
                }
                
                $changes.append($item);
            });
            $('#cleanup-apply-btn').show();
            $('#cleanup-regenerate-btn').show();
        }
    }
    
    // Close cleanup modal
    function closeCleanupModal() {
        $('#mindfulseo-cleanup-modal').hide();
        $('#mindfulseo-cleanup-backdrop').hide();
    }
    
    // Use event delegation for all cleanup modal buttons since they're dynamically shown/hidden
    $(document).on('click', '#cleanup-cancel-btn, #mindfulseo-cleanup-backdrop', closeCleanupModal);
    
    // Regenerate suggestions
    $(document).on('click', '#cleanup-regenerate-btn', function() {
        console.log('MindfulSEO: Regenerate button clicked');
        $('#mindfulseo-cleanup-keywords-btn').click();
    });
    
    // Apply changes - NO CONFIRMATION, just apply directly
    $(document).on('click', '#cleanup-apply-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        console.log('=== MindfulSEO: APPLY BUTTON CLICKED ===');
        
        // Check if button is disabled
        if ($(this).prop('disabled')) {
            console.log('MindfulSEO: Button is disabled, ignoring click');
            return;
        }
        
        // Collect all selected (checked) suggestions
        var selectedSuggestions = [];
        $('#cleanup-changes input[type="checkbox"]:checked').each(function() {
            var $checkbox = $(this);
            var $suggestionItem = $checkbox.closest('.cleanup-suggestion-item');
            var suggestionData = $suggestionItem.data('suggestion');
            
            if (suggestionData) {
                selectedSuggestions.push(suggestionData);
            }
        });
        
        console.log('MindfulSEO: Found ' + selectedSuggestions.length + ' selected suggestions');
        
        if (selectedSuggestions.length === 0) {
            alert('Please select at least one suggestion to apply.');
            return;
        }
        
        // REMOVED CONFIRMATION - Apply directly
        var $button = $(this);
        $button.prop('disabled', true).text('Applying...');
        
        console.log('MindfulSEO: Sending AJAX request to apply cleanup changes');
        
        $.ajax({
            url: mindfulseoAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'mindfulseo_apply_cleanup',
                nonce: mindfulseoAdmin.nonces.cleanup,
                suggestions: JSON.stringify(selectedSuggestions)
            },
            success: function(response) {
                console.log('MindfulSEO: Apply cleanup response:', response);
                if (response.success) {
                    // Close the modal first
                    closeCleanupModal();
                    // Show success message
                    alert('✅ ' + response.data.message);
                    // Reload page to show updated keywords
                    location.reload();
                } else {
                    alert('Error: ' + (response.data || 'Unknown error'));
                    $button.prop('disabled', false).text('Apply Selected Changes');
                }
            },
            error: function(xhr, status, error) {
                console.error('Apply Cleanup Error:', error);
                alert('Request failed. Please try again.');
                $button.prop('disabled', false).text('Apply Selected Changes');
            }
        });
    });
    
    // ===================
    // DELETE ALL KEYWORDS
    // ===================
    
    console.log('MindfulSEO: Attaching Delete All Keywords handler');
    
    var deleteConfirmed = false; // Flag to prevent dialog loop
    
    $('#delete-all-keywords-form').on('submit', function(e) {
        console.log('MindfulSEO: Delete All Keywords form submit triggered, confirmed=' + deleteConfirmed);
        
        // If already confirmed, allow the form to submit
        if (deleteConfirmed) {
            console.log('MindfulSEO: Allowing form submission');
            return true;
        }
        
        // Otherwise, prevent and show confirmation
        e.preventDefault();
        
        var $form = $(this);
        
        // Create custom confirmation dialog (in case browser confirm is blocked/hidden)
        if ($('#mindfulseo-delete-confirm-dialog').length === 0) {
            var $dialog = $('<div id="mindfulseo-delete-confirm-dialog" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.3); z-index: 999999; max-width: 500px;">' +
                '<h2 style="margin: 0 0 15px 0; color: #dc3232; font-size: 20px;">⚠️ Delete All Keywords?</h2>' +
                '<p style="margin: 0 0 20px 0; line-height: 1.6;">This will permanently delete <strong>all keywords</strong> from your strategy.<br><br>This action <strong>cannot be undone!</strong></p>' +
                '<div style="display: flex; gap: 10px; justify-content: flex-end;">' +
                '<button id="mindfulseo-delete-cancel" class="button" style="padding: 8px 20px;">Cancel</button>' +
                '<button id="mindfulseo-delete-confirm" class="button" style="background: #dc3232; color: #fff; border-color: #c62828; padding: 8px 20px;">Delete All Keywords</button>' +
                '</div>' +
                '</div>');
            
            var $overlay = $('<div id="mindfulseo-delete-overlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999998;"></div>');
            
            $('body').append($overlay, $dialog);
            
            $('#mindfulseo-delete-confirm').on('click', function() {
                console.log('MindfulSEO: Delete confirmed, setting flag and submitting form');
                $('#mindfulseo-delete-confirm-dialog, #mindfulseo-delete-overlay').remove();
                // Set flag and trigger submit
                deleteConfirmed = true;
                $form.submit();
            });
            
            $('#mindfulseo-delete-cancel, #mindfulseo-delete-overlay').on('click', function() {
                console.log('MindfulSEO: Delete cancelled');
                $('#mindfulseo-delete-confirm-dialog, #mindfulseo-delete-overlay').remove();
            });
        }
        
        return false;
    });
    
    console.log('MindfulSEO: Delete All Keywords handler attached');
    
    // ===================
    // DELETE ALL GUIDELINES
    // ===================
    
    console.log('MindfulSEO: Attaching Delete All Guidelines handler');
    
    var guidelinesDeleteConfirmed = false;
    
    $('#delete-all-guidelines-form').on('submit', function(e) {
        console.log('MindfulSEO: Delete All Guidelines form submit triggered, confirmed=' + guidelinesDeleteConfirmed);
        
        if (guidelinesDeleteConfirmed) {
            console.log('MindfulSEO: Allowing form submission');
            return true;
        }
        
        e.preventDefault();
        
        var $form = $(this);
        
        if ($('#mindfulseo-guidelines-delete-confirm-dialog').length === 0) {
            var $dialog = $('<div id="mindfulseo-guidelines-delete-confirm-dialog" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.3); z-index: 999999; max-width: 500px;">' +
                '<h2 style="margin: 0 0 15px 0; color: #dc3232; font-size: 20px;">⚠️ Delete All Guidelines?</h2>' +
                '<p style="margin: 0 0 20px 0; line-height: 1.6;">This will permanently delete <strong>all guidelines</strong> from your language rules.<br><br>This action <strong>cannot be undone!</strong></p>' +
                '<div style="display: flex; gap: 10px; justify-content: flex-end;">' +
                '<button id="mindfulseo-guidelines-delete-cancel" class="button" style="padding: 8px 20px;">Cancel</button>' +
                '<button id="mindfulseo-guidelines-delete-confirm" class="button" style="background: #dc3232; color: #fff; border-color: #c62828; padding: 8px 20px;">Delete All Guidelines</button>' +
                '</div>' +
                '</div>');
            
            var $overlay = $('<div id="mindfulseo-guidelines-delete-overlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999998;"></div>');
            
            $('body').append($overlay, $dialog);
            
            $('#mindfulseo-guidelines-delete-confirm').on('click', function() {
                console.log('MindfulSEO: Guidelines delete confirmed');
                $('#mindfulseo-guidelines-delete-confirm-dialog, #mindfulseo-guidelines-delete-overlay').remove();
                guidelinesDeleteConfirmed = true;
                $form.submit();
            });
            
            $('#mindfulseo-guidelines-delete-cancel, #mindfulseo-guidelines-delete-overlay').on('click', function() {
                console.log('MindfulSEO: Guidelines delete cancelled');
                $('#mindfulseo-guidelines-delete-confirm-dialog, #mindfulseo-guidelines-delete-overlay').remove();
            });
        }
        
        return false;
    });
    
    console.log('MindfulSEO: Delete All Guidelines handler attached');
    
    // ===================
    // REFRESH SEO DATA
    // ===================
    
    console.log('MindfulSEO: Attaching Refresh SEO Data handler');
    
    $('#mindfulseo-refresh-seo-data-btn').on('click', function() {
        var $button = $(this);
        
        // Create custom confirmation dialog
        if ($('#mindfulseo-refresh-confirm-dialog').length === 0) {
            var $dialog = $('<div id="mindfulseo-refresh-confirm-dialog" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.3); z-index: 999999; max-width: 500px;">' +
                '<h2 style="margin: 0 0 15px 0; color: #2271b1; font-size: 20px;">🔄 Refresh SEO Data?</h2>' +
                '<p style="margin: 0 0 20px 0; line-height: 1.6;">This will fetch fresh <strong>search volume</strong>, <strong>difficulty</strong>, and <strong>CPC data</strong> from DataForSEO for all keywords.<br><br>This may take a few moments and will <strong>consume API credits</strong>.</p>' +
                '<div style="display: flex; gap: 10px; justify-content: flex-end;">' +
                '<button id="mindfulseo-refresh-cancel" class="button" style="padding: 8px 20px;">Cancel</button>' +
                '<button id="mindfulseo-refresh-confirm" class="button button-primary" style="padding: 8px 20px;">Refresh Data</button>' +
                '</div>' +
                '</div>');
            
            var $overlay = $('<div id="mindfulseo-refresh-overlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999998;"></div>');
            
            $('body').append($overlay, $dialog);
            
            $('#mindfulseo-refresh-confirm').on('click', function() {
                console.log('MindfulSEO: Refresh confirmed');
                $('#mindfulseo-refresh-confirm-dialog, #mindfulseo-refresh-overlay').remove();
                
                // Disable button and show loading state
                $button.prop('disabled', true)
                       .html('<span class="dashicons dashicons-update dashicons-spin" style="vertical-align: middle;"></span> Refreshing...');
                
                $.ajax({
                    url: mindfulseoAdmin.ajaxurl,
                    type: 'POST',
                    timeout: 300000, // 5 minutes timeout
                    data: {
                        action: 'mindfulseo_refresh_seo_data',
                        nonce: mindfulseoAdmin.nonces.refresh_seo_data
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            // Reload the page to show updated data
                            location.reload();
                        } else {
                            alert('Error: ' + response.data.message);
                            $button.prop('disabled', false)
                                   .html('<span class="dashicons dashicons-update" style="vertical-align: middle;"></span> Refresh SEO Data');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Refresh SEO Data error:', status, error);
                        alert('Request failed: ' + (status === 'timeout' ? 'Request timed out. Please try again.' : 'Please try again.'));
                        $button.prop('disabled', false)
                               .html('<span class="dashicons dashicons-update" style="vertical-align: middle;"></span> Refresh SEO Data');
                    }
                });
            });
            
            $('#mindfulseo-refresh-cancel, #mindfulseo-refresh-overlay').on('click', function() {
                console.log('MindfulSEO: Refresh cancelled');
                $('#mindfulseo-refresh-confirm-dialog, #mindfulseo-refresh-overlay').remove();
            });
        }
    });
    
    // ===================
    // ANALYZE SITE RANKINGS (NEW - Task 2)
    // ===================
    
    console.log('MindfulSEO: Attaching Analyze Site Rankings handler');
    
    $('#mindfulseo-analyze-rankings-btn').on('click', function() {
        var $button = $(this);
        
        // Create custom confirmation dialog
        if ($('#mindfulseo-analyze-confirm-dialog').length === 0) {
            var $dialog = $('<div id="mindfulseo-analyze-confirm-dialog" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.3); z-index: 999999; max-width: 500px;">' +
                '<h2 style="margin: 0 0 15px 0; color: #2271b1; font-size: 20px;">📊 Analyze Site Rankings?</h2>' +
                '<p style="margin: 0 0 20px 0; line-height: 1.6;">This will analyze what keywords your site currently ranks for in Google and update the <strong>Current Rank</strong> data.<br><br>This may take a few moments and will <strong>consume API credits</strong>.</p>' +
                '<div style="display: flex; gap: 10px; justify-content: flex-end;">' +
                '<button id="mindfulseo-analyze-cancel" class="button" style="padding: 8px 20px;">Cancel</button>' +
                '<button id="mindfulseo-analyze-confirm" class="button button-primary" style="padding: 8px 20px;">Analyze Now</button>' +
                '</div>' +
                '</div>');
            
            var $overlay = $('<div id="mindfulseo-analyze-overlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999998;"></div>');
            
            $('body').append($overlay, $dialog);
            
            $('#mindfulseo-analyze-confirm').on('click', function() {
                console.log('MindfulSEO: Analyze Site Rankings confirmed');
                $('#mindfulseo-analyze-confirm-dialog, #mindfulseo-analyze-overlay').remove();
                
                // Disable button and show loading state
                $button.prop('disabled', true)
                       .html('<span class="dashicons dashicons-update dashicons-spin" style="vertical-align: middle;"></span> Analyzing...');
                
                $.ajax({
                    url: mindfulseoAdmin.ajaxurl,
                    type: 'POST',
                    timeout: 300000, // 5 minutes timeout
                    data: {
                        action: 'mindfulseo_analyze_site_rankings',
                        nonce: mindfulseoAdmin.nonces.ajax_nonce
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
    
    // ===================
    // TABLE SORTING
    // ===================
    
    $('.sortable').on('click', function() {
        var $th = $(this);
        var $table = $th.closest('table');
        var $tbody = $table.find('tbody');
        var sortBy = $th.data('sort');
        var isAscending = !$th.hasClass('sorted-asc');
        
        // Determine if this is a numeric column
        var isNumericColumn = ['search_volume', 'keyword_difficulty', 'cpc', 'volume', 'difficulty'].indexOf(sortBy) !== -1;
        
        // Remove sort indicators from all headers
        $table.find('.sortable').removeClass('sorted-asc sorted-desc');
        $table.find('.sortable .dashicons').removeClass('dashicons-arrow-up dashicons-arrow-down').addClass('dashicons-sort');
        
        // Add sort indicator to clicked header
        $th.addClass(isAscending ? 'sorted-asc' : 'sorted-desc');
        $th.find('.dashicons').removeClass('dashicons-sort').addClass(isAscending ? 'dashicons-arrow-up' : 'dashicons-arrow-down');
        
        // Get all rows
        var $rows = $tbody.find('tr').get();
        
        // Sort rows
        $rows.sort(function(a, b) {
            var $cellA = $(a).find('td').eq($th.index());
            var $cellB = $(b).find('td').eq($th.index());
            
            var valA, valB;
            
            // Check if cells have data-sort-value attribute (for Keywords Strategy table)
            if ($cellA.attr('data-sort-value') !== undefined && $cellB.attr('data-sort-value') !== undefined) {
                valA = $cellA.attr('data-sort-value');
                valB = $cellB.attr('data-sort-value');
            }
            // Handle different cell types
            else if ($cellA.find('select').length) {
                // Dropdown select
                valA = $cellA.find('select').val();
                valB = $cellB.find('select').val();
            } else if ($cellA.find('.editable').length) {
                // Editable span
                valA = $cellA.find('.editable').text();
                valB = $cellB.find('.editable').text();
            } else {
                // Plain text
                valA = $cellA.text();
                valB = $cellB.text();
            }
            
            // Trim values
            valA = valA.trim();
            valB = valB.trim();
            
            // Handle numeric sorting
            if (isNumericColumn) {
                // Convert "—" and empty strings to -1 for numeric columns (so they sort to bottom)
                var numA = (valA === '—' || valA === '' || valA === '-') ? -1 : parseFloat(valA.replace(/[^0-9.-]/g, ''));
                var numB = (valB === '—' || valB === '' || valB === '-') ? -1 : parseFloat(valB.replace(/[^0-9.-]/g, ''));
                
                // Handle NaN values
                numA = isNaN(numA) ? -1 : numA;
                numB = isNaN(numB) ? -1 : numB;
                
                if (numA < numB) return isAscending ? -1 : 1;
                if (numA > numB) return isAscending ? 1 : -1;
                return 0;
            }
            
            // Text sorting (case-insensitive)
            valA = valA.toLowerCase();
            valB = valB.toLowerCase();
            
            if (valA < valB) return isAscending ? -1 : 1;
            if (valA > valB) return isAscending ? 1 : -1;
            return 0;
        });
        
        // Reorder rows in DOM
        $.each($rows, function(index, row) {
            $tbody.append(row);
        });
    });
    
    // ================================================
    // Custom Prompts for Keyword Strategy & Language Guidelines
    // ================================================
    
    // Save custom prompt
    $('.save-prompt-btn').on('click', function() {
        const $button = $(this);
        const promptType = $button.data('prompt-type');
        let promptValue = '';
        
        if (promptType === 'keyword_generation') {
            promptValue = $('#keyword-generation-prompt').val();
        } else if (promptType === 'guideline_generation') {
            promptValue = $('#guideline-generation-prompt').val();
        }
        
        const $status = $button.siblings('.prompt-save-status');
        
        $button.prop('disabled', true).html('<span class="dashicons dashicons-update dashicons-spin"></span> Saving...');
        
        $.ajax({
            url: mindfulseo.ajaxurl,
            type: 'POST',
            data: {
                action: 'mindfulseo_save_custom_prompt',
                nonce: mindfulseo.nonce,
                prompt_type: promptType,
                prompt_value: promptValue
            },
            success: function(response) {
                if (response.success) {
                    $status.fadeIn().delay(3000).fadeOut();
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> Save Custom Instructions');
                } else {
                    alert('Error saving prompt: ' + response.data.message);
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> Save Custom Instructions');
                }
            },
            error: function() {
                alert('Error saving prompt. Please try again.');
                $button.prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> Save Custom Instructions');
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
        let $textarea;
        
        if (promptType === 'keyword_generation') {
            $textarea = $('#keyword-generation-prompt');
        } else if (promptType === 'guideline_generation') {
            $textarea = $('#guideline-generation-prompt');
        }
        
        $button.prop('disabled', true).html('<span class="dashicons dashicons-update dashicons-spin"></span> Resetting...');
        
        $.ajax({
            url: mindfulseo.ajaxurl,
            type: 'POST',
            data: {
                action: 'mindfulseo_save_custom_prompt',
                nonce: mindfulseo.nonce,
                prompt_type: promptType,
                prompt_value: ''
            },
            success: function(response) {
                if (response.success) {
                    $textarea.val('');
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
    
    console.log('MindfulSEO document.ready - END');
    
})(jQuery);

console.log('MindfulSEO admin.js loaded - END');
