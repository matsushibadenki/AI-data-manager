<?php
require_once('../../../wp-load.php');
$url = 'https://assetlead.co.jp/';
$browserless_function_url = 'http://browserless:3000/function';
$js_code = "
module.exports = async ({ page, context }) => {
    await page.goto(context.url, { waitUntil: 'networkidle0', timeout: 30000 });
    
    await page.evaluate(async () => {
        const videos = document.querySelectorAll('video');
        for (let vid of videos) {
            vid.pause();
            
            // Wait for video to have enough data to seek
            if (vid.readyState < 2) {
                await new Promise(res => {
                    vid.onloadeddata = res;
                    vid.load();
                    setTimeout(res, 5000);
                });
            }
            
            // Seek and wait for seeked event
            await new Promise(res => {
                vid.onseeked = res;
                vid.currentTime = Math.min(2.0, vid.duration / 2 || 1.0);
                setTimeout(res, 3000); // timeout
            });
            
            // Create canvas and draw video
            const canvas = document.createElement('canvas');
            canvas.width = vid.videoWidth || 1920;
            canvas.height = vid.videoHeight || 1080;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(vid, 0, 0, canvas.width, canvas.height);
            
            // Replace video with image
            const img = document.createElement('img');
            img.src = canvas.toDataURL('image/jpeg');
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
    file_put_contents('test_scrape_canvas2.png', base64_decode($data['screenshot']));
    echo "Saved to test_scrape_canvas2.png\n";
} else {
    echo "Failed.\n";
}
