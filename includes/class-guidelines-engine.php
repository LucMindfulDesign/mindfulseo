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
        
        // Split by lines
        $lines = explode("\n", $markdown_content);
        
        $current_section = '';
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip empty lines
            if (empty($line)) {
                continue;
            }
            
            // Detect sections
            if (preg_match('/^##\s+(.+)/', $line, $matches)) {
                $current_section = trim($matches[1]);
                continue;
            }
            
            // Parse "avoid term" rules (e.g., - **Avoid:** "ritual" → Use "practice")
            if (preg_match('/[•\-\*]\s*\*\*Avoid:\*\*\s*"([^"]+)"\s*→\s*Use\s*"([^"]+)"/i', $line, $matches)) {
                $rules[] = array(
                    'rule_type' => 'avoid_term',
                    'avoid_term' => $matches[1],
                    'preferred_term' => $matches[2],
                    'context' => $current_section
                );
                continue;
            }
            
            // Parse "capitalize" rules (e.g., - Capitalize: Dharma, Sangha, Lamrim)
            if (preg_match('/[•\-\*]\s*\*\*Capitalize:\*\*\s*(.+)/i', $line, $matches)) {
                $terms = array_map('trim', explode(',', $matches[1]));
                foreach ($terms as $term) {
                    $rules[] = array(
                        'rule_type' => 'capitalize',
                        'avoid_term' => strtolower($term),
                        'preferred_term' => $term,
                        'context' => $current_section
                    );
                }
                continue;
            }
            
            // Parse "preferred term" rules (e.g., - **Preferred:** "His Holiness the Dalai Lama")
            if (preg_match('/[•\-\*]\s*\*\*Preferred:\*\*\s*"([^"]+)"/i', $line, $matches)) {
                $rules[] = array(
                    'rule_type' => 'preferred_term',
                    'avoid_term' => '',
                    'preferred_term' => $matches[1],
                    'context' => $current_section
                );
                continue;
            }
            
            // Parse SEO-friendly rules (e.g., - **SEO-Friendly:** "meditation practice" instead of "meditation ritual")
            if (preg_match('/[•\-\*]\s*\*\*SEO-Friendly:\*\*\s*"([^"]+)"\s*instead of\s*"([^"]+)"/i', $line, $matches)) {
                $rules[] = array(
                    'rule_type' => 'seo_friendly',
                    'avoid_term' => $matches[2],
                    'preferred_term' => $matches[1],
                    'context' => $current_section
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
    public function generate_ai_context() {
        $rules = $this->get_all_rules();
        
        if (empty($rules)) {
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
                $context .= "- Use \"{$rule->preferred_term}\" instead of \"{$rule->avoid_term}\"\n";
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
        
        $stats = array();
        
        // Total rules
        $stats['total'] = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE active = 1");
        
        // By type
        $stats['by_type'] = $wpdb->get_results(
            "SELECT rule_type, COUNT(*) as count 
             FROM {$this->table_name} 
             WHERE active = 1 
             GROUP BY rule_type",
            OBJECT_K
        );
        
        // Uploaded files
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
        
        // Validate required fields
        if (empty($rule['rule_type'])) {
            return new WP_Error('invalid_rule', __('Rule type is required.', 'mindfulseo'));
        }
        
        // Insert rule
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
     * Delete rule
     *
     * @param int $rule_id Rule ID
     * @return bool
     */
    public function delete_rule($rule_id) {
        global $wpdb;
        
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
        
        $result = $wpdb->delete(
            $this->table_name,
            array('guideline_source' => $source_filename),
            array('%s')
        );
        
        return $result !== false ? $result : 0;
    }
}

