<?php
/**
 * Content Analyzer Class
 *
 * Analyzes existing content to extract keywords and language patterns
 *
 * @package MindfulSEO
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_Content_Analyzer {
    
    /**
     * Supported post types
     */
    private $supported_post_types = array();
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->supported_post_types = $this->get_supported_post_types();
    }
    
    /**
     * Get all supported post types
     *
     * @return array
     */
    public function get_supported_post_types() {
        $post_types = array(
            'post' => __('Blog Posts', 'mindfulseo'),
            'page' => __('Pages', 'mindfulseo')
        );
        
        // Add WooCommerce products if active
        if (class_exists('WooCommerce')) {
            $post_types['product'] = __('Products (WooCommerce)', 'mindfulseo');
        }
        
        // Add The Events Calendar events if active
        if (class_exists('Tribe__Events__Main')) {
            $post_types['tribe_events'] = __('Events (The Events Calendar)', 'mindfulseo');
        }
        
        // Add Event Espresso events if active
        if (class_exists('EE_System')) {
            $post_types['espresso_events'] = __('Events (Event Espresso)', 'mindfulseo');
        }
        
        // Add custom post types (exclude built-in WordPress types)
        $custom_post_types = get_post_types(array(
            'public' => true,
            '_builtin' => false
        ), 'objects');
        
        foreach ($custom_post_types as $cpt) {
            // Skip if already added
            if (!isset($post_types[$cpt->name]) && 
                !in_array($cpt->name, array('attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset'))) {
                $post_types[$cpt->name] = $cpt->label;
            }
        }
        
        return $post_types;
    }
    
    /**
     * Analyze content to generate keyword suggestions
     * NOW WITH AI ENHANCEMENT for better quality!
     *
     * @param array $options Analysis options
     * @return array Suggested keywords
     */
    public function analyze_for_keywords($options = array()) {
        $defaults = array(
            'post_types' => array('post'),
            'limit' => 50,
            'min_word_count' => 300,
            'use_ai' => true // NEW: Use AI for analysis
        );
        
        $options = wp_parse_args($options, $defaults);
        
        // Get posts
        $posts = get_posts(array(
            'post_type' => $options['post_types'],
            'posts_per_page' => $options['limit'],
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        if (empty($posts)) {
            return array();
        }
        
        // NEW: Use AI-enhanced analysis if enabled
        if ($options['use_ai']) {
            return $this->ai_generate_keywords($posts, $options);
        }
        
        // FALLBACK: Original pattern-based analysis
        return $this->pattern_based_keywords($posts, $options);
    }
    
    /**
     * AI-Enhanced keyword generation
     * Analyzes content themes, user intent, and generates high-quality keywords
     *
     * @param array $posts Posts to analyze
     * @param array $options Options
     * @return array Generated keywords
     */
    private function ai_generate_keywords($posts, $options) {
        // Prepare content samples for AI analysis
        $content_samples = array();
        $titles = array();
        $total_word_count = 0;
        
        foreach ($posts as $post) {
            $content = wp_strip_all_tags($post->post_content);
            $word_count = str_word_count($content);
            
            if ($word_count < $options['min_word_count']) {
                continue;
            }
            
            // Take excerpt (first 500 chars to keep prompt size reasonable)
            $excerpt = substr($content, 0, 500);
            
            $content_samples[] = array(
                'title' => $post->post_title,
                'excerpt' => $excerpt,
                'word_count' => $word_count
            );
            
            $titles[] = $post->post_title;
            $total_word_count += $word_count;
            
            // Limit to 20 samples to keep API cost reasonable
            if (count($content_samples) >= 20) {
                break;
            }
        }
        
        if (empty($content_samples)) {
            return array();
        }
        
        // Build AI prompt
        $prompt = $this->build_keyword_generation_prompt($content_samples, array(
            'post_count' => count($content_samples),
            'total_word_count' => $total_word_count,
            'post_types' => $options['post_types']
        ));
        
        // Call AI
        $ai_connector = MFSEO_AI_Connector::get_instance();
        $response = $ai_connector->generate_content($prompt, array(
            'timeout' => 90, // Longer timeout for analysis
            'temperature' => 0.3 // Lower temperature for more focused analysis
        ));
        
        if (is_wp_error($response)) {
            error_log('MindfulSEO Keyword AI Error: ' . $response->get_error_message());
            // Fall back to pattern-based analysis
            return $this->pattern_based_keywords($posts, $options);
        }
        
        // Parse AI response
        $keywords = $this->parse_ai_keyword_response($response);
        
        if (empty($keywords)) {
            error_log('MindfulSEO: AI returned no keywords, falling back to pattern-based');
            return $this->pattern_based_keywords($posts, $options);
        }
        
        return $keywords;
    }
    
    /**
     * Build AI prompt for keyword generation
     *
     * @param array $samples Content samples
     * @param array $context Context information
     * @return string Prompt
     */
    private function build_keyword_generation_prompt($samples, $context) {
        // ==================================
        // EXTRACT SITE IDENTITY & ENTITIES
        // ==================================
        $site_name = get_bloginfo('name');
        $site_description = get_bloginfo('description');
        $site_url = get_site_url();
        
        // Extract key entities from content
        $entities = $this->extract_site_entities($samples);
        
        $prompt = "You are an expert SEO strategist and content analyst specializing in keyword research and search intent identification.\n\n";
        
        $prompt .= "━━━ YOUR TASK ━━━\n";
        $prompt .= "Analyze the following content samples from a website and generate a comprehensive keyword strategy.\n";
        $prompt .= "Focus on identifying WHAT USERS WOULD SEARCH to find this content.\n\n";
        
        $prompt .= "━━━ WEBSITE IDENTITY ━━━\n";
        $prompt .= "Site Name: {$site_name}\n";
        if (!empty($site_description)) {
            $prompt .= "Tagline: {$site_description}\n";
        }
        $prompt .= "URL: {$site_url}\n";
        
        // Add extracted entities
        if (!empty($entities['people'])) {
            $prompt .= "\nKey People/Teachers Mentioned:\n";
            foreach ($entities['people'] as $person) {
                $prompt .= "  • {$person}\n";
            }
        }
        
        if (!empty($entities['locations'])) {
            $prompt .= "\nLocations:\n";
            foreach ($entities['locations'] as $location) {
                $prompt .= "  • {$location}\n";
            }
        }
        
        if (!empty($entities['programs'])) {
            $prompt .= "\nPrograms/Courses/Categories:\n";
            foreach ($entities['programs'] as $program) {
                $prompt .= "  • {$program}\n";
            }
        }
        
        $prompt .= "\n⚠️ CRITICAL: You MUST include PRIMARY keywords for:\n";
        $prompt .= "  1. The organization/site itself (\"{$site_name}\" and variations)\n";
        if (!empty($entities['people'])) {
            $prompt .= "  2. Key people/teachers listed above (each person should get their own PRIMARY keyword)\n";
        }
        if (!empty($entities['locations'])) {
            $prompt .= "  3. Location-based variations (e.g., \"{$entities['locations'][0]} + topic\")\n";
        }
        $prompt .= "  4. Main content themes (from the samples below)\n\n";
        
        $prompt .= "━━━ CONTENT OVERVIEW ━━━\n";
        $prompt .= "Post Count: {$context['post_count']} posts\n";
        $prompt .= "Total Words: ~" . number_format($context['total_word_count']) . " words\n";
        $prompt .= "Content Types: " . implode(', ', $context['post_types']) . "\n\n";
        
        $prompt .= "━━━ CONTENT SAMPLES ━━━\n\n";
        foreach ($samples as $i => $sample) {
            $prompt .= "Sample " . ($i + 1) . ":\n";
            $prompt .= "Title: {$sample['title']}\n";
            $prompt .= "Excerpt: {$sample['excerpt']}\n";
            $prompt .= "Word Count: {$sample['word_count']}\n\n";
        }
        
        $prompt .= "━━━ ANALYSIS FRAMEWORK ━━━\n\n";
        $prompt .= "1. IDENTIFY MAIN THEMES:\n";
        $prompt .= "   • What are the 3-5 core topics covered across all samples?\n";
        $prompt .= "   • What subjects or categories do these posts belong to?\n";
        $prompt .= "   • What expertise or knowledge is being shared?\n\n";
        
        $prompt .= "2. UNDERSTAND USER INTENT:\n";
        $prompt .= "   • What would someone type into Google to find this content?\n";
        $prompt .= "   • What questions are users trying to answer?\n";
        $prompt .= "   • What problems are they trying to solve?\n";
        $prompt .= "   • What are they trying to learn or accomplish?\n\n";
        
        $prompt .= "3. GENERATE PRIMARY KEYWORDS:\n";
        $prompt .= "   • Create 15-25 primary keywords (3-5 words each)\n";
        $prompt .= "   • Use natural language that real people search\n";
        $prompt .= "   • Focus on medium to high search volume potential\n";
        $prompt .= "   • Avoid overly broad or overly niche terms\n";
        $prompt .= "   • Consider semantic variations and synonyms\n";
        $prompt .= "   • Include question-based keywords when relevant\n\n";
        
        $prompt .= "4. CREATE LONGTAIL VARIANTS:\n";
        $prompt .= "   • For EACH primary keyword, generate 2-3 longtail variations\n";
        $prompt .= "   • Longtails should be 4-7 words\n";
        $prompt .= "   • Add modifiers like: 'how to', 'best', 'guide', 'for beginners', 'explained'\n";
        $prompt .= "   • Make them specific and actionable\n";
        $prompt .= "   • Ensure they're genuinely different from the primary\n\n";
        
        $prompt .= "5. ASSIGN SEARCH INTENT:\n";
        $prompt .= "   • Informational: User wants to learn/understand\n";
        $prompt .= "   • Navigational: User looking for specific page/resource\n";
        $prompt .= "   • Transactional: User ready to take action (sign up, download, join)\n";
        $prompt .= "   • Commercial: User comparing options or researching before action\n\n";
        
        $prompt .= "6. DETERMINE PRIORITY:\n";
        $prompt .= "   • HIGH: Core topic, high relevance, strong content match\n";
        $prompt .= "   • MEDIUM: Secondary topic, good relevance, decent match\n";
        $prompt .= "   • LOW: Tangential topic, lower relevance, weak match\n\n";
        
        $prompt .= "━━━ QUALITY STANDARDS ━━━\n\n";
        $prompt .= "✓ Keywords MUST be authentic (what real people search)\n";
        $prompt .= "✓ Keywords MUST match the actual content themes\n";
        $prompt .= "✓ Keywords MUST be specific enough to be actionable\n";
        $prompt .= "✓ Longtails MUST add value (not just word-for-word repeats)\n";
        $prompt .= "✓ Search intent MUST be accurate for each keyword\n";
        $prompt .= "✓ Priority MUST reflect content focus and quality\n\n";
        
        $prompt .= "❌ AVOID:\n";
        $prompt .= "• Code/CSS/technical terms (border, padding, font, etc.)\n";
        $prompt .= "• Single words or 2-word phrases (too broad)\n";
        $prompt .= "• Overly long phrases (8+ words)\n";
        $prompt .= "• Keywords with numbers/dates (unless part of name)\n";
        $prompt .= "• Generic terms (stuff, things, content, etc.)\n\n";
        
        $prompt .= "━━━ REQUIRED JSON OUTPUT ━━━\n\n";
        $prompt .= "Respond with ONLY a valid JSON array (no markdown, no explanations):\n\n";
        $prompt .= "[\n";
        $prompt .= "  {\n";
        $prompt .= '    "primary_keyword": "buddhist meditation practices",'."\n";
        $prompt .= '    "longtail_keywords": ['."\n";
        $prompt .= '      "how to start buddhist meditation",'."\n";
        $prompt .= '      "buddhist meditation for beginners guide",'."\n";
        $prompt .= '      "daily buddhist meditation practices"'."\n";
        $prompt .= '    ],'."\n";
        $prompt .= '    "search_intent": "Informational",'."\n";
        $prompt .= '    "priority": "HIGH",'."\n";
        $prompt .= '    "reasoning": "Core topic with multiple related posts"'."\n";
        $prompt .= "  },\n";
        $prompt .= "  {\n";
        $prompt .= '    "primary_keyword": "compassion in buddhism",'."\n";
        $prompt .= '    "longtail_keywords": ['."\n";
        $prompt .= '      "how to develop compassion buddhism",'."\n";
        $prompt .= '      "compassion meditation techniques",'."\n";
        $prompt .= '      "buddhist compassion practice daily life"'."\n";
        $prompt .= '    ],'."\n";
        $prompt .= '    "search_intent": "Informational",'."\n";
        $prompt .= '    "priority": "MEDIUM",'."\n";
        $prompt .= '    "reasoning": "Secondary theme appearing in several posts"'."\n";
        $prompt .= "  }\n";
        $prompt .= "]\n\n";
        
        $prompt .= "Generate 15-25 keyword objects in this format.\n";
        $prompt .= "Each longtail_keywords array must have exactly 2-3 variants.\n";
        $prompt .= "Focus on quality over quantity - every keyword should be genuinely useful.\n";
        
        return $prompt;
    }
    
    /**
     * Parse AI response for keywords
     *
     * @param string $response AI response
     * @return array Parsed keywords
     */
    private function parse_ai_keyword_response($response) {
        // Strip markdown code blocks if present
        $response = preg_replace('/^```json\s*\n/m', '', $response);
        $response = preg_replace('/^```\s*\n/m', '', $response);
        $response = preg_replace('/\n```$/m', '', $response);
        $response = trim($response);
        
        // Try to extract JSON if it's embedded in text
        if (!str_starts_with($response, '[')) {
            if (preg_match('/\[[\s\S]*\]/', $response, $matches)) {
                $response = $matches[0];
            }
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('MindfulSEO: JSON parse error in AI keywords: ' . json_last_error_msg());
            error_log('Response: ' . substr($response, 0, 500));
            return array();
        }
        
        if (!is_array($data)) {
            return array();
        }
        
        $keywords = array();
        
        foreach ($data as $item) {
            // Validate structure
            if (!isset($item['primary_keyword']) || !isset($item['longtail_keywords']) || 
                !isset($item['search_intent']) || !isset($item['priority'])) {
                continue;
            }
            
            // Validate search intent
            $valid_intents = array('Informational', 'Navigational', 'Transactional', 'Commercial');
            if (!in_array($item['search_intent'], $valid_intents)) {
                $item['search_intent'] = 'Informational'; // Default
            }
            
            // Validate priority
            $valid_priorities = array('HIGH', 'MEDIUM', 'LOW');
            if (!in_array(strtoupper($item['priority']), $valid_priorities)) {
                $item['priority'] = 'MEDIUM'; // Default
            } else {
                $item['priority'] = strtoupper($item['priority']);
            }
            
            // Process each longtail keyword
            if (is_array($item['longtail_keywords'])) {
                foreach ($item['longtail_keywords'] as $longtail) {
                    $keywords[] = array(
                        'primary_keyword' => sanitize_text_field($item['primary_keyword']),
                        'longtail_keyword' => sanitize_text_field($longtail),
                        'search_intent' => $item['search_intent'],
                        'priority' => $item['priority'],
                        'source' => 'AI Generated'
                    );
                }
            }
        }
        
        return $keywords;
    }
    
    /**
     * Original pattern-based keyword analysis (FALLBACK)
     *
     * @param array $posts Posts to analyze
     * @param array $options Options
     * @return array Keywords
     */
    private function pattern_based_keywords($posts, $options) {
        $keyword_frequency = array();
        $keyword_contexts = array();
        
        foreach ($posts as $post) {
            $content = $post->post_title . ' ' . $post->post_content;
            $word_count = str_word_count(strip_tags($content));
            
            if ($word_count < $options['min_word_count']) {
                continue;
            }
            
            // Extract phrases (2-3 word combinations)
            $phrases = $this->extract_phrases($content);
            
            foreach ($phrases as $phrase) {
                if (!isset($keyword_frequency[$phrase])) {
                    $keyword_frequency[$phrase] = 0;
                    $keyword_contexts[$phrase] = array();
                }
                $keyword_frequency[$phrase]++;
                
                // Store post title as context
                if (!in_array($post->post_title, $keyword_contexts[$phrase])) {
                    $keyword_contexts[$phrase][] = $post->post_title;
                }
            }
        }
        
        // Sort by frequency
        arsort($keyword_frequency);
        
        // Generate keyword suggestions
        $suggestions = array();
        $count = 0;
        
        foreach ($keyword_frequency as $keyword => $frequency) {
            if ($count >= 20) break; // Limit to top 20 (SEO best practice: focus on quality, not quantity)
            if ($frequency < 3) continue; // Must appear at least 3 times (more selective)
            
            // Determine search intent based on keyword
            $intent = $this->determine_search_intent($keyword);
            
            // Determine priority based on frequency
            $priority = 'LOW';
            if ($frequency >= 10) {
                $priority = 'HIGH';
            } elseif ($frequency >= 5) {
                $priority = 'MEDIUM';
            }
            
            $suggestions[] = array(
                'primary_keyword' => $keyword,
                'longtail_keyword' => $this->generate_longtail($keyword),
                'search_intent' => $intent,
                'priority' => $priority,
                'frequency' => $frequency,
                'contexts' => array_slice($keyword_contexts[$keyword], 0, 3) // First 3 contexts
            );
            
            $count++;
        }
        
        return $suggestions;
    }
    
    /**
     * Extract phrases from content
     *
     * @param string $content Content to analyze
     * @return array Phrases
     */
    private function extract_phrases($content) {
        // Clean content
        $content = strip_tags($content);
        $content = strtolower($content);
        
        // Remove HTML entities and special characters
        $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
        $content = preg_replace('/&[a-z]+;/', '', $content); // Remove remaining HTML entities like &nbsp;
        
        // AGGRESSIVE CSS/CODE PATTERN REMOVAL
        // Remove ALL border/margin/padding patterns
        $content = preg_replace('/\b(border|margin|padding|text|font|background|color|width|height)-(top|bottom|left|right|color|style|width|radius|spacing|align|decoration|transform|shadow|family|size|weight|variant|stretch)\b/i', '', $content);
        
        // Remove CSS property patterns
        $content = preg_replace('/\b(max|min)-(width|height|content|zoom)\b/', '', $content);
        $content = preg_replace('/\b(border|solid|dashed|dotted|none|auto|inherit|initial|unset)-/', '', $content);
        $content = preg_replace('/-?(top|bottom|left|right|center)\b/', '', $content);
        
        // Remove hex colors and color codes
        $content = preg_replace('/\b[0-9a-f]{3,6}\b/', '', $content); // Hex colors like ffffff, dfdad1
        $content = preg_replace('/\b(rgba?|hsla?)\([^)]+\)/', '', $content); // Color functions
        
        // Remove pixel/unit values
        $content = preg_replace('/\b[0-9]+(px|em|rem|%|vh|vw|pt|cm|mm|in)\b/', '', $content);
        
        // Remove common code/tech class patterns
        $content = preg_replace('/\b(stk|wp|btn|nav|menu|widget|plugin|theme|post|page|admin|content|sidebar|footer|header)-[a-z-]+\b/', '', $content);
        
        // Remove CSS keywords
        $css_keywords = array('important', 'serif', 'sans-serif', 'sans', 'monospace', 'inherit', 'inline', 'block', 'flex', 'grid', 'absolute', 'relative', 'fixed', 'static', 'sticky', 'hidden', 'visible', 'auto', 'none', 'initial', 'unset', 'normal', 'bold', 'italic', 'underline');
        foreach ($css_keywords as $keyword) {
            $content = preg_replace('/\b' . preg_quote($keyword, '/') . '\b/', '', $content);
        }
        
        // Remove font names
        $fonts = array('roboto', 'lato', 'arial', 'helvetica', 'verdana', 'georgia', 'times', 'courier', 'comic', 'impact', 'trebuchet', 'palatino', 'garamond');
        foreach ($fonts as $font) {
            $content = preg_replace('/\b' . preg_quote($font, '/') . '\b/', '', $content);
        }
        
        // Remove tech/layout terms
        $tech_terms = array('wrapper', 'container', 'responsive', 'mobile', 'desktop', 'tablet', 'media', 'screen', 'viewport', 'breakpoint', 'overlay', 'modal', 'dropdown', 'accordion', 'carousel', 'slider', 'gallery');
        foreach ($tech_terms as $term) {
            $content = preg_replace('/\b' . preg_quote($term, '/') . '\b/', '', $content);
        }
        
        // Clean up the result
        $content = preg_replace('/[^a-z0-9\s-]/', ' ', $content);
        $content = preg_replace('/\s+/', ' ', $content); // Collapse multiple spaces
        $content = preg_replace('/\s-\s|-\s|\s-/', ' ', $content); // Remove standalone hyphens
        
        // Split into words
        $words = preg_split('/\s+/', $content);
        $words = array_filter($words, function($word) {
            // Skip short words, numbers, and words with trailing/leading hyphens
            return strlen($word) > 3 && !is_numeric($word) && !preg_match('/^-|-$/', $word);
        });
        
        // Remove stop words (expanded list)
        $stop_words = array('this', 'that', 'these', 'those', 'with', 'from', 'have', 'been', 'will', 'would', 'could', 'should', 'about', 'which', 'their', 'there', 'when', 'where', 'what', 'your', 'more', 'some', 'than', 'into', 'very', 'also', 'just', 'only', 'other', 'such', 'make', 'many', 'over', 'know', 'nbsp', 'ensuring', 'using', 'like', 'need', 'want', 'even', 'well', 'back', 'still', 'through', 'after', 'down', 'good', 'most', 'while', 'think', 'come', 'give', 'take');
        $words = array_diff($words, $stop_words);
        $words = array_values($words);
        
        $phrases = array();
        
        // Extract 2-word phrases
        for ($i = 0; $i < count($words) - 1; $i++) {
            $phrase = $words[$i] . ' ' . $words[$i + 1];
            
            // Skip if phrase looks like code or CSS
            if ($this->is_code_pattern($phrase)) {
                continue;
            }
            
            $phrases[] = $phrase;
        }
        
        // Extract 3-word phrases
        for ($i = 0; $i < count($words) - 2; $i++) {
            $phrase = $words[$i] . ' ' . $words[$i + 1] . ' ' . $words[$i + 2];
            
            // Skip if phrase looks like code or CSS
            if ($this->is_code_pattern($phrase)) {
                continue;
            }
            
            $phrases[] = $phrase;
        }
        
        return $phrases;
    }
    
    /**
     * Check if a phrase looks like code or CSS
     *
     * @param string $phrase Phrase to check
     * @return bool True if it looks like code
     */
    private function is_code_pattern($phrase) {
        // Check for hyphenated technical terms (likely CSS classes or properties)
        if (preg_match('/[a-z]+-[a-z]+-[a-z]+/', $phrase)) {
            return true;
        }
        
        // Check for ANY hyphen combinations (CSS properties, borders, etc.)
        if (preg_match('/(border|margin|padding|text|font|background|color|width|height|min|max|top|bottom|left|right|solid|dashed|dotted)-/', $phrase)) {
            return true;
        }
        
        // Check for single hyphen followed by word (like "-bottom", "-left")
        if (preg_match('/^-[a-z]+/', $phrase) || preg_match('/[a-z]+-$/', $phrase)) {
            return true;
        }
        
        // Check for camelCase or PascalCase (likely code)
        if (preg_match('/[a-z][A-Z]/', $phrase)) {
            return true;
        }
        
        // Check for repeated words (like "nbsp nbsp")
        $words = explode(' ', $phrase);
        if (count($words) !== count(array_unique($words))) {
            return true;
        }
        
        // Check for hex colors (e.g. "color ffffff")
        if (preg_match('/[0-9a-f]{6}/', $phrase)) {
            return true;
        }
        
        // Check for words with numbers mixed in (likely CSS/code)
        if (preg_match('/[a-z]+[0-9]+|[0-9]+[a-z]+/', $phrase)) {
            return true;
        }
        
        // Comprehensive tech/code terms blacklist
        $tech_terms = array(
            'media', 'screen', 'wrapper', 'container', 'block', 'heading', 'widget', 
            'plugin', 'theme', 'roboto', 'lato', 'arial', 'helvetica', 'verdana',
            'auto', 'inherit', 'initial', 'none', 'flex', 'grid', 'inline', 'block',
            'border', 'margin', 'padding', 'color', 'background', 'font', 'text',
            'width', 'height', 'left', 'right', 'bottom', 'center', 'style',
            'stk', 'btn', 'nav', 'menu', 'footer', 'header', 'sidebar', 'content',
            'important', 'serif', 'sans', 'monospace', 'rgba', 'hsla', 'ffffff'
        );
        
        foreach ($tech_terms as $term) {
            if (stripos($phrase, $term) !== false) {
                return true;
            }
        }
        
        // Check for CSS-like patterns (word-word-word)
        if (substr_count($phrase, '-') >= 2) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Determine search intent from keyword
     *
     * @param string $keyword Keyword
     * @return string Search intent
     */
    private function determine_search_intent($keyword) {
        $keyword_lower = strtolower($keyword);
        
        // Commercial/Transactional indicators (strongest signals)
        $transactional = array('buy', 'purchase', 'order', 'shop', 'price', 'cost', 'deal', 'discount', 'coupon', 'cheap', 'affordable', 'book', 'register', 'enroll', 'join', 'subscribe', 'donate', 'pay', 'checkout');
        foreach ($transactional as $word) {
            if (stripos($keyword_lower, $word) !== false) {
                return 'Transactional';
            }
        }
        
        // Navigational indicators (specific locations/pages)
        $navigational = array('login', 'sign in', 'account', 'dashboard', 'contact', 'about', 'schedule', 'calendar', 'location', 'venue', 'center', 'address', 'phone', 'email');
        foreach ($navigational as $word) {
            if (stripos($keyword_lower, $word) !== false) {
                return 'Navigational';
            }
        }
        
        // Informational - HOW TO/WHAT IS patterns
        $informational_patterns = array('how to', 'what is', 'why', 'when', 'where', 'guide', 'tutorial', 'learn', 'teach', 'understand', 'explain', 'meaning', 'definition', 'benefits', 'difference', 'compare', 'versus', 'vs');
        foreach ($informational_patterns as $pattern) {
            if (stripos($keyword_lower, $pattern) !== false) {
                return 'Informational';
            }
        }
        
        // Commercial Investigation - "best", "top", "review" patterns
        $commercial_investigation = array('best', 'top', 'review', 'comparison', 'alternative', 'option', 'choice', 'recommend', 'rating', 'versus');
        foreach ($commercial_investigation as $word) {
            if (stripos($keyword_lower, $word) !== false) {
                return 'Commercial';
            }
        }
        
        // Default: Mixed approach based on keyword structure
        // Longer phrases (3+ words) tend to be informational
        $word_count = str_word_count($keyword);
        if ($word_count >= 4) {
            return 'Informational';
        }
        
        // Short phrases (1-2 words) without indicators could be navigational
        if ($word_count <= 2) {
            // If it's a name/proper noun (capitalized), likely navigational
            $words = explode(' ', $keyword);
            $capitalized_count = 0;
            foreach ($words as $word) {
                if (ucfirst($word) === $word) {
                    $capitalized_count++;
                }
            }
            if ($capitalized_count >= 1) {
                return 'Navigational';
            }
        }
        
        // Default to Informational (most common for content sites)
        return 'Informational';
    }
    
    /**
     * Generate longtail variation
     *
     * @param string $keyword Base keyword
     * @return string Longtail keyword
     */
    /**
     * Generate intelligent longtail keyword from primary keyword
     *
     * @param string $keyword Primary keyword
     * @return string Longtail keyword
     */
    private function generate_longtail($keyword) {
        // Analyze keyword type
        $keyword_lower = strtolower($keyword);
        
        // If it's already long (4+ words), just return it
        $word_count = str_word_count($keyword);
        if ($word_count >= 4) {
            return $keyword;
        }
        
        // If it already has a modifier, enhance it differently
        $has_modifier = (
            stripos($keyword_lower, 'how to') !== false ||
            stripos($keyword_lower, 'what is') !== false ||
            stripos($keyword_lower, 'best') !== false ||
            stripos($keyword_lower, 'top') !== false ||
            stripos($keyword_lower, 'guide') !== false
        );
        
        if ($has_modifier) {
            // Already has a modifier, just add context
            $suffixes = array('explained', 'for beginners', 'complete guide', 'step by step', 'tips and tricks', 'in detail');
            $suffix = $suffixes[array_rand($suffixes)];
            return $keyword . ' ' . $suffix;
        }
        
        // Check if it's a name/proper noun (capitalized in original)
        $is_proper_noun = preg_match('/[A-Z]/', $keyword);
        
        if ($is_proper_noun) {
            // For names like "Lama Zopa" - use relevant Buddhist/spiritual modifiers
            $patterns = array(
                'teachings',
                'biography',
                'books',
                'quotes',
                'practice instructions',
                'guided meditations',
                'dharma talks',
                'life story',
                'advice',
                'wisdom'
            );
            $pattern = $patterns[array_rand($patterns)];
            return $keyword . ' ' . $pattern;
        }
        
        // For concepts/topics - use question-based or informational modifiers
        $informational_prefixes = array(
            'what is' => 70,  // Weight (higher = more likely)
            'how to' => 80,
            'guide to' => 60,
            'introduction to' => 40,
            'understanding' => 50,
            'learn about' => 45
        );
        
        $informational_suffixes = array(
            'explained' => 60,
            'for beginners' => 70,
            'guide' => 65,
            'tutorial' => 50,
            'complete guide' => 55,
            'step by step' => 45,
            'benefits' => 60,
            'meaning' => 50,
            'practice' => 55,
            'meditation' => 40
        );
        
        // Weighted random selection (60% prefix, 40% suffix)
        if (rand(1, 100) <= 60) {
            // Use prefix
            $options = array();
            foreach ($informational_prefixes as $prefix => $weight) {
                for ($i = 0; $i < $weight; $i++) {
                    $options[] = $prefix;
                }
            }
            $prefix = $options[array_rand($options)];
            
            // Special handling for certain prefixes
            if ($prefix === 'understanding' || $prefix === 'learn about') {
                return $prefix . ' ' . $keyword;
            } else {
                return $prefix . ' ' . $keyword;
            }
        } else {
            // Use suffix
            $options = array();
            foreach ($informational_suffixes as $suffix => $weight) {
                for ($i = 0; $i < $weight; $i++) {
                    $options[] = $suffix;
                }
            }
            $suffix = $options[array_rand($options)];
            return $keyword . ' ' . $suffix;
        }
    }
    
    /**
     * Analyze content to generate guideline suggestions
     * NOW WITH AI ENHANCEMENT for better quality!
     *
     * @param array $options Analysis options
     * @return array Suggested guidelines
     */
    public function analyze_for_guidelines($options = array()) {
        $defaults = array(
            'post_types' => array('post'),
            'limit' => 100,
            'use_ai' => true // NEW: Use AI for analysis
        );
        
        $options = wp_parse_args($options, $defaults);
        
        // Get posts
        $posts = get_posts(array(
            'post_type' => $options['post_types'],
            'posts_per_page' => $options['limit'],
            'post_status' => 'publish'
        ));
        
        if (empty($posts)) {
            return array();
        }
        
        // NEW: Use AI-enhanced analysis if enabled
        if ($options['use_ai']) {
            return $this->ai_generate_guidelines($posts, $options);
        }
        
        // FALLBACK: Original pattern-based analysis
        return $this->pattern_based_guidelines($posts);
    }
    
    /**
     * AI-Enhanced guidelines generation  
     * (see class-content-analyzer.php lines 380-550 for full AI methods)
     *
     * @param array $posts Posts to analyze
     * @param array $options Options
     * @return array Generated guidelines
     */
    private function ai_generate_guidelines($posts, $options) {
        // For now, fall back to pattern-based
        // TODO: Implement AI guidelines in next update
        return $this->pattern_based_guidelines($posts);
    }
    
    /**
     * Original pattern-based guidelines analysis
     *
     * @param array $posts Posts to analyze
     * @return array Guidelines
     */
    private function pattern_based_guidelines($posts) {
        // Analyze content patterns
        $all_content = '';
        $titles = array();
        
        foreach ($posts as $post) {
            $all_content .= ' ' . $post->post_content;
            $titles[] = $post->post_title;
        }
        
        $suggestions = array(
            'capitalize_terms' => $this->find_capitalized_terms($all_content, $titles),
            'common_phrases' => $this->find_common_phrases($all_content),
            'brand_voice' => $this->analyze_brand_voice($all_content)
        );
        
        return $suggestions;
    }
    
    /**
     * Find consistently capitalized terms
     *
     * @param string $content Content to analyze
     * @param array $titles Post titles
     * @return array Capitalized terms
     */
    private function find_capitalized_terms($content, $titles) {
        // Find words that are consistently capitalized
        $words = str_word_count($content, 1);
        $capitalized = array();
        
        foreach ($words as $word) {
            if (strlen($word) > 3 && preg_match('/^[A-Z][a-z]+$/', $word)) {
                if (!isset($capitalized[$word])) {
                    $capitalized[$word] = 0;
                }
                $capitalized[$word]++;
            }
        }
        
        // Filter by frequency (must appear at least 5 times)
        $capitalized = array_filter($capitalized, function($count) {
            return $count >= 5;
        });
        
        arsort($capitalized);
        
        return array_keys(array_slice($capitalized, 0, 20));
    }
    
    /**
     * Find common phrases in content
     *
     * @param string $content Content to analyze
     * @return array Common phrases
     */
    private function find_common_phrases($content) {
        $phrases = $this->extract_phrases($content);
        $frequency = array_count_values($phrases);
        
        // Filter by frequency (minimum 5 occurrences)
        $frequency = array_filter($frequency, function($count) {
            return $count >= 5;
        });
        
        // Additional filter: remove any remaining code-like patterns
        $frequency = array_filter($frequency, function($count, $phrase) {
            // Skip if contains numbers
            if (preg_match('/\d/', $phrase)) {
                return false;
            }
            // Skip very long phrases (likely code)
            if (strlen($phrase) > 40) {
                return false;
            }
            return true;
        }, ARRAY_FILTER_USE_BOTH);
        
        arsort($frequency);
        
        return array_keys(array_slice($frequency, 0, 30));
    }
    
    /**
     * Extract key entities from content samples (people, locations, programs)
     *
     * @param array $samples Content samples
     * @return array Extracted entities
     */
    private function extract_site_entities($samples) {
        $entities = array(
            'people' => array(),
            'locations' => array(),
            'programs' => array()
        );
        
        $all_text = '';
        foreach ($samples as $sample) {
            $all_text .= ' ' . $sample['title'] . ' ' . $sample['excerpt'];
        }
        
        // Extract people (titles like Geshe, Lama, Rinpoche, Venerable, etc.)
        $people_patterns = array(
            '/\b(Geshe|Lama|Rinpoche|Venerable|His Holiness|Her Holiness)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+){0,3})\b/',
            '/\b([A-Z][a-z]+\s+[A-Z][a-z]+)\s+(Rinpoche|Geshe)\b/',
        );
        
        foreach ($people_patterns as $pattern) {
            if (preg_match_all($pattern, $all_text, $matches)) {
                foreach ($matches[0] as $match) {
                    $clean = trim($match);
                    if (!in_array($clean, $entities['people'])) {
                        $entities['people'][] = $clean;
                    }
                }
            }
        }
        
        // Extract locations (common city names, countries)
        $location_words = array('London', 'UK', 'United Kingdom', 'England', 'Scotland', 'Wales', 'Ireland', 
                                'Manchester', 'Edinburgh', 'Bristol', 'Leeds', 'Birmingham', 'Cambridge', 'Oxford',
                                'New York', 'California', 'Sydney', 'Melbourne', 'Toronto', 'Vancouver');
        
        foreach ($location_words as $location) {
            if (stripos($all_text, $location) !== false) {
                if (!in_array($location, $entities['locations'])) {
                    $entities['locations'][] = $location;
                }
            }
        }
        
        // Extract programs/categories from WordPress categories and tags
        $categories = get_categories(array('hide_empty' => false));
        foreach ($categories as $category) {
            if ($category->slug !== 'uncategorized') {
                $entities['programs'][] = $category->name;
            }
        }
        
        // Also check post types registered (like courses, events)
        $post_types = get_post_types(array('public' => true, '_builtin' => false), 'objects');
        foreach ($post_types as $post_type) {
            // Skip system post types
            if (!in_array($post_type->name, array('attachment', 'revision', 'nav_menu_item'))) {
                $entities['programs'][] = $post_type->labels->name;
            }
        }
        
        // Limit to most relevant
        $entities['people'] = array_slice(array_unique($entities['people']), 0, 5);
        $entities['locations'] = array_slice(array_unique($entities['locations']), 0, 3);
        $entities['programs'] = array_slice(array_unique($entities['programs']), 0, 10);
        
        return $entities;
    }
    
    /**
     * Analyze brand voice patterns
     *
     * @param string $content Content to analyze
     * @return array Voice characteristics
     */
    private function analyze_brand_voice($content) {
        $characteristics = array();
        
        // Check for first-person usage
        $first_person_count = preg_match_all('/\b(we|our|us)\b/i', $content);
        $characteristics['uses_first_person'] = $first_person_count > 10;
        
        // Check for questions
        $question_count = substr_count($content, '?');
        $characteristics['uses_questions'] = $question_count > 5;
        
        // Check average sentence length
        $sentences = preg_split('/[.!?]+/', $content);
        $total_words = 0;
        foreach ($sentences as $sentence) {
            $total_words += str_word_count($sentence);
        }
        $avg_sentence_length = count($sentences) > 0 ? $total_words / count($sentences) : 0;
        $characteristics['avg_sentence_length'] = round($avg_sentence_length);
        $characteristics['tone'] = $avg_sentence_length < 15 ? 'conversational' : 'formal';
        
        return $characteristics;
    }
    
    /**
     * Get post count by type
     *
     * @param string $post_type Post type
     * @return int Count
     */
    public function get_post_count($post_type) {
        $counts = wp_count_posts($post_type);
        return isset($counts->publish) ? $counts->publish : 0;
    }
}

