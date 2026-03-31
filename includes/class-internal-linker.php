<?php
/**
 * Internal Linker
 *
 * Finds orphan posts (no incoming links) and suggests internal links.
 * Uses same logic as AJAX analyze_internal_links for consistency.
 *
 * @package MindfulSEO
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_Internal_Linker {

    const ORPHAN_LIMIT           = 100;
    const SUGGESTIONS_PER_POST   = 10;
    const SUGGESTIONS_BATCH      = 50;
    const TRANSIENT_KEY          = 'mfseo_orphan_opportunities';
    const TRANSIENT_TTL          = 3600;
    const BROKEN_LINKS_TRANSIENT = 'mfseo_broken_links_scan';
    const BROKEN_LINKS_TTL       = 21600; // 6 hours
    /** Transient key for chunked scan progress (multi-request). */
    const BROKEN_LINKS_STATE_TRANSIENT = 'mfseo_broken_links_scan_state';
    const BROKEN_LINKS_STATE_TTL       = 1800; // 30 minutes
    /** Posts processed per AJAX request (keeps each request under typical PHP timeouts). */
    const BROKEN_LINKS_CHUNK_SIZE      = 400;
    /** Max unique external URLs to HTTP-check per scan (full content is still scanned). */
    const MAX_EXTERNAL_CHECKS    = 200;

    /**
     * The single instance of the class
     */
    private static $instance = null;

    /**
     * Get instance
     */
    public static function get_instance() {
        if ( is_null( self::$instance ) ) {
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
     * Find opportunities: orphan posts and suggested links for them.
     * Results are cached in a transient to avoid the expensive orphan query on every page load.
     *
     * @param bool $force_refresh Bypass cache.
     * @return array { orphans, suggestions, orphan_count }
     */
    public function find_opportunities( $force_refresh = false ) {
        $empty = array( 'orphans' => array(), 'suggestions' => array(), 'orphan_count' => 0 );

        if ( ! $force_refresh ) {
            $cached = get_transient( self::TRANSIENT_KEY );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        try {
            $result = $this->find_opportunities_uncached();
        } catch ( \Throwable $e ) {
            error_log( 'MindfulSEO Internal Linker error: ' . $e->getMessage() );
            return $empty;
        }

        set_transient( self::TRANSIENT_KEY, $result, self::TRANSIENT_TTL );
        return $result;
    }

    /**
     * Clear the internal linker cache.
     */
    public function clear_cache() {
        delete_transient( self::TRANSIENT_KEY );
    }

    /**
     * Clear broken links cache.
     */
    public function clear_broken_links_cache() {
        delete_transient( self::BROKEN_LINKS_TRANSIENT );
        delete_transient( self::BROKEN_LINKS_STATE_TRANSIENT );
    }

    /**
     * Scan all published post content for broken links.
     * - Internal: links pointing to a URL on the same domain that no longer resolves to a post.
     * - External: outbound links returning HTTP 404 / 410 / 5xx / connection failure.
     *
     * @param bool $force_refresh Bypass cache.
     * @return array { broken_internal, broken_external, internal_count, external_count, external_checked, elapsed }
     */
    public function scan_broken_links( $force_refresh = false ) {
        if ( ! $force_refresh ) {
            $cached = get_transient( self::BROKEN_LINKS_TRANSIENT );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        $result = $this->do_scan_broken_links();
        set_transient( self::BROKEN_LINKS_TRANSIENT, $result, self::BROKEN_LINKS_TTL );
        return $result;
    }

    /**
     * Turn any href from post content into an absolute http(s) URL, or null to skip.
     *
     * @param string $raw Raw href attribute value.
     * @return string|null
     */
    private function normalize_href_for_scan( $raw ) {
        $raw = trim( $raw );
        if ( $raw === '' ) {
            return null;
        }
        if ( preg_match( '/^(mailto|tel|javascript|data):/i', $raw ) ) {
            return null;
        }
        if ( $raw === '#' || strpos( $raw, '#' ) === 0 ) {
            return null;
        }
        if ( preg_match( '/^https?:\/\//i', $raw ) ) {
            return $raw;
        }
        // Protocol-relative: //example.com/foo
        if ( strpos( $raw, '//' ) === 0 && strpos( $raw, '///' ) !== 0 ) {
            $scheme = is_ssl() ? 'https' : 'http';

            return $scheme . ':' . $raw;
        }
        // Root-relative /path or path without scheme
        if ( strpos( $raw, '/' ) === 0 ) {
            return home_url( $raw );
        }
        if ( ! preg_match( '/^[a-z][a-z0-9+.-]*:/i', $raw ) ) {
            return home_url( '/' . ltrim( $raw, '/' ) );
        }

        return null;
    }

    /**
     * Parse <a href> tags from HTML and return visible link text (for broken-link reports).
     *
     * @param string $html Post content HTML.
     * @return array<int, array{href: string, text: string}>
     */
    private function parse_a_tags_from_html( $html ) {
        $links = array();
        if ( $html === '' ) {
            return $links;
        }

        if ( class_exists( 'DOMDocument' ) ) {
            libxml_use_internal_errors( true );
            $doc = new DOMDocument();
            $wrapped = '<?xml encoding="utf-8"?><div id="mfseo-link-parse-root">' . $html . '</div>';
            $ok      = @$doc->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
            libxml_clear_errors();
            if ( $ok ) {
                $xp = new DOMXPath( $doc );
                foreach ( $xp->query( '//a[@href]' ) as $node ) {
                    if ( ! ( $node instanceof DOMElement ) ) {
                        continue;
                    }
                    $href = $node->getAttribute( 'href' );
                    $text = trim( preg_replace( '/\s+/u', ' ', $node->textContent ) );
                    if ( $text === '' ) {
                        $t = trim( $node->getAttribute( 'title' ) );
                        if ( $t !== '' ) {
                            $text = $t;
                        }
                    }
                    $links[] = array(
                        'href' => $href,
                        'text' => $text,
                    );
                }
                if ( ! empty( $links ) ) {
                    return $links;
                }
            }
        }

        preg_match_all( '/href=["\']([^"\']*)["\']/i', $html, $matches );
        if ( ! empty( $matches[1] ) ) {
            foreach ( $matches[1] as $raw ) {
                $links[] = array(
                    'href' => $raw,
                    'text' => '',
                );
            }
        }

        return $links;
    }

    /**
     * Perform the actual broken link scan (all published posts/pages — no time cap on DB pass).
     * Single long request; prefer chunked AJAX for large sites.
     */
    private function do_scan_broken_links() {
        $state = $this->create_broken_links_scan_state( false, 0 );
        while ( $this->run_broken_links_content_chunk( $state ) ) {
            // Exhaust all post batches.
        }
        return $this->finalize_broken_links_scan_result( $state );
    }

    /**
     * Initialize scan state for chunked or full run.
     *
     * @param bool $quick_mode Skip external HTTP checks (no outbound requests to third-party sites).
     * @param int  $post_limit 0 = scan all published posts/pages; N = scan at most N most recently modified (fast).
     * @return array
     */
    private function create_broken_links_scan_state( $quick_mode, $post_limit = 0 ) {
        global $wpdb;

        $site_url = untrailingslashit( get_site_url() );

        $db_total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page')"
        );

        $post_limit = absint( $post_limit );
        if ( $post_limit > 20000 ) {
            $post_limit = 20000;
        }

        if ( $post_limit > 0 ) {
            $effective_total = min( $post_limit, max( 0, $db_total ) );
            /* Small chunks so each request finishes quickly and progress updates often. */
            $chunk_size = 20;
            $order_by   = 'modified';
        } else {
            $effective_total = max( 0, $db_total );
            $chunk_size      = (int) self::BROKEN_LINKS_CHUNK_SIZE;
            $order_by        = 'id';
        }

        return array(
            'offset'                  => 0,
            'total_posts'             => max( 1, $effective_total ),
            'post_limit'              => $post_limit,
            'db_total'                => $db_total,
            'order_by'                => $order_by,
            'broken_internal'         => array(),
            'unique_external'         => array(),
            'seen_internal'           => array(),
            'posts_scanned'           => 0,
            'hrefs_seen'              => 0,
            'external_pool_truncated' => false,
            'quick_mode'              => $quick_mode,
            'site_host'               => (string) parse_url( $site_url, PHP_URL_HOST ),
            'started'                 => microtime( true ),
            'chunk_size'              => $chunk_size,
            'external_pool_cap'       => 500,
        );
    }

    /**
     * Load one batch of posts and scan hrefs. Mutates $state.
     *
     * @param array $state Scan state (by ref).
     * @return bool True if more post batches may remain.
     */
    private function run_broken_links_content_chunk( &$state ) {
        global $wpdb;

        $batch_size = isset( $state['chunk_size'] ) ? (int) $state['chunk_size'] : self::BROKEN_LINKS_CHUNK_SIZE;
        $offset     = (int) $state['offset'];
        $post_limit = isset( $state['post_limit'] ) ? (int) $state['post_limit'] : 0;

        if ( $post_limit > 0 ) {
            $remaining = $post_limit - (int) $state['posts_scanned'];
            if ( $remaining <= 0 ) {
                return false;
            }
            $this_batch = min( $batch_size, $remaining );
        } else {
            $this_batch = $batch_size;
        }

        $order_sql = ( isset( $state['order_by'] ) && $state['order_by'] === 'modified' )
            ? 'post_modified DESC'
            : 'ID DESC';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_title, post_content
                 FROM {$wpdb->posts}
                 WHERE post_status = 'publish' AND post_type IN ('post','page')
                 ORDER BY {$order_sql}
                 LIMIT %d OFFSET %d",
                $this_batch,
                $offset
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return false;
        }

        $state['offset'] = $offset + count( $rows );

        $skip_patterns = array( '/wp-admin/', '/wp-content/', '/wp-includes/', '/wp-json/', '/feed/' );
        $site_host = $state['site_host'];
        $quick     = ! empty( $state['quick_mode'] );
        $pool_cap  = isset( $state['external_pool_cap'] ) ? (int) $state['external_pool_cap'] : 500;

        foreach ( $rows as $post ) {
            $state['posts_scanned']++;
            if ( empty( $post['post_content'] ) ) {
                continue;
            }

            foreach ( $this->parse_a_tags_from_html( $post['post_content'] ) as $alink ) {
                $raw_url   = isset( $alink['href'] ) ? $alink['href'] : '';
                $link_text = isset( $alink['text'] ) ? $alink['text'] : '';
                $url       = $this->normalize_href_for_scan( $raw_url );
                if ( ! $url ) {
                    continue;
                }
                $state['hrefs_seen']++;

                $skip = false;
                foreach ( $skip_patterns as $pat ) {
                    if ( strpos( $url, $pat ) !== false ) {
                        $skip = true;
                        break;
                    }
                }
                if ( $skip ) {
                    continue;
                }

                $parsed_host = (string) parse_url( $url, PHP_URL_HOST );

                if ( $parsed_host === $site_host ) {
                    if ( strpos( $url, '?' ) !== false ) {
                        continue;
                    }
                    $dedupe_key = strtolower( untrailingslashit( $url ) );
                    if ( isset( $state['seen_internal'][ $dedupe_key ] ) ) {
                        continue;
                    }
                    $state['seen_internal'][ $dedupe_key ] = true;

                    if ( ! $this->is_internal_url_valid( $url, $wpdb, false ) ) {
                        $state['broken_internal'][] = array(
                            'source_id'    => (int) $post['ID'],
                            'source_title' => $post['post_title'],
                            'broken_url'   => $url,
                            'link_text'    => $link_text,
                            'edit_url'     => get_edit_post_link( (int) $post['ID'] ),
                        );
                    }
                } elseif ( ! empty( $parsed_host ) && ! $quick ) {
                    if ( ! isset( $state['unique_external'][ $url ] ) ) {
                        if ( count( $state['unique_external'] ) < $pool_cap ) {
                            $state['unique_external'][ $url ] = array(
                                'source_id'    => (int) $post['ID'],
                                'source_title' => $post['post_title'],
                                'link_text'    => $link_text,
                            );
                        } else {
                            $state['external_pool_truncated'] = true;
                        }
                    }
                }
            }
        }

        if ( $post_limit > 0 && (int) $state['posts_scanned'] >= $post_limit ) {
            return false;
        }

        if ( $post_limit > 0 ) {
            return (int) $state['posts_scanned'] < $post_limit && count( $rows ) >= $this_batch;
        }

        return count( $rows ) >= $this_batch;
    }

    /**
     * After all content chunks: optional external HTTP checks, build API result array.
     *
     * @param array $state Scan state.
     * @return array
     */
    private function finalize_broken_links_scan_result( array $state ) {
        $broken_external  = array();
        $external_checked = 0;
        $start_time       = isset( $state['started'] ) ? $state['started'] : microtime( true );
        /** @var float Budget (seconds) only for external HTTP checks */
        $head_phase_budget = 120;
        $head_phase_start  = microtime( true );

        if ( empty( $state['quick_mode'] ) && ! empty( $state['unique_external'] ) ) {
            $skip_reliable_hosts = array( 'google.com', 'youtube.com', 'facebook.com', 'twitter.com', 'x.com', 'linkedin.com', 'wikipedia.org' );

            foreach ( $state['unique_external'] as $url => $meta ) {
                if ( $external_checked >= self::MAX_EXTERNAL_CHECKS ) {
                    break;
                }
                if ( ( microtime( true ) - $head_phase_start ) > $head_phase_budget ) {
                    break;
                }

                $ext_host = (string) parse_url( $url, PHP_URL_HOST );
                $skip     = false;
                foreach ( $skip_reliable_hosts as $rh ) {
                    if ( strlen( $ext_host ) >= strlen( $rh ) && substr( $ext_host, -strlen( $rh ) ) === $rh ) {
                        $skip = true;
                        break;
                    }
                }
                if ( $skip ) {
                    continue;
                }

                $status = $this->check_external_url_status( $url );
                $external_checked++;

                if ( $status === 0 || $status === 404 || $status === 410 || $status >= 500 ) {
                    $label = $status === 0 ? 'Connection failed' : $status;
                    $broken_external[] = array(
                        'source_id'    => $meta['source_id'],
                        'source_title' => $meta['source_title'],
                        'link_text'    => isset( $meta['link_text'] ) ? $meta['link_text'] : '',
                        'external_url' => $url,
                        'status_code'  => $status,
                        'status_label' => $label,
                        'edit_url'     => get_edit_post_link( $meta['source_id'] ),
                    );
                }
            }
        }

        $pl = isset( $state['post_limit'] ) ? (int) $state['post_limit'] : 0;

        return array(
            'broken_internal'         => $state['broken_internal'],
            'broken_external'         => $broken_external,
            'internal_count'          => count( $state['broken_internal'] ),
            'external_count'          => count( $broken_external ),
            'external_checked'        => $external_checked,
            'posts_scanned'           => (int) $state['posts_scanned'],
            'hrefs_found'             => (int) $state['hrefs_seen'],
            'external_pool_truncated' => ! empty( $state['external_pool_truncated'] ),
            'elapsed'                 => round( microtime( true ) - $start_time, 1 ),
            'quick_mode'              => ! empty( $state['quick_mode'] ),
            'post_limit'              => $pl,
            'scan_scope'              => $pl > 0 ? 'recent' : 'full',
            'db_total'                => isset( $state['db_total'] ) ? (int) $state['db_total'] : 0,
        );
    }

    /**
     * Start or continue a chunked broken-link scan (stores progress in a transient).
     *
     * @param bool   $quick_mode Quick scan (internal only, no outbound HTTP to third parties).
     * @param string $phase      "start" | "step".
     * @param int    $post_limit 0 = all posts; N = cap at N most recently modified (recommended for large sites).
     * @return array { needs_more, progress?, data? } progress: scanned, total, percent.
     */
    public function scan_broken_links_chunked( $quick_mode, $phase, $post_limit = 0 ) {
        if ( $phase === 'start' ) {
            delete_transient( self::BROKEN_LINKS_STATE_TRANSIENT );
            $state = $this->create_broken_links_scan_state( $quick_mode, $post_limit );
            set_transient( self::BROKEN_LINKS_STATE_TRANSIENT, $state, self::BROKEN_LINKS_STATE_TTL );
        } else {
            $state = get_transient( self::BROKEN_LINKS_STATE_TRANSIENT );
            if ( ! is_array( $state ) ) {
                return array(
                    'needs_more' => false,
                    'error'      => __( 'Scan session expired. Start again.', 'mindfulseo' ),
                );
            }
        }

        $has_more = $this->run_broken_links_content_chunk( $state );
        set_transient( self::BROKEN_LINKS_STATE_TRANSIENT, $state, self::BROKEN_LINKS_STATE_TTL );

        $total   = max( 1, (int) $state['total_posts'] );
        $scanned = (int) $state['posts_scanned'];
        $percent = (int) min( 100, round( ( $scanned / $total ) * 100 ) );

        if ( $has_more ) {
            return array(
                'needs_more' => true,
                'progress'   => array(
                    'scanned' => $scanned,
                    'total'   => (int) $state['total_posts'],
                    'percent' => $percent,
                ),
                'quick_mode' => ! empty( $state['quick_mode'] ),
            );
        }

        delete_transient( self::BROKEN_LINKS_STATE_TRANSIENT );
        $result = $this->finalize_broken_links_scan_result( $state );
        set_transient( self::BROKEN_LINKS_TRANSIENT, $result, self::BROKEN_LINKS_TTL );

        return array(
            'needs_more' => false,
            'data'       => $result,
        );
    }

    /**
     * HEAD request; on failure or 405, try a tiny GET (many servers block HEAD).
     *
     * @param string $url Full URL.
     * @return int HTTP status code, or 0 on total failure.
     */
    private function check_external_url_status( $url ) {
        $args = array(
            'timeout'     => 4,
            'sslverify'   => false,
            'redirection' => 3,
            'headers'     => array( 'User-Agent' => 'Mozilla/5.0 (compatible; MindfulSEO/1.0; +https://wordpress.org/)' ),
        );

        $response = wp_remote_head( $url, $args );
        $status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

        if ( $status === 0 || $status === 405 ) {
            $response = wp_remote_get(
                $url,
                array_merge( $args, array( 'timeout' => 5 ) )
            );
            $status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
        }

        return $status;
    }

    /**
     * Internal: run orphan detection and suggestion generation (uncached).
     *
     * Uses a batched PHP approach instead of the O(N^2) SQL subquery that was locking MySQL.
     * Processing: fetch all published posts, then scan post_content in batches to find
     * which post GUIDs are linked from other posts. Posts NOT linked anywhere are orphans.
     *
     * @return array
     */
    private function find_opportunities_uncached() {
        global $wpdb;

        $start_time = microtime( true );
        $time_limit = 45; // seconds -- bail out if it takes too long

        // Step 1: Get all published posts with their GUIDs (lightweight query)
        $all_posts = $wpdb->get_results(
            "SELECT ID, post_title, guid
             FROM {$wpdb->posts}
             WHERE post_status = 'publish'
             AND post_type IN ('post', 'page')
             ORDER BY post_date DESC
             LIMIT 5000",
            ARRAY_A
        );

        if ( ! is_array( $all_posts ) || empty( $all_posts ) ) {
            return array( 'orphans' => array(), 'suggestions' => array(), 'orphan_count' => 0 );
        }

        // Build lookup: ID => post data, and guid => ID for fast matching
        $posts_by_id = array();
        $guid_to_id  = array();
        foreach ( $all_posts as $p ) {
            $pid = (int) $p['ID'];
            $posts_by_id[ $pid ] = $p;
            if ( ! empty( $p['guid'] ) ) {
                $guid_to_id[ $p['guid'] ] = $pid;
            }
        }

        // Step 2: Scan post_content in batches to find which GUIDs are linked
        $linked_ids = array();
        $batch_size = 50;
        $all_ids    = array_keys( $posts_by_id );
        $total      = count( $all_ids );
        $guids_list = array_keys( $guid_to_id );

        for ( $offset = 0; $offset < $total; $offset += $batch_size ) {
            // Time safety: bail if running too long
            if ( ( microtime( true ) - $start_time ) > $time_limit ) {
                error_log( 'MindfulSEO orphan detection: time limit reached after ' . $offset . ' posts.' );
                break;
            }

            $batch_ids    = array_slice( $all_ids, $offset, $batch_size );
            $placeholders = implode( ',', array_fill( 0, count( $batch_ids ), '%d' ) );

            $content_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_content FROM {$wpdb->posts} WHERE ID IN ($placeholders)",
                    $batch_ids
                ),
                ARRAY_A
            );

            if ( ! is_array( $content_rows ) ) {
                continue;
            }

            foreach ( $content_rows as $row ) {
                $content   = $row['post_content'];
                $source_id = (int) $row['ID'];

                if ( empty( $content ) ) {
                    continue;
                }

                // Check which GUIDs appear in this post's content
                foreach ( $guids_list as $guid ) {
                    $target_id = $guid_to_id[ $guid ];

                    // Skip self-links and already-found targets
                    if ( $target_id === $source_id || isset( $linked_ids[ $target_id ] ) ) {
                        continue;
                    }

                    if ( strpos( $content, $guid ) !== false ) {
                        $linked_ids[ $target_id ] = true;
                    }
                }
            }
        }

        // Step 3: Orphans = posts NOT found as linked targets
        $orphans = array();
        $orphan_total = 0;
        foreach ( $all_posts as $post ) {
            $pid = (int) $post['ID'];
            if ( ! isset( $linked_ids[ $pid ] ) ) {
                $orphan_total++;
                if ( count( $orphans ) < self::ORPHAN_LIMIT ) {
                    $orphans[] = $post;
                }
            }
        }

        // Step 4: Build suggestions for first batch of orphans
        $adapter     = class_exists( 'MFSEO_SEO_Plugin_Adapter' ) ? MFSEO_SEO_Plugin_Adapter::get_instance() : null;
        $suggestions = array();
        $to_process  = array_slice( $orphans, 0, self::SUGGESTIONS_BATCH );

        if ( ! empty( $to_process ) ) {
            $recent_post = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT ID, post_title FROM {$wpdb->posts}
                     WHERE post_status = %s AND post_type = %s
                     ORDER BY post_date DESC LIMIT 1",
                    'publish',
                    'post'
                ),
                ARRAY_A
            );

            foreach ( $to_process as $post ) {
                if ( ! $recent_post || (int) $recent_post['ID'] === (int) $post['ID'] ) {
                    continue;
                }
                $target_id = (int) $post['ID'];
                $anchor    = isset( $post['post_title'] ) ? $post['post_title'] : '';
                if ( $adapter ) {
                    $focus = $adapter->get_focus_keyword( $target_id );
                    if ( is_string( $focus ) && trim( $focus ) !== '' ) {
                        $anchor = trim( $focus );
                    }
                }
                $suggestions[] = array(
                    'source_id'    => (int) $recent_post['ID'],
                    'source_title' => $recent_post['post_title'],
                    'target_id'    => $target_id,
                    'target_title' => $post['post_title'],
                    'target_url'   => get_permalink( $target_id ),
                    'anchor_text'  => $anchor,
                );
            }
        }

        $elapsed = round( microtime( true ) - $start_time, 2 );
        error_log( 'MindfulSEO orphan detection completed in ' . $elapsed . 's. Found ' . $orphan_total . ' orphans.' );

        return array(
            'orphans'      => $orphans,
            'suggestions'  => $suggestions,
            'orphan_count' => $orphan_total,
        );
    }

    /**
     * Suggest links for a single post: related posts that could link to this one (or that this post could link to).
     *
     * @param int $post_id Post ID
     * @return array List of { post_id, title, url, anchor_text }
     */
    public function suggest_links($post_id) {
        global $wpdb;

        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return array();
        }

        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            return array();
        }

        $adapter = class_exists('MFSEO_SEO_Plugin_Adapter') ? MFSEO_SEO_Plugin_Adapter::get_instance() : null;
        $focus_kw = $adapter ? $adapter->get_focus_keyword($post_id) : '';
        $content = $post->post_content;

        $meta_key = $adapter && $adapter->is_seo_plugin_active()
            ? ($adapter->get_active_plugin() === 'rankmath' ? 'rank_math_focus_keyword' : '_yoast_wpseo_focuskw')
            : 'rank_math_focus_keyword';

        $candidates = array();
        if (is_string($focus_kw) && trim($focus_kw) !== '') {
            $kw_like = '%' . $wpdb->esc_like(trim($focus_kw)) . '%';
            $candidates = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID, p.post_title
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
                 WHERE p.post_status = 'publish' AND p.post_type IN ('post', 'page')
                 AND p.ID != %d
                 AND (pm.meta_value = %s OR pm.meta_value LIKE %s)
                 ORDER BY p.post_date DESC
                 LIMIT %d",
                $meta_key,
                $post_id,
                trim($focus_kw),
                $kw_like,
                self::SUGGESTIONS_PER_POST
            ), ARRAY_A);
        }

        if (empty($candidates)) {
            $candidates = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_title FROM {$wpdb->posts}
                 WHERE post_status = 'publish' AND post_type IN ('post', 'page') AND ID != %d
                 ORDER BY post_date DESC
                 LIMIT %d",
                $post_id,
                self::SUGGESTIONS_PER_POST
            ), ARRAY_A);
        }

        $already_linked = array();
        if (is_string($content)) {
            preg_match_all('/href=["\']([^"\']+)["\']/', $content, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $url) {
                    $already_linked[] = trailingslashit($url);
                }
            }
        }

        $out = array();
        foreach ($candidates as $c) {
            $url = get_permalink($c['ID']);
            if (in_array(trailingslashit($url), $already_linked, true)) {
                continue;
            }
            $out[] = array(
                'post_id' => (int) $c['ID'],
                'title' => $c['post_title'],
                'url' => $url,
                'anchor_text' => $c['post_title'],
            );
        }
        return $out;
    }

    /**
     * Check whether an internal URL resolves to an existing published post/page.
     *
     * Uses a three-tier strategy to minimize false positives:
     *  1. url_to_postid() — fast, handles standard permalink structures
     *  2. Slug-based DB lookup — catches custom post types, child pages, etc.
     *  3. Local HTTP HEAD — final fallback for archive pages, custom rewrites, etc.
     *
     * @param string $url   Full internal URL.
     * @param wpdb   $wpdb  WordPress DB instance.
     * @param bool   $skip_tier3 If true, skip local HTTP HEAD (faster chunked/quick scans; may miss custom rewrites).
     * @return bool True if the URL is valid/reachable.
     */
    private function is_internal_url_valid( $url, $wpdb, $skip_tier3 = false ) {
        // Tier 1: WordPress built-in resolver
        if ( url_to_postid( $url ) > 0 ) {
            return true;
        }

        // Tier 2: Extract the last path segment (slug) and look it up in wp_posts
        $path = trim( (string) parse_url( $url, PHP_URL_PATH ), '/' );
        if ( $path !== '' ) {
            // For hierarchical URLs like /parent/child/, check the last segment
            $segments = explode( '/', $path );
            $slug     = end( $segments );

            if ( $slug !== '' ) {
                $found = $wpdb->get_var( $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_name = %s
                       AND post_status = 'publish'
                       AND post_type IN ('post','page')
                     LIMIT 1",
                    $slug
                ) );
                if ( $found ) {
                    return true;
                }
            }
        }

        if ( $skip_tier3 ) {
            return false;
        }

        // Tier 3: HTTP HEAD to the local site (catches archives, CPTs, custom rewrites)
        $response = wp_remote_head( $url, array(
            'timeout'     => 3,
            'sslverify'   => false,
            'redirection' => 3,
        ) );

        if ( ! is_wp_error( $response ) ) {
            $status = (int) wp_remote_retrieve_response_code( $response );
            if ( $status >= 200 && $status < 400 ) {
                return true;
            }
        }

        return false;
    }
}
