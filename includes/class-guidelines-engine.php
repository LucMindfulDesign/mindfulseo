<?php
/**
 * Guidelines Engine Class
 *
 * Parses markdown guideline files and applies language rules
 *
 * @package MindfulSEO
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_Guidelines_Engine {
    
    /**
     * The single instance of the class
     * 
     * @var MFSEO_Guidelines_Engine
     */
    private static $instance = null;
    
    /**
     * Database table name
     */
    private $table_name;
    
    /**
     * Upload directory
     */
    private $upload_dir;
    
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
        $this->table_name = $wpdb->prefix . 'mindfulseo_guidelines';
        $this->upload_dir = MINDFULSEO_GUIDELINES_DIR;
        $this->ensure_upload_directory();
    }

    /**
     * Check if the guidelines table exists
     *
     * @return bool
     */
    private function table_exists() {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->table_name)) === $this->table_name;
    }
    
    /**
     * Ensure upload directory exists
     */
    private function ensure_upload_directory() {
        if (!file_exists($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
            
            // Add .htaccess to protect directory
            $htaccess = $this->upload_dir . '.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, 'Deny from all');
            }
            
            // Add index.php to prevent directory listing
            $index = $this->upload_dir . 'index.php';
            if (!file_exists($index)) {
                file_put_contents($index, '<?php // Silence is golden');
            }
        }
    }
    
    /**
     * Import markdown guidelines file
     *
     * @param array $file $_FILES array element
     * @return array|WP_Error Import result or error
     */
    public function import_guidelines($file) {
        // Validate file
        $validation = $this->validate_file($file);
        if (is_wp_error($validation)) {
            return $validation;
        }
        
        // Generate unique filename
        $filename = $this->generate_filename($file['name']);
        $filepath = $this->upload_dir . $filename;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            return new WP_Error(
                'upload_failed',
                __('Failed to upload file.', 'mindfulseo')
            );
        }
        
        // Read and parse markdown
        $content = file_get_contents($filepath);
        $parsed_rules = $this->parse_markdown_guidelines($content);
        
        if (is_wp_error($parsed_rules)) {
            unlink($filepath);
            return $parsed_rules;
        }
        
        // Import rules to database
        $import_result = $this->import_rules_to_database($parsed_rules, $filename);
        
        if (is_wp_error($import_result)) {
            return $import_result;
        }
        
        // Log import
        if (class_exists('MFSEO_Logger')) {
            $logger = MFSEO_Logger::get_instance();
            if ($logger) {
                $logger->log_info('Guidelines imported', array(
                    'filename' => $filename,
                    'rules_count' => count($parsed_rules)
                ));
            }
        }
        
        return array(
            'filename' => $filename,
            'rules_imported' => count($parsed_rules)
        );
    }
    
    /**
     * Validate uploaded file
     *
     * @param array $file $_FILES array element
     * @return true|WP_Error
     */
    private function validate_file($file) {
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error(
                'upload_error',
                sprintf(__('Upload error: %s', 'mindfulseo'), $file['error'])
            );
        }
        
        // Check file size (max 2MB for markdown)
        if ($file['size'] > 2097152) {
            return new WP_Error(
                'file_too_large',
                __('File is too large. Maximum size is 2MB.', 'mindfulseo')
            );
        }
        
        // Check file extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, array('md', 'txt', 'markdown'))) {
            return new WP_Error(
                'invalid_file_type',
                __('Invalid file type. Allowed types: .md, .txt, .markdown', 'mindfulseo')
            );
        }
        
        return true;
    }
    
    /**
     * Generate unique filename
     *
     * @param string $original_name Original filename
     * @return string Unique filename
     */
    private function generate_filename($original_name) {
        $ext = pathinfo($original_name, PATHINFO_EXTENSION);
        $name = pathinfo($original_name, PATHINFO_FILENAME);
        $name = sanitize_file_name($name);
        
        $unique = date('Y-m-d_H-i-s') . '_' . wp_generate_password(8, false);
        return $name . '_' . $unique . '.' . $ext;
    }
    
    /**
     * Parse markdown guidelines
     *
     * Extracts rules from markdown content
     *
     * @param string $markdown_content Markdown content
     * @return array|WP_Error Parsed rules or error
     */
    public function parse_markdown_guidelines($markdown_content) {
        $rules = array();
        
        $lines = explode("\n", $markdown_content);
        $current_section = '';
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (empty($line)) {
                continue;
            }
            
            // Detect section headings (## or ###)
            if (preg_match('/^#{2,3}\s+(.+)/', $line, $matches)) {
                $current_section = trim($matches[1]);
                continue;
            }
            
            // Must be a list item
            if (!preg_match('/^[•\-\*]\s+/', $line)) {
                continue;
            }
            
            // --- Avoid term: "X" → Use "Y" (Unicode arrow, ASCII ->, =>; multiple quoted alternatives after Use) ---
            if (preg_match('/\*\*Avoid:\*\*\s*"([^"]+)"\s*(?:→|->|=>)\s*Use\s+(.+)/iu', $line, $matches)) {
                $avoid = trim($matches[1]);
                $after_use = trim($matches[2]);
                $preferred_parts = array();
                if (preg_match_all('/"([^"]+)"/', $after_use, $quoted)) {
                    foreach ($quoted[1] as $p) {
                        $p = rtrim(trim($p), ',');
                        if ($p !== '') {
                            $preferred_parts[] = $p;
                        }
                    }
                }
                $preferred = ! empty($preferred_parts) ? implode(' / ', $preferred_parts) : '';

                $context = $current_section;
                if (preg_match('/\(([^)]+)\)\s*$/', $line, $note)) {
                    $context .= ' — ' . trim($note[1]);
                }

                if ($avoid !== '' && $preferred !== '') {
                    $rules[] = array(
                        'rule_type' => 'avoid_term',
                        'avoid_term' => $avoid,
                        'preferred_term' => $preferred,
                        'context' => $context,
                    );
                }
                continue;
            }
            
            // --- Capitalize: Term1, Term2 OR Capitalize: Term (parenthetical) ---
            if (preg_match('/\*\*Capitalize:\*\*\s*(.+)/i', $line, $matches)) {
                $raw = trim($matches[1]);
                
                // Check if this is a single term with parenthetical context
                if (preg_match('/^([^,(]+)\s+\((.+)\)\s*$/', $raw, $single)) {
                    $term = trim($single[1]);
                    $ctx = trim($single[2]);
                    $rules[] = array(
                        'rule_type' => 'capitalize',
                        'avoid_term' => strtolower($term),
                        'preferred_term' => $term,
                        'context' => $current_section . ' — ' . $ctx
                    );
                } else {
                    $terms = array_map('trim', explode(',', $raw));
                    foreach ($terms as $term) {
                        if (empty($term)) continue;
                        $rules[] = array(
                            'rule_type' => 'capitalize',
                            'avoid_term' => strtolower($term),
                            'preferred_term' => $term,
                            'context' => $current_section
                        );
                    }
                }
                continue;
            }
            
            // --- Required: Always use "X" for Y → capitalize rule ---
            if (preg_match('/\*\*Required:\*\*\s*(.+)/i', $line, $matches)) {
                $text = trim($matches[1]);
                if (preg_match('/(?:Always\s+use\s+)?"([^"]+)"\s+(?:for|before|when)\s+(.+)/i', $text, $req)) {
                    $preferred = rtrim(trim($req[1]), ',');
                    $for_what = rtrim(trim($req[2]), '.');
                    $rules[] = array(
                        'rule_type' => 'capitalize',
                        'avoid_term' => strtolower($preferred),
                        'preferred_term' => $preferred,
                        'context' => $current_section . ' — ' . $for_what
                    );
                }
                continue;
            }
            
            // --- Preferred: Use "X" for/when Y → preferred_term rule (multiple quoted terms → one rule each) ---
            if (preg_match('/\*\*Preferred:\*\*\s*(.+)/i', $line, $matches)) {
                $text = trim($matches[1]);
                $ctx = $current_section;
                if (preg_match('/\(([^)]+)\)/', $text, $note)) {
                    $ctx .= ' — ' . trim($note[1]);
                } elseif (preg_match('/for\s+(.+)/i', $text, $note)) {
                    $ctx .= ' — for ' . rtrim(trim($note[1]), '.');
                }
                if (preg_match_all('/"([^"]+)"/', $text, $all_pref)) {
                    foreach ($all_pref[1] as $preferred) {
                        $preferred = rtrim(trim($preferred), ',');
                        if ($preferred === '') {
                            continue;
                        }
                        $rules[] = array(
                            'rule_type' => 'preferred_term',
                            'avoid_term' => '',
                            'preferred_term' => $preferred,
                            'context' => $ctx,
                        );
                    }
                }
                continue;
            }
            
            // --- SEO-Friendly: "X" instead of "Y" ---
            if (preg_match('/\*\*SEO[\-\s]*Friendly:\*\*\s*"([^"]+)"\s*instead\s+of\s*"([^"]+)"/i', $line, $matches)) {
                $rules[] = array(
                    'rule_type' => 'seo_friendly',
                    'avoid_term' => rtrim(trim($matches[2]), ','),
                    'preferred_term' => rtrim(trim($matches[1]), ','),
                    'context' => $current_section
                );
                continue;
            }
            
            // --- SEO-Friendly: free-form (no quoted pair) → store as guideline note ---
            if (preg_match('/\*\*SEO[\-\s]*Friendly:\*\*\s*(.+)/i', $line, $matches)) {
                $text = rtrim(trim($matches[1]), '.');
                $rules[] = array(
                    'rule_type' => 'seo_friendly',
                    'avoid_term' => '',
                    'preferred_term' => $text,
                    'context' => $current_section
                );
                continue;
            }
            
            // --- Meta/Content strategy lines → store as seo_friendly notes ---
            if (preg_match('/\*\*(Meta[^:]*|Content|Strategy):\*\*\s*(.+)/i', $line, $matches)) {
                $text = rtrim(trim($matches[2]), '.');
                $rules[] = array(
                    'rule_type' => 'seo_friendly',
                    'avoid_term' => '',
                    'preferred_term' => $text,
                    'context' => $current_section . ' — ' . trim($matches[1])
                );
                continue;
            }
        }
        
        if (empty($rules)) {
            return new WP_Error(
                'no_rules_found',
                __('No guidelines rules found in the file. Please check the format.', 'mindfulseo')
            );
        }
        
        return $rules;
    }
    
    /**
     * Import rules to database
     *
     * @param array $rules Parsed rules
     * @param string $source_filename Original filename
     * @return true|WP_Error
     */
    private function import_rules_to_database($rules, $source_filename) {
        global $wpdb;

        if (!$this->table_exists()) {
            return new WP_Error('table_missing', __('Guidelines table is not available. Try deactivating and reactivating the plugin.', 'mindfulseo'));
        }
        
        foreach ($rules as $rule) {
            $wpdb->insert(
                $this->table_name,
                array(
                    'rule_type' => $rule['rule_type'],
                    'avoid_term' => $rule['avoid_term'],
                    'preferred_term' => $rule['preferred_term'],
                    'context' => $rule['context'],
                    'guideline_source' => $source_filename,
                    'active' => 1,
                    'created_date' => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%s', '%s', '%d', '%s')
            );
        }
        
        return true;
    }
    
    /**
     * Get all active rules
     *
     * @param array $args Query arguments
     * @return array
     */
    public function get_all_rules($args = array()) {
        global $wpdb;

        if (!$this->table_exists()) {
            return array();
        }
        
        $defaults = array(
            'rule_type' => '',
            'active_only' => true,
            'orderby' => 'rule_type',
            'order' => 'ASC'
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $query = "SELECT * FROM {$this->table_name} WHERE 1=1";
        $query_params = array();
        
        if ($args['active_only']) {
            $query .= " AND active = 1";
        }
        
        if (!empty($args['rule_type'])) {
            $query .= " AND rule_type = %s";
            $query_params[] = $args['rule_type'];
        }
        
        $query .= " ORDER BY {$args['orderby']} {$args['order']}";
        
        if (!empty($query_params)) {
            $query = $wpdb->prepare($query, $query_params);
        }
        
        return $wpdb->get_results($query);
    }

    /**
     * Rules that must survive wizard guideline refresh (not AI/Auto disposable buckets).
     *
     * @return object[] Full row objects.
     */
    public function get_preservable_guideline_rows() {
        global $wpdb;
        if ( ! $this->table_exists() ) {
            return array();
        }
        return $wpdb->get_results(
            "SELECT * FROM {$this->table_name}
             WHERE guideline_source IS NULL
                OR TRIM( IFNULL( guideline_source, '' ) ) = ''
                OR LOWER( TRIM( guideline_source ) ) NOT IN ( 'auto-generated', 'ai-generated' )",
            OBJECT
        );
    }

    /**
     * Restore preservable guideline rows if missing (same type + avoid + preferred).
     *
     * @param object[] $rows From get_preservable_guideline_rows() before AI deletes.
     * @return int Rows re-inserted.
     */
    public function reinsert_missing_guidelines( $rows ) {
        global $wpdb;
        if ( ! $this->table_exists() || empty( $rows ) || ! is_array( $rows ) ) {
            return 0;
        }
        $n = 0;
        foreach ( $rows as $row ) {
            $type = isset( $row->rule_type ) ? (string) $row->rule_type : '';
            if ( $type === '' ) {
                continue;
            }
            $avoid = isset( $row->avoid_term ) ? (string) $row->avoid_term : '';
            $pref  = isset( $row->preferred_term ) ? (string) $row->preferred_term : '';
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$this->table_name} WHERE rule_type = %s AND avoid_term = %s AND preferred_term = %s LIMIT 1",
                    $type,
                    $avoid,
                    $pref
                )
            );
            if ( $exists ) {
                continue;
            }
            $src    = isset( $row->guideline_source ) && $row->guideline_source !== '' ? $row->guideline_source : 'Manual';
            $ctx    = isset( $row->context ) ? (string) $row->context : '';
            $active = isset( $row->active ) ? ( (int) $row->active ? 1 : 0 ) : 1;
            $ins    = $wpdb->insert(
                $this->table_name,
                array(
                    'rule_type'        => $type,
                    'avoid_term'       => $avoid,
                    'preferred_term'   => $pref,
                    'context'          => $ctx,
                    'guideline_source' => $src,
                    'active'           => $active,
                    'created_date'     => isset( $row->created_date ) ? $row->created_date : current_time( 'mysql' ),
                ),
                array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
            );
            if ( $ins ) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Text snapshot of imported/manual rules (excludes Auto-generated and AI-generated) for AI prompts.
     *
     * @param int $max_chars Max character length.
     * @param int $max_rules Max rules to include.
     * @return string
     */
    public function get_editor_policy_snapshot_text( $max_chars = 12000, $max_rules = 250 ) {
        $rules = $this->get_all_rules( array( 'active_only' => true ) );
        $skip  = array( 'Auto-generated', 'AI-generated' );
        $lines = array();
        foreach ( $rules as $rule ) {
            $src = isset( $rule->guideline_source ) ? $rule->guideline_source : '';
            if ( in_array( $src, $skip, true ) ) {
                continue;
            }
            $at = isset( $rule->avoid_term ) ? $rule->avoid_term : '';
            $lines[] = sprintf(
                '- [%s] %s → %s (source: %s)',
                $rule->rule_type,
                ( $at !== '' && $at !== null ) ? $at : '—',
                $rule->preferred_term,
                $src
            );
        }
        $out = implode( "\n", array_slice( $lines, 0, $max_rules ) );
        if ( strlen( $out ) > $max_chars ) {
            $out = substr( $out, 0, $max_chars ) . "\n[…]";
        }
        return $out;
    }
    
    /**
     * Apply guidelines to text
     *
     * @param string $text Text to process
     * @param array $options Options
     * @return array Processed text and applied rules
     */
    public function apply_to_text($text, $options = array()) {
        $defaults = array(
            'apply_avoid_terms' => true,
            'apply_capitalization' => true,
            'apply_seo_friendly' => true
        );
        
        $options = wp_parse_args($options, $defaults);
        
        $rules = $this->get_all_rules();
        $applied_rules = array();
        $original_text = $text;
        
        foreach ($rules as $rule) {
            $rule_applied = false;
            
            switch ($rule->rule_type) {
                case 'avoid_term':
                    if ($options['apply_avoid_terms'] && !empty($rule->avoid_term)) {
                        $pattern = '/\b' . preg_quote($rule->avoid_term, '/') . '\b/i';
                        if (preg_match($pattern, $text)) {
                            $text = preg_replace($pattern, $rule->preferred_term, $text);
                            $rule_applied = true;
                        }
                    }
                    break;
                
                case 'capitalize':
                    if ($options['apply_capitalization'] && !empty($rule->preferred_term)) {
                        $pattern = '/\b' . preg_quote($rule->preferred_term, '/') . '\b/i';
                        if (preg_match($pattern, $text)) {
                            $text = preg_replace($pattern, $rule->preferred_term, $text);
                            $rule_applied = true;
                        }
                    }
                    break;
                
                case 'seo_friendly':
                    if ($options['apply_seo_friendly'] && !empty($rule->avoid_term)) {
                        $pattern = '/\b' . preg_quote($rule->avoid_term, '/') . '\b/i';
                        if (preg_match($pattern, $text)) {
                            $text = preg_replace($pattern, $rule->preferred_term, $text);
                            $rule_applied = true;
                        }
                    }
                    break;
            }
            
            if ($rule_applied) {
                $applied_rules[] = array(
                    'rule_type' => $rule->rule_type,
                    'from' => $rule->avoid_term,
                    'to' => $rule->preferred_term,
                    'context' => $rule->context
                );
            }
        }
        
        return array(
            'original' => $original_text,
            'processed' => $text,
            'applied_rules' => $applied_rules,
            'rules_count' => count($applied_rules)
        );
    }
    
    /**
     * Generate AI context from guidelines
     *
     * Creates a formatted string for AI prompts
     *
     * @return string
     */
    private $_cached_context = null;
    
    public function generate_ai_context() {
        if ($this->_cached_context !== null) {
            return $this->_cached_context;
        }
        
        $rules = $this->get_all_rules();
        
        if (empty($rules)) {
            $this->_cached_context = '';
            return '';
        }
        
        $context = "LANGUAGE GUIDELINES:\n";
        $context .= "Please follow these brand-specific language rules:\n\n";
        
        // Group rules by type
        $grouped_rules = array();
        foreach ($rules as $rule) {
            $grouped_rules[$rule->rule_type][] = $rule;
        }
        
        // Format avoid terms
        if (isset($grouped_rules['avoid_term'])) {
            $context .= "AVOID THESE TERMS:\n";
            foreach ($grouped_rules['avoid_term'] as $rule) {
                $context .= "- Avoid \"{$rule->avoid_term}\" → Use \"{$rule->preferred_term}\"\n";
            }
            $context .= "\n";
        }
        
        // Format capitalizations
        if (isset($grouped_rules['capitalize'])) {
            $context .= "CAPITALIZE THESE TERMS:\n";
            $terms = array_map(function($rule) {
                return $rule->preferred_term;
            }, $grouped_rules['capitalize']);
            $context .= "- " . implode(', ', $terms) . "\n\n";
        }
        
        // Format SEO-friendly terms
        if (isset($grouped_rules['seo_friendly'])) {
            $context .= "SEO-FRIENDLY ALTERNATIVES:\n";
            foreach ($grouped_rules['seo_friendly'] as $rule) {
                if (!empty($rule->avoid_term)) {
                    $context .= "- Use \"{$rule->preferred_term}\" instead of \"{$rule->avoid_term}\"\n";
                } else {
                    $context .= "- Target phrase to incorporate: \"{$rule->preferred_term}\"\n";
                }
            }
            $context .= "\n";
        }
        
        // Format preferred terms
        if (isset($grouped_rules['preferred_term'])) {
            $context .= "PREFERRED TERMINOLOGY:\n";
            foreach ($grouped_rules['preferred_term'] as $rule) {
                $context .= "- Use: \"{$rule->preferred_term}\"\n";
            }
        }
        
        $this->_cached_context = $context;
        return $context;
    }
    
    /**
     * Check text for guideline violations
     *
     * @param string $text Text to check
     * @return array Violations found
     */
    public function check_violations($text) {
        $rules = $this->get_all_rules();
        $violations = array();
        
        foreach ($rules as $rule) {
            if ($rule->rule_type === 'avoid_term' && !empty($rule->avoid_term)) {
                $pattern = '/\b' . preg_quote($rule->avoid_term, '/') . '\b/i';
                if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as $match) {
                        $violations[] = array(
                            'type' => 'avoid_term',
                            'term' => $match[0],
                            'position' => $match[1],
                            'suggestion' => $rule->preferred_term,
                            'rule_id' => $rule->id
                        );
                    }
                }
            }
        }
        
        return $violations;
    }
    
    /**
     * Get guidelines statistics
     *
     * @return array
     */
    public function get_statistics() {
        global $wpdb;

        if (!$this->table_exists()) {
            return array(
                'total' => 0,
                'by_type' => array(),
                'sources' => array(),
            );
        }
        
        $stats = array();
        $stats['total'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE active = 1");
        $stats['by_type'] = $wpdb->get_results(
            "SELECT rule_type, COUNT(*) as count 
             FROM {$this->table_name} 
             WHERE active = 1 
             GROUP BY rule_type",
            OBJECT_K
        );
        $stats['sources'] = $wpdb->get_results(
            "SELECT guideline_source, COUNT(*) as count 
             FROM {$this->table_name} 
             GROUP BY guideline_source"
        );
        
        return $stats;
    }
    
    /**
     * Deactivate rule
     *
     * @param int $rule_id Rule ID
     * @return bool
     */
    public function deactivate_rule($rule_id) {
        global $wpdb;

        if (!$this->table_exists()) {
            return false;
        }
        
        return $wpdb->update(
            $this->table_name,
            array('active' => 0),
            array('id' => $rule_id),
            array('%d'),
            array('%d')
        ) !== false;
    }
    
    /**
     * Activate rule
     *
     * @param int $rule_id Rule ID
     * @return bool
     */
    public function activate_rule($rule_id) {
        global $wpdb;

        if (!$this->table_exists()) {
            return false;
        }
        
        return $wpdb->update(
            $this->table_name,
            array('active' => 1),
            array('id' => $rule_id),
            array('%d'),
            array('%d')
        ) !== false;
    }
    
    /**
     * Add a single guideline rule
     *
     * @param array $rule Rule data
     * @return int|WP_Error Rule ID or error
     */
    public function add_rule($rule) {
        global $wpdb;

        if (!$this->table_exists()) {
            return new WP_Error('table_missing', __('Guidelines table is not available. Try deactivating and reactivating the plugin.', 'mindfulseo'));
        }
        
        if (empty($rule['rule_type'])) {
            return new WP_Error('invalid_rule', __('Rule type is required.', 'mindfulseo'));
        }
        
        $avoid = isset($rule['avoid_term']) ? sanitize_text_field($rule['avoid_term']) : '';
        $preferred = isset($rule['preferred_term']) ? sanitize_text_field($rule['preferred_term']) : '';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE rule_type = %s AND avoid_term = %s AND preferred_term = %s LIMIT 1",
            sanitize_text_field($rule['rule_type']),
            $avoid,
            $preferred
        ));
        if ($existing) {
            return new WP_Error('duplicate_rule', __('This guideline rule already exists.', 'mindfulseo'));
        }
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'rule_type' => sanitize_text_field($rule['rule_type']),
                'avoid_term' => isset($rule['avoid_term']) ? sanitize_text_field($rule['avoid_term']) : '',
                'preferred_term' => isset($rule['preferred_term']) ? sanitize_text_field($rule['preferred_term']) : '',
                'context' => isset($rule['context']) ? sanitize_textarea_field($rule['context']) : '',
                'guideline_source' => isset($rule['guideline_source']) ? sanitize_text_field($rule['guideline_source']) : 'Manual',
                'active' => isset($rule['active']) ? (bool)$rule['active'] : true,
                'created_date' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%s')
        );
        
        if ($result === false) {
            return new WP_Error('insert_failed', __('Failed to add guideline rule.', 'mindfulseo'));
        }
        
        return $wpdb->insert_id;
    }

    /**
     * Wizard import: insert or refresh an existing rule (same type + avoid + preferred) so editor CSV/MD always wins over AI rows.
     *
     * @param array $rule Same shape as add_rule().
     * @return int|WP_Error New or existing rule ID.
     */
    public function upsert_rule_wizard_import( array $rule ) {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return new WP_Error( 'table_missing', __( 'Guidelines table is not available.', 'mindfulseo' ) );
        }
        if ( empty( $rule['rule_type'] ) ) {
            return new WP_Error( 'invalid_rule', __( 'Rule type is required.', 'mindfulseo' ) );
        }

        $type      = sanitize_text_field( $rule['rule_type'] );
        $avoid     = isset( $rule['avoid_term'] ) ? sanitize_text_field( $rule['avoid_term'] ) : '';
        $preferred = isset( $rule['preferred_term'] ) ? sanitize_text_field( $rule['preferred_term'] ) : '';
        $existing  = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->table_name} WHERE rule_type = %s AND avoid_term = %s AND preferred_term = %s LIMIT 1",
                $type,
                $avoid,
                $preferred
            )
        );

        $src    = isset( $rule['guideline_source'] ) ? sanitize_text_field( $rule['guideline_source'] ) : 'Wizard Import';
        $ctx    = isset( $rule['context'] ) ? sanitize_textarea_field( $rule['context'] ) : '';
        $active = isset( $rule['active'] ) ? (bool) $rule['active'] : true;

        if ( $existing ) {
            $ok = $wpdb->update(
                $this->table_name,
                array(
                    'context'           => $ctx,
                    'guideline_source'  => $src,
                    'active'            => $active ? 1 : 0,
                ),
                array( 'id' => (int) $existing ),
                array( '%s', '%s', '%d' ),
                array( '%d' )
            );
            if ( $ok === false ) {
                return new WP_Error( 'update_failed', __( 'Failed to update guideline rule.', 'mindfulseo' ) );
            }
            return (int) $existing;
        }

        return $this->add_rule( $rule );
    }
    
    /**
     * Delete rule
     *
     * @param int $rule_id Rule ID
     * @return bool
     */
    public function delete_rule($rule_id) {
        global $wpdb;

        if (!$this->table_exists()) {
            return false;
        }
        
        return $wpdb->delete(
            $this->table_name,
            array('id' => $rule_id),
            array('%d')
        ) !== false;
    }
    
    /**
     * Delete all rules from a source
     *
     * @param string $source_filename Filename
     * @return int Number of deleted rules
     */
    public function delete_rules_by_source($source_filename) {
        global $wpdb;

        if (!$this->table_exists()) {
            return 0;
        }
        
        $result = $wpdb->delete(
            $this->table_name,
            array('guideline_source' => $source_filename),
            array('%s')
        );
        
        return $result !== false ? $result : 0;
    }
}

