<?php
/*
 * Name: page.php
 * Description: 固定ページテンプレート。メインコンテンツとサイドバーを .site-content-wrapper で囲むよう調整。多言語対応。
 * Template Name: page
 */
?>
<?php get_header(); ?>

<!-- パンくずリストを表示 -->
<?php if (function_exists('bcn_display')) : ?>
    <div class="breadcrumbs">
        <?php bcn_display(); ?>
    </div>
<?php endif; ?>

<div class="site-content-wrapper">
    <div id="primary" class="content-area">
        <main id="main" class="site-main" role="main">

            <?php
            if (have_posts()) :
                while (have_posts()) : the_post();
                    // サムネイルを取得
                    $thumbnail = wp_get_attachment_image_src(get_the_ID(), 'full');
                    // 画像のタイトル
                    $title = get_the_title();
                    // 説明を取得
                    $description = get_post_field('post_content', get_the_ID());
                    // ファイル名
                    $file_name = basename(wp_get_attachment_url(get_the_ID()));

                    // JSONデータを解析
                    $json_data = json_decode($description, true);
                    ?>

                    <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                        <header class="entry-header">
                            <h1 class="entry-title"><?php the_title(); ?></h1>
                        </header>

                        <!-- 画像を表示 -->
                        <div class="entry-content">
                            <?php if ($thumbnail) : ?>
                                <img src="<?php echo esc_url($thumbnail[0]); ?>" alt="<?php echo esc_attr($title); ?>" />
                            <?php endif; ?>

                            <!-- JSONデータを解析して表示 -->
                            <?php if ($json_data && is_array($json_data)) : ?>
                                <h4>JSONデータ:</h4>
                                <ul>
                                    <li><strong>名前:</strong> <?php echo esc_html($json_data['name']); ?></li>
                                    <li><strong>スペース:</strong> <?php echo esc_html($json_data['space']); ?></li>
                                    <?php if (isset($json_data['detail']) && is_array($json_data['detail'])) : ?>
                                    <li><strong>詳細:</strong>
                                        <ul>
                                            <?php foreach ($json_data['detail'] as $detail_item) : ?>
                                                <li><?php echo esc_html($detail_item); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </li>
                                    <?php endif; ?>
                                    <?php if (isset($json_data['format'])) : ?>
                                        <li><strong>学習フォーマット:</strong> <?php echo esc_html($json_data['format']); ?></li>
                                    <?php endif; ?>
                                    <?php if (isset($json_data['data'])) : ?>
                                        <li style="display: block; width: 100%; margin-top: 1rem;"><strong>学習データ:</strong>
                                            <div style="background-color: #f5f5f5; border: 1px solid #ddd; padding: 1rem; overflow-x: auto; margin-top: 0.5rem;">
                                                <pre style="margin: 0; font-family: monospace; font-size: 0.85rem; white-space: pre-wrap; word-wrap: break-word;"><?php echo esc_html(wp_json_encode($json_data['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
                                            </div>
                                        </li>
                                    <?php endif; ?>
                                    <li><strong>タイプ:</strong> <?php echo esc_html($json_data['type']); ?></li>
                                </ul>
                            <?php else : ?>
                                <p><strong>説明:</strong> <?php echo esc_html($description); ?></p>
                            <?php endif; ?>

                            <!-- ダウンロードリンク -->
                            <a href="<?php echo esc_url(wp_get_attachment_url(get_the_ID())); ?>" download="<?php echo esc_attr($file_name); ?>">ダウンロード</a>
                        </div>

                        <footer class="entry-footer">
                            <?php edit_post_link(__('Edit', 'textdomain'), '<span class="edit-link">', '</span>'); ?>
                        </footer>
                    </article>

                    <?php
                endwhile;
            else :
                echo '<p>No content found.</p>';
            endif;
            ?>

        </main>
    </div>

    <?php get_sidebar(); ?>
</div>

<?php get_footer(); ?>
