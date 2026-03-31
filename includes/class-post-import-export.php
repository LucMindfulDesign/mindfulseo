<?php
/**
 * Post export / import (ZIP + manifest.csv) for site migrations.
 *
 * @package MindfulSEO
 * @since 2.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_Post_Import_Export {

    const NONCE_EXPORT = 'mfseo_export_posts';
    const NONCE_IMPORT = 'mfseo_import_posts';

    public static function init() {
        add_action('admin_post_mindfulseo_export_posts', array(__CLASS__, 'handle_export'));
        add_action('admin_post_mindfulseo_import_posts', array(__CLASS__, 'handle_import'));
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission.', 'mindfulseo'));
        }
        $export_nonce = wp_nonce_field(self::NONCE_EXPORT, 'mfseo_export_nonce', true, false);
        $import_nonce = wp_nonce_field(self::NONCE_IMPORT, 'mfseo_import_nonce', true, false);
        ?>
        <div class="wrap mindfulseo-admin-wrap">
            <h1><?php esc_html_e('Import / Export Posts', 'mindfulseo'); ?></h1>
            <p><?php esc_html_e('Export posts with SEO fields and HTML bodies as a ZIP (manifest.csv + html/). Re-import on another site running MindfulSEO.', 'mindfulseo'); ?></p>

            <?php if (isset($_GET['import']) && $_GET['import'] === 'done') : ?>
                <div class="notice notice-success"><p>
                    <?php
                    printf(
                        esc_html__('Import finished. Updated: %1$d, created: %2$d, skipped: %3$d.', 'mindfulseo'),
                        isset($_GET['u']) ? (int) $_GET['u'] : 0,
                        isset($_GET['c']) ? (int) $_GET['c'] : 0,
                        isset($_GET['s']) ? (int) $_GET['s'] : 0
                    );
                    ?>
                </p></div>
            <?php endif; ?>

            <h2><?php esc_html_e('Export', 'mindfulseo'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php echo $export_nonce; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <input type="hidden" name="action" value="mindfulseo_export_posts">
                <table class="form-table">
                    <tr>
                        <th><label for="mfseo-ex-post-type"><?php esc_html_e('Post type', 'mindfulseo'); ?></label></th>
                        <td>
                            <select name="post_type" id="mfseo-ex-post-type">
                                <option value="post"><?php esc_html_e('Post', 'mindfulseo'); ?></option>
                                <option value="page"><?php esc_html_e('Page', 'mindfulseo'); ?></option>
                                <option value="any"><?php esc_html_e('Post + Page', 'mindfulseo'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mfseo-ex-status"><?php esc_html_e('Status', 'mindfulseo'); ?></label></th>
                        <td>
                            <select name="post_status" id="mfseo-ex-status">
                                <option value="publish"><?php esc_html_e('Published', 'mindfulseo'); ?></option>
                                <option value="any"><?php esc_html_e('Any status', 'mindfulseo'); ?></option>
                                <option value="draft"><?php esc_html_e('Draft', 'mindfulseo'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mfseo-ex-limit"><?php esc_html_e('How many', 'mindfulseo'); ?></label></th>
                        <td>
                            <select name="export_limit" id="mfseo-ex-limit">
                                <option value="0"><?php esc_html_e('All matching posts', 'mindfulseo'); ?></option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                                <option value="200">200</option>
                                <option value="500">500</option>
                            </select>
                            <p class="description"><?php esc_html_e('Limited exports use newest-modified-first ordering.', 'mindfulseo'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Download ZIP export', 'mindfulseo')); ?>
            </form>

            <h2 style="margin-top:2em;"><?php esc_html_e('Import', 'mindfulseo'); ?></h2>
            <form id="mfseo-import-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php echo $import_nonce; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <input type="hidden" name="action" value="mindfulseo_import_posts">
                <table class="form-table">
                    <tr>
                        <th><label for="mfseo-import-zip"><?php esc_html_e('ZIP file', 'mindfulseo'); ?></label></th>
                        <td>
                            <input type="file" name="import_zip" id="mfseo-import-zip" accept=".zip,application/zip" required>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Records', 'mindfulseo'); ?></th>
                        <td>
                            <fieldset>
                                <label><input type="radio" name="import_record_mode" value="upsert" checked> <?php esc_html_e('Create and update (full merge)', 'mindfulseo'); ?></label><br>
                                <label><input type="radio" name="import_record_mode" value="create_only"> <?php esc_html_e('Create new posts only (skip existing slugs)', 'mindfulseo'); ?></label><br>
                                <label><input type="radio" name="import_record_mode" value="update_existing"> <?php esc_html_e('Update existing posts only (skip unknown slugs)', 'mindfulseo'); ?></label>
                            </fieldset>
                            <p class="description mfseo-import-mode-help"
                               data-upsert="<?php echo esc_attr(__('Creates missing posts and updates matching posts. Field checkboxes below apply to each row that is created or updated.', 'mindfulseo')); ?>"
                               data-create="<?php echo esc_attr(__('Only adds posts that do not exist yet. Rows matching an existing slug are skipped.', 'mindfulseo')); ?>"
                               data-update="<?php echo esc_attr(__('Only changes posts that already exist. Manifest rows with no match are skipped.', 'mindfulseo')); ?>">
                                <?php esc_html_e('Creates missing posts and updates matching posts. Field checkboxes below apply to each row that is created or updated.', 'mindfulseo'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr id="mfseo-import-field-options">
                        <th><?php esc_html_e('Fields', 'mindfulseo'); ?></th>
                        <td>
                            <label><input type="checkbox" name="import_content" value="1" checked> <?php esc_html_e('Apply HTML body from the ZIP when a row is created or updated', 'mindfulseo'); ?></label><br>
                            <label><input type="checkbox" name="import_seo" value="1" checked> <?php esc_html_e('Apply SEO title, description, and focus keyword from the manifest', 'mindfulseo'); ?></label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Run import', 'mindfulseo')); ?>
            </form>
            <script>
            (function(){
                var form = document.getElementById('mfseo-import-form');
                if (!form) return;
                var help = form.querySelector('.mfseo-import-mode-help');
                var radios = form.querySelectorAll('input[name="import_record_mode"]');
                function sync() {
                    var v = 'upsert';
                    for (var i = 0; i < radios.length; i++) { if (radios[i].checked) { v = radios[i].value; break; } }
                    if (help && help.dataset) {
                        var k = v === 'create_only' ? 'create' : (v === 'update_existing' ? 'update' : 'upsert');
                        help.textContent = help.dataset[k] || help.dataset.upsert || '';
                    }
                }
                for (var j = 0; j < radios.length; j++) { radios[j].addEventListener('change', sync); }
                sync();
            })();
            </script>
        </div>
        <?php
    }

    public static function handle_export() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'mindfulseo'));
        }
        check_admin_referer(self::NONCE_EXPORT, 'mfseo_export_nonce');

        if (!class_exists('ZipArchive')) {
            wp_die(esc_html__('PHP ZipArchive is required for export.', 'mindfulseo'));
        }

        $post_type   = isset($_POST['post_type']) ? sanitize_text_field(wp_unslash($_POST['post_type'])) : 'post';
        $post_status = isset($_POST['post_status']) ? sanitize_text_field(wp_unslash($_POST['post_status'])) : 'publish';
        $limit       = isset($_POST['export_limit']) ? max(0, (int) $_POST['export_limit']) : 0;

        $types = ($post_type === 'any') ? array('post', 'page') : array($post_type);

        $args = array(
            'post_type'           => $types,
            'post_status'         => ($post_status === 'any') ? 'any' : $post_status,
            'orderby'             => 'modified',
            'order'               => 'DESC',
            'posts_per_page'      => $limit > 0 ? $limit : -1,
            'ignore_sticky_posts' => true,
        );

        $q           = new WP_Query($args);
        $export_uuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('mfseo_', true);
        $site_url    = home_url('/');

        $tmp = wp_tempnam('mfseo-export');
        if (!is_string($tmp) || !file_exists($tmp)) {
            wp_die(esc_html__('Could not create temp file.', 'mindfulseo'));
        }
        unlink($tmp);
        $tmp .= '.zip';

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            wp_die(esc_html__('Could not create export file.', 'mindfulseo'));
        }

        $csv = fopen('php://temp', 'r+');
        $headers = array(
            'export_uuid', 'source_site', 'post_id', 'post_type', 'post_status', 'slug', 'title',
            'excerpt', 'post_date_gmt', 'post_modified_gmt', 'author_login', 'categories', 'tags',
            'permalink', 'focus_keyword', 'seo_title', 'meta_description', 'seo_plugin',
            'featured_image_url', 'featured_image_alt', 'content_file', 'content_image_urls',
        );
        fputcsv($csv, $headers);

        $adapter = class_exists('MFSEO_SEO_Plugin_Adapter') ? MFSEO_SEO_Plugin_Adapter::get_instance() : null;

        while ($q->have_posts()) {
            $q->the_post();
            $post = get_post();
            if (!$post) {
                continue;
            }
            $uid = (int) $post->ID;

            $author       = get_user_by('id', (int) $post->post_author);
            $author_login = $author ? $author->user_login : '';

            $seo_plugin = ($adapter && $adapter->is_seo_plugin_active()) ? (string) $adapter->get_active_plugin() : 'none';
            $seo        = ($adapter && $adapter->is_seo_plugin_active()) ? $adapter->get_all_seo_meta($uid) : array('keyword' => '', 'title' => '', 'description' => '');

            $thumb = get_the_post_thumbnail_url($uid, 'full');
            if (!is_string($thumb)) {
                $thumb = '';
            }
            $alt = '';
            $tid = get_post_thumbnail_id($uid);
            if ($tid) {
                $alt = (string) get_post_meta($tid, '_wp_attachment_image_alt', true);
            }

            $cats  = wp_get_post_terms($uid, 'category', array('fields' => 'names'));
            $tags  = wp_get_post_terms($uid, 'post_tag', array('fields' => 'names'));
            $cat_s = is_array($cats) ? implode('|', $cats) : '';
            $tag_s = is_array($tags) ? implode('|', $tags) : '';

            preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/', (string) $post->post_content, $imgs);
            $img_csv = (is_array($imgs) && !empty($imgs[1])) ? implode(' ', array_slice($imgs[1], 0, 50)) : '';

            $content_file = 'html/' . $uid . '.html';
            $zip->addFromString($content_file, (string) $post->post_content);

            fputcsv($csv, array(
                $export_uuid,
                $site_url,
                $uid,
                $post->post_type,
                $post->post_status,
                $post->post_name,
                $post->post_title,
                $post->post_excerpt,
                $post->post_date_gmt,
                $post->post_modified_gmt,
                $author_login,
                $cat_s,
                $tag_s,
                get_permalink($uid),
                isset($seo['keyword']) ? $seo['keyword'] : '',
                isset($seo['title']) ? $seo['title'] : '',
                isset($seo['description']) ? $seo['description'] : '',
                $seo_plugin,
                $thumb,
                $alt,
                $content_file,
                $img_csv,
            ));
        }
        wp_reset_postdata();

        rewind($csv);
        $manifest = stream_get_contents($csv);
        fclose($csv);
        $zip->addFromString('manifest.csv', $manifest);
        $zip->close();

        $filename = 'mindfulseo-export-' . gmdate('Y-m-d') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
        unlink($tmp);
        exit;
    }

    public static function handle_import() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'mindfulseo'));
        }
        check_admin_referer(self::NONCE_IMPORT, 'mfseo_import_nonce');

        if (empty($_FILES['import_zip']['tmp_name'])) {
            wp_safe_redirect(add_query_arg(array('page' => 'mindfulseo-import-export', 'import' => 'no_file'), admin_url('admin.php')));
            exit;
        }

        $file = $_FILES['import_zip'];
        if (!empty($file['error'])) {
            wp_safe_redirect(add_query_arg(array('page' => 'mindfulseo-import-export', 'import' => 'upload_err'), admin_url('admin.php')));
            exit;
        }

        if (!class_exists('ZipArchive')) {
            wp_die(esc_html__('PHP ZipArchive is required.', 'mindfulseo'));
        }

        $mode = isset($_POST['import_record_mode']) ? sanitize_key(wp_unslash($_POST['import_record_mode'])) : 'upsert';
        if (!in_array($mode, array('upsert', 'create_only', 'update_existing'), true)) {
            $mode = 'upsert';
        }
        $do_create          = ($mode === 'upsert' || $mode === 'create_only');
        $do_update_existing = ($mode === 'upsert' || $mode === 'update_existing');
        $do_content         = !empty($_POST['import_content']);
        $do_seo             = !empty($_POST['import_seo']);

        $persisted = wp_tempnam('mfseo-import-zip');
        if (!is_string($persisted) || !@move_uploaded_file($file['tmp_name'], $persisted)) {
            wp_safe_redirect(add_query_arg(array('page' => 'mindfulseo-import-export', 'import' => 'move_failed'), admin_url('admin.php')));
            exit;
        }

        $zip = new ZipArchive();
        if ($zip->open($persisted) !== true) {
            @unlink($persisted);
            wp_safe_redirect(add_query_arg(array('page' => 'mindfulseo-import-export', 'import' => 'zip_open'), admin_url('admin.php')));
            exit;
        }

        $manifest_idx = $zip->locateName('manifest.csv');
        if ($manifest_idx === false) {
            $zip->close();
            @unlink($persisted);
            wp_safe_redirect(add_query_arg(array('page' => 'mindfulseo-import-export', 'import' => 'no_manifest'), admin_url('admin.php')));
            exit;
        }

        $manifest = $zip->getFromIndex($manifest_idx);

        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $manifest);
        rewind($fh);
        $header = fgetcsv($fh);
        if (!$header || !in_array('slug', $header, true)) {
            fclose($fh);
            $zip->close();
            @unlink($persisted);
            wp_safe_redirect(add_query_arg(array('page' => 'mindfulseo-import-export', 'import' => 'bad_csv'), admin_url('admin.php')));
            exit;
        }
        $col  = array_flip($header);
        $rows = array();
        while (($data = fgetcsv($fh)) !== false) {
            $rows[] = $data;
        }
        fclose($fh);

        $adapter = class_exists('MFSEO_SEO_Plugin_Adapter') ? MFSEO_SEO_Plugin_Adapter::get_instance() : null;
        $updated = 0;
        $created = 0;
        $skipped = 0;

        foreach ($rows as $data) {
            if (count($data) < count($header)) {
                $skipped++;
                continue;
            }
            $slug  = isset($col['slug']) ? sanitize_title($data[ $col['slug'] ]) : '';
            $ptype = isset($col['post_type']) ? sanitize_key($data[ $col['post_type'] ]) : 'post';
            if ($slug === '') {
                $skipped++;
                continue;
            }

            $existing = get_posts(array(
                'name'           => $slug,
                'post_type'      => $ptype,
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ));

            $post_id = $existing ? (int) $existing[0] : 0;

            $html = '';
            if ($do_content && isset($col['content_file'])) {
                $cf = ltrim($data[ $col['content_file'] ], '/');
                $z  = $zip->getFromName($cf);
                $html = ($z !== false) ? $z : '';
            }

            if ($post_id && !$do_update_existing) {
                $skipped++;
                continue;
            }

            if (!$post_id && !$do_create) {
                $skipped++;
                continue;
            }

            $seo_data = array();
            if (isset($col['focus_keyword'])) {
                $seo_data['keyword'] = sanitize_text_field($data[ $col['focus_keyword'] ]);
            }
            if (isset($col['seo_title'])) {
                $seo_data['title'] = sanitize_text_field($data[ $col['seo_title'] ]);
            }
            if (isset($col['meta_description'])) {
                $seo_data['description'] = sanitize_textarea_field($data[ $col['meta_description'] ]);
            }
            $seo_nonempty = false;
            foreach ($seo_data as $v) {
                if ($v !== '' && $v !== null) {
                    $seo_nonempty = true;
                    break;
                }
            }

            if (!$post_id) {
                $title   = isset($col['title']) ? sanitize_text_field($data[ $col['title'] ]) : $slug;
                $post_id = wp_insert_post(array(
                    'post_title'   => $title,
                    'post_name'    => $slug,
                    'post_type'    => $ptype,
                    'post_status'  => 'draft',
                    'post_content' => $do_content ? $html : '',
                ), true);
                if (is_wp_error($post_id) || !$post_id) {
                    $skipped++;
                    continue;
                }
                $created++;
                if ($do_seo && $seo_nonempty && $adapter && $adapter->is_seo_plugin_active()) {
                    $adapter->set_all_seo_meta($post_id, $seo_data);
                }
            } else {
                $touched = false;
                $args    = array('ID' => $post_id);
                if ($do_content && $html !== '') {
                    $args['post_content'] = $html;
                    $touched                = true;
                }
                if ($do_content && isset($col['excerpt'])) {
                    $args['post_excerpt'] = sanitize_textarea_field($data[ $col['excerpt'] ]);
                    $touched              = true;
                }
                if (count($args) > 1) {
                    wp_update_post($args);
                }
                if ($do_seo && $seo_nonempty && $adapter && $adapter->is_seo_plugin_active()) {
                    $adapter->set_all_seo_meta($post_id, $seo_data);
                    $touched = true;
                }
                if ($touched) {
                    $updated++;
                } else {
                    $skipped++;
                }
            }
        }

        $zip->close();
        @unlink($persisted);

        wp_safe_redirect(add_query_arg(array(
            'page'   => 'mindfulseo-import-export',
            'import' => 'done',
            'u'      => $updated,
            'c'      => $created,
            's'      => $skipped,
        ), admin_url('admin.php')));
        exit;
    }
}
