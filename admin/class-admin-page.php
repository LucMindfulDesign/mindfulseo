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
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
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

            if (file_exists($batch_optimizer_path)) {
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
                ),
            ));
        }
    }
    
    /**
     * Get branding header HTML - WooCommerce Style
     */
    private function get_branding_header($title = 'MindfulSEO') {
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
        
        $settings = MindfulSEO::get_settings();
        $logger = class_exists('MFSEO_Logger') ? MFSEO_Logger::get_instance() : null;
        $seo_adapter = class_exists('MFSEO_SEO_Plugin_Adapter') ? MFSEO_SEO_Plugin_Adapter::get_instance() : null;
        
        // Get stats
        $stats = array();
        if ($logger) {
            $stats = $logger->get_api_stats('month');
        }
        
        ?>
        <!-- Output WordPress notices OUTSIDE and BEFORE the wrap div -->
        <?php settings_errors(); ?>
        
        <?php echo $this->get_branding_header(); ?>
        
        <div class="wrap mindfulseo-page">
            
            <div class="mindfulseo-content">
                <div class="mindfulseo-welcome-card" style="margin-bottom: 30px;">
                    <h2><?php _e('🚀 Welcome to MindfulSEO!', 'mindfulseo'); ?></h2>
                    <p style="font-size: 14px; color: #646970; margin-bottom: 20px;">
                        <?php _e('AI-powered SEO optimization and blog content generation with brand-aware guidelines.', 'mindfulseo'); ?>
                    </p>
                    <p style="font-size: 13px; color: #787c82; margin-bottom: 25px;">
                        <?php _e('Get started by configuring your API keys and uploading your keyword strategy.', 'mindfulseo'); ?>
                    </p>
                    
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 25px;">
                        <div>
                            <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 12px; color: #1d2327;">⚙️ Setup</h3>
                            <ul style="margin: 0; padding: 0; list-style: none; line-height: 2;">
                                <li><a href="<?php echo admin_url('admin.php?page=mindfulseo-settings'); ?>" style="text-decoration: none;"><?php _e('Configure API Keys', 'mindfulseo'); ?></a></li>
                                <li><a href="<?php echo admin_url('admin.php?page=mindfulseo-keywords'); ?>" style="text-decoration: none;"><?php _e('Upload Keyword Strategy', 'mindfulseo'); ?></a></li>
                                <li><a href="<?php echo admin_url('admin.php?page=mindfulseo-guidelines'); ?>" style="text-decoration: none;"><?php _e('Add Language Guidelines', 'mindfulseo'); ?></a></li>
                            </ul>
                        </div>
                        <div>
                            <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 12px; color: #1d2327;">📚 Features</h3>
                            <ul style="margin: 0; padding: 0; list-style: none; line-height: 2; color: #646970;">
                                <li><?php _e('AI Blog Writer (Coming Soon)', 'mindfulseo'); ?></li>
                                <li><?php _e('SEO Optimization (Coming Soon)', 'mindfulseo'); ?></li>
                                <li><?php _e('Batch Processing (Coming Soon)', 'mindfulseo'); ?></li>
                                <li><?php _e('Content Audit (Coming Soon)', 'mindfulseo'); ?></li>
                            </ul>
                        </div>
                        <div>
                            <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 12px; color: #1d2327;">🔌 Integration</h3>
                            
                            <!-- SEO Plugin Connection -->
                            <p style="margin: 0 0 8px 0; padding-top: 4px;">
                                <?php if ($seo_adapter && $seo_adapter->is_seo_plugin_active()): ?>
                                    <span style="color: #46b450;">✓</span>
                                    <?php printf(__('Connected to %s', 'mindfulseo'), '<strong>' . esc_html($seo_adapter->get_active_plugin() === 'rankmath' ? 'RankMath SEO' : 'Yoast SEO') . '</strong>'); ?>
                                <?php else: ?>
                                    <span style="color: #dc3232;">⚠</span>
                                    <?php _e('No SEO plugin detected', 'mindfulseo'); ?>
                                <?php endif; ?>
                            </p>
                            
                            <!-- OpenAI API Connection -->
                            <?php 
                            $settings = get_option('mindfulseo_settings', array());
                            $openai_key = isset($settings['openai_api_key']) ? $settings['openai_api_key'] : '';
                            ?>
                            <p style="margin: 0 0 8px 0;">
                                <?php if (!empty($openai_key)): ?>
                                    <span style="color: #46b450;">✓</span>
                                    <strong><?php _e('OpenAI API Key', 'mindfulseo'); ?></strong>
                                    <?php _e('Configured', 'mindfulseo'); ?>
                                <?php else: ?>
                                    <span style="color: #dc3232;">⚠</span>
                                    <strong><?php _e('OpenAI API Key', 'mindfulseo'); ?></strong>
                                    <?php _e('Not configured', 'mindfulseo'); ?>
                                <?php endif; ?>
                            </p>
                            
                            <!-- Claude API Connection -->
                            <?php 
                            $claude_key = isset($settings['claude_api_key']) ? $settings['claude_api_key'] : '';
                            ?>
                            <p style="margin: 0 0 8px 0;">
                                <?php if (!empty($claude_key)): ?>
                                    <span style="color: #46b450;">✓</span>
                                    <strong><?php _e('Claude API Key', 'mindfulseo'); ?></strong>
                                    <?php _e('Configured', 'mindfulseo'); ?>
                                <?php else: ?>
                                    <span style="color: #dc3232;">⚠</span>
                                    <strong><?php _e('Claude API Key', 'mindfulseo'); ?></strong>
                                    <?php _e('Not configured', 'mindfulseo'); ?>
                                <?php endif; ?>
                            </p>
                            
                            <!-- DataForSEO API Connection -->
                            <?php 
                            $dataforseo_login = isset($settings['dataforseo_login']) ? $settings['dataforseo_login'] : '';
                            $dataforseo_password = isset($settings['dataforseo_password']) ? $settings['dataforseo_password'] : '';
                            $dataforseo_configured = !empty($dataforseo_login) && !empty($dataforseo_password);
                            ?>
                            <p style="margin: 0;">
                                <?php if ($dataforseo_configured): ?>
                                    <span style="color: #46b450;">✓</span>
                                    <strong><?php _e('DataForSEO API', 'mindfulseo'); ?></strong>
                                    <?php _e('Configured', 'mindfulseo'); ?>
                                <?php else: ?>
                                    <span style="color: #dc3232;">⚠</span>
                                    <strong><?php _e('DataForSEO API', 'mindfulseo'); ?></strong>
                                    <?php _e('Not configured', 'mindfulseo'); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="mindfulseo-stats-grid">
                <div class="mindfulseo-stat-card stat-info">
                    <div class="stat-icon">
                        <img src="<?php echo MINDFULSEO_PLUGIN_URL . 'assets/icon-activity.svg'; ?>" alt="" />
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format(isset($stats['total_calls']) ? $stats['total_calls'] : 0); ?></div>
                        <div class="stat-label"><?php _e('API Calls (Month)', 'mindfulseo'); ?></div>
                        <?php if (isset($stats['by_provider']) && !empty($stats['by_provider'])): ?>
                            <div style="font-size: 11px; color: #999; margin-top: 6px; line-height: 1.4;">
                                <?php foreach ($stats['by_provider'] as $provider => $pstats): ?>
                                    <div><?php echo esc_html(ucfirst($provider)); ?>: <?php echo number_format($pstats['calls']); ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="mindfulseo-stat-card stat-warning">
                    <div class="stat-icon">
                        <img src="<?php echo MINDFULSEO_PLUGIN_URL . 'assets/icon-tokens.svg'; ?>" alt="" />
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format(isset($stats['total_tokens']) ? $stats['total_tokens'] : 0); ?></div>
                        <div class="stat-label"><?php _e('Tokens Used', 'mindfulseo'); ?></div>
                        <?php if (isset($stats['by_provider']) && !empty($stats['by_provider'])): ?>
                            <div style="font-size: 11px; color: #999; margin-top: 6px; line-height: 1.4;">
                                <?php foreach ($stats['by_provider'] as $provider => $pstats): ?>
                                    <div><?php echo esc_html(ucfirst($provider)); ?>: <?php echo number_format($pstats['total_tokens']); ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="mindfulseo-stat-card stat-success">
                    <div class="stat-icon">
                        <img src="<?php echo MINDFULSEO_PLUGIN_URL . 'assets/icon-dollar.svg'; ?>" alt="" />
                    </div>
                    <div class="stat-content">
                        <div class="stat-value">$<?php echo number_format(isset($stats['total_cost']) ? $stats['total_cost'] : 0, 2); ?></div>
                        <div class="stat-label"><?php _e('Total Cost', 'mindfulseo'); ?></div>
                        <?php if (isset($stats['by_provider']) && !empty($stats['by_provider'])): ?>
                            <div style="font-size: 11px; color: #999; margin-top: 6px; line-height: 1.4;">
                                <?php foreach ($stats['by_provider'] as $provider => $pstats): ?>
                                    <div><?php echo esc_html(ucfirst($provider)); ?>: $<?php echo number_format($pstats['cost'], 3); ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="mindfulseo-stat-card">
                    <div class="stat-icon">
                        <img src="<?php echo MINDFULSEO_PLUGIN_URL . 'assets/icon-check.svg'; ?>" alt="" />
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php _e('Coming Soon', 'mindfulseo'); ?></div>
                        <div class="stat-label"><?php _e('Posts Optimized', 'mindfulseo'); ?></div>
                    </div>
                </div>
            </div>
            
            <div class="mindfulseo-status-panel">
                <h2><?php _e('System Status', 'mindfulseo'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Component', 'mindfulseo'); ?></th>
                            <th><?php _e('Status', 'mindfulseo'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?php _e('Database Tables', 'mindfulseo'); ?></td>
                            <td><span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> <?php _e('Installed', 'mindfulseo'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('SEO Plugin', 'mindfulseo'); ?></td>
                            <td>
                                <?php if ($seo_adapter && $seo_adapter->is_seo_plugin_active()): ?>
                                    <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                                    <?php echo esc_html($seo_adapter->get_active_plugin() === 'rankmath' ? 'RankMath' : 'Yoast'); ?>
                                <?php else: ?>
                                    <span class="dashicons dashicons-warning" style="color: #dc3232;"></span>
                                    <?php _e('Not detected', 'mindfulseo'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('OpenAI API Key', 'mindfulseo'); ?></td>
                            <td>
                                <?php if (!empty($settings['openai_api_key'])): ?>
                                    <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                                    <?php _e('Configured', 'mindfulseo'); ?>
                                <?php else: ?>
                                    <span class="dashicons dashicons-warning" style="color: #dc3232;"></span>
                                    <?php _e('Not configured', 'mindfulseo'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Claude API Key', 'mindfulseo'); ?></td>
                            <td>
                                <?php if (!empty($settings['claude_api_key'])): ?>
                                    <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                                    <?php _e('Configured', 'mindfulseo'); ?>
                                <?php else: ?>
                                    <span class="dashicons dashicons-warning" style="color: #dc3232;"></span>
                                    <?php _e('Not configured', 'mindfulseo'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <style>
            .mindfulseo-dashboard {
                max-width: 1200px;
            }
            .mindfulseo-welcome-panel {
                background: #fff;
                border: 1px solid #c3c4c7;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                padding: 20px;
                margin: 20px 0;
            }
            </style>
        <?php
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'mindfulseo'));
        }
        
        $settings = MindfulSEO::get_settings();
        
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
            
            <div style="background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; margin: 20px 20px 0 0;">
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
                        
                        <tr>
                            <th scope="row">
                                <label for="openai_model"><?php _e('OpenAI Model', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <select id="openai_model" name="openai_model">
                                    <optgroup label="GPT-5 Series (Latest - Nov 2025)">
                                        <option value="gpt-5" <?php selected(isset($settings['openai_model']) ? $settings['openai_model'] : 'gpt-5', 'gpt-5'); ?>>
                                            GPT-5 (Recommended - Released Aug 2025, 272K context, Best Quality)
                                        </option>
                                        <option value="gpt-5-codex" <?php selected(isset($settings['openai_model']) ? $settings['openai_model'] : '', 'gpt-5-codex'); ?>>
                                            GPT-5 Codex (Optimized for Coding, Sep 2025)
                                        </option>
                                    </optgroup>
                                    <optgroup label="GPT-4 Series">
                                        <option value="gpt-4o" <?php selected(isset($settings['openai_model']) ? $settings['openai_model'] : '', 'gpt-4o'); ?>>
                                            GPT-4o (Fast & Multimodal)
                                        </option>
                                        <option value="gpt-4o-mini" <?php selected(isset($settings['openai_model']) ? $settings['openai_model'] : '', 'gpt-4o-mini'); ?>>
                                            GPT-4o Mini (Budget-Friendly, Fast)
                                        </option>
                                        <option value="gpt-4-turbo" <?php selected(isset($settings['openai_model']) ? $settings['openai_model'] : '', 'gpt-4-turbo'); ?>>
                                            GPT-4 Turbo (Vision Capable, 128K context)
                                        </option>
                                        <option value="gpt-4" <?php selected(isset($settings['openai_model']) ? $settings['openai_model'] : '', 'gpt-4'); ?>>
                                            GPT-4 (Classic, Most Reliable)
                                        </option>
                                    </optgroup>
                                    <optgroup label="Budget Options">
                                        <option value="gpt-3.5-turbo" <?php selected(isset($settings['openai_model']) ? $settings['openai_model'] : '', 'gpt-3.5-turbo'); ?>>
                                            GPT-3.5 Turbo (Fastest & Cheapest)
                                        </option>
                                    </optgroup>
                                </select>
                                <p class="description"><?php _e('Choose which OpenAI model to use. GPT-5 (Aug 2025) is the latest and most capable.', 'mindfulseo'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
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
                        
                        <tr>
                            <th scope="row">
                                <label for="claude_model"><?php _e('Claude Model', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <select id="claude_model" name="claude_model">
                                    <optgroup label="Claude 4 Series (Latest - Nov 2025)">
                                        <option value="claude-sonnet-4-5" <?php selected(isset($settings['claude_model']) ? $settings['claude_model'] : 'claude-sonnet-4-5', 'claude-sonnet-4-5'); ?>>
                                            Claude Sonnet 4.5 (Recommended - Released Sep 2025, Best Coding & Agents)
                                        </option>
                                        <option value="claude-opus-4-1" <?php selected(isset($settings['claude_model']) ? $settings['claude_model'] : '', 'claude-opus-4-1'); ?>>
                                            Claude Opus 4.1 (Most Powerful, Released Aug 2025, 200K context)
                                        </option>
                                    </optgroup>
                                    <optgroup label="Claude 3.5 Series">
                                        <option value="claude-3-5-sonnet-20241022" <?php selected(isset($settings['claude_model']) ? $settings['claude_model'] : '', 'claude-3-5-sonnet-20241022'); ?>>
                                            Claude 3.5 Sonnet (Oct 2024) ⚠️ DEPRECATED
                                        </option>
                                        <option value="claude-3-5-sonnet-20240620" <?php selected(isset($settings['claude_model']) ? $settings['claude_model'] : '', 'claude-3-5-sonnet-20240620'); ?>>
                                            Claude 3.5 Sonnet (Jun 2024) ✅ WORKS
                                        </option>
                                        <option value="claude-3-5-haiku-20241022" <?php selected(isset($settings['claude_model']) ? $settings['claude_model'] : '', 'claude-3-5-haiku-20241022'); ?>>
                                            Claude 3.5 Haiku (Fast & Budget-Friendly, Oct 2024)
                                        </option>
                                    </optgroup>
                                    <optgroup label="Claude 3 Series (Legacy)">
                                        <option value="claude-3-opus-20240229" <?php selected(isset($settings['claude_model']) ? $settings['claude_model'] : '', 'claude-3-opus-20240229'); ?>>
                                            Claude 3 Opus
                                        </option>
                                        <option value="claude-3-sonnet-20240229" <?php selected(isset($settings['claude_model']) ? $settings['claude_model'] : '', 'claude-3-sonnet-20240229'); ?>>
                                            Claude 3 Sonnet
                                        </option>
                                        <option value="claude-3-haiku-20240307" <?php selected(isset($settings['claude_model']) ? $settings['claude_model'] : '', 'claude-3-haiku-20240307'); ?>>
                                            Claude 3 Haiku
                                        </option>
                                    </optgroup>
                                </select>
                                <p class="description"><?php _e('Choose which Claude model to use. Claude Sonnet 4.5 (Sep 2025) is the latest and recommended.', 'mindfulseo'); ?></p>
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
                                    <option value="openai" <?php selected(isset($settings['primary_provider']) ? $settings['primary_provider'] : 'openai', 'openai'); ?>>
                                        OpenAI
                                    </option>
                                    <option value="claude" <?php selected(isset($settings['primary_provider']) ? $settings['primary_provider'] : 'openai', 'claude'); ?>>
                                        Claude
                                    </option>
                                </select>
                                <p class="description"><?php _e('Primary provider for AI operations', 'mindfulseo'); ?></p>
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
                                    <?php _e('Automatically use secondary provider if primary fails', 'mindfulseo'); ?>
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
        </div>
        <?php
    }
    
    /**
     * Render keywords page
     */
    public function render_keywords_page() {
        error_log('=== MindfulSEO: render_keywords_page() START ===');
        error_log('MindfulSEO: REQUEST_METHOD = ' . $_SERVER['REQUEST_METHOD']);
        error_log('MindfulSEO: POST keys = ' . implode(', ', array_keys($_POST)));
        error_log('MindfulSEO: GET keys = ' . implode(', ', array_keys($_GET)));
        
        // Initialize keyword manager
        $keyword_manager = null;
        if (class_exists('MFSEO_Keyword_Manager')) {
            $keyword_manager = MFSEO_Keyword_Manager::get_instance();
        }
        
        // Handle CSV upload
        if (isset($_POST['mindfulseo_upload_keywords']) && check_admin_referer('mindfulseo_upload_keywords', 'mindfulseo_keywords_nonce')) {
            if (isset($_FILES['keyword_csv']) && $keyword_manager) {
                $result = $keyword_manager->import_csv($_FILES['keyword_csv']);
                
                if (is_wp_error($result)) {
                    add_settings_error(
                        'mindfulseo_keywords',
                        'import_error',
                        $result->get_error_message(),
                        'error'
                    );
                } else {
                    add_settings_error(
                        'mindfulseo_keywords',
                        'import_success',
                        sprintf(
                            __('Successfully imported %d keywords (%d skipped as duplicates)', 'mindfulseo'),
                            $result['imported'],
                            $result['skipped']
                        ),
                        'success'
                    );
                }
            }
        }
        
        // Handle auto-generate keywords
        if (isset($_POST['mindfulseo_autogenerate_keywords']) && check_admin_referer('mindfulseo_autogenerate_keywords', 'mindfulseo_autogen_nonce')) {
            $post_types = isset($_POST['analyze_post_types']) ? array_map('sanitize_text_field', $_POST['analyze_post_types']) : array('post');
            $limit = isset($_POST['analyze_limit']) ? intval($_POST['analyze_limit']) : 50;
            
            // Analyze content
            if (class_exists('MFSEO_Content_Analyzer')) {
                $analyzer = new MFSEO_Content_Analyzer();
                $suggestions = $analyzer->analyze_for_keywords(array(
                    'post_types' => $post_types,
                    'limit' => $limit
                ));
                
                if (empty($suggestions)) {
                    add_settings_error(
                        'mindfulseo_keywords',
                        'analyze_empty',
                        __('No keywords found. Try analyzing more posts or different post types.', 'mindfulseo'),
                        'warning'
                    );
                } else {
                    // Import suggestions into database
                    $imported = 0;
                    $skipped = 0;
                    foreach ($suggestions as $suggestion) {
                        if ($keyword_manager) {
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
                            } else {
                                // Skip duplicates silently
                                if ($result->get_error_code() === 'duplicate_keyword') {
                                    $skipped++;
                                }
                            }
                        }
                    }
                    
                    if ($imported > 0) {
                        $message = sprintf(
                            __('Successfully analyzed content and imported %d keyword suggestions!', 'mindfulseo'),
                            $imported
                        );
                        if ($skipped > 0) {
                            $message .= ' ' . sprintf(__('(%d duplicates skipped)', 'mindfulseo'), $skipped);
                        }
                        add_settings_error(
                            'mindfulseo_keywords',
                            'analyze_success',
                            $message,
                            'success'
                        );
                    } else {
                        add_settings_error(
                            'mindfulseo_keywords',
                            'analyze_no_new',
                            __('All suggested keywords already exist in your database.', 'mindfulseo'),
                            'warning'
                        );
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
        
        // Handle delete all keywords
        if (isset($_POST['mindfulseo_delete_all_keywords']) && check_admin_referer('mindfulseo_delete_all_keywords', 'mindfulseo_delete_all_nonce')) {
            error_log('MindfulSEO: Delete All Keywords POST received');
            if ($keyword_manager) {
                error_log('MindfulSEO: Keyword manager exists');
                global $wpdb;
                $table_name = $wpdb->prefix . 'mindfulseo_keywords';
                $deleted = $wpdb->query("DELETE FROM {$table_name}");
                error_log('MindfulSEO: Deleted ' . $deleted . ' keywords');
                
                if ($deleted !== false) {
                    error_log('MindfulSEO: Redirecting with deleted_all=' . $deleted);
                    // Redirect to show success message
                    wp_redirect(add_query_arg(array(
                        'page' => 'mindfulseo-keywords',
                        'deleted_all' => $deleted
                    ), admin_url('admin.php')));
                    exit;
                } else {
                    error_log('MindfulSEO: wpdb->query returned false');
                }
            } else {
                error_log('MindfulSEO: Keyword manager is NULL');
            }
        }
        
        // Show success message after redirect
        if (isset($_GET['deleted_all'])) {
            $count = intval($_GET['deleted_all']);
            add_settings_error(
                'mindfulseo_keywords',
                'delete_all_success',
                sprintf(__('%d keywords deleted successfully.', 'mindfulseo'), $count),
                'success'
            );
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
        
        // Get keywords
        $keywords = array();
        $stats = array();
        if ($keyword_manager) {
            $keywords = $keyword_manager->get_keywords(array('limit' => 999999)); // Get ALL keywords
            $stats = $keyword_manager->get_statistics();
            
            // Sort keywords alphabetically by primary keyword
            if (!empty($keywords)) {
                usort($keywords, function($a, $b) {
                    return strcasecmp($a->primary_keyword, $b->primary_keyword);
                });
            }
        }
        
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
                    </table>
                    
                    <p class="submit">
                        <button type="submit" name="mindfulseo_autogenerate_keywords" class="button button-primary">
                            <?php _e('🔍 Analyze Content & Generate Keywords', 'mindfulseo'); ?>
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
                <h2><?php _e('📤 Upload Keyword CSV', 'mindfulseo'); ?></h2>
                <p><?php _e('Upload a CSV file with your keyword waterfall strategy. Map primary keywords to longtail variations with search intent and priority levels.', 'mindfulseo'); ?></p>
                <p><strong><?php _e('Required columns:', 'mindfulseo'); ?></strong> PRIMARY KEYWORD, LONGTAIL KEYWORD, SEARCH INTENT, PRIORITY</p>
                
                <form method="post" enctype="multipart/form-data" style="margin-top: 20px;">
                    <?php wp_nonce_field('mindfulseo_upload_keywords', 'mindfulseo_keywords_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="keyword_csv"><?php _e('CSV File', 'mindfulseo'); ?></label>
                            </th>
                            <td>
                                <input type="file" name="keyword_csv" id="keyword_csv" accept=".csv,.txt" required>
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
                                <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
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
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 20%; cursor: pointer;" class="sortable" data-sort="primary_keyword">
                                    <?php _e('Primary Keyword', 'mindfulseo'); ?> 
                                    <span class="dashicons dashicons-sort" style="font-size: 14px; vertical-align: middle;"></span>
                                </th>
                                <th style="width: 23%; cursor: pointer;" class="sortable" data-sort="longtail_keyword">
                                    <?php _e('Longtail Keyword', 'mindfulseo'); ?>
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
                                <th style="width: 8%; cursor: pointer;" class="sortable" data-sort="priority">
                                    <?php _e('Priority', 'mindfulseo'); ?>
                                    <span class="dashicons dashicons-sort" style="font-size: 14px; vertical-align: middle;"></span>
                                </th>
                                <th style="width: 11%;"><?php _e('Actions', 'mindfulseo'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($keywords as $keyword): ?>
                                <tr>
                                    <td><strong><span class="editable" contenteditable="true" 
                                        data-id="<?php echo $keyword->id; ?>" 
                                        data-field="primary_keyword" 
                                        data-original="<?php echo esc_attr($keyword->primary_keyword); ?>"><?php echo esc_html($keyword->primary_keyword); ?></span></strong></td>
                                    <td><span class="editable" contenteditable="true" 
                                        data-id="<?php echo $keyword->id; ?>" 
                                        data-field="longtail_keyword" 
                                        data-original="<?php echo esc_attr($keyword->longtail_keyword); ?>"><?php echo esc_html($keyword->longtail_keyword); ?></span></td>
                                    <td style="text-align: center;" data-sort-value="<?php echo isset($keyword->search_volume) && $keyword->search_volume !== null ? intval($keyword->search_volume) : -1; ?>">
                                        <?php if (isset($keyword->search_volume) && $keyword->search_volume !== null): ?>
                                            <strong><?php echo number_format(intval($keyword->search_volume)); ?></strong>
                                        <?php else: ?>
                                            <span style="color: #999;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;" data-sort-value="<?php echo isset($keyword->keyword_difficulty) && $keyword->keyword_difficulty !== null ? intval($keyword->keyword_difficulty) : -1; ?>">
                                        <?php if (isset($keyword->keyword_difficulty) && $keyword->keyword_difficulty !== null): ?>
                                            <?php 
                                            $difficulty = intval($keyword->keyword_difficulty);
                                            $color = $difficulty < 30 ? '#46b450' : ($difficulty < 70 ? '#ffb900' : '#dc3232');
                                            ?>
                                            <span style="color: <?php echo $color; ?>; font-weight: 600;">
                                                <?php echo $difficulty; ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #999;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;" data-sort-value="<?php echo isset($keyword->cpc) && $keyword->cpc !== null ? floatval($keyword->cpc) : -1; ?>">
                                        <?php if (isset($keyword->cpc) && $keyword->cpc !== null): ?>
                                            $<?php echo number_format(floatval($keyword->cpc), 2); ?>
                                        <?php else: ?>
                                            <span style="color: #999;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <select class="editable-select" 
                                                data-id="<?php echo $keyword->id; ?>" 
                                                data-field="search_intent">
                                            <option value="Informational" <?php selected($keyword->search_intent, 'Informational'); ?>>Informational</option>
                                            <option value="Navigational" <?php selected($keyword->search_intent, 'Navigational'); ?>>Navigational</option>
                                            <option value="Transactional" <?php selected($keyword->search_intent, 'Transactional'); ?>>Transactional</option>
                                            <option value="Commercial" <?php selected($keyword->search_intent, 'Commercial'); ?>>Commercial</option>
                                        </select>
                                    </td>
                                    <td>
                                        <select class="editable-select" 
                                                data-id="<?php echo $keyword->id; ?>" 
                                                data-field="priority">
                                            <option value="HIGH" <?php selected($keyword->priority, 'HIGH'); ?>>HIGH</option>
                                            <option value="MEDIUM" <?php selected($keyword->priority, 'MEDIUM'); ?>>MEDIUM</option>
                                            <option value="LOW" <?php selected($keyword->priority, 'LOW'); ?>>LOW</option>
                                        </select>
                                    </td>
                                    <td>
                                        <form method="post" style="display: inline;">
                                            <?php wp_nonce_field('mindfulseo_delete_keyword', 'mindfulseo_delete_nonce'); ?>
                                            <input type="hidden" name="keyword_id" value="<?php echo intval($keyword->id); ?>">
                                            <button type="submit" name="mindfulseo_delete_keyword" class="button button-small" 
                                                    onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this keyword?', 'mindfulseo'); ?>');">
                                                <?php _e('Delete', 'mindfulseo'); ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <?php if (!empty($keywords) && isset($stats['total'])): ?>
                        <div style="margin-top: 15px; padding: 10px; background: #f0f0f1; border-radius: 4px;">
                            <strong><?php printf(__('Showing %d keywords', 'mindfulseo'), count($keywords)); ?></strong>
                            <span style="color: #666; margin-left: 10px;">
                                <?php _e('💡 Click any cell to edit inline. Press Enter or click away to save.', 'mindfulseo'); ?>
                            </span>
                        </div>
                    <?php endif; ?>
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
        
        // Handle auto-generate guidelines
        if (isset($_POST['mindfulseo_autogenerate_guidelines']) && check_admin_referer('mindfulseo_autogenerate_guidelines', 'mindfulseo_guidelines_autogen_nonce')) {
            $post_types = isset($_POST['analyze_guideline_post_types']) ? array_map('sanitize_text_field', $_POST['analyze_guideline_post_types']) : array('post');
            $limit = isset($_POST['analyze_guideline_limit']) ? intval($_POST['analyze_guideline_limit']) : 100;
            
            // Analyze content
            if (class_exists('MFSEO_Content_Analyzer') && $guidelines_engine) {
                $analyzer = new MFSEO_Content_Analyzer();
                $suggestions = $analyzer->analyze_for_guidelines(array(
                    'post_types' => $post_types,
                    'limit' => $limit
                ));
                
                if (empty($suggestions)) {
                    add_settings_error(
                        'mindfulseo_guidelines',
                        'analyze_empty',
                        __('No patterns found. Try analyzing more posts or different post types.', 'mindfulseo'),
                        'warning'
                    );
                } else {
                    // Import capitalization rules
                    $imported = 0;
                    if (!empty($suggestions['capitalize_terms'])) {
                        foreach ($suggestions['capitalize_terms'] as $term) {
                            $result = $guidelines_engine->add_rule(array(
                                'rule_type' => 'capitalize',
                                'avoid_term' => strtolower($term),
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
                    
                    // Import common phrases as SEO-friendly rules
                    if (!empty($suggestions['common_phrases'])) {
                        $phrase_count = 0;
                        foreach (array_slice($suggestions['common_phrases'], 0, 10) as $phrase) {
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
                                $phrase_count++;
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
            if ($guidelines_engine) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'mindfulseo_guidelines';
                $deleted = $wpdb->query("DELETE FROM {$table_name}");
                
                if ($deleted !== false) {
                    add_settings_error(
                        'mindfulseo_guidelines',
                        'delete_all_success',
                        sprintf(__('%d guidelines deleted successfully.', 'mindfulseo'), $deleted),
                        'success'
                    );
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
                    add_settings_error(
                        'mindfulseo_guidelines',
                        'delete_success',
                        __('Guideline deleted successfully.', 'mindfulseo'),
                        'success'
                    );
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
                    add_settings_error(
                        'mindfulseo_guidelines',
                        'delete_success',
                        __('Guideline rule deleted successfully.', 'mindfulseo'),
                        'success'
                    );
                }
            }
        }
        
        // Get rules and stats
        $rules = array();
        $stats = array();
        if ($guidelines_engine) {
            $rules = $guidelines_engine->get_all_rules(array('active_only' => false));
            $stats = $guidelines_engine->get_statistics();
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
                        <button type="submit" name="mindfulseo_autogenerate_guidelines" class="button button-primary">
                            <?php _e('🔍 Analyze Content & Generate Guidelines', 'mindfulseo'); ?>
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
                              placeholder="<?php esc_attr_e('Example: Focus on respectful Buddhist terminology. Always capitalize: Buddha, Dharma, Sangha, Rinpoche. Avoid casual language.', 'mindfulseo'); ?>"><?php echo esc_textarea($guideline_prompt); ?></textarea>
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
                
                <form method="post" enctype="multipart/form-data" style="margin-top: 20px;">
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
                                    <td><small><?php echo esc_html(substr($rule->guideline_source, 0, 20)); ?></small></td>
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
        $settings['openai_model'] = isset($_POST['openai_model']) ? sanitize_text_field($_POST['openai_model']) : 'gpt-4-turbo';
        $settings['claude_model'] = isset($_POST['claude_model']) ? sanitize_text_field($_POST['claude_model']) : 'claude-3-5-sonnet-20241022';
        $settings['primary_provider'] = isset($_POST['primary_provider']) ? sanitize_text_field($_POST['primary_provider']) : 'claude';
        $settings['enable_fallback'] = isset($_POST['enable_fallback']) ? true : false;
        $settings['require_approval'] = isset($_POST['require_approval']) ? true : false;
        $settings['batch_size'] = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : 10;
        $settings['dataforseo_location'] = isset($_POST['dataforseo_location']) ? sanitize_text_field($_POST['dataforseo_location']) : '2840';
        $settings['dataforseo_language'] = isset($_POST['dataforseo_language']) ? sanitize_text_field($_POST['dataforseo_language']) : 'en';
        $settings['auto_refresh_keywords'] = isset($_POST['auto_refresh_keywords']) ? true : false;
        
        // Save settings
        MindfulSEO::update_settings($settings);
        
        // Redirect back with success message
        wp_redirect(add_query_arg(array(
            'page' => 'mindfulseo-settings',
            'updated' => 'true',
        ), admin_url('admin.php')));
        exit;
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

