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
     * Default model
     */
    private $default_model = 'claude-3-5-sonnet-20241022';
    
    /**
     * Available models
     */
    private $available_models = [
        'claude-3-5-sonnet-20241022',
        'claude-3-opus-20240229',
        'claude-3-sonnet-20240229',
        'claude-3-haiku-20240307',
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
                'claude-3-haiku-20240307', // Use cheaper model for testing
                50 // Max tokens
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
    public function call_api($prompt, $model = null, $max_tokens = 1000, $temperature = 0.7) {
        if (empty($this->api_key)) {
            throw new Exception(__('Claude API key is not configured.', 'mindfulseo'));
        }
        
        if (!$model) {
            $model = $this->default_model;
        }
        
        // Build request body (Claude uses different format than OpenAI)
        $body = [
            'model' => $model,
            'max_tokens' => $max_tokens,
            'temperature' => $temperature,
            'system' => 'You are an expert SEO consultant and content writer specializing in Buddhist content for FPMT (Foundation for the Preservation of the Mahayana Tradition).',
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
        
        // Log usage
        if (isset($data['usage'])) {
            do_action('mindfulseo_api_usage', 'claude', $model, $data['usage']);
        }
        
        return $data;
    }
    
    /**
     * Generate SEO optimization for content
     */
    public function optimize_content($content, $keyword, $guidelines = [], $search_intent = 'Informational') {
        $prompt = $this->create_optimization_prompt($content, $keyword, $guidelines, $search_intent);
        
        try {
            $response = $this->call_api($prompt, $this->default_model, 1500);
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
- Respect Buddhist terminology guidelines
- No clickbait or exaggeration

Respond with ONLY the title, no explanations.
PROMPT;
        
        try {
            $response = $this->call_api($prompt, $this->default_model, 100, 0.8);
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
- Respect Buddhist terminology guidelines

Respond with ONLY the meta description, no explanations.
PROMPT;
        
        try {
            $response = $this->call_api($prompt, $this->default_model, 100, 0.8);
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
You are an SEO expert optimizing content for FPMT (Foundation for the Preservation of the Mahayana Tradition).

LANGUAGE GUIDELINES:
{$guidelines_text}

KEYWORD STRATEGY:
Primary Keyword: {$keyword}
Search Intent: {$search_intent}

CONTENT TO OPTIMIZE:
{$content_excerpt}

TASK:
Generate SEO optimizations while respecting the language guidelines and Buddhist terminology. Provide:

1. **Optimized SEO Title** (55-60 characters)
   - Include primary keyword naturally
   - Compelling and click-worthy
   - Respect FPMT language preferences

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

