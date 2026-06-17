<?php
/*
 * Name: functions_learning-data.php
 * Description: LLM学習用データの編集・削除・インポート・エクスポート・統計等のAJAXハンドラ群。
 */

if (!defined('ABSPATH')) {
    exit;
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
    $fields = ['language', 'category', 'difficulty', 'quality', 'source', 'tags'];
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
            $parsed_items[] = _detect_and_format_import_item($json);
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
                $parsed_items[] = _detect_and_format_import_item($item);
            }
        } else {
            $parsed_items[] = _detect_and_format_import_item($json);
        }
    } else if ($ext === 'csv') {
        $rows = array_map('str_getcsv', explode("\n", trim($content)));
        $header = array_shift($rows);
        foreach ($rows as $index => $row) {
            if (count($header) !== count($row)) continue;
            $item = array_combine($header, $row);
            $parsed_items[] = _detect_and_format_import_item($item);
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
function _detect_and_format_import_item($raw)
{
    $format = 'structured';
    $data = $raw;

    if (isset($raw['instruction']) && isset($raw['output'])) {
        $format = 'instruction';
    } else if (isset($raw['messages']) && is_array($raw['messages'])) {
        $format = 'chatml';
    } else if (isset($raw['conversations']) && is_array($raw['conversations'])) {
        $format = 'sharegpt';
    } else if (isset($raw['question']) && isset($raw['thought']) && isset($raw['answer'])) {
        $format = 'cot';
    } else if (isset($raw['prompt']) && isset($raw['chosen']) && isset($raw['rejected'])) {
        $format = 'dpo';
    } else if (isset($raw['html']) || isset($raw['css']) || isset($raw['js'])) {
        $format = 'frontend_code';
    } else if (isset($raw['text']) && count($raw) === 1) {
        $format = 'plain';
    }

    return [
        'title'  => isset($raw['title']) ? $raw['title'] : '',
        'format' => $format,
        'data'   => $data
    ];
}

/*--------------------------------------------------------------
  エクスポート
--------------------------------------------------------------*/
add_action('wp_ajax_frontend_learning_data_export', 'frontend_learning_data_export_handler');
add_action('wp_ajax_nopriv_frontend_learning_data_export', 'frontend_learning_data_export_handler');
function frontend_learning_data_export_handler()
{
    // 通常のファイルダウンロードとして処理
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'learning_data_action')) {
        wp_die(esc_html__('無効なリクエストです。', 'fourier'));
    }

    $export_format = isset($_POST['export_format']) ? sanitize_text_field($_POST['export_format']) : 'jsonl';
    $target_formats = isset($_POST['formats']) ? explode(',', sanitize_text_field($_POST['formats'])) : [];
    
    $args = [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => [
            ['key' => 'is_learning_data', 'value' => '1']
        ]
    ];

    if (!empty($target_formats) && !in_array('all', $target_formats)) {
        $args['meta_query'][] = [
            'key' => 'learning_format',
            'value' => $target_formats,
            'compare' => 'IN'
        ];
    }

    $query = new WP_Query($args);
    $export_data = [];

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $content = json_decode(get_the_content(), true);
            if (!$content) continue;

            $item = [
                'title' => get_the_title(),
                'format' => isset($content['format']) ? $content['format'] : '',
                'data' => isset($content['data']) ? $content['data'] : []
            ];

            if ($export_format === 'csv') {
                $flat = ['title' => $item['title'], 'format' => $item['format']];
                if (is_array($item['data'])) {
                    foreach ($item['data'] as $k => $v) {
                        if (is_string($v) || is_numeric($v)) $flat[$k] = $v;
                    }
                }
                $export_data[] = $flat;
            } else {
                // jsonl, json の場合は data の中身をそのまま（または平坦化して）使用
                // 一般的なLLMデータセットは title 等を含まないことが多いが、一旦ラップする
                $merged = array_merge(['title' => $item['title']], is_array($item['data']) ? $item['data'] : ['text' => $item['data']]);
                $export_data[] = $merged;
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
        'plain' => 0, 'instruction' => 0, 'chatml' => 0,
        'sharegpt' => 0, 'cot' => 0, 'dpo' => 0, 'frontend_code' => 0, 'structured' => 0
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
        'post_type' => 'post', 'post_status' => 'publish',
        'posts_per_page' => 10, 'orderby' => 'date', 'order' => 'DESC',
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
