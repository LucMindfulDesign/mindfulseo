<?php
/**
 * SEO Audit Page - Redesigned for Better UX
 * 
 * Clear workflow: Discover → Select → Fix
 *
 * @package MindfulSEO
 * @since 1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_SEO_Audit_Page {
    
    /**
     * Render the SEO audit page
     */
    public static function render() {
        // Check user permissions
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'mindfulseo'));
        }
        
        // Get audit data
        $audit = self::run_audit();
        
        // Get branding header
        $icon_url = MINDFULSEO_PLUGIN_URL . 'assets/icon-gold.svg?v=' . MINDFULSEO_VERSION;
        $logo_url = MINDFULSEO_PLUGIN_URL . 'assets/logo-white.svg?v=' . MINDFULSEO_VERSION;
        $version = MINDFULSEO_VERSION;
        
        ?>
        <!-- Branding Header -->
        <div class="mindfulseo-branding-header">
            <div class="mindfulseo-brand-content">
                <img src="<?php echo esc_url($icon_url); ?>" 
                     alt="Mindful Design Icon" 
                     style="height: 40px; width: auto;"
                     onerror="this.style.display='none';">
                <div class="mindfulseo-brand-text">
                    <h1><?php _e('SEO Audit Dashboard', 'mindfulseo'); ?></h1>
                    <p class="mindfulseo-brand-tagline">
                        v<?php echo esc_html($version); ?> • By <a href="https://mindfuldesign.me" target="_blank">Mindful Design</a>
                    </p>
                </div>
            </div>
            <div class="mindfulseo-brand-logo">
                <img src="<?php echo esc_url($logo_url); ?>" 
                     alt="Mindful Design Logo" 
                     style="height: 32px; width: auto;"
                     onerror="this.style.display='none';">
            </div>
        </div>
        
        <div class="wrap mindfulseo-page">
            <div class="mindfulseo-content">
                
                <!-- STEP 1: Overview -->
                <div class="mindfulseo-workflow-step">
                    <div class="mindfulseo-step-header">
                        <span class="mindfulseo-step-number">1</span>
                        <h2><?php _e('📊 Your SEO Health Overview', 'mindfulseo'); ?></h2>
                    </div>
                    
                    <div class="mindfulseo-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px;">
                        
                        <div class="mindfulseo-stat-card <?php echo $audit['total_issues'] == 0 ? 'stat-success' : 'stat-warning'; ?>">
                            <div class="mindfulseo-stat-icon">
                                <svg viewBox="0 0 24 24" fill="currentColor" style="width: 32px; height: 32px;">
                                    <path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/>
                                </svg>
                            </div>
                            <div class="mindfulseo-stat-content">
                                <h3 style="margin: 0; font-size: 32px; font-weight: 600; line-height: 1.2;"><?php echo esc_html($audit['total_issues']); ?></h3>
                                <p style="margin: 5px 0 0; font-size: 13px; color: #646970;"><?php _e('Total Issues Found', 'mindfulseo'); ?></p>
                            </div>
                        </div>
                        
                        <div class="mindfulseo-stat-card">
                            <div class="mindfulseo-stat-icon">
                                <svg viewBox="0 0 24 24" fill="currentColor" style="width: 32px; height: 32px;">
                                    <path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/>
                                </svg>
                            </div>
                            <div class="mindfulseo-stat-content">
                                <h3 style="margin: 0; font-size: 32px; font-weight: 600; line-height: 1.2;"><?php echo esc_html($audit['total_posts']); ?></h3>
                                <p style="margin: 5px 0 0; font-size: 13px; color: #646970;"><?php _e('Published Posts', 'mindfulseo'); ?></p>
                            </div>
                        </div>
                        
                        <div class="mindfulseo-stat-card <?php echo $audit['optimization_score'] >= 80 ? 'stat-success' : ($audit['optimization_score'] >= 50 ? 'stat-warning' : 'stat-error'); ?>">
                            <div class="mindfulseo-stat-icon">
                                <svg viewBox="0 0 24 24" fill="currentColor" style="width: 32px; height: 32px;">
                                    <path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/>
                                </svg>
                            </div>
                            <div class="mindfulseo-stat-content">
                                <h3 style="margin: 0; font-size: 32px; font-weight: 600; line-height: 1.2;"><?php echo esc_html($audit['optimization_score']); ?>%</h3>
                                <p style="margin: 5px 0 0; font-size: 13px; color: #646970;">
                                    <?php 
                                    if ($audit['optimization_score'] >= 80) {
                                        _e('Excellent!', 'mindfulseo');
                                    } elseif ($audit['optimization_score'] >= 50) {
                                        _e('Needs Work', 'mindfulseo');
                                    } else {
                                        _e('Urgent', 'mindfulseo');
                                    }
                                    ?>
                                </p>
                            </div>
                        </div>
                        
                        <div class="mindfulseo-stat-card">
                            <div class="mindfulseo-stat-icon">
                                <svg viewBox="0 0 24 24" fill="currentColor" style="width: 32px; height: 32px;">
                                    <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                                </svg>
                            </div>
                            <div class="mindfulseo-stat-content">
                                <h3 style="margin: 0; font-size: 32px; font-weight: 600; line-height: 1.2;"><?php echo esc_html($audit['posts_optimized']); ?></h3>
                                <p style="margin: 5px 0 0; font-size: 13px; color: #646970;"><?php printf(__('of %d Optimized', 'mindfulseo'), $audit['total_posts']); ?></p>
                            </div>
                        </div>
                        
                    </div>
                    
                    <?php if ($audit['total_issues'] > 0): ?>
                    <div class="notice notice-info inline" style="margin: 0;">
                        <p>
                            <strong><?php _e('What does this mean?', 'mindfulseo'); ?></strong>
                            <?php 
                            printf(
                                __('You have %1$s posts that need SEO attention. Click on any issue below to see which posts are affected, then use "Fix with AI" to automatically optimize them.', 'mindfulseo'),
                                '<strong>' . $audit['total_issues'] . '</strong>'
                            );
                            ?>
                        </p>
                    </div>
                    <?php else: ?>
                    <div class="notice notice-success inline" style="margin: 0;">
                        <p>
                            <strong><?php _e('🎉 Great job!', 'mindfulseo'); ?></strong>
                            <?php _e('All your posts have proper SEO metadata. Keep monitoring regularly to maintain this excellent status.', 'mindfulseo'); ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- STEP 2: Issues -->
                <?php if ($audit['total_issues'] > 0): ?>
                <div class="mindfulseo-workflow-step">
                    <div class="mindfulseo-step-header">
                        <span class="mindfulseo-step-number">2</span>
                        <h2><?php _e('🔍 Issues Found (Click to Expand)', 'mindfulseo'); ?></h2>
                    </div>
                    
                    <div class="mindfulseo-audit-issues" style="display: flex; flex-direction: column; gap: 15px;">
                        
                        <!-- Missing Meta Descriptions -->
                        <?php if ($audit['issues']['no_meta_description']['count'] > 0) : ?>
                        <?php self::render_issue_card(
                            'no_meta_description',
                            __('Missing Meta Descriptions', 'mindfulseo'),
                            sprintf(__('%d posts without meta descriptions', 'mindfulseo'), $audit['issues']['no_meta_description']['count']),
                            __('Meta descriptions help searchers understand your content. Without them, Google creates poor auto-generated descriptions.', 'mindfulseo'),
                            $audit['issues']['no_meta_description']['posts'],
                            'high'
                        ); ?>
                        <?php endif; ?>
                        
                        <!-- Missing Focus Keywords -->
                        <?php if ($audit['issues']['no_focus_keyword']['count'] > 0) : ?>
                        <?php self::render_issue_card(
                            'no_focus_keyword',
                            __('Missing Focus Keywords', 'mindfulseo'),
                            sprintf(__('%d posts without focus keywords', 'mindfulseo'), $audit['issues']['no_focus_keyword']['count']),
                            __('Focus keywords tell search engines what your content is about. Missing keywords = harder to rank.', 'mindfulseo'),
                            $audit['issues']['no_focus_keyword']['posts'],
                            'high'
                        ); ?>
                        <?php endif; ?>
                        
                        <!-- Low SEO Scores -->
                        <?php if ($audit['issues']['low_seo_score']['count'] > 0) : ?>
                        <?php self::render_issue_card(
                            'low_seo_score',
                            __('Low SEO Scores', 'mindfulseo'),
                            sprintf(__('%d posts with scores below 70', 'mindfulseo'), $audit['issues']['low_seo_score']['count']),
                            __('Posts with SEO scores below 70 need optimization. Target 80+ for better rankings.', 'mindfulseo'),
                            $audit['issues']['low_seo_score']['posts'],
                            'medium',
                            true // Show scores
                        ); ?>
                        <?php endif; ?>
                        
                        <!-- Long Titles -->
                        <?php if ($audit['issues']['title_too_long']['count'] > 0) : ?>
                        <?php self::render_issue_card(
                            'title_too_long',
                            __('Titles Too Long', 'mindfulseo'),
                            sprintf(__('%d posts with titles over 60 characters', 'mindfulseo'), $audit['issues']['title_too_long']['count']),
                            __('Long titles get truncated in Google search results. Keep under 60 characters for full visibility.', 'mindfulseo'),
                            $audit['issues']['title_too_long']['posts'],
                            'medium',
                            false,
                            true // Show lengths
                        ); ?>
                        <?php endif; ?>
                        
                        <!-- Thin Content -->
                        <?php if ($audit['issues']['thin_content']['count'] > 0) : ?>
                        <div class="mindfulseo-issue-card mindfulseo-issue-info" data-issue="thin_content">
                            <div class="mindfulseo-issue-header">
                                <div class="mindfulseo-issue-badge mindfulseo-badge-info">Info</div>
                                <div class="mindfulseo-issue-title">
                                    <h3><?php _e('Thin Content', 'mindfulseo'); ?></h3>
                                    <p><?php printf(__('%d posts with less than 300 words', 'mindfulseo'), $audit['issues']['thin_content']['count']); ?></p>
                                </div>
                                <button class="button mindfulseo-expand-btn">
                                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                                    <?php _e('View Posts', 'mindfulseo'); ?>
                                </button>
                            </div>
                            <div class="mindfulseo-issue-details" style="display: none;">
                                <div class="mindfulseo-issue-explanation">
                                    <p><?php _e('⚠️ These posts need manual content expansion. AI cannot add new content - you\'ll need to write more yourself. Aim for 500+ words for better SEO.', 'mindfulseo'); ?></p>
                                </div>
                                <table class="widefat striped">
                                    <thead>
                                        <tr>
                                            <th><?php _e('Post Title', 'mindfulseo'); ?></th>
                                            <th style="width: 120px;"><?php _e('Word Count', 'mindfulseo'); ?></th>
                                            <th style="width: 120px;"><?php _e('Actions', 'mindfulseo'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($audit['issues']['thin_content']['posts'] as $post_id) : 
                                            $post = get_post($post_id);
                                            $word_count = str_word_count(wp_strip_all_tags($post->post_content));
                                        ?>
                                        <tr>
                                            <td><strong><a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>" target="_blank"><?php echo esc_html($post->post_title); ?></a></strong></td>
                                            <td><span style="<?php echo $word_count < 200 ? 'color: #d63638; font-weight: 600;' : 'color: #996800;'; ?>"><?php echo esc_html($word_count); ?> words</span></td>
                                            <td><a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>" class="button button-small" target="_blank"><span class="dashicons dashicons-edit" style="font-size: 13px;"></span> <?php _e('Edit', 'mindfulseo'); ?></a></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- STEP 3: Actions -->
                <div class="mindfulseo-workflow-step">
                    <div class="mindfulseo-step-header">
                        <span class="mindfulseo-step-number">3</span>
                        <h2><?php _e('⚡ Quick Actions', 'mindfulseo'); ?></h2>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
                        <div class="mindfulseo-action-card">
                            <h3><?php _e('🔄 Refresh Audit', 'mindfulseo'); ?></h3>
                            <p><?php _e('Re-scan all posts to get the latest SEO status. Run this after making manual changes.', 'mindfulseo'); ?></p>
                            <button class="button button-secondary button-large" id="mindfulseo-refresh-audit" style="width: 100%;">
                                <span class="dashicons dashicons-update"></span>
                                <?php _e('Refresh Now', 'mindfulseo'); ?>
                            </button>
                        </div>
                        
                        <div class="mindfulseo-action-card">
                            <h3><?php _e('🎯 Batch Optimizer', 'mindfulseo'); ?></h3>
                            <p><?php _e('Manually select specific posts to optimize with AI. Good for targeted improvements.', 'mindfulseo'); ?></p>
                            <a href="<?php echo admin_url('admin.php?page=mindfulseo-batch-optimize'); ?>" class="button button-large" style="width: 100%;">
                                <span class="dashicons dashicons-admin-tools"></span>
                                <?php _e('Open Batch Optimizer', 'mindfulseo'); ?>
                            </a>
                        </div>
                        
                        <div class="mindfulseo-action-card">
                            <h3><?php _e('⚙️ Settings', 'mindfulseo'); ?></h3>
                            <p><?php _e('Configure API keys, default preferences, and optimization behavior.', 'mindfulseo'); ?></p>
                            <a href="<?php echo admin_url('admin.php?page=mindfulseo-settings'); ?>" class="button button-large" style="width: 100%;">
                                <span class="dashicons dashicons-admin-generic"></span>
                                <?php _e('Open Settings', 'mindfulseo'); ?>
                            </a>
                        </div>
                    </div>
                </div>
                
            </div>
        </div>
        
        <style>
        .mindfulseo-workflow-step {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 30px;
            margin-bottom: 30px;
        }
        .mindfulseo-step-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        .mindfulseo-step-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 50%;
            font-size: 20px;
            font-weight: 700;
            flex-shrink: 0;
        }
        .mindfulseo-step-header h2 {
            margin: 0;
            font-size: 22px;
            font-weight: 600;
        }
        
        .mindfulseo-issue-card {
            background: white;
            border: 2px solid #ddd;
            border-radius: 6px;
            overflow: hidden;
            transition: all 0.2s;
        }
        .mindfulseo-issue-card:hover {
            border-color: #999;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .mindfulseo-issue-card.mindfulseo-issue-high {
            border-left: 4px solid #d63638;
        }
        .mindfulseo-issue-card.mindfulseo-issue-medium {
            border-left: 4px solid #f0b429;
        }
        .mindfulseo-issue-card.mindfulseo-issue-info {
            border-left: 4px solid #72aee6;
        }
        
        .mindfulseo-issue-header {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 20px;
            background: #fafafa;
            cursor: pointer;
        }
        .mindfulseo-issue-badge {
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .mindfulseo-badge-high {
            background: #fee;
            color: #d63638;
        }
        .mindfulseo-badge-medium {
            background: #fff8dc;
            color: #996800;
        }
        .mindfulseo-badge-info {
            background: #e7f5fe;
            color: #2271b1;
        }
        
        .mindfulseo-issue-title {
            flex: 1;
        }
        .mindfulseo-issue-title h3 {
            margin: 0 0 5px;
            font-size: 16px;
            font-weight: 600;
        }
        .mindfulseo-issue-title p {
            margin: 0;
            font-size: 13px;
            color: #646970;
        }
        
        .mindfulseo-issue-details {
            padding: 0;
            background: #f9f9f9;
            border-top: 1px solid #ddd;
        }
        .mindfulseo-issue-explanation {
            padding: 20px;
            background: #fff8dc;
            border-bottom: 1px solid #ddd;
            margin: 0;
        }
        .mindfulseo-issue-explanation p {
            margin: 0;
            font-size: 14px;
        }
        .mindfulseo-issue-details table {
            margin: 0;
        }
        
        .mindfulseo-issue-actions {
            padding: 20px;
            background: white;
            border-top: 1px solid #ddd;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .mindfulseo-issue-actions-left {
            flex: 1;
        }
        
        .mindfulseo-stat-card.stat-error {
            border-color: #d63638;
            background: #fee;
        }
        .mindfulseo-stat-card.stat-error .mindfulseo-stat-icon {
            color: #d63638;
        }
        
        .mindfulseo-action-card {
            background: #f9f9f9;
            border: 2px solid #ddd;
            border-radius: 6px;
            padding: 20px;
        }
        .mindfulseo-action-card h3 {
            margin: 0 0 10px;
            font-size: 16px;
        }
        .mindfulseo-action-card p {
            margin: 0 0 15px;
            font-size: 13px;
            color: #646970;
        }
        
        .mindfulseo-issue-card.expanded .mindfulseo-expand-btn .dashicons {
            transform: rotate(180deg);
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Expand/collapse on header click
            $('.mindfulseo-issue-header').on('click', function() {
                const card = $(this).closest('.mindfulseo-issue-card');
                const details = card.find('.mindfulseo-issue-details');
                
                card.toggleClass('expanded');
                details.slideToggle(200);
            });
            
            // Select all checkboxes
            $('.mindfulseo-select-all').on('change', function() {
                const checked = $(this).is(':checked');
                $(this).closest('table').find('.mindfulseo-post-checkbox').prop('checked', checked);
            });
            
            // Fix with AI button
            $('.mindfulseo-fix-btn').on('click', function(e) {
                e.stopPropagation(); // Don't toggle card
                
                const card = $(this).closest('.mindfulseo-issue-card');
                const issueType = card.data('issue'); // Get issue type from data attribute
                const postIds = [];
                
                // Get checked posts or all posts if none checked
                card.find('.mindfulseo-post-checkbox:checked').each(function() {
                    postIds.push($(this).val());
                });
                
                if (postIds.length === 0) {
                    // If none selected, get all from the issue
                    card.find('tbody tr').each(function() {
                        const checkbox = $(this).find('.mindfulseo-post-checkbox');
                        if (checkbox.length) {
                            postIds.push(checkbox.val());
                        }
                    });
                }
                
                if (postIds.length > 0) {
                    // Redirect to batch optimizer with these post IDs and issue type
                    window.location.href = 'admin.php?page=mindfulseo-batch-optimize&auto_select=' + postIds.join(',') + '&issue_type=' + issueType;
                }
            });
            
            // Refresh audit
            $('#mindfulseo-refresh-audit').on('click', function() {
                location.reload();
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render issue card helper
     */
    private static function render_issue_card($issue_id, $title, $subtitle, $explanation, $post_ids, $priority = 'medium', $show_scores = false, $show_lengths = false) {
        $priority_class = 'mindfulseo-issue-' . $priority;
        $badge_class = 'mindfulseo-badge-' . $priority;
        $badge_label = ucfirst($priority);
        
        ?>
        <div class="mindfulseo-issue-card <?php echo esc_attr($priority_class); ?>" data-issue="<?php echo esc_attr($issue_id); ?>">
            <div class="mindfulseo-issue-header">
                <div class="mindfulseo-issue-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($badge_label); ?></div>
                <div class="mindfulseo-issue-title">
                    <h3><?php echo esc_html($title); ?></h3>
                    <p><?php echo esc_html($subtitle); ?></p>
                </div>
                <button class="button mindfulseo-expand-btn">
                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                    <?php _e('View Posts', 'mindfulseo'); ?>
                </button>
            </div>
            <div class="mindfulseo-issue-details" style="display: none;">
                <div class="mindfulseo-issue-explanation">
                    <p><?php echo esc_html($explanation); ?></p>
                </div>
                
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width: 30px;"><input type="checkbox" class="mindfulseo-select-all"></th>
                            <th><?php _e('Post Title', 'mindfulseo'); ?></th>
                            <?php if ($show_scores): ?>
                            <th style="width: 100px;"><?php _e('Current Score', 'mindfulseo'); ?></th>
                            <?php elseif ($show_lengths): ?>
                            <th style="width: 100px;"><?php _e('Length', 'mindfulseo'); ?></th>
                            <?php else: ?>
                            <th style="width: 100px;"><?php _e('SEO Score', 'mindfulseo'); ?></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($post_ids as $post_id) : 
                            $post = get_post($post_id);
                            if (!$post) continue;
                            $score = get_post_meta($post_id, 'rank_math_seo_score', true);
                            
                            if ($show_lengths) {
                                $title_text = get_post_meta($post_id, 'rank_math_title', true);
                                if (empty($title_text)) {
                                    $title_text = $post->post_title;
                                }
                                $length = strlen($title_text);
                            }
                        ?>
                        <tr>
                            <td><input type="checkbox" class="mindfulseo-post-checkbox" value="<?php echo esc_attr($post_id); ?>"></td>
                            <td><strong><a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>" target="_blank"><?php echo esc_html($post->post_title); ?></a></strong></td>
                            <td>
                                <?php if ($show_scores): ?>
                                    <span style="display: inline-block; padding: 3px 8px; border-radius: 3px; font-weight: 600; font-size: 12px; <?php echo $score < 50 ? 'background: #fee; color: #d63638;' : 'background: #fff8dc; color: #996800;'; ?>">
                                        <?php echo $score ? esc_html($score) : '—'; ?>
                                    </span>
                                <?php elseif ($show_lengths): ?>
                                    <span style="<?php echo $length > 70 ? 'color: #d63638; font-weight: 600;' : 'color: #996800;'; ?>">
                                        <?php echo esc_html($length); ?> chars
                                    </span>
                                <?php else: ?>
                                    <?php echo $score ? esc_html($score) : '—'; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div class="mindfulseo-issue-actions">
                    <div class="mindfulseo-issue-actions-left">
                        <small style="color: #646970;">
                            <span class="dashicons dashicons-info" style="font-size: 14px; vertical-align: middle;"></span>
                            <?php _e('Select specific posts or click "Fix with AI" to optimize all', 'mindfulseo'); ?>
                        </small>
                    </div>
                    <button class="button button-primary button-large mindfulseo-fix-btn">
                        <span class="dashicons dashicons-admin-generic" style="margin-top: 3px;"></span>
                        <?php _e('Fix with AI', 'mindfulseo'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Run SEO audit
     * 
     * @return array Audit results
     */
    private static function run_audit() {
        global $wpdb;
        
        // Get total published posts
        $total_posts = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->posts}
            WHERE post_type = 'post' 
            AND post_status = 'publish'
        ");
        
        if (!$total_posts) {
            return array(
                'total_posts' => 0,
                'posts_optimized' => 0,
                'total_issues' => 0,
                'optimization_score' => 100,
                'issues' => array(
                    'no_meta_description' => array('count' => 0, 'posts' => array()),
                    'no_focus_keyword' => array('count' => 0, 'posts' => array()),
                    'low_seo_score' => array('count' => 0, 'posts' => array()),
                    'title_too_long' => array('count' => 0, 'posts' => array()),
                    'thin_content' => array('count' => 0, 'posts' => array()),
                ),
            );
        }
        
        // 1. Posts without meta descriptions
        $no_meta = $wpdb->get_results("
            SELECT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                AND pm.meta_key = 'rank_math_description'
            WHERE p.post_type = 'post' 
            AND p.post_status = 'publish'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
            LIMIT 100
        ", ARRAY_A);
        
        // 2. Posts without focus keywords
        $no_keyword = $wpdb->get_results("
            SELECT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                AND pm.meta_key = 'rank_math_focus_keyword'
            WHERE p.post_type = 'post' 
            AND p.post_status = 'publish'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
            LIMIT 100
        ", ARRAY_A);
        
        // 3. Posts with low SEO scores
        $low_score = $wpdb->get_results("
            SELECT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                AND pm.meta_key = 'rank_math_seo_score'
            WHERE p.post_type = 'post' 
            AND p.post_status = 'publish'
            AND CAST(pm.meta_value AS UNSIGNED) < 70
            AND CAST(pm.meta_value AS UNSIGNED) > 0
            ORDER BY CAST(pm.meta_value AS UNSIGNED) ASC
            LIMIT 100
        ", ARRAY_A);
        
        // 4. Titles too long
        $long_titles = $wpdb->get_results("
            SELECT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                AND pm.meta_key = 'rank_math_title'
            WHERE p.post_type = 'post' 
            AND p.post_status = 'publish'
            AND LENGTH(COALESCE(pm.meta_value, p.post_title)) > 60
            LIMIT 100
        ", ARRAY_A);
        
        // 5. Thin content
        $thin_content = $wpdb->get_results("
            SELECT ID
            FROM {$wpdb->posts}
            WHERE post_type = 'post' 
            AND post_status = 'publish'
            AND CHAR_LENGTH(post_content) < 1000
            LIMIT 100
        ", ARRAY_A);
        
        // Calculate posts optimized (have both keyword and meta description)
        $posts_optimized = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id 
                AND pm1.meta_key = 'rank_math_focus_keyword'
                AND pm1.meta_value != ''
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id 
                AND pm2.meta_key = 'rank_math_description'
                AND pm2.meta_value != ''
            WHERE p.post_type = 'post' 
            AND p.post_status = 'publish'
        ");
        
        $total_issues = count($no_meta) + count($no_keyword) + count($low_score) + count($long_titles) + count($thin_content);
        
        $optimization_score = $total_posts > 0 ? round(($posts_optimized / $total_posts) * 100) : 100;
        
        return array(
            'total_posts' => intval($total_posts),
            'posts_optimized' => intval($posts_optimized),
            'total_issues' => $total_issues,
            'optimization_score' => $optimization_score,
            'issues' => array(
                'no_meta_description' => array(
                    'count' => count($no_meta),
                    'posts' => wp_list_pluck($no_meta, 'ID'),
                ),
                'no_focus_keyword' => array(
                    'count' => count($no_keyword),
                    'posts' => wp_list_pluck($no_keyword, 'ID'),
                ),
                'low_seo_score' => array(
                    'count' => count($low_score),
                    'posts' => wp_list_pluck($low_score, 'ID'),
                ),
                'title_too_long' => array(
                    'count' => count($long_titles),
                    'posts' => wp_list_pluck($long_titles, 'ID'),
                ),
                'thin_content' => array(
                    'count' => count($thin_content),
                    'posts' => wp_list_pluck($thin_content, 'ID'),
                ),
            ),
        );
    }
}
