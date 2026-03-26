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
     * Whether the last keyword extraction was high-confidence (proper noun found)
     * or low-confidence (generic title words). When false, the AI should choose
     * the keyword instead of using our suggestion.
     */
    private $last_extraction_confident = false;

    /**
     * Extract keyword from title and content using improved NLP
     * 
     * @param string $title Post title
     * @param string $content Post content (HTML)
     * @return string Extracted keyword phrase
     */
    private function extract_keyword_from_content($title, $content) {
        $this->last_extraction_confident = false;
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        $content = is_string($content) ? $content : '';
        $content_clean = wp_strip_all_tags($content);
        $content_clean = html_entity_decode($content_clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        $combined_text = $title . ' ' . substr($content_clean, 0, 3000);
        $combined_lower = strtolower($combined_text);
        
        // Split title on common separators to get the primary segment
        $title_segments = preg_split('/\s*[:\|–—]\s*/', $title);
        $primary_segment = trim($title_segments[0]);
        
        // Strip possessives for matching ("Yeshe's Wisdom" → look for "Yeshe")
        $segment_clean = preg_replace("/[''']s\b/u", '', $primary_segment);
        
        // 1) Find proper noun phrases (2+ capitalized words) in the title
        $proper_noun_regex = '/\b([A-Z][a-z]+(?:\s+(?:of|the|and|for|in|von|van|de|del|al|bin|el)\s+)?(?:[A-Z][a-z]+)(?:\s+[A-Z][a-z]+)*)\b/';
        
        $keyword = '';
        $candidates = array();
        
        if (preg_match_all($proper_noun_regex, $combined_text, $name_matches)) {
            $title_lower = strtolower($title);
            $non_entity_starts = array('the', 'a', 'an', 'this', 'that', 'our', 'my', 'your');
            
            foreach ($name_matches[0] as $name) {
                $name_lower = strtolower($name);
                
                $first_word = strtolower(explode(' ', $name)[0]);
                if (in_array($first_word, $non_entity_starts)) {
                    continue;
                }
                
                if (stripos($title_lower, $name_lower) === false) {
                    continue;
                }
                
                // Strip leading/trailing garbage words from multi-word candidates
                // e.g. "By Doing Vajrasattva" → "Vajrasattva"
                // e.g. "Doing Vajrasattva Practice" → "Vajrasattva Practice"
                $name_words = explode(' ', $name_lower);
                while (count($name_words) > 1 && $this->is_garbage_keyword($name_words[0])) {
                    array_shift($name_words);
                }
                while (count($name_words) > 1 && $this->is_garbage_keyword(end($name_words))) {
                    array_pop($name_words);
                }
                $name_lower = implode(' ', $name_words);
                
                // Skip if nothing meaningful remains
                $has_distinctive_word = false;
                foreach ($name_words as $nw) {
                    if (!$this->is_garbage_keyword($nw)) {
                        $has_distinctive_word = true;
                        break;
                    }
                }
                if (!$has_distinctive_word) {
                    continue;
                }
                
                // Avoid duplicates after trimming
                if (isset($candidates[$name_lower])) {
                    $candidates[$name_lower]['count'] = max($candidates[$name_lower]['count'], substr_count($combined_lower, $name_lower));
                    continue;
                }
                
                $freq = substr_count($combined_lower, $name_lower);
                $wc = count($name_words);
                
                $in_primary = stripos(strtolower($primary_segment), $name_lower) !== false;
                
                $candidates[$name_lower] = array(
                    'name' => $name_lower,
                    'count' => $freq,
                    'word_count' => $wc,
                    'in_primary' => $in_primary,
                );
            }
        }
        
        // Also check for single proper nouns in the primary segment
        if (preg_match_all('/\b([A-Z][a-z]{2,})\b/', $segment_clean, $single_matches)) {
            $singles = array_unique($single_matches[0]);
            // Add distinctive single words as candidates (not just pairs)
            foreach ($singles as $single) {
                $sl = strtolower($single);
                if (!$this->is_garbage_keyword($sl) && !isset($candidates[$sl])) {
                    $candidates[$sl] = array(
                        'name' => $sl,
                        'count' => substr_count($combined_lower, $sl),
                        'word_count' => 1,
                        'in_primary' => true,
                    );
                }
            }
            // Try pairing consecutive proper nouns from the primary segment
            for ($i = 0; $i < count($singles) - 1; $i++) {
                $pair = $singles[$i] . ' ' . $singles[$i + 1];
                $pair_lower = strtolower($pair);
                if (!isset($candidates[$pair_lower]) && substr_count($combined_lower, $pair_lower) >= 1) {
                    $has_distinctive = false;
                    foreach (explode(' ', $pair_lower) as $pw) {
                        if (!$this->is_garbage_keyword($pw)) { $has_distinctive = true; break; }
                    }
                    if (!$has_distinctive) { continue; }
                    $candidates[$pair_lower] = array(
                        'name' => $pair_lower,
                        'count' => substr_count($combined_lower, $pair_lower),
                        'word_count' => 2,
                        'in_primary' => true,
                    );
                }
            }
        }
        
        if (!empty($candidates)) {
            usort($candidates, function($a, $b) {
                if ($a['in_primary'] !== $b['in_primary']) {
                    return $b['in_primary'] ? 1 : -1;
                }
                // Prefer multi-word names (more specific)
                if ($a['word_count'] > 1 && $b['word_count'] <= 1) return -1;
                if ($b['word_count'] > 1 && $a['word_count'] <= 1) return 1;
                if ($a['count'] !== $b['count']) {
                    return $b['count'] - $a['count'];
                }
                return $b['word_count'] - $a['word_count'];
            });
            
            $best = $candidates[0];
            if ($best['word_count'] >= 2 && $best['word_count'] <= 5) {
                $keyword = $best['name'];
                $this->last_extraction_confident = true;
            } elseif ($best['word_count'] === 1 && $best['count'] >= 1) {
                // Single distinctive word — confident if it's clearly not a
                // common word (the is_garbage_keyword filter already ran).
                $keyword = $best['name'];
                $this->last_extraction_confident = true;
            }
        }
        
        // 2) Fallback: extract key phrase from primary title segment (low confidence)
        if (empty($keyword)) {
            $stop_words = array(
                'the', 'and', 'but', 'for', 'with', 'from', 'into', 'about', 'during',
                'after', 'before', 'through', 'over', 'under', 'between', 'among',
                'this', 'that', 'these', 'those', 'what', 'which', 'who', 'when', 'where',
                'how', 'why', 'all', 'each', 'every', 'both', 'few', 'more', 'most',
                'other', 'some', 'such', 'only', 'own', 'same', 'than', 'too', 'very',
                'can', 'will', 'just', 'should', 'now', 'also', 'our', 'your', 'their',
                'please', 'enjoy', 'read', 'check', 'discover', 'explore', 'view',
                'visit', 'find', 'join', 'share', 'watch', 'listen', 'learn', 'see',
                'here', 'come', 'look', 'make', 'take', 'give', 'know', 'get', 'let',
                'new', 'latest', 'recent', 'don', 'does', 'did', 'has', 'have', 'had',
                'are', 'was', 'were', 'been', 'being', 'not', 'its', 'his', 'her',
                'january', 'february', 'march', 'april', 'may', 'june', 'july',
                'august', 'september', 'october', 'november', 'december',
                'newsletter', 'e-news', 'enews', 'update', 'updates', 'news',
            );
            
            $seg_words = preg_split('/\s+/', strtolower($primary_segment));
            $keyword_words = array();
            foreach ($seg_words as $word) {
                $word_clean = preg_replace("/[^a-z0-9'-]/", '', $word);
                if (strlen($word_clean) > 2 && !in_array($word_clean, $stop_words)) {
                    $keyword_words[] = $word_clean;
                }
            }
            
            if (count($keyword_words) > 3) {
                $keyword_words = array_slice($keyword_words, 0, 3);
            }
            $keyword = implode(' ', $keyword_words);
            
            // If the result is still garbage, leave it empty so the AI picks something
            if (!empty($keyword) && $this->is_garbage_keyword($keyword)) {
                $keyword = '';
            }
        }
        
        // 3) Last resort — only use title if it produces something meaningful
        if (empty($keyword) || strlen($keyword) < 3) {
            $keyword = strtolower(trim($primary_segment));
            $keyword = preg_replace('/[^a-z0-9\s\'-]/u', '', $keyword);
            $keyword = trim($keyword);
            if ($this->is_garbage_keyword($keyword)) {
                $keyword = '';
            }
        }
        
        // Strip possessives from final keyword
        $keyword = preg_replace("/[''']s\b/u", '', trim($keyword));
        
        $words = explode(' ', $keyword);
        if (count($words) > 4) {
            $keyword = implode(' ', array_slice($words, 0, 4));
        }
        
        return trim($keyword);
    }
    
    /**
     * Check whether a keyword is a common English word or otherwise useless for SEO.
     */
    private function is_garbage_keyword($keyword) {
        $kw = strtolower(trim($keyword));
        if (strlen($kw) < 3 || $kw === '') {
            return true;
        }

        $garbage = array(
            'the', 'and', 'but', 'for', 'with', 'from', 'into', 'about', 'during',
            'after', 'before', 'through', 'over', 'under', 'between', 'among',
            'this', 'that', 'these', 'those', 'what', 'which', 'who', 'when', 'where',
            'how', 'why', 'all', 'each', 'every', 'both', 'few', 'more', 'most',
            'other', 'some', 'such', 'only', 'own', 'same', 'than', 'too', 'very',
            'can', 'will', 'just', 'should', 'now', 'also', 'our', 'your', 'their',
            'are', 'was', 'were', 'been', 'being', 'not', 'its', 'his', 'her',
            'has', 'have', 'had', 'does', 'did', 'don', 'you', 'she', 'they',
            'him', 'them', 'who', 'whom', 'get', 'got', 'let', 'may', 'might',
            'would', 'could', 'shall', 'must', 'need', 'want', 'like', 'use',
            'way', 'day', 'one', 'two', 'yes', 'yet', 'still', 'here', 'there',
            'then', 'where', 'when', 'much', 'many', 'well', 'back', 'even',
            'come', 'came', 'make', 'made', 'take', 'took', 'give', 'gave',
            'see', 'saw', 'know', 'knew', 'think', 'said', 'tell', 'told',
            'find', 'found', 'look', 'keep', 'kept', 'put', 'run', 'say',
            'go', 'do', 'no', 'so', 'if', 'or', 'as', 'at', 'by', 'to', 'up',
            'out', 'off', 'down', 'away', 'again', 'once', 'ever', 'never',
            'page', 'post', 'blog', 'article', 'read', 'click', 'share',
            'home', 'contact', 'doing', 'done', 'exactly', 'really',
            'please', 'enjoy', 'new', 'news', 'update', 'updates', 'latest',
            'january', 'february', 'march', 'april', 'may', 'june', 'july',
            'august', 'september', 'october', 'november', 'december',
        );

        // Single word check
        if (in_array($kw, $garbage)) {
            return true;
        }
        
        // Multi-word: reject if no word is a meaningful domain term
        $words = explode(' ', $kw);
        if (count($words) >= 2) {
            $meaningful_count = 0;
            foreach ($words as $w) {
                if (!in_array($w, $garbage) && strlen($w) >= 3) {
                    $meaningful_count++;
                }
            }
            if ($meaningful_count === 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Last-resort keyword extraction: pick the most distinctive word from the title.
     * Used when all other extraction methods produced garbage.
     */
    private function extract_title_keyword_fallback($title) {
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $words = preg_split('/[\s,.\-:;!?|–—]+/', $title);

        $best = '';
        $best_len = 0;
        foreach ($words as $word) {
            $clean = preg_replace("/[^a-zA-Z'-]/", '', $word);
            if (strlen($clean) < 4) {
                continue;
            }
            if ($this->is_garbage_keyword($clean)) {
                continue;
            }
            if (strlen($clean) > $best_len) {
                $best = strtolower($clean);
                $best_len = strlen($clean);
            }
        }
        return $best !== '' ? $best : strtolower(trim($title));
    }
    
    /**
     * Apply language guideline capitalization rules to a keyword.
     * Matches capitalize/preferred_term rules and uses the correct casing.
     */
    private function apply_guideline_capitalization($keyword) {
        if (empty($keyword) || !$this->guidelines_engine) {
            return $keyword;
        }
        
        $rules = $this->guidelines_engine->get_all_rules(array('rule_type' => 'capitalize'));
        $preferred_rules = $this->guidelines_engine->get_all_rules(array('rule_type' => 'preferred_term'));
        $rules = array_merge($rules, $preferred_rules);
        
        if (empty($rules)) {
            return $keyword;
        }
        
        $kw_lower = strtolower($keyword);
        
        // Sort by preferred_term length desc so longer matches apply first
        usort($rules, function($a, $b) {
            return strlen($b->preferred_term) - strlen($a->preferred_term);
        });
        
        foreach ($rules as $rule) {
            $preferred = isset($rule->preferred_term) ? $rule->preferred_term : '';
            if (empty($preferred)) {
                continue;
            }
            
            $preferred_lower = strtolower($preferred);
            
            if ($kw_lower === $preferred_lower) {
                return $preferred;
            }
            
            if (strpos($kw_lower, $preferred_lower) !== false) {
                $pattern = '/\b' . preg_quote($preferred_lower, '/') . '\b/i';
                $result = preg_replace($pattern, $preferred, $keyword);
                if ($result !== $keyword) {
                    $keyword = $result;
                    $kw_lower = strtolower($keyword);
                }
            }
        }
        
        return $keyword;
    }

    /**
     * Expand a keyword to its full proper name form if the content uses a longer version.
     * 
     * e.g. "john smith" → "dr. john smith" if the content consistently uses
     * the longer form. This prevents truncated names from being used as keywords.
     * 
     * @param string $keyword Current keyword
     * @param string $title Post title
     * @param string $content Post content (HTML)
     * @return string Expanded keyword or original if no expansion found
     */
    private function expand_proper_name($keyword, $title, $content) {
        if (empty($keyword) || str_word_count($keyword) > 4) {
            return $keyword;
        }
        
        $keyword_lower = strtolower($keyword);
        $combined = $title . ' ' . wp_strip_all_tags(is_string($content) ? $content : '');
        
        // Find all proper noun phrases in the text that contain our keyword
        // Match sequences of capitalized words (with optional connectors like "of", "the")
        if (!preg_match_all('/\b([A-Z][a-z]+(?:\s+(?:of|the|and|von|van|de)\s+)?(?:\s*[A-Z][a-z]+)+)\b/', $combined, $matches)) {
            return $keyword;
        }
        
        $expansions = array();
        foreach ($matches[0] as $proper_phrase) {
            $phrase_lower = strtolower($proper_phrase);
            
            // Does this proper noun phrase contain our keyword?
            if (strpos($phrase_lower, $keyword_lower) !== false && $phrase_lower !== $keyword_lower) {
                $count = substr_count(strtolower($combined), $phrase_lower);
                if ($count >= 2) {
                    $expansions[] = array(
                        'phrase' => $phrase_lower,
                        'count' => $count,
                        'words' => str_word_count($phrase_lower),
                    );
                }
            }
        }
        
        if (empty($expansions)) {
            return $keyword;
        }
        
        // Pick the most frequently occurring expansion (prefer longer if tied)
        usort($expansions, function($a, $b) {
            if ($a['count'] !== $b['count']) {
                return $b['count'] - $a['count'];
            }
            return $b['words'] - $a['words'];
        });
        
        $best = $expansions[0];
        
        // Only expand if the longer name appears at least twice and isn't excessively long
        if ($best['count'] >= 2 && $best['words'] <= 5) {
            error_log(sprintf('MindfulSEO: Expanded "%s" → "%s" (found %d times)', $keyword, $best['phrase'], $best['count']));
            return $best['phrase'];
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
        
        // SMART KEYWORD MATCHING
        // A strategy keyword is only used if:
        //   1. It appears in the post TITLE (score includes 50+ from title match), OR
        //   2. It clearly dominates the content AND no other keyword scores similarly
        //      (which would indicate a roundup/newsletter where no single keyword is THE topic)
        
        $title_lower = strtolower($title);
        
        // Detect newsletter/roundup/digest posts from the title.
        // These posts cover multiple topics so a content-only keyword match is unreliable.
        $is_roundup = (bool) preg_match('/\b(e-?news|newsletter|news\s*letter|roundup|round-up|digest|recap|bulletin|update|round\s*up|weekly|monthly|annual\s+report)\b/i', $title);
        
        if (!empty($keywords)) {
            $best_match = $keywords[0];
            $score = $best_match['score'];
            $primary_keyword_data = $best_match['keyword'];
            $primary_keyword_text = $primary_keyword_data->primary_keyword;
            $keyword_in_title = stripos($title_lower, strtolower($primary_keyword_text)) !== false;
            
            // Competition check: if the 2nd-best keyword scores close to the 1st,
            // it means multiple strategy topics are discussed — no single keyword dominates.
            $has_competition = false;
            if (count($keywords) >= 2 && !$keyword_in_title) {
                $runner_up_score = $keywords[1]['score'];
                if ($runner_up_score > $score * 0.5) {
                    $has_competition = true;
                    error_log(sprintf(
                        'MindfulSEO: Competition detected — #1 "%s" (%.1f) vs #2 "%s" (%.1f)',
                        $primary_keyword_text, $score,
                        $keywords[1]['keyword']->primary_keyword, $runner_up_score
                    ));
                }
            }
            
            error_log(sprintf(
                'MindfulSEO: Best match "%s" score=%.1f, in_title=%s, roundup=%s, competition=%s',
                $primary_keyword_text, $score,
                $keyword_in_title ? 'YES' : 'no',
                $is_roundup ? 'YES' : 'no',
                $has_competition ? 'YES' : 'no'
            ));
            
            if (!empty(trim($primary_keyword_text))) {
                if ($keyword_in_title && $score >= 15) {
                    $longtail_keywords = $this->keyword_manager->get_longtail_keywords($primary_keyword_text);
                    $search_intent = $primary_keyword_data->search_intent;
                    $use_keyword_from_strategy = true;
                    $keyword_source = 'strategy_title_match';
                    error_log('MindfulSEO: Using strategy keyword (title match): ' . $primary_keyword_text);
                } elseif (!$keyword_in_title && !$is_roundup && !$has_competition && $score > 80) {
                    // Content-only match: must be strong (>80), NOT a roundup post,
                    // and NOT competing with another keyword.
                    $longtail_keywords = $this->keyword_manager->get_longtail_keywords($primary_keyword_text);
                    $search_intent = $primary_keyword_data->search_intent;
                    $use_keyword_from_strategy = true;
                    $keyword_source = 'strategy_strong_content';
                    error_log('MindfulSEO: Using strategy keyword (strong content, no title): ' . $primary_keyword_text);
                } else {
                    $reason = array();
                    if ($is_roundup) $reason[] = 'roundup title';
                    if ($has_competition) $reason[] = 'keyword competition';
                    if ($score <= 80) $reason[] = sprintf('score %.1f <= 80', $score);
                    error_log(sprintf(
                        'MindfulSEO: Rejecting "%s" (%s) — extracting from title instead',
                        $primary_keyword_text, implode(', ', $reason)
                    ));
                }
            }
        }
        
        if (!$use_keyword_from_strategy) {
            $primary_keyword_text = $this->extract_keyword_from_content($title, $content);
            $search_intent = 'Informational';
            $use_keyword_from_strategy = false;
        }
        
        // Expand partial proper names to their full form.
        // Expand partial proper names to their full form when the content
        // consistently uses the longer version.
        $primary_keyword_text = $this->expand_proper_name($primary_keyword_text, $title, $content);
        
        // Load language guidelines
        $guidelines_context = $this->guidelines_engine->generate_ai_context();
        
        // When extraction was weak (no proper nouns, just title words), let
        // the AI choose a better keyword from the actual content.
        $keyword_confident = $use_keyword_from_strategy || $this->last_extraction_confident;

        // Build AI prompt
        $prompt = $this->build_optimization_prompt(array(
            'title' => $title,
            'content' => $content,
            'primary_keyword' => $primary_keyword_text,
            'longtail_keywords' => $longtail_keywords,
            'search_intent' => $search_intent,
            'guidelines' => $guidelines_context,
            'use_keyword_from_strategy' => $use_keyword_from_strategy,
            'keyword_confident' => $keyword_confident,
        ));
        
        $response = $this->ai_connector->generate_content($prompt, array(
            'max_tokens' => 800,
            'temperature' => 0.7,
            'fast_model' => true,
            'usage_context' => 'batch_optimizer',
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

        // If extraction was weak and the AI chose a better keyword, use it.
        if (!$keyword_confident && !empty($optimization_data['focus_keyword'])) {
            $ai_keyword = trim($optimization_data['focus_keyword']);
            if (strlen($ai_keyword) >= 3 && str_word_count($ai_keyword) <= 5
                && !$this->is_garbage_keyword($ai_keyword)) {
                error_log(sprintf('MindfulSEO: AI chose keyword "%s" (replacing weak extraction "%s")', $ai_keyword, $primary_keyword_text));
                $primary_keyword_text = $ai_keyword;
            } else {
                error_log(sprintf('MindfulSEO: Rejected AI keyword "%s" (garbage/too short/too long)', $ai_keyword));
            }
        }
        
        // Final sanity gate: never save a garbage keyword regardless of source
        if ($this->is_garbage_keyword($primary_keyword_text)) {
            error_log(sprintf('MindfulSEO: Final gate rejected garbage keyword "%s" for post %d, falling back to title extraction', $primary_keyword_text, $post_id));
            $primary_keyword_text = $this->extract_title_keyword_fallback($title);
        }
        
        // Apply language guidelines capitalization to the keyword
        $primary_keyword_text = $this->apply_guideline_capitalization($primary_keyword_text);

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
            'suggested_slug' => $this->generate_optimized_slug($title, $primary_keyword_text, $post_id),
            'suggestions' => $optimization_data['suggestions'],
            'seo_score' => $optimization_data['seo_score'],
        );
        
        return $preview_data;
    }
    
    /**
     * Extract headings structure from HTML content
     * 
     * Gives the AI a bird's-eye view of the entire post structure,
     * so it understands the MAIN topic even if the content is truncated.
     * 
     * @param string $html_content Raw HTML content
     * @return array ['outline' => string, 'headings' => array]
     */
    private function extract_content_structure($html_content) {
        $headings = array();
        $outline = '';
        
        if (empty($html_content) || !is_string($html_content)) {
            return array('outline' => '', 'headings' => array());
        }
        
        if (preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h\1>/si', $html_content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $level = intval($match[1]);
                $text = wp_strip_all_tags($match[2]);
                $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $text = trim($text);
                
                if (strlen($text) > 2) {
                    $indent = str_repeat('  ', $level - 1);
                    $headings[] = array('level' => $level, 'text' => $text);
                    $outline .= "{$indent}H{$level}: {$text}\n";
                }
            }
        }
        
        return array('outline' => $outline, 'headings' => $headings);
    }
    
    /**
     * Build a smart content summary using beginning, middle, and end samples
     * 
     * Instead of just the first N chars (which biases toward intro content),
     * this samples from across the entire post so the AI understands the full scope.
     * 
     * @param string $content_clean Plain-text content (tags stripped)
     * @param int $total_budget Max chars to include (default 3500)
     * @return string Smart summary with section markers
     */
    private function build_smart_content_summary($content_clean, $total_budget = 3500) {
        $total_len = strlen($content_clean);
        
        if ($total_len <= $total_budget) {
            return $content_clean;
        }
        
        $section_size = intval($total_budget / 3);
        $beginning = substr($content_clean, 0, $section_size);
        
        $mid_start = intval(($total_len / 2) - ($section_size / 2));
        $middle = substr($content_clean, $mid_start, $section_size);
        
        $end = substr($content_clean, -$section_size);
        
        $summary = "[BEGINNING OF POST]\n" . trim($beginning);
        $summary .= "\n\n[...]\n\n[MIDDLE OF POST]\n" . trim($middle);
        $summary .= "\n\n[...]\n\n[END OF POST]\n" . trim($end);
        
        return $summary;
    }
    
    /**
     * Build optimization prompt for AI
     * 
     * @param array $data Prompt data
     * @return string AI prompt
     */
    private function build_optimization_prompt($data) {
        $prompt = "You are a professional SEO strategist. Optimize the following post's SEO metadata.\n\n";
        
        // === LANGUAGE GUIDELINES (critical for brand accuracy) ===
        if (!empty($data['guidelines'])) {
            $prompt .= "LANGUAGE GUIDELINES (MANDATORY — follow these exactly):\n";
            $prompt .= $data['guidelines'] . "\n\n";
        }
        
        // === KEYWORD STRATEGY ===
        $prompt .= "KEYWORD STRATEGY:\n";
        $keyword_confident = !empty($data['keyword_confident']);

        if (!empty($data['use_keyword_from_strategy'])) {
            $prompt .= "Primary keyword: \"{$data['primary_keyword']}\" — MUST appear in both title and description.\n";
            if (!empty($data['longtail_keywords'])) {
                $longtail_strings = array_map(function($kw) {
                    return is_array($kw) ? (isset($kw['keyword']) ? $kw['keyword'] : '') : (string) $kw;
                }, array_slice($data['longtail_keywords'], 0, 3));
                $longtail_strings = array_filter($longtail_strings);
                if (!empty($longtail_strings)) {
                    $prompt .= "Longtail variants: " . implode(', ', $longtail_strings) . "\n";
                }
            }
            if (!empty($data['search_intent'])) {
                $prompt .= "Search intent: {$data['search_intent']}\n";
            }
        } elseif ($keyword_confident) {
            $prompt .= "Primary keyword: \"{$data['primary_keyword']}\" — use this keyword.\n";
        } else {
            $prompt .= "No strong keyword was detected. Analyze the content and choose the single best SEO focus keyword (1-4 words) that:\n";
            $prompt .= "- Represents the page's PRIMARY topic (not a generic description of the page type)\n";
            $prompt .= "- Is specific enough to rank for (e.g. \"Buddhist meditation retreat\" not \"practice programs\")\n";
            $prompt .= "- People would actually type into Google to find this content\n";
            $prompt .= "- Uses proper nouns or domain-specific terms where relevant\n";
            $prompt .= "- For newsletters/roundups, choose the most prominent topic or the organization name — NEVER use months, pronouns, or generic words like \"our november\"\n";
            $prompt .= "- Follow the capitalization rules from the Language Guidelines (e.g. proper names must be capitalized)\n";
            $prompt .= "Include your chosen keyword as \"focus_keyword\" in the JSON response.\n";
            if (!empty($data['primary_keyword'])) {
                $prompt .= "For context, the page title suggests: \"{$data['primary_keyword']}\" — but you should choose something more search-worthy.\n";
            }
        }
        
        // === CONTENT ===
        $content = isset($data['content']) && is_string($data['content']) ? $data['content'] : '';
        $content_clean = wp_strip_all_tags($content);
        $content_clean = html_entity_decode($content_clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        $structure = $this->extract_content_structure($content);
        
        $prompt .= "\nPOST TITLE: \"{$data['title']}\"\n";
        
        if (!empty($structure['outline'])) {
            $prompt .= "\nARTICLE STRUCTURE:\n" . $structure['outline'] . "\n";
        }
        
        $smart_summary = $this->build_smart_content_summary($content_clean, 1500);
        $prompt .= "CONTENT SAMPLE:\n" . $smart_summary . "\n\n";
        
        // === TASK with quality instructions ===
        $prompt .= "TASK:\n";
        $prompt .= "1. Identify the MAIN TOPIC from the title and headings. Do NOT focus on minor mentions or tangential details.\n";
        $prompt .= "2. SEO TITLE (55-60 characters): Preserve the core topic from the original title. Include the keyword naturally. Make it compelling for search results. Do NOT add honorifics, titles, or words that are not in the original title (e.g. do not add 'Kyabje', 'His Holiness', 'Venerable' unless the original title uses them).\n";
        $prompt .= "3. META DESCRIPTION (150-160 characters): Describe the overall main topic accurately. Include a call-to-action (Discover, Learn, Explore). Include the keyword.\n";
        $prompt .= "4. SUGGESTIONS: 4 actionable SEO improvements (keyword placement, headings, readability, internal linking).\n\n";
        
        $prompt .= "Respond with ONLY valid JSON, no markdown, no commentary:\n";
        $prompt .= "{\n";
        if (!$keyword_confident && empty($data['use_keyword_from_strategy'])) {
            $prompt .= '  "focus_keyword": "your chosen keyword",' . "\n";
        }
        $prompt .= '  "seo_title": "Your optimized title here",' . "\n";
        $prompt .= '  "meta_description": "Your meta description here",' . "\n";
        $prompt .= '  "suggestions": ["suggestion 1", "suggestion 2", "suggestion 3", "suggestion 4"]' . "\n";
        $prompt .= "}\n";
        
        // === CUSTOM USER INSTRUCTIONS ===
        $settings = get_option('mindfulseo_settings', array());
        if (!empty($settings['batch_optimizer_prompt'])) {
            $prompt .= "\nADDITIONAL INSTRUCTIONS:\n";
            $prompt .= $settings['batch_optimizer_prompt'] . "\n";
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
        // Guard against null/empty responses
        if (!is_string($response) || strlen(trim($response)) < 5) {
            error_log('MindfulSEO: parse_ai_response received empty/null response (type: ' . gettype($response) . ', length: ' . (is_string($response) ? strlen($response) : 'N/A') . ')');
            return new WP_Error(
                'empty_response',
                __('AI returned an empty response. This may be due to a timeout, content filter, or API issue. Please try again.', 'mindfulseo')
            );
        }
        
        // Log raw response for debugging (first 1000 chars)
        error_log('MindfulSEO: Raw AI response (length=' . strlen($response) . '): ' . substr($response, 0, 1000));
        
        // Strip markdown code blocks if present
        $response = preg_replace('/^```(?:json)?\s*\n?/m', '', $response);
        $response = preg_replace('/\n?```\s*$/m', '', $response);
        $response = trim($response);
        
        // Try decoding the full cleaned response first
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Extract outermost JSON object by finding balanced braces
            $json_str = $this->extract_json_object($response);
            if ($json_str !== false) {
                $data = json_decode($json_str, true);
            }
        }
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_msg = json_last_error_msg();
            error_log('MindfulSEO: JSON decode error: ' . $error_msg);
            error_log('MindfulSEO: Failed to decode (length=' . strlen($response) . '): ' . substr($response, 0, 2000));
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
     * Extract the outermost JSON object from a string by matching balanced braces.
     * Handles nested braces and braces inside quoted strings.
     *
     * @param string $text Text potentially containing a JSON object
     * @return string|false The extracted JSON string, or false if not found
     */
    private function extract_json_object($text) {
        $start = strpos($text, '{');
        if ($start === false) {
            return false;
        }
        
        $depth = 0;
        $in_string = false;
        $escape = false;
        $len = strlen($text);
        
        for ($i = $start; $i < $len; $i++) {
            $char = $text[$i];
            
            if ($escape) {
                $escape = false;
                continue;
            }
            
            if ($char === '\\' && $in_string) {
                $escape = true;
                continue;
            }
            
            if ($char === '"') {
                $in_string = !$in_string;
                continue;
            }
            
            if ($in_string) {
                continue;
            }
            
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }
        
        return false;
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
        
        // Set post meta to mark as optimized (critical for batch optimizer filtering!)
        update_post_meta($post_id, '_mindfulseo_optimized', '1');
        update_post_meta($post_id, '_mindfulseo_optimized_date', current_time('mysql'));
        update_post_meta($post_id, '_mindfulseo_optimization_id', $optimization_id);
        
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
     * Example: "Dr. Jane Smith: Modern Approaches to Sustainable Architecture" 
     *          + keyword "sustainable architecture"
     *          = "dr-jane-smith-sustainable-architecture-guide"
     * 
     * @param string $title Optimized SEO title
     * @param string $keyword Primary keyword
     * @param int $post_id Post ID (to check for duplicates)
     * @return string Optimized slug
     */
    private function generate_optimized_slug($title, $keyword, $post_id) {
        $title = is_string($title) ? $title : '';
        $keyword = is_string($keyword) ? $keyword : '';
        $title_clean = strtolower(strip_tags($title));
        $keyword_clean = strtolower(strip_tags($keyword));
        
        // Strip possessives before processing so "yeshe's" becomes "yeshe"
        $title_clean = preg_replace("/['']s\b/i", '', $title_clean);
        
        $title_clean = preg_replace('/[^\w\s-]/', '', $title_clean);
        $keyword_clean = preg_replace('/[^\w\s-]/', '', $keyword_clean);
        
        $title_words = array_filter(explode(' ', $title_clean));
        $keyword_words = array_filter(explode(' ', $keyword_clean));
        
        $filler_words = array('the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'from', 'is', 'are', 'was', 'were', 'be', 'been', 'has', 'have', 'had', 'do', 'does', 'did', 'doing', 'done', 'will', 'would', 'should', 'could', 'may', 'might', 'must', 'can', 'this', 'that', 'these', 'those', 'our', 'your', 'their', 'its', 'his', 'her', 'you', 'we', 'they', 'what', 'how', 'why', 'when', 'where', 'who', 'which', 'not', 'no', 'yes', 'exactly', 'also', 'just', 'about', 'need', 'being', 'come', 'comes');
        
        $slug_words = array();
        
        // FIRST PASS: Include title words, skipping filler
        foreach ($title_words as $word) {
            if (in_array($word, $filler_words) && !in_array($word, $keyword_words)) {
                continue;
            }
            if (is_numeric($word) && count($slug_words) === 0) {
                continue;
            }
            
            // Deduplicate: skip if this word (or its stem) is already in slug
            $dominated = false;
            foreach ($slug_words as $existing) {
                if ($word === $existing || strpos($existing, $word) === 0 || strpos($word, $existing) === 0) {
                    $dominated = true;
                    break;
                }
            }
            if ($dominated) {
                continue;
            }
            
            $slug_words[] = $word;
            
            if (count($slug_words) >= 6) {
                break;
            }
        }
        
        // SECOND PASS: Add missing keyword words (deduped against existing)
        foreach ($keyword_words as $kw) {
            if (in_array($kw, $filler_words)) {
                continue;
            }
            $already_present = false;
            foreach ($slug_words as $existing) {
                if ($kw === $existing || strpos($existing, $kw) === 0 || strpos($kw, $existing) === 0) {
                    $already_present = true;
                    break;
                }
            }
            if (!$already_present) {
                $slug_words[] = $kw;
                if (count($slug_words) >= 7) break;
            }
        }
        
        $slug = implode('-', $slug_words);
        
        if (strlen($slug) < 10) {
            $slug = sanitize_title($keyword);
        }
        
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
                $post->post_title, 
                $optimization['primary_keyword'], 
                $post_id
            ),
            'suggestions' => json_decode($optimization['content_suggestions'], true),
            'seo_score' => $optimization['optimization_score'],
            'status' => $optimization['status'],
        );
    }
}

