<?php
/**
 * DataForSEO API Connector
 * 
 * Handles integration with DataForSEO API to fetch keyword metrics:
 * - Search volume
 * - Keyword difficulty
 * - CPC (Cost Per Click)
 * 
 * @package MindfulSEO
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_DataForSEO_Connector {
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * DataForSEO API endpoint
     */
    private $api_url = 'https://api.dataforseo.com/v3/';
    
    /**
     * API credentials
     */
    private $login;
    private $password;
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     * 
     * @param string $login Optional login (if not using settings)
     * @param string $password Optional password (if not using settings)
     */
    public function __construct($login = null, $password = null) {
        $this->logger = MFSEO_Logger::get_instance();
        
        if ($login && $password) {
            // Use provided credentials (e.g., for testing)
            $this->login = $login;
            $this->password = $password;
        } else {
            // Load from settings
            $this->load_credentials();
        }
    }
    
    /**
     * Load API credentials from settings
     */
    private function load_credentials() {
        $settings = get_option('mindfulseo_settings', array());
        $this->login = isset($settings['dataforseo_login']) ? $settings['dataforseo_login'] : '';
        
        // Decrypt the password if it exists
        if (!empty($settings['dataforseo_password'])) {
            if (class_exists('MFSEO_AI_Connector')) {
                $connector = MFSEO_AI_Connector::get_instance();
                $this->password = $connector->decrypt_api_key($settings['dataforseo_password']);
            } else {
                $this->password = $settings['dataforseo_password'];
            }
        } else {
            $this->password = '';
        }
    }
    
    /**
     * Check if API is configured
     * 
     * @return bool
     */
    public function is_configured() {
        return !empty($this->login) && !empty($this->password);
    }
    
    /**
     * Test API connection
     * 
     * @return array|WP_Error
     */
    public function test_connection() {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('DataForSEO API credentials not configured.', 'mindfulseo'));
        }
        
        // Test with the account info endpoint (free, doesn't consume credits)
        $response = wp_remote_get($this->api_url . 'appendix/user_data', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->login . ':' . $this->password),
            ),
            'timeout' => 15,
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code !== 200) {
            $error_message = 'API connection failed';
            if (isset($body['status_message'])) {
                $error_message = $body['status_message'];
            } elseif (isset($body['tasks']) && !empty($body['tasks']) && isset($body['tasks'][0]['status_message'])) {
                $error_message = $body['tasks'][0]['status_message'];
            }
            return new WP_Error('api_error', $error_message);
        }
        
        // Check if the response has valid structure
        if (isset($body['status_code']) && $body['status_code'] == 20000) {
            return array(
                'success' => true,
                'message' => __('Connection successful!', 'mindfulseo'),
            );
        }
        
        return new WP_Error('api_error', __('Unexpected API response.', 'mindfulseo'));
    }
    
    /**
     * Get keyword metrics for multiple keywords
     * 
     * @param array $keywords Array of keyword strings
     * @param string $location_code Location code (e.g., '2840' for United States, '2826' for UK)
     * @param string $language_code Language code (e.g., 'en' for English)
     * @return array|WP_Error
     */
    public function get_keyword_metrics($keywords, $location_code = '2840', $language_code = 'en') {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('DataForSEO API not configured.', 'mindfulseo'));
        }
        
        if (empty($keywords)) {
            return new WP_Error('no_keywords', __('No keywords provided.', 'mindfulseo'));
        }
        
        // DataForSEO allows max 1000 keywords per request, but we'll batch to 100 for safety
        $keywords = array_slice($keywords, 0, 100);
        
        // Prepare the request
        $post_data = array(
            array(
                'keywords' => $keywords,
                'location_code' => intval($location_code),
                'language_code' => $language_code,
            ),
        );
        
        $response = wp_remote_post($this->api_url . 'keywords_data/google_ads/search_volume/live', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->login . ':' . $this->password),
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($post_data),
            'timeout' => 60,
        ));
        
        if (is_wp_error($response)) {
            $this->logger->log_error('DataForSEO API Error', $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code !== 200) {
            $error_message = isset($body['status_message']) ? $body['status_message'] : __('API request failed.', 'mindfulseo');
            $this->logger->log_error('DataForSEO API Error', $error_message);
            return new WP_Error('api_error', $error_message);
        }
        
        // Parse the response
        return $this->parse_keyword_response($body);
    }
    
    /**
     * Parse keyword response from DataForSEO
     * 
     * @param array $response
     * @return array
     */
    private function parse_keyword_response($response) {
        $results = array();
        
        if (!isset($response['tasks']) || empty($response['tasks'])) {
            return $results;
        }
        
        foreach ($response['tasks'] as $task) {
            if ($task['status_code'] !== 20000) {
                continue;
            }
            
            if (!isset($task['result']) || empty($task['result'])) {
                continue;
            }
            
            foreach ($task['result'] as $result) {
                $keyword = isset($result['keyword']) ? $result['keyword'] : '';
                
                if (empty($keyword)) {
                    continue;
                }
                
                $results[$keyword] = array(
                    'search_volume' => isset($result['search_volume']) && $result['search_volume'] !== null ? intval($result['search_volume']) : null,
                    'competition' => isset($result['competition']) && $result['competition'] !== null ? floatval($result['competition']) : null,
                    'cpc' => isset($result['cpc']) && $result['cpc'] !== null ? floatval($result['cpc']) : null,
                    // DataForSEO doesn't provide keyword difficulty in search_volume endpoint
                    // Would need to use a different endpoint or service for that
                    'keyword_difficulty' => null,
                );
            }
        }
        
        return $results;
    }
    
    /**
     * Get keyword difficulty (requires separate API call)
     * 
     * @param array $keywords
     * @param string $location_code
     * @param string $language_code
     * @return array|WP_Error
     */
    public function get_keyword_difficulty($keywords, $location_code = '2840', $language_code = 'en') {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('DataForSEO API not configured.', 'mindfulseo'));
        }
        
        // Limit to 100 keywords
        $keywords = array_slice($keywords, 0, 100);
        
        $post_data = array(
            array(
                'keywords' => $keywords,
                'location_code' => intval($location_code),
                'language_code' => $language_code,
            ),
        );
        
        // Use the bulk keyword difficulty endpoint
        $response = wp_remote_post($this->api_url . 'dataforseo_labs/google/bulk_keyword_difficulty/live', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->login . ':' . $this->password),
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($post_data),
            'timeout' => 60,
        ));
        
        if (is_wp_error($response)) {
            $this->logger->log_error('DataForSEO Difficulty API Error', $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code !== 200) {
            $error_message = isset($body['status_message']) ? $body['status_message'] : __('API request failed.', 'mindfulseo');
            $this->logger->log_error('DataForSEO Difficulty API Error', $error_message);
            return new WP_Error('api_error', $error_message);
        }
        
        return $this->parse_difficulty_response($body);
    }
    
    /**
     * Parse difficulty response
     * 
     * @param array $response
     * @return array
     */
    private function parse_difficulty_response($response) {
        $results = array();
        
        error_log('MindfulSEO DataForSEO: Parsing difficulty response from bulk endpoint');
        
        if (!isset($response['tasks']) || empty($response['tasks'])) {
            error_log('MindfulSEO DataForSEO: No tasks in difficulty response');
            return $results;
        }
        
        foreach ($response['tasks'] as $task_index => $task) {
            error_log('MindfulSEO DataForSEO: Task ' . $task_index . ' status_code: ' . (isset($task['status_code']) ? $task['status_code'] : 'N/A'));
            
            if ($task['status_code'] !== 20000) {
                error_log('MindfulSEO DataForSEO: Task ' . $task_index . ' status not 20000, skipping');
                continue;
            }
            
            if (!isset($task['result']) || empty($task['result'])) {
                error_log('MindfulSEO DataForSEO: Task ' . $task_index . ' has no result');
                continue;
            }
            
            foreach ($task['result'] as $result_index => $result) {
                if (!isset($result['items']) || empty($result['items'])) {
                    error_log('MindfulSEO DataForSEO: Result ' . $result_index . ' has no items');
                    continue;
                }
                
                error_log('MindfulSEO DataForSEO: Result ' . $result_index . ' has ' . count($result['items']) . ' items');
                
                foreach ($result['items'] as $item_index => $item) {
                    $keyword = isset($item['keyword']) ? $item['keyword'] : '';
                    
                    if (empty($keyword)) {
                        continue;
                    }
                    
                    // Log the item structure for first keyword
                    if ($item_index === 0) {
                        error_log('MindfulSEO DataForSEO: Sample item structure: ' . print_r($item, true));
                    }
                    
                    // For bulk keyword difficulty endpoint, the difficulty is directly in the item
                    $difficulty = isset($item['keyword_difficulty']) ? intval($item['keyword_difficulty']) : null;
                    
                    error_log('MindfulSEO DataForSEO: Keyword "' . $keyword . '" difficulty: ' . ($difficulty !== null ? $difficulty : 'NULL'));
                    
                    $results[$keyword] = array(
                        'keyword_difficulty' => $difficulty,
                    );
                }
            }
        }
        
        error_log('MindfulSEO DataForSEO: Parsed ' . count($results) . ' difficulty results');
        
        return $results;
    }
    
    /**
     * Get combined metrics (volume + difficulty) for keywords
     * Makes two API calls but returns combined results
     * 
     * @param array $keywords
     * @param string $location_code
     * @param string $language_code
     * @return array|WP_Error
     */
    public function get_combined_metrics($keywords, $location_code = '2840', $language_code = 'en') {
        // Get search volume and CPC
        $volume_data = $this->get_keyword_metrics($keywords, $location_code, $language_code);
        
        if (is_wp_error($volume_data)) {
            return $volume_data;
        }
        
        // Get keyword difficulty
        $difficulty_data = $this->get_keyword_difficulty($keywords, $location_code, $language_code);
        
        if (is_wp_error($difficulty_data)) {
            return $difficulty_data;
        }
        
        // Merge the results
        $combined = array();
        
        foreach ($keywords as $keyword) {
            $combined[$keyword] = array(
                // Only set if we have actual data from API (don't default to 0)
                'search_volume' => isset($volume_data[$keyword]['search_volume']) ? $volume_data[$keyword]['search_volume'] : null,
                'cpc' => isset($volume_data[$keyword]['cpc']) ? $volume_data[$keyword]['cpc'] : null,
                'keyword_difficulty' => isset($difficulty_data[$keyword]['keyword_difficulty']) ? $difficulty_data[$keyword]['keyword_difficulty'] : null,
            );
        }
        
        return $combined;
    }
    
    /**
     * Get keywords that a domain ranks for (Domain Analysis)
     * 
     * @param string $domain Domain to analyze (e.g., "jamyang-london.org")
     * @param array $filters Optional filters for results
     * @param int $limit Maximum number of keywords to return (default 100)
     * @param string $location_code Location code (default USA)
     * @param string $language_code Language code (default en)
     * @return array|WP_Error Array of keywords with rankings or WP_Error on failure
     */
    public function get_keywords_for_site($domain, $filters = array(), $limit = 100, $location_code = '2840', $language_code = 'en') {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('DataForSEO API not configured.', 'mindfulseo'));
        }
        
        // Build request data
        $post_data = array(
            array(
                'target' => $domain,
                'location_code' => intval($location_code),
                'language_code' => $language_code,
                'limit' => $limit,
            ),
        );
        
        // Add filters if provided
        if (!empty($filters)) {
            $post_data[0]['filters'] = $filters;
        }
        
        error_log('MindfulSEO DataForSEO: Fetching keywords for site: ' . $domain);
        
        // Call DataForSEO API
        $response = wp_remote_post($this->api_url . 'dataforseo_labs/google/keywords_for_site/live', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->login . ':' . $this->password),
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($post_data),
            'timeout' => 60,
        ));
        
        if (is_wp_error($response)) {
            $this->logger->log_error('DataForSEO keywords_for_site Error', $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code !== 200) {
            $error_message = isset($body['status_message']) ? $body['status_message'] : __('API request failed.', 'mindfulseo');
            $this->logger->log_error('DataForSEO keywords_for_site Error', $error_message);
            return new WP_Error('api_error', $error_message);
        }
        
        // Parse response
        return $this->parse_keywords_for_site_response($body);
    }
    
    /**
     * Parse keywords_for_site response
     */
    private function parse_keywords_for_site_response($response) {
        $results = array();
        
        if (!isset($response['tasks']) || empty($response['tasks'])) {
            return $results;
        }
        
        foreach ($response['tasks'] as $task) {
            if ($task['status_code'] !== 20000) {
                continue;
            }
            
            if (!isset($task['result']) || empty($task['result'])) {
                continue;
            }
            
            foreach ($task['result'] as $result) {
                if (!isset($result['items']) || empty($result['items'])) {
                    continue;
                }
                
                foreach ($result['items'] as $item) {
                    $keyword = isset($item['keyword_data']['keyword']) ? $item['keyword_data']['keyword'] : '';
                    
                    if (empty($keyword)) {
                        continue;
                    }
                    
                    $results[] = array(
                        'keyword' => $keyword,
                        'position' => isset($item['ranked_serp_element']['serp_item']['rank_absolute']) ? intval($item['ranked_serp_element']['serp_item']['rank_absolute']) : null,
                        'search_volume' => isset($item['keyword_data']['keyword_info']['search_volume']) ? intval($item['keyword_data']['keyword_info']['search_volume']) : null,
                        'keyword_difficulty' => isset($item['keyword_data']['keyword_properties']['keyword_difficulty']) ? intval($item['keyword_data']['keyword_properties']['keyword_difficulty']) : null,
                        'cpc' => isset($item['keyword_data']['keyword_info']['cpc']) ? floatval($item['keyword_data']['keyword_info']['cpc']) : null,
                        'url' => isset($item['ranked_serp_element']['serp_item']['url']) ? $item['ranked_serp_element']['serp_item']['url'] : null,
                    );
                }
            }
        }
        
        error_log('MindfulSEO DataForSEO: Found ' . count($results) . ' ranking keywords');
        
        return $results;
    }
    
    /**
     * Get related keywords for a seed keyword
     * 
     * @param string $seed_keyword Seed keyword to expand
     * @param int $limit Maximum results (default 50)
     * @param string $location_code Location code (default USA)
     * @param string $language_code Language code (default en)
     * @return array|WP_Error Array of related keywords or WP_Error
     */
    public function get_related_keywords($seed_keyword, $limit = 50, $location_code = '2840', $language_code = 'en') {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('DataForSEO API not configured.', 'mindfulseo'));
        }
        
        $post_data = array(
            array(
                'keyword' => $seed_keyword,
                'location_code' => intval($location_code),
                'language_code' => $language_code,
                'limit' => $limit,
            ),
        );
        
        error_log('MindfulSEO DataForSEO: Fetching related keywords for: ' . $seed_keyword);
        
        $response = wp_remote_post($this->api_url . 'dataforseo_labs/google/related_keywords/live', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->login . ':' . $this->password),
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($post_data),
            'timeout' => 60,
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code !== 200) {
            $error_message = isset($body['status_message']) ? $body['status_message'] : __('API request failed.', 'mindfulseo');
            return new WP_Error('api_error', $error_message);
        }
        
        return $this->parse_keyword_expansion_response($body);
    }
    
    /**
     * Get keyword suggestions (autocomplete data)
     * 
     * @param string $seed_keyword Seed keyword
     * @param int $limit Maximum results (default 50)
     * @param string $location_code Location code (default USA)
     * @param string $language_code Language code (default en)
     * @return array|WP_Error Array of suggested keywords or WP_Error
     */
    public function get_keyword_suggestions($seed_keyword, $limit = 50, $location_code = '2840', $language_code = 'en') {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('DataForSEO API not configured.', 'mindfulseo'));
        }
        
        $post_data = array(
            array(
                'keyword' => $seed_keyword,
                'location_code' => intval($location_code),
                'language_code' => $language_code,
                'limit' => $limit,
            ),
        );
        
        error_log('MindfulSEO DataForSEO: Fetching keyword suggestions for: ' . $seed_keyword);
        
        $response = wp_remote_post($this->api_url . 'dataforseo_labs/google/keyword_suggestions/live', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->login . ':' . $this->password),
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($post_data),
            'timeout' => 60,
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code !== 200) {
            $error_message = isset($body['status_message']) ? $body['status_message'] : __('API request failed.', 'mindfulseo');
            return new WP_Error('api_error', $error_message);
        }
        
        return $this->parse_keyword_expansion_response($body);
    }
    
    /**
     * Get keyword ideas (questions, related searches)
     * 
     * @param string $seed_keyword Seed keyword
     * @param int $limit Maximum results (default 50)
     * @param string $location_code Location code (default USA)
     * @param string $language_code Language code (default en)
     * @return array|WP_Error Array of keyword ideas or WP_Error
     */
    public function get_keyword_ideas($seed_keyword, $limit = 50, $location_code = '2840', $language_code = 'en') {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('DataForSEO API not configured.', 'mindfulseo'));
        }
        
        $post_data = array(
            array(
                'keyword' => $seed_keyword,
                'location_code' => intval($location_code),
                'language_code' => $language_code,
                'limit' => $limit,
                'include_serp_info' => false,
            ),
        );
        
        error_log('MindfulSEO DataForSEO: Fetching keyword ideas for: ' . $seed_keyword);
        
        $response = wp_remote_post($this->api_url . 'dataforseo_labs/google/keyword_ideas/live', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->login . ':' . $this->password),
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($post_data),
            'timeout' => 60,
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code !== 200) {
            $error_message = isset($body['status_message']) ? $body['status_message'] : __('API request failed.', 'mindfulseo');
            return new WP_Error('api_error', $error_message);
        }
        
        return $this->parse_keyword_expansion_response($body);
    }
    
    /**
     * Parse keyword expansion responses (related/suggestions/ideas)
     */
    private function parse_keyword_expansion_response($response) {
        $results = array();
        
        if (!isset($response['tasks']) || empty($response['tasks'])) {
            return $results;
        }
        
        foreach ($response['tasks'] as $task) {
            if ($task['status_code'] !== 20000) {
                continue;
            }
            
            if (!isset($task['result']) || empty($task['result'])) {
                continue;
            }
            
            foreach ($task['result'] as $result) {
                if (!isset($result['items']) || empty($result['items'])) {
                    continue;
                }
                
                foreach ($result['items'] as $item) {
                    $keyword = isset($item['keyword_data']['keyword']) ? $item['keyword_data']['keyword'] : '';
                    
                    if (empty($keyword)) {
                        continue;
                    }
                    
                    $results[] = array(
                        'keyword' => $keyword,
                        'search_volume' => isset($item['keyword_data']['keyword_info']['search_volume']) ? intval($item['keyword_data']['keyword_info']['search_volume']) : null,
                        'keyword_difficulty' => isset($item['keyword_data']['keyword_properties']['keyword_difficulty']) ? intval($item['keyword_data']['keyword_properties']['keyword_difficulty']) : null,
                        'cpc' => isset($item['keyword_data']['keyword_info']['cpc']) ? floatval($item['keyword_data']['keyword_info']['cpc']) : null,
                        'competition' => isset($item['keyword_data']['keyword_info']['competition']) ? floatval($item['keyword_data']['keyword_info']['competition']) : null,
                    );
                }
            }
        }
        
        error_log('MindfulSEO DataForSEO: Found ' . count($results) . ' expanded keywords');
        
        return $results;
    }
    
    /**
     * Get Lighthouse audit data for a URL
     * 
     * Returns PageSpeed Insights data including:
     * - Performance score
     * - Accessibility score
     * - Best Practices score
     * - SEO score
     * - Core Web Vitals (LCP, FID, CLS)
     * - Detailed audit results
     * 
     * @param string $url URL to audit (must be publicly accessible)
     * @param string $device Device type: 'desktop' or 'mobile' (default 'mobile')
     * @param array $audits Optional specific audits to run
     * @return array|WP_Error Array with Lighthouse data or WP_Error on failure
     */
    public function get_lighthouse_audit($url, $device = 'mobile', $audits = array()) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('DataForSEO API not configured.', 'mindfulseo'));
        }
        
        // Validate URL
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', __('Invalid URL provided.', 'mindfulseo'));
        }
        
        // Build request data
        $post_data = array(
            array(
                'url' => $url,
                'desktop' => ($device === 'desktop'),
                'enable_javascript' => true,
            ),
        );
        
        // Add specific audits if provided
        if (!empty($audits)) {
            $post_data[0]['audits'] = $audits;
        }
        
        error_log('MindfulSEO DataForSEO: Running Lighthouse audit for: ' . $url . ' (' . $device . ')');
        
        // Call DataForSEO On-Page API - Lighthouse endpoint
        $response = wp_remote_post($this->api_url . 'on_page/lighthouse/live', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->login . ':' . $this->password),
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($post_data),
            'timeout' => 120, // Lighthouse can take a while
        ));
        
        if (is_wp_error($response)) {
            $this->logger->log_error('DataForSEO Lighthouse Error', $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code !== 200) {
            $error_message = isset($body['status_message']) ? $body['status_message'] : __('API request failed.', 'mindfulseo');
            $this->logger->log_error('DataForSEO Lighthouse Error', $error_message);
            return new WP_Error('api_error', $error_message);
        }
        
        // Parse response
        return $this->parse_lighthouse_response($body);
    }
    
    /**
     * Parse Lighthouse response
     * 
     * @param array $response Raw API response
     * @return array Structured Lighthouse data
     */
    private function parse_lighthouse_response($response) {
        $results = array(
            'success' => false,
            'scores' => array(),
            'audits' => array(),
            'metrics' => array(),
            'opportunities' => array(),
        );
        
        if (!isset($response['tasks']) || empty($response['tasks'])) {
            error_log('MindfulSEO DataForSEO: No tasks in Lighthouse response');
            return $results;
        }
        
        foreach ($response['tasks'] as $task) {
            if ($task['status_code'] !== 20000) {
                error_log('MindfulSEO DataForSEO: Task status not 20000: ' . $task['status_code']);
                continue;
            }
            
            if (!isset($task['result']) || empty($task['result'])) {
                error_log('MindfulSEO DataForSEO: Task has no result');
                continue;
            }
            
            foreach ($task['result'] as $result) {
                // Extract Lighthouse categories (scores)
                if (isset($result['categories'])) {
                    $results['scores'] = array(
                        'performance' => isset($result['categories']['performance']['score']) ? 
                            round($result['categories']['performance']['score'] * 100) : null,
                        'accessibility' => isset($result['categories']['accessibility']['score']) ? 
                            round($result['categories']['accessibility']['score'] * 100) : null,
                        'best_practices' => isset($result['categories']['best-practices']['score']) ? 
                            round($result['categories']['best-practices']['score'] * 100) : null,
                        'seo' => isset($result['categories']['seo']['score']) ? 
                            round($result['categories']['seo']['score'] * 100) : null,
                    );
                }
                
                // Extract Core Web Vitals metrics
                if (isset($result['audits'])) {
                    $audits = $result['audits'];
                    
                    $results['metrics'] = array(
                        'first_contentful_paint' => isset($audits['first-contentful-paint']['displayValue']) ? 
                            $audits['first-contentful-paint']['displayValue'] : null,
                        'largest_contentful_paint' => isset($audits['largest-contentful-paint']['displayValue']) ? 
                            $audits['largest-contentful-paint']['displayValue'] : null,
                        'cumulative_layout_shift' => isset($audits['cumulative-layout-shift']['displayValue']) ? 
                            $audits['cumulative-layout-shift']['displayValue'] : null,
                        'total_blocking_time' => isset($audits['total-blocking-time']['displayValue']) ? 
                            $audits['total-blocking-time']['displayValue'] : null,
                        'speed_index' => isset($audits['speed-index']['displayValue']) ? 
                            $audits['speed-index']['displayValue'] : null,
                    );
                    
                    // Extract failed audits (opportunities for improvement)
                    foreach ($audits as $audit_key => $audit_data) {
                        if (isset($audit_data['score']) && $audit_data['score'] < 0.9) {
                            // This audit failed or needs improvement
                            $results['audits'][] = array(
                                'id' => $audit_key,
                                'title' => isset($audit_data['title']) ? $audit_data['title'] : '',
                                'description' => isset($audit_data['description']) ? $audit_data['description'] : '',
                                'score' => $audit_data['score'],
                                'display_value' => isset($audit_data['displayValue']) ? $audit_data['displayValue'] : '',
                            );
                        }
                    }
                }
                
                $results['success'] = true;
                error_log('MindfulSEO DataForSEO: Lighthouse audit successful. Scores: ' . json_encode($results['scores']));
                
                // Only process first result
                break 2;
            }
        }
        
        return $results;
    }
    
    /**
     * Get Instant Page analysis (quick on-page SEO check)
     * 
     * Returns immediate feedback about:
     * - Missing meta tags
     * - Image alt attributes
     * - Page title and description
     * - H1 tags
     * - Content length
     * - Internal/external links
     * 
     * @param string $url URL to analyze
     * @return array|WP_Error Array with page data or WP_Error on failure
     */
    public function get_instant_page_analysis($url) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('DataForSEO API not configured.', 'mindfulseo'));
        }
        
        // Validate URL
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', __('Invalid URL provided.', 'mindfulseo'));
        }
        
        $post_data = array(
            array(
                'url' => $url,
                'enable_javascript' => true,
                'custom_js' => '', // Can inject custom JS if needed
            ),
        );
        
        error_log('MindfulSEO DataForSEO: Running Instant Page analysis for: ' . $url);
        
        // Call DataForSEO On-Page API - Instant Pages endpoint
        $response = wp_remote_post($this->api_url . 'on_page/instant_pages', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->login . ':' . $this->password),
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($post_data),
            'timeout' => 60,
        ));
        
        if (is_wp_error($response)) {
            $this->logger->log_error('DataForSEO Instant Pages Error', $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code !== 200) {
            $error_message = isset($body['status_message']) ? $body['status_message'] : __('API request failed.', 'mindfulseo');
            $this->logger->log_error('DataForSEO Instant Pages Error', $error_message);
            return new WP_Error('api_error', $error_message);
        }
        
        // Parse response
        return $this->parse_instant_page_response($body);
    }
    
    /**
     * Parse Instant Page response
     * 
     * @param array $response Raw API response
     * @return array Structured page data
     */
    private function parse_instant_page_response($response) {
        $results = array(
            'success' => false,
            'meta' => array(),
            'content' => array(),
            'links' => array(),
            'images' => array(),
            'issues' => array(),
        );
        
        if (!isset($response['tasks']) || empty($response['tasks'])) {
            return $results;
        }
        
        foreach ($response['tasks'] as $task) {
            if ($task['status_code'] !== 20000) {
                continue;
            }
            
            if (!isset($task['result']) || empty($task['result'])) {
                continue;
            }
            
            foreach ($task['result'] as $result) {
                // Extract meta information
                if (isset($result['meta'])) {
                    $meta = $result['meta'];
                    $results['meta'] = array(
                        'title' => isset($meta['title']) ? $meta['title'] : '',
                        'description' => isset($meta['description']) ? $meta['description'] : '',
                        'charset' => isset($meta['charset']) ? $meta['charset'] : '',
                        'favicon' => isset($meta['favicon']) ? $meta['favicon'] : '',
                        'canonical' => isset($meta['canonical']) ? $meta['canonical'] : '',
                    );
                    
                    // Check for missing meta
                    if (empty($results['meta']['title'])) {
                        $results['issues'][] = array('type' => 'missing_title', 'severity' => 'high');
                    }
                    if (empty($results['meta']['description'])) {
                        $results['issues'][] = array('type' => 'missing_meta_description', 'severity' => 'high');
                    }
                }
                
                // Extract content information
                if (isset($result['page_content'])) {
                    $content = $result['page_content'];
                    $results['content'] = array(
                        'h1' => isset($content['main_topic']) ? $content['main_topic'] : '',
                        'word_count' => isset($content['plain_text_word_count']) ? $content['plain_text_word_count'] : 0,
                        'text_to_html_ratio' => isset($content['text_to_html_ratio']) ? $content['text_to_html_ratio'] : 0,
                    );
                    
                    // Check for thin content
                    if ($results['content']['word_count'] < 300) {
                        $results['issues'][] = array('type' => 'thin_content', 'severity' => 'medium', 'value' => $results['content']['word_count']);
                    }
                }
                
                // Extract links
                if (isset($result['links'])) {
                    $results['links'] = array(
                        'internal' => isset($result['links']['internal']) ? count($result['links']['internal']) : 0,
                        'external' => isset($result['links']['external']) ? count($result['links']['external']) : 0,
                        'broken' => isset($result['broken_links']) ? count($result['broken_links']) : 0,
                    );
                    
                    if ($results['links']['broken'] > 0) {
                        $results['issues'][] = array('type' => 'broken_links', 'severity' => 'medium', 'count' => $results['links']['broken']);
                    }
                }
                
                // Extract image information
                if (isset($result['images'])) {
                    $images_without_alt = 0;
                    foreach ($result['images'] as $image) {
                        if (empty($image['alt'])) {
                            $images_without_alt++;
                        }
                    }
                    
                    $results['images'] = array(
                        'total' => count($result['images']),
                        'without_alt' => $images_without_alt,
                    );
                    
                    if ($images_without_alt > 0) {
                        $results['issues'][] = array('type' => 'missing_alt_tags', 'severity' => 'medium', 'count' => $images_without_alt);
                    }
                }
                
                $results['success'] = true;
                error_log('MindfulSEO DataForSEO: Instant Page analysis successful. Found ' . count($results['issues']) . ' issues');
                
                // Only process first result
                break 2;
            }
        }
        
        return $results;
    }
}

