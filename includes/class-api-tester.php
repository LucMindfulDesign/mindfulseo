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
    public static function test_openai_connection($api_key, $model = 'gpt-4o-mini') {
        if (empty($api_key)) {
            return new WP_Error('empty_key', __('API key is required.', 'mindfulseo'));
        }
        
        $start_time = microtime(true);
        
        // Always test with gpt-4o-mini first -- it's cheap, fast, and
        // available to ALL account tiers. This isolates "bad key" from
        // "model not available on your tier".
        $test_model = 'gpt-4o-mini';
        
        $body = array(
            'model' => $test_model,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => 'Hi'
                )
            ),
            'max_tokens' => 5,
        );
        
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout'   => 15,
            'sslverify' => apply_filters('https_local_ssl_verify', true),
            'headers'   => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => json_encode($body),
        ));
        
        $end_time = microtime(true);
        $response_time = round(($end_time - $start_time) * 1000); // in milliseconds
        
        if (is_wp_error($response)) {
            $err  = $response->get_error_message();
            $hint = '';
            if (strpos($err, 'SSL') !== false || strpos($err, 'certificate') !== false) {
                $hint = ' (SSL error — common on local dev sites; your key may still be valid)';
            } elseif (strpos($err, 'cURL error 6') !== false || strpos($err, 'resolve') !== false) {
                $hint = ' (DNS error — check your internet connection)';
            } elseif (strpos($err, 'timed out') !== false || strpos($err, 'Operation timed out') !== false) {
                $hint = ' (timed out — OpenAI may be slow right now, try again)';
            }
            return new WP_Error('connection_failed', sprintf(__('Connection failed: %s', 'mindfulseo'), $err . $hint));
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body        = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code === 401) {
            return new WP_Error('invalid_key', __('Invalid API key — double-check at platform.openai.com/api-keys.', 'mindfulseo'));
        }

        if ($status_code === 429) {
            $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'No details provided';
            return new WP_Error('rate_limit', sprintf('OpenAI rate limit: %s', $error_msg));
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
            return new WP_Error('api_error', sprintf('HTTP %d (model: %s): %s', $status_code, $model, $error_message));
        }
        
        $msg = sprintf(__('API key works (%dms). Selected model: %s', 'mindfulseo'), $response_time, $model);
        if ($model !== $test_model && $model !== 'gpt-4o-mini') {
            $msg .= sprintf(__('. Note: %s may require a higher-tier OpenAI account.', 'mindfulseo'), $model);
        }

        self::log_connection_test_row('openai', is_array($body) ? $body : array(), $test_model);

        return array(
            'success' => true,
            'model' => $model,
            'response_time' => $response_time,
            'message' => $msg
        );
    }
    
    /**
     * Test OpenRouter (OpenAI-compatible) connection.
     *
     * @param string $api_key API key.
     * @param string $model   Model id, e.g. qwen/qwen3.5-flash-02-23.
     * @return array|WP_Error
     */
    public static function test_openrouter_connection($api_key, $model = 'qwen/qwen3.5-flash-02-23') {
        if (empty($api_key)) {
            return new WP_Error('empty_key', __('API key is required.', 'mindfulseo'));
        }
        $start_time = microtime(true);
        $test_model = !empty($model) ? $model : 'qwen/qwen3.5-flash-02-23';
        $referer = home_url('/');
        if ($referer === '') {
            $referer = 'https://mindfuldesign.me';
        }
        $body = array(
            'model' => $test_model,
            'messages' => array(
                array('role' => 'user', 'content' => 'Hi'),
            ),
            'max_tokens' => 5,
            'temperature' => 0.3,
        );
        $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', array(
            'timeout'   => 20,
            'sslverify' => apply_filters('https_local_ssl_verify', true),
            'headers'   => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => $referer,
                'X-Title'       => 'MindfulSEO',
            ),
            'body' => wp_json_encode($body),
        ));
        $end_time = microtime(true);
        $response_time = round(($end_time - $start_time) * 1000);
        if (is_wp_error($response)) {
            return new WP_Error('connection_failed', $response->get_error_message());
        }
        $status_code = wp_remote_retrieve_response_code($response);
        $decoded     = json_decode(wp_remote_retrieve_body($response), true);
        if ($status_code === 401) {
            return new WP_Error('invalid_key', __('Invalid OpenRouter API key.', 'mindfulseo'));
        }
        if ($status_code !== 200 || !isset($decoded['choices'][0]['message']['content'])) {
            $msg = isset($decoded['error']['message']) ? $decoded['error']['message'] : 'HTTP ' . $status_code;
            return new WP_Error('api_error', $msg);
        }

        self::log_connection_test_row('openrouter', is_array($decoded) ? $decoded : array(), $test_model);

        return array(
            'success' => true,
            'model' => $test_model,
            'response_time' => $response_time,
            'message' => sprintf(__('OpenRouter OK (%dms) — %s', 'mindfulseo'), $response_time, $test_model),
        );
    }

    /**
     * Test Claude API connection
     *
     * @param string $api_key API key to test
     * @param string $model Model to test with
     * @return array|WP_Error Test result or error
     */
    public static function test_claude_connection($api_key, $model = 'claude-sonnet-4-5') {
        if (empty($api_key)) {
            return new WP_Error('empty_key', __('API key is required.', 'mindfulseo'));
        }
        
        $start_time = microtime(true);
        
        // Make a minimal API call
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'timeout'   => 15,
            'sslverify' => apply_filters('https_local_ssl_verify', true),
            'headers'   => array(
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ),
            'body' => json_encode(array(
                'model'      => $model,
                'max_tokens' => 10,
                'messages'   => array(
                    array('role' => 'user', 'content' => 'Hi'),
                ),
            )),
        ));

        $end_time      = microtime(true);
        $response_time = round(($end_time - $start_time) * 1000);

        if (is_wp_error($response)) {
            $err  = $response->get_error_message();
            $hint = '';
            if (strpos($err, 'SSL') !== false || strpos($err, 'certificate') !== false) {
                $hint = ' (SSL error — common on local dev sites; your key may still be valid)';
            } elseif (strpos($err, 'timed out') !== false) {
                $hint = ' (timed out — Anthropic may be slow right now, try again)';
            }
            return new WP_Error('connection_failed', sprintf(__('Connection failed: %s', 'mindfulseo'), $err . $hint));
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body        = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code === 401) {
            return new WP_Error('invalid_key', __('Invalid API key — double-check at console.anthropic.com.', 'mindfulseo'));
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

        self::log_connection_test_row('claude', is_array($body) ? $body : array(), $model);

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
    public static function test_all_connections($openai_key, $claude_key, $openai_model = 'gpt-4o-mini', $claude_model = 'claude-sonnet-4-5') {
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
    /**
     * Log a successful settings "Test connection" call for Usage tab accuracy.
     *
     * @param string $provider openai|openrouter|claude
     * @param array  $decoded  Response JSON.
     * @param string $model    Model id sent to the API.
     */
    private static function log_connection_test_row($provider, $decoded, $model) {
        if (!class_exists('MFSEO_Logger') || !is_array($decoded)) {
            return;
        }
        $norm = isset($decoded['usage']) ? MFSEO_Logger::normalize_usage_tokens($decoded['usage']) : null;
        $logger = MFSEO_Logger::get_instance();
        if ($norm === null) {
            $logger->log_api_call($provider, 0, 0, 0, $model, 'connection_test', 'connection_test', 'usage_missing');
            return;
        }
        $cost = self::estimate_connection_test_usd($provider, $model, $norm['in'], $norm['out']);
        $logger->log_api_call($provider, $norm['in'], $norm['out'], $cost, $model, 'connection_test', 'connection_test');
    }

    /**
     * @param string $provider
     * @param string $model
     * @param int    $in_t
     * @param int    $out_t
     * @return float
     */
    private static function estimate_connection_test_usd($provider, $model, $in_t, $out_t) {
        if ($provider === 'openrouter' && class_exists('MFSEO_OpenAI_Provider')) {
            return MFSEO_OpenAI_Provider::estimate_openrouter_usd($model, $in_t, $out_t);
        }
        if ($provider === 'openai') {
            if (strpos($model, 'gpt-4o-mini') !== false) {
                return ($in_t / 1000) * 0.00015 + ($out_t / 1000) * 0.0006;
            }
            return ($in_t / 1000) * 0.0025 + ($out_t / 1000) * 0.01;
        }
        return ($in_t / 1000) * 0.001 + ($out_t / 1000) * 0.005;
    }


}

