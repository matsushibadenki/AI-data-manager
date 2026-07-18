<?php
/**
 * ファイル名: functions_commons_dump.php
 * パス: /Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/inc/functions_commons_dump.php
 * 説明: Wikimedia Commonsダンプファイルの非同期処理用AJAXハンドラ。
 */

if (!defined('ABSPATH')) {
    exit;
}

// 処理開始
add_action('wp_ajax_start_commons_dump_process', 'start_commons_dump_process_handler');
add_action('wp_ajax_nopriv_start_commons_dump_process', 'start_commons_dump_process_handler');
function start_commons_dump_process_handler()
{
    try {
        if (!check_ajax_referer('learning_data_action', 'nonce', false)) {
            wp_send_json_error(['message' => 'Nonce verification failed']);
        }

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        $chunk_size = isset($_POST['chunk_size']) ? intval($_POST['chunk_size']) : 10000;

        if (!$url) {
            wp_send_json_error(['message' => 'URLが指定されていません。']);
        }

        $upload_dir = wp_upload_dir();
        $commons_dir = $upload_dir['basedir'] . '/commons_dumps';
        if (!file_exists($commons_dir)) {
            if (!@wp_mkdir_p($commons_dir)) {
                wp_send_json_error(['message' => '保存先ディレクトリの作成に失敗しました。パーミッションを確認してください。']);
            }
        }

        $status_file = $commons_dir . '/commons_import_status.json';
        $script_path = get_template_directory() . '/scripts/process_commons_dump.py';
        $log_file = $commons_dir . '/worker.log';

        // 初期ステータス書き込み
        $initial_status = [
            "state" => "starting",
            "progress" => 0,
            "message" => "Commonsワーカーを起動中...",
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
            escapeshellarg($commons_dir),
            $chunk_size,
            escapeshellarg($status_file),
            escapeshellarg($log_file)
        );

        @exec($cmd);

        wp_send_json_success(['message' => 'Commons処理を開始しました。', 'cmd' => $cmd]);
    } catch (Exception $e) {
        wp_send_json_error(['message' => '予期せぬエラー: ' . $e->getMessage()]);
    }
}

// 進捗確認
add_action('wp_ajax_check_commons_dump_status', 'check_commons_dump_status_handler');
add_action('wp_ajax_nopriv_check_commons_dump_status', 'check_commons_dump_status_handler');
function check_commons_dump_status_handler()
{
    if (!check_ajax_referer('learning_data_action', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }

    $upload_dir = wp_upload_dir();
    $status_file = $upload_dir['basedir'] . '/commons_dumps/commons_import_status.json';

    if (file_exists($status_file)) {
        $json = @file_get_contents($status_file);
        if ($json) {
            $data = json_decode($json, true);
            wp_send_json_success($data);
        }
    }
    wp_send_json_error(['message' => 'ステータスファイルが見つかりません。']);
}

// 生成されたJSONファイル一覧取得
add_action('wp_ajax_list_commons_dump_files', 'list_commons_dump_files_handler');
add_action('wp_ajax_nopriv_list_commons_dump_files', 'list_commons_dump_files_handler');
function list_commons_dump_files_handler()
{
    if (!check_ajax_referer('learning_data_action', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }

    $upload_dir = wp_upload_dir();
    $commons_dir = $upload_dir['basedir'] . '/commons_dumps';
    
    $files = [];
    if (file_exists($commons_dir)) {
        foreach (glob($commons_dir . '/*.json') as $file) {
            if (basename($file) !== 'commons_import_status.json') {
                $files[] = [
                    'name' => basename($file),
                    'path' => $file,
                    'size' => filesize($file)
                ];
            }
        }
    }
    
    wp_send_json_success(['files' => $files]);
}
