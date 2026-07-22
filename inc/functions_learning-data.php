<?php
/*
 * Name: functions_learning-data.php
 * Description: LLM学習用データの編集・削除・インポート・エクスポート・統計等のAJAXハンドラ群。
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Episode / Causal Narrativeの最低限の構造を検証する。
 * 自由記述のCoTではなく、観測可能な事実と因果注釈を分離して保存する。
 */
function fourier_validate_episode_payload($payload) {
    if (!is_array($payload) || empty($payload['data']) || !is_array($payload['data'])) {
        return new WP_Error('invalid_episode', 'Episodeデータのdataがありません。');
    }

    $data = $payload['data'];
    if (($data['data_type'] ?? 'episode') !== 'episode') {
        return new WP_Error('invalid_episode', 'Episodeのdata_typeはepisodeである必要があります。');
    }
    if (empty($data['narrative']) || !is_array($data['narrative'])) {
        return new WP_Error('invalid_episode', 'Episodeにはnarrativeオブジェクトが必要です。');
    }
    if (isset($data['narrative']['events']) && !is_array($data['narrative']['events'])) {
        return new WP_Error('invalid_episode', 'narrative.eventsは配列である必要があります。');
    }

    foreach (['causal_relations', 'agents', 'impact', 'alternatives', 'interpretations'] as $field) {
        if (isset($data[$field]) && !is_array($data[$field])) {
            return new WP_Error('invalid_episode', $field . 'は配列である必要があります。');
        }
    }

    return true;
}

/*--------------------------------------------------------------
  学習データのメタデータを保存する（アップロード/更新/インポート時）
--------------------------------------------------------------*/
add_action('frontend_learning_data_after_save', 'frontend_learning_data_save_meta', 10, 2);
function frontend_learning_data_save_meta($post_id, $post_data)
{
    // JSONからformatを抽出
    if (!empty($post_data['json_data'])) {
        $json_str = wp_unslash($post_data['json_data']);
        $decoded = json_decode($json_str, true);
        if ($decoded && isset($decoded['format'])) {
            update_post_meta($post_id, 'learning_format', sanitize_text_field($decoded['format']));
        }
    }

    // フォームから送信されたメタデータ
    $fields = ['language', 'category', 'difficulty', 'quality', 'source', 'tags', 'speakers'];
    foreach ($fields as $field) {
        if (isset($post_data[$field]) && $post_data[$field] !== '') {
            update_post_meta($post_id, 'learning_' . $field, sanitize_text_field($post_data[$field]));
        } else {
            // 空の場合は削除してクリーンに保つ
            delete_post_meta($post_id, 'learning_' . $field);
        }
    }

    // バージョン管理（簡易）
    $version = get_post_meta($post_id, 'learning_version', true);
    if (!$version) {
        update_post_meta($post_id, 'learning_version', 1);
    } else {
        update_post_meta($post_id, 'learning_version', intval($version) + 1);
    }

    // 文字数（トークン数の簡易推測用）
    $content = get_post_field('post_content', $post_id);
    update_post_meta($post_id, 'learning_char_count', mb_strlen($content));
}

/*--------------------------------------------------------------
  単一データ取得（編集用）
--------------------------------------------------------------*/
add_action('wp_ajax_frontend_learning_data_get_single', 'frontend_learning_data_get_single_handler');
add_action('wp_ajax_nopriv_frontend_learning_data_get_single', 'frontend_learning_data_get_single_handler');
function frontend_learning_data_get_single_handler()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'learning_data_action')) {
        wp_send_json_error(['message' => esc_html__('セッションが無効です。', 'fourier')], 403);
    }

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $post = get_post($post_id);

    if (!$post || get_post_meta($post_id, 'is_learning_data', true) !== '1') {
        wp_send_json_error(['message' => esc_html__('データが見つかりません。', 'fourier')], 404);
    }

    $json_content = json_decode($post->post_content, true);
    $format = isset($json_content['format']) ? $json_content['format'] : 'unknown';
    $data = isset($json_content['data']) ? $json_content['data'] : [];

    $meta = [
        'language'   => get_post_meta($post_id, 'learning_language', true),
        'category'   => get_post_meta($post_id, 'learning_category', true),
        'difficulty' => get_post_meta($post_id, 'learning_difficulty', true),
        'quality'    => get_post_meta($post_id, 'learning_quality', true),
        'source'     => get_post_meta($post_id, 'learning_source', true),
        'tags'       => get_post_meta($post_id, 'learning_tags', true),
        'speakers'   => get_post_meta($post_id, 'learning_speakers', true),
    ];

    wp_send_json_success([
        'post_id' => $post_id,
        'title'   => $post->post_title,
        'format'  => $format,
        'data'    => $data,
        'meta'    => $meta
    ]);
}

/*--------------------------------------------------------------
  データ更新
--------------------------------------------------------------*/
add_action('wp_ajax_frontend_learning_data_update', 'frontend_learning_data_update_handler');
add_action('wp_ajax_nopriv_frontend_learning_data_update', 'frontend_learning_data_update_handler');
function frontend_learning_data_update_handler()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'learning_data_action')) {
        wp_send_json_error(['message' => esc_html__('セッションが無効です。', 'fourier')], 403);
    }

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$post_id || get_post_meta($post_id, 'is_learning_data', true) !== '1') {
        wp_send_json_error(['message' => esc_html__('対象データが存在しません。', 'fourier')], 404);
    }

    $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
    $json_data_str = isset($_POST['json_data']) ? wp_unslash($_POST['json_data']) : '';

    if (empty($title) || empty($json_data_str)) {
        wp_send_json_error(['message' => esc_html__('入力が不足しています。', 'fourier')], 400);
    }

    $decoded_payload = json_decode($json_data_str, true);
    if (isset($decoded_payload['format']) && $decoded_payload['format'] === 'episode') {
        $episode_validation = fourier_validate_episode_payload($decoded_payload);
        if (is_wp_error($episode_validation)) {
            wp_send_json_error(['message' => $episode_validation->get_error_message()], 400);
        }
    }

    if (json_last_error() !== JSON_ERROR_NONE) {
        wp_send_json_error(['message' => esc_html__('無効なJSONです。', 'fourier')], 400);
    }

    $post_data = [
        'ID'           => $post_id,
        'post_title'   => $title,
        'post_content' => wp_slash($json_data_str)
    ];

    wp_update_post($post_data);
    do_action('frontend_learning_data_after_save', $post_id, $_POST);

    wp_send_json_success(['post_id' => $post_id, 'message' => esc_html__('更新しました。', 'fourier')]);
}

/*--------------------------------------------------------------
  データ削除（個別）
--------------------------------------------------------------*/
add_action('wp_ajax_frontend_learning_data_delete', 'frontend_learning_data_delete_handler');
add_action('wp_ajax_nopriv_frontend_learning_data_delete', 'frontend_learning_data_delete_handler');
function frontend_learning_data_delete_handler()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'learning_data_action')) {
        wp_send_json_error(['message' => esc_html__('セッションが無効です。', 'fourier')], 403);
    }

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$post_id || get_post_meta($post_id, 'is_learning_data', true) !== '1') {
        wp_send_json_error(['message' => esc_html__('対象データが存在しません。', 'fourier')], 404);
    }

    wp_delete_post($post_id, true); // true = ゴミ箱をスキップして完全削除
    wp_send_json_success(['message' => esc_html__('削除しました。', 'fourier')]);
}

/*--------------------------------------------------------------
  データ削除（一括）
--------------------------------------------------------------*/
add_action('wp_ajax_frontend_learning_data_bulk_delete', 'frontend_learning_data_bulk_delete_handler');
add_action('wp_ajax_nopriv_frontend_learning_data_bulk_delete', 'frontend_learning_data_bulk_delete_handler');
function frontend_learning_data_bulk_delete_handler()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'learning_data_action')) {
        wp_send_json_error(['message' => esc_html__('セッションが無効です。', 'fourier')], 403);
    }

    $post_ids_str = isset($_POST['post_ids']) ? sanitize_text_field($_POST['post_ids']) : '';
    $post_ids = explode(',', $post_ids_str);
    $deleted_count = 0;

    foreach ($post_ids as $id) {
        $id = intval($id);
        if ($id && get_post_meta($id, 'is_learning_data', true) === '1') {
            wp_delete_post($id, true);
            $deleted_count++;
        }
    }

    wp_send_json_success([
        'count' => $deleted_count,
        'message' => sprintf(esc_html__('%d件のデータを削除しました。', 'fourier'), $deleted_count)
    ]);
}

/*--------------------------------------------------------------
  データ複製
--------------------------------------------------------------*/
add_action('wp_ajax_frontend_learning_data_duplicate', 'frontend_learning_data_duplicate_handler');
add_action('wp_ajax_nopriv_frontend_learning_data_duplicate', 'frontend_learning_data_duplicate_handler');
function frontend_learning_data_duplicate_handler()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'learning_data_action')) {
        wp_send_json_error(['message' => esc_html__('セッションが無効です。', 'fourier')], 403);
    }

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $post = get_post($post_id);

    if (!$post || get_post_meta($post_id, 'is_learning_data', true) !== '1') {
        wp_send_json_error(['message' => esc_html__('対象データが存在しません。', 'fourier')], 404);
    }

    $new_post_data = [
        'post_title'   => $post->post_title . ' ' . esc_html__('(コピー)', 'fourier'),
        'post_content' => wp_slash($post->post_content),
        'post_status'  => 'publish',
        'post_type'    => 'post'
    ];

    $new_post_id = wp_insert_post($new_post_data);
    if (is_wp_error($new_post_id) || $new_post_id == 0) {
        wp_send_json_error(['message' => esc_html__('複製に失敗しました。', 'fourier')], 500);
    }

    // メタデータのコピー
    update_post_meta($new_post_id, 'is_learning_data', '1');
    $meta_keys = ['learning_format', 'learning_language', 'learning_category', 'learning_difficulty', 'learning_quality', 'learning_source', 'learning_tags', 'learning_char_count'];
    foreach ($meta_keys as $key) {
        $val = get_post_meta($post_id, $key, true);
        if ($val !== '') update_post_meta($new_post_id, $key, $val);
    }
    update_post_meta($new_post_id, 'learning_version', 1);

    wp_send_json_success(['post_id' => $new_post_id, 'message' => esc_html__('複製しました。', 'fourier')]);
}

/*--------------------------------------------------------------
  インポート用プレビュー (JSONL, JSON, CSV)
--------------------------------------------------------------*/
add_action('wp_ajax_frontend_learning_data_import_preview', 'frontend_learning_data_import_preview_handler');
add_action('wp_ajax_nopriv_frontend_learning_data_import_preview', 'frontend_learning_data_import_preview_handler');
function frontend_learning_data_import_preview_handler()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'learning_data_action')) {
        wp_send_json_error(['message' => esc_html__('無効なリクエストです。', 'fourier')]);
    }

    if (empty($_FILES['import_file']['tmp_name'])) {
        wp_send_json_error(['message' => esc_html__('ファイルが選択されていません。', 'fourier')]);
    }

    $file = $_FILES['import_file'];
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $content = file_get_contents($file['tmp_name']);

    $force_format = isset($_POST['force_format']) ? sanitize_text_field($_POST['force_format']) : 'auto';

    $parsed_items = [];
    $errors = [];

    if ($ext === 'jsonl') {
        $lines = explode("\n", trim($content));
        foreach ($lines as $index => $line) {
            $line = trim($line);
            if (empty($line)) continue;
            $json = json_decode($line, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = sprintf(esc_html__('行 %d: JSONの解析に失敗しました。', 'fourier'), $index + 1);
                continue;
            }
            $parsed_items[] = _detect_and_format_import_item($json, $force_format);
        }
    } else if ($ext === 'json') {
        $json = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => esc_html__('JSONの解析に失敗しました。', 'fourier')]);
        }

        // もし LLM 出力のような { "draft_thought": "...", "data": [...] } の形式なら "data" 配列を抽出する
        if (is_array($json) && !wp_is_numeric_array($json) && isset($json['data']) && is_array($json['data'])) {
            $json = $json['data'];
        }

        if (wp_is_numeric_array($json)) {
            foreach ($json as $index => $item) {
                $parsed_items[] = _detect_and_format_import_item($item, $force_format);
            }
        } else {
            $parsed_items[] = _detect_and_format_import_item($json, $force_format);
        }
    } else if ($ext === 'csv') {
        $rows = array_map('str_getcsv', explode("\n", trim($content)));
        $header = array_shift($rows);
        foreach ($rows as $index => $row) {
            if (count($header) !== count($row)) continue;
            $item = array_combine($header, $row);
            $parsed_items[] = _detect_and_format_import_item($item, $force_format);
        }
    } else {
        wp_send_json_error(['message' => esc_html__('対応していないファイル形式です。', 'fourier')]);
    }

    $format_counts = [];
    foreach ($parsed_items as $item) {
        $fmt = $item['format'];
        if (!isset($format_counts[$fmt])) $format_counts[$fmt] = 0;
        $format_counts[$fmt]++;
    }

    wp_send_json_success([
        'total_count' => count($parsed_items),
        'format_counts' => $format_counts,
        'preview' => array_slice($parsed_items, 0, 10),
        'errors' => $errors
    ]);
}

/*--------------------------------------------------------------
  インポート実行
--------------------------------------------------------------*/
add_action('wp_ajax_frontend_learning_data_import_execute', 'frontend_learning_data_import_execute_handler');
add_action('wp_ajax_nopriv_frontend_learning_data_import_execute', 'frontend_learning_data_import_execute_handler');
function frontend_learning_data_import_execute_handler()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'learning_data_action')) {
        wp_send_json_error(['message' => esc_html__('無効なリクエストです。', 'fourier')]);
    }

    $items_json = isset($_POST['items']) ? wp_unslash($_POST['items']) : '[]';
    $items = json_decode($items_json, true);
    if (!is_array($items) || empty($items)) {
        wp_send_json_error(['message' => esc_html__('インポートデータがありません。', 'fourier')]);
    }

    $imported = 0;
    foreach ($items as $item) {
        $title = isset($item['title']) && $item['title'] !== '' ? sanitize_text_field($item['title']) : esc_html__('インポートデータ', 'fourier') . ' ' . date('Ymd_His');

        if (($item['format'] ?? '') === 'episode') {
            $episode_validation = fourier_validate_episode_payload(['format' => 'episode', 'data' => $item['data'] ?? []]);
            if (is_wp_error($episode_validation)) {
                continue;
            }
        }

        $post_data = [
            'post_title'   => $title,
            'post_content' => wp_slash(json_encode([
                'format' => $item['format'],
                'data'   => $item['data']
            ], JSON_UNESCAPED_UNICODE)),
            'post_status'  => 'publish',
            'post_type'    => 'post'
        ];

        $post_id = wp_insert_post($post_data);
        if (!is_wp_error($post_id) && $post_id > 0) {
            update_post_meta($post_id, 'is_learning_data', '1');

            if (!empty($item['source_url'])) {
                update_post_meta($post_id, 'learning_data_source', sanitize_text_field($item['source_url']));
            }
            if (!empty($item['imported_at'])) {
                update_post_meta($post_id, 'learning_data_imported_at', sanitize_text_field($item['imported_at']));
            } else {
                update_post_meta($post_id, 'learning_data_imported_at', current_time('mysql'));
            }

            // 画像のインポート処理 (Base64 または URL)
            if (isset($item['data']['image_base64']) || isset($item['data']['image_url'])) {
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/media.php');

                $upload_dir = wp_upload_dir();
                $filename = isset($item['data']['image_filename']) && $item['data']['image_filename'] !== '' ? sanitize_file_name($item['data']['image_filename']) : 'imported_image_' . md5(uniqid()) . '.jpg';
                // 拡張子がない場合は適当に付与
                if (pathinfo($filename, PATHINFO_EXTENSION) === '') {
                    $filename .= '.jpg';
                }
                $filepath = $upload_dir['path'] . '/' . $filename;
                
                $image_saved = false;
                if (isset($item['data']['image_base64'])) {
                    $image_data = base64_decode($item['data']['image_base64']);
                    if ($image_data !== false) {
                        $image_saved = (file_put_contents($filepath, $image_data) !== false);
                    }
                } else if (isset($item['data']['image_url'])) {
                    $response = wp_remote_get($item['data']['image_url'], ['timeout' => 30]);
                    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                        $image_data = wp_remote_retrieve_body($response);
                        $image_saved = (file_put_contents($filepath, $image_data) !== false);
                    }
                }

                if ($image_saved) {
                    $filetype = wp_check_filetype($filename, null);
                    $attachment = [
                        'post_mime_type' => $filetype['type'],
                        'post_title'     => sanitize_file_name($filename),
                        'post_content'   => '',
                        'post_status'    => 'inherit',
                        'post_parent'    => $post_id
                    ];
                    $attach_id = wp_insert_attachment($attachment, $filepath, $post_id);
                    if (!is_wp_error($attach_id)) {
                        add_filter('big_image_size_threshold', '__return_false');
                        $attach_data = wp_generate_attachment_metadata($attach_id, $filepath);
                        wp_update_attachment_metadata($attach_id, $attach_data);
                        remove_filter('big_image_size_threshold', '__return_false');

                        set_post_thumbnail($post_id, $attach_id);
                    }
                }
            }

            // 簡易POST配列を作ってフック実行
            $mock_post = [
                'json_data' => wp_slash(json_encode(['format' => $item['format']])),
                'language' => isset($_POST['default_language']) ? sanitize_text_field($_POST['default_language']) : '',
                'category' => isset($_POST['default_category']) ? sanitize_text_field($_POST['default_category']) : '',
            ];
            do_action('frontend_learning_data_after_save', $post_id, $mock_post);
            $imported++;
        }
    }

    wp_send_json_success([
        'imported_count' => $imported,
        'message' => sprintf(esc_html__('%d件のデータをインポートしました。', 'fourier'), $imported)
    ]);
}

// ヘルパー: フォーマットの自動推測
function _detect_and_format_import_item($raw, $force_format = 'auto')
{
    $format = 'structured';
    $data = $raw;

    // ネストされたdata配列がある場合のチェック用
    $check_target = isset($raw['data']) && is_array($raw['data']) ? $raw['data'] : $raw;

    if ($force_format !== 'auto') {
        $format = $force_format;
    } else if (isset($check_target['instruction']) && isset($check_target['output'])) {
        $format = 'instruction';
    } else if (isset($check_target['messages']) && is_array($check_target['messages'])) {
        $format = 'chatml';
    } else if (isset($check_target['conversations']) && is_array($check_target['conversations'])) {
        $format = 'sharegpt';
    } else if (isset($check_target['question']) && isset($check_target['thought']) && isset($check_target['answer'])) {
        $format = 'cot';
    } else if (isset($check_target['prompt']) && isset($check_target['chosen']) && isset($check_target['rejected'])) {
        $format = 'dpo';
    } else if (isset($check_target['html']) || isset($check_target['css']) || isset($check_target['js'])) {
        $format = 'frontend_code';
    } else if (($check_target['data_type'] ?? '') === 'episode' || (isset($check_target['narrative']) && isset($check_target['causal_relations']))) {
        $format = 'episode';
    } else if (isset($check_target['text'])) {
        $keys = array_keys($check_target);
        $allowed = ['text', 'image_base64', 'image_url', 'image_filename'];
        $diff = array_diff($keys, $allowed);
        if (empty($diff)) {
            $format = 'plain';
        }
    } else if (is_array($check_target) && wp_is_numeric_array($check_target) && !empty($check_target)) {
        // 配列（リスト）パターンのフォールバック
        $first = $check_target[0];
        if (isset($first['instruction']) && isset($first['output'])) {
            $format = 'instruction';
        } else if (isset($first['role'])) {
            $format = 'chatml';
        } else if (isset($first['from'])) {
            $format = 'sharegpt';
        } else if (isset($first['question']) && isset($first['thought']) && isset($first['answer'])) {
            $format = 'cot';
        } else if (isset($first['prompt']) && isset($first['chosen']) && isset($first['rejected'])) {
            $format = 'dpo';
        }
    }

    return [
        'title'  => isset($raw['title']) ? $raw['title'] : '',
        'format' => $format,
        'data'   => $check_target
    ];
}

/*--------------------------------------------------------------
  エクスポート
--------------------------------------------------------------*/
function fourier_format_learning_data($item, $output_style)
{
    if ($output_style === 'sara') {
        return [
            'event_uid' => 'ld_' . md5($item['title'] . wp_generate_password(8, false)),
            'event_type' => 'learning_data',
            'proposal_source' => 'import',
            'verification_state' => 'verified',
            'evidence_type' => 'verified_fact',
            'content' => json_encode($item['data'], JSON_UNESCAPED_UNICODE),
            'tags' => ['learning_data', 'sara', $item['format']]
        ];
    } elseif ($output_style === 'transformer') {
        $text = '';
        $data = $item['data'];
        if (is_array($data)) {
            if ($item['format'] === 'instruction' && isset($data['instruction'])) {
                $text = "### 指示:\n" . $data['instruction'] . "\n\n";
                if (!empty($data['input'])) {
                    $text .= "### 入力:\n" . $data['input'] . "\n\n";
                }
                if (isset($data['output'])) {
                    $text .= "### 応答:\n" . $data['output'];
                }
            } elseif ($item['format'] === 'chatml' && isset($data['messages'])) {
                foreach ($data['messages'] as $msg) {
                    $text .= "<|im_start|>" . ($msg['role'] ?? '') . "\n" . ($msg['content'] ?? '') . "<|im_end|>\n";
                }
            } elseif ($item['format'] === 'sharegpt' && isset($data['conversations'])) {
                foreach ($data['conversations'] as $msg) {
                    $text .= strtoupper($msg['from'] ?? '') . ": " . ($msg['value'] ?? '') . "\n";
                }
            } elseif ($item['format'] === 'dpo' && isset($data['prompt'])) {
                $text = "### Prompt:\n" . $data['prompt'] . "\n\n### Chosen:\n" . ($data['chosen'] ?? '') . "\n\n### Rejected:\n" . ($data['rejected'] ?? '');
            } elseif ($item['format'] === 'cot' && isset($data['question'])) {
                $text = "### Question:\n" . $data['question'] . "\n\n### Thought:\n" . ($data['thought'] ?? '') . "\n\n### Answer:\n" . ($data['answer'] ?? '');
            } elseif ($item['format'] === 'episode') {
                $text = $data['narrative_text'] ?? '';
                if (!$text && isset($data['narrative']) && is_array($data['narrative'])) {
                    $text = implode("\n", array_filter([
                        $data['narrative']['setting'] ?? '',
                        $data['narrative']['initial_state'] ?? '',
                        $data['narrative']['goal'] ?? '',
                        $data['narrative']['outcome'] ?? '',
                        $data['narrative']['long_term_outcome'] ?? ''
                    ]));
                }
            } else {
                $text = json_encode($data, JSON_UNESCAPED_UNICODE);
            }
        } else {
            $text = $data;
        }
        return ['text' => trim($text)];
    }

    // raw (default)
    $data = is_array($item['data']) ? $item['data'] : ['text' => $item['data']];
    $is_list = false;
    if (!empty($data)) {
        $is_list = true;
        $i = 0;
        foreach ($data as $k => $v) {
            if ($k !== $i++) {
                $is_list = false;
                break;
            }
        }
    }

    if ($is_list) {
        return ['title' => $item['title'], 'data' => $data];
    }
    return array_merge(['title' => $item['title']], $data);
}

add_action('wp_ajax_frontend_learning_data_export', 'frontend_learning_data_export_handler');
add_action('wp_ajax_nopriv_frontend_learning_data_export', 'frontend_learning_data_export_handler');
function frontend_learning_data_export_handler()
{
    // 通常のファイルダウンロードとして処理
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'learning_data_action')) {
        wp_die(esc_html__('無効なリクエストです。', 'fourier'));
    }

    $export_format = isset($_POST['export_format']) ? sanitize_text_field($_POST['export_format']) : 'jsonl';

    $target_formats = [];
    if (isset($_POST['formats'])) {
        $posted_formats = wp_unslash($_POST['formats']);
        if (is_array($posted_formats)) {
            $target_formats = array_map('sanitize_text_field', $posted_formats);
        } else {
            $target_formats = explode(',', sanitize_text_field($posted_formats));
        }
    }

    $args = [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => [
            ['key' => 'is_learning_data', 'value' => '1']
        ]
    ];

    $query = new WP_Query($args);
    $export_data = [];

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $content = json_decode(get_the_content(), true);
            if (!$content) continue;

            $post_format = isset($content['format']) ? $content['format'] : 'structured';

            // フィルタリング: target_formatsがallでない場合、JSON内のformatが一致するかチェック
            if (!empty($target_formats) && !in_array('all', $target_formats)) {
                if (!in_array($post_format, $target_formats)) {
                    continue;
                }
            }

            $item = [
                'title' => get_the_title(),
                'format' => $post_format,
                'data' => isset($content['data']) ? $content['data'] : []
            ];

            $source_url = get_post_meta(get_the_ID(), 'learning_data_source', true);
            if ($source_url) {
                $item['source_url'] = $source_url;
            }
            $imported_at = get_post_meta(get_the_ID(), 'learning_data_imported_at', true);
            if (!$imported_at) {
                // フォールバック: 投稿の作成日を使用
                $imported_at = get_the_date('Y-m-d\TH:i:sP');
            }
            $item['imported_at'] = $imported_at;

            // アイキャッチ画像（添付画像）があればBase64エンコードして含める
            $thumbnail_id = get_post_thumbnail_id(get_the_ID());
            if ($thumbnail_id) {
                $image_path = get_attached_file($thumbnail_id);
                if ($image_path && file_exists($image_path)) {
                    $image_data = file_get_contents($image_path);
                    $base64 = base64_encode($image_data);
                    $filename = wp_basename($image_path);
                    if (!is_array($item['data'])) {
                        $item['data'] = ['text' => $item['data']];
                    }
                    $item['data']['image_base64'] = $base64;
                    $item['data']['image_filename'] = $filename;
                }
            }

            $output_style = isset($_REQUEST['output_style']) ? sanitize_text_field($_REQUEST['output_style']) : 'raw';

            if ($export_format === 'csv') {
                $flat = ['title' => $item['title'], 'format' => $item['format']];
                if (is_array($item['data'])) {
                    foreach ($item['data'] as $k => $v) {
                        if (is_string($v) || is_numeric($v)) $flat[$k] = $v;
                    }
                }
                $export_data[] = $flat;
            } else {
                $formatted_item = fourier_format_learning_data($item, $output_style);
                $export_data[] = $formatted_item;
            }
        }
    }
    wp_reset_postdata();

    $filename = 'learning_data_export_' . date('Ymd_His');

    if ($export_format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.json"');
        echo json_encode($export_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    } else if ($export_format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $output = fopen('php://output', 'w');
        // UTF-8 BOM for Excel
        fwrite($output, "\xEF\xBB\xBF");

        // ヘッダー抽出
        $headers = [];
        foreach ($export_data as $row) {
            foreach (array_keys($row) as $key) {
                if (!in_array($key, $headers)) $headers[] = $key;
            }
        }
        fputcsv($output, $headers);

        foreach ($export_data as $row) {
            $csv_row = [];
            foreach ($headers as $h) {
                $csv_row[] = isset($row[$h]) ? $row[$h] : '';
            }
            fputcsv($output, $csv_row);
        }
        fclose($output);
        exit;
    } else {
        // default: JSONL
        header('Content-Type: application/jsonlines; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.jsonl"');
        foreach ($export_data as $row) {
            echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
        }
        exit;
    }
}

/*--------------------------------------------------------------
  ダッシュボード用統計データ
--------------------------------------------------------------*/
add_action('wp_ajax_frontend_learning_data_statistics', 'frontend_learning_data_statistics_handler');
add_action('wp_ajax_nopriv_frontend_learning_data_statistics', 'frontend_learning_data_statistics_handler');
function frontend_learning_data_statistics_handler()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'learning_data_action')) {
        wp_send_json_error(['message' => esc_html__('無効なリクエストです。', 'fourier')]);
    }

    global $wpdb;

    // 全データ件数とフォーマット別集計
    $total_count = 0;
    $format_counts = [
        'plain' => 0,
        'instruction' => 0,
        'chatml' => 0,
        'sharegpt' => 0,
        'cot' => 0,
        'dpo' => 0,
        'frontend_code' => 0,
        'structured' => 0,
        'episode' => 0
    ];
    $total_chars = 0;

    $args = [
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'meta_query' => [['key' => 'is_learning_data', 'value' => '1']]
    ];
    $query = new WP_Query($args);

    if ($query->have_posts()) {
        $total_count = $query->found_posts;
        while ($query->have_posts()) {
            $query->the_post();
            $id = get_the_ID();
            $fmt = get_post_meta($id, 'learning_format', true);
            if ($fmt && isset($format_counts[$fmt])) {
                $format_counts[$fmt]++;
            } else {
                $format_counts['structured']++; // default fallback
            }

            $chars = get_post_meta($id, 'learning_char_count', true);
            if ($chars) {
                $total_chars += intval($chars);
            } else {
                $total_chars += mb_strlen(get_the_content());
            }
        }
    }

    // 直近30日の登録推移
    $daily_counts = [];
    $thirty_days_ago = date('Y-m-d', strtotime('-30 days'));
    $sql = "SELECT DATE(post_date) as date, COUNT(*) as count 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'post' AND p.post_status = 'publish' 
            AND pm.meta_key = 'is_learning_data' AND pm.meta_value = '1'
            AND p.post_date >= %s
            GROUP BY DATE(post_date) ORDER BY date ASC";
    $results = $wpdb->get_results($wpdb->prepare($sql, $thirty_days_ago));

    // 0埋め
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $daily_counts[$d] = 0;
    }
    foreach ($results as $row) {
        $daily_counts[$row->date] = intval($row->count);
    }

    // 最新10件
    $recent = [];
    $query_recent = new WP_Query([
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => 10,
        'orderby' => 'date',
        'order' => 'DESC',
        'meta_query' => [['key' => 'is_learning_data', 'value' => '1']]
    ]);
    if ($query_recent->have_posts()) {
        while ($query_recent->have_posts()) {
            $query_recent->the_post();
            $recent[] = [
                'ID' => get_the_ID(),
                'title' => get_the_title(),
                'format' => get_post_meta(get_the_ID(), 'learning_format', true) ?: 'unknown',
                'date' => get_the_date('Y/m/d H:i')
            ];
        }
    }

    wp_send_json_success([
        'total_count' => $total_count,
        'format_counts' => $format_counts,
        'total_chars' => $total_chars,
        'estimated_tokens' => floor($total_chars / 3),
        'daily_counts' => $daily_counts,
        'recent' => $recent
    ]);
}
