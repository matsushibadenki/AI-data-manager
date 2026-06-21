<?php
/*
 * File: /header.php
 * Name: header.php
 * Description: テーマヘッダーテンプレート
 *              サイトロゴ、ナビゲーション、メタタグを含む
 *              多言語対応
 */
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> data-theme="<?php echo esc_attr(defined('FOURIER_THEME_MODE') ? FOURIER_THEME_MODE : 'dark'); ?>">

<head>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-DC12Q1BHQH"></script>
    <script>
        window.dataLayer = window.dataLayer || [];

        function gtag() {
            dataLayer.push(arguments);
        }
        gtag('js', new Date());

        gtag('config', 'G-DC12Q1BHQH');
    </script>
    <meta charset="<?php bloginfo('charset'); ?>" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="format-detection" content="telephone=no" />
    <?php wp_head(); ?>

</head>

<body <?php body_class(); ?>>

    <header class="site-header">
        <a href="<?php echo esc_url(home_url('/')); ?>" class="site-logo">
            <span class="material-symbols-outlined" aria-hidden="true">folder_open</span>
            <span><?php bloginfo('name'); ?></span>
        </a>
        <nav>
        </nav>
    </header>

    <?php
    // グローバル学習データ管理メニュー
    $learning_menu_items = [
        ['url' => home_url('/'), 'label' => 'ダッシュボード'],
        ['url' => home_url('/index-image/'), 'label' => 'メディア一覧'],
        ['url' => home_url('/index-text/'), 'label' => 'シート一覧'],
        ['url' => home_url('/import-export/'), 'label' => 'インポート/エクスポート'],
        ['type' => 'separator'],
        ['url' => home_url('/text-based-learning/'), 'label' => '個別登録'],
        ['url' => home_url('/media-upload/'), 'label' => 'メディア登録'],
        ['url' => home_url('/ai-registration/'), 'label' => 'AI登録'],
        ['url' => home_url('/bot-registration/'), 'label' => 'Bot登録'],
        ['type' => 'separator'],
        ['url' => home_url('/api-settings/'), 'label' => 'API設定'],
    ];
    ?>
    <div style="width: 100%; background: var(--bg-surface, #fff); border-bottom: 1px solid var(--border-subtle, #eee); font-size: 0.9rem;">
        <div style="display: flex; gap: 1.5rem; padding: 0.8rem 1.5rem; overflow-x: auto; white-space: nowrap; max-width: 1200px; margin: 0 auto; align-items: center;">
            <?php foreach ($learning_menu_items as $item): ?>
                <?php if (isset($item['type']) && $item['type'] === 'separator'): ?>
                    <span style="color: var(--border-subtle, #ccc); margin: 0 -0.5rem;">|</span>
                <?php else: ?>
                    <a href="<?php echo esc_url($item['url']); ?>" style="color: var(--text-primary, #333); text-decoration: none; font-weight: 500;">
                        <?php echo esc_html__($item['label'], 'fourier'); ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>

            <a href="<?php echo esc_url(add_query_arg('action', 'logout')); ?>" style="color: var(--text-secondary, #666); text-decoration: none; font-weight: 500; margin-left: auto;">
                <?php echo esc_html__('ログアウト', 'fourier'); ?>
            </a>
        </div>
    </div>