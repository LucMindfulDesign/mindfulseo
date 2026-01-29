<?php
/**
 * AJAX Handlers Class
 *
 * Handles AJAX requests for API testing and content analysis
 *
 * @package MindfulSEO
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_AJAX_Handlers {
    
    /**
     * Initialize AJAX handlers
     */
    public static function init() {
        // API testing
        add_action('wp_ajax_mindfulseo_test_openai', array(__CLASS__, 'test_openai_connection'));
        add_action('wp_ajax_mindfulseo_test_claude', array(__CLASS__, 'test_claude_connection'));
        add_action('wp_ajax_mindfulseo_test_both', array(__CLASS__, 'test_both_connections'));
        add_action('wp_ajax_mindfulseo_test_dataforseo', array(__CLASS__, 'test_dataforseo_connection'));
        
        // Auto-generate keywords
        add_action('wp_ajax_mindfulseo_autogenerate_keywords', array(__CLASS__, 'autogenerate_keywords'));
        
        // Auto-generate guidelines
        add_action('wp_ajax_mindfulseo_autogenerate_guidelines', array(__CLASS__, 'autogenerate_guidelines'));
        
        // Inline editing
        add_action('wp_ajax_mindfulseo_update_keyword', array(__CLASS__, 'update_keyword'));
        add_action('wp_ajax_mindfulseo_update_guideline', array(__CLASS__, 'update_guideline'));
        
        // AI Cleanup
        add_action('wp_ajax_mindfulseo_cleanup_keywords', array(__CLASS__, 'cleanup_keywords'));
        add_action('wp_ajax_mindfulseo_apply_cleanup', array(__CLASS__, 'apply_cleanup_changes'));
        
        // Refresh SEO Data
        add_action('wp_ajax_mindfulseo_refresh_seo_data', array(__CLASS__, 'refresh_seo_data'));
        
        // Refresh Metrics (DataForSEO for visible keywords)
        add_action('wp_ajax_mindfulseo_recalculate_rankmath_scores', array(__CLASS__, 'recalculate_rankmath_scores'));
        
        // Batch optimization
        add_action('wp_ajax_mindfulseo_batch_optimize_single', array(__CLASS__, 'batch_optimize_single'));
        
        // Inline editing for batch table
        add_action('wp_ajax_mindfulseo_update_post_seo', array(__CLASS__, 'update_post_seo'));
        
        // Domain analysis (Task 2)
        add_action('wp_ajax_mindfulseo_analyze_site_rankings', array(__CLASS__, 'analyze_site_rankings'));
        
        // Custom prompts
        add_action('wp_ajax_mindfulseo_save_custom_prompt', array(__CLASS__, 'save_custom_prompt'));
    }
    
    /**
     * Test OpenAI connection via AJAX
     */
    public static function test_openai_connection() {
        check_ajax_referer('mindfulseo_test_api', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'mindfulseo')));
        }
        
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        $model = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : 'gpt-3.5-turbo';
        
        // If key is empty or just dots (masked value), use saved key
        if (empty($api_key) || strpos($api_key, '•••') !== false) {
            $settings = get_option('mindfulseo_settings', array());
            $api_key = isset($settings['openai_api_key']) ? $settings['openai_api_key'] : '';
            
            // Decrypt the saved key
            if (!empty($api_key) && class_exists('MFSEO_AI_Connector')) {
                $connector = MFSEO_AI_Connector::get_instance();
                $api_key = $connector->decrypt_api_key($api_key);
            }
        }
        
        $result = MFSEO_API_Tester::test_openai_connection($api_key, $model);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'code' => $result->get_error_code()
            ));
        } else {
            wp_send_json_success($result);
        }
    }
    
    /**
     * Test Claude connection via AJAX
     */
    public static function test_claude_connection() {
        check_ajax_referer('mindfulseo_test_api', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'mindfulseo')));
        }
        
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        $model = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : 'claude-3-haiku-20240307';
        
        // If key is empty or just dots (masked value), use saved key
        if (empty($api_key) || strpos($api_key, '•••') !== false) {
            $settings = get_option('mindfulseo_settings', array());
            $api_key = isset($settings['claude_api_key']) ? $settings['claude_api_key'] : '';
            
            // Decrypt the saved key
            if (!empty($api_key) && class_exists('MFSEO_AI_Connector')) {
                $connector = MFSEO_AI_Connector::get_instance();
                $api_key = $connector->decrypt_api_key($api_key);
            }
        }
        
        $result = MFSEO_API_Tester::test_claude_connection($api_key, $model);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'code' => $result->get_error_code()
            ));
        } else {
            wp_send_json_success($result);
        }
    }
    
    /**
     * Test both connections via AJAX
     */
    public static function test_both_connections() {
        check_ajax_referer('mindfulseo_test_api', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'mindfulseo')));
        }
        
        $settings = get_option('mindfulseo_settings', array());
        $openai_key = isset($settings['openai_api_key']) ? $settings['openai_api_key'] : '';
        $claude_key = isset($settings['claude_api_key']) ? $settings['claude_api_key'] : '';
        $openai_model = isset($settings['openai_model']) ? $settings['openai_model'] : 'gpt-3.5-turbo';
        $claude_model = isset($settings['claude_model']) ? $settings['claude_model'] : 'claude-3-haiku-20240307';
        
        $results = MFSEO_API_Tester::test_all_connections($openai_key, $claude_key, $openai_model, $claude_model);
        
        wp_send_json_success($results);
    }
    
    /**
     * Auto-generate keywords via AJAX
     */
    public static function autogenerate_keywords() {
        check_ajax_referer('mindfulseo_autogenerate', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'mindfulseo')));
        }
        
        $post_types = isset($_POST['post_types']) ? array_map('sanitize_text_field', $_POST['post_types']) : array('post');
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 50;
        
        $analyzer = new MFSEO_Content_Analyzer();
        $suggestions = $analyzer->analyze_for_keywords(array(
            'post_types' => $post_types,
            'limit' => $limit
        ));
        
        if (empty($suggestions)) {
            wp_send_json_error(array(
                'message' => __('No keywords found. Try analyzing more posts or different post types.', 'mindfulseo')
            ));
        }
        
        wp_send_json_success(array(
            'suggestions' => $suggestions,
            'count' => count($suggestions)
        ));
    }
    
    /**
     * Auto-generate guidelines via AJAX
     */
    public static function autogenerate_guidelines() {
        check_ajax_referer('mindfulseo_autogenerate', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'mindfulseo')));
        }
        
        $post_types = isset($_POST['post_types']) ? array_map('sanitize_text_field', $_POST['post_types']) : array('post');
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 100;
        
        $analyzer = new MFSEO_Content_Analyzer();
        $suggestions = $analyzer->analyze_for_guidelines(array(
            'post_types' => $post_types,
            'limit' => $limit
        ));
        
        if (empty($suggestions)) {
            wp_send_json_error(array(
                'message' => __('No patterns found. Try analyzing more posts or different post types.', 'mindfulseo')
            ));
        }
        
        wp_send_json_success(array(
            'suggestions' => $suggestions
        ));
    }
    
    /**
     * Update keyword inline
     */
    public static function update_keyword() {
        check_ajax_referer('mindfulseo_inline_edit', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'mindfulseo')));
        }
        
        $keyword_id = isset($_POST['keyword_id']) ? intval($_POST['keyword_id']) : 0;
        $field = isset($_POST['field']) ? sanitize_key($_POST['field']) : '';
        $value = isset($_POST['value']) ? sanitize_text_field($_POST['value']) : '';
        
        if (!$keyword_id || !$field) {
            wp_send_json_error(array('message' => __('Invalid request', 'mindfulseo')));
        }
        
        // Allowed fields
        $allowed_fields = array('primary_keyword', 'longtail_keyword', 'search_intent', 'priority', 'notes');
        if (!in_array($field, $allowed_fields)) {
            wp_send_json_error(array('message' => __('Invalid field', 'mindfulseo')));
        }
        
        // Validate search intent
        if ($field === 'search_intent') {
            $allowed_intents = array('Informational', 'Navigational', 'Transactional', 'Commercial');
            if (!in_array($value, $allowed_intents)) {
                wp_send_json_error(array('message' => __('Invalid search intent', 'mindfulseo')));
            }
        }
        
        // Validate priority
        if ($field === 'priority') {
            $value = strtoupper($value);
            $allowed_priorities = array('HIGH', 'MEDIUM', 'LOW');
            if (!in_array($value, $allowed_priorities)) {
                wp_send_json_error(array('message' => __('Invalid priority', 'mindfulseo')));
            }
        }
        
        $keyword_manager = MFSEO_Keyword_Manager::get_instance();
        $result = $keyword_manager->update_keyword($keyword_id, array($field => $value));
        
        if ($result) {
            wp_send_json_success(array(
                'message' => __('Keyword updated', 'mindfulseo'),
                'value' => $value
            ));
        } else {
            wp_send_json_error(array('message' => __('Update failed', 'mindfulseo')));
        }
    }
    
    /**
     * Update guideline inline
     */
    public static function update_guideline() {
        check_ajax_referer('mindfulseo_inline_edit', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'mindfulseo')));
        }
        
        $guideline_id = isset($_POST['guideline_id']) ? intval($_POST['guideline_id']) : 0;
        $field = isset($_POST['field']) ? sanitize_key($_POST['field']) : '';
        $value = isset($_POST['value']) ? sanitize_text_field($_POST['value']) : '';
        
        if (!$guideline_id || !$field) {
            wp_send_json_error(array('message' => __('Invalid request', 'mindfulseo')));
        }
        
        // Allowed fields
        $allowed_fields = array('rule_type', 'avoid_term', 'preferred_term', 'context');
        if (!in_array($field, $allowed_fields)) {
            wp_send_json_error(array('message' => __('Invalid field', 'mindfulseo')));
        }
        
        // Validate rule type
        if ($field === 'rule_type') {
            $allowed_types = array('avoid_term', 'capitalize', 'seo_friendly', 'preferred_term');
            if (!in_array($value, $allowed_types)) {
                wp_send_json_error(array('message' => __('Invalid rule type', 'mindfulseo')));
            }
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'mindfulseo_guidelines';
        
        $result = $wpdb->update(
            $table_name,
            array($field => $value),
            array('id' => $guideline_id),
            array('%s'),
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success(array(
                'message' => __('Guideline updated', 'mindfulseo'),
                'value' => $value
            ));
        } else {
            wp_send_json_error(array('message' => __('Update failed', 'mindfulseo')));
        }
    }
    
    /**
     * AI Cleanup Keywords
     */
    public static function cleanup_keywords() {
        error_log('MindfulSEO: cleanup_keywords() called');
        
        try {
            check_ajax_referer('mindfulseo_cleanup', 'nonce');
            error_log('MindfulSEO: nonce check passed');
            
            if (!current_user_can('manage_options')) {
                error_log('MindfulSEO: User does not have manage_options capability');
                wp_send_json_error(array('message' => __('Unauthorized', 'mindfulseo')));
            }
            
            // Get all keywords
            $keyword_manager = MFSEO_Keyword_Manager::get_instance();
            error_log('MindfulSEO: Keyword manager created');
            
            $keywords = $keyword_manager->get_keywords(array('limit' => 999999));
            error_log('MindfulSEO: Found ' . count($keywords) . ' keywords');
            
            if (empty($keywords)) {
                wp_send_json_error(array('message' => __('No keywords to review', 'mindfulseo')));
            }
            
            // Prepare keyword list for AI
            $keyword_list = array();
            foreach ($keywords as $kw) {
                $keyword_list[] = array(
                    'id' => $kw->id,
                    'primary' => $kw->primary_keyword,
                    'longtail' => $kw->longtail_keyword,
                    'intent' => $kw->search_intent,
                    'priority' => $kw->priority
                );
            }
            
            error_log('MindfulSEO: About to create AI connector');
            
            // Call AI to review
            try {
                $ai_connector = MFSEO_AI_Connector::get_instance();
                error_log('MindfulSEO: AI connector created successfully');
            } catch (Exception $ai_ex) {
                error_log('MindfulSEO: Failed to create AI connector: ' . $ai_ex->getMessage());
                wp_send_json_error(array('message' => 'Failed to initialize AI: ' . $ai_ex->getMessage()));
            }
            
            // Check if AI is configured
            $settings = MindfulSEO::get_settings();
            $has_openai = !empty($settings['openai_api_key']);
            $has_claude = !empty($settings['claude_api_key']);
            
            error_log('MindfulSEO: Has OpenAI: ' . ($has_openai ? 'yes' : 'no') . ', Has Claude: ' . ($has_claude ? 'yes' : 'no'));
            
            if (!$has_openai && !$has_claude) {
                error_log('MindfulSEO: No API keys configured');
                wp_send_json_error(array('message' => __('Please configure your OpenAI or Claude API keys in the Settings page first.', 'mindfulseo')));
            }
            
            // Build comprehensive context for AI
            $prompt = self::build_smart_cleanup_prompt($keyword_list, $settings);
            
            error_log('MindfulSEO: Calling AI with prompt');
            $response = $ai_connector->generate_content($prompt, array('max_tokens' => 4000));
            error_log('MindfulSEO: AI response received');
            
            if (is_wp_error($response)) {
                error_log('MindfulSEO: AI returned error: ' . $response->get_error_message());
                wp_send_json_error(array('message' => $response->get_error_message()));
            }
            
            // Parse AI response
            error_log('MindfulSEO: Parsing AI response');
            
            // Strip markdown code blocks if present
            $response = trim($response);
            if (strpos($response, '```json') === 0) {
                $response = preg_replace('/^```json\s*/', '', $response);
                $response = preg_replace('/\s*```$/', '', $response);
                $response = trim($response);
            } elseif (strpos($response, '```') === 0) {
                $response = preg_replace('/^```\s*/', '', $response);
                $response = preg_replace('/\s*```$/', '', $response);
                $response = trim($response);
            }
            
            // Check if JSON is complete (ends with ] or })
            if (!preg_match('/[\]\}]\s*$/', $response)) {
                error_log('MindfulSEO: Response appears truncated, attempting to fix');
                // Try to close the JSON array properly
                $response = rtrim($response, ',') . ']';
            }
            
            $suggestions = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($suggestions)) {
                error_log('MindfulSEO: JSON parse error: ' . json_last_error_msg());
                error_log('MindfulSEO: Response was: ' . substr($response, 0, 1000));
                wp_send_json_error(array('message' => __('Failed to parse AI response. The response may have been truncated. Try again or reduce the number of keywords.', 'mindfulseo')));
            }
            
            error_log('MindfulSEO: Returning success with ' . count($suggestions) . ' suggestions');
            wp_send_json_success(array(
                'suggestions' => $suggestions,
                'total_keywords' => count($keywords),
                'issues_found' => count($suggestions),
                'keyword_list' => $keyword_list // Include for before/after comparison
            ));
            
        } catch (Exception $e) {
            error_log('MindfulSEO: Exception in cleanup_keywords: ' . $e->getMessage());
            error_log('MindfulSEO: Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error(array('message' => 'Server error: ' . $e->getMessage()));
        }
    }
    
    /**
     * Test DataForSEO connection via AJAX
     */
    public static function test_dataforseo_connection() {
        check_ajax_referer('mindfulseo_test_api', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'mindfulseo')));
        }
        
        $login = isset($_POST['login']) ? sanitize_email($_POST['login']) : '';
        $password = isset($_POST['password']) ? sanitize_text_field($_POST['password']) : '';
        
        // If login/password is empty or just dots (masked value), use saved credentials
        if (empty($login) || empty($password) || strpos($password, '•••') !== false || strpos($login, '•••') !== false) {
            $settings = get_option('mindfulseo_settings', array());
            if (empty($login) || strpos($login, '•••') !== false) {
                $login = isset($settings['dataforseo_login']) ? $settings['dataforseo_login'] : '';
            }
            if (empty($password) || strpos($password, '•••') !== false) {
                $password = isset($settings['dataforseo_password']) ? $settings['dataforseo_password'] : '';
                
                // Decrypt the saved password
                if (!empty($password) && class_exists('MFSEO_AI_Connector')) {
                    $connector = MFSEO_AI_Connector::get_instance();
                    $password = $connector->decrypt_api_key($password);
                }
            }
        }
        
        if (empty($login) || empty($password)) {
            wp_send_json_error(array('message' => __('Login and API password are required', 'mindfulseo')));
        }
        
        // Test connection using DataForSEO connector
        if (!class_exists('MFSEO_DataForSEO_Connector')) {
            wp_send_json_error(array('message' => __('DataForSEO connector not available', 'mindfulseo')));
        }
        
        $connector = new MFSEO_DataForSEO_Connector($login, $password);
        $result = $connector->test_connection();
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            wp_send_json_success(array('message' => __('DataForSEO connection successful!', 'mindfulseo')));
        }
    }
    
    /**
     * Refresh SEO data for all keywords via AJAX
     */
    public static function refresh_seo_data() {
        error_log('MindfulSEO: refresh_seo_data() called');
        
        check_ajax_referer('mindfulseo_refresh_seo_data', 'nonce');
        
        error_log('MindfulSEO: Nonce check passed');
        
        if (!current_user_can('manage_options')) {
            error_log('MindfulSEO: User lacks permissions');
            wp_send_json_error(array('message' => __('Unauthorized', 'mindfulseo')));
        }
        
        // Check if DataForSEO is configured
        if (!class_exists('MFSEO_DataForSEO_Connector')) {
            error_log('MindfulSEO: DataForSEO connector class not found');
            wp_send_json_error(array('message' => __('DataForSEO connector not available', 'mindfulseo')));
        }
        
        error_log('MindfulSEO: Getting DataForSEO connector instance');
        $connector = MFSEO_DataForSEO_Connector::get_instance();
        
        if (!$connector->is_configured()) {
            error_log('MindfulSEO: DataForSEO not configured');
            wp_send_json_error(array('message' => __('DataForSEO API not configured. Please add your credentials in Settings.', 'mindfulseo')));
        }
        
        error_log('MindfulSEO: DataForSEO is configured, continuing...');
        
        // Get all keywords from database
        if (!class_exists('MFSEO_Keyword_Manager')) {
            error_log('MindfulSEO: Keyword manager class not found');
            wp_send_json_error(array('message' => __('Keyword manager not available', 'mindfulseo')));
        }
        
        $keyword_manager = MFSEO_Keyword_Manager::get_instance();
        $keywords = $keyword_manager->get_keywords(array('limit' => 999999));
        
        if (empty($keywords)) {
            wp_send_json_error(array('message' => __('No keywords found to refresh', 'mindfulseo')));
        }
        
        // Extract keyword strings (primary keywords only for now)
        $keyword_strings = array();
        $keyword_map = array(); // Map primary keyword => ID
        foreach ($keywords as $keyword) {
            $keyword_strings[] = $keyword->primary_keyword;
            $keyword_map[$keyword->primary_keyword] = $keyword->id;
        }
        
        // Get settings for location and language
        $settings = get_option('mindfulseo_settings', array());
        $location_code = isset($settings['dataforseo_location']) ? $settings['dataforseo_location'] : '2840';
        $language_code = isset($settings['dataforseo_language']) ? $settings['dataforseo_language'] : 'en';
        
        // Batch process (100 keywords at a time)
        $batch_size = 100;
        $total_updated = 0;
        $total_keywords = count($keyword_strings);
        $batches = array_chunk($keyword_strings, $batch_size);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'mindfulseo_keywords';
        
        $keywords_with_data = 0;
        
        foreach ($batches as $batch_index => $batch) {
            // Get metrics for this batch
            $metrics = $connector->get_combined_metrics($batch, $location_code, $language_code);
            
            if (is_wp_error($metrics)) {
                // Log error but continue with next batch
                error_log('MindfulSEO: DataForSEO API Error for batch ' . ($batch_index + 1) . ': ' . $metrics->get_error_message());
                continue;
            }
            
            // Update database for each keyword in the batch
            foreach ($batch as $keyword_string) {
                if (!isset($metrics[$keyword_string]) || !isset($keyword_map[$keyword_string])) {
                    continue;
                }
                
                $keyword_id = $keyword_map[$keyword_string];
                $data = $metrics[$keyword_string];
                
                // Check if we got actual data (not just NULL values)
                $dataforseo_status = 'pending';
                $search_volume = isset($data['search_volume']) && $data['search_volume'] !== null ? $data['search_volume'] : null;
                $keyword_difficulty = isset($data['keyword_difficulty']) && $data['keyword_difficulty'] !== null ? $data['keyword_difficulty'] : null;
                $cpc_value = isset($data['cpc']) && $data['cpc'] !== null ? $data['cpc'] : null;
                
                if ($search_volume !== null || $keyword_difficulty !== null || $cpc_value !== null) {
                    $keywords_with_data++;
                    $dataforseo_status = 'success';
                } else {
                    $dataforseo_status = 'no_data';
                }
                
                $update_result = $wpdb->update(
                    $table_name,
                    array(
                        'search_volume' => $search_volume,
                        'keyword_difficulty' => $keyword_difficulty,
                        'cpc' => $cpc_value,
                        'seo_data_updated' => current_time('mysql'),
                        'dataforseo_status' => $dataforseo_status,
                    ),
                    array('id' => $keyword_id),
                    array('%d', '%d', '%f', '%s', '%s'),
                    array('%d')
                );
                
                if ($update_result !== false) {
                    $total_updated++;
                }
            }
        }
        
        // Build success message with warning if no data returned
        $message = sprintf(
            __('Successfully updated SEO data for %d out of %d keywords!', 'mindfulseo'),
            $total_updated,
            $total_keywords
        );
        
        if ($keywords_with_data === 0) {
            $message .= "\n\n" . __(
                '⚠️ Warning: DataForSEO returned empty results for all keywords. ' .
                'Please verify your account has credits and check your location settings.',
                'mindfulseo'
            );
        } elseif ($keywords_with_data < $total_keywords) {
            $message .= "\n\n" . sprintf(
                __('Note: Only %d keywords have data. Some keywords may not be in DataForSEO database.', 'mindfulseo'),
                $keywords_with_data
            );
        }
        
        wp_send_json_success(array(
            'message' => $message,
            'updated' => $total_updated,
            'total' => $total_keywords,
            'with_data' => $keywords_with_data,
        ));
    }
    
    /**
     * Apply AI Cleanup changes
     */
    public static function apply_cleanup_changes() {
        check_ajax_referer('mindfulseo_cleanup', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'mindfulseo'));
        }
        
        // Get suggestions from request
        $suggestions = isset($_POST['suggestions']) ? json_decode(stripslashes($_POST['suggestions']), true) : array();
        
        if (empty($suggestions)) {
            wp_send_json_error(__('No suggestions provided.', 'mindfulseo'));
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'mindfulseo_keywords';
        
        $stats = array(
            'deleted' => 0,
            'replaced' => 0,
            'merged' => 0,
            'errors' => array(),
        );
        
        error_log('MindfulSEO: Applying ' . count($suggestions) . ' cleanup changes');
        
        // Process each suggestion
        foreach ($suggestions as $suggestion) {
            $action = isset($suggestion['action']) ? $suggestion['action'] : '';
            $keyword_id = isset($suggestion['keyword_id']) ? intval($suggestion['keyword_id']) : 0;
            
            if (!$keyword_id || !$action) {
                continue;
            }
            
            switch ($action) {
                case 'delete':
                    // Delete the keyword
                    $result = $wpdb->delete(
                        $table_name,
                        array('id' => $keyword_id),
                        array('%d')
                    );
                    
                    if ($result !== false) {
                        $stats['deleted']++;
                        error_log('MindfulSEO: Deleted keyword ID ' . $keyword_id);
                    } else {
                        $stats['errors'][] = sprintf(__('Failed to delete keyword ID %d', 'mindfulseo'), $keyword_id);
                    }
                    break;
                    
                case 'replace':
                    // Update the keyword with improved version
                    $update_data = array();
                    
                    if (isset($suggestion['improved_primary'])) {
                        $update_data['primary_keyword'] = sanitize_text_field($suggestion['improved_primary']);
                    }
                    if (isset($suggestion['improved_longtail'])) {
                        $update_data['longtail_keyword'] = sanitize_text_field($suggestion['improved_longtail']);
                    }
                    if (isset($suggestion['improved_intent'])) {
                        $update_data['search_intent'] = sanitize_text_field($suggestion['improved_intent']);
                    }
                    if (isset($suggestion['improved_priority'])) {
                        $update_data['priority'] = sanitize_text_field($suggestion['improved_priority']);
                    }
                    
                    if (!empty($update_data)) {
                        $result = $wpdb->update(
                            $table_name,
                            $update_data,
                            array('id' => $keyword_id),
                            null,
                            array('%d')
                        );
                        
                        if ($result !== false) {
                            $stats['replaced']++;
                            error_log('MindfulSEO: Replaced keyword ID ' . $keyword_id . ' with: ' . print_r($update_data, true));
                        } else {
                            $stats['errors'][] = sprintf(__('Failed to replace keyword ID %d', 'mindfulseo'), $keyword_id);
                        }
                    }
                    break;
                    
                case 'merge':
                    // Delete this keyword (it will be merged into the target)
                    $result = $wpdb->delete(
                        $table_name,
                        array('id' => $keyword_id),
                        array('%d')
                    );
                    
                    if ($result !== false) {
                        $stats['merged']++;
                        error_log('MindfulSEO: Merged (deleted) keyword ID ' . $keyword_id);
                    } else {
                        $stats['errors'][] = sprintf(__('Failed to merge keyword ID %d', 'mindfulseo'), $keyword_id);
                    }
                    break;
            }
        }
        
        // Build success message
        $messages = array();
        if ($stats['replaced'] > 0) {
            $messages[] = sprintf(__('%d keyword(s) updated', 'mindfulseo'), $stats['replaced']);
        }
        if ($stats['merged'] > 0) {
            $messages[] = sprintf(__('%d keyword(s) merged', 'mindfulseo'), $stats['merged']);
        }
        if ($stats['deleted'] > 0) {
            $messages[] = sprintf(__('%d keyword(s) deleted', 'mindfulseo'), $stats['deleted']);
        }
        
        $message = !empty($messages) ? 
            __('Cleanup complete! ', 'mindfulseo') . implode(', ', $messages) . '.' :
            __('No changes were applied.', 'mindfulseo');
        
        if (!empty($stats['errors'])) {
            $message .= ' ' . sprintf(__('(%d errors occurred)', 'mindfulseo'), count($stats['errors']));
        }
        
        wp_send_json_success(array(
            'message' => $message,
            'stats' => $stats,
        ));
    }
    
    /**
     * Batch optimize a single post via AJAX
     */
    public static function batch_optimize_single() {
        check_ajax_referer('mindfulseo_batch_optimize', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Unauthorized', 'mindfulseo'));
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        
        if (!$post_id) {
            wp_send_json_error(__('Invalid post ID', 'mindfulseo'));
        }
        
        // Get optimizer instance
        $optimizer = MFSEO_Optimizer::get_instance();
        
        // Optimize the post
        $result = $optimizer->optimize_post($post_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        // Auto-approve and apply optimization for batch processing
        if (isset($result['optimization_id'])) {
            $apply_result = $optimizer->apply_optimization($result['optimization_id']);
            
            if (is_wp_error($apply_result)) {
                wp_send_json_error($apply_result->get_error_message());
            }
        }
        
        // Get the updated SEO data to return
        require_once MINDFULSEO_PLUGIN_DIR . 'includes/class-seo-plugin-adapter.php';
        $adapter = MFSEO_SEO_Plugin_Adapter::get_instance();
        $post = get_post($post_id);
        
        wp_send_json_success(array(
            'message' => __('Post optimized successfully', 'mindfulseo'),
            'post_id' => $post_id,
            'seo_data' => array(
                'keyword' => $adapter->get_focus_keyword($post_id) ?: '—',
                'title' => $adapter->get_seo_title($post_id) ?: '—',
                'description' => $adapter->get_meta_description($post_id) ?: '—',
                'slug' => $post ? $post->post_name : '—',
            ),
        ));
    }
    
    /**
     * Update post SEO data inline
     */
    public static function update_post_seo() {
        check_ajax_referer('mindfulseo_batch_optimize', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'mindfulseo')));
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $field = isset($_POST['field']) ? sanitize_text_field($_POST['field']) : '';
        $value = isset($_POST['value']) ? sanitize_textarea_field($_POST['value']) : '';
        
        if (!$post_id || !$field) {
            wp_send_json_error(array('message' => __('Invalid parameters', 'mindfulseo')));
        }
        
        // Get SEO adapter instance
        require_once MINDFULSEO_PLUGIN_DIR . 'includes/class-seo-plugin-adapter.php';
        $adapter = MFSEO_SEO_Plugin_Adapter::get_instance();
        
        // Update based on field
        $result = false;
        switch ($field) {
            case 'focus_keyword':
                $result = $adapter->set_focus_keyword($post_id, $value);
                break;
            case 'seo_title':
                $result = $adapter->set_seo_title($post_id, $value);
                break;
            case 'meta_description':
                $result = $adapter->set_meta_description($post_id, $value);
                break;
            case 'slug':
                // Update post slug
                $result = wp_update_post(array(
                    'ID' => $post_id,
                    'post_name' => sanitize_title($value)
                ));
                break;
            default:
                wp_send_json_error(array('message' => __('Invalid field', 'mindfulseo')));
        }
        
        if ($result !== false) {
            wp_send_json_success(array(
                'message' => __('Updated successfully', 'mindfulseo'),
                'value' => $value
            ));
        } else {
            wp_send_json_error(array('message' => __('Update failed', 'mindfulseo')));
        }
    }
    
    /**
     * Build smart cleanup prompt with full context
     * 
     * @param array $keyword_list Keywords to analyze
     * @param array $settings Plugin settings
     * @return string Comprehensive AI prompt
     */
    private static function build_smart_cleanup_prompt($keyword_list, $settings) {
        // ===================================
        // GATHER CONTEXTUAL INTELLIGENCE
        // ===================================
        
        // 1. Analyze site content to understand themes
        $content_analysis = self::analyze_site_content();
        
        // 2. Get language guidelines for style consistency
        $guidelines = self::get_language_guidelines_summary();
        
        // 3. Identify existing content gaps
        $content_coverage = self::analyze_keyword_content_coverage($keyword_list);
        
        // 4. Build the intelligent prompt
        $prompt = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $prompt .= "   EXPERT SEO KEYWORD STRATEGY OPTIMIZATION\n";
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $prompt .= "You are a world-class SEO strategist and keyword researcher with expertise in:\n";
        $prompt .= "• Search intent analysis and user behavior patterns\n";
        $prompt .= "• Long-tail keyword generation based on actual search trends\n";
        $prompt .= "• Content-keyword mapping and topical authority\n";
        $prompt .= "• Competitive keyword analysis and opportunity identification\n\n";
        
        $prompt .= "━━━ WEBSITE CONTEXT & CONTENT THEMES ━━━\n\n";
        $prompt .= "Site Focus: " . get_bloginfo('name') . "\n";
        $prompt .= "Description: " . get_bloginfo('description') . "\n\n";
        
        if (!empty($content_analysis['top_topics'])) {
            $prompt .= "📊 PRIMARY CONTENT THEMES (based on actual published content):\n";
            foreach ($content_analysis['top_topics'] as $topic => $count) {
                $prompt .= "   • {$topic}: {$count} posts\n";
            }
            $prompt .= "\n";
        }
        
        if (!empty($content_analysis['common_terms'])) {
            $prompt .= "🔑 FREQUENTLY USED TERMS IN CONTENT:\n";
            $prompt .= "   " . implode(', ', array_slice($content_analysis['common_terms'], 0, 20)) . "\n\n";
        }
        
        if (!empty($guidelines)) {
            $prompt .= "━━━ LANGUAGE & STYLE GUIDELINES ━━━\n\n";
            $prompt .= $guidelines . "\n\n";
            $prompt .= "✓ All longtail suggestions MUST align with these style guidelines\n";
            $prompt .= "✓ Use terminology that matches the site's voice and audience\n\n";
        }
        
        $prompt .= "━━━ CONTENT COVERAGE ANALYSIS ━━━\n\n";
        if (!empty($content_coverage['well_covered'])) {
            $prompt .= "✅ WELL-COVERED TOPICS (have supporting content):\n";
            foreach (array_slice($content_coverage['well_covered'], 0, 10) as $kw) {
                $prompt .= "   • \"{$kw['primary']}\" - {$kw['post_count']} related posts\n";
            }
            $prompt .= "\n";
        }
        
        if (!empty($content_coverage['gaps'])) {
            $prompt .= "⚠️ CONTENT GAPS (keywords without supporting content):\n";
            foreach (array_slice($content_coverage['gaps'], 0, 15) as $kw) {
                $prompt .= "   • \"{$kw['primary']}\" - 0 posts (consider if this should be deprioritized)\n";
            }
            $prompt .= "\n";
        }
        
        $prompt .= "━━━ KEYWORD OPTIMIZATION TASK ━━━\n\n";
        $prompt .= "Review the keywords below and provide HIGH-VALUE suggestions to:\n\n";
        
        $prompt .= "1. **IMPROVE LONGTAIL KEYWORDS** - Transform weak/generic longtails into:\n";
        $prompt .= "   ✓ Natural search phrases people ACTUALLY type\n";
        $prompt .= "   ✓ Question-based queries (how to, what is, where can, etc.)\n";
        $prompt .= "   ✓ Specific, actionable phrases that match search intent\n";
        $prompt .= "   ✓ Phrases that align with existing content themes above\n";
        $prompt .= "   \n";
        $prompt .= "   ❌ AVOID: Generic phrases like \"[keyword] guide\" or \"best [keyword]\"\n";
        $prompt .= "   ✅ PREFER: Specific, user-focused phrases that reflect real search behavior\n\n";
        
        $prompt .= "2. **MERGE DUPLICATES** - Consolidate similar/overlapping keywords:\n";
        $prompt .= "   • When merging A → B, ALSO suggest improved longtail for B if needed\n";
        $prompt .= "   • Keep the keyword with better search potential\n";
        $prompt .= "   • Consider search volume data if available\n\n";
        
        $prompt .= "3. **FIX SEARCH INTENT** - Correct misclassified intent:\n";
        $prompt .= "   • Informational: Learning/research queries\n";
        $prompt .= "   • Navigational: Looking for specific pages/brands\n";
        $prompt .= "   • Transactional: Ready to take action/purchase\n";
        $prompt .= "   • Commercial: Comparing options before decision\n\n";
        
        $prompt .= "4. **ADJUST PRIORITY** - Based on:\n";
        $prompt .= "   • Content coverage (prioritize keywords with existing content)\n";
        $prompt .= "   • Search intent alignment with site goals\n";
        $prompt .= "   • Topic relevance to primary themes\n\n";
        
        $prompt .= "5. **DELETE ONLY** truly useless keywords:\n";
        $prompt .= "   • Technical jargon (CSS, code, class names)\n";
        $prompt .= "   • Non-searchable terms\n";
        $prompt .= "   • Completely off-topic keywords\n\n";
        
        $prompt .= "━━━ KEYWORDS TO REVIEW ━━━\n\n";
        $prompt .= json_encode($keyword_list, JSON_PRETTY_PRINT) . "\n\n";
        
        $prompt .= "━━━ RESPONSE FORMAT ━━━\n\n";
        $prompt .= "Respond with a JSON array (NO markdown, NO explanation):\n\n";
        $prompt .= "[\n";
        $prompt .= "  {\n";
        $prompt .= "    \"id\": keyword_id (required),\n";
        $prompt .= "    \"issue\": \"Clear problem description\" (required),\n";
        $prompt .= "    \"action\": \"replace|delete|merge\" (required),\n";
        $prompt .= "    \"replacement\": {\n";
        $prompt .= "      \"primary\": \"improved primary (optional)\",\n";
        $prompt .= "      \"longtail\": \"improved longtail - MUST be natural, specific search phrase (optional)\",\n";
        $prompt .= "      \"intent\": \"Informational|Navigational|Transactional|Commercial (optional)\",\n";
        $prompt .= "      \"priority\": \"HIGH|MEDIUM|LOW (optional)\"\n";
        $prompt .= "    },\n";
        $prompt .= "    \"merge_with\": keyword_id_to_keep (required if action=merge),\n";
        $prompt .= "    \"reasoning\": \"SEO benefit + user value\" (required)\n";
        $prompt .= "  }\n";
        $prompt .= "]\n\n";
        
        $prompt .= "━━━ CRITICAL QUALITY STANDARDS ━━━\n\n";
        $prompt .= "✓ Longtails MUST sound like real search queries, not forced SEO phrases\n";
        $prompt .= "✓ Consider the site's actual content and themes shown above\n";
        $prompt .= "✓ Prioritize keywords that align with existing content coverage\n";
        $prompt .= "✓ When merging, check if target also needs improvement\n";
        $prompt .= "✓ Only suggest changes that meaningfully improve SEO value\n";
        $prompt .= "✓ Return [] if no significant improvements needed\n";
        $prompt .= "✓ Focus on QUALITY over QUANTITY - suggest 10-20 high-impact changes\n\n";
        
        return $prompt;
    }
    
    /**
     * Analyze site content to extract themes and topics
     * 
     * @return array Content analysis data
     */
    private static function analyze_site_content() {
        $analysis = array(
            'top_topics' => array(),
            'common_terms' => array()
        );
        
        // Get recent published posts
        $posts = get_posts(array(
            'post_type' => array('post', 'page'),
            'post_status' => 'publish',
            'posts_per_page' => 100,
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        if (empty($posts)) {
            return $analysis;
        }
        
        // Extract topics from categories and tags
        $topic_counts = array();
        $term_frequency = array();
        
        foreach ($posts as $post) {
            // Get categories
            $categories = get_the_category($post->ID);
            foreach ($categories as $cat) {
                if ($cat->name !== 'Uncategorized') {
                    $topic_counts[$cat->name] = isset($topic_counts[$cat->name]) ? $topic_counts[$cat->name] + 1 : 1;
                }
            }
            
            // Get tags
            $tags = get_the_tags($post->ID);
            if ($tags) {
                foreach ($tags as $tag) {
                    $topic_counts[$tag->name] = isset($topic_counts[$tag->name]) ? $topic_counts[$tag->name] + 1 : 1;
                }
            }
            
            // Extract common terms from titles
            $title = strtolower($post->post_title);
            $words = preg_split('/\s+/', $title);
            foreach ($words as $word) {
                $word = trim($word, '.,;:!?"\'()[]{}');
                if (strlen($word) > 4) { // Only words longer than 4 chars
                    $term_frequency[$word] = isset($term_frequency[$word]) ? $term_frequency[$word] + 1 : 1;
                }
            }
        }
        
        // Sort and limit
        arsort($topic_counts);
        arsort($term_frequency);
        
        $analysis['top_topics'] = array_slice($topic_counts, 0, 10);
        $analysis['common_terms'] = array_keys(array_slice($term_frequency, 0, 30));
        
        return $analysis;
    }
    
    /**
     * Get language guidelines summary
     * 
     * @return string Guidelines text
     */
    private static function get_language_guidelines_summary() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mindfulseo_language_rules';
        
        $rules = $wpdb->get_results("SELECT * FROM {$table_name} WHERE status = 'active' ORDER BY priority DESC LIMIT 10");
        
        if (empty($rules)) {
            return '';
        }
        
        $guidelines_text = '';
        foreach ($rules as $rule) {
            if ($rule->rule_type === 'preferred_term' && !empty($rule->preferred_term)) {
                $guidelines_text .= "• Prefer: \"{$rule->preferred_term}\"";
                if (!empty($rule->avoid_terms)) {
                    $avoid = json_decode($rule->avoid_terms, true);
                    if ($avoid) {
                        $guidelines_text .= " (avoid: " . implode(', ', array_slice($avoid, 0, 3)) . ")";
                    }
                }
                $guidelines_text .= "\n";
            }
        }
        
        return trim($guidelines_text);
    }
    
    /**
     * Analyze which keywords have supporting content
     * 
     * @param array $keyword_list Keywords to analyze
     * @return array Coverage analysis
     */
    private static function analyze_keyword_content_coverage($keyword_list) {
        $coverage = array(
            'well_covered' => array(),
            'gaps' => array()
        );
        
        foreach ($keyword_list as $kw) {
            $primary = $kw['primary'];
            
            // Search for posts containing this keyword
            $search_query = new WP_Query(array(
                's' => $primary,
                'post_type' => array('post', 'page'),
                'post_status' => 'publish',
                'posts_per_page' => 5,
                'fields' => 'ids'
            ));
            
            $post_count = $search_query->found_posts;
            
            $kw['post_count'] = $post_count;
            
            if ($post_count >= 2) {
                $coverage['well_covered'][] = $kw;
            } elseif ($post_count === 0) {
                $coverage['gaps'][] = $kw;
            }
        }
        
        // Sort by post count
        usort($coverage['well_covered'], function($a, $b) {
            return $b['post_count'] - $a['post_count'];
        });
        
        return $coverage;
    }
    
    /**
     * Refresh metrics for batch optimizer - fetch data for keywords from the current page
     * Only fetches from DataForSEO if data is missing or older than 30 days
     */
    public static function recalculate_rankmath_scores() {
        check_ajax_referer('mindfulseo_batch_optimize', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'mindfulseo')));
        }
        
        // Get keywords from POST data (sent from JavaScript)
        $keywords_from_page = isset($_POST['keywords']) ? $_POST['keywords'] : array();
        
        if (empty($keywords_from_page) || !is_array($keywords_from_page)) {
            wp_send_json_error(array('message' => __('No keywords provided', 'mindfulseo')));
        }
        
        // Check if DataForSEO is configured
        if (!class_exists('MFSEO_DataForSEO_Connector')) {
            wp_send_json_error(array('message' => __('DataForSEO connector not available', 'mindfulseo')));
        }
        
        $connector = MFSEO_DataForSEO_Connector::get_instance();
        
        if (!$connector->is_configured()) {
            wp_send_json_error(array('message' => __('DataForSEO API not configured. Please add your credentials in Settings.', 'mindfulseo')));
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'mindfulseo_keywords';
        
        // Sanitize keywords
        $keywords_to_check = array();
        foreach ($keywords_from_page as $keyword) {
            $clean_keyword = sanitize_text_field($keyword);
            if (!empty($clean_keyword)) {
                $keywords_to_check[] = $clean_keyword;
            }
        }
        
        if (empty($keywords_to_check)) {
            wp_send_json_error(array('message' => __('No valid keywords provided', 'mindfulseo')));
        }
        
        // Check which keywords need refreshing (missing data OR older than 30 days)
        $thirty_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));
        $placeholders = implode(', ', array_fill(0, count($keywords_to_check), '%s'));
        
        $existing_keywords = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, primary_keyword, search_volume, keyword_difficulty, cpc, seo_data_updated
                 FROM {$table_name}
                 WHERE primary_keyword IN ($placeholders)",
                $keywords_to_check
            ),
            OBJECT_K
        );
        
        // Separate keywords into: need_fetch, already_fresh
        $keywords_to_fetch = array();
        $keyword_map = array(); // Map keyword => existing ID
        $fresh_count = 0;
        
        foreach ($keywords_to_check as $keyword) {
            $existing = null;
            
            // Find existing keyword (case-insensitive)
            foreach ($existing_keywords as $row) {
                if (strcasecmp($row->primary_keyword, $keyword) === 0) {
                    $existing = $row;
                    break;
                }
            }
            
            if (!$existing) {
                // Keyword doesn't exist - need to fetch
                $keywords_to_fetch[] = $keyword;
            } elseif (empty($existing->seo_data_updated) || $existing->seo_data_updated < $thirty_days_ago) {
                // Data is missing or old - need to fetch
                $keywords_to_fetch[] = $keyword;
                $keyword_map[$keyword] = $existing->id;
            } elseif ($existing->search_volume === null && $existing->keyword_difficulty === null) {
                // Has timestamp but no actual data - need to fetch
                $keywords_to_fetch[] = $keyword;
                $keyword_map[$keyword] = $existing->id;
            } else {
                // Data is fresh - don't fetch
                $keyword_map[$keyword] = $existing->id;
                $fresh_count++;
            }
        }
        
        // If no keywords need fetching, we're done
        if (empty($keywords_to_fetch)) {
            wp_send_json_success(array(
                'message' => sprintf(
                    __('All %d keywords are up to date (refreshed within 30 days). Page will reload to show current data.', 'mindfulseo'),
                    $fresh_count
                ),
                'keywords_refreshed' => 0,
                'keywords_fresh' => $fresh_count,
                'reload' => true
            ));
            return;
        }
        
        // Get settings for location and language
        $settings = get_option('mindfulseo_settings', array());
        $location_code = isset($settings['dataforseo_location']) ? $settings['dataforseo_location'] : '2840';
        $language_code = isset($settings['dataforseo_language']) ? $settings['dataforseo_language'] : 'en';
        
        // Fetch metrics from DataForSEO for keywords that need it
        error_log('MindfulSEO: Fetching metrics for ' . count($keywords_to_fetch) . ' keywords');
        error_log('MindfulSEO: Keywords to fetch: ' . print_r($keywords_to_fetch, true));
        
        $metrics = $connector->get_combined_metrics($keywords_to_fetch, $location_code, $language_code);
        
        error_log('MindfulSEO: DataForSEO returned metrics for ' . count($metrics) . ' keywords');
        error_log('MindfulSEO: First 3 metrics: ' . print_r(array_slice($metrics, 0, 3, true), true));
        
        if (is_wp_error($metrics)) {
            error_log('MindfulSEO: DataForSEO API Error: ' . $metrics->get_error_message());
            wp_send_json_error(array(
                'message' => __('DataForSEO API Error: ', 'mindfulseo') . $metrics->get_error_message()
            ));
            return;
        }
        
        // Update or insert keywords in database
        $total_updated = 0;
        $total_inserted = 0;
        $keywords_with_data = 0;
        
        foreach ($keywords_to_fetch as $keyword_string) {
            // Determine dataforseo_status and metrics
            $dataforseo_status = 'pending';
            $search_volume = null;
            $difficulty = null;
            $cpc = null;
            
            if (isset($metrics[$keyword_string])) {
                $data = $metrics[$keyword_string];
                $search_volume = isset($data['search_volume']) && $data['search_volume'] !== null ? intval($data['search_volume']) : null;
                $difficulty = isset($data['keyword_difficulty']) && $data['keyword_difficulty'] !== null ? intval($data['keyword_difficulty']) : null;
                $cpc = isset($data['cpc']) && $data['cpc'] !== null ? floatval($data['cpc']) : null;
                
                // Check if we got actual data (not just NULL values)
                if ($search_volume !== null || $difficulty !== null || $cpc !== null) {
                    $keywords_with_data++;
                    $dataforseo_status = 'success';
                } else {
                    $dataforseo_status = 'no_data';
                }
            } else {
                // API returned nothing for this keyword
                $dataforseo_status = 'error';
            }
            
            if (isset($keyword_map[$keyword_string])) {
                // Update existing keyword
                $update_result = $wpdb->update(
                    $table_name,
                    array(
                        'search_volume' => $search_volume,
                        'keyword_difficulty' => $difficulty,
                        'cpc' => $cpc,
                        'seo_data_updated' => current_time('mysql'),
                        'dataforseo_status' => $dataforseo_status,
                    ),
                    array('id' => $keyword_map[$keyword_string]),
                    array('%d', '%d', '%f', '%s', '%s'),
                    array('%d')
                );
                
                if ($update_result !== false) {
                    $total_updated++;
                }
            } else {
                // Insert new keyword - ALWAYS insert even with NULL values
                $insert_result = $wpdb->insert(
                    $table_name,
                    array(
                        'primary_keyword' => $keyword_string,
                        'longtail_keyword' => $keyword_string,
                        'search_volume' => $search_volume,
                        'keyword_difficulty' => $difficulty,
                        'cpc' => $cpc,
                        'priority' => 'MEDIUM',
                        'search_intent' => 'Informational',
                        'seo_data_updated' => current_time('mysql'),
                        'dataforseo_status' => $dataforseo_status,
                        'created_date' => current_time('mysql'),
                    ),
                    array('%s', '%s', '%d', '%d', '%f', '%s', '%s', '%s', '%s', '%s')
                );
                
                if ($insert_result) {
                    $total_inserted++;
                }
            }
        }
        
        $message = sprintf(
            __('%d keywords refreshed from DataForSEO, %d already up to date. Page will reload.', 'mindfulseo'),
            $total_updated + $total_inserted,
            $fresh_count
        );
        
        // Add warning if no data was returned
        if ($keywords_with_data === 0 && ($total_updated > 0 || $total_inserted > 0)) {
            $message .= "\n\n" . __(
                '⚠️ Warning: DataForSEO returned empty results for all keywords. ' .
                'Please verify your account has credits and check your location settings.',
                'mindfulseo'
            );
        } elseif ($keywords_with_data < ($total_updated + $total_inserted)) {
            $message .= "\n\n" . sprintf(
                __('Note: Only %d out of %d keywords have data. Some keywords may not be in DataForSEO database.', 'mindfulseo'),
                $keywords_with_data,
                ($total_updated + $total_inserted)
            );
        }
        
        wp_send_json_success(array(
            'message' => $message,
            'keywords_refreshed' => $total_updated + $total_inserted,
            'keywords_fresh' => $fresh_count,
            'with_data' => $keywords_with_data,
            'reload' => true
        ));
    }
    
    /**
     * Analyze site rankings using DataForSEO keywords_for_site endpoint
     * This populates current_rank and ranking_url for existing keywords
     */
    public static function analyze_site_rankings() {
        check_ajax_referer('mindfulseo_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'mindfulseo')));
        }
        
        // Get domain from site URL
        $site_url = get_site_url();
        $parsed_url = parse_url($site_url);
        $domain = isset($parsed_url['host']) ? $parsed_url['host'] : '';
        
        // Remove www. prefix if present
        $domain = preg_replace('/^www\./', '', $domain);
        
        if (empty($domain)) {
            wp_send_json_error(array(
                'message' => __('Unable to determine site domain.', 'mindfulseo')
            ));
        }
        
        // Get DataForSEO connector
        if (!class_exists('MFSEO_DataForSEO_Connector')) {
            wp_send_json_error(array(
                'message' => __('DataForSEO connector not available.', 'mindfulseo')
            ));
        }
        
        $connector = MFSEO_DataForSEO_Connector::get_instance();
        
        // Get keywords for site
        $result = $connector->get_keywords_for_site($domain);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => sprintf(
                    __('DataForSEO error: %s', 'mindfulseo'),
                    $result->get_error_message()
                )
            ));
        }
        
        if (empty($result) || !is_array($result)) {
            wp_send_json_error(array(
                'message' => __('No ranking data returned from DataForSEO. Your site may not have enough data yet.', 'mindfulseo')
            ));
        }
        
        // Log what we received for debugging
        error_log('MindfulSEO: DataForSEO returned ' . count($result) . ' ranking keywords');
        if (count($result) > 0) {
            error_log('MindfulSEO: First 3 keywords from DataForSEO: ' . print_r(array_slice($result, 0, 3), true));
        }
        
        // Get keyword manager
        if (!class_exists('MFSEO_Keyword_Manager')) {
            wp_send_json_error(array(
                'message' => __('Keyword manager not available.', 'mindfulseo')
            ));
        }
        
        $keyword_manager = MFSEO_Keyword_Manager::get_instance();
        
        // Get all keywords from database
        global $wpdb;
        $table_name = $wpdb->prefix . 'mindfulseo_keywords';
        $db_keywords = $wpdb->get_results(
            "SELECT id, longtail_keyword FROM {$table_name}",
            OBJECT_K
        );
        
        error_log('MindfulSEO: Have ' . count($db_keywords) . ' keywords in database');
        if (count($db_keywords) > 0) {
            $first_5 = array_slice($db_keywords, 0, 5);
            $keyword_names = array_map(function($k) { return $k->longtail_keyword; }, $first_5);
            error_log('MindfulSEO: First 5 DB keywords: ' . implode(', ', $keyword_names));
        }
        
        $updated_count = 0;
        $new_keywords_found = 0;
        
        // Process each ranking keyword from DataForSEO
        foreach ($result as $item) {
            $keyword = isset($item['keyword']) ? trim($item['keyword']) : '';
            $rank = isset($item['rank_absolute']) ? intval($item['rank_absolute']) : null;
            $ranking_url = isset($item['url']) ? esc_url_raw($item['url']) : null;
            $search_volume = isset($item['search_volume']) ? intval($item['search_volume']) : null;
            
            if (empty($keyword) || $rank === null) {
                continue;
            }
            
            // Find if this keyword exists in our database
            $keyword_id = null;
            foreach ($db_keywords as $id => $row) {
                if (strtolower($row->longtail_keyword) === strtolower($keyword)) {
                    $keyword_id = $id;
                    break;
                }
            }
            
            if ($keyword_id) {
                // Update existing keyword with ranking data
                $wpdb->update(
                    $table_name,
                    array(
                        'current_rank' => $rank,
                        'ranking_url' => $ranking_url,
                        'seo_data_updated' => current_time('mysql'),
                    ),
                    array('id' => $keyword_id),
                    array('%d', '%s', '%s'),
                    array('%d')
                );
                $updated_count++;
            } else {
                // This is a new keyword the site ranks for but isn't in our database yet
                $new_keywords_found++;
            }
        }
        
        $message = sprintf(
            __('✅ Domain analysis complete! DataForSEO returned %d keywords. Updated ranking data for %d keywords in your list.', 'mindfulseo'),
            count($result),
            $updated_count
        );
        
        if ($new_keywords_found > 0) {
            $message .= ' ' . sprintf(
                __('Found %d additional keywords your site ranks for (not yet in keyword list).', 'mindfulseo'),
                $new_keywords_found
            );
        }
        
        if ($updated_count === 0 && count($result) > 0) {
            $message .= ' ' . __(
                '⚠️ None of the keywords from DataForSEO matched your keyword list. This likely means the keywords you rank for are different from what you have in your strategy.',
                'mindfulseo'
            );
        }
        
        wp_send_json_success(array(
            'message' => $message,
            'updated' => $updated_count,
            'new_found' => $new_keywords_found,
            'reload' => true
        ));
    }
    
    /**
     * Save custom AI prompt
     * 
     * @since 1.3.0
     */
    public static function save_custom_prompt() {
        check_ajax_referer('mindfulseo_ajax', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'mindfulseo')));
        }
        
        $prompt_type = isset($_POST['prompt_type']) ? sanitize_key($_POST['prompt_type']) : '';
        $prompt_value = isset($_POST['prompt_value']) ? wp_kses_post($_POST['prompt_value']) : '';
        
        if (empty($prompt_type)) {
            wp_send_json_error(array('message' => __('Invalid prompt type', 'mindfulseo')));
        }
        
        // Valid prompt types
        $valid_types = array('batch_optimizer', 'keyword_generation', 'guideline_generation');
        if (!in_array($prompt_type, $valid_types)) {
            wp_send_json_error(array('message' => __('Invalid prompt type', 'mindfulseo')));
        }
        
        // Get settings
        $settings = get_option('mindfulseo_settings', array());
        
        // Update the specific prompt
        $settings[$prompt_type . '_prompt'] = $prompt_value;
        
        // Save settings
        update_option('mindfulseo_settings', $settings);
        
        wp_send_json_success(array(
            'message' => __('Custom prompt saved successfully', 'mindfulseo'),
            'prompt_type' => $prompt_type
        ));
    }
}

// Initialize AJAX handlers
MFSEO_AJAX_Handlers::init();

