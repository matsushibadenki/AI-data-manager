<?php
/*
 * Name: attachment.php
 * Description: 添付ファイル詳細ページテンプレート。画像/動画のプレビュー、メタ情報表示、ダウンロード機能。ダウンロードパスワード保護機能に対応。多言語対応。
 */
get_header();
?>

<main id="main" class="site-main" role="main">

<?php if (function_exists('bcn_display')) : ?>
    <div class="breadcrumbs">
        <?php bcn_display(); ?>
    </div>
<?php endif; ?>

<?php
// メディアファイルの詳細情報を取得
$media_item = get_post();
$mime_type = get_post_mime_type($media_item->ID);
$title = get_the_title($media_item->ID);
$description = get_post_field('post_content', $media_item->ID);

$download_url = wp_get_attachment_url($media_item->ID);
$file_name = basename($download_url);

// JSONデータを解析（メディアの説明がJSON形式の場合）
$json_data = json_decode($description, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $json_data = null; // JSONデコードに失敗した場合
}

// パスワード保護の検証
$password_required = false;
$password_error = false;
$hash = get_post_meta($media_item->ID, '_download_password', true);

if (!empty($hash)) {
    $cookie_name = 'fourier_unlocked_' . $media_item->ID;
    $expected_cookie_val = md5($hash . '_salt_cookie_2026');
    if (isset($_COOKIE[$cookie_name]) && $_COOKIE[$cookie_name] === $expected_cookie_val) {
        $password_required = false;
    } else {
        $password_required = true;
        // POSTされたパスワードを検証
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download_password'])) {
            $input_password = sanitize_text_field($_POST['download_password']);
            if (wp_check_password($input_password, $hash)) {
                setcookie($cookie_name, $expected_cookie_val, time() + 3600, '/');
                $password_required = false;
                wp_safe_redirect(get_attachment_link($media_item->ID));
                exit;
            } else {
                $password_error = true;
            }
        }
    }
}
?>

<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
    <header class="entry-header">
        <h1 class="entry-title"><?php echo esc_html($title); ?></h1>
    </header>

    <div class="entry-content">
        <?php if ($password_required) : ?>
            <!-- パスワード入力画面 -->
            <div class="download-password-box" style="max-width: 400px; margin: 2rem auto; text-align: center; background-color: var(--bg-surface); padding: 2.5rem 2rem; border-radius: var(--radius-lg); border: 1px solid var(--border-subtle); box-shadow: var(--shadow-lg);">
                <span class="material-symbols-outlined" style="font-size: 3rem !important; color: var(--accent); margin-bottom: 1rem;">lock</span>
                <p style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 1.5rem; text-align: center; line-height: 1.6;">
                    <?php echo esc_html__('このファイルはパスワードで保護されています。ダウンロードするにはパスワードを入力してください。', 'fourier'); ?>
                </p>
                <?php if ($password_error) : ?>
                    <p style="color: var(--error); font-size: 0.85rem; margin-bottom: 1rem; text-align: center; font-weight: 500;">
                        <?php echo esc_html__('パスワードが正しくありません。', 'fourier'); ?>
                    </p>
                <?php endif; ?>
                <form method="post" action="" style="display: flex; flex-direction: column; gap: 1.25rem; text-align: left;">
                    <div style="display: flex; flex-direction: column; gap: 0.4rem;">
                        <label for="download_password" style="font-size: 0.8rem; font-weight: 500; color: var(--text-secondary);"><?php echo esc_html__('パスワード', 'fourier'); ?></label>
                        <input type="password" name="download_password" id="download_password" class="upload-form-input" required autofocus style="background-color: var(--bg-primary); border: 1px solid var(--border-subtle); border-radius: var(--radius-md); padding: 0.75rem 1rem; color: var(--text-primary); font-size: 0.95rem; outline: none; transition: all var(--transition-base);" />
                    </div>
                    <button type="submit" class="btn-black" style="width: 100%; padding: 0.85rem; font-size: 0.9rem; font-weight: 500; justify-content: center; margin: 0.5rem 0 0 0;">
                        <?php echo esc_html__('認証', 'fourier'); ?>
                    </button>
                </form>
            </div>
        <?php else : ?>
            <!-- 画像の場合 -->
            <?php if (strpos($mime_type, 'image') !== false) : ?>
                <div class="media-item">
                    <img src="<?php echo esc_url($download_url); ?>" alt="<?php echo esc_attr($title); ?>" style="width: 100%; height: auto;" />
                </div>
            <!-- 動画の場合 -->
            <?php elseif (strpos($mime_type, 'video') !== false) : ?>
                <?php $video_url = wp_get_attachment_url($media_item->ID); ?>
                <div class="media-item">
                    <video controls width="640" height="360" style="width: 100%; height: auto;">
                        <source src="<?php echo esc_url($video_url); ?>" type="<?php echo esc_attr($mime_type); ?>">
                        <?php echo esc_html__('お使いのブラウザは動画タグに対応していません。', 'fourier'); ?>
                    </video>
                </div>
            <?php endif; ?>
            
            <p><a href="<?php echo esc_url($download_url); ?>" download="<?php echo esc_attr($file_name); ?>"><?php echo esc_html__('このファイルをダウンロード', 'fourier'); ?>&nbsp;<span class="material-symbols-outlined">download</span></a></p>

            <?php if ($json_data && is_array($json_data)) : ?>
                <ul class="detail-list">
                    <li><strong><?php echo esc_html__('ファイル名:', 'fourier'); ?></strong> <?php echo esc_html($file_name); ?></li>
                    <?php if (isset($json_data['name'])) : ?>
                        <li><strong><?php echo esc_html__('撮影者:', 'fourier'); ?></strong> <?php echo esc_html($json_data['name']); ?></li>
                    <?php endif; ?>
                    <?php if (isset($json_data['space'])) : ?>
                        <li><strong><?php echo esc_html__('場所:', 'fourier'); ?></strong> <?php echo esc_html($json_data['space']); ?></li>
                    <?php endif; ?>
                    <?php if (isset($json_data['type'])) : ?>
                        <li><strong><?php echo esc_html__('データタイプ:', 'fourier'); ?></strong> <?php echo esc_html($json_data['type']); ?></li>
                    <?php endif; ?>
                    <?php if (isset($json_data['detail']) && is_array($json_data['detail'])) : ?>
                        <li><strong><?php echo esc_html__('タグ:', 'fourier'); ?></strong>
                            <ul class="detail-tag">
                                <?php foreach ($json_data['detail'] as $detail_item) : ?>
                                    <li><?php echo esc_html($detail_item); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php endif; ?>
                    <?php if (isset($json_data['format'])) : ?>
                        <li><strong><?php echo esc_html__('学習フォーマット:', 'fourier'); ?></strong> <span style="display:inline-block; padding:0.2rem 0.6rem; background:var(--accent-subtle); color:var(--accent); border-radius:var(--radius-full); font-size:0.8rem; font-weight:500; border:1px solid rgba(201,169,110,0.3);"><?php echo esc_html($json_data['format']); ?></span></li>
                    <?php endif; ?>
                    <?php if (isset($json_data['data'])) : ?>
                        <li style="display: block; width: 100%; margin-top: 1rem;"><strong style="display: block; margin-bottom: 0.5rem;"><?php echo esc_html__('学習データ:', 'fourier'); ?></strong>
                            <div style="background-color: var(--bg-primary); border: 1px solid var(--border-subtle); border-radius: var(--radius-md); padding: 1rem; overflow-x: auto;">
                                <pre style="margin: 0; font-family: monospace; font-size: 0.85rem; color: var(--text-secondary); white-space: pre-wrap; word-wrap: break-word;"><?php echo esc_html(wp_json_encode($json_data['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
                            </div>
                        </li>
                    <?php endif; ?>
                </ul>
            <?php else : ?>
                <p><?php echo esc_html($description); ?></p>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</article>
<a href="javascript:history.back();" target="_top" class="btn-black"><?php echo esc_html__('前のページに戻る', 'fourier'); ?><span class="material-symbols-outlined">undo</span></a>

</main>
<?php
get_footer();
?>