<?php
/**
 * Batch Optimizer Page
 * 
 * Admin page for selecting and batch optimizing multiple posts
 *
 * @package MindfulSEO
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_Batch_Optimizer_Page {
    
    /**
     * Render the batch optimizer page
     */
    public function render_page() {
        // Check user capabilities
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'mindfulseo'));
        }
        
        // Get optimization statistics
        global $wpdb;
        $opts_table = $wpdb->prefix . 'mindfulseo_optimizations';
        $opts_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $opts_table)) === $opts_table;
        
        // Count ALL public post types (not just 'post')
        $post_types = get_post_types(array('public' => true), 'names');
        $total_count = 0;
        foreach ($post_types as $post_type) {
            $count_obj = wp_count_posts($post_type);
            $total_count += isset($count_obj->publish) ? $count_obj->publish : 0;
        }
        
        if ($opts_table_exists) {
            $stats = array(
                'total_posts' => $total_count,
                'optimized' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$opts_table} WHERE status = %s", 'approved' ) ),
                'pending' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$opts_table} WHERE status = %s", 'pending' ) ),
                'never_optimized' => 0,
            );
            // Optimisation rows can reference trashed/deleted posts or other post types — avoid negative "Never".
            $stats['never_optimized'] = max( 0, $stats['total_posts'] - $stats['optimized'] - $stats['pending'] );
        } else {
            $stats = array(
                'total_posts' => $total_count,
                'optimized' => 0,
                'pending' => 0,
                'never_optimized' => $total_count,
            );
        }
        
        // Active filters
        $filters = $this->get_active_filters();

        // Check if we have auto_select parameter (from SEO Audit)
        $auto_select = isset($_GET['auto_select']) ? sanitize_text_field($_GET['auto_select']) : '';
        $auto_select_ids = array();
        if (!empty($auto_select)) {
            $auto_select_ids = array_map('intval', explode(',', $auto_select));
            $filters['post_ids'] = $auto_select_ids; // Add to filters
        }

        // Pagination
        $per_page = $filters['per_page'];
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Get all posts with their optimization status and SEO data
        $posts = $this->get_posts_with_status($per_page, $offset, $filters);
        $total_posts_count = $this->get_total_posts_count($filters);
        $total_pages = ceil($total_posts_count / $per_page);

        // Prepare supporting data: SEO adapter, keyword metrics, Rank Math scores
        require_once MINDFULSEO_PLUGIN_DIR . 'includes/class-seo-plugin-adapter.php';
        $adapter = MFSEO_SEO_Plugin_Adapter::get_instance();

        require_once MINDFULSEO_PLUGIN_DIR . 'includes/class-keyword-manager.php';
        $keyword_manager = MFSEO_Keyword_Manager::get_instance();

        $post_keywords = array();
        $metrics_keywords = array();

        foreach ($posts as $post) {
            $focus_keyword = trim((string) $adapter->get_focus_keyword($post->ID));
            if ($focus_keyword === '' && ! empty($post->opt_primary_keyword)) {
                $focus_keyword = trim((string) $post->opt_primary_keyword);
            }
            $post_keywords[$post->ID] = $focus_keyword;

            if ($focus_keyword !== '') {
                $metrics_keywords[] = $focus_keyword;
            }

        }

        $keyword_metrics = !empty($metrics_keywords)
            ? $keyword_manager->get_metrics_for_keywords($metrics_keywords)
            : array();
        
        ?>
        <?php settings_errors(); ?>
        <?php $this->render_header(); ?>
        <?php if (!$opts_table_exists) : ?>
        <div class="notice notice-warning inline"><p><?php esc_html_e('Database tables may need to be created. Try deactivating and reactivating the plugin.', 'mindfulseo'); ?></p></div>
        <?php endif; ?>
        
        <div class="wrap mindfulseo-page">
            
            <div class="mindfulseo-content">

                <!-- Statistics Cards -->
                <div class="mindfulseo-stats-grid">
                    <div class="mindfulseo-stat-card">
                        <div class="stat-icon">
                            <img src="<?php echo MINDFULSEO_PLUGIN_URL . 'assets/icon-posts.svg'; ?>" alt="" />
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo esc_html($stats['total_posts']); ?></div>
                            <div class="stat-label">Total Posts</div>
                        </div>
                    </div>
                    
                    <div class="mindfulseo-stat-card stat-success">
                        <div class="stat-icon">
                            <img src="<?php echo MINDFULSEO_PLUGIN_URL . 'assets/icon-check.svg'; ?>" alt="" />
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo esc_html($stats['optimized']); ?></div>
                            <div class="stat-label">Optimized</div>
                        </div>
                    </div>
                    
                    <div class="mindfulseo-stat-card stat-warning">
                        <div class="stat-icon">
                            <img src="<?php echo MINDFULSEO_PLUGIN_URL . 'assets/icon-clock.svg'; ?>" alt="" />
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo esc_html($stats['pending']); ?></div>
                            <div class="stat-label">Pending Review</div>
                        </div>
                    </div>
                    
                    <div class="mindfulseo-stat-card stat-info">
                        <div class="stat-icon">
                            <img src="<?php echo MINDFULSEO_PLUGIN_URL . 'assets/icon-close.svg'; ?>" alt="" />
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo esc_html($stats['never_optimized']); ?></div>
                            <div class="stat-label">Never Optimized</div>
                        </div>
                    </div>
                    
                </div>
                
                <!-- Filters -->
                <div class="mindfulseo-filters">
                    <div class="filter-group">
                        <label for="post-type-filter"><?php _e('Post Type:', 'mindfulseo'); ?></label>
                        <select id="post-type-filter">
                            <option value="all" <?php selected($filters['post_type'], 'all'); ?>><?php _e('All Types', 'mindfulseo'); ?></option>
                            <option value="post" <?php selected($filters['post_type'], 'post'); ?>><?php _e('Posts', 'mindfulseo'); ?></option>
                            <option value="page" <?php selected($filters['post_type'], 'page'); ?>><?php _e('Pages', 'mindfulseo'); ?></option>
                            <?php
                            // Add custom post types
                            $post_types = get_post_types(array('public' => true, '_builtin' => false), 'objects');
                            foreach ($post_types as $post_type) {
                                printf(
                                    '<option value="%1$s" %2$s>%3$s</option>',
                                    esc_attr($post_type->name),
                                    selected($filters['post_type'], $post_type->name, false),
                                    esc_html($post_type->label)
                                );
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="status-filter"><?php _e('Optimization Status:', 'mindfulseo'); ?></label>
                        <select id="status-filter">
                            <option value="all" <?php selected($filters['status'], 'all'); ?>><?php _e('All', 'mindfulseo'); ?></option>
                            <option value="optimized" <?php selected($filters['status'], 'optimized'); ?>><?php _e('Optimized', 'mindfulseo'); ?></option>
                            <option value="pending" <?php selected($filters['status'], 'pending'); ?>><?php _e('Pending Review', 'mindfulseo'); ?></option>
                            <option value="never" <?php selected($filters['status'], 'never'); ?>><?php _e('Never Optimized', 'mindfulseo'); ?></option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="date-filter"><?php _e('Date Range:', 'mindfulseo'); ?></label>
                        <select id="date-filter">
                            <option value="all" <?php selected($filters['date'], 'all'); ?>><?php _e('All Time', 'mindfulseo'); ?></option>
                            <option value="today" <?php selected($filters['date'], 'today'); ?>><?php _e('Today', 'mindfulseo'); ?></option>
                            <option value="week" <?php selected($filters['date'], 'week'); ?>><?php _e('Last 7 Days', 'mindfulseo'); ?></option>
                            <option value="month" <?php selected($filters['date'], 'month'); ?>><?php _e('Last 30 Days', 'mindfulseo'); ?></option>
                            <option value="year" <?php selected($filters['date'], 'year'); ?>><?php _e('Last Year', 'mindfulseo'); ?></option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="per-page-filter"><?php _e('Posts Per Page:', 'mindfulseo'); ?></label>
                        <select id="per-page-filter">
                            <option value="25" <?php selected($filters['per_page'], 25); ?>>25</option>
                            <option value="50" <?php selected($filters['per_page'], 50); ?>>50</option>
                            <option value="100" <?php selected($filters['per_page'], 100); ?>>100</option>
                            <option value="200" <?php selected($filters['per_page'], 200); ?>>200</option>
                        </select>
                    </div>

                    <button class="button button-primary" id="apply-filters"><?php _e('Apply Filters', 'mindfulseo'); ?></button>
                    <button class="button" id="reset-filters"><?php _e('Reset', 'mindfulseo'); ?></button>
                </div>
                
                <!-- Custom Prompts Section -->
                <?php
                $settings = get_option('mindfulseo_settings', array());
                $batch_optimizer_prompt = isset($settings['batch_optimizer_prompt']) ? $settings['batch_optimizer_prompt'] : '';
                ?>
                <div class="mindfulseo-custom-prompts-section" style="margin-top: 20px;">
                    <div class="mindfulseo-section-header" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">
                        <h3 style="margin: 0; font-size: 14px; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                            <span class="dashicons dashicons-admin-generic" style="font-size: 16px; width: 16px; height: 16px;"></span>
                            <span><?php _e('Custom AI Prompts', 'mindfulseo'); ?></span>
                        </h3>
                        <button type="button" class="button toggle-prompts-btn mfseo-btn-icon" style="margin: 0;" aria-expanded="false" aria-controls="batch-optimizer-prompts-panel">
                            <span class="dashicons dashicons-arrow-down-alt2 toggle-prompts-icon" aria-hidden="true"></span>
                            <span class="toggle-prompts-label"><?php _e('Show', 'mindfulseo'); ?></span>
                        </button>
                    </div>
                    <div id="batch-optimizer-prompts-panel" class="prompts-content" style="display: none; padding: 20px; border: 1px solid #ddd; border-top: none; border-radius: 0 0 4px 4px; background: #fff;">
                        <p class="description">
                            <?php _e('Customize the AI prompts used for content optimization. These instructions will be appended to the default optimization prompts. Leave empty to use only the default prompts.', 'mindfulseo'); ?>
                        </p>
                        <div class="prompt-field" style="margin-top: 15px;">
                            <label for="batch-optimizer-prompt">
                                <strong><?php _e('Content Optimization Instructions', 'mindfulseo'); ?></strong>
                                <p class="description" style="margin-top: 5px;">
                                    <?php _e('Additional instructions for the AI when optimizing titles, descriptions, and keywords. This will be added to the system prompt.', 'mindfulseo'); ?>
                                </p>
                            </label>
                            <textarea id="batch-optimizer-prompt" 
                                      name="batch_optimizer_prompt" 
                                      rows="8" 
                                      style="width: 100%; font-family: monospace; font-size: 13px;"
                                      class="large-text code"
                                      placeholder="<?php esc_attr_e('Example: Focus on emotional storytelling. Always include a clear call-to-action. Use inclusive language.', 'mindfulseo'); ?>"><?php echo esc_textarea($batch_optimizer_prompt); ?></textarea>
                            <p class="description" style="margin-top: 8px;">
                                <?php _e('💡 Tip: Be specific! Example: "For blog posts, prioritize emotional engagement. For events, emphasize dates and locations prominently."', 'mindfulseo'); ?>
                            </p>
                            <div class="prompt-actions" style="margin-top: 15px; display: flex; gap: 10px;">
                                <button type="button" class="button button-primary save-prompt-btn" data-prompt-type="batch_optimizer">
                                    <span class="dashicons dashicons-yes"></span>
                                    <?php _e('Save Prompt', 'mindfulseo'); ?>
                                </button>
                                <button type="button" class="button reset-prompt-btn" data-prompt-type="batch_optimizer">
                                    <span class="dashicons dashicons-update"></span>
                                    <?php _e('Reset to Default', 'mindfulseo'); ?>
                                </button>
                                <span class="prompt-save-status" style="align-self: center; color: #46b450; display: none;">
                                    <span class="dashicons dashicons-yes"></span>
                                    <?php _e('Saved!', 'mindfulseo'); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="mindfulseo-table-actions">
                    <div class="mindfulseo-column-controls">
                        <button type="button" class="button column-toggle-button mfseo-btn-icon">
                            <span class="dashicons dashicons-visibility"></span>
                            <span><?php _e('Columns', 'mindfulseo'); ?></span>
                        </button>
                        <div class="column-toggle-panel">
                            <p class="column-toggle-heading"><?php _e('Show/Hide Columns', 'mindfulseo'); ?></p>
                            <div class="column-toggle-options">
                                <label><input type="checkbox" data-column="current_keyword" checked> <?php _e('Target Keyword', 'mindfulseo'); ?></label>
                                <label><input type="checkbox" data-column="seo_title" checked> <?php _e('SEO Title', 'mindfulseo'); ?></label>
                                <label><input type="checkbox" data-column="meta_description" checked> <?php _e('Meta Description', 'mindfulseo'); ?></label>
                                <label><input type="checkbox" data-column="slug" checked> <?php _e('Slug', 'mindfulseo'); ?></label>
                                <label><input type="checkbox" data-column="type" checked> <?php _e('Type', 'mindfulseo'); ?></label>
                                <label><input type="checkbox" data-column="status" checked> <?php _e('Status', 'mindfulseo'); ?></label>
                                <label><input type="checkbox" data-column="search_volume" checked> <?php _e('Vol. (Search Volume)', 'mindfulseo'); ?></label>
                                <label><input type="checkbox" data-column="difficulty" checked> <?php _e('KD (Difficulty)', 'mindfulseo'); ?></label>
                                <label><input type="checkbox" data-column="current_rank" checked> <?php _e('Rank', 'mindfulseo'); ?></label>
                                <label><input type="checkbox" data-column="optimization" checked> <?php _e('Optimization', 'mindfulseo'); ?></label>
                                <label><input type="checkbox" data-column="actions" checked> <?php _e('Actions', 'mindfulseo'); ?></label>
                            </div>
                        </div>
                    </div>
                    <div class="mindfulseo-table-actions-right">
                        <span class="selected-count">
                            <strong>0</strong> <?php _e('posts selected', 'mindfulseo'); ?>
                        </span>
                        <button class="button button-primary button-large mfseo-btn-icon" id="batch-optimize-btn" disabled>
                            <span class="dashicons dashicons-admin-generic"></span>
                            <span><?php _e('Optimize Selected Posts', 'mindfulseo'); ?></span>
                        </button>
                        <button type="button" class="button button-large mfseo-btn-icon" id="refresh-page-btn" style="margin-left: 10px;">
                            <span class="dashicons dashicons-update"></span>
                            <span><?php _e('Refresh Metrics', 'mindfulseo'); ?></span>
                        </button>
                        <?php
                        // Check if DataForSEO is configured
                        $settings = get_option('mindfulseo_settings', array());
                        $dataforseo_configured = !empty($settings['dataforseo_login']) && !empty($settings['dataforseo_password']);
                        ?>
                        <button type="button" class="button button-primary button-large mfseo-btn-icon" id="mindfulseo-analyze-rankings-batch-btn" 
                                style="margin-left: 10px; background: #d4af37; border-color: #b8941f;" 
                                <?php if (!$dataforseo_configured): ?>disabled title="<?php esc_attr_e('Configure DataForSEO API in Settings first', 'mindfulseo'); ?>"<?php endif; ?>>
                            <span class="dashicons dashicons-chart-line"></span>
                            <span><?php _e('Analyze Site Rankings', 'mindfulseo'); ?></span>
                        </button>
                    </div>
                </div>
                
                <!-- Progress Bar (shown during optimization) -->
                <div id="mfseo-inline-progress" class="mfseo-progress-bar-wrap" style="display:none;">
                    <div class="mfseo-progress-header">
                        <div class="mfseo-progress-header__left">
                            <div id="mfseo-progress-spinner" class="mfseo-progress-spinner"></div>
                            <div>
                                <strong id="mfseo-progress-title"><?php _e('Optimizing with AI...', 'mindfulseo'); ?></strong>
                                <span id="mfseo-progress-detail" class="mfseo-progress-detail"></span>
                            </div>
                        </div>
                        <div class="mfseo-progress-header__right">
                            <button type="button" id="mfseo-toggle-details" class="mfseo-progress-toggle-btn">
                                <span class="dashicons dashicons-arrow-down-alt2"></span>
                                <span><?php _e('See Details', 'mindfulseo'); ?></span>
                            </button>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=mindfulseo-batch-optimize&filter_status=optimized')); ?>" id="mfseo-view-optimized" class="mfseo-progress-view-btn" style="display:none;">
                                <?php _e('View Optimized Posts', 'mindfulseo'); ?>
                            </a>
                            <button type="button" id="mfseo-close-progress" class="mfseo-progress-close-btn" title="<?php esc_attr_e('Close', 'mindfulseo'); ?>">&times;</button>
                        </div>
                    </div>
                    <div class="mfseo-progress-track">
                        <div id="mfseo-progress-bar" class="mfseo-progress-fill"></div>
                    </div>
                    <div id="mfseo-progress-stats" class="mfseo-progress-stats">
                        <span id="mfseo-progress-count">0</span> <?php _e('of', 'mindfulseo'); ?> <span id="mfseo-progress-total">0</span> <?php _e('posts processed', 'mindfulseo'); ?>
                        &nbsp;&bull;&nbsp;
                        <span id="mfseo-progress-success" class="mfseo-progress-success">0 <?php _e('successful', 'mindfulseo'); ?></span>
                        &nbsp;&bull;&nbsp;
                        <span id="mfseo-progress-errors" class="mfseo-progress-errors">0 <?php _e('errors', 'mindfulseo'); ?></span>
                    </div>
                    <div id="mfseo-progress-details" class="mfseo-progress-details">
                        <div id="mfseo-progress-log" class="mfseo-progress-log"></div>
                    </div>
                </div>

                <!-- Posts Table -->
                <div class="mindfulseo-posts-table-wrap">
                    <div class="mindfulseo-table-scroll">
                        <table class="mindfulseo-table" id="posts-table">
                            <thead>
                            <tr>
                                <th width="40" class="column-select" data-sortable="false"><input type="checkbox" id="select-all-header"></th>
                                <th class="column-post-title sortable-column" data-column="post_title" data-sort-type="text"><?php _e('Post Title', 'mindfulseo'); ?></th>
                                <th class="column-current-keyword sortable-column" data-column="current_keyword" data-sort-type="text"><?php _e('Target Keyword', 'mindfulseo'); ?></th>
                                <th class="column-seo-title sortable-column" data-column="seo_title" data-sort-type="text"><?php _e('SEO Title', 'mindfulseo'); ?></th>
                                <th class="column-meta-description sortable-column" data-column="meta_description" data-sort-type="text"><?php _e('Meta Description', 'mindfulseo'); ?></th>
                                <th class="column-slug sortable-column" data-column="slug" data-sort-type="text"><?php _e('Slug', 'mindfulseo'); ?></th>
                                <th class="column-type sortable-column" data-column="type" data-sort-type="text"><?php _e('Type', 'mindfulseo'); ?></th>
                                <th class="column-status sortable-column" data-column="status" data-sort-type="text"><?php _e('Status', 'mindfulseo'); ?></th>
                                <th class="column-search-volume sortable-column" data-column="search_volume" data-sort-type="numeric" title="<?php esc_attr_e('Search Volume', 'mindfulseo'); ?>"><?php esc_html_e( 'Vol.', 'mindfulseo' ); ?></th>
                                <th class="column-difficulty sortable-column" data-column="difficulty" data-sort-type="numeric" title="<?php esc_attr_e('Keyword Difficulty', 'mindfulseo'); ?>"><?php esc_html_e( 'KD', 'mindfulseo' ); ?></th>
                                <th class="column-current-rank sortable-column" data-column="current_rank" data-sort-type="numeric" title="<?php esc_attr_e('Current Rank — real Google rank from DataForSEO; run Analyze Site Rankings to populate', 'mindfulseo'); ?>"><?php esc_html_e( 'Rank', 'mindfulseo' ); ?></th>
                                <th class="column-optimization sortable-column" data-column="optimization" data-sort-type="text"><?php _e('Optimization', 'mindfulseo'); ?></th>
                                <th class="column-actions" data-column="actions" data-sortable="false"><?php _e('Actions', 'mindfulseo'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            foreach ($posts as $post): 
                                $raw_keyword = isset($post_keywords[$post->ID]) ? $post_keywords[$post->ID] : '';
                                $current_keyword = $raw_keyword !== '' ? $raw_keyword : '—';
                                $metrics_key = strtolower($raw_keyword);
                                $metrics = ($raw_keyword !== '' && isset($keyword_metrics[$metrics_key])) ? $keyword_metrics[$metrics_key] : null;
                                $seo_title = $adapter->get_seo_title($post->ID);
                                if ($seo_title === null || $seo_title === '') {
                                    $seo_title = ! empty($post->opt_seo_title) ? (string) $post->opt_seo_title : null;
                                }
                                $seo_title = $seo_title ?: '—';

                                $meta_desc = $adapter->get_meta_description($post->ID);
                                if ($meta_desc === null || $meta_desc === '') {
                                    $meta_desc = ! empty($post->opt_meta_description) ? (string) $post->opt_meta_description : null;
                                }
                                $meta_desc = $meta_desc ?: '—';
                                $slug = $post->post_name ?: '—';

                                $search_volume_display = '—';
                                if ($metrics && isset($metrics['search_volume']) && $metrics['search_volume'] !== null) {
                                    // Show volume even if it's 0 (means no monthly searches)
                                    $search_volume_display = number_format(intval($metrics['search_volume']));
                                }

                                $difficulty_display = '—';
                                $difficulty_class = '';
                                if ($metrics && isset($metrics['keyword_difficulty']) && $metrics['keyword_difficulty'] !== null) {
                                    $difficulty_value = intval($metrics['keyword_difficulty']);
                                    $difficulty_display = $difficulty_value;

                                    if ($difficulty_value <= 29) {
                                        $difficulty_class = 'difficulty-easy';
                                    } elseif ($difficulty_value <= 69) {
                                        $difficulty_class = 'difficulty-medium';
                                    } else {
                                        $difficulty_class = 'difficulty-hard';
                                    }
                                }
                                
                                // Current rank display
                                $current_rank_display = '—';
                                $rank_class = '';
                                if ($metrics && isset($metrics['current_rank']) && $metrics['current_rank'] !== null) {
                                    $rank_value = intval($metrics['current_rank']);
                                    $current_rank_display = '#' . $rank_value;
                                    
                                    // Color code based on position
                                    if ($rank_value <= 3) {
                                        $rank_class = 'rank-top3'; // Gold
                                    } elseif ($rank_value <= 10) {
                                        $rank_class = 'rank-top10'; // Green
                                    } elseif ($rank_value <= 20) {
                                        $rank_class = 'rank-top20'; // Yellow
                                    } else {
                                        $rank_class = 'rank-low'; // Red
                                    }
                                }
                            ?>
                                <tr data-post-id="<?php echo esc_attr($post->ID); ?>" 
                                    data-status="<?php echo esc_attr($post->opt_status ?: 'never'); ?>"
                                    data-type="<?php echo esc_attr($post->post_type); ?>"
                                    data-modified="<?php echo esc_attr($post->post_modified); ?>">
                                    <td class="column-select">
                                        <input type="checkbox" class="post-checkbox" value="<?php echo esc_attr($post->ID); ?>">
                                    </td>
                                    <td class="column-post-title" data-column="post_title">
                                        <strong>
                                            <a href="<?php echo get_edit_post_link($post->ID); ?>" target="_blank">
                                                <?php echo esc_html($post->post_title); ?>
                                            </a>
                                        </strong>
                                    </td>
                                    <td class="column-current-keyword" data-column="current_keyword">
                                        <small class="editable" 
                                               contenteditable="true" 
                                               data-post-id="<?php echo esc_attr($post->ID); ?>"
                                               data-field="focus_keyword"><?php echo esc_html($current_keyword !== '—' ? $current_keyword : ''); ?></small>
                                    </td>
                                    <td class="column-seo-title" data-column="seo_title">
                                        <small class="editable" 
                                               contenteditable="true" 
                                               data-post-id="<?php echo esc_attr($post->ID); ?>"
                                               data-field="seo_title"><?php echo esc_html($seo_title !== '—' ? mb_substr($seo_title, 0, 60) : ''); ?></small>
                                    </td>
                                    <td class="column-meta-description" data-column="meta_description">
                                        <small class="editable" 
                                               contenteditable="true" 
                                               data-post-id="<?php echo esc_attr($post->ID); ?>"
                                               data-field="meta_description"><?php echo esc_html($meta_desc !== '—' ? mb_substr($meta_desc, 0, 160) : ''); ?></small>
                                    </td>
                                    <td class="column-slug" data-column="slug">
                                        <small class="editable" 
                                               contenteditable="true" 
                                               data-post-id="<?php echo esc_attr($post->ID); ?>"
                                               data-field="slug"><?php echo esc_html($slug !== '—' ? $slug : ''); ?></small>
                                    </td>
                                    <td class="column-type" data-column="type" data-sort-value="<?php $post_type_obj = get_post_type_object($post->post_type); $type_label = ($post_type_obj && isset($post_type_obj->labels->singular_name)) ? $post_type_obj->labels->singular_name : 'Post'; echo esc_attr($type_label); ?>"><?php echo esc_html($type_label); ?></td>
                                    <td class="column-status" data-column="status" data-sort-value="<?php echo esc_attr($post->post_status); ?>">
                                        <?php
                                        $status_labels = array(
                                            'publish' => '<span class="status-badge status-publish">Published</span>',
                                            'draft' => '<span class="status-badge status-draft">Draft</span>',
                                            'pending' => '<span class="status-badge status-pending">Pending</span>',
                                        );
                                        echo $status_labels[$post->post_status] ?? '<span class="status-badge">' . esc_html($post->post_status) . '</span>';
                                        ?>
                                    </td>
                                    <td class="metric-cell column-search-volume" data-column="search_volume" data-sort-value="<?php echo esc_attr($metrics && isset($metrics['search_volume']) ? intval($metrics['search_volume']) : 0); ?>">
                                        <?php echo esc_html($search_volume_display); ?>
                                    </td>
                                    <td class="metric-cell column-difficulty" data-column="difficulty" data-sort-value="<?php echo esc_attr($metrics && isset($metrics['keyword_difficulty']) ? intval($metrics['keyword_difficulty']) : 0); ?>">
                                        <?php if ($difficulty_display !== '—'): ?>
                                            <span class="mindfulseo-difficulty-badge <?php echo esc_attr($difficulty_class); ?>">
                                                <?php echo esc_html($difficulty_display); ?>
                                            </span>
                                        <?php else: ?>
                                            <?php echo esc_html($difficulty_display); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="metric-cell column-current-rank" data-column="current_rank" data-sort-value="<?php echo esc_attr($metrics && isset($metrics['current_rank']) ? intval($metrics['current_rank']) : 999); ?>">
                                        <?php if ($current_rank_display !== '—'): ?>
                                            <span class="mindfulseo-rank-badge <?php echo esc_attr($rank_class); ?>">
                                                <?php echo esc_html($current_rank_display); ?>
                                            </span>
                                        <?php else: ?>
                                            <?php echo esc_html($current_rank_display); ?>
                                        <?php endif; ?>
                                    </td>
                                    <?php
                                    $optimization_sort_value = 'Never';
                                    if ($post->opt_status === 'approved') {
                                        $optimization_sort_value = 'Optimized';
                                    } elseif ($post->opt_status === 'pending') {
                                        $optimization_sort_value = 'Pending';
                                    }
                                    ?>
                                    <td class="column-optimization" data-column="optimization" data-sort-value="<?php echo esc_attr($optimization_sort_value); ?>">
                                        <?php
                                        if ($post->opt_status === 'approved') {
                                            echo '<span class="opt-badge opt-approved">✅ Optimized</span>';
                                            if ($post->opt_date) {
                                                echo '<br><small>' . human_time_diff(strtotime($post->opt_date), current_time('timestamp')) . ' ago</small>';
                                            }
                                        } elseif ($post->opt_status === 'pending') {
                                            echo '<span class="opt-badge opt-pending">⏳ Pending</span>';
                                        } else {
                                            echo '<span class="opt-badge opt-none">— Never</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="column-actions" data-column="actions">
                                        <button class="button button-small optimize-single" data-post-id="<?php echo esc_attr($post->ID); ?>">
                                            <?php echo $post->opt_status === 'approved' ? __('Re-Optimize', 'mindfulseo') : __('Optimize', 'mindfulseo'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="mindfulseo-batch-table-scroll-hint">
                        <?php esc_html_e( 'Scroll inside the table area above to see all posts loaded on this page.', 'mindfulseo' ); ?>
                    </p>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="mindfulseo-pagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px; padding: 15px; border-top: 1px solid #dcdcde;">
                        <div style="color: #666; font-size: 13px;">
                            <?php
                            $start_count = (($current_page - 1) * $per_page) + 1;
                            $end_count = min($current_page * $per_page, $total_posts_count);
                            printf(__('Showing %d-%d of %d posts', 'mindfulseo'), $start_count, $end_count, $total_posts_count);
                            ?>
                        </div>
                        <div style="display: flex; align-items: center; gap: 5px;">
                        <?php
                        $base_url = add_query_arg(
                            $this->build_filter_query_args($filters),
                            admin_url('admin.php')
                        );
                        
                        // Previous button
                        if ($current_page > 1): ?>
                            <a href="<?php echo esc_url(add_query_arg('paged', ($current_page - 1), $base_url)); ?>" class="button">‹ <?php _e('Previous', 'mindfulseo'); ?></a>
                        <?php endif;
                        
                        // Determine page range to show
                        $range = 2; // Pages to show on each side of current page
                        $start_page = max(1, $current_page - $range);
                        $end_page = min($total_pages, $current_page + $range);
                        
                        // Always show first page
                        if ($start_page > 1): ?>
                            <a href="<?php echo esc_url(add_query_arg('paged', 1, $base_url)); ?>" class="button">1</a>
                            <?php if ($start_page > 2): ?>
                                <span class="button" style="cursor: default; background: transparent; border: none;">...</span>
                            <?php endif;
                        endif;
                        
                        // Page numbers
                        for ($i = $start_page; $i <= $end_page; $i++):
                            if ($i == $current_page): ?>
                                <span class="button button-primary" style="cursor: default;"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="<?php echo esc_url(add_query_arg('paged', $i, $base_url)); ?>" class="button"><?php echo $i; ?></a>
                            <?php endif;
                        endfor;
                        
                        // Always show last page
                        if ($end_page < $total_pages):
                            if ($end_page < $total_pages - 1): ?>
                                <span class="button" style="cursor: default; background: transparent; border: none;">...</span>
                            <?php endif; ?>
                            <a href="<?php echo esc_url(add_query_arg('paged', $total_pages, $base_url)); ?>" class="button"><?php echo $total_pages; ?></a>
                        <?php endif;
                        
                        // Next button
                        if ($current_page < $total_pages): ?>
                            <a href="<?php echo esc_url(add_query_arg('paged', ($current_page + 1), $base_url)); ?>" class="button"><?php _e('Next', 'mindfulseo'); ?> ›</a>
                        <?php endif; ?>
                        
                        <span style="margin-left: 15px; color: #666; font-size: 13px;">
                            <?php printf(__('Page %d of %d', 'mindfulseo'), $current_page, $total_pages); ?>
                        </span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
            </div>
        </div>
        
        <!-- Move WordPress notices out of header -->
        <script>
        jQuery(document).ready(function($) {
            // Move ALL notices to the top of wpbody-content
            function moveNotices() {
                const notices = $('.mindfulseo-branding-header').find('.notice, .updated, .error, .fs-notice');
                if (notices.length > 0) {
                    const wpbodyContent = $('#wpbody-content');
                    const screenMeta = $('#screen-meta');
                    
                    notices.each(function() {
                        $(this).detach();
                        if (screenMeta.length) {
                            screenMeta.after($(this));
                        } else {
                            wpbodyContent.prepend($(this));
                        }
                    });
                }
            }
            
            // Run immediately and watch for dynamically added notices
            moveNotices();
            setTimeout(moveNotices, 100);
            setTimeout(moveNotices, 500);
            
            const observer = new MutationObserver(moveNotices);
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render page header with branding - matching other pages
     */
    private function render_header() {
        $icon_url = MINDFULSEO_PLUGIN_URL . 'assets/icon-gold.svg?v=' . MINDFULSEO_VERSION;
        $logo_url = MINDFULSEO_PLUGIN_URL . 'assets/logo-white.svg?v=' . MINDFULSEO_VERSION;
        ?>
        <div class="mindfulseo-branding-header">
            <div class="mindfulseo-brand-content">
                <img src="<?php echo esc_url($icon_url); ?>" 
                     alt="Mindful Design Icon" 
                     style="height: 40px; width: auto;"
                     onerror="this.style.display='none';">
                <div class="mindfulseo-brand-text">
                    <h1><?php _e('Batch Optimizer', 'mindfulseo'); ?></h1>
                    <p class="mindfulseo-brand-tagline">
                        <?php _e('Select and optimize multiple posts at once', 'mindfulseo'); ?>
                    </p>
                </div>
            </div>
            <div class="mindfulseo-brand-logo">
                <img src="<?php echo esc_url($logo_url); ?>" 
                     alt="Mindful Design Logo" 
                     class="mindfulseo-brand-logo"
                     onerror="console.error('Logo failed to load:', this.src);">
            </div>
        </div>
        <?php
    }
    
    /**
     * LEFT JOIN fragment: one row per post — latest optimisation by optimization_date, then id.
     * (Legacy imports inserted newest-first so MAX(id) alone pointed at an old empty row.)
     *
     * @param string $opts_table Full table name including prefix.
     * @return string Safe SQL fragment (table name is passed from plugin code only).
     */
    private function get_latest_optimization_join_sql( $opts_table ) {
        return "
            LEFT JOIN (
                SELECT o1.post_id, o1.status, o1.optimization_date, o1.primary_keyword, o1.seo_title, o1.meta_description
                FROM {$opts_table} o1
                INNER JOIN (
                    SELECT o.post_id, MAX(o.id) AS latest_id
                    FROM {$opts_table} o
                    INNER JOIN (
                        SELECT post_id, MAX(optimization_date) AS max_date
                        FROM {$opts_table}
                        GROUP BY post_id
                    ) md ON o.post_id = md.post_id AND o.optimization_date = md.max_date
                    GROUP BY o.post_id
                ) pick ON o1.id = pick.latest_id
            ) o ON p.ID = o.post_id
        ";
    }

    /**
     * Get all posts with their optimization status
     *
     * @param int $per_page Posts per page
     * @param int $offset Offset for pagination
     * @return array Posts with status
     */
    private function get_posts_with_status($per_page = 50, $offset = 0, $filters = array()) {
        global $wpdb;
        $opts_table = $wpdb->prefix . 'mindfulseo_optimizations';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $opts_table)) !== $opts_table) {
            return array();
        }
        
        // Get all public post types (including custom post types like Events)
        $post_types = get_post_types(array('public' => true), 'names');
        $post_types_list = "'" . implode("', '", array_map('esc_sql', $post_types)) . "'";
        
        $where = array(
            "p.post_status IN ('publish', 'draft', 'pending')",
            "p.post_type IN ($post_types_list)"
        );
        $params = array();

        // Post IDs filter (from SEO Audit auto-select)
        if (!empty($filters['post_ids']) && is_array($filters['post_ids'])) {
            $post_ids_placeholders = implode(',', array_fill(0, count($filters['post_ids']), '%d'));
            $where[] = "p.ID IN ($post_ids_placeholders)";
            $params = array_merge($params, $filters['post_ids']);
        }

        // Post type filter
        if (!empty($filters['post_type']) && 'all' !== $filters['post_type']) {
            $where[] = 'p.post_type = %s';
            $params[] = $filters['post_type'];
        }

        // Optimization status filter
        if (!empty($filters['status']) && 'all' !== $filters['status']) {
            if ('optimized' === $filters['status']) {
                $where[] = "o.status = 'approved'";
            } elseif ('pending' === $filters['status']) {
                $where[] = "o.status = 'pending'";
            } elseif ('never' === $filters['status']) {
                $where[] = "(o.status IS NULL OR o.status = '')";
            }
        }

        // Date filter - filter by optimization date OR post modification date
        // This ensures newly optimized posts show up even if the post itself is old
        if (!empty($filters['date']) && 'all' !== $filters['date']) {
            $date_threshold = $this->get_date_threshold_gmt($filters['date']);
            if ($date_threshold) {
                // Show posts that were either optimized OR modified in the date range
                $where[] = '(o.optimization_date >= %s OR p.post_modified_gmt >= %s)';
                $params[] = $date_threshold;
                $params[] = $date_threshold;
            }
        }

        $where_sql = implode(' AND ', $where);

        // Order by most recently modified first for relevancy
        $order_by = 'p.post_modified DESC';

        $params[] = $per_page;
        $params[] = $offset;

        $opt_join = $this->get_latest_optimization_join_sql( $opts_table );

        // Get posts with optimization status via LEFT JOIN
        $sql = "
            SELECT 
                p.ID,
                p.post_title,
                p.post_name,
                p.post_type,
                p.post_status,
                p.post_modified,
                o.status as opt_status,
                o.optimization_date as opt_date,
                o.primary_keyword as opt_primary_keyword,
                o.seo_title as opt_seo_title,
                o.meta_description as opt_meta_description
            FROM {$wpdb->posts} p
            {$opt_join}
            WHERE $where_sql
            ORDER BY $order_by
            LIMIT %d OFFSET %d
        ";
        
        // Only prepare if we have parameters
        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }
        
        return $wpdb->get_results($sql);
    }
    
    /**
     * Get total count of posts for pagination
     * 
     * @return int Total posts count
     */
    private function get_total_posts_count($filters = array()) {
        global $wpdb;
        $opts_table = $wpdb->prefix . 'mindfulseo_optimizations';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $opts_table)) !== $opts_table) {
            return 0;
        }
        
        // Get all public post types
        $post_types = get_post_types(array('public' => true), 'names');
        $post_types_list = "'" . implode("', '", array_map('esc_sql', $post_types)) . "'";

        $where = array(
            "p.post_status IN ('publish', 'draft', 'pending')",
            "p.post_type IN ($post_types_list)"
        );
        $params = array();

        // Post IDs filter (from SEO Audit auto-select)
        if (!empty($filters['post_ids']) && is_array($filters['post_ids'])) {
            $post_ids_placeholders = implode(',', array_fill(0, count($filters['post_ids']), '%d'));
            $where[] = "p.ID IN ($post_ids_placeholders)";
            $params = array_merge($params, $filters['post_ids']);
        }

        if (!empty($filters['post_type']) && 'all' !== $filters['post_type']) {
            $where[] = 'p.post_type = %s';
            $params[] = $filters['post_type'];
        }

        if (!empty($filters['status']) && 'all' !== $filters['status']) {
            if ('optimized' === $filters['status']) {
                $where[] = "o.status = 'approved'";
            } elseif ('pending' === $filters['status']) {
                $where[] = "o.status = 'pending'";
            } elseif ('never' === $filters['status']) {
                $where[] = "(o.status IS NULL OR o.status = '')";
            }
        }

        if (!empty($filters['date']) && 'all' !== $filters['date']) {
            $date_threshold = $this->get_date_threshold_gmt($filters['date']);
            if ($date_threshold) {
                $where[] = 'p.post_modified_gmt >= %s';
                $params[] = $date_threshold;
            }
        }

        $where_sql = implode(' AND ', $where);

        $opt_join = $this->get_latest_optimization_join_sql( $opts_table );

        $sql = "
            SELECT COUNT(*)
            FROM {$wpdb->posts} p
            {$opt_join}
            WHERE $where_sql
        ";
        
        // Only prepare if we have parameters
        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }
        
        return $wpdb->get_var($sql);
    }

    /**
     * Retrieve active filters from the current request
     *
     * @return array
     */
    private function get_active_filters() {
        $filters = array(
            'post_type' => isset($_GET['filter_post_type']) ? sanitize_key(wp_unslash($_GET['filter_post_type'])) : 'all',
            'status'    => isset($_GET['filter_status']) ? sanitize_key(wp_unslash($_GET['filter_status'])) : 'all',
            'date'      => isset($_GET['filter_date']) ? sanitize_key(wp_unslash($_GET['filter_date'])) : 'all', // Default to 'all' so new optimizations show
            'per_page'  => isset($_GET['per_page']) ? intval($_GET['per_page']) : 50,
        );

        // Validate post type
        $all_post_types = get_post_types(array('public' => true), 'names');
        if ('all' !== $filters['post_type'] && !in_array($filters['post_type'], $all_post_types, true)) {
            $filters['post_type'] = 'all';
        }

        // Validate status filter
        $allowed_status = array('all', 'optimized', 'pending', 'never');
        if (!in_array($filters['status'], $allowed_status, true)) {
            $filters['status'] = 'all';
        }

        // Validate date filter
        $allowed_dates = array('all', 'today', 'week', 'month', 'year');
        if (!in_array($filters['date'], $allowed_dates, true)) {
            $filters['date'] = 'all';
        }

        // Validate per page
        $allowed_per_page = array(25, 50, 100, 200);
        if (!in_array($filters['per_page'], $allowed_per_page, true)) {
            $filters['per_page'] = 50;
        }

        return $filters;
    }

    /**
     * Convert active filters to query args for pagination links
     *
     * @param array $filters Active filters
     * @return array
     */
    private function build_filter_query_args($filters) {
        $args = array(
            'page' => 'mindfulseo-batch-optimize',
        );

        if ('all' !== $filters['post_type']) {
            $args['filter_post_type'] = $filters['post_type'];
        }

        if ('all' !== $filters['status']) {
            $args['filter_status'] = $filters['status'];
        }

        if ('all' !== $filters['date']) {
            $args['filter_date'] = $filters['date'];
        }

        if (50 !== $filters['per_page']) {
            $args['per_page'] = $filters['per_page'];
        }

        return $args;
    }

    /**
     * Get GMT date string threshold for date filtering
     *
     * @param string $filter_key Date filter key
     * @return string|null
     */
    private function get_date_threshold_gmt($filter_key) {
        $allowed = array('today', 'week', 'month', 'year');
        if (!in_array($filter_key, $allowed, true)) {
            return null;
        }

        $timezone = wp_timezone();
        $now = new DateTime('now', $timezone);

        switch ($filter_key) {
            case 'today':
                $threshold = clone $now;
                $threshold->setTime(0, 0, 0);
                break;
            case 'week':
                $threshold = (clone $now)->modify('-7 days');
                break;
            case 'month':
                $threshold = (clone $now)->modify('-30 days');
                break;
            case 'year':
                $threshold = (clone $now)->modify('-1 year');
                break;
            default:
                $threshold = null;
        }

        if (!$threshold) {
            return null;
        }

        $threshold->setTimezone(new DateTimeZone('UTC'));
        return $threshold->format('Y-m-d H:i:s');
    }
}
