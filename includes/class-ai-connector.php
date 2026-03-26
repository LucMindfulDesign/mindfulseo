<?php
/**
 * AI Connector
 * 
 * Manages AI provider connections with fallback support
 * 
 * @package MindfulSEO
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_AI_Connector {
    
    /**
     * The single instance of the class
     * 
     * @var MFSEO_AI_Connector
     */
    private static $instance = null;
    
    /**
     * Primary AI provider
     * 
     * @var MFSEO_OpenAI_Provider|MFSEO_Claude_Provider|null
     */
    private $primary_provider = null;
    
    /**
     * Fallback AI provider
     * 
     * @var MFSEO_OpenAI_Provider|MFSEO_Claude_Provider|null
     */
    private $fallback_provider = null;
    
    /**
     * Logger instance
     * 
     * @var MFSEO_Logger
     */
    private $logger = null;
    
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
        // Initialize logger FIRST
        $this->logger = class_exists('MFSEO_Logger') ? MFSEO_Logger::get_instance() : null;
        
        // Then initialize providers
        $this->initialize_providers();
    }
    
    /**
     * Initialize AI providers based on settings
     */
    private function initialize_providers() {
        $settings = MindfulSEO::get_settings();
        
        $primary = isset($settings['primary_provider']) ? $settings['primary_provider'] : 'openai';
        $fallback_enabled = isset($settings['enable_fallback']) ? $settings['enable_fallback'] : true;
        
        // Initialize primary provider
        try {
            $this->primary_provider = $this->create_provider($primary);
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->log_error('Failed to initialize primary provider: ' . $e->getMessage());
            }
            error_log('MindfulSEO: Failed to initialize primary provider: ' . $e->getMessage());
        }
        
        // Initialize fallback provider if enabled
        if ($fallback_enabled) {
            $fallback = $primary === 'openai' ? 'claude' : 'openai';
            try {
                $this->fallback_provider = $this->create_provider($fallback);
            } catch (Exception $e) {
                if ($this->logger) {
                    $this->logger->log_error('Failed to initialize fallback provider: ' . $e->getMessage());
                }
                error_log('MindfulSEO: Failed to initialize fallback provider: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Create provider instance
     * 
     * @param string $provider_name Provider name (openai or claude)
     * @return MFSEO_OpenAI_Provider|MFSEO_Claude_Provider
     * @throws Exception If provider cannot be created
     */
    private function create_provider($provider_name) {
        $settings = MindfulSEO::get_settings();
        
        switch ($provider_name) {
            case 'openai':
                $api_key = isset($settings['openai_api_key']) ? $this->decrypt_api_key($settings['openai_api_key']) : '';
                if (empty($api_key)) {
                    throw new Exception('OpenAI API key not configured');
                }
                return new MFSEO_OpenAI_Provider($api_key);
                
            case 'claude':
                $api_key = isset($settings['claude_api_key']) ? $this->decrypt_api_key($settings['claude_api_key']) : '';
                if (empty($api_key)) {
                    throw new Exception('Claude API key not configured');
                }
                return new MFSEO_Claude_Provider($api_key);
                
            default:
                throw new Exception('Unknown provider: ' . $provider_name);
        }
    }
    
    /**
     * Call AI provider with fallback support
     * 
     * @param string $method Method to call on provider
     * @param array $args Arguments to pass
     * @return mixed Result from provider
     * @throws Exception If all providers fail
     */
    public function call($method, $args = array()) {
        // Try primary provider
        if ($this->primary_provider) {
            try {
                $result = call_user_func_array(array($this->primary_provider, $method), $args);
                return $result;
            } catch (Exception $e) {
                if ( $this->logger ) {
                    $this->logger->log_error('Primary provider failed: ' . $e->getMessage());
                }
                error_log( 'MindfulSEO: Primary provider failed: ' . $e->getMessage() );

                // Try fallback if available
                if ($this->fallback_provider) {
                    try {
                        $result = call_user_func_array(array($this->fallback_provider, $method), $args);
                        if ( $this->logger ) {
                            $this->logger->log_info('Fallback provider succeeded');
                        }
                        return $result;
                    } catch (Exception $e2) {
                        if ( $this->logger ) {
                            $this->logger->log_error('Fallback provider also failed: ' . $e2->getMessage());
                        }
                        error_log( 'MindfulSEO: Fallback provider also failed: ' . $e2->getMessage() );
                        throw new Exception('All AI providers failed');
                    }
                } else {
                    throw $e;
                }
            }
        }

        throw new Exception('No AI providers configured');
    }
    
    /**
     * Generate SEO optimization for content
     * 
     * @param string $content Post content
     * @param array $guidelines Language guidelines
     * @param array $keywords Keyword data
     * @return array Optimization results
     */
    public function optimize_content($content, $guidelines, $keywords) {
        return $this->call('optimize_content', array($content, $guidelines, $keywords));
    }
    
    /**
     * Generate SEO title
     * 
     * @param string $content Post content
     * @param string $keyword Focus keyword
     * @param array $guidelines Language guidelines
     * @return string Generated title
     */
    public function generate_seo_title($content, $keyword, $guidelines) {
        return $this->call('generate_seo_title', array($content, $keyword, $guidelines));
    }
    
    /**
     * Generate meta description
     * 
     * @param string $content Post content
     * @param string $keyword Focus keyword
     * @param array $guidelines Language guidelines
     * @return string Generated description
     */
    public function generate_meta_description($content, $keyword, $guidelines) {
        return $this->call('generate_meta_description', array($content, $keyword, $guidelines));
    }
    
    /**
     * Analyze content
     * 
     * @param string $content Post content
     * @param array $guidelines Language guidelines
     * @return array Analysis results
     */
    public function analyze_content($content, $guidelines) {
        return $this->call('analyze_content', array($content, $guidelines));
    }
    
    /**
     * Generate blog post outline
     * 
     * @param string $topic Topic
     * @param string $keyword Primary keyword
     * @param array $params Generation parameters
     * @return array Outline data
     */
    public function generate_blog_outline($topic, $keyword, $params) {
        return $this->call('generate_blog_outline', array($topic, $keyword, $params));
    }
    
    /**
     * Generate blog post draft from outline
     * 
     * @param array $outline Outline data
     * @param array $params Generation parameters
     * @return array Draft data
     */
    public function generate_blog_draft($outline, $params) {
        return $this->call('generate_blog_draft', array($outline, $params));
    }
    
    /**
     * Polish blog post content
     * 
     * @param string $content Draft content
     * @param array $guidelines Language guidelines
     * @return array Polished content data
     */
    public function polish_content($content, $guidelines) {
        return $this->call('polish_content', array($content, $guidelines));
    }
    
    /**
     * Generate content from a prompt (generic method)
     * 
     * @param string $prompt The prompt to send to the AI
     * @param array $options Optional parameters like max_tokens, temperature, etc.
     * @return string|WP_Error Generated content or error
     */
    public function generate_content($prompt, $options = array()) {
        $prompt = mb_convert_encoding($prompt, 'UTF-8', 'UTF-8');
        $prompt = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $prompt);
        $prompt = $this->truncate_prompt_for_http( $prompt );
        
        $settings = MindfulSEO::get_settings();
        $primary = isset($settings['primary_provider']) ? $settings['primary_provider'] : 'openai';
        $fallback = $primary === 'openai' ? 'claude' : 'openai';
        
        $use_fast = !empty($options['fast_model']);

        // Circuit breaker: skip a provider that recently failed (avoids wasting
        // seconds on a broken API key for every single post in a batch).
        $breaker_key = 'mfseo_provider_down_' . $primary;
        $primary_down = get_transient($breaker_key);

        $fallback_key_field = $fallback === 'openai' ? 'openai_api_key' : 'claude_api_key';
        $fallback_key = isset($settings[$fallback_key_field]) ? $this->decrypt_api_key($settings[$fallback_key_field]) : '';

        if ($primary_down && !empty($fallback_key)) {
            $result = $this->generate_content_with_provider($fallback, $prompt, $options, $settings, $use_fast);
            if (!is_wp_error($result)) {
                return $result;
            }
            // Fallback also failed — clear breaker and try primary as last resort
            delete_transient($breaker_key);
        }
        
        // Try primary provider
        $result = $this->generate_content_with_provider($primary, $prompt, $options, $settings, $use_fast);
        
        if (!is_wp_error($result)) {
            return $result;
        }
        
        // Primary failed — mark it down for 5 minutes so subsequent calls skip it
        set_transient($breaker_key, 1, 5 * MINUTE_IN_SECONDS);

        $primary_error = $result->get_error_message();
        error_log('MindfulSEO: Primary provider (' . $primary . ') failed: ' . substr($primary_error, 0, 120) . ' — trying fallback (' . $fallback . ')');
        
        if (empty($fallback_key)) {
            return $result;
        }
        
        $fallback_result = $this->generate_content_with_provider($fallback, $prompt, $options, $settings, $use_fast);
        
        if (!is_wp_error($fallback_result)) {
            error_log('MindfulSEO: Fallback provider (' . $fallback . ') succeeded');
            return $fallback_result;
        }
        
        error_log('MindfulSEO: Fallback provider (' . $fallback . ') also failed: ' . $fallback_result->get_error_message());
        return new WP_Error('all_providers_failed', sprintf(
            'Primary (%s): %s | Fallback (%s): %s',
            $primary, $primary_error, $fallback, $fallback_result->get_error_message()
        ));
    }
    
    /**
     * Cap prompt size so JSON POST body stays under common HTTP/cURL limits (e.g. cURL error 100).
     *
     * @param string $prompt Prompt text.
     * @param int    $max_bytes Max byte length (strlen).
     * @return string
     */
    private function truncate_prompt_for_http( $prompt, $max_bytes = 300000 ) {
        $max_bytes = (int) apply_filters( 'mfseo_max_ai_prompt_bytes', $max_bytes );
        if ( $max_bytes < 50000 ) {
            $max_bytes = 50000;
        }
        if ( strlen( $prompt ) <= $max_bytes ) {
            return $prompt;
        }
        error_log( 'MindfulSEO: Truncating AI prompt from ' . strlen( $prompt ) . ' to ' . $max_bytes . ' bytes for HTTP limits.' );
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $prompt, 0, $max_bytes ) . "\n\n[Prompt truncated for HTTP request size limits.]";
        }
        return substr( $prompt, 0, $max_bytes ) . "\n\n[Prompt truncated for HTTP request size limits.]";
    }

    /**
     * Execute a content generation request against a specific provider
     */
    private function generate_content_with_provider($provider, $prompt, $options, $settings, $use_fast) {
        try {
            if ($provider === 'openai') {
                return $this->generate_content_openai($prompt, $options, $settings, $use_fast);
            } else {
                return $this->generate_content_claude($prompt, $options, $settings, $use_fast);
            }
        } catch (Exception $e) {
            return new WP_Error('exception', $e->getMessage());
        }
    }
    
    /**
     * Generate content via OpenAI
     */
    private function generate_content_openai($prompt, $options, $settings, $use_fast) {
        $api_key = isset($settings['openai_api_key']) ? $this->decrypt_api_key($settings['openai_api_key']) : '';
        if (empty($api_key)) {
            return new WP_Error('no_api_key', 'OpenAI API key not configured');
        }
        
        if ($use_fast) {
            $model = 'gpt-4o-mini';
        } else {
            $model = isset($settings['openai_model']) ? $settings['openai_model'] : 'gpt-4o';
        }
        
        $body = array(
            'model' => $model,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
            'temperature' => isset($options['temperature']) ? $options['temperature'] : 0.7,
        );
        
        $is_reasoning_model = (bool) preg_match('/^(gpt-5|o[1-9])/i', $model);
        
        if (strpos($model, 'gpt-5') === 0) {
            $requested = isset($options['max_tokens']) ? (int) $options['max_tokens'] : 2000;
            $body['max_completion_tokens'] = max(4096, $requested * 3);
            $body['verbosity'] = isset($options['verbosity']) ? $options['verbosity'] : 'low';
            $body['reasoning_effort'] = isset($options['reasoning_effort']) ? $options['reasoning_effort'] : 'low';
        } elseif ($is_reasoning_model) {
            $requested = isset($options['max_tokens']) ? (int) $options['max_tokens'] : 2000;
            $body['max_completion_tokens'] = max(4096, $requested * 3);
        } else {
            $body['max_tokens'] = isset($options['max_tokens']) ? $options['max_tokens'] : 2000;
        }
        
        if ($is_reasoning_model) {
            unset($body['temperature']);
        } else {
            $body['temperature'] = round($body['temperature'], 1);
        }
        
        $api_timeout = $is_reasoning_model ? 180 : 120;
        if (isset($options['timeout']) && (int) $options['timeout'] > $api_timeout) {
            $api_timeout = (int) $options['timeout'];
        }

        $json_body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json_body === false) {
            return new WP_Error('json_error', 'Failed to encode request: ' . json_last_error_msg());
        }

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => $json_body,
            'timeout' => $api_timeout,
            'sslverify' => true,
            'httpversion' => '1.1',
        ));
        
        if (is_wp_error($response)) {
            error_log('MindfulSEO OpenAI Error: ' . $response->get_error_message());
            return $response;
        }
        
        $raw_body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode($raw_body, true);
        
        if ($body === null && json_last_error() !== JSON_ERROR_NONE) {
            error_log('MindfulSEO: OpenAI response is not valid JSON (HTTP ' . $status_code . '). Raw: ' . substr($raw_body, 0, 500));
            return new WP_Error('invalid_json', 'OpenAI returned non-JSON response (HTTP ' . $status_code . ')');
        }
        
        if (isset($body['error'])) {
            $err_msg = isset($body['error']['message']) ? $body['error']['message'] : json_encode($body['error']);
            error_log('MindfulSEO: OpenAI API error (HTTP ' . $status_code . '): ' . $err_msg);
            return new WP_Error('api_error', $err_msg);
        }
        
        if (!isset($body['choices'][0]['message']['content'])) {
            error_log('MindfulSEO: OpenAI response missing content. finish_reason: ' . (isset($body['choices'][0]['finish_reason']) ? $body['choices'][0]['finish_reason'] : 'unknown') . ' Full body keys: ' . implode(',', array_keys($body)));
            return new WP_Error('invalid_response', 'Invalid API response structure — no content returned');
        }
        
        $content = $body['choices'][0]['message']['content'];
        
        if (empty($content) || !is_string($content) || strlen(trim($content)) < 5) {
            $finish_reason = isset($body['choices'][0]['finish_reason']) ? $body['choices'][0]['finish_reason'] : 'unknown';
            error_log('MindfulSEO: OpenAI returned empty/null content. finish_reason: ' . $finish_reason);
            return new WP_Error('empty_response', sprintf('AI returned empty response (finish_reason: %s). The model may have refused the request or hit a content filter.', $finish_reason));
        }
        
        $usage_context = isset($options['usage_context']) ? (string) $options['usage_context'] : 'generate_content';

        if (isset($body['usage']) && class_exists('MFSEO_Logger')) {
            $logger = MFSEO_Logger::get_instance();
            $prompt_tokens = isset($body['usage']['prompt_tokens']) ? $body['usage']['prompt_tokens'] : 0;
            $completion_tokens = isset($body['usage']['completion_tokens']) ? $body['usage']['completion_tokens'] : 0;
            $cost = $this->estimate_openai_cost($model, $prompt_tokens, $completion_tokens);
            $logger->log_api_call('openai', $prompt_tokens, $completion_tokens, $cost, $model, $usage_context);
        }
        
        return $content;
    }
    
    /**
     * Generate content via Claude
     */
    private function generate_content_claude($prompt, $options, $settings, $use_fast) {
        $api_key = isset($settings['claude_api_key']) ? $this->decrypt_api_key($settings['claude_api_key']) : '';
        if (empty($api_key)) {
            return new WP_Error('no_api_key', 'Claude API key not configured');
        }
        
        if ($use_fast) {
            $model = isset($settings['claude_model_fast']) ? $settings['claude_model_fast'] : 'claude-haiku-4-5';
            if (strpos($model, 'claude-3-5-haiku') !== false || strpos($model, 'claude-3-haiku') !== false) {
                $model = 'claude-haiku-4-5';
            }
        } else {
            $model = isset($settings['claude_model']) ? $settings['claude_model'] : 'claude-sonnet-4-5';
        }
        
        $body = array(
            'model' => $model,
            'max_tokens' => isset($options['max_tokens']) ? $options['max_tokens'] : 2000,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
        );
        
        $claude_timeout = $use_fast ? 30 : 150;
        if (isset($options['timeout']) && (int) $options['timeout'] > $claude_timeout) {
            $claude_timeout = (int) $options['timeout'];
        }

        $json_body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json_body === false) {
            return new WP_Error('json_error', 'Failed to encode request: ' . json_last_error_msg());
        }

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ),
            'body' => $json_body,
            'timeout' => $claude_timeout,
            'sslverify' => true,
            'httpversion' => '1.1',
        ));
        
        if (is_wp_error($response)) {
            error_log('MindfulSEO Claude Error: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code === 404) {
            return new WP_Error('model_not_found', 
                sprintf(__('Claude model "%s" not found. This model may have been deprecated. Please update your model selection in Settings.', 'mindfulseo'), $model)
            );
        }
        
        if (isset($body['error'])) {
            $error_msg = is_array($body['error']) ? 
                (isset($body['error']['message']) ? $body['error']['message'] : json_encode($body['error'])) : 
                $body['error'];
            error_log('MindfulSEO: Claude API error: ' . $error_msg);
            return new WP_Error('api_error', $error_msg);
        }
        
        if (!isset($body['content'][0]['text'])) {
            error_log('MindfulSEO: Invalid Claude response structure. stop_reason: ' . (isset($body['stop_reason']) ? $body['stop_reason'] : 'unknown'));
            return new WP_Error('invalid_response', 'Invalid API response structure — no content returned');
        }
        
        $content = $body['content'][0]['text'];
        
        if (empty($content) || !is_string($content) || strlen(trim($content)) < 5) {
            $stop_reason = isset($body['stop_reason']) ? $body['stop_reason'] : 'unknown';
            error_log('MindfulSEO: Claude returned empty/null content. stop_reason: ' . $stop_reason);
            return new WP_Error('empty_response', sprintf('AI returned empty response (stop_reason: %s). The model may have refused the request or hit a content filter.', $stop_reason));
        }
        
        $usage_context = isset($options['usage_context']) ? (string) $options['usage_context'] : 'generate_content';

        if (isset($body['usage']) && class_exists('MFSEO_Logger')) {
            $logger = MFSEO_Logger::get_instance();
            $prompt_tokens = isset($body['usage']['input_tokens']) ? $body['usage']['input_tokens'] : 0;
            $completion_tokens = isset($body['usage']['output_tokens']) ? $body['usage']['output_tokens'] : 0;
            $cost = $this->estimate_claude_cost($model, $prompt_tokens, $completion_tokens);
            $logger->log_api_call('claude', $prompt_tokens, $completion_tokens, $cost, $model, $usage_context);
        }
        
        return $content;
    }
    
    /**
     * Get provider status
     * 
     * @param string $provider_name Provider name
     * @return array Status information
     */
    public function get_provider_status($provider_name = null) {
        if ($provider_name === null) {
            return array(
                'primary' => $this->get_single_provider_status($this->primary_provider),
                'fallback' => $this->get_single_provider_status($this->fallback_provider),
            );
        }
        
        $provider = $provider_name === 'primary' ? $this->primary_provider : $this->fallback_provider;
        return $this->get_single_provider_status($provider);
    }
    
    /**
     * Get status of single provider
     * 
     * @param object|null $provider Provider instance
     * @return array Status
     */
    private function get_single_provider_status($provider) {
        if (!$provider) {
            return array(
                'available' => false,
                'name' => 'None',
                'error' => 'Not configured',
            );
        }
        
        try {
            $test = $provider->test_connection();
            return array(
                'available' => true,
                'name' => $provider->get_name(),
                'model' => $provider->get_model(),
                'test' => $test,
            );
        } catch (Exception $e) {
            return array(
                'available' => false,
                'name' => $provider->get_name(),
                'error' => $e->getMessage(),
            );
        }
    }
    
    /**
     * Encrypt API key
     * 
     * @param string $api_key Plain text API key
     * @return string Encrypted key
     */
    public function encrypt_api_key($api_key) {
        $key = $this->get_encryption_key();
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($api_key, 'aes-256-cbc', $key, 0, $iv);
        return base64_encode($encrypted . '::' . $iv);
    }
    
    /**
     * Decrypt API key
     * 
     * @param string $encrypted_key Encrypted key
     * @return string Plain text API key
     */
    public function decrypt_api_key($encrypted_key) {
        if (empty($encrypted_key)) {
            return '';
        }
        
        $key = $this->get_encryption_key();
        $parts = explode('::', base64_decode($encrypted_key), 2);
        
        if (count($parts) !== 2) {
            return '';
        }
        
        list($encrypted_data, $iv) = $parts;
        return openssl_decrypt($encrypted_data, 'aes-256-cbc', $key, 0, $iv);
    }
    
    /**
     * Get encryption key
     * 
     * @return string Encryption key
     */
    private function get_encryption_key() {
        // Use WordPress salts for encryption key
        if (defined('AUTH_KEY')) {
            return substr(AUTH_KEY, 0, 32);
        }
        return 'mindfulseo_default_key_change_me';
    }
    
    /**
     * Test connection to providers
     * 
     * @return array Test results
     */
    public function test_connections() {
        $results = array();
        
        if ($this->primary_provider) {
            try {
                $results['primary'] = array(
                    'success' => true,
                    'name' => $this->primary_provider->get_name(),
                    'message' => 'Connection successful',
                );
            } catch (Exception $e) {
                $results['primary'] = array(
                    'success' => false,
                    'name' => $this->primary_provider->get_name(),
                    'message' => $e->getMessage(),
                );
            }
        }
        
        if ($this->fallback_provider) {
            try {
                $results['fallback'] = array(
                    'success' => true,
                    'name' => $this->fallback_provider->get_name(),
                    'message' => 'Connection successful',
                );
            } catch (Exception $e) {
                $results['fallback'] = array(
                    'success' => false,
                    'name' => $this->fallback_provider->get_name(),
                    'message' => $e->getMessage(),
                );
            }
        }
        
        return $results;
    }
    
    /**
     * Estimate OpenAI cost based on model and tokens
     * 
     * Pricing as of November 2025 (approximate):
     * - GPT-5: $0.015/1K input, $0.060/1K output
     * - GPT-4o: $0.005/1K input, $0.015/1K output
     * - GPT-4 Turbo: $0.010/1K input, $0.030/1K output
     * - GPT-4: $0.030/1K input, $0.060/1K output
     * - GPT-3.5 Turbo: $0.001/1K input, $0.002/1K output
     * - o-series (o1, o3): $0.015/1K input, $0.060/1K output
     * 
     * @param string $model Model name
     * @param int $prompt_tokens Input tokens
     * @param int $completion_tokens Output tokens
     * @return float Estimated cost in USD
     */
    private function estimate_openai_cost($model, $prompt_tokens, $completion_tokens) {
        // Default rates (gpt-4o: $2.50/$10.00 per 1M = $0.0025/$0.010 per 1K)
        $input_rate = 0.0025;
        $output_rate = 0.010;

        // Adjust based on model — most specific patterns first
        if ( strpos($model, 'gpt-4o-mini') !== false ) {
            $input_rate  = 0.00015;
            $output_rate = 0.0006;
        } elseif ( strpos($model, 'gpt-4o') !== false ) {
            $input_rate  = 0.0025;
            $output_rate = 0.010;
        } elseif ( strpos($model, 'o1-') === 0 || strpos($model, 'o3-') === 0 ) {
            $input_rate  = 0.015;
            $output_rate = 0.060;
        } elseif ( strpos($model, 'gpt-4-turbo') !== false || strpos($model, 'gpt-4-1106') !== false ) {
            $input_rate  = 0.010;
            $output_rate = 0.030;
        } elseif ( strpos($model, 'gpt-4') === 0 ) {
            $input_rate  = 0.030;
            $output_rate = 0.060;
        } elseif ( strpos($model, 'gpt-3.5') === 0 ) {
            $input_rate  = 0.001;
            $output_rate = 0.002;
        }

        $input_cost  = ( $prompt_tokens / 1000 ) * $input_rate;
        $output_cost = ( $completion_tokens / 1000 ) * $output_rate;

        return $input_cost + $output_cost;
    }
    
    /**
     * Estimate Claude cost based on model and tokens
     * 
     * Pricing as of November 2025 (approximate):
     * - Claude Sonnet 4/4.5: $0.003/1K input, $0.015/1K output
     * - Claude Opus 4.1: $0.015/1K input, $0.075/1K output
     * - Claude 3.5 Sonnet: $0.003/1K input, $0.015/1K output
     * - Claude 3.5 Haiku: $0.001/1K input, $0.005/1K output
     * - Claude 3 Opus: $0.015/1K input, $0.075/1K output
     * - Claude 3 Sonnet: $0.003/1K input, $0.015/1K output
     * - Claude 3 Haiku: $0.00025/1K input, $0.00125/1K output
     * 
     * @param string $model Model name
     * @param int $prompt_tokens Input tokens
     * @param int $completion_tokens Output tokens
     * @return float Estimated cost in USD
     */
    private function estimate_claude_cost($model, $prompt_tokens, $completion_tokens) {
        // Default rates (Claude Sonnet 4.5 — $3/$15 per 1M = $0.003/$0.015 per 1K)
        $input_rate = 0.003;
        $output_rate = 0.015;

        // Anthropic published rates (per 1K tokens), checked March 2026
        if (strpos($model, 'claude-haiku-4') === 0 || strpos($model, 'claude-3-5-haiku') !== false) {
            // Claude Haiku 4.5 / 3.5 Haiku: $0.80/$4.00 per 1M = $0.0008/$0.004 per 1K
            $input_rate = 0.0008;
            $output_rate = 0.004;
        } elseif (strpos($model, 'claude-sonnet-4') === 0) {
            // Claude Sonnet 4.5: $3/$15 per 1M = $0.003/$0.015 per 1K
            $input_rate = 0.003;
            $output_rate = 0.015;
        } elseif (strpos($model, 'claude-opus-4') === 0) {
            // Claude Opus 4.1: $15/$75 per 1M = $0.015/$0.075 per 1K
            $input_rate = 0.015;
            $output_rate = 0.075;
        } elseif (strpos($model, 'claude-3-opus') !== false) {
            $input_rate = 0.015;
            $output_rate = 0.075;
        } elseif (strpos($model, 'claude-3-haiku') !== false) {
            // Claude 3 Haiku: $0.25/$1.25 per 1M = $0.00025/$0.00125 per 1K
            $input_rate = 0.00025;
            $output_rate = 0.00125;
        } elseif (strpos($model, 'claude-3-sonnet') !== false) {
            $input_rate = 0.003;
            $output_rate = 0.015;
        }
        
        // Calculate cost (rates are per 1K tokens)
        $input_cost = ($prompt_tokens / 1000) * $input_rate;
        $output_cost = ($completion_tokens / 1000) * $output_rate;
        
        return $input_cost + $output_cost;
    }
}

