<?php
/*
Plugin Name: Fake News Or The Real Dealio
Description: A WordPress plugin to analyze URLs or rumors for misinformation and bias using OpenAI and Grok 3 API.
Version: 1.0.7
Author: Mark Z Marketing
License: GPL-2.0+
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('FNORD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FNORD_PLUGIN_URL', plugin_dir_url(__FILE__));

// Enqueue scripts and styles
function fnord_enqueue_scripts() {
    wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '4.4.4', true);
    wp_enqueue_script('fnord-frontend', FNORD_PLUGIN_URL . 'frontend.js', ['jquery', 'chart-js'], '1.0.4', true);
    wp_enqueue_style('fnord-styles', FNORD_PLUGIN_URL . 'style.css', [], '1.0.0');
    
    // Localize script for AJAX
    wp_localize_script('fnord-frontend', 'fnordAjax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('fnord_nonce')
    ]);
}
add_action('wp_enqueue_scripts', 'fnord_enqueue_scripts');

// Register admin settings page
function fnord_register_settings() {
    add_options_page(
        'Fake News Or The Real Dealio Settings',
        'Fake News Settings',
        'manage_options',
        'fnord-settings',
        'fnord_settings_page'
    );
}
add_action('admin_menu', 'fnord_register_settings');

// Settings page callback
function fnord_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    // Save API keys or clear cache
    if (isset($_POST['fnord_save_settings']) && check_admin_referer('fnord_settings_nonce')) {
        update_option('fnord_openai_api_key', sanitize_text_field($_POST['fnord_openai_api_key']));
        update_option('fnord_grok3_api_key', sanitize_text_field($_POST['fnord_grok3_api_key']));
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }
    if (isset($_POST['fnord_clear_cache']) && check_admin_referer('fnord_settings_nonce')) {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_fnord_%'");
        echo '<div class="updated"><p>Cache cleared.</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>Fake News Or The Real Dealio Settings</h1>
        <form method="post">
            <?php wp_nonce_field('fnord_settings_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="fnord_openai_api_key">OpenAI API Key</label></th>
                    <td>
                        <input type="text" name="fnord_openai_api_key" id="fnord_openai_api_key" value="<?php echo esc_attr(get_option('fnord_openai_api_key')); ?>" class="regular-text">
                        <p class="description">Enter your OpenAI API key. Get one at <a href="https://platform.openai.com/" target="_blank">OpenAI</a>.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="fnord_grok3_api_key">Grok 3 API Key</label></th>
                    <td>
                        <input type="text" name="fnord_grok3_api_key" id="fnord_grok3_api_key" value="<?php echo esc_attr(get_option('fnord_grok3_api_key')); ?>" class="regular-text">
                        <p class="description">Enter your xAI Grok 3 API key for recent event analysis. Get one at <a href="https://x.ai/api" target="_blank">xAI</a>.</p>
                    </td>
                </tr>
            </table>
            <input type="submit" name="fnord_save_settings" class="button button-primary" value="Save Settings">
            <input type="submit" name="fnord_clear_cache" class="button button-secondary" value="Clear Cache">
        </form>
    </div>
    <?php
}

// Register settings
function fnord_register_options() {
    register_setting('fnord_settings', 'fnord_openai_api_key', 'sanitize_text_field');
    register_setting('fnord_settings', 'fnord_grok3_api_key', 'sanitize_text_field');
}
add_action('admin_init', 'fnord_register_options');

// Shortcode for the form
function fnord_form_shortcode() {
    ob_start();
    ?>
    <div id="fnord-form-container">
        <form id="fnord-form" method="post">
            <div class="fnord-field">
                <label for="fnord-url">I Think This Might Be Fake News (URL)</label>
                <input type="url" id="fnord-url" name="fnord_url" placeholder="https://example.com/article">
            </div>
            <div class="fnord-field">
                <label for="fnord-rumor">Rumor Sez</label>
                <textarea id="fnord-rumor" name="fnord_rumor" placeholder="e.g., I heard Elvis is alive"></textarea>
            </div>
            <button type="submit" class="fnord-submit">Analyze</button>
        </form>
        <div id="fnord-results" style="display: none;"></div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('fnord_form', 'fnord_form_shortcode');

// AJAX handler for form submission
function fnord_handle_submission() {
    if (!check_ajax_referer('fnord_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Invalid nonce. Please refresh the page and try again.']);
    }
    
    $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
    $rumor = isset($_POST['rumor']) ? sanitize_textarea_field($_POST['rumor']) : '';
    
    if (empty($url) && empty($rumor)) {
        wp_send_json_error(['message' => 'Please fill out at least one field.']);
    }
    
    $openai_api_key = get_option('fnord_openai_api_key');
    $grok3_api_key = get_option('fnord_grok3_api_key');
    if (empty($openai_api_key) && empty($grok3_api_key)) {
        wp_send_json_error(['message' => 'At least one API key (OpenAI or Grok 3) must be configured.']);
    }
    
    // Fetch URL content if provided
    $content = '';
    if ($url) {
        $response = wp_remote_get($url, ['timeout' => 15]);
        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            // Parse HTML to extract main text
            $doc = new DOMDocument();
            @$doc->loadHTML($body); // Suppress warnings for malformed HTML
            $paragraphs = $doc->getElementsByTagName('p');
            $text = '';
            foreach ($paragraphs as $p) {
                $text .= $p->textContent . ' ';
            }
            $content = trim($text);
            if (empty($content)) {
                wp_send_json_error(['message' => 'No readable content found at the provided URL.']);
            }
        } else {
            wp_send_json_error(['message' => 'Failed to fetch URL content: ' . $response->get_error_message()]);
        }
    }
    
    // Use rumor if no URL content
    $input = $content ?: $rumor;
    
    // Log input
    error_log('FNORD: Input for analysis: ' . substr($input, 0, 100));
    
    // Call OpenAI API for primary analysis
    $analysis = [];
    if (!empty($openai_api_key)) {
        $analysis = fnord_call_openai_api($input, $openai_api_key);
    } else {
        wp_send_json_error(['message' => 'OpenAI API key required for analysis.']);
    }
    
    if (isset($analysis['error'])) {
        wp_send_json_error(['message' => $analysis['error']]);
    }
    
    // Use Grok 3 API for article search
    $articles = [];
    if (!empty($grok3_api_key)) {
        $articles = fnord_call_grok3_for_articles($input, $grok3_api_key);
    }
    
    // Fallback to OpenAI for articles if Grok 3 fails or no results
    if (empty($articles) && !empty($openai_api_key)) {
        $articles = fnord_call_openai_for_articles($input, $openai_api_key);
    }
    
    // Prepare response
    $response = [
        'analysis' => $analysis,
        'articles' => array_slice($articles, 0, 10), // Limit to 10 articles
        'pie_data' => [
            'truthful' => $analysis['truthful_percentage'] ?? 0,
            'misinformation' => $analysis['misinformation_percentage'] ?? 0,
            'bias' => $analysis['bias_percentage'] ?? 0
        ]
    ];
    
    error_log('FNORD: AJAX response: ' . json_encode($response));
    wp_send_json_success($response);
}
add_action('wp_ajax_fnord_submit', 'fnord_handle_submission');
add_action('wp_ajax_nopriv_fnord_submit', 'fnord_handle_submission');

// OpenAI API call for primary analysis with caching
function fnord_call_openai_api($input, $api_key) {
    // Check cache
    $cache_key = 'fnord_openai_' . md5($input);
    $analysis = get_transient($cache_key);
    if (false !== $analysis) {
        return $analysis;
    }
    
    // Use dynamic current date
    $current_date = date('F j, Y');
    $prompt = "Analyze the following content for misinformation, bias, and truthfulness as of $current_date. Provide a response in JSON format with the following structure:
    {
        \"category\": \"Misinformation|Partially Misinformation|Truthful|Biased\",
        \"truthful_percentage\": number,
        \"misinformation_percentage\": number,
        \"bias_percentage\": number,
        \"bias_type\": \"Leftist/Communist|Right-wing/Conservative|Neutral\",
        \"reasoning\": \"Detailed explanation of the analysis, considering credible sources and social media sentiment. Assign high misinformation (around 90%) if claims lack definitive evidence, such as video or firsthand reports. Evaluate bias based on X post sentiment, noting left-leaning or right-leaning criticism.\"
    }
    Ensure percentages sum to 100. Content to analyze: $input";
    
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode([
            'model' => 'gpt-4.1-mini',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'response_format' => ['type' => 'json_object'],
            'max_tokens' => 500,
            'temperature' => 0.3
        ]),
        'timeout' => 30
    ]);
    
    // Check for WP errors
    if (is_wp_error($response)) {
        error_log('FNORD: OpenAI API connection failed: ' . $response->get_error_message());
        return ['error' => 'Failed to connect to OpenAI API: ' . $response->get_error_message()];
    }
    
    // Get response body
    $body = wp_remote_retrieve_body($response);
    error_log('FNORD: OpenAI API raw response: ' . $body);
    $data = json_decode($body, true);
    
    // Check for API errors
    if (isset($data['error'])) {
        error_log('FNORD: OpenAI API error: ' . $data['error']['message']);
        return ['error' => 'OpenAI API error: ' . $data['error']['message']];
    }
    
    // Extract and validate response
    if (!isset($data['choices'][0]['message']['content'])) {
        error_log('FNORD: Invalid OpenAI API response: Missing choices content');
        return ['error' => 'Invalid response from OpenAI API.'];
    }
    
    $content = json_decode($data['choices'][0]['message']['content'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('FNORD: OpenAI API content JSON decode error: ' . json_last_error_msg());
        return ['error' => 'Invalid JSON content from OpenAI API.'];
    }
    
    // Validate required fields
    if (!isset($content['category'], $content['truthful_percentage'], $content['misinformation_percentage'], $content['bias_percentage'], $content['bias_type'], $content['reasoning'])) {
        error_log('FNORD: OpenAI API missing fields: ' . print_r($content, true));
        return ['error' => 'Incomplete analysis from OpenAI API.'];
    }
    
    // Ensure percentages sum to 100
    $total = $content['truthful_percentage'] + $content['misinformation_percentage'] + $content['bias_percentage'];
    if ($total !== 100) {
        error_log('FNORD: OpenAI API invalid percentages: Total = ' . $total);
        return ['error' => 'Invalid percentages from OpenAI API: must sum to 100.'];
    }
    
    $analysis = [
        'category' => sanitize_text_field($content['category']),
        'truthful_percentage' => intval($content['truthful_percentage']),
        'misinformation_percentage' => intval($content['misinformation_percentage']),
        'bias_percentage' => intval($content['bias_percentage']),
        'bias_type' => sanitize_text_field($content['bias_type']),
        'reasoning' => sanitize_textarea_field($content['reasoning'])
    ];
    
    // Cache successful response
    set_transient($cache_key, $analysis, HOUR_IN_SECONDS);
    
    return $analysis;
}

// Grok 3 API call for article search
function fnord_call_grok3_for_articles($input, $api_key) {
    // Check cache
    $cache_key = 'fnord_grok3_articles_' . md5($input);
    $articles = get_transient($cache_key);
    if (false !== $articles) {
        return $articles;
    }
    
    // Use dynamic current date and search back
    $current_date = date('F j, Y');
    $prompt = "Search for up to 10 credible news articles or posts from verified X users (e.g., journalists, news outlets) related to the following claim, as of $current_date. Focus on events from the past 30 days up to $current_date. Return a JSON array of items with fields: title (string), url (string, use https://x.com/username/status/post_id for X posts), publication (string, or 'X' for posts), date (YYYY-MM-DD), author (string, or username for X posts). If no credible sources are found, return an empty array. Exclude speculative or unverified sources. Claim: $input";
    
    $response = wp_remote_post('https://api.x.ai/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode([
            'model' => 'grok-3-beta',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => 1000,
            'temperature' => 0.3,
            'search' => ['enabled' => true]
        ]),
        'timeout' => 30
    ]);
    
    // Check for WP errors
    if (is_wp_error($response)) {
        error_log('FNORD: Grok 3 article search connection failed: ' . $response->get_error_message());
        return [];
    }
    
    // Get response body
    $body = wp_remote_retrieve_body($response);
    error_log('FNORD: Grok 3 article search raw response: ' . $body);
    $data = json_decode($body, true);
    
    // Check for API errors
    if (isset($data['error'])) {
        error_log('FNORD: Grok 3 article search error: ' . $data['error']['message']);
        return [];
    }
    
    // Extract and validate response
    if (!isset($data['choices'][0]['message']['content'])) {
        error_log('FNORD: Invalid Grok 3 article search response: Missing choices content');
        return [];
    }
    
    $content = json_decode($data['choices'][0]['message']['content'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('FNORD: Grok 3 article search JSON decode error: ' . json_last_error_msg());
        return [];
    }
    
    // Validate response is an array
    if (!is_array($content)) {
        error_log('FNORD: Invalid Grok 3 article search response: Not an array');
        return [];
    }
    
    $articles = [];
    foreach ($content as $item) {
        if (isset($item['title'], $item['url'], $item['publication'], $item['date'], $item['author'])) {
            $articles[] = [
                'title' => sanitize_text_field($item['title']),
                'url' => esc_url_raw($item['url']),
                'publication' => sanitize_text_field($item['publication']),
                'date' => sanitize_text_field($item['date']),
                'author' => sanitize_text_field($item['author'])
            ];
        }
    }
    
    // Cache successful response
    if (!empty($articles)) {
        set_transient($cache_key, $articles, HOUR_IN_SECONDS);
    }
    
    return $articles;
}

// OpenAI API call for article search (fallback)
function fnord_call_openai_for_articles($input, $api_key) {
    // Check cache
    $cache_key = 'fnord_openai_articles_' . md5($input);
    $articles = get_transient($cache_key);
    if (false !== $articles) {
        return $articles;
    }
    
    // Use dynamic current date and search back
    $current_date = date('F j, Y');
    $prompt = "Search for up to 10 credible news articles related to the following claim, as of $current_date. Focus on events from the past 30 days up to $current_date. Return a JSON array of items with fields: title (string), url (string), publication (string), date (YYYY-MM-DD), author (string). If no credible sources are found, return an empty array. Exclude speculative or unverified sources. Claim: $input";
    
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode([
            'model' => 'gpt-4.1-mini',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'response_format' => ['type' => 'json_object'],
            'max_tokens' => 1000,
            'temperature' => 0.3
        ]),
        'timeout' => 30
    ]);
    
    // Check for WP errors
    if (is_wp_error($response)) {
        error_log('FNORD: OpenAI article search connection failed: ' . $response->get_error_message());
        return [];
    }
    
    // Get response body
    $body = wp_remote_retrieve_body($response);
    error_log('FNORD: OpenAI article search raw response: ' . $body);
    $data = json_decode($body, true);
    
    // Check for API errors
    if (isset($data['error'])) {
        error_log('FNORD: OpenAI article search error: ' . $data['error']['message']);
        return [];
    }
    
    // Extract and validate response
    if (!isset($data['choices'][0]['message']['content'])) {
        error_log('FNORD: Invalid OpenAI article search response: Missing choices content');
        return [];
    }
    
    $content = json_decode($data['choices'][0]['message']['content'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('FNORD: OpenAI article search JSON decode error: ' . json_last_error_msg());
        return [];
    }
    
    // Validate response is an array
    if (!is_array($content)) {
        error_log('FNORD: Invalid OpenAI article search response: Not an array');
        return [];
    }
    
    $articles = [];
    foreach ($content as $item) {
        if (isset($item['title'], $item['url'], $item['publication'], $item['date'], $item['author'])) {
            $articles[] = [
                'title' => sanitize_text_field($item['title']),
                'url' => esc_url_raw($item['url']),
                'publication' => sanitize_text_field($item['publication']),
                'date' => sanitize_text_field($item['date']),
                'author' => sanitize_text_field($item['author'])
            ];
        }
    }
    
    // Cache successful response
    if (!empty($articles)) {
        set_transient($cache_key, $articles, HOUR_IN_SECONDS);
    }
    
    return $articles;
}
?>
