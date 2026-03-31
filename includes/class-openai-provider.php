<?php
/**
 * OpenAI Provider Class
 *
 * Handles all interactions with the OpenAI API (GPT-4, GPT-4o, GPT-4 Turbo)
 *
 * @package MindfulSEO
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_OpenAI_Provider {
    
    /**
     * API key
     */
    protected $api_key;
    
    /**
     * API endpoint
     */
    protected $api_endpoint = 'https://api.openai.com/v1/chat/completions';

    /**
     * Extra HTTP headers (e.g. OpenRouter Referer / X-Title).
     *
     * @var array<string, string>
     */
    protected $extra_request_headers = array();
    
    /**
     * Default model — reads from saved settings so it respects the user's choice
     */
    private $default_model = 'gpt-4o';

    /**
     * Load default model from plugin settings
     */
    protected function get_default_model() {
        $settings = get_option( 'mindfulseo_settings', array() );
        return ! empty( $settings['openai_model'] ) ? $settings['openai_model'] : $this->default_model;
    }

    /**
     * Estimate cost in USD for an OpenAI call (rates per 1K tokens, March 2026)
     */
    private function estimate_cost( $model, $input_tokens, $output_tokens ) {
        if ( strpos( $model, 'gpt-4o-mini' ) !== false ) {
            $in_rate = 0.00015; $out_rate = 0.0006;
        } elseif ( strpos( $model, 'gpt-4o' ) !== false ) {
            $in_rate = 0.0025;  $out_rate = 0.010;
        } elseif ( strpos( $model, 'gpt-4-turbo' ) !== false ) {
            $in_rate = 0.010;   $out_rate = 0.030;
        } elseif ( strpos( $model, 'gpt-4' ) === 0 ) {
            $in_rate = 0.030;   $out_rate = 0.060;
        } elseif ( strpos( $model, 'gpt-3.5' ) === 0 ) {
            $in_rate = 0.001;   $out_rate = 0.002;
        } else {
            $in_rate = 0.0025;  $out_rate = 0.010;
        }
        return ( $input_tokens / 1000 * $in_rate ) + ( $output_tokens / 1000 * $out_rate );
    }

    /**
     * OpenRouter USD estimate (filterable; conservative defaults by vendor prefix).
     */
    public static function estimate_openrouter_usd( $model, $input_tokens, $output_tokens ) {
        $model_l = strtolower( (string) $model );
        $in_rate  = 0.0005;
        $out_rate = 0.002;
        if ( strpos( $model_l, 'qwen' ) !== false ) {
            $in_rate = 0.0002;
            $out_rate = 0.0008;
        } elseif ( strpos( $model_l, 'minimax' ) !== false ) {
            $in_rate = 0.0004;
            $out_rate = 0.0016;
        }
        $rates = apply_filters(
            'mfseo_openrouter_cost_per_1k',
            array( 'in' => $in_rate, 'out' => $out_rate ),
            $model
        );
        $in_r = isset( $rates['in'] ) ? (float) $rates['in'] : $in_rate;
        $out_r = isset( $rates['out'] ) ? (float) $rates['out'] : $out_rate;
        return ( $input_tokens / 1000 * $in_r ) + ( $output_tokens / 1000 * $out_r );
    }

    /**
     * @param string $model
     * @param int    $input_tokens
     * @param int    $output_tokens
     * @return float
     */
    protected function estimate_usage_cost_for_endpoint( $model, $input_tokens, $output_tokens ) {
        if ( strpos( $this->api_endpoint, 'openrouter.ai' ) !== false ) {
            return self::estimate_openrouter_usd( $model, $input_tokens, $output_tokens );
        }
        return $this->estimate_cost( $model, $input_tokens, $output_tokens );
    }
    
    /**
     * Available models
     */
    private $available_models = [
        'gpt-4o',
        'gpt-4-turbo',
        'gpt-4',
        'gpt-3.5-turbo',
    ];
    
    /**
     * Constructor.
     *
     * @param string      $api_key API key (OpenAI or OpenRouter).
     * @param string|null $endpoint_override Chat completions URL, or null for OpenAI.
     * @param array       $extra_headers Optional headers merged after Content-Type and Authorization.
     */
    public function __construct($api_key, $endpoint_override = null, $extra_headers = array()) {
        $this->api_key = $api_key;
        if (is_string($endpoint_override) && $endpoint_override !== '') {
            $this->api_endpoint = $endpoint_override;
        }
        if (is_array($extra_headers) && $extra_headers !== array()) {
            $this->extra_request_headers = $extra_headers;
        }
    }

    /**
     * Provider label for status UIs.
     */
    public function get_name() {
        return strpos($this->api_endpoint, 'openrouter.ai') !== false ? 'OpenRouter' : 'OpenAI';
    }

    /**
     * Default model id for this provider instance.
     */
    public function get_model() {
        return $this->get_default_model();
    }
    
    /**
     * Test API connection
     */
    public function test_connection() {
        try {
            $response = $this->call_api(
                'Respond with "OK" if you can read this.',
                'gpt-3.5-turbo', // Use cheaper model for testing
                50,
                0.7,
                'connection_test'
            );
            
            if ($response && isset($response['choices'][0]['message']['content'])) {
                return [
                    'success' => true,
                    'message' => __('OpenAI connection successful!', 'mindfulseo'),
                ];
            }
            
            return [
                'success' => false,
                'message' => __('Unexpected response from OpenAI API.', 'mindfulseo'),
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Call OpenAI API
     */
    public function call_api($prompt, $model = null, $max_tokens = 1000, $temperature = 0.7, $usage_context = '') {
        if (empty($this->api_key)) {
            throw new Exception(__('OpenAI API key is not configured.', 'mindfulseo'));
        }
        
        if (!$model) {
            $model = $this->get_default_model();
        }
        
        // Build request body
        $body = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an expert SEO consultant and content writer. Follow any language guidelines provided in the prompt.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'max_tokens' => $max_tokens,
            'temperature' => $temperature,
        ];
        
        $headers = array_merge(
            array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ),
            $this->extra_request_headers
        );

        // Make API request
        $response = wp_remote_post($this->api_endpoint, [
            'headers' => $headers,
            'body' => wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'timeout' => 60,
            'httpversion' => '1.1',
        ]);
        
        // Handle errors
        if (is_wp_error($response)) {
            throw new Exception(
                sprintf(__('OpenAI API request failed: %s', 'mindfulseo'), $response->get_error_message())
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            $error_data = json_decode($response_body, true);
            $error_message = isset($error_data['error']['message']) 
                ? $error_data['error']['message'] 
                : __('Unknown API error', 'mindfulseo');
            
            throw new Exception(
                sprintf(__('OpenAI API error (%d): %s', 'mindfulseo'), $response_code, $error_message)
            );
        }
        
        // Parse response
        $data = json_decode($response_body, true);
        
        if (!$data || !isset($data['choices'])) {
            throw new Exception(__('Invalid response from OpenAI API.', 'mindfulseo'));
        }
        
        // Log usage to the MindfulSEO cost tracker
        if ( class_exists( 'MFSEO_Logger' ) ) {
            $_mfseo_log = strpos($this->api_endpoint, 'openrouter.ai') !== false ? 'openrouter' : 'openai';
            $norm = isset( $data['usage'] ) ? MFSEO_Logger::normalize_usage_tokens( $data['usage'] ) : null;
            $call_kind = ( strpos( $usage_context, 'connection_test' ) !== false ) ? 'connection_test' : 'production';
            if ( $norm === null ) {
                MFSEO_Logger::get_instance()->log_api_call( $_mfseo_log, 0, 0, 0, $model, $usage_context, $call_kind, 'usage_missing' );
            } else {
                $cost = $this->estimate_usage_cost_for_endpoint( $model, $norm['in'], $norm['out'] );
                MFSEO_Logger::get_instance()->log_api_call( $_mfseo_log, $norm['in'], $norm['out'], $cost, $model, $usage_context, $call_kind );
            }
        }

        return $data;
    }
    
    /**
     * Generate SEO optimization for content
     */
    public function optimize_content($content, $keyword, $guidelines = [], $search_intent = 'Informational') {
        $prompt = $this->create_optimization_prompt($content, $keyword, $guidelines, $search_intent);
        
        try {
            $response = $this->call_api($prompt, $this->get_default_model(), 1500, 0.7, 'optimize_content');
            return $this->parse_optimization_response($response);
            
        } catch (Exception $e) {
            error_log('MindfulSEO OpenAI Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Generate SEO title
     */
    public function generate_seo_title($content, $keyword, $guidelines = []) {
        $guidelines_text = $this->format_guidelines($guidelines);
        
        $prompt = <<<PROMPT
Generate an SEO-optimized title for this content.

KEYWORD: {$keyword}

CONTENT EXCERPT:
{$content}

LANGUAGE GUIDELINES:
{$guidelines_text}

REQUIREMENTS:
- Include the keyword naturally
- 55-60 characters long
- Compelling and click-worthy
- Respect any language guidelines provided
- No clickbait or exaggeration

Respond with ONLY the title, no explanations.
PROMPT;
        
        try {
            $response = $this->call_api($prompt, $this->get_default_model(), 100, 0.8, 'generate_seo_title');
            $title = trim($response['choices'][0]['message']['content']);
            
            // Remove quotes if AI added them
            $title = trim($title, '"\'');
            
            return [
                'success' => true,
                'title' => $title,
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Generate meta description
     */
    public function generate_meta_description($content, $keyword, $guidelines = []) {
        $guidelines_text = $this->format_guidelines($guidelines);
        
        $prompt = <<<PROMPT
Generate an SEO-optimized meta description for this content.

KEYWORD: {$keyword}

CONTENT EXCERPT:
{$content}

LANGUAGE GUIDELINES:
{$guidelines_text}

REQUIREMENTS:
- Include the keyword naturally
- 150-155 characters long
- Clear value proposition
- Call-to-action where appropriate
- Respect any language guidelines provided

Respond with ONLY the meta description, no explanations.
PROMPT;
        
        try {
            $response = $this->call_api($prompt, $this->get_default_model(), 100, 0.8, 'generate_meta_description');
            $description = trim($response['choices'][0]['message']['content']);
            
            // Remove quotes if AI added them
            $description = trim($description, '"\'');
            
            return [
                'success' => true,
                'description' => $description,
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Create optimization prompt
     */
    private function create_optimization_prompt($content, $keyword, $guidelines, $search_intent) {
        $guidelines_text = $this->format_guidelines($guidelines);
        $content_excerpt = $this->truncate_content($content, 3000);
        
        $prompt = <<<PROMPT
You are an SEO expert optimizing website content.

LANGUAGE GUIDELINES:
{$guidelines_text}

KEYWORD STRATEGY:
Primary Keyword: {$keyword}
Search Intent: {$search_intent}

CONTENT TO OPTIMIZE:
{$content_excerpt}

TASK:
Generate SEO optimizations while respecting any language guidelines provided. Provide:

1. **Optimized SEO Title** (55-60 characters)
   - Include primary keyword naturally
   - Compelling and click-worthy
   - Respect any language guidelines provided

2. **Meta Description** (150-155 characters)
   - Include primary keyword
   - Clear value proposition
   - Call-to-action appropriate for {$search_intent} intent

3. **Keyword Placement Suggestions**
   - Where to naturally include keywords in content
   - Heading suggestions (H2, H3)

4. **Content Improvements**
   - Readability enhancements
   - Structure improvements
   - Guideline compliance issues

FORMAT YOUR RESPONSE AS JSON:
{
  "seo_title": "...",
  "meta_description": "...",
  "keyword_placements": ["...", "..."],
  "content_improvements": ["...", "..."],
  "seo_score": 85
}

Respond with ONLY the JSON, no explanations before or after.
PROMPT;
        
        return $prompt;
    }
    
    /**
     * Parse optimization response
     */
    private function parse_optimization_response($response) {
        if (!isset($response['choices'][0]['message']['content'])) {
            return [
                'success' => false,
                'error' => __('Invalid API response.', 'mindfulseo'),
            ];
        }
        
        $content = trim($response['choices'][0]['message']['content']);
        
        // Try to extract JSON from response
        // Sometimes AI adds explanations before/after JSON
        preg_match('/\{.*\}/s', $content, $matches);
        
        if (empty($matches)) {
            return [
                'success' => false,
                'error' => __('Could not parse JSON from API response.', 'mindfulseo'),
            ];
        }
        
        $data = json_decode($matches[0], true);
        
        if (!$data) {
            return [
                'success' => false,
                'error' => __('Invalid JSON in API response.', 'mindfulseo'),
            ];
        }
        
        return [
            'success' => true,
            'data' => $data,
        ];
    }
    
    /**
     * Format guidelines for prompt
     */
    private function format_guidelines($guidelines) {
        if (empty($guidelines)) {
            return "No specific guidelines provided.";
        }
        
        $formatted = [];
        
        foreach ($guidelines as $guideline) {
            if (isset($guideline['avoid_term']) && isset($guideline['preferred_term'])) {
                $formatted[] = sprintf(
                    '- Avoid "%s", use "%s" instead',
                    $guideline['avoid_term'],
                    $guideline['preferred_term']
                );
            }
        }
        
        return !empty($formatted) ? implode("\n", $formatted) : "No specific guidelines provided.";
    }
    
    /**
     * Truncate content for API
     */
    private function truncate_content($content, $max_chars = 3000) {
        // PHP 8.x null safety: ensure content is a string
        $content = is_string($content) ? $content : '';
        
        // Remove HTML tags
        $content = wp_strip_all_tags($content);
        
        // Truncate if too long
        if (strlen($content) > $max_chars) {
            $content = substr($content, 0, $max_chars) . '...';
        }
        
        return $content;
    }
    
    /**
     * Get available models
     */
    public function get_available_models() {
        return $this->available_models;
    }
    
    /**
     * Set default model
     */
    public function set_default_model($model) {
        if (in_array($model, $this->available_models)) {
            $this->default_model = $model;
        }
    }
}

