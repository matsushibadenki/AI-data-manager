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
function is_headless_browser_available()
{
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
function check_browserless_status_handler()
{
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
function start_web_scrape_handler()
{
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
        let cssContent = '';
        let jsContent = '';

        page.on('response', async (response) => {
            try {
                const type = response.request().resourceType();
                const url = response.url();
                if (url.startsWith('data:')) return;
                
                if (type === 'stylesheet') {
                    const text = await response.text();
                    cssContent += '/* Source: ' + url + ' */\\n' + text + '\\n';
                } else if (type === 'script') {
                    const text = await response.text();
                    jsContent += '/* Source: ' + url + ' */\\n' + text + '\\n';
                }
            } catch(e) {
                // Ignore errors like body not found
            }
        });

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
        
        // 横スクロールバーを隠す
        await page.addStyleTag({ content: 'html, body { overflow-x: hidden !important; }' });
        
        // ページ全体の高さを取得（最低でも1080pxを保証）
        const docHeight = await page.evaluate(() => {
            return Math.max(
                document.body.scrollHeight, document.documentElement.scrollHeight,
                document.body.offsetHeight, document.documentElement.offsetHeight,
                document.body.clientHeight, document.documentElement.clientHeight,
                1080
            );
        });
        
        // 領域を 1920 x 全体の高さ に固定してスクリーンショットを撮影する
        const screenshot = await page.screenshot({
            clip: { x: 0, y: 0, width: 1920, height: docHeight },
            encoding: 'base64'
        });
        
        const inlineData = await page.evaluate(() => {
            let inlineCss = '';
            let inlineJs = '';
            document.querySelectorAll('style').forEach(s => {
                inlineCss += '/* Inline Style */\\n' + s.innerHTML + '\\n';
            });
            document.querySelectorAll('script:not([src])').forEach(s => {
                if (!s.type || s.type === 'text/javascript' || s.type === 'module' || s.type === 'application/javascript') {
                    inlineJs += '/* Inline Script */\\n' + s.innerHTML + '\\n';
                }
            });
            return { css: inlineCss, js: inlineJs };
        });

        cssContent += inlineData.css;
        jsContent += inlineData.js;
        
        return { 
            data: { html, screenshot, css: cssContent, js: jsContent },
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

    // 縦に長いスクリーンショットがWordPressの自動リサイズ機能（上限2560px）によって
    // 大幅に縮小され、横幅が極端に小さくなってしまうのを防ぐ
    add_filter('big_image_size_threshold', '__return_false');
    $attach_data = wp_generate_attachment_metadata($attach_id, $filepath);
    wp_update_attachment_metadata($attach_id, $attach_data);
    remove_filter('big_image_size_threshold', '__return_false');

    // 学習データとして登録
    $post_title = 'Scraped: ' . parse_url($url, PHP_URL_HOST);

    $payload = [
        'format' => 'frontend_code',
        'data' => [
            'html' => $data['html'],
            'css' => isset($data['css']) ? $data['css'] : '',
            'js' => isset($data['js']) ? $data['js'] : ''
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
