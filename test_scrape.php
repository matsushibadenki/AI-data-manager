<?php
require_once('../../../wp-load.php');

$url = 'https://assetlead.co.jp/';
$browserless_function_url = 'http://browserless:3000/function';

$js_code = "
module.exports = async ({ page, context }) => {
    // ネットワークアイドル状態まで待機
    try {
        await page.goto(context.url, { waitUntil: 'networkidle2', timeout: 30000 });
    } catch (e) {
        // タイムアウトしても処理を継続
    }
    
    // 初期ローディングが終わるまで少し待機（ローディング画面対策）
    await new Promise(r => setTimeout(r, 3000));

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

    // 少しスクロール
    await page.evaluate(async () => {
        await new Promise((resolve) => {
            let totalHeight = 0;
            let distance = 150;
            let maxRetries = 10;
            let retries = 0;
            let lastScrollHeight = document.body.scrollHeight;
            
            let timer = setInterval(() => {
                let scrollHeight = Math.max(document.body.scrollHeight, document.documentElement.scrollHeight);
                window.scrollBy(0, distance);
                totalHeight += distance;
                
                if (totalHeight >= scrollHeight - window.innerHeight || totalHeight > 15000) {
                    if (scrollHeight > lastScrollHeight) {
                        lastScrollHeight = scrollHeight;
                        retries = 0;
                    } else {
                        retries++;
                        if (retries >= maxRetries || totalHeight > 15000) {
                            clearInterval(timer);
                            resolve();
                        }
                    }
                }
            }, 100);
        });
    });
    
    await new Promise(r => setTimeout(r, 3000));
    await page.evaluate(() => window.scrollTo(0, 0));
    await new Promise(r => setTimeout(r, 1000));

    // ページ全体の高さを取得
    const docHeight = await page.evaluate(() => {
        return Math.max(
            document.body.scrollHeight, document.documentElement.scrollHeight,
            1080
        );
    });

    // ヒーロー部分の動画を処理
    await page.evaluate(async () => {
        const videos = Array.from(document.querySelectorAll('video'));
        await Promise.all(videos.map(vid => {
            return new Promise((resolve) => {
                vid.pause();
                vid.style.opacity = '1';
                vid.style.visibility = 'visible';

                if (vid.readyState >= 2) {
                    vid.currentTime = Math.min(1.0, vid.duration / 2 || 1.0);
                    resolve();
                } else {
                    vid.preload = 'auto';
                    let resolved = false;
                    const onLoaded = () => {
                        if (resolved) return;
                        resolved = true;
                        vid.currentTime = Math.min(1.0, vid.duration / 2 || 1.0);
                        resolve();
                    };
                    vid.addEventListener('loadeddata', onLoaded);
                    vid.addEventListener('canplay', onLoaded);
                    
                    setTimeout(() => {
                        if (!resolved) {
                            resolved = true;
                            resolve();
                        }
                    }, 3000);
                    vid.load();
                }
            });
        }));
    });
    
    await new Promise(r => setTimeout(r, 1000));
    
    const screenshot = await page.screenshot({
        clip: { x: 0, y: 0, width: 1920, height: docHeight },
        encoding: 'base64'
    });
    
    return { 
        data: { screenshot },
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
    'timeout' => 60
]);

if (is_wp_error($response)) {
    die("Error: " . $response->get_error_message());
}

$body = wp_remote_retrieve_body($response);
$data = json_decode($body, true);

if (isset($data['screenshot'])) {
    $image_data = base64_decode($data['screenshot']);
    file_put_contents('test_scrape.png', $image_data);
    echo "Screenshot saved to test_scrape.png\n";
} else {
    echo "No screenshot found in response.\n";
    print_r($data);
}
