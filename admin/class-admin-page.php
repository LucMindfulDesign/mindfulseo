<?php
/**
 * Admin Page
 * 
 * Main admin interface for MindfulSEO
 * 
 * @package MindfulSEO
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_Admin_Page {
    
    /**
     * The single instance of the class
     * 
     * @var MFSEO_Admin_Page
     */
    private static $instance = null;
    
    /**
     * Hook suffixes for conditional asset loading
     * 
     * @var array
     */
    private $hook_suffixes = array();
    
    /**
     * Get instance
     */
    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // Menu registration is now handled by MFSEO_Admin class (v2.0)
        // Only register if MFSEO_Admin is not loaded (backward compatibility)
        if (!class_exists('MFSEO_Admin')) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        }
        
        // Keep save settings handler
        add_action('admin_post_mindfulseo_save_settings', array($this, 'save_settings'));
    }
    
    /**
     * Add admin menu
     * 
     * Pages ordered by workflow:
     * 1. Dashboard - Overview
     * 2. SEO Audit - Find issues
     * 3. Keyword Strategy - Plan keywords
     * 4. Language Guidelines - Set brand voice
     * 5. Batch Optimizer - Optimize posts
     * 6. Settings - API keys & config
     */
    public function add_admin_menu() {
        // Main menu page
        $this->hook_suffixes['dashboard'] = add_menu_page(
            __('MindfulSEO', 'mindfulseo'),
            __('MindfulSEO', 'mindfulseo'),
            'manage_options',
            'mindfulseo',
            array($this, 'render_dashboard_page'),
            'dashicons-search',
            30
        );
        
        // 1. Dashboard submenu (rename parent)
        add_submenu_page(
            'mindfulseo',
            __('Dashboard', 'mindfulseo'),
            __('Dashboard', 'mindfulseo'),
            'manage_options',
            'mindfulseo',
            array($this, 'render_dashboard_page')
        );
        
        // 2. SEO Audit - Find issues (most users start here)
        $this->hook_suffixes['seo_audit'] = add_submenu_page(
            'mindfulseo',
            __('SEO Audit', 'mindfulseo'),
            __('SEO Audit', 'mindfulseo'),
            'edit_posts',
            'mindfulseo-seo-audit',
            array($this, 'render_seo_audit_page')
        );
        
        // 3. Keyword Strategy - Plan keywords
        $this->hook_suffixes['keywords'] = add_submenu_page(
            'mindfulseo',
            __('Keyword Strategy', 'mindfulseo'),
            __('Keyword Strategy', 'mindfulseo'),
            'manage_options',
            'mindfulseo-keywords',
            array($this, 'render_keywords_page')
        );
        
        // 4. Language Guidelines - Set brand voice
        $this->hook_suffixes['guidelines'] = add_submenu_page(
            'mindfulseo',
            __('Language Guidelines', 'mindfulseo'),
            __('Language Guidelines', 'mindfulseo'),
            'manage_options',
            'mindfulseo-guidelines',
            array($this, 'render_guidelines_page')
        );
        
        // 5. Batch Optimizer - Optimize posts
        $this->hook_suffixes['batch_optimize'] = add_submenu_page(
            'mindfulseo',
            __('Batch Optimizer', 'mindfulseo'),
            __('Batch Optimizer', 'mindfulseo'),
            'edit_posts',
            'mindfulseo-batch-optimize',
            array($this, 'render_batch_optimize_page')
        );
        
        // 6. Settings - API keys & config (setup, not daily use)
        $this->hook_suffixes['settings'] = add_submenu_page(
            'mindfulseo',
            __('Settings', 'mindfulseo'),
            __('Settings', 'mindfulseo'),
            'manage_options',
            'mindfulseo-settings',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our admin pages
        if (!in_array($hook, $this->hook_suffixes)) {
            return;
        }
        
        // Debug: Add HTML comment to show which hook we're on
        add_action('admin_print_footer_scripts', function() use ($hook) {
            echo "<!-- MindfulSEO Debug: Current hook = " . esc_html($hook) . " -->\n";
            echo "<!-- MindfulSEO Debug: Batch optimize hook = " . esc_html($this->hook_suffixes['batch_optimize']) . " -->\n";
        }, 99);
        
        // Enqueue WordPress color picker
        wp_enqueue_style('wp-color-picker');
        
        // Enqueue admin CSS
        wp_enqueue_style(
            'mindfulseo-admin',
            MINDFULSEO_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            MINDFULSEO_VERSION
        );
        
        // Enqueue admin JS
        wp_enqueue_script(
            'mindfulseo-admin',
            MINDFULSEO_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery', 'wp-color-picker'),
            MINDFULSEO_VERSION,
            true
        );
        
        // Localize script with AJAX data
        wp_localize_script('mindfulseo-admin', 'mindfulseoAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mindfulseo_admin'),
            'nonces' => array(
                'test_api' => wp_create_nonce('mindfulseo_test_api'),
                'autogenerate' => wp_create_nonce('mindfulseo_autogenerate'),
                'inline_edit' => wp_create_nonce('mindfulseo_inline_edit'),
                'cleanup' => wp_create_nonce('mindfulseo_cleanup'),
                'refresh_seo_data' => wp_create_nonce('mindfulseo_refresh_seo_data'),
                'ajax_nonce' => wp_create_nonce('mindfulseo_ajax_nonce'),
            ),
            'strings' => array(
                'saving' => __('Saving...', 'mindfulseo'),
                'saved' => __('Settings saved!', 'mindfulseo'),
                'error' => __('Error saving settings.', 'mindfulseo'),
                'updating' => __('Updating...', 'mindfulseo'),
                'updated' => __('Updated!', 'mindfulseo'),
            ),
        ));
        
        // Enqueue batch optimizer JS on batch optimize page
        if ($hook === $this->hook_suffixes['batch_optimize']) {
            $batch_optimizer_version = MINDFULSEO_VERSION . '-v2'; // Force reload
            $batch_optimizer_path = MINDFULSEO_PLUGIN_DIR . 'assets/js/batch-optimizer.js';

            // PHP 8.x: Ensure path is valid string before file_exists
            if (is_string($batch_optimizer_path) && !empty($batch_optimizer_path) && file_exists($batch_optimizer_path)) {
                $batch_optimizer_version .= '-' . filemtime($batch_optimizer_path);
            }

            wp_enqueue_script('jquery-ui-sortable');
            wp_enqueue_script(
                'mindfulseo-batch-optimizer',
                MINDFULSEO_PLUGIN_URL . 'assets/js/batch-optimizer.js',
                array('jquery', 'jquery-ui-sortable'),
                $batch_optimizer_version,
                true
            );
            
            wp_localize_script('mindfulseo-batch-optimizer', 'mfseoBatchOptimizer', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mindfulseo_batch_optimize'),
                'ajaxNonce' => wp_create_nonce('mindfulseo_ajax_nonce'),
                'inlineEditNonce' => wp_create_nonce('mindfulseo_inline_edit'),
                'baseUrl' => admin_url('admin.php'),
                'pageSlug' => 'mindfulseo-batch-optimize',
                'defaultPerPage' => 50,
                'i18n' => array(
                    'postSelectedSingular' => __('post selected', 'mindfulseo'),
                    'postSelectedPlural' => __('posts selected', 'mindfulseo'),
                    'showCustomPrompts' => __('Show', 'mindfulseo'),
                    'hideCustomPrompts' => __('Hide', 'mindfulseo'),
                ),
            ));
        }
    }
    
    /**
     * Get branding header HTML - WooCommerce Style (public for reuse by SEO Audit etc.)
     */
    public function get_branding_header($title = 'MindfulSEO') {
        $icon_url = MINDFULSEO_PLUGIN_URL . 'assets/icon-gold.svg?v=' . MINDFULSEO_VERSION;
        $logo_url = MINDFULSEO_PLUGIN_URL . 'assets/logo-white.svg?v=' . MINDFULSEO_VERSION . '&t=' . time();
        $version = MINDFULSEO_VERSION;
        
        ob_start();
        ?>
        <div class="mindfulseo-branding-header">
            <div class="mindfulseo-brand-content">
                <img src="<?php echo esc_url($icon_url); ?>" 
                     alt="Mindful Design Icon" 
                     style="height: 40px; width: auto;"
                     onerror="this.style.display='none';">
                <div class="mindfulseo-brand-text">
                    <h1><?php echo esc_html($title); ?></h1>
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
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render dashboard page
     */
    public function render_dashboard_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'mindfulseo'));
        }

        global $wpdb;
        $settings    = get_option('mindfulseo_settings', array());
        $logger      = class_exists('MFSEO_Logger') ? MFSEO_Logger::get_instance() : null;
        $seo_adapter = class_exists('MFSEO_SEO_Plugin_Adapter') ? MFSEO_SEO_Plugin_Adapter::get_instance() : null;
        $stats       = $logger ? $logger->get_api_stats('month') : array();

        $is_rankmath    = $seo_adapter && $seo_adapter->is_seo_plugin_active() && $seo_adapter->get_active_plugin() === 'rankmath';
        $meta_desc_key  = $is_rankmath ? 'rank_math_description' : '_yoast_wpseo_metadesc';
        $focus_key      = $is_rankmath ? 'rank_math_focus_keyword' : '_yoast_wpseo_focuskw';

        $total_posts = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
            'post', 'publish'
        ) );

        $optimized = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = %s AND pm1.meta_value != ''
             INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = %s AND pm2.meta_value != ''
             WHERE p.post_type = %s AND p.post_status = %s",
            $focus_key, $meta_desc_key, 'post', 'publish'
        ) );

        $no_meta_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(p.ID) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = %s AND p.post_status = %s
             AND (pm.meta_value IS NULL OR pm.meta_value = '')",
            $meta_desc_key, 'post', 'publish'
        ) );

        $no_kw_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(p.ID) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = %s AND p.post_status = %s
             AND (pm.meta_value IS NULL OR pm.meta_value = '')",
            $focus_key, 'post', 'publish'
        ) );

        $thin_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = %s AND post_status = %s AND CHAR_LENGTH(post_content) < 1000",
            'post', 'publish'
        ) );

        $title_key = $is_rankmath ? 'rank_math_title' : '_yoast_wpseo_title';
        $title_long_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = %s AND p.post_status = %s
             AND LENGTH(COALESCE(pm.meta_value, p.post_title)) > 60",
            $title_key, 'post', 'publish'
        ) );

        $score_pct   = $total_posts > 0 ? (int) round( ( $optimized / $total_posts ) * 100 ) : 100;
        $score_class = $score_pct >= 70 ? 'good' : ( $score_pct >= 40 ? 'warning' : 'error' );

        $api_status = $this->get_live_api_status( $settings );
        $openai_status = $api_status['openai'];
        $claude_status = $api_status['claude'];
        $dfs_ok        = ! empty( $settings['dataforseo_login'] ) && ! empty( $settings['dataforseo_password'] );

        settings_errors();
        echo $this->get_branding_header();
        ?>
        <div class="wrap mindfulseo-page">
            <div class="mindfulseo-content">

                <div class="mfseo-dash-hero">
                    <div class="mfseo-dash-hero__score">
                        <svg class="mfseo-ring" viewBox="0 0 120 120">
                            <circle cx="60" cy="60" r="52" fill="none" stroke="#e9ecef" stroke-width="10"/>
                            <circle cx="60" cy="60" r="52" fill="none"
                                    stroke="<?php echo $score_class === 'good' ? '#46b450' : ( $score_class === 'warning' ? '#f0b849' : '#dc3232' ); ?>"
                                    stroke-width="10" stroke-linecap="round"
                                    stroke-dasharray="<?php echo round( 326.73 * $score_pct / 100 ); ?> 326.73"
                                    transform="rotate(-90 60 60)"/>
                        </svg>
                        <div class="mfseo-ring__value"><?php echo esc_html( $score_pct ); ?>%</div>
                        <div class="mfseo-ring__label"><?php _e( 'SEO Health', 'mindfulseo' ); ?></div>
                    </div>

                    <div class="mfseo-dash-hero__stats">
                        <?php
                        $by_provider = isset( $stats['by_provider'] ) ? $stats['by_provider'] : array();
                        $dfs_data    = isset( $by_provider['dataforseo'] ) ? $by_provider['dataforseo'] : array();
                        $dfs_cost    = isset( $dfs_data['cost'] ) ? (float) $dfs_data['cost'] : 0;
                        $dfs_calls   = isset( $dfs_data['calls'] ) ? (int) $dfs_data['calls'] : 0;
                        $total_calls = isset( $stats['total_calls'] ) ? (int) $stats['total_calls'] : 0;
                        $ai_calls    = max( 0, $total_calls - $dfs_calls );
                        $ai_tokens   = isset( $stats['total_tokens'] ) ? $stats['total_tokens'] : 0;
                        $ai_cost     = isset( $stats['total_cost'] ) ? $stats['total_cost'] - $dfs_cost : 0;
                        ?>
                        <div class="mfseo-dash-pill">
                            <span class="mfseo-dash-pill__val"><?php echo number_format( $ai_calls ); ?></span>
                            <span class="mfseo-dash-pill__lbl"><?php _e( 'AI Calls (30d)', 'mindfulseo' ); ?></span>
                        </div>
                        <div class="mfseo-dash-pill">
                            <span class="mfseo-dash-pill__val">$<?php echo number_format( max( 0, $ai_cost ), 2 ); ?></span>
                            <span class="mfseo-dash-pill__lbl"><?php _e( 'AI Cost (30d)', 'mindfulseo' ); ?></span>
                        </div>
                        <div class="mfseo-dash-pill">
                            <span class="mfseo-dash-pill__val">$<?php echo number_format( $dfs_cost, 2 ); ?></span>
                            <span class="mfseo-dash-pill__lbl"><?php _e( 'DataForSEO (30d)', 'mindfulseo' ); ?></span>
                        </div>
                        <a href="<?php echo esc_url( admin_url('admin.php?page=mindfulseo-settings&tab=usage') ); ?>" class="mfseo-dash-pill mfseo-dash-pill--link" style="text-decoration:none;">
                            <span class="mfseo-dash-pill__val"><span class="dashicons dashicons-chart-bar" style="font-size:16px;width:16px;height:16px;vertical-align:middle;"></span></span>
                            <span class="mfseo-dash-pill__lbl"><?php _e( 'Full Usage', 'mindfulseo' ); ?></span>
                        </a>
                    </div>
                </div>

                <h2 class="mfseo-dash-section-title"><?php _e( 'Content Health', 'mindfulseo' ); ?></h2>
                <div class="mfseo-dash-health-grid">
                    <?php
                    $batch_url = admin_url( 'admin.php?page=mindfulseo-batch-optimize' );
                    $health_cards = array(
                        array(
                            'count' => $optimized,
                            'total' => $total_posts,
                            'label' => __( 'Posts Optimized', 'mindfulseo' ),
                            'color' => 'good',
                            'link'  => $total_posts > 0 ? $batch_url : '',
                            'link_text' => __( 'Continue fixing', 'mindfulseo' ),
                        ),
                        array(
                            'count' => $no_meta_count,
                            'total' => $total_posts,
                            'label' => __( 'Missing Meta Description', 'mindfulseo' ),
                            'color' => $no_meta_count > 0 ? 'error' : 'good',
                            'link'  => $no_meta_count > 0 ? admin_url( 'admin.php?page=mindfulseo-seo-audit' ) : '',
                        ),
                        array(
                            'count' => $no_kw_count,
                            'total' => $total_posts,
                            'label' => __( 'Missing Focus Keyword', 'mindfulseo' ),
                            'color' => $no_kw_count > 0 ? 'error' : 'good',
                            'link'  => $no_kw_count > 0 ? admin_url( 'admin.php?page=mindfulseo-seo-audit' ) : '',
                        ),
                        array(
                            'count' => $title_long_count,
                            'total' => $total_posts,
                            'label' => __( 'Title Too Long', 'mindfulseo' ),
                            'color' => $title_long_count > 0 ? 'warning' : 'good',
                            'link'  => $title_long_count > 0 ? admin_url( 'admin.php?page=mindfulseo-seo-audit' ) : '',
                        ),
                        array(
                            'count' => $thin_count,
                            'total' => $total_posts,
                            'label' => __( 'Thin Content', 'mindfulseo' ),
                            'color' => $thin_count > 0 ? 'warning' : 'good',
                            'link'  => $thin_count > 0 ? admin_url( 'admin.php?page=mindfulseo-seo-audit' ) : '',
                        ),
                    );
                    foreach ( $health_cards as $hc ) : ?>
                        <div class="mfseo-health-card mfseo-health-card--<?php echo esc_attr( $hc['color'] ); ?>">
                            <div class="mfseo-health-card__count"><?php echo number_format( $hc['count'] ); ?></div>
                            <div class="mfseo-health-card__label"><?php echo esc_html( $hc['label'] ); ?></div>
                            <?php if ( $hc['total'] > 0 && $hc['count'] !== $hc['total'] ) : ?>
                                <div class="mfseo-health-card__of"><?php printf( __( 'of %s posts', 'mindfulseo' ), number_format( $hc['total'] ) ); ?></div>
                            <?php endif; ?>
                            <?php if ( $hc['link'] ) : ?>
                                <a href="<?php echo esc_url( $hc['link'] ); ?>" class="mfseo-health-card__action"><?php echo esc_html( isset( $hc['link_text'] ) ? $hc['link_text'] : __( 'Fix', 'mindfulseo' ) ); ?> &rarr;</a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php
                $strategy_links = array(
                    array(
                        'url'   => admin_url('admin.php?page=mindfulseo-keywords'),
                        'icon'  => 'dashicons-tag',
                        'title' => __('Keyword Strategy', 'mindfulseo'),
                        'desc'  => __('Manage target keywords', 'mindfulseo'),
                        'color' => '#00a32a',
                    ),
                    array(
                        'url'   => admin_url('admin.php?page=mindfulseo-guidelines'),
                        'icon'  => 'dashicons-book',
                        'title' => __('Language Guidelines', 'mindfulseo'),
                        'desc'  => __('Brand voice & terminology', 'mindfulseo'),
                        'color' => '#9b59b6',
                    ),
                    array(
                        'url'   => admin_url('admin.php?page=mindfulseo-settings'),
                        'icon'  => 'dashicons-admin-settings',
                        'title' => __('Settings', 'mindfulseo'),
                        'desc'  => __('API keys & configuration', 'mindfulseo'),
                        'color' => '#50575e',
                    ),
                );
                $action_links = array(
                    array(
                        'url'   => admin_url('admin.php?page=mindfulseo-seo-audit'),
                        'icon'  => 'dashicons-chart-bar',
                        'title' => __('SEO Audit', 'mindfulseo'),
                        'desc'  => __('Find & fix SEO issues', 'mindfulseo'),
                        'color' => '#dc3232',
                    ),
                    array(
                        'url'   => admin_url('admin.php?page=mindfulseo-batch-optimize'),
                        'icon'  => 'dashicons-admin-generic',
                        'title' => __('Batch Optimizer', 'mindfulseo'),
                        'desc'  => __('AI-optimize multiple posts', 'mindfulseo'),
                        'color' => '#2271b1',
                    ),
                    array(
                        'url'   => admin_url('admin.php?page=mindfulseo-content-hub'),
                        'icon'  => 'dashicons-networking',
                        'title' => __('Content Hub', 'mindfulseo'),
                        'desc'  => __('Topics & content health', 'mindfulseo'),
                        'color' => '#8c5e1b',
                    ),
                );
                ?>
                <div class="mfseo-dash-quicklinks-2col">
                    <div class="mfseo-dash-quicklinks-col">
                        <h3 class="mfseo-dash-col-heading"><?php _e('Strategy & Config', 'mindfulseo'); ?></h3>
                        <?php foreach ($strategy_links as $link) : ?>
                        <a href="<?php echo esc_url($link['url']); ?>" class="mfseo-dash-qlink">
                            <span class="mfseo-dash-qlink__icon dashicons <?php echo esc_attr($link['icon']); ?>" style="color:<?php echo esc_attr($link['color']); ?>;"></span>
                            <span class="mfseo-dash-qlink__text">
                                <strong><?php echo esc_html($link['title']); ?></strong>
                                <small><?php echo esc_html($link['desc']); ?></small>
                            </span>
                            <span class="mfseo-dash-qlink__arrow dashicons dashicons-arrow-right-alt2"></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="mfseo-dash-quicklinks-col">
                        <h3 class="mfseo-dash-col-heading"><?php _e('Content Actions', 'mindfulseo'); ?></h3>
                        <?php foreach ($action_links as $link) : ?>
                        <a href="<?php echo esc_url($link['url']); ?>" class="mfseo-dash-qlink">
                            <span class="mfseo-dash-qlink__icon dashicons <?php echo esc_attr($link['icon']); ?>" style="color:<?php echo esc_attr($link['color']); ?>;"></span>
                            <span class="mfseo-dash-qlink__text">
                                <strong><?php echo esc_html($link['title']); ?></strong>
                                <small><?php echo esc_html($link['desc']); ?></small>
                            </span>
                            <span class="mfseo-dash-qlink__arrow dashicons dashicons-arrow-right-alt2"></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mfseo-dash-status-bar">
                    <span class="mfseo-dash-status-bar__title"><?php _e('System Status', 'mindfulseo'); ?></span>
                    <span class="mfseo-dash-status-pill <?php echo ($seo_adapter && $seo_adapter->is_seo_plugin_active()) ? 'mfseo-pill--ok' : 'mfseo-pill--err'; ?>">
                        <?php
                        if ($seo_adapter && $seo_adapter->is_seo_plugin_active()) {
                            echo esc_html($seo_adapter->get_active_plugin() === 'rankmath' ? 'Rank Math' : 'Yoast SEO');
                        } else {
                            _e('No SEO plugin', 'mindfulseo');
                        }
                        ?>
                    </span>
                    <span class="mfseo-dash-status-pill <?php echo $openai_status === 'ok' ? 'mfseo-pill--ok' : ( $openai_status === 'no_key' ? 'mfseo-pill--neutral' : 'mfseo-pill--err' ); ?>">
                        <?php _e('OpenAI', 'mindfulseo'); ?>
                    </span>
                    <span class="mfseo-dash-status-pill <?php echo $claude_status === 'ok' ? 'mfseo-pill--ok' : ( $claude_status === 'no_key' ? 'mfseo-pill--neutral' : 'mfseo-pill--err' ); ?>">
                        <?php _e('Claude', 'mindfulseo'); ?>
                    </span>
                    <span class="mfseo-dash-status-pill <?php echo $dfs_ok ? 'mfseo-pill--ok' : 'mfseo-pill--neutral'; ?>">
                        <?php _e('DataForSEO', 'mindfulseo'); ?>
                    </span>
                </div>

            </div>
        </div>
        <?php
    }

    /**
     * Live API status check with 5-minute caching.
     * Returns 'ok', 'error', or 'no_key' for each provider.
     */
    private function get_live_api_status( $settings ) {
        $cache_key = 'mfseo_api_status_live';
        $cached = get_transient( $cache_key );

        $openai_enc = isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';
        $claude_enc = isset( $settings['claude_api_key'] ) ? $settings['claude_api_key'] : '';

        $fingerprint = md5( $openai_enc . '|' . $claude_enc );

        if ( is_array( $cached ) && isset( $cached['fingerprint'] ) && $cached['fingerprint'] === $fingerprint ) {
            return $cached;
        }

        $ai = MFSEO_AI_Connector::get_instance();
        $openai_key = ! empty( $openai_enc ) ? $ai->decrypt_api_key( $openai_enc ) : '';
        $claude_key = ! empty( $claude_enc ) ? $ai->decrypt_api_key( $claude_enc ) : '';

        $result = array( 'openai' => 'no_key', 'claude' => 'no_key', 'fingerprint' => $fingerprint );

        if ( ! empty( $openai_key ) ) {
            if ( class_exists( 'MFSEO_API_Tester' ) ) {
                $test = MFSEO_API_Tester::test_openai_connection( $openai_key );
                $result['openai'] = is_wp_error( $test ) ? 'error' : 'ok';
            } else {
                $result['openai'] = 'ok';
            }
        }

        if ( ! empty( $claude_key ) ) {
            if ( class_exists( 'MFSEO_API_Tester' ) ) {
                $test = MFSEO_API_Tester::test_claude_connection( $claude_key );
                $result['claude'] = is_wp_error( $test ) ? 'error' : 'ok';
            } else {
                $result['claude'] = 'ok';
            }
        }

        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
        return $result;
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'mindfulseo'));
        }
        
        $settings = MindfulSEO::get_settings();
        $active_tab = isset($_GET['tab']) && $_GET['tab'] === 'usage' ? 'usage' : 'settings';
        $mfseo_settings_main_ai = (! empty($settings['ai_backend']) && $settings['ai_backend'] === 'openrouter')
            ? 'openrouter'
            : (in_array(isset($settings['primary_provider']) ? $settings['primary_provider'] : '', array('openai', 'claude'), true)
                ? $settings['primary_provider']
                : 'openai');
        
        ?>
        <!-- Output WordPress notices OUTSIDE and BEFORE the wrap div -->
        <?php settings_errors(); ?>
        
        <?php echo $this->get_branding_header('MindfulSEO Settings'); ?>
        
        <div class="wrap mindfulseo-settings-wrap">
            <!-- Quick Start Guide - Collapsible -->
            <div class="quick-start-box-wrapper" id="mindfulseo-settings-quickstart">
                <div>
                    <button type="button" class="quick-start-toggle mindfulseo-quick-start-toggle">
                        <span>🚀 <?php _e('Quick Start Guide', 'mindfulseo'); ?></span>
                        <span class="dashicons dashicons-arrow-down-alt2"></span>
                    </button>
                    <a href="#" class="quick-start-dismiss" data-guide="settings"><?php _e('Dismiss', 'mindfulseo'); ?></a>
                </div>
                <div class="quick-start-content mindfulseo-quick-start-content">
                    <ol style="margin: 0; padding-left: 20px; line-height: 1.8;">
                        <li style="margin-bottom: 8px;">
                            <strong><?php _e('Add API Keys:', 'mindfulseo'); ?></strong> 
                            <?php _e('Enter your OpenAI and/or Claude API keys below', 'mindfulseo'); ?>
                        </li>
                        <li style="margin-bottom: 8px;">
                            <strong><?php _e('Test Connections:', 'mindfulseo'); ?></strong> 
                            <?php _e('Click "Test Connection" next to each key to verify it works', 'mindfulseo'); ?>
                        </li>
                        <li style="margin-bottom: 8px;">
                            <strong><?php _e('Choose Models:', 'mindfulseo'); ?></strong> 
                            <?php _e('Select which AI models to use (GPT-5 or Claude Sonnet 4.5 recommended - latest releases)', 'mindfulseo'); ?>
                        </li>
                        <li style="margin-bottom: 8px;">
                            <strong><?php _e('Save Settings:', 'mindfulseo'); ?></strong> 
                            <?php _e('Click "Save Settings" at the bottom', 'mindfulseo'); ?>
                        </li>
                        <li>
                            <strong><?php _e('Next Steps:', 'mindfulseo'); ?></strong> 
                            <?php _e('Go to Keyword Strategy or Language Guidelines to set up your SEO foundation', 'mindfulseo'); ?>
                        </li>
                    </ol>
                    <p style="margin-bottom: 0; margin-top: 15px;">
                        <strong><?php _e('💡 Tip:', 'mindfulseo'); ?></strong> 
                        <?php _e('You only need ONE API key (either OpenAI or Claude) to get started. Having both provides automatic fallback if one fails.', 'mindfulseo'); ?>
                    </p>
                </div>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                // Check if guide was dismissed
                var isDismissed = localStorage.getItem('mindfulSEOQuickStartDismissed') === 'true';
                if (isDismissed) {
                    $('#mindfulseo-settings-quickstart').hide();
                    return;
                }
                
                // Load collapsed state from localStorage
                var isCollapsed = localStorage.getItem('mindfulSEOQuickStartCollapsed') !== 'false';
                if (!isCollapsed) {
                    $('.mindfulseo-quick-start-content').show();
                    $('.mindfulseo-quick-start-toggle').addClass('active');
                }
                
                // Toggle collapse
                $('.mindfulseo-quick-start-toggle').on('click', function(e) {
                    var $toggle = $(this);
                    var $content = $('.mindfulseo-quick-start-content');
                    
                    $content.slideToggle(300);
                    $toggle.toggleClass('active');
                    
                    // Save state to localStorage
                    localStorage.setItem('mindfulSEOQuickStartCollapsed', $content.is(':hidden'));
                });
                
                // Handle dismiss
                $('.quick-start-dismiss[data-guide="settings"]').on('click', function(e) {
                    e.preventDefault();
                    localStorage.setItem('mindfulSEOQuickStartDismissed', 'true');
                    $('#mindfulseo-settings-quickstart').slideUp(300);
                });
            });
            </script>
            
            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible" style="margin: 20px 20px 0 0;">
                    <p><?php _e('Settings saved successfully!', 'mindfulseo'); ?></p>
                </div>
            <?php endif; ?>

            <!-- Settings / Usage tab nav -->
            <nav class="mfseo-tabs" style="margin: 20px 20px 0 0;">
                <a href="<?php echo esc_url( admin_url('admin.php?page=mindfulseo-settings&tab=settings') ); ?>"
                   class="mfseo-tabs__tab <?php echo $active_tab === 'settings' ? 'active' : ''; ?>">
                    <?php _e('Settings', 'mindfulseo'); ?>
                </a>
                <a href="<?php echo esc_url( admin_url('admin.php?page=mindfulseo-settings&tab=usage') ); ?>"
                   class="mfseo-tabs__tab <?php echo $active_tab === 'usage' ? 'active' : ''; ?>">
                    <?php _e('API Usage', 'mindfulseo'); ?>
                </a>
            </nav>

            <?php if ($active_tab === 'settings') : ?>
            <div style="background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; margin: 0 20px 0 0;">
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="mindfulseo-settings-form">
                    <?php wp_nonce_field('mindfulseo_save_settings', 'mindfulseo_nonce'); ?>
                    <input type="hidden" name="action" value="mindfulseo_save_settings">
                    
                    <table class="form-table">
                        <tr>
                            <th colspan="2">
                                <h2><?php _e('AI Provider Settings', 'mindfulseo'); ?></h2>
                                <p style="margin: 10px 0 0 0; font-size: 14px; color: #666; font-weight: normal;">
                                    <?php _e('Configure your AI provider credentials to enable automated SEO content generation, keyword analysis, and brand-aware content optimization. At least one API key (OpenAI or Claude) is required.', 'mindfulseo'); ?>
                                </p>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="ai_backend"><?php _e('AI connection', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <select name="ai_backend" id="mfseo-ai-backend">
                                    <option value="direct" <?php selected(isset($settings['ai_backend']) ? $settings['ai_backend'] : 'direct', 'direct'); ?>>
                                        <?php _e('Direct — OpenAI & Claude APIs', 'mindfulseo'); ?>
                                    </option>
                                    <option value="openrouter" <?php selected(isset($settings['ai_backend']) ? $settings['ai_backend'] : 'direct', 'openrouter'); ?>>
                                        <?php _e('OpenRouter (many models, one key)', 'mindfulseo'); ?>
                                    </option>
                                </select>
                                <p class="description mfseo-backend-desc-direct"><?php _e('Connect using your OpenAI and Claude API keys. Choose which provider is primary below.', 'mindfulseo'); ?></p>
                                <p class="description mfseo-backend-desc-or" style="display:none;"><?php _e('Traffic goes to OpenRouter first (Qwen, MiniMax, etc.). Optional OpenAI and Claude keys below are only used if OpenRouter fails and fallback is enabled—expand the section to configure them.', 'mindfulseo'); ?></p>
                            </td>
                        </tr>

                        <tr class="mfseo-openrouter-only">
                            <th scope="row">
                                <label for="openrouter_api_key"><?php _e('OpenRouter API Key', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                    <input type="password" id="openrouter_api_key" name="openrouter_api_key" class="regular-text"
                                           value="<?php echo esc_attr(!empty($settings['openrouter_api_key']) ? '••••••••••••••••' : ''); ?>"
                                           placeholder="sk-or-...">
                                    <button type="button" id="test-openrouter-connection" class="button">
                                        <span class="dashicons dashicons-admin-plugins" style="vertical-align:middle;margin-top:3px;"></span>
                                        <?php _e('Test Connection', 'mindfulseo'); ?>
                                    </button>
                                    <span id="openrouter-status-indicator"></span>
                                </div>
                                <p class="description"><a href="https://openrouter.ai/keys" target="_blank" rel="noopener">openrouter.ai/keys</a></p>
                            </td>
                        </tr>

                        <tr class="mfseo-openrouter-only">
                            <th scope="row"><label for="openrouter_http_referer"><?php _e('OpenRouter HTTP-Referer', 'mindfulseo'); ?></label></th>
                            <td>
                                <input type="url" class="regular-text" id="openrouter_http_referer" name="openrouter_http_referer"
                                       value="<?php echo esc_attr(isset($settings['openrouter_http_referer']) ? $settings['openrouter_http_referer'] : ''); ?>"
                                       placeholder="<?php echo esc_attr(home_url('/')); ?>">
                                <p class="description"><?php _e('Optional. Defaults to this site URL for OpenRouter attribution.', 'mindfulseo'); ?></p>
                            </td>
                        </tr>

                        <tr class="mfseo-openrouter-only mfseo-or-fallback-intro">
                            <td colspan="2" style="padding-top:0;">
                                <details id="mfseo-openrouter-fallback-details" class="mfseo-fallback-details" style="margin:4px 0 8px;">
                                    <summary style="cursor:pointer;font-weight:600;"><?php esc_html_e('Optional: OpenAI & Claude for automatic fallback', 'mindfulseo'); ?></summary>
                                    <p class="description" style="margin:8px 0 0;"><?php esc_html_e('If OpenRouter returns an error, MindfulSEO can retry with these direct APIs (same as “Direct” mode). Keys are optional but required for fallback to work.', 'mindfulseo'); ?></p>
                                </details>
                            </td>
                        </tr>


                        <tr class="mfseo-direct-cred-tr">
                            <th scope="row">
                                <label for="openai_api_key"><?php _e('OpenAI API Key', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                    <input type="password" 
                                           id="openai_api_key" 
                                           name="openai_api_key" 
                                           value="<?php echo esc_attr(isset($settings['openai_api_key']) ? '••••••••••••••••' : ''); ?>" 
                                           class="regular-text"
                                           placeholder="sk-...">
                                    <button type="button" id="test-openai-connection" class="button">
                                        <span class="dashicons dashicons-admin-plugins" style="vertical-align: middle; margin-top: 3px;"></span>
                                        <?php _e('Test Connection', 'mindfulseo'); ?>
                                    </button>
                                    <span id="openai-status-indicator"></span>
                                </div>
                                <p class="description">
                                    <?php _e('Get your API key from', 'mindfulseo'); ?> 
                                    <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com/api-keys</a>
                                </p>
                            </td>
                        </tr>
                        
                        <tr class="mfseo-direct-cred-tr">
                            <th scope="row">
                                <label for="openai_model"><?php _e('OpenAI Model', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <select id="openai_model" name="openai_model">
                                    <optgroup label="── GPT-4o Series (Recommended) ──">
                                        <option value="gpt-4o" <?php selected(isset($settings['openai_model']) ? $settings['openai_model'] : 'gpt-4o', 'gpt-4o'); ?>>
                                            gpt-4o — Latest flagship, fast &amp; multimodal
                                        </option>
                                        <option value="gpt-4o-mini" <?php selected(isset($settings['openai_model']) ? $settings['openai_model'] : '', 'gpt-4o-mini'); ?>>
                                            gpt-4o-mini — Budget-friendly, fast (great for testing)
                                        </option>
                                    </optgroup>
                                    <optgroup label="── GPT-4 Series ──">
                                        <option value="gpt-4-turbo" <?php selected(isset($settings['openai_model']) ? $settings['openai_model'] : '', 'gpt-4-turbo'); ?>>
                                            gpt-4-turbo — 128K context, vision capable
                                        </option>
                                        <option value="gpt-4" <?php selected(isset($settings['openai_model']) ? $settings['openai_model'] : '', 'gpt-4'); ?>>
                                            gpt-4 — Classic, highly reliable
                                        </option>
                                    </optgroup>
                                    <optgroup label="── Budget ──">
                                        <option value="gpt-3.5-turbo" <?php selected(isset($settings['openai_model']) ? $settings['openai_model'] : '', 'gpt-3.5-turbo'); ?>>
                                            gpt-3.5-turbo — Cheapest, lower quality
                                        </option>
                                    </optgroup>
                                </select>
                                <p class="description"><?php _e('The exact model ID shown is what gets sent to the OpenAI API. Default: gpt-4o.', 'mindfulseo'); ?></p>
                            </td>
                        </tr>
                        
                        <tr class="mfseo-direct-cred-tr">
                            <th scope="row">
                                <label for="claude_api_key"><?php _e('Claude API Key', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                    <input type="password" 
                                           id="claude_api_key" 
                                           name="claude_api_key" 
                                           value="<?php echo esc_attr(isset($settings['claude_api_key']) ? '••••••••••••••••' : ''); ?>" 
                                           class="regular-text"
                                           placeholder="sk-ant-...">
                                    <button type="button" id="test-claude-connection" class="button">
                                        <span class="dashicons dashicons-admin-plugins" style="vertical-align: middle; margin-top: 3px;"></span>
                                        <?php _e('Test Connection', 'mindfulseo'); ?>
                                    </button>
                                    <span id="claude-status-indicator"></span>
                                </div>
                                <p class="description">
                                    <?php _e('Get your API key from', 'mindfulseo'); ?> 
                                    <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a>
                                </p>
                            </td>
                        </tr>
                        
                        <tr class="mfseo-direct-cred-tr">
                            <th scope="row">
                                <label for="claude_model"><?php _e('Claude Model', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <select id="claude_model" name="claude_model">
                                    <optgroup label="── Claude 4 Series (Recommended) ──">
                                        <option value="claude-sonnet-4-5" <?php selected(isset($settings['claude_model']) ? $settings['claude_model'] : 'claude-sonnet-4-5', 'claude-sonnet-4-5'); ?>>
                                            claude-sonnet-4-5 — Best balance, quality SEO content
                                        </option>
                                        <option value="claude-haiku-4-5" <?php selected(isset($settings['claude_model']) ? $settings['claude_model'] : '', 'claude-haiku-4-5'); ?>>
                                            claude-haiku-4-5 — Fast &amp; cheap (great for testing)
                                        </option>
                                        <option value="claude-opus-4-1" <?php selected(isset($settings['claude_model']) ? $settings['claude_model'] : '', 'claude-opus-4-1'); ?>>
                                            claude-opus-4-1 — Most powerful, highest cost
                                        </option>
                                    </optgroup>
                                    <optgroup label="── Claude 3.5 Series (Legacy) ──">
                                        <option value="claude-3-5-sonnet-20241022" <?php selected(isset($settings['claude_model']) ? $settings['claude_model'] : '', 'claude-3-5-sonnet-20241022'); ?>>
                                            claude-3-5-sonnet-20241022 — Legacy, Oct 2024
                                        </option>
                                        <option value="claude-3-5-haiku-20241022" <?php selected(isset($settings['claude_model']) ? $settings['claude_model'] : '', 'claude-3-5-haiku-20241022'); ?>>
                                            claude-3-5-haiku-20241022 — Legacy Haiku, Oct 2024
                                        </option>
                                    </optgroup>
                                    <optgroup label="── Claude 3 Series (Legacy) ──">
                                        <option value="claude-3-opus-20240229" <?php selected(isset($settings['claude_model']) ? $settings['claude_model'] : '', 'claude-3-opus-20240229'); ?>>
                                            claude-3-opus-20240229 — Legacy Opus
                                        </option>
                                        <option value="claude-3-haiku-20240307" <?php selected(isset($settings['claude_model']) ? $settings['claude_model'] : '', 'claude-3-haiku-20240307'); ?>>
                                            claude-3-haiku-20240307 — Legacy Haiku, cheapest
                                        </option>
                                    </optgroup>
                                </select>
                                <p class="description"><?php _e('The exact model ID shown is what gets sent to the Anthropic API. Default: claude-sonnet-4-5.', 'mindfulseo'); ?></p>
                            </td>
                        </tr>
                        
                        <!-- DataForSEO API Settings -->
                        <tr>
                            <th scope="row" colspan="2">
                                <h3 style="margin: 20px 0 10px 0; padding-top: 20px; border-top: 1px solid #ddd;">
                                    <span class="dashicons dashicons-chart-line" style="vertical-align: middle;"></span>
                                    <?php _e('Keyword Research (Optional)', 'mindfulseo'); ?>
                                </h3>
                                <p class="description" style="font-weight: normal;">
                                    <?php _e('Add DataForSEO credentials to automatically fetch search volume, keyword difficulty, and CPC data for your keywords.', 'mindfulseo'); ?>
                                    <a href="https://dataforseo.com/" target="_blank"><?php _e('Get API access', 'mindfulseo'); ?></a>
                                </p>
                            </th>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="dataforseo_login"><?php _e('DataForSEO Login', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                    <input type="text" 
                                           id="dataforseo_login" 
                                           name="dataforseo_login" 
                                           value="<?php echo esc_attr(isset($settings['dataforseo_login']) && !empty($settings['dataforseo_login']) ? '••••••••••••••••' : ''); ?>" 
                                           class="regular-text"
                                           placeholder="your-login@email.com">
                                    <button type="button" id="test-dataforseo-connection" class="button">
                                        <span class="dashicons dashicons-admin-plugins" style="vertical-align: middle; margin-top: 3px;"></span>
                                        <?php _e('Test Connection', 'mindfulseo'); ?>
                                    </button>
                                    <span id="dataforseo-status-indicator"></span>
                                </div>
                                <p class="description">
                                    <?php _e('Your DataForSEO login email', 'mindfulseo'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="dataforseo_password"><?php _e('DataForSEO API Password', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <input type="password" 
                                       id="dataforseo_password" 
                                       name="dataforseo_password" 
                                       value="<?php echo esc_attr(isset($settings['dataforseo_password']) && !empty($settings['dataforseo_password']) ? '••••••••••••••••' : ''); ?>" 
                                       class="regular-text"
                                       placeholder="API Password (not Base64)">
                                <p class="description">
                                    <?php _e('Your DataForSEO API password (use the password directly, not the Base64 encoded version)', 'mindfulseo'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="dataforseo_location"><?php _e('Default Location', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <select id="dataforseo_location" name="dataforseo_location">
                                    <option value="2840" <?php selected(isset($settings['dataforseo_location']) ? $settings['dataforseo_location'] : '2840', '2840'); ?>>
                                        United States (2840)
                                    </option>
                                    <option value="2826" <?php selected(isset($settings['dataforseo_location']) ? $settings['dataforseo_location'] : '', '2826'); ?>>
                                        United Kingdom (2826)
                                    </option>
                                    <option value="2036" <?php selected(isset($settings['dataforseo_location']) ? $settings['dataforseo_location'] : '', '2036'); ?>>
                                        Australia (2036)
                                    </option>
                                    <option value="2124" <?php selected(isset($settings['dataforseo_location']) ? $settings['dataforseo_location'] : '', '2124'); ?>>
                                        Canada (2124)
                                    </option>
                                    <option value="2356" <?php selected(isset($settings['dataforseo_location']) ? $settings['dataforseo_location'] : '', '2356'); ?>>
                                        India (2356)
                                    </option>
                                </select>
                                <p class="description"><?php _e('Default location for keyword research data', 'mindfulseo'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="dataforseo_language"><?php _e('Default Language', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <select id="dataforseo_language" name="dataforseo_language">
                                    <option value="en" <?php selected(isset($settings['dataforseo_language']) ? $settings['dataforseo_language'] : 'en', 'en'); ?>>
                                        English
                                    </option>
                                    <option value="es" <?php selected(isset($settings['dataforseo_language']) ? $settings['dataforseo_language'] : '', 'es'); ?>>
                                        Spanish
                                    </option>
                                    <option value="fr" <?php selected(isset($settings['dataforseo_language']) ? $settings['dataforseo_language'] : '', 'fr'); ?>>
                                        French
                                    </option>
                                    <option value="de" <?php selected(isset($settings['dataforseo_language']) ? $settings['dataforseo_language'] : '', 'de'); ?>>
                                        German
                                    </option>
                                    <option value="it" <?php selected(isset($settings['dataforseo_language']) ? $settings['dataforseo_language'] : '', 'it'); ?>>
                                        Italian
                                    </option>
                                </select>
                                <p class="description"><?php _e('Default language for keyword research data', 'mindfulseo'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="auto_refresh_keywords"><?php _e('Auto-Refresh Keywords', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           id="auto_refresh_keywords" 
                                           name="auto_refresh_keywords" 
                                           value="1" 
                                           <?php checked(isset($settings['auto_refresh_keywords']) ? $settings['auto_refresh_keywords'] : false, true); ?>>
                                    <?php _e('Automatically refresh keyword metrics monthly', 'mindfulseo'); ?>
                                </label>
                                <p class="description">
                                    <?php _e('When enabled, keyword data will be automatically refreshed every month via WordPress cron.', 'mindfulseo'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row" colspan="2">
                                <h3 style="margin: 20px 0 10px 0; padding-top: 20px; border-top: 1px solid #ddd;">
                                    <span class="dashicons dashicons-admin-settings" style="vertical-align: middle;"></span>
                                    <?php _e('General Settings', 'mindfulseo'); ?>
                                </h3>
                            </th>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="primary_provider"><?php _e('Primary AI Provider', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <select id="primary_provider" name="primary_provider">
                                    <option value="openai" <?php selected($mfseo_settings_main_ai, 'openai'); ?>>
                                        <?php esc_html_e('OpenAI', 'mindfulseo'); ?>
                                    </option>
                                    <option value="claude" <?php selected($mfseo_settings_main_ai, 'claude'); ?>>
                                        <?php esc_html_e('Claude', 'mindfulseo'); ?>
                                    </option>
                                    <option value="openrouter" <?php selected($mfseo_settings_main_ai, 'openrouter'); ?>>
                                        <?php esc_html_e('OpenRouter', 'mindfulseo'); ?>
                                    </option>
                                </select>
                                <p class="description mfseo-primary-desc-direct"><?php esc_html_e('Choose how AI requests run: OpenAI or Claude (direct vendor APIs), or OpenRouter (one key; pick models below).', 'mindfulseo'); ?></p>
                                <p class="description mfseo-primary-desc-or" style="display:none;"><?php esc_html_e('OpenRouter is primary. If it fails, the direct option selected above (OpenAI vs Claude) is tried first—requires that key in the optional fallback section.', 'mindfulseo'); ?></p>
                            </td>
                        </tr>

                        <tr class="mfseo-openrouter-only mfseo-general-openrouter-models">
                            <th scope="row"><label for="openrouter_model"><?php _e('OpenRouter model', 'mindfulseo'); ?></label></th>
                            <td>
                                <select id="openrouter_model" name="openrouter_model">
                                    <option value="qwen/qwen3.5-flash-02-23" <?php selected(isset($settings['openrouter_model']) ? $settings['openrouter_model'] : '', 'qwen/qwen3.5-flash-02-23'); ?>>Qwen 3.5 Flash</option>
                                    <option value="qwen/qwen3.5-35b-a3b" <?php selected(isset($settings['openrouter_model']) ? $settings['openrouter_model'] : '', 'qwen/qwen3.5-35b-a3b'); ?>>Qwen 3.5 35B A3B</option>
                                    <option value="minimax/minimax-m2.5" <?php selected(isset($settings['openrouter_model']) ? $settings['openrouter_model'] : '', 'minimax/minimax-m2.5'); ?>>MiniMax M2.5</option>
                                    <option value="minimax/minimax-m2" <?php selected(isset($settings['openrouter_model']) ? $settings['openrouter_model'] : '', 'minimax/minimax-m2'); ?>>MiniMax M2</option>
                                </select>
                                <p class="description"><?php _e('Main model for full prompts (unless custom id is set).', 'mindfulseo'); ?></p>
                            </td>
                        </tr>

                        <tr class="mfseo-openrouter-only mfseo-general-openrouter-models">
                            <th scope="row"><label for="openrouter_model_fast"><?php _e('OpenRouter fast model', 'mindfulseo'); ?></label></th>
                            <td>
                                <select id="openrouter_model_fast" name="openrouter_model_fast">
                                    <option value="qwen/qwen3.5-flash-02-23" <?php selected(isset($settings['openrouter_model_fast']) ? $settings['openrouter_model_fast'] : '', 'qwen/qwen3.5-flash-02-23'); ?>>Qwen 3.5 Flash</option>
                                    <option value="minimax/minimax-m2.5" <?php selected(isset($settings['openrouter_model_fast']) ? $settings['openrouter_model_fast'] : '', 'minimax/minimax-m2.5'); ?>>MiniMax M2.5</option>
                                    <option value="minimax/minimax-m2" <?php selected(isset($settings['openrouter_model_fast']) ? $settings['openrouter_model_fast'] : '', 'minimax/minimax-m2'); ?>>MiniMax M2</option>
                                </select>
                                <p class="description"><?php _e('Used for lighter / fast requests. Connection test uses this model.', 'mindfulseo'); ?></p>
                            </td>
                        </tr>

                        <tr class="mfseo-openrouter-only mfseo-general-openrouter-models">
                            <th scope="row"><label for="openrouter_custom_model"><?php _e('Custom model id', 'mindfulseo'); ?></label></th>
                            <td>
                                <input type="text" class="large-text" id="openrouter_custom_model" name="openrouter_custom_model"
                                       value="<?php echo esc_attr(isset($settings['openrouter_custom_model']) ? $settings['openrouter_custom_model'] : ''); ?>"
                                       placeholder="e.g. minimax/minimax-m2.7">
                                <p class="description"><?php _e('If set, overrides the main preset for non-fast requests. Verify ids on openrouter.ai/models.', 'mindfulseo'); ?></p>
                            </td>
                        </tr>

                        <tr class="mfseo-openrouter-only mfseo-or-fallback-prefer">
                            <th scope="row"><label for="mfseo_fallback_direct_priority"><?php _e('If OpenRouter fails, try first', 'mindfulseo'); ?></label></th>
                            <td>
                                <select id="mfseo_fallback_direct_priority" name="mfseo_fallback_direct_priority">
                                    <option value="openai" <?php selected(isset($settings['primary_provider']) ? $settings['primary_provider'] : 'openai', 'openai'); ?>><?php esc_html_e('OpenAI', 'mindfulseo'); ?></option>
                                    <option value="claude" <?php selected(isset($settings['primary_provider']) ? $settings['primary_provider'] : 'openai', 'claude'); ?>><?php esc_html_e('Claude', 'mindfulseo'); ?></option>
                                </select>
                                <p class="description"><?php _e('Requires the matching API key in the optional fallback block under AI Provider Settings.', 'mindfulseo'); ?></p>
                            </td>
                        </tr>
                    
                        <tr>
                            <th scope="row">
                                <label for="enable_fallback"><?php _e('Enable Fallback', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           id="enable_fallback" 
                                           name="enable_fallback" 
                                           value="1" 
                                           <?php checked(isset($settings['enable_fallback']) ? $settings['enable_fallback'] : true, true); ?>>
                                    <span class="mfseo-fallback-desc-direct"><?php _e('Automatically use secondary provider if primary fails', 'mindfulseo'); ?></span>
                                    <span class="mfseo-fallback-desc-or" style="display:none;"><?php _e('Automatically try the other direct API if OpenRouter fails', 'mindfulseo'); ?></span>
                                </label>
                            </td>
                        </tr>
                    
                    <tr>
                        <th colspan="2">
                            <h2><?php _e('Optimization Settings', 'mindfulseo'); ?></h2>
                        </th>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="require_approval"><?php _e('Require Manual Approval', 'mindfulseo'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       id="require_approval" 
                                       name="require_approval" 
                                       value="1" 
                                       <?php checked(isset($settings['require_approval']) ? $settings['require_approval'] : true, true); ?>>
                                <?php _e('Review optimizations before applying to posts', 'mindfulseo'); ?>
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="batch_size"><?php _e('Batch Size', 'mindfulseo'); ?></label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="batch_size" 
                                   name="batch_size" 
                                   value="<?php echo esc_attr(isset($settings['batch_size']) ? $settings['batch_size'] : 10); ?>" 
                                   min="1" 
                                   max="100"
                                   class="small-text">
                            <p class="description"><?php _e('Number of posts to process in each batch', 'mindfulseo'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('Save Settings', 'mindfulseo')); ?>
            </form>
            </div>
            
            <!-- Setup Wizard Section -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin: 20px 20px 0 0;">
                <h3 style="margin: 0 0 10px 0; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-welcome-learn-more" style="color: #e1ca8e;"></span>
                    <?php _e('Setup Wizard', 'mindfulseo'); ?>
                </h3>
                <p style="color: #64748b; margin: 0 0 15px 0;">
                    <?php _e('Need to reconfigure the plugin? Run the setup wizard again to walk through the initial configuration.', 'mindfulseo'); ?>
                </p>
                <a href="<?php echo admin_url('admin.php?page=mindfulseo-wizard'); ?>" class="button">
                    <span class="dashicons dashicons-admin-generic" style="vertical-align: middle; margin-right: 5px;"></span>
                    <?php _e('Run Setup Wizard', 'mindfulseo'); ?>
                </a>
            </div>

            <?php else : // Usage tab ?>
            <?php $this->render_usage_tab(); ?>
            <?php endif; ?>

        </div>
        <?php
    }

    /**
     * Render the API Usage tab on the Settings page.
     */
    private function render_usage_tab() {
        $logger = class_exists('MFSEO_Logger') ? MFSEO_Logger::get_instance() : null;
        $range  = isset($_GET['usage_range']) && in_array($_GET['usage_range'], array('today','week','month','all')) ? $_GET['usage_range'] : 'month';
        $include_conn_tests = isset($_GET['usage_tests']) && $_GET['usage_tests'] === '1';
        $exclude_tests = ! $include_conn_tests;

        $stats = $logger ? $logger->get_api_stats($range, $exclude_tests) : array();
        $trend = $logger ? $logger->get_api_daily_trend($range, $exclude_tests) : array();
        $by_model = $logger ? $logger->get_api_stats_by_model($range, $exclude_tests) : array();
        $recent_api = $logger ? $logger->get_recent_logs(35, 'api_call') : array();

        // Find the earliest logged API call to warn about historical gap
        $earliest_log = null;
        if ( $logger ) {
            global $wpdb;
            $earliest_log = $wpdb->get_var(
                "SELECT MIN(created_at) FROM {$wpdb->prefix}mindfulseo_logs WHERE log_type = 'api_call'"
            );
        }

        $by_provider = isset($stats['by_provider']) ? $stats['by_provider'] : array();
        $dfs_data    = isset($by_provider['dataforseo']) ? $by_provider['dataforseo'] : array();
        $ai_cost     = ( isset($stats['total_cost']) ? (float)$stats['total_cost'] : 0 ) - ( isset($dfs_data['cost']) ? (float)$dfs_data['cost'] : 0 );
        $ai_calls    = ( isset($stats['total_calls']) ? (int)$stats['total_calls'] : 0 ) - ( isset($dfs_data['calls']) ? (int)$dfs_data['calls'] : 0 );
        $ai_tokens   = isset($stats['total_tokens']) ? (int)$stats['total_tokens'] : 0;

        $range_labels = array('today' => 'Today', 'week' => 'Last 7 Days', 'month' => 'Last 30 Days', 'all' => 'All Time');
        $settings_url = admin_url('admin.php?page=mindfulseo-settings&tab=usage');
        ?>
        <div style="margin: 0 20px 20px 0;">

            <!-- Date range selector -->
            <div style="display:flex;align-items:center;gap:10px;margin:16px 0 20px;">
                <strong style="font-size:13px;"><?php _e('Date Range:', 'mindfulseo'); ?></strong>
                <?php foreach ($range_labels as $key => $label) : ?>
                    <?php
                    $range_href = add_query_arg('usage_range', $key, $settings_url);
                    if ($include_conn_tests) {
                        $range_href = add_query_arg('usage_tests', '1', $range_href);
                    }
                    ?>
                    <a href="<?php echo esc_url($range_href); ?>"
                       class="button <?php echo $range === $key ? 'button-primary' : ''; ?>" style="text-decoration:none;">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
                <span style="margin-left:12px;font-size:13px;">
                    <?php
                    $tests_on = $include_conn_tests;
                    $usage_base = remove_query_arg(array('usage_tests'), add_query_arg('usage_range', $range, $settings_url));
                    $tests_url = add_query_arg('usage_tests', $tests_on ? '0' : '1', $usage_base);
                    ?>
                    <a href="<?php echo esc_url($tests_url); ?>" class="button<?php echo $tests_on ? ' button-primary' : ''; ?>" style="text-decoration:none;">
                        <?php echo $tests_on ? esc_html__('Hide connection tests in totals', 'mindfulseo') : esc_html__('Include connection tests in totals', 'mindfulseo'); ?>
                    </a>
                </span>
            </div>

            <?php if ( $earliest_log ) : ?>
            <div style="background:#f0f6fc;border:1px solid #c3c4c7;border-left:4px solid #2271b1;border-radius:6px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:flex-start;gap:10px;">
                <span class="dashicons dashicons-info-outline" style="color:#2271b1;font-size:18px;flex-shrink:0;margin-top:1px;"></span>
                <div style="font-size:13px;line-height:1.6;">
                    <strong><?php _e( 'Earliest logged usage:', 'mindfulseo' ); ?> <?php echo esc_html( date_i18n( get_option('date_format'), strtotime( $earliest_log ) ) ); ?></strong><br>
                    <?php _e( 'Usage before this date was not tracked by the plugin. If your provider bill is higher than shown here, the difference is from before logging was enabled.', 'mindfulseo' ); ?>
                </div>
            </div>
            <?php else : ?>
            <div style="background:#f0f6fc;border:1px solid #c3c4c7;border-left:4px solid #2271b1;border-radius:6px;padding:12px 16px;margin-bottom:20px;font-size:13px;">
                <span class="dashicons dashicons-info" style="color:#2271b1;vertical-align:middle;margin-right:6px;"></span>
                <?php _e( 'No API calls have been logged yet. Cost tracking begins automatically with your next AI or DataForSEO call.', 'mindfulseo' ); ?>
            </div>
            <?php endif; ?>

            <!-- Summary cards -->
            <div class="mfseo-dash-health-grid mfseo-usage-summary-grid" style="margin-bottom:24px;">
                <div class="mfseo-health-card mfseo-health-card--good">
                    <div class="mfseo-health-card__count"><?php echo number_format($ai_calls); ?></div>
                    <div class="mfseo-health-card__label"><?php _e('AI Calls', 'mindfulseo'); ?></div>
                    <div class="mfseo-health-card__of"><?php echo esc_html($range_labels[$range]); ?></div>
                </div>
                <div class="mfseo-health-card">
                    <div class="mfseo-health-card__count"><?php echo number_format($ai_tokens); ?></div>
                    <div class="mfseo-health-card__label"><?php _e('Tokens Used', 'mindfulseo'); ?></div>
                    <div class="mfseo-health-card__of"><?php _e('prompt + completion', 'mindfulseo'); ?></div>
                </div>
                <div class="mfseo-health-card mfseo-health-card--warning">
                    <div class="mfseo-health-card__count">$<?php echo number_format(max(0,$ai_cost),2); ?></div>
                    <div class="mfseo-health-card__label"><?php _e('AI Cost (est.)', 'mindfulseo'); ?></div>
                    <div class="mfseo-health-card__of"><?php _e('OpenAI + Claude + OpenRouter (est.)', 'mindfulseo'); ?></div>
                </div>
                <div class="mfseo-health-card mfseo-health-card--warning">
                    <div class="mfseo-health-card__count">$<?php echo number_format(isset($dfs_data['cost']) ? $dfs_data['cost'] : 0, 3); ?></div>
                    <div class="mfseo-health-card__label"><?php _e('DataForSEO (est.)', 'mindfulseo'); ?></div>
                    <div class="mfseo-health-card__of"><?php _e('credits estimate', 'mindfulseo'); ?></div>
                </div>
            </div>

            <div class="mfseo-usage-transparency" style="background:#f6f7f7;border:1px solid #c3c4c7;border-radius:6px;padding:14px 18px;margin-bottom:20px;font-size:13px;line-height:1.65;">
                <p style="margin:0 0 10px;"><strong><?php esc_html_e( 'Cost transparency', 'mindfulseo' ); ?></strong></p>
                <ul style="margin:0 0 0 18px;padding:0;">
                    <li><?php esc_html_e( 'Successful OpenAI, Claude, and OpenRouter chat calls are logged with tokens and an estimated USD cost (OpenRouter rates are approximate). Rows without token usage from the API are still logged with a usage_missing flag. DataForSEO calls are logged separately.', 'mindfulseo' ); ?></li>
                    <li><?php esc_html_e( 'The “Model / context” column shows the model and which feature ran (for example batch_optimizer, content_analyzer_keywords, or keyword_strategy_ai_cleanup).', 'mindfulseo' ); ?></li>
                    <li><?php esc_html_e( 'If the primary provider fails and the plugin tries the other provider once, you are normally only charged for the successful call; failures are not stored as paid rows.', 'mindfulseo' ); ?></li>
                    <li><?php esc_html_e( 'There is no scheduled background AI job: the monthly cron only refreshes DataForSEO keyword metrics when that option is enabled—not OpenAI or Claude.', 'mindfulseo' ); ?></li>
                    <li><?php esc_html_e( 'Totals here are estimates from published rates. Always reconcile with your provider invoices.', 'mindfulseo' ); ?></li>
                </ul>
            </div>

            <!-- Per-provider breakdown -->
            <?php if (!empty($by_provider)) : ?>
            <div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px;margin-bottom:20px;">
                <h3 style="margin:0 0 16px;font-size:14px;"><?php _e('Breakdown by Provider', 'mindfulseo'); ?></h3>
                <table class="widefat striped" style="font-size:13px;">
                    <thead>
                        <tr>
                            <th><?php _e('Provider / Model', 'mindfulseo'); ?></th>
                            <th><?php _e('Calls', 'mindfulseo'); ?></th>
                            <th><?php _e('Input Tokens / Items', 'mindfulseo'); ?></th>
                            <th><?php _e('Output Tokens', 'mindfulseo'); ?></th>
                            <th><?php _e('Estimated Cost', 'mindfulseo'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($by_provider as $prov => $pdata) :
                            $prov_label = $prov === 'dataforseo' ? 'DataForSEO' : ucfirst($prov);
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($prov_label); ?></strong></td>
                            <td><?php echo number_format((int)$pdata['calls']); ?></td>
                            <td><?php echo number_format((int)$pdata['prompt_tokens']); ?></td>
                            <td><?php echo $prov === 'dataforseo' ? '—' : number_format((int)$pdata['completion_tokens']); ?></td>
                            <td><strong>$<?php echo number_format((float)$pdata['cost'], $prov === 'dataforseo' ? 3 : 2); ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="margin:10px 0 0;font-size:12px;color:#787c82;">
                    <?php _e('AI costs are estimated from published per-token rates. DataForSEO costs are estimated from published per-call credit rates. Always verify against your actual provider invoices.', 'mindfulseo'); ?>
                </p>
            </div>
            <?php endif; ?>

            <?php if (!empty($by_model)) : ?>
            <div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px;margin-bottom:20px;">
                <h3 style="margin:0 0 16px;font-size:14px;"><?php _e('AI usage by model', 'mindfulseo'); ?></h3>
                <table class="widefat striped" style="font-size:13px;">
                    <thead>
                        <tr>
                            <th><?php _e('Provider', 'mindfulseo'); ?></th>
                            <th><?php _e('Model', 'mindfulseo'); ?></th>
                            <th><?php _e('Calls', 'mindfulseo'); ?></th>
                            <th><?php _e('In', 'mindfulseo'); ?></th>
                            <th><?php _e('Out', 'mindfulseo'); ?></th>
                            <th><?php _e('Est. cost', 'mindfulseo'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($by_model as $bm) : ?>
                        <tr>
                            <td><?php echo esc_html($bm['ai_provider']); ?></td>
                            <td style="max-width:220px;word-break:break-word;"><?php echo esc_html($bm['model_slug']); ?></td>
                            <td><?php echo number_format((int) $bm['calls']); ?></td>
                            <td><?php echo number_format((int) $bm['prompt_tokens']); ?></td>
                            <td><?php echo number_format((int) $bm['completion_tokens']); ?></td>
                            <td><strong>$<?php echo number_format((float) $bm['cost'], 2); ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Daily cost bar chart -->
            <?php if (!empty($trend)) :
                // Build per-day totals across all providers
                $daily = array();
                foreach ($trend as $row) {
                    $day = $row['day'];
                    if (!isset($daily[$day])) $daily[$day] = 0;
                    $daily[$day] += (float)$row['cost'];
                }
                $max_cost = max(array_values($daily));
            ?>
            <div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px;">
                <h3 style="margin:0 0 16px;font-size:14px;"><?php _e('Daily Cost Trend', 'mindfulseo'); ?></h3>
                <div style="display:flex;align-items:flex-end;gap:6px;height:120px;overflow-x:auto;padding-bottom:4px;">
                    <?php foreach ($daily as $day => $cost) :
                        $pct    = $max_cost > 0 ? ($cost / $max_cost) * 100 : 0;
                        $height = max(4, (int)($pct * 1.2)); // max ~120px
                        $label  = date('M j', strtotime($day));
                    ?>
                    <div style="display:flex;flex-direction:column;align-items:center;min-width:32px;flex:1;">
                        <div title="<?php echo esc_attr($day . ': $' . number_format($cost,3)); ?>"
                             style="width:100%;background:#e1ca8e;border-radius:3px 3px 0 0;height:<?php echo intval($height); ?>px;cursor:default;"></div>
                        <div style="font-size:10px;color:#787c82;margin-top:4px;white-space:nowrap;"><?php echo esc_html($label); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p style="margin:8px 0 0;font-size:12px;color:#787c82;"><?php _e('Hover a bar to see exact date and cost. All costs are estimates.', 'mindfulseo'); ?></p>
            </div>
            <?php else : ?>
            <div style="background:#f0f0f1;padding:20px;border-radius:6px;text-align:center;color:#787c82;">
                <span class="dashicons dashicons-chart-bar" style="font-size:32px;width:32px;height:32px;margin-bottom:8px;"></span>
                <p style="margin:0;"><?php _e('No API usage data found for the selected period.', 'mindfulseo'); ?></p>
            </div>
            <?php endif; ?>

            <?php if ( ! empty( $recent_api ) ) : ?>
            <div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px;margin-top:24px;">
                <h3 style="margin:0 0 14px;font-size:14px;"><?php esc_html_e( 'Recent API calls (detail)', 'mindfulseo' ); ?></h3>
                <p style="margin:0 0 12px;font-size:12px;color:#787c82;"><?php esc_html_e( 'Newest first. Use this to match spikes on your OpenAI or Anthropic bill to a specific action.', 'mindfulseo' ); ?></p>
                <div style="overflow-x:auto;">
                    <table class="widefat striped" style="font-size:12px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'When', 'mindfulseo' ); ?></th>
                                <th><?php esc_html_e( 'Provider', 'mindfulseo' ); ?></th>
                                <th><?php esc_html_e( 'Model', 'mindfulseo' ); ?></th>
                                <th><?php esc_html_e( 'Context', 'mindfulseo' ); ?></th>
                                <th><?php esc_html_e( 'Kind', 'mindfulseo' ); ?></th>
                                <th><?php esc_html_e( 'In', 'mindfulseo' ); ?></th>
                                <th><?php esc_html_e( 'Out', 'mindfulseo' ); ?></th>
                                <th><?php esc_html_e( 'Est. $', 'mindfulseo' ); ?></th>
                                <th><?php esc_html_e( 'Notes', 'mindfulseo' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $recent_api as $log ) : ?>
                            <tr>
                                <td><?php echo esc_html( $log->created_at ); ?></td>
                                <td><?php echo esc_html( $log->ai_provider ); ?></td>
                                <td style="max-width:140px;word-break:break-word;"><?php echo esc_html( isset( $log->ai_model ) && $log->ai_model !== '' && $log->ai_model !== null ? $log->ai_model : '—' ); ?></td>
                                <td style="max-width:120px;word-break:break-word;"><?php echo esc_html( isset( $log->usage_context ) && $log->usage_context !== '' && $log->usage_context !== null ? $log->usage_context : '—' ); ?></td>
                                <td><?php echo esc_html( isset( $log->api_call_kind ) && $log->api_call_kind !== '' ? $log->api_call_kind : 'production' ); ?></td>
                                <td><?php echo number_format( (int) $log->prompt_tokens ); ?></td>
                                <td><?php echo number_format( (int) $log->completion_tokens ); ?></td>
                                <td><strong>$<?php echo esc_html( number_format( (float) $log->cost, 4 ) ); ?></strong></td>
                                <td style="max-width:200px;word-break:break-word;"><?php echo esc_html( $log->message ); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        </div>
        <?php
    }
    
    /**
     * Render keywords page
     */
    public function render_keywords_page() {
        // Initialize keyword manager
        $keyword_manager = null;
        if (class_exists('MFSEO_Keyword_Manager')) {
            $keyword_manager = MFSEO_Keyword_Manager::get_instance();
        }
        
        // Handle CSV upload
        if (isset($_POST['mindfulseo_upload_keywords']) && check_admin_referer('mindfulseo_upload_keywords', 'mindfulseo_keywords_nonce')) {
            if (!$keyword_manager) {
                add_settings_error('mindfulseo_keywords', 'import_error', __('Keyword Manager not available.', 'mindfulseo'), 'error');
            } elseif (!isset($_FILES['keyword_csv']) || $_FILES['keyword_csv']['error'] === UPLOAD_ERR_NO_FILE) {
                add_settings_error('mindfulseo_keywords', 'import_error', __('No file selected. Please choose a CSV file.', 'mindfulseo'), 'error');
            } elseif ($_FILES['keyword_csv']['error'] !== UPLOAD_ERR_OK) {
                $upload_errors = array(
                    UPLOAD_ERR_INI_SIZE => 'File exceeds server upload_max_filesize',
                    UPLOAD_ERR_FORM_SIZE => 'File exceeds form MAX_FILE_SIZE',
                    UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder on server',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                    UPLOAD_ERR_EXTENSION => 'Upload stopped by a PHP extension',
                );
                $err_msg = isset($upload_errors[$_FILES['keyword_csv']['error']]) ? $upload_errors[$_FILES['keyword_csv']['error']] : 'Unknown upload error';
                add_settings_error('mindfulseo_keywords', 'import_error', 'Upload failed: ' . $err_msg, 'error');
            } else {
                error_log('MindfulSEO CSV Import: Starting import of ' . $_FILES['keyword_csv']['name'] . ' (' . $_FILES['keyword_csv']['size'] . ' bytes)');
                // Merge duplicate primary+longtail so your CSV wins over existing AI rows (same behavior as setup wizard).
                $result = $keyword_manager->import_csv(
                    $_FILES['keyword_csv'],
                    array(
                        'wizard_merge' => true,
                        'csv_source'   => sanitize_file_name( $_FILES['keyword_csv']['name'] ),
                    )
                );
                
                if (is_wp_error($result)) {
                    error_log('MindfulSEO CSV Import Error: ' . $result->get_error_message());
                    add_settings_error(
                        'mindfulseo_keywords',
                        'import_error',
                        $result->get_error_message(),
                        'error'
                    );
                } else {
                    $updated = isset( $result['updated'] ) ? (int) $result['updated'] : 0;
                    error_log( 'MindfulSEO CSV Import Success: ' . $result['imported'] . ' new, ' . $updated . ' updated, ' . $result['skipped'] . ' skipped' );
                    $msg = sprintf(
                        /* translators: 1: new rows, 2: updated rows, 3: skipped */
                        __( 'Keyword CSV: %1$d new rows, %2$d existing rows updated from your file, %3$d skipped (empty or invalid rows).', 'mindfulseo' ),
                        (int) $result['imported'],
                        $updated,
                        (int) $result['skipped']
                    );
                    if (!empty($result['errors'])) {
                        $msg .= '. Database errors: ' . implode('; ', array_slice($result['errors'], 0, 3));
                    }
                    add_settings_error(
                        'mindfulseo_keywords',
                        'import_success',
                        $msg,
                        $result['imported'] > 0 ? 'success' : 'warning'
                    );
                }
            }
        }
        
        // Handle auto-generate keywords
        if (isset($_POST['mindfulseo_autogenerate_keywords']) && check_admin_referer('mindfulseo_autogenerate_keywords', 'mindfulseo_autogen_nonce')) {
            @set_time_limit(300);

            $post_types = isset($_POST['analyze_post_types']) ? array_map('sanitize_text_field', $_POST['analyze_post_types']) : array('post');
            $limit = isset($_POST['analyze_limit']) ? intval($_POST['analyze_limit']) : 50;
            
            if (!class_exists('MFSEO_Content_Analyzer')) {
                add_settings_error('mindfulseo_keywords', 'analyzer_missing', __('Content Analyzer class not found. Please deactivate and reactivate the plugin.', 'mindfulseo'), 'error');
            } elseif (!$keyword_manager) {
                add_settings_error('mindfulseo_keywords', 'manager_missing', __('Keyword Manager not available. Please check plugin installation.', 'mindfulseo'), 'error');
            } else {
                $analyzer = new MFSEO_Content_Analyzer();
                $suggestions = $analyzer->analyze_for_keywords(array(
                    'post_types' => $post_types,
                    'limit' => $limit
                ));

                if (is_wp_error($suggestions)) {
                    add_settings_error('mindfulseo_keywords', 'analyze_error', $suggestions->get_error_message(), 'error');
                } elseif (empty($suggestions)) {
                    add_settings_error('mindfulseo_keywords', 'analyze_empty', __('No keywords found. Try analyzing more posts or different post types.', 'mindfulseo'), 'warning');
                } else {
                    $imported = 0;
                    $skipped = 0;
                    foreach ($suggestions as $suggestion) {
                        $result = $keyword_manager->add_keyword(array(
                            'primary_keyword' => $suggestion['primary_keyword'],
                            'longtail_keyword' => $suggestion['longtail_keyword'],
                            'search_intent' => $suggestion['search_intent'],
                            'priority' => $suggestion['priority'],
                            'current_sessions' => isset($suggestion['frequency']) ? $suggestion['frequency'] : 0,
                            'notes' => 'Auto-generated from content analysis',
                            'csv_source' => 'Auto-generated'
                        ));
                        if (!is_wp_error($result)) {
                            $imported++;
                        } elseif ($result->get_error_code() === 'duplicate_keyword') {
                            $skipped++;
                        }
                    }
                    
                    if ($imported > 0) {
                        $message = sprintf(__('Successfully analyzed content and imported %d keyword suggestions!', 'mindfulseo'), $imported);
                        if ($skipped > 0) {
                            $message .= ' ' . sprintf(__('(%d duplicates skipped)', 'mindfulseo'), $skipped);
                        }
                        add_settings_error('mindfulseo_keywords', 'analyze_success', $message, 'success');
                    } else {
                        add_settings_error('mindfulseo_keywords', 'analyze_no_new', __('All suggested keywords already exist in your database.', 'mindfulseo'), 'warning');
                    }
                }
            }
        }
        
        // Handle keyword deletion
        if (isset($_POST['mindfulseo_delete_keyword']) && check_admin_referer('mindfulseo_delete_keyword', 'mindfulseo_delete_nonce')) {
            if (isset($_POST['keyword_id']) && $keyword_manager) {
                $deleted = $keyword_manager->delete_keyword(intval($_POST['keyword_id']));
                if ($deleted) {
                    add_settings_error(
                        'mindfulseo_keywords',
                        'delete_success',
                        __('Keyword deleted successfully.', 'mindfulseo'),
                        'success'
                    );
                }
            }
        }

        // Handle keyword GROUP deletion (parent + all longtails)
        if (isset($_POST['mindfulseo_delete_keyword_group']) && check_admin_referer('mindfulseo_delete_keyword', 'mindfulseo_delete_nonce')) {
            if (isset($_POST['keyword_group_ids']) && is_array($_POST['keyword_group_ids']) && $keyword_manager) {
                $count = 0;
                foreach ($_POST['keyword_group_ids'] as $gid) {
                    if ($keyword_manager->delete_keyword(intval($gid))) {
                        $count++;
                    }
                }
                if ($count > 0) {
                    add_settings_error(
                        'mindfulseo_keywords',
                        'delete_success',
                        sprintf(__('Deleted %d keywords.', 'mindfulseo'), $count),
                        'success'
                    );
                }
            }
        }
        
        // Handle delete all keywords
        if (isset($_POST['mindfulseo_delete_all_keywords']) && check_admin_referer('mindfulseo_delete_all_keywords', 'mindfulseo_delete_all_nonce')) {
            if ($keyword_manager) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'mindfulseo_keywords';
                $deleted = 0;
                if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name) {
                    $deleted = (int) $wpdb->query("DELETE FROM {$table_name}");
                }
                if ($deleted > 0) {
                    add_settings_error(
                        'mindfulseo_keywords',
                        'delete_all_success',
                        sprintf(__('%d keywords deleted successfully.', 'mindfulseo'), $deleted),
                        'success'
                    );
                }
            }
        }
        
        // Handle add keyword
        if (isset($_POST['mindfulseo_add_keyword']) && check_admin_referer('mindfulseo_add_keyword', 'mindfulseo_add_keyword_nonce')) {
            if ($keyword_manager) {
                $primary = sanitize_text_field($_POST['new_primary_keyword']);
                $longtail = sanitize_text_field($_POST['new_longtail_keyword']);
                $intent = sanitize_text_field($_POST['new_search_intent']);
                $priority = sanitize_text_field($_POST['new_priority']);
                $notes = sanitize_textarea_field($_POST['new_notes']);
                
                if (!empty($primary) && !empty($longtail)) {
                    $result = $keyword_manager->add_keyword(array(
                        'primary_keyword' => $primary,
                        'longtail_keyword' => $longtail,
                        'search_intent' => $intent,
                        'priority' => $priority,
                        'notes' => $notes,
                        'csv_source' => 'manual'
                    ));
                    
                    if ($result) {
                        add_settings_error(
                            'mindfulseo_keywords',
                            'add_success',
                            __('Keyword added successfully!', 'mindfulseo'),
                            'success'
                        );
                    } else {
                        add_settings_error(
                            'mindfulseo_keywords',
                            'add_error',
                            __('Failed to add keyword. It may already exist.', 'mindfulseo'),
                            'error'
                        );
                    }
                }
            }
        }
        
        // Get keywords — preserve HIGH → MEDIUM → LOW; do not sort A–Z only (that hid CSV/import priority).
        $keywords         = array();
        $stats            = array();
        $keyword_groups   = array();
        $kw_priority_rank = function ( $p ) {
            $p = strtoupper( (string) $p );
            if ( 'HIGH' === $p ) {
                return 0;
            }
            if ( 'LOW' === $p ) {
                return 2;
            }
            return 1;
        };
        if ($keyword_manager) {
            $keywords = $keyword_manager->get_keywords(
                array(
                    'limit'   => 999999,
                    'orderby' => 'priority',
                    'order'   => 'ASC',
                )
            );
            $stats = $keyword_manager->get_statistics();

            if (!empty($keywords)) {
                usort(
                    $keywords,
                    function ( $a, $b ) use ( $kw_priority_rank ) {
                        $pa = $kw_priority_rank( $a->priority );
                        $pb = $kw_priority_rank( $b->priority );
                        if ( $pa !== $pb ) {
                            return $pa - $pb;
                        }
                        $c = strcasecmp( $a->primary_keyword, $b->primary_keyword );
                        if ( 0 !== $c ) {
                            return $c;
                        }
                        return strcasecmp( $a->longtail_keyword, $b->longtail_keyword );
                    }
                );
            }
        }

        // Group keywords by primary keyword for the collapsible table
        foreach ($keywords as $kw) {
            $gkey = strtolower(trim($kw->primary_keyword));
            if (!isset($keyword_groups[$gkey])) {
                $keyword_groups[$gkey] = array(
                    'name' => $kw->primary_keyword,
                    'rows' => array(),
                );
            }
            $keyword_groups[$gkey]['rows'][] = $kw;
        }
        foreach ( $keyword_groups as &$mfseo_kw_grp ) {
            usort(
                $mfseo_kw_grp['rows'],
                function ( $a, $b ) use ( $kw_priority_rank ) {
                    $pa = $kw_priority_rank( $a->priority );
                    $pb = $kw_priority_rank( $b->priority );
                    if ( $pa !== $pb ) {
                        return $pa - $pb;
                    }
                    return strcasecmp( $a->longtail_keyword, $b->longtail_keyword );
                }
            );
        }
        unset( $mfseo_kw_grp );
        uasort(
            $keyword_groups,
            function ( $a, $b ) use ( $kw_priority_rank ) {
                $min_rank = function ( $rows ) use ( $kw_priority_rank ) {
                    $m = 99;
                    foreach ( $rows as $r ) {
                        $m = min( $m, $kw_priority_rank( $r->priority ) );
                    }
                    return $m;
                };
                $ra = $min_rank( $a['rows'] );
                $rb = $min_rank( $b['rows'] );
                if ( $ra !== $rb ) {
                    return $ra - $rb;
                }
                return strcasecmp( $a['name'], $b['name'] );
            }
        );
        $primary_metrics = get_option('mindfulseo_primary_metrics', array());
        
        // Get active tab
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'manage';
        
        ?>
        <!-- Output WordPress notices OUTSIDE and BEFORE the wrap div -->
        <?php settings_errors('mindfulseo_keywords'); ?>
        
        <?php echo $this->get_branding_header('Keyword Strategy'); ?>
        
        <div class="wrap mindfulseo-keywords-wrap">
            
            <!-- Tab Navigation -->
            <nav class="nav-tab-wrapper wp-clearfix">
                <a href="<?php echo esc_url(add_query_arg(array('page' => 'mindfulseo-keywords', 'tab' => 'manage'), admin_url('admin.php'))); ?>" 
                   class="nav-tab <?php echo $active_tab === 'manage' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-list-view"></span>
                    <?php _e('Manage Keywords', 'mindfulseo'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg(array('page' => 'mindfulseo-keywords', 'tab' => 'import'), admin_url('admin.php'))); ?>" 
                   class="nav-tab <?php echo $active_tab === 'import' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-upload"></span>
                    <?php _e('Import CSV', 'mindfulseo'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg(array('page' => 'mindfulseo-keywords', 'tab' => 'autogenerate'), admin_url('admin.php'))); ?>" 
                   class="nav-tab <?php echo $active_tab === 'autogenerate' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-generic"></span>
                    <?php _e('Auto-Generate', 'mindfulseo'); ?>
                </a>
            </nav>
            
            <!-- Tab Content -->
            <div class="mindfulseo-tab-content">
            
            <?php if ($active_tab === 'manage'): ?>
                <!-- MANAGE TAB: View/Edit Keywords -->
                
            <!-- Statistics Cards -->
            <div class="mindfulseo-stats-grid" style="margin: 20px 20px 20px 0;">
                <div class="mindfulseo-stat-card">
                    <h3><?php _e('Total Keywords', 'mindfulseo'); ?></h3>
                    <p class="stat-number"><?php echo isset($stats['total']) ? intval($stats['total']) : 0; ?></p>
                </div>
                <div class="mindfulseo-stat-card">
                    <h3><?php _e('Primary Keywords', 'mindfulseo'); ?></h3>
                    <p class="stat-number"><?php echo isset($stats['unique_primary']) ? intval($stats['unique_primary']) : 0; ?></p>
                </div>
                <div class="mindfulseo-stat-card">
                    <h3><?php _e('High Priority', 'mindfulseo'); ?></h3>
                    <p class="stat-number"><?php echo isset($stats['by_priority']['HIGH']->count) ? intval($stats['by_priority']['HIGH']->count) : 0; ?></p>
                </div>
                <div class="mindfulseo-stat-card">
                    <h3><?php _e('Informational', 'mindfulseo'); ?></h3>
                    <p class="stat-number"><?php echo isset($stats['by_intent']['Informational']->count) ? intval($stats['by_intent']['Informational']->count) : 0; ?></p>
                </div>
            </div>
            
            <?php elseif ($active_tab === 'autogenerate'): ?>
                <!-- AUTO-GENERATE TAB -->
            <div style="background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; margin: 20px 20px 0 0;">
                <h2><?php _e('🤖 Auto-Generate Keywords', 'mindfulseo'); ?></h2>
                <p><?php _e('Analyze your existing content to automatically discover and extract keyword opportunities.', 'mindfulseo'); ?></p>
                
                <?php
                // Get content analyzer
                $analyzer = new MFSEO_Content_Analyzer();
                $post_types = $analyzer->get_supported_post_types();
                ?>
                
                <form method="post" id="autogenerate-keywords-form" style="margin-top: 20px;">
                    <?php wp_nonce_field('mindfulseo_autogenerate_keywords', 'mindfulseo_autogen_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="analyze_post_types"><?php _e('Content Types to Analyze', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <?php foreach ($post_types as $type => $label): ?>
                                    <?php $count = $analyzer->get_post_count($type); ?>
                                    <label style="display: block; margin-bottom: 8px;">
                                        <input type="checkbox" 
                                               name="analyze_post_types[]" 
                                               value="<?php echo esc_attr($type); ?>" 
                                               <?php checked(in_array($type, array('post', 'page'))); ?>>
                                        <?php echo esc_html($label); ?> 
                                        <span style="color: #666;">(<?php echo number_format($count); ?> <?php _e('published', 'mindfulseo'); ?>)</span>
                                    </label>
                                <?php endforeach; ?>
                                <p class="description"><?php _e('Select which content types to analyze for keyword extraction.', 'mindfulseo'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="analyze_limit"><?php _e('Posts to Analyze', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <input type="number" 
                                       id="analyze_limit" 
                                       name="analyze_limit" 
                                       value="50" 
                                       min="10" 
                                       max="500" 
                                       step="10" 
                                       class="small-text">
                                <p class="description"><?php _e('Number of posts to analyze (more posts = better results but slower)', 'mindfulseo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="deep_analysis"><?php _e('Analysis Quality', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" id="deep_analysis" name="deep_analysis" value="1">
                                    <?php _e('Deep Analysis (uses your primary AI model — slower but higher quality keywords)', 'mindfulseo'); ?>
                                </label>
                                <p class="description"><?php _e('When off, uses a fast model for quicker results. Turn on for best possible keyword quality.', 'mindfulseo'); ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" name="mindfulseo_autogenerate_keywords" class="button button-primary" id="mfseo-autogen-keywords-btn">
                            <?php _e('Analyze Content & Generate Keywords', 'mindfulseo'); ?>
                        </button>
                    </p>
                </form>
                
                <!-- Custom AI Prompts Section -->
                <?php
                $settings = get_option('mindfulseo_settings', array());
                $keyword_prompt = isset($settings['keyword_generation_prompt']) ? $settings['keyword_generation_prompt'] : '';
                ?>
                <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
                    <h3 style="font-size: 14px; margin-bottom: 10px;">
                        <span class="dashicons dashicons-admin-generic" style="vertical-align: middle;"></span>
                        <?php _e('Custom AI Instructions for Keyword Generation', 'mindfulseo'); ?>
                    </h3>
                    <p class="description" style="margin-bottom: 15px;">
                        <?php _e('Add custom instructions to guide the AI when generating keywords. These will be appended to the default system prompt.', 'mindfulseo'); ?>
                    </p>
                    <textarea id="keyword-generation-prompt" 
                              name="keyword_generation_prompt" 
                              rows="6" 
                              style="width: 100%; font-family: monospace; font-size: 13px;"
                              class="large-text code"
                              placeholder="<?php esc_attr_e('Example: Focus on longtail keywords with low competition. Prioritize location-based terms for our London audience.', 'mindfulseo'); ?>"><?php echo esc_textarea($keyword_prompt); ?></textarea>
                    <p class="description" style="margin-top: 8px;">
                        <?php _e('💡 Tip: Be specific about your audience, location, or special focus areas.', 'mindfulseo'); ?>
                    </p>
                    <div style="margin-top: 15px;">
                        <button type="button" class="button button-primary save-prompt-btn" data-prompt-type="keyword_generation">
                            <span class="dashicons dashicons-yes"></span>
                            <?php _e('Save Custom Instructions', 'mindfulseo'); ?>
                        </button>
                        <button type="button" class="button reset-prompt-btn" data-prompt-type="keyword_generation">
                            <span class="dashicons dashicons-update"></span>
                            <?php _e('Reset to Default', 'mindfulseo'); ?>
                        </button>
                        <span class="prompt-save-status" style="margin-left: 10px; color: #46b450; display: none;">
                            <span class="dashicons dashicons-yes"></span>
                            <?php _e('Saved!', 'mindfulseo'); ?>
                        </span>
                    </div>
                </div>
                
                <div id="autogenerate-results" style="margin-top: 20px;"></div>
            </div>
            
            <?php elseif ($active_tab === 'import'): ?>
                <!-- IMPORT TAB -->
            
            <!-- Upload Section -->
            <div style="background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; margin: 20px 20px 0 0;">
                <h2><?php _e('Upload Keyword CSV', 'mindfulseo'); ?></h2>
                <p><?php _e('Upload a CSV file with your keyword waterfall strategy. Map primary keywords to longtail variations with search intent and priority levels.', 'mindfulseo'); ?></p>
                <p><strong><?php _e('Required column:', 'mindfulseo'); ?></strong> PRIMARY KEYWORD (or PRIMARY_KEYWORD)</p>
                <p><?php _e('Optional columns: LONGTAIL KEYWORD, SEARCH INTENT, PRIORITY, CURRENT SESSIONS, NOTES', 'mindfulseo'); ?></p>
                <p class="description"><?php _e('Column headers are flexible: underscores, hyphens, and mixed case all work.', 'mindfulseo'); ?></p>
                
                <?php
                // Show inline import result right here so user can't miss it
                $import_notices = get_settings_errors('mindfulseo_keywords');
                if (!empty($import_notices)) {
                    foreach ($import_notices as $notice) {
                        $bg = $notice['type'] === 'success' ? '#d4edda' : ($notice['type'] === 'error' ? '#f8d7da' : '#fff3cd');
                        $border = $notice['type'] === 'success' ? '#28a745' : ($notice['type'] === 'error' ? '#dc3545' : '#ffc107');
                        $color = $notice['type'] === 'success' ? '#155724' : ($notice['type'] === 'error' ? '#721c24' : '#856404');
                        echo '<div style="background:' . $bg . ';border:2px solid ' . $border . ';color:' . $color . ';padding:12px 16px;border-radius:6px;margin:15px 0;font-size:14px;font-weight:500;">';
                        echo esc_html($notice['message']);
                        echo '</div>';
                    }
                }
                ?>
                
                <form method="post" enctype="multipart/form-data" style="margin-top: 20px;">
                    <?php wp_nonce_field('mindfulseo_upload_keywords', 'mindfulseo_keywords_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="keyword_csv"><?php _e('CSV File', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <input type="file" name="keyword_csv" id="keyword_csv" accept=".csv,.txt">
                                <p class="description">
                                    <?php _e('Maximum file size: 5MB. Format: CSV with headers.', 'mindfulseo'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="mindfulseo_upload_keywords" class="button button-primary" value="<?php esc_attr_e('Upload & Import', 'mindfulseo'); ?>">
                    </p>
                </form>
                
                <div style="background: #f0f0f1; padding: 15px; border-radius: 4px; margin-top: 20px;">
                    <h4><?php _e('CSV Format Example:', 'mindfulseo'); ?></h4>
                    <pre style="background: #fff; padding: 10px; overflow-x: auto;">PRIMARY KEYWORD,LONGTAIL KEYWORD,SEARCH INTENT,PRIORITY,CURRENT SESSIONS,NOTES
seo services,affordable seo services for small business,Transactional,HIGH,2500,Target local businesses
content marketing,what is content marketing,Informational,MEDIUM,1800,Educational focus
web design,responsive web design examples,Informational,HIGH,3200,Showcase portfolio</pre>
                </div>
            </div>
            
            <?php endif; // End tab conditionals ?>
            
            <?php if ($active_tab === 'manage'): ?>
                <!-- Continue MANAGE TAB: Keywords Table + Add Form -->
            
            <!-- Keywords Table -->
            <div style="background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; margin: 20px 20px 0 0;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
                    <h2 style="margin: 0;"><?php _e('📊 Keyword List', 'mindfulseo'); ?></h2>
                    <div style="display: flex; gap: 10px;">
                        <?php if (!empty($keywords)): ?>
                            <!-- Refresh SEO Data Button -->
                            <?php 
                            $dataforseo_configured = false;
                            $settings = get_option('mindfulseo_settings', array());
                            if (!empty($settings['dataforseo_login']) && !empty($settings['dataforseo_password'])) {
                                $dataforseo_configured = true;
                            }
                            ?>
                            <button type="button" class="button" id="mindfulseo-refresh-seo-data-btn" 
                                    <?php if (!$dataforseo_configured): ?>disabled title="<?php esc_attr_e('Configure DataForSEO API in Settings first', 'mindfulseo'); ?>"<?php endif; ?>>
                                <span class="dashicons dashicons-update"></span>
                                <?php _e('Refresh SEO Data', 'mindfulseo'); ?>
                            </button>
                            
                            <form method="post" action="" style="margin: 0;" id="delete-all-keywords-form">
                                <?php wp_nonce_field('mindfulseo_delete_all_keywords', 'mindfulseo_delete_all_nonce'); ?>
                                <input type="hidden" name="mindfulseo_delete_all_keywords" value="1">
                                <button type="submit" class="button button-secondary" id="mindfulseo-delete-all-keywords-btn"
                                        style="background: #dc3232; color: #fff; border-color: #c62828;">
                                    <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                                    <?php _e('Delete All Keywords', 'mindfulseo'); ?>
                                </button>
                            </form>
                        <?php endif; ?>
                        
                        <!-- Add Keyword Button -->
                        <button type="button" class="button" id="mindfulseo-add-keyword-btn">
                            <span class="dashicons dashicons-plus-alt" style="vertical-align: middle;"></span>
                            <?php _e('Add Keyword', 'mindfulseo'); ?>
                        </button>
                        
                        <!-- AI Cleanup Button -->
                        <button type="button" class="button button-primary" id="mindfulseo-cleanup-keywords-btn">
                            <span class="dashicons dashicons-superhero" style="vertical-align: middle;"></span>
                            <?php _e('AI Cleanup Keywords', 'mindfulseo'); ?>
                        </button>
                    </div>
                </div>
                
                <!-- Add Keyword Form (hidden by default) -->
                <div id="mindfulseo-add-keyword-form" style="display: none; margin: 20px 0; padding: 20px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                    <h3><?php _e('Add New Keyword', 'mindfulseo'); ?></h3>
                    <form method="post">
                        <?php wp_nonce_field('mindfulseo_add_keyword', 'mindfulseo_add_keyword_nonce'); ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="new_primary_keyword"><?php _e('Primary Keyword', 'mindfulseo'); ?> *</label></th>
                                <td><input type="text" name="new_primary_keyword" id="new_primary_keyword" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th><label for="new_longtail_keyword"><?php _e('Longtail Keyword', 'mindfulseo'); ?> *</label></th>
                                <td><input type="text" name="new_longtail_keyword" id="new_longtail_keyword" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th><label for="new_search_intent"><?php _e('Search Intent', 'mindfulseo'); ?></label></th>
                                <td>
                                    <select name="new_search_intent" id="new_search_intent">
                                        <option value="Informational">Informational</option>
                                        <option value="Navigational">Navigational</option>
                                        <option value="Transactional">Transactional</option>
                                        <option value="Commercial">Commercial</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="new_priority"><?php _e('Priority', 'mindfulseo'); ?></label></th>
                                <td>
                                    <select name="new_priority" id="new_priority">
                                        <option value="MEDIUM">MEDIUM</option>
                                        <option value="HIGH">HIGH</option>
                                        <option value="LOW">LOW</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="new_notes"><?php _e('Notes', 'mindfulseo'); ?></label></th>
                                <td><textarea name="new_notes" id="new_notes" class="large-text" rows="3"></textarea></td>
                            </tr>
                        </table>
                        <p>
                            <button type="submit" name="mindfulseo_add_keyword" class="button button-primary"><?php _e('Add Keyword', 'mindfulseo'); ?></button>
                            <button type="button" class="button" id="mindfulseo-cancel-add-keyword"><?php _e('Cancel', 'mindfulseo'); ?></button>
                        </p>
                    </form>
                </div>
                
                <!-- AI Cleanup Modal (hidden by default) -->
                <div id="mindfulseo-cleanup-modal" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #fff; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); padding: 30px; max-width: 800px; max-height: 80vh; overflow-y: auto; z-index: 999999; pointer-events: auto;">
                    <h2 style="margin-top: 0; pointer-events: auto;"><?php _e('🤖 AI Keyword Cleanup', 'mindfulseo'); ?></h2>
                    
                    <!-- Progress State -->
                    <div id="cleanup-progress" style="display: none;">
                        <p><?php _e('Analyzing keywords with AI...', 'mindfulseo'); ?></p>
                        <div style="background: #f0f0f1; height: 20px; border-radius: 10px; overflow: hidden;">
                            <div id="cleanup-progress-bar" style="background: linear-gradient(90deg, #8B0000, #d32f2f); height: 100%; width: 0%; transition: width 0.3s;"></div>
                        </div>
                        <p id="cleanup-status" style="color: #666; font-size: 13px;"></p>
                    </div>
                    
                    <!-- Results State -->
                    <div id="cleanup-results" style="display: none;">
                        <div style="background: #e3f2fd; border-left: 4px solid #2196f3; padding: 15px; margin-bottom: 20px;">
                            <strong><?php _e('AI Review Complete!', 'mindfulseo'); ?></strong>
                            <p id="cleanup-summary" style="margin: 5px 0 0 0;"></p>
                        </div>
                        
                        <h3><?php _e('Suggested Changes:', 'mindfulseo'); ?></h3>
                        <div id="cleanup-changes" style="max-height: 400px; overflow-y: auto;"></div>
                        
                        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; position: relative; z-index: 10;">
                            <button type="button" class="button button-primary button-large" id="cleanup-apply-btn" style="pointer-events: auto; cursor: pointer;">
                                <?php _e('Apply Selected Changes', 'mindfulseo'); ?>
                            </button>
                            <button type="button" class="button button-secondary button-large" id="cleanup-regenerate-btn" style="margin-left: 10px; pointer-events: auto; cursor: pointer;">
                                <?php _e('Regenerate Suggestions', 'mindfulseo'); ?>
                            </button>
                            <button type="button" class="button button-large" id="cleanup-cancel-btn" style="margin-left: 10px; pointer-events: auto; cursor: pointer;">
                                <?php _e('Cancel', 'mindfulseo'); ?>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Modal Backdrop -->
                <div id="mindfulseo-cleanup-backdrop" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 999998;"></div>
                
                <?php if (empty($keywords)): ?>
                    <p><?php _e('No keywords found. Upload a CSV file to get started.', 'mindfulseo'); ?></p>
                <?php else: ?>
                    <div class="mfseo-keyword-table-wrap">
                    <?php if (!empty($keywords) && isset($stats['total'])): ?>
                        <div style="margin-bottom: 15px; padding: 10px; background: #f0f0f1; border-radius: 4px;">
                            <strong><?php printf(__('Showing %d keywords in %d groups', 'mindfulseo'), count($keywords), count($keyword_groups)); ?></strong>
                            <span style="color: #666; margin-left: 10px;">
                                <?php _e('Click any cell to edit inline. Click ▶ to expand longtails.', 'mindfulseo'); ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <table class="wp-list-table widefat fixed striped mfseo-keyword-table">
                        <thead>
                            <tr>
                                <th style="width: 20%; cursor: pointer;" class="sortable" data-sort="primary_keyword">
                                    <?php _e('Keyword', 'mindfulseo'); ?> 
                                    <span class="dashicons dashicons-sort" style="font-size: 14px; vertical-align: middle;"></span>
                                </th>
                                <th style="width: 7%; cursor: pointer;" class="sortable sorted-asc" data-sort="priority">
                                    <?php _e('Priority', 'mindfulseo'); ?>
                                    <span class="dashicons dashicons-sort dashicons-arrow-up" style="font-size: 14px; vertical-align: middle;"></span>
                                </th>
                                <th style="width: 19%; cursor: pointer;" class="sortable" data-sort="longtail_keyword">
                                    <?php _e('Longtails', 'mindfulseo'); ?>
                                    <span class="dashicons dashicons-sort" style="font-size: 14px; vertical-align: middle;"></span>
                                </th>
                                <th style="width: 10%; cursor: pointer;" class="sortable" data-sort="search_volume">
                                    <?php _e('Volume', 'mindfulseo'); ?>
                                    <span class="dashicons dashicons-sort" style="font-size: 14px; vertical-align: middle;"></span>
                                </th>
                                <th style="width: 8%; cursor: pointer;" class="sortable" data-sort="keyword_difficulty">
                                    <?php _e('Difficulty', 'mindfulseo'); ?>
                                    <span class="dashicons dashicons-sort" style="font-size: 14px; vertical-align: middle;"></span>
                                </th>
                                <th style="width: 8%; cursor: pointer;" class="sortable" data-sort="cpc">
                                    <?php _e('CPC', 'mindfulseo'); ?>
                                    <span class="dashicons dashicons-sort" style="font-size: 14px; vertical-align: middle;"></span>
                                </th>
                                <th style="width: 12%; cursor: pointer;" class="sortable" data-sort="search_intent">
                                    <?php _e('Intent', 'mindfulseo'); ?>
                                    <span class="dashicons dashicons-sort" style="font-size: 14px; vertical-align: middle;"></span>
                                </th>
                                <th style="width: 11%;" class="mfseo-kw-col-actions"><?php _e('Actions', 'mindfulseo'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($keyword_groups as $gkey => $group):
                                $rows = $group['rows'];
                                $first = $rows[0];
                                $longtails = array();
                                foreach ($rows as $r) {
                                    if (strtolower(trim($r->longtail_keyword)) !== strtolower(trim($r->primary_keyword)) && !empty($r->longtail_keyword)) {
                                        $longtails[] = $r;
                                    }
                                }
                                $lt_count = count($longtails);
                                $has_toggle = $lt_count > 0;
                                $pm = isset($primary_metrics[$gkey]) ? $primary_metrics[$gkey] : null;
                                $p_vol = $pm && isset($pm['search_volume']) ? $pm['search_volume'] : null;
                                $p_diff = $pm && isset($pm['keyword_difficulty']) ? $pm['keyword_difficulty'] : null;
                                $p_cpc = $pm && isset($pm['cpc']) ? $pm['cpc'] : null;

                                // Collect all row IDs for batch delete
                                $all_ids = array_map(function($r) { return $r->id; }, $rows);
                            ?>
                                <!-- Parent row -->
                                <tr class="mfseo-kw-parent" data-group="<?php echo esc_attr($gkey); ?>"
                                    data-sort-volume="<?php echo $p_vol !== null ? intval($p_vol) : -1; ?>"
                                    data-sort-difficulty="<?php echo $p_diff !== null ? intval($p_diff) : -1; ?>"
                                    data-sort-cpc="<?php echo $p_cpc !== null ? floatval($p_cpc) : -1; ?>">
                                    <td style="white-space: nowrap;">
                                        <?php if ($has_toggle): ?>
                                            <span class="mfseo-kw-toggle">&#9654;</span>
                                        <?php endif; ?>
                                        <strong><span class="editable mfseo-kw-primary-edit" contenteditable="true"
                                            data-id="<?php echo $first->id; ?>"
                                            data-field="primary_keyword"
                                            data-group-ids="<?php echo esc_attr(implode(',', $all_ids)); ?>"
                                            data-original="<?php echo esc_attr($first->primary_keyword); ?>"><?php echo esc_html($first->primary_keyword); ?></span></strong>
                                    </td>
                                    <td data-sort-value="<?php echo (int) $kw_priority_rank( $first->priority ); ?>">
                                        <select class="editable-select" data-id="<?php echo $first->id; ?>" data-field="priority">
                                            <option value="HIGH" <?php selected($first->priority, 'HIGH'); ?>>HIGH</option>
                                            <option value="MEDIUM" <?php selected($first->priority, 'MEDIUM'); ?>>MEDIUM</option>
                                            <option value="LOW" <?php selected($first->priority, 'LOW'); ?>>LOW</option>
                                        </select>
                                    </td>
                                    <td>
                                        <?php if ($has_toggle): ?>
                                            <span class="mfseo-lt-count"><?php echo $lt_count; ?> longtail<?php echo $lt_count !== 1 ? 's' : ''; ?></span>
                                        <?php else: ?>
                                            <span style="color: #999; font-size: 12px;"><?php _e('No longtails', 'mindfulseo'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;" data-sort-value="<?php echo $p_vol !== null ? intval($p_vol) : -1; ?>">
                                        <?php if ($p_vol !== null): ?>
                                            <strong><?php echo number_format(intval($p_vol)); ?></strong>
                                        <?php else: ?>
                                            <span style="color: #999;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;" data-sort-value="<?php echo $p_diff !== null ? intval($p_diff) : -1; ?>">
                                        <?php if ($p_diff !== null): ?>
                                            <?php $color = intval($p_diff) < 30 ? '#46b450' : (intval($p_diff) < 70 ? '#ffb900' : '#dc3232'); ?>
                                            <span style="color: <?php echo $color; ?>; font-weight: 600;"><?php echo intval($p_diff); ?></span>
                                        <?php else: ?>
                                            <span style="color: #999;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;" data-sort-value="<?php echo $p_cpc !== null ? floatval($p_cpc) : -1; ?>">
                                        <?php if ($p_cpc !== null): ?>
                                            $<?php echo number_format(floatval($p_cpc), 2); ?>
                                        <?php else: ?>
                                            <span style="color: #999;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <select class="editable-select" data-id="<?php echo $first->id; ?>" data-field="search_intent">
                                            <option value="Informational" <?php selected($first->search_intent, 'Informational'); ?>>Informational</option>
                                            <option value="Navigational" <?php selected($first->search_intent, 'Navigational'); ?>>Navigational</option>
                                            <option value="Transactional" <?php selected($first->search_intent, 'Transactional'); ?>>Transactional</option>
                                            <option value="Commercial" <?php selected($first->search_intent, 'Commercial'); ?>>Commercial</option>
                                        </select>
                                    </td>
                                    <td class="mfseo-kw-col-actions">
                                        <form method="post" style="display: inline;">
                                            <?php wp_nonce_field('mindfulseo_delete_keyword', 'mindfulseo_delete_nonce'); ?>
                                            <input type="hidden" name="keyword_id" value="<?php echo intval($first->id); ?>">
                                            <?php if ($lt_count > 0): ?>
                                                <?php foreach ($all_ids as $aid): ?>
                                                    <input type="hidden" name="keyword_group_ids[]" value="<?php echo intval($aid); ?>">
                                                <?php endforeach; ?>
                                                <button type="submit" name="mindfulseo_delete_keyword_group" class="button button-small"
                                                        onclick="return confirm('<?php printf(esc_attr__('Delete "%s" and all %d longtails?', 'mindfulseo'), esc_attr($first->primary_keyword), $lt_count); ?>');">
                                                    <?php _e('Delete All', 'mindfulseo'); ?>
                                                </button>
                                            <?php else: ?>
                                                <button type="submit" name="mindfulseo_delete_keyword" class="button button-small"
                                                        onclick="return confirm('<?php esc_attr_e('Delete this keyword?', 'mindfulseo'); ?>');">
                                                    <?php _e('Delete', 'mindfulseo'); ?>
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                    </td>
                                </tr>

                                <?php if ($has_toggle): foreach ($longtails as $lt_row): ?>
                                <!-- Child / longtail row -->
                                <tr class="mfseo-kw-child" data-group="<?php echo esc_attr($gkey); ?>" style="display: none;">
                                    <td>
                                        <span class="mfseo-lt-arrow">↳</span>
                                        <span class="editable" contenteditable="true"
                                            data-id="<?php echo $lt_row->id; ?>"
                                            data-field="longtail_keyword"
                                            data-original="<?php echo esc_attr($lt_row->longtail_keyword); ?>"><?php echo esc_html($lt_row->longtail_keyword); ?></span>
                                    </td>
                                    <td data-sort-value="<?php echo (int) $kw_priority_rank( $lt_row->priority ); ?>">
                                        <select class="editable-select" data-id="<?php echo $lt_row->id; ?>" data-field="priority">
                                            <option value="HIGH" <?php selected($lt_row->priority, 'HIGH'); ?>>HIGH</option>
                                            <option value="MEDIUM" <?php selected($lt_row->priority, 'MEDIUM'); ?>>MEDIUM</option>
                                            <option value="LOW" <?php selected($lt_row->priority, 'LOW'); ?>>LOW</option>
                                        </select>
                                    </td>
                                    <td></td>
                                    <td style="text-align: center;" data-sort-value="<?php echo isset($lt_row->search_volume) && $lt_row->search_volume !== null ? intval($lt_row->search_volume) : -1; ?>">
                                        <?php if (isset($lt_row->search_volume) && $lt_row->search_volume !== null): ?>
                                            <?php echo number_format(intval($lt_row->search_volume)); ?>
                                        <?php else: ?>
                                            <span style="color: #999;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;" data-sort-value="<?php echo isset($lt_row->keyword_difficulty) && $lt_row->keyword_difficulty !== null ? intval($lt_row->keyword_difficulty) : -1; ?>">
                                        <?php if (isset($lt_row->keyword_difficulty) && $lt_row->keyword_difficulty !== null): ?>
                                            <?php $d = intval($lt_row->keyword_difficulty); $c = $d < 30 ? '#46b450' : ($d < 70 ? '#ffb900' : '#dc3232'); ?>
                                            <span style="color: <?php echo $c; ?>; font-weight: 600;"><?php echo $d; ?></span>
                                        <?php else: ?>
                                            <span style="color: #999;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;" data-sort-value="<?php echo isset($lt_row->cpc) && $lt_row->cpc !== null ? floatval($lt_row->cpc) : -1; ?>">
                                        <?php if (isset($lt_row->cpc) && $lt_row->cpc !== null): ?>
                                            $<?php echo number_format(floatval($lt_row->cpc), 2); ?>
                                        <?php else: ?>
                                            <span style="color: #999;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <select class="editable-select" data-id="<?php echo $lt_row->id; ?>" data-field="search_intent">
                                            <option value="Informational" <?php selected($lt_row->search_intent, 'Informational'); ?>>Informational</option>
                                            <option value="Navigational" <?php selected($lt_row->search_intent, 'Navigational'); ?>>Navigational</option>
                                            <option value="Transactional" <?php selected($lt_row->search_intent, 'Transactional'); ?>>Transactional</option>
                                            <option value="Commercial" <?php selected($lt_row->search_intent, 'Commercial'); ?>>Commercial</option>
                                        </select>
                                    </td>
                                    <td class="mfseo-kw-col-actions">
                                        <form method="post" style="display: inline;">
                                            <?php wp_nonce_field('mindfulseo_delete_keyword', 'mindfulseo_delete_nonce'); ?>
                                            <input type="hidden" name="keyword_id" value="<?php echo intval($lt_row->id); ?>">
                                            <button type="submit" name="mindfulseo_delete_keyword" class="button button-small"
                                                    onclick="return confirm('<?php esc_attr_e('Delete this longtail?', 'mindfulseo'); ?>');">
                                                <?php _e('Delete', 'mindfulseo'); ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; endif; ?>

                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            </div>
            
        </div>
        
        <style>
            .mindfulseo-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
            }
            .mindfulseo-badge-informational {
                background: #e3f2fd;
                color: #1976d2;
            }
            .mindfulseo-badge-navigational {
                background: #f3e5f5;
                color: #7b1fa2;
            }
            .mindfulseo-badge-transactional {
                background: #fff3e0;
                color: #f57c00;
            }
            .mindfulseo-badge-priority-high {
                background: #ffebee;
                color: #c62828;
            }
            .mindfulseo-badge-priority-medium {
                background: #fff9c4;
                color: #f57f17;
            }
            .mindfulseo-badge-priority-low {
                background: #e0f2f1;
                color: #00695c;
            }
        </style>
        
        <?php endif; // End manage tab ?>
        
        </div><!-- .mindfulseo-tab-content -->
        </div><!-- .wrap -->
        <?php
    }
    
    /**
     * Render guidelines page
     */
    public function render_guidelines_page() {
        // Initialize guidelines engine
        $guidelines_engine = null;
        if (class_exists('MFSEO_Guidelines_Engine')) {
            $guidelines_engine = MFSEO_Guidelines_Engine::get_instance();
        }

        // Handle markdown file upload
        if (isset($_POST['mindfulseo_upload_guidelines']) && check_admin_referer('mindfulseo_upload_guidelines', 'mindfulseo_guidelines_nonce')) {
            if (isset($_FILES['guidelines_file']) && $guidelines_engine) {
                $result = $guidelines_engine->import_guidelines($_FILES['guidelines_file']);
                
                if (is_wp_error($result)) {
                    add_settings_error(
                        'mindfulseo_guidelines',
                        'import_error',
                        $result->get_error_message(),
                        'error'
                    );
                } else {
                    add_settings_error(
                        'mindfulseo_guidelines',
                        'import_success',
                        sprintf(
                            __('Successfully imported %d guideline rules from %s', 'mindfulseo'),
                            $result['rules_imported'],
                            $result['filename']
                        ),
                        'success'
                    );
                }
            }
        }
        
        // Handle CSV guideline import
        if (isset($_POST['mindfulseo_upload_guidelines_csv']) && check_admin_referer('mindfulseo_upload_guidelines_csv', 'mindfulseo_guidelines_csv_nonce')) {
            if (!empty($_FILES['guidelines_csv_file']['name']) && $guidelines_engine) {
                $file = $_FILES['guidelines_csv_file'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                if (!in_array($ext, array('csv', 'txt'))) {
                    add_settings_error('mindfulseo_guidelines', 'csv_error', __('Invalid file type. Please upload a CSV file.', 'mindfulseo'), 'error');
                } else {
                    $handle = fopen($file['tmp_name'], 'r');
                    if (!$handle) {
                        add_settings_error('mindfulseo_guidelines', 'csv_error', __('Cannot open file.', 'mindfulseo'), 'error');
                    } else {
                        $header = fgetcsv($handle);
                        if ($header) {
                            // Strip BOM and normalize
                            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
                            $header = array_map(function($h) {
                                return strtoupper(trim(str_replace(array('_', '-'), ' ', $h)));
                            }, $header);
                        }
                        
                        if (!$header || !in_array('RULE TYPE', $header)) {
                            fclose($handle);
                            add_settings_error('mindfulseo_guidelines', 'csv_error', __('Missing required column: RULE TYPE. Expected columns: RULE TYPE, AVOID TERM, PREFERRED TERM', 'mindfulseo'), 'error');
                        } else {
                            $imported = 0;
                            $skipped = 0;
                            
                            while (($row = fgetcsv($handle)) !== false) {
                                if (empty(array_filter($row))) continue;
                                
                                $data = array();
                                foreach ($header as $i => $col) {
                                    $data[$col] = isset($row[$i]) ? trim($row[$i]) : '';
                                }
                                
                                $rule_type = strtolower($data['RULE TYPE']);
                                $valid_types = array('capitalize', 'preferred_term', 'avoid_term', 'seo_friendly');
                                if (!in_array($rule_type, $valid_types)) {
                                    $skipped++;
                                    continue;
                                }
                                
                                $result = $guidelines_engine->add_rule(array(
                                    'rule_type' => $rule_type,
                                    'avoid_term' => isset($data['AVOID TERM']) ? $data['AVOID TERM'] : (isset($data['AVOID FROM']) ? $data['AVOID FROM'] : ''),
                                    'preferred_term' => isset($data['PREFERRED TERM']) ? $data['PREFERRED TERM'] : (isset($data['PREFERRED TO']) ? $data['PREFERRED TO'] : ''),
                                    'context' => isset($data['CONTEXT']) ? $data['CONTEXT'] : 'Imported from CSV',
                                    'guideline_source' => 'CSV Import',
                                    'active' => true,
                                ));
                                
                                if (!is_wp_error($result)) {
                                    $imported++;
                                } else {
                                    $skipped++;
                                }
                            }
                            
                            fclose($handle);
                            
                            if ($imported > 0) {
                                add_settings_error('mindfulseo_guidelines', 'csv_success', sprintf(__('Successfully imported %d guideline rules from CSV (%d skipped).', 'mindfulseo'), $imported, $skipped), 'success');
                            } else {
                                add_settings_error('mindfulseo_guidelines', 'csv_error', sprintf(__('No rules imported. %d rows skipped. Check that RULE TYPE values are: capitalize, preferred_term, avoid_term, or seo_friendly.', 'mindfulseo'), $skipped), 'error');
                            }
                        }
                    }
                }
            } else {
                add_settings_error('mindfulseo_guidelines', 'csv_error', __('No file selected. Please choose a CSV file to upload.', 'mindfulseo'), 'error');
            }
        }
        
        // Handle auto-generate guidelines
        if (isset($_POST['mindfulseo_autogenerate_guidelines']) && check_admin_referer('mindfulseo_autogenerate_guidelines', 'mindfulseo_guidelines_autogen_nonce')) {
            @set_time_limit(300);

            $post_types = isset($_POST['analyze_guideline_post_types']) ? array_map('sanitize_text_field', $_POST['analyze_guideline_post_types']) : array('post');
            $limit = isset($_POST['analyze_guideline_limit']) ? intval($_POST['analyze_guideline_limit']) : 100;

            if (!class_exists('MFSEO_Content_Analyzer')) {
                add_settings_error('mindfulseo_guidelines', 'analyzer_missing', __('Content Analyzer class not found. Please deactivate and reactivate the plugin.', 'mindfulseo'), 'error');
            } elseif (!$guidelines_engine) {
                add_settings_error('mindfulseo_guidelines', 'engine_missing', __('Guidelines engine not available.', 'mindfulseo'), 'error');
            } else {
                $analyzer = new MFSEO_Content_Analyzer();
                $deep_analysis = !empty($_POST['deep_analysis']);
                $manual_gl = $guidelines_engine->get_editor_policy_snapshot_text();
                $wizard_gl_payload = '';
                if ($manual_gl !== '') {
                    $wizard_gl_payload = "=== USER-DEFINED AND IMPORTED RULES (authoritative — never contradict; extend with complementary rules only) ===\n" . $manual_gl;
                }
                $suggestions = $analyzer->analyze_for_guidelines(array(
                    'post_types' => $post_types,
                    'limit' => $limit,
                    'deep_analysis' => $deep_analysis,
                    'wizard_guidelines_snapshot' => $wizard_gl_payload,
                    'ai_usage_context' => 'guidelines_page_autogenerate',
                ));
                
                if (empty($suggestions)) {
                    add_settings_error(
                        'mindfulseo_guidelines',
                        'analyze_empty',
                        __('No patterns found. Try analyzing more posts or different post types.', 'mindfulseo'),
                        'warning'
                    );
                } else {
                    $imported = 0;

                    $ai_cap_lower = array();
                    if (!empty($suggestions['ai_guidelines'])) {
                        foreach ($suggestions['ai_guidelines'] as $ar) {
                            if (isset($ar['type']) && $ar['type'] === 'capitalize' && !empty($ar['preferred'])) {
                                $ai_cap_lower[strtolower($ar['preferred'])] = true;
                            }
                        }
                    }

                    // AI-generated guidelines first (all types)
                    if (!empty($suggestions['ai_guidelines'])) {
                        foreach ($suggestions['ai_guidelines'] as $ai_rule) {
                            $rule_type = $ai_rule['type'];
                            $avoid = isset($ai_rule['avoid']) ? $ai_rule['avoid'] : '';
                            $preferred = $ai_rule['preferred'];
                            $context = !empty($ai_rule['context']) ? $ai_rule['context'] : 'AI-generated';
                            
                            if ($rule_type === 'capitalize' && empty($avoid)) {
                                $avoid = strtolower($preferred);
                            }
                            
                            $result = $guidelines_engine->add_rule(array(
                                'rule_type' => $rule_type,
                                'avoid_term' => $avoid,
                                'preferred_term' => $preferred,
                                'context' => $context,
                                'guideline_source' => 'AI-generated',
                                'active' => true
                            ));
                            if (!is_wp_error($result)) {
                                $imported++;
                            }
                        }
                    }

                    // Pattern-based capitalize: capped; after AI; dedupe AI capitalize
                    if (!empty($suggestions['capitalize_terms'])) {
                        $ai_ok = !empty($suggestions['ai_succeeded']);
                        $max_pat = $ai_ok ? 10 : 28;
                        $added_pat = 0;
                        foreach ($suggestions['capitalize_terms'] as $term) {
                            if ($added_pat >= $max_pat) {
                                break;
                            }
                            $low = strtolower($term);
                            if ($ai_ok && isset($ai_cap_lower[$low])) {
                                continue;
                            }
                            $result = $guidelines_engine->add_rule(array(
                                'rule_type' => 'capitalize',
                                'avoid_term' => $low,
                                'preferred_term' => $term,
                                'context' => 'Auto-generated from content analysis',
                                'guideline_source' => 'Auto-generated',
                                'active' => true
                            ));
                            if (!is_wp_error($result)) {
                                $imported++;
                                $added_pat++;
                            }
                        }
                    }
                    
                    // Fallback: pattern-based rules only when AI didn't succeed
                    if (empty($suggestions['ai_succeeded'])) {
                        if (!empty($suggestions['preferred_terms'])) {
                            foreach ($suggestions['preferred_terms'] as $term) {
                                $result = $guidelines_engine->add_rule(array(
                                    'rule_type' => 'preferred_term',
                                    'avoid_term' => '',
                                    'preferred_term' => $term,
                                    'context' => 'Auto-generated from content analysis',
                                    'guideline_source' => 'Auto-generated',
                                    'active' => true
                                ));
                                if (!is_wp_error($result)) {
                                    $imported++;
                                }
                            }
                        }
                        
                        if (!empty($suggestions['avoid_terms'])) {
                            foreach ($suggestions['avoid_terms'] as $avoid_rule) {
                                $result = $guidelines_engine->add_rule(array(
                                    'rule_type' => 'avoid_term',
                                    'avoid_term' => $avoid_rule['avoid'],
                                    'preferred_term' => $avoid_rule['preferred'],
                                    'context' => 'Auto-generated from content analysis',
                                    'guideline_source' => 'Auto-generated',
                                    'active' => true
                                ));
                                if (!is_wp_error($result)) {
                                    $imported++;
                                }
                            }
                        }
                        
                        if (!empty($suggestions['common_phrases'])) {
                            foreach (array_slice($suggestions['common_phrases'], 0, 15) as $phrase) {
                                $result = $guidelines_engine->add_rule(array(
                                    'rule_type' => 'seo_friendly',
                                    'avoid_term' => '',
                                    'preferred_term' => $phrase,
                                    'context' => 'Common phrase from content analysis',
                                    'guideline_source' => 'Auto-generated',
                                    'active' => true
                                ));
                                if (!is_wp_error($result)) {
                                    $imported++;
                                }
                            }
                        }
                        
                        if (!empty($suggestions['semantic_avoid_terms'])) {
                            foreach ($suggestions['semantic_avoid_terms'] as $semantic_rule) {
                                $result = $guidelines_engine->add_rule(array(
                                    'rule_type' => 'avoid_term',
                                    'avoid_term' => $semantic_rule['avoid'],
                                    'preferred_term' => $semantic_rule['preferred'],
                                    'context' => !empty($semantic_rule['context']) ? $semantic_rule['context'] : 'AI-generated domain-specific rule',
                                    'guideline_source' => 'AI-generated',
                                    'active' => true
                                ));
                                if (!is_wp_error($result)) {
                                    $imported++;
                                }
                            }
                        }
                    }
                    
                    add_settings_error(
                        'mindfulseo_guidelines',
                        'analyze_success',
                        sprintf(
                            __('Successfully analyzed content and imported %d guideline suggestions!', 'mindfulseo'),
                            $imported
                        ),
                        'success'
                    );
                }
            }
        }
        
        // Handle delete all guidelines
        if (isset($_POST['mindfulseo_delete_all_guidelines']) && check_admin_referer('mindfulseo_delete_all_guidelines', 'mindfulseo_delete_all_nonce')) {
            if (!$guidelines_engine) {
                add_settings_error('mindfulseo_guidelines', 'engine_missing', __('Guidelines engine not available.', 'mindfulseo'), 'error');
            } else {
                global $wpdb;
                $table_name = $wpdb->prefix . 'mindfulseo_guidelines';
                $deleted = 0;
                if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name) {
                    $deleted = (int) $wpdb->query("DELETE FROM {$table_name}");
                }
                if ($deleted > 0) {
                    add_settings_error('mindfulseo_guidelines', 'delete_all_success', sprintf(__('%d guidelines deleted successfully.', 'mindfulseo'), $deleted), 'success');
                } else {
                    add_settings_error('mindfulseo_guidelines', 'delete_all_empty', __('No guidelines found to delete.', 'mindfulseo'), 'warning');
                }
            }
        }
        
        // Handle add guideline
        if (isset($_POST['mindfulseo_add_guideline']) && check_admin_referer('mindfulseo_add_guideline', 'mindfulseo_add_guideline_nonce')) {
            if ($guidelines_engine) {
                $rule_type = sanitize_text_field($_POST['new_rule_type']);
                $avoid_term = sanitize_text_field($_POST['new_avoid_term']);
                $preferred_term = sanitize_text_field($_POST['new_preferred_term']);
                $context = sanitize_textarea_field($_POST['new_context']);
                
                if (!empty($avoid_term)) {
                    $result = $guidelines_engine->add_rule(array(
                        'rule_type' => $rule_type,
                        'avoid_term' => $avoid_term,
                        'preferred_term' => $preferred_term,
                        'context' => $context,
                        'guideline_source' => 'manual',
                        'active' => true
                    ));
                    
                    if ($result) {
                        add_settings_error(
                            'mindfulseo_guidelines',
                            'add_success',
                            __('Guideline added successfully!', 'mindfulseo'),
                            'success'
                        );
                    } else {
                        add_settings_error(
                            'mindfulseo_guidelines',
                            'add_error',
                            __('Failed to add guideline.', 'mindfulseo'),
                            'error'
                        );
                    }
                }
            }
        }
        
        // Handle toggle guideline active status
        if (isset($_POST['mindfulseo_toggle_guideline']) && check_admin_referer('mindfulseo_toggle_guideline', 'mindfulseo_toggle_nonce')) {
            if (isset($_POST['guideline_id']) && $guidelines_engine) {
                $guideline_id = intval($_POST['guideline_id']);
                $current_status = isset($_POST['current_status']) ? intval($_POST['current_status']) : 0;
                $new_status = $current_status ? 0 : 1;
                
                if ($new_status) {
                    $guidelines_engine->activate_rule($guideline_id);
                } else {
                    $guidelines_engine->deactivate_rule($guideline_id);
                }
            }
        }
        
        // Handle delete individual guideline
        if (isset($_POST['mindfulseo_delete_guideline']) && check_admin_referer('mindfulseo_delete_guideline', 'mindfulseo_delete_guideline_nonce')) {
            if (isset($_POST['guideline_id']) && $guidelines_engine) {
                $deleted = $guidelines_engine->delete_rule(intval($_POST['guideline_id']));
                if ($deleted) {
                    add_settings_error('mindfulseo_guidelines', 'delete_success', __('Guideline deleted successfully.', 'mindfulseo'), 'success');
                } else {
                    add_settings_error('mindfulseo_guidelines', 'delete_error', __('Could not delete guideline. It may have already been removed.', 'mindfulseo'), 'warning');
                }
            }
        }
        
        // Handle rule toggle
        if (isset($_POST['mindfulseo_toggle_rule']) && check_admin_referer('mindfulseo_toggle_rule', 'mindfulseo_rule_nonce')) {
            if (isset($_POST['rule_id']) && isset($_POST['action_type']) && $guidelines_engine) {
                $rule_id = intval($_POST['rule_id']);
                if ($_POST['action_type'] === 'activate') {
                    $guidelines_engine->activate_rule($rule_id);
                } else {
                    $guidelines_engine->deactivate_rule($rule_id);
                }
            }
        }
        
        // Handle rule deletion
        if (isset($_POST['mindfulseo_delete_rule']) && check_admin_referer('mindfulseo_delete_rule', 'mindfulseo_rule_delete_nonce')) {
            if (isset($_POST['rule_id']) && $guidelines_engine) {
                $deleted = $guidelines_engine->delete_rule(intval($_POST['rule_id']));
                if ($deleted) {
                    add_settings_error('mindfulseo_guidelines', 'delete_success', __('Guideline rule deleted successfully.', 'mindfulseo'), 'success');
                } else {
                    add_settings_error('mindfulseo_guidelines', 'delete_error', __('Could not delete rule. It may have already been removed.', 'mindfulseo'), 'warning');
                }
            }
        }
        
        // Get rules and stats
        $rules = array();
        $stats = array();
        if ($guidelines_engine) {
            $rules = $guidelines_engine->get_all_rules(array('active_only' => false));
            $stats = $guidelines_engine->get_statistics();
            $mfseo_gl_src_rank = function ($src) {
                $s = (string) $src;
                if ($s === '') {
                    return 3;
                }
                if (strpos($s, 'Wizard Import') === 0 || $s === 'CSV Import' || $s === 'manual' || $s === 'Manual') {
                    return 0;
                }
                if ($s === 'AI-generated' || $s === 'Auto-generated') {
                    return 2;
                }
                return 1;
            };
            usort(
                $rules,
                function ($a, $b) use ($mfseo_gl_src_rank) {
                    $ra = $mfseo_gl_src_rank(isset($a->guideline_source) ? $a->guideline_source : '');
                    $rb = $mfseo_gl_src_rank(isset($b->guideline_source) ? $b->guideline_source : '');
                    if ($ra !== $rb) {
                        return $ra - $rb;
                    }
                    $ca = isset($a->rule_type) ? $a->rule_type : '';
                    $cb = isset($b->rule_type) ? $b->rule_type : '';
                    $cmp = strcmp($ca, $cb);
                    if (0 !== $cmp) {
                        return $cmp;
                    }
                    return (int) $a->id - (int) $b->id;
                }
            );
        }
        
        // Get active tab
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'manage';
        
        ?>
        <!-- Output WordPress notices OUTSIDE and BEFORE the wrap div -->
        <?php settings_errors('mindfulseo_guidelines'); ?>
        
        <?php echo $this->get_branding_header('Language Guidelines'); ?>
        
        <div class="wrap mindfulseo-guidelines-wrap">
            
            <!-- Tab Navigation -->
            <nav class="nav-tab-wrapper wp-clearfix">
                <a href="<?php echo esc_url(add_query_arg(array('page' => 'mindfulseo-guidelines', 'tab' => 'manage'), admin_url('admin.php'))); ?>" 
                   class="nav-tab <?php echo $active_tab === 'manage' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-list-view"></span>
                    <?php _e('Manage Guidelines', 'mindfulseo'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg(array('page' => 'mindfulseo-guidelines', 'tab' => 'import'), admin_url('admin.php'))); ?>" 
                   class="nav-tab <?php echo $active_tab === 'import' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-upload"></span>
                    <?php _e('Import File', 'mindfulseo'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg(array('page' => 'mindfulseo-guidelines', 'tab' => 'autogenerate'), admin_url('admin.php'))); ?>" 
                   class="nav-tab <?php echo $active_tab === 'autogenerate' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-generic"></span>
                    <?php _e('Auto-Generate', 'mindfulseo'); ?>
                </a>
            </nav>
            
            <!-- Tab Content -->
            <div class="mindfulseo-tab-content">
            
            <?php if ($active_tab === 'manage'): ?>
                <!-- MANAGE TAB: View/Edit Guidelines -->
                
            <!-- Statistics Cards -->
            <div class="mindfulseo-stats-grid" style="margin: 20px 20px 20px 0;">
                <div class="mindfulseo-stat-card">
                    <h3><?php _e('Total Rules', 'mindfulseo'); ?></h3>
                    <p class="stat-number"><?php echo isset($stats['total']) ? intval($stats['total']) : 0; ?></p>
                </div>
                <div class="mindfulseo-stat-card">
                    <h3><?php _e('Avoid Terms', 'mindfulseo'); ?></h3>
                    <p class="stat-number"><?php echo isset($stats['by_type']['avoid_term']->count) ? intval($stats['by_type']['avoid_term']->count) : 0; ?></p>
                </div>
                <div class="mindfulseo-stat-card">
                    <h3><?php _e('Capitalize Rules', 'mindfulseo'); ?></h3>
                    <p class="stat-number"><?php echo isset($stats['by_type']['capitalize']->count) ? intval($stats['by_type']['capitalize']->count) : 0; ?></p>
                </div>
                <div class="mindfulseo-stat-card">
                    <h3><?php _e('SEO Friendly', 'mindfulseo'); ?></h3>
                    <p class="stat-number"><?php echo isset($stats['by_type']['seo_friendly']->count) ? intval($stats['by_type']['seo_friendly']->count) : 0; ?></p>
                </div>
            </div>
            
            <?php elseif ($active_tab === 'autogenerate'): ?>
                <!-- AUTO-GENERATE TAB -->
            
            <!-- Auto-Generate Section -->
            <div style="background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; margin: 20px 20px 0 0;">
                <h2><?php _e('🤖 Auto-Generate Guidelines', 'mindfulseo'); ?></h2>
                <p><?php _e('Analyze your existing content to automatically discover brand voice patterns and language consistency rules.', 'mindfulseo'); ?></p>

                <?php
                // Get content analyzer
                $analyzer = new MFSEO_Content_Analyzer();
                $post_types = $analyzer->get_supported_post_types();
                ?>
                
                <form method="post" id="autogenerate-guidelines-form" style="margin-top: 20px;">
                    <?php wp_nonce_field('mindfulseo_autogenerate_guidelines', 'mindfulseo_guidelines_autogen_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="analyze_guideline_post_types"><?php _e('Content Types to Analyze', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <?php foreach ($post_types as $type => $label): ?>
                                    <?php $count = $analyzer->get_post_count($type); ?>
                                    <label style="display: block; margin-bottom: 8px;">
                                        <input type="checkbox" 
                                               name="analyze_guideline_post_types[]" 
                                               value="<?php echo esc_attr($type); ?>" 
                                               <?php checked(in_array($type, array('post', 'page'))); ?>>
                                        <?php echo esc_html($label); ?> 
                                        <span style="color: #666;">(<?php echo number_format($count); ?> <?php _e('published', 'mindfulseo'); ?>)</span>
                                    </label>
                                <?php endforeach; ?>
                                <p class="description"><?php _e('Select which content types to analyze for language patterns.', 'mindfulseo'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="analyze_guideline_limit"><?php _e('Posts to Analyze', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <input type="number" 
                                       id="analyze_guideline_limit" 
                                       name="analyze_guideline_limit" 
                                       value="100" 
                                       min="20" 
                                       max="1000" 
                                       step="10" 
                                       class="small-text">
                                <p class="description"><?php _e('Number of posts to analyze (more posts = better pattern detection)', 'mindfulseo'); ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" name="mindfulseo_autogenerate_guidelines" class="button button-primary" id="mfseo-autogen-guidelines-btn">
                            <?php _e('Analyze Content & Generate Guidelines', 'mindfulseo'); ?>
                        </button>
                    </p>
                </form>
                
                <!-- Custom AI Prompts Section -->
                <?php
                $settings = get_option('mindfulseo_settings', array());
                $guideline_prompt = isset($settings['guideline_generation_prompt']) ? $settings['guideline_generation_prompt'] : '';
                ?>
                <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
                    <h3 style="font-size: 14px; margin-bottom: 10px;">
                        <span class="dashicons dashicons-admin-generic" style="vertical-align: middle;"></span>
                        <?php _e('Custom AI Instructions for Guideline Generation', 'mindfulseo'); ?>
                    </h3>
                    <p class="description" style="margin-bottom: 15px;">
                        <?php _e('Add custom instructions to guide the AI when analyzing language patterns. These will be appended to the default system prompt.', 'mindfulseo'); ?>
                    </p>
                    <textarea id="guideline-generation-prompt" 
                              name="guideline_generation_prompt" 
                              rows="6" 
                              style="width: 100%; font-family: monospace; font-size: 13px;"
                              class="large-text code"
                              placeholder="<?php esc_attr_e('Example: Always capitalize brand names and proper nouns. Use preferred terminology consistently. Maintain a professional tone.', 'mindfulseo'); ?>"><?php echo esc_textarea($guideline_prompt); ?></textarea>
                    <p class="description" style="margin-top: 8px;">
                        <?php _e('💡 Tip: Be specific about terminology preferences, formality level, and cultural sensitivity requirements.', 'mindfulseo'); ?>
                    </p>
                    <div style="margin-top: 15px;">
                        <button type="button" class="button button-primary save-prompt-btn" data-prompt-type="guideline_generation">
                            <span class="dashicons dashicons-yes"></span>
                            <?php _e('Save Custom Instructions', 'mindfulseo'); ?>
                        </button>
                        <button type="button" class="button reset-prompt-btn" data-prompt-type="guideline_generation">
                            <span class="dashicons dashicons-update"></span>
                            <?php _e('Reset to Default', 'mindfulseo'); ?>
                        </button>
                        <span class="prompt-save-status" style="margin-left: 10px; color: #46b450; display: none;">
                            <span class="dashicons dashicons-yes"></span>
                            <?php _e('Saved!', 'mindfulseo'); ?>
                        </span>
                    </div>
                </div>
                
                <div id="autogenerate-guidelines-results" style="margin-top: 20px;"></div>
            </div>
            
            <?php elseif ($active_tab === 'import'): ?>
                <!-- IMPORT TAB -->
            
            <!-- Upload Section -->
            <div style="background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; margin: 20px 20px 0 0;">
                <h2><?php _e('📤 Upload Guidelines File', 'mindfulseo'); ?></h2>
                <p><?php _e('Upload a Markdown (.md) file with your brand language guidelines. The file will be parsed to extract language rules.', 'mindfulseo'); ?></p>
                
                <?php
                $md_notices = get_settings_errors('mindfulseo_guidelines');
                if (!empty($md_notices)) {
                    foreach ($md_notices as $notice) {
                        if ($notice['code'] === 'import_success' || $notice['code'] === 'import_error') {
                            $bg = $notice['type'] === 'success' ? '#d4edda' : '#f8d7da';
                            $color = $notice['type'] === 'success' ? '#155724' : '#721c24';
                            $border = $notice['type'] === 'success' ? '#c3e6cb' : '#f5c6cb';
                            printf(
                                '<div style="background:%s;color:%s;border:1px solid %s;padding:12px 16px;border-radius:6px;margin:10px 0 15px;">%s</div>',
                                $bg, $color, $border, esc_html($notice['message'])
                            );
                        }
                    }
                }
                ?>
                
                <form method="post" enctype="multipart/form-data" style="margin-top: 20px;"
                      action="<?php echo esc_url(add_query_arg(array('page' => 'mindfulseo-guidelines', 'tab' => 'import'), admin_url('admin.php'))); ?>">
                    <?php wp_nonce_field('mindfulseo_upload_guidelines', 'mindfulseo_guidelines_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="guidelines_file"><?php _e('Markdown File', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <input type="file" name="guidelines_file" id="guidelines_file" accept=".md,.txt,.markdown" required>
                                <p class="description">
                                    <?php _e('Maximum file size: 2MB. Format: Markdown with structured rules.', 'mindfulseo'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="mindfulseo_upload_guidelines" class="button button-primary" value="<?php esc_attr_e('Upload & Import', 'mindfulseo'); ?>">
                    </p>
                </form>
                
                <div style="background: #f0f0f1; padding: 15px; border-radius: 4px; margin-top: 20px;">
                    <h4><?php _e('Markdown Format Example:', 'mindfulseo'); ?></h4>
                    <pre style="background: #fff; padding: 10px; overflow-x: auto;">## Brand Voice Guidelines

- **Avoid:** "cheap" → Use "affordable"
- **Avoid:** "buy now" → Use "get started"
- **Capitalize:** Company Name, Product Names, Brand Terms
- **Preferred:** "Our customers" (not "clients" or "users")
- **SEO-Friendly:** "professional services" instead of "expert services"

## Industry-Specific Terms

- **Avoid:** technical jargon → Use "simple explanations"
- **Capitalize:** Important Industry Terms
- **Preferred:** Use active voice over passive voice</pre>
                </div>
            </div>
            
            <!-- CSV Import Section -->
            <div style="background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; margin: 20px 20px 0 0;">
                <h2><?php _e('📊 Import CSV', 'mindfulseo'); ?></h2>
                <p><?php _e('Import guidelines from a CSV file. Flexible column headers (underscores, hyphens, any case).', 'mindfulseo'); ?></p>
                
                <?php
                $csv_notices = get_settings_errors('mindfulseo_guidelines');
                if (!empty($csv_notices)) {
                    foreach ($csv_notices as $notice) {
                        if (strpos($notice['code'], 'csv_') === 0) {
                            $bg = $notice['type'] === 'success' ? '#d4edda' : '#f8d7da';
                            $border = $notice['type'] === 'success' ? '#28a745' : '#dc3545';
                            $color = $notice['type'] === 'success' ? '#155724' : '#721c24';
                            echo '<div style="background:' . $bg . ';border:2px solid ' . $border . ';color:' . $color . ';padding:12px 16px;border-radius:6px;margin:10px 0;font-size:14px;font-weight:500;">';
                            echo esc_html($notice['message']);
                            echo '</div>';
                        }
                    }
                }
                ?>
                
                <form method="post" enctype="multipart/form-data" style="margin-top: 15px;"
                      action="<?php echo esc_url(add_query_arg(array('page' => 'mindfulseo-guidelines', 'tab' => 'import'), admin_url('admin.php'))); ?>">
                    <?php wp_nonce_field('mindfulseo_upload_guidelines_csv', 'mindfulseo_guidelines_csv_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="guidelines_csv_file"><?php _e('CSV File', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <input type="file" name="guidelines_csv_file" id="guidelines_csv_file" accept=".csv,.txt">
                                <p class="description">
                                    <?php _e('Required columns: RULE TYPE, PREFERRED TERM. Optional: AVOID TERM, CONTEXT.', 'mindfulseo'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="mindfulseo_upload_guidelines_csv" class="button button-primary" value="<?php esc_attr_e('Import CSV', 'mindfulseo'); ?>">
                    </p>
                </form>
                
                <div style="background: #f0f0f1; padding: 15px; border-radius: 4px; margin-top: 15px;">
                    <h4><?php _e('CSV Format Example:', 'mindfulseo'); ?></h4>
                    <pre style="background: #fff; padding: 10px; overflow-x: auto; font-size: 12px;">RULE TYPE,AVOID TERM,PREFERRED TERM,CONTEXT
avoid_term,Homepage,Home Page,Use two-word form
avoid_term,Info,Information,Prefer full word in formal content
capitalize,,Your Organization Name,Organization proper name
capitalize,,CEO,Common acronym
preferred_term,,Search Engine Optimization,Full form for clarity
seo_friendly,,content marketing strategy,SEO target phrase</pre>
                </div>
            </div>
            
            <?php endif; // End tab conditionals ?>
            
            <?php if ($active_tab === 'manage'): ?>
                <!-- Continue MANAGE TAB: Guidelines Table + Add Form -->
            
            <!-- Guidelines Table -->
            <div style="background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; margin: 20px 20px 0 0;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 style="margin: 0;"><?php _e('📋 Guideline Rules', 'mindfulseo'); ?></h2>
                    <div style="display: flex; gap: 10px;">
                        <?php if (!empty($rules)): ?>
                            <form method="post" style="margin: 0;" id="delete-all-guidelines-form">
                                <?php wp_nonce_field('mindfulseo_delete_all_guidelines', 'mindfulseo_delete_all_nonce'); ?>
                                <button type="submit" name="mindfulseo_delete_all_guidelines" class="button button-secondary" 
                                        style="background: #dc3232; color: #fff; border-color: #c62828;">
                                    <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                                    <?php _e('Delete All Guidelines', 'mindfulseo'); ?>
                                </button>
                            </form>
                        <?php endif; ?>
                        
                        <!-- Add Guideline Button -->
                        <button type="button" class="button" id="mindfulseo-add-guideline-btn">
                            <span class="dashicons dashicons-plus-alt" style="vertical-align: middle;"></span>
                            <?php _e('Add Guideline', 'mindfulseo'); ?>
                        </button>
                    </div>
                </div>
                
                <!-- Add Guideline Form (hidden by default) -->
                <div id="mindfulseo-add-guideline-form" style="display: none; margin: 20px 0; padding: 20px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                    <h3><?php _e('Add New Guideline', 'mindfulseo'); ?></h3>
                    <form method="post">
                        <?php wp_nonce_field('mindfulseo_add_guideline', 'mindfulseo_add_guideline_nonce'); ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="new_rule_type"><?php _e('Rule Type', 'mindfulseo'); ?></label></th>
                                <td>
                                    <select name="new_rule_type" id="new_rule_type">
                                        <option value="avoid_term">Avoid Term</option>
                                        <option value="preferred_term">Preferred Term</option>
                                        <option value="capitalize">Capitalize</option>
                                        <option value="seo_friendly">SEO Friendly</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="new_avoid_term"><?php _e('Avoid Term', 'mindfulseo'); ?> *</label></th>
                                <td><input type="text" name="new_avoid_term" id="new_avoid_term" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th><label for="new_preferred_term"><?php _e('Preferred Term', 'mindfulseo'); ?></label></th>
                                <td><input type="text" name="new_preferred_term" id="new_preferred_term" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><label for="new_context"><?php _e('Context/Notes', 'mindfulseo'); ?></label></th>
                                <td><textarea name="new_context" id="new_context" class="large-text" rows="3"></textarea></td>
                            </tr>
                        </table>
                        <p>
                            <button type="submit" name="mindfulseo_add_guideline" class="button button-primary"><?php _e('Add Guideline', 'mindfulseo'); ?></button>
                            <button type="button" class="button" id="mindfulseo-cancel-add-guideline"><?php _e('Cancel', 'mindfulseo'); ?></button>
                        </p>
                    </form>
                </div>
                
                <?php if (empty($rules)): ?>
                    <p><?php _e('No guidelines found. Upload a Markdown file to get started.', 'mindfulseo'); ?></p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 15%; cursor: pointer;" class="sortable" data-sort="rule_type">
                                    <?php _e('Rule Type', 'mindfulseo'); ?>
                                    <span class="dashicons dashicons-sort" style="font-size: 14px; vertical-align: middle;"></span>
                                </th>
                                <th style="width: 20%; cursor: pointer;" class="sortable" data-sort="avoid_term">
                                    <?php _e('Avoid/From', 'mindfulseo'); ?>
                                    <span class="dashicons dashicons-sort" style="font-size: 14px; vertical-align: middle;"></span>
                                </th>
                                <th style="width: 20%; cursor: pointer;" class="sortable" data-sort="preferred_term">
                                    <?php _e('Preferred/To', 'mindfulseo'); ?>
                                    <span class="dashicons dashicons-sort" style="font-size: 14px; vertical-align: middle;"></span>
                                </th>
                                <th style="width: 15%;"><?php _e('Context', 'mindfulseo'); ?></th>
                                <th style="width: 15%;"><?php _e('Source', 'mindfulseo'); ?></th>
                                <th style="width: 10%;"><?php _e('Status', 'mindfulseo'); ?></th>
                                <th style="width: 15%;"><?php _e('Actions', 'mindfulseo'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rules as $rule): ?>
                                <tr style="<?php echo $rule->active ? '' : 'opacity: 0.5;'; ?>">
                                    <td>
                                        <select class="editable-select" 
                                                data-id="<?php echo $rule->id; ?>" 
                                                data-field="rule_type">
                                            <option value="avoid_term" <?php selected($rule->rule_type, 'avoid_term'); ?>>Avoid Term</option>
                                            <option value="capitalize" <?php selected($rule->rule_type, 'capitalize'); ?>>Capitalize</option>
                                            <option value="seo_friendly" <?php selected($rule->rule_type, 'seo_friendly'); ?>>SEO Friendly</option>
                                            <option value="preferred_term" <?php selected($rule->rule_type, 'preferred_term'); ?>>Preferred Term</option>
                                        </select>
                                    </td>
                                    <td><span class="editable" contenteditable="true" 
                                        data-id="<?php echo $rule->id; ?>" 
                                        data-field="avoid_term" 
                                        data-original="<?php echo esc_attr($rule->avoid_term); ?>"><?php echo esc_html($rule->avoid_term); ?></span></td>
                                    <td><strong><span class="editable" contenteditable="true" 
                                        data-id="<?php echo $rule->id; ?>" 
                                        data-field="preferred_term" 
                                        data-original="<?php echo esc_attr($rule->preferred_term); ?>"><?php echo esc_html($rule->preferred_term); ?></span></strong></td>
                                    <td><small><span class="editable" contenteditable="true" 
                                        data-id="<?php echo $rule->id; ?>" 
                                        data-field="context" 
                                        data-original="<?php echo esc_attr($rule->context); ?>"><?php echo esc_html($rule->context); ?></span></small></td>
                                    <td><small title="<?php echo esc_attr($rule->guideline_source); ?>"><?php echo esc_html($rule->guideline_source); ?></small></td>
                                    <td>
                                        <?php if ($rule->active): ?>
                                            <span style="color: #46b450;">● <?php _e('Active', 'mindfulseo'); ?></span>
                                        <?php else: ?>
                                            <span style="color: #999;">○ <?php _e('Inactive', 'mindfulseo'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" style="display: inline;">
                                            <?php wp_nonce_field('mindfulseo_toggle_rule', 'mindfulseo_rule_nonce'); ?>
                                            <input type="hidden" name="rule_id" value="<?php echo intval($rule->id); ?>">
                                            <input type="hidden" name="action_type" value="<?php echo $rule->active ? 'deactivate' : 'activate'; ?>">
                                            <button type="submit" name="mindfulseo_toggle_rule" class="button button-small">
                                                <?php echo $rule->active ? __('Deactivate', 'mindfulseo') : __('Activate', 'mindfulseo'); ?>
                                            </button>
                                        </form>
                                        <form method="post" style="display: inline; margin-left: 5px;">
                                            <?php wp_nonce_field('mindfulseo_delete_rule', 'mindfulseo_rule_delete_nonce'); ?>
                                            <input type="hidden" name="rule_id" value="<?php echo intval($rule->id); ?>">
                                            <button type="submit" name="mindfulseo_delete_rule" class="button button-small" 
                                                    onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this rule?', 'mindfulseo'); ?>');">
                                                <?php _e('Delete', 'mindfulseo'); ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <?php if (!empty($rules) && isset($guidelines_stats['total'])): ?>
                        <div style="margin-top: 15px; padding: 10px; background: #f0f0f1; border-radius: 4px;">
                            <strong><?php printf(__('Showing %d guidelines', 'mindfulseo'), count($rules)); ?></strong>
                            <span style="color: #666; margin-left: 10px;">
                                <?php _e('💡 Click any cell to edit inline. Press Enter or click away to save.', 'mindfulseo'); ?>
                            </span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
        </div>
        
        <style>
            .mindfulseo-badge-avoid_term {
                background: #ffebee;
                color: #c62828;
            }
            .mindfulseo-badge-capitalize {
                background: #e3f2fd;
                color: #1976d2;
            }
            .mindfulseo-badge-seo_friendly {
                background: #e8f5e9;
                color: #2e7d32;
            }
            .mindfulseo-badge-preferred_term {
                background: #f3e5f5;
                color: #7b1fa2;
            }
        </style>
        
        <?php endif; // End manage tab ?>
        
        </div><!-- .mindfulseo-tab-content -->
        </div><!-- .wrap -->
        <?php
    }
    
    /**
     * Save settings
     */
    public function save_settings() {
        // Verify nonce
        if (!isset($_POST['mindfulseo_nonce']) || !wp_verify_nonce($_POST['mindfulseo_nonce'], 'mindfulseo_save_settings')) {
            wp_die(__('Security check failed', 'mindfulseo'));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'mindfulseo'));
        }
        
        // Get current settings
        $settings = MindfulSEO::get_settings();
        
        // Update API keys (only if not masked)
        if (isset($_POST['openai_api_key']) && $_POST['openai_api_key'] !== '••••••••••••••••') {
            if (class_exists('MFSEO_AI_Connector')) {
                $connector = MFSEO_AI_Connector::get_instance();
                $settings['openai_api_key'] = $connector->encrypt_api_key(sanitize_text_field($_POST['openai_api_key']));
            } else {
                $settings['openai_api_key'] = sanitize_text_field($_POST['openai_api_key']);
            }
        }
        
        if (isset($_POST['claude_api_key']) && $_POST['claude_api_key'] !== '••••••••••••••••') {
            if (class_exists('MFSEO_AI_Connector')) {
                $connector = MFSEO_AI_Connector::get_instance();
                $settings['claude_api_key'] = $connector->encrypt_api_key(sanitize_text_field($_POST['claude_api_key']));
            } else {
                $settings['claude_api_key'] = sanitize_text_field($_POST['claude_api_key']);
            }
        }

        if (isset($_POST['openrouter_api_key']) && $_POST['openrouter_api_key'] !== '••••••••••••••••') {
            if (class_exists('MFSEO_AI_Connector')) {
                $connector = MFSEO_AI_Connector::get_instance();
                $settings['openrouter_api_key'] = $connector->encrypt_api_key(sanitize_text_field($_POST['openrouter_api_key']));
            } else {
                $settings['openrouter_api_key'] = sanitize_text_field($_POST['openrouter_api_key']);
            }
        }
        
        // Update DataForSEO credentials (only if not masked)
        if (isset($_POST['dataforseo_login']) && $_POST['dataforseo_login'] !== '••••••••••••••••') {
            $settings['dataforseo_login'] = sanitize_email($_POST['dataforseo_login']);
        }
        
        if (isset($_POST['dataforseo_password']) && $_POST['dataforseo_password'] !== '••••••••••••••••') {
            if (class_exists('MFSEO_AI_Connector')) {
                $connector = MFSEO_AI_Connector::get_instance();
                $settings['dataforseo_password'] = $connector->encrypt_api_key(sanitize_text_field($_POST['dataforseo_password']));
            } else {
                $settings['dataforseo_password'] = sanitize_text_field($_POST['dataforseo_password']);
            }
        }
        
        // Update other settings
        $posted_main_ai = isset($_POST['primary_provider']) ? sanitize_text_field(wp_unslash($_POST['primary_provider'])) : '';
        if ($posted_main_ai === 'openrouter') {
            $settings['ai_backend'] = 'openrouter';
            if (isset($_POST['mfseo_fallback_direct_priority'])) {
                $fd = sanitize_text_field(wp_unslash($_POST['mfseo_fallback_direct_priority']));
                if (in_array($fd, array('openai', 'claude'), true)) {
                    $settings['primary_provider'] = $fd;
                }
            } elseif (empty($settings['primary_provider']) || ! in_array($settings['primary_provider'], array('openai', 'claude'), true)) {
                $settings['primary_provider'] = 'openai';
            }
        } elseif ($posted_main_ai === 'openai' || $posted_main_ai === 'claude') {
            $settings['ai_backend'] = 'direct';
            $settings['primary_provider'] = $posted_main_ai;
        } else {
            $settings['ai_backend'] = isset($_POST['ai_backend']) && $_POST['ai_backend'] === 'openrouter' ? 'openrouter' : 'direct';
            $fallback_pp = isset($_POST['primary_provider']) ? sanitize_text_field(wp_unslash($_POST['primary_provider'])) : 'openai';
            $settings['primary_provider'] = in_array($fallback_pp, array('openai', 'claude'), true) ? $fallback_pp : 'openai';
        }
        /* Direct mode: connector keys off primary_provider; keep ai_provider identical for wizard + Settings consistency. */
        if ( isset( $settings['ai_backend'] ) && $settings['ai_backend'] === 'direct'
            && isset( $settings['primary_provider'] ) && in_array( $settings['primary_provider'], array( 'openai', 'claude' ), true ) ) {
            $settings['ai_provider'] = $settings['primary_provider'];
        }
        $settings['openrouter_model'] = isset($_POST['openrouter_model']) ? sanitize_text_field($_POST['openrouter_model']) : 'qwen/qwen3.5-flash-02-23';
        $settings['openrouter_model_fast'] = isset($_POST['openrouter_model_fast']) ? sanitize_text_field($_POST['openrouter_model_fast']) : 'minimax/minimax-m2.5';
        $settings['openrouter_custom_model'] = isset($_POST['openrouter_custom_model']) ? sanitize_text_field(stripslashes($_POST['openrouter_custom_model'])) : '';
        $settings['openrouter_http_referer'] = isset($_POST['openrouter_http_referer']) ? esc_url_raw($_POST['openrouter_http_referer']) : '';
        $settings['openai_model'] = isset($_POST['openai_model']) ? sanitize_text_field($_POST['openai_model']) : 'gpt-4-turbo';
        $settings['claude_model'] = isset($_POST['claude_model']) ? sanitize_text_field($_POST['claude_model']) : 'claude-sonnet-4-5';
        $settings['enable_fallback'] = isset($_POST['enable_fallback']) ? true : false;
        $settings['require_approval'] = isset($_POST['require_approval']) ? true : false;
        $settings['batch_size'] = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : 10;
        $settings['dataforseo_location'] = isset($_POST['dataforseo_location']) ? sanitize_text_field($_POST['dataforseo_location']) : '2840';
        $settings['dataforseo_language'] = isset($_POST['dataforseo_language']) ? sanitize_text_field($_POST['dataforseo_language']) : 'en';
        $settings['auto_refresh_keywords'] = isset($_POST['auto_refresh_keywords']) ? true : false;
        
        // Save settings
        MindfulSEO::update_settings($settings);

        delete_transient( 'mfseo_api_status_live' );
        delete_transient( 'mfseo_provider_down_openai' );
        delete_transient( 'mfseo_provider_down_claude' );
        delete_transient( 'mfseo_provider_down_openrouter' );

        // Redirect back with success message
        wp_redirect(add_query_arg(array(
            'page' => 'mindfulseo-settings',
            'updated' => 'true',
        ), admin_url('admin.php')));
        exit;
    }
    
    /**
     * Transient key and TTL for Content Hub quick counts cache.
     */
    const CONTENT_HUB_COUNTS_TTL = 900; // 15 minutes

    private static function hub_counts_key() {
        return 'mfseo_hub_qc_' . MINDFULSEO_VERSION;
    }

    /**
     * Get quick counts for Content Hub overview (thin content, missing meta, gaps, orphans, etc.)
     * Results are cached in a transient to avoid redundant queries.
     *
     * @param bool $force_refresh Bypass cache.
     * @return array Counts and optional post IDs for quick-action links
     */
    private function get_content_hub_quick_counts( $force_refresh = false ) {
        if ( ! $force_refresh ) {
            $cached = get_transient( self::hub_counts_key() );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        global $wpdb;
        $defaults = array(
            'thin_content_count' => 0,
            'thin_content_ids'   => array(),
            'no_meta_count'      => 0,
            'no_meta_ids'        => array(),
            'no_keyword_count'   => 0,
            'no_keyword_ids'     => array(),
            'title_too_long_count' => 0,
            'title_too_long_ids'   => array(),
            'not_optimized_count' => 0,
            'total_posts'        => 0,
            'optimized_count'    => 0,
            'gap_count'          => 0,
            'orphan_count'       => 0,
        );

        $adapter      = class_exists( 'MFSEO_SEO_Plugin_Adapter' ) ? MFSEO_SEO_Plugin_Adapter::get_instance() : null;
        $is_rankmath  = $adapter && $adapter->is_seo_plugin_active() && $adapter->get_active_plugin() === 'rankmath';
        $meta_desc_key = $is_rankmath ? 'rank_math_description' : '_yoast_wpseo_metadesc';
        $focus_key    = $is_rankmath ? 'rank_math_focus_keyword' : '_yoast_wpseo_focuskw';

        // Accurate counts (no LIMIT)
        $thin_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = %s AND post_status = %s
             AND CHAR_LENGTH(post_content) < 1000",
            'post', 'publish'
        ) );

        $no_meta_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = %s AND p.post_status = %s
             AND (pm.meta_value IS NULL OR pm.meta_value = '')",
            $meta_desc_key, 'post', 'publish'
        ) );

        $no_kw_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = %s AND p.post_status = %s
             AND (pm.meta_value IS NULL OR pm.meta_value = '')",
            $focus_key, 'post', 'publish'
        ) );

        // Post ID lists for display (capped for performance)
        $thin = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = %s AND post_status = %s
             AND CHAR_LENGTH(post_content) < 1000
             LIMIT 100",
            'post', 'publish'
        ), ARRAY_A );
        $thin = is_array( $thin ) ? $thin : array();

        $no_meta = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = %s AND p.post_status = %s
             AND (pm.meta_value IS NULL OR pm.meta_value = '')
             LIMIT 100",
            $meta_desc_key, 'post', 'publish'
        ), ARRAY_A );
        $no_meta = is_array( $no_meta ) ? $no_meta : array();

        $no_kw = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = %s AND p.post_status = %s
             AND (pm.meta_value IS NULL OR pm.meta_value = '')
             LIMIT 100",
            $focus_key, 'post', 'publish'
        ), ARRAY_A );
        $no_kw = is_array( $no_kw ) ? $no_kw : array();

        $title_key = $is_rankmath ? 'rank_math_title' : '_yoast_wpseo_title';
        $title_long_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = %s AND p.post_status = %s
             AND LENGTH(COALESCE(pm.meta_value, p.post_title)) > 60",
            $title_key, 'post', 'publish'
        ) );

        $title_long = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = %s AND p.post_status = %s
             AND LENGTH(COALESCE(pm.meta_value, p.post_title)) > 60
             LIMIT 100",
            $title_key, 'post', 'publish'
        ), ARRAY_A );
        $title_long = is_array( $title_long ) ? $title_long : array();

        $optimized = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = %s AND pm1.meta_value != ''
             INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = %s AND pm2.meta_value != ''
             WHERE p.post_type = %s AND p.post_status = %s",
            $focus_key, $meta_desc_key, 'post', 'publish'
        ) );

        $total_posts   = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
            'post', 'publish'
        ) );
        $not_optimized = max( 0, $total_posts - $optimized );

        // Gap count -- only read from transient cache, never compute synchronously.
        // The gap analysis transient is populated when the user visits the Gaps tab or via AJAX refresh.
        $gap_count = -1; // -1 = "not yet computed"
        $gap_cache = get_transient( 'mfseo_gap_list_all' );
        if ( is_array( $gap_cache ) ) {
            $gap_count = count( $gap_cache );
        }

        // Orphan count -- NEVER run the expensive orphan query during page render.
        // It does a full-table scan of post_content which kills MySQL on large sites.
        // The user can trigger it via the "Refresh Analysis" button on the Links tab.
        $orphan_count = -1; // -1 = "not yet computed"
        $orphan_cache = get_transient( 'mfseo_orphan_opportunities' );
        if ( is_array( $orphan_cache ) && isset( $orphan_cache['orphan_count'] ) ) {
            $orphan_count = (int) $orphan_cache['orphan_count'];
        }

        $result = array_merge( $defaults, array(
            'thin_content_count' => $thin_count,
            'thin_content_ids'   => wp_list_pluck( $thin, 'ID' ),
            'no_meta_count'      => $no_meta_count,
            'no_meta_ids'        => wp_list_pluck( $no_meta, 'ID' ),
            'no_keyword_count'   => $no_kw_count,
            'no_keyword_ids'     => wp_list_pluck( $no_kw, 'ID' ),
            'title_too_long_count' => $title_long_count,
            'title_too_long_ids'   => wp_list_pluck( $title_long, 'ID' ),
            'not_optimized_count' => $not_optimized,
            'total_posts'        => $total_posts,
            'optimized_count'    => $optimized,
            'gap_count'          => $gap_count,
            'orphan_count'       => $orphan_count,
        ) );

        set_transient( self::hub_counts_key(), $result, self::CONTENT_HUB_COUNTS_TTL );
        return $result;
    }

    /**
     * Render the Content Hub page (clusters, gaps, internal linking)
     */
    public function render_content_hub_page() {
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'mindfulseo'));
        }
        try {
            $this->render_content_hub_page_inner();
        } catch (Throwable $e) {
            echo $this->get_branding_header(__('Content Hub', 'mindfulseo'));
            echo '<div class="wrap mindfulseo-page"><div class="mindfulseo-content"><div class="notice notice-error"><p><strong>' . esc_html__('Content Hub could not load.', 'mindfulseo') . '</strong> ' . esc_html($e->getMessage()) . '</p></div></div></div>';
        }
    }

    /**
     * Inner Content Hub render (so we can catch any exception)
     */
    private function render_content_hub_page_inner() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'clusters';
        if (!in_array($active_tab, array('clusters', 'health', 'links'), true)) {
            $active_tab = 'clusters';
        }
        $counts_error = false;
        $counts_default = array(
            'thin_content_count' => 0,
            'thin_content_ids' => array(),
            'no_meta_count' => 0,
            'no_meta_ids' => array(),
            'no_keyword_count' => 0,
            'no_keyword_ids' => array(),
            'title_too_long_count' => 0,
            'title_too_long_ids' => array(),
            'not_optimized_count' => 0,
            'total_posts' => 0,
            'optimized_count' => 0,
            'gap_count' => 0,
            'orphan_count' => 0,
        );
        try {
            $counts = array_merge($counts_default, $this->get_content_hub_quick_counts());
        } catch (Throwable $e) {
            $counts_error = true;
            $counts = $counts_default;
        }
        $seo_score_pct = isset($counts['total_posts'], $counts['optimized_count']) && $counts['total_posts'] > 0
            ? (int) round(($counts['optimized_count'] / $counts['total_posts']) * 100) : 100;
        echo $this->get_branding_header(__('Content Hub', 'mindfulseo'));
        if (!empty($counts_error)) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Something went wrong loading counts. Showing safe defaults. You can still use the tabs below.', 'mindfulseo') . '</p></div>';
        }
        $score_color = $seo_score_pct >= 70 ? '#46b450' : ( $seo_score_pct >= 40 ? '#f0b849' : '#dc3232' );

        $cluster_count = 0;
        if ( class_exists( 'MFSEO_Content_Cluster_Engine' ) ) {
            try {
                $ce = MFSEO_Content_Cluster_Engine::get_instance();
                $cl = $ce ? $ce->get_clusters() : array();
                $cluster_count = is_array( $cl ) ? count( $cl ) : 0;
            } catch ( \Throwable $e ) {
                $cluster_count = 0;
            }
        }

        $health_issues = (int) $counts['no_meta_count'] + (int) $counts['no_keyword_count'] + (int) $counts['title_too_long_count'] + (int) $counts['thin_content_count'];

        $broken_scan       = get_transient( 'mfseo_broken_links_scan' );
        $broken_links_total = null;
        if ( is_array( $broken_scan ) ) {
            $broken_links_total = ( isset( $broken_scan['internal_count'] ) ? (int) $broken_scan['internal_count'] : 0 )
                + ( isset( $broken_scan['external_count'] ) ? (int) $broken_scan['external_count'] : 0 );
        }
        ?>
        <div class="wrap mindfulseo-page">
            <div class="mindfulseo-content">

                <p class="mfseo-hub-intro"><?php _e( 'Organize your content by topic, fix SEO issues, and strengthen internal links across your site.', 'mindfulseo' ); ?></p>

                <div class="mfseo-hub-metrics">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=mindfulseo-content-hub&tab=clusters' ) ); ?>" class="mfseo-hub-metric <?php echo $active_tab === 'clusters' ? 'mfseo-hub-metric--active' : ''; ?>">
                        <span class="mfseo-hub-metric__number"><?php echo number_format( $cluster_count ); ?></span>
                        <span class="mfseo-hub-metric__label"><?php _e( 'Topic Groups', 'mindfulseo' ); ?></span>
                        <span class="mfseo-hub-metric__desc"><?php _e( 'Posts organized by focus keyword', 'mindfulseo' ); ?></span>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=mindfulseo-content-hub&tab=health' ) ); ?>" class="mfseo-hub-metric <?php echo $active_tab === 'health' ? 'mfseo-hub-metric--active' : ''; ?>">
                        <span class="mfseo-hub-metric__number"><?php echo number_format( $health_issues ); ?></span>
                        <span class="mfseo-hub-metric__label"><?php _e( 'Content Health', 'mindfulseo' ); ?></span>
                        <span class="mfseo-hub-metric__desc"><?php _e( 'Posts that need attention', 'mindfulseo' ); ?></span>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=mindfulseo-content-hub&tab=links' ) ); ?>" class="mfseo-hub-metric <?php echo $active_tab === 'links' ? 'mfseo-hub-metric--active' : ''; ?>" id="mfseo-hub-metric-link-health">
                        <span id="mfseo-hub-metric-broken-count" class="mfseo-hub-metric__number<?php echo ( $broken_links_total !== null && $broken_links_total > 0 ) ? ' mfseo-hub-metric__number--warning' : ''; ?>"><?php
                        if ( $broken_links_total !== null ) {
                            echo esc_html( number_format_i18n( $broken_links_total ) );
                        } else {
                            echo esc_html( '—' );
                        }
                        ?></span>
                        <span class="mfseo-hub-metric__label"><?php _e( 'Broken links', 'mindfulseo' ); ?></span>
                        <span id="mfseo-hub-metric-broken-desc" class="mfseo-hub-metric__desc"><?php
                        if ( $broken_links_total !== null ) {
                            esc_html_e( 'From your last Link Health scan', 'mindfulseo' );
                        } else {
                            esc_html_e( 'Run a scan on the Link Health tab', 'mindfulseo' );
                        }
                        ?></span>
                    </a>
                </div>

                <nav class="mfseo-tabs">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=mindfulseo-content-hub&tab=clusters' ) ); ?>" class="mfseo-tabs__tab <?php echo $active_tab === 'clusters' ? 'active' : ''; ?>" data-tab="clusters"><?php _e( 'Topic Groups', 'mindfulseo' ); ?></a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=mindfulseo-content-hub&tab=health' ) ); ?>" class="mfseo-tabs__tab <?php echo $active_tab === 'health' ? 'active' : ''; ?>" data-tab="health">
                        <?php _e( 'Content Health', 'mindfulseo' ); ?>
                        <?php if ( $health_issues > 0 ) : ?>
                            <span class="mfseo-tabs__badge"><?php echo esc_html( $health_issues ); ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=mindfulseo-content-hub&tab=links' ) ); ?>" class="mfseo-tabs__tab <?php echo $active_tab === 'links' ? 'active' : ''; ?>" data-tab="links">
                        <?php _e( 'Link Health', 'mindfulseo' ); ?>
                        <?php if ( $broken_links_total !== null && $broken_links_total > 0 ) : ?>
                            <span class="mfseo-tabs__badge"><?php echo esc_html( $broken_links_total ); ?></span>
                        <?php endif; ?>
                    </a>
                </nav>
                <?php
                if ($active_tab === 'clusters') {
                    $this->render_content_hub_clusters_tab();
                } elseif ($active_tab === 'health') {
                    $this->render_content_hub_health_tab($counts);
                } else {
                    $this->render_content_hub_links_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Content Hub: Clusters tab
     */
    private function render_content_hub_clusters_tab() {
        $engine   = null;
        $clusters = array();
        if ( class_exists( 'MFSEO_Content_Cluster_Engine' ) ) {
            try {
                $engine   = MFSEO_Content_Cluster_Engine::get_instance();
                $clusters = $engine ? $engine->get_clusters() : array();
                if ( ! is_array( $clusters ) ) { $clusters = array(); }
            } catch ( \Throwable $e ) {
                error_log( 'MindfulSEO Cluster tab error: ' . $e->getMessage() );
                $clusters = array();
            }
        }
        $adapter = class_exists( 'MFSEO_SEO_Plugin_Adapter' ) ? MFSEO_SEO_Plugin_Adapter::get_instance() : null;

        $all_cluster_post_ids = array();
        $total_posts_in_clusters = 0;
        foreach ( $clusters as $c_item ) {
            $ids = isset( $c_item['post_ids'] ) ? $c_item['post_ids'] : array();
            $total_posts_in_clusters += count( $ids );
            if ( isset( $c_item['pillar_post_id'] ) && $c_item['pillar_post_id'] ) {
                $ids[] = $c_item['pillar_post_id'];
            }
            $all_cluster_post_ids = array_merge( $all_cluster_post_ids, $ids );
        }
        $all_cluster_post_ids = array_unique( array_filter( array_map( 'intval', $all_cluster_post_ids ) ) );
        if ( ! empty( $all_cluster_post_ids ) ) {
            _prime_post_caches( $all_cluster_post_ids, true, true );
        }
        $batch_seo_scores = array();
        if ( $adapter && ! empty( $all_cluster_post_ids ) ) {
            global $wpdb;
            $is_rankmath = $adapter->get_active_plugin() === 'rankmath';
            if ( $is_rankmath ) {
                $ph   = implode( ',', array_fill( 0, count( $all_cluster_post_ids ), '%d' ) );
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ($ph)",
                    array_merge( array( 'rank_math_seo_score' ), $all_cluster_post_ids )
                ), ARRAY_A );
                if ( is_array( $rows ) ) {
                    foreach ( $rows as $sr ) {
                        $batch_seo_scores[ (int) $sr['post_id'] ] = is_numeric( $sr['meta_value'] ) ? (int) $sr['meta_value'] : null;
                    }
                }
            }
        }
        ?>
        <div class="mfseo-tab-content mfseo-tab-clusters">
            <div class="mfseo-hub-overview">
                <div class="mfseo-hub-overview__icon mfseo-hub-overview__icon--slate">
                    <span class="dashicons dashicons-category"></span>
                </div>
                <div class="mfseo-hub-overview__text">
                    <strong><?php printf( __( '%d topic groups covering %d posts', 'mindfulseo' ), count( $clusters ), $total_posts_in_clusters ); ?></strong>
                    <p><?php _e( 'Posts that share the same focus keyword are grouped together. Expand a group to see its posts.', 'mindfulseo' ); ?></p>
                </div>
                <div class="mfseo-hub-overview__action">
                    <button type="button" id="mfseo-refresh-clusters" class="button button-secondary">
                        <span class="dashicons dashicons-update"></span>
                        <?php _e( 'Refresh', 'mindfulseo' ); ?>
                    </button>
                </div>
            </div>

            <?php if ( empty( $clusters ) ) : ?>
                <div class="mfseo-hub-empty">
                    <span class="dashicons dashicons-category" style="color:#94a3b8;"></span>
                    <strong><?php _e( 'No topic groups found yet', 'mindfulseo' ); ?></strong>
                    <p><?php _e( 'Add focus keywords to your posts using the Batch Optimizer or post editor, then click Refresh.', 'mindfulseo' ); ?></p>
                </div>
            <?php else : ?>
                <?php foreach ( $clusters as $index => $c ) :
                    $pillar       = isset( $c['pillar_post_id'] ) && $c['pillar_post_id'] ? get_post( $c['pillar_post_id'] ) : null;
                    $post_ids     = isset( $c['post_ids'] ) ? $c['post_ids'] : array();
                    $health_score = isset( $c['health_score'] ) ? $c['health_score'] : null;
                    $batch_url    = admin_url( 'admin.php?page=mindfulseo-batch-optimize&auto_select=' . implode( ',', array_slice( $post_ids, 0, 50 ) ) );
                    $cluster_id   = 'cluster-body-' . $index;
                    $post_count   = count( $post_ids );

                    $color_class = 'mfseo-hub-card--slate';
                    if ( $health_score !== null ) {
                        if ( $health_score >= 70 ) { $color_class = 'mfseo-hub-card--green'; }
                        elseif ( $health_score >= 40 ) { $color_class = 'mfseo-hub-card--amber'; }
                        else { $color_class = 'mfseo-hub-card--red'; }
                    }

                    $pillar_label = $pillar ? $pillar->post_title . ' ' . __( '(Main Post)', 'mindfulseo' ) : '';

                    $supporting_ids   = array_diff( $post_ids, array( isset( $c['pillar_post_id'] ) ? $c['pillar_post_id'] : 0 ) );
                    $supporting_posts = array();
                    foreach ( array_slice( $supporting_ids, 0, 12 ) as $pid ) {
                        $p = get_post( $pid );
                        if ( $p ) {
                            $supporting_posts[] = array(
                                'ID'       => $pid,
                                'title'    => $p->post_title,
                                'score'    => isset( $batch_seo_scores[ $pid ] ) ? $batch_seo_scores[ $pid ] : null,
                                'edit_url' => get_edit_post_link( $pid ),
                            );
                        }
                    }
                ?>
                <div class="mfseo-hub-card <?php echo esc_attr( $color_class ); ?>">
                    <button type="button" class="mfseo-hub-card__header" data-toggle="<?php echo esc_attr( $cluster_id ); ?>">
                        <span class="mfseo-hub-card__icon dashicons dashicons-category"></span>
                        <span class="mfseo-hub-card__title">
                            <?php echo esc_html( isset( $c['cluster_name'] ) ? $c['cluster_name'] : '' ); ?>
                            <?php if ( $pillar_label ) : ?>
                                <span class="mfseo-hub-card__subtitle"><?php echo esc_html( $pillar_label ); ?></span>
                            <?php endif; ?>
                        </span>
                        <?php if ( $health_score !== null && $health_score >= 0 && $health_score <= 100 ) : ?>
                            <span class="mfseo-hub-card__meta"><?php echo esc_html( $health_score ); ?>/100</span>
                        <?php endif; ?>
                        <span class="mfseo-hub-card__count"><?php echo esc_html( $post_count ); ?></span>
                        <span class="mfseo-hub-card__arrow dashicons dashicons-arrow-down-alt2"></span>
                    </button>
                    <div class="mfseo-hub-card__body" id="<?php echo esc_attr( $cluster_id ); ?>">
                        <?php if ( ! empty( $supporting_posts ) ) : ?>
                            <p class="mfseo-hub-card__desc">
                                <?php printf( __( 'Related Posts (%d)', 'mindfulseo' ), count( $supporting_posts ) ); ?>
                                <?php if ( count( $supporting_ids ) > 12 ) : ?>
                                    <?php printf( __( 'of %d total', 'mindfulseo' ), count( $supporting_ids ) ); ?>
                                <?php endif; ?>
                            </p>
                            <div class="mfseo-cluster-post-grid">
                                <?php foreach ( $supporting_posts as $sp ) :
                                    $sc = $sp['score'];
                                    $sc_color = ( $sc !== null && $sc >= 70 ) ? '#16a34a' : ( ( $sc !== null && $sc >= 50 ) ? '#d97706' : '#dc2626' );
                                ?>
                                <div class="mfseo-cluster-post-item" style="border-left-color:<?php echo esc_attr( $sc_color ); ?>">
                                    <a href="<?php echo esc_url( $sp['edit_url'] ); ?>" target="_blank"><?php echo esc_html( $sp['title'] ); ?></a>
                                    <?php if ( $sc !== null ) : ?>
                                        <span class="mfseo-cluster-post-item__score" style="color:<?php echo esc_attr( $sc_color ); ?>"><?php echo esc_html( $sc ); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?>
                            <p class="mfseo-hub-card__desc"><?php _e( 'No related posts found.', 'mindfulseo' ); ?></p>
                        <?php endif; ?>
                        <div class="mfseo-hub-card__actions">
                            <a href="<?php echo esc_url( $batch_url ); ?>" class="button button-small button-primary"><?php _e( 'Optimize All', 'mindfulseo' ); ?></a>
                            <?php if ( $pillar ) : ?>
                                <a href="<?php echo esc_url( get_edit_post_link( $pillar->ID ) ); ?>" class="button button-small" target="_blank"><?php _e( 'Edit Main Post', 'mindfulseo' ); ?></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Content Hub: Content Health tab -- shows actionable SEO issues
     */
    private function render_content_hub_health_tab( $counts ) {
        $no_meta_ids     = isset( $counts['no_meta_ids'] ) ? $counts['no_meta_ids'] : array();
        $no_keyword_ids  = isset( $counts['no_keyword_ids'] ) ? $counts['no_keyword_ids'] : array();
        $thin_ids        = isset( $counts['thin_content_ids'] ) ? $counts['thin_content_ids'] : array();
        $title_long_ids  = isset( $counts['title_too_long_ids'] ) ? $counts['title_too_long_ids'] : array();
        $no_meta_total   = (int) ( isset( $counts['no_meta_count'] ) ? $counts['no_meta_count'] : count( $no_meta_ids ) );
        $no_kw_total     = (int) ( isset( $counts['no_keyword_count'] ) ? $counts['no_keyword_count'] : count( $no_keyword_ids ) );
        $thin_total      = (int) ( isset( $counts['thin_content_count'] ) ? $counts['thin_content_count'] : count( $thin_ids ) );
        $title_long_total = (int) ( isset( $counts['title_too_long_count'] ) ? $counts['title_too_long_count'] : count( $title_long_ids ) );
        $not_optimized   = (int) ( isset( $counts['not_optimized_count'] ) ? $counts['not_optimized_count'] : 0 );
        $total_posts     = (int) ( isset( $counts['total_posts'] ) ? $counts['total_posts'] : 0 );
        $optimized_count = (int) ( isset( $counts['optimized_count'] ) ? $counts['optimized_count'] : 0 );

        $total_issues = $no_meta_total + $no_kw_total + $title_long_total + $thin_total;
        $health_pct = $total_posts > 0 ? (int) round( ( $optimized_count / $total_posts ) * 100 ) : 100;
        $health_color = $health_pct >= 70 ? '#16a34a' : ( $health_pct >= 40 ? '#d97706' : '#dc2626' );

        $issues = array(
            array(
                'key'      => 'no_meta',
                'label'    => __( 'Missing Meta Description', 'mindfulseo' ),
                'desc'     => __( 'Posts without a meta description may appear poorly in search results.', 'mindfulseo' ),
                'ids'      => $no_meta_ids,
                'total'    => $no_meta_total,
                'severity' => 'high',
                'icon'     => 'dashicons-editor-help',
            ),
            array(
                'key'      => 'no_keyword',
                'label'    => __( 'Missing Focus Keyword', 'mindfulseo' ),
                'desc'     => __( 'Posts without a focus keyword cannot be fully optimized for search.', 'mindfulseo' ),
                'ids'      => $no_keyword_ids,
                'total'    => $no_kw_total,
                'severity' => 'high',
                'icon'     => 'dashicons-search',
            ),
            array(
                'key'      => 'title_long',
                'label'    => __( 'Title Too Long', 'mindfulseo' ),
                'desc'     => __( 'Titles over 60 characters get truncated in Google search results. Shorten them for full visibility.', 'mindfulseo' ),
                'ids'      => $title_long_ids,
                'total'    => $title_long_total,
                'severity' => 'medium',
                'icon'     => 'dashicons-editor-textcolor',
            ),
            array(
                'key'      => 'thin',
                'label'    => __( 'Thin Content', 'mindfulseo' ),
                'desc'     => __( 'Posts with very little text may be seen as low-quality by search engines.', 'mindfulseo' ),
                'ids'      => $thin_ids,
                'total'    => $thin_total,
                'severity' => 'medium',
                'icon'     => 'dashicons-text-page',
            ),
        );
        $overview_icon_class = $health_pct >= 70 ? 'mfseo-hub-overview__icon--green' : ( $health_pct >= 40 ? 'mfseo-hub-overview__icon--amber' : 'mfseo-hub-overview__icon--red' );
        ?>
        <div class="mfseo-tab-content mfseo-tab-health">
            <div class="mfseo-hub-overview">
                <div class="mfseo-health-score">
                    <svg viewBox="0 0 36 36" class="mfseo-health-ring" width="72" height="72">
                        <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="#e5e7eb" stroke-width="3"/>
                        <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="<?php echo esc_attr( $health_color ); ?>" stroke-width="3" stroke-dasharray="<?php echo esc_attr( $health_pct ); ?>, 100" stroke-linecap="round"/>
                    </svg>
                    <span class="mfseo-health-score__pct" style="color:<?php echo esc_attr( $health_color ); ?>"><?php echo $health_pct; ?>%</span>
                </div>
                <div class="mfseo-hub-overview__text">
                    <strong><?php printf( __( '%d of %d posts fully optimized', 'mindfulseo' ), $optimized_count, $total_posts ); ?></strong>
                    <?php if ( $total_issues === 0 ) : ?>
                        <p><?php _e( 'Great job! No SEO issues found across your content.', 'mindfulseo' ); ?></p>
                    <?php else : ?>
                        <p><?php printf( _n( '%d issue found that may affect your search rankings.', '%d issues found that may affect your search rankings.', $total_issues, 'mindfulseo' ), $total_issues ); ?></p>
                    <?php endif; ?>
                </div>
                <?php if ( $not_optimized > 0 ) : ?>
                    <div class="mfseo-hub-overview__action">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=mindfulseo-batch-optimize' ) ); ?>" class="button button-primary">
                            <?php printf( __( 'Optimize %d Remaining', 'mindfulseo' ), $not_optimized ); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <?php
            // Map issue keys to batch optimizer issue_type slugs
            $issue_type_map = array(
                'no_meta'    => 'no_meta_description',
                'no_keyword' => 'no_focus_keyword',
                'title_long' => 'title_too_long',
                'thin'       => 'thin_content',
            );
            $visible_issues = array_filter( $issues, function( $i ) {
                $count = isset( $i['total'] ) ? (int) $i['total'] : count( $i['ids'] );
                return $count > 0;
            } );
            if ( ! empty( $visible_issues ) ) : ?>
            <div class="mfseo-dash-health-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:0;">
                <?php foreach ( $visible_issues as $issue ) :
                    $count     = isset( $issue['total'] ) ? (int) $issue['total'] : count( $issue['ids'] );
                    $color_key = $issue['severity'] === 'high' ? 'error' : 'warning';
                    $type_key  = isset( $issue_type_map[ $issue['key'] ] ) ? $issue_type_map[ $issue['key'] ] : $issue['key'];
                    $post_ids  = array_slice( $issue['ids'], 0, 300 );
                    $fix_url   = admin_url( 'admin.php?page=mindfulseo-seo-audit' );
                ?>
                <div class="mfseo-health-card mfseo-health-card--<?php echo esc_attr( $color_key ); ?>">
                    <div class="mfseo-health-card__count"><?php echo number_format( $count ); ?></div>
                    <div class="mfseo-health-card__label"><?php echo esc_html( $issue['label'] ); ?></div>
                    <?php if ( $total_posts > 0 ) : ?>
                        <div class="mfseo-health-card__of"><?php printf( __( 'of %s posts', 'mindfulseo' ), number_format( $total_posts ) ); ?></div>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( $fix_url ); ?>" class="mfseo-health-card__action"><?php _e( 'Fix', 'mindfulseo' ); ?> &rarr;</a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ( $total_issues === 0 ) : ?>
                <div class="mfseo-hub-empty">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <strong><?php _e( 'All Clear', 'mindfulseo' ); ?></strong>
                    <p><?php _e( 'All your published posts look healthy. Keep up the great work!', 'mindfulseo' ); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // _deprecated_gaps_stub removed: contained broken smart-quote characters causing parse errors.

    
    /**
     * Content Hub: Internal Linking tab
     */
    private function render_content_hub_links_tab() {
        $scan        = get_transient( 'mfseo_broken_links_scan' );
        $has_scan    = is_array( $scan );
        $int_broken  = $has_scan && isset( $scan['broken_internal'] ) ? $scan['broken_internal'] : array();
        $ext_broken  = $has_scan && isset( $scan['broken_external'] ) ? $scan['broken_external'] : array();
        $int_count   = count( $int_broken );
        $ext_count   = count( $ext_broken );
        $ext_checked = $has_scan && isset( $scan['external_checked'] ) ? (int) $scan['external_checked'] : 0;
        $elapsed     = $has_scan && isset( $scan['elapsed'] ) ? $scan['elapsed'] : 0;
        $posts_scanned = $has_scan && isset( $scan['posts_scanned'] ) ? (int) $scan['posts_scanned'] : 0;
        $ext_trunc   = $has_scan && ! empty( $scan['external_pool_truncated'] );
        $scan_quick   = $has_scan && ! empty( $scan['quick_mode'] );
        $scan_scope   = $has_scan && isset( $scan['scan_scope'] ) ? $scan['scan_scope'] : '';
        $scan_plimit  = $has_scan && isset( $scan['post_limit'] ) ? (int) $scan['post_limit'] : 0;
        $total        = $int_count + $ext_count;

        $scope_html = '';
        if ( $has_scan && $scan_scope === 'recent' && $scan_plimit > 0 ) {
            $scope_html = ' <span style="color:#64748b;">' . esc_html(
                sprintf(
                    /* translators: %d: number of posts scanned (recent first) */
                    __( 'Scope: %d most recently updated posts.', 'mindfulseo' ),
                    $scan_plimit
                )
            ) . '</span>';
        } elseif ( $has_scan && $scan_scope === 'full' ) {
            $scope_html = ' <span style="color:#64748b;">' . esc_html__( 'Scope: all published posts.', 'mindfulseo' ) . '</span>';
        }

        $status_icon = $has_scan
            ? ( $total === 0 ? 'mfseo-hub-overview__icon--green' : 'mfseo-hub-overview__icon--red' )
            : 'mfseo-hub-overview__icon--blue';

        $nonce = wp_create_nonce( 'mindfulseo_admin' );
        $ajurl = admin_url( 'admin-ajax.php' );
        ?>
        <div class="mfseo-tab-content mfseo-tab-links">

            <div class="mfseo-hub-overview">
                <div class="mfseo-hub-overview__icon <?php echo esc_attr( $status_icon ); ?>">
                    <span class="dashicons dashicons-admin-links"></span>
                </div>
                <div class="mfseo-hub-overview__text" id="mfseo-link-health-summary">
                    <?php if ( ! $has_scan ) : ?>
                        <strong><?php _e( 'Not yet scanned', 'mindfulseo' ); ?></strong>
                        <p><?php _e( 'Checks links inside your content on your server only — no AI calls, no DataForSEO credits. By default only the most recently updated posts are scanned (pick how many below). Use Quick to skip external sites; run a larger or full scan when you need it.', 'mindfulseo' ); ?></p>
                    <?php elseif ( $total === 0 ) : ?>
                        <strong><?php _e( 'No broken links found', 'mindfulseo' ); ?></strong>
                        <p><?php
                        if ( $posts_scanned > 0 ) {
                            if ( $scan_quick ) {
                                printf(
                                    /* translators: 1: posts scanned, 2: seconds */
                                    __( 'Scanned %1$s posts in %2$ss (internal links only; external sites were not checked).', 'mindfulseo' ),
                                    number_format_i18n( $posts_scanned ),
                                    esc_html( $elapsed )
                                );
                            } else {
                                printf(
                                    /* translators: 1: posts scanned, 2: seconds */
                                    __( 'Scanned %1$s posts in %2$ss. Checked links resolve (internal + sample of external).', 'mindfulseo' ),
                                    number_format_i18n( $posts_scanned ),
                                    esc_html( $elapsed )
                                );
                            }
                        } else {
                            printf( __( 'Scan completed in %ss.', 'mindfulseo' ), esc_html( $elapsed ) );
                        }
                        echo $scope_html;
                        ?></p>
                    <?php else : ?>
                        <strong><?php printf( _n( '%d broken link found', '%d broken links found', $total, 'mindfulseo' ), $total ); ?></strong>
                        <p><?php
                        printf(
                            __( '%1$d internal &middot; %2$d external &mdash; %3$s posts scanned in %4$ss.', 'mindfulseo' ),
                            $int_count,
                            $ext_count,
                            $posts_scanned > 0 ? number_format_i18n( $posts_scanned ) : '—',
                            esc_html( $elapsed )
                        );
                        if ( $ext_trunc ) {
                            echo ' <span style="color:#64748b;">' . esc_html__( 'Some external links were not checked (limit). Run again after fixes.', 'mindfulseo' ) . '</span>';
                        }
                        if ( $scan_quick && $ext_count === 0 ) {
                            echo ' <span style="color:#64748b;">' . esc_html__( 'Quick scan: outbound URLs were not tested.', 'mindfulseo' ) . '</span>';
                        }
                        echo $scope_html;
                        ?></p>
                    <?php endif; ?>
                </div>
                <div class="mfseo-hub-overview__action" style="display:flex;flex-direction:column;align-items:flex-end;gap:10px;max-width:420px;text-align:right;">
                    <label for="mfseo-link-scan-limit" style="font-size:13px;color:#50575e;display:block;width:100%;"><?php _e( 'Posts to scan (most recently updated first)', 'mindfulseo' ); ?></label>
                    <select id="mfseo-link-scan-limit" style="width:100%;max-width:420px;">
                        <option value="10">10</option>
                        <option value="20" selected>20 — <?php esc_html_e( 'recommended', 'mindfulseo' ); ?></option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="0"><?php esc_html_e( 'All published (slow on large sites)', 'mindfulseo' ); ?></option>
                    </select>
                    <p class="description" style="margin:6px 0 0;text-align:left;width:100%;max-width:420px;">
                        <?php _e( 'This limits how many posts are checked for links — not how many broken links to “find.” Every broken link found inside those posts is listed (could be none or many). Older posts outside the sample are not scanned until you choose a larger number or run a full scan.', 'mindfulseo' ); ?>
                    </p>
                    <label style="display:flex;align-items:flex-start;gap:8px;font-size:13px;color:#50575e;cursor:pointer;user-select:none;text-align:left;">
                        <input type="checkbox" id="mfseo-link-scan-quick" checked style="margin-top:3px;" />
                        <?php _e( 'Quick: internal links only (skip checking external websites)', 'mindfulseo' ); ?>
                    </label>
                    <button type="button" id="mfseo-scan-links-btn" class="button button-primary">
                        <span class="dashicons dashicons-search" style="margin-top:3px;"></span>
                        <?php _e( 'Scan Links', 'mindfulseo' ); ?>
                    </button>
                </div>
            </div>
            <div id="mfseo-link-scan-progress-wrap" style="display:none;margin:-8px 0 16px;padding:12px 16px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;font-size:13px;color:#2c3338;">
                    <span id="mfseo-link-scan-progress-label"><?php esc_html_e( 'Scanning posts…', 'mindfulseo' ); ?></span>
                    <span id="mfseo-link-scan-progress-pct">0%</span>
                </div>
                <div style="height:8px;background:#e0e0e0;border-radius:4px;overflow:hidden;">
                    <div id="mfseo-link-scan-progress-bar" style="height:100%;width:0;background:#b8a064;border-radius:4px;transition:width .2s ease;"></div>
                </div>
            </div>

            <div id="mfseo-link-health-results">
                <?php $this->render_broken_links_html( $int_broken, $ext_broken, $has_scan, $ext_checked ); ?>
            </div>

        </div>

        <script type="text/javascript">
        (function($) {
            function statusBadge(code) {
                var label = code === 0 ? 'Connection failed' : 'HTTP ' + code;
                var color = (code === 404 || code === 410) ? '#dc2626' : '#d97706';
                return '<span style="background:' + color + ';color:#fff;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:600;">' + label + '</span>';
            }
            function esc(s) { return $('<span>').text(s).html(); }

            function renderTable(items, type) {
                if (!items || !items.length) return '';
                var internal = (type === 'internal');
                var urlField = internal ? 'broken_url' : 'external_url';
                var html = '<table class="widefat striped" style="margin-top:4px;"><thead><tr>';
                html += '<th><?php echo esc_js( __( 'Post with broken link', 'mindfulseo' ) ); ?></th>';
                html += '<th><?php echo esc_js( __( 'Link text', 'mindfulseo' ) ); ?></th>';
                html += '<th>' + (internal ? '<?php echo esc_js( __( 'Broken internal URL', 'mindfulseo' ) ); ?>' : '<?php echo esc_js( __( 'Broken external URL', 'mindfulseo' ) ); ?>') + '</th>';
                if (!internal) html += '<th style="width:130px;"><?php echo esc_js( __( 'Status', 'mindfulseo' ) ); ?></th>';
                html += '<th style="width:70px;"><?php echo esc_js( __( 'Fix', 'mindfulseo' ) ); ?></th>';
                html += '</tr></thead><tbody>';
                $.each(items, function(i, row) {
                    var url = row[urlField] || '';
                    var lt = (row.link_text && String(row.link_text).trim()) ? String(row.link_text).trim() : '—';
                    html += '<tr><td>' + esc(row.source_title) + '</td>';
                    html += '<td style="max-width:220px;">' + esc(lt) + '</td>';
                    html += '<td><a href="' + esc(url) + '" target="_blank" style="word-break:break-all;">' + esc(url) + '</a></td>';
                    if (!internal) html += '<td>' + statusBadge(row.status_code) + '</td>';
                    html += '<td>' + (row.edit_url ? '<a href="' + esc(row.edit_url) + '" target="_blank" class="button button-small">Edit</a>' : '') + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table>';
                return html;
            }

            function scopeSuffix(d) {
                if (!d || !d.scan_scope) return '';
                if (d.scan_scope === 'recent' && d.post_limit > 0) {
                    return ' <span style="color:#64748b;"><?php echo esc_js( __( 'Scope:', 'mindfulseo' ) ); ?> ' + d.post_limit + ' <?php echo esc_js( __( 'most recently updated posts.', 'mindfulseo' ) ); ?></span>';
                }
                if (d.scan_scope === 'full') {
                    return ' <span style="color:#64748b;"><?php echo esc_js( __( 'Scope: all published posts.', 'mindfulseo' ) ); ?></span>';
                }
                return '';
            }

            function updateHubBrokenMetric(total) {
                var $n = $('#mfseo-hub-metric-broken-count');
                if (!$n.length) {
                    return;
                }
                $n.text(total).toggleClass('mfseo-hub-metric__number--warning', total > 0);
                $('#mfseo-hub-metric-broken-desc').text('<?php echo esc_js( __( 'From your last Link Health scan', 'mindfulseo' ) ); ?>');
                var $tab = $('a.mfseo-tabs__tab[data-tab="links"]');
                var $bd = $tab.find('.mfseo-tabs__badge');
                if (total > 0) {
                    if (!$bd.length) {
                        $tab.append($('<span class="mfseo-tabs__badge"></span>').text(total));
                    } else {
                        $bd.text(total);
                    }
                } else {
                    $bd.remove();
                }
            }

            function applyScanResult(d) {
                var total = (d.internal_count || 0) + (d.external_count || 0);
                var quickNote = d.quick_mode ? ' <span style="color:#64748b;"><?php echo esc_js( __( 'External sites were not checked (quick scan). Uncheck Quick and run again to test outbound links.', 'mindfulseo' ) ); ?></span>' : '';
                var sc = scopeSuffix(d);
                if (total === 0) {
                    var ps = (d.posts_scanned && d.posts_scanned > 0) ? ('<?php echo esc_js( __( 'Scanned', 'mindfulseo' ) ); ?> ' + d.posts_scanned + ' <?php echo esc_js( __( 'posts in', 'mindfulseo' ) ); ?> ' + d.elapsed + 's. ') : '';
                    $('#mfseo-link-health-summary').html('<strong><?php echo esc_js( __( 'No broken links found', 'mindfulseo' ) ); ?></strong><p>' + ps + '<?php echo esc_js( __( 'All checked links are working.', 'mindfulseo' ) ); ?>' + quickNote + sc + '</p>');
                    $('#mfseo-link-health-results').html('<div class="mfseo-hub-empty"><span class="dashicons dashicons-yes-alt" style="color:#16a34a;font-size:36px;width:36px;height:36px;"></span><strong><?php echo esc_js( __( 'All links are working', 'mindfulseo' ) ); ?></strong><p><?php echo esc_js( __( 'No broken links detected.', 'mindfulseo' ) ); ?></p></div>');
                    updateHubBrokenMetric(0);
                    return;
                }
                var trunc = d.external_pool_truncated ? ' <span style="color:#64748b;"><?php echo esc_js( __( 'Some external URLs were not checked (limit).', 'mindfulseo' ) ); ?></span>' : '';
                $('#mfseo-link-health-summary').html('<strong>' + total + ' <?php echo esc_js( __( 'broken link', 'mindfulseo' ) ); ?>' + (total !== 1 ? 's' : '') + ' <?php echo esc_js( __( 'found', 'mindfulseo' ) ); ?></strong><p>' + (d.internal_count || 0) + ' internal &middot; ' + (d.external_count || 0) + ' external &mdash; ' + (d.posts_scanned || 0) + ' <?php echo esc_js( __( 'posts scanned in', 'mindfulseo' ) ); ?> ' + d.elapsed + 's.' + trunc + quickNote + sc + '</p>');
                var results = '';
                if (d.internal_count > 0) {
                    results += '<h3 style="margin:24px 0 6px;font-size:14px;">' +
                        '<span class="dashicons dashicons-warning" style="color:#dc2626;vertical-align:middle;"></span> ' +
                        '<?php echo esc_js( __( 'Broken Internal Links', 'mindfulseo' ) ); ?> ' +
                        '<span style="background:#dc2626;color:#fff;font-size:11px;font-weight:700;padding:1px 8px;border-radius:10px;vertical-align:middle;">' + d.internal_count + '</span></h3>';
                    results += '<p style="color:#64748b;font-size:12px;margin:0 0 6px;"><?php echo esc_js( __( 'Links on your site pointing to pages that no longer exist (slug may have changed).', 'mindfulseo' ) ); ?></p>';
                    results += renderTable(d.broken_internal, 'internal');
                }
                if (d.external_count > 0) {
                    results += '<h3 style="margin:28px 0 6px;font-size:14px;">' +
                        '<span class="dashicons dashicons-external" style="color:#d97706;vertical-align:middle;"></span> ' +
                        '<?php echo esc_js( __( 'Broken External Links', 'mindfulseo' ) ); ?> ' +
                        '<span style="background:#d97706;color:#fff;font-size:11px;font-weight:700;padding:1px 8px;border-radius:10px;vertical-align:middle;">' + d.external_count + '</span></h3>';
                    results += '<p style="color:#64748b;font-size:12px;margin:0 0 6px;"><?php echo esc_js( __( 'Checked', 'mindfulseo' ) ); ?> ' + (d.external_checked || 0) + ' <?php echo esc_js( __( 'external URLs.', 'mindfulseo' ) ); ?></p>';
                    results += renderTable(d.broken_external, 'external');
                }
                $('#mfseo-link-health-results').html(results);
                updateHubBrokenMetric(total);
            }

            $('#mfseo-scan-links-btn').on('click', function() {
                var $btn = $(this);
                var quick = $('#mfseo-link-scan-quick').is(':checked') ? 1 : 0;
                var plimit = parseInt($('#mfseo-link-scan-limit').val(), 10);
                if (isNaN(plimit)) plimit = 20;
                var scanBlurb = (plimit === 0)
                    ? '<?php echo esc_js( __( 'Checking all published posts (this can take a while).', 'mindfulseo' ) ); ?>'
                    : '<?php echo esc_js( __( 'Checking up to', 'mindfulseo' ) ); ?> ' + plimit + ' <?php echo esc_js( __( 'recently updated posts — no AI, server-side only.', 'mindfulseo' ) ); ?>';
                scanBlurb += quick ? ' <?php echo esc_js( __( 'Quick: internal links only.', 'mindfulseo' ) ); ?>' : ' <?php echo esc_js( __( 'Then a sample of external URLs.', 'mindfulseo' ) ); ?>';
                $btn.prop('disabled', true).find('.dashicons').removeClass('dashicons-search').addClass('dashicons-update mfseo-spin');
                $('#mfseo-link-scan-progress-wrap').show();
                $('#mfseo-link-scan-progress-bar').css('width', '0%');
                $('#mfseo-link-scan-progress-pct').text('0%');
                $('#mfseo-link-health-summary').html('<strong><?php echo esc_js( __( 'Scanning…', 'mindfulseo' ) ); ?></strong><p>' + scanBlurb + '</p>');
                $('#mfseo-link-health-results').html('<p style="padding:16px 0;color:#64748b;"><?php echo esc_js( __( 'Scan in progress…', 'mindfulseo' ) ); ?></p>');

                function finishFail(msg) {
                    $btn.prop('disabled', false).find('.dashicons').removeClass('dashicons-update mfseo-spin').addClass('dashicons-search');
                    $('#mfseo-link-scan-progress-wrap').hide();
                    $('#mfseo-link-health-summary').html('<strong style="color:#dc2626;"><?php echo esc_js( __( 'Scan failed', 'mindfulseo' ) ); ?></strong><p>' + esc(msg || 'Unknown error') + '</p>');
                }

                function runPhase(phase) {
                    $.ajax({
                        url: '<?php echo esc_js( $ajurl ); ?>',
                        type: 'POST',
                        timeout: 120000,
                        data: {
                            action: 'mindfulseo_scan_broken_links',
                            nonce: '<?php echo esc_js( $nonce ); ?>',
                            phase: phase,
                            quick: quick,
                            post_limit: $('#mfseo-link-scan-limit').val()
                        }
                    }).done(function(res) {
                        if (!res.success) {
                            finishFail((res.data && res.data.message) || 'Unknown error');
                            return;
                        }
                        var payload = res.data;
                        if (payload.error) {
                            finishFail(payload.error);
                            return;
                        }
                        if (payload.needs_more && payload.progress) {
                            var p = payload.progress.percent || 0;
                            $('#mfseo-link-scan-progress-bar').css('width', p + '%');
                            $('#mfseo-link-scan-progress-pct').text(p + '%');
                            $('#mfseo-link-scan-progress-label').text('<?php echo esc_js( __( 'Scanning posts…', 'mindfulseo' ) ); ?> ' + (payload.progress.scanned || 0) + ' / ' + (payload.progress.total || ''));
                            setTimeout(function() { runPhase('step'); }, 30);
                            return;
                        }
                        if (payload.data) {
                            $btn.prop('disabled', false).find('.dashicons').removeClass('dashicons-update mfseo-spin').addClass('dashicons-search');
                            $('#mfseo-link-scan-progress-wrap').hide();
                            applyScanResult(payload.data);
                            return;
                        }
                        /* Legacy single response */
                        applyScanResult(payload);
                        $btn.prop('disabled', false).find('.dashicons').removeClass('dashicons-update mfseo-spin').addClass('dashicons-search');
                        $('#mfseo-link-scan-progress-wrap').hide();
                    }).fail(function(xhr) {
                        $btn.prop('disabled', false).find('.dashicons').removeClass('dashicons-update mfseo-spin').addClass('dashicons-search');
                        $('#mfseo-link-scan-progress-wrap').hide();
                        $('#mfseo-link-health-summary').html('<strong style="color:#dc2626;"><?php echo esc_js( __( 'Request failed', 'mindfulseo' ) ); ?></strong><p><?php echo esc_js( __( 'The request timed out — try Quick scan or check server logs.', 'mindfulseo' ) ); ?></p>');
                    });
                }
                runPhase('start');
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Server-side render of cached broken link results (used on initial page load).
     */
    private function render_broken_links_html( $int_broken, $ext_broken, $has_scan, $ext_checked ) {
        $int_count = count( $int_broken );
        $ext_count = count( $ext_broken );
        $total     = $int_count + $ext_count;

        if ( ! $has_scan ) { ?>
            <div class="mfseo-hub-empty" style="margin-top:24px;">
                <span class="dashicons dashicons-admin-links" style="color:#94a3b8;font-size:36px;width:36px;height:36px;"></span>
                <strong><?php _e( 'Ready to scan', 'mindfulseo' ); ?></strong>
                <p><?php _e( 'Click "Scan Links" above to check your posts for broken links. No API credits used — all checks are local. Results are cached for 6 hours.', 'mindfulseo' ); ?></p>
            </div>
        <?php return; }

        if ( $total === 0 ) { ?>
            <div class="mfseo-hub-empty" style="margin-top:24px;">
                <span class="dashicons dashicons-yes-alt" style="color:#16a34a;font-size:36px;width:36px;height:36px;"></span>
                <strong><?php _e( 'All links are working', 'mindfulseo' ); ?></strong>
                <p><?php _e( 'No broken links detected in your content.', 'mindfulseo' ); ?></p>
            </div>
        <?php return; }

        if ( $int_count > 0 ) : ?>
            <h3 style="margin:24px 0 6px;font-size:14px;">
                <span class="dashicons dashicons-warning" style="color:#dc2626;vertical-align:middle;"></span>
                <?php _e( 'Broken Internal Links', 'mindfulseo' ); ?>
                <span style="background:#dc2626;color:#fff;font-size:11px;font-weight:700;padding:1px 8px;border-radius:10px;vertical-align:middle;"><?php echo $int_count; ?></span>
            </h3>
            <p style="color:#64748b;font-size:12px;margin:0 0 6px;"><?php _e( 'Links on your site pointing to pages that no longer exist (slug may have changed).', 'mindfulseo' ); ?></p>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php _e( 'Post with broken link', 'mindfulseo' ); ?></th>
                    <th><?php _e( 'Link text', 'mindfulseo' ); ?></th>
                    <th><?php _e( 'Broken internal URL', 'mindfulseo' ); ?></th>
                    <th style="width:70px;"><?php _e( 'Fix', 'mindfulseo' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $int_broken as $row ) :
                    $lt = ! empty( $row['link_text'] ) ? $row['link_text'] : '—';
                ?>
                    <tr>
                        <td><?php echo esc_html( $row['source_title'] ); ?></td>
                        <td style="max-width:220px;"><?php echo esc_html( $lt ); ?></td>
                        <td><a href="<?php echo esc_url( $row['broken_url'] ); ?>" target="_blank" style="word-break:break-all;"><?php echo esc_html( $row['broken_url'] ); ?></a></td>
                        <td><a href="<?php echo esc_url( get_edit_post_link( $row['source_id'] ) ); ?>" target="_blank" class="button button-small"><?php _e( 'Edit', 'mindfulseo' ); ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;

        if ( $ext_count > 0 ) : ?>
            <h3 style="margin:28px 0 6px;font-size:14px;">
                <span class="dashicons dashicons-external" style="color:#d97706;vertical-align:middle;"></span>
                <?php _e( 'Broken External Links', 'mindfulseo' ); ?>
                <span style="background:#d97706;color:#fff;font-size:11px;font-weight:700;padding:1px 8px;border-radius:10px;vertical-align:middle;"><?php echo $ext_count; ?></span>
            </h3>
            <p style="color:#64748b;font-size:12px;margin:0 0 6px;"><?php printf( __( 'Checked %d external URLs.', 'mindfulseo' ), $ext_checked ); ?></p>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php _e( 'Post with broken link', 'mindfulseo' ); ?></th>
                    <th><?php _e( 'Link text', 'mindfulseo' ); ?></th>
                    <th><?php _e( 'Broken external URL', 'mindfulseo' ); ?></th>
                    <th style="width:130px;"><?php _e( 'Status', 'mindfulseo' ); ?></th>
                    <th style="width:70px;"><?php _e( 'Fix', 'mindfulseo' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $ext_broken as $row ) :
                    $badge = $row['status_code'] === 0 ? 'Connection failed' : 'HTTP ' . $row['status_code'];
                    $color = ( $row['status_code'] === 404 || $row['status_code'] === 410 ) ? '#dc2626' : '#d97706';
                    $lt    = ! empty( $row['link_text'] ) ? $row['link_text'] : '—';
                ?>
                    <tr>
                        <td><?php echo esc_html( $row['source_title'] ); ?></td>
                        <td style="max-width:220px;"><?php echo esc_html( $lt ); ?></td>
                        <td><a href="<?php echo esc_url( $row['external_url'] ); ?>" target="_blank" style="word-break:break-all;"><?php echo esc_html( $row['external_url'] ); ?></a></td>
                        <td><span style="background:<?php echo esc_attr( $color ); ?>;color:#fff;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:600;"><?php echo esc_html( $badge ); ?></span></td>
                        <td><a href="<?php echo esc_url( get_edit_post_link( $row['source_id'] ) ); ?>" target="_blank" class="button button-small"><?php _e( 'Edit', 'mindfulseo' ); ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }
    
    /**
     * Render the batch optimizer page
     */
    public function render_batch_optimize_page() {
        // Include the batch optimizer page class
        require_once MINDFULSEO_PLUGIN_DIR . 'admin/class-batch-optimizer-page.php';
        
        $batch_page = new MFSEO_Batch_Optimizer_Page();
        $batch_page->render_page();
    }
    
    /**
     * Render the SEO audit page
     */
    public function render_seo_audit_page() {
        // Include the SEO audit page class
        require_once MINDFULSEO_PLUGIN_DIR . 'admin/class-seo-audit-page.php';
        
        MFSEO_SEO_Audit_Page::render();
    }
}

