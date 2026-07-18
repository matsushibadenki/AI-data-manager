<?php
require_once('../../../wp-load.php');
$url = 'https://assetlead.co.jp/';
$browserless_function_url = 'http://browserless:3000/function';
$js_code = "
module.exports = async ({ page, context }) => {
    await page.goto(context.url, { waitUntil: 'networkidle0', timeout: 30000 });
    
    // Inject CSS to disable hardware acceleration triggers
    await page.addStyleTag({ content: `
        * {
            transform: none !important;
            perspective: none !important;
            backface-visibility: visible !important;
            will-change: auto !important;
            box-shadow: none !important;
        }
    ` });

    await page.evaluate(async () => {
        const videos = document.querySelectorAll('video');
        for (let vid of videos) {
            vid.pause();
            
            if (vid.readyState < 2) {
                await new Promise(res => {
                    vid.onloadeddata = res;
                    vid.load();
                    setTimeout(res, 5000);
                });
            }
            
            await new Promise(res => {
                vid.onseeked = res;
                vid.currentTime = Math.min(2.0, vid.duration / 2 || 1.0);
                setTimeout(res, 3000);
            });
            
            // Try to draw to canvas again
            const canvas = document.createElement('canvas');
            canvas.width = vid.videoWidth || 1920;
            canvas.height = vid.videoHeight || 1080;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(vid, 0, 0, canvas.width, canvas.height);
            
            const img = document.createElement('img');
            img.src = canvas.toDataURL('image/jpeg');
            // We removed transforms, so we might need to manually center it or just replace it
            img.style.cssText = vid.style.cssText;
            img.className = vid.className;
            vid.parentNode.replaceChild(img, vid);
        }
    });
    
    const screenshot = await page.screenshot({ encoding: 'base64' });
    return { data: { screenshot }, type: 'application/json' };
};
";

$response = wp_remote_post($browserless_function_url, [
    'headers' => ['Content-Type' => 'application/json'],
    'body' => wp_json_encode(['code' => $js_code, 'context' => ['url' => $url]]),
    'timeout' => 60
]);
$body = wp_remote_retrieve_body($response);
$data = json_decode($body, true);
if (isset($data['screenshot'])) {
    $output_path = __DIR__ . '/../../tests/fixtures/test_scrape_gpu.png';
    file_put_contents($output_path, base64_decode($data['screenshot']));
    echo "Saved to {$output_path}\n";
} else {
    echo "Failed.\n";
}
