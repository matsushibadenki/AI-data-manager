<?php
/*
 * Name: functions_llm_api.php
 * Description: LLM APIとの通信およびデータバリエーションの生成処理
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_frontend_learning_data_generate_variation', 'frontend_learning_data_generate_variation_handler');
add_action('wp_ajax_nopriv_frontend_learning_data_generate_variation', 'frontend_learning_data_generate_variation_handler');

function frontend_learning_data_generate_variation_handler() {
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
                $model = get_user_meta($current_user_id, 'llm_openai_model', true) ?: 'gpt-4o';
                if (!$api_key) throw new Exception("OpenAI API Keyが設定されていません。");
                $generated_variations = llm_api_call_openai($api_key, $model, $system_prompt, $user_prompt);
                break;

            case 'gemini':
                $api_key = get_user_meta($current_user_id, 'llm_gemini_api_key', true);
                $model = get_user_meta($current_user_id, 'llm_gemini_model', true) ?: 'gemini-1.5-pro-latest';
                if (!$api_key) throw new Exception("Gemini API Keyが設定されていません。");
                $generated_variations = llm_api_call_gemini($api_key, $model, $system_prompt, $user_prompt);
                break;

            case 'ollama':
                $url = get_user_meta($current_user_id, 'llm_ollama_url', true) ?: 'http://127.0.0.1:11434';
                $model = get_user_meta($current_user_id, 'llm_ollama_model', true) ?: 'llama3';
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

function frontend_learning_data_distill_handler() {
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

    // プロンプト構築
    $system_prompt = "あなたはAI学習データを作成・精製する優秀なデータエンジニアです。与えられたデータに対して要求された「蒸留処理」を行い、結果をJSON配列の形式で出力してください。出力は必ず配列のみにしてください。";
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
    $user_prompt .= "\n\n出力は次のようなJSON配列のみにしてください: [ { ... } ]";

    $current_user_id = get_current_user_id();
    $distilled_results = [];

    // プロバイダごとの通信処理
    try {
        switch ($provider) {
            case 'openai':
                $api_key = get_user_meta($current_user_id, 'llm_openai_api_key', true);
                $model = get_user_meta($current_user_id, 'llm_openai_model', true) ?: 'gpt-4o';
                if (!$api_key) throw new Exception("OpenAI API Keyが設定されていません。");
                $distilled_results = llm_api_call_openai($api_key, $model, $system_prompt, $user_prompt);
                break;
            case 'gemini':
                $api_key = get_user_meta($current_user_id, 'llm_gemini_api_key', true);
                $model = get_user_meta($current_user_id, 'llm_gemini_model', true) ?: 'gemini-1.5-pro-latest';
                if (!$api_key) throw new Exception("Gemini API Keyが設定されていません。");
                $distilled_results = llm_api_call_gemini($api_key, $model, $system_prompt, $user_prompt);
                break;
            case 'ollama':
                $url = get_user_meta($current_user_id, 'llm_ollama_url', true) ?: 'http://127.0.0.1:11434';
                $model = get_user_meta($current_user_id, 'llm_ollama_model', true) ?: 'llama3';
                $distilled_results = llm_api_call_ollama($url, $model, $system_prompt, $user_prompt);
                break;
            case 'custom':
                $url = get_user_meta($current_user_id, 'llm_custom_url', true) ?: 'http://127.0.0.1:8080/v1';
                $model = get_user_meta($current_user_id, 'llm_custom_model', true);
                $distilled_results = llm_api_call_custom($url, $model, $system_prompt, $user_prompt);
                break;
            default:
                throw new Exception("不明なプロバイダです。");
        }
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }

    if (!is_array($distilled_results) || count($distilled_results) === 0) {
        wp_send_json_error(['message' => '有効なJSON配列が返されませんでした。']);
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

function _parse_json_from_llm_response($text) {
    // マークダウンのコードブロック(```json ... ```)を取り除く
    $text = preg_replace('/^```json\s*/m', '', $text);
    $text = preg_replace('/```$/m', '', $text);
    $text = trim($text);

    // 最初と最後が [ ] または { } でない場合はエラーにしたいが、まずはパースしてみる
    $decoded = json_decode($text, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return wp_is_numeric_array($decoded) ? $decoded : [$decoded]; // 配列に強制
    }
    
    // パース失敗時、配列の開始部分を強引に探す
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

function llm_api_call_openai($api_key, $model, $system, $user) {
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

function llm_api_call_gemini($api_key, $model, $system, $user) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";
    $body = [
        'system_instruction' => [
            'parts' => [ ['text' => $system] ]
        ],
        'contents' => [
            [
                'role' => 'user',
                'parts' => [ ['text' => $user] ]
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

function llm_api_call_ollama($base_url, $model, $system, $user) {
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
        'timeout' => 120, // ローカルは時間がかかる場合がある
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

function llm_api_call_custom($base_url, $model, $system, $user) {
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

function frontend_learning_data_scrape_url_handler() {
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

    // 1. URLからHTMLを取得
    $response = wp_remote_get($url, ['timeout' => 30]);
    if (is_wp_error($response)) {
        wp_send_json_error(['message' => esc_html__('URLの取得に失敗しました: ', 'fourier') . $response->get_error_message()]);
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
        wp_send_json_error(['message' => esc_html__('URLの取得に失敗しました (Status: ', 'fourier') . $status_code . ')']);
    }

    $html = wp_remote_retrieve_body($response);

    // 2. テキスト抽出 (script, style を除去してからタグ削除)
    $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
    $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
    $text = wp_strip_all_tags($html);
    // 連続する空白や改行をまとめる
    $text = preg_replace('/\s+/', ' ', $text);
    // トークン数制限のため、先頭から15000文字程度でカット
    $text = mb_substr(trim($text), 0, 15000);

    if (empty($text)) {
        wp_send_json_error(['message' => esc_html__('テキストを抽出できませんでした。', 'fourier')]);
    }

    // 3. プロンプト構築
    $system_prompt = "あなたはAI学習データを作成する優秀なデータエンジニアです。ユーザーから提供されたWebページのテキストを読み取り、指定されたフォーマットのAI学習データを生成してください。出力は必ずJSON形式のみにし、マークダウンや余分なテキストを含めないでください。";
    $user_prompt = "【指定フォーマット】: {$target_format}\n";
    $user_prompt .= "生成するデータは、このフォーマットに沿った有効なJSON（単一のオブジェクトまたは配列）にしてください。\n";
    if ($extra_prompt) {
        $user_prompt .= "【追加の指示】: {$extra_prompt}\n";
    }
    $user_prompt .= "\n【Webページのテキスト】:\n{$text}\n";

    // 4. LLM API 呼び出し
    $current_user_id = get_current_user_id();
    $llm_response_text = "";
    try {
        switch ($provider) {
            case 'openai':
                $api_key = get_user_meta($current_user_id, 'llm_openai_api_key', true);
                $model = get_user_meta($current_user_id, 'llm_openai_model', true) ?: 'gpt-4o';
                if (!$api_key) throw new Exception("OpenAI API Keyが設定されていません。");
                $llm_response_text = llm_api_call_openai($api_key, $model, $system_prompt, $user_prompt);
                break;

            case 'gemini':
                $api_key = get_user_meta($current_user_id, 'llm_gemini_api_key', true);
                $model = get_user_meta($current_user_id, 'llm_gemini_model', true) ?: 'gemini-1.5-pro-latest';
                if (!$api_key) throw new Exception("Gemini API Keyが設定されていません。");
                $llm_response_text = llm_api_call_gemini($api_key, $model, $system_prompt, $user_prompt);
                break;

            case 'ollama':
                $url = get_user_meta($current_user_id, 'llm_ollama_url', true) ?: 'http://127.0.0.1:11434';
                $model = get_user_meta($current_user_id, 'llm_ollama_model', true) ?: 'llama3';
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

    // JSONパース
    $parsed_json = _parse_json_from_llm_response($llm_response_text);
    if ($parsed_json === null) {
        wp_send_json_error(['message' => esc_html__('LLMからのJSONパースに失敗しました。', 'fourier')]);
    }

    // 5. データ保存
    $payload = [
        'format' => $target_format,
        'data' => $parsed_json
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
