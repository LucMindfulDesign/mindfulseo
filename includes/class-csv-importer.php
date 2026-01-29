<?php
/**
 * CSV Importer Class
 *
 * Handles CSV file uploads and imports keyword waterfall data
 *
 * @package MindfulSEO
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_CSV_Importer {
    
    /**
     * Upload directory
     */
    private $upload_dir;
    
    /**
     * Allowed file types
     */
    private $allowed_types = array('csv', 'txt');
    
    /**
     * Maximum file size (5MB)
     */
    private $max_file_size = 5242880;
    
    /**
     * Required CSV columns
     */
    private $required_columns = array(
        'PRIMARY KEYWORD',
        'LONGTAIL KEYWORD',
        'SEARCH INTENT',
        'PRIORITY'
    );
    
    /**
     * Optional CSV columns
     */
    private $optional_columns = array(
        'CURRENT SESSIONS',
        'NOTES'
    );
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->upload_dir = MINDFULSEO_KEYWORDS_DIR;
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
     * Handle file upload
     *
     * @param array $file $_FILES array element
     * @return array|WP_Error Upload result or error
     */
    public function upload_csv($file) {
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
        
        // Log upload
        if (class_exists('MFSEO_Logger')) {
            $logger = MFSEO_Logger::get_instance();
            if ($logger) {
                $logger->log_info('CSV file uploaded', array(
                    'filename' => $filename,
                    'size' => $file['size']
                ));
            }
        }
        
        return array(
            'filename' => $filename,
            'filepath' => $filepath,
            'size' => $file['size']
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
        
        // Check file size
        if ($file['size'] > $this->max_file_size) {
            return new WP_Error(
                'file_too_large',
                sprintf(
                    __('File is too large. Maximum size is %s MB.', 'mindfulseo'),
                    ($this->max_file_size / 1048576)
                )
            );
        }
        
        // Check file extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowed_types)) {
            return new WP_Error(
                'invalid_file_type',
                sprintf(
                    __('Invalid file type. Allowed types: %s', 'mindfulseo'),
                    implode(', ', $this->allowed_types)
                )
            );
        }
        
        // Check MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        $allowed_mimes = array('text/csv', 'text/plain', 'application/csv');
        if (!in_array($mime, $allowed_mimes)) {
            return new WP_Error(
                'invalid_mime_type',
                __('Invalid file format. Please upload a valid CSV file.', 'mindfulseo')
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
        
        // Add timestamp and random string
        $unique = date('Y-m-d_H-i-s') . '_' . wp_generate_password(8, false);
        return $name . '_' . $unique . '.' . $ext;
    }
    
    /**
     * Parse CSV file
     *
     * @param string $filepath Path to CSV file
     * @return array|WP_Error Parsed data or error
     */
    public function parse_csv($filepath) {
        if (!file_exists($filepath)) {
            return new WP_Error(
                'file_not_found',
                __('CSV file not found.', 'mindfulseo')
            );
        }
        
        $handle = fopen($filepath, 'r');
        if (!$handle) {
            return new WP_Error(
                'cannot_open_file',
                __('Cannot open CSV file.', 'mindfulseo')
            );
        }
        
        // Read header row
        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return new WP_Error(
                'empty_file',
                __('CSV file is empty.', 'mindfulseo')
            );
        }
        
        // Validate header
        $validation = $this->validate_csv_structure($header);
        if (is_wp_error($validation)) {
            fclose($handle);
            return $validation;
        }
        
        // Parse rows
        $data = array();
        $row_number = 1;
        
        while (($row = fgetcsv($handle)) !== false) {
            $row_number++;
            
            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }
            
            // Map row to associative array
            $row_data = array();
            foreach ($header as $index => $column) {
                $row_data[$column] = isset($row[$index]) ? trim($row[$index]) : '';
            }
            
            // Validate row
            $validation = $this->validate_row($row_data, $row_number);
            if (is_wp_error($validation)) {
                // Log error but continue
                if (class_exists('MFSEO_Logger')) {
                    $logger = MFSEO_Logger::get_instance();
                    if ($logger) {
                        $logger->log_warning('Invalid CSV row', array(
                            'row' => $row_number,
                            'error' => $validation->get_error_message()
                        ));
                    }
                }
                continue;
            }
            
            $data[] = $row_data;
        }
        
        fclose($handle);
        
        return $data;
    }
    
    /**
     * Validate CSV structure
     *
     * @param array $header Header row from CSV
     * @return true|WP_Error
     */
    public function validate_csv_structure($header) {
        // Check for required columns
        foreach ($this->required_columns as $column) {
            if (!in_array($column, $header)) {
                return new WP_Error(
                    'missing_column',
                    sprintf(
                        __('Missing required column: %s', 'mindfulseo'),
                        $column
                    )
                );
            }
        }
        
        return true;
    }
    
    /**
     * Validate single row
     *
     * @param array $row Row data
     * @param int $row_number Row number (for error reporting)
     * @return true|WP_Error
     */
    private function validate_row($row, $row_number) {
        // Check required fields
        foreach ($this->required_columns as $column) {
            if (empty($row[$column])) {
                return new WP_Error(
                    'empty_required_field',
                    sprintf(
                        __('Row %d: Empty required field "%s"', 'mindfulseo'),
                        $row_number,
                        $column
                    )
                );
            }
        }
        
        // Validate search intent
        $valid_intents = array('Informational', 'Navigational', 'Transactional');
        if (!empty($row['SEARCH INTENT']) && !in_array($row['SEARCH INTENT'], $valid_intents)) {
            return new WP_Error(
                'invalid_search_intent',
                sprintf(
                    __('Row %d: Invalid search intent. Must be: %s', 'mindfulseo'),
                    $row_number,
                    implode(', ', $valid_intents)
                )
            );
        }
        
        // Validate priority
        $valid_priorities = array('HIGH', 'MEDIUM', 'LOW');
        if (!empty($row['PRIORITY']) && !in_array(strtoupper($row['PRIORITY']), $valid_priorities)) {
            return new WP_Error(
                'invalid_priority',
                sprintf(
                    __('Row %d: Invalid priority. Must be: %s', 'mindfulseo'),
                    $row_number,
                    implode(', ', $valid_priorities)
                )
            );
        }
        
        return true;
    }
    
    /**
     * Import data to database
     *
     * @param array $data Parsed CSV data
     * @param string $source_filename Original filename
     * @return array|WP_Error Import statistics or error
     */
    public function import_to_database($data, $source_filename) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'mindfulseo_keywords';
        
        $imported = 0;
        $skipped = 0;
        $errors = array();
        
        foreach ($data as $row) {
            // Check if keyword already exists
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_name WHERE primary_keyword = %s AND longtail_keyword = %s",
                $row['PRIMARY KEYWORD'],
                $row['LONGTAIL KEYWORD']
            ));
            
            if ($existing) {
                $skipped++;
                continue;
            }
            
            // Insert row
            $result = $wpdb->insert(
                $table_name,
                array(
                    'primary_keyword' => sanitize_text_field($row['PRIMARY KEYWORD']),
                    'longtail_keyword' => sanitize_text_field($row['LONGTAIL KEYWORD']),
                    'search_intent' => sanitize_text_field($row['SEARCH INTENT']),
                    'priority' => strtoupper(sanitize_text_field($row['PRIORITY'])),
                    'current_sessions' => isset($row['CURRENT SESSIONS']) ? intval($row['CURRENT SESSIONS']) : 0,
                    'notes' => isset($row['NOTES']) ? sanitize_textarea_field($row['NOTES']) : '',
                    'csv_source' => sanitize_text_field($source_filename),
                    'created_date' => current_time('mysql'),
                ),
                array('%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
            );
            
            if ($result) {
                $imported++;
            } else {
                $errors[] = $wpdb->last_error;
            }
        }
        
        // Log import
        if (class_exists('MFSEO_Logger')) {
            $logger = MFSEO_Logger::get_instance();
            if ($logger) {
                $logger->log_info('CSV import completed', array(
                    'source' => $source_filename,
                    'imported' => $imported,
                    'skipped' => $skipped,
                    'errors' => count($errors)
                ));
            }
        }
        
        return array(
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'total' => count($data)
        );
    }
    
    /**
     * Get upload directory path
     *
     * @return string
     */
    public function get_upload_directory() {
        return $this->upload_dir;
    }
    
    /**
     * Get list of uploaded CSV files
     *
     * @return array
     */
    public function get_uploaded_files() {
        $files = glob($this->upload_dir . '*.csv');
        $result = array();
        
        foreach ($files as $file) {
            $result[] = array(
                'filename' => basename($file),
                'filepath' => $file,
                'size' => filesize($file),
                'date' => filemtime($file)
            );
        }
        
        // Sort by date (newest first)
        usort($result, function($a, $b) {
            return $b['date'] - $a['date'];
        });
        
        return $result;
    }
    
    /**
     * Delete CSV file
     *
     * @param string $filename Filename to delete
     * @return bool
     */
    public function delete_file($filename) {
        $filepath = $this->upload_dir . basename($filename);
        
        if (!file_exists($filepath)) {
            return false;
        }
        
        $result = unlink($filepath);
        
        if ($result && class_exists('MFSEO_Logger')) {
            $logger = MFSEO_Logger::get_instance();
            if ($logger) {
                $logger->log_info('CSV file deleted', array('filename' => $filename));
            }
        }
        
        return $result;
    }
}

