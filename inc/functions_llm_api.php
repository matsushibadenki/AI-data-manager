<?php
/*
 * URL: /wp-content/themes/AI-data-manager/inc/functions_llm_api.php
 * File Name: functions_llm_api.php
 * Description: LLM APIとの通信およびデータバリエーションの生成処理。URLやファイルからLLMを使って学習データを抽出・生成する機能、Ollama等のローカル/外部API連携を管理。
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_frontend_learning_data_generate_variation', 'frontend_learning_data_generate_variation_handler');
add_action('wp_ajax_nopriv_frontend_learning_data_generate_variation', 'frontend_learning_data_generate_variation_handler');

function frontend_learning_data_generate_variation_handler()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'learning_data_action')) {
        wp_send_json_error(['message' => esc_html__('セッションが無効です。', 'fourier')]);
    }

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $provider = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : '';
    $count = isset($_POST['count']) ? intval($_POST['count']) : 1;
    $extra_prompt = isset($_POST['extra_prompt']) ? sanitize_textarea_field($_POST['extra_prompt']) : '';

    if (!$post_id || !$provider || $count < 1) {
        wp_send_json_error(['message' => esc_html__('パラメータが不足しています。', 'fourier')]);
    }

    $post = get_post($post_id);
    if (!$post || get_post_meta($post_id, 'is_learning_data', true) !== '1') {
        wp_send_json_error(['message' => esc_html__('対象のデータが見つかりません。', 'fourier')]);
    }

    $original_content = $post->post_content;
    $json_content = json_decode($original_content, true);
    if (!$json_content || !isset($json_content['format']) || !isset($json_content['data'])) {
        wp_send_json_error(['message' => esc_html__('データのフォーマットが不正です。', 'fourier')]);
    }

    $format = $json_content['format'];
    $data_to_vary = json_encode($json_content['data'], JSON_UNESCAPED_UNICODE);

    // プロンプト構築
    $system_prompt = "あなたはAI学習データを作成する優秀なデータエンジニアです。与えられたJSONデータの構造を完全に維持したまま、内容を少し変えた「バリエーション」を作成してください。出力は必ず要求された数のJSON配列のみを出力してください。マークダウンや余計なテキストは含めないでください。";
    $user_prompt = "フォーマット: {$format}\n元のデータ:\n{$data_to_vary}\n\n生成数: {$count}個\n";
    if ($extra_prompt) {
        $user_prompt .= "追加の指示: {$extra_prompt}\n\n";
    }
    $user_prompt .= "出力は次のようなJSON配列のみにしてください: [ { ... }, { ... } ]";

    $current_user_id = get_current_user_id();
    $generated_variations = [];

    // プロバイダごとの通信処理
    try {
        switch ($provider) {
            case 'openai':
                $api_key = get_user_meta($current_user_id, 'llm_openai_api_key', true);
                $model = get_user_meta($current_user_id, 'llm_openai_model', true) ?: 'gpt-5.5';
                if (!$api_key) throw new Exception("OpenAI API Keyが設定されていません。");
                $generated_variations = llm_api_call_openai($api_key, $model, $system_prompt, $user_prompt);
                break;

            case 'gemini':
                $api_key = get_user_meta($current_user_id, 'llm_gemini_api_key', true);
                $model = get_user_meta($current_user_id, 'llm_gemini_model', true) ?: 'gemini-3.1-pro-preview';
                if (!$api_key) throw new Exception("Gemini API Keyが設定されていません。");
                $generated_variations = llm_api_call_gemini($api_key, $model, $system_prompt, $user_prompt);
                break;

            case 'ollama':
                $url = get_user_meta($current_user_id, 'llm_ollama_url', true) ?: 'http://127.0.0.1:11434';
                $model = get_user_meta($current_user_id, 'llm_ollama_model', true) ?: 'gemma4:12b-mlx';
                $generated_variations = llm_api_call_ollama($url, $model, $system_prompt, $user_prompt);
                break;

            case 'custom':
                $url = get_user_meta($current_user_id, 'llm_custom_url', true) ?: 'http://127.0.0.1:8080/v1';
                $model = get_user_meta($current_user_id, 'llm_custom_model', true);
                $generated_variations = llm_api_call_custom($url, $model, $system_prompt, $user_prompt);
                break;

            default:
                throw new Exception("不明なプロバイダです。");
        }
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }

    if (!is_array($generated_variations) || count($generated_variations) === 0) {
        wp_send_json_error(['message' => '有効なJSON配列が返されませんでした。']);
    }

    // 新規ポストとして保存
    $saved_count = 0;
    foreach ($generated_variations as $idx => $variation_data) {
        $new_post_data = [
            'post_title'   => $post->post_title . ' (Var ' . ($idx + 1) . ')',
            'post_content' => wp_slash(json_encode([
                'format' => $format,
                'data'   => $variation_data
            ], JSON_UNESCAPED_UNICODE)),
            'post_status'  => 'publish',
            'post_type'    => 'post'
        ];

        $new_post_id = wp_insert_post($new_post_data);
        if (!is_wp_error($new_post_id) && $new_post_id > 0) {
            update_post_meta($new_post_id, 'is_learning_data', '1');
            // メタデータもコピー
            $meta_keys = ['learning_format', 'learning_language', 'learning_category', 'learning_difficulty', 'learning_quality', 'learning_source', 'learning_tags', 'learning_char_count'];
            foreach ($meta_keys as $key) {
                $val = get_post_meta($post_id, $key, true);
                if ($val !== '') update_post_meta($new_post_id, $key, $val);
            }
            update_post_meta($new_post_id, 'learning_version', 1);
            $saved_count++;
        }
    }

    wp_send_json_success([
        'message' => sprintf(esc_html__('%d個のバリエーションを生成・保存しました。', 'fourier'), $saved_count),
        'saved_count' => $saved_count
    ]);
}

add_action('wp_ajax_frontend_learning_data_distill', 'frontend_learning_data_distill_handler');
add_action('wp_ajax_nopriv_frontend_learning_data_distill', 'frontend_learning_data_distill_handler');

function frontend_learning_data_distill_handler()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'learning_data_action')) {
        wp_send_json_error(['message' => esc_html__('セッションが無効です。', 'fourier')]);
    }

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $provider = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : '';
    $strategy = isset($_POST['strategy']) ? sanitize_text_field($_POST['strategy']) : 'refine';
    $extra_prompt = isset($_POST['extra_prompt']) ? sanitize_textarea_field($_POST['extra_prompt']) : '';

    if (!$post_id || !$provider) {
        wp_send_json_error(['message' => esc_html__('パラメータが不足しています。', 'fourier')]);
    }

    $post = get_post($post_id);
    if (!$post || get_post_meta($post_id, 'is_learning_data', true) !== '1') {
        wp_send_json_error(['message' => esc_html__('対象のデータが見つかりません。', 'fourier')]);
    }

    $original_content = $post->post_content;
    $json_content = json_decode($original_content, true);
    if (!$json_content || !isset($json_content['data'])) {
        wp_send_json_error(['message' => esc_html__('データのフォーマットが不正です。', 'fourier')]);
    }

    $format = $json_content['format'] ?? 'plain';
    $data_to_distill = json_encode($json_content['data'], JSON_UNESCAPED_UNICODE);

    // プロンプト構築（高品質化）
    $system_prompt = "あなたはAI学習データを作成・精製する優秀なデータエンジニアです。情報の正確性、一貫性、多様性を確保し、ハルシネーションを含まない最高品質の学習データを作成してください。\n";
    $system_prompt .= "【重要要件】\n";
    $system_prompt .= "1. 出力は必ずJSON形式のみとしてください。\n";
    $system_prompt .= "2. 最終的な結果を生成する前に、必ず内部的な推論・自己評価を `\"draft_thought\"` というキーに出力し、その後に実際のデータを `\"data\"` キー内に配列として配置してください。\n";

    $user_prompt = "元のフォーマット: {$format}\n元のデータ:\n{$data_to_distill}\n\n";

    $target_format = $format;

    if ($strategy === 'refine') {
        $user_prompt .= "【指示】元のデータの構造とキーを完全に維持したまま、内容をより高品質、正確、詳細、プロフェッショナルに書き直して（精製して）ください。";
    } elseif ($strategy === 'extract') {
        $target_format = 'instruction';
        $user_prompt .= "【指示】このデータから「Instruction（指示 / 質問）」と「Output（回答）」のペアを可能な限り抽出し、Instruction形式のデータに変換してください。各要素は `instruction`, `input` (任意), `output` というキーを持つJSONオブジェクトとしてください。";
    } elseif ($strategy === 'cot') {
        $target_format = 'cot';
        $user_prompt .= "【指示】この回答データが導かれるまでの「論理的な思考プロセス（Chain-of-Thought）」を詳細に考え、それをデータに付加してください。出力の構造は `instruction`, `thought_process` (思考ステップ), `output` (最終回答) というキーを持つJSONオブジェクトとしてください。";
    }

    if ($extra_prompt) {
        $user_prompt .= "\n\n追加の指示: {$extra_prompt}";
    }
    $user_prompt .= "\n\n出力構造は { \"draft_thought\": \"...\", \"data\": [ { ... }, { ... } ] } としてください。";

    $current_user_id = get_current_user_id();
    $llm_response_text = "";

    // プロバイダごとの通信処理
    try {
        switch ($provider) {
            case 'openai':
                $api_key = get_user_meta($current_user_id, 'llm_openai_api_key', true);
                $model = get_user_meta($current_user_id, 'llm_openai_model', true) ?: 'gpt-5.5';
                if (!$api_key) throw new Exception("OpenAI API Keyが設定されていません。");
                $llm_response_text = llm_api_call_openai($api_key, $model, $system_prompt, $user_prompt);
                break;
            case 'gemini':
                $api_key = get_user_meta($current_user_id, 'llm_gemini_api_key', true);
                $model = get_user_meta($current_user_id, 'llm_gemini_model', true) ?: 'gemini-3.1-pro-preview';
                if (!$api_key) throw new Exception("Gemini API Keyが設定されていません。");
                $llm_response_text = llm_api_call_gemini($api_key, $model, $system_prompt, $user_prompt);
                break;
            case 'ollama':
                $url = get_user_meta($current_user_id, 'llm_ollama_url', true) ?: 'http://127.0.0.1:11434';
                $model = get_user_meta($current_user_id, 'llm_ollama_model', true) ?: 'gemma4:12b-mlx';
                $llm_response_text = llm_api_call_ollama($url, $model, $system_prompt, $user_prompt);
                break;
            case 'custom':
                $url = get_user_meta($current_user_id, 'llm_custom_url', true) ?: 'http://127.0.0.1:8080/v1';
                $model = get_user_meta($current_user_id, 'llm_custom_model', true);
                $llm_response_text = llm_api_call_custom($url, $model, $system_prompt, $user_prompt);
                break;
            default:
                throw new Exception("不明なプロバイダです。");
        }
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }

    if (empty($llm_response_text)) {
        wp_send_json_error(['message' => 'LLMから有効な応答が返されませんでした。']);
    }

    $parsed_json = $llm_response_text; // Already parsed by llm_api_call_*
    $distilled_results = $parsed_json;
    if (is_array($parsed_json) && isset($parsed_json['draft_thought']) && isset($parsed_json['data'])) {
        $distilled_results = $parsed_json['data'];
    }

    if (!is_array($distilled_results) || count($distilled_results) === 0) {
        wp_send_json_error(['message' => '有効なデータ配列が返されませんでした。']);
    }

    $saved_count = 0;
    foreach ($distilled_results as $idx => $distilled_data) {
        $title_prefix = '[Distilled] ';
        if ($strategy === 'extract') $title_prefix = '[Extracted] ';
        if ($strategy === 'cot') $title_prefix = '[CoT] ';

        $new_post_data = [
            'post_title'   => $title_prefix . $post->post_title . ($idx > 0 ? " ($idx)" : ""),
            'post_content' => wp_slash(json_encode([
                'format' => $target_format,
                'data'   => $distilled_data
            ], JSON_UNESCAPED_UNICODE)),
            'post_status'  => 'publish',
            'post_type'    => 'post'
        ];

        $new_post_id = wp_insert_post($new_post_data);
        if (!is_wp_error($new_post_id) && $new_post_id > 0) {
            update_post_meta($new_post_id, 'is_learning_data', '1');
            $meta_keys = ['learning_language', 'learning_category', 'learning_difficulty', 'learning_quality', 'learning_source', 'learning_tags'];
            foreach ($meta_keys as $key) {
                $val = get_post_meta($post_id, $key, true);
                if ($val !== '') update_post_meta($new_post_id, $key, $val);
            }
            update_post_meta($new_post_id, 'learning_format', $target_format);
            update_post_meta($new_post_id, 'learning_version', 1);
            $saved_count++;
        }
    }

    wp_send_json_success([
        'message' => sprintf(esc_html__('%d個の蒸留済みデータを保存しました。', 'fourier'), $saved_count),
        'saved_count' => $saved_count
    ]);
}

// ------------------------------------------------------------------
// APIリクエストラッパー関数群
// ------------------------------------------------------------------

function _parse_json_from_llm_response($text)
{
    // マークダウンのコードブロック(```json ... ```)を取り除く
    $text = preg_replace('/^```json\s*/m', '', $text);
    $text = preg_replace('/```$/m', '', $text);
    $text = trim($text);

    // 最初と最後が [ ] または { } でない場合はエラーにしたいが、まずはパースしてみる
    $decoded = json_decode($text, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        if (!wp_is_numeric_array($decoded)) {
            // draft_thought と data がある場合、または variations がある場合はそのまま返す
            if ((isset($decoded['draft_thought']) && isset($decoded['data'])) || isset($decoded['variations'])) {
                return $decoded;
            }
            return [$decoded]; // それ以外は配列に強制
        }
        return $decoded;
    }

    // パース失敗時、オブジェクトの開始部分を強引に探す
    $start_obj = strpos($text, '{');
    $end_obj = strrpos($text, '}');
    if ($start_obj !== false && $end_obj !== false && $start_obj < $end_obj) {
        $substr_obj = substr($text, $start_obj, $end_obj - $start_obj + 1);
        $decoded_obj = json_decode($substr_obj, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (!wp_is_numeric_array($decoded_obj)) {
                if ((isset($decoded_obj['draft_thought']) && isset($decoded_obj['data'])) || isset($decoded_obj['variations'])) {
                    return $decoded_obj;
                }
                return [$decoded_obj];
            }
            return $decoded_obj;
        }
    }

    // オブジェクトが見つからない場合、配列の開始部分を強引に探す
    $start = strpos($text, '[');
    $end = strrpos($text, ']');
    if ($start !== false && $end !== false && $start < $end) {
        $substr = substr($text, $start, $end - $start + 1);
        $decoded = json_decode($substr, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
    }

    throw new Exception("LLMの応答からJSONをパースできませんでした。");
}

function llm_api_call_openai($api_key, $model, $system, $user)
{
    $url = 'https://api.openai.com/v1/chat/completions';
    $body = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user]
        ],
        'response_format' => ['type' => 'json_object'], // 確実なJSON出力（プロンプトにJSONを出力しろという指示が必要）
    ];
    // JSONの配列を出力させたい場合、json_object指定時に { "variations": [ ... ] } というルートオブジェクトが必要になるため、ユーザープロンプトを少し調整
    $body['messages'][1]['content'] .= "\n必ずルートはオブジェクトにし、 `variations` というキーの中に配列を入れてください。例: {\"variations\": [ {...} ] }";

    $response = wp_remote_post($url, [
        'timeout' => 60,
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key
        ],
        'body' => json_encode($body)
    ]);

    if (is_wp_error($response)) {
        throw new Exception($response->get_error_message());
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body['error'])) {
        throw new Exception($body['error']['message']);
    }

    $content = $body['choices'][0]['message']['content'];
    $parsed = json_decode($content, true);
    if (isset($parsed['variations'])) {
        return $parsed['variations'];
    }
    return _parse_json_from_llm_response($content);
}

function llm_api_call_gemini($api_key, $model, $system, $user)
{
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";
    $body = [
        'system_instruction' => [
            'parts' => [['text' => $system]]
        ],
        'contents' => [
            [
                'role' => 'user',
                'parts' => [['text' => $user]]
            ]
        ],
        'generationConfig' => [
            'responseMimeType' => 'application/json'
        ]
    ];

    $response = wp_remote_post($url, [
        'timeout' => 60,
        'headers' => [
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($body)
    ]);

    if (is_wp_error($response)) {
        throw new Exception($response->get_error_message());
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body['error'])) {
        throw new Exception($body['error']['message']);
    }

    $content = $body['candidates'][0]['content']['parts'][0]['text'] ?? '[]';
    return _parse_json_from_llm_response($content);
}

function llm_api_call_ollama($base_url, $model, $system, $user)
{
    $url = rtrim($base_url, '/') . '/api/chat';
    $body = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user]
        ],
        'format' => 'json',
        'stream' => false
    ];

    $response = wp_remote_post($url, [
        'timeout' => 1800, // ローカルは非常に時間がかかる場合があるため30分に設定
        'headers' => [
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($body)
    ]);

    if (is_wp_error($response)) {
        throw new Exception($response->get_error_message());
    }

    $res_body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($res_body['error'])) {
        throw new Exception($res_body['error']);
    }

    $content = $res_body['message']['content'];
    // ollamaの場合 json format 指定してもルートが配列にならない場合があるため、 {"variations": []} 形式を要求するのが安全
    // 今回は_parse_json_from_llm_responseでよしなに処理する
    $parsed = _parse_json_from_llm_response($content);
    if (isset($parsed['variations'])) return $parsed['variations'];
    return $parsed;
}

function llm_api_call_custom($base_url, $model, $system, $user)
{
    $url = rtrim($base_url, '/') . '/chat/completions';
    $body = [
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user]
        ],
    ];
    if ($model) {
        $body['model'] = $model;
    }

    $response = wp_remote_post($url, [
        'timeout' => 120,
        'headers' => [
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($body)
    ]);

    if (is_wp_error($response)) {
        throw new Exception($response->get_error_message());
    }

    $res_body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($res_body['error'])) {
        $err = is_array($res_body['error']) ? ($res_body['error']['message'] ?? 'Unknown Error') : $res_body['error'];
        throw new Exception($err);
    }

    $content = $res_body['choices'][0]['message']['content'];
    $parsed = _parse_json_from_llm_response($content);
    if (isset($parsed['variations'])) return $parsed['variations'];
    return $parsed;
}

add_action('wp_ajax_frontend_learning_data_scrape_url', 'frontend_learning_data_scrape_url_handler');
add_action('wp_ajax_nopriv_frontend_learning_data_scrape_url', 'frontend_learning_data_scrape_url_handler');

function frontend_learning_data_scrape_url_handler()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'learning_data_action')) {
        wp_send_json_error(['message' => esc_html__('セッションが無効です。', 'fourier')]);
    }

    $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
    $target_format = isset($_POST['target_format']) ? sanitize_text_field($_POST['target_format']) : 'instruction';
    $provider = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : 'openai';
    $extra_prompt = isset($_POST['extra_prompt']) ? sanitize_textarea_field($_POST['extra_prompt']) : '';
    $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';

    if (!$url || !$title) {
        wp_send_json_error(['message' => esc_html__('URLとタイトルは必須です。', 'fourier')]);
    }

    // URLからホストを取得
    $parsed_url = parse_url($url);
    $host = isset($parsed_url['host']) ? strtolower($parsed_url['host']) : '';
    $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';

    $text = '';
    $is_custom_scraped = false;

    // 1. カスタムスクレイパー
    if (strpos($host, 'wikipedia.org') !== false) {
        $path_parts = explode('/', trim($path, '/'));
        if (count($path_parts) >= 2 && $path_parts[0] === 'wiki') {
            $page_title = urldecode(end($path_parts));
            $lang = explode('.', $host)[0] ?: 'en';
            $api_url = "https://{$lang}.wikipedia.org/w/api.php?action=query&prop=extracts&explaintext=1&titles=" . urlencode($page_title) . "&format=json";
            $response = wp_remote_get($api_url, ['timeout' => 30]);
            if (!is_wp_error($response)) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (isset($body['query']['pages'])) {
                    $pages = $body['query']['pages'];
                    $page = reset($pages);
                    if (isset($page['extract'])) {
                        $text = "【Wikipedia Extract】\n" . $page['title'] . "\n\n" . $page['extract'];
                        $is_custom_scraped = true;
                    }
                }
            }
        }
    } elseif (strpos($host, 'arxiv.org') !== false) {
        if (preg_match('/\/abs\/([0-9\.]+)/', $path, $matches)) {
            $arxiv_id = $matches[1];
            $api_url = "http://export.arxiv.org/api/query?id_list=" . urlencode($arxiv_id);
            $response = wp_remote_get($api_url, ['timeout' => 30]);
            if (!is_wp_error($response)) {
                $body = wp_remote_retrieve_body($response);
                if ($body) {
                    if (preg_match('/<title>(.*?)<\/title>.*?<summary>(.*?)<\/summary>/s', $body, $xml_matches)) {
                        $text = "【ArXiv Paper】\nTitle: " . trim(strip_tags($xml_matches[1])) . "\n\nAbstract: " . trim(strip_tags($xml_matches[2]));
                        $is_custom_scraped = true;
                    }
                }
            }
        }
    } elseif (strpos($host, 'github.com') !== false) {
        $path_parts = explode('/', trim($path, '/'));
        if (count($path_parts) >= 2) {
            $owner = $path_parts[0];
            $repo = $path_parts[1];
            if (count($path_parts) == 2 || (count($path_parts) == 3 && $path_parts[2] == 'tree')) {
                $api_url = "https://api.github.com/repos/{$owner}/{$repo}/readme";
                $response = wp_remote_get($api_url, ['timeout' => 30, 'headers' => ['User-Agent' => 'WordPress']]);
                if (!is_wp_error($response)) {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    if (isset($body['content']) && isset($body['encoding']) && $body['encoding'] === 'base64') {
                        $text = "【GitHub README: {$owner}/{$repo}】\n\n" . base64_decode($body['content']);
                        $is_custom_scraped = true;
                    }
                }
            } elseif (count($path_parts) >= 5 && $path_parts[2] === 'blob') {
                $branch = $path_parts[3];
                $filepath = implode('/', array_slice($path_parts, 4));
                $raw_url = "https://raw.githubusercontent.com/{$owner}/{$repo}/{$branch}/{$filepath}";
                $response = wp_remote_get($raw_url, ['timeout' => 30]);
                if (!is_wp_error($response)) {
                    $text = "【GitHub File: {$owner}/{$repo}/{$filepath}】\n\n" . wp_remote_retrieve_body($response);
                    $is_custom_scraped = true;
                }
            }
        }
    } elseif (strpos($host, 'codepen.io') !== false) {
        $path_parts = explode('/', trim($path, '/'));
        if (count($path_parts) >= 3 && $path_parts[1] === 'pen') {
            $user = $path_parts[0];
            $pen_id = $path_parts[2];
            $base_pen_url = "https://codepen.io/{$user}/pen/{$pen_id}";
            $html_res = wp_remote_get($base_pen_url . ".html");
            $css_res = wp_remote_get($base_pen_url . ".css");
            $js_res = wp_remote_get($base_pen_url . ".js");
            $text = "【CodePen Snippet】\n";
            if (!is_wp_error($html_res)) $text .= "--- HTML ---\n" . wp_remote_retrieve_body($html_res) . "\n\n";
            if (!is_wp_error($css_res)) $text .= "--- CSS ---\n" . wp_remote_retrieve_body($css_res) . "\n\n";
            if (!is_wp_error($js_res)) $text .= "--- JS ---\n" . wp_remote_retrieve_body($js_res) . "\n\n";
            $is_custom_scraped = true;
        }
    } elseif (strpos($host, 'reddit.com') !== false) {
        if (strpos($path, '/comments/') !== false) {
            $json_url = rtrim($url, '/') . '.json';
            $response = wp_remote_get($json_url, ['timeout' => 30, 'headers' => ['User-Agent' => 'WordPress-Data-Scraper/1.0']]);
            if (!is_wp_error($response)) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (is_array($body) && count($body) >= 2) {
                    $post_data = $body[0]['data']['children'][0]['data'];
                    $comments_data = $body[1]['data']['children'];
                    $text = "【Reddit Post】\nTitle: " . $post_data['title'] . "\nBody: " . $post_data['selftext'] . "\n\n--- Top Comments ---\n";
                    $comment_count = 0;
                    foreach ($comments_data as $comment) {
                        if ($comment_count >= 10) break;
                        if (isset($comment['data']['body'])) {
                            $text .= "- " . str_replace("\n", " ", $comment['data']['body']) . "\n";
                            $comment_count++;
                        }
                    }
                    $is_custom_scraped = true;
                }
            }
        }
    }

    // フォールバック（通常のHTMLスクレイピング）
    if (!$is_custom_scraped) {
        $response = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => esc_html__('URLの取得に失敗しました: ', 'fourier') . $response->get_error_message()]);
        }
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            wp_send_json_error(['message' => esc_html__('URLの取得に失敗しました (Status: ', 'fourier') . $status_code . ')']);
        }
        $html = wp_remote_retrieve_body($response);
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
        $text = wp_strip_all_tags($html);
    }

    // トークン数制限
    $text = preg_replace('/\s+/', ' ', $text);
    $text = mb_substr(trim($text), 0, 15000);

    if (empty($text)) {
        wp_send_json_error(['message' => esc_html__('テキストを抽出できませんでした。', 'fourier')]);
    }

    // 3. プロンプト構築（高品質化対応）
    $system_prompt = "あなたはAI学習データを作成する優秀なデータエンジニアです。情報の正確性、一貫性、多様性を確保し、ハルシネーション（嘘）を含まない最高品質の学習データを作成してください。\n";
    $system_prompt .= "【重要要件】\n";
    $system_prompt .= "1. 出力は必ずJSON形式のみとしてください。\n";
    $system_prompt .= "2. 最終的な結果を生成する前に、必ず内部的な推論・自己評価を `\"draft_thought\"` というキーに出力し、その後に実際のデータを配置してください。\n";
    $system_prompt .= "（例: { \"draft_thought\": \"抽出したテキストから...という論理を組み立てる\", \"data\": [ { \"instruction\": \"...\", ... } ] } ）";

    $user_prompt = "【指定フォーマット】: {$target_format}\n";
    if ($extra_prompt) {
        $user_prompt .= "【追加の指示】: {$extra_prompt}\n";
    }
    $user_prompt .= "\n【抽出元データ（Web/API）】:\n{$text}\n\n";
    $user_prompt .= "この抽出元データから、フォーマットに従ったJSONを出力してください。構造は { \"draft_thought\": \"...\", \"data\": <指定フォーマットに基づくデータ> } としてください。";

    // 4. LLM API 呼び出し
    $current_user_id = get_current_user_id();
    $llm_response_text = "";
    try {
        switch ($provider) {
            case 'openai':
                $api_key = get_user_meta($current_user_id, 'llm_openai_api_key', true);
                $model = get_user_meta($current_user_id, 'llm_openai_model', true) ?: 'gpt-5.5';
                if (!$api_key) throw new Exception("OpenAI API Keyが設定されていません。");
                $llm_response_text = llm_api_call_openai($api_key, $model, $system_prompt, $user_prompt);
                break;
            case 'gemini':
                $api_key = get_user_meta($current_user_id, 'llm_gemini_api_key', true);
                $model = get_user_meta($current_user_id, 'llm_gemini_model', true) ?: 'gemini-3.1-pro-preview';
                if (!$api_key) throw new Exception("Gemini API Keyが設定されていません。");
                $llm_response_text = llm_api_call_gemini($api_key, $model, $system_prompt, $user_prompt);
                break;
            case 'ollama':
                $url = get_user_meta($current_user_id, 'llm_ollama_url', true) ?: 'http://127.0.0.1:11434';
                $model = get_user_meta($current_user_id, 'llm_ollama_model', true) ?: 'gemma4:12b-mlx';
                $llm_response_text = llm_api_call_ollama($url, $model, $system_prompt, $user_prompt);
                break;
            case 'custom':
                $url = get_user_meta($current_user_id, 'llm_custom_url', true) ?: 'http://127.0.0.1:8080/v1';
                $model = get_user_meta($current_user_id, 'llm_custom_model', true);
                $llm_response_text = llm_api_call_custom($url, $model, $system_prompt, $user_prompt);
                break;
            default:
                throw new Exception("不明なプロバイダです。");
        }
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }

    if (empty($llm_response_text)) {
        wp_send_json_error(['message' => 'LLMから有効な応答が返されませんでした。']);
    }

    // 5. データ保存
    $parsed_json = $llm_response_text; // Already parsed by llm_api_call_*
    $final_data = $parsed_json;
    if (is_array($parsed_json) && isset($parsed_json['draft_thought']) && isset($parsed_json['data'])) {
        $final_data = $parsed_json['data'];
    }

    $payload = [
        'format' => $target_format,
        'data' => $final_data
    ];

    $post_data = array(
        'post_title'   => $title,
        'post_content' => wp_slash(json_encode($payload, JSON_UNESCAPED_UNICODE)),
        'post_status'  => 'publish',
        'post_type'    => 'post',
        'post_author'  => $current_user_id
    );

    $post_id = wp_insert_post($post_data);
    if (is_wp_error($post_id)) {
        wp_send_json_error(['message' => esc_html__('データの保存に失敗しました。', 'fourier')]);
    }

    update_post_meta($post_id, 'is_learning_data', '1');
    $meta_fields = ['language', 'category', 'difficulty', 'quality', 'source', 'tags'];
    foreach ($meta_fields as $field) {
        if (isset($_POST[$field]) && $_POST[$field] !== '') {
            $val = sanitize_text_field($_POST[$field]);
            update_post_meta($post_id, 'learning_data_' . $field, $val);
        }
    }
    // ソースがない場合はURLをソースにする
    if (empty($_POST['source'])) {
        update_post_meta($post_id, 'learning_data_source', $url);
    }

    wp_send_json_success(['post_id' => $post_id]);
}

// --- 新規：シードデータからの蒸留処理 ---
add_action('wp_ajax_frontend_learning_data_distill_from_seed', 'frontend_learning_data_distill_from_seed_handler');
add_action('wp_ajax_nopriv_frontend_learning_data_distill_from_seed', 'frontend_learning_data_distill_from_seed_handler');

function frontend_learning_data_distill_from_seed_handler()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'learning_data_action')) {
        wp_send_json_error(['message' => esc_html__('セッションが無効です。', 'fourier')]);
    }

    $seed_data = isset($_POST['seed_data']) ? sanitize_textarea_field($_POST['seed_data']) : '';
    $distill_method = isset($_POST['distill_method']) ? sanitize_text_field($_POST['distill_method']) : 'self-instruct';
    $target_format = isset($_POST['target_format']) ? sanitize_text_field($_POST['target_format']) : 'instruction';
    $provider = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : 'openai';
    $extra_prompt = isset($_POST['extra_prompt']) ? sanitize_textarea_field($_POST['extra_prompt']) : '';
    $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : 'Distilled Data';

    if (!$seed_data || !$provider) {
        wp_send_json_error(['message' => esc_html__('パラメータが不足しています。', 'fourier')]);
    }

    // 1. プロンプトの構築（高品質化）
    $system_prompt = "あなたはAI学習データを作成・精製する専門のデータエンジニアです。情報の正確性、一貫性、多様性を確保し、ハルシネーション（嘘）を含まない最高品質の学習データを作成してください。\n";
    $system_prompt .= "【重要要件】\n";
    $system_prompt .= "1. 出力は必ず指定された形式のJSONのみとしてください。\n";
    $system_prompt .= "2. 最終的な結果を生成する前に、必ず内部的な推論・自己評価を `\"draft_thought\"` というキーに出力し、その後に実際のデータを配置してください。\n";
    $system_prompt .= "（例: { \"draft_thought\": \"このトピックから多様なタスクを生成するために...と考える\", \"data\": [ { \"instruction\": \"...\", ... } ] } ）";

    $user_prompt = "【シードデータ / トピック】\n{$seed_data}\n\n";

    switch ($distill_method) {
        case 'self-instruct':
            $user_prompt .= "【指示: Self-Instruct】上記のトピックまたはデータに基づいて、多様で高品質なタスク（指示と回答のペア等）を複数生成してください。\n";
            break;
        case 'refinement':
            $user_prompt .= "【指示: Refinement】上記の入力データの品質を向上させ、より詳細で正確、かつプロフェッショナルな表現に書き直してください。元の意図や情報は維持してください。\n";
            break;
        case 'cot':
            $user_prompt .= "【指示: CoT Generation】上記のデータに対する回答が導かれるまでの「ステップバイステップの論理的な思考過程（Chain-of-Thought）」を詳細に生成し、付加してください。\n";
            break;
        case 'backtranslation':
            $user_prompt .= "【指示: Backtranslation】上記のテキストを「AIの回答」と仮定し、ユーザーがAIに入力したであろう最適なプロンプト（指示や質問）を逆生成してペアにしてください。\n";
            break;
        case 'format-conversion':
            $user_prompt .= "【指示: Format Conversion】上記のデータを、指定された構造化フォーマットに適切に変換・整形してください。\n";
            break;
    }

    if ($extra_prompt) {
        $user_prompt .= "\n【追加の指示】\n{$extra_prompt}\n\n";
    }

    $user_prompt .= "【出力フォーマット】\n生成する `data` フィールドの中身は以下のJSON構造に従ってください:\n";
    if ($target_format === 'instruction') {
        $user_prompt .= '{ "instruction": "...", "input": "...", "output": "..." } (複数生成する場合は配列)';
    } elseif ($target_format === 'chatml') {
        $user_prompt .= '{ "messages": [ { "role": "system", "content": "..." }, { "role": "user", "content": "..." }, { "role": "assistant", "content": "..." } ] }';
    } elseif ($target_format === 'cot') {
        $user_prompt .= '{ "question": "...", "thought": "...", "answer": "..." }';
    } elseif ($target_format === 'dpo') {
        $user_prompt .= '{ "prompt": "...", "chosen": "...", "rejected": "..." }';
    } else {
        $user_prompt .= '{ "text": "..." }';
    }
    $user_prompt .= "\n\n構造全体は { \"draft_thought\": \"...\", \"data\": <指定フォーマットに基づくデータ> } としてください。";

    $current_user_id = get_current_user_id();
    $llm_response_text = '';

    // 2. LLMプロバイダごとの通信
    try {
        switch ($provider) {
            case 'openai':
                $api_key = get_user_meta($current_user_id, 'llm_openai_api_key', true);
                $model = get_user_meta($current_user_id, 'llm_openai_model', true) ?: 'gpt-5.5';
                if (!$api_key) throw new Exception("OpenAI API Keyが設定されていません。");
                $llm_response_text = llm_api_call_openai($api_key, $model, $system_prompt, $user_prompt);
                break;
            case 'gemini':
                $api_key = get_user_meta($current_user_id, 'llm_gemini_api_key', true);
                $model = get_user_meta($current_user_id, 'llm_gemini_model', true) ?: 'gemini-3.1-pro-preview';
                if (!$api_key) throw new Exception("Gemini API Keyが設定されていません。");
                $llm_response_text = llm_api_call_gemini($api_key, $model, $system_prompt, $user_prompt);
                break;
            case 'ollama':
                $url = get_user_meta($current_user_id, 'llm_ollama_url', true) ?: 'http://127.0.0.1:11434';
                $model = get_user_meta($current_user_id, 'llm_ollama_model', true) ?: 'gemma4:12b-mlx';
                $llm_response_text = llm_api_call_ollama($url, $model, $system_prompt, $user_prompt);
                break;
            case 'custom':
                $url = get_user_meta($current_user_id, 'llm_custom_url', true) ?: 'http://127.0.0.1:8080/v1';
                $model = get_user_meta($current_user_id, 'llm_custom_model', true);
                $llm_response_text = llm_api_call_custom($url, $model, $system_prompt, $user_prompt);
                break;
            default:
                throw new Exception("不明なプロバイダです。");
        }
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }

    if (empty($llm_response_text)) {
        wp_send_json_error(['message' => 'LLMから有効な応答が返されませんでした。']);
    }

    // 3. パース済みデータ取得
    $parsed_json = $llm_response_text; // Already parsed by llm_api_call_*
    $final_data = $parsed_json;
    if (is_array($parsed_json) && isset($parsed_json['draft_thought']) && isset($parsed_json['data'])) {
        $final_data = $parsed_json['data'];
    }

    // 4. データ保存
    $payload = [
        'format' => $target_format,
        'data' => $final_data
    ];

    $post_data = array(
        'post_title'   => $title,
        'post_content' => wp_slash(json_encode($payload, JSON_UNESCAPED_UNICODE)),
        'post_status'  => 'publish',
        'post_type'    => 'post',
        'post_author'  => $current_user_id
    );

    $post_id = wp_insert_post($post_data);
    if (is_wp_error($post_id)) {
        wp_send_json_error(['message' => esc_html__('データの保存に失敗しました。', 'fourier')]);
    }

    update_post_meta($post_id, 'is_learning_data', '1');

    $meta_fields = ['language', 'category', 'difficulty', 'quality', 'source', 'tags'];
    foreach ($meta_fields as $field) {
        if (isset($_POST[$field]) && $_POST[$field] !== '') {
            $val = sanitize_text_field($_POST[$field]);
            update_post_meta($post_id, 'learning_data_' . $field, $val);
        }
    }

    wp_send_json_success(['post_id' => $post_id]);
}

// --- 新規：LLM接続確認用API ---
add_action('wp_ajax_test_llm_connection', 'test_llm_connection_handler');
function test_llm_connection_handler()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'test_llm_connection_action')) {
        wp_send_json_error(['message' => 'セッションが無効です。']);
    }

    $provider = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : '';

    $system_prompt = "You are a helpful assistant.";
    $user_prompt = "Reply with exactly this JSON: { \"status\": \"OK\" }";

    $response_data = "";
    try {
        switch ($provider) {
            case 'openai':
                $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
                $model = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : 'gpt-5.5';
                if (!$api_key) throw new Exception("API Keyが空です。");
                $response_data = llm_api_call_openai($api_key, $model, $system_prompt, $user_prompt);
                break;
            case 'gemini':
                $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
                $model = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : 'gemini-3.1-pro-preview';
                if (!$api_key) throw new Exception("API Keyが空です。");
                $response_data = llm_api_call_gemini($api_key, $model, $system_prompt, $user_prompt);
                break;
            case 'ollama':
                $url = isset($_POST['url']) ? sanitize_text_field($_POST['url']) : 'http://127.0.0.1:11434';
                $model = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : 'gemma4:12b-mlx';
                if (!$url) throw new Exception("URLが空です。");
                $response_data = llm_api_call_ollama($url, $model, $system_prompt, $user_prompt);
                break;
            case 'custom':
                $url = isset($_POST['url']) ? sanitize_text_field($_POST['url']) : 'http://127.0.0.1:8080/v1';
                $model = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : '';
                if (!$url) throw new Exception("URLが空です。");
                $response_data = llm_api_call_custom($url, $model, $system_prompt, $user_prompt);
                break;
            default:
                throw new Exception("不明なプロバイダです。");
        }

        if (empty($response_data)) {
            throw new Exception("空の応答が返されました。");
        }
        
        $response_str = is_array($response_data) ? wp_json_encode($response_data) : (string)$response_data;

        wp_send_json_success(['message' => '接続成功！ (応答: ' . esc_html(mb_substr($response_str, 0, 50)) . ')']);
    } catch (Exception $e) {
        wp_send_json_error(['message' => '接続失敗: ' . $e->getMessage()]);
    }
}

add_action('wp_ajax_frontend_learning_data_bot_crawl', 'frontend_learning_data_bot_crawl_handler');
add_action('wp_ajax_nopriv_frontend_learning_data_bot_crawl', 'frontend_learning_data_bot_crawl_handler');

function frontend_learning_data_bot_crawl_handler() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'learning_data_action')) {
        wp_send_json_error(['message' => esc_html__('セッションが無効です。', 'fourier')]);
    }

    $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
    $target_format = isset($_POST['format']) ? sanitize_text_field($_POST['format']) : 'instruction';
    $provider = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : 'openai';
    $extra_prompt = isset($_POST['extra_prompt']) ? sanitize_textarea_field($_POST['extra_prompt']) : '';

    if (!$url) {
        wp_send_json_error(['message' => esc_html__('URLは必須です。', 'fourier')]);
    }

    // URLからページを取得
    $response = wp_remote_get($url, ['timeout' => 30]);
    if (is_wp_error($response)) {
        wp_send_json_error(['message' => esc_html__('URLの取得に失敗しました: ', 'fourier') . $response->get_error_message()]);
    }
    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
        wp_send_json_error(['message' => esc_html__('URLの取得に失敗しました (Status: ', 'fourier') . $status_code . ')']);
    }
    
    $html = wp_remote_retrieve_body($response);
    
    // タイトルの抽出
    $title = $url;
    if (preg_match('/<title>(.*?)<\/title>/is', $html, $matches)) {
        $title = trim(strip_tags($matches[1]));
    }

    $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
    $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
    $text = wp_strip_all_tags($html);

    // トークン数制限
    $text = preg_replace('/\s+/', ' ', $text);
    $text = mb_substr(trim($text), 0, 15000);

    if (empty($text)) {
        wp_send_json_error(['message' => esc_html__('テキストを抽出できませんでした。', 'fourier')]);
    }

    // プロンプト構築
    $system_prompt = "あなたはAI学習データを作成する優秀なデータエンジニアです。情報の正確性、一貫性、多様性を確保し、ハルシネーション（嘘）を含まない最高品質の学習データを作成してください。\n";
    $system_prompt .= "【重要要件】\n";
    $system_prompt .= "1. 出力は必ずJSON形式のみとしてください。\n";
    $system_prompt .= "2. 最終的な結果を生成する前に、必ず内部的な推論・自己評価を `\"draft_thought\"` というキーに出力し、その後に実際のデータを `\"data\"` に配置してください。\n";

    $user_prompt = "【指定フォーマット】: {$target_format}\n";
    if ($extra_prompt) {
        $user_prompt .= "【追加の指示】: {$extra_prompt}\n";
    }
    $user_prompt .= "\n【抽出元データ（Web）】:\n{$text}\n\n";
    $user_prompt .= "この抽出元データから、フォーマットに従ったJSONを出力してください。構造は { \"draft_thought\": \"...\", \"data\": <指定フォーマットに基づくデータ> } としてください。";

    // LLM API 呼び出し
    $current_user_id = get_current_user_id();
    $llm_response_text = "";
    try {
        switch ($provider) {
            case 'openai':
                $api_key = get_user_meta($current_user_id, 'llm_openai_api_key', true);
                $model = get_user_meta($current_user_id, 'llm_openai_model', true) ?: 'gpt-4o-mini';
                if (!$api_key) throw new Exception("OpenAI API Keyが設定されていません。");
                $llm_response_text = llm_api_call_openai($api_key, $model, $system_prompt, $user_prompt);
                break;
            case 'gemini':
                $api_key = get_user_meta($current_user_id, 'llm_gemini_api_key', true);
                $model = get_user_meta($current_user_id, 'llm_gemini_model', true) ?: 'gemini-1.5-flash';
                if (!$api_key) throw new Exception("Gemini API Keyが設定されていません。");
                $llm_response_text = llm_api_call_gemini($api_key, $model, $system_prompt, $user_prompt);
                break;
            default:
                throw new Exception("不明なプロバイダです。");
        }
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }

    if (empty($llm_response_text)) {
        wp_send_json_error(['message' => 'LLMから有効な応答が返されませんでした。']);
    }

    // データ保存
    $parsed_json = $llm_response_text;
    $final_data = $parsed_json;
    if (is_array($parsed_json) && isset($parsed_json['draft_thought']) && isset($parsed_json['data'])) {
        $final_data = $parsed_json['data'];
    } elseif (is_array($parsed_json) && !isset($parsed_json['draft_thought']) && isset($parsed_json['data'])) {
        $final_data = $parsed_json['data'];
    } elseif (is_array($parsed_json)) {
        $final_data = $parsed_json;
    }

    $payload = [
        'format' => $target_format,
        'data' => $final_data
    ];

    $post_data = array(
        'post_title'   => "[Bot] " . $title,
        'post_content' => wp_slash(json_encode($payload, JSON_UNESCAPED_UNICODE)),
        'post_status'  => 'publish',
        'post_type'    => 'post',
        'post_author'  => $current_user_id
    );

    $post_id = wp_insert_post($post_data);
    if (is_wp_error($post_id)) {
        wp_send_json_error(['message' => esc_html__('データの保存に失敗しました。', 'fourier')]);
    }

    update_post_meta($post_id, 'is_learning_data', '1');
    update_post_meta($post_id, 'learning_data_source', $url);
    update_post_meta($post_id, 'learning_data_category', 'bot_crawled');

    wp_send_json_success(['post_id' => $post_id, 'title' => $title]);
}
