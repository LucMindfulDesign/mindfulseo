<?php
/**
 * Admin Interface Controller (v2.0)
 * 
 * Manages the redesigned MindfulSEO admin interface
 * 
 * @package MindfulSEO
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_Admin {
    
    /**
     * The single instance of the class
     */
    private static $instance = null;
    
    /**
     * Hook suffixes for conditional asset loading
     */
    private $hook_suffixes = array();
    
    /**
     * Page class instances
     */
    private $page_classes = array();
    
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
        $this->load_page_classes();
        
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // Remove WordPress admin footer on our pages
        add_filter('admin_footer_text', array($this, 'remove_admin_footer_text'), 999);
        add_filter('update_footer', '__return_empty_string', 999);
        
        // Initialize setup wizard to register AJAX handlers
        if (class_exists('MFSEO_Setup_Wizard')) {
            MFSEO_Setup_Wizard::get_instance();
        }
        // Setup wizard hooks
        add_action('admin_notices', array($this, 'maybe_show_wizard_notice'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_wizard_assets'));
        
        // Hide admin notices on Content Hub for cleaner UI
        add_action('admin_head', array($this, 'hide_content_hub_notices'));
    }
    
    /**
     * Remove admin footer text on MindfulSEO pages
     */
    public function remove_admin_footer_text($text) {
        $screen = get_current_screen();
        
        // Check if we're on a MindfulSEO admin page
        if ($screen && strpos($screen->id, 'mindfulseo') !== false) {
            return '';
        }
        
        return $text;
    }
    
    /**
     * Hide third-party admin notices on Content Hub for cleaner UI,
     * but keep our own wizard notice.
     */
    public function hide_content_hub_notices() {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'mindfulseo_page_mindfulseo-content-hub') {
            global $wp_filter;
            foreach (array('admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices') as $tag) {
                if (isset($wp_filter[$tag])) {
                    foreach ($wp_filter[$tag]->callbacks as $priority => $hooks) {
                        foreach ($hooks as $key => $hook) {
                            if (is_array($hook['function']) && is_object($hook['function'][0]) && $hook['function'][0] === $this && $hook['function'][1] === 'maybe_show_wizard_notice') {
                                continue;
                            }
                            unset($wp_filter[$tag]->callbacks[$priority][$key]);
                        }
                    }
                }
            }
        }
    }
    
    /**
     * Load page classes
     */
    private function load_page_classes() {
        $page_files = array(
            'dashboard' => MINDFULSEO_PLUGIN_DIR . 'admin/pages/class-dashboard-page.php',
            'content-hub' => MINDFULSEO_PLUGIN_DIR . 'admin/pages/class-content-hub-page.php',
            'keywords' => MINDFULSEO_PLUGIN_DIR . 'admin/pages/class-keywords-page.php',
        );
        
        foreach ($page_files as $key => $file) {
            if (file_exists($file)) {
                require_once $file;
            }
        }
    }
    
    /**
     * Register admin menu
     */
    public function register_menu() {
        // Main menu page (Dashboard)
        $icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 750 750" width="20" height="20"><path fill="currentColor" d="M 144.363281 307.726562 C 149.65625 307.726562 153.972656 312.042969 153.972656 317.339844 C 153.972656 322.632812 149.65625 326.949219 144.363281 326.949219 C 139.070312 326.949219 134.753906 322.632812 134.753906 317.339844 C 134.753906 312.042969 139.070312 307.726562 144.363281 307.726562 Z M 173.191406 182.800781 C 178.488281 182.800781 182.800781 187.117188 182.800781 192.410156 C 182.800781 197.707031 178.488281 202.019531 173.191406 202.019531 C 167.898438 202.019531 163.582031 197.707031 163.582031 192.410156 C 163.582031 187.117188 167.898438 182.800781 173.191406 182.800781 Z M 288.507812 105.921875 C 293.804688 105.921875 298.117188 110.242188 298.117188 115.535156 C 298.117188 120.828125 293.804688 125.144531 288.507812 125.144531 C 283.214844 125.144531 278.898438 120.828125 278.898438 115.535156 C 278.898438 110.242188 283.214844 105.921875 288.507812 105.921875 Z M 365.386719 153.972656 L 365.386719 213.398438 C 362.351562 212.34375 359.160156 211.628906 355.777344 211.628906 C 351.347656 211.628906 347.175781 212.714844 343.429688 214.523438 L 316.886719 187.980469 C 315.234375 177.429688 307.921875 168.839844 298.117188 165.351562 L 298.117188 142.597656 C 305.894531 139.820312 312.121094 133.925781 315.195312 126.308594 L 368.460938 133.925781 C 366.472656 140.269531 365.386719 146.988281 365.386719 153.972656 Z M 384.605469 153.972656 C 384.605469 127.46875 406.152344 105.921875 432.65625 105.921875 C 451.578125 105.921875 468.808594 117.109375 476.53125 134.453125 C 477.589844 136.816406 479.539062 138.617188 481.941406 139.519531 C 484.34375 140.421875 487.007812 140.347656 489.332031 139.257812 C 495.789062 136.253906 502.546875 134.753906 509.53125 134.753906 C 536.035156 134.753906 557.582031 156.300781 557.582031 182.800781 C 557.582031 187.980469 556.640625 193.085938 555.023438 197.964844 C 545.308594 194.4375 534.875 192.410156 523.949219 192.410156 C 473.613281 192.410156 432.65625 233.367188 432.65625 283.703125 L 451.875 283.703125 C 451.875 243.949219 484.191406 211.628906 523.949219 211.628906 C 535.429688 211.628906 546.28125 214.40625 555.929688 219.210938 C 561.714844 222.105469 566.933594 225.671875 571.621094 229.804688 C 572.21875 230.320312 572.824219 230.890625 573.417969 231.445312 C 575.109375 233.03125 576.722656 234.714844 578.261719 236.480469 C 578.980469 237.308594 579.695312 238.132812 580.367188 239 C 581.757812 240.757812 583.066406 242.527344 584.269531 244.398438 C 584.949219 245.457031 585.621094 246.542969 586.21875 247.628906 C 587.121094 249.128906 587.910156 250.664062 588.660156 252.210938 C 589.1875 253.261719 589.714844 254.242188 590.167969 255.289062 C 591.214844 257.765625 592.15625 260.3125 592.945312 262.910156 C 593.203125 263.8125 593.398438 264.707031 593.617188 265.570312 C 594.148438 267.675781 594.628906 269.78125 594.972656 271.914062 C 595.117188 272.816406 595.269531 273.757812 595.386719 274.660156 C 595.761719 277.621094 596.019531 280.628906 596.019531 283.703125 C 596.019531 291.132812 594.742188 298.453125 592.492188 305.480469 C 580.625 294.964844 565.046875 288.507812 547.972656 288.507812 L 547.972656 307.726562 C 564.453125 307.726562 579.011719 316.097656 587.6875 328.78125 C 587.910156 329.128906 588.0625 329.503906 588.285156 329.800781 C 589.601562 331.867188 590.765625 333.960938 591.746094 336.222656 C 592.117188 337.007812 592.417969 337.835938 592.714844 338.660156 C 593.464844 340.574219 594.070312 342.523438 594.558594 344.550781 C 594.742188 345.378906 594.972656 346.242188 595.15625 347.070312 C 595.683594 349.925781 596.019531 352.808594 596.019531 355.777344 C 596.019531 375.292969 584.34375 392.753906 566.25 400.183594 C 563.78125 401.230469 561.859375 403.230469 560.925781 405.738281 C 559.984375 408.292969 560.175781 411.070312 561.378906 413.472656 C 565.242188 420.980469 567.191406 429.089844 567.191406 437.460938 C 567.191406 447.703125 564.261719 457.429688 558.9375 465.796875 C 551.875 474.8125 541.0625 480.703125 528.753906 480.703125 C 510.773438 480.703125 495.753906 468.238281 491.589844 451.578125 C 523.199219 448.492188 547.972656 421.804688 547.972656 389.410156 L 528.753906 389.410156 C 528.753906 413.242188 509.339844 432.65625 485.507812 432.65625 C 461.675781 432.65625 442.265625 413.242188 442.265625 389.410156 L 423.046875 389.410156 C 423.046875 419.105469 443.878906 443.996094 471.726562 450.296875 C 475.640625 478.300781 499.703125 499.921875 528.753906 499.921875 C 534.605469 499.921875 540.234375 499.019531 545.570312 497.40625 C 547.144531 502.890625 547.972656 508.589844 547.972656 514.335938 C 547.972656 516.441406 547.855469 518.546875 547.675781 520.601562 C 547.597656 521.285156 547.441406 521.957031 547.335938 522.667969 C 547.183594 524.023438 546.992188 525.371094 546.730469 526.6875 C 546.578125 527.472656 546.355469 528.261719 546.136719 529.050781 C 545.867188 530.210938 545.609375 531.414062 545.234375 532.578125 C 545.003906 533.402344 544.667969 534.191406 544.367188 535.019531 C 543.992188 536.074219 543.65625 537.160156 543.207031 538.207031 C 542.867188 539.035156 542.457031 539.824219 542.082031 540.609375 C 541.628906 541.628906 541.175781 542.636719 540.648438 543.578125 C 540.234375 544.40625 539.753906 545.195312 539.265625 545.980469 C 538.738281 546.886719 538.207031 547.78125 537.652344 548.644531 C 537.082031 549.472656 536.527344 550.257812 535.960938 551.046875 C 535.363281 551.835938 534.796875 552.621094 534.191406 553.371094 C 533.519531 554.199219 532.847656 554.957031 532.136719 555.746094 C 531.53125 556.417969 530.960938 557.089844 530.328125 557.734375 C 529.539062 558.5625 528.714844 559.347656 527.847656 560.136719 C 527.292969 560.65625 526.726562 561.183594 526.128906 561.675781 C 525.148438 562.539062 524.167969 563.289062 523.160156 564.078125 C 522.632812 564.453125 522.140625 564.828125 521.621094 565.203125 C 520.460938 566.027344 519.257812 566.816406 518.019531 567.566406 C 517.644531 567.824219 517.230469 568.054688 516.816406 568.277344 C 515.460938 569.101562 514.039062 569.851562 512.570312 570.535156 C 512.308594 570.679688 512.050781 570.792969 511.78125 570.949219 C 510.167969 571.660156 508.523438 572.332031 506.871094 572.976562 C 506.753906 573.003906 506.601562 573.042969 506.496094 573.082031 C 500.96875 575.070312 495.042969 576.273438 488.890625 576.609375 L 488.851562 576.609375 C 487.71875 576.6875 486.632812 576.800781 485.507812 576.800781 C 451.046875 576.800781 423.046875 548.796875 423.046875 514.335938 L 403.824219 514.335938 C 403.824219 557.773438 437.871094 593.242188 480.664062 595.800781 C 480.664062 595.828125 480.703125 595.945312 480.703125 596.019531 C 480.703125 622.523438 459.160156 644.070312 432.65625 644.070312 C 406.152344 644.070312 384.605469 622.523438 384.605469 596.019531 Z M 352.777344 596.847656 L 321.019531 525.371094 C 327.398438 521.996094 332.347656 516.402344 334.789062 509.53125 L 365.386719 509.53125 L 365.386719 596.019531 C 365.386719 598.722656 365.578125 601.355469 365.875 603.9375 C 362.273438 600.527344 357.804688 598.046875 352.777344 596.847656 Z M 346.167969 634.460938 C 340.871094 634.460938 336.558594 630.144531 336.558594 624.851562 C 336.558594 619.554688 340.871094 615.238281 346.167969 615.238281 C 351.460938 615.238281 355.777344 619.554688 355.777344 624.851562 C 355.777344 630.144531 351.460938 634.460938 346.167969 634.460938 Z M 240.460938 586.410156 C 240.460938 581.117188 244.773438 576.800781 250.070312 576.800781 C 255.363281 576.800781 259.679688 581.117188 259.679688 586.410156 C 259.679688 591.707031 255.363281 596.019531 250.070312 596.019531 C 244.773438 596.019531 240.460938 591.707031 240.460938 586.410156 Z M 173.191406 528.753906 C 173.191406 523.457031 177.507812 519.140625 182.800781 519.140625 C 188.097656 519.140625 192.410156 523.457031 192.410156 528.753906 C 192.410156 534.046875 188.097656 538.363281 182.800781 538.363281 C 177.507812 538.363281 173.191406 534.046875 173.191406 528.753906 Z M 173.191406 403.824219 C 178.488281 403.824219 182.800781 408.140625 182.800781 413.433594 C 182.800781 418.730469 178.488281 423.046875 173.191406 423.046875 C 167.898438 423.046875 163.582031 418.730469 163.582031 413.433594 C 163.582031 408.140625 167.898438 403.824219 173.191406 403.824219 Z M 172.105469 324.765625 L 223.34375 318.347656 C 226.496094 326.3125 233.097656 332.425781 241.324219 335.058594 L 249.167969 405.890625 C 242.566406 408.476562 237.230469 413.433594 234.039062 419.738281 L 201.117188 406.5625 C 198.496094 396.023438 190.125 387.757812 179.496094 385.355469 L 158.101562 342.523438 C 164.9375 338.769531 170.039062 332.425781 172.105469 324.765625 Z M 288.507812 182.800781 C 293.804688 182.800781 298.117188 187.117188 298.117188 192.410156 C 298.117188 197.707031 293.804688 202.019531 288.507812 202.019531 C 283.214844 202.019531 278.898438 197.707031 278.898438 192.410156 C 278.898438 187.117188 283.214844 182.800781 288.507812 182.800781 Z M 355.777344 365.386719 C 350.480469 365.386719 346.167969 361.070312 346.167969 355.777344 C 346.167969 350.480469 350.480469 346.167969 355.777344 346.167969 C 361.070312 346.167969 365.386719 350.480469 365.386719 355.777344 C 365.386719 361.070312 361.070312 365.386719 355.777344 365.386719 Z M 307.726562 509.53125 C 302.433594 509.53125 298.117188 505.21875 298.117188 499.921875 C 298.117188 494.628906 302.433594 490.3125 307.726562 490.3125 C 313.023438 490.3125 317.339844 494.628906 317.339844 499.921875 C 317.339844 505.21875 313.023438 509.53125 307.726562 509.53125 Z M 259.679688 423.046875 C 264.972656 423.046875 269.289062 427.359375 269.289062 432.65625 C 269.289062 437.949219 264.972656 442.265625 259.679688 442.265625 C 254.382812 442.265625 250.070312 437.949219 250.070312 432.65625 C 250.070312 427.359375 254.382812 423.046875 259.679688 423.046875 Z M 275.257812 321.46875 L 328.1875 347.933594 C 327.4375 350.445312 326.949219 353.039062 326.949219 355.777344 C 326.949219 364.339844 330.773438 371.960938 336.75 377.246094 L 283.820312 416.960938 C 280.214844 411.484375 274.804688 407.390625 268.425781 405.324219 L 260.582031 334.492188 C 266.886719 332.011719 272.027344 327.359375 275.257812 321.46875 Z M 250.070312 298.117188 C 255.363281 298.117188 259.679688 302.433594 259.679688 307.726562 C 259.679688 313.023438 255.363281 317.339844 250.070312 317.339844 C 244.773438 317.339844 240.460938 313.023438 240.460938 307.726562 C 240.460938 302.433594 244.773438 298.117188 250.070312 298.117188 Z M 191.46875 501.382812 L 183.8125 440.160156 C 190.34375 437.574219 195.679688 432.617188 198.832031 426.351562 L 231.753906 439.527344 C 234.867188 452.09375 246.167969 461.484375 259.679688 461.484375 C 266.175781 461.484375 272.105469 459.273438 276.910156 455.632812 L 292.035156 475.785156 C 286.328125 479.539062 282.128906 485.25 280.175781 491.925781 L 203.636719 508.9375 C 200.328125 505.476562 196.167969 502.890625 191.46875 501.382812 Z M 227.132812 569.105469 L 204.992188 546.960938 C 209.074219 541.964844 211.628906 535.699219 211.628906 528.753906 C 211.628906 528.117188 211.476562 527.511719 211.4375 526.878906 L 281.300781 511.367188 C 283.664062 516.816406 287.683594 521.324219 292.679688 524.398438 L 266.886719 563.097656 C 262.160156 559.648438 256.375 557.582031 250.070312 557.582031 C 240.679688 557.582031 232.425781 562.164062 227.132812 569.105469 Z M 320.644531 611.714844 L 277.957031 593.398438 C 278.523438 591.140625 278.898438 588.8125 278.898438 586.410156 C 278.898438 584.421875 278.707031 582.472656 278.304688 580.597656 L 306.035156 538.996094 L 332.875 599.402344 C 327.660156 602.179688 323.382812 606.417969 320.644531 611.714844 Z M 334.789062 490.3125 C 331.378906 480.742188 323.121094 473.496094 312.90625 471.621094 L 287.941406 438.324219 C 287.980469 438.171875 287.941406 437.988281 287.980469 437.835938 L 359.496094 384.230469 C 361.523438 383.972656 363.511719 383.519531 365.386719 382.839844 L 365.386719 490.3125 Z M 355.777344 230.851562 C 361.070312 230.851562 365.386719 235.164062 365.386719 240.460938 C 365.386719 245.753906 361.070312 250.070312 355.777344 250.070312 C 350.480469 250.070312 346.167969 245.753906 346.167969 240.460938 C 346.167969 235.164062 350.480469 230.851562 355.777344 230.851562 Z M 311.445312 209.71875 L 329.839844 228.113281 C 328.035156 231.859375 326.949219 236.03125 326.949219 240.460938 C 326.949219 256.375 339.863281 269.289062 355.777344 269.289062 C 359.160156 269.289062 362.351562 268.578125 365.386719 267.519531 L 365.386719 328.714844 C 362.351562 327.660156 359.160156 326.949219 355.777344 326.949219 C 349.695312 326.949219 344.101562 328.859375 339.449219 332.050781 L 278.148438 301.425781 C 276.304688 293.238281 270.980469 286.441406 263.8125 282.542969 L 294.8125 220.492188 C 301.566406 218.992188 307.390625 215.042969 311.445312 209.71875 Z M 278.898438 142.597656 L 278.898438 165.351562 C 267.75 169.328125 259.679688 179.910156 259.679688 192.410156 C 259.679688 203.300781 265.839844 212.679688 274.765625 217.597656 L 248.300781 270.566406 L 198.300781 206.296875 C 200.597656 202.175781 202.019531 197.476562 202.019531 192.410156 C 202.019531 190.757812 201.800781 189.144531 201.53125 187.566406 L 264.972656 132.089844 C 268.386719 136.894531 273.230469 140.570312 278.898438 142.597656 Z M 156.75 291.40625 L 180.476562 220.195312 C 181.636719 219.890625 182.800781 219.589844 183.925781 219.140625 L 234.15625 283.707031 C 228.746094 287.3125 224.726562 292.75 222.699219 299.0625 L 170.597656 305.589844 C 167.820312 299.363281 162.867188 294.363281 156.75 291.40625"/></svg>';
        $icon_data_uri = 'data:image/svg+xml;base64,' . base64_encode($icon_svg);

        $this->hook_suffixes['dashboard'] = add_menu_page(
            __('MindfulSEO', 'mindfulseo'),
            __('MindfulSEO', 'mindfulseo'),
            'manage_options',
            'mindfulseo',
            array($this, 'render_dashboard_page'),
            $icon_data_uri,
            30
        );
        
        // Dashboard submenu (rename parent)
        add_submenu_page(
            'mindfulseo',
            __('Dashboard', 'mindfulseo'),
            __('Dashboard', 'mindfulseo'),
            'manage_options',
            'mindfulseo',
            array($this, 'render_dashboard_page')
        );
        
        // SEO Audit (Site Health)
        $this->hook_suffixes['seo_audit'] = add_submenu_page(
            'mindfulseo',
            __('SEO Audit', 'mindfulseo'),
            __('SEO Audit', 'mindfulseo'),
            'edit_posts',
            'mindfulseo-seo-audit',
            array($this, 'render_seo_audit_page')
        );
        
        // Content Hub
        $this->hook_suffixes['content-hub'] = add_submenu_page(
            'mindfulseo',
            __('Content Hub', 'mindfulseo'),
            __('Content Hub', 'mindfulseo'),
            'edit_posts',
            'mindfulseo-content-hub',
            array($this, 'render_content_hub_page')
        );
        
        // Keyword Strategy
        $this->hook_suffixes['keywords'] = add_submenu_page(
            'mindfulseo',
            __('Keyword Strategy', 'mindfulseo'),
            __('Keyword Strategy', 'mindfulseo'),
            'manage_options',
            'mindfulseo-keywords',
            array($this, 'render_keywords_page')
        );
        
        // Language Guidelines
        $this->hook_suffixes['guidelines'] = add_submenu_page(
            'mindfulseo',
            __('Language Guidelines', 'mindfulseo'),
            __('Language Guidelines', 'mindfulseo'),
            'manage_options',
            'mindfulseo-guidelines',
            array($this, 'render_guidelines_page')
        );
        
        // Batch Optimizer
        $this->hook_suffixes['batch_optimize'] = add_submenu_page(
            'mindfulseo',
            __('Batch Optimizer', 'mindfulseo'),
            __('Batch Optimizer', 'mindfulseo'),
            'edit_posts',
            'mindfulseo-batch-optimize',
            array($this, 'render_batch_optimize_page')
        );
        
        // Import / Export
        $this->hook_suffixes['import_export'] = add_submenu_page(
            'mindfulseo',
            __('Import / Export', 'mindfulseo'),
            __('Import / Export', 'mindfulseo'),
            'manage_options',
            'mindfulseo-import-export',
            array($this, 'render_import_export_page')
        );
        
        // Settings
        $this->hook_suffixes['settings'] = add_submenu_page(
            'mindfulseo',
            __('Settings', 'mindfulseo'),
            __('Settings', 'mindfulseo'),
            'manage_options',
            'mindfulseo-settings',
            array($this, 'render_settings_page')
        );
        
        // Setup Wizard (hidden from menu - use parent slug to avoid NULL warning)
        $this->hook_suffixes['wizard'] = add_submenu_page(
            'mindfulseo', // Use parent slug instead of null to prevent deprecation warnings
            __('Setup Wizard', 'mindfulseo'),
            __('Setup Wizard', 'mindfulseo'),
            'manage_options',
            'mindfulseo-wizard',
            array($this, 'render_wizard_page')
        );
        
        // Hide the wizard menu item via CSS (remove_submenu_page breaks page access)
        add_action('admin_head', function() {
            echo '<style>#adminmenu a[href="admin.php?page=mindfulseo-wizard"]{display:none!important}</style>';
        });
    }
    
    public function render_import_export_page() {
        if (class_exists('MFSEO_Post_Import_Export')) {
            MFSEO_Post_Import_Export::render_page();
            return;
        }
        echo '<div class="wrap"><div class="notice notice-error"><p>Import/Export unavailable.</p></div></div>';
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard_page() {
        if (class_exists('MFSEO_Dashboard_Page')) {
            $page = new MFSEO_Dashboard_Page();
            $page->render();
            return;
        }
        if (class_exists('MFSEO_Admin_Page')) {
            MFSEO_Admin_Page::get_instance()->render_dashboard_page();
            return;
        }
        echo '<div class="wrap"><div class="notice notice-error"><p><strong>' . esc_html__('Dashboard is unavailable.', 'mindfulseo') . '</strong> ' . esc_html__('Please ensure MindfulSEO is fully installed.', 'mindfulseo') . '</p></div></div>';
    }
    
    /**
     * Render content hub page (direct to Admin_Page so it always opens)
     */
    public function render_content_hub_page() {
        if (class_exists('MFSEO_Admin_Page') && method_exists(MFSEO_Admin_Page::get_instance(), 'render_content_hub_page')) {
            try {
                MFSEO_Admin_Page::get_instance()->render_content_hub_page();
                return;
            } catch (Throwable $e) {
                echo '<div class="wrap"><div class="notice notice-error"><p><strong>' . esc_html__('Content Hub could not load.', 'mindfulseo') . '</strong> ' . esc_html($e->getMessage()) . '</p></div></div>';
                return;
            }
        }
        if (class_exists('MFSEO_Content_Hub_Page')) {
            $page = new MFSEO_Content_Hub_Page();
            $page->render();
            return;
        }
        echo '<div class="wrap"><div class="notice notice-error"><p><strong>' . esc_html__('Content Hub is unavailable.', 'mindfulseo') . '</strong> ' . esc_html__('Please ensure MindfulSEO is fully installed.', 'mindfulseo') . '</p></div></div>';
    }
    
    /**
     * Render keywords page
     */
    public function render_keywords_page() {
        if (class_exists('MFSEO_Keywords_Page')) {
            $page = new MFSEO_Keywords_Page();
            $page->render();
            return;
        }
        if (class_exists('MFSEO_Admin_Page')) {
            MFSEO_Admin_Page::get_instance()->render_keywords_page();
            return;
        }
        echo '<div class="wrap"><div class="notice notice-error"><p><strong>' . esc_html__('Keyword Strategy is unavailable.', 'mindfulseo') . '</strong> ' . esc_html__('Please ensure MindfulSEO is fully installed.', 'mindfulseo') . '</p></div></div>';
    }
    
    /**
     * Render SEO Audit page
     */
    public function render_seo_audit_page() {
        if (class_exists('MFSEO_Admin_Page')) {
            MFSEO_Admin_Page::get_instance()->render_seo_audit_page();
            return;
        }
        echo '<div class="wrap"><div class="notice notice-error"><p><strong>' . esc_html__('SEO Audit is unavailable.', 'mindfulseo') . '</strong> ' . esc_html__('Please ensure MindfulSEO is fully installed.', 'mindfulseo') . '</p></div></div>';
    }
    
    /**
     * Render Language Guidelines page
     */
    public function render_guidelines_page() {
        if (class_exists('MFSEO_Admin_Page')) {
            MFSEO_Admin_Page::get_instance()->render_guidelines_page();
            return;
        }
        echo '<div class="wrap"><div class="notice notice-error"><p><strong>' . esc_html__('Language Guidelines is unavailable.', 'mindfulseo') . '</strong> ' . esc_html__('Please ensure MindfulSEO is fully installed.', 'mindfulseo') . '</p></div></div>';
    }
    
    /**
     * Render Batch Optimizer page
     */
    public function render_batch_optimize_page() {
        if (class_exists('MFSEO_Admin_Page')) {
            MFSEO_Admin_Page::get_instance()->render_batch_optimize_page();
            return;
        }
        echo '<div class="wrap"><div class="notice notice-error"><p><strong>' . esc_html__('Batch Optimizer is unavailable.', 'mindfulseo') . '</strong> ' . esc_html__('Please ensure MindfulSEO is fully installed.', 'mindfulseo') . '</p></div></div>';
    }
    
    /**
     * Render Settings page
     */
    public function render_settings_page() {
        if (class_exists('MFSEO_Admin_Page')) {
            MFSEO_Admin_Page::get_instance()->render_settings_page();
            return;
        }
        echo '<div class="wrap"><div class="notice notice-error"><p><strong>' . esc_html__('Settings is unavailable.', 'mindfulseo') . '</strong> ' . esc_html__('Please ensure MindfulSEO is fully installed.', 'mindfulseo') . '</p></div></div>';
    }
    
    /**
     * Render wizard page
     */
    public function render_wizard_page() {
        update_option('mindfulseo_wizard_needed', true);

        if (class_exists('MFSEO_Setup_Wizard')) {
            MFSEO_Setup_Wizard::get_instance()->render_wizard_page();
        }
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_assets($hook_suffix) {
        // Only load on our pages
        if (!in_array($hook_suffix, $this->hook_suffixes)) {
            return;
        }
        
        // WordPress color picker (used on Settings)
        wp_enqueue_style('wp-color-picker');
        
        // Base admin styles and scripts for all MindfulSEO pages
        wp_enqueue_style(
            'mindfulseo-admin',
            MINDFULSEO_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            MINDFULSEO_VERSION
        );
        
        wp_enqueue_script(
            'mindfulseo-admin',
            MINDFULSEO_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery', 'wp-color-picker'),
            MINDFULSEO_VERSION,
            true
        );
        
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
        
        // Batch Optimizer page: sortable + batch-optimizer.js
        if (isset($this->hook_suffixes['batch_optimize']) && $hook_suffix === $this->hook_suffixes['batch_optimize']) {
            $batch_optimizer_version = MINDFULSEO_VERSION . '-v2';
            $batch_optimizer_path = MINDFULSEO_PLUGIN_DIR . 'assets/js/batch-optimizer.js';
            if (is_string($batch_optimizer_path) && $batch_optimizer_path !== '' && file_exists($batch_optimizer_path)) {
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
        
        // Content Hub: content-hub.js + admin nonce for cluster/link/gap AJAX
        if ($hook_suffix === $this->hook_suffixes['content-hub']) {
            wp_enqueue_script(
                'mindfulseo-content-hub',
                MINDFULSEO_PLUGIN_URL . 'assets/js/content-hub.js',
                array('jquery'),
                MINDFULSEO_VERSION,
                true
            );
            wp_localize_script('mindfulseo-content-hub', 'mfseoContentHub', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mindfulseo_content_hub'),
                'adminNonce' => wp_create_nonce('mindfulseo_admin'),
                'tabUrls' => array(
                    'clusters' => admin_url('admin.php?page=mindfulseo-content-hub&tab=clusters'),
                    'health' => admin_url('admin.php?page=mindfulseo-content-hub&tab=health'),
                    'links' => admin_url('admin.php?page=mindfulseo-content-hub&tab=links'),
                ),
            ));
        }
        
        if ($hook_suffix === $this->hook_suffixes['dashboard']) {
            wp_enqueue_script(
                'mindfulseo-dashboard',
                MINDFULSEO_PLUGIN_URL . 'assets/js/dashboard.js',
                array('jquery'),
                MINDFULSEO_VERSION,
                true
            );
        }
    }
    
    /**
     * Maybe show wizard notice
     */
    public function maybe_show_wizard_notice() {
        if (!get_option('mindfulseo_wizard_needed')) {
            return;
        }
        
        // Don't show on the wizard page itself
        $screen = get_current_screen();
        if ($screen && strpos($screen->id, 'mindfulseo-wizard') !== false) {
            return;
        }
        
        // Only show on MindfulSEO pages
        if ($screen && strpos($screen->id, 'mindfulseo') === false) {
            return;
        }
        
        ?>
        <div class="notice notice-info is-dismissible mfseo-wizard-notice">
            <p>
                <strong><?php _e('Welcome to MindfulSEO!', 'mindfulseo'); ?></strong>
                <?php _e('Run the setup wizard to configure your API keys and optimize your first post.', 'mindfulseo'); ?>
            </p>
            <p>
                <a href="<?php echo admin_url('admin.php?page=mindfulseo-wizard'); ?>" class="button button-primary">
                    <?php _e('Run Setup Wizard', 'mindfulseo'); ?>
                </a>
                <button type="button" class="button button-secondary mfseo-dismiss-wizard">
                    <?php _e('Dismiss', 'mindfulseo'); ?>
                </button>
            </p>
        </div>
        <?php
    }
    
    /**
     * Enqueue wizard assets
     */
    public function enqueue_wizard_assets($hook_suffix) {
        $wizard_pages = array(
            'mindfulseo_page_mindfulseo-wizard',
        );
        if (!in_array($hook_suffix, $wizard_pages, true)) {
            return;
        }

        $css_path = MINDFULSEO_PLUGIN_DIR . 'assets/css/setup-wizard.css';
        $js_path  = MINDFULSEO_PLUGIN_DIR . 'assets/js/setup-wizard.js';
        $ver_css  = MINDFULSEO_VERSION . (file_exists($css_path) ? '.' . (string) filemtime($css_path) : '');
        $ver_js   = MINDFULSEO_VERSION . (file_exists($js_path) ? '.' . (string) filemtime($js_path) : '');

        wp_enqueue_style(
            'mindfulseo-wizard',
            MINDFULSEO_PLUGIN_URL . 'assets/css/setup-wizard.css',
            array(),
            $ver_css
        );

        wp_enqueue_script(
            'mindfulseo-wizard',
            MINDFULSEO_PLUGIN_URL . 'assets/js/setup-wizard.js',
            array('jquery'),
            $ver_js,
            true
        );

        wp_localize_script('mindfulseo-wizard', 'mfseoWizard', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mfseo_wizard_nonce'),
            'batchNonce' => wp_create_nonce('mindfulseo_batch_optimize'),
            'dashboardUrl' => admin_url('admin.php?page=mindfulseo'),
            'optimizerUrl' => admin_url('admin.php?page=mindfulseo-batch-optimize'),
            'adminUrl' => admin_url(),
            'strings' => array(
                'selectAtLeastOne' => __('Please select at least one post to optimize.', 'mindfulseo'),
                'optimizingPost' => __('Optimizing post %1$d of %2$d...', 'mindfulseo'),
                'resultHeadingAI' => __('Content analysis complete!', 'mindfulseo'),
                'resultHeadingIssues' => __('Analysis finished with issues', 'mindfulseo'),
                'analyzeNoteIssues' => __('Some AI steps failed (see warnings above). Pattern-based guidelines may still have been added. Fix API keys or try again; check MindfulSEO → Settings → Usage for details.', 'mindfulseo'),
                'analyzeNoteDefault' => __('You can review and edit these anytime from the Keyword Strategy and Language Guidelines pages.', 'mindfulseo'),
                'resultHeadingSaved' => __('Using your saved keyword strategy & guidelines', 'mindfulseo'),
                'kwLabelGenerated' => __('keywords generated', 'mindfulseo'),
                'kwLabelSaved' => __('keywords in your strategy', 'mindfulseo'),
                'glLabelCreated' => __('guidelines created', 'mindfulseo'),
                'glLabelSaved' => __('guidelines in use', 'mindfulseo'),
                'continueToNext' => __('Continue', 'mindfulseo') . ' →',
                'selectRegenerateArea' => __('Choose at least one: Regenerate keywords or Regenerate guidelines — or turn “Use imported & saved strategy” on to improve everything using your current strategy.', 'mindfulseo'),
                'savedSummaryLine' => __('You have %1$s keywords and %2$s language guidelines saved in MindfulSEO.', 'mindfulseo'),
                'savedSummaryEmpty' => __('No keywords or guidelines in MindfulSEO yet — import files below, then run analysis (or analyze first).', 'mindfulseo'),
                'formatExampleMissing' => __('Example content could not be loaded. Try closing and opening again, or reload the page.', 'mindfulseo'),
                'wizardPreservationSummary' => __('Protected your imports: %1$s keyword row(s) and %2$s guideline row(s) from files or manual entry were kept; AI additions are separate.', 'mindfulseo'),
                'wizardPreservationRestored' => __(' Re-synced %1$s keyword and %2$s guideline row(s) that were missing (data restored).', 'mindfulseo'),
            ),
        ));
    }
}
