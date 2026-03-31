<?php
/**
 * OpenRouter provider (OpenAI-compatible chat completions).
 *
 * @package MindfulSEO
 * @since 2.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Uses OpenRouter with the same optimize_* flow as MFSEO_OpenAI_Provider.
 */
class MFSEO_OpenRouter_Provider extends MFSEO_OpenAI_Provider {

    /**
     * @param string $api_key OpenRouter API key.
     */
    public function __construct($api_key) {
        $settings = MindfulSEO::get_settings();
        $ref = !empty($settings['openrouter_http_referer'])
            ? esc_url_raw($settings['openrouter_http_referer'])
            : home_url('/');
        if ($ref === '') {
            $ref = 'https://mindfuldesign.me';
        }
        $headers = array(
            'HTTP-Referer' => $ref,
            'X-Title'      => 'MindfulSEO',
        );
        parent::__construct($api_key, 'https://openrouter.ai/api/v1/chat/completions', $headers);
    }

    /**
     * @inheritdoc
     */
    public function get_name() {
        return 'OpenRouter';
    }

    /**
     * Resolve model: custom slug wins, then saved preset, then default.
     */
    protected function get_default_model() {
        $s = MindfulSEO::get_settings();
        if (!empty($s['openrouter_custom_model'])) {
            return sanitize_text_field($s['openrouter_custom_model']);
        }
        if (!empty($s['openrouter_model'])) {
            return sanitize_text_field($s['openrouter_model']);
        }
        return 'qwen/qwen3.5-flash-02-23';
    }

    /**
     * Fast model for short internal calls.
     */
    protected function get_fast_model_slug() {
        $s = MindfulSEO::get_settings();
        if (!empty($s['openrouter_model_fast'])) {
            return sanitize_text_field($s['openrouter_model_fast']);
        }
        return 'qwen/qwen3.5-flash-02-23';
    }

    /**
     * @inheritdoc
     */
    public function test_connection() {
        try {
            $test_model = $this->get_fast_model_slug();
            $response = $this->call_api(
                'Reply with exactly: OK',
                $test_model,
                50,
                0.3,
                'connection_test'
            );

            if ($response && isset($response['choices'][0]['message']['content'])) {
                return array(
                    'success' => true,
                    'message' => __('OpenRouter connection successful!', 'mindfulseo'),
                );
            }

            return array(
                'success' => false,
                'message' => __('Unexpected response from OpenRouter.', 'mindfulseo'),
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage(),
            );
        }
    }
}
