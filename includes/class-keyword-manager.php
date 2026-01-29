<?php
/**
 * Keyword Manager Class
 *
 * Manages keyword strategy data and matching logic
 *
 * @package MindfulSEO
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_Keyword_Manager {
    
    /**
     * The single instance of the class
     * 
     * @var MFSEO_Keyword_Manager
     */
    private static $instance = null;
    
    /**
     * Database table name
     */
    private $table_name;
    
    /**
     * CSV Importer instance
     */
    private $csv_importer;
    
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
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'mindfulseo_keywords';
        
        if (class_exists('MFSEO_CSV_Importer')) {
            $this->csv_importer = new MFSEO_CSV_Importer();
        }
    }
    
    /**
     * Import CSV file
     *
     * @param array $file $_FILES array element
     * @return array|WP_Error Import result or error
     */
    public function import_csv($file) {
        if (!$this->csv_importer) {
            return new WP_Error(
                'importer_not_available',
                __('CSV Importer is not available.', 'mindfulseo')
            );
        }
        
        // Upload file
        $upload_result = $this->csv_importer->upload_csv($file);
        if (is_wp_error($upload_result)) {
            return $upload_result;
        }
        
        // Parse CSV
        $parsed_data = $this->csv_importer->parse_csv($upload_result['filepath']);
        if (is_wp_error($parsed_data)) {
            // Clean up uploaded file
            $this->csv_importer->delete_file($upload_result['filename']);
            return $parsed_data;
        }
        
        // Import to database
        $import_result = $this->csv_importer->import_to_database(
            $parsed_data,
            $file['name']
        );
        
        if (is_wp_error($import_result)) {
            return $import_result;
        }
        
        // Add upload info to result
        $import_result['uploaded_file'] = $upload_result['filename'];
        
        return $import_result;
    }
    
    /**
     * Add a single keyword
     *
     * @param array $data Keyword data
     * @return int|WP_Error Keyword ID or error
     */
    public function add_keyword($data) {
        global $wpdb;
        
        // Validate required fields
        if (empty($data['primary_keyword']) || empty($data['longtail_keyword'])) {
            return new WP_Error('invalid_keyword', __('Primary keyword and longtail keyword are required.', 'mindfulseo'));
        }
        
        // Check for duplicates
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE primary_keyword = %s AND longtail_keyword = %s",
            $data['primary_keyword'],
            $data['longtail_keyword']
        ));
        
        if ($existing) {
            return new WP_Error('duplicate_keyword', __('This keyword combination already exists.', 'mindfulseo'));
        }
        
        // Insert keyword
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'primary_keyword' => sanitize_text_field($data['primary_keyword']),
                'longtail_keyword' => sanitize_text_field($data['longtail_keyword']),
                'search_intent' => isset($data['search_intent']) ? sanitize_text_field($data['search_intent']) : 'Informational',
                'priority' => isset($data['priority']) ? strtoupper(sanitize_text_field($data['priority'])) : 'MEDIUM',
                'current_sessions' => isset($data['current_sessions']) ? intval($data['current_sessions']) : 0,
                'notes' => isset($data['notes']) ? sanitize_textarea_field($data['notes']) : '',
                'csv_source' => isset($data['csv_source']) ? sanitize_text_field($data['csv_source']) : 'Manual',
                'created_date' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            return new WP_Error('insert_failed', __('Failed to add keyword.', 'mindfulseo'));
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Get all keywords
     *
     * @param array $args Query arguments
     * @return array
     */
    public function get_keywords($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'primary_keyword' => '',
            'search_intent' => '',
            'priority' => '',
            'limit' => 100,
            'offset' => 0,
            'orderby' => 'priority',
            'order' => 'ASC'
        );
        
        $args = wp_parse_args($args, $defaults);
        
        // Build query
        $query = "SELECT * FROM {$this->table_name} WHERE 1=1";
        $query_params = array();
        
        if (!empty($args['primary_keyword'])) {
            $query .= " AND primary_keyword LIKE %s";
            $query_params[] = '%' . $wpdb->esc_like($args['primary_keyword']) . '%';
        }
        
        if (!empty($args['search_intent'])) {
            $query .= " AND search_intent = %s";
            $query_params[] = $args['search_intent'];
        }
        
        if (!empty($args['priority'])) {
            $query .= " AND priority = %s";
            $query_params[] = strtoupper($args['priority']);
        }
        
        // Order by
        $allowed_orderby = array('priority', 'primary_keyword', 'created_date', 'current_sessions');
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'priority';
        $order = in_array(strtoupper($args['order']), array('ASC', 'DESC')) ? strtoupper($args['order']) : 'ASC';
        
        // Priority custom sort (HIGH, MEDIUM, LOW)
        if ($orderby === 'priority') {
            $query .= " ORDER BY FIELD(priority, 'HIGH', 'MEDIUM', 'LOW') {$order}";
        } else {
            $query .= " ORDER BY {$orderby} {$order}";
        }
        
        // Limit
        $query .= " LIMIT %d OFFSET %d";
        $query_params[] = $args['limit'];
        $query_params[] = $args['offset'];
        
        if (!empty($query_params)) {
            $query = $wpdb->prepare($query, $query_params);
        }
        
        return $wpdb->get_results($query);
    }
    
    /**
     * Get keyword by ID
     *
     * @param int $id Keyword ID
     * @return object|null
     */
    public function get_keyword($id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ));
    }
    
    /**
     * Get keyword by primary keyword
     *
     * @param string $primary_keyword Primary keyword
     * @return array
     */
    public function get_keywords_by_primary($primary_keyword) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE primary_keyword = %s ORDER BY priority ASC",
            $primary_keyword
        ));
    }
    
    /**
     * Get stored metrics for an array of primary keywords
     *
     * @param array $keywords List of primary keyword strings
     * @return array Associative array keyed by lowercase keyword
     */
    public function get_metrics_for_keywords($keywords = array()) {
        global $wpdb;

        if (empty($keywords)) {
            return array();
        }

        // Sanitize and ensure uniqueness
        $prepared_keywords = array();
        foreach ($keywords as $keyword) {
            $keyword = sanitize_text_field($keyword);
            if ($keyword !== '') {
                $prepared_keywords[] = $keyword;
            }
        }

        $prepared_keywords = array_values(array_unique($prepared_keywords));

        if (empty($prepared_keywords)) {
            return array();
        }

        $placeholders = implode(', ', array_fill(0, count($prepared_keywords), '%s'));

        $query = $wpdb->prepare(
            "SELECT primary_keyword, search_volume, keyword_difficulty, cpc, seo_data_updated
             FROM {$this->table_name}
             WHERE primary_keyword IN ($placeholders)",
            $prepared_keywords
        );

        $results = $wpdb->get_results($query);

        if (empty($results)) {
            return array();
        }

        $metrics = array();
        foreach ($results as $row) {
            $key = strtolower(trim($row->primary_keyword));
            if ($key === '') {
                continue;
            }

            $metrics[$key] = array(
                'search_volume'      => $row->search_volume !== null ? intval($row->search_volume) : null,
                'keyword_difficulty' => $row->keyword_difficulty !== null ? intval($row->keyword_difficulty) : null,
                'cpc'                => $row->cpc !== null ? floatval($row->cpc) : null,
                'seo_data_updated'   => isset($row->seo_data_updated) ? $row->seo_data_updated : null,
            );
        }

        return $metrics;
    }

    /**
     * Get stored metrics for a single primary keyword
     *
     * @param string $keyword Primary keyword string
     * @return array|null Metrics or null if not found
     */
    public function get_metrics_for_keyword($keyword) {
        if (empty($keyword)) {
            return null;
        }

        $metrics = $this->get_metrics_for_keywords(array($keyword));
        $keyword_key = strtolower(trim($keyword));

        return isset($metrics[$keyword_key]) ? $metrics[$keyword_key] : null;
    }

    /**
     * Get longtail keywords for a primary keyword
     *
     * @param string $primary_keyword Primary keyword
     * @return array
     */
    public function get_longtail_keywords($primary_keyword) {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT longtail_keyword, search_intent, priority FROM {$this->table_name} 
             WHERE primary_keyword = %s 
             ORDER BY FIELD(priority, 'HIGH', 'MEDIUM', 'LOW') ASC",
            $primary_keyword
        ));
        
        return array_map(function($row) {
            return array(
                'keyword' => $row->longtail_keyword,
                'intent' => $row->search_intent,
                'priority' => $row->priority
            );
        }, $results);
    }
    
    /**
     * Find matching keywords for post content
     *
     * Uses NLP-like analysis to find relevant keywords from the content
     *
     * @param string $post_content Post content
     * @param int $limit Maximum number of keywords to return
     * @return array Matching keywords with relevance scores
     */
    public function find_matching_keywords($post_content, $limit = 10, $post_title = '') {
        global $wpdb;
        
        // Clean and prepare content
        $content = strtolower(strip_tags($post_content));
        $content = preg_replace('/[^a-z0-9\s-]/', ' ', $content);
        $title = strtolower(strip_tags($post_title));
        $title = preg_replace('/[^a-z0-9\s-]/', ' ', $title);
        
        // Get first 500 characters (intro is most important)
        $intro = substr($content, 0, 500);
        
        // Get all keywords from database
        $all_keywords = $wpdb->get_results(
            "SELECT * FROM {$this->table_name}"
        );
        
        // Score each keyword
        $scored_keywords = array();
        
        foreach ($all_keywords as $keyword_row) {
            $score = 0;
            $primary = strtolower($keyword_row->primary_keyword);
            $longtail = strtolower($keyword_row->longtail_keyword);
            
            // Skip empty keywords
            if (empty(trim($primary))) {
                continue;
            }
            
            // 🎯 TITLE MATCH = HIGHEST PRIORITY (Post is ABOUT this keyword)
            // Exact match in title = +50 points
            if (stripos($title, $primary) !== false) {
                $score += 50;
            }
            if (!empty($longtail) && stripos($title, $longtail) !== false) {
                $score += 60;
            }
            
            // 🔍 INTRO MATCH = HIGH PRIORITY (Keyword appears early)
            // Count occurrences in first paragraph
            $intro_count_primary = substr_count($intro, $primary);
            $intro_count_longtail = !empty($longtail) ? substr_count($intro, $longtail) : 0;
            $score += $intro_count_primary * 20;
            $score += $intro_count_longtail * 25;
            
            // 📄 CONTENT FREQUENCY = MEDIUM PRIORITY
            // Count how many times keyword appears in full content
            $content_count_primary = substr_count($content, $primary);
            $content_count_longtail = !empty($longtail) ? substr_count($content, $longtail) : 0;
            $score += $content_count_primary * 5;
            $score += $content_count_longtail * 7;
            
            // ⭐ PRIORITY BOOST
            if ($keyword_row->priority === 'HIGH') {
                $score *= 1.3;
            } elseif ($keyword_row->priority === 'MEDIUM') {
                $score *= 1.1;
            }
            
            if ($score > 0) {
                $scored_keywords[] = array(
                    'keyword' => $keyword_row,
                    'score' => $score,
                    'debug' => array(
                        'primary' => $primary,
                        'title_match' => stripos($title, $primary) !== false,
                        'intro_count' => $intro_count_primary,
                        'content_count' => $content_count_primary,
                    )
                );
            }
        }
        
        // Sort by score (descending)
        usort($scored_keywords, function($a, $b) {
            return $b['score'] - $a['score'];
        });
        
        // Log top 3 matches for debugging
        error_log('MindfulSEO: Top keyword matches:');
        foreach (array_slice($scored_keywords, 0, 3) as $match) {
            error_log(sprintf(
                '  - "%s" (score: %.1f, title_match: %s, intro: %d, content: %d)',
                $match['debug']['primary'],
                $match['score'],
                $match['debug']['title_match'] ? 'YES' : 'no',
                $match['debug']['intro_count'],
                $match['debug']['content_count']
            ));
        }
        
        // Return top matches
        return array_slice($scored_keywords, 0, $limit);
    }
    
    /**
     * Suggest keyword for a specific post
     *
     * @param int $post_id Post ID
     * @return array|null Best matching keyword or null
     */
    public function suggest_keyword_for_post($post_id) {
        $post = get_post($post_id);
        
        if (!$post) {
            return null;
        }
        
        // Combine title and content for matching
        $content = $post->post_title . ' ' . $post->post_content;
        
        $matches = $this->find_matching_keywords($content, 1);
        
        return !empty($matches) ? $matches[0]['keyword'] : null;
    }
    
    /**
     * Update keyword sessions count
     *
     * @param int $keyword_id Keyword ID
     * @param int $sessions New session count
     * @return bool
     */
    public function update_keyword_sessions($keyword_id, $sessions) {
        global $wpdb;
        
        $result = $wpdb->update(
            $this->table_name,
            array('current_sessions' => intval($sessions)),
            array('id' => $keyword_id),
            array('%d'),
            array('%d')
        );
        
        return $result !== false;
    }
    
    /**
     * Update keyword
     *
     * @param int $keyword_id Keyword ID
     * @param array $data Keyword data
     * @return bool
     */
    public function update_keyword($keyword_id, $data) {
        global $wpdb;
        
        $allowed_fields = array(
            'primary_keyword',
            'longtail_keyword',
            'search_intent',
            'priority',
            'current_sessions',
            'notes'
        );
        
        $update_data = array();
        $format = array();
        
        foreach ($data as $field => $value) {
            if (in_array($field, $allowed_fields)) {
                $update_data[$field] = $value;
                $format[] = is_numeric($value) ? '%d' : '%s';
            }
        }
        
        if (empty($update_data)) {
            return false;
        }
        
        $result = $wpdb->update(
            $this->table_name,
            $update_data,
            array('id' => $keyword_id),
            $format,
            array('%d')
        );
        
        return $result !== false;
    }
    
    /**
     * Delete keyword
     *
     * @param int $keyword_id Keyword ID
     * @return bool
     */
    public function delete_keyword($keyword_id) {
        global $wpdb;
        
        $result = $wpdb->delete(
            $this->table_name,
            array('id' => $keyword_id),
            array('%d')
        );
        
        if ($result && class_exists('MFSEO_Logger')) {
            $logger = MFSEO_Logger::get_instance();
            if ($logger) {
                $logger->log_info('Keyword deleted', array('id' => $keyword_id));
            }
        }
        
        return $result !== false;
    }
    
    /**
     * Delete all keywords from a CSV source
     *
     * @param string $csv_source CSV filename
     * @return int Number of deleted keywords
     */
    public function delete_keywords_by_source($csv_source) {
        global $wpdb;
        
        $result = $wpdb->delete(
            $this->table_name,
            array('csv_source' => $csv_source),
            array('%s')
        );
        
        return $result !== false ? $result : 0;
    }
    
    /**
     * Get keyword statistics
     *
     * @return array
     */
    public function get_statistics() {
        global $wpdb;
        
        $stats = array();
        
        // Total keywords
        $stats['total'] = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        
        // By priority
        $stats['by_priority'] = $wpdb->get_results(
            "SELECT priority, COUNT(*) as count 
             FROM {$this->table_name} 
             GROUP BY priority 
             ORDER BY FIELD(priority, 'HIGH', 'MEDIUM', 'LOW')",
            OBJECT_K
        );
        
        // By search intent
        $stats['by_intent'] = $wpdb->get_results(
            "SELECT search_intent, COUNT(*) as count 
             FROM {$this->table_name} 
             GROUP BY search_intent",
            OBJECT_K
        );
        
        // Unique primary keywords
        $stats['unique_primary'] = $wpdb->get_var(
            "SELECT COUNT(DISTINCT primary_keyword) FROM {$this->table_name}"
        );
        
        // Top keywords by sessions
        $stats['top_by_sessions'] = $wpdb->get_results(
            "SELECT primary_keyword, longtail_keyword, current_sessions 
             FROM {$this->table_name} 
             WHERE current_sessions > 0 
             ORDER BY current_sessions DESC 
             LIMIT 10"
        );
        
        return $stats;
    }
    
    /**
     * Get CSV importer instance
     *
     * @return MFSEO_CSV_Importer|null
     */
    public function get_csv_importer() {
        return $this->csv_importer;
    }
}

