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

    $delay_time = isset($_POST['delay_time']) ? (int) $_POST['delay_time'] : 0;
    if ($delay_time < 0) $delay_time = 0;
    if ($delay_time > 120) $delay_time = 120; // 最大120秒に制限

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

        // 横幅1920pxに設定
        await page.setViewport({ width: 1920, height: 1080 });
        
        // ユーザー指定の遅延時間を考慮したタイムアウト設定
        const delayMs = (context.delay_time || 0) * 1000;
        const gotoTimeout = Math.max(30000, 30000 + delayMs);
        
        // ネットワークアイドル状態まで待機（レンダリング完了を待つ）
        try {
            // networkidle0だと無限ローディングするスクリプトがある場合にタイムアウトするため networkidle2 に緩和
            await page.goto(context.url, { waitUntil: 'networkidle2', timeout: gotoTimeout });
        } catch (e) {
            // タイムアウトしても大部分は読み込み完了しているため処理を続行する
        }
        
        // 初期ローディングが終わるまで少し待機（ローディング画面対策）
        await new Promise(r => setTimeout(r, 3000));
        
        // ユーザー指定の遅延時間がある場合はさらに待機
        if (delayMs > 0) {
            await new Promise(r => setTimeout(r, delayMs));
        }

        // スクロールロックの解除とローディング画面の強制非表示
        await page.evaluate(() => {
            document.body.style.overflow = 'auto';
            document.documentElement.style.overflow = 'auto';
            
            // 画面全体を覆うfixed要素(ローダー等)を非表示にする
            const elements = document.querySelectorAll('div, section');
            elements.forEach(el => {
                const style = window.getComputedStyle(el);
                if (style.position === 'fixed' && style.zIndex >= 900) {
                    const w = parseInt(style.width, 10) || 0;
                    const h = parseInt(style.height, 10) || 0;
                    if ((style.width === '100vw' || w >= window.innerWidth * 0.9) && 
                        (style.height === '100vh' || h >= window.innerHeight * 0.9)) {
                        el.style.display = 'none';
                    }
                }
            });
        });

        // iframeの遅延読み込みを解除
        await page.evaluate(() => {
            document.querySelectorAll('iframe[loading=\"lazy\"]').forEach(iframe => {
                iframe.loading = 'eager';
            });
        });
        
        // GPU診断情報
        const graphicsDiagnostics = await page.evaluate(() => {
            const canvas = document.createElement('canvas');
            const gl = canvas.getContext('webgl2') || canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
            let webgl = null;
            if (gl) {
                const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
                webgl = {
                    version: gl.getParameter(gl.VERSION),
                    vendor: debugInfo ? gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL) : gl.getParameter(gl.VENDOR),
                    renderer: debugInfo ? gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL) : gl.getParameter(gl.RENDERER),
                    maxTextureSize: gl.getParameter(gl.MAX_TEXTURE_SIZE),
                    maxRenderbufferSize: gl.getParameter(gl.MAX_RENDERBUFFER_SIZE)
                };
            }
            const testVideo = document.createElement('video');
            return {
                webgl,
                webgpu: Boolean(navigator.gpu),
                codecs: {
                    h264: testVideo.canPlayType('video/mp4; codecs=\"avc1.42E01E\"'),
                    hevc: testVideo.canPlayType('video/mp4; codecs=\"hvc1\"'),
                    vp9: testVideo.canPlayType('video/webm; codecs=\"vp9\"'),
                    av1: testVideo.canPlayType('video/mp4; codecs=\"av01.0.05M.08\"')
                },
                userAgent: navigator.userAgent
            };
        });

        const docHeight = await page.evaluate(() => {
            const heights = [
                document.documentElement ? document.documentElement.scrollHeight : 0,
                document.documentElement ? document.documentElement.offsetHeight : 0,
                document.body ? document.body.scrollHeight : 0,
                document.body ? document.body.offsetHeight : 0,
                window.innerHeight,
                1080
            ];

            document.querySelectorAll('*').forEach(el => {
                if (el.scrollHeight > el.clientHeight + 20) {
                    heights.push(el.scrollHeight);
                }
            });

            return Math.max(...heights.filter(height => Number.isFinite(height) && height > 0));
        });

        await page.evaluate(() => {
            window.__getScrollableElements = () => {
                const elements = [
                    document.scrollingElement,
                    document.documentElement,
                    document.body,
                    ...document.querySelectorAll('*')
                ].filter(Boolean);

                return [...new Set(elements)].filter(el => {
                    const style = window.getComputedStyle(el);
                    const overflow = style.overflow + style.overflowY;
                    return el.scrollHeight > el.clientHeight + 20 && /(auto|scroll|overlay|hidden)/.test(overflow);
                });
            };
        });

        const viewportWidth = 1920;
        const viewportHeight = 1080;

        await page.setViewport({
            width: viewportWidth,
            height: viewportHeight,
            deviceScaleFactor: 1
        });

        const captures = [];

        // 2枚目以降のタイルで固定ヘッダー等が重複して映り込むのを防ぐための関数を定義
        await page.evaluate(() => {
            window.__hideFixedElements = (hide) => {
                if (!window.__fixedElements) {
                    window.__fixedElements = [];
                    const elements = document.querySelectorAll('*');
                    for (const el of elements) {
                        const style = window.getComputedStyle(el);
                        if (style.position === 'fixed' || style.position === 'sticky') {
                            window.__fixedElements.push({
                                el: el,
                                originalVisibility: el.style.visibility
                            });
                        }
                    }
                }
                for (const item of window.__fixedElements) {
                    // opacity:0 だとクリック判定が残るなどの影響があるが撮影用途なら visibility:hidden が最適
                    item.el.style.visibility = hide ? 'hidden' : item.originalVisibility;
                }
            };
        });

        const maxScrollY = Math.max(0, docHeight - viewportHeight);
        const scrollPositions = [];
        for (let y = 0; y < docHeight; y += viewportHeight) {
            scrollPositions.push(Math.min(y, maxScrollY));
        }
        if (!scrollPositions.includes(maxScrollY)) {
            scrollPositions.push(maxScrollY);
        }

        for (const targetY of [...new Set(scrollPositions)]) {
            await page.evaluate((scrollY) => {
                window.scrollTo(0, scrollY);
                if (window.__getScrollableElements) {
                    window.__getScrollableElements().forEach(el => {
                        el.scrollTop = Math.min(scrollY, el.scrollHeight - el.clientHeight);
                    });
                }
                if (window.__hideFixedElements) {
                    // y > 0 なら固定要素を隠し、y === 0（トップ）なら表示状態に戻す
                    window.__hideFixedElements(scrollY > 0);
                }
            }, targetY);

            // スクロールによるlazy-load、Canvas再描画、動画フレーム更新を待つ
            await new Promise(resolve => setTimeout(resolve, 700));

            // 現在表示されている動画のフレーム生成を待つ
            await page.evaluate(async () => {
                const visibleVideos = [...document.querySelectorAll('video')]
                    .filter(video => {
                        const rect = video.getBoundingClientRect();
                        return (
                            rect.bottom > 0 &&
                            rect.top < window.innerHeight &&
                            rect.right > 0 &&
                            rect.left < window.innerWidth
                        );
                    });

                await Promise.all(visibleVideos.map(async video => {
                    try {
                        video.muted = true;
                        video.playsInline = true;

                        if (video.readyState < HTMLMediaElement.HAVE_CURRENT_DATA) {
                            video.load();

                            await Promise.race([
                                new Promise(resolve => {
                                    video.addEventListener('loadeddata', resolve, {
                                        once: true
                                    });
                                }),
                                new Promise(resolve => setTimeout(resolve, 3000))
                            ]);
                        }

                        if (Number.isFinite(video.duration) && video.duration > 0) {
                            const targetTime = Math.min(
                                Math.max(0.1, video.duration * 0.25),
                                video.duration - 0.05
                            );

                            if (Math.abs(video.currentTime - targetTime) > 0.05) {
                                video.currentTime = targetTime;

                                await Promise.race([
                                    new Promise(resolve => {
                                        video.addEventListener('seeked', resolve, {
                                            once: true
                                        });
                                    }),
                                    new Promise(resolve => setTimeout(resolve, 3000))
                                ]);
                            }
                        }

                        if ('requestVideoFrameCallback' in video) {
                            await Promise.race([
                                new Promise(resolve => {
                                    video.requestVideoFrameCallback(() => resolve());
                                }),
                                new Promise(resolve => setTimeout(resolve, 1000))
                            ]);
                        }

                        video.pause();
                    } catch (e) {
                        // 再生不能な動画はそのまま撮影する
                    }
                }));

                // Canvas/WebGLの次の描画タイミングを待つ
                await new Promise(resolve => {
                    requestAnimationFrame(() => {
                        requestAnimationFrame(resolve);
                    });
                });
            });
        }

        await page.evaluate(() => {
            window.scrollTo(0, 0);
            if (window.__getScrollableElements) {
                window.__getScrollableElements().forEach(el => {
                    el.scrollTop = 0;
                });
            }
            if (window.__hideFixedElements) {
                window.__hideFixedElements(false);
            }
        });

        await new Promise(resolve => setTimeout(resolve, 500));

        await page.evaluate(() => {
            const style = document.createElement('style');
            style.textContent = `
                html, body {
                    height: auto !important;
                    min-height: 100% !important;
                    overflow: visible !important;
                }
            `;
            document.head.appendChild(style);

            if (window.__getScrollableElements) {
                window.__getScrollableElements().forEach(el => {
                    if (el === document.documentElement || el === document.body) return;

                    const computed = window.getComputedStyle(el);
                    if (computed.position === 'fixed') {
                        el.style.setProperty('position', 'relative', 'important');
                    }
                    el.style.setProperty('height', el.scrollHeight + 'px', 'important');
                    el.style.setProperty('max-height', 'none', 'important');
                    el.style.setProperty('overflow', 'visible', 'important');
                    el.style.setProperty('overflow-y', 'visible', 'important');
                    el.style.setProperty('transform', 'none', 'important');
                });
            }
        });

        await new Promise(resolve => setTimeout(resolve, 500));

        const finalDocHeight = await page.evaluate(() => {
            const heights = [
                document.documentElement ? document.documentElement.scrollHeight : 0,
                document.documentElement ? document.documentElement.offsetHeight : 0,
                document.body ? document.body.scrollHeight : 0,
                document.body ? document.body.offsetHeight : 0,
                window.innerHeight,
                1080
            ];

            document.querySelectorAll('*').forEach(el => {
                const rect = el.getBoundingClientRect();
                const bottom = rect.bottom + window.scrollY;
                if (bottom > 0) {
                    heights.push(bottom);
                }
                if (el.scrollHeight > el.clientHeight + 20) {
                    heights.push(el.scrollHeight);
                }
            });

            return Math.ceil(Math.max(...heights.filter(height => Number.isFinite(height) && height > 0)));
        });

        const fullPageImage = await page.screenshot({
            type: 'png',
            encoding: 'base64',
            fullPage: true
        });

        captures.push({
            y: 0,
            width: viewportWidth,
            height: finalDocHeight,
            image: fullPageImage
        });

        const html = await page.content();
        
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
            data: { html, captures, css: cssContent, js: jsContent, graphicsDiagnostics },
            type: 'application/json'
        };
    };
    ";

    $response = wp_remote_post($browserless_function_url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode([
            'code' => $js_code,
            'context' => [
                'url' => $url,
                'delay_time' => $delay_time
            ]
        ]),
        'timeout' => 60 + $delay_time // ユーザー指定の遅延時間に合わせてタイムアウトを延長
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
    if (!$data || !isset($data['html']) || !isset($data['captures'])) {
        wp_send_json_error(['message' => '不正なデータが返却されました。']);
    }

    // 画像の保存とメディアライブラリ登録
    $upload_dir = wp_upload_dir();
    $scrape_dir = $upload_dir['path'];
    $filename = 'scraped_' . md5($url . time()) . '.png';
    $filepath = $scrape_dir . '/' . $filename;
    $file_url = $upload_dir['url'] . '/' . $filename;

    // 画像の高さを算出（分割撮影の場合は結合キャンバスの高さとして利用）
    $totalHeight = 0;
    $width = 1920;
    foreach ($data['captures'] as $cap) {
        $capY = isset($cap['y']) ? (int) $cap['y'] : 0;
        $capHeight = isset($cap['height']) ? (int) $cap['height'] : 0;
        $totalHeight = max($totalHeight, $capY + $capHeight);
    }

    if ($totalHeight <= 0 || empty($data['captures'])) {
        wp_send_json_error(['message' => '有効なスクリーンショットが取得できませんでした。']);
    }

    try {
        if (count($data['captures']) === 1 && (int) $data['captures'][0]['y'] === 0) {
            $image_data = base64_decode($data['captures'][0]['image'], true);
            if ($image_data === false) {
                wp_send_json_error(['message' => 'スクリーンショット画像のデコードに失敗しました。']);
            }
            file_put_contents($filepath, $image_data);
        } elseif (extension_loaded('imagick')) {
            $imagick = new Imagick();
            $imagick->newImage($width, $totalHeight, new ImagickPixel('white'));
            $imagick->setImageFormat('png');

            foreach ($data['captures'] as $cap) {
                $blob = base64_decode($cap['image']);
                $tile = new Imagick();
                $tile->readImageBlob($blob);
                $imagick->compositeImage($tile, Imagick::COMPOSITE_DEFAULT, 0, (int) $cap['y']);
                $tile->clear();
                $tile->destroy();
            }
            $imagick->writeImage($filepath);
            $imagick->clear();
            $imagick->destroy();
        } elseif (extension_loaded('gd')) {
            $img = imagecreatetruecolor($width, $totalHeight);
            $white = imagecolorallocate($img, 255, 255, 255);
            imagefill($img, 0, 0, $white);

            foreach ($data['captures'] as $cap) {
                $blob = base64_decode($cap['image']);
                $tile = imagecreatefromstring($blob);
                if ($tile !== false) {
                    imagecopy($img, $tile, 0, (int) $cap['y'], 0, 0, (int) $cap['width'], (int) $cap['height']);
                    imagedestroy($tile);
                }
            }
            imagepng($img, $filepath);
            imagedestroy($img);
        } else {
            // 結合できない場合はフォールバックとして1枚目だけ保存
            $image_data = base64_decode($data['captures'][0]['image']);
            file_put_contents($filepath, $image_data);
        }
    } catch (Exception $e) {
        wp_send_json_error(['message' => '画像の結合に失敗しました: ' . $e->getMessage()]);
    }

    if (!file_exists($filepath)) {
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
            'js' => isset($data['js']) ? $data['js'] : '',
            'graphicsDiagnostics' => isset($data['graphicsDiagnostics']) ? $data['graphicsDiagnostics'] : null
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

    // アタッチメントと投稿を紐付け（アイキャッチ画像として設定）
    set_post_thumbnail($post_id, $attach_id);

    // アタッチメントの親投稿を設定
    wp_update_post([
        'ID' => $attach_id,
        'post_parent' => $post_id
    ]);

    wp_send_json_success([
        'message' => 'スクレイピングが完了し、データが登録されました。',
        'post_id' => $post_id,
        'image_url' => $file_url
    ]);
}
