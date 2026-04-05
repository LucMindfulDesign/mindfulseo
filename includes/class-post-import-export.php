<?php
/**
 * Post export / import (ZIP) for MindfulSEO site migrations.
 *
 * ZIP structure
 * ─────────────
 *   manifest.csv              – one row per post (all core fields + SEO fields)
 *   html/{post_id}.html       – post_content (raw HTML)
 *   meta/{post_id}.json       – all post meta except blocked keys
 *   images/featured-{id}.ext  – featured images (binary)
 *   mfseo/{post_id}.json      – mindfulseo_optimizations rows (optional)
 *   mfseo-export.json         – export metadata (version, site, date)
 *
 * Export delivery — direct URL (most reliable on any host/debug config)
 * ─────────────────────────────────────────────────────────────────────
 *   1. POST → admin_init (priority 1)
 *      Generate ZIP → save to uploads/mfseo-exports/ (publicly accessible)
 *      wp_safe_redirect() straight to the ZIP's HTTP URL
 *   2. Browser GETs the ZIP from Apache/Nginx directly — no PHP streaming.
 *
 * This sidesteps all output-buffering, WP_DEBUG_DISPLAY, and headers_sent()
 * issues that plagued the previous PHP-streaming approach.
 *
 * Import: POST targets admin.php?page=mindfulseo-import-export; processing runs on admin_init
 * and the result is shown on the same request (no redirect flash). admin-post.php remains as
 * a legacy redirect path.
 *
 * @package MindfulSEO
 * @since   2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MFSEO_Post_Import_Export {

    const FORMAT_VERSION = '3';

    const NONCE_EXPORT  = 'mfseo_export_posts';
    const NONCE_IMPORT  = 'mfseo_import_posts';
    const ACTION_IMPORT = 'mfseo_import_posts';

    /** @var bool */
    private static $hooks_registered = false;

    /**
     * Feedback from an import handled during this HTTP request (admin_init), for the same
     * request’s render_page() — avoids redirects, user-meta flashes, and object-cache issues.
     *
     * @var array<string,mixed>|null
     */
    private static $import_feedback_for_current_request = null;

    // =========================================================
    // BOOTSTRAP
    // =========================================================

    public static function init() {
        if ( self::$hooks_registered ) {
            return;
        }
        self::$hooks_registered = true;

        // Export: AJAX → generate ZIP → return JSON URL → JS clicks <a download>.
        add_action( 'wp_ajax_mfseo_do_export', array( __CLASS__, 'ajax_export' ) );

        // Import: AJAX → run import → return JSON → JS shows result inline (no redirect).
        add_action( 'wp_ajax_mfseo_do_import', array( __CLASS__, 'ajax_import' ) );

        // Legacy admin-post handler kept for any bookmarked/external POST flows.
        add_action( 'admin_post_' . self::ACTION_IMPORT, array( __CLASS__, 'handle_import' ) );
    }

    // =========================================================
    // EXPORT — AJAX HANDLER  (wp_ajax_mfseo_do_export)
    // =========================================================

    /**
     * AJAX handler: generate the ZIP, return its public URL as JSON.
     *
     * The browser-side JavaScript then triggers a programmatic <a download> click
     * on that URL, letting Apache serve the file directly.
     *
     * Why AJAX instead of a form redirect:
     *  - No PHP streaming (no Content-Disposition headers, no readfile()).
     *  - No wp_safe_redirect() (which Arc on localhost sometimes mishandles).
     *  - Output buffers are cleared before wp_send_json_success() so WP_DEBUG
     *    notices never contaminate the JSON response.
     */
    public static function ajax_export() {
        // Discard any buffered debug output so our JSON response stays clean.
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        check_ajax_referer( 'mfseo_ajax_export', 'mfseo_ajax_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'mindfulseo' ) );
        }

        if ( ! class_exists( 'ZipArchive' ) ) {
            wp_send_json_error( __( 'PHP ZipArchive extension is required.', 'mindfulseo' ) );
        }

        $post_type   = sanitize_text_field( wp_unslash( $_POST['post_type']   ?? 'post' ) );
        $post_status = sanitize_text_field( wp_unslash( $_POST['post_status'] ?? 'publish' ) );
        $limit_raw   = wp_unslash( $_POST['export_limit'] ?? '0' );
        $limit       = $limit_raw === 'other'
            ? max( 1, min( 5000, (int) ( $_POST['export_limit_custom'] ?? 10 ) ) )
            : max( 0, (int) $limit_raw );
        $include_mfseo = ! empty( $_POST['export_mfseo_records'] );

        $types  = ( $post_type === 'any' ) ? array( 'post', 'page' ) : array( $post_type );
        $result = self::generate_export_zip( $types, $post_status, $limit, $include_mfseo );

        if ( is_wp_error( $result ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( 'MindfulSEO export error: ' . $result->get_error_message() );
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array(
            'url'      => $result['url'],
            'filename' => $result['filename'],
        ) );
    }

    // =========================================================
    // IMPORT — AJAX HANDLER  (wp_ajax_mfseo_do_import)
    // =========================================================

    /**
     * AJAX handler: run the import upload, return JSON so JS can display inline feedback.
     *
     * Uses FormData + fetch() from the browser side — no page reload or redirect needed.
     * The existing form nonce (mfseo_import_nonce) is included in the FormData and verified here.
     */
    public static function ajax_import() {
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        check_ajax_referer( self::NONCE_IMPORT, 'mfseo_import_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'no_permission' );
        }

        $result = self::run_import_upload( true );

        if ( ! empty( $result['success'] ) ) {
            wp_send_json_success( array(
                'u' => (int) ( $result['u'] ?? 0 ),
                'c' => (int) ( $result['c'] ?? 0 ),
                's' => (int) ( $result['s'] ?? 0 ),
            ) );
        } else {
            wp_send_json_error( $result['code'] ?? 'unknown' );
        }
    }

    // =========================================================
    // GENERATE EXPORT ZIP
    // =========================================================

    /**
     * Build the ZIP, save it to uploads/mfseo-exports/ (publicly accessible),
     * and return the filepath + direct HTTP URL.
     *
     * The directory is outside uploads/mindfulseo/ (which has Deny from all),
     * so Apache serves it directly with no PHP streaming required.
     *
     * @param  string[] $types         Post types.
     * @param  string   $post_status   'publish'|'draft'|'any'.
     * @param  int      $limit         0 = all.
     * @param  bool     $include_mfseo Whether to include optimisation records.
     * @return array|WP_Error          ['filepath','filename','url'] or WP_Error.
     */
    private static function generate_export_zip( $types, $post_status, $limit, $include_mfseo ) {
        $upload      = wp_upload_dir();
        $exports_dir = $upload['basedir'] . '/mfseo-exports/';
        $exports_url = $upload['baseurl'] . '/mfseo-exports/';

        if ( ! file_exists( $exports_dir ) ) {
            wp_mkdir_p( $exports_dir );
            // Prevent directory listing; individual .zip files remain accessible.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( $exports_dir . '.htaccess', "Options -Indexes\n" );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( $exports_dir . 'index.php', '<?php // Silence is golden' );
        }

        // Clean up stale exports (older than 10 minutes) before creating a new one.
        self::cleanup_old_exports( $exports_dir );

        $rand     = substr( md5( uniqid( '', true ) ), 0, 8 );
        $filename = 'mindfulseo-export-' . gmdate( 'Y-m-d' ) . '-' . $rand . '.zip';
        $filepath = $exports_dir . $filename;

        try {
            $zip = new ZipArchive();
            if ( $zip->open( $filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
                return new WP_Error( 'zip_open', 'ZipArchive::open failed: ' . $filepath );
            }

            $q           = self::get_export_query( $types, $post_status, $limit );
            $export_uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'mfseo_', true );
            $site_url    = home_url( '/' );

            $csv = fopen( 'php://temp', 'r+' );
            fputcsv( $csv, array(
                'mfseo_format_version',
                'export_uuid', 'source_site',
                'post_id', 'post_type', 'post_status', 'slug',
                'title', 'excerpt',
                'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt',
                'comment_status', 'ping_status', 'menu_order',
                'post_parent_slug', 'post_password',
                'author_login', 'author_display_name',
                'categories', 'tags', 'taxonomies_json',
                'permalink',
                'focus_keyword', 'seo_title', 'meta_description', 'seo_plugin',
                'featured_image_alt', 'featured_image_file',
                'mindfulseo_optimized', 'mindfulseo_optimized_date',
                'meta_file', 'content_file', 'mfseo_file',
            ) );

            $adapter    = class_exists( 'MFSEO_SEO_Plugin_Adapter' ) ? MFSEO_SEO_Plugin_Adapter::get_instance() : null;
            $seo_active = $adapter && $adapter->is_seo_plugin_active();

            while ( $q->have_posts() ) {
                $q->the_post();
                $post = get_post();
                if ( ! $post ) {
                    continue;
                }
                $pid = (int) $post->ID;

                $author       = get_user_by( 'id', (int) $post->post_author );
                $author_login = $author ? $author->user_login    : '';
                $author_disp  = $author ? $author->display_name  : '';

                $seo_plugin = $seo_active ? (string) $adapter->get_active_plugin() : 'none';
                $seo        = $seo_active
                    ? $adapter->get_all_seo_meta( $pid )
                    : array( 'keyword' => '', 'title' => '', 'description' => '' );

                $feat_alt = '';
                $feat_rel = '';
                $tid      = get_post_thumbnail_id( $pid );
                if ( $tid ) {
                    $feat_alt = (string) get_post_meta( $tid, '_wp_attachment_image_alt', true );
                    $path     = get_attached_file( $tid );
                    if ( is_string( $path ) && $path !== '' && file_exists( $path ) ) {
                        $ext      = strtolower( preg_replace( '/[^a-z0-9]/i', '', pathinfo( $path, PATHINFO_EXTENSION ) ) );
                        $ext      = $ext ?: 'jpg';
                        $feat_rel = 'images/featured-' . $pid . '.' . $ext;
                        $zip->addFile( $path, $feat_rel );
                    }
                }

                $cats  = wp_get_post_terms( $pid, 'category', array( 'fields' => 'names' ) );
                $tags  = wp_get_post_terms( $pid, 'post_tag',  array( 'fields' => 'names' ) );
                $cat_s = is_array( $cats ) ? implode( '|', $cats ) : '';
                $tag_s = is_array( $tags ) ? implode( '|', $tags ) : '';
                $tax_j = self::build_taxonomies_json( $pid, $post->post_type );

                $parent_slug = '';
                if ( (int) $post->post_parent > 0 ) {
                    $pp = get_post( (int) $post->post_parent );
                    if ( $pp ) {
                        $parent_slug = (string) $pp->post_name;
                    }
                }

                $meta_file = 'meta/' . $pid . '.json';
                $zip->addFromString(
                    $meta_file,
                    (string) wp_json_encode( self::build_meta_export_payload( $pid ), JSON_UNESCAPED_UNICODE )
                );

                $content_file = 'html/' . $pid . '.html';
                $zip->addFromString( $content_file, (string) $post->post_content );

                $mfseo_file = '';
                if ( $include_mfseo ) {
                    $opt_rows   = self::get_mfseo_optimization_rows( $pid );
                    $mfseo_file = 'mfseo/' . $pid . '.json';
                    $zip->addFromString(
                        $mfseo_file,
                        (string) wp_json_encode( $opt_rows, JSON_UNESCAPED_UNICODE )
                    );
                }

                fputcsv( $csv, array(
                    self::FORMAT_VERSION,
                    $export_uuid,
                    $site_url,
                    $pid,
                    $post->post_type,
                    $post->post_status,
                    $post->post_name,
                    $post->post_title,
                    $post->post_excerpt,
                    $post->post_date,
                    $post->post_date_gmt,
                    $post->post_modified,
                    $post->post_modified_gmt,
                    $post->comment_status,
                    $post->ping_status,
                    (int) $post->menu_order,
                    $parent_slug,
                    (string) $post->post_password,
                    $author_login,
                    $author_disp,
                    $cat_s,
                    $tag_s,
                    $tax_j,
                    get_permalink( $pid ),
                    $seo['keyword']     ?? '',
                    $seo['title']       ?? '',
                    $seo['description'] ?? '',
                    $seo_plugin,
                    $feat_alt,
                    $feat_rel,
                    get_post_meta( $pid, '_mindfulseo_optimized', true ) === '1' ? '1' : '0',
                    (string) get_post_meta( $pid, '_mindfulseo_optimized_date', true ),
                    $meta_file,
                    $content_file,
                    $mfseo_file,
                ) );
            }
            wp_reset_postdata();

            rewind( $csv );
            $zip->addFromString( 'manifest.csv', (string) stream_get_contents( $csv ) );
            fclose( $csv );

            $zip->addFromString( 'mfseo-export.json', (string) wp_json_encode( array(
                'format_version' => self::FORMAT_VERSION,
                'plugin_version' => defined( 'MINDFULSEO_VERSION' ) ? MINDFULSEO_VERSION : '2.5.0',
                'export_uuid'    => $export_uuid,
                'source_site'    => $site_url,
                'export_date'    => gmdate( 'c' ),
                'wp_version'     => get_bloginfo( 'version' ),
            ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );

            $zip->close();

            if ( ! file_exists( $filepath ) ) {
                return new WP_Error( 'zip_missing', 'ZIP file missing after ZipArchive::close()' );
            }

            return array(
                'filepath' => $filepath,
                'filename' => $filename,
                'url'      => $exports_url . $filename,
            );

        } catch ( \Throwable $e ) {
            if ( file_exists( $filepath ) ) {
                // phpcs:ignore WordPress.PHP.NoSilencedErrors
                @unlink( $filepath );
            }
            return new WP_Error( 'export_exception', $e->getMessage() );
        }
    }

    /**
     * Delete export ZIPs older than 10 minutes from the exports directory.
     *
     * @param string $dir Absolute path to exports directory.
     */
    private static function cleanup_old_exports( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $cutoff = time() - 600;
        foreach ( glob( $dir . '*.zip' ) ?: array() as $file ) {
            if ( filemtime( $file ) < $cutoff ) {
                // phpcs:ignore WordPress.PHP.NoSilencedErrors
                @unlink( $file );
            }
        }
    }

    // =========================================================
    // RENDER PAGE
    // =========================================================

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission.', 'mindfulseo' ) );
        }

        /*
         * Primary: same-request flag from admin_init (POST to this screen). Fallback: user-meta
         * flash after legacy admin-post redirect; then ?import= query args.
         */
        $fb = null;
        if ( self::$import_feedback_for_current_request !== null ) {
            $fb                                       = self::$import_feedback_for_current_request;
            self::$import_feedback_for_current_request = null;
        }
        if ( $fb === null ) {
            $fb = self::take_import_feedback();
        }
        $import_status = '';
        $import_u      = 0;
        $import_c      = 0;
        $import_s      = 0;
        if ( is_array( $fb ) ) {
            if ( ! empty( $fb['success'] ) ) {
                $import_status = 'done';
                $import_u      = (int) ( $fb['u'] ?? 0 );
                $import_c      = (int) ( $fb['c'] ?? 0 );
                $import_s      = (int) ( $fb['s'] ?? 0 );
            } elseif ( ! empty( $fb['code'] ) ) {
                $import_status = sanitize_key( (string) $fb['code'] );
            }
        }
        if ( $import_status === '' && isset( $_GET['import'] ) ) {
            $import_status = sanitize_key( wp_unslash( $_GET['import'] ) );
            $import_u      = isset( $_GET['u'] ) ? (int) $_GET['u'] : 0;
            $import_c      = isset( $_GET['c'] ) ? (int) $_GET['c'] : 0;
            $import_s      = isset( $_GET['s'] ) ? (int) $_GET['s'] : 0;
        }

        $scroll_import_section = ( $import_status !== '' );

        $error_messages = array(
            'no_file'       => __( 'No file was uploaded. Please choose a ZIP file before clicking "Run Import".', 'mindfulseo' ),
            'upload_err'    => __( 'Upload error. Check your server\'s upload_max_filesize and post_max_size PHP settings.', 'mindfulseo' ),
            'move_failed'   => __( 'Could not save the uploaded file. Check server write permissions on the temp directory.', 'mindfulseo' ),
            'zip_open'      => __( 'Could not open the ZIP archive. Make sure the file is a valid MindfulSEO export (not corrupted or truncated).', 'mindfulseo' ),
            'no_manifest'   => __( 'The ZIP does not contain manifest.csv. Was this file exported from MindfulSEO?', 'mindfulseo' ),
            'bad_csv'       => __( 'manifest.csv is malformed — missing required "slug" column. Please re-export from MindfulSEO.', 'mindfulseo' ),
            'bad_nonce'     => __( 'Security check failed. Please reload the page and try again.', 'mindfulseo' ),
            'no_permission' => __( 'You do not have permission to import content.', 'mindfulseo' ),
            'no_zip'        => __( 'PHP ZipArchive extension is required for import.', 'mindfulseo' ),
        );
        ?>
        <div class="wrap mindfulseo-admin-wrap">
            <h1><?php esc_html_e( 'Import / Export', 'mindfulseo' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Export posts with all SEO fields, taxonomies, custom meta, featured images, HTML content, and MindfulSEO optimisation records as a ZIP. Re-import on any site running MindfulSEO — full round-trip guaranteed.', 'mindfulseo' ); ?></p>

            <!-- ── EXPORT ─────────────────────────────────────────── -->
            <div class="card" style="max-width:680px;padding:20px 24px;margin-top:20px;">
                <h2 style="margin-top:0;"><?php esc_html_e( 'Export', 'mindfulseo' ); ?></h2>
                <p><?php esc_html_e( 'Generates a ZIP and downloads it directly — all post data, SEO fields, featured images, and MindfulSEO optimisation records included.', 'mindfulseo' ); ?></p>

                <form id="mfseo-export-form">
                    <table class="form-table" style="margin-top:0;">
                        <tr>
                            <th scope="row"><label for="mfseo-ex-type"><?php esc_html_e( 'Post type', 'mindfulseo' ); ?></label></th>
                            <td>
                                <select name="post_type" id="mfseo-ex-type">
                                    <option value="post"><?php esc_html_e( 'Posts', 'mindfulseo' ); ?></option>
                                    <option value="page"><?php esc_html_e( 'Pages', 'mindfulseo' ); ?></option>
                                    <option value="any"><?php esc_html_e( 'Posts + Pages', 'mindfulseo' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mfseo-ex-status"><?php esc_html_e( 'Status', 'mindfulseo' ); ?></label></th>
                            <td>
                                <select name="post_status" id="mfseo-ex-status">
                                    <option value="publish"><?php esc_html_e( 'Published', 'mindfulseo' ); ?></option>
                                    <option value="any"><?php esc_html_e( 'Any status', 'mindfulseo' ); ?></option>
                                    <option value="draft"><?php esc_html_e( 'Drafts', 'mindfulseo' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mfseo-ex-limit"><?php esc_html_e( 'Quantity', 'mindfulseo' ); ?></label></th>
                            <td>
                                <select name="export_limit" id="mfseo-ex-limit">
                                    <option value="0"><?php esc_html_e( 'All matching posts', 'mindfulseo' ); ?></option>
                                    <option value="10">10</option>
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                    <option value="200">200</option>
                                    <option value="500">500</option>
                                    <option value="other"><?php esc_html_e( 'Other…', 'mindfulseo' ); ?></option>
                                </select>
                                <span id="mfseo-limit-custom-wrap" style="display:none;margin-left:6px;vertical-align:middle;">
                                    <input type="number" name="export_limit_custom" id="mfseo-limit-custom"
                                           class="small-text" value="10" min="1" max="5000" step="1">
                                </span>
                                <p class="description"><?php esc_html_e( 'Limited exports prioritise MindfulSEO-optimised posts first (newest), then others by modified date.', 'mindfulseo' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Include', 'mindfulseo' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="export_mfseo_records" value="1" checked>
                                    <?php esc_html_e( 'MindfulSEO optimisation records (recommended)', 'mindfulseo' ); ?>
                                </label>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" id="mfseo-export-btn" class="button button-primary">
                            <?php esc_html_e( 'Download ZIP', 'mindfulseo' ); ?>
                        </button>
                        <span id="mfseo-export-status" style="margin-left:10px;color:#666;font-style:italic;"></span>
                    </p>
                </form>
            </div>

            <!-- ── IMPORT ─────────────────────────────────────────── -->
            <div id="mfseo-import-section" class="card" style="max-width:680px;padding:20px 24px;margin-top:20px;scroll-margin-top:46px;">
                <h2 style="margin-top:0;"><?php esc_html_e( 'Import', 'mindfulseo' ); ?></h2>

                <?php if ( $import_status === 'done' ) : ?>
                    <?php
                    $batch_url = admin_url( 'admin.php?page=mindfulseo-batch-optimize' );
                    $posts_url = admin_url( 'edit.php' );
                    ?>
                    <div id="mfseo-import-feedback" class="notice notice-success" style="margin:0 0 18px;padding:12px 14px;border-left-width:4px;" role="status">
                        <p style="margin:0 0 10px;font-size:14px;">
                            <strong><?php esc_html_e( 'Import finished successfully.', 'mindfulseo' ); ?></strong>
                        </p>
                        <p style="margin:0 0 12px;">
                            <?php
                            printf(
                                /* translators: 1: updated count, 2: created count, 3: skipped count */
                                esc_html__( 'Updated: %1$d · Created: %2$d · Skipped: %3$d.', 'mindfulseo' ),
                                $import_u,
                                $import_c,
                                $import_s
                            );
                            ?>
                        </p>
                        <p style="margin:0;">
                            <a href="<?php echo esc_url( $batch_url ); ?>" class="button button-primary"><?php esc_html_e( 'Open Batch Optimizer', 'mindfulseo' ); ?></a>
                            <a href="<?php echo esc_url( $posts_url ); ?>" class="button"><?php esc_html_e( 'All posts', 'mindfulseo' ); ?></a>
                        </p>
                    </div>

                <?php elseif ( $import_status !== '' && isset( $error_messages[ $import_status ] ) ) : ?>
                    <div id="mfseo-import-feedback" class="notice notice-error" style="margin:0 0 18px;padding:12px 14px;border-left-width:4px;" role="alert">
                        <p style="margin:0;font-size:14px;"><strong><?php esc_html_e( 'Import could not complete.', 'mindfulseo' ); ?></strong></p>
                        <p style="margin:8px 0 0;"><?php echo esc_html( $error_messages[ $import_status ] ); ?></p>
                    </div>

                <?php elseif ( $import_status !== '' ) : ?>
                    <div id="mfseo-import-feedback" class="notice notice-error" style="margin:0 0 18px;padding:12px 14px;border-left-width:4px;" role="alert">
                        <p style="margin:0;font-size:14px;"><strong><?php esc_html_e( 'Import could not complete.', 'mindfulseo' ); ?></strong></p>
                        <p style="margin:8px 0 0;"><?php esc_html_e( 'Check the PHP error log for details.', 'mindfulseo' ); ?></p>
                    </div>
                <?php endif; ?>

                <p><?php esc_html_e( 'Upload a ZIP exported by MindfulSEO. Posts are matched by slug + post type.', 'mindfulseo' ); ?></p>

                <form id="mfseo-import-form" method="post"
                      action="<?php echo esc_url( admin_url( 'admin.php?page=mindfulseo-import-export' ) ); ?>"
                      enctype="multipart/form-data">
                    <?php wp_nonce_field( self::NONCE_IMPORT, 'mfseo_import_nonce' ); ?>
                    <input type="hidden" name="mfseo_ie_process_import" value="1">

                    <table class="form-table" style="margin-top:0;">
                        <tr>
                            <th scope="row"><label for="mfseo-import-zip"><?php esc_html_e( 'ZIP file', 'mindfulseo' ); ?></label></th>
                            <td>
                                <input type="file" name="import_zip" id="mfseo-import-zip"
                                       accept=".zip,application/zip,application/x-zip-compressed" required>
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: %s: human-readable upload size limit */
                                        esc_html__( 'Max upload size: %s', 'mindfulseo' ),
                                        esc_html( size_format( wp_max_upload_size() ) )
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Existing posts', 'mindfulseo' ); ?></th>
                            <td>
                                <fieldset>
                                    <label><input type="radio" name="import_record_mode" value="upsert" checked>
                                        <?php esc_html_e( 'Create new + update existing (full merge)', 'mindfulseo' ); ?></label><br>
                                    <label><input type="radio" name="import_record_mode" value="create_only">
                                        <?php esc_html_e( 'Create new posts only (skip existing slugs)', 'mindfulseo' ); ?></label><br>
                                    <label><input type="radio" name="import_record_mode" value="update_existing">
                                        <?php esc_html_e( 'Update existing posts only (skip unknown slugs)', 'mindfulseo' ); ?></label>
                                </fieldset>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Apply from ZIP', 'mindfulseo' ); ?></th>
                            <td>
                                <label><input type="checkbox" name="import_content"  value="1" checked> <?php esc_html_e( 'Post content (HTML body)', 'mindfulseo' ); ?></label><br>
                                <label><input type="checkbox" name="import_seo"      value="1" checked> <?php esc_html_e( 'SEO title, meta description, focus keyword', 'mindfulseo' ); ?></label><br>
                                <label><input type="checkbox" name="import_featured" value="1" checked> <?php esc_html_e( 'Featured image', 'mindfulseo' ); ?></label><br>
                                <label><input type="checkbox" name="import_attrs"    value="1" checked> <?php esc_html_e( 'Status, dates, author, excerpt, parent, password, menu order', 'mindfulseo' ); ?></label><br>
                                <label><input type="checkbox" name="import_tax"      value="1" checked> <?php esc_html_e( 'Categories, tags and all other taxonomies (creates terms if missing)', 'mindfulseo' ); ?></label><br>
                                <label><input type="checkbox" name="import_meta"     value="1" checked> <?php esc_html_e( 'All custom fields / post meta (SEO scores excluded)', 'mindfulseo' ); ?></label><br>
                                <label><input type="checkbox" name="import_mfseo"    value="1" checked> <?php esc_html_e( 'MindfulSEO optimisation records', 'mindfulseo' ); ?></label>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button( __( 'Run Import', 'mindfulseo' ), 'primary', 'submit', true, array( 'id' => 'mfseo-import-btn' ) ); ?>
                    <p id="mfseo-import-js-note" style="margin:4px 0 0;font-size:12px;color:#999;display:none;">
                        <?php esc_html_e( 'AJAX mode active — result shown inline.', 'mindfulseo' ); ?>
                    </p>
                </form>
            </div>
        </div>

        <script>
        (function () {

            /* ── Quantity "Other…" toggle ── */
            document.addEventListener('DOMContentLoaded', function () {
                var sel  = document.getElementById('mfseo-ex-limit');
                var wrap = document.getElementById('mfseo-limit-custom-wrap');
                var inp  = document.getElementById('mfseo-limit-custom');
                if (sel && wrap && inp) {
                    function syncLimit() {
                        var show = sel.value === 'other';
                        wrap.style.display = show ? 'inline-block' : 'none';
                        inp.required = show;
                    }
                    sel.addEventListener('change', syncLimit);
                    syncLimit();
                }
            });

            /* ── Export via AJAX → <a download> ── */
            var exportForm   = document.getElementById('mfseo-export-form');
            var exportBtn    = document.getElementById('mfseo-export-btn');
            var exportStatus = document.getElementById('mfseo-export-status');
            var ajaxUrl      = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var exportNonce  = <?php echo wp_json_encode( wp_create_nonce( 'mfseo_ajax_export' ) ); ?>;

            if (exportForm && exportBtn) {
                exportForm.addEventListener('submit', function (e) {
                    e.preventDefault();

                    exportBtn.disabled = true;
                    exportBtn.textContent = <?php echo wp_json_encode( __( 'Generating ZIP…', 'mindfulseo' ) ); ?>;
                    exportStatus.textContent = '';
                    exportStatus.style.color = '#666';

                    var fd = new FormData(exportForm);
                    fd.append('action',          'mfseo_do_export');
                    fd.append('mfseo_ajax_nonce', exportNonce);

                    fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(function (r) { return r.text(); })
                        .then(function (text) {
                            /* Extract JSON even if WP_DEBUG prepended notices */
                            var start = text.indexOf('{"success"');
                            if (start === -1) start = text.indexOf('{"error"');
                            var json;
                            try {
                                json = JSON.parse(start >= 0 ? text.slice(start) : text);
                            } catch (_) {
                                throw new Error('Bad response: ' + text.slice(0, 300));
                            }
                            return json;
                        })
                        .then(function (resp) {
                            exportBtn.disabled = false;
                            exportBtn.textContent = <?php echo wp_json_encode( __( 'Download ZIP', 'mindfulseo' ) ); ?>;

                            if (!resp.success) {
                                exportStatus.textContent = 'Error: ' + (resp.data || 'Export failed');
                                exportStatus.style.color = '#d63638';
                                return;
                            }

                            exportStatus.textContent = <?php echo wp_json_encode( __( 'Done! Starting download…', 'mindfulseo' ) ); ?>;
                            exportStatus.style.color = '#00a32a';

                            /* Trigger browser download — Apache serves the ZIP directly */
                            var a = document.createElement('a');
                            a.href     = resp.data.url;
                            a.download = resp.data.filename;
                            document.body.appendChild(a);
                            a.click();
                            document.body.removeChild(a);

                            setTimeout(function () { exportStatus.textContent = ''; }, 5000);
                        })
                        .catch(function (err) {
                            exportBtn.disabled = false;
                            exportBtn.textContent = <?php echo wp_json_encode( __( 'Download ZIP', 'mindfulseo' ) ); ?>;
                            exportStatus.textContent = 'Error: ' + err.message;
                            exportStatus.style.color = '#d63638';
                        });
                });
            }

            /* ── Import via AJAX → inline result (no page reload) ── */
            document.addEventListener('DOMContentLoaded', function () {
                var importForm = document.getElementById('mfseo-import-form');
                var importBtn  = document.getElementById('mfseo-import-btn');
                var importSec  = document.getElementById('mfseo-import-section');
                var importNote = document.getElementById('mfseo-import-js-note');

                var importErrorMessages = <?php echo wp_json_encode( $error_messages ); ?>;

                function showImportFeedback(html, isSuccess) {
                    var existing = document.getElementById('mfseo-import-feedback');
                    if (existing) { existing.parentNode.removeChild(existing); }

                    var div = document.createElement('div');
                    div.id = 'mfseo-import-feedback';
                    div.className = 'notice ' + (isSuccess ? 'notice-success' : 'notice-error');
                    div.setAttribute('role', isSuccess ? 'status' : 'alert');
                    div.style.cssText = 'margin:0 0 18px;padding:12px 14px;border-left-width:4px;';
                    div.innerHTML = html;

                    /* Insert before the form, or fall back to appending inside the section */
                    if (importForm && importForm.parentNode) {
                        importForm.parentNode.insertBefore(div, importForm);
                    } else if (importSec) {
                        importSec.appendChild(div);
                    } else {
                        document.body.appendChild(div);
                    }

                    var target = importSec || div;
                    setTimeout(function () {
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }, 80);
                }

                if (!importForm) {
                    /* Form not found — skip AJAX; page will fall back to normal POST */
                    return;
                }

                /* Show the "AJAX mode active" note so we know JS reached this point */
                if (importNote) { importNote.style.display = 'block'; }

                importForm.addEventListener('submit', function (e) {
                    e.preventDefault();

                    var fileInput = document.getElementById('mfseo-import-zip');
                    if (!fileInput || !fileInput.files || !fileInput.files.length) {
                        showImportFeedback(
                            '<p style="margin:0;font-size:14px;"><strong><?php echo esc_js( __( 'Please choose a ZIP file first.', 'mindfulseo' ) ); ?></strong></p>',
                            false
                        );
                        return;
                    }

                    if (importBtn) {
                        importBtn.disabled = true;
                        importBtn.value = <?php echo wp_json_encode( __( 'Importing…', 'mindfulseo' ) ); ?>;
                    }

                    var existing = document.getElementById('mfseo-import-feedback');
                    if (existing) { existing.parentNode.removeChild(existing); }

                    var fd = new FormData(importForm);
                    fd.set('action', 'mfseo_do_import');

                    fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin'
                    })
                    .then(function (r) { return r.text(); })
                    .then(function (text) {
                        /* Strip any debug output before the JSON */
                        var start = text.indexOf('{"success"');
                        if (start === -1) { start = text.indexOf('{"error"'); }
                        try {
                            return JSON.parse(start >= 0 ? text.slice(start) : text);
                        } catch (_) {
                            throw new Error('Server returned unexpected response:\n' + text.slice(0, 400));
                        }
                    })
                    .then(function (resp) {
                        if (importBtn) {
                            importBtn.disabled = false;
                            importBtn.value = <?php echo wp_json_encode( __( 'Run Import', 'mindfulseo' ) ); ?>;
                        }

                        if (resp.success) {
                            var d = resp.data;
                            var batchUrl = <?php echo wp_json_encode( admin_url( 'admin.php?page=mindfulseo-batch-optimize' ) ); ?>;
                            var postsUrl = <?php echo wp_json_encode( admin_url( 'edit.php' ) ); ?>;
                            showImportFeedback(
                                '<p style="margin:0 0 10px;font-size:14px;"><strong><?php echo esc_js( __( 'Import finished successfully.', 'mindfulseo' ) ); ?></strong></p>' +
                                '<p style="margin:0 0 12px;"><?php echo esc_js( __( 'Updated:', 'mindfulseo' ) ); ?> ' + d.u + ' &middot; <?php echo esc_js( __( 'Created:', 'mindfulseo' ) ); ?> ' + d.c + ' &middot; <?php echo esc_js( __( 'Skipped:', 'mindfulseo' ) ); ?> ' + d.s + '</p>' +
                                '<p style="margin:0;"><a href="' + batchUrl + '" class="button button-primary"><?php echo esc_js( __( 'Open Batch Optimizer', 'mindfulseo' ) ); ?></a> ' +
                                '<a href="' + postsUrl + '" class="button"><?php echo esc_js( __( 'All posts', 'mindfulseo' ) ); ?></a></p>',
                                true
                            );
                        } else {
                            var code = typeof resp.data === 'string' ? resp.data : 'unknown';
                            var msg  = importErrorMessages[code] || ('Import failed (code: ' + code + '). Check the PHP error log.');
                            showImportFeedback(
                                '<p style="margin:0;font-size:14px;"><strong><?php echo esc_js( __( 'Import could not complete.', 'mindfulseo' ) ); ?></strong></p>' +
                                '<p style="margin:8px 0 0;">' + msg + '</p>',
                                false
                            );
                        }
                    })
                    .catch(function (err) {
                        if (importBtn) {
                            importBtn.disabled = false;
                            importBtn.value = <?php echo wp_json_encode( __( 'Run Import', 'mindfulseo' ) ); ?>;
                        }
                        showImportFeedback(
                            '<p style="margin:0;font-size:14px;"><strong><?php echo esc_js( __( 'Import could not complete.', 'mindfulseo' ) ); ?></strong></p>' +
                            '<p style="margin:8px 0 0;">' + err.message + '</p>',
                            false
                        );
                    });
                });
            });
        })();
        </script>
        <?php
    }

    // =========================================================
    // IMPORT
    // =========================================================

    /**
     * Run import during admin_init when the Import form POSTs to admin.php?page=….
     *
     * Feedback is read later in render_page() from self::$import_feedback_for_current_request.
     */
    public static function maybe_handle_import_on_admin_init() {
        if ( ! is_admin() || wp_doing_ajax() ) {
            return;
        }
        if ( empty( $_POST['mfseo_ie_process_import'] ) ) {
            return;
        }
        $approved_pages = array( 'mindfulseo-import-export' );
        $page           = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        if ( ! in_array( $page, $approved_pages, true ) ) {
            return;
        }
        self::$import_feedback_for_current_request = self::run_import_upload();
    }

    /**
     * Legacy: admin-post.php → redirect back with user-meta flash.
     */
    public static function handle_import() {
        self::redirect_import_page_with_feedback( self::run_import_upload() );
    }

    /**
     * @param bool $nonce_already_verified Set true when the caller has already called
     *                                      check_ajax_referer()/check_admin_referer().
     * @return array{success:bool,u?:int,c?:int,s?:int,code?:string}
     */
    private static function run_import_upload( bool $nonce_already_verified = false ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return array( 'success' => false, 'code' => 'no_permission' );
        }

        if ( ! $nonce_already_verified ) {
            check_admin_referer( self::NONCE_IMPORT, 'mfseo_import_nonce' );
        }

        if ( empty( $_FILES['import_zip']['tmp_name'] ) ) {
            return array( 'success' => false, 'code' => 'no_file' );
        }
        $file = $_FILES['import_zip'];
        if ( ! empty( $file['error'] ) ) {
            return array( 'success' => false, 'code' => 'upload_err' );
        }

        if ( ! class_exists( 'ZipArchive' ) ) {
            return array( 'success' => false, 'code' => 'no_zip' );
        }

        $mode = sanitize_key( wp_unslash( $_POST['import_record_mode'] ?? 'upsert' ) );
        if ( ! in_array( $mode, array( 'upsert', 'create_only', 'update_existing' ), true ) ) {
            $mode = 'upsert';
        }
        $do_create          = in_array( $mode, array( 'upsert', 'create_only' ), true );
        $do_update_existing = in_array( $mode, array( 'upsert', 'update_existing' ), true );
        $do_content         = ! empty( $_POST['import_content'] );
        $do_seo             = ! empty( $_POST['import_seo'] );
        $do_featured        = ! empty( $_POST['import_featured'] );
        $do_attrs           = ! empty( $_POST['import_attrs'] );
        $do_tax             = ! empty( $_POST['import_tax'] );
        $do_meta            = ! empty( $_POST['import_meta'] );
        $do_mfseo           = ! empty( $_POST['import_mfseo'] );

        $persisted = wp_tempnam( 'mfseo-import' );
        if ( ! is_string( $persisted ) || ! @move_uploaded_file( $file['tmp_name'], $persisted ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
            return array( 'success' => false, 'code' => 'move_failed' );
        }

        $zip = new ZipArchive();
        if ( $zip->open( $persisted ) !== true ) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors
            @unlink( $persisted );
            return array( 'success' => false, 'code' => 'zip_open' );
        }

        $manifest_idx = $zip->locateName( 'manifest.csv' );
        if ( $manifest_idx === false ) {
            $zip->close();
            // phpcs:ignore WordPress.PHP.NoSilencedErrors
            @unlink( $persisted );
            return array( 'success' => false, 'code' => 'no_manifest' );
        }

        $fh = fopen( 'php://memory', 'r+' );
        fwrite( $fh, (string) $zip->getFromIndex( $manifest_idx ) );
        rewind( $fh );
        $header = fgetcsv( $fh );

        if ( ! $header || ! in_array( 'slug', $header, true ) ) {
            fclose( $fh );
            $zip->close();
            // phpcs:ignore WordPress.PHP.NoSilencedErrors
            @unlink( $persisted );
            return array( 'success' => false, 'code' => 'bad_csv' );
        }

        $col  = array_flip( $header );
        $hlen = count( $header );
        $rows = array();
        while ( ( $data = fgetcsv( $fh ) ) !== false ) {
            if ( count( $data ) < $hlen ) {
                $data = array_pad( $data, $hlen, '' );
            }
            $rows[] = $data;
        }
        fclose( $fh );

        $adapter = class_exists( 'MFSEO_SEO_Plugin_Adapter' ) ? MFSEO_SEO_Plugin_Adapter::get_instance() : null;
        $created = 0;
        $updated = 0;
        $skipped = 0;

        $queue = $rows;
        $pass  = 0;
        while ( ! empty( $queue ) && $pass < 200 ) {
            $next = array();
            foreach ( $queue as $data ) {
                $ctx = self::build_row_ctx(
                    $data, $col, $zip, $adapter,
                    $do_create, $do_update_existing,
                    $do_content, $do_seo, $do_featured,
                    $do_attrs, $do_tax, $do_meta, $do_mfseo
                );
                $ctx['force_parent_zero'] = false;
                $r = self::import_process_row( $ctx );
                if ( $r === 'retry' ) {
                    $next[] = $data;
                    continue;
                }
                if ( is_array( $r ) ) {
                    $created += $r[0];
                    $updated += $r[1];
                    $skipped += $r[2];
                }
            }
            if ( count( $next ) === count( $queue ) ) {
                foreach ( $next as $data ) {
                    $ctx = self::build_row_ctx(
                        $data, $col, $zip, $adapter,
                        $do_create, $do_update_existing,
                        $do_content, $do_seo, $do_featured,
                        $do_attrs, $do_tax, $do_meta, $do_mfseo
                    );
                    $ctx['force_parent_zero'] = true;
                    $r = self::import_process_row( $ctx );
                    if ( is_array( $r ) ) {
                        $created += $r[0];
                        $updated += $r[1];
                        $skipped += $r[2];
                    }
                }
                break;
            }
            $queue = $next;
            ++$pass;
        }

        $zip->close();
        // phpcs:ignore WordPress.PHP.NoSilencedErrors
        @unlink( $persisted );

        return array(
            'success' => true,
            'u'       => $updated,
            'c'       => $created,
            's'       => $skipped,
        );
    }

    // =========================================================
    // ROW PROCESSING
    // =========================================================

    private static function build_row_ctx(
        $data, $col, $zip, $adapter,
        $do_create, $do_update_existing,
        $do_content, $do_seo, $do_featured,
        $do_attrs, $do_tax, $do_meta, $do_mfseo
    ) {
        return array(
            'data'               => $data,
            'col'                => $col,
            'zip'                => $zip,
            'adapter'            => $adapter,
            'do_create'          => $do_create,
            'do_update_existing' => $do_update_existing,
            'do_content'         => $do_content,
            'do_seo'             => $do_seo,
            'do_featured'        => $do_featured,
            'do_attrs'           => $do_attrs,
            'do_tax'             => $do_tax,
            'do_meta'            => $do_meta,
            'do_mfseo'           => $do_mfseo,
            'create_terms'       => true,
            'force_parent_zero'  => false,
        );
    }

    /**
     * @param  array $ctx
     * @return string|int[]  'retry' or [created, updated, skipped]
     */
    private static function import_process_row( array $ctx ) {
        $data               = $ctx['data'];
        $col                = $ctx['col'];
        $zip                = $ctx['zip'];
        $adapter            = $ctx['adapter'];
        $do_create          = $ctx['do_create'];
        $do_update_existing = $ctx['do_update_existing'];
        $do_content         = $ctx['do_content'];
        $do_seo             = $ctx['do_seo'];
        $do_featured        = $ctx['do_featured'];
        $do_attrs           = $ctx['do_attrs'];
        $do_tax             = $ctx['do_tax'];
        $do_meta            = $ctx['do_meta'];
        $do_mfseo           = $ctx['do_mfseo'];
        $create_terms       = $ctx['create_terms'];
        $force_parent_zero  = $ctx['force_parent_zero'];

        $slug  = isset( $col['slug'] )      ? sanitize_title( $data[ $col['slug'] ] )    : '';
        $ptype = isset( $col['post_type'] ) ? sanitize_key( $data[ $col['post_type'] ] ) : 'post';
        if ( $slug === '' || ! post_type_exists( $ptype ) ) {
            return array( 0, 0, 1 );
        }

        $existing = get_posts( array(
            'name'           => $slug,
            'post_type'      => $ptype,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ) );
        $post_id = $existing ? (int) $existing[0] : 0;

        if ( $post_id && ! $do_update_existing ) {
            return array( 0, 0, 1 );
        }
        if ( ! $post_id && ! $do_create ) {
            return array( 0, 0, 1 );
        }

        $parent_slug_raw = ( $do_attrs && isset( $col['post_parent_slug'] ) )
            ? trim( (string) $data[ $col['post_parent_slug'] ] )
            : '';
        $parent_slug = $parent_slug_raw !== '' ? sanitize_title( $parent_slug_raw ) : '';
        $parent_id   = 0;
        if ( $do_attrs && $parent_slug !== '' && is_post_type_hierarchical( $ptype ) ) {
            if ( $force_parent_zero ) {
                $parent_id = 0;
            } else {
                $parent_id = self::resolve_parent_post_id( $parent_slug, $ptype );
                if ( $parent_id === 0 ) {
                    return 'retry';
                }
            }
        }

        $author_id = get_current_user_id();
        if ( $do_attrs && isset( $col['author_login'] ) && $data[ $col['author_login'] ] !== '' ) {
            $u = get_user_by( 'login', sanitize_user( (string) $data[ $col['author_login'] ], true ) );
            if ( $u ) {
                $author_id = (int) $u->ID;
            }
        }

        $html = '';
        if ( $do_content && isset( $col['content_file'] ) ) {
            $cf = ltrim( (string) $data[ $col['content_file'] ], '/' );
            $z  = $zip->getFromName( $cf );
            if ( $z === false ) {
                $z = $zip->getFromName( str_replace( '\\', '/', $cf ) );
            }
            $html = ( $z !== false ) ? $z : '';
        }

        $seo_data = array();
        if ( $do_seo ) {
            $map = array(
                'focus_keyword'    => 'keyword',
                'seo_title'        => 'title',
                'meta_description' => 'description',
            );
            foreach ( $map as $col_key => $seo_key ) {
                if ( isset( $col[ $col_key ] ) ) {
                    $raw = (string) $data[ $col[ $col_key ] ];
                    $seo_data[ $seo_key ] = ( $col_key === 'meta_description' )
                        ? sanitize_textarea_field( $raw )
                        : sanitize_text_field( $raw );
                }
            }
        }

        $excerpt_val = isset( $col['excerpt'] ) ? (string) $data[ $col['excerpt'] ] : '';
        $row_changed = false;
        $created_inc = 0;

        if ( ! $post_id ) {
            $status_for_create = 'draft';
            if ( $do_attrs && isset( $col['post_status'] ) && $data[ $col['post_status'] ] !== '' ) {
                $status_for_create = sanitize_key( (string) $data[ $col['post_status'] ] );
            }
            $title = isset( $col['title'] ) ? sanitize_text_field( $data[ $col['title'] ] ) : $slug;
            $ins   = array(
                'post_title'   => $title,
                'post_name'    => $slug,
                'post_type'    => $ptype,
                'post_status'  => $status_for_create,
                'post_content' => $do_content ? $html : '',
                'post_author'  => $author_id,
            );
            if ( $do_attrs ) {
                if ( $excerpt_val !== '' ) {
                    $ins['post_excerpt'] = sanitize_textarea_field( $excerpt_val );
                }
                foreach ( array( 'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt' ) as $df ) {
                    if ( isset( $col[ $df ] ) && $data[ $col[ $df ] ] !== '' ) {
                        $ins[ $df ] = sanitize_text_field( (string) $data[ $col[ $df ] ] );
                    }
                }
                foreach ( array( 'comment_status', 'ping_status' ) as $sf ) {
                    if ( isset( $col[ $sf ] ) ) {
                        $ins[ $sf ] = sanitize_key( (string) $data[ $col[ $sf ] ] );
                    }
                }
                if ( isset( $col['menu_order'] ) ) {
                    $ins['menu_order'] = (int) $data[ $col['menu_order'] ];
                }
                if ( $parent_id > 0 ) {
                    $ins['post_parent'] = $parent_id;
                }
                if ( isset( $col['post_password'] ) ) {
                    $ins['post_password'] = (string) $data[ $col['post_password'] ];
                }
            }
            $post_id = wp_insert_post( wp_slash( $ins ), true );
            if ( is_wp_error( $post_id ) || ! $post_id ) {
                return array( 0, 0, 1 );
            }
            $created_inc = 1;
            $row_changed = true;
        } else {
            $args = array( 'ID' => $post_id );
            if ( $do_content && $html !== '' ) {
                $args['post_content'] = $html;
                $row_changed          = true;
            }
            if ( $do_content && $excerpt_val !== '' ) {
                $args['post_excerpt'] = sanitize_textarea_field( $excerpt_val );
                $row_changed          = true;
            }
            if ( $do_attrs ) {
                if ( isset( $col['post_status'] ) && $data[ $col['post_status'] ] !== '' ) {
                    $args['post_status'] = sanitize_key( (string) $data[ $col['post_status'] ] );
                    $row_changed         = true;
                }
                foreach ( array( 'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt' ) as $df ) {
                    if ( isset( $col[ $df ] ) ) {
                        $args[ $df ] = sanitize_text_field( (string) $data[ $col[ $df ] ] );
                        $row_changed = true;
                    }
                }
                foreach ( array( 'comment_status', 'ping_status' ) as $sf ) {
                    if ( isset( $col[ $sf ] ) ) {
                        $args[ $sf ] = sanitize_key( (string) $data[ $col[ $sf ] ] );
                        $row_changed = true;
                    }
                }
                if ( isset( $col['menu_order'] ) ) {
                    $args['menu_order'] = (int) $data[ $col['menu_order'] ];
                    $row_changed        = true;
                }
                if ( is_post_type_hierarchical( $ptype ) && $parent_slug_raw !== '' ) {
                    $args['post_parent'] = $parent_id;
                    $row_changed         = true;
                }
                if ( isset( $col['post_password'] ) ) {
                    $args['post_password'] = (string) $data[ $col['post_password'] ];
                    $row_changed           = true;
                }
                $args['post_author'] = $author_id;
                $row_changed         = true;
            }
            if ( count( $args ) > 1 ) {
                wp_update_post( wp_slash( $args ) );
            }
        }

        if ( $do_tax ) {
            $did_tax = false;
            if ( isset( $col['taxonomies_json'] ) && $data[ $col['taxonomies_json'] ] !== '' ) {
                $tmap = json_decode( (string) $data[ $col['taxonomies_json'] ], true );
                if ( is_array( $tmap ) ) {
                    foreach ( $tmap as $tax => $slugs ) {
                        if ( is_string( $tax ) && taxonomy_exists( $tax ) && is_array( $slugs ) ) {
                            self::import_set_terms( $post_id, $tax, $slugs, $create_terms );
                            $did_tax = true;
                        }
                    }
                }
            }
            if ( ! $did_tax ) {
                if ( isset( $col['categories'] ) && $data[ $col['categories'] ] !== '' ) {
                    self::import_set_terms_from_pipe_names( $post_id, 'category', (string) $data[ $col['categories'] ], $create_terms );
                    $did_tax = true;
                }
                if ( isset( $col['tags'] ) && $data[ $col['tags'] ] !== '' ) {
                    self::import_set_terms_from_pipe_names( $post_id, 'post_tag', (string) $data[ $col['tags'] ], $create_terms );
                    $did_tax = true;
                }
            }
            if ( $did_tax ) {
                $row_changed = true;
            }
        }

        if ( $do_featured && isset( $col['featured_image_file'] ) ) {
            $feat_rel = trim( (string) $data[ $col['featured_image_file'] ] );
            if ( $feat_rel !== '' ) {
                $feat_alt = isset( $col['featured_image_alt'] )
                    ? sanitize_text_field( $data[ $col['featured_image_alt'] ] )
                    : '';
                if ( self::import_featured_from_zip( $zip, $post_id, $feat_rel, $feat_alt ) ) {
                    $row_changed = true;
                }
            }
        }

        if ( $do_meta && isset( $col['meta_file'] ) ) {
            $mf  = ltrim( (string) $data[ $col['meta_file'] ], '/' );
            $raw = $mf !== '' ? $zip->getFromName( $mf ) : false;
            if ( $raw === false && $mf !== '' ) {
                $raw = $zip->getFromName( str_replace( '\\', '/', $mf ) );
            }
            if ( $raw !== false && $raw !== '' ) {
                $payload = json_decode( $raw, true );
                if ( is_array( $payload ) ) {
                    self::import_apply_meta_payload( $post_id, $payload );
                    $row_changed = true;
                }
            }
        }

        $mfseo_rows_for_seo = null;
        if ( $do_mfseo && isset( $col['mfseo_file'] ) ) {
            $mf = trim( (string) $data[ $col['mfseo_file'] ] );
            if ( $mf !== '' ) {
                $mf  = ltrim( $mf, '/' );
                $raw = $zip->getFromName( $mf );
                if ( $raw === false ) {
                    $raw = $zip->getFromName( str_replace( '\\', '/', $mf ) );
                }
                if ( $raw !== false && $raw !== '' ) {
                    $opt_rows = json_decode( $raw, true );
                    if ( is_array( $opt_rows ) ) {
                        self::import_mfseo_records( $post_id, $opt_rows );
                        $mfseo_rows_for_seo = $opt_rows;
                        $row_changed = true;
                    }
                }
            }
        }

        /*
         * SEO in the manifest often comes from Rank Math / Yoast post meta. Many sites only
         * store keyword/title/description inside MindfulSEO optimization rows until "Apply"
         * is run — so CSV columns can be empty while mfseo/*.json has the real data.
         * Merge mfseo/*.json whenever that package was imported (even if "SEO" CSV import
         * was unchecked) so post meta and Batch Optimizer stay in sync.
         */
        $mfseo_had_rows = is_array( $mfseo_rows_for_seo ) && ! empty( $mfseo_rows_for_seo );
        if ( $adapter && $mfseo_had_rows ) {
            $base_for_merge = $do_seo ? $seo_data : array();
            $seo_data       = self::merge_seo_data_from_mfseo_rows( $base_for_merge, $mfseo_rows_for_seo );
        }

        $seo_nonempty = (
            ( isset( $seo_data['keyword'] ) && $seo_data['keyword'] !== '' )
            || ( isset( $seo_data['title'] ) && $seo_data['title'] !== '' )
            || ( isset( $seo_data['description'] ) && $seo_data['description'] !== '' )
        );

        if ( $adapter && $seo_nonempty && ( $do_seo || $mfseo_had_rows ) ) {
            $adapter->set_all_seo_meta( $post_id, $seo_data );
            $row_changed = true;
        }

        if ( $created_inc ) {
            return array( 1, 0, 0 );
        }
        return $row_changed ? array( 0, 1, 0 ) : array( 0, 0, 1 );
    }

    // =========================================================
    // PRIVATE HELPERS
    // =========================================================

    /**
     * User meta key for one-shot import feedback (survives redirect URL stripping and object cache).
     *
     * @return string
     */
    private static function import_feedback_meta_key() {
        return '_mfseo_import_feedback_flash';
    }

    /**
     * @return array<string,mixed>|null Payload or null.
     */
    private static function take_import_feedback() {
        $uid = (int) get_current_user_id();
        if ( $uid < 1 ) {
            return null;
        }
        $raw = get_user_meta( $uid, self::import_feedback_meta_key(), true );
        if ( ! is_array( $raw ) ) {
            return null;
        }
        delete_user_meta( $uid, self::import_feedback_meta_key() );
        return $raw;
    }

    /**
     * Store feedback and redirect to the Import / Export screen (no fragile query args).
     *
     * @param array $data { success: bool, u?: int, c?: int, s?: int, code?: string }
     */
    private static function redirect_import_page_with_feedback( array $data ) {
        $uid = (int) get_current_user_id();
        if ( $uid > 0 ) {
            update_user_meta( $uid, self::import_feedback_meta_key(), $data );
        }
        $base = admin_url( 'admin.php?page=mindfulseo-import-export' );
        wp_safe_redirect( $base . '#mfseo-import-section' );
        exit;
    }

    /**
     * Fill empty SEO fields from MindfulSEO optimization export rows (mfseo/{id}.json).
     * Rows are ordered newest-first in export; first non-empty value wins per field.
     *
     * @param array $seo_data Existing keyword/title/description from manifest.csv.
     * @param array $rows     List of DB-style optimization rows.
     * @return array{keyword?:string,title?:string,description?:string}
     */
    private static function merge_seo_data_from_mfseo_rows( array $seo_data, array $rows ) {
        $out = array(
            'keyword'     => isset( $seo_data['keyword'] ) ? (string) $seo_data['keyword'] : '',
            'title'       => isset( $seo_data['title'] ) ? (string) $seo_data['title'] : '',
            'description' => isset( $seo_data['description'] ) ? (string) $seo_data['description'] : '',
        );

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            if ( $out['keyword'] === '' && ! empty( $row['primary_keyword'] ) ) {
                $out['keyword'] = sanitize_text_field( (string) $row['primary_keyword'] );
            }
            if ( $out['title'] === '' && ! empty( $row['seo_title'] ) ) {
                $out['title'] = sanitize_text_field( (string) $row['seo_title'] );
            }
            if ( $out['description'] === '' && ! empty( $row['meta_description'] ) ) {
                $out['description'] = sanitize_textarea_field( (string) $row['meta_description'] );
            }
            if ( $out['keyword'] !== '' && $out['title'] !== '' && $out['description'] !== '' ) {
                break;
            }
        }

        return $out;
    }


    private static function meta_key_is_blocked( $key ) {
        if ( ! is_string( $key ) || $key === '' ) {
            return true;
        }
        static $exact = array(
            '_edit_lock'                => true,
            '_edit_last'                => true,
            '_revision-meta'            => true,
            '_revision-meta-v2'         => true,
            '_thumbnail_id'             => true,
            'yoast_wpseo_linkdex'       => true,
            'yoast_wpseo_content_score' => true,
        );
        if ( isset( $exact[ $key ] ) ) {
            return true;
        }
        foreach ( array( 'rank_math_seo_score', 'rank_math_content_ai', 'rank_math_ca_keyword_score' ) as $frag ) {
            if ( strpos( $key, $frag ) !== false ) {
                return true;
            }
        }
        if ( preg_match( '/_score$/', $key )
            && (
                strpos( $key, 'rank_math' ) !== false
                || strpos( $key, 'yoast' ) !== false
                || strpos( $key, 'wpseo' ) !== false
            )
        ) {
            return true;
        }
        return false;
    }

    /** @return array<string, mixed[]> */
    private static function build_meta_export_payload( $post_id ) {
        $all = get_post_meta( (int) $post_id );
        $out = array();
        if ( ! is_array( $all ) ) {
            return $out;
        }
        foreach ( $all as $mk => $rows ) {
            if ( self::meta_key_is_blocked( $mk ) || ! is_array( $rows ) ) {
                continue;
            }
            $out[ $mk ] = array_values( $rows );
        }
        return $out;
    }

    /** @return string JSON */
    private static function build_taxonomies_json( $post_id, $post_type ) {
        $map = array();
        foreach ( (array) get_object_taxonomies( $post_type, 'names' ) as $tax ) {
            $terms = wp_get_post_terms( $post_id, $tax, array( 'fields' => 'slugs' ) );
            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }
            $map[ $tax ] = array_values( array_filter( array_map( 'strval', $terms ) ) );
        }
        return (string) wp_json_encode( $map, JSON_UNESCAPED_UNICODE );
    }

    /** @return array */
    private static function get_mfseo_optimization_rows( $post_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'mindfulseo_optimizations';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return array();
        }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE post_id = %d ORDER BY optimization_date DESC LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL
                (int) $post_id
            ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }

    private static function resolve_parent_post_id( $slug, $post_type ) {
        $slug = sanitize_title( (string) $slug );
        if ( $slug === '' || ! is_post_type_hierarchical( $post_type ) ) {
            return 0;
        }
        $p = get_posts( array(
            'name'           => $slug,
            'post_type'      => $post_type,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ) );
        return $p ? (int) $p[0] : 0;
    }

    private static function import_featured_from_zip( ZipArchive $zip, $post_id, $rel, $alt = '' ) {
        $rel = ltrim( (string) $rel, '/' );
        if ( $rel === '' || $post_id < 1 ) {
            return false;
        }
        $bytes = $zip->getFromName( $rel );
        if ( $bytes === false ) {
            $bytes = $zip->getFromName( str_replace( '\\', '/', $rel ) );
        }
        if ( $bytes === false || $bytes === '' ) {
            return false;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = wp_tempnam( 'mfseo-fi' );
        if ( ! is_string( $tmp ) ) {
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $tmp, $bytes );
        $filename = sanitize_file_name( basename( $rel ) ) ?: 'featured.jpg';
        $att_id   = media_handle_sideload( array( 'name' => $filename, 'tmp_name' => $tmp ), $post_id, null );
        // phpcs:ignore WordPress.PHP.NoSilencedErrors
        @unlink( $tmp );

        if ( is_wp_error( $att_id ) ) {
            return false;
        }
        if ( $alt !== '' ) {
            update_post_meta( (int) $att_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
        }
        set_post_thumbnail( $post_id, (int) $att_id );
        return true;
    }

    private static function import_apply_meta_payload( $post_id, $payload ) {
        if ( ! is_array( $payload ) ) {
            return;
        }
        foreach ( $payload as $mk => $vals ) {
            if ( self::meta_key_is_blocked( $mk ) || ! is_array( $vals ) ) {
                continue;
            }
            delete_post_meta( $post_id, $mk );
            foreach ( $vals as $one ) {
                add_post_meta( $post_id, $mk, maybe_unserialize( $one ) );
            }
        }
    }

    private static function import_set_terms( $post_id, $taxonomy, $slugs, $create_missing ) {
        if ( ! taxonomy_exists( $taxonomy ) || empty( $slugs ) ) {
            return;
        }
        $ids = array();
        foreach ( (array) $slugs as $slug ) {
            $slug = sanitize_title( (string) $slug );
            if ( $slug === '' ) {
                continue;
            }
            $term = get_term_by( 'slug', $slug, $taxonomy );
            if ( ! $term || is_wp_error( $term ) ) {
                if ( ! $create_missing ) {
                    continue;
                }
                $ins = wp_insert_term( $slug, $taxonomy, array( 'slug' => $slug ) );
                $tid = ( ! is_wp_error( $ins ) && isset( $ins['term_id'] ) ) ? (int) $ins['term_id'] : 0;
            } else {
                $tid = (int) $term->term_id;
            }
            if ( $tid > 0 ) {
                $ids[] = $tid;
            }
        }
        if ( ! empty( $ids ) ) {
            wp_set_object_terms( $post_id, $ids, $taxonomy, false );
        }
    }

    private static function import_set_terms_from_pipe_names( $post_id, $taxonomy, $pipe, $create_missing ) {
        if ( ! taxonomy_exists( $taxonomy ) || $pipe === '' ) {
            return;
        }
        $labels = array_filter( array_map( 'trim', explode( '|', $pipe ) ) );
        $ids    = array();
        foreach ( $labels as $label ) {
            $term = get_term_by( 'name', $label, $taxonomy );
            if ( ! $term || is_wp_error( $term ) ) {
                $term = get_term_by( 'slug', sanitize_title( $label ), $taxonomy );
            }
            if ( ! $term || is_wp_error( $term ) ) {
                if ( ! $create_missing ) {
                    continue;
                }
                $ins = wp_insert_term( $label, $taxonomy, array( 'slug' => sanitize_title( $label ) ) );
                $tid = ( ! is_wp_error( $ins ) && isset( $ins['term_id'] ) ) ? (int) $ins['term_id'] : 0;
            } else {
                $tid = (int) $term->term_id;
            }
            if ( $tid > 0 ) {
                $ids[] = $tid;
            }
        }
        if ( ! empty( $ids ) ) {
            wp_set_object_terms( $post_id, $ids, $taxonomy, false );
        }
    }

    private static function import_mfseo_records( $post_id, array $rows ) {
        global $wpdb;
        $table = $wpdb->prefix . 'mindfulseo_optimizations';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }
        /*
         * Export stores rows newest-first (DESC). Inserting in that order gives the oldest row
         * the highest id; the Batch Optimizer used MAX(id) as "latest" and showed stale/empty
         * SEO. Insert oldest-first so newest row gets the highest id (matches normal saves).
         */
        $rows = array_reverse( $rows );
        $now  = current_time( 'mysql' );
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $wpdb->insert(
                $table,
                array(
                    'post_id'             => $post_id,
                    'optimization_date'   => sanitize_text_field( $row['optimization_date']   ?? $now ),
                    'ai_provider'         => sanitize_text_field( $row['ai_provider']          ?? '' ),
                    'primary_keyword'     => sanitize_text_field( $row['primary_keyword']      ?? '' ),
                    'longtail_keywords'   => sanitize_textarea_field( $row['longtail_keywords']    ?? '' ),
                    'seo_title'           => sanitize_text_field( $row['seo_title']            ?? '' ),
                    'meta_description'    => sanitize_textarea_field( $row['meta_description']     ?? '' ),
                    'content_suggestions' => sanitize_textarea_field( $row['content_suggestions']  ?? '' ),
                    'optimization_score'  => (int) ( $row['optimization_score'] ?? 0 ),
                    'status'              => sanitize_key( $row['status'] ?? 'imported' ),
                    'created_by'          => get_current_user_id(),
                    'created_at'          => $now,
                ),
                array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
            );
        }
    }

    // =========================================================
    // EXPORT QUERY HELPERS
    // =========================================================

    private static function get_export_query( $types, $post_status, $limit ) {
        $status = ( $post_status === 'any' ) ? 'any' : $post_status;
        if ( $limit <= 0 ) {
            return new WP_Query( array(
                'post_type'           => $types,
                'post_status'         => $status,
                'orderby'             => 'modified',
                'order'               => 'DESC',
                'posts_per_page'      => -1,
                'ignore_sticky_posts' => true,
            ) );
        }
        $ids = self::collect_export_post_ids( $types, $post_status, $limit );
        if ( empty( $ids ) ) {
            return new WP_Query( array( 'post__in' => array( 0 ) ) );
        }
        return new WP_Query( array(
            'post_type'           => $types,
            'post__in'            => $ids,
            'orderby'             => 'post__in',
            'posts_per_page'      => count( $ids ),
            'ignore_sticky_posts' => true,
            'post_status'         => 'any',
        ) );
    }

    private static function collect_export_post_ids( $types, $post_status, $limit ) {
        $limit  = max( 1, (int) $limit );
        $status = ( $post_status === 'any' ) ? 'any' : $post_status;
        $ids    = array();

        $q1 = new WP_Query( array(
            'post_type'           => $types,
            'post_status'         => $status,
            'posts_per_page'      => $limit,
            'fields'              => 'ids',
            'ignore_sticky_posts' => true,
            'meta_query'          => array(
                'relation' => 'AND',
                array( 'key' => '_mindfulseo_optimized', 'value' => '1' ),
                array( 'key' => '_mindfulseo_optimized_date', 'compare' => 'EXISTS' ),
            ),
            'orderby'             => 'meta_value',
            'meta_key'            => '_mindfulseo_optimized_date',
            'order'               => 'DESC',
        ) );
        $ids = array_merge( $ids, $q1->posts );

        if ( count( $ids ) < $limit ) {
            $q2 = new WP_Query( array(
                'post_type'           => $types,
                'post_status'         => $status,
                'posts_per_page'      => $limit - count( $ids ),
                'fields'              => 'ids',
                'post__not_in'        => $ids ?: array( 0 ),
                'ignore_sticky_posts' => true,
                'meta_query'          => array(
                    array( 'key' => '_mindfulseo_optimized', 'value' => '1' ),
                ),
                'orderby'             => 'modified',
                'order'               => 'DESC',
            ) );
            $ids = array_merge( $ids, $q2->posts );
        }

        if ( count( $ids ) < $limit ) {
            $q3 = new WP_Query( array(
                'post_type'           => $types,
                'post_status'         => $status,
                'posts_per_page'      => $limit - count( $ids ),
                'fields'              => 'ids',
                'post__not_in'        => $ids ?: array( 0 ),
                'ignore_sticky_posts' => true,
                'orderby'             => 'modified',
                'order'               => 'DESC',
            ) );
            $ids = array_merge( $ids, $q3->posts );
        }

        return array_slice( array_map( 'intval', $ids ), 0, $limit );
    }
}
