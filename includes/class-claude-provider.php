<?php
/**
 * Claude Provider Class
 *
 * Handles all interactions with the Anthropic Claude API
 *
 * @package MindfulSEO
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_Claude_Provider {
    
    /**
     * API key
     */
    private $api_key;
    
    /**
     * API endpoint
     */
    private $api_endpoint = 'https://api.anthropic.com/v1/messages';
    
    /**
     * Default model — reads from saved settings so it respects the user's choice
     */
    private $default_model = 'claude-sonnet-4-5';

    /**
     * Load default model from plugin settings
     */
    private function get_default_model() {
        $settings = get_option( 'mindfulseo_settings', array() );
        return ! empty( $settings['claude_model'] ) ? $settings['claude_model'] : $this->default_model;
    }
    
    /**
     * Available models
     */
    private $available_models = [
        'claude-sonnet-4-5',
        'claude-opus-4-1',
        'claude-3-5-sonnet-20240620',
        'claude-3-opus-20240229',
    ];
    
    /**
     * Constructor
     */
    public function __construct($api_key) {
        $this->api_key = $api_key;
    }

    public function get_name() {
        return 'Claude';
    }

    public function get_model() {
        $settings = get_option('mindfulseo_settings', array());
        return !empty($settings['claude_model']) ? $settings['claude_model'] : 'claude-sonnet-4-5';
    }
    
    /**
     * Test API connection
     */
    public function test_connection() {
        try {
            $response = $this->call_api(
                'Respond with "OK" if you can read this.',
                'claude-sonnet-4-5',
                50,
                0.7,
                'connection_test'
            );
            
            if ($response && isset($response['content'][0]['text'])) {
                return [
                    'success' => true,
                    'message' => __('Claude connection successful!', 'mindfulseo'),
                ];
            }
            
            return [
                'success' => false,
                'message' => __('Unexpected response from Claude API.', 'mindfulseo'),
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Call Claude API
     */
    public function call_api($prompt, $model = null, $max_tokens = 1000, $temperature = 0.7, $usage_context = '') {
        if (empty($this->api_key)) {
            throw new Exception(__('Claude API key is not configured.', 'mindfulseo'));
        }

        if (!$model) {
            $model = $this->get_default_model();
        }
        
        // Build request body (Claude uses different format than OpenAI)
        $body = [
            'model' => $model,
            'max_tokens' => $max_tokens,
            'temperature' => $temperature,
            'system' => 'You are an expert SEO consultant and content writer. Follow any language guidelines provided in the prompt.',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
        ];
        
        // Make API request
        $response = wp_remote_post($this->api_endpoint, [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $this->api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => json_encode($body),
            'timeout' => 60,
        ]);
        
        // Handle errors
        if (is_wp_error($response)) {
            throw new Exception(
                sprintf(__('Claude API request failed: %s', 'mindfulseo'), $response->get_error_message())
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
                sprintf(__('Claude API error (%d): %s', 'mindfulseo'), $response_code, $error_message)
            );
        }
        
        // Parse response
        $data = json_decode($response_body, true);
        
        if (!$data || !isset($data['content'])) {
            throw new Exception(__('Invalid response from Claude API.', 'mindfulseo'));
        }
        
        // Log usage to the MindfulSEO cost tracker
        if ( class_exists( 'MFSEO_Logger' ) ) {
            $norm = isset( $data['usage'] ) ? MFSEO_Logger::normalize_usage_tokens( $data['usage'] ) : null;
            $call_kind = ( strpos( $usage_context, 'connection_test' ) !== false ) ? 'connection_test' : 'production';
            if ( $norm === null ) {
                MFSEO_Logger::get_instance()->log_api_call( 'claude', 0, 0, 0, $model, $usage_context, $call_kind, 'usage_missing' );
            } else {
                $cost = $this->estimate_cost( $model, $norm['in'], $norm['out'] );
                MFSEO_Logger::get_instance()->log_api_call( 'claude', $norm['in'], $norm['out'], $cost, $model, $usage_context, $call_kind );
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
            error_log('MindfulSEO Claude Error: ' . $e->getMessage());
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
            $title = trim($response['content'][0]['text']);
            
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
            $description = trim($response['content'][0]['text']);
            
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
        if (!isset($response['content'][0]['text'])) {
            return [
                'success' => false,
                'error' => __('Invalid API response.', 'mindfulseo'),
            ];
        }
        
        $content = trim($response['content'][0]['text']);
        
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
     * Estimate cost in USD for a Claude call (rates per 1K tokens, March 2026)
     */
    private function estimate_cost( $model, $input_tokens, $output_tokens ) {
        if ( strpos( $model, 'claude-haiku-4' ) === 0 || strpos( $model, 'claude-3-5-haiku' ) !== false ) {
            $in_rate  = 0.0008;
            $out_rate = 0.004;
        } elseif ( strpos( $model, 'claude-opus-4' ) === 0 || strpos( $model, 'claude-3-opus' ) !== false ) {
            $in_rate  = 0.015;
            $out_rate = 0.075;
        } elseif ( strpos( $model, 'claude-3-haiku' ) !== false ) {
            $in_rate  = 0.00025;
            $out_rate = 0.00125;
        } else {
            // Sonnet 4.5 / 3.5 Sonnet default: $3/$15 per 1M
            $in_rate  = 0.003;
            $out_rate = 0.015;
        }
        return ( $input_tokens / 1000 * $in_rate ) + ( $output_tokens / 1000 * $out_rate );
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

