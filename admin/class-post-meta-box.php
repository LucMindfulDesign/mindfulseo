<?php
/**
 * Post Meta Box
 * 
 * Adds MindfulSEO optimization meta box to post edit screen
 * 
 * @package MindfulSEO
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_Post_Meta_Box {
    
    /**
     * The single instance of the class
     * 
     * @var MFSEO_Post_Meta_Box
     */
    private static $instance = null;
    
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
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_mindfulseo_optimize_post', array($this, 'ajax_optimize_post'));
        add_action('wp_ajax_mindfulseo_get_preview', array($this, 'ajax_get_preview'));
        add_action('wp_ajax_mindfulseo_apply_optimization', array($this, 'ajax_apply_optimization'));
        add_action('wp_ajax_mindfulseo_reject_optimization', array($this, 'ajax_reject_optimization'));
    }
    
    /**
     * Add meta box to post edit screen
     */
    public function add_meta_box() {
        $post_types = array('post', 'page');
        
        // Add custom post types if they exist
        if (post_type_exists('product')) {
            $post_types[] = 'product'; // WooCommerce
        }
        if (post_type_exists('tribe_events')) {
            $post_types[] = 'tribe_events'; // The Events Calendar
        }
        if (post_type_exists('sfwd-courses')) {
            $post_types[] = 'sfwd-courses'; // LearnDash
        }
        
        foreach ($post_types as $post_type) {
            add_meta_box(
                'mindfulseo_optimization',
                __('🤖 MindfulSEO AI Optimization', 'mindfulseo'),
                array($this, 'render_meta_box'),
                $post_type,
                'side',
                'high'
            );
        }
    }
    
    /**
     * Render meta box content
     */
    public function render_meta_box($post) {
        // Check if post is saved (not a draft)
        if ($post->post_status === 'auto-draft') {
            echo '<p>' . __('Please save your post first before optimizing.', 'mindfulseo') . '</p>';
            return;
        }
        
        // Get optimization status
        $optimizer = MFSEO_Optimizer::get_instance();
        $optimization = $optimizer->get_optimization_status($post->ID);
        
        wp_nonce_field('mindfulseo_optimize', 'mindfulseo_optimize_nonce');
        
        ?>
        <div id="mindfulseo-meta-box">
            <?php if ($optimization && $optimization['status'] === 'pending'): ?>
                <!-- Pending optimization -->
                <div class="mindfulseo-status mindfulseo-status-pending">
                    <p><strong>⏳ <?php _e('Optimization Ready for Review', 'mindfulseo'); ?></strong></p>
                    <button type="button" class="button button-primary button-large" id="mindfulseo-preview-btn" style="width: 100%; margin-bottom: 8px;">
                        <?php _e('Preview Changes', 'mindfulseo'); ?>
                    </button>
                    <button type="button" class="button button-secondary" id="mindfulseo-regenerate-btn" style="width: 100%;">
                        <?php _e('Regenerate', 'mindfulseo'); ?>
                    </button>
                </div>
            <?php elseif ($optimization && $optimization['status'] === 'approved'): ?>
                <!-- Applied optimization -->
                <div class="mindfulseo-status mindfulseo-status-approved">
                    <p><strong>✅ <?php _e('Optimized', 'mindfulseo'); ?></strong></p>
                    <p style="font-size: 12px; color: #646970;">
                        <?php 
                        printf(
                            __('Last optimized: %s', 'mindfulseo'), 
                            human_time_diff(strtotime($optimization['optimization_date']), current_time('timestamp')) . ' ago'
                        ); 
                        ?>
                    </p>
                    <button type="button" class="button button-secondary button-large" id="mindfulseo-optimize-btn" style="width: 100%;">
                        <?php _e('Re-Optimize', 'mindfulseo'); ?>
                    </button>
                </div>
            <?php else: ?>
                <!-- No optimization yet -->
                <div class="mindfulseo-status mindfulseo-status-none">
                    <p style="font-size: 13px; color: #646970; margin-bottom: 12px;">
                        <?php _e('Use AI to optimize your SEO title, meta description, and content for better rankings.', 'mindfulseo'); ?>
                    </p>
                    <button type="button" class="button button-primary button-large" id="mindfulseo-optimize-btn" style="width: 100%;">
                        <span class="dashicons dashicons-superhero" style="vertical-align: middle;"></span>
                        <?php _e('Optimize with AI', 'mindfulseo'); ?>
                    </button>
                </div>
            <?php endif; ?>
            
            <!-- Loading state (hidden by default) -->
            <div id="mindfulseo-loading" style="display: none; text-align: center; padding: 20px;">
                <span class="spinner is-active" style="float: none; margin: 0 auto;"></span>
                <p style="margin-top: 10px; font-size: 12px; color: #646970;">
                    <?php _e('Analyzing content and optimizing with AI...', 'mindfulseo'); ?>
                </p>
            </div>
        </div>
        
        <!-- Preview Modal (will be populated by JS) -->
        <div id="mindfulseo-preview-modal" style="display: none;">
            <div class="mindfulseo-modal-overlay"></div>
            <div class="mindfulseo-modal-content">
                <div class="mindfulseo-modal-header">
                    <h2><?php _e('SEO Optimization Preview', 'mindfulseo'); ?></h2>
                    <button type="button" class="mindfulseo-modal-close">&times;</button>
                </div>
                <div class="mindfulseo-modal-body" id="mindfulseo-preview-content">
                    <!-- Content loaded via AJAX -->
                </div>
                <div class="mindfulseo-modal-footer">
                    <button type="button" class="button button-primary button-large" id="mindfulseo-apply-btn">
                        <?php _e('Apply Changes', 'mindfulseo'); ?>
                    </button>
                    <button type="button" class="button button-secondary button-large" id="mindfulseo-reject-btn">
                        <?php _e('Reject', 'mindfulseo'); ?>
                    </button>
                    <button type="button" class="button button-link" id="mindfulseo-cancel-btn">
                        <?php _e('Cancel', 'mindfulseo'); ?>
                    </button>
                </div>
            </div>
        </div>
        
        <style>
        .mindfulseo-status {
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 12px;
        }
        .mindfulseo-status-pending {
            background: #f0f6fc;
            border-left: 4px solid #2271b1;
        }
        .mindfulseo-status-approved {
            background: #f0f6fc;
            border-left: 4px solid #46b450;
        }
        .mindfulseo-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 999998;
        }
        .mindfulseo-modal-content {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            z-index: 999999;
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }
        .mindfulseo-modal-header {
            padding: 20px;
            border-bottom: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .mindfulseo-modal-header h2 {
            margin: 0;
        }
        .mindfulseo-modal-close {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #646970;
            line-height: 1;
            padding: 0;
        }
        .mindfulseo-modal-body {
            padding: 20px;
            overflow-y: auto;
            flex: 1;
        }
        .mindfulseo-modal-footer {
            padding: 20px;
            border-top: 1px solid #ddd;
            text-align: right;
        }
        .mindfulseo-modal-footer .button {
            margin-left: 8px;
        }
        .mindfulseo-comparison {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .mindfulseo-before, .mindfulseo-after {
            padding: 15px;
            border-radius: 4px;
        }
        .mindfulseo-before {
            background: #f6f7f7;
        }
        .mindfulseo-after {
            background: #f0f6fc;
            border-left: 3px solid #2271b1;
        }
        .mindfulseo-before h4, .mindfulseo-after h4 {
            margin-top: 0;
            font-size: 13px;
            text-transform: uppercase;
            color: #646970;
        }
        .mindfulseo-suggestions {
            margin-top: 20px;
            padding: 15px;
            background: #f6f7f7;
            border-radius: 4px;
        }
        .mindfulseo-suggestions h4 {
            margin-top: 0;
        }
        .mindfulseo-suggestions ul {
            margin: 10px 0;
            padding-left: 20px;
        }
        .mindfulseo-suggestions li {
            margin-bottom: 8px;
            line-height: 1.5;
        }
        </style>
        <?php
    }
    
    /**
     * Enqueue scripts
     */
    public function enqueue_scripts($hook) {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }
        
        wp_enqueue_script(
            'mindfulseo-post-meta-box',
            MINDFULSEO_PLUGIN_URL . 'admin/js/post-meta-box.js',
            array('jquery'),
            MINDFULSEO_VERSION,
            true
        );
        
        wp_localize_script('mindfulseo-post-meta-box', 'mindfulseoMetaBox', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mindfulseo_optimize'),
            'postId' => get_the_ID(),
        ));
    }
    
    /**
     * AJAX: Optimize post
     */
    public function ajax_optimize_post() {
        check_ajax_referer('mindfulseo_optimize', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Insufficient permissions.', 'mindfulseo'));
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        
        if (!$post_id) {
            wp_send_json_error(__('Invalid post ID.', 'mindfulseo'));
        }
        
        $optimizer = MFSEO_Optimizer::get_instance();
        $result = $optimizer->optimize_post($post_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Get preview data
     */
    public function ajax_get_preview() {
        check_ajax_referer('mindfulseo_optimize', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Insufficient permissions.', 'mindfulseo'));
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        
        if (!$post_id) {
            wp_send_json_error(__('Invalid post ID.', 'mindfulseo'));
        }
        
        $optimizer = MFSEO_Optimizer::get_instance();
        
        // Get the latest optimization for this post
        $optimization = $optimizer->get_optimization_status($post_id);
        
        if (!$optimization) {
            wp_send_json_error(__('No optimization found for this post.', 'mindfulseo'));
        }
        
        // Get preview data using the optimization ID
        $result = $optimizer->preview_optimization($optimization['id']);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Apply optimization
     */
    public function ajax_apply_optimization() {
        check_ajax_referer('mindfulseo_optimize', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Insufficient permissions.', 'mindfulseo'));
        }
        
        $optimization_id = isset($_POST['optimization_id']) ? intval($_POST['optimization_id']) : 0;
        
        if (!$optimization_id) {
            wp_send_json_error(__('Invalid optimization ID.', 'mindfulseo'));
        }
        
        $optimizer = MFSEO_Optimizer::get_instance();
        $result = $optimizer->apply_optimization($optimization_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success(array(
            'message' => __('Optimization applied successfully!', 'mindfulseo'),
        ));
    }
    
    /**
     * AJAX: Reject optimization
     */
    public function ajax_reject_optimization() {
        check_ajax_referer('mindfulseo_optimize', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Insufficient permissions.', 'mindfulseo'));
        }
        
        $optimization_id = isset($_POST['optimization_id']) ? intval($_POST['optimization_id']) : 0;
        
        if (!$optimization_id) {
            wp_send_json_error(__('Invalid optimization ID.', 'mindfulseo'));
        }
        
        $optimizer = MFSEO_Optimizer::get_instance();
        $result = $optimizer->reject_optimization($optimization_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success(array(
            'message' => __('Optimization rejected.', 'mindfulseo'),
        ));
    }
}

// Initialize
MFSEO_Post_Meta_Box::get_instance();

