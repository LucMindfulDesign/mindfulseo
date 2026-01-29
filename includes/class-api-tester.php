<?php
/**
 * API Key Tester Class
 *
 * Tests API connections for OpenAI and Claude
 *
 * @package MindfulSEO
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MFSEO_API_Tester {
    
    /**
     * Test OpenAI API connection
     *
     * @param string $api_key API key to test
     * @param string $model Model to test with
     * @return array|WP_Error Test result or error
     */
    public static function test_openai_connection($api_key, $model = 'gpt-3.5-turbo') {
        if (empty($api_key)) {
            return new WP_Error('empty_key', __('API key is required.', 'mindfulseo'));
        }
        
        $start_time = microtime(true);
        
        // Use minimal test parameters - just check if the API key works
        $body = array(
            'model' => $model,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => 'Hi'
                )
            )
        );
        
        // Only add max_tokens for models that support it (not GPT-5 or o-series)
        if (!preg_match('/^(gpt-5|o1|o3)/', $model)) {
            $body['max_tokens'] = 5;
        } else {
            // For newer models, use max_completion_tokens if they support it
            if (preg_match('/^o/', $model)) {
                // o-series models use different parameters
                $body['max_completion_tokens'] = 100;
            }
        }
        
        // Make a minimal API call
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($body)
        ));
        
        $end_time = microtime(true);
        $response_time = round(($end_time - $start_time) * 1000); // in milliseconds
        
        if (is_wp_error($response)) {
            return new WP_Error(
                'connection_failed',
                sprintf(__('Connection failed: %s', 'mindfulseo'), $response->get_error_message())
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code === 401) {
            return new WP_Error('invalid_key', __('Invalid API key. Please check your key and try again.', 'mindfulseo'));
        }
        
        if ($status_code === 429) {
            return new WP_Error('rate_limit', __('Rate limit exceeded. Please try again later.', 'mindfulseo'));
        }
        
        if ($status_code === 400) {
            // Check for specific error messages
            if (isset($body['error']['message'])) {
                $error_msg = $body['error']['message'];
                
                // Model not found
                if (strpos($error_msg, 'model') !== false && strpos($error_msg, 'does not exist') !== false) {
                    return new WP_Error('invalid_model', sprintf(__('Model "%s" not available. This model may require special access or may not exist.', 'mindfulseo'), $model));
                }
                
                // Organization verification required
                if (strpos($error_msg, 'organization must be verified') !== false) {
                    return new WP_Error('verification_required', __('This model requires organization verification. Most users should use GPT-4o or GPT-4 Turbo instead.', 'mindfulseo'));
                }
                
                // Parameter error
                if (strpos($error_msg, 'Unsupported parameter') !== false) {
                    return new WP_Error('parameter_error', sprintf(__('Parameter error: %s. This model may use different parameters.', 'mindfulseo'), $error_msg));
                }
                
                return new WP_Error('api_error', $error_msg);
            }
        }
        
        if ($status_code !== 200) {
            $error_message = isset($body['error']['message']) ? $body['error']['message'] : __('Unknown error', 'mindfulseo');
            return new WP_Error('api_error', $error_message);
        }
        
        // Success
        return array(
            'success' => true,
            'model' => $model,
            'response_time' => $response_time,
            'message' => sprintf(__('Connected successfully to %s (%dms)', 'mindfulseo'), $model, $response_time)
        );
    }
    
    /**
     * Test Claude API connection
     *
     * @param string $api_key API key to test
     * @param string $model Model to test with
     * @return array|WP_Error Test result or error
     */
    public static function test_claude_connection($api_key, $model = 'claude-3-haiku-20240307') {
        if (empty($api_key)) {
            return new WP_Error('empty_key', __('API key is required.', 'mindfulseo'));
        }
        
        $start_time = microtime(true);
        
        // Make a minimal API call
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'timeout' => 15,
            'headers' => array(
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'model' => $model,
                'max_tokens' => 10,
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => 'Hello'
                    )
                )
            ))
        ));
        
        $end_time = microtime(true);
        $response_time = round(($end_time - $start_time) * 1000); // in milliseconds
        
        if (is_wp_error($response)) {
            return new WP_Error(
                'connection_failed',
                sprintf(__('Connection failed: %s', 'mindfulseo'), $response->get_error_message())
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code === 401) {
            return new WP_Error('invalid_key', __('Invalid API key. Please check your key and try again.', 'mindfulseo'));
        }
        
        if ($status_code === 429) {
            return new WP_Error('rate_limit', __('Rate limit exceeded. Please try again later.', 'mindfulseo'));
        }
        
        if ($status_code === 404 && isset($body['error']['type']) && $body['error']['type'] === 'not_found_error') {
            return new WP_Error('invalid_model', sprintf(__('Model "%s" not found. Please check model availability.', 'mindfulseo'), $model));
        }
        
        if ($status_code !== 200) {
            $error_message = isset($body['error']['message']) ? $body['error']['message'] : __('Unknown error', 'mindfulseo');
            return new WP_Error('api_error', $error_message);
        }
        
        // Success
        return array(
            'success' => true,
            'model' => $model,
            'response_time' => $response_time,
            'message' => sprintf(__('Connected successfully to %s (%dms)', 'mindfulseo'), $model, $response_time)
        );
    }
    
    /**
     * Test both API connections
     *
     * @param string $openai_key OpenAI API key
     * @param string $claude_key Claude API key
     * @param string $openai_model OpenAI model
     * @param string $claude_model Claude model
     * @return array Test results
     */
    public static function test_all_connections($openai_key, $claude_key, $openai_model = 'gpt-3.5-turbo', $claude_model = 'claude-3-haiku-20240307') {
        $results = array(
            'openai' => null,
            'claude' => null,
            'timestamp' => current_time('mysql')
        );
        
        if (!empty($openai_key)) {
            $results['openai'] = self::test_openai_connection($openai_key, $openai_model);
        }
        
        if (!empty($claude_key)) {
            $results['claude'] = self::test_claude_connection($claude_key, $claude_model);
        }
        
        return $results;
    }
}

