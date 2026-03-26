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
    
    private $detected_entities = array();

    /**
     * avoid => preferred pairs for keyword sanitization (longest avoid first).
     *
     * @var array<int, array{avoid:string, preferred:string}>
     */
    private $keyword_avoid_replace_pairs = array();

    /**
     * Lowercase => correctly capitalized form from guideline capitalize rules.
     *
     * @var array<string, string>
     */
    private $guideline_capitalize_terms_map = array();

    /** @var list<string> */
    private $keyword_avoid_terms_lower = array();

    /**
     * Constructor
     */
    public function __construct() {
        $this->supported_post_types = $this->get_supported_post_types();
    }

    /**
     * Bound prompt text so outbound HTTP requests stay under server/cURL limits (e.g. cURL error 100).
     *
     * @param string $text      Text.
     * @param int    $max_chars Max characters (UTF-8 when mbstring available).
     * @return string
     */
    private function truncate_for_ai_prompt( $text, $max_chars = 12000 ) {
        if ( $text === '' || $text === null ) {
            return '';
        }
        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            if ( mb_strlen( $text ) <= $max_chars ) {
                return $text;
            }
            return mb_substr( $text, 0, $max_chars ) . "\n\n[Context truncated for API size limits.]";
        }
        if ( strlen( $text ) <= $max_chars ) {
            return $text;
        }
        return substr( $text, 0, $max_chars ) . "\n\n[Context truncated for API size limits.]";
    }

    /**
     * Build a bounded title list for the keyword prompt (avoids multi‑MB prompts on large sites).
     *
     * @param array $titles Post titles.
     * @return string
     */
    private function prepare_titles_for_keyword_prompt( array $titles ) {
        $slice = array_slice( $titles, 0, 150 );
        $out   = array();
        foreach ( $slice as $t ) {
            $t = trim( (string) $t );
            if ( $t === '' ) {
                continue;
            }
            if ( function_exists( 'mb_substr' ) ) {
                $t = mb_substr( $t, 0, 160 );
            } else {
                $t = substr( $t, 0, 160 );
            }
            $out[] = $t;
        }
        return implode( ' | ', $out );
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
            'post_types' => array('post', 'page'),
            'min_word_count' => 100,
            'use_ai' => true,
            'deep_analysis' => false,
            'wizard_saved_snapshot' => '',
            'ai_usage_context' => '',
        );
        
        $options = wp_parse_args($options, $defaults);
        
        global $wpdb;
        $post_type_in = implode("','", array_map('esc_sql', $options['post_types']));
        $total_post_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
             WHERE post_type IN ('{$post_type_in}') 
             AND post_status = 'publish'"
        );
        if ( $total_post_count < 1 ) {
            return array();
        }
        // Representative titles only — loading every title blows memory and entity extraction (7288+ rows).
        $all_titles = $wpdb->get_col(
            "SELECT post_title FROM {$wpdb->posts} 
             WHERE post_type IN ('{$post_type_in}') 
             AND post_status = 'publish' 
             ORDER BY post_modified DESC
             LIMIT 400"
        );
        $options['total_post_count'] = $total_post_count;
        
        if (empty($all_titles)) {
            return array();
        }
        
        $sample_posts = get_posts(array(
            'post_type' => $options['post_types'],
            'posts_per_page' => 60,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        if ($options['use_ai']) {
            return $this->ai_generate_keywords($sample_posts, $options, $all_titles);
        }
        
        return $this->pattern_based_keywords($sample_posts, $options);
    }

    /**
     * Load keyword strategy + active guidelines for keyword AI prompt and post-filtering.
     */
    private function load_keyword_strategy_and_guidelines_for_prompt() {
        $this->keyword_avoid_replace_pairs = array();
        $this->guideline_capitalize_terms_map = array();
        $this->keyword_avoid_terms_lower = array();

        $primary_keywords = array();
        if (class_exists('MFSEO_Keyword_Manager')) {
            $km = MFSEO_Keyword_Manager::get_instance();
            $keywords = $km->get_keywords(array('limit' => 500));
            $seen = array();
            foreach ($keywords as $kw) {
                $pk = trim($kw->primary_keyword);
                if ($pk === '') {
                    continue;
                }
                $k = strtolower($pk);
                if (!isset($seen[$k])) {
                    $primary_keywords[] = $pk;
                    $seen[$k] = true;
                }
            }
        }

        $guidelines_block = '';
        $pair_map = array();

        if (class_exists('MFSEO_Guidelines_Engine')) {
            $ge = MFSEO_Guidelines_Engine::get_instance();
            $guidelines_block = $ge->generate_ai_context();
            $rules = $ge->get_all_rules(array('active_only' => true));
            foreach ($rules as $rule) {
                if ($rule->rule_type === 'capitalize' && !empty($rule->preferred_term)) {
                    $this->guideline_capitalize_terms_map[strtolower($rule->preferred_term)] = $rule->preferred_term;
                }
                if (in_array($rule->rule_type, array('avoid_term', 'preferred_term', 'seo_friendly'), true)
                    && !empty($rule->avoid_term)
                    && !empty($rule->preferred_term)) {
                    $pair_map[strtolower($rule->avoid_term)] = array(
                        'avoid' => $rule->avoid_term,
                        'preferred' => $rule->preferred_term,
                    );
                }
                if ($rule->rule_type === 'avoid_term' && !empty($rule->avoid_term)) {
                    $this->keyword_avoid_terms_lower[] = strtolower($rule->avoid_term);
                }
                if ($rule->rule_type === 'preferred_term' && !empty($rule->avoid_term)) {
                    $this->keyword_avoid_terms_lower[] = strtolower($rule->avoid_term);
                }
                if ($rule->rule_type === 'seo_friendly' && !empty($rule->avoid_term)) {
                    $this->keyword_avoid_terms_lower[] = strtolower($rule->avoid_term);
                }
            }
        }

        foreach ($pair_map as $pair) {
            $this->keyword_avoid_replace_pairs[] = $pair;
        }
        usort($this->keyword_avoid_replace_pairs, function ($a, $b) {
            return strlen($b['avoid']) - strlen($a['avoid']);
        });
        $this->keyword_avoid_terms_lower = array_values(array_unique($this->keyword_avoid_terms_lower));

        return array(
            'primary_keywords' => $primary_keywords,
            'guidelines_block' => $guidelines_block,
        );
    }

    /**
     * Apply avoid→preferred replacements for keyword phrases (word boundaries).
     *
     * @param string $phrase Phrase.
     * @return string
     */
    private function apply_keyword_avoid_replacements($phrase) {
        $out = $phrase;
        foreach ($this->keyword_avoid_replace_pairs as $pair) {
            $pattern = '/\b' . preg_quote($pair['avoid'], '/') . '\b/iu';
            $out = preg_replace($pattern, $pair['preferred'], $out);
        }
        return $out;
    }

    /**
     * True if phrase still contains any forbidden avoid term (after replacements).
     *
     * @param string $phrase Phrase.
     * @return bool
     */
    private function keyword_phrase_contains_avoid_term($phrase) {
        foreach ($this->keyword_avoid_terms_lower as $avoid_l) {
            $avoid = preg_quote($avoid_l, '/');
            if (preg_match('/\b' . $avoid . '\b/iu', $phrase)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Apply guideline capitalization map (merge with detected entities in fix_keyword_capitalization).
     *
     * @param string $keyword Keyword.
     * @return string
     */
    private function sanitize_keyword_phrase_with_guidelines($keyword) {
        if ($keyword === '' || $keyword === null) {
            return null;
        }
        $adjusted = $this->apply_keyword_avoid_replacements($keyword);
        if ($this->keyword_phrase_contains_avoid_term($adjusted)) {
            return null;
        }
        return $this->fix_keyword_capitalization($adjusted);
    }

    /**
     * AI-Enhanced keyword generation
     * Analyzes content themes, user intent, and generates high-quality keywords
     *
     * @param array $posts Posts to analyze
     * @param array $options Options
     * @return array Generated keywords
     */
    private function ai_generate_keywords($posts, $options, $all_titles = array()) {
        $content_samples = array();
        $total_word_count = 0;
        $homepage_content = '';
        $about_content = '';
        $deep = !empty($options['deep_analysis']);
        $max_samples = 25;
        $excerpt_len = 1000;
        
        $front_page_id = get_option('page_on_front');
        if ($front_page_id) {
            $fp = get_post($front_page_id);
            if ($fp && !empty($fp->post_content)) {
                $homepage_content = substr(wp_strip_all_tags($fp->post_content), 0, 1500);
            }
        }
        
        $about_page = get_posts(array(
            'post_type' => 'page',
            'name' => 'about',
            'posts_per_page' => 1,
            'post_status' => 'publish',
        ));
        if (empty($about_page)) {
            $about_page = get_posts(array(
                'post_type' => 'page',
                'post_status' => 'publish',
                's' => 'about',
                'posts_per_page' => 1,
            ));
        }
        if (!empty($about_page)) {
            $about_content = substr(wp_strip_all_tags($about_page[0]->post_content), 0, 1500);
        }
        
        foreach ($posts as $post) {
            if ($post->ID == $front_page_id) continue;
            $content = wp_strip_all_tags($post->post_content);
            $word_count = str_word_count($content);
            
            if ($word_count < $options['min_word_count']) {
                continue;
            }
            
            $content_samples[] = array(
                'title' => $post->post_title,
                'excerpt' => substr($content, 0, $excerpt_len),
                'word_count' => $word_count
            );
            
            $total_word_count += $word_count;
            
            if (count($content_samples) >= $max_samples) {
                break;
            }
        }
        
        if (empty($content_samples) && empty($homepage_content)) {
            return array();
        }
        
        $context = array(
            'post_count' => isset( $options['total_post_count'] ) ? (int) $options['total_post_count'] : count( $all_titles ),
            'total_word_count' => $total_word_count,
            'post_types' => $options['post_types'],
            'all_titles' => $all_titles,
            'homepage_content' => $homepage_content,
            'about_content' => $about_content,
        );
        if (!empty($options['wizard_saved_snapshot'])) {
            $context['wizard_saved_snapshot'] = $options['wizard_saved_snapshot'];
        }
        
        $prompt = $this->build_keyword_generation_prompt($content_samples, $context);
        
        $ai_connector = MFSEO_AI_Connector::get_instance();
        $kw_ctx = ! empty( $options['ai_usage_context'] ) ? $options['ai_usage_context'] : 'content_analyzer_keywords';
        $response = $ai_connector->generate_content($prompt, array(
            'timeout' => $deep ? 180 : 120,
            'temperature' => 0.3,
            'max_tokens' => 6000,
            'fast_model' => !$deep,
            'usage_context' => $kw_ctx,
        ));
        
        if (is_wp_error($response)) {
            error_log('MindfulSEO Keyword AI Error: ' . $response->get_error_message());
            return new WP_Error('ai_failed', 'AI keyword generation failed: ' . $response->get_error_message());
        }
        
        $keywords = $this->parse_ai_keyword_response($response);
        
        if (empty($keywords)) {
            error_log('MindfulSEO: AI returned no parseable keywords. Response: ' . substr($response, 0, 500));
            return new WP_Error('ai_parse_failed', 'AI returned no parseable keywords. Please try again or check your API key.');
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
        $site_name = get_bloginfo('name');
        $site_description = get_bloginfo('description');
        $site_url = get_site_url();
        
        $all_titles = !empty($context['all_titles']) ? $context['all_titles'] : array();
        $entities = $this->extract_site_entities($samples, $all_titles);
        $this->detected_entities = $entities;

        $strategy_ctx = $this->load_keyword_strategy_and_guidelines_for_prompt();
        
        $all_titles_text = ! empty( $all_titles ) ? $this->prepare_titles_for_keyword_prompt( $all_titles ) : '';

        $prompt = "You are an expert SEO keyword researcher. Analyze this website's ENTIRE content and identify keywords that REAL PEOPLE type into Google to find content like this.\n\n";
        
        $prompt .= "=== WEBSITE IDENTITY ===\n";
        $prompt .= "Name: {$site_name}\n";
        if (!empty($site_description)) {
            $prompt .= "Tagline: {$site_description}\n";
        }
        $prompt .= "URL: {$site_url}\n";
        $prompt .= "Total published posts: {$context['post_count']}\n\n";

        if (!empty($context['wizard_saved_snapshot'])) {
            $prompt .= "=== PREVIOUS SAVED KEYWORD STRATEGY (from database — IMPROVE: produce a stronger, more complete set; keep useful themes, fix gaps and redundancy) ===\n";
            $prompt .= $this->truncate_for_ai_prompt( $context['wizard_saved_snapshot'], 12000 ) . "\n\n";
        }

        if (!empty($context['homepage_content'])) {
            $prompt .= "=== HOMEPAGE CONTENT (this is what the site is primarily about) ===\n";
            $prompt .= $context['homepage_content'] . "\n\n";
        }
        if (!empty($context['about_content'])) {
            $prompt .= "=== ABOUT PAGE CONTENT ===\n";
            $prompt .= $context['about_content'] . "\n\n";
        }

        $prompt .= "=== DETECTED SITE STRUCTURE ===\n\n";

        if (!empty($entities['key_terms'])) {
            $acr_parts = array();
            foreach ($entities['key_terms'] as $acr => $count) {
                $acr_parts[] = "{$acr} ({$count} mentions)";
            }
            $prompt .= "Key Acronyms/Organizations: " . implode(', ', $acr_parts) . "\n";
        }

        if (!empty($entities['people'])) {
            $people_parts = array();
            foreach (array_slice($entities['people'], 0, 20, true) as $name => $count) {
                $people_parts[] = "{$name} ({$count} mentions)";
            }
            $prompt .= "Key People (ranked by frequency — the MOST mentioned people are the MOST important to target as keywords):\n";
            $prompt .= implode(', ', $people_parts) . "\n";
        }

        if (!empty($entities['categories_with_counts'])) {
            $cat_parts = array();
            foreach (array_slice($entities['categories_with_counts'], 0, 20, true) as $cat => $count) {
                $cat_parts[] = "{$cat} ({$count} posts)";
            }
            $prompt .= "Site Categories (ranked by post count — these are the site's main content areas):\n";
            $prompt .= implode(', ', $cat_parts) . "\n";
        }

        if (!empty($entities['tags_with_counts'])) {
            $tag_parts = array();
            foreach (array_slice($entities['tags_with_counts'], 0, 30, true) as $tag => $count) {
                $tag_parts[] = "{$tag} ({$count} posts)";
            }
            $prompt .= "Site Tags (ranked by usage — these represent the specific topics readers care about):\n";
            $prompt .= implode(', ', $tag_parts) . "\n";
        }

        if (!empty($all_titles_text)) {
            $prompt .= "\nALL POST TITLES (scan for scope):\n";
            $prompt .= $all_titles_text . "\n";
        }
        $prompt .= "\n";
        
        $prompt .= "=== CONTENT SAMPLES (" . count($samples) . " posts) ===\n\n";
        foreach ($samples as $i => $sample) {
            $prompt .= ($i + 1) . ". \"{$sample['title']}\"\n   {$sample['excerpt']}\n\n";
        }

        if (!empty($strategy_ctx['primary_keywords'])) {
            $prompt .= "=== EXISTING KEYWORD STRATEGY (align with these clusters and wording) ===\n";
            $prompt .= implode(', ', array_slice($strategy_ctx['primary_keywords'], 0, 100)) . "\n\n";
        }

        if (!empty($strategy_ctx['guidelines_block'])) {
            $prompt .= "=== AUTHORITATIVE LANGUAGE GUIDELINES (MUST follow for every primary and longtail keyword) ===\n";
            $prompt .= $this->truncate_for_ai_prompt( $strategy_ctx['guidelines_block'], 10000 ) . "\n";
        }

        $prompt .= "TERMINOLOGY: Do not target avoided terms as keywords; use the guideline-preferred wording instead.\n\n";
        
        $prompt .= "=== KEYWORD GENERATION RULES ===\n\n";

        $prompt .= "Generate 15-20 PRIMARY keywords covering ALL aspects of this site. Quality over quantity.\n\n";

        $prompt .= "MANDATORY CATEGORIES (must have keywords in EACH):\n\n";

        $prompt .= "A) BRAND/ORGANIZATION (1-2 keywords): The main organization name (\"{$site_name}\") and its acronym. HIGH priority.\n\n";

        $prompt .= "B) KEY PEOPLE (1 keyword per prominent person, HIGH priority): The MOST frequently mentioned people MUST be keywords. ";
        $prompt .= "Use their COMPLETE FULL NAME exactly as detected. NEVER shorten or abbreviate.\n\n";

        $prompt .= "C) CORE TOPICS & PRACTICES (8-12 keywords, mix of HIGH and MEDIUM): The site's main content areas. ";
        $prompt .= "Use categories, tags, and content samples to identify the key topics people search for. ";
        $prompt .= "Use the site's actual domain-specific terminology.\n\n";

        $prompt .= "D) LOCATIONS, EVENTS & RESOURCES (2-4 keywords if applicable): Key locations, centers, events, or resource types.\n\n";
        
        $prompt .= "KEYWORD WATERFALL FORMAT:\n";
        $prompt .= "- Primary keywords: 1-5 words, Properly Capitalized for proper nouns\n";
        $prompt .= "- Longtail keywords: 4-6 per primary, each 3-8 words, ALL LOWERCASE\n";
        $prompt .= "- Longtails should cover different search angles: meaning, how-to, benefits, related concepts\n";
        $prompt .= "- Each longtail must be a REAL search query people actually type into Google\n";
        $prompt .= "- Longtails should be genuinely different from each other (not just adding one word)\n\n";
        
        $prompt .= "SEARCH INTENT: Informational | Navigational | Transactional | Commercial\n";
        $prompt .= "PRIORITY: HIGH (core to the site) | MEDIUM (secondary topic) | LOW (tangential)\n\n";
        
        $prompt .= "ABSOLUTE RULES:\n";
        $prompt .= "- NEVER use generic filler words like \"rituals\", \"charitable activities\", \"holy days\", \"community stories\"\n";
        $prompt .= "- NEVER shorten or cut names — always use the FULL form as it appears in the content\n";
        $prompt .= "- NEVER combine or merge different people into a single keyword\n";
        $prompt .= "- NEVER rearrange post titles into keywords\n";
        $prompt .= "- NEVER create multiple slight variations of the same keyword\n";
        $prompt .= "- NEVER invent topics not actually covered in the content\n";
        $prompt .= "- ALWAYS use domain-specific terminology exactly as the site uses it\n";
        $prompt .= "- ALWAYS create keywords real people would actually type into Google\n";
        $prompt .= "- ALWAYS use PROPER CAPITALIZATION for proper nouns, titles, and names (e.g. 'Dalai Lama' NOT 'dalai lama', 'Lama Zopa Rinpoche' NOT 'lama zopa rinpoche')\n";
        $prompt .= "- Organization name/acronym MUST be a standalone HIGH priority keyword\n";
        $prompt .= "- Generic topics should be lowercase (e.g. 'buddhist meditation', 'tibetan buddhism') but proper nouns MUST be capitalized\n\n";
        
        $prompt .= "Respond with ONLY a valid JSON array:\n";
        $prompt .= '[{"primary_keyword":"Buddhist Meditation", "longtail_keywords":["buddhist meditation for beginners","how to do buddhist meditation","buddhist meditation techniques","tibetan buddhist meditation","buddhist meditation benefits"], "search_intent":"Informational", "priority":"HIGH", "reasoning":"core topic"}]' . "\n";
        
        return $prompt;
    }
    
    /**
     * Parse AI response for keywords
     *
     * @param string $response AI response
     * @return array Parsed keywords
     */
    private function parse_ai_keyword_response($response) {
        $response = preg_replace('/^```json\s*\n/m', '', $response);
        $response = preg_replace('/^```\s*\n/m', '', $response);
        $response = preg_replace('/\n```$/m', '', $response);
        $response = trim($response);
        
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
        
        // Deduplicate: track primary keywords we've already seen to skip
        // near-duplicates (substring overlap or very similar phrasing)
        $seen_primaries = array();
        $keywords = array();
        
        foreach ($data as $item) {
            if (!isset($item['primary_keyword']) || !isset($item['longtail_keywords']) || 
                !isset($item['search_intent']) || !isset($item['priority'])) {
                continue;
            }
            
            $primary_raw = strtolower(trim(sanitize_text_field($item['primary_keyword'])));
            
            $word_count = str_word_count($primary_raw);
            if ($word_count < 1 || $word_count > 6) {
                continue;
            }

            $valid_intents = array('Informational', 'Navigational', 'Transactional', 'Commercial');
            if (!in_array($item['search_intent'], $valid_intents)) {
                $item['search_intent'] = 'Informational';
            }
            
            $valid_priorities = array('HIGH', 'MEDIUM', 'LOW');
            if (!in_array(strtoupper($item['priority']), $valid_priorities)) {
                $item['priority'] = 'MEDIUM';
            } else {
                $item['priority'] = strtoupper($item['priority']);
            }

            $primary_sanitized = $this->sanitize_keyword_phrase_with_guidelines(sanitize_text_field($item['primary_keyword']));
            if ($primary_sanitized === null) {
                continue;
            }

            $primary = strtolower($primary_sanitized);
            $is_duplicate = false;
            foreach ($seen_primaries as $existing) {
                if (strpos($existing, $primary) !== false || strpos($primary, $existing) !== false) {
                    $is_duplicate = true;
                    break;
                }
                $existing_words = explode(' ', $existing);
                $primary_words = explode(' ', $primary);
                $shared = count(array_intersect($primary_words, $existing_words));
                $max_words = max(count($existing_words), count($primary_words));
                if ($max_words > 0 && ($shared / $max_words) > 0.6) {
                    $is_duplicate = true;
                    break;
                }
            }
            if ($is_duplicate) {
                continue;
            }
            $seen_primaries[] = $primary;
            
            if (is_array($item['longtail_keywords'])) {
                $longtails = $item['longtail_keywords'];
                foreach ($longtails as $longtail) {
                    $longtail_clean = trim(sanitize_text_field($longtail));
                    if (empty($longtail_clean) || str_word_count($longtail_clean) < 3) {
                        continue;
                    }
                    $longtail_sanitized = $this->sanitize_keyword_phrase_with_guidelines($longtail_clean);
                    if ($longtail_sanitized === null) {
                        continue;
                    }
                    $keywords[] = array(
                        'primary_keyword' => $primary_sanitized,
                        'longtail_keyword' => $longtail_sanitized,
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
     * Fix proper noun capitalization in keywords using detected entities
     */
    private function fix_keyword_capitalization($keyword) {
        $proper_nouns = array();

        if (!empty($this->guideline_capitalize_terms_map)) {
            foreach ($this->guideline_capitalize_terms_map as $lower => $correct) {
                $proper_nouns[$lower] = $correct;
            }
        }
        
        if (!empty($this->detected_entities['people'])) {
            foreach ($this->detected_entities['people'] as $name => $count) {
                $proper_nouns[strtolower($name)] = $name;
            }
        }
        if (!empty($this->detected_entities['key_terms'])) {
            foreach ($this->detected_entities['key_terms'] as $term => $count) {
                $proper_nouns[strtolower($term)] = $term;
            }
        }
        
        if (empty($proper_nouns)) {
            return $keyword;
        }
        
        // Sort by length (longest first) so "Lama Zopa Rinpoche" matches before "Lama Zopa"
        uksort($proper_nouns, function($a, $b) {
            return strlen($b) - strlen($a);
        });
        
        $keyword_lower = strtolower($keyword);
        foreach ($proper_nouns as $lower => $correct) {
            if (strpos($keyword_lower, $lower) !== false) {
                $keyword = preg_replace('/' . preg_quote($lower, '/') . '/i', $correct, $keyword);
                $keyword_lower = strtolower($keyword);
            }
        }
        
        return $keyword;
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
            if ($count >= 20) break;
            if ($frequency < 3) continue;
            
            if ($this->is_junk_keyword($keyword)) {
                continue;
            }
            
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
        
        // Remove WordPress shortcodes entirely before any analysis
        $content = preg_replace('/\[[^\]]+\]/', ' ', $content);
        
        // AGGRESSIVE CSS/CODE PATTERN REMOVAL
        $content = preg_replace('/\b(border|margin|padding|text|font|background|color|width|height)-(top|bottom|left|right|color|style|width|radius|spacing|align|decoration|transform|shadow|family|size|weight|variant|stretch)\b/i', '', $content);
        
        $content = preg_replace('/\b(max|min)-(width|height|content|zoom)\b/', '', $content);
        $content = preg_replace('/\b(border|solid|dashed|dotted|none|auto|inherit|initial|unset)-/', '', $content);
        $content = preg_replace('/-?(top|bottom|left|right|center)\b/', '', $content);
        
        $content = preg_replace('/\b[0-9a-f]{3,6}\b/', '', $content);
        $content = preg_replace('/\b(rgba?|hsla?)\([^)]+\)/', '', $content);
        
        $content = preg_replace('/\b[0-9]+(px|em|rem|%|vh|vw|pt|cm|mm|in)\b/', '', $content);
        
        $content = preg_replace('/\b(stk|wp|btn|nav|menu|widget|plugin|theme|post|page|admin|content|sidebar|footer|header)-[a-z-]+\b/', '', $content);
        
        // Remove WordPress/HTML artifact words
        $wp_artifacts = array(
            'attachment', 'aligncenter', 'alignleft', 'alignright', 'alignnone',
            'align', 'caption', 'shortcode', 'iframe', 'embed', 'noscript',
            'onclick', 'onload', 'href', 'srcset', 'sizes', 'nofollow',
            'noopener', 'noreferrer', 'target', 'blank', 'class', 'style',
            'span', 'div', 'nbsp', 'amp', 'quot', 'lt', 'gt',
            'thumbnail', 'fullsize', 'wp-image', 'size-full', 'size-large',
            'size-medium', 'size-thumbnail', 'wp-caption', 'gallery-item',
            'gallery-columns', 'gallery-size', 'attachment-full',
            'entry-content', 'post-content', 'wp-block', 'has-text',
            'has-background', 'is-layout', 'wp-element'
        );
        foreach ($wp_artifacts as $artifact) {
            $content = preg_replace('/\b' . preg_quote($artifact, '/') . '\b/i', '', $content);
        }
        
        // Remove CSS keywords
        $css_keywords = array('important', 'serif', 'sans-serif', 'sans', 'monospace', 'inherit', 'inline', 'block', 'flex', 'grid', 'absolute', 'relative', 'fixed', 'static', 'sticky', 'hidden', 'visible', 'auto', 'none', 'initial', 'unset', 'normal', 'bold', 'italic', 'underline', 'display', 'position', 'float', 'clear', 'overflow', 'opacity', 'transform', 'transition', 'animation');
        foreach ($css_keywords as $keyword) {
            $content = preg_replace('/\b' . preg_quote($keyword, '/') . '\b/', '', $content);
        }
        
        $fonts = array('roboto', 'lato', 'arial', 'helvetica', 'verdana', 'georgia', 'times', 'courier', 'comic', 'impact', 'trebuchet', 'palatino', 'garamond');
        foreach ($fonts as $font) {
            $content = preg_replace('/\b' . preg_quote($font, '/') . '\b/', '', $content);
        }
        
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
     * Check if a keyword is WordPress/HTML junk that shouldn't be a keyword
     *
     * @param string $keyword Keyword to validate
     * @return bool True if junk
     */
    private function is_junk_keyword($keyword) {
        $junk_words = array(
            'attachment', 'align', 'caption', 'thumbnail', 'shortcode',
            'iframe', 'embed', 'noscript', 'nofollow', 'srcset', 'fullsize',
            'gallery', 'nbsp', 'display', 'overflow', 'opacity', 'float',
            'clear', 'position', 'inline', 'block', 'wrapper', 'container',
            'widget', 'sidebar', 'footer', 'header', 'plugin', 'theme'
        );
        
        $words = explode(' ', strtolower($keyword));
        
        $junk_count = 0;
        foreach ($words as $word) {
            if (in_array($word, $junk_words)) {
                $junk_count++;
            }
        }
        
        // If more than half the words are junk, reject the keyword
        if ($junk_count > 0 && ($junk_count / count($words)) >= 0.5) {
            return true;
        }
        
        return $this->is_code_pattern($keyword);
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
        
        // Comprehensive tech/code/WordPress terms blacklist
        $tech_terms = array(
            'media', 'screen', 'wrapper', 'container', 'block', 'heading', 'widget', 
            'plugin', 'theme', 'roboto', 'lato', 'arial', 'helvetica', 'verdana',
            'auto', 'inherit', 'initial', 'none', 'flex', 'grid', 'inline', 'block',
            'border', 'margin', 'padding', 'color', 'background', 'font', 'text',
            'width', 'height', 'left', 'right', 'bottom', 'center', 'style',
            'stk', 'btn', 'nav', 'menu', 'footer', 'header', 'sidebar', 'content',
            'important', 'serif', 'sans', 'monospace', 'rgba', 'hsla', 'ffffff',
            'attachment', 'align', 'caption', 'thumbnail', 'shortcode', 'iframe',
            'embed', 'noscript', 'nofollow', 'noopener', 'noreferrer', 'srcset',
            'fullsize', 'gallery', 'nbsp', 'amp', 'quot', 'display', 'position',
            'float', 'clear', 'overflow', 'opacity', 'transform', 'transition'
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
            $patterns = array(
                'biography',
                'books',
                'quotes',
                'interview',
                'reviews',
                'guide',
                'history',
                'overview',
                'information',
                'resources'
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
            'tips' => 50,
            'best practices' => 55,
            'examples' => 40
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
            'post_types' => array('post', 'page'),
            'use_ai' => true,
            'deep_analysis' => false,
            'wizard_guidelines_snapshot' => '',
            'ai_usage_context' => '',
        );
        
        $options = wp_parse_args($options, $defaults);
        
        // Load a generous but manageable number of posts for pattern detection.
        // 300 posts gives excellent coverage for finding proper nouns, names,
        // and terminology patterns without blowing memory or timeouts.
        $posts = get_posts(array(
            'post_type' => $options['post_types'],
            'posts_per_page' => 300,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        if (empty($posts)) {
            return array();
        }
        
        if ($options['use_ai']) {
            return $this->ai_generate_guidelines($posts, $options);
        }
        
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
        $deep = !empty($options['deep_analysis']);
        
        // Gather content samples BEFORE calling pattern_based_guidelines,
        // because that method clears $post->post_content to free memory.
        $content_samples = array();
        $max     = 22;
        $excerpt = 650;
        foreach ($posts as $post) {
            $content = wp_strip_all_tags($post->post_content);
            if (str_word_count($content) < 100) continue;
            $content_samples[] = array(
                'title' => $post->post_title,
                'excerpt' => substr($content, 0, $excerpt),
            );
            if (count($content_samples) >= $max) break;
        }
        
        $pattern_results = $this->pattern_based_guidelines($posts);
        
        if (empty($content_samples)) {
            error_log('MindfulSEO: No content samples for AI guidelines — skipping AI call');
            return $pattern_results;
        }
        
        $existing_context = $this->load_existing_keyword_and_guideline_context();
        if (!empty($options['wizard_guidelines_snapshot'])) {
            $existing_context['wizard_guidelines_snapshot'] = $options['wizard_guidelines_snapshot'];
        }
        
        $prompt = $this->build_guideline_generation_prompt($content_samples, $pattern_results, $existing_context);
        
        $ai_connector = MFSEO_AI_Connector::get_instance();
        $gl_ctx = ! empty( $options['ai_usage_context'] ) ? $options['ai_usage_context'] : 'content_analyzer_guidelines';
        $response = $ai_connector->generate_content($prompt, array(
            'timeout' => $deep ? 150 : 90,
            'temperature' => 0.3,
            'max_tokens' => $deep ? 6000 : 4800,
            'fast_model' => !$deep,
            'usage_context' => $gl_ctx,
        ));
        
        if (is_wp_error($response)) {
            error_log('MindfulSEO Guideline AI Error: ' . $response->get_error_message());
            $pattern_results['ai_error'] = $response->get_error_message();
            return $pattern_results;
        }
        
        error_log('MindfulSEO: AI guideline response received, length: ' . strlen($response));
        
        $ai_rules = $this->parse_ai_guideline_response($response, $pattern_results);
        error_log('MindfulSEO: Parsed ' . count($ai_rules) . ' AI guideline rules');
        
        if (!empty($ai_rules)) {
            $pattern_results['ai_guidelines'] = $ai_rules;
            $pattern_results['ai_succeeded'] = true;
            // AI generated all rule types — drop noisy pattern-based preferred terms and common phrases
            $pattern_results['preferred_terms'] = array();
            $pattern_results['common_phrases'] = array();
            $pattern_results['avoid_terms'] = array();
            unset($pattern_results['semantic_avoid_terms']);
        } else {
            $pattern_results['ai_succeeded'] = false;
        }
        
        return $pattern_results;
    }
    
    /**
     * Load existing keywords and guidelines from the database to provide
     * domain context for the AI guideline generation prompt.
     */
    private function load_existing_keyword_and_guideline_context() {
        $context = array(
            'primary_keywords' => array(),
            'existing_rules' => array(),
        );
        
        if (class_exists('MFSEO_Keyword_Manager')) {
            $km = MFSEO_Keyword_Manager::get_instance();
            $keywords = $km->get_keywords(array('limit' => 200));
            $seen = array();
            foreach ($keywords as $kw) {
                $pk = $kw->primary_keyword;
                if (!isset($seen[$pk])) {
                    $context['primary_keywords'][] = $pk;
                    $seen[$pk] = true;
                }
            }
        }
        
        if (class_exists('MFSEO_Guidelines_Engine')) {
            $ge = MFSEO_Guidelines_Engine::get_instance();
            $rules = $ge->get_all_rules(array('active_only' => false));
            foreach ($rules as $rule) {
                $context['existing_rules'][] = array(
                    'type' => $rule->rule_type,
                    'avoid' => $rule->avoid_term,
                    'preferred' => $rule->preferred_term,
                );
            }
        }
        
        return $context;
    }
    
    private function build_guideline_generation_prompt($samples, $pattern_results, $existing_context = array()) {
        $site_name = get_bloginfo('name');
        $site_description = get_bloginfo('description');
        
        $prompt = "You are an expert SEO editor creating comprehensive language guidelines for a website. ";
        $prompt .= "You will generate rules across multiple categories based on the site's content, existing keyword strategy, and existing guidelines.\n\n";
        
        $prompt .= "=== WEBSITE ===\n";
        $prompt .= "Name: {$site_name}\n";
        if (!empty($site_description)) {
            $prompt .= "Tagline: {$site_description}\n";
        }
        
        $categories = get_categories(array('hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 15));
        if (!empty($categories)) {
            $cat_names = array_map(function($c) { return $c->name; }, $categories);
            $prompt .= "Categories: " . implode(', ', $cat_names) . "\n";
        }
        $prompt .= "\n";

        if (!empty($existing_context['wizard_guidelines_snapshot'])) {
            $prompt .= "=== PREVIOUS SAVED LANGUAGE GUIDELINES (from database — IMPROVE: refine, expand, and deduplicate; replace weak rules with stronger ones) ===\n";
            $prompt .= $this->truncate_for_ai_prompt( $existing_context['wizard_guidelines_snapshot'], 12000 ) . "\n\n";
        }
        
        if (!empty($existing_context['primary_keywords'])) {
            $prompt .= "=== EXISTING KEYWORD STRATEGY ===\n";
            $prompt .= "These are the site's target SEO keywords (use them to understand the site's domain and focus):\n";
            $prompt .= implode(', ', array_slice($existing_context['primary_keywords'], 0, 40)) . "\n\n";
        }
        
        if (!empty($existing_context['existing_rules'])) {
            $prompt .= "=== EXISTING LANGUAGE GUIDELINES (do NOT duplicate) ===\n";
            $grouped = array();
            foreach ($existing_context['existing_rules'] as $r) {
                $grouped[$r['type']][] = $r;
            }
            foreach ($grouped as $type => $rules) {
                $prompt .= strtoupper(str_replace('_', ' ', $type)) . ":\n";
                foreach (array_slice($rules, 0, 15) as $r) {
                    if (!empty($r['avoid']) && !empty($r['preferred'])) {
                        $prompt .= "  - \"{$r['avoid']}\" → \"{$r['preferred']}\"\n";
                    } elseif (!empty($r['preferred'])) {
                        $prompt .= "  - \"{$r['preferred']}\"\n";
                    }
                }
            }
            $prompt .= "\n";
        }
        
        $prompt .= "=== CONTENT SAMPLES ===\n\n";
        foreach ($samples as $i => $s) {
            $prompt .= ($i + 1) . ". \"{$s['title']}\"\n   {$s['excerpt']}\n\n";
        }
        
        if (!empty($pattern_results['capitalize_terms'])) {
            $prompt .= "=== DETECTED PROPER NOUNS (already handled as capitalize rules) ===\n";
            $prompt .= implode(', ', array_slice($pattern_results['capitalize_terms'], 0, 20)) . "\n\n";
        }
        
        $prompt .= "=== TASK ===\n\n";

        $prompt .= "Build a PRACTICAL STARTER SET of language rules the site will use immediately for AI-assisted SEO (titles, meta, keywords, batch optimization). ";
        $prompt .= "Coverage should be broad enough that optimization runs smoothly without editors having to add dozens of rules by hand first.\n\n";

        $prompt .= "Generate 30-55 NEW language rules that COMPLEMENT the existing guidelines. Each rule must have a \"type\" field. The four types are:\n\n";

        $prompt .= "TYPE 1: \"avoid_term\" — SEMANTIC DOMAIN REPLACEMENTS (generate at least 12)\n";
        $prompt .= "Words that outsiders/casual writers use vs the correct domain-specific term.\n";
        $prompt .= "Both \"avoid\" and \"preferred\" fields are required.\n";
        $prompt .= "Think: What would a newcomer/outsider call things on this site vs what insiders call them?\n";
        $prompt .= "Also include common misspellings of domain terms.\n";
        $prompt .= "Examples: 'ritual' → 'practice', 'budha' → 'Buddha', 'priest' → 'lama'\n\n";

        $prompt .= "TYPE 2: \"capitalize\" — CANONICAL PROPER NAMES (generate at least 10)\n";
        $prompt .= "Use the site's STANDARD full name for each entity — Title Case, no extra fluff.\n";
        $prompt .= "PEOPLE: full name as you would list them in a directory (e.g. given names + family/Rinpoche line). Do NOT add leading adjectives (\"Great\", \"Venerable\", \"Dear\", \"Beloved\"), stacked honorifics, or descriptive clauses. No comma-separated bios.\n";
        $prompt .= "PLACES (monasteries, temples, schools, centers): OFFICIAL name only — e.g. \"Kopan Monastery\", \"Sagarmatha Secondary School\". Do NOT prepend \"Our\", \"The famous\", \"Beautiful\", region stacks, or marketing words.\n";
        $prompt .= "Only \"preferred\" field is needed (the correctly capitalized form).\n";
        $prompt .= "NEVER use standalone common nouns: 'office', 'center', 'practice', 'retreat', 'community', 'event', 'fund', 'newsletter', 'program' (unless part of a real proper name like \"X Program\" where X is the official brand).\n";
        $prompt .= "Examples: 'Lama Zopa Rinpoche', 'Medicine Buddha', 'Heart Sutra', 'Rolwaling Sangag Choling Monastery'\n\n";

        $prompt .= "TYPE 3: \"preferred_term\" — SHORT FORM → FULL FORM ONLY (generate at least 4)\n";
        $prompt .= "ONLY when the site uses BOTH a short label and a longer official name for the SAME entity (e.g. \"FPMT\" → full org name, \"ILTK\" → full institute name).\n";
        $prompt .= "Both \"avoid\" and \"preferred\" are REQUIRED. \"avoid\" must be SHORT (1–3 words). \"preferred\" must be 2–5 words max.\n";
        $prompt .= "FORBIDDEN for preferred_term: post titles, newsletter headlines, or vague phrases. Put people's and institutions' CANONICAL names under \"capitalize\" instead.\n";
        $prompt .= "preferred_term is ONLY for short label → longer official name (e.g. acronyms), not for copying headlines.\n";
        $prompt .= "Examples: 'Zopa Rinpoche' → 'Lama Zopa Rinpoche', 'ILTK' → 'Istituto Lama Tzong Khapa'\n\n";

        $prompt .= "TYPE 4: \"seo_friendly\" — KEY DOMAIN PHRASES FOR SEO (generate at least 8)\n";
        $prompt .= "Short, realistic search phrases (2–6 words) that match how people search — NOT article titles or proper nouns copied from content.\n";
        $prompt .= "Only \"preferred\" field is needed.\n";
        $prompt .= "Examples: 'buddhist meditation practice', 'tibetan buddhist teachings'\n\n";

        $prompt .= "CRITICAL RULES:\n";
        $prompt .= "- Hit the minimum counts per type; prefer slightly more avoid_term and seo_friendly if you must choose — they anchor keyword and phrasing consistency during optimization\n";
        $prompt .= "- Do NOT duplicate any existing guidelines shown above\n";
        $prompt .= "- Do NOT duplicate any detected proper nouns shown above\n";
        $prompt .= "- Focus on DOMAIN-SPECIFIC language, not general grammar\n";
        $prompt .= "- preferred_term: short↔long pairs only; \"preferred\" max 5 words. Full person/place names belong in \"capitalize\", not here.\n";
        $prompt .= "- seo_friendly: max 6 words, max ~70 characters; must read like a search query, not a page headline\n";
        $prompt .= "- capitalize: canonical full names only (people & institutions); max 7 words / 78 characters; NO headline adjectives, NO comma-separated clauses, NO em-dash subtitles\n";
        $prompt .= "- Do NOT create preferred_term rules for obscure fund names, minor programs, or rarely-referenced institutions\n";
        $prompt .= "- seo_friendly terms should be phrases people actually search for\n";
        $prompt .= "- The 'context' field briefly explains WHY the rule matters\n";
        $prompt .= "- NEVER use possessive forms (e.g. \"Rinpoche's\") as avoid terms — possessives are normal grammar, not wrong terminology\n";
        $prompt .= "- NEVER eliminate legitimate terms that have distinct meanings. E.g. 'nunnery' is NOT wrong for 'monastery' — they refer to different things\n";
        $prompt .= "- Only create avoid rules where the avoid term is genuinely WRONG or INAPPROPRIATE, not just a different but valid word\n";
        $prompt .= "- avoid_term: Do NOT flag standard English vocabulary as wrong. Words like 'enlightenment', 'morality', 'spiritual guide', 'retreat', 'prayer' are perfectly valid. Only flag terms that are genuinely incorrect or misleading in this domain.\n";
        $prompt .= "- capitalize: Ask yourself — would this word be capitalized in a newspaper? If not, it is NOT a proper noun. 'Office', 'Center', 'Fund' etc. are common nouns unless part of a specific proper name.\n\n";

        $settings = get_option('mindfulseo_settings', array());
        if (!empty($settings['guideline_generation_prompt'])) {
            $custom = trim(wp_kses_post($settings['guideline_generation_prompt']));
            if ($custom !== '') {
                $prompt .= "=== CUSTOM INSTRUCTIONS FROM SITE EDITOR (apply across all rule types) ===\n";
                $prompt .= $this->truncate_for_ai_prompt($custom, 6000) . "\n\n";
            }
        }

        $prompt .= "Respond with ONLY a valid JSON array:\n";
        $prompt .= '[{"type":"avoid_term","avoid":"wrong term","preferred":"correct term","context":"why"},';
        $prompt .= '{"type":"capitalize","preferred":"Correct Name","context":"why"},';
        $prompt .= '{"type":"preferred_term","avoid":"short form","preferred":"full correct form","context":"why"},';
        $prompt .= '{"type":"seo_friendly","preferred":"search phrase","context":"why"}]' . "\n";

        return $prompt;
    }
    
    private function parse_ai_guideline_response($response, $pattern_results) {
        $response = preg_replace('/^```json\s*\n/m', '', $response);
        $response = preg_replace('/^```\s*\n/m', '', $response);
        $response = preg_replace('/\n```$/m', '', $response);
        $response = trim($response);
        
        if (!str_starts_with($response, '[')) {
            if (preg_match('/\[[\s\S]*\]/', $response, $matches)) {
                $response = $matches[0];
            }
        }
        
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            error_log('MindfulSEO: JSON parse error in AI guidelines: ' . json_last_error_msg());
            return array();
        }
        
        $existing_terms = array();
        if (!empty($pattern_results['capitalize_terms'])) {
            foreach ($pattern_results['capitalize_terms'] as $ct) {
                $existing_terms[strtolower($ct)] = true;
            }
        }
        
        $valid_types = array('avoid_term', 'capitalize', 'preferred_term', 'seo_friendly');
        $rules = array();
        $seen = array();
        
        foreach ($data as $item) {
            $type = isset($item['type']) ? sanitize_text_field(trim($item['type'])) : 'avoid_term';
            if (!in_array($type, $valid_types)) continue;
            
            $preferred = isset($item['preferred']) ? sanitize_text_field(trim($item['preferred'])) : '';
            $avoid = isset($item['avoid']) ? sanitize_text_field(trim($item['avoid'])) : '';
            $context = isset($item['context']) ? sanitize_text_field(trim($item['context'])) : '';
            
            if (empty($preferred)) continue;
            
            if (in_array($type, array('avoid_term', 'preferred_term'))) {
                if (empty($avoid)) continue;
                if (strtolower($avoid) === strtolower($preferred)) continue;
            }

            if ( ! $this->ai_guideline_rule_passes_quality_gate( $type, $avoid, $preferred ) ) {
                continue;
            }

            // Pair-based dedupe for avoid/preferred_term so many misspellings → same correct form all survive.
            if ( $type === 'avoid_term' || $type === 'preferred_term' ) {
                $dedup_key = $type . ':' . strtolower( $avoid ) . '=>' . strtolower( $preferred );
            } else {
                $dedup_key = $type . ':' . strtolower( $preferred );
            }
            if (isset($seen[$dedup_key])) continue;
            // Pattern already adds capitalize for these tokens; skip duplicate AI capitalize only — keep avoid/preferred pairs.
            if ( $type === 'capitalize' && isset( $existing_terms[ strtolower( $preferred ) ] ) ) {
                continue;
            }

            $seen[$dedup_key] = true;
            $rules[] = array(
                'type' => $type,
                'avoid' => $avoid,
                'preferred' => $preferred,
                'context' => $context,
            );
        }
        
        return $rules;
    }

    /**
     * Drop AI rules that look like post titles, one-off entity dumps, or invalid pairs.
     *
     * @param string $type Rule type.
     * @param string $avoid Avoid text.
     * @param string $preferred Preferred text.
     * @return bool
     */
    private function ai_guideline_rule_passes_quality_gate( $type, $avoid, $preferred ) {
        $preferred = trim( (string) $preferred );
        $avoid     = trim( (string) $avoid );
        $pw        = str_word_count( $preferred );
        $aw        = str_word_count( $avoid );

        if ( $type === 'capitalize' ) {
            if ( $this->guideline_capitalize_invalid( $preferred ) ) {
                return false;
            }
        } else {
            if ( $this->guideline_string_looks_like_title_or_entity_dump( $preferred ) ) {
                return false;
            }
            if ( $avoid !== '' && $this->guideline_string_looks_like_title_or_entity_dump( $avoid ) ) {
                return false;
            }
        }

        switch ( $type ) {
            case 'capitalize':
                if ( $pw < 1 || $pw > 7 ) {
                    return false;
                }
                if ( strlen( $preferred ) > 78 ) {
                    return false;
                }
                break;
            case 'seo_friendly':
                if ( preg_match( "/'s\\b/u", $preferred ) ) {
                    return false;
                }
                if ( preg_match( '/\sorganization$/iu', $preferred ) ) {
                    return false;
                }
                if ( $pw < 2 || $pw > 6 ) {
                    return false;
                }
                if ( strlen( $preferred ) > 72 ) {
                    return false;
                }
                break;
            case 'preferred_term':
                if ( $aw < 1 || $aw > 3 ) {
                    return false;
                }
                if ( $pw < 2 || $pw > 5 ) {
                    return false;
                }
                if ( strlen( $preferred ) > 90 || strlen( $avoid ) > 45 ) {
                    return false;
                }
                break;
            case 'avoid_term':
                if ( strlen( $preferred ) > 120 || strlen( $avoid ) > 85 ) {
                    return false;
                }
                break;
            default:
                return false;
        }

        return true;
    }

    /**
     * Capitalize rules: allow full person / monastery / school names; reject headline fluff.
     *
     * @param string $s Preferred form.
     * @return bool True if invalid.
     */
    private function guideline_capitalize_invalid( $s ) {
        $s = trim( (string) $s );
        if ( $s === '' ) {
            return true;
        }
        $wc = str_word_count( $s );
        if ( $wc < 1 || $wc > 7 ) {
            return true;
        }
        if ( strlen( $s ) > 78 ) {
            return true;
        }
        if ( $this->guideline_capitalize_has_headline_fluff( $s ) ) {
            return true;
        }
        if ( in_array( strtolower( $s ), $this->get_pattern_capitalize_blocklist(), true ) ) {
            return true;
        }
        if ( substr_count( $s, ',' ) >= 2 ) {
            return true;
        }
        if ( preg_match( '/\b(Program|Coordinator|Newsletter|Stories Needed|Annual Report)\b/i', $s ) && $wc >= 5 ) {
            return true;
        }

        return false;
    }

    /**
     * Leading adjectives / promo words / title patterns — not canonical names.
     *
     * @param string $s String.
     * @return bool True if fluff detected.
     */
    private function guideline_capitalize_has_headline_fluff( $s ) {
        $t = trim( $s );
        if ( $t === '' ) {
            return true;
        }
        if ( preg_match( '/\s[—–]\s/', $t ) ) {
            return true;
        }
        if ( preg_match( '/\([^)]{25,}\)/', $t ) ) {
            return true;
        }

        $parts = preg_split( '/\s+/', $t, 2 );
        $first = isset( $parts[0] ) ? strtolower( $parts[0] ) : '';
        $bad_first = array(
            'our', 'your', 'my', 'the', 'a', 'an', 'new', 'annual', 'special', 'important', 'free', 'join',
            'celebrating', 'welcome', 'dear', 'great', 'holy', 'sacred', 'wonderful', 'amazing', 'latest', 'exclusive',
            'official', 'featured', 'introducing', 'remembering', 'honoring', 'supporting', 'discover', 'explore',
            'beautiful', 'famous', 'international', 'local', 'regarding', 'offering', 'announcing',
        );
        if ( in_array( $first, $bad_first, true ) ) {
            return true;
        }

        return false;
    }

    /**
     * Heuristic: post titles, SEO noise — for non-capitalize rule types.
     *
     * @param string $s String.
     * @return bool True if should reject.
     */
    private function guideline_string_looks_like_title_or_entity_dump( $s ) {
        $s = trim( $s );
        if ( $s === '' ) {
            return true;
        }
        if ( strlen( $s ) > 85 ) {
            return true;
        }
        $wc = str_word_count( $s );
        if ( $wc >= 6 ) {
            return true;
        }
        if ( preg_match( '/\b(Program|Secondary School|Coordinator|Newsletter|Stories Needed|Stories|Annual Report)\b/i', $s ) && $wc >= 4 ) {
            return true;
        }
        if ( preg_match( '/[—–]|(\s-\s)/', $s ) && $wc >= 4 ) {
            return true;
        }

        return false;
    }

    /**
     * Single-word pattern capitalize candidates that are common nouns / fragments, not editorial proper nouns.
     *
     * @return list<string> Lowercase.
     */
    private function get_pattern_capitalize_blocklist() {
        return array(
            'office', 'offices', 'centre', 'center', 'centres', 'centers',
            'medicine', 'medicines', 'medical', 'khapa',
        );
    }

    /**
     * Drop pattern-based capitalize terms that are generic English or meaningless fragments.
     *
     * @param array $terms Terms from find_capitalized_terms.
     * @return list<string>
     */
    private function filter_pattern_capitalize_terms( $terms ) {
        $block = array_fill_keys( $this->get_pattern_capitalize_blocklist(), true );
        $out   = array();
        foreach ( $terms as $term ) {
            $t = trim( (string) $term );
            if ( $t === '' ) {
                continue;
            }
            $lower = strtolower( $t );
            if ( isset( $block[ $lower ] ) ) {
                continue;
            }
            $out[] = $t;
        }
        return array_values( $out );
    }

    /**
     * Repeated Title Case three-word phrases (e.g. person / place names) for fragment filtering.
     *
     * @param string $content Plain text.
     * @return list<string> Canonical Title Case phrases.
     */
    private function extract_frequent_titlecase_trigrams( $content ) {
        $out = array();
        if ( preg_match_all( '/\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+){2})\b/u', $content, $m ) ) {
            $counts = array_count_values( $m[1] );
            $counts = array_filter(
                $counts,
                function ( $n ) {
                    return $n >= 6;
                }
            );
            arsort( $counts, SORT_NUMERIC );
            $out = array_slice( array_keys( $counts ), 0, 10 );
        }
        // Case-insensitive: canonical teacher name often repeated with mixed casing in body text.
        if ( preg_match_all( '/\b(Lama\s+Zopa\s+Rinpoche)\b/iu', $content, $lm ) ) {
            if ( count( $lm[0] ) >= 4 ) {
                $out[] = 'Lama Zopa Rinpoche';
            }
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * Strip junk SEO rows, merge canonical multi-word names, drop bigrams subsumed by a frequent trigram.
     *
     * @param list<string> $phrases Candidate phrases (frequency-ordered).
     * @param string       $content Plain text (same corpus as find_common_phrases).
     * @return list<string>
     */
    private function refine_common_phrases_list( array $phrases, $content ) {
        $phrases = array_map( 'trim', $phrases );
        $phrases = array_values( array_filter( $phrases ) );

        $phrases = array_values(
            array_filter(
                $phrases,
                function ( $p ) {
                    if ( preg_match( "/'s\\b/u", $p ) || preg_match( "/'s\\s*$/u", $p ) ) {
                        return false;
                    }
                    if ( preg_match( '/\sorganization$/iu', $p ) ) {
                        return false;
                    }
                    return true;
                }
            )
        );

        $trigrams = $this->extract_frequent_titlecase_trigrams( $content );
        $trigrams = array_unique( $trigrams );

        foreach ( $trigrams as $long ) {
            $before  = $phrases;
            $phrases = array_values(
                array_filter(
                    $phrases,
                    function ( $p ) use ( $long ) {
                        if ( strcasecmp( $p, $long ) === 0 ) {
                            return true;
                        }
                        if ( str_word_count( $p ) >= str_word_count( $long ) ) {
                            return true;
                        }
                        return ! preg_match( '/\b' . preg_quote( $p, '/' ) . '\b/iu', $long );
                    }
                )
            );
            $subsumed = count( $before ) > count( $phrases );
            if ( $subsumed ) {
                $have = false;
                foreach ( $phrases as $p ) {
                    if ( strcasecmp( $p, $long ) === 0 ) {
                        $have = true;
                        break;
                    }
                }
                if ( ! $have ) {
                    $phrases[] = $long;
                }
            }
        }

        $phrases = array_values( array_unique( $phrases ) );

        return array_slice( $phrases, 0, 15 );
    }
    
    /**
     * Original pattern-based guidelines analysis
     *
     * @param array $posts Posts to analyze
     * @return array Guidelines
     */
    private function pattern_based_guidelines($posts) {
        $all_content = '';
        $titles = array();
        
        foreach ($posts as $post) {
            // Replace closing tags with '. ' before stripping so the proper-noun
            // regex can't match across HTML element boundaries (prevents phantoms
            // like "Community News Stories Retreat Stories Needed" from nav menus).
            $cleaned = preg_replace('/<\/[^>]+>/', '. ', $post->post_content);
            $cleaned = wp_strip_all_tags($cleaned);
            $all_content .= ' ' . $cleaned;
            $titles[] = $post->post_title;
            $post->post_content = '';
        }
        
        $capitalize_terms = $this->find_capitalized_terms($all_content, $titles);
        $preferred_terms = $this->find_preferred_terms($all_content, $titles);
        $common_phrases = $this->find_common_phrases($all_content, $preferred_terms, $capitalize_terms);
        $avoid_terms = $this->find_avoid_terms($all_content, $preferred_terms);
        $brand_voice = $this->analyze_brand_voice($all_content);
        
        $preferred_lower = array_map('strtolower', $preferred_terms);
        $capitalize_terms = array_filter($capitalize_terms, function($term) use ($preferred_lower) {
            $term_lower = strtolower($term);
            foreach ($preferred_lower as $pref) {
                if ($term_lower === $pref || stripos($pref, $term_lower) !== false) {
                    return false;
                }
            }
            return true;
        });
        $capitalize_terms = array_values( $this->filter_pattern_capitalize_terms( $capitalize_terms ) );
        
        return array(
            'capitalize_terms' => $capitalize_terms,
            'common_phrases' => $common_phrases,
            'preferred_terms' => $preferred_terms,
            'avoid_terms' => $avoid_terms,
            'brand_voice' => $brand_voice,
        );
    }
    
    /**
     * Find consistently capitalized terms (proper nouns, not sentence starters)
     *
     * Returns BOTH single-word proper nouns and multi-word proper noun phrases.
     * Also detects ALL-CAPS acronyms. Uses strict filtering to avoid fragments
     * and common English words.
     *
     * @param string $content Content to analyze
     * @param array $titles Post titles
     * @return array Capitalized terms
     */
    private function find_capitalized_terms($content, $titles) {
        $content_clean = wp_strip_all_tags($content);
        $content_clean = html_entity_decode($content_clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (strlen($content_clean) > 500000) {
            $content_clean = substr($content_clean, 0, 500000);
        }

        // Comprehensive blocklist of common English words that start sentences
        $common_words = array(
            'this', 'that', 'these', 'those', 'there', 'their', 'they', 'them',
            'here', 'where', 'when', 'what', 'which', 'while', 'with', 'were',
            'have', 'been', 'will', 'would', 'could', 'should', 'shall',
            'many', 'much', 'most', 'more', 'some', 'such', 'each', 'every',
            'also', 'only', 'just', 'even', 'still', 'well', 'very', 'then',
            'after', 'before', 'about', 'above', 'below', 'from', 'into',
            'over', 'under', 'between', 'through', 'during', 'since', 'until',
            'please', 'thank', 'thanks', 'note', 'learn', 'read', 'view',
            'click', 'visit', 'find', 'make', 'take', 'give', 'come', 'know',
            'like', 'want', 'need', 'feel', 'look', 'work', 'call', 'help',
            'keep', 'send', 'join', 'free', 'open', 'live', 'love', 'life',
            'home', 'back', 'good', 'great', 'long', 'full', 'high', 'last',
            'first', 'next', 'part', 'both', 'being', 'other', 'same', 'told',
            'photo', 'image', 'video', 'link', 'post', 'page', 'site', 'blog',
            'fund', 'form', 'plan', 'text', 'book', 'year', 'time', 'date',
            'name', 'type', 'land', 'four', 'five', 'said', 'does', 'done',
            'however', 'therefore', 'although', 'because', 'another', 'letter',
            'world', 'today', 'right', 'place', 'number', 'point', 'group',
            'always', 'never', 'often', 'sometimes', 'usually', 'really',
            'truly', 'quite', 'rather', 'almost', 'already', 'indeed',
            'perhaps', 'maybe', 'certainly', 'actually', 'especially',
            'together', 'different', 'important', 'special', 'possible',
            'several', 'nothing', 'everything', 'something', 'anything',
            'everyone', 'someone', 'anyone', 'nobody', 'people', 'person',
            'begin', 'began', 'start', 'using', 'making', 'going', 'coming',
            'taking', 'giving', 'looking', 'working', 'living', 'getting',
            'offer', 'offers', 'offered', 'welcome', 'share', 'support',
            'program', 'project', 'event', 'service', 'practice', 'course',
            'class', 'meeting', 'retreat', 'conference', 'session', 'center',
            'recent', 'latest', 'early', 'late', 'young', 'local', 'annual',
            'dear', 'happy', 'sorry', 'along', 'among', 'against', 'toward',
            'abbey', 'duke', 'earth', 'month', 'april', 'march', 'august',
            'general', 'particular', 'according',
        );

        // Track mid-sentence capitalized words vs lowercase occurrences
        $mid_sentence_caps = array();
        $lowercase_counts = array();

        $sentences = preg_split('/(?<=[.!?:;])\s+/', $content_clean);

        foreach ($sentences as $sentence) {
            $words = preg_split('/\s+/', trim($sentence));

            foreach ($words as $idx => $word) {
                $clean_word = trim($word, '.,;:!?"\'()[]{}–—-…');

                if (strlen($clean_word) < 5) {
                    continue;
                }

                $lower = strtolower($clean_word);

                if (in_array($lower, $common_words)) {
                    continue;
                }

                if (preg_match('/^[A-Z][a-z]+$/', $clean_word)) {
                    if ($idx > 0) {
                        if (!isset($mid_sentence_caps[$clean_word])) {
                            $mid_sentence_caps[$clean_word] = 0;
                        }
                        $mid_sentence_caps[$clean_word]++;
                    }
                } elseif (preg_match('/^[a-z]+$/', $clean_word)) {
                    if (!isset($lowercase_counts[$lower])) {
                        $lowercase_counts[$lower] = 0;
                    }
                    $lowercase_counts[$lower]++;
                }
            }
        }

        // Strict filtering: must appear capitalized mid-sentence at least 5 times
        // and appear capitalized MORE than lowercase
        $proper_nouns = array();

        foreach ($mid_sentence_caps as $word => $cap_count) {
            if ($cap_count < 5) {
                continue;
            }

            $lower = strtolower($word);
            $low_count = isset($lowercase_counts[$lower]) ? $lowercase_counts[$lower] : 0;

            // Must appear capitalized significantly more than lowercase
            if ($low_count == 0 || $cap_count > ($low_count * 3)) {
                $proper_nouns[$word] = $cap_count;
            }
        }

        // Also add ALL-CAPS acronyms found frequently
        if (preg_match_all('/\b([A-Z]{2,10})\b/', $content_clean, $acr_matches)) {
            $acr_counts = array_count_values($acr_matches[0]);
            $skip_acrs = array('HTML', 'CSS', 'PHP', 'URL', 'HTTP', 'HTTPS', 'RSS', 'API', 'XML', 'JSON', 'PDF', 'ID', 'OK', 'AM', 'PM', 'US', 'UK');
            foreach ($acr_counts as $acr => $count) {
                if ($count >= 5 && !in_array($acr, $skip_acrs)) {
                    $proper_nouns[$acr] = $count;
                }
            }
        }

        arsort($proper_nouns);

        return array_keys(array_slice($proper_nouns, 0, 40));
    }
    
    /**
     * Find common domain-specific phrases in content that represent
     * consistent terminology the site uses.
     *
     * Returns phrases that are used frequently and are genuine domain
     * terms, not fragments of proper names or generic English.
     *
     * @param string $content Content to analyze
     * @param array $proper_nouns Known proper nouns to filter fragments against
     * @param array $capitalize_terms Known capitalized terms to filter against
     * @return array Common phrases
     */
    private function find_common_phrases($content, $proper_nouns = array(), $capitalize_terms = array()) {
        $content_clean = wp_strip_all_tags($content);
        $content_clean = html_entity_decode($content_clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        if (strlen($content_clean) > 500000) {
            $content_clean = substr($content_clean, 0, 500000);
        }

        // Build a list of known proper noun strings for fragment detection (include frequent trigrams).
        $known_names = array_merge($proper_nouns, $capitalize_terms);
        $known_names = array_merge($known_names, $this->extract_frequent_titlecase_trigrams($content_clean));
        $known_names_lower = array_map('strtolower', $known_names);

        $words = preg_split('/\s+/', $content_clean);
        $raw_phrases = array();

        for ($i = 0; $i < count($words) - 1; $i++) {
            $w1 = trim($words[$i], '.,;:!?"\'()[]{}–—-…');
            $w2 = trim($words[$i + 1], '.,;:!?"\'()[]{}–—-…');
            $w1 = preg_replace("/[''']s$/", '', $w1);
            $w2 = preg_replace("/[''']s$/", '', $w2);
            if (strlen($w1) >= 4 && strlen($w2) >= 4) {
                $raw_phrases[] = $w1 . ' ' . $w2;
            }
            // Acronym + word (e.g. "FPMT organization") — tracked so we can drop the noisy pair later.
            if (strlen($w1) >= 2 && strlen($w1) <= 12 && preg_match('/^[A-Z]{2,12}$/', $w1) && strlen($w2) >= 4) {
                $raw_phrases[] = $w1 . ' ' . $w2;
            }
        }

        $frequency = array_count_values($raw_phrases);

        $frequency = array_filter($frequency, function($count) {
            return $count >= 18;
        });

        $filtered = array();
        $generic_words = array('the', 'and', 'for', 'that', 'this', 'with', 'from', 'have', 'been',
            'will', 'would', 'could', 'should', 'about', 'which', 'their', 'there', 'when',
            'where', 'what', 'your', 'more', 'some', 'than', 'into', 'very', 'also', 'just',
            'only', 'other', 'such', 'make', 'many', 'over', 'know', 'like', 'need', 'want',
            'read', 'click', 'here', 'view', 'post', 'page', 'site',
            'you', 'are', 'who', 'has', 'was', 'were', 'had', 'can', 'may', 'did', 'does',
            'its', 'our', 'per', 'not', 'how', 'why', 'but', 'nor', 'yet', 'all', 'any',
            'each', 'both', 'own', 'her', 'his', 'she', 'him', 'they', 'them', 'then',
            'now', 'still', 'even', 'well', 'too', 'much', 'most', 'less', 'few', 'same',
            'new', 'old', 'good', 'best', 'last', 'next', 'first', 'being', 'having',
            'doing', 'going', 'using', 'made', 'take', 'give', 'come', 'keep', 'help',
            'photo', 'image', 'courtesy', 'please', 'thank', 'dear', 'dedicated', 'via',
            'org', 'part', 'way', 'one', 'two', 'day', 'time', 'year', 'work', 'life',
            'after', 'before', 'between', 'through', 'during', 'since', 'until',
            'based', 'able', 'available', 'different', 'important', 'those', 'these',
            'values', 'worldwide', 'teaching', 'learn', 'learning', 'become', 'becoming',
            'wisdom', 'sacred', 'holy', 'divine', 'spiritual', 'peace', 'peaceful');

        foreach ($frequency as $phrase => $count) {
            $lower = strtolower($phrase);

            if (preg_match('/\d/', $phrase)) continue;
            if (strlen($phrase) > 32) continue;
            if (preg_match("/'s\\b/u", $phrase) || preg_match('/\sorganization$/iu', $phrase)) {
                continue;
            }
            if ($this->is_code_pattern($lower)) continue;

            $pwords = explode(' ', $lower);
            $generic = false;
            foreach ($pwords as $pw) {
                if (in_array($pw, $generic_words) || strlen($pw) < 4) {
                    $generic = true;
                    break;
                }
            }
            if ($generic) continue;

            // Skip if this phrase is a substring of any known proper noun
            $is_name_fragment = false;
            foreach ($known_names_lower as $name) {
                if (strlen($name) > strlen($lower) && stripos($name, $lower) !== false) {
                    $is_name_fragment = true;
                    break;
                }
            }
            if ($is_name_fragment) continue;

            $filtered[$phrase] = $count;
        }

        arsort($filtered);

        $candidates = array_keys(array_slice($filtered, 0, 15));

        return $this->refine_common_phrases_list($candidates, $content_clean);
    }
    
    /**
     * Legacy hook: pattern-based “preferred term” extraction.
     *
     * Previously this returned long multi-word proper nouns from titles/content.
     * Those are not valid “preferred term” rules without a real short→long editorial
     * pair, so the wizard imported post titles and person names as garbage rows.
     * Real preferred_term rules come from AI (avoid + preferred). This returns none.
     *
     * @param string $content All post content combined
     * @param array  $titles Post titles
     * @return array Always empty
     */
    private function find_preferred_terms($content, $titles) {
        return array();
    }

    /**
     * Find avoid terms — shortened or partial name forms that should be
     * replaced with their full preferred version.
     *
     * For example, if content uses both "Smith" alone and "Dr. John Smith",
     * the short form should be flagged with the full form as the preferred replacement.
     *
     * @param string $content All post content combined
     * @param array $preferred_terms Already-detected preferred terms
     * @return array Each element: ['avoid' => short form, 'preferred' => full form]
     */
    /**
     * Pattern-based avoid terms are disabled — they produced low-quality
     * "partial name → full name" rules that aren't real avoid terms.
     * Genuine avoid terms (e.g. "church" → "center") are handled by
     * the AI semantic rules in ai_generate_guidelines().
     */
    private function find_avoid_terms($content, $preferred_terms) {
        return array();
    }

    /**
     * Extract key entities from content samples (people, organizations, topics)
     *
     * Uses a universal approach: detects multi-word proper noun phrases by
     * capitalization patterns and frequency, rather than relying on hardcoded
     * title prefixes. Works for any niche or naming convention.
     *
     * @param array $samples Content samples
     * @param array $all_titles Optional array of ALL post titles for broader scanning
     * @return array Extracted entities
     */
    private function extract_site_entities($samples, $all_titles = array()) {
        $entities = array(
            'people' => array(),
            'locations' => array(),
            'programs' => array(),
            'key_terms' => array(),
            'categories_with_counts' => array(),
            'tags_with_counts' => array(),
        );
        
        $all_text = '';
        foreach ($samples as $sample) {
            $all_text .= ' ' . $sample['title'] . ' ' . $sample['excerpt'];
        }
        if (!empty($all_titles)) {
            $titles_for_scan = array_slice($all_titles, 0, 400);
            $all_text .= ' ' . implode('. ', $titles_for_scan);
        }

        $proper_noun_counts = array();
        $blocklist = array('Please Enjoy', 'Read More', 'Click Here', 'Learn More',
            'Find Out', 'Check Out', 'Sign Up', 'Join Us', 'New York',
            'Share This', 'Leave Reply', 'Filed Under', 'Posted',
            'Continue Reading', 'View All', 'Load More',
            'Previous Post', 'Next Post', 'Related Posts', 'Recent Posts',
            'No Comments', 'Leave Comment', 'Your Email');
        $title_start_words = array('news', 'regarding', 'our', 'second', 'offer',
            'update', 'report', 'annual', 'monthly', 'stories', 'newsletter',
            'how', 'why', 'what', 'when', 'where', 'weekly', 'daily',
            'latest', 'recent', 'upcoming', 'welcome', 'introducing',
            'announcing', 'special', 'important', 'notice',
            'the', 'an', 'a', 'its', 'his', 'her', 'this', 'that');
        $common_last_words = array('continue', 'continues', 'continued', 'update', 'updates',
            'updated', 'needed', 'begins', 'started', 'starts', 'ends', 'ended', 'opens',
            'opened', 'closed', 'review', 'reviewed', 'submit', 'available', 'upcoming',
            'report', 'reported', 'selected', 'completed', 'announced', 'published',
            'received', 'presented', 'offered', 'included', 'featured', 'released');

        if (preg_match_all('/\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+){1,4})\b/', $all_text, $pn_matches)) {
            foreach ($pn_matches[0] as $phrase) {
                $phrase = trim($phrase);
                if (strlen($phrase) < 5) continue;

                $blocked = false;
                foreach ($blocklist as $bl) {
                    if (stripos($phrase, $bl) !== false) { $blocked = true; break; }
                }
                if ($blocked) continue;

                $phrase_words = explode(' ', $phrase);
                $first_word = strtolower($phrase_words[0]);
                if (in_array($first_word, $title_start_words)) continue;

                $last_word = end($phrase_words);
                if (strlen($last_word) <= 3) continue;
                if (in_array(strtolower($last_word), $common_last_words)) continue;

                if (!isset($proper_noun_counts[$phrase])) {
                    $proper_noun_counts[$phrase] = 0;
                }
                $proper_noun_counts[$phrase]++;
            }
        }

        $acronyms = array();
        if (preg_match_all('/\b([A-Z]{2,10})\b/', $all_text, $acr_matches)) {
            foreach ($acr_matches[0] as $acr) {
                $skip = array('HTML', 'CSS', 'PHP', 'URL', 'HTTP', 'HTTPS', 'RSS', 'API', 'XML', 'JSON', 'PDF', 'ID', 'OK');
                if (in_array($acr, $skip)) continue;
                if (!isset($acronyms[$acr])) {
                    $acronyms[$acr] = 0;
                }
                $acronyms[$acr]++;
            }
        }

        uksort($proper_noun_counts, function($a, $b) use ($proper_noun_counts) {
            $len_diff = strlen($b) - strlen($a);
            if ($len_diff !== 0) return $len_diff;
            return $proper_noun_counts[$b] - $proper_noun_counts[$a];
        });
        $org_suffixes = array('fund', 'centre', 'center', 'project', 'college',
            'school', 'monastery', 'nunnery', 'temple', 'institute', 'foundation',
            'association', 'society', 'trust', 'program', 'programme', 'initiative',
            'retreat', 'pilgrimage', 'mantras', 'puja', 'teachers', 'committee',
            'council', 'board', 'network', 'alliance', 'organization', 'charity',
            'leeds', 'london', 'bodhichitta', 'sangha', 'gonpa', 'gompa');
        $deduped = array();
        $deduped_counts = array();
        foreach (array_keys($proper_noun_counts) as $phrase) {
            if ($proper_noun_counts[$phrase] < 2) continue;
            $dominated = false;
            foreach ($deduped as $existing) {
                if (stripos($existing, $phrase) !== false) {
                    if ($proper_noun_counts[$phrase] > $proper_noun_counts[$existing] * 1.5) {
                        break;
                    }
                    $extra_text = trim(str_ireplace($phrase, '', $existing));
                    $extra_words = array_filter(explode(' ', strtolower($extra_text)));
                    $has_org_suffix = false;
                    foreach ($extra_words as $ew) {
                        if (in_array($ew, $org_suffixes)) { $has_org_suffix = true; break; }
                    }
                    if ($has_org_suffix) {
                        break;
                    }
                    $dominated = true;
                    break;
                }
            }
            if (!$dominated) {
                $deduped[] = $phrase;
                $deduped_counts[$phrase] = $proper_noun_counts[$phrase];
            }
        }
        arsort($deduped_counts);
        $entities['people'] = array_slice($deduped_counts, 0, 25, true);

        arsort($acronyms);
        $acr_with_counts = array();
        foreach ($acronyms as $acr => $count) {
            if ($count >= 3) {
                $acr_with_counts[$acr] = $count;
            }
        }
        $entities['key_terms'] = array_slice($acr_with_counts, 0, 10, true);

        $categories = get_categories(array('hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC'));
        foreach ($categories as $category) {
            if ($category->slug !== 'uncategorized' && $category->count > 0) {
                $entities['categories_with_counts'][$category->name] = $category->count;
                $entities['programs'][] = $category->name;
            }
        }

        $tags = get_tags(array('hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 50));
        if (!empty($tags) && !is_wp_error($tags)) {
            foreach ($tags as $tag) {
                $entities['tags_with_counts'][$tag->name] = $tag->count;
                $entities['programs'][] = $tag->name;
            }
        }

        $post_types = get_post_types(array('public' => true, '_builtin' => false), 'objects');
        foreach ($post_types as $post_type) {
            if (!in_array($post_type->name, array('attachment', 'revision', 'nav_menu_item'))) {
                $entities['programs'][] = $post_type->labels->name;
            }
        }

        $entities['programs'] = array_slice(array_unique($entities['programs']), 0, 30);
        
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

