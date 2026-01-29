jQuery(document).ready(function($) {
    
    // Optimize button click
    $('#mindfulseo-optimize-btn, #mindfulseo-regenerate-btn').on('click', function() {
        var $button = $(this);
        var $metaBox = $('#mindfulseo-meta-box');
        var $status = $metaBox.find('.mindfulseo-status');
        var $loading = $('#mindfulseo-loading');
        
        // Show loading state
        $status.hide();
        $loading.show();
        
        $.ajax({
            url: mindfulseoMetaBox.ajaxurl,
            type: 'POST',
            data: {
                action: 'mindfulseo_optimize_post',
                nonce: mindfulseoMetaBox.nonce,
                post_id: mindfulseoMetaBox.postId
            },
            success: function(response) {
                if (response.success) {
                    // Hide loading, show success state, and show preview modal
                    $loading.hide();
                    $status.show();
                    showPreviewModal(response.data);
                    // Don't reload here - let user review and apply/reject first
                } else {
                    alert('Error: ' + response.data);
                    $loading.hide();
                    $status.show();
                }
            },
            error: function() {
                alert('Request failed. Please try again.');
                $loading.hide();
                $status.show();
            }
        });
    });
    
    // Preview button click
    $('#mindfulseo-preview-btn').on('click', function() {
        // Get optimization data and show preview
        $.ajax({
            url: mindfulseoMetaBox.ajaxurl,
            type: 'POST',
            data: {
                action: 'mindfulseo_get_preview',
                nonce: mindfulseoMetaBox.nonce,
                post_id: mindfulseoMetaBox.postId
            },
            success: function(response) {
                if (response.success) {
                    showPreviewModal(response.data);
                } else {
                    alert('Error: ' + response.data);
                }
            }
        });
    });
    
    // Show preview modal
    function showPreviewModal(data) {
        console.log('Preview data:', data); // Debug
        
        var html = '';
        
        // Remove fake SEO score - it's misleading!
        // Keyword comparison
        html += '<div class="mindfulseo-comparison">';
        html += '<div class="mindfulseo-before">';
        html += '<h4>❌ Current Keyword</h4>';
        html += '<p>' + (data.current_keyword || '<em>No keyword set</em>') + '</p>';
        html += '</div>';
        html += '<div class="mindfulseo-after">';
        html += '<h4>✅ Suggested Keyword</h4>';
        if (data.suggested_keyword && data.suggested_keyword.trim() !== '') {
            html += '<p><strong>' + data.suggested_keyword + '</strong></p>';
        } else {
            html += '<p><em>No keyword generated</em></p>';
        }
        html += '</div>';
        html += '</div>';
        
        // Title comparison
        html += '<div class="mindfulseo-comparison">';
        html += '<div class="mindfulseo-before">';
        html += '<h4>❌ Current Title</h4>';
        html += '<p>' + (data.current_title || '<em>No title set</em>') + '</p>';
        html += '</div>';
        html += '<div class="mindfulseo-after">';
        html += '<h4>✅ Suggested Title</h4>';
        html += '<p><strong>' + data.suggested_title + '</strong></p>';
        html += '<p style="font-size: 11px; color: #646970; margin-top: 5px;">' + data.suggested_title.length + ' characters</p>';
        html += '</div>';
        html += '</div>';
        
        // Description comparison
        html += '<div class="mindfulseo-comparison">';
        html += '<div class="mindfulseo-before">';
        html += '<h4>❌ Current Description</h4>';
        html += '<p>' + (data.current_description || '<em>No description set</em>') + '</p>';
        html += '</div>';
        html += '<div class="mindfulseo-after">';
        html += '<h4>✅ Suggested Description</h4>';
        html += '<p><strong>' + data.suggested_description + '</strong></p>';
        html += '<p style="font-size: 11px; color: #646970; margin-top: 5px;">' + data.suggested_description.length + ' characters</p>';
        html += '</div>';
        html += '</div>';
        
        // URL/Slug comparison
        html += '<div class="mindfulseo-comparison">';
        html += '<div class="mindfulseo-before">';
        html += '<h4>❌ Current URL</h4>';
        html += '<p style="word-break: break-all;">' + (data.current_slug || '<em>No slug set</em>') + '</p>';
        html += '</div>';
        html += '<div class="mindfulseo-after">';
        html += '<h4>✅ Optimized URL</h4>';
        if (data.suggested_slug && data.suggested_slug.trim() !== '') {
            html += '<p style="word-break: break-all;"><strong>' + data.suggested_slug + '</strong></p>';
            html += '<p style="font-size: 11px; color: #646970; margin-top: 5px;">Based on primary keyword</p>';
        } else {
            html += '<p><em>No slug generated</em></p>';
        }
        html += '</div>';
        html += '</div>';
        
        // Content suggestions
        if (data.suggestions && data.suggestions.length > 0) {
            html += '<div class="mindfulseo-suggestions">';
            html += '<h4>💡 Content Improvement Suggestions</h4>';
            html += '<ul>';
            data.suggestions.forEach(function(suggestion) {
                html += '<li>' + suggestion + '</li>';
            });
            html += '</ul>';
            html += '</div>';
        }
        
        // Store optimization ID for apply/reject
        $('#mindfulseo-preview-modal').data('optimization-id', data.optimization_id);
        
        // Show modal
        $('#mindfulseo-preview-content').html(html);
        $('#mindfulseo-preview-modal').show();
    }
    
    // Close modal
    $('.mindfulseo-modal-close, #mindfulseo-cancel-btn, .mindfulseo-modal-overlay').on('click', function() {
        $('#mindfulseo-preview-modal').hide();
    });
    
    // Apply optimization
    $('#mindfulseo-apply-btn').on('click', function() {
        var optimizationId = $('#mindfulseo-preview-modal').data('optimization-id');
        
        if (!confirm('Apply these SEO optimizations to your post?')) {
            return;
        }
        
        var $button = $(this);
        $button.prop('disabled', true).text('Applying...');
        
        $.ajax({
            url: mindfulseoMetaBox.ajaxurl,
            type: 'POST',
            data: {
                action: 'mindfulseo_apply_optimization',
                nonce: mindfulseoMetaBox.nonce,
                optimization_id: optimizationId
            },
            success: function(response) {
                if (response.success) {
                    $('#mindfulseo-preview-modal').hide();
                    alert(response.data.message);
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                    $button.prop('disabled', false).text('Apply Changes');
                }
            },
            error: function() {
                alert('Request failed. Please try again.');
                $button.prop('disabled', false).text('Apply Changes');
            }
        });
    });
    
    // Reject optimization
    $('#mindfulseo-reject-btn').on('click', function() {
        var optimizationId = $('#mindfulseo-preview-modal').data('optimization-id');
        
        if (!confirm('Reject this optimization? You can regenerate it later.')) {
            return;
        }
        
        var $button = $(this);
        $button.prop('disabled', true).text('Rejecting...');
        
        $.ajax({
            url: mindfulseoMetaBox.ajaxurl,
            type: 'POST',
            data: {
                action: 'mindfulseo_reject_optimization',
                nonce: mindfulseoMetaBox.nonce,
                optimization_id: optimizationId
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                    $button.prop('disabled', false).text('Reject');
                }
            },
            error: function() {
                alert('Request failed. Please try again.');
                $button.prop('disabled', false).text('Reject');
            }
        });
    });
});

