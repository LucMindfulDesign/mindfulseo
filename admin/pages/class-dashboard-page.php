<?php
/**
 * Dashboard Page (v2.0)
 * 
 * @package MindfulSEO
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_Dashboard_Page {
    
    /**
     * Render the dashboard page
     */
    public function render() {
        if (class_exists('MFSEO_Admin_Page')) {
            MFSEO_Admin_Page::get_instance()->render_dashboard_page();
            return;
        }
        echo '<div class="wrap"><div class="notice notice-error"><p><strong>' . esc_html__('Dashboard is unavailable.', 'mindfulseo') . '</strong> ' . esc_html__('Please ensure MindfulSEO is fully installed.', 'mindfulseo') . '</p></div></div>';
    }
}
