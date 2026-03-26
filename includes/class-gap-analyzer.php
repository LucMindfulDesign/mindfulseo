<?php
/**
 * Gap Analyzer
 *
 * Identifies content gaps: strategy keywords that have no post targeting them.
 * Uses keyword manager and SEO adapter; no new tables.
 *
 * @package MindfulSEO
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_Gap_Analyzer {

    const TRANSIENT_KEY_PREFIX = 'mfseo_gap_list_';
    const TRANSIENT_TTL = 43200; // 12 hours
    const MAX_GAPS = 100;

    /**
     * The single instance of the class
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
        // No-op
    }

    /**
     * Find content gaps: strategy primary keywords with no post using that focus keyword.
     * Results are cached in a transient to avoid repeated expensive queries.
     *
     * @param bool $force_refresh Whether to bypass cache.
     * @return array List of gap items: keyword, volume, difficulty, cpc, cluster (keyword used as cluster)
     */
    public function find_gaps( $force_refresh = false ) {
        $cache_key = self::TRANSIENT_KEY_PREFIX . 'all';

        if ( ! $force_refresh ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        try {
            $gaps = $this->find_gaps_uncached();
        } catch ( \Throwable $e ) {
            error_log( 'MindfulSEO Gap Analyzer error: ' . $e->getMessage() );
            return array();
        }

        set_transient( $cache_key, $gaps, self::TRANSIENT_TTL );
        return $gaps;
    }

    /**
     * Clear the gap analysis cache.
     */
    public function clear_cache() {
        delete_transient( self::TRANSIENT_KEY_PREFIX . 'all' );
    }

    /**
     * Internal: run the actual gap analysis queries (uncached).
     *
     * @return array
     */
    private function find_gaps_uncached() {
        global $wpdb;

        $adapter = class_exists( 'MFSEO_SEO_Plugin_Adapter' ) ? MFSEO_SEO_Plugin_Adapter::get_instance() : null;
        $meta_key = $adapter && $adapter->is_seo_plugin_active()
            ? ( $adapter->get_active_plugin() === 'rankmath' ? 'rank_math_focus_keyword' : '_yoast_wpseo_focuskw' )
            : 'rank_math_focus_keyword';

        $kw_table = $wpdb->prefix . 'mindfulseo_keywords';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $kw_table ) ) !== $kw_table ) {
            return array();
        }

        // Sanitize meta_key and use it directly in the query to avoid wpdb->prepare issues
        // with WordPress 6.x stricter prepare() validation
        $safe_meta_key = sanitize_key( $meta_key );
        if ( empty( $safe_meta_key ) ) {
            $safe_meta_key = 'rank_math_focus_keyword';
        }

        // Use esc_sql for the meta_key since sanitize_key already strips dangerous chars
        $assigned_raw = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT LOWER(TRIM(pm.meta_value))
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s
                 AND p.post_status = %s
                 AND p.post_type = %s
                 AND pm.meta_value IS NOT NULL AND pm.meta_value != ''",
                $safe_meta_key,
                'publish',
                'post'
            )
        );

        if ( $wpdb->last_error ) {
            error_log( 'MindfulSEO Gap Analyzer SQL error: ' . $wpdb->last_error );
            return array();
        }

        $assigned = is_array( $assigned_raw ) ? $assigned_raw : array();
        $assigned_set = array_flip( array_map( 'strtolower', array_map( 'trim', $assigned ) ) );

        $strategy_keywords = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT primary_keyword,
                        MAX(search_volume) AS search_volume,
                        MAX(keyword_difficulty) AS keyword_difficulty,
                        MAX(cpc) AS cpc
                 FROM {$kw_table}
                 WHERE primary_keyword IS NOT NULL AND TRIM(primary_keyword) != ''
                 GROUP BY primary_keyword
                 ORDER BY COALESCE(MAX(search_volume), 0) DESC
                 LIMIT %d",
                500
            )
        );

        if ( ! is_array( $strategy_keywords ) ) {
            return array();
        }

        $gaps = array();
        foreach ( $strategy_keywords as $row ) {
            $kw = is_string( $row->primary_keyword ) ? trim( $row->primary_keyword ) : '';
            if ( $kw === '' ) {
                continue;
            }
            $key = strtolower( $kw );
            if ( isset( $assigned_set[ $key ] ) ) {
                continue;
            }
            $vol  = $row->search_volume !== null ? (int) $row->search_volume : 0;
            $diff = $row->keyword_difficulty !== null ? (int) $row->keyword_difficulty : null;
            $priority = $this->compute_priority( $vol, $diff );
            $gaps[] = array(
                'keyword'        => $kw,
                'volume'         => $vol,
                'search_volume'  => $vol,
                'difficulty'     => $diff,
                'cpc'            => $row->cpc !== null ? (float) $row->cpc : null,
                'cluster'        => $kw,
                'priority'       => $priority,
            );
            if ( count( $gaps ) >= self::MAX_GAPS ) {
                break;
            }
        }

        usort( $gaps, array( $this, 'sort_gaps_by_priority' ) );
        return $gaps;
    }

    /**
     * Compute priority for a gap: High = high volume + low difficulty; Medium = decent opportunity; Low = rest.
     *
     * @param int $volume Search volume.
     * @param int|null $difficulty Keyword difficulty 0-100.
     * @return string 'high', 'medium', or 'low'
     */
    private function compute_priority($volume, $difficulty) {
        if ($volume >= 1000) {
            return ($difficulty !== null && $difficulty <= 50) ? 'high' : (($difficulty !== null && $difficulty <= 70) ? 'medium' : 'low');
        }
        if ($volume >= 500 && $difficulty !== null && $difficulty <= 50) {
            return 'high';
        }
        if ($volume >= 100 || ($difficulty !== null && $difficulty <= 70)) {
            return 'medium';
        }
        return 'low';
    }

    /**
     * Sort gaps by priority (high first), then by volume descending.
     *
     * @param array $a
     * @param array $b
     * @return int
     */
    private function sort_gaps_by_priority($a, $b) {
        $order = array('high' => 3, 'medium' => 2, 'low' => 1);
        $pa = isset($order[$a['priority']]) ? $order[$a['priority']] : 0;
        $pb = isset($order[$b['priority']]) ? $order[$b['priority']] : 0;
        if ($pa !== $pb) {
            return $pb - $pa;
        }
        return (isset($b['volume']) ? $b['volume'] : 0) - (isset($a['volume']) ? $a['volume'] : 0);
    }

    /**
     * Analyze keyword gaps (alias)
     *
     * @return array
     */
    public function analyze_keyword_gaps() {
        return $this->find_gaps();
    }

    /**
     * Get the count of gaps without returning full data (uses cache if available).
     *
     * @return int
     */
    public function get_gap_count() {
        return count( $this->find_gaps() );
    }
}
