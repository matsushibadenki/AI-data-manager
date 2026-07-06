<?php
// file:///Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/inc/functions_wiki_dump.php
// functions_wiki_dump.php
// Wikipediaダンプのバックグラウンド処理用AJAXハンドラ

if (!defined('ABSPATH')) exit;

// ワーカー起動
add_action('wp_ajax_start_wiki_dump_process', 'start_wiki_dump_process_handler');
add_action('wp_ajax_nopriv_start_wiki_dump_process', 'start_wiki_dump_process_handler');
function start_wiki_dump_process_handler()
{
    try {
        if (!check_ajax_referer('learning_data_action', 'nonce', false)) {
            wp_send_json_error(['message' => 'Nonce verification failed']);
        }

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        $chunk_size = isset($_POST['chunk_size']) ? intval($_POST['chunk_size']) : 5000;

        if (!$url) {
            wp_send_json_error(['message' => 'URLが指定されていません。']);
        }

        $upload_dir = wp_upload_dir();
        $wiki_dir = $upload_dir['basedir'] . '/wiki_dumps';
        if (!file_exists($wiki_dir)) {
            if (!@wp_mkdir_p($wiki_dir)) {
                wp_send_json_error(['message' => '保存先ディレクトリの作成に失敗しました。パーミッションを確認してください。']);
            }
        }

        $status_file = $wiki_dir . '/wiki_import_status.json';
        $script_path = get_template_directory() . '/process_wiki_dump.py';
        $log_file = $wiki_dir . '/worker.log';

        // 初期ステータス書き込み
        $initial_status = [
            "state" => "starting",
            "progress" => 0,
            "message" => "ワーカーを起動中...",
            "updated_at" => @date('c')
        ];
        if (@file_put_contents($status_file, json_encode($initial_status, JSON_UNESCAPED_UNICODE)) === false) {
            wp_send_json_error(['message' => 'ステータスファイルの書き込みに失敗しました。']);
        }

        // Pythonスクリプトをバックグラウンドで実行
        $cmd = sprintf(
            'nohup python3 %s %s %s %d %s > %s 2>&1 &',
            escapeshellarg($script_path),
            escapeshellarg($url),
            escapeshellarg($wiki_dir),
            $chunk_size,
            escapeshellarg($status_file),
            escapeshellarg($log_file)
        );

        @exec($cmd);

        wp_send_json_success(['message' => '処理を開始しました。', 'cmd' => $cmd]);
    } catch (Exception $e) {
        wp_send_json_error(['message' => '予期せぬエラー: ' . $e->getMessage()]);
    }
}

// 進捗確認
add_action('wp_ajax_check_wiki_dump_status', 'check_wiki_dump_status_handler');
add_action('wp_ajax_nopriv_check_wiki_dump_status', 'check_wiki_dump_status_handler');
function check_wiki_dump_status_handler()
{
    if (!check_ajax_referer('learning_data_action', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }

    $upload_dir = wp_upload_dir();
    $status_file = $upload_dir['basedir'] . '/wiki_dumps/wiki_import_status.json';

    if (file_exists($status_file)) {
        $content = file_get_contents($status_file);
        $data = json_decode($content, true);
        wp_send_json_success($data);
    } else {
        wp_send_json_success(['state' => 'idle', 'message' => '待機中']);
    }
}

// ファイル一覧取得
add_action('wp_ajax_list_wiki_dump_files', 'list_wiki_dump_files_handler');
add_action('wp_ajax_nopriv_list_wiki_dump_files', 'list_wiki_dump_files_handler');
function list_wiki_dump_files_handler()
{
    if (!check_ajax_referer('learning_data_action', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }

    $upload_dir = wp_upload_dir();
    $wiki_dir = $upload_dir['basedir'] . '/wiki_dumps';
    $files_info = [];

    if (file_exists($wiki_dir)) {
        $files = glob($wiki_dir . '/wiki_dataset_part_*.json');
        foreach ($files as $file) {
            $files_info[] = [
                'name' => basename($file),
                'size' => filesize($file),
                'path' => $file,
                'url'  => str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $file)
            ];
        }
    }

    // 名前順でソート（数値ソート）
    usort($files_info, function ($a, $b) {
        preg_match('/_(\d+)\.json$/', $a['name'], $ma);
        preg_match('/_(\d+)\.json$/', $b['name'], $mb);
        $num_a = isset($ma[1]) ? intval($ma[1]) : 0;
        $num_b = isset($mb[1]) ? intval($mb[1]) : 0;
        return $num_a - $num_b;
    });

    wp_send_json_success(['files' => $files_info]);
}
