<?php
/**
 * Plugin Name: MindfulSEO
 * Plugin URI: https://mindfuldesign.me
 * Description: AI-powered SEO optimization and blog content generation with brand-aware guidelines. Works with RankMath and Yoast SEO.
 * Version: 2.2.2
 * Author: Mindful Design
 * Author URI: https://mindfuldesign.me
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mindfulseo
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants with PHP 8.x null safety
define('MINDFULSEO_VERSION', '2.2.4');

// Ensure path functions return valid strings (PHP 8.x compatibility)
$_mfseo_plugin_dir = plugin_dir_path(__FILE__);
$_mfseo_plugin_url = plugin_dir_url(__FILE__);
$_mfseo_plugin_file = __FILE__;
$_mfseo_plugin_basename = plugin_basename(__FILE__);

define('MINDFULSEO_PLUGIN_DIR', is_string($_mfseo_plugin_dir) && !empty($_mfseo_plugin_dir) ? $_mfseo_plugin_dir : dirname(__FILE__) . '/');
define('MINDFULSEO_PLUGIN_URL', is_string($_mfseo_plugin_url) && !empty($_mfseo_plugin_url) ? $_mfseo_plugin_url : plugins_url('/', __FILE__));
define('MINDFULSEO_PLUGIN_FILE', is_string($_mfseo_plugin_file) && !empty($_mfseo_plugin_file) ? $_mfseo_plugin_file : __FILE__);
define('MINDFULSEO_PLUGIN_BASENAME', is_string($_mfseo_plugin_basename) && !empty($_mfseo_plugin_basename) ? $_mfseo_plugin_basename : basename(dirname(__FILE__)) . '/' . basename(__FILE__));

// Clean up temporary variables
unset($_mfseo_plugin_dir, $_mfseo_plugin_url, $_mfseo_plugin_file, $_mfseo_plugin_basename);

/**
 * Main MindfulSEO Class
 * 
 * @since 1.0.0
 */
final class MindfulSEO {
    
    /**
     * The single instance of the class
     * 
     * @var MindfulSEO
     */
    private static $instance = null;
    
    /**
     * Main MindfulSEO Instance
     * 
     * Ensures only one instance of MindfulSEO is loaded or can be loaded.
     * 
     * @return MindfulSEO - Main instance
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
        $this->define_constants();
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    /**
     * Define additional plugin constants
     */
    private function define_constants() {
        // Upload directories - ensure values are not null (PHP 8.x compatibility)
        $upload_dir = wp_upload_dir();
        $basedir = isset($upload_dir['basedir']) && is_string($upload_dir['basedir']) ? $upload_dir['basedir'] : WP_CONTENT_DIR . '/uploads';
        $baseurl = isset($upload_dir['baseurl']) && is_string($upload_dir['baseurl']) ? $upload_dir['baseurl'] : content_url('uploads');
        
        define('MINDFULSEO_UPLOAD_DIR', $basedir . '/mindfulseo/');
        define('MINDFULSEO_UPLOAD_URL', $baseurl . '/mindfulseo/');
        
        // Sub-directories
        define('MINDFULSEO_GUIDELINES_DIR', MINDFULSEO_UPLOAD_DIR . 'guidelines/');
        define('MINDFULSEO_KEYWORDS_DIR', MINDFULSEO_UPLOAD_DIR . 'keywords/');
        define('MINDFULSEO_LOGS_DIR', MINDFULSEO_UPLOAD_DIR . 'logs/');
    }
    
    /**
     * Load required dependencies
     */
    private function load_dependencies() {
        // Helper function to safely require files (PHP 8.x compatible)
        $require_if_exists = function($file) {
            // Ensure $file is a valid string path before file_exists (prevents null deprecation)
            if (!is_string($file) || empty($file)) {
                return false;
            }
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
            return false;
        };
        
        // Core classes - load only if they exist
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'includes/class-logger.php');
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'includes/class-seo-plugin-adapter.php');
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'includes/class-openai-provider.php');
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'includes/class-claude-provider.php');
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'includes/class-ai-connector.php');
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'includes/class-dataforseo-connector.php');
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'includes/class-keyword-manager.php');
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'includes/class-guidelines-engine.php');
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'includes/class-content-analyzer.php');
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'includes/class-api-tester.php');
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'includes/class-ajax-handlers.php');
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'includes/class-optimizer.php');
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'includes/class-blog-writer.php');
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'includes/class-content-researcher.php');
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'includes/class-csv-importer.php');
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'includes/class-content-cluster-engine.php');
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'includes/class-gap-analyzer.php');
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'includes/class-internal-linker.php');
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'includes/class-cache-manager.php');
        
        // Admin classes - always load them, WordPress will handle is_admin() internally
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'admin/class-admin.php');
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'admin/class-admin-page.php');
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'admin/class-batch-optimizer-page.php');
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'admin/class-post-meta-box.php');
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'admin/class-batch-processor.php');
        $require_if_exists(MINDFULSEO_PLUGIN_DIR . 'admin/class-setup-wizard.php');
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Activation/Deactivation hooks
        register_activation_hook(MINDFULSEO_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(MINDFULSEO_PLUGIN_FILE, array($this, 'deactivate'));
        
        // Initialization
        add_action('plugins_loaded', array($this, 'init'));
        
        // Admin hooks - Initialize admin page early so it can register its menu
        if (is_admin()) {
            add_action('init', array($this, 'init_admin'));
            add_action('admin_init', array($this, 'admin_init'));
        }
        
        // Load text domain for translations
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        
        // Cron hooks
        add_action('mindfulseo_monthly_refresh_seo_data', array($this, 'cron_refresh_seo_data'));
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables
        $this->create_database_tables();
        
        // Create upload directories
        $this->create_upload_directories();
        
        // Set default settings
        $this->set_default_settings();
        
        // Schedule monthly cron job for SEO data refresh (if auto-refresh is enabled)
        $this->schedule_seo_data_refresh();
        
        // Show welcome notice
        set_transient('mindfulseo_activation_notice', true, 60);
        
        // Set wizard needed flag if not already completed
        if (!get_option('mindfulseo_wizard_completed')) {
            update_option('mindfulseo_wizard_needed', true);
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled cron events
        $timestamp = wp_next_scheduled('mindfulseo_monthly_refresh_seo_data');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'mindfulseo_monthly_refresh_seo_data');
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Create database tables
     */
    private function create_database_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Table 1: Optimizations
        $table_optimizations = $wpdb->prefix . 'mindfulseo_optimizations';
        $sql_optimizations = "CREATE TABLE IF NOT EXISTS $table_optimizations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            post_id BIGINT UNSIGNED NOT NULL,
            optimization_date DATETIME NOT NULL,
            ai_provider VARCHAR(20) NOT NULL,
            primary_keyword VARCHAR(255),
            longtail_keywords TEXT,
            seo_title VARCHAR(255),
            meta_description TEXT,
            content_suggestions TEXT,
            optimization_score INT,
            status VARCHAR(20) DEFAULT 'pending',
            created_by BIGINT UNSIGNED,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX post_id_idx (post_id),
            INDEX status_idx (status),
            INDEX optimization_date_idx (optimization_date)
        ) $charset_collate;";
        
        // Table 2: Keywords
        $table_keywords = $wpdb->prefix . 'mindfulseo_keywords';
        $sql_keywords = "CREATE TABLE IF NOT EXISTS $table_keywords (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            primary_keyword VARCHAR(255) NOT NULL,
            longtail_keyword VARCHAR(255) NOT NULL,
            search_intent VARCHAR(50),
            priority VARCHAR(10),
            current_sessions INT DEFAULT 0,
            search_volume INT DEFAULT NULL,
            keyword_difficulty INT DEFAULT NULL,
            cpc DECIMAL(10,2) DEFAULT NULL,
            seo_data_updated DATETIME DEFAULT NULL,
            dataforseo_status VARCHAR(20) DEFAULT 'pending',
            current_rank INT DEFAULT NULL,
            ranking_url TEXT DEFAULT NULL,
            notes TEXT,
            csv_source VARCHAR(255),
            created_date DATETIME NOT NULL,
            INDEX primary_keyword_idx (primary_keyword),
            INDEX priority_idx (priority),
            INDEX search_intent_idx (search_intent),
            INDEX search_volume_idx (search_volume),
            INDEX keyword_difficulty_idx (keyword_difficulty),
            INDEX dataforseo_status_idx (dataforseo_status),
            INDEX current_rank_idx (current_rank)
        ) $charset_collate;";
        
        // Table 3: Guidelines
        $table_guidelines = $wpdb->prefix . 'mindfulseo_guidelines';
        $sql_guidelines = "CREATE TABLE IF NOT EXISTS $table_guidelines (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rule_type VARCHAR(50),
            avoid_term VARCHAR(255),
            preferred_term VARCHAR(255),
            context TEXT,
            guideline_source VARCHAR(255),
            active BOOLEAN DEFAULT TRUE,
            created_date DATETIME NOT NULL,
            INDEX rule_type_idx (rule_type),
            INDEX avoid_term_idx (avoid_term),
            INDEX active_idx (active)
        ) $charset_collate;";
        
        // Table 4: Activity Logs
        $table_logs = $wpdb->prefix . 'mindfulseo_logs';
        $sql_logs = "CREATE TABLE IF NOT EXISTS $table_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            log_type VARCHAR(50) NOT NULL,
            post_id BIGINT UNSIGNED,
            ai_provider VARCHAR(20),
            prompt_tokens INT,
            completion_tokens INT,
            cost DECIMAL(10,4),
            message TEXT,
            user_id BIGINT UNSIGNED,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX log_type_idx (log_type),
            INDEX post_id_idx (post_id),
            INDEX created_at_idx (created_at)
        ) $charset_collate;";
        
        // Execute table creation
        dbDelta($sql_optimizations);
        dbDelta($sql_keywords);
        dbDelta($sql_guidelines);
        dbDelta($sql_logs);
        
        // Store database version
        update_option('mindfulseo_db_version', '1.2.0');
    }
    
    /**
     * Check and upgrade database if needed
     */
    private function check_database_upgrade() {
        $current_version = get_option('mindfulseo_db_version', '1.0.0');
        
        if (version_compare($current_version, '1.1.0', '<')) {
            $this->upgrade_database_to_1_1_0();
        }
        
        if (version_compare($current_version, '1.2.0', '<')) {
            $this->upgrade_database_to_1_2_0();
        }
    }
    
    /**
     * Upgrade database to version 1.1.0 - Add SEO metrics columns
     */
    private function upgrade_database_to_1_1_0() {
        global $wpdb;
        $table_keywords = $wpdb->prefix . 'mindfulseo_keywords';
        
        // Check if columns already exist
        $columns = $wpdb->get_col("DESC {$table_keywords}", 0);
        
        if (!in_array('search_volume', $columns)) {
            $wpdb->query("ALTER TABLE {$table_keywords} ADD COLUMN search_volume INT DEFAULT NULL AFTER current_sessions");
            $wpdb->query("ALTER TABLE {$table_keywords} ADD INDEX search_volume_idx (search_volume)");
        }
        
        if (!in_array('keyword_difficulty', $columns)) {
            $wpdb->query("ALTER TABLE {$table_keywords} ADD COLUMN keyword_difficulty INT DEFAULT NULL AFTER search_volume");
            $wpdb->query("ALTER TABLE {$table_keywords} ADD INDEX keyword_difficulty_idx (keyword_difficulty)");
        }
        
        if (!in_array('cpc', $columns)) {
            $wpdb->query("ALTER TABLE {$table_keywords} ADD COLUMN cpc DECIMAL(10,2) DEFAULT NULL AFTER keyword_difficulty");
        }
        
        if (!in_array('seo_data_updated', $columns)) {
            $wpdb->query("ALTER TABLE {$table_keywords} ADD COLUMN seo_data_updated DATETIME DEFAULT NULL AFTER cpc");
        }
        
        update_option('mindfulseo_db_version', '1.1.0');
    }
    
    /**
     * Upgrade database to version 1.2.0 - Add DataForSEO status and ranking columns
     */
    private function upgrade_database_to_1_2_0() {
        global $wpdb;
        $table_keywords = $wpdb->prefix . 'mindfulseo_keywords';
        
        // Check if columns already exist
        $columns = $wpdb->get_col("DESC {$table_keywords}", 0);
        
        if (!in_array('dataforseo_status', $columns)) {
            $wpdb->query("ALTER TABLE {$table_keywords} ADD COLUMN dataforseo_status VARCHAR(20) DEFAULT 'pending' AFTER seo_data_updated");
            $wpdb->query("ALTER TABLE {$table_keywords} ADD INDEX dataforseo_status_idx (dataforseo_status)");
        }
        
        if (!in_array('current_rank', $columns)) {
            $wpdb->query("ALTER TABLE {$table_keywords} ADD COLUMN current_rank INT DEFAULT NULL AFTER dataforseo_status");
            $wpdb->query("ALTER TABLE {$table_keywords} ADD INDEX current_rank_idx (current_rank)");
        }
        
        if (!in_array('ranking_url', $columns)) {
            $wpdb->query("ALTER TABLE {$table_keywords} ADD COLUMN ranking_url TEXT DEFAULT NULL AFTER current_rank");
        }
        
        update_option('mindfulseo_db_version', '1.2.0');
    }
    
    /**
     * Create upload directories
     */
    private function create_upload_directories() {
        $directories = array(
            MINDFULSEO_UPLOAD_DIR,
            MINDFULSEO_GUIDELINES_DIR,
            MINDFULSEO_KEYWORDS_DIR,
            MINDFULSEO_LOGS_DIR,
        );
        
        foreach ($directories as $dir) {
            // PHP 8.x: Ensure directory path is valid string before file_exists/wp_mkdir_p
            if (!is_string($dir) || empty($dir)) {
                continue;
            }
            if (!file_exists($dir)) {
                wp_mkdir_p($dir);
                
                // Create .htaccess for security
                $htaccess_content = "Options -Indexes\nDeny from all";
                @file_put_contents($dir . '.htaccess', $htaccess_content);
                
                // Create index.php for security
                @file_put_contents($dir . 'index.php', '<?php // Silence is golden');
            }
        }
    }
    
    /**
     * Set default plugin settings
     */
    private function set_default_settings() {
        $defaults = array(
            'primary_provider' => 'claude',
            'fallback_provider' => 'openai',
            'enable_fallback' => true,
            'require_approval' => true,
            'auto_optimize_new' => false,
            'title_length' => 60,
            'description_length' => 155,
            'keyword_density_min' => 1.0,
            'keyword_density_max' => 2.0,
            'batch_size' => 10,
            'rate_limit' => 60,
            'api_timeout' => 60,
            // OpenAI models (November 2025 - Latest)
            'openai_model' => 'gpt-5',                          // GPT-5 (Released Aug 2025, 272K context)
            'openai_model_alt' => 'gpt-4o',                     // GPT-4o (fallback)
            // Claude models (November 2025 - Latest)
            'claude_model' => 'claude-sonnet-4-5',              // Claude Sonnet 4.5 (Released Sep 2025)
            'claude_model_alt' => 'claude-3-5-sonnet-20240620', // Claude 3.5 Sonnet Jun 2024 (fallback)
            // Blog writer settings
            'blog_writer_default_length' => 1500,
            'blog_writer_default_tone' => 'educational',
            'include_faq' => true,
            'version' => MINDFULSEO_VERSION,
        );
        
        // Only set if not already set
        if (!get_option('mindfulseo_settings')) {
            add_option('mindfulseo_settings', $defaults);
        }
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Check and upgrade database if needed
        $this->check_database_upgrade();
        
        // Initialize core components only if classes exist
        if (class_exists('MFSEO_SEO_Plugin_Adapter')) {
            MFSEO_SEO_Plugin_Adapter::get_instance();
        }
        
        // Check for SEO plugin and show notice if none found
        $this->check_seo_plugin_dependency();
    }
    
    /**
     * Initialize admin
     */
    public function init_admin() {
        // Initialize new admin class (v2.0) for redesigned UI
        if (class_exists('MFSEO_Admin')) {
            MFSEO_Admin::get_instance();
        }
        
        // Initialize legacy admin page class for backward compatibility
        // This provides the render methods that new page classes delegate to
        if (class_exists('MFSEO_Admin_Page')) {
            MFSEO_Admin_Page::get_instance();
        }
        
        // Initialize Content Cluster Engine
        if (class_exists('MFSEO_Content_Cluster_Engine')) {
            MFSEO_Content_Cluster_Engine::get_instance();
        }
    }
    
    /**
     * Initialize admin-specific functionality
     */
    public function admin_init() {
        // Show activation notice
        if (get_transient('mindfulseo_activation_notice')) {
            add_action('admin_notices', array($this, 'activation_notice'));
            delete_transient('mindfulseo_activation_notice');
        }
    }
    
    /**
     * Check if SEO plugin is installed
     */
    private function check_seo_plugin_dependency() {
        if (!class_exists('MFSEO_SEO_Plugin_Adapter')) {
            return;
        }
        
        $adapter = MFSEO_SEO_Plugin_Adapter::get_instance();
        
        if (!$adapter->is_seo_plugin_active()) {
            add_action('admin_notices', array($this, 'seo_plugin_missing_notice'));
        }
    }
    
    /**
     * Load plugin text domain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'mindfulseo',
            false,
            dirname(MINDFULSEO_PLUGIN_BASENAME) . '/languages/'
        );
    }
    
    /**
     * Activation notice
     */
    public function activation_notice() {
        ?>
        <div class="notice notice-success is-dismissible">
            <h3><?php _e('🎉 Welcome to MindfulSEO!', 'mindfulseo'); ?></h3>
            <p><?php _e('Thank you for installing MindfulSEO - AI-powered content optimization for mindful organizations.', 'mindfulseo'); ?></p>
            <p>
                <a href="<?php echo admin_url('admin.php?page=mindfulseo'); ?>" class="button button-primary">
                    <?php _e('Get Started', 'mindfulseo'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=mindfulseo-settings'); ?>" class="button button-secondary">
                    <?php _e('Configure Settings', 'mindfulseo'); ?>
                </a>
            </p>
        </div>
        <?php
    }
    
    /**
     * SEO plugin missing notice
     */
    public function seo_plugin_missing_notice() {
        ?>
        <div class="notice notice-warning">
            <h3><?php _e('MindfulSEO: SEO Plugin Required', 'mindfulseo'); ?></h3>
            <p>
                <?php _e('MindfulSEO works best with RankMath or Yoast SEO. Please install one of these plugins for full functionality.', 'mindfulseo'); ?>
            </p>
            <p>
                <a href="<?php echo admin_url('plugin-install.php?s=rank+math&tab=search'); ?>" class="button">
                    <?php _e('Install RankMath', 'mindfulseo'); ?>
                </a>
                <a href="<?php echo admin_url('plugin-install.php?s=yoast+seo&tab=search'); ?>" class="button">
                    <?php _e('Install Yoast SEO', 'mindfulseo'); ?>
                </a>
            </p>
        </div>
        <?php
    }
    
    /**
     * Get plugin settings
     * 
     * @return array Plugin settings
     */
    public static function get_settings() {
        return get_option('mindfulseo_settings', array());
    }
    
    /**
     * Get specific setting value
     * 
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return mixed Setting value
     */
    public static function get_setting($key, $default = null) {
        $settings = self::get_settings();
        return isset($settings[$key]) ? $settings[$key] : $default;
    }
    
    /**
     * Update plugin settings
     * 
     * @param array $new_settings New settings to merge
     * @return bool Success
     */
    public static function update_settings($new_settings) {
        $current_settings = self::get_settings();
        $updated_settings = array_merge($current_settings, $new_settings);
        
        // If auto_refresh_keywords setting changed, update cron schedule
        $instance = self::get_instance();
        if (isset($new_settings['auto_refresh_keywords'])) {
            $instance->schedule_seo_data_refresh();
        }
        
        return update_option('mindfulseo_settings', $updated_settings);
    }
    
    /**
     * Schedule monthly SEO data refresh cron job
     */
    public function schedule_seo_data_refresh() {
        $settings = self::get_settings();
        
        // Clear any existing schedule first
        $timestamp = wp_next_scheduled('mindfulseo_monthly_refresh_seo_data');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'mindfulseo_monthly_refresh_seo_data');
        }
        
        // Schedule new event if auto-refresh is enabled
        if (!empty($settings['auto_refresh_keywords'])) {
            // Schedule for the first day of next month at 3 AM
            $next_month = strtotime('first day of next month 03:00:00');
            wp_schedule_event($next_month, 'monthly', 'mindfulseo_monthly_refresh_seo_data');
        }
    }
    
    /**
     * Cron callback to refresh SEO data
     * This runs automatically every month if enabled in settings
     */
    public function cron_refresh_seo_data() {
        // Check if DataForSEO is configured
        if (!class_exists('MFSEO_DataForSEO_Connector')) {
            error_log('MindfulSEO: Cron job failed - DataForSEO connector not available');
            return;
        }
        
        $connector = MFSEO_DataForSEO_Connector::get_instance();
        
        if (!$connector->is_configured()) {
            error_log('MindfulSEO: Cron job failed - DataForSEO API not configured');
            return;
        }
        
        // Get all keywords
        if (!class_exists('MFSEO_Keyword_Manager')) {
            error_log('MindfulSEO: Cron job failed - Keyword manager not available');
            return;
        }
        
        $keyword_manager = MFSEO_Keyword_Manager::get_instance();
        $keywords = $keyword_manager->get_keywords(array('limit' => 999999));
        
        if (empty($keywords)) {
            error_log('MindfulSEO: Cron job - No keywords to refresh');
            return;
        }
        
        // Extract keyword strings
        $keyword_strings = array();
        $keyword_map = array();
        foreach ($keywords as $keyword) {
            $keyword_strings[] = $keyword->primary_keyword;
            $keyword_map[$keyword->primary_keyword] = $keyword->id;
        }
        
        // Get settings
        $settings = self::get_settings();
        $location_code = isset($settings['dataforseo_location']) ? $settings['dataforseo_location'] : '2840';
        $language_code = isset($settings['dataforseo_language']) ? $settings['dataforseo_language'] : 'en';
        
        // Batch process
        $batch_size = 100;
        $total_updated = 0;
        $batches = array_chunk($keyword_strings, $batch_size);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'mindfulseo_keywords';
        
        foreach ($batches as $batch_index => $batch) {
            $metrics = $connector->get_combined_metrics($batch, $location_code, $language_code);
            
            if (is_wp_error($metrics)) {
                error_log('MindfulSEO: Cron job - DataForSEO API Error for batch ' . ($batch_index + 1) . ': ' . $metrics->get_error_message());
                continue;
            }
            
            foreach ($batch as $keyword_string) {
                if (!isset($metrics[$keyword_string]) || !isset($keyword_map[$keyword_string])) {
                    continue;
                }
                
                $keyword_id = $keyword_map[$keyword_string];
                $data = $metrics[$keyword_string];
                
                $update_result = $wpdb->update(
                    $table_name,
                    array(
                        'search_volume' => $data['search_volume'],
                        'keyword_difficulty' => $data['keyword_difficulty'],
                        'cpc' => $data['cpc'],
                        'seo_data_updated' => current_time('mysql'),
                    ),
                    array('id' => $keyword_id),
                    array('%d', '%d', '%f', '%s'),
                    array('%d')
                );
                
                if ($update_result !== false) {
                    $total_updated++;
                }
            }
            
            // Sleep between batches to avoid API rate limits
            sleep(2);
        }
        
        error_log('MindfulSEO: Cron job completed - Updated ' . $total_updated . ' out of ' . count($keyword_strings) . ' keywords');
    }
}

/**
 * Initialize the plugin
 */
function mindfulseo() {
    return MindfulSEO::get_instance();
}

// Start the plugin
mindfulseo();

