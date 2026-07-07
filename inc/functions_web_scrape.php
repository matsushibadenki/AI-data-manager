<?php
/**
 * ファイル名: functions_web_scrape.php
 * パス: /Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/inc/functions_web_scrape.php
 * 説明: Webページをスクレイピングして画像とHTMLを取得し、学習データとして登録する機能
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Headlessブラウザ（browserless等）が利用可能かチェックする
 * @return bool
 */
function is_headless_browser_available() {
    $browserless_url = 'http://browserless:3000/';
    $response = wp_remote_get($browserless_url, ['timeout' => 3]);
    
    if (is_wp_error($response)) {
        return false;
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    // browserlessのルートは200 OKまたは404を返す可能性があるが、接続できればOKとする
    return ($status_code >= 200 && $status_code < 500);
}

add_action('wp_ajax_check_browserless_status', 'check_browserless_status_handler');
function check_browserless_status_handler() {
    if (!check_ajax_referer('learning_data_action', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }

    if (is_headless_browser_available()) {
        wp_send_json_success(['available' => true]);
    } else {
        wp_send_json_success(['available' => false]);
    }
}

add_action('wp_ajax_start_web_scrape', 'start_web_scrape_handler');
function start_web_scrape_handler() {
    if (!check_ajax_referer('learning_data_action', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }

    if (!is_headless_browser_available()) {
        wp_send_json_error(['message' => 'Headlessブラウザが検出されないため、この機能は利用できません。']);
    }

    $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
    if (!$url) {
        wp_send_json_error(['message' => 'URLが入力されていません。']);
    }

    // Browserlessの /function APIを利用してリモートでPuppeteerを実行
    $browserless_function_url = 'http://browserless:3000/function';
    
    $js_code = "
    module.exports = async ({ page, context }) => {
        // 横幅1920pxに設定（縦はなりゆきだが、とりあえず高めに設定してフルページキャプチャする）
        await page.setViewport({ width: 1920, height: 1080 });
        
        // ネットワークアイドル状態まで待機（レンダリング完了を待つ）
        await page.goto(context.url, { waitUntil: 'networkidle2', timeout: 30000 });
        
        // 少しスクロールして遅延読み込みをトリガー（簡易的）
        await page.evaluate(async () => {
            await new Promise((resolve) => {
                let totalHeight = 0;
                let distance = 100;
                let timer = setInterval(() => {
                    let scrollHeight = document.body.scrollHeight;
                    window.scrollBy(0, distance);
                    totalHeight += distance;
                    if(totalHeight >= scrollHeight - window.innerHeight){
                        clearInterval(timer);
                        resolve();
                    }
                }, 100);
            });
        });
        
        const html = await page.content();
        const screenshot = await page.screenshot({ fullPage: true, encoding: 'base64' });
        
        return { 
            data: { html, screenshot },
            type: 'application/json'
        };
    };
    ";

    $response = wp_remote_post($browserless_function_url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode([
            'code' => $js_code,
            'context' => ['url' => $url]
        ]),
        'timeout' => 60 // スクレイピングは時間がかかるためタイムアウトを長めに設定
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'Browserlessとの通信エラー: ' . $response->get_error_message()]);
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    
    if ($status_code !== 200) {
        wp_send_json_error(['message' => 'Browserlessエラー (' . $status_code . '): ' . $body]);
    }

    $data = json_decode($body, true);
    if (!$data || !isset($data['html']) || !isset($data['screenshot'])) {
        wp_send_json_error(['message' => '不正なデータが返却されました。']);
    }

    // 画像の保存とメディアライブラリ登録
    $upload_dir = wp_upload_dir();
    $scrape_dir = $upload_dir['path'];
    $filename = 'scraped_' . md5($url . time()) . '.png';
    $filepath = $scrape_dir . '/' . $filename;
    $file_url = $upload_dir['url'] . '/' . $filename;

    $image_data = base64_decode($data['screenshot']);
    if (file_put_contents($filepath, $image_data) === false) {
        wp_send_json_error(['message' => '画像の保存に失敗しました。']);
    }

    $filetype = wp_check_filetype($filename, null);
    $attachment = [
        'post_mime_type' => $filetype['type'],
        'post_title'     => sanitize_file_name($filename),
        'post_content'   => wp_json_encode(['source' => $url]),
        'post_status'    => 'inherit'
    ];
    $attach_id = wp_insert_attachment($attachment, $filepath);
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attach_data = wp_generate_attachment_metadata($attach_id, $filepath);
    wp_update_attachment_metadata($attach_id, $attach_data);

    // 学習データとして登録
    $post_title = 'Scraped: ' . parse_url($url, PHP_URL_HOST);
    
    $payload = [
        'format' => 'frontend_code',
        'data' => [
            'html' => $data['html'],
            'css' => '/* 取得したページのフルサイズ画像URL: ' . $file_url . ' */',
            'js' => '// URL: ' . $url
        ]
    ];

    $post_data = [
        'post_title' => sanitize_text_field($post_title),
        'post_content' => wp_slash(wp_json_encode($payload, JSON_UNESCAPED_UNICODE)),
        'post_status' => 'publish',
        'post_type' => 'post'
    ];

    $post_id = wp_insert_post($post_data);
    if (is_wp_error($post_id)) {
        wp_send_json_error(['message' => 'データ登録に失敗しました。']);
    }

    // フォーマットなどをメタに保存
    update_post_meta($post_id, 'is_learning_data', '1');
    update_post_meta($post_id, 'data_format', 'frontend_code');
    update_post_meta($post_id, 'learning_data_source', $url);

    wp_send_json_success([
        'message' => 'スクレイピングが完了し、データが登録されました。',
        'post_id' => $post_id,
        'image_url' => $file_url
    ]);
}
