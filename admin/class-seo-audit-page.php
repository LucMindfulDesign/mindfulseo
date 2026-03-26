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
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'mindfulseo'));
        }

        $audit = self::run_audit();
        $score = $audit['optimization_score'];
        $score_class = $score >= 80 ? 'good' : ( $score >= 50 ? 'warning' : 'error' );

        if (class_exists('MFSEO_Admin_Page') && method_exists(MFSEO_Admin_Page::get_instance(), 'get_branding_header')) {
            echo MFSEO_Admin_Page::get_instance()->get_branding_header(__('SEO Audit', 'mindfulseo'));
        } else {
            echo '<div class="mindfulseo-branding-header"><h1>' . esc_html__('SEO Audit', 'mindfulseo') . '</h1></div>';
        }

        $high_count   = $audit['issues']['no_meta_description']['count'] + $audit['issues']['no_focus_keyword']['count'];
        $medium_count = $audit['issues']['low_seo_score']['count'] + $audit['issues']['title_too_long']['count'];
        $info_count   = $audit['issues']['thin_content']['count'];
        ?>
        <div class="wrap mindfulseo-page mindfulseo-audit">
            <div class="mindfulseo-content">

                <div class="mfseo-audit-hero">
                    <div class="mfseo-audit-hero__ring">
                        <svg class="mfseo-ring" viewBox="0 0 120 120">
                            <circle cx="60" cy="60" r="52" fill="none" stroke="#e9ecef" stroke-width="10"/>
                            <circle cx="60" cy="60" r="52" fill="none"
                                    stroke="<?php echo $score_class === 'good' ? '#46b450' : ( $score_class === 'warning' ? '#f0b849' : '#dc3232' ); ?>"
                                    stroke-width="10" stroke-linecap="round"
                                    stroke-dasharray="<?php echo round( 326.73 * $score / 100 ); ?> 326.73"
                                    transform="rotate(-90 60 60)"/>
                        </svg>
                        <div class="mfseo-ring__value"><?php echo esc_html( $score ); ?>%</div>
                    </div>
                    <div class="mfseo-audit-hero__details">
                        <div class="mfseo-audit-hero__metrics">
                            <div class="mfseo-audit-metric">
                                <span class="mfseo-audit-metric__val"><?php echo esc_html( $audit['total_issues'] ); ?></span>
                                <span class="mfseo-audit-metric__lbl"><?php _e( 'Issues', 'mindfulseo' ); ?></span>
                            </div>
                            <div class="mfseo-audit-metric">
                                <span class="mfseo-audit-metric__val"><?php echo esc_html( $audit['posts_optimized'] ); ?></span>
                                <span class="mfseo-audit-metric__lbl"><?php _e( 'Optimized', 'mindfulseo' ); ?></span>
                            </div>
                            <div class="mfseo-audit-metric">
                                <span class="mfseo-audit-metric__val"><?php echo esc_html( $audit['total_posts'] ); ?></span>
                                <span class="mfseo-audit-metric__lbl"><?php _e( 'Published', 'mindfulseo' ); ?></span>
                            </div>
                        </div>
                        <?php if ( $audit['total_issues'] === 0 ) : ?>
                            <p class="mfseo-audit-hero__msg mfseo-audit-hero__msg--ok"><?php _e( 'All posts have proper SEO metadata.', 'mindfulseo' ); ?></p>
                        <?php else : ?>
                            <p class="mfseo-audit-hero__msg"><?php printf( __( '%s posts need SEO attention. Expand any issue below to fix.', 'mindfulseo' ), '<strong>' . $audit['total_issues'] . '</strong>' ); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="mfseo-audit-hero__action">
                        <?php if ( $audit['total_issues'] > 0 ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=mindfulseo-batch-optimize' ) ); ?>" id="mfseo-fix-all-btn" class="button button-primary button-hero" style="display:inline-flex;align-items:center;gap:6px;font-size:14px;padding:6px 18px;height:auto;" data-issues="<?php echo intval($audit['total_issues']); ?>">
                            <span class="dashicons dashicons-admin-generic" style="font-size:18px;width:18px;height:18px;"></span>
                            <?php _e( 'Fix All with Batch Optimizer', 'mindfulseo' ); ?>
                        </a>
                        <?php endif; ?>
                        <button class="button button-secondary" id="mindfulseo-refresh-audit">
                            <span class="dashicons dashicons-update"></span>
                            <?php _e( 'Refresh', 'mindfulseo' ); ?>
                        </button>
                    </div>
                </div>

                <?php if ( $audit['total_issues'] > 0 ) : ?>

                <div class="mindfulseo-audit-issues">

                    <!-- Missing Meta Descriptions -->
                    <?php if ($audit['issues']['no_meta_description']['count'] > 0) : ?>
                    <?php self::render_issue_card(
                        'no_meta_description',
                        __('Missing Meta Descriptions', 'mindfulseo'),
                        sprintf(__('%d posts without meta descriptions', 'mindfulseo'), $audit['issues']['no_meta_description']['count']),
                        __('Meta descriptions help searchers understand your content. Without them, Google creates poor auto-generated descriptions.', 'mindfulseo'),
                        $audit['issues']['no_meta_description']['posts'],
                        'high',
                        false,
                        false,
                        $audit['issues']['no_meta_description']['count']
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
                        'high',
                        false,
                        false,
                        $audit['issues']['no_focus_keyword']['count']
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
                        true,
                        $audit['issues']['title_too_long']['count']
                    ); ?>
                    <?php endif; ?>

                    <!-- Thin Content (view + edit only — AI cannot add content) -->
                    <?php if ($audit['issues']['thin_content']['count'] > 0) : ?>
                    <div class="mindfulseo-issue-card mindfulseo-issue-info" data-issue="thin_content">
                        <div class="mindfulseo-issue-header">
                            <div class="mindfulseo-issue-badge mindfulseo-badge-info">Info</div>
                            <div class="mindfulseo-issue-title">
                                <h3><?php _e('Thin Content', 'mindfulseo'); ?></h3>
                                <p><?php printf(__('%d posts with less than 300 words', 'mindfulseo'), $audit['issues']['thin_content']['count']); ?></p>
                            </div>
                            <div class="mindfulseo-header-buttons" style="display:flex;gap:8px;align-items:center;">
                                <button class="button mindfulseo-expand-btn" style="display:inline-flex;align-items:center;gap:4px;">
                                    <span class="dashicons dashicons-arrow-down-alt2" style="font-size:14px;width:14px;height:14px;line-height:14px;"></span>
                                    <?php _e('View Posts', 'mindfulseo'); ?>
                                </button>
                            </div>
                        </div>
                        <div class="mindfulseo-issue-details" style="display: none;">
                            <div class="mindfulseo-issue-explanation">
                                <p><?php _e('These posts need manual content expansion. AI cannot add new content — you\'ll need to write more yourself. Aim for 500+ words for better SEO.', 'mindfulseo'); ?></p>
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
                                        if (!$post) continue;
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

                </div><!-- /.mindfulseo-audit-issues -->

                <?php endif; ?><!-- /total_issues -->

            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Expand/collapse — only trigger on the expand button or the title area, not on fix buttons
            $('.mindfulseo-expand-btn, .mindfulseo-issue-title').on('click', function(e) {
                e.stopPropagation();
                const card = $(this).closest('.mindfulseo-issue-card');
                const details = card.find('.mindfulseo-issue-details');
                const btn = card.find('.mindfulseo-expand-btn .dashicons');
                
                card.toggleClass('expanded');
                details.slideToggle(200);
                btn.toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
            });
            
            // Update the selection bar count and visibility
            function updateSelectionBar(card) {
                const count = card.find('.mindfulseo-post-checkbox:checked').length;
                const bar = card.find('.mfseo-selection-bar');
                if (count > 0) {
                    bar.find('.mfseo-selection-bar__count').text(count + ' post' + (count !== 1 ? 's' : '') + ' selected');
                    bar.slideDown(150);
                } else {
                    bar.slideUp(150);
                }
            }
            
            // Select all checkboxes
            $('.mindfulseo-select-all').on('change', function() {
                const checked = $(this).is(':checked');
                const card = $(this).closest('.mindfulseo-issue-card');
                card.find('.mindfulseo-post-checkbox').prop('checked', checked);
                updateSelectionBar(card);
            });
            
            // Individual checkbox change
            $('.mindfulseo-post-checkbox').on('change', function() {
                updateSelectionBar($(this).closest('.mindfulseo-issue-card'));
            });
            
            // Shared friendly warning for large batch operations (threshold: 500 posts)
            var MFSEO_WARN_THRESHOLD = 500;

            function mfseoMaybeBatchWarn(count, onConfirm) {
                if (count < MFSEO_WARN_THRESHOLD) {
                    onConfirm();
                    return;
                }
                var $overlay = $('<div class="mfseo-warn-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.45);z-index:999998;"></div>');
                var $dialog = $('<div class="mfseo-warn-dialog" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:28px 30px 24px;border-radius:10px;box-shadow:0 8px 40px rgba(0,0,0,0.18);z-index:999999;max-width:420px;width:90%;">' +
                    '<strong style="display:block;font-size:15px;color:#1d2327;margin-bottom:10px;">Optimising ' + count.toLocaleString() + ' posts</strong>' +
                    '<p style="margin:0 0 18px;line-height:1.7;color:#50575e;">This may take a little while and will use API credits. You can always select fewer posts using the checkboxes instead.</p>' +
                    '<div style="display:flex;gap:10px;justify-content:flex-end;">' +
                        '<button class="button mfseo-warn-cancel" style="padding:6px 16px;">Cancel</button>' +
                        '<button class="button button-primary mfseo-warn-confirm" style="padding:6px 16px;">Continue</button>' +
                    '</div>' +
                '</div>');

                $('body').append($overlay, $dialog);
                $dialog.find('.mfseo-warn-confirm').on('click', function() {
                    $overlay.remove(); $dialog.remove();
                    onConfirm();
                });
                $overlay.add($dialog.find('.mfseo-warn-cancel')).on('click', function() {
                    $overlay.remove(); $dialog.remove();
                });
            }

            // Fix N Posts buttons (header + expanded detail)
            $('.mindfulseo-fix-btn').on('click', function(e) {
                e.stopPropagation();
                var card = $(this).closest('.mindfulseo-issue-card');
                var issueType = card.data('issue');
                var postIds = [];

                card.find('.mindfulseo-post-checkbox:checked').each(function() { postIds.push($(this).val()); });
                if (postIds.length === 0) {
                    card.find('.mindfulseo-post-checkbox').each(function() { postIds.push($(this).val()); });
                }

                if (postIds.length === 0) return;

                mfseoMaybeBatchWarn(postIds.length, function() {
                    window.location.href = 'admin.php?page=mindfulseo-batch-optimize&auto_select=' + postIds.join(',') + '&issue_type=' + issueType;
                });
            });

            // Fix All with Batch Optimizer (always warn — it's the whole site)
            $('#mfseo-fix-all-btn').on('click', function(e) {
                e.preventDefault();
                var href = $(this).attr('href');
                var issues = parseInt($(this).data('issues')) || 0;
                mfseoMaybeBatchWarn(Math.max(issues, MFSEO_WARN_THRESHOLD), function() {
                    window.location.href = href;
                });
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
    private static function render_issue_card($issue_id, $title, $subtitle, $explanation, $post_ids, $priority = 'medium', $show_scores = false, $show_lengths = false, $total_count = 0) {
        if ($total_count <= 0) {
            $total_count = count($post_ids);
        }
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
                <div class="mindfulseo-header-buttons" style="display:flex;gap:8px;align-items:center;">
                    <button class="button button-primary button-small mindfulseo-fix-btn" style="display:inline-flex;align-items:center;gap:4px;" title="<?php esc_attr_e('Send these posts to Batch Optimizer', 'mindfulseo'); ?>">
                        <span class="dashicons dashicons-admin-generic" style="font-size:14px;width:14px;height:14px;line-height:14px;"></span>
                        <?php printf( __( 'Fix %d Posts', 'mindfulseo' ), $total_count ); ?>
                    </button>
                    <button class="button mindfulseo-expand-btn" style="display:inline-flex;align-items:center;gap:4px;">
                        <span class="dashicons dashicons-arrow-down-alt2" style="font-size:14px;width:14px;height:14px;line-height:14px;"></span>
                        <?php _e('View Posts', 'mindfulseo'); ?>
                    </button>
                </div>
            </div>
            <div class="mindfulseo-issue-details" style="display: none;">
                <div class="mindfulseo-issue-explanation">
                    <p><?php echo esc_html($explanation); ?></p>
                </div>

                <div class="mfseo-selection-bar" style="display:none;">
                    <span class="mfseo-selection-bar__count">0 posts selected</span>
                    <button class="button button-primary mindfulseo-fix-btn" style="display:inline-flex;align-items:center;gap:4px;">
                        <span class="dashicons dashicons-admin-generic" style="font-size:16px;width:16px;height:16px;line-height:16px;"></span>
                        <?php _e('Fix Selected with AI', 'mindfulseo'); ?>
                    </button>
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
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                            $adapter = class_exists('MFSEO_SEO_Plugin_Adapter') ? MFSEO_SEO_Plugin_Adapter::get_instance() : null;
                            foreach ($post_ids as $post_id) :
                            $post = get_post($post_id);
                            if (!$post) continue;
                            if ($show_scores) {
                                $score = $adapter ? $adapter->get_seo_score($post_id) : get_post_meta($post_id, 'rank_math_seo_score', true);
                            }
                            if ($show_lengths) {
                                $title_text = $adapter ? $adapter->get_seo_title($post_id) : get_post_meta($post_id, 'rank_math_title', true);
                                if (empty($title_text)) {
                                    $title_text = $post->post_title;
                                }
                                $length = strlen($title_text);
                            }
                        ?>
                        <tr>
                            <td><input type="checkbox" class="mindfulseo-post-checkbox" value="<?php echo esc_attr($post_id); ?>"></td>
                            <td><strong><a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>" target="_blank"><?php echo esc_html($post->post_title); ?></a></strong></td>
                            <?php if ($show_scores): ?>
                            <td>
                                <span style="display: inline-block; padding: 3px 8px; border-radius: 3px; font-weight: 600; font-size: 12px; <?php echo $score < 50 ? 'background: #fee; color: #d63638;' : 'background: #fff8dc; color: #996800;'; ?>">
                                    <?php echo $score ? esc_html($score) : '—'; ?>
                                </span>
                            </td>
                            <?php elseif ($show_lengths): ?>
                            <td>
                                <span style="<?php echo $length > 70 ? 'color: #d63638; font-weight: 600;' : 'color: #996800;'; ?>">
                                    <?php echo esc_html($length); ?> chars
                                </span>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div class="mindfulseo-issue-actions">
                    <div class="mindfulseo-issue-actions-left">
                        <small style="color: #646970;">
                            <span class="dashicons dashicons-info" style="font-size: 14px; vertical-align: middle;"></span>
                            <?php _e('Select specific posts or click "Fix Selected" to optimize checked posts', 'mindfulseo'); ?>
                        </small>
                    </div>
                    <button class="button button-primary button-large mindfulseo-fix-btn">
                        <span class="dashicons dashicons-admin-generic" style="margin-top: 3px;"></span>
                        <?php _e('Fix Selected with AI', 'mindfulseo'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Run SEO audit (uses SEO Plugin Adapter for Rank Math / Yoast meta keys)
     *
     * @return array Audit results
     */
    private static function run_audit() {
        global $wpdb;

        $adapter = class_exists('MFSEO_SEO_Plugin_Adapter') ? MFSEO_SEO_Plugin_Adapter::get_instance() : null;
        $keys = $adapter && $adapter->is_seo_plugin_active() ? $adapter->get_meta_key_map() : array();
        $meta_desc_key = isset($keys['description']) ? $keys['description'] : 'rank_math_description';
        $focus_key = isset($keys['keyword']) ? $keys['keyword'] : 'rank_math_focus_keyword';
        $title_key = isset($keys['title']) ? $keys['title'] : 'rank_math_title';
        $is_rankmath = $adapter && $adapter->get_active_plugin() === 'rankmath';

        $total_posts = (int) $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->posts}
            WHERE post_type = 'post' AND post_status = 'publish'
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

        // Get accurate counts (no LIMIT)
        $no_meta_count = (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
            WHERE p.post_type = 'post' AND p.post_status = 'publish'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
        ", $meta_desc_key));

        $no_keyword_count = (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
            WHERE p.post_type = 'post' AND p.post_status = 'publish'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
        ", $focus_key));

        $low_score_count = 0;
        if ($is_rankmath) {
            $low_score_count = (int) $wpdb->get_var("
                SELECT COUNT(*)
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'rank_math_seo_score'
                WHERE p.post_type = 'post' AND p.post_status = 'publish'
                AND CAST(pm.meta_value AS UNSIGNED) < 70 AND CAST(pm.meta_value AS UNSIGNED) > 0
            ");
        }

        $long_titles_count = (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
            WHERE p.post_type = 'post' AND p.post_status = 'publish'
            AND LENGTH(COALESCE(pm.meta_value, p.post_title)) > 60
        ", $title_key));

        $thin_content_count = (int) $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'post' AND post_status = 'publish'
            AND CHAR_LENGTH(post_content) < 1000
        ");

        // Get post IDs for display (capped for performance)
        $display_limit = 100;

        $no_meta = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
            WHERE p.post_type = 'post' AND p.post_status = 'publish'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
            LIMIT %d
        ", $meta_desc_key, $display_limit), ARRAY_A);

        $no_keyword = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
            WHERE p.post_type = 'post' AND p.post_status = 'publish'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
            LIMIT %d
        ", $focus_key, $display_limit), ARRAY_A);

        $low_score = array();
        if ($is_rankmath) {
            $low_score = $wpdb->get_results($wpdb->prepare("
                SELECT p.ID
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'rank_math_seo_score'
                WHERE p.post_type = 'post' AND p.post_status = 'publish'
                AND CAST(pm.meta_value AS UNSIGNED) < 70 AND CAST(pm.meta_value AS UNSIGNED) > 0
                ORDER BY CAST(pm.meta_value AS UNSIGNED) ASC
                LIMIT %d
            ", $display_limit), ARRAY_A);
        }

        $long_titles = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
            WHERE p.post_type = 'post' AND p.post_status = 'publish'
            AND LENGTH(COALESCE(pm.meta_value, p.post_title)) > 60
            LIMIT %d
        ", $title_key, $display_limit), ARRAY_A);

        $thin_content = $wpdb->get_results($wpdb->prepare("
            SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'post' AND post_status = 'publish'
            AND CHAR_LENGTH(post_content) < 1000
            LIMIT %d
        ", $display_limit), ARRAY_A);

        $posts_optimized = (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = %s AND pm1.meta_value != ''
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = %s AND pm2.meta_value != ''
            WHERE p.post_type = 'post' AND p.post_status = 'publish'
        ", $focus_key, $meta_desc_key));

        $total_issues = $no_meta_count + $no_keyword_count + $low_score_count + $long_titles_count + $thin_content_count;
        $optimization_score = $total_posts > 0 ? (int) round(($posts_optimized / $total_posts) * 100) : 100;

        return array(
            'total_posts' => $total_posts,
            'posts_optimized' => $posts_optimized,
            'total_issues' => $total_issues,
            'optimization_score' => $optimization_score,
            'issues' => array(
                'no_meta_description' => array('count' => $no_meta_count, 'posts' => wp_list_pluck($no_meta, 'ID')),
                'no_focus_keyword' => array('count' => $no_keyword_count, 'posts' => wp_list_pluck($no_keyword, 'ID')),
                'low_seo_score' => array('count' => $low_score_count, 'posts' => wp_list_pluck($low_score, 'ID')),
                'title_too_long' => array('count' => $long_titles_count, 'posts' => wp_list_pluck($long_titles, 'ID')),
                'thin_content' => array('count' => $thin_content_count, 'posts' => wp_list_pluck($thin_content, 'ID')),
            ),
        );
    }
}
