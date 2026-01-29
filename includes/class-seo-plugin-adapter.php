<?php
/**
 * SEO Plugin Adapter
 * 
 * Provides unified interface for RankMath and Yoast SEO plugins
 * 
 * @package MindfulSEO
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_SEO_Plugin_Adapter {
    
    /**
     * The single instance of the class
     * 
     * @var MFSEO_SEO_Plugin_Adapter
     */
    private static $instance = null;
    
    /**
     * Active SEO plugin
     * 
     * @var string 'rankmath', 'yoast', or null
     */
    private $active_plugin = null;
    
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
        $this->detect_seo_plugin();
    }
    
    /**
     * Detect which SEO plugin is active
     * 
     * @return string|null Plugin name or null
     */
    private function detect_seo_plugin() {
        // Check for RankMath
        if (class_exists('RankMath')) {
            $this->active_plugin = 'rankmath';
            return 'rankmath';
        }
        
        // Check for Yoast SEO
        if (defined('WPSEO_VERSION') || class_exists('WPSEO_Options')) {
            $this->active_plugin = 'yoast';
            return 'yoast';
        }
        
        return null;
    }
    
    /**
     * Check if any SEO plugin is active
     * 
     * @return bool
     */
    public function is_seo_plugin_active() {
        return !is_null($this->active_plugin);
    }
    
    /**
     * Get active SEO plugin name
     * 
     * @return string|null
     */
    public function get_active_plugin() {
        return $this->active_plugin;
    }
    
    /**
     * Set focus keyword for a post
     * 
     * @param int $post_id Post ID
     * @param string $keyword Focus keyword
     * @return bool Success
     */
    public function set_focus_keyword($post_id, $keyword) {
        if (!$this->is_seo_plugin_active()) {
            return false;
        }
        
        $keyword = sanitize_text_field($keyword);
        
        switch ($this->active_plugin) {
            case 'rankmath':
                return update_post_meta($post_id, 'rank_math_focus_keyword', $keyword);
                
            case 'yoast':
                return update_post_meta($post_id, '_yoast_wpseo_focuskw', $keyword);
                
            default:
                return false;
        }
    }
    
    /**
     * Set SEO title for a post
     * 
     * @param int $post_id Post ID
     * @param string $title SEO title
     * @return bool Success
     */
    public function set_seo_title($post_id, $title) {
        if (!$this->is_seo_plugin_active()) {
            return false;
        }
        
        $title = sanitize_text_field($title);
        
        switch ($this->active_plugin) {
            case 'rankmath':
                return update_post_meta($post_id, 'rank_math_title', $title);
                
            case 'yoast':
                return update_post_meta($post_id, '_yoast_wpseo_title', $title);
                
            default:
                return false;
        }
    }
    
    /**
     * Set meta description for a post
     * 
     * @param int $post_id Post ID
     * @param string $description Meta description
     * @return bool Success
     */
    public function set_meta_description($post_id, $description) {
        if (!$this->is_seo_plugin_active()) {
            return false;
        }
        
        $description = sanitize_textarea_field($description);
        
        switch ($this->active_plugin) {
            case 'rankmath':
                return update_post_meta($post_id, 'rank_math_description', $description);
                
            case 'yoast':
                return update_post_meta($post_id, '_yoast_wpseo_metadesc', $description);
                
            default:
                return false;
        }
    }
    
    /**
     * Get current focus keyword for a post
     * 
     * @param int $post_id Post ID
     * @return string|null Current keyword
     */
    public function get_focus_keyword($post_id) {
        if (!$this->is_seo_plugin_active()) {
            return null;
        }
        
        switch ($this->active_plugin) {
            case 'rankmath':
                return get_post_meta($post_id, 'rank_math_focus_keyword', true);
                
            case 'yoast':
                return get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
                
            default:
                return null;
        }
    }
    
    /**
     * Get current SEO title for a post
     * 
     * @param int $post_id Post ID
     * @return string|null Current title
     */
    public function get_seo_title($post_id) {
        if (!$this->is_seo_plugin_active()) {
            return null;
        }
        
        switch ($this->active_plugin) {
            case 'rankmath':
                return get_post_meta($post_id, 'rank_math_title', true);
                
            case 'yoast':
                return get_post_meta($post_id, '_yoast_wpseo_title', true);
                
            default:
                return null;
        }
    }
    
    /**
     * Get current meta description for a post
     * 
     * @param int $post_id Post ID
     * @return string|null Current description
     */
    public function get_meta_description($post_id) {
        if (!$this->is_seo_plugin_active()) {
            return null;
        }
        
        switch ($this->active_plugin) {
            case 'rankmath':
                return get_post_meta($post_id, 'rank_math_description', true);
                
            case 'yoast':
                return get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
                
            default:
                return null;
        }
    }
    
    /**
     * Get SEO score for a post (if available)
     *
     * Currently supported for Rank Math SEO.
     *
     * @param int $post_id Post ID
     * @return int|null SEO score or null if unavailable
     */
    public function get_seo_score($post_id) {
        if (!$this->is_seo_plugin_active()) {
            return null;
        }

        switch ($this->active_plugin) {
            case 'rankmath':
                $score = get_post_meta($post_id, 'rank_math_seo_score', true);
                if ($score === '' || $score === null) {
                    return null;
                }

                return intval($score);

            case 'yoast':
            default:
                return null;
        }
    }
    
    /**
     * Set all SEO meta fields at once
     * 
     * @param int $post_id Post ID
     * @param array $seo_data SEO data array
     *                        - keyword (string)
     *                        - title (string)
     *                        - description (string)
     * @return array Results for each field
     */
    public function set_all_seo_meta($post_id, $seo_data) {
        $results = array(
            'keyword' => false,
            'title' => false,
            'description' => false,
        );
        
        if (isset($seo_data['keyword'])) {
            $results['keyword'] = $this->set_focus_keyword($post_id, $seo_data['keyword']);
        }
        
        if (isset($seo_data['title'])) {
            $results['title'] = $this->set_seo_title($post_id, $seo_data['title']);
        }
        
        if (isset($seo_data['description'])) {
            $results['description'] = $this->set_meta_description($post_id, $seo_data['description']);
        }
        
        return $results;
    }
    
    /**
     * Get all SEO meta fields at once
     * 
     * @param int $post_id Post ID
     * @return array SEO data
     */
    public function get_all_seo_meta($post_id) {
        return array(
            'keyword' => $this->get_focus_keyword($post_id),
            'title' => $this->get_seo_title($post_id),
            'description' => $this->get_meta_description($post_id),
            'plugin' => $this->active_plugin,
        );
    }
    
    /**
     * Check if post has SEO meta data
     * 
     * @param int $post_id Post ID
     * @return bool True if has any SEO meta
     */
    public function has_seo_meta($post_id) {
        $meta = $this->get_all_seo_meta($post_id);
        return !empty($meta['keyword']) || !empty($meta['title']) || !empty($meta['description']);
    }
    
    /**
     * Get compatibility info
     * 
     * @return array Compatibility information
     */
    public function get_compatibility_info() {
        return array(
            'is_active' => $this->is_seo_plugin_active(),
            'plugin' => $this->active_plugin,
            'plugin_name' => $this->get_plugin_display_name(),
            'can_set_keyword' => $this->is_seo_plugin_active(),
            'can_set_title' => $this->is_seo_plugin_active(),
            'can_set_description' => $this->is_seo_plugin_active(),
        );
    }
    
    /**
     * Get display name for active plugin
     * 
     * @return string Display name
     */
    private function get_plugin_display_name() {
        switch ($this->active_plugin) {
            case 'rankmath':
                return 'Rank Math SEO';
            case 'yoast':
                return 'Yoast SEO';
            default:
                return __('None', 'mindfulseo');
        }
    }
    
    /**
     * Get meta key map for current plugin
     * 
     * @return array Meta key mappings
     */
    public function get_meta_key_map() {
        $maps = array(
            'rankmath' => array(
                'keyword' => 'rank_math_focus_keyword',
                'title' => 'rank_math_title',
                'description' => 'rank_math_description',
            ),
            'yoast' => array(
                'keyword' => '_yoast_wpseo_focuskw',
                'title' => '_yoast_wpseo_title',
                'description' => '_yoast_wpseo_metadesc',
            ),
        );
        
        return isset($maps[$this->active_plugin]) ? $maps[$this->active_plugin] : array();
    }
}

