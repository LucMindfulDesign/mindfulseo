<?php
/**
 * Content Cluster Engine
 *
 * Analyzes content relationships and builds topic clusters.
 * Uses SEO adapter for focus keywords and scores; caches results in transients.
 *
 * @package MindfulSEO
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_Content_Cluster_Engine {

    const TRANSIENT_KEY = 'mfseo_cluster_all_clusters';
    const TRANSIENT_TTL = 86400; // 24 hours

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
     * Get content clusters (from cache or compute)
     *
     * @return array List of clusters, each with cluster_name, pillar_post_id, post_ids, health_score
     */
    public function get_clusters() {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached)) {
            return $cached;
        }
        return $this->identify_clusters();
    }

    /**
     * Identify clusters from posts and their focus keywords.
     * Groups posts by focus keyword, picks pillar per cluster, computes health score.
     * Caches result in transient.
     *
     * @return array List of clusters
     */
    public function identify_clusters() {
        try {
            return $this->identify_clusters_inner();
        } catch ( \Throwable $e ) {
            error_log( 'MindfulSEO Cluster Engine error: ' . $e->getMessage() );
            set_transient( self::TRANSIENT_KEY, array(), self::TRANSIENT_TTL );
            return array();
        }
    }

    /**
     * Inner implementation of cluster identification (wrapped by try-catch in identify_clusters).
     *
     * @return array
     */
    private function identify_clusters_inner() {
        global $wpdb;

        $adapter = class_exists( 'MFSEO_SEO_Plugin_Adapter' ) ? MFSEO_SEO_Plugin_Adapter::get_instance() : null;
        if ( ! $adapter || ! $adapter->is_seo_plugin_active() ) {
            set_transient( self::TRANSIENT_KEY, array(), self::TRANSIENT_TTL );
            return array();
        }

        $meta_key  = $adapter->get_active_plugin() === 'rankmath' ? 'rank_math_focus_keyword' : '_yoast_wpseo_focuskw';
        $score_key = $adapter->get_active_plugin() === 'rankmath' ? 'rank_math_seo_score' : null;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, pm.meta_value AS focus_keyword
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_status = %s AND p.post_type = %s
             AND pm.meta_value IS NOT NULL AND pm.meta_value != ''",
            $meta_key, 'publish', 'post'
        ), ARRAY_A );

        if ( ! is_array( $rows ) ) {
            $rows = array();
        }

        $by_keyword  = array();
        $all_post_ids = array();
        foreach ( $rows as $row ) {
            $kw = is_string( $row['focus_keyword'] ) ? trim( $row['focus_keyword'] ) : '';
            if ( $kw === '' ) {
                continue;
            }
            $key = strtolower( $kw );
            if ( ! isset( $by_keyword[ $key ] ) ) {
                $by_keyword[ $key ] = array( 'keyword' => $kw, 'post_ids' => array() );
            }
            $pid = (int) $row['ID'];
            $by_keyword[ $key ]['post_ids'][] = $pid;
            $all_post_ids[] = $pid;
        }

        // Merge near-duplicate focus keywords (e.g. "merit box" + "merit box project" + "international merit box project").
        $by_keyword = $this->merge_related_focus_keyword_groups( $by_keyword );

        // Batch-fetch all SEO scores in one query instead of per-cluster
        $scores_by_id = array();
        if ( $score_key && ! empty( $all_post_ids ) ) {
            $unique_ids       = array_unique( $all_post_ids );
            $ids_placeholders = implode( ',', array_fill( 0, count( $unique_ids ), '%d' ) );
            $score_rows       = $wpdb->get_results( $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key = %s AND post_id IN ($ids_placeholders)",
                array_merge( array( $score_key ), $unique_ids )
            ), ARRAY_A );
            if ( is_array( $score_rows ) ) {
                foreach ( $score_rows as $s ) {
                    $scores_by_id[ (int) $s['post_id'] ] = is_numeric( $s['meta_value'] ) ? (int) $s['meta_value'] : 0;
                }
            }
        }

        $clusters = array();
        foreach ( $by_keyword as $key => $data ) {
            $post_ids = array_unique( $data['post_ids'] );
            if ( count( $post_ids ) < 2 ) {
                continue;
            }

            $pillar_post_id = null;
            $health_score   = null;

            if ( $score_key && ! empty( $post_ids ) ) {
                $cluster_scores = array();
                $sum   = 0;
                $count = 0;
                foreach ( $post_ids as $pid ) {
                    if ( isset( $scores_by_id[ $pid ] ) ) {
                        $s = (int) $scores_by_id[ $pid ];
                        // Skip corrupted/negative values — RankMath scores must be 0–100
                        if ( $s < 0 || $s > 100 ) {
                            continue;
                        }
                        $cluster_scores[ $pid ] = $s;
                        $sum  += $s;
                        $count++;
                    }
                }
                if ( $count > 0 ) {
                    $health_score = (int) round( $sum / $count );
                }
                arsort( $cluster_scores );
                $first_key      = key( $cluster_scores );
                $pillar_post_id = $first_key ? (int) $first_key : null;
            }
            if ( $pillar_post_id === null && ! empty( $post_ids ) ) {
                $pillar_post_id = $post_ids[0];
            }

            $clusters[] = array(
                'cluster_name'  => $data['keyword'],
                'pillar_post_id' => $pillar_post_id,
                'post_ids'      => array_values( $post_ids ),
                'health_score'  => $health_score,
            );
        }

        usort( $clusters, function ( $a, $b ) {
            $ca = count( $a['post_ids'] );
            $cb = count( $b['post_ids'] );
            if ( $ca !== $cb ) {
                return $cb - $ca;
            }
            return strcmp( $a['cluster_name'], $b['cluster_name'] );
        } );

        set_transient( self::TRANSIENT_KEY, $clusters, self::TRANSIENT_TTL );
        return $clusters;
    }

    /**
     * Merge focus-keyword buckets where one keyword phrase is contained in another (whole words).
     * Example: "merit box", "merit box project", "international merit box project" → one group.
     *
     * @param array $by_keyword Map of lowercase key => array( 'keyword' => display string, 'post_ids' => int[] ).
     * @return array Merged map (keys may change).
     */
    private function merge_related_focus_keyword_groups( $by_keyword ) {
        if ( count( $by_keyword ) < 2 ) {
            return $by_keyword;
        }

        $keys   = array_keys( $by_keyword );
        $n      = count( $keys );
        $parent = range( 0, $n - 1 );

        $find_root = function ( $x ) use ( &$parent ) {
            while ( $parent[ $x ] !== $x ) {
                $x = $parent[ $x ];
            }
            return $x;
        };

        for ( $i = 0; $i < $n; $i++ ) {
            for ( $j = $i + 1; $j < $n; $j++ ) {
                if ( $this->focus_keywords_phrase_related( $keys[ $i ], $keys[ $j ] ) ) {
                    $ri = $find_root( $i );
                    $rj = $find_root( $j );
                    if ( $ri !== $rj ) {
                        $parent[ $ri ] = $rj;
                    }
                }
            }
        }

        $components = array();
        for ( $i = 0; $i < $n; $i++ ) {
            $r = $find_root( $i );
            if ( ! isset( $components[ $r ] ) ) {
                $components[ $r ] = array();
            }
            $components[ $r ][] = $i;
        }

        $merged = array();
        foreach ( $components as $members ) {
            $best_display = '';
            $all_ids      = array();
            foreach ( $members as $idx ) {
                $k    = $keys[ $idx ];
                $data = $by_keyword[ $k ];
                $all_ids = array_merge( $all_ids, $data['post_ids'] );
                $disp    = $data['keyword'];
                if ( strlen( $disp ) > strlen( $best_display ) ) {
                    $best_display = $disp;
                }
            }
            $new_key = strtolower( trim( $best_display ) );
            $merged[ $new_key ] = array(
                'keyword'  => $best_display,
                'post_ids' => array_values( array_unique( array_map( 'intval', $all_ids ) ) ),
            );
        }

        return $merged;
    }

    /**
     * True if two keyword strings should share one topic group (one phrase contained in the other).
     *
     * @param string $key_a Lowercase focus key from DB.
     * @param string $key_b Lowercase focus key from DB.
     * @return bool
     */
    private function focus_keywords_phrase_related( $key_a, $key_b ) {
        $a = trim( strtolower( $key_a ) );
        $b = trim( strtolower( $key_b ) );
        if ( $a === $b ) {
            return false;
        }
        $short = strlen( $a ) < strlen( $b ) ? $a : $b;
        $long  = strlen( $a ) < strlen( $b ) ? $b : $a;
        if ( strlen( $short ) < 8 ) {
            return false;
        }
        $words = preg_split( '/\s+/u', $short, -1, PREG_SPLIT_NO_EMPTY );
        if ( count( $words ) < 2 ) {
            return false;
        }

        return (bool) preg_match( '/\b' . preg_quote( $short, '/' ) . '\b/u', $long );
    }

    /**
     * Find content gaps: strategy keywords with no matching post.
     * Returns format expected by AJAX generate_gap_suggestions: keyword, search_volume, difficulty, cluster.
     *
     * @return array List of gap items
     */
    public function find_content_gaps() {
        if ( ! class_exists( 'MFSEO_Gap_Analyzer' ) ) {
            return array();
        }
        try {
            $gaps = MFSEO_Gap_Analyzer::get_instance()->find_gaps();
            if ( ! is_array( $gaps ) ) {
                return array();
            }
            $result = array();
            foreach ( $gaps as $g ) {
                $keyword = isset( $g['keyword'] ) ? $g['keyword'] : '';
                $result[] = array(
                    'keyword'       => $keyword,
                    'search_volume' => isset( $g['volume'] ) ? $g['volume'] : ( isset( $g['search_volume'] ) ? $g['search_volume'] : 0 ),
                    'difficulty'    => isset( $g['difficulty'] ) ? $g['difficulty'] : null,
                    'cluster'       => isset( $g['cluster'] ) ? $g['cluster'] : $keyword,
                );
            }
            return $result;
        } catch ( \Throwable $e ) {
            error_log( 'MindfulSEO find_content_gaps error: ' . $e->getMessage() );
            return array();
        }
    }

    /**
     * Analyze content for clusters (alias for identify_clusters for backward compatibility)
     *
     * @return array
     */
    public function analyze_content() {
        return $this->identify_clusters();
    }
}
