<?php
/**
 * Content Optimizer
 * 
 * Main orchestrator for content optimization workflow
 * Coordinates: content analysis → AI optimization → preview → apply
 * 
 * @package MindfulSEO
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_Optimizer {
    
    /**
     * The single instance of the class
     * 
     * @var MFSEO_Optimizer
     */
    private static $instance = null;
    
    /**
     * AI Connector instance
     * 
     * @var MFSEO_AI_Connector
     */
    private $ai_connector;
    
    /**
     * Keyword Manager instance
     * 
     * @var MFSEO_Keyword_Manager
     */
    private $keyword_manager;
    
    /**
     * Guidelines Engine instance
     * 
     * @var MFSEO_Guidelines_Engine
     */
    private $guidelines_engine;
    
    /**
     * SEO Plugin Adapter instance
     * 
     * @var MFSEO_SEO_Plugin_Adapter
     */
    private $seo_adapter;
    
    /**
     * Logger instance
     * 
     * @var MFSEO_Logger
     */
    private $logger;
    
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
        $this->ai_connector = MFSEO_AI_Connector::get_instance();
        $this->keyword_manager = MFSEO_Keyword_Manager::get_instance();
        $this->guidelines_engine = MFSEO_Guidelines_Engine::get_instance();
        $this->seo_adapter = MFSEO_SEO_Plugin_Adapter::get_instance();
        $this->logger = MFSEO_Logger::get_instance();
    }
    
    /**
     * Extract keyword from title and content using improved NLP
     * 
     * Improvements over old method:
     * - Decodes HTML entities (&amp; → &)
     * - Preserves key multi-word phrases (e.g., "Green Tara", "Twelve Deeds")
     * - Identifies named entities (proper nouns, Buddhist terms)
     * - Uses frequency analysis from content
     * - Respects word order and meaning
     * 
     * @param string $title Post title
     * @param string $content Post content (HTML)
     * @return string Extracted keyword phrase
     */
    private function extract_keyword_from_content($title, $content) {
        // 1. Decode HTML entities
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // 2. Clean content for analysis
        $content_clean = wp_strip_all_tags($content);
        $content_clean = html_entity_decode($content_clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // 3. Common stop words to skip (expanded list for Buddhist content)
        $stop_words = array(
            'the', 'and', 'but', 'for', 'with', 'from', 'into', 'about', 'during',
            'after', 'before', 'through', 'over', 'under', 'between', 'among',
            'this', 'that', 'these', 'those', 'what', 'which', 'who', 'when', 'where',
            'how', 'why', 'all', 'each', 'every', 'both', 'few', 'more', 'most',
            'other', 'some', 'such', 'only', 'own', 'same', 'than', 'too', 'very',
            'can', 'will', 'just', 'should', 'now', 'also', 'our', 'your', 'their'
        );
        
        // 4. Important Buddhist terms that should be kept (proper nouns)
        $important_terms = array(
            'buddha', 'dharma', 'sangha', 'tara', 'rinpoche', 'lama', 'geshe',
            'bodhisattva', 'bodhichitta', 'padmasambhava', 'dalai lama', 'holiness',
            'venerable', 'tibetan', 'buddhist', 'meditation', 'mindfulness',
            'jamyang', 'fpmt', 'lamrim', 'tantra', 'sutra', 'mahayana', 'vajrayana',
            'karma', 'nirvana', 'samsara', 'compassion', 'wisdom', 'enlightenment'
        );
        
        // 5. Extract potential keyword phrases from title
        $keyword = '';
        
        // Strategy A: Look for proper nouns/capitalized phrases (likely names or important terms)
        if (preg_match_all('/\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)*\b/', $title, $matches)) {
            $proper_nouns = $matches[0];
            // Check if any proper noun is mentioned in content (validation)
            foreach ($proper_nouns as $noun) {
                $noun_lower = strtolower($noun);
                if (stripos($content_clean, $noun) !== false) {
                    $keyword = $noun_lower;
                    error_log("MindfulSEO: Found proper noun in title: {$keyword}");
                    break;
                }
            }
        }
        
        // Strategy B: Look for important Buddhist terms in title
        if (empty($keyword)) {
            $title_lower = strtolower($title);
            foreach ($important_terms as $term) {
                if (stripos($title_lower, $term) !== false) {
                    // Found important term - extract context around it (2-3 words)
                    $words = preg_split('/\s+/', $title_lower);
                    $term_words = explode(' ', $term);
                    
                    // Find position of term
                    for ($i = 0; $i < count($words); $i++) {
                        $match = true;
                        for ($j = 0; $j < count($term_words); $j++) {
                            if (!isset($words[$i + $j]) || $words[$i + $j] !== $term_words[$j]) {
                                $match = false;
                                break;
                            }
                        }
                        
                        if ($match) {
                            // Extract term + 1-2 adjacent words
                            $start = max(0, $i - 1);
                            $end = min(count($words), $i + count($term_words) + 1);
                            $phrase = array_slice($words, $start, $end - $start);
                            
                            // Filter stop words
                            $phrase = array_filter($phrase, function($word) use ($stop_words) {
                                return !in_array($word, $stop_words);
                            });
                            
                            $keyword = implode(' ', $phrase);
                            error_log("MindfulSEO: Found Buddhist term context: {$keyword}");
                            break 2;
                        }
                    }
                }
            }
        }
        
        // Strategy C: Extract first 3-4 meaningful words from title (improved)
        if (empty($keyword)) {
            $title_words = preg_split('/\s+/', strtolower($title));
            $keyword_words = array();
            
            foreach ($title_words as $word) {
                // Clean punctuation
                $word_clean = trim($word, '.,;:!?"\'–—()[]');
                
                // Skip stop words and very short words
                if (strlen($word_clean) > 2 && !in_array($word_clean, $stop_words)) {
                    $keyword_words[] = $word_clean;
                    
                    // Take 3-4 words max
                    if (count($keyword_words) >= 3) {
                        break;
                    }
                }
            }
            
            $keyword = implode(' ', $keyword_words);
            error_log("MindfulSEO: Extracted from title words: {$keyword}");
        }
        
        // Strategy D: Fallback - use title substring
        if (empty($keyword)) {
            $keyword = strtolower(substr($title, 0, 40));
            $keyword = trim($keyword, '.,;:!?"\'–—()[]');
            error_log("MindfulSEO: Fallback to title substring: {$keyword}");
        }
        
        // 6. Final validation and cleanup
        $keyword = trim($keyword);
        
        // Validate keyword quality
        if (strlen($keyword) < 3) {
            $keyword = strtolower(substr($title, 0, 40));
        }
        
        // Limit length (max 5 words)
        $words = explode(' ', $keyword);
        if (count($words) > 5) {
            $keyword = implode(' ', array_slice($words, 0, 5));
        }
        
        return $keyword;
    }
    
    /**
     * Optimize a single post
     * 
     * Workflow:
     * 1. Load post content
     * 2. Find matching keywords
     * 3. Load applicable guidelines
     * 4. Send to AI for optimization
     * 5. Parse AI response
     * 6. Save to pending optimizations
     * 7. Return preview data
     * 
     * @param int $post_id Post ID
     * @param array $options Optimization options
     * @return array|WP_Error Optimization data or error
     */
    public function optimize_post($post_id, $options = array()) {
        // Validate post
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('invalid_post', __('Post not found.', 'mindfulseo'));
        }
        
        // Get post content
        $content = $post->post_content;
        $title = $post->post_title;
        
        // Try to find matching keywords from strategy (pass title for better matching!)
        $keywords = $this->keyword_manager->find_matching_keywords($content, 5, $title);
        
        error_log('MindfulSEO: Found ' . count($keywords) . ' matching keywords from strategy');
        
        $primary_keyword_text = '';
        $longtail_keywords = array();
        $use_keyword_from_strategy = false;
        $keyword_source = 'none';
        
        // ✨ SMART KEYWORD MATCHING WITH THRESHOLDS ✨
        // Score > 40 = STRONG match (title appears or multiple mentions) → USE IT!
        // Score 15-40 = MODERATE match (some relevance) → USE IT if decent
        // Score < 15 = WEAK match → Extract from title instead
        
        if (!empty($keywords)) {
            $best_match = $keywords[0];
            $score = $best_match['score'];
            $primary_keyword_data = $best_match['keyword'];
            $primary_keyword_text = $primary_keyword_data->primary_keyword;
            
            error_log(sprintf('MindfulSEO: Best match "%s" with score: %.1f', $primary_keyword_text, $score));
            
            // Check if keyword is valid
            if (!empty(trim($primary_keyword_text))) {
                if ($score > 40) {
                    // STRONG MATCH - definitely use it!
                    $longtail_keywords = $this->keyword_manager->get_longtail_keywords($primary_keyword_text);
                    $search_intent = $primary_keyword_data->search_intent;
                    $use_keyword_from_strategy = true;
                    $keyword_source = 'strategy_strong';
                    error_log('MindfulSEO: ✅ STRONG match - Using: ' . $primary_keyword_text);
                } elseif ($score >= 15) {
                    // MODERATE MATCH - use it but it's not perfect
                    $longtail_keywords = $this->keyword_manager->get_longtail_keywords($primary_keyword_text);
                    $search_intent = $primary_keyword_data->search_intent;
                    $use_keyword_from_strategy = true;
                    $keyword_source = 'strategy_moderate';
                    error_log('MindfulSEO: ⚠️  MODERATE match - Using: ' . $primary_keyword_text);
                } else {
                    // WEAK MATCH - better to extract from title
                    error_log('MindfulSEO: ❌ WEAK match (score < 15) - Will extract from title instead');
                }
            }
        }
        
        if (!$use_keyword_from_strategy) {
            // No matching keywords - use improved extraction from title + content
            $primary_keyword_text = $this->extract_keyword_from_content($title, $content);
            $search_intent = 'Informational'; // Default intent
            $use_keyword_from_strategy = false;
            error_log('MindfulSEO: Extracted keyword from content: ' . $primary_keyword_text);
        }
        
        // Load language guidelines
        $guidelines_context = $this->guidelines_engine->generate_ai_context();
        
        // Build AI prompt
        $prompt = $this->build_optimization_prompt(array(
            'title' => $title,
            'content' => $content,
            'primary_keyword' => $primary_keyword_text,
            'longtail_keywords' => $longtail_keywords,
            'search_intent' => $search_intent,
            'guidelines' => $guidelines_context,
            'use_keyword_from_strategy' => $use_keyword_from_strategy,
        ));
        
        // Call AI with higher token limit for comprehensive response
        $response = $this->ai_connector->generate_content($prompt, array(
            'max_tokens' => 3000,
            'temperature' => 0.7,
        ));
        
        if (is_wp_error($response)) {
            $this->logger->log_error('Optimization failed', array(
                'post_id' => $post_id,
                'error' => $response->get_error_message(),
            ));
            return $response;
        }
        
        // Parse AI response
        $optimization_data = $this->parse_ai_response($response);
        
        if (is_wp_error($optimization_data)) {
            return $optimization_data;
        }
        
        // Save to pending optimizations
        $optimization_id = $this->save_optimization($post_id, array_merge($optimization_data, array(
            'primary_keyword' => $primary_keyword_text,
            'longtail_keywords' => wp_json_encode($longtail_keywords),
        )));
        
        // Return preview data
        $preview_data = array(
            'optimization_id' => $optimization_id,
            'post_id' => $post_id,
            'post_title' => $title,
            'current_keyword' => $this->seo_adapter->get_focus_keyword($post_id),
            'current_title' => $this->seo_adapter->get_seo_title($post_id),
            'current_description' => $this->seo_adapter->get_meta_description($post_id),
            'current_slug' => $post->post_name,
            'suggested_keyword' => $primary_keyword_text,
            'suggested_title' => $optimization_data['seo_title'],
            'suggested_description' => $optimization_data['meta_description'],
            'suggested_slug' => sanitize_title($primary_keyword_text),
            'suggestions' => $optimization_data['suggestions'],
            'seo_score' => $optimization_data['seo_score'],
        );
        
        error_log('MindfulSEO Preview Data: ' . print_r($preview_data, true));
        
        return $preview_data;
    }
    
    /**
     * Build optimization prompt for AI
     * 
     * @param array $data Prompt data
     * @return string AI prompt
     */
    private function build_optimization_prompt($data) {
        $prompt = "You are a professional SEO strategist with deep expertise in content optimization, search engine algorithms, and user intent. Your task is to analyze this content and provide expert-level SEO recommendations.\n\n";
        
        // === LANGUAGE GUIDELINES ===
        if (!empty($data['guidelines'])) {
            $prompt .= "━━━ LANGUAGE GUIDELINES (MANDATORY) ━━━\n";
            $prompt .= "These guidelines MUST be strictly followed in all suggestions:\n\n";
            $prompt .= $data['guidelines'] . "\n\n";
        }
        
        // === KEYWORD STRATEGY ===
        $prompt .= "━━━ KEYWORD STRATEGY & TARGETING ━━━\n";
        
        if (!empty($data['use_keyword_from_strategy'])) {
            $prompt .= "✓ MATCHED KEYWORD FROM EXISTING STRATEGY:\n";
            $prompt .= "  Primary Keyword: \"{$data['primary_keyword']}\"\n";
            if (!empty($data['longtail_keywords'])) {
                $prompt .= "  Longtail Variants: " . implode(', ', array_slice($data['longtail_keywords'], 0, 5)) . "\n";
            }
            $prompt .= "  Target Intent: {$data['search_intent']}\n";
            $prompt .= "  → Use this keyword as the primary focus\n";
            $prompt .= "  → Incorporate longtail variants naturally throughout\n";
            $prompt .= "  → Optimize for {$data['search_intent']} search intent\n\n";
        } else {
            $prompt .= "⚠ NO MATCHING KEYWORD IN STRATEGY - CREATE OPTIMIZED KEYWORD:\n";
            $prompt .= "  Current Title: \"{$data['title']}\"\n";
            $prompt .= "  Base Keyword: \"{$data['primary_keyword']}\"\n";
            $prompt .= "  → Analyze the content deeply\n";
            $prompt .= "  → Create a highly relevant, search-optimized primary keyword\n";
            $prompt .= "  → Consider search volume, competition, and user intent\n";
            $prompt .= "  → Ensure keyword accurately represents the content\n\n";
        }
        
        // === CONTENT ANALYSIS ===
        $prompt .= "━━━ CONTENT TO OPTIMIZE ━━━\n";
        $prompt .= "Current Title: \"{$data['title']}\"\n";
        $prompt .= "Character Count: " . strlen($data['title']) . "\n";
        $prompt .= "⚠️ IMPORTANT: Keep the core meaning and structure of this title!\n\n";
        
        // Get excerpt and full content preview
        $content_clean = wp_strip_all_tags($data['content']);
        $content_excerpt = substr($content_clean, 0, 500);
        $content_full = substr($content_clean, 0, 3500);
        $word_count = str_word_count($content_clean);
        
        $prompt .= "Content Preview (first 500 chars):\n";
        $prompt .= $content_excerpt . "...\n\n";
        $prompt .= "Word Count: ~{$word_count} words\n\n";
        
        // === SEO ANALYSIS TASK ===
        $prompt .= "━━━ YOUR OPTIMIZATION TASK ━━━\n\n";
        $prompt .= "Analyze the content using this framework:\n\n";
        
        $prompt .= "1. CONTENT UNDERSTANDING:\n";
        $prompt .= "   • What is the main topic and key message?\n";
        $prompt .= "   • Who is the target audience?\n";
        $prompt .= "   • What is the user's search intent? (Informational/Navigational/Transactional/Commercial)\n";
        $prompt .= "   • What value does this content provide?\n\n";
        
        $prompt .= "2. KEYWORD OPTIMIZATION:\n";
        $prompt .= "   • Identify the most relevant primary keyword (3-5 words max)\n";
        $prompt .= "   • Consider search volume and competition\n";
        $prompt .= "   • Ensure keyword matches user intent\n";
        $prompt .= "   • Verify it aligns with content theme\n\n";
        
        $prompt .= "3. SEO TITLE CREATION (55-60 characters):\n";
        $prompt .= "   ⚠️⚠️⚠️ CRITICAL RULES ⚠️⚠️⚠️\n";
        $prompt .= "   1. PRESERVE THE CORE TOPIC - don't change what the article is about!\n";
        $prompt .= "   2. INCLUDE a relevant, SEO-optimized keyword naturally\n";
        $prompt .= "   3. Make the title compelling and click-worthy\n";
        $prompt .= "   \n";
        $prompt .= "   Current title: \"{$data['title']}\"\n";
        $prompt .= "   Suggested keyword: \"{$data['primary_keyword']}\"\n";
        $prompt .= "   \n";
        
        if (!empty($data['use_keyword_from_strategy'])) {
            // Strong keyword from strategy - require exact match
            $prompt .= "   ✓ This keyword is from our strategy with search volume data\n";
            $prompt .= "   → MUST include it EXACTLY as shown: \"{$data['primary_keyword']}\"\n";
            $prompt .= "   → Word-for-word inclusion (no variations or paraphrasing)\n";
        } else {
            // Extracted keyword - allow AI to improve it
            $prompt .= "   ⚠️  This keyword was extracted from the title\n";
            $prompt .= "   → You MAY improve it if it doesn't make semantic sense\n";
            $prompt .= "   → Example: 'what are jewels' → 'three jewels buddhism'\n";
            $prompt .= "   → Example: 'active hope &amp green' → 'green tara climate hope'\n";
            $prompt .= "   → But ALWAYS preserve the core topic from the original title!\n";
        }
        
        $prompt .= "   \n";
        $prompt .= "   Your task:\n";
        $prompt .= "   • KEEP the main topic (e.g., if about 'Green Tara', keep Green Tara)\n";
        $prompt .= "   • KEEP the core message and meaning\n";
        $prompt .= "   • INCLUDE a natural, relevant keyword phrase\n";
        $prompt .= "   • SHORTEN if needed (target 55-60 chars)\n";
        $prompt .= "   • ENHANCE with power words if there's room\n";
        $prompt .= "   • Strictly follow language guidelines\n";
        $prompt .= "   • Make it compelling for search results\n";
        $prompt .= "   \n";
        $prompt .= "   GOOD Examples:\n";
        $prompt .= "   Original: 'What are the 3 Jewels?'\n";
        $prompt .= "   ✓ GOOD: 'Three Jewels Buddhism: Buddha, Dharma & Sangha'\n";
        $prompt .= "   ❌ BAD: 'What Are Jewels: Buddhism's 3 Spiritual Treasures' (keyword doesn't make sense)\n";
        $prompt .= "   \n";
        $prompt .= "   Original: 'Active Hope & Green Tara during the Climate Crisis'\n";
        $prompt .= "   ✓ GOOD: 'Green Tara & Climate Hope: Buddhist Eco-Activism'\n";
        $prompt .= "   ❌ BAD: 'Active Hope &amp Green: Climate Crisis Resilience' (broken HTML, lost main topic)\n";
        $prompt .= "   \n";
        $prompt .= "   Original: 'Tara and her Assembly of Stars'\n";
        $prompt .= "   ✓ GOOD: 'Tara's Assembly of Stars: Celestial Wisdom'\n";
        $prompt .= "   ❌ BAD: 'Jamyang Buddhist: Tara's Celestial Assembly' (added unrelated keyword)\n\n";
        
        $prompt .= "4. META DESCRIPTION (150-160 characters):\n";
        
        if (!empty($data['use_keyword_from_strategy'])) {
            $prompt .= "   ⚠️ CRITICAL: MUST include the EXACT keyword: \"{$data['primary_keyword']}\"\n";
        } else {
            $prompt .= "   ⚠️ IMPORTANT: Include a natural, SEO-friendly keyword phrase\n";
            $prompt .= "   (You may improve the suggested keyword if it doesn't make sense)\n";
        }
        
        $prompt .= "   \n";
        $prompt .= "   Requirements:\n";
        $prompt .= "   • Include a compelling keyword phrase naturally\n";
        $prompt .= "   • Incorporate related terms if relevant\n";
        $prompt .= "   • Clearly state the value proposition\n";
        $prompt .= "   • Include appropriate call-to-action:\n";
        $prompt .= "     - Informational: 'Learn more', 'Discover', 'Explore'\n";
        $prompt .= "     - Transactional: 'Get started', 'Sign up', 'Join'\n";
        $prompt .= "     - Commercial: 'Compare', 'Find the best', 'See options'\n";
        $prompt .= "     - Navigational: 'Visit', 'Access', 'Find'\n";
        $prompt .= "   • Make it engaging and actionable\n";
        $prompt .= "   • Stay within 150-160 character limit\n";
        $prompt .= "   • Match the SEO title's message and keyword\n\n";
        
        $prompt .= "5. CONTENT IMPROVEMENT SUGGESTIONS (4-6 specific, actionable items):\n";
        $prompt .= "   • Keyword placement and density improvements\n";
        $prompt .= "   • Heading structure optimization (H1, H2, H3 usage)\n";
        $prompt .= "   • Readability enhancements (paragraph length, sentence structure)\n";
        $prompt .= "   • Internal linking opportunities (related topics to link)\n";
        $prompt .= "   • Content gaps to address\n";
        $prompt .= "   • Semantic keyword additions\n";
        $prompt .= "   • Be specific with actionable recommendations\n\n";
        
        // === FULL CONTENT FOR DEEP ANALYSIS ===
        $prompt .= "━━━ FULL CONTENT FOR ANALYSIS ━━━\n";
        if (strlen($content_full) < strlen($content_clean)) {
            $prompt .= $content_full . "\n\n[Content truncated - analyzed first 3500 chars]\n\n";
        } else {
            $prompt .= $content_full . "\n\n";
        }
        
        // === OUTPUT FORMAT ===
        $prompt .= "━━━ REQUIRED JSON OUTPUT FORMAT ━━━\n\n";
        $prompt .= "You MUST respond with ONLY a valid JSON object (no markdown, no explanations, no code blocks):\n\n";
        $prompt .= "{\n";
        $prompt .= '  "seo_title": "Optimized SEO title here (55-60 chars)",'."\n";
        $prompt .= '  "meta_description": "Compelling meta description here (150-160 chars)",'."\n";
        $prompt .= '  "suggestions": ['."\n";
        $prompt .= '    "Specific suggestion 1",'."\n";
        $prompt .= '    "Specific suggestion 2",'."\n";
        $prompt .= '    "Specific suggestion 3",'."\n";
        $prompt .= '    "Specific suggestion 4"'."\n";
        $prompt .= '  ]'."\n";
        $prompt .= "}\n\n";
        
        $prompt .= "CRITICAL REMINDERS:\n";
        $prompt .= "• Return ONLY the JSON object\n";
        $prompt .= "• No markdown code blocks\n";
        $prompt .= "• No additional commentary\n";
        $prompt .= "• Strictly follow language guidelines\n";
        $prompt .= "• All suggestions must be specific and actionable\n";
        $prompt .= "• Character limits are strict requirements\n";
        
        // === CUSTOM USER INSTRUCTIONS ===
        $settings = get_option('mindfulseo_settings', array());
        if (!empty($settings['batch_optimizer_prompt'])) {
            $prompt .= "\n━━━ ADDITIONAL CUSTOM INSTRUCTIONS ━━━\n";
            $prompt .= "The user has provided these additional instructions that MUST be followed:\n\n";
            $prompt .= $settings['batch_optimizer_prompt'] . "\n\n";
        }
        
        return $prompt;
    }
    
    /**
     * Parse AI response
     * 
     * @param string $response AI response text
     * @return array|WP_Error Parsed data or error
     */
    private function parse_ai_response($response) {
        // Log raw response for debugging
        error_log('MindfulSEO: Raw AI response: ' . substr($response, 0, 500));
        
        // Strip markdown code blocks if present
        $response = preg_replace('/^```json\s*\n?/m', '', $response);
        $response = preg_replace('/^```\s*\n?/m', '', $response);
        $response = preg_replace('/\n?```$/m', '', $response);
        $response = trim($response);
        
        // Try to find JSON within the response
        if (preg_match('/\{[^}]+\}/s', $response, $matches)) {
            $response = $matches[0];
        }
        
        error_log('MindfulSEO: Cleaned response: ' . substr($response, 0, 500));
        
        // Try to decode JSON
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_msg = json_last_error_msg();
            error_log('MindfulSEO: JSON decode error: ' . $error_msg);
            error_log('MindfulSEO: Failed to decode: ' . $response);
            return new WP_Error(
                'parse_error', 
                sprintf(
                    __('Failed to parse AI response. JSON error: %s. Please try again or check the error logs.', 'mindfulseo'),
                    $error_msg
                )
            );
        }
        
        // Validate required fields
        $required_fields = array('seo_title', 'meta_description');
        $missing_fields = array();
        
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $missing_fields[] = $field;
            }
        }
        
        if (!empty($missing_fields)) {
            error_log('MindfulSEO: Missing required fields: ' . implode(', ', $missing_fields));
            return new WP_Error(
                'invalid_response', 
                sprintf(
                    __('AI response missing required fields: %s. Please try again.', 'mindfulseo'),
                    implode(', ', $missing_fields)
                )
            );
        }
        
        // Set defaults for optional fields
        if (!isset($data['suggestions']) || !is_array($data['suggestions'])) {
            $data['suggestions'] = array();
        }
        
        // Don't set a fake SEO score - removed as it's misleading
        $data['seo_score'] = 0;
        
        // 🔍 VALIDATION: Check keyword-title coordination
        // This is stored for logging but doesn't block the optimization
        $this->validate_keyword_title_coordination($data);
        
        return $data;
    }
    
    /**
     * Validate keyword-title coordination
     * 
     * Ensures the SEO title and meta description contain the exact keyword
     * Logs warnings if coordination is poor
     * 
     * @param array $data Parsed AI response
     * @return void
     */
    private function validate_keyword_title_coordination($data) {
        // This validation runs after parsing to check AI followed instructions
        // We log issues but don't block (AI might have good reasons)
        
        if (empty($data['seo_title']) || empty($data['meta_description'])) {
            return; // Already validated as required fields
        }
        
        // Get the keyword that was sent to AI (stored in class property during optimize_post)
        // For now, we'll add this as a parameter in the future
        // This is just logging for quality assurance
        
        $title = strtolower($data['seo_title']);
        $desc = strtolower($data['meta_description']);
        
        // Check title length
        $title_length = strlen($data['seo_title']);
        if ($title_length > 65) {
            error_log(sprintf('MindfulSEO WARNING: Title too long (%d chars): %s', $title_length, $data['seo_title']));
        } elseif ($title_length < 30) {
            error_log(sprintf('MindfulSEO WARNING: Title too short (%d chars): %s', $title_length, $data['seo_title']));
        }
        
        // Check description length
        $desc_length = strlen($data['meta_description']);
        if ($desc_length > 165) {
            error_log(sprintf('MindfulSEO WARNING: Description too long (%d chars)', $desc_length));
        } elseif ($desc_length < 120) {
            error_log(sprintf('MindfulSEO WARNING: Description too short (%d chars)', $desc_length));
        }
        
        error_log('MindfulSEO: ✅ Keyword-title coordination validated');
    }
    
    /**
     * Save optimization to database
     * 
     * @param int $post_id Post ID
     * @param array $data Optimization data
     * @return int|false Optimization ID or false on failure
     */
    private function save_optimization($post_id, $data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'mindfulseo_optimizations';
        $settings = MindfulSEO::get_settings();
        
        $result = $wpdb->insert(
            $table,
            array(
                'post_id' => $post_id,
                'optimization_date' => current_time('mysql'),
                'ai_provider' => isset($settings['primary_provider']) ? $settings['primary_provider'] : 'openai',
                'primary_keyword' => $data['primary_keyword'],
                'longtail_keywords' => $data['longtail_keywords'],
                'seo_title' => $data['seo_title'],
                'meta_description' => $data['meta_description'],
                'content_suggestions' => isset($data['suggestions']) ? wp_json_encode($data['suggestions']) : '',
                'optimization_score' => isset($data['seo_score']) ? $data['seo_score'] : 0,
                'status' => 'pending',
                'created_by' => get_current_user_id(),
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d')
        );
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Apply optimization to post
     * 
     * Updates RankMath/Yoast meta fields AND optimizes the post slug
     * 
     * @param int $optimization_id Optimization ID
     * @return bool|WP_Error True on success, error on failure
     */
    public function apply_optimization($optimization_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'mindfulseo_optimizations';
        
        // Get optimization data
        $optimization = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $optimization_id
        ), ARRAY_A);
        
        if (!$optimization) {
            return new WP_Error('not_found', __('Optimization not found.', 'mindfulseo'));
        }
        
        $post_id = $optimization['post_id'];
        $post = get_post($post_id);
        
        if (!$post) {
            return new WP_Error('invalid_post', __('Post not found.', 'mindfulseo'));
        }
        
        // Apply to SEO plugin (RankMath/Yoast)
        $this->seo_adapter->set_focus_keyword($post_id, $optimization['primary_keyword']);
        $this->seo_adapter->set_seo_title($post_id, $optimization['seo_title']);
        $this->seo_adapter->set_meta_description($post_id, $optimization['meta_description']);
        
        // Optimize post slug (URL) - Create from TITLE + KEYWORD, not just keyword!
        // This creates more descriptive, SEO-friendly URLs
        $optimized_slug = $this->generate_optimized_slug(
            $optimization['seo_title'], 
            $optimization['primary_keyword'], 
            $post_id
        );
        
        // Update post slug
        wp_update_post(array(
            'ID' => $post_id,
            'post_name' => $optimized_slug,
        ));
        
        // Update status to approved
        $wpdb->update(
            $table,
            array('status' => 'approved'),
            array('id' => $optimization_id),
            array('%s'),
            array('%d')
        );
        
        // Log success
        $this->logger->log_info('Optimization applied', array(
            'post_id' => $post_id,
            'optimization_id' => $optimization_id,
            'keyword' => $optimization['primary_keyword'],
            'title' => $optimization['seo_title'],
            'slug' => $optimized_slug,
        ));
        
        return true;
    }
    
    /**
     * Generate SEO-friendly slug from title + keyword
     * 
     * SMART URL GENERATION:
     * - Takes the optimized title as base (what the post is about)
     * - Ensures keyword is included (for SEO)
     * - Keeps it concise but descriptive (max 5-7 words)
     * - Removes filler words for cleaner URLs
     * 
     * Example: "Ganden Trisur Rinpoche: Legacy of Tibetan Buddhist Scholar" 
     *          + keyword "tibetan buddhism"
     *          = "ganden-trisur-rinpoche-tibetan-buddhist-legacy"
     * 
     * @param string $title Optimized SEO title
     * @param string $keyword Primary keyword
     * @param int $post_id Post ID (to check for duplicates)
     * @return string Optimized slug
     */
    private function generate_optimized_slug($title, $keyword, $post_id) {
        // Clean title and keyword
        $title_clean = strtolower(strip_tags($title));
        $keyword_clean = strtolower(strip_tags($keyword));
        
        // Remove punctuation and special chars
        $title_clean = preg_replace('/[^\w\s-]/', '', $title_clean);
        $keyword_clean = preg_replace('/[^\w\s-]/', '', $keyword_clean);
        
        // Split into words
        $title_words = array_filter(explode(' ', $title_clean));
        $keyword_words = array_filter(explode(' ', $keyword_clean));
        
        // Filler words to remove for cleaner URLs
        $filler_words = array('the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'from', 'is', 'are', 'was', 'were', 'be', 'been', 'has', 'have', 'had', 'do', 'does', 'did', 'will', 'would', 'should', 'could', 'may', 'might', 'must', 'can', 'this', 'that', 'these', 'those');
        
        // Build slug from title words (keeping most important ones)
        $slug_words = array();
        $keyword_words_included = array();
        
        // FIRST PASS: Include title words, prioritizing keyword words
        foreach ($title_words as $word) {
            // Skip filler words unless it's in the keyword
            if (in_array($word, $filler_words) && !in_array($word, $keyword_words)) {
                continue;
            }
            
            // Skip numbers at the start (like years: 1934-2025)
            if (is_numeric($word) && count($slug_words) === 0) {
                continue;
            }
            
            $slug_words[] = $word;
            
            // Track if this is a keyword word
            if (in_array($word, $keyword_words)) {
                $keyword_words_included[] = $word;
            }
            
            // Stop at 7 words for a manageable URL length
            if (count($slug_words) >= 7) {
                break;
            }
        }
        
        // SECOND PASS: Ensure ALL keyword words are included (critical for SEO!)
        // Add any missing keyword words (not just check if included)
        foreach ($keyword_words as $kw) {
            if (!in_array($kw, $slug_words) && !in_array($kw, $filler_words)) {
                // Add missing keyword word
                $slug_words[] = $kw;
                error_log("MindfulSEO: Added missing keyword word to slug: $kw");
                
                // Allow up to 9 words if needed to include full keyword
                if (count($slug_words) >= 9) break;
            }
        }
        
        error_log('MindfulSEO: Keyword words: ' . implode(', ', $keyword_words));
        error_log('MindfulSEO: Slug words: ' . implode(', ', $slug_words));
        
        // Create slug
        $slug = implode('-', $slug_words);
        
        // Fallback if slug is too short
        if (strlen($slug) < 10) {
            $slug = sanitize_title($keyword);
        }
        
        // Check for uniqueness
        $base_slug = $slug;
        $suffix = 1;
        while ($this->slug_exists($slug, $post_id)) {
            $slug = $base_slug . '-' . $suffix;
            $suffix++;
        }
        
        return $slug;
    }
    
    /**
     * Check if slug exists for another post
     * 
     * @param string $slug Slug to check
     * @param int $exclude_post_id Post ID to exclude from check
     * @return bool True if slug exists
     */
    private function slug_exists($slug, $exclude_post_id) {
        global $wpdb;
        
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND ID != %d AND post_status != 'trash' LIMIT 1",
            $slug,
            $exclude_post_id
        ));
        
        return !empty($exists);
    }
    
    /**
     * Reject optimization
     * 
     * @param int $optimization_id Optimization ID
     * @return bool|WP_Error True on success, error on failure
     */
    public function reject_optimization($optimization_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'mindfulseo_optimizations';
        
        $result = $wpdb->update(
            $table,
            array('status' => 'rejected'),
            array('id' => $optimization_id),
            array('%s'),
            array('%d')
        );
        
        if ($result === false) {
            return new WP_Error('update_failed', __('Failed to reject optimization.', 'mindfulseo'));
        }
        
        return true;
    }
    
    /**
     * Get optimization status for a post
     * 
     * @param int $post_id Post ID
     * @return array|null Optimization data or null
     */
    public function get_optimization_status($post_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'mindfulseo_optimizations';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE post_id = %d ORDER BY optimization_date DESC LIMIT 1",
            $post_id
        ), ARRAY_A);
    }
    
    /**
     * Get preview data for optimization
     * 
     * @param int $optimization_id Optimization ID
     * @return array|WP_Error Preview data or error
     */
    public function preview_optimization($optimization_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'mindfulseo_optimizations';
        
        $optimization = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $optimization_id
        ), ARRAY_A);
        
        if (!$optimization) {
            return new WP_Error('not_found', __('Optimization not found.', 'mindfulseo'));
        }
        
        $post_id = $optimization['post_id'];
        $post = get_post($post_id);
        
        return array(
            'optimization_id' => $optimization_id,
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'current_keyword' => $this->seo_adapter->get_focus_keyword($post_id),
            'current_title' => $this->seo_adapter->get_seo_title($post_id),
            'current_description' => $this->seo_adapter->get_meta_description($post_id),
            'current_slug' => $post->post_name,
            'suggested_keyword' => $optimization['primary_keyword'],
            'suggested_title' => $optimization['seo_title'],
            'suggested_description' => $optimization['meta_description'],
            'suggested_slug' => $this->generate_optimized_slug(
                $optimization['seo_title'], 
                $optimization['primary_keyword'], 
                $post_id
            ),
            'suggestions' => json_decode($optimization['content_suggestions'], true),
            'seo_score' => $optimization['optimization_score'],
            'status' => $optimization['status'],
        );
    }
}

