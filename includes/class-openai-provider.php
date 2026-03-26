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
    private $api_key;
    
    /**
     * API endpoint
     */
    private $api_endpoint = 'https://api.openai.com/v1/chat/completions';
    
    /**
     * Default model — reads from saved settings so it respects the user's choice
     */
    private $default_model = 'gpt-4o';

    /**
     * Load default model from plugin settings
     */
    private function get_default_model() {
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
     * Available models
     */
    private $available_models = [
        'gpt-4o',
        'gpt-4-turbo',
        'gpt-4',
        'gpt-3.5-turbo',
    ];
    
    /**
     * Constructor
     */
    public function __construct($api_key) {
        $this->api_key = $api_key;
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
        
        // Make API request
        $response = wp_remote_post($this->api_endpoint, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ],
            'body' => json_encode($body),
            'timeout' => 60,
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
        if ( isset( $data['usage'] ) && class_exists( 'MFSEO_Logger' ) ) {
            $input_tokens  = isset( $data['usage']['prompt_tokens'] )     ? (int) $data['usage']['prompt_tokens']     : 0;
            $output_tokens = isset( $data['usage']['completion_tokens'] ) ? (int) $data['usage']['completion_tokens'] : 0;
            $cost = $this->estimate_cost( $model, $input_tokens, $output_tokens );
            MFSEO_Logger::get_instance()->log_api_call( 'openai', $input_tokens, $output_tokens, $cost, $model, $usage_context );
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

