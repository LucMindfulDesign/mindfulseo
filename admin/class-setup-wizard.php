<?php
/**
 * Setup Wizard
 * 
 * Streamlined 4-step onboarding wizard for new users
 * 
 * @package MindfulSEO
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_Setup_Wizard {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('wp_ajax_mfseo_wizard_save_step', array($this, 'ajax_save_step'));
        add_action('wp_ajax_mfseo_wizard_complete', array($this, 'ajax_complete_wizard'));
        add_action('wp_ajax_mfseo_wizard_dismiss', array($this, 'ajax_dismiss_wizard'));
        add_action('wp_ajax_mfseo_wizard_test_api', array($this, 'ajax_test_api'));
        add_action('wp_ajax_mfseo_wizard_analyze_content', array($this, 'ajax_analyze_content'));
        add_action('wp_ajax_mfseo_wizard_import_csv', array($this, 'ajax_import_csv'));
        add_action('wp_ajax_mfseo_wizard_import_guidelines_csv', array($this, 'ajax_import_guidelines_csv'));
    }
    
    public static function needs_wizard() {
        return get_option('mindfulseo_wizard_needed') === true;
    }
    
    /**
     * Render the wizard as a standalone full-page layout (not a modal overlay)
     */
    public function render_wizard_page() {
        $wizard_state = get_option('mindfulseo_wizard_state', array('step' => 1));
        $current_step = isset($wizard_state['step']) ? min(intval($wizard_state['step']), 4) : 1;
        $settings = get_option('mindfulseo_settings', array());
        ?>
        <div class="mfseo-wizard-page">
            <div class="mfseo-wizard-card">
                <div class="mfseo-wizard-header">
                    <div class="mfseo-wizard-logo">
                        <img src="<?php echo esc_url(MINDFULSEO_PLUGIN_URL . 'assets/icon-gold.svg'); ?>" alt="MindfulSEO" width="36" height="36">
                    </div>
                    <h2><?php _e('Welcome to MindfulSEO', 'mindfulseo'); ?></h2>
                    <p class="mfseo-wizard-subtitle"><?php _e('Get your AI-powered SEO optimization running in 4 quick steps', 'mindfulseo'); ?></p>
                    <div class="mfseo-wizard-steps-indicator">
                        <?php for ($i = 1; $i <= 4; $i++) : ?>
                            <div class="mfseo-wizard-step-dot <?php echo $i <= $current_step ? 'active' : ''; ?> <?php echo $i < $current_step ? 'completed' : ''; ?>" data-step="<?php echo $i; ?>">
                                <span class="dot-number"><?php echo $i; ?></span>
                                <span class="dot-check dashicons dashicons-yes"></span>
                            </div>
                            <?php if ($i < 4) : ?>
                                <div class="mfseo-wizard-step-line <?php echo $i < $current_step ? 'active' : ''; ?>"></div>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </div>
                    <div class="mfseo-wizard-step-labels">
                        <span><?php _e('Connect AI', 'mindfulseo'); ?></span>
                        <span><?php _e('Site Profile', 'mindfulseo'); ?></span>
                        <span><?php _e('Analyze Content', 'mindfulseo'); ?></span>
                        <span><?php _e('Quick Optimize', 'mindfulseo'); ?></span>
                    </div>
                </div>
                
                <div class="mfseo-wizard-body">
                    <?php
                    $this->render_step_1($settings);
                    $this->render_step_2($settings);
                    $this->render_step_3();
                    $this->render_step_4();
                    ?>
                </div>

                <div class="mfseo-wizard-footer">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=mindfulseo')); ?>" class="mfseo-wizard-dismiss-link" id="wizard-dismiss-link">
                        <?php _e('Skip setup and go to dashboard', 'mindfulseo'); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Step 1: Choose AI Provider
     */
    private function render_step_1($settings) {
        $ai_backend  = isset($settings['ai_backend']) ? $settings['ai_backend'] : 'direct';
        $ai_provider = isset($settings['ai_provider']) ? $settings['ai_provider'] : 'openai';
        if ($ai_backend === 'openrouter') {
            $wizard_ai = 'openrouter';
        } else {
            $wizard_ai = in_array($ai_provider, array('openai', 'claude'), true) ? $ai_provider : 'openai';
        }
        $or_model = isset($settings['openrouter_model']) ? $settings['openrouter_model'] : 'qwen/qwen3.5-flash-02-23';
        ?>
        <div class="mfseo-wizard-step" id="wizard-step-1" style="display: block;" data-ai-panel="<?php echo esc_attr( $wizard_ai ); ?>">
            <h3><?php _e('Connect Your AI Provider', 'mindfulseo'); ?></h3>
            <p><?php _e('MindfulSEO uses AI to optimize your content. Pick how you connect, then enter your API key.', 'mindfulseo'); ?></p>
            <div class="mfseo-wizard-step1-credentials">


            <div class="mfseo-wizard-ai-connection">
                <label for="mfseo_wizard_ai" class="mfseo-wizard-ai-connection-label">
                    <strong><?php esc_html_e('AI connection', 'mindfulseo'); ?></strong>
                </label>
                <select id="mfseo_wizard_ai" name="mfseo_wizard_ai" class="mfseo-wizard-select widefat">
                    <option value="openai" <?php selected($wizard_ai, 'openai'); ?>><?php esc_html_e('OpenAI (ChatGPT)', 'mindfulseo'); ?></option>
                    <option value="claude" <?php selected($wizard_ai, 'claude'); ?>><?php esc_html_e('Anthropic Claude', 'mindfulseo'); ?></option>
                    <option value="openrouter" <?php selected($wizard_ai, 'openrouter'); ?>><?php esc_html_e('OpenRouter (many models, one key)', 'mindfulseo'); ?></option>
                </select>
                <p class="description mfseo-wizard-ai-connection-hint"><?php esc_html_e( 'OpenAI and Claude use each vendor\'s API directly. OpenRouter routes requests through openrouter.ai (you can add fallback keys later in Settings).', 'mindfulseo' ); ?></p>
            </div>

            <div class="mfseo-wizard-api-config" id="openai-config">
                <label>
                    <strong><?php _e('OpenAI API Key', 'mindfulseo'); ?></strong>
                    <input type="password" name="openai_api_key" value="<?php echo esc_attr(isset($settings['openai_api_key']) ? $settings['openai_api_key'] : ''); ?>" class="mfseo-wizard-input" placeholder="sk-...">
                    <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer"><?php _e('Get your API key', 'mindfulseo'); ?> &rarr;</a>
                </label>
                <button type="button" class="mfseo-wizard-btn mfseo-wizard-btn-secondary test-api-btn" data-provider="openai">
                    <span class="dashicons dashicons-yes"></span>
                    <?php _e('Test Connection', 'mindfulseo'); ?>
                </button>
                <div class="api-test-result"></div>
            </div>

            <div class="mfseo-wizard-api-config" id="claude-config">
                <label>
                    <strong><?php _e('Claude API Key', 'mindfulseo'); ?></strong>
                    <input type="password" name="claude_api_key" value="<?php echo esc_attr(isset($settings['claude_api_key']) ? $settings['claude_api_key'] : ''); ?>" class="mfseo-wizard-input" placeholder="sk-ant-...">
                    <a href="https://console.anthropic.com/" target="_blank" rel="noopener noreferrer"><?php _e('Get your API key', 'mindfulseo'); ?> &rarr;</a>
                </label>
                <button type="button" class="mfseo-wizard-btn mfseo-wizard-btn-secondary test-api-btn" data-provider="claude">
                    <span class="dashicons dashicons-yes"></span>
                    <?php _e('Test Connection', 'mindfulseo'); ?>
                </button>
                <div class="api-test-result"></div>
            </div>

            <div class="mfseo-wizard-api-config" id="openrouter-config">
                <label>
                    <strong><?php esc_html_e('OpenRouter API Key', 'mindfulseo'); ?></strong>
                    <input type="password" name="openrouter_api_key" value="<?php echo esc_attr(isset($settings['openrouter_api_key']) ? $settings['openrouter_api_key'] : ''); ?>" class="mfseo-wizard-input" placeholder="sk-or-...">
                    <a href="https://openrouter.ai/keys" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Get your API key', 'mindfulseo'); ?> &rarr;</a>
                </label>
                <label class="mfseo-wizard-openrouter-model-label">
                    <strong><?php esc_html_e('Model', 'mindfulseo'); ?></strong>
                    <select id="wizard-openrouter-model" name="openrouter_model" class="mfseo-wizard-select widefat">
                        <option value="qwen/qwen3.5-flash-02-23" <?php selected($or_model, 'qwen/qwen3.5-flash-02-23'); ?>>Qwen 3.5 Flash</option>
                        <option value="qwen/qwen3.5-35b-a3b" <?php selected($or_model, 'qwen/qwen3.5-35b-a3b'); ?>>Qwen 3.5 35B A3B</option>
                        <option value="minimax/minimax-m2.5" <?php selected($or_model, 'minimax/minimax-m2.5'); ?>>MiniMax M2.5</option>
                        <option value="minimax/minimax-m2" <?php selected($or_model, 'minimax/minimax-m2'); ?>>MiniMax M2</option>
                    </select>
                </label>
                <p class="description"><?php esc_html_e('You can fine-tune fast models and more in Settings after setup.', 'mindfulseo'); ?></p>
                <button type="button" class="mfseo-wizard-btn mfseo-wizard-btn-secondary test-api-btn" data-provider="openrouter">
                    <span class="dashicons dashicons-yes"></span>
                    <?php _e('Test Connection', 'mindfulseo'); ?>
                </button>
                <div class="api-test-result"></div>
            </div>
            </div>

            <div class="mfseo-wizard-actions">
                <button type="button" class="mfseo-wizard-btn mfseo-wizard-btn-primary" id="wizard-step-1-next">
                    <?php _e('Continue', 'mindfulseo'); ?> &rarr;
                </button>
            </div>
        </div>
        <?php
    }
    
    /**
     * Step 2: Site Profile
     */
    private function render_step_2($settings) {
        $site_type = isset($settings['site_type']) ? $settings['site_type'] : '';

        $adapter = class_exists('MFSEO_SEO_Plugin_Adapter') ? MFSEO_SEO_Plugin_Adapter::get_instance() : null;
        $seo_plugin_active = $adapter && $adapter->is_seo_plugin_active();
        $seo_plugin_name = $seo_plugin_active ? $adapter->get_active_plugin() : null;
        $seo_plugin_label = $seo_plugin_name === 'rankmath' ? 'Rank Math' : ($seo_plugin_name === 'yoast' ? 'Yoast SEO' : '');

        $post_count = (int) wp_count_posts('post')->publish;
        $page_count = (int) wp_count_posts('page')->publish;
        ?>
        <div class="mfseo-wizard-step" id="wizard-step-2" style="display: none;">
            <h3><?php _e('Tell Us About Your Site', 'mindfulseo'); ?></h3>
            <p><?php _e('This helps MindfulSEO tailor optimizations to your content type.', 'mindfulseo'); ?></p>
            
            <div class="mfseo-wizard-site-types">
                <?php
                $types = array(
                    'blog'       => array('label' => __('Blog', 'mindfulseo'),       'icon' => 'dashicons-welcome-write-blog', 'desc' => __('Articles, tutorials, personal writing', 'mindfulseo')),
                    'business'   => array('label' => __('Business', 'mindfulseo'),   'icon' => 'dashicons-building',           'desc' => __('Company website, services, portfolio', 'mindfulseo')),
                    'ecommerce'  => array('label' => __('E-commerce', 'mindfulseo'), 'icon' => 'dashicons-cart',               'desc' => __('Online store, product pages', 'mindfulseo')),
                    'news'       => array('label' => __('News / Magazine', 'mindfulseo'), 'icon' => 'dashicons-media-text',    'desc' => __('News articles, magazine content', 'mindfulseo')),
                );
                foreach ($types as $value => $type) :
                ?>
                    <label class="mfseo-wizard-type-card <?php echo $site_type === $value ? 'active' : ''; ?>">
                        <input type="radio" name="site_type" value="<?php echo esc_attr($value); ?>" <?php checked($site_type, $value); ?>>
                        <span class="dashicons <?php echo esc_attr($type['icon']); ?>"></span>
                        <strong><?php echo esc_html($type['label']); ?></strong>
                        <span class="mfseo-wizard-type-desc"><?php echo esc_html($type['desc']); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="mfseo-wizard-site-info">
                <?php if ($seo_plugin_active) : ?>
                    <div class="mfseo-wizard-info-row mfseo-wizard-info-row--success">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <span><?php printf(__('%s detected &mdash; MindfulSEO will sync with your existing SEO data.', 'mindfulseo'), '<strong>' . esc_html($seo_plugin_label) . '</strong>'); ?></span>
                    </div>
                <?php else : ?>
                    <div class="mfseo-wizard-info-row mfseo-wizard-info-row--warning">
                        <span class="dashicons dashicons-info"></span>
                        <span><?php _e('No SEO plugin detected. Install Rank Math or Yoast SEO for best results.', 'mindfulseo'); ?></span>
                    </div>
                <?php endif; ?>

                <div class="mfseo-wizard-info-row">
                    <span class="dashicons dashicons-admin-post"></span>
                    <span><?php printf(__('Your site has <strong>%d published posts</strong> and <strong>%d pages</strong>.', 'mindfulseo'), $post_count, $page_count); ?></span>
                </div>
            </div>
            
            <div class="mfseo-wizard-actions">
                <button type="button" class="mfseo-wizard-btn mfseo-wizard-btn-secondary" id="wizard-step-2-back">
                    &larr; <?php _e('Back', 'mindfulseo'); ?>
                </button>
                <button type="button" class="mfseo-wizard-btn mfseo-wizard-btn-primary" id="wizard-step-2-next">
                    <?php _e('Continue', 'mindfulseo'); ?> &rarr;
                </button>
            </div>
        </div>
        <?php
    }
    
    /**
     * Count keywords and active guidelines already stored (used by setup wizard step 3).
     *
     * @return array{keywords: int, guidelines: int}
     */
    private function get_existing_strategy_counts() {
        global $wpdb;
        $kw_table = $wpdb->prefix . 'mindfulseo_keywords';
        $gl_table = $wpdb->prefix . 'mindfulseo_guidelines';
        $keywords   = 0;
        $guidelines = 0;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $kw_table ) ) === $kw_table ) {
            $keywords = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$kw_table}" );
        }
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $gl_table ) ) === $gl_table ) {
            $guidelines = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$gl_table} WHERE active = 1" );
        }
        return array(
            'keywords'   => $keywords,
            'guidelines' => $guidelines,
        );
    }

    /**
     * Posts for wizard step 4: prefer not yet applied as MindfulSEO-optimized
     * (_mindfulseo_optimized on apply in class-optimizer.php), then fill from recent posts.
     *
     * @param int $limit Max posts to return.
     * @return WP_Post[]
     */
    private function get_wizard_quick_optimize_candidates( $limit = 5 ) {
        $limit = max( 1, (int) $limit );

        $not_applied = get_posts(
            array(
                'post_type'              => array( 'post', 'page' ),
                'post_status'            => 'publish',
                'posts_per_page'         => $limit,
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_query'             => array(
                    'relation' => 'OR',
                    array(
                        'key'     => '_mindfulseo_optimized',
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key'     => '_mindfulseo_optimized',
                        'value'   => '1',
                        'compare' => '!=',
                        'type'    => 'CHAR',
                    ),
                ),
            )
        );

        $ids  = wp_list_pluck( $not_applied, 'ID' );
        $need = $limit - count( $not_applied );
        if ( $need > 0 ) {
            $fill = get_posts(
                array(
                    'post_type'              => array( 'post', 'page' ),
                    'post_status'            => 'publish',
                    'posts_per_page'         => $need,
                    'orderby'                => 'date',
                    'order'                  => 'DESC',
                    'post__not_in'           => $ids,
                    'no_found_rows'          => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                )
            );
            $not_applied = array_merge( $not_applied, $fill );
        }

        return $not_applied;
    }

    /**
     * Drop AI keyword rows that duplicate anything already in DB (wizard extend-only safety).
     *
     * Setup wizard: target count for AI-only suggestions (separate from imports; imports are never modified).
     * With preserved rows: 30–50 scaled by import volume. Cold start (nothing to preserve): ~60.
     *
     * @param int $preserved_count Preservable keyword or guideline rows before AI refresh.
     * @return int Cap for extra AI rows/rules in this run.
     */
    private function get_wizard_ai_suggestion_cap( $preserved_count ) {
        $n = max( 0, (int) $preserved_count );
        if ( $n === 0 ) {
            return 60;
        }
        $extra = (int) round( 20 * min( 1.0, $n / 100.0 ) );

        return min( 50, max( 30, 30 + $extra ) );
    }

    /**
     * @param array               $suggestions Parsed suggestions from analyzer.
     * @param MFSEO_Keyword_Manager $keyword_manager Keyword manager.
     * @return array
     */
    private function filter_wizard_new_keyword_suggestions( $suggestions, $keyword_manager ) {
        if ( empty( $suggestions ) || ! is_array( $suggestions ) ) {
            return array();
        }
        $rows = $keyword_manager->get_keywords( array( 'limit' => 999999, 'orderby' => 'primary_keyword', 'order' => 'ASC' ) );
        $seen = array();
        foreach ( $rows as $row ) {
            $pk = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $row->primary_keyword ), 'UTF-8' ) : strtolower( trim( (string) $row->primary_keyword ) );
            $lt = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $row->longtail_keyword ), 'UTF-8' ) : strtolower( trim( (string) $row->longtail_keyword ) );
            $seen[ $pk . "\x1e" . $lt ] = true;
        }
        $out = array();
        foreach ( $suggestions as $s ) {
            if ( empty( $s['primary_keyword'] ) || empty( $s['longtail_keyword'] ) ) {
                continue;
            }
            $pk = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $s['primary_keyword'] ), 'UTF-8' ) : strtolower( trim( (string) $s['primary_keyword'] ) );
            $lt = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $s['longtail_keyword'] ), 'UTF-8' ) : strtolower( trim( (string) $s['longtail_keyword'] ) );
            $key = $pk . "\x1e" . $lt;
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $out[] = $s;
        }
        return $out;
    }

    /**
     * When extending imports, reserve most rows for NEW primary topics; cap longtails on existing primaries.
     *
     * @param array    $suggestions Flat AI rows (primary + longtail each).
     * @param object[] $pres_kw     Preservable keyword rows (define "imported" primaries).
     * @param int      $cap         Row budget for this run.
     * @return array
     */
    private function balance_wizard_extend_keyword_suggestions( $suggestions, $pres_kw, $cap ) {
        if ( empty( $suggestions ) || ! is_array( $suggestions ) || $cap < 1 ) {
            return is_array( $suggestions ) ? array_slice( $suggestions, 0, $cap ) : array();
        }
        $auth = array();
        foreach ( $pres_kw as $row ) {
            if ( ! is_object( $row ) || ! isset( $row->primary_keyword ) ) {
                continue;
            }
            $pk = trim( (string) $row->primary_keyword );
            if ( $pk === '' ) {
                continue;
            }
            $k = function_exists( 'mb_strtolower' ) ? mb_strtolower( $pk, 'UTF-8' ) : strtolower( $pk );
            $auth[ $k ] = true;
        }
        if ( empty( $auth ) ) {
            return array_slice( $suggestions, 0, $cap );
        }
        $max_on_existing = max( 2, min( 14, (int) ceil( $cap * 0.26 ) ) );
        $on_new          = array();
        $on_existing     = array();
        foreach ( $suggestions as $s ) {
            if ( empty( $s['primary_keyword'] ) ) {
                continue;
            }
            $pk = function_exists( 'mb_strtolower' )
                ? mb_strtolower( trim( (string) $s['primary_keyword'] ), 'UTF-8' )
                : strtolower( trim( (string) $s['primary_keyword'] ) );
            if ( isset( $auth[ $pk ] ) ) {
                $on_existing[] = $s;
            } else {
                $on_new[] = $s;
            }
        }
        $out           = array();
        $existing_used = 0;
        foreach ( $on_new as $s ) {
            if ( count( $out ) >= $cap ) {
                break;
            }
            $out[] = $s;
        }
        foreach ( $on_existing as $s ) {
            if ( count( $out ) >= $cap ) {
                break;
            }
            if ( $existing_used >= $max_on_existing ) {
                break;
            }
            $out[] = $s;
            $existing_used++;
        }

        return $out;
    }

    /**
     * Capture keyword + guideline text before wizard regeneration (for AI improvement context).
     *
     * @return array{keywords: string, guidelines: string}
     */
    private function get_strategy_snapshot_texts() {
        $kw_text = '';
        $gl_text = '';
        if ( class_exists( 'MFSEO_Keyword_Manager' ) ) {
            $km    = MFSEO_Keyword_Manager::get_instance();
            $lines = array();
            foreach ( $km->get_keywords( array( 'limit' => 5000, 'orderby' => 'priority', 'order' => 'ASC' ) ) as $row ) {
                $lines[] = sprintf(
                    '- %s | %s | %s | priority: %s',
                    $row->primary_keyword,
                    $row->longtail_keyword,
                    $row->search_intent,
                    $row->priority
                );
            }
            $kw_text = implode( "\n", $lines );
        }
        if ( class_exists( 'MFSEO_Guidelines_Engine' ) ) {
            $ge    = MFSEO_Guidelines_Engine::get_instance();
            $lines = array();
            $rules = $ge->get_all_rules( array( 'active_only' => true ) );
            foreach ( $rules as $rule ) {
                $lines[] = sprintf(
                    '- [%s] current: %s | suggested: %s',
                    $rule->rule_type,
                    $rule->avoid_term,
                    $rule->preferred_term
                );
            }
            $gl_text = implode( "\n", $lines );
        }
        $max_kw = 24000;
        $max_gl = 24000;
        if ( strlen( $kw_text ) > $max_kw ) {
            $kw_text = substr( $kw_text, 0, $max_kw ) . "\n[…]";
        }
        if ( strlen( $gl_text ) > $max_gl ) {
            $gl_text = substr( $gl_text, 0, $max_gl ) . "\n[…]";
        }

        return array(
            'keywords'   => $kw_text,
            'guidelines' => $gl_text,
        );
    }

    /**
     * Guidelines from non-auto sources only (imports, manual) for AI context.
     * Always passed when regenerating so editor policy is not dropped when "Use saved" is off.
     *
     * @return string
     */
    private function get_manual_guidelines_snapshot_text() {
        if ( ! class_exists( 'MFSEO_Guidelines_Engine' ) ) {
            return '';
        }
        return MFSEO_Guidelines_Engine::get_instance()->get_editor_policy_snapshot_text( 24000, 8000 );
    }

    /**
     * Step 3: Analyze Content (generate keywords + guidelines)
     */
    private function render_step_3() {
        $post_count = (int) wp_count_posts('post')->publish;
        $page_count = (int) wp_count_posts('page')->publish;
        $total = $post_count + $page_count;
        $counts    = $this->get_existing_strategy_counts();
        $has_saved = ( $counts['keywords'] + $counts['guidelines'] ) > 0;
        ?>
        <div class="mfseo-wizard-step" id="wizard-step-3" style="display: none;" data-prestart-strategy="<?php echo $has_saved ? '1' : '0'; ?>">
            <h3><?php _e('Analyze Your Content', 'mindfulseo'); ?></h3>
            <p><?php _e('Choose how to set up your keyword strategy and language guidelines: use data you already have, add or edit it yourself, or let AI scan your site and generate everything.', 'mindfulseo'); ?></p>

            <?php if ( $has_saved ) : ?>
            <div class="mfseo-wizard-saved-summary">
                <p id="wizard-saved-summary-line">
                    <?php printf(
                        /* translators: 1: keyword count, 2: guideline count */
                        __( 'You have %1$s keywords and %2$s language guidelines saved in MindfulSEO.', 'mindfulseo' ),
                        number_format_i18n( $counts['keywords'] ),
                        number_format_i18n( $counts['guidelines'] )
                    ); ?>
                </p>
            </div>

            <div id="wizard-saved-full-controls" class="mfseo-wizard-saved-controls">
                <div class="mfseo-wizard-use-saved-toggle-wrap" id="wizard-use-saved-wrap">
                    <div class="mfseo-wizard-use-saved-head">
                        <span class="mfseo-wizard-use-saved-title"><?php esc_html_e( 'Use imported & saved strategy as AI context', 'mindfulseo' ); ?></span>
                        <label class="mfseo-wizard-toggle" for="wizard-use-saved">
                            <input type="checkbox" id="wizard-use-saved" class="mfseo-wizard-toggle-input" role="switch" <?php checked( true ); ?> aria-label="<?php esc_attr_e( 'Pass imported and saved keywords and guidelines to AI when analyzing', 'mindfulseo' ); ?>" />
                            <span class="mfseo-wizard-toggle-ui" aria-hidden="true">
                                <span class="mfseo-wizard-toggle-caption mfseo-wizard-toggle-caption--off"><?php esc_html_e( 'Off', 'mindfulseo' ); ?></span>
                                <span class="mfseo-wizard-toggle-track">
                                    <span class="mfseo-wizard-toggle-thumb"></span>
                                </span>
                                <span class="mfseo-wizard-toggle-caption mfseo-wizard-toggle-caption--on"><?php esc_html_e( 'On', 'mindfulseo' ); ?></span>
                            </span>
                        </label>
                    </div>
                    <p class="mfseo-wizard-saved-controls-hint"><?php esc_html_e( 'When running AI analysis:', 'mindfulseo' ); ?></p>
                    <div class="mfseo-wizard-regenerate-options" id="wizard-regenerate-options">
                        <p class="mfseo-wizard-regenerate-options-label"><?php esc_html_e( 'Regenerate', 'mindfulseo' ); ?></p>
                        <label class="mfseo-wizard-regenerate-option" for="wizard-regenerate-keywords">
                            <input type="checkbox" id="wizard-regenerate-keywords" checked="checked" />
                            <?php esc_html_e( 'Regenerate keyword strategy', 'mindfulseo' ); ?>
                        </label>
                        <label class="mfseo-wizard-regenerate-option" for="wizard-regenerate-guidelines">
                            <input type="checkbox" id="wizard-regenerate-guidelines" checked="checked" />
                            <?php esc_html_e( 'Regenerate language guidelines', 'mindfulseo' ); ?>
                        </label>
                    </div>
                    <p class="mfseo-wizard-saved-controls-footnote"><?php esc_html_e( 'When on, your saved keywords and guidelines are sent to the AI as context; auto-generated entries are then refreshed. Turn off to choose only keywords or only guidelines without tying regeneration options together.', 'mindfulseo' ); ?></p>
                </div>
            </div>
            <?php endif; ?>

            <span id="wizard-saved-meta" class="mfseo-wizard-hidden-meta" aria-hidden="true" data-kw="<?php echo esc_attr( $counts['keywords'] ); ?>" data-gl="<?php echo esc_attr( $counts['guidelines'] ); ?>"></span>

            <div id="wizard-analyze-info">
                <div class="mfseo-wizard-analyze-cards">
                    <div class="mfseo-wizard-analyze-card">
                        <span class="dashicons dashicons-tag"></span>
                        <strong><?php _e('Keyword Strategy', 'mindfulseo'); ?></strong>
                        <p><?php _e('AI analyzes your content themes and generates search-optimized keywords that real people type into Google.', 'mindfulseo'); ?></p>
                        <div style="margin-top: 10px; padding-top: 8px; border-top: 1px solid #eee; font-size: 12px;">
                            <span style="color: #888;"><?php _e('Or import CSV:', 'mindfulseo'); ?></span>
                            <div style="margin-top: 6px;">
                                <input type="file" id="wizard-csv-file" accept=".csv,.txt" style="font-size: 11px; max-width: 160px;">
                                <button type="button" class="mfseo-wizard-btn mfseo-wizard-btn-secondary" id="wizard-import-csv" style="padding: 4px 10px; font-size: 11px; margin-top: 4px;" disabled>
                                    <?php _e('Import', 'mindfulseo'); ?>
                                </button>
                                <span id="wizard-import-status" style="font-size: 11px; color: #666; display: block; margin-top: 4px;"></span>
                            </div>
                            <div class="mfseo-wizard-format-hint">
                                <span class="mfseo-wizard-format-hint-text"><?php esc_html_e( 'PRIMARY KEYWORD, LONGTAIL KEYWORD, SEARCH INTENT, PRIORITY', 'mindfulseo' ); ?></span>
                                <button type="button" class="mfseo-wizard-format-example-btn" data-format="keywords" data-modal-title="<?php esc_attr_e( 'Keyword CSV format example', 'mindfulseo' ); ?>" aria-label="<?php esc_attr_e( 'Show keyword CSV format example', 'mindfulseo' ); ?>">
                                    <span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="mfseo-wizard-analyze-card">
                        <span class="dashicons dashicons-editor-spellcheck"></span>
                        <strong><?php _e('Language Guidelines', 'mindfulseo'); ?></strong>
                        <p><?php _e('Detects proper nouns, preferred terminology, and capitalization rules specific to your brand.', 'mindfulseo'); ?></p>
                        <div style="margin-top: 10px; padding-top: 8px; border-top: 1px solid #eee; font-size: 12px;">
                            <span style="color: #888;"><?php _e('Or import file:', 'mindfulseo'); ?></span>
                            <div style="margin-top: 6px;">
                                <input type="file" id="wizard-guidelines-csv-file" accept=".csv,.txt,.md,.markdown" style="font-size: 11px; max-width: 160px;">
                                <button type="button" class="mfseo-wizard-btn mfseo-wizard-btn-secondary" id="wizard-import-guidelines-csv" style="padding: 4px 10px; font-size: 11px; margin-top: 4px;" disabled>
                                    <?php _e('Import', 'mindfulseo'); ?>
                                </button>
                                <span id="wizard-import-guidelines-status" style="font-size: 11px; color: #666; display: block; margin-top: 4px;"></span>
                            </div>
                            <div class="mfseo-wizard-format-hint">
                                <span class="mfseo-wizard-format-hint-text"><?php esc_html_e( 'Markdown (.md) or CSV (.csv)', 'mindfulseo' ); ?></span>
                                <button type="button" class="mfseo-wizard-format-example-btn" data-format="guidelines" data-modal-title="<?php esc_attr_e( 'Guidelines import examples', 'mindfulseo' ); ?>" aria-label="<?php esc_attr_e( 'Show guidelines file format examples', 'mindfulseo' ); ?>">
                                    <span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="mfseo-wizard-analyze-note mfseo-wizard-analyze-note--save-first" style="font-size: 12px; color: #475569; margin-top: 10px;">
                    <?php esc_html_e( 'Imports: click Import to save immediately, or leave a file selected and run Analyze Content — files are written to the database first (same behavior as Keyword Strategy and Language Guidelines → Import).', 'mindfulseo' ); ?>
                </p>
                
                <?php if ($total > 0) : ?>
                    <p class="mfseo-wizard-analyze-note">
                        <?php printf(__('Will scan all %d titles and analyze your content for keywords, proper nouns, and terminology.', 'mindfulseo'), $total); ?>
                    </p>
                    <label style="display: block; margin-top: 12px; font-size: 13px; cursor: pointer;">
                        <input type="checkbox" id="wizard-deep-analysis" value="1" style="margin-right: 6px;">
                        <?php _e('Deep Analysis (uses your primary AI model — slower but higher quality)', 'mindfulseo'); ?>
                    </label>
                <?php else : ?>
                    <p class="mfseo-wizard-analyze-note mfseo-wizard-analyze-note--warning">
                        <?php _e('No published content found. You can generate keywords and guidelines later from the settings.', 'mindfulseo'); ?>
                    </p>
                <?php endif; ?>
            </div>
            
            <div id="wizard-analyze-progress" style="display: none;">
                <div class="mfseo-wizard-progress-bar">
                    <div class="mfseo-wizard-analyze-bar-fill" id="wizard-analyze-bar-fill"></div>
                </div>
                <p id="wizard-analyze-status"><?php _e('Analyzing your content...', 'mindfulseo'); ?></p>
                <p class="mfseo-wizard-analyze-patience"><?php _e('This may take a minute or two depending on site size — hang tight!', 'mindfulseo'); ?></p>
            </div>
            
            <div id="wizard-analyze-results" style="display: none;">
                <div class="mfseo-wizard-analyze-result-icon mfseo-wizard-analyze-result-icon--success" id="wizard-analyze-result-icon" aria-hidden="true">
                    <span class="dashicons dashicons-yes-alt"></span>
                </div>
                <h4 id="wizard-result-heading"><?php _e('Content analysis complete!', 'mindfulseo'); ?></h4>
                <div class="mfseo-wizard-analyze-summary">
                    <div class="mfseo-wizard-analyze-stat">
                        <span class="dashicons dashicons-tag"></span>
                        <strong id="wizard-kw-count">0</strong>
                        <span id="wizard-kw-count-label"> <?php _e('keywords generated', 'mindfulseo'); ?></span>
                    </div>
                    <div class="mfseo-wizard-analyze-stat">
                        <span class="dashicons dashicons-editor-spellcheck"></span>
                        <strong id="wizard-gl-count">0</strong>
                        <span id="wizard-gl-count-label"> <?php _e('guidelines created', 'mindfulseo'); ?></span>
                    </div>
                </div>
                <p class="mfseo-wizard-analyze-note" id="wizard-analyze-result-note"><?php _e('You can review and edit these anytime from the Keyword Strategy and Language Guidelines pages.', 'mindfulseo'); ?></p>
            </div>
            
            
            <div class="mfseo-wizard-actions mfseo-wizard-actions--step3" id="wizard-step-3-actions">
                <button type="button" class="mfseo-wizard-btn mfseo-wizard-btn-secondary" id="wizard-step-3-back">
                    &larr; <?php _e('Back', 'mindfulseo'); ?>
                </button>
                <div class="mfseo-wizard-step3-main-actions" id="wizard-step-3-main-actions">
                    <button type="button" class="mfseo-wizard-btn mfseo-wizard-btn-primary" id="wizard-step-3-analyze" data-analyze-enabled="<?php echo $total > 0 ? '1' : '0'; ?>" <?php disabled($total === 0); ?>
                        title="<?php esc_attr_e( 'Scan your published content with AI (options at the top apply when you have saved keywords or guidelines)', 'mindfulseo' ); ?>">
                        <span class="dashicons dashicons-search"></span>
                        <?php _e('Analyze & generate with AI', 'mindfulseo'); ?>
                    </button>
                </div>
                <button type="button" class="mfseo-wizard-btn mfseo-wizard-btn-skip" id="wizard-step-3-skip">
                    <?php _e('Skip', 'mindfulseo'); ?> &rarr;
                </button>
                <button type="button" class="mfseo-wizard-btn mfseo-wizard-btn-primary" id="wizard-step-3-continue-final" style="display:none;">
                    <?php _e( 'Continue', 'mindfulseo' ); ?> &rarr;
                </button>
            </div>

            <div id="wizard-format-modal" class="mfseo-wizard-format-modal" role="dialog" aria-modal="true" aria-labelledby="wizard-format-modal-title" hidden tabindex="-1">
                <div class="mfseo-wizard-format-modal-inner">
                    <div class="mfseo-wizard-format-modal-head">
                        <h3 id="wizard-format-modal-title"></h3>
                        <button type="button" id="wizard-format-modal-close" class="mfseo-wizard-format-modal-close" aria-label="<?php esc_attr_e( 'Close', 'mindfulseo' ); ?>">
                            <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                        </button>
                    </div>
                    <div id="wizard-format-modal-body" class="mfseo-wizard-format-modal-body"></div>
                </div>
            </div>

            <div id="wizard-format-content-keywords" class="mfseo-wizard-hidden-meta" aria-hidden="true">
                <pre class="mfseo-wizard-format-pre"><?php echo esc_html(
                    "PRIMARY KEYWORD,LONGTAIL KEYWORD,SEARCH INTENT,PRIORITY,CURRENT SESSIONS,NOTES\n"
                    . "seo services,affordable seo services for small business,Transactional,HIGH,2500,Target local businesses\n"
                    . "content marketing,what is content marketing,Informational,MEDIUM,1800,Educational focus\n"
                    . "web design,responsive web design examples,Informational,HIGH,3200,Showcase portfolio"
                ); ?></pre>
            </div>
            <div id="wizard-format-content-guidelines" class="mfseo-wizard-hidden-meta" aria-hidden="true">
                <div class="mfseo-wizard-format-section">
                    <h4 class="mfseo-wizard-format-subheading"><?php esc_html_e( 'Markdown (.md)', 'mindfulseo' ); ?></h4>
                    <pre class="mfseo-wizard-format-pre"><?php echo esc_html(
                        "## Brand Voice Guidelines\n\n"
                        . "- **Avoid:** \"cheap\" → Use \"affordable\"\n"
                        . "- **Capitalize:** Company Name, Product Names\n"
                        . "- **Preferred:** \"Our customers\" (not \"clients\")\n\n"
                        . "## Industry Terms\n\n"
                        . "- **Avoid:** jargon → Use \"simple explanations\""
                    ); ?></pre>
                </div>
                <div class="mfseo-wizard-format-section">
                    <h4 class="mfseo-wizard-format-subheading"><?php esc_html_e( 'CSV', 'mindfulseo' ); ?></h4>
                    <pre class="mfseo-wizard-format-pre"><?php echo esc_html(
                        "RULE TYPE,AVOID TERM,PREFERRED TERM,CONTEXT\n"
                        . "avoid_term,Homepage,Home Page,Use two-word form\n"
                        . "capitalize,,Your Organization Name,Organization proper name\n"
                        . "preferred_term,,Search Engine Optimization,Full form for clarity"
                    ); ?></pre>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Step 4: Quick Optimize
     */
    private function render_step_4() {
        $recent_posts = $this->get_wizard_quick_optimize_candidates( 5 );
        ?>
        <div class="mfseo-wizard-step" id="wizard-step-4" style="display: none;">
            <h3><?php _e('Optimize Your First Posts', 'mindfulseo'); ?></h3>
            <p><?php _e('See MindfulSEO in action. Select a few posts and the AI will optimize their SEO metadata.', 'mindfulseo'); ?></p>
            <p class="description"><?php esc_html_e( 'Suggestions favor posts not yet applied as optimized in MindfulSEO (newest published first). If everything published is already optimized, recent posts are shown instead.', 'mindfulseo' ); ?></p>

            <div id="wizard-posts-selection">
                <?php if (empty($recent_posts)) : ?>
                    <p class="mfseo-wizard-no-posts"><?php _e('No published content found. You can optimize posts later from the Batch Optimizer.', 'mindfulseo'); ?></p>
                <?php else : ?>
                    <div class="mfseo-wizard-posts-list">
                        <?php foreach ($recent_posts as $index => $post) : ?>
                            <label class="mfseo-wizard-post-item">
                                <input type="checkbox" name="wizard_posts[]" value="<?php echo $post->ID; ?>" <?php checked($index === 0); ?>>
                                <div class="post-details">
                                    <strong><?php echo esc_html($post->post_title); ?></strong>
                                    <span class="post-meta"><?php echo esc_html(get_post_type_object($post->post_type)->labels->singular_name); ?> &middot; <?php echo get_the_date('', $post); ?></span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div id="wizard-opt-progress" style="display: none;">
                <div class="mfseo-wizard-progress-bar">
                    <div class="mfseo-wizard-opt-bar-fill" id="wizard-opt-bar-fill"></div>
                </div>
                <p id="wizard-opt-current"><?php _e('Starting optimization...', 'mindfulseo'); ?></p>
            </div>
            
            <div id="wizard-opt-success" style="display: none;">
                <div class="mfseo-wizard-success-icon">
                    <span class="dashicons dashicons-yes-alt"></span>
                </div>
                <h4><?php _e('Your posts have been optimized!', 'mindfulseo'); ?></h4>
                <div id="wizard-results"></div>
                <div class="mfseo-wizard-actions" style="margin-top: 24px;">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=mindfulseo-batch-optimize&filter_status=optimized')); ?>" class="mfseo-wizard-btn mfseo-wizard-btn-primary" id="wizard-view-posts-btn">
                        <?php _e('View Optimized Posts', 'mindfulseo'); ?> &rarr;
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=mindfulseo-batch-optimize')); ?>" class="mfseo-wizard-btn mfseo-wizard-btn-secondary">
                        <?php _e('Optimize More Posts', 'mindfulseo'); ?>
                    </a>
                    <button type="button" class="mfseo-wizard-btn mfseo-wizard-btn-skip" id="wizard-finish-btn">
                        <?php _e('Go to Dashboard', 'mindfulseo'); ?>
                    </button>
                </div>
            </div>
            
            <div class="mfseo-wizard-actions" id="wizard-step-4-actions">
                <button type="button" class="mfseo-wizard-btn mfseo-wizard-btn-secondary" id="wizard-step-4-back">
                    &larr; <?php _e('Back', 'mindfulseo'); ?>
                </button>
                <button type="button" class="mfseo-wizard-btn mfseo-wizard-btn-primary" id="wizard-step-4-optimize" <?php disabled(empty($recent_posts)); ?>>
                    <span class="dashicons dashicons-superhero-alt"></span>
                    <?php _e('Optimize Now', 'mindfulseo'); ?>
                </button>
                <button type="button" class="mfseo-wizard-btn mfseo-wizard-btn-skip" id="wizard-step-4-skip">
                    <?php _e('Skip &mdash; finish setup', 'mindfulseo'); ?>
                </button>
            </div>
        </div>
        <?php
    }
    
    // ── AJAX handlers ────────────────────────────────────────
    
    public function ajax_save_step() {
        check_ajax_referer('mfseo_wizard_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $step = isset($_POST['step']) ? intval($_POST['step']) : 1;
        $data = isset($_POST['data']) ? $_POST['data'] : array();
        
        $settings = get_option('mindfulseo_settings', array());
        
        $connector = class_exists('MFSEO_AI_Connector') ? MFSEO_AI_Connector::get_instance() : null;
        
        foreach ($data as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            $value = sanitize_text_field(wp_unslash($value));
            
            if (in_array($key, array('openai_api_key', 'claude_api_key', 'openrouter_api_key'), true)) {
                if (!empty($value) && $connector) {
                    $settings[$key] = $connector->encrypt_api_key($value);
                } elseif (!empty($value)) {
                    $settings[$key] = $value;
                }
            } else {
                $settings[$key] = $value;
            }
        }

        /*
         * Wizard step 1 stores the user's vendor choice as ai_provider.
         * MFSEO_AI_Connector::initialize_providers() uses primary_provider for Direct mode — keep them aligned
         * so choosing Claude in the wizard does not still route requests to OpenAI first.
         */
        if ( $step === 1 && isset( $settings['ai_backend'] ) && $settings['ai_backend'] === 'direct'
            && isset( $settings['ai_provider'] ) && in_array( $settings['ai_provider'], array( 'openai', 'claude' ), true ) ) {
            $settings['primary_provider'] = $settings['ai_provider'];
        }
        
        update_option('mindfulseo_settings', $settings);

        if ($step === 1 && isset($settings['ai_backend']) && $settings['ai_backend'] === 'openrouter') {
            delete_transient('mfseo_provider_down_openrouter');
        }
        update_option('mindfulseo_wizard_state', array('step' => $step));
        
        wp_send_json_success(array('step' => $step));
    }
    
    public function ajax_complete_wizard() {
        check_ajax_referer('mfseo_wizard_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        update_option('mindfulseo_wizard_completed', true);
        delete_option('mindfulseo_wizard_needed');
        delete_option('mindfulseo_wizard_state');
        
        wp_send_json_success(array(
            'message' => __('Setup complete!', 'mindfulseo'),
            'redirect' => admin_url('admin.php?page=mindfulseo')
        ));
    }
    
    public function ajax_dismiss_wizard() {
        check_ajax_referer('mfseo_wizard_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        update_option('mindfulseo_wizard_dismissed', true);
        delete_option('mindfulseo_wizard_needed');
        
        wp_send_json_success();
    }
    
    public function ajax_test_api() {
        check_ajax_referer('mfseo_wizard_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $provider = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : '';
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        
        if (empty($api_key)) {
            wp_send_json_error(__('Please enter an API key.', 'mindfulseo'));
            return;
        }
        
        if (!class_exists('MFSEO_API_Tester')) {
            wp_send_json_error(__('API tester not available', 'mindfulseo'));
            return;
        }

        // Use the same models as MindfulSEO → Settings (wizard previously hard-coded Sonnet for Claude).
        $settings = get_option('mindfulseo_settings', array());

        if ($provider === 'openai') {
            $model  = ! empty($settings['openai_model']) ? sanitize_text_field($settings['openai_model']) : 'gpt-4o-mini';
            $result = MFSEO_API_Tester::test_openai_connection($api_key, $model);
        } elseif ($provider === 'claude') {
            $model  = ! empty($settings['claude_model']) ? sanitize_text_field($settings['claude_model']) : 'claude-sonnet-4-5';
            $result = MFSEO_API_Tester::test_claude_connection($api_key, $model);
        } elseif ($provider === 'openrouter') {
            $model = isset($_POST['model']) ? sanitize_text_field(wp_unslash($_POST['model'])) : '';
            if ($model === '') {
                $model = ! empty($settings['openrouter_model']) ? sanitize_text_field($settings['openrouter_model']) : 'qwen/qwen3.5-flash-02-23';
            }
            $result = MFSEO_API_Tester::test_openrouter_connection($api_key, $model);
        } else {
            wp_send_json_error(__('Invalid provider', 'mindfulseo'));
            return;
        }
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX: Analyze content to generate keywords + guidelines in one step
     */
    public function ajax_analyze_content() {
        check_ajax_referer('mfseo_wizard_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $deep_analysis = !empty($_POST['deep_analysis']);
        @set_time_limit($deep_analysis ? 600 : 300);

        $use_saved_context = isset( $_POST['use_saved_context'] ) && ( intval( wp_unslash( $_POST['use_saved_context'] ), 10 ) === 1 );

        $regenerate_keywords = ! array_key_exists( 'regenerate_keywords', $_POST )
            ? true
            : ( intval( wp_unslash( $_POST['regenerate_keywords'] ), 10 ) === 1 );
        $regenerate_guidelines = ! array_key_exists( 'regenerate_guidelines', $_POST )
            ? true
            : ( intval( wp_unslash( $_POST['regenerate_guidelines'] ), 10 ) === 1 );

        $snapshots = array(
            'keywords'   => '',
            'guidelines' => '',
        );
        if ( $use_saved_context ) {
            $snapshots = $this->get_strategy_snapshot_texts();
            $regenerate_keywords   = true;
            $regenerate_guidelines = true;
        }

        if ( ! $regenerate_keywords && ! $regenerate_guidelines ) {
            wp_send_json_error(
                __( 'Choose at least one area to regenerate.', 'mindfulseo' )
            );
        }

        $keywords_imported     = 0;
        $guidelines_imported   = 0;
        $errors                = array();
        $kw_preservation_count = 0;
        $gl_preservation_count = 0;
        $restored_kw           = 0;
        $restored_gl           = 0;
        
        // --- Generate Keywords ---
        if ( $regenerate_keywords ) {
            if ( class_exists( 'MFSEO_Content_Analyzer' ) && class_exists( 'MFSEO_Keyword_Manager' ) ) {
                $keyword_manager = MFSEO_Keyword_Manager::get_instance();
                $pres_kw                  = $keyword_manager->get_preservable_keyword_rows();
                $kw_preservation_count    = count( $pres_kw );
                $kw_ai_cap                = $this->get_wizard_ai_suggestion_cap( $kw_preservation_count );
                $keyword_manager->delete_keywords_by_source( 'Auto-generated' );

                $kw_snapshot_for_ai = '';
                if ( $use_saved_context && $snapshots['keywords'] !== '' ) {
                    $kw_snapshot_for_ai = $snapshots['keywords'];
                } else {
                    $kw_after = $this->get_strategy_snapshot_texts();
                    $kw_snapshot_for_ai = $kw_after['keywords'];
                }

                $analyzer = new MFSEO_Content_Analyzer();

                $suggestions = $analyzer->analyze_for_keywords( array(
                    'post_types' => array( 'post', 'page' ),
                    'deep_analysis' => $deep_analysis,
                    'wizard_saved_snapshot' => $kw_snapshot_for_ai,
                    'wizard_preservable_keyword_count' => count( $pres_kw ),
                    'wizard_extend_only_keywords' => $kw_preservation_count > 0,
                    'wizard_max_extra_keyword_rows' => $kw_ai_cap,
                    'wizard_total_keyword_cap' => $kw_preservation_count > 0 ? 0 : $kw_ai_cap,
                    'ai_usage_context' => 'setup_wizard_keywords',
                ) );

                if ( is_wp_error( $suggestions ) ) {
                    $errors[] = $suggestions->get_error_message();
                } else {
                    if ( is_array( $suggestions ) && $kw_preservation_count > 0 ) {
                        $suggestions = $this->filter_wizard_new_keyword_suggestions( $suggestions, $keyword_manager );
                        $suggestions = $this->balance_wizard_extend_keyword_suggestions( $suggestions, $pres_kw, $kw_ai_cap );
                    }
                    if ( ! empty( $suggestions ) ) {
                        foreach ( $suggestions as $suggestion ) {
                            $result = $keyword_manager->add_keyword( array(
                                'primary_keyword' => $suggestion['primary_keyword'],
                                'longtail_keyword' => $suggestion['longtail_keyword'],
                                'search_intent' => $suggestion['search_intent'],
                                'priority' => $suggestion['priority'],
                                'current_sessions' => isset( $suggestion['frequency'] ) ? $suggestion['frequency'] : 0,
                                'notes' => 'Auto-generated from setup wizard',
                                'csv_source' => 'Auto-generated',
                            ) );
                            if ( ! is_wp_error( $result ) ) {
                                $keywords_imported++;
                            }
                        }
                    } elseif ( $kw_preservation_count === 0 && is_array( $suggestions ) ) {
                        $errors[] = __(
                            'Keyword AI returned no rows we could import (often fixed by running Analyze again, enabling deep analysis, or checking the API model output in MindfulSEO → Settings → Usage). Your imported keywords, if any, are unchanged.',
                            'mindfulseo'
                        );
                    }
                }
                $restored_kw = $keyword_manager->reinsert_missing_keywords( $pres_kw );
                if ( $restored_kw > 0 ) {
                    error_log( 'MindfulSEO wizard: restored ' . (int) $restored_kw . ' keyword row(s); check API response wizard_preservation.' );
                }
            } else {
                $errors[] = 'Content Analyzer or Keyword Manager not available.';
            }
        }

        // --- Generate Guidelines ---
        if ($regenerate_guidelines) {
            if (class_exists('MFSEO_Content_Analyzer') && class_exists('MFSEO_Guidelines_Engine')) {
                $guidelines_engine = MFSEO_Guidelines_Engine::get_instance();
                $pres_gl                  = $guidelines_engine->get_preservable_guideline_rows();
                $gl_preservation_count    = count( $pres_gl );
                $gl_ai_cap                = $this->get_wizard_ai_suggestion_cap( $gl_preservation_count );
                $pre_delete_snapshots    = $this->get_strategy_snapshot_texts();
                $manual_guidelines_text  = $this->get_manual_guidelines_snapshot_text();

                $wizard_gl_payload = '';
                if ( $manual_guidelines_text !== '' ) {
                    $wizard_gl_payload = "=== USER-DEFINED AND IMPORTED RULES (authoritative — never contradict; extend with complementary rules only) ===\n" . $manual_guidelines_text;
                }
                if ( $pre_delete_snapshots['guidelines'] !== '' ) {
                    $wizard_gl_payload .= ( $wizard_gl_payload !== '' ? "\n\n" : '' ) . "=== FULL SAVED GUIDELINES SNAPSHOT (pre-refresh) ===\n" . $pre_delete_snapshots['guidelines'];
                }

                $guidelines_engine->delete_rules_by_source('Auto-generated');
                $guidelines_engine->delete_rules_by_source('AI-generated');

                $analyzer = new MFSEO_Content_Analyzer();

                $suggestions = $analyzer->analyze_for_guidelines(array(
                    'post_types' => array( 'post', 'page' ),
                    'deep_analysis' => $deep_analysis,
                    'wizard_guidelines_snapshot' => $wizard_gl_payload,
                    'wizard_preservable_guideline_count' => count( $pres_gl ),
                    'wizard_extend_only_guidelines' => $gl_preservation_count > 0,
                    'wizard_max_extra_guidelines' => $gl_ai_cap,
                    'ai_usage_context' => 'setup_wizard_guidelines',
                ));

                if ( ! empty( $suggestions ) ) {
                    $g_scale = max( 0.5, min( 2.0, $gl_ai_cap / 30.0 ) );
                    if ( ! empty( $suggestions['ai_guidelines'] ) ) {
                        $suggestions['ai_guidelines'] = array_slice( $suggestions['ai_guidelines'], 0, $gl_ai_cap );
                    }
                    if ( ! empty( $suggestions['capitalize_terms'] ) ) {
                        $capMax = max( 8, min( 22, (int) round( 8 * $g_scale ) ) );
                        if ( $gl_preservation_count > 0 ) {
                            $capMax = min( $capMax, 6 );
                        }
                        $suggestions['capitalize_terms'] = array_slice( $suggestions['capitalize_terms'], 0, $capMax );
                    }
                    if ( empty( $suggestions['ai_succeeded'] ) ) {
                        if ( ! empty( $suggestions['avoid_terms'] ) ) {
                            $avoidMax = max( 12, min( 28, (int) round( 12 * $g_scale ) ) );
                            $suggestions['avoid_terms'] = array_slice( $suggestions['avoid_terms'], 0, $avoidMax );
                        }
                        if ( ! empty( $suggestions['common_phrases'] ) ) {
                            $phraseMax = max( 6, min( 16, (int) round( 6 * $g_scale ) ) );
                            $suggestions['common_phrases'] = array_slice( $suggestions['common_phrases'], 0, $phraseMax );
                        }
                        if ( ! empty( $suggestions['semantic_avoid_terms'] ) ) {
                            $semMax = max( 8, min( 20, (int) round( 8 * $g_scale ) ) );
                            $suggestions['semantic_avoid_terms'] = array_slice( $suggestions['semantic_avoid_terms'], 0, $semMax );
                        }
                    }
                }

                if (!empty($suggestions)) {
                if (!empty($suggestions['ai_error'])) {
                    $errors[] = 'AI semantic guidelines failed: ' . $suggestions['ai_error'];
                }

                $ai_cap_lower = array();
                if (!empty($suggestions['ai_guidelines'])) {
                    foreach ($suggestions['ai_guidelines'] as $ar) {
                        if (isset($ar['type']) && $ar['type'] === 'capitalize' && !empty($ar['preferred'])) {
                            $ai_cap_lower[ strtolower( $ar['preferred'] ) ] = true;
                        }
                    }
                }

                // AI-generated guidelines first (all types)
                if (!empty($suggestions['ai_guidelines'])) {
                    foreach ($suggestions['ai_guidelines'] as $ai_rule) {
                        $rule_type = $ai_rule['type'];
                        $avoid = isset($ai_rule['avoid']) ? $ai_rule['avoid'] : '';
                        $preferred = $ai_rule['preferred'];
                        $context = !empty($ai_rule['context']) ? $ai_rule['context'] : 'AI-generated';

                        if ($rule_type === 'capitalize' && empty($avoid)) {
                            $avoid = strtolower($preferred);
                        }

                        $result = $guidelines_engine->add_rule(array(
                            'rule_type' => $rule_type,
                            'avoid_term' => $avoid,
                            'preferred_term' => $preferred,
                            'context' => $context,
                            'guideline_source' => 'AI-generated',
                            'active' => true,
                        ));
                        if (!is_wp_error($result)) {
                            $guidelines_imported++;
                        }
                    }
                }

                // Pattern-based capitalize: capped; after AI; skip duplicates of AI capitalize
                if (!empty($suggestions['capitalize_terms'])) {
                    $ai_ok = !empty($suggestions['ai_succeeded']);
                    if ( $gl_preservation_count > 0 ) {
                        $max_pat = $ai_ok
                            ? max( 4, min( 10, (int) round( $gl_ai_cap * 0.22 ) ) )
                            : max( 24, min( 40, (int) round( $gl_ai_cap * 0.48 ) ) );
                    } else {
                        $max_pat = $ai_ok
                            ? max( 10, min( 24, (int) round( $gl_ai_cap * 0.38 ) ) )
                            : max( 24, min( 40, (int) round( $gl_ai_cap * 0.48 ) ) );
                    }
                    $added_pat = 0;
                    foreach ($suggestions['capitalize_terms'] as $term) {
                        if ($added_pat >= $max_pat) {
                            break;
                        }
                        $low = strtolower($term);
                        if ($ai_ok && isset($ai_cap_lower[$low])) {
                            continue;
                        }
                        $result = $guidelines_engine->add_rule(array(
                            'rule_type' => 'capitalize',
                            'avoid_term' => $low,
                            'preferred_term' => $term,
                            'context' => 'Auto-generated from setup wizard',
                            'guideline_source' => 'Auto-generated',
                            'active' => true,
                        ));
                        if (!is_wp_error($result)) {
                            $guidelines_imported++;
                            $added_pat++;
                        }
                    }
                }

                // Fallback: pattern-based rules only when AI didn't succeed
                if (empty($suggestions['ai_succeeded'])) {
                    if (!empty($suggestions['avoid_terms'])) {
                        foreach ($suggestions['avoid_terms'] as $avoid_rule) {
                            $result = $guidelines_engine->add_rule(array(
                                'rule_type' => 'avoid_term',
                                'avoid_term' => $avoid_rule['avoid'],
                                'preferred_term' => $avoid_rule['preferred'],
                                'context' => 'Auto-generated from setup wizard',
                                'guideline_source' => 'Auto-generated',
                                'active' => true,
                            ));
                            if (!is_wp_error($result)) {
                                $guidelines_imported++;
                            }
                        }
                    }
                    
                    if (!empty($suggestions['common_phrases'])) {
                        foreach (array_slice($suggestions['common_phrases'], 0, 15) as $phrase) {
                            $result = $guidelines_engine->add_rule(array(
                                'rule_type' => 'seo_friendly',
                                'avoid_term' => '',
                                'preferred_term' => $phrase,
                                'context' => 'Common phrase from setup wizard analysis',
                                'guideline_source' => 'Auto-generated',
                                'active' => true,
                            ));
                            if (!is_wp_error($result)) {
                                $guidelines_imported++;
                            }
                        }
                    }
                    
                    if (!empty($suggestions['semantic_avoid_terms'])) {
                        foreach ($suggestions['semantic_avoid_terms'] as $semantic_rule) {
                            $result = $guidelines_engine->add_rule(array(
                                'rule_type' => 'avoid_term',
                                'avoid_term' => $semantic_rule['avoid'],
                                'preferred_term' => $semantic_rule['preferred'],
                                'context' => !empty($semantic_rule['context']) ? $semantic_rule['context'] : 'AI-generated domain-specific rule',
                                'guideline_source' => 'AI-generated',
                                'active' => true,
                            ));
                            if (!is_wp_error($result)) {
                                $guidelines_imported++;
                            }
                        }
                    }
                }
            }
                $restored_gl = $guidelines_engine->reinsert_missing_guidelines( $pres_gl );
                if ( $restored_gl > 0 ) {
                    error_log( 'MindfulSEO wizard: restored ' . (int) $restored_gl . ' guideline row(s); check API response wizard_preservation.' );
                }
            } else {
                $errors[] = 'Guidelines Engine not available.';
            }
        }
        
        $totals = $this->get_existing_strategy_counts();
        wp_send_json_success(array(
            'keywords_count'     => $keywords_imported,
            'guidelines_count'   => $guidelines_imported,
            'keywords_total'     => $totals['keywords'],
            'guidelines_total'   => $totals['guidelines'],
            'errors'             => $errors,
            'wizard_preservation' => array(
                'keywords_protected'     => $kw_preservation_count,
                'keywords_restored'      => $restored_kw,
                'guidelines_protected'   => $gl_preservation_count,
                'guidelines_restored'    => $restored_gl,
            ),
        ));
    }
    
    public function ajax_import_csv() {
        check_ajax_referer('mfseo_wizard_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        if (empty($_FILES['csv_file'])) {
            wp_send_json_error('No file uploaded');
        }
        
        if (!class_exists('MFSEO_CSV_Importer') || !class_exists('MFSEO_Keyword_Manager')) {
            wp_send_json_error('Required classes not available');
        }
        
        $keyword_manager = MFSEO_Keyword_Manager::get_instance();
        $kw_csv_source   = ! empty( $_FILES['csv_file']['name'] )
            ? sanitize_file_name( wp_unslash( $_FILES['csv_file']['name'] ) )
            : 'import.csv';

        $result = $keyword_manager->import_csv(
            $_FILES['csv_file'],
            array(
                'wizard_merge' => true,
                'csv_source'   => $kw_csv_source,
            )
        );

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        $imported = isset($result['imported']) ? (int) $result['imported'] : 0;
        $updated  = isset($result['updated'] ) ? (int) $result['updated']  : 0;
        $skipped = isset($result['skipped']) ? (int) $result['skipped'] : 0;
        $total = isset($result['total']) ? (int) $result['total'] : 0;

        $msg = sprintf(
            /* translators: 1: new rows, 2: updated rows, 3: skipped */
            __( '%1$d keywords added, %2$d updated from file (%3$d skipped or empty rows)', 'mindfulseo' ),
            $imported,
            $updated,
            $skipped
        );

        $totals = $this->get_existing_strategy_counts();
        wp_send_json_success(array(
            'imported' => $imported,
            'skipped' => $skipped,
            'duplicates' => 0,
            'total' => $total,
            'message' => $msg,
            'keywords_total' => $totals['keywords'],
            'guidelines_total' => $totals['guidelines'],
        ));
    }
    
    public function ajax_import_guidelines_csv() {
        check_ajax_referer('mfseo_wizard_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        if (empty($_FILES['csv_file'])) {
            wp_send_json_error('No file uploaded');
        }
        
        if (!class_exists('MFSEO_Guidelines_Engine')) {
            wp_send_json_error('Guidelines Engine not available');
        }
        
        $file = $_FILES['csv_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = array('csv', 'txt', 'md', 'markdown');
        if (!in_array($ext, $allowed)) {
            wp_send_json_error('Invalid file type. Accepts: .md, .csv, .txt');
        }
        
        $guidelines_engine = MFSEO_Guidelines_Engine::get_instance();
        $gl_source_name    = sanitize_file_name( wp_unslash( $file['name'] ) );
        $is_markdown       = in_array( $ext, array( 'md', 'markdown' ) );
        
        // Auto-detect: if .txt, peek at content to decide format
        if ($ext === 'txt') {
            $peek = file_get_contents($file['tmp_name'], false, null, 0, 500);
            if ($peek && (strpos($peek, '**Avoid:') !== false || strpos($peek, '**Capitalize:') !== false || preg_match('/^#{2,3}\s/m', $peek))) {
                $is_markdown = true;
            }
        }
        
        if ($is_markdown) {
            // Parse as markdown using the guidelines engine parser
            $content = file_get_contents($file['tmp_name']);
            if (empty($content)) {
                wp_send_json_error('File is empty');
            }
            
            $parsed_rules = $guidelines_engine->parse_markdown_guidelines($content);
            if (is_wp_error($parsed_rules)) {
                wp_send_json_error($parsed_rules->get_error_message());
            }
            
            $imported = 0;
            $skipped = 0;
            foreach ($parsed_rules as $rule) {
                $result = $guidelines_engine->upsert_rule_wizard_import(array(
                    'rule_type' => $rule['rule_type'],
                    'avoid_term' => isset($rule['avoid_term']) ? $rule['avoid_term'] : '',
                    'preferred_term' => $rule['preferred_term'],
                    'context' => isset($rule['context']) ? $rule['context'] : 'Imported via setup wizard',
                    'guideline_source' => $gl_source_name,
                    'active' => true,
                ));
                if (!is_wp_error($result)) {
                    $imported++;
                } else {
                    $skipped++;
                }
            }
            
            $totals = $this->get_existing_strategy_counts();
            wp_send_json_success(array(
                'imported' => $imported,
                'skipped' => $skipped,
                'format' => 'markdown',
                'keywords_total' => $totals['keywords'],
                'guidelines_total' => $totals['guidelines'],
            ));
            return;
        }
        
        // CSV parsing
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            wp_send_json_error('Cannot open file');
        }
        
        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            wp_send_json_error('File is empty');
        }
        
        // Normalize headers (strip BOM, case, underscores)
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $header = array_map(function($h) {
            return strtoupper(trim(str_replace(array('_', '-'), ' ', $h)));
        }, $header);
        
        if (!in_array('RULE TYPE', $header)) {
            fclose($handle);
            wp_send_json_error('Missing required column: RULE TYPE. This looks like CSV format — use column headers: RULE TYPE, AVOID TERM, PREFERRED TERM');
        }
        
        $imported = 0;
        $skipped = 0;
        
        while (($row = fgetcsv($handle)) !== false) {
            if (empty(array_filter($row))) continue;
            
            $data = array();
            foreach ($header as $i => $col) {
                $data[$col] = isset($row[$i]) ? trim($row[$i]) : '';
            }
            
            $rule_type = strtolower($data['RULE TYPE']);
            $valid_types = array('capitalize', 'preferred_term', 'avoid_term', 'seo_friendly');
            if (!in_array($rule_type, $valid_types)) {
                $skipped++;
                continue;
            }
            
            $result = $guidelines_engine->upsert_rule_wizard_import(array(
                'rule_type' => $rule_type,
                'avoid_term' => isset($data['AVOID TERM']) ? $data['AVOID TERM'] : (isset($data['AVOID FROM']) ? $data['AVOID FROM'] : ''),
                'preferred_term' => isset($data['PREFERRED TERM']) ? $data['PREFERRED TERM'] : (isset($data['PREFERRED TO']) ? $data['PREFERRED TO'] : ''),
                'context' => isset($data['CONTEXT']) ? $data['CONTEXT'] : 'Imported from CSV via setup wizard',
                'guideline_source' => $gl_source_name,
                'active' => true,
            ));
            
            if (!is_wp_error($result)) {
                $imported++;
            } else {
                $skipped++;
            }
        }
        
        fclose($handle);
        
        $totals = $this->get_existing_strategy_counts();
        wp_send_json_success(array(
            'imported' => $imported,
            'skipped' => $skipped,
            'format' => 'csv',
            'keywords_total' => $totals['keywords'],
            'guidelines_total' => $totals['guidelines'],
        ));
    }
}
