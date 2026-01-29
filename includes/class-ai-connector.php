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
                $this->logger->log_error('Primary provider failed: ' . $e->getMessage());
                
                // Try fallback if available
                if ($this->fallback_provider) {
                    try {
                        $result = call_user_func_array(array($this->fallback_provider, $method), $args);
                        $this->logger->log_info('Fallback provider succeeded');
                        return $result;
                    } catch (Exception $e2) {
                        $this->logger->log_error('Fallback provider also failed: ' . $e2->getMessage());
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
        $settings = MindfulSEO::get_settings();
        $primary = isset($settings['primary_provider']) ? $settings['primary_provider'] : 'openai';
        
        // Get the appropriate model
        $model = '';
        if ($primary === 'openai') {
            $model = isset($settings['openai_model']) ? $settings['openai_model'] : 'gpt-4o';
        } else {
            $model = isset($settings['claude_model']) ? $settings['claude_model'] : 'claude-3-5-sonnet-20241022';
        }
        
        // Prepare API request based on provider
        try {
            if ($primary === 'openai') {
                $api_key = isset($settings['openai_api_key']) ? $this->decrypt_api_key($settings['openai_api_key']) : '';
                if (empty($api_key)) {
                    return new WP_Error('no_api_key', 'OpenAI API key not configured');
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
                
                // Handle model-specific parameters
                if (strpos($model, 'gpt-5') === 0) {
                    // GPT-5 models use verbosity instead of max_tokens
                    $body['verbosity'] = isset($options['verbosity']) ? $options['verbosity'] : 'standard';
                } elseif (strpos($model, 'o1-') === 0 || strpos($model, 'o3-') === 0) {
                    // o-series models use max_completion_tokens
                    $body['max_completion_tokens'] = isset($options['max_tokens']) ? $options['max_tokens'] : 4000;
                } else {
                    // Standard models use max_tokens
                    $body['max_tokens'] = isset($options['max_tokens']) ? $options['max_tokens'] : 2000;
                }
                
                $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $api_key,
                        'Content-Type' => 'application/json',
                    ),
                    'body' => json_encode($body),
                    'timeout' => 60,
                    'sslverify' => true,
                    'httpversion' => '1.1',
                ));
                
                if (is_wp_error($response)) {
                    error_log('MindfulSEO OpenAI Error: ' . $response->get_error_message());
                    return $response;
                }
                
                $body = json_decode(wp_remote_retrieve_body($response), true);
                
                if (isset($body['error'])) {
                    return new WP_Error('api_error', $body['error']['message']);
                }
                
                if (!isset($body['choices'][0]['message']['content'])) {
                    return new WP_Error('invalid_response', 'Invalid API response');
                }
                
                // Log API usage
                if (isset($body['usage']) && class_exists('MFSEO_Logger')) {
                    $logger = MFSEO_Logger::get_instance();
                    $prompt_tokens = isset($body['usage']['prompt_tokens']) ? $body['usage']['prompt_tokens'] : 0;
                    $completion_tokens = isset($body['usage']['completion_tokens']) ? $body['usage']['completion_tokens'] : 0;
                    $total_tokens = $prompt_tokens + $completion_tokens;
                    
                    // Estimate cost based on model (approximate rates as of Nov 2025)
                    $cost = $this->estimate_openai_cost($model, $prompt_tokens, $completion_tokens);
                    
                    $logger->log_api_call('openai', $prompt_tokens, $completion_tokens, $cost);
                }
                
                return $body['choices'][0]['message']['content'];
                
            } else { // Claude
                $api_key = isset($settings['claude_api_key']) ? $this->decrypt_api_key($settings['claude_api_key']) : '';
                if (empty($api_key)) {
                    return new WP_Error('no_api_key', 'Claude API key not configured');
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
                
                $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
                    'headers' => array(
                        'x-api-key' => $api_key,
                        'anthropic-version' => '2023-06-01',
                        'content-type' => 'application/json',
                    ),
                    'body' => json_encode($body),
                    'timeout' => 60,
                    'sslverify' => true,
                    'httpversion' => '1.1',
                ));
                
                if (is_wp_error($response)) {
                    error_log('MindfulSEO Claude Error: ' . $response->get_error_message());
                    return $response;
                }
                
                $status_code = wp_remote_retrieve_response_code($response);
                $body = json_decode(wp_remote_retrieve_body($response), true);
                
                // Log the full response for debugging
                error_log('MindfulSEO: Claude API status code: ' . $status_code);
                error_log('MindfulSEO: Claude API response body: ' . print_r($body, true));
                
                // Handle 404 errors (model not found)
                if ($status_code === 404) {
                    return new WP_Error('model_not_found', 
                        sprintf(__('Claude model "%s" not found. This model may have been deprecated. Please update your model selection in Settings to use Claude 3.5 Sonnet (Jun 2024) or another available model.', 'mindfulseo'), $model)
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
                    error_log('MindfulSEO: Invalid Claude response structure');
                    return new WP_Error('invalid_response', 'Invalid API response');
                }
                
                // Log API usage
                if (isset($body['usage']) && class_exists('MFSEO_Logger')) {
                    $logger = MFSEO_Logger::get_instance();
                    $prompt_tokens = isset($body['usage']['input_tokens']) ? $body['usage']['input_tokens'] : 0;
                    $completion_tokens = isset($body['usage']['output_tokens']) ? $body['usage']['output_tokens'] : 0;
                    
                    // Estimate cost based on model (approximate rates as of Nov 2025)
                    $cost = $this->estimate_claude_cost($model, $prompt_tokens, $completion_tokens);
                    
                    $logger->log_api_call('claude', $prompt_tokens, $completion_tokens, $cost);
                }
                
                return $body['content'][0]['text'];
            }
        } catch (Exception $e) {
            return new WP_Error('exception', $e->getMessage());
        }
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
        // Default rates (GPT-4o)
        $input_rate = 0.005;
        $output_rate = 0.015;
        
        // Adjust based on model
        if (strpos($model, 'gpt-5') === 0 || strpos($model, 'gpt-5-codex') === 0) {
            $input_rate = 0.015;
            $output_rate = 0.060;
        } elseif (strpos($model, 'o1-') === 0 || strpos($model, 'o3-') === 0) {
            $input_rate = 0.015;
            $output_rate = 0.060;
        } elseif (strpos($model, 'gpt-4-turbo') !== false || strpos($model, 'gpt-4-1106') !== false) {
            $input_rate = 0.010;
            $output_rate = 0.030;
        } elseif (strpos($model, 'gpt-4') === 0) {
            $input_rate = 0.030;
            $output_rate = 0.060;
        } elseif (strpos($model, 'gpt-3.5') === 0) {
            $input_rate = 0.001;
            $output_rate = 0.002;
        }
        
        // Calculate cost (rates are per 1K tokens)
        $input_cost = ($prompt_tokens / 1000) * $input_rate;
        $output_cost = ($completion_tokens / 1000) * $output_rate;
        
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
        // Default rates (Claude 3.5 Sonnet)
        $input_rate = 0.003;
        $output_rate = 0.015;
        
        // Adjust based on model
        if (strpos($model, 'claude-sonnet-4') === 0) {
            $input_rate = 0.003;
            $output_rate = 0.015;
        } elseif (strpos($model, 'claude-opus-4') === 0) {
            $input_rate = 0.015;
            $output_rate = 0.075;
        } elseif (strpos($model, 'claude-3-5-haiku') !== false) {
            $input_rate = 0.001;
            $output_rate = 0.005;
        } elseif (strpos($model, 'claude-3-opus') !== false) {
            $input_rate = 0.015;
            $output_rate = 0.075;
        } elseif (strpos($model, 'claude-3-haiku') !== false) {
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

