<?php
/*
Plugin Name: Polylang Auto Translator
Description: Translates content from the original German text using MyMemory while preserving the Gutenberg structure.
Version: 1.3.1
Author: jado GmbH
*/

if (!defined('ABSPATH')) exit;

class Polylang_Translator {

    public function __construct() {
        add_action('plugins_loaded', [$this, 'init']);
    }

    public function init() {
        if (!function_exists('pll_default_language')) {
            add_action('admin_notices', [$this, 'polylang_missing_notice']);
            return;
        }

        add_filter('pll_get_post_types', [$this, 'add_wp_block_to_polylang'], 10, 2);
        add_action('add_meta_boxes', [$this, 'add_translation_meta_box']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_pll_translate_content', [$this, 'ajax_translate_content']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function polylang_missing_notice() {
        ?>
        <div class="error notice">
            <p><?php _e('Polylang Auto Translator requires the Polylang plugin to be installed and active.', 'polylang-auto-translate'); ?></p>
        </div>
        <?php
    }

    public function add_settings_page() {
        add_options_page(
            __('Polylang AI Translate Settings', 'polylang-auto-translate'),
            __('Polylang AI Translate', 'polylang-auto-translate'),
            'manage_options',
            'pll-ai-translate',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('pll_ai_translate_settings', 'pll_ai_translate_email');
        register_setting('pll_ai_translate_settings', 'pll_ai_translate_key');
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Polylang AI Translate Settings', 'polylang-auto-translate'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('pll_ai_translate_settings');
                do_settings_sections('pll_ai_translate_settings');
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php _e('MyMemory Email', 'polylang-auto-translate'); ?></th>
                        <td>
                            <input type="email" name="pll_ai_translate_email" value="<?php echo esc_attr(get_option('pll_ai_translate_email')); ?>" class="regular-text" />
                            <p class="description"><?php _e('Enter your email address to increase the daily limit from 1,000 to 10,000 words (free).', 'polylang-auto-translate'); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('MyMemory API Key', 'polylang-auto-translate'); ?></th>
                        <td>
                            <input type="text" name="pll_ai_translate_key" value="<?php echo esc_attr(get_option('pll_ai_translate_key')); ?>" class="regular-text" />
                            <p class="description"><?php _e('Optional: Your MyMemory API Key (if available).', 'polylang-auto-translate'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function add_wp_block_to_polylang($post_types, $is_settings) {
        if ($is_settings) {
            $post_types['wp_block'] = 'wp_block';
        } else {
            $post_types[] = 'wp_block';
        }
        return $post_types;
    }

    public function add_translation_meta_box() {
        if (!function_exists('pll_get_post_language')) return;

        $post_types = get_post_types(['public' => true], 'names');
        $post_types['wp_block'] = 'wp_block'; // Manuelle Ergänzung für Vorlagen/Patterns

        foreach ($post_types as $screen) {
            add_meta_box(
                'pll_translator_box',
                __('Translation', 'polylang-auto-translate'),
                [$this, 'render_meta_box'],
                $screen,
                'side',
                'high'
            );
        }
    }

    public function render_meta_box($post) {
        if (!function_exists('pll_get_post_language')) return;

        $current_lang = pll_get_post_language($post->ID);
        $default_lang = pll_default_language();
        $translations = pll_get_post_translations($post->ID);


        if (!isset($translations[$default_lang]) && isset($_GET['from_post'])) {
            $from_post_id = intval($_GET['from_post']);
            if (pll_get_post_language($from_post_id) === $default_lang) {
                $translations[$default_lang] = $from_post_id;
            }
        }


        if ($current_lang === $default_lang) {
            echo '<p>' . sprintf(__('This is the original in %s.', 'polylang-auto-translate'), $default_lang) . '</p>';
            return;
        }

        if (!isset($translations[$default_lang])) {
            echo '<p>' . sprintf(__('No original in the main language (%s) found.', 'polylang-auto-translate'), $default_lang) . '</p>';
            return;
        }

        echo '<div id="pll-translator-actions">';
        echo '<p>' . sprintf(__('Translate content from the %s original (%s).', 'polylang-auto-translate'), $default_lang, get_the_title($translations[$default_lang])) . '</p>';
        echo '<select id="pll_translate_engine" style="width:100%; margin-bottom:10px; display:none;">';
        echo '<option value="mymemory">' . __('MyMemory (Free)', 'polylang-auto-translate') . '</option>';
        echo '</select>';
        echo '<button type="button" id="pll_translate_btn" class="button button-primary">' . __('Translate now', 'polylang-auto-translate') . '</button>';
        echo '<div id="pll_translate_status" style="margin-top:10px;"></div>';
        echo '</div>';
    }

    public function enqueue_admin_scripts($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'])) return;

        $screen = get_current_screen();
        $allowed_post_types = get_post_types(['public' => true], 'names');
        $allowed_post_types[] = 'wp_block';

        if ($screen && !in_array($screen->post_type, $allowed_post_types)) return;

        $script = "
        jQuery(document).ready(function($) {
            $('#pll_translate_btn').on('click', function() {
                var btn = $(this);
                var status = $('#pll_translate_status');
                var engine = $('#pll_translate_engine').val();
                
                if (!confirm('" . esc_js(__('The current content in the editor will be overwritten. Continue?', 'polylang-auto-translate')) . "')) return;

                btn.prop('disabled', true).text('" . esc_js(__('Translating...', 'polylang-auto-translate')) . "');
                status.text('" . esc_js(__('Getting data...', 'polylang-auto-translate')) . "');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'pll_translate_content',
                        post_id: $('#post_ID').val(),
                        engine: engine,
                        nonce: '" . wp_create_nonce('pll_translate_nonce') . "'
                    },
                    success: function(response) {
                        if (response.success) {
                            status.html('<span style=\"color:green\">" . esc_js(__('Successfully translated!', 'polylang-auto-translate')) . "</span>');
                            
                            // Check if Gutenberg is active
                            if (window.wp && wp.data && wp.data.dispatch('core/editor')) {
                                wp.data.dispatch('core/editor').resetBlocks(wp.blocks.parse(response.data.content));
                                wp.data.dispatch('core/editor').editPost({ title: response.data.title });
                            } else {
                                // Classic Editor
                                if (typeof tinyMCE !== 'undefined' && tinyMCE.get('content')) {
                                    tinyMCE.get('content').setContent(response.data.content);
                                }
                                $('#title').val(response.data.title);
                                $('#content').val(response.data.content);
                            }
                        } else {
                            status.html('<span style=\"color:red\">" . esc_js(__('Error:', 'polylang-auto-translate')) . " ' + response.data + '</span>');
                        }
                    },
                    error: function() {
                        status.html('<span style=\"color:red\">" . esc_js(__('Network error.', 'polylang-auto-translate')) . "</span>');
                    },
                    complete: function() {
                        btn.prop('disabled', false).text('" . esc_js(__('Translate now', 'polylang-auto-translate')) . "');
                    }
                });
            });
        });";
        wp_add_inline_script('jquery-core', $script);
    }

    public function ajax_translate_content() {
        check_ajax_referer('pll_translate_nonce', 'nonce');

        $post_id = intval($_POST['post_id']);
        $engine = sanitize_text_field($_POST['engine']);
        $target_lang = pll_get_post_language($post_id);
        $default_lang = pll_default_language();

        $translations = pll_get_post_translations($post_id);

        if (!isset($translations[$default_lang])) {
            $referer = wp_get_referer();
            if ($referer) {
                parse_str(parse_url($referer, PHP_URL_QUERY), $query_params);
                if (isset($query_params['from_post'])) {
                    $from_post_id = intval($query_params['from_post']);
                    if (pll_get_post_language($from_post_id) === $default_lang) {
                        $translations[$default_lang] = $from_post_id;
                    }
                }
            }
        }

        if (!isset($translations[$default_lang])) {
            wp_send_json_error(sprintf(__('No original in the main language (%s) found.', 'polylang-auto-translate'), $default_lang));
        }

        $source_post = get_post($translations[$default_lang]);
        $content = $source_post->post_content;
        $title = $source_post->post_title;

        $translated_title = $this->translate_with_mymemory($title, $target_lang, false, $default_lang);
        $translated_content = $this->translate_with_mymemory($content, $target_lang, true, $default_lang);

        if (strpos($translated_title, 'MYMEMORY ERROR:') === 0) {
            wp_send_json_error(str_replace('MYMEMORY ERROR:', '', $translated_title));
        }
        if (strpos($translated_content, 'MYMEMORY ERROR:') === 0) {
            wp_send_json_error(str_replace('MYMEMORY ERROR:', '', $translated_content));
        }

        if (!$translated_title || !$translated_content) {
            error_log('Polylang Translator: Translation failed. Target Lang: ' . $target_lang);
            wp_send_json_error(__('Translation failed. Please check the debug.log.', 'polylang-auto-translate'));
        }

        wp_send_json_success([
            'title' => $translated_title,
            'content' => $translated_content
        ]);
    }

    private function translate_with_mymemory($text, $lang, $is_content = false, $source_lang = 'de') {
        if (!$is_content) {
            return $this->mymemory_request($text, $lang, $source_lang);
        }


        $pattern = '/(<!--\s+wp:.*?-->|<!--\s+\/wp:.*?-->)/s';
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        $translated_parts = [];
        foreach ($parts as $part) {
            if (preg_match($pattern, $part)) {
                $translated_parts[] = $part;
            } else {
                $translated = $this->translate_html_content($part, $lang, $source_lang);
                if ($translated === false || strpos($translated, 'MYMEMORY ERROR:') === 0) {
                    return $translated;
                }
                $translated_parts[] = $translated;
            }
        }

        return implode('', $translated_parts);
    }

    private function translate_html_content($html, $lang, $source_lang = 'de') {
        if (empty(trim(strip_tags($html)))) {
            return $html;
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);

        $html_prepared = '<div>' . $html . '</div>';
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html_prepared . '</body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $textNodes = $xpath->query('//text() | //@aria-label | //@title | //@placeholder | //@alt');

        foreach ($textNodes as $node) {
            $original_text = $node->nodeValue;
            if (trim($original_text) !== '') {
                if (preg_match('/[a-zA-ZäöüÄÖÜß]/', $original_text)) {
                    preg_match('/^(\s*)/u', $original_text, $leading);
                    preg_match('/(\s*)$/u', $original_text, $trailing);
                    $trimmed_text = trim($original_text);
                    $translated_text = $this->mymemory_request($trimmed_text, $lang, $source_lang);
                    if ($translated_text === false) {
                        return false;
                    }
                    if (strpos($translated_text, 'MYMEMORY ERROR:') === 0) {
                        return $translated_text;
                    }
                    if ($translated_text) {
                        $node->nodeValue = $leading[1] . $translated_text . $trailing[1];
                    }
                }
            }
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        $result = '';
        foreach ($body->childNodes as $child) {
            $result .= $dom->saveHTML($child);
        }

        if (strpos($result, '<div>') === 0) {
            $result = substr($result, 5, -6);
        }

        return $result;
    }

    private function mymemory_request($text, $lang, $source_lang = 'de') {
        $lang_pair = $source_lang . '|' . $lang;

        $max_length = 450;
        $chunks = [];

        if (mb_strlen($text) <= $max_length) {
            $chunks = [$text];
        } else {
            $current_chunk = '';
            $sentences = preg_split('/(?<=[.?!])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

            foreach ($sentences as $sentence) {
                if (mb_strlen($current_chunk . ' ' . $sentence) > $max_length) {
                    if (!empty($current_chunk)) {
                        $chunks[] = trim($current_chunk);
                        $current_chunk = $sentence;
                    } else {
                        // Ein Satz ist zu lang, an Leerzeichen splitten
                        $words = explode(' ', $sentence);
                        foreach ($words as $word) {
                            if (mb_strlen($current_chunk . ' ' . $word) > $max_length) {
                                $chunks[] = trim($current_chunk);
                                $current_chunk = $word;
                            } else {
                                $current_chunk .= (empty($current_chunk) ? '' : ' ') . $word;
                            }
                        }
                    }
                } else {
                    $current_chunk .= (empty($current_chunk) ? '' : ' ') . $sentence;
                }
            }
            if (!empty($current_chunk)) {
                $chunks[] = trim($current_chunk);
            }
        }

        $translated_chunks = [];
        foreach ($chunks as $chunk) {
            if (empty(trim($chunk))) continue;

            $url = 'https://api.mymemory.translated.net/get?q=' . urlencode($chunk) . '&langpair=' . urlencode($lang_pair);

            $email = get_option('pll_ai_translate_email');
            if ($email) {
                $url .= '&de=' . urlencode($email);
            }

            $key = get_option('pll_ai_translate_key');
            if ($key) {
                $url .= '&key=' . urlencode($key);
            }

            $response = wp_remote_get($url, [
                'timeout' => 30,
                'sslverify' => false
            ]);

            if (is_wp_error($response)) {
                error_log('Polylang Translator: MyMemory API Error: ' . $response->get_error_message());
                return false;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            if (isset($body['responseData']['translatedText']) && $body['responseStatus'] == 200) {
                $translated_chunks[] = trim(html_entity_decode($body['responseData']['translatedText']));
            } else {
                $error_msg = isset($body['responseDetails']) ? $body['responseDetails'] : print_r($body, true);
                error_log('Polylang Translator: MyMemory API Error: ' . $error_msg);

                if (isset($body['responseStatus']) && $body['responseStatus'] != 200) {
                    return 'MYMEMORY ERROR: ' . $error_msg;
                }

                return false;
            }
        }

        return implode(' ', $translated_chunks);
    }
}

new Polylang_Translator();
