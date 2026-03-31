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
        add_action('wp_ajax_mindfulseo_test_openrouter', array(__CLASS__, 'test_openrouter_connection'));
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
        
        // Dashboard (v2.0)
        add_action('wp_ajax_mindfulseo_refresh_dashboard', array(__CLASS__, 'refresh_dashboard'));
        
        // Content Hub (v2.0)
        add_action('wp_ajax_mindfulseo_refresh_clusters', array(__CLASS__, 'refresh_clusters'));
        add_action('wp_ajax_mindfulseo_suggest_pillar', array(__CLASS__, 'suggest_pillar'));
        add_action('wp_ajax_mindfulseo_generate_gap_suggestions', array(__CLASS__, 'generate_gap_suggestions'));
        add_action('wp_ajax_mindfulseo_analyze_internal_links', array(__CLASS__, 'analyze_internal_links'));
        add_action('wp_ajax_mindfulseo_scan_broken_links', array(__CLASS__, 'scan_broken_links'));
    }
    
    /**
     * Test OpenAI connection via AJAX
     */
    public static function test_openai_connection() {
        // Swallow any stray PHP notices/warnings that would corrupt the JSON response
        ob_start();

        check_ajax_referer('mindfulseo_test_api', 'nonce');

        if (!current_user_can('manage_options')) {
            ob_end_clean();
            wp_send_json_error(array('message' => __('Unauthorized', 'mindfulseo')));
        }

        $api_key    = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        $model      = isset($_POST['model'])   ? sanitize_text_field($_POST['model'])   : 'gpt-4o';
        $api_key    = is_string($api_key) ? $api_key : '';
        $using_saved = false;

        if (empty($api_key) || strpos($api_key, '•') !== false) {
            $using_saved = true;
            $settings = get_option('mindfulseo_settings', array());
            $api_key  = isset($settings['openai_api_key']) ? $settings['openai_api_key'] : '';
            if (!empty($api_key) && class_exists('MFSEO_AI_Connector')) {
                $api_key = MFSEO_AI_Connector::get_instance()->decrypt_api_key($api_key);
            }
        }

        if (empty($api_key)) {
            ob_end_clean();
            wp_send_json_error(array(
                'message' => __('No API key found. Save your key in Settings first, then test.', 'mindfulseo'),
                'code'    => 'no_key',
            ));
        }

        $result = MFSEO_API_Tester::test_openai_connection($api_key, $model);

        if (is_wp_error($result) && $using_saved) {
            $result = new WP_Error(
                $result->get_error_code(),
                $result->get_error_message() . ' — testing saved key. If you just changed it, click Save Settings first.'
            );
        }

        ob_end_clean();

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
            ));
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * Test Claude connection via AJAX
     */
    public static function test_claude_connection() {
        // Swallow any stray PHP notices/warnings that would corrupt the JSON response
        ob_start();

        check_ajax_referer('mindfulseo_test_api', 'nonce');

        if (!current_user_can('manage_options')) {
            ob_end_clean();
            wp_send_json_error(array('message' => __('Unauthorized', 'mindfulseo')));
        }

        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        $model   = isset($_POST['model'])   ? sanitize_text_field($_POST['model'])   : 'claude-sonnet-4-5';
        $api_key = is_string($api_key) ? $api_key : '';

        if (empty($api_key) || strpos($api_key, '•') !== false) {
            $settings = get_option('mindfulseo_settings', array());
            $api_key  = isset($settings['claude_api_key']) ? $settings['claude_api_key'] : '';
            if (!empty($api_key) && class_exists('MFSEO_AI_Connector')) {
                $api_key = MFSEO_AI_Connector::get_instance()->decrypt_api_key($api_key);
            }
        }

        if (empty($api_key)) {
            ob_end_clean();
            wp_send_json_error(array(
                'message' => __('No API key found. Save your key in Settings first, then test.', 'mindfulseo'),
                'code'    => 'no_key',
            ));
        }

        $result = MFSEO_API_Tester::test_claude_connection($api_key, $model);

        ob_end_clean();

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
            ));
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * Test OpenRouter via AJAX
     */
    public static function test_openrouter_connection() {
        ob_start();
        check_ajax_referer('mindfulseo_test_api', 'nonce');
        if (!current_user_can('manage_options')) {
            ob_end_clean();
            wp_send_json_error(array('message' => __('Unauthorized', 'mindfulseo')));
        }
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        $model   = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : 'qwen/qwen3.5-flash-02-23';
        if (empty($api_key) || strpos($api_key, '•') !== false) {
            $settings = get_option('mindfulseo_settings', array());
            $api_key  = isset($settings['openrouter_api_key']) ? $settings['openrouter_api_key'] : '';
            if (!empty($api_key) && class_exists('MFSEO_AI_Connector')) {
                $api_key = MFSEO_AI_Connector::get_instance()->decrypt_api_key($api_key);
            }
        }
        if (empty($api_key)) {
            ob_end_clean();
            wp_send_json_error(array('message' => __('No OpenRouter key. Save Settings first.', 'mindfulseo')));
        }
        $result = MFSEO_API_Tester::test_openrouter_connection($api_key, $model);
        ob_end_clean();
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message(), 'code' => $result->get_error_code()));
        }
        wp_send_json_success($result);
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
        $claude_model = isset($settings['claude_model']) ? $settings['claude_model'] : 'claude-sonnet-4-5';
        
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
        
        @set_time_limit(300);
        
        $post_types = isset($_POST['post_types']) ? array_map('sanitize_text_field', $_POST['post_types']) : array('post');
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 50;
        $deep_analysis = !empty($_POST['deep_analysis']);
        
        try {
            $analyzer = new MFSEO_Content_Analyzer();
            $suggestions = $analyzer->analyze_for_keywords(array(
                'post_types' => $post_types,
                'limit' => $limit,
                'deep_analysis' => $deep_analysis,
                'ai_usage_context' => 'keywords_page_autogenerate',
            ));
            
            if (is_wp_error($suggestions)) {
                wp_send_json_error(array('message' => $suggestions->get_error_message()));
            }
            
            if (empty($suggestions)) {
                wp_send_json_error(array(
                    'message' => __('No keywords found. Try analyzing more posts or different post types.', 'mindfulseo')
                ));
            }
            
            $keyword_manager = MFSEO_Keyword_Manager::get_instance();
            $imported = 0;
            $skipped = 0;
            
            foreach ($suggestions as $suggestion) {
                $result = $keyword_manager->add_keyword(array(
                    'primary_keyword' => $suggestion['primary_keyword'],
                    'longtail_keyword' => isset($suggestion['longtail_keyword']) ? $suggestion['longtail_keyword'] : '',
                    'search_intent' => isset($suggestion['search_intent']) ? $suggestion['search_intent'] : '',
                    'priority' => isset($suggestion['priority']) ? $suggestion['priority'] : 'Medium',
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
            } else {
                $message = __('All suggested keywords already exist in your database.', 'mindfulseo');
            }
            
            wp_send_json_success(array(
                'message' => $message,
                'imported' => $imported,
                'skipped' => $skipped,
                'total' => count($suggestions)
            ));
        } catch (\Throwable $e) {
            error_log('MindfulSEO autogenerate_keywords error: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => __('Server error: ', 'mindfulseo') . $e->getMessage()
            ));
        }
    }
    
    /**
     * Auto-generate guidelines via AJAX
     */
    public static function autogenerate_guidelines() {
        check_ajax_referer('mindfulseo_autogenerate', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'mindfulseo')));
        }
        
        @set_time_limit(300);
        
        $post_types = isset($_POST['post_types']) ? array_map('sanitize_text_field', $_POST['post_types']) : array('post');
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 100;
        
        try {
            $guidelines_engine = class_exists('MFSEO_Guidelines_Engine') ? MFSEO_Guidelines_Engine::get_instance() : null;
            if (!$guidelines_engine) {
                wp_send_json_error(array('message' => __('Guidelines engine not available.', 'mindfulseo')));
            }

            $manual_gl = $guidelines_engine->get_editor_policy_snapshot_text();
            $wizard_gl_payload = '';
            if ($manual_gl !== '') {
                $wizard_gl_payload = "=== USER-DEFINED AND IMPORTED RULES (authoritative — never contradict; extend with complementary rules only) ===\n" . $manual_gl;
            }

            $analyzer = new MFSEO_Content_Analyzer();
            $suggestions = $analyzer->analyze_for_guidelines(array(
                'post_types' => $post_types,
                'limit' => $limit,
                'wizard_guidelines_snapshot' => $wizard_gl_payload,
                'ai_usage_context' => 'guidelines_page_autogenerate',
            ));

            if (is_wp_error($suggestions)) {
                wp_send_json_error(array('message' => $suggestions->get_error_message()));
            }

            if (empty($suggestions)) {
                wp_send_json_error(array(
                    'message' => __('No patterns found. Try analyzing more posts or different post types.', 'mindfulseo')
                ));
            }

            $imported = 0;

            $ai_cap_lower = array();
            if (!empty($suggestions['ai_guidelines'])) {
                foreach ($suggestions['ai_guidelines'] as $ar) {
                    if (isset($ar['type']) && $ar['type'] === 'capitalize' && !empty($ar['preferred'])) {
                        $ai_cap_lower[strtolower($ar['preferred'])] = true;
                    }
                }
            }

            // AI-generated guidelines first
            if (!empty($suggestions['ai_guidelines'])) {
                $ai_rule_types = array('avoid_term', 'capitalize', 'preferred_term', 'seo_friendly');
                foreach ($suggestions['ai_guidelines'] as $ai_rule) {
                    $rule_type = isset($ai_rule['type']) ? sanitize_key($ai_rule['type']) : '';
                    $avoid     = isset($ai_rule['avoid']) ? $ai_rule['avoid'] : '';
                    $preferred = isset($ai_rule['preferred']) ? $ai_rule['preferred'] : '';
                    $context   = !empty($ai_rule['context']) ? $ai_rule['context'] : 'AI-generated';

                    if (!in_array($rule_type, $ai_rule_types, true) || $preferred === '') {
                        continue;
                    }

                    if ($rule_type === 'capitalize' && $avoid === '') {
                        $avoid = strtolower($preferred);
                    }

                    $result = $guidelines_engine->add_rule(array(
                        'rule_type' => $rule_type,
                        'avoid_term' => $avoid,
                        'preferred_term' => $preferred,
                        'context' => $context,
                        'guideline_source' => 'AI-generated',
                        'active' => true,
                    ));
                    if (!is_wp_error($result)) {
                        $imported++;
                    }
                }
            }

            // Pattern capitalize after AI, capped
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
                        'active' => true,
                    ));
                    if (!is_wp_error($result)) {
                        $imported++;
                        $added_pat++;
                    }
                }
            }

            if (empty($suggestions['ai_succeeded'])) {
                if (!empty($suggestions['preferred_terms'])) {
                    foreach ($suggestions['preferred_terms'] as $term) {
                        $result = $guidelines_engine->add_rule(array(
                            'rule_type' => 'preferred_term',
                            'avoid_term' => '',
                            'preferred_term' => $term,
                            'context' => 'Auto-generated from content analysis',
                            'guideline_source' => 'Auto-generated',
                            'active' => true,
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
                            'active' => true,
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
                            'active' => true,
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
                            'active' => true,
                        ));
                        if (!is_wp_error($result)) {
                            $imported++;
                        }
                    }
                }
            }
            
            if ($imported > 0) {
                $message = sprintf(__('Successfully analyzed content and imported %d guideline suggestions!', 'mindfulseo'), $imported);
            } else {
                $message = __('Analysis complete but no new guidelines were generated. Your content patterns may already be covered.', 'mindfulseo');
            }
            
            wp_send_json_success(array(
                'message' => $message,
                'imported' => $imported
            ));
        } catch (\Throwable $e) {
            error_log('MindfulSEO autogenerate_guidelines error: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => __('Server error: ', 'mindfulseo') . $e->getMessage()
            ));
        }
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

        // If group_ids are provided (parent row edit), update all rows in the group
        $group_ids = isset($_POST['group_ids']) ? $_POST['group_ids'] : '';
        if (!empty($group_ids) && $field === 'primary_keyword') {
            $ids = array_filter(array_map('intval', explode(',', $group_ids)));
            $updated = 0;
            foreach ($ids as $gid) {
                if ($keyword_manager->update_keyword($gid, array($field => $value))) {
                    $updated++;
                }
            }
            wp_send_json_success(array(
                'message' => sprintf(__('Updated %d keywords', 'mindfulseo'), $updated),
                'value' => $value
            ));
            return;
        }

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
            $response = $ai_connector->generate_content($prompt, array(
                'max_tokens' => 4000,
                'usage_context' => 'keyword_strategy_ai_cleanup',
            ));
            error_log('MindfulSEO: AI response received');
            
            if (is_wp_error($response)) {
                error_log('MindfulSEO: AI returned error: ' . $response->get_error_message());
                wp_send_json_error(array('message' => $response->get_error_message()));
            }
            
            // Parse AI response
            error_log('MindfulSEO: Parsing AI response');
            
            // Ensure response is a string (PHP 8.x compatibility)
            $response = is_string($response) ? $response : '';
            
            // Strip markdown code blocks if present
            // PHP 8.x: Ensure $response is a string
            $response = is_string($response) ? trim($response) : '';
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
        
        // PHP 8.x: Ensure strings before strpos
        $login = is_string($login) ? $login : '';
        $password = is_string($password) ? $password : '';
        
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
        
        // Check if DataForSEO is configured (check settings FIRST before initializing connector)
        $settings = get_option('mindfulseo_settings', array());
        $dataforseo_configured = !empty($settings['dataforseo_login']) && !empty($settings['dataforseo_password']);
        
        if (!$dataforseo_configured) {
            error_log('MindfulSEO: DataForSEO not configured - returning info message');
            wp_send_json_success(array(
                'message' => __(
                    'ℹ️ DataForSEO is not set up yet. Keywords will work without metrics. ' .
                    'To add search volume and difficulty data, configure DataForSEO in the Setup Wizard (Step 2) or in Settings → API Configuration.',
                    'mindfulseo'
                ),
                'updated' => 0,
                'total' => 0,
                'with_data' => 0,
                'info_only' => true
            ));
        }
        
        if (!class_exists('MFSEO_DataForSEO_Connector')) {
            error_log('MindfulSEO: DataForSEO connector class not found');
            wp_send_json_error(array('message' => __('DataForSEO connector not available', 'mindfulseo')));
        }
        
        error_log('MindfulSEO: Getting DataForSEO connector instance');
        $connector = MFSEO_DataForSEO_Connector::get_instance();
        
        if (!$connector->is_configured()) {
            error_log('MindfulSEO: DataForSEO connector reports not configured');
            wp_send_json_error(array('message' => __('DataForSEO API configuration error. Please check your credentials in Settings.', 'mindfulseo')));
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
        
        // Collect ALL unique keywords (both primary and longtail) for API lookup.
        // Each row's DB columns will store its longtail's metrics.
        // Primary keyword metrics are saved separately in a WP option for the grouped table.
        $all_strings    = array();   // deduped list of all keyword strings to send
        $all_seen       = array();   // lowercase => original string (dedup tracker)
        $longtail_to_ids = array();  // lowercase longtail => array of row IDs
        $primary_ids     = array();  // lowercase primary => true (just track which are primaries)

        foreach ($keywords as $keyword) {
            $pk = trim($keyword->primary_keyword);
            $lt = trim($keyword->longtail_keyword);
            if (empty($pk) && empty($lt)) {
                continue;
            }

            // Track the longtail (or primary as fallback) for this row's DB update
            $row_kw = !empty($lt) ? $lt : $pk;
            $row_key = strtolower($row_kw);
            if (!isset($longtail_to_ids[$row_key])) {
                $longtail_to_ids[$row_key] = array();
            }
            $longtail_to_ids[$row_key][] = $keyword->id;

            // Add both primary and longtail to the combined deduped list
            if (!empty($pk)) {
                $pk_key = strtolower($pk);
                $primary_ids[$pk_key] = true;
                if (!isset($all_seen[$pk_key])) {
                    $all_seen[$pk_key] = $pk;
                    $all_strings[] = $pk;
                }
            }
            if (!empty($lt) && strtolower($lt) !== strtolower($pk)) {
                $lt_key = strtolower($lt);
                if (!isset($all_seen[$lt_key])) {
                    $all_seen[$lt_key] = $lt;
                    $all_strings[] = $lt;
                }
            }
        }

        // Get settings for location and language
        $settings = get_option('mindfulseo_settings', array());
        $location_code = isset($settings['dataforseo_location']) ? $settings['dataforseo_location'] : '2840';
        $language_code = isset($settings['dataforseo_language']) ? $settings['dataforseo_language'] : 'en';

        $batch_size = 700;
        $total_updated = 0;
        $total_keywords = count($all_strings);
        $batches = array_chunk($all_strings, $batch_size);

        global $wpdb;
        $table_name = $wpdb->prefix . 'mindfulseo_keywords';

        $keywords_with_data = 0;
        $all_metrics_lower = array(); // accumulate all results for primary option + Labs fallback

        foreach ($batches as $batch_index => $batch) {
            $metrics = $connector->get_combined_metrics($batch, $location_code, $language_code);

            if (is_wp_error($metrics)) {
                error_log('MindfulSEO: DataForSEO API Error for batch ' . ($batch_index + 1) . ': ' . $metrics->get_error_message());
                continue;
            }

            if (is_array($metrics)) {
                foreach ($metrics as $mk => $mv) {
                    $all_metrics_lower[strtolower($mk)] = $mv;
                }
            }
        }

        // --- Labs fallback for keywords with no data ---
        $no_data_strings = array();
        foreach ($all_seen as $lk => $orig) {
            if (!isset($all_metrics_lower[$lk])) {
                $no_data_strings[] = $orig;
            } else {
                $d = $all_metrics_lower[$lk];
                $has = (isset($d['search_volume']) && $d['search_volume'] !== null)
                    || (isset($d['keyword_difficulty']) && $d['keyword_difficulty'] !== null)
                    || (isset($d['cpc']) && $d['cpc'] !== null);
                if (!$has) {
                    $no_data_strings[] = $orig;
                }
            }
        }

        if (!empty($no_data_strings)) {
            error_log('MindfulSEO: ' . count($no_data_strings) . ' keywords with no data — trying Labs fallback');
            $labs_batches = array_chunk($no_data_strings, 700);

            foreach ($labs_batches as $labs_batch) {
                $labs_metrics = $connector->get_keyword_overview_labs($labs_batch, $location_code, $language_code);
                if (is_wp_error($labs_metrics)) {
                    error_log('MindfulSEO: Labs fallback error: ' . $labs_metrics->get_error_message());
                    continue;
                }
                if (is_array($labs_metrics)) {
                    foreach ($labs_metrics as $lmk => $lmv) {
                        $key = strtolower($lmk);
                        if (!isset($all_metrics_lower[$key]) || (
                            ($all_metrics_lower[$key]['search_volume'] ?? null) === null &&
                            ($all_metrics_lower[$key]['keyword_difficulty'] ?? null) === null &&
                            ($all_metrics_lower[$key]['cpc'] ?? null) === null
                        )) {
                            $all_metrics_lower[$key] = $lmv;
                        }
                    }
                }
            }
        }

        // --- Update each row's DB columns with its LONGTAIL keyword's metrics ---
        $now = current_time('mysql');
        foreach ($longtail_to_ids as $lt_key => $row_ids) {
            $sv = null; $kd = null; $cpc_val = null;
            $status = 'no_data';

            if (isset($all_metrics_lower[$lt_key])) {
                $d = $all_metrics_lower[$lt_key];
                $sv      = isset($d['search_volume']) && $d['search_volume'] !== null ? $d['search_volume'] : null;
                $kd      = isset($d['keyword_difficulty']) && $d['keyword_difficulty'] !== null ? $d['keyword_difficulty'] : null;
                $cpc_val = isset($d['cpc']) && $d['cpc'] !== null ? $d['cpc'] : null;
            }

            if ($sv !== null || $kd !== null || $cpc_val !== null) {
                $keywords_with_data++;
                $status = 'success';
            }

            $sv_sql  = $sv !== null ? $wpdb->prepare('%d', $sv) : 'NULL';
            $kd_sql  = $kd !== null ? $wpdb->prepare('%d', $kd) : 'NULL';
            $cpc_sql = $cpc_val !== null ? $wpdb->prepare('%f', $cpc_val) : 'NULL';

            foreach ($row_ids as $kid) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$table_name}
                     SET search_volume = {$sv_sql},
                         keyword_difficulty = {$kd_sql},
                         cpc = {$cpc_sql},
                         seo_data_updated = %s,
                         dataforseo_status = %s
                     WHERE id = %d",
                    $now, $status, $kid
                ) );
                $total_updated++;
            }
        }

        // --- Save primary keyword metrics in a WP option for the grouped table display ---
        $primary_metrics_option = array();
        foreach ($primary_ids as $pk_key => $unused) {
            if (isset($all_metrics_lower[$pk_key])) {
                $d = $all_metrics_lower[$pk_key];
                $primary_metrics_option[$pk_key] = array(
                    'search_volume'     => isset($d['search_volume']) ? $d['search_volume'] : null,
                    'keyword_difficulty' => isset($d['keyword_difficulty']) ? $d['keyword_difficulty'] : null,
                    'cpc'               => isset($d['cpc']) ? $d['cpc'] : null,
                );
            }
        }
        update_option('mindfulseo_primary_metrics', $primary_metrics_option, false);
        
        // Check if DataForSEO is configured
        $settings = get_option('mindfulseo_settings', array());
        $dataforseo_configured = !empty($settings['dataforseo_login']) && !empty($settings['dataforseo_password']);
        
        $message = sprintf(
            __('Refreshed metrics for %d keywords — %d have search data.', 'mindfulseo'),
            $total_keywords,
            $keywords_with_data
        );
        
        // Calculate data coverage percentage
        $data_coverage_percent = $total_keywords > 0 ? ($keywords_with_data / $total_keywords) * 100 : 0;
        
        // Only show warnings if we have VERY poor coverage
        if ($keywords_with_data === 0 && $total_keywords > 0) {
            if (!$dataforseo_configured) {
                // If DataForSEO not configured, give helpful message instead of alarming warning
                $message .= "\n\n" . __(
                    'ℹ️ DataForSEO is not configured yet. Keywords will work without metrics. ' .
                    'To add search volume and difficulty data, configure DataForSEO in Settings.',
                    'mindfulseo'
                );
            } else {
                // DataForSEO IS configured but returned NO data at all - real issue
                $message .= "\n\n" . __(
                    '⚠️ Warning: DataForSEO returned empty results for all keywords. ' .
                    'Please verify your account has credits and check your location settings.',
                    'mindfulseo'
                );
            }
        } elseif ($data_coverage_percent < 20 && $total_keywords > 5) {
            // Less than 20% coverage AND more than 5 keywords = potential issue
            $message .= "\n\n" . sprintf(
                __('⚠️ Warning: Only %d out of %d keywords have data. This may indicate low API credits or very niche keywords.', 'mindfulseo'),
                $keywords_with_data,
                $total_keywords
            );
        } elseif ($data_coverage_percent < 100) {
            // Some missing data, but this is NORMAL - gentle note only if significant
            $missing = $total_keywords - $keywords_with_data;
            if ($missing > 2) {
                $message .= "\n\n" . sprintf(
                    __('Note: %d keywords have no metrics yet. This is normal for niche or brand-specific terms.', 'mindfulseo'),
                    $missing
                );
            }
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

        @set_time_limit(180);

        try {
            $optimizer = MFSEO_Optimizer::get_instance();

            $result = $optimizer->optimize_post($post_id);

            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
            }

            if (!is_array($result) || !isset($result['optimization_id'])) {
                wp_send_json_error(__('Optimization returned no data. The AI may not have responded correctly.', 'mindfulseo'));
            }

            $apply_result = $optimizer->apply_optimization($result['optimization_id']);

            if (is_wp_error($apply_result)) {
                wp_send_json_error($apply_result->get_error_message());
            }

            require_once MINDFULSEO_PLUGIN_DIR . 'includes/class-seo-plugin-adapter.php';
            $adapter = MFSEO_SEO_Plugin_Adapter::get_instance();
            $post = get_post($post_id);

            $post_title = $post && isset($post->post_title) ? (string) $post->post_title : '';
            if (empty($post_title)) {
                $post_title = 'Post #' . $post_id;
            }

            wp_send_json_success(array(
                'message' => __('Post optimized successfully', 'mindfulseo'),
                'post_id' => $post_id,
                'post_title' => $post_title,
                'seo_data' => array(
                    'keyword' => $adapter->get_focus_keyword($post_id) ?: '—',
                    'title' => $adapter->get_seo_title($post_id) ?: '—',
                    'description' => $adapter->get_meta_description($post_id) ?: '—',
                    'slug' => $post ? $post->post_name : '—',
                ),
            ));
        } catch (\Throwable $e) {
            error_log('MindfulSEO batch_optimize_single fatal: ' . $e->getMessage());
            wp_send_json_error(__('Server error: ', 'mindfulseo') . $e->getMessage());
        }
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
            
            // Extract common terms from titles (PHP 8.x: ensure string is not null)
            $post_title = isset($post->post_title) && is_string($post->post_title) ? $post->post_title : '';
            $title = strtolower($post_title);
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
        
        $rules = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE status = %s ORDER BY priority DESC LIMIT %d", 'active', 10 ) );
        
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
            // Return info_only flag - not an error, just informational
            wp_send_json_success(array(
                'message' => __('ℹ️ DataForSEO is not configured yet. Search volume and difficulty data will be available once you configure DataForSEO in Settings.', 'mindfulseo'),
                'info_only' => true
            ));
            return;
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
        
        // Always re-fetch all keywords when the user clicks Refresh
        $placeholders = implode(', ', array_fill(0, count($keywords_to_check), '%s'));
        $all_params = array_merge($keywords_to_check, $keywords_to_check);
        $existing_keywords = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, primary_keyword, longtail_keyword
                 FROM {$table_name}
                 WHERE primary_keyword IN ($placeholders)
                    OR longtail_keyword IN ($placeholders)",
                $all_params
            )
        );
        $existing_keywords = is_array($existing_keywords) ? $existing_keywords : array();

        $keywords_to_fetch = $keywords_to_check;
        $keyword_map = array();
        $fresh_count = 0;

        foreach ($existing_keywords as $row) {
            foreach ($keywords_to_check as $kw) {
                if (strcasecmp($row->primary_keyword, $kw) === 0 || strcasecmp($row->longtail_keyword, $kw) === 0) {
                    $keyword_map[$kw] = $row->id;
                }
            }
        }
        
        // Get settings for location and language
        $settings = get_option('mindfulseo_settings', array());
        $location_code = isset($settings['dataforseo_location']) ? $settings['dataforseo_location'] : '2840';
        $language_code = isset($settings['dataforseo_language']) ? $settings['dataforseo_language'] : 'en';
        
        // Fetch metrics from DataForSEO for keywords that need it
        error_log('MindfulSEO: Fetching metrics for ' . count($keywords_to_fetch) . ' keywords');
        error_log('MindfulSEO: Keywords to fetch: ' . print_r($keywords_to_fetch, true));
        
        $metrics = $connector->get_combined_metrics($keywords_to_fetch, $location_code, $language_code);
        
        if ( is_wp_error( $metrics ) ) {
            error_log( 'MindfulSEO: DataForSEO API Error: ' . $metrics->get_error_message() );
            wp_send_json_error( array(
                'message' => __( 'DataForSEO API Error: ', 'mindfulseo' ) . $metrics->get_error_message()
            ) );
            return;
        }
        
        if ( ! is_array( $metrics ) ) {
            error_log( 'MindfulSEO: DataForSEO returned unexpected type: ' . gettype( $metrics ) );
            $metrics = array();
        }
        
        error_log( 'MindfulSEO: DataForSEO returned metrics for ' . count( $metrics ) . ' keywords' );
        
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
            
            $sv_sql  = $search_volume !== null ? $wpdb->prepare('%d', $search_volume) : 'NULL';
            $kd_sql  = $difficulty !== null ? $wpdb->prepare('%d', $difficulty) : 'NULL';
            $cpc_sql = $cpc !== null ? $wpdb->prepare('%f', $cpc) : 'NULL';
            $now     = current_time('mysql');

            if (isset($keyword_map[$keyword_string])) {
                // Update rows matching by primary_keyword OR longtail_keyword
                $rows_affected = $wpdb->query( $wpdb->prepare(
                    "UPDATE {$table_name}
                     SET search_volume = {$sv_sql},
                         keyword_difficulty = {$kd_sql},
                         cpc = {$cpc_sql},
                         seo_data_updated = %s,
                         dataforseo_status = %s
                     WHERE LOWER(primary_keyword) = LOWER(%s) OR LOWER(longtail_keyword) = LOWER(%s)",
                    $now, $dataforseo_status, $keyword_string, $keyword_string
                ) );
                $total_updated += max(1, (int) $rows_affected);
            } else {
                $existing_count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name} WHERE LOWER(primary_keyword) = LOWER(%s) OR LOWER(longtail_keyword) = LOWER(%s)",
                    $keyword_string, $keyword_string
                ) );
                if ( $existing_count > 0 ) {
                    $rows_affected = $wpdb->query( $wpdb->prepare(
                        "UPDATE {$table_name}
                         SET search_volume = {$sv_sql},
                             keyword_difficulty = {$kd_sql},
                             cpc = {$cpc_sql},
                             seo_data_updated = %s,
                             dataforseo_status = %s
                         WHERE LOWER(primary_keyword) = LOWER(%s) OR LOWER(longtail_keyword) = LOWER(%s)",
                        $now, $dataforseo_status, $keyword_string, $keyword_string
                    ) );
                    $total_updated += max(1, (int) $rows_affected);
                } else {
                    $wpdb->query( $wpdb->prepare(
                        "INSERT INTO {$table_name}
                         (primary_keyword, longtail_keyword, search_volume, keyword_difficulty, cpc, priority, search_intent, seo_data_updated, dataforseo_status, created_date)
                         VALUES (%s, %s, {$sv_sql}, {$kd_sql}, {$cpc_sql}, %s, %s, %s, %s, %s)",
                        $keyword_string, $keyword_string, 'MEDIUM', 'Informational', $now, $dataforseo_status, $now
                    ) );
                    $total_inserted++;
                }
            }
        }

        // --- Labs fallback for this handler too ---
        $no_data_kw = array();
        foreach ($keywords_to_fetch as $kw_str) {
            $check = $wpdb->get_row( $wpdb->prepare(
                "SELECT search_volume, keyword_difficulty, cpc FROM {$table_name}
                 WHERE LOWER(primary_keyword) = LOWER(%s) OR LOWER(longtail_keyword) = LOWER(%s)
                 LIMIT 1",
                $kw_str, $kw_str
            ) );
            if ($check && $check->search_volume === null && $check->keyword_difficulty === null && $check->cpc === null) {
                $no_data_kw[] = $kw_str;
            }
        }

        if (!empty($no_data_kw)) {
            error_log('MindfulSEO Batch: ' . count($no_data_kw) . ' keywords with no data — trying Labs fallback');
            $labs_batches2 = array_chunk($no_data_kw, 700);

            foreach ($labs_batches2 as $lb) {
                $labs_m = $connector->get_keyword_overview_labs($lb, $location_code, $language_code);
                if (is_wp_error($labs_m)) {
                    error_log('MindfulSEO Batch: Labs fallback error: ' . $labs_m->get_error_message());
                    continue;
                }

                $labs_low = array();
                if (is_array($labs_m)) {
                    foreach ($labs_m as $mk2 => $mv2) {
                        $labs_low[strtolower($mk2)] = $mv2;
                    }
                }

                foreach ($lb as $kw2) {
                    $kl2 = strtolower(trim($kw2));
                    $sv2 = null; $kd2 = null; $cpc2 = null; $st2 = 'no_data';

                    if (isset($labs_low[$kl2])) {
                        $d2 = $labs_low[$kl2];
                        $sv2  = isset($d2['search_volume']) && $d2['search_volume'] !== null ? $d2['search_volume'] : null;
                        $kd2  = isset($d2['keyword_difficulty']) && $d2['keyword_difficulty'] !== null ? $d2['keyword_difficulty'] : null;
                        $cpc2 = isset($d2['cpc']) && $d2['cpc'] !== null ? $d2['cpc'] : null;
                    }

                    if ($sv2 !== null || $kd2 !== null || $cpc2 !== null) {
                        $keywords_with_data++;
                        $st2 = 'success';
                    }

                    $sv_s2  = $sv2 !== null ? $wpdb->prepare('%d', $sv2) : 'NULL';
                    $kd_s2  = $kd2 !== null ? $wpdb->prepare('%d', $kd2) : 'NULL';
                    $cpc_s2 = $cpc2 !== null ? $wpdb->prepare('%f', $cpc2) : 'NULL';
                    $now2   = current_time('mysql');

                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$table_name}
                         SET search_volume = {$sv_s2},
                             keyword_difficulty = {$kd_s2},
                             cpc = {$cpc_s2},
                             seo_data_updated = %s,
                             dataforseo_status = %s
                         WHERE LOWER(primary_keyword) = LOWER(%s) OR LOWER(longtail_keyword) = LOWER(%s)",
                        $now2, $st2, $kw2, $kw2
                    ) );
                }
            }
        }
        
        // Check if DataForSEO is configured
        $settings = get_option('mindfulseo_settings', array());
        $dataforseo_configured = !empty($settings['dataforseo_login']) && !empty($settings['dataforseo_password']);
        
        $message = sprintf(
            __('%d keywords refreshed from DataForSEO. Page will reload.', 'mindfulseo'),
            $total_updated + $total_inserted
        );
        
        $total_keywords_on_page = $total_updated + $total_inserted;
        $total_with_data = $keywords_with_data;
        
        // Only show warnings if we actually fetched keywords and got VERY poor results
        if ($total_updated + $total_inserted > 0) {
            // Calculate what percentage of NEWLY FETCHED keywords got data
            $new_fetch_coverage = ($keywords_with_data / ($total_updated + $total_inserted)) * 100;
            
            // Only show warning if less than 20% of NEWLY fetched keywords got data
            // AND we're not just checking a few niche keywords
            if ($new_fetch_coverage < 20 && ($total_updated + $total_inserted) > 5) {
                if (!$dataforseo_configured) {
                    $message .= "\n\n" . __(
                        'ℹ️ DataForSEO is not configured yet. To add search volume and difficulty data, configure DataForSEO in Settings.',
                        'mindfulseo'
                    );
                } else {
                    // DataForSEO IS configured but returned almost no data for many keywords
                    $message .= "\n\n" . __(
                        '⚠️ Warning: DataForSEO returned very limited data for these keywords. ' .
                        'This may indicate: 1) Keywords are very niche, 2) Low API credits, or 3) Location settings need adjustment.',
                        'mindfulseo'
                    );
                }
            } elseif ($new_fetch_coverage < 100 && $keywords_with_data < ($total_updated + $total_inserted)) {
                // Some new keywords have no data - this is NORMAL, just a gentle note
                $missing = ($total_updated + $total_inserted) - $keywords_with_data;
                if ($missing > 2) { // Only show if more than 2 keywords are missing data
                    $message .= "\n\n" . sprintf(
                        __('Note: %d keywords have no metrics yet. This is normal for very niche or brand-specific terms.', 'mindfulseo'),
                        $missing
                    );
                }
            }
        }
        
        wp_send_json_success(array(
            'message' => $message,
            'keywords_refreshed' => $total_updated + $total_inserted,
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
        
        // Use the configured live domain first; fall back to the WordPress site URL
        $settings = get_option( 'mindfulseo_settings', array() );
        $domain   = isset( $settings['live_domain'] ) ? trim( $settings['live_domain'] ) : '';

        if ( empty( $domain ) ) {
            $parsed_url = parse_url( get_site_url() );
            $domain     = isset( $parsed_url['host'] ) ? $parsed_url['host'] : '';
            $domain     = preg_replace( '/^www\./', '', $domain );
        }

        if ( empty( $domain ) ) {
            wp_send_json_error( array(
                'message' => __( 'Unable to determine site domain. Please set your Live Domain in MindfulSEO Settings → DataForSEO section.', 'mindfulseo' ),
            ) );
        }

        // If the domain still looks like a local/staging URL, warn the user
        if ( preg_match( '/\.(local|test|localhost|dev|staging)$/i', $domain ) || strpos( $domain, 'localhost' ) !== false ) {
            wp_send_json_error( array(
                'message' => sprintf(
                    __( 'The domain "%s" is a local/staging URL that DataForSEO cannot look up. Please set your live production domain in MindfulSEO → Settings → DataForSEO.', 'mindfulseo' ),
                    $domain
                ),
            ) );
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
        check_ajax_referer('mindfulseo_ajax_nonce', 'nonce');
        
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
    
    /**
     * Refresh dashboard data
     * 
     * @since 2.0.0
     */
    public static function refresh_dashboard() {
        check_ajax_referer('mindfulseo_dashboard', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'mindfulseo')));
        }
        
        global $wpdb;
        
        // Gather dashboard statistics
        $total_posts = wp_count_posts('post')->publish + wp_count_posts('page')->publish;
        
        // Optimized posts
        $optimized_posts = $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} 
            WHERE meta_key IN ('rank_math_focus_keyword', '_yoast_wpseo_focuskw')
            AND meta_value != ''"
        );
        
        // Keywords tracked
        $keywords_table = $wpdb->prefix . 'mindfulseo_keywords';
        $keywords_tracked = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$keywords_table}" );

        // Content clusters
        $content_clusters = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT primary_keyword) FROM {$keywords_table}" );
        
        // Needs attention
        $needs_attention = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                AND pm.meta_key IN ('rank_math_description', '_yoast_wpseo_metadesc')
            WHERE p.post_status = 'publish' 
            AND p.post_type IN ('post', 'page')
            AND (pm.meta_value IS NULL OR pm.meta_value = '')"
        );
        
        // Calculate SEO score
        $optimization_rate = $total_posts > 0 ? ($optimized_posts / $total_posts) * 100 : 0;
        $seo_score = min(100, round($optimization_rate * 0.6 + ($keywords_tracked > 0 ? 20 : 0) + ($content_clusters > 0 ? 20 : 0)));
        
        wp_send_json_success(array(
            'stats' => array(
                'total_posts' => $total_posts,
                'optimized_posts' => $optimized_posts,
                'keywords_tracked' => $keywords_tracked,
                'content_clusters' => $content_clusters,
                'needs_attention' => $needs_attention,
                'seo_score' => $seo_score,
            ),
        ));
    }
    
    /**
     * Refresh content clusters
     * 
     * @since 2.0.0
     */
    public static function refresh_clusters() {
        check_ajax_referer( 'mindfulseo_admin', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'mindfulseo' ) ) );
        }

        // Clear cluster cache and Content Hub quick counts
        delete_transient( 'mfseo_cluster_all_clusters' );
        delete_transient( 'mfseo_hub_quick_counts' );

        // Force recalculation
        if ( class_exists( 'MFSEO_Content_Cluster_Engine' ) ) {
            $engine   = MFSEO_Content_Cluster_Engine::get_instance();
            $clusters = $engine->identify_clusters();

            wp_send_json_success( array(
                'message'       => __( 'Clusters refreshed', 'mindfulseo' ),
                'cluster_count' => count( $clusters ),
            ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Cluster engine not available', 'mindfulseo' ) ) );
        }
    }
    
    /**
     * Suggest pillar content for a cluster
     * 
     * @since 2.0.0
     */
    public static function suggest_pillar() {
        check_ajax_referer('mindfulseo_admin', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'mindfulseo')));
        }
        
        $cluster = isset($_POST['cluster']) ? sanitize_text_field($_POST['cluster']) : '';
        
        if (empty($cluster)) {
            wp_send_json_error(array('message' => __('Cluster name required', 'mindfulseo')));
        }
        
        // Use AI to suggest pillar content
        if (class_exists('MFSEO_AI_Connector')) {
            $ai = MFSEO_AI_Connector::get_instance();
            
            $prompt = sprintf(
                'You are an SEO expert. Suggest a comprehensive pillar content piece for the topic cluster "%s". ' .
                'Provide: 1) A compelling title, 2) A brief outline (5-7 main sections), 3) Target word count, ' .
                '4) Key subtopics to cover. Format as a structured recommendation.',
                $cluster
            );
            
            $response = $ai->generate_completion($prompt, array(
                'max_tokens' => 500,
                'temperature' => 0.7,
            ));
            
            if ($response && !is_wp_error($response)) {
                wp_send_json_success(array(
                    'suggestion' => $response,
                    'cluster' => $cluster,
                ));
            }
        }
        
        // Fallback suggestion
        wp_send_json_success(array(
            'suggestion' => sprintf(
                'For the "%s" cluster, consider creating a comprehensive guide that covers all aspects of this topic. ' .
                'Aim for 2,000+ words with clear sections, actionable advice, and internal links to supporting content.',
                $cluster
            ),
        ));
    }
    
    /**
     * Generate content gap suggestions
     * 
     * @since 2.0.0
     */
    public static function generate_gap_suggestions() {
        check_ajax_referer('mindfulseo_admin', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'mindfulseo')));
        }
        
        // Get content gaps
        if (class_exists('MFSEO_Content_Cluster_Engine')) {
            $engine = MFSEO_Content_Cluster_Engine::get_instance();
            $gaps = $engine->find_content_gaps();
            
            // Generate AI suggestions for top gaps
            $suggestions = array();
            $top_gaps = array_slice($gaps, 0, 3);
            
            foreach ($top_gaps as $gap) {
                $suggestions[] = array(
                    'title' => 'Create content for: ' . $gap['keyword'],
                    'description' => sprintf(
                        'This keyword has %s monthly searches and %s difficulty. ' .
                        'Create a targeted piece in the "%s" cluster to capture this opportunity.',
                        number_format($gap['search_volume']),
                        $gap['difficulty'] ?: 'unknown',
                        $gap['cluster']
                    ),
                );
            }
            
            wp_send_json_success(array('suggestions' => $suggestions));
        }
        
        wp_send_json_error(array('message' => __('Unable to generate suggestions', 'mindfulseo')));
    }
    
    /**
     * Analyze internal links
     * 
     * @since 2.0.0
     */
    public static function analyze_internal_links() {
        check_ajax_referer( 'mindfulseo_admin', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'mindfulseo' ) ) );
            return;
        }

        if ( ! class_exists( 'MFSEO_Internal_Linker' ) ) {
            wp_send_json_error( array( 'message' => __( 'Internal Linker not available', 'mindfulseo' ) ) );
            return;
        }

        // Extend PHP time limit for this AJAX operation (orphan scan can take a while)
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 60 );
        }

        $start_time = microtime( true );

        try {
            $linker = MFSEO_Internal_Linker::get_instance();
            $linker->clear_cache();
            $data = $linker->find_opportunities( true );
        } catch ( \Throwable $e ) {
            error_log( 'MindfulSEO: Internal links analysis error: ' . $e->getMessage() );
            wp_send_json_error( array( 'message' => __( 'Analysis failed: ', 'mindfulseo' ) . $e->getMessage() ) );
            return;
        }

        $total_time = microtime( true ) - $start_time;
        error_log( sprintf( 'MindfulSEO: Internal links analysis completed in %.2f seconds', $total_time ) );

        if ( ! is_array( $data ) ) {
            wp_send_json_error( array( 'message' => __( 'Analysis returned unexpected data.', 'mindfulseo' ) ) );
            return;
        }

        $orphan_count = isset( $data['orphan_count'] ) ? (int) $data['orphan_count'] : 0;
        $suggestions  = isset( $data['suggestions'] ) ? $data['suggestions'] : array();

        delete_transient( 'mfseo_hub_quick_counts' );

        wp_send_json_success( array(
            'missing_links' => $orphan_count,
            'orphan_pages'  => $orphan_count,
            'suggestions'   => $suggestions,
            'message'       => sprintf(
                __( 'Found %d orphan pages (showing top %d suggestions). Completed in %.1f seconds.', 'mindfulseo' ),
                $orphan_count,
                count( $suggestions ),
                $total_time
            ),
        ) );
    }

    /**
     * Scan all post content for broken internal and external links.
     */
    public static function scan_broken_links() {
        check_ajax_referer( 'mindfulseo_admin', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'mindfulseo' ) ) );
            return;
        }

        if ( ! class_exists( 'MFSEO_Internal_Linker' ) ) {
            wp_send_json_error( array( 'message' => __( 'Internal Linker not available', 'mindfulseo' ) ) );
            return;
        }

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }
        if ( function_exists( 'ignore_user_abort' ) ) {
            @ignore_user_abort( true );
        }

        try {
            $linker = MFSEO_Internal_Linker::get_instance();
            $phase = isset( $_POST['phase'] ) ? sanitize_key( wp_unslash( $_POST['phase'] ) ) : '';
            $quick = isset( $_POST['quick'] ) && (string) $_POST['quick'] === '1';
            $post_limit = 20;
            if ( isset( $_POST['post_limit'] ) ) {
                $post_limit = absint( wp_unslash( $_POST['post_limit'] ) );
            }

            if ( $phase === 'start' || $phase === 'step' ) {
                if ( $phase === 'start' ) {
                    $linker->clear_broken_links_cache();
                }
                $out = $linker->scan_broken_links_chunked( $quick, $phase, $post_limit );
                if ( ! empty( $out['error'] ) ) {
                    wp_send_json_error( array( 'message' => $out['error'] ) );
                    return;
                }
                wp_send_json_success( $out );
                return;
            }

            /* Legacy single-shot scan (large sites may hit timeouts). */
            $linker->clear_broken_links_cache();
            $data = $linker->scan_broken_links( true );
        } catch ( \Throwable $e ) {
            error_log( 'MindfulSEO: Broken link scan error: ' . $e->getMessage() );
            wp_send_json_error( array( 'message' => __( 'Scan failed: ', 'mindfulseo' ) . $e->getMessage() ) );
            return;
        }

        wp_send_json_success( $data );
    }
}

// Initialize AJAX handlers
MFSEO_AJAX_Handlers::init();

