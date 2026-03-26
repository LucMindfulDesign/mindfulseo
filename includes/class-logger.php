<?php
/**
 * Logger
 * 
 * Logs all plugin activities, API calls, and errors
 * 
 * @package MindfulSEO
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_Logger {
    
    /**
     * The single instance of the class
     * 
     * @var MFSEO_Logger
     */
    private static $instance = null;
    
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
        // Initialize
    }
    
    /**
     * Log optimization activity
     * 
     * @param int $post_id Post ID
     * @param array $data Optimization data
     * @return int|false Log ID or false on failure
     */
    public function log_optimization($post_id, $data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'mindfulseo_logs';
        
        $result = $wpdb->insert(
            $table,
            array(
                'log_type' => 'optimization',
                'post_id' => $post_id,
                'ai_provider' => isset($data['provider']) ? $data['provider'] : '',
                'prompt_tokens' => isset($data['prompt_tokens']) ? $data['prompt_tokens'] : 0,
                'completion_tokens' => isset($data['completion_tokens']) ? $data['completion_tokens'] : 0,
                'cost' => isset($data['cost']) ? $data['cost'] : 0,
                'message' => isset($data['message']) ? $data['message'] : '',
                'user_id' => get_current_user_id(),
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%d', '%s', '%d', '%d', '%f', '%s', '%d', '%s')
        );
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Log API call
     * 
     * @param string $provider Provider name
     * @param int $prompt_tokens Prompt tokens used
     * @param int $completion_tokens Completion tokens used
     * @param float $cost Estimated cost
     * @param string $model Model id (optional)
     * @param string $usage_context Short label: feature that triggered the call (e.g. batch_optimizer, content_analyzer_keywords)
     * @return int|false Log ID or false
     */
    public function log_api_call($provider, $prompt_tokens, $completion_tokens, $cost, $model = '', $usage_context = '') {
        global $wpdb;
        
        $table = $wpdb->prefix . 'mindfulseo_logs';

        $message = '';
        if ($model !== '') {
            $message = $model;
            if ($usage_context !== '') {
                $message .= ' :: ' . $usage_context;
            }
        } elseif ($usage_context !== '') {
            $message = $usage_context;
        }
        
        $result = $wpdb->insert(
            $table,
            array(
                'log_type'         => 'api_call',
                'ai_provider'      => $provider,
                'prompt_tokens'    => $prompt_tokens,
                'completion_tokens' => $completion_tokens,
                'cost'             => $cost,
                'message'          => $message !== '' ? $message : null,
                'user_id'          => get_current_user_id(),
                'created_at'       => current_time('mysql'),
            ),
            array('%s', '%s', '%d', '%d', '%f', '%s', '%d', '%s')
        );
        
        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Date filter for api_call rows: created_at is stored with current_time('mysql') (site timezone).
     * Do not use CURDATE()/NOW() — MySQL uses server TZ and will mismatch the stored local datetimes.
     *
     * @param string $date_range 'today'|'week'|'month'|'all'
     * @return string SQL fragment starting with leading space + AND … (empty for 'all')
     */
    private function get_api_call_date_sql_fragment($date_range) {
        global $wpdb;

        switch ($date_range) {
            case 'today':
                $d = current_time('Y-m-d');
                return $wpdb->prepare(
                    ' AND created_at >= %s AND created_at <= %s',
                    $d . ' 00:00:00',
                    $d . ' 23:59:59'
                );
            case 'week':
                $start = date('Y-m-d H:i:s', strtotime('-7 days', current_time('timestamp')));
                return $wpdb->prepare(' AND created_at >= %s', $start);
            case 'month':
                $start = date('Y-m-d H:i:s', strtotime('-30 days', current_time('timestamp')));
                return $wpdb->prepare(' AND created_at >= %s', $start);
            default:
                return '';
        }
    }

    /**
     * Get daily API cost trend grouped by provider.
     *
     * @param string $date_range  'today' | 'week' | 'month' | 'all'
     * @return array  Array of [ 'day' => 'YYYY-MM-DD', 'provider' => string, 'calls' => int, 'cost' => float ]
     */
    public function get_api_daily_trend($date_range = 'month') {
        global $wpdb;
        $table = $wpdb->prefix . 'mindfulseo_logs';

        $where = "log_type = 'api_call'";
        $where .= $this->get_api_call_date_sql_fragment($date_range);

        return $wpdb->get_results(
            "SELECT DATE(created_at) AS day, ai_provider AS provider,
                    COUNT(*) AS calls, SUM(cost) AS cost, SUM(prompt_tokens) AS tokens
             FROM $table
             WHERE $where
             GROUP BY DATE(created_at), ai_provider
             ORDER BY day ASC",
            ARRAY_A
        );
    }
    
    /**
     * Log error
     * 
     * @param string $message Error message
     * @param array $context Additional context
     * @return int|false Log ID or false
     */
    public function log_error($message, $context = array()) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'mindfulseo_logs';
        
        $context_json = !empty($context) ? wp_json_encode($context) : '';
        
        $result = $wpdb->insert(
            $table,
            array(
                'log_type' => 'error',
                'message' => $message,
                'post_id' => isset($context['post_id']) ? $context['post_id'] : null,
                'ai_provider' => isset($context['provider']) ? $context['provider'] : '',
                'user_id' => get_current_user_id(),
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%d', '%s', '%d', '%s')
        );
        
        // Also log to PHP error log if debug mode is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MindfulSEO Error: ' . $message . ' Context: ' . $context_json);
        }
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Log info message
     * 
     * @param string $message Info message
     * @param array $context Additional context
     * @return int|false Log ID or false
     */
    public function log_info($message, $context = array()) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'mindfulseo_logs';
        
        $result = $wpdb->insert(
            $table,
            array(
                'log_type' => 'info',
                'message' => $message,
                'post_id' => isset($context['post_id']) ? $context['post_id'] : null,
                'user_id' => get_current_user_id(),
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%d', '%d', '%s')
        );
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Get recent logs
     * 
     * @param int $limit Number of logs to retrieve
     * @param string $type Log type filter
     * @return array Logs
     */
    public function get_recent_logs($limit = 100, $type = null) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'mindfulseo_logs';
        
        $sql = "SELECT * FROM $table";
        
        if ($type) {
            $sql .= $wpdb->prepare(" WHERE log_type = %s", $type);
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT %d";
        
        return $wpdb->get_results($wpdb->prepare($sql, $limit));
    }
    
    /**
     * Get logs for a specific post
     * 
     * @param int $post_id Post ID
     * @return array Logs
     */
    public function get_post_logs($post_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'mindfulseo_logs';
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE post_id = %d ORDER BY created_at DESC",
                $post_id
            )
        );
    }
    
    /**
     * Get API usage statistics
     * 
     * @param string $date_range Date range (today, week, month, all)
     * @return array Statistics
     */
    public function get_api_stats($date_range = 'month') {
        global $wpdb;
        
        $table = $wpdb->prefix . 'mindfulseo_logs';
        
        $where = "log_type = 'api_call'";
        $where .= $this->get_api_call_date_sql_fragment($date_range);
        
        // Get overall stats
        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total_calls,
                SUM(prompt_tokens) as total_prompt_tokens,
                SUM(completion_tokens) as total_completion_tokens,
                SUM(cost) as total_cost,
                AVG(cost) as avg_cost
            FROM $table
            WHERE $where"
        );
        
        // Get per-provider breakdown
        $provider_stats = $wpdb->get_results(
            "SELECT 
                ai_provider,
                COUNT(*) as calls,
                SUM(prompt_tokens) as prompt_tokens,
                SUM(completion_tokens) as completion_tokens,
                SUM(cost) as cost
            FROM $table
            WHERE $where
            GROUP BY ai_provider",
            ARRAY_A
        );
        
        $by_provider = array();
        foreach ($provider_stats as $row) {
            $provider = $row['ai_provider'];
            $by_provider[$provider] = array(
                'calls' => (int) $row['calls'],
                'prompt_tokens' => (int) $row['prompt_tokens'],
                'completion_tokens' => (int) $row['completion_tokens'],
                'total_tokens' => (int) ($row['prompt_tokens'] + $row['completion_tokens']),
                'cost' => (float) $row['cost'],
            );
        }
        
        return array(
            'total_calls' => (int) $stats->total_calls,
            'total_prompt_tokens' => (int) $stats->total_prompt_tokens,
            'total_completion_tokens' => (int) $stats->total_completion_tokens,
            'total_tokens' => (int) ($stats->total_prompt_tokens + $stats->total_completion_tokens),
            'total_cost' => (float) $stats->total_cost,
            'avg_cost' => (float) $stats->avg_cost,
            'by_provider' => $by_provider,
        );
    }
    
    /**
     * Export logs to CSV
     * 
     * @param string $date_range Date range
     * @return string|false CSV file path or false on failure
     */
    public function export_logs_to_csv($date_range = 'all') {
        global $wpdb;
        
        $table = $wpdb->prefix . 'mindfulseo_logs';
        
        $where = "1=1";
        $where .= $this->get_api_call_date_sql_fragment($date_range);
        
        $logs = $wpdb->get_results("SELECT * FROM $table WHERE $where ORDER BY created_at DESC");
        
        if (empty($logs)) {
            return false;
        }
        
        // Create CSV file
        $filename = 'mindfulseo-logs-' . date('Y-m-d-His') . '.csv';
        $filepath = MINDFULSEO_LOGS_DIR . $filename;
        
        $fp = fopen($filepath, 'w');
        
        if (!$fp) {
            return false;
        }
        
        // Write headers
        fputcsv($fp, array(
            'ID',
            'Type',
            'Post ID',
            'Provider',
            'Prompt Tokens',
            'Completion Tokens',
            'Cost',
            'Message',
            'User ID',
            'Date',
        ));
        
        // Write data
        foreach ($logs as $log) {
            fputcsv($fp, array(
                $log->id,
                $log->log_type,
                $log->post_id,
                $log->ai_provider,
                $log->prompt_tokens,
                $log->completion_tokens,
                $log->cost,
                $log->message,
                $log->user_id,
                $log->created_at,
            ));
        }
        
        fclose($fp);
        
        return $filepath;
    }
    
    /**
     * Clean old logs
     * 
     * @param int $days Delete logs older than X days
     * @return int|false Number of rows deleted or false
     */
    public function clean_old_logs($days = 90) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'mindfulseo_logs';
        
        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
    }
}

