<?php
/**
 * Cache Manager
 * 
 * Manages caching for expensive operations
 * 
 * @package MindfulSEO
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_Cache_Manager {

    /**
     * The single instance of the class
     */
    private static $instance = null;

    /**
     * Cache group for wp_cache
     */
    const CACHE_GROUP = 'mindfulseo';

    /**
     * Known transient keys used by the plugin
     */
    const TRANSIENT_KEYS = array(
        'mfseo_gap_list_all',
        'mfseo_orphan_opportunities',
        'mfseo_cluster_all_clusters',
        'mfseo_hub_quick_counts',
    );

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
        // Initialization
    }

    /**
     * Get cached value (object cache)
     *
     * @param string $key Cache key.
     * @return mixed|false
     */
    public function get( $key ) {
        return wp_cache_get( $key, self::CACHE_GROUP );
    }

    /**
     * Set cached value (object cache)
     *
     * @param string $key        Cache key.
     * @param mixed  $value      Value to cache.
     * @param int    $expiration TTL in seconds.
     * @return bool
     */
    public function set( $key, $value, $expiration = 3600 ) {
        return wp_cache_set( $key, $value, self::CACHE_GROUP, $expiration );
    }

    /**
     * Delete cached value (object cache)
     *
     * @param string $key Cache key.
     * @return bool
     */
    public function delete( $key ) {
        return wp_cache_delete( $key, self::CACHE_GROUP );
    }

    /**
     * Clear all plugin caches (both object cache group and known transients).
     */
    public function clear_all() {
        // Clear known transients
        foreach ( self::TRANSIENT_KEYS as $key ) {
            delete_transient( $key );
        }

        // Clear object cache group
        wp_cache_delete( self::CACHE_GROUP );
    }

    /**
     * Clear only Content Hub related caches.
     * Call this after content changes that affect hub data (post updates, keyword changes, etc.).
     */
    public function clear_content_hub_caches() {
        delete_transient( 'mfseo_hub_quick_counts' );
        delete_transient( 'mfseo_gap_list_all' );
        delete_transient( 'mfseo_orphan_opportunities' );
        delete_transient( 'mfseo_cluster_all_clusters' );
    }

    /**
     * Clear gap analysis cache.
     */
    public function clear_gap_cache() {
        delete_transient( 'mfseo_gap_list_all' );
        delete_transient( 'mfseo_hub_quick_counts' );
    }

    /**
     * Clear cluster cache.
     */
    public function clear_cluster_cache() {
        delete_transient( 'mfseo_cluster_all_clusters' );
        delete_transient( 'mfseo_hub_quick_counts' );
    }

    /**
     * Clear orphan/internal linking cache.
     */
    public function clear_linker_cache() {
        delete_transient( 'mfseo_orphan_opportunities' );
        delete_transient( 'mfseo_hub_quick_counts' );
    }
}
