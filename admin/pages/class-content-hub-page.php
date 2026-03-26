<?php
/**
 * Content Hub Page (v2.0)
 * 
 * @package MindfulSEO
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_Content_Hub_Page {
    
    /**
     * Render the content hub page
     */
    public function render() {
        if (!class_exists('MFSEO_Admin_Page')) {
            echo '<div class="wrap"><div class="notice notice-error"><p><strong>' . esc_html__('Content Hub is unavailable.', 'mindfulseo') . '</strong> ' . esc_html__('Please reinstall or update MindfulSEO.', 'mindfulseo') . '</p></div></div>';
            return;
        }
        $admin_page = MFSEO_Admin_Page::get_instance();
        if (!method_exists($admin_page, 'render_content_hub_page')) {
            echo '<div class="wrap"><div class="notice notice-error"><p><strong>' . esc_html__('Content Hub is unavailable.', 'mindfulseo') . '</strong> ' . esc_html__('Please reinstall or update MindfulSEO.', 'mindfulseo') . '</p></div></div>';
            return;
        }
        $admin_page->render_content_hub_page();
    }
}
