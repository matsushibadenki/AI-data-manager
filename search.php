<?php
/*
 * Name: search.php
 * Description: 検索結果ページテンプレート。メディアファイル検索、ギャラリー表示、ページネーション。多言語対応。
 */
get_header();
?>

<main>
    <div id="primary">

        <div id="search">
            <form role="search" method="get" class="search-form" action="<?php echo esc_url(home_url('/')); ?>">
                <label>
                    <span class="screen-reader-text"><?php echo _x('検索:', 'label'); ?></span>
                    <input type="search" class="search-field" placeholder="<?php echo esc_attr_x('検索 …', 'placeholder'); ?>" value="<?php echo get_search_query(); ?>" name="s" />
                </label>
                <button type="submit" class="search-submit"><?php echo esc_attr_x('検索', 'submit button'); ?></button>
            </form>
        </div>

        <h3><?php printf(esc_html__('検索結果: %s', 'textdomain'), get_search_query()); ?></h3>

        <?php if (function_exists('bcn_display')) : ?>
            <div class="breadcrumbs">
                <?php bcn_display(); ?>
            </div>
        <?php endif; ?>

        <?php
        // 現在のページ番号を取得
        $paged = (get_query_var('paged')) ? get_query_var('paged') : ((get_query_var('page')) ? get_query_var('page') : 1);

        // 検索クエリを確認
        $search_query = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

        // メディアの画像と動画一覧を取得（検索条件付き）
        $args = array(
            'post_type'      => array('attachment', 'post'),
            'post_mime_type' => array('image', 'video', 'application/pdf', 'audio'),
            'post_status'    => 'inherit',
            'posts_per_page' => 10,
            'paged'          => $paged,
            's'              => $search_query, // 検索クエリを追加
        );
        $media_items = new WP_Query($args);


        // メディアがあるか確認
        if ($media_items->have_posts()) :
            echo '<div class="media-gallery">';
            while ($media_items->have_posts()) : $media_items->the_post();
                $media_item = get_post();
                $mime_type = get_post_mime_type($media_item->ID);
                $title = get_the_title($media_item->ID);
                $file_name = basename(wp_get_attachment_url($media_item->ID));
                $download_url = wp_get_attachment_url($media_item->ID); // ダウンロードURL取得
                // 説明を取得（post_contentを使用）
                $description = get_post_field('post_content', $media_item->ID);
                // JSONデータを解析
                $json_data = json_decode($description, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $json_data = null; // JSONデコードに失敗した場合
                }


                // 画像の場合
                if (strpos($mime_type, 'image') !== false) {
                    $thumbnail = wp_get_attachment_image_src($media_item->ID, 'large');
        ?>
                    <div class="media-item">
                        <div class="mediaarea">
                            <a href="<?php echo get_attachment_link($media_item->ID); ?>" class="medialink">
                                <img src="<?php echo esc_url($thumbnail[0]); ?>" alt="<?php echo esc_attr($title); ?>" />
                            </a>
                            <a href="<?php echo esc_url($download_url); ?>" download="<?php echo esc_attr($file_name); ?>" class="downloadlink"><span class="material-symbols-outlined">download</span></a>

                        </div>

                        <?php if ($json_data && is_array($json_data)) : ?>
                            <h3><?php echo esc_html($title); ?></h3>
                            <ul class="detail-list">
                                <li><span><?php echo esc_html__('ファイル名', 'fourier'); ?></span><span><?php echo esc_html($file_name); ?></span></li>
                                <?php if (isset($json_data['name'])) : ?>
                                    <li><span><?php echo esc_html__('撮影者', 'fourier'); ?></span><span><?php echo esc_html($json_data['name']); ?></span></li>
                                <?php endif; ?>
                                <?php if (isset($json_data['space'])) : ?>
                                    <li><span><?php echo esc_html__('場所', 'fourier'); ?></span><span><?php echo esc_html($json_data['space']); ?></span></li>
                                <?php endif; ?>
                                <?php if (isset($json_data['type'])) : ?>
                                    <li><span><?php echo esc_html__('データタイプ', 'fourier'); ?></span><span><?php echo esc_html($json_data['type']); ?></span></li>
                                <?php endif; ?>
                                <?php if (isset($json_data['detail']) && is_array($json_data['detail'])) : ?>
                                    <li><span><?php echo esc_html__('タグ', 'fourier'); ?></span>
                                        <ul class="detail-tag">
                                            <?php foreach ($json_data['detail'] as $detail_item) : ?>
                                                <li><?php echo esc_html($detail_item); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        <?php else : ?>
                            <h3><?php echo esc_html($title); ?></h3>
                            <?php if (!empty($description)) : ?>
                                <p><span><?php echo esc_html__('説明', 'fourier'); ?></span><span><?php echo esc_html($description); ?></span></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php
                }
                // 動画の場合
                elseif (strpos($mime_type, 'video') !== false) {
                    $video_url = wp_get_attachment_url($media_item->ID);
                ?>
                    <div class="media-item">
                        <div class="mediaarea">
                            <!-- 動画詳細ページリンク -->
                            <a href="<?php echo get_attachment_link($media_item->ID); ?>" class="medialink">
                                <video preload="metadata" style="width: 100%; height: auto; pointer-events: none; display: block;">
                                    <source src="<?php echo esc_url($video_url); ?>" type="<?php echo esc_attr($mime_type); ?>">
                                    <?php echo esc_html__('お使いのブラウザは動画タグに対応していません。', 'fourier'); ?>
                                </video>
                            </a>
                            <a href="<?php echo esc_url($download_url); ?>" download="<?php echo esc_attr($file_name); ?>" class="downloadlink"><span class="material-symbols-outlined">download</span></a>
                        </div>
                        <h3><?php echo esc_html($title); ?></h3>
                        <p><?php echo esc_html__('ファイル名', 'fourier'); ?>: <?php echo esc_html($file_name); ?></p>
                    </div>
        <?php
                }
            endwhile;
            wp_reset_postdata(); // ここでリセット
            echo '</div>';

            // ページネーションの追加
            echo '<nav class="pagination">';
            echo paginate_links(array(
                'total'        => $media_items->max_num_pages,
                'current'      => $paged,
                'format'       => '?paged=%#%',
                'show_all'     => false,
                'end_size'     => 1,
                'mid_size'     => 2,
                'prev_next'    => true,
                'prev_text'    => __('« Previous'),
                'next_text'    => __('Next »'),
                'type'         => 'plain',
                'add_args'     => array( 's' => get_search_query() ), // バグ修正: 検索クエリをページネーションに引き継ぐ
                'add_fragment' => '',
            ));
            echo '</nav>';
        else :
            echo '<p class="empty-message">' . esc_html__('お探しのメディアファイルが見つかりませんでした。検索条件を変更してもう一度お試しください。', 'fourier') . '</p>';
        endif;
        ?>
    </div>
    <a href="<?php echo esc_url(home_url('/')); ?>" target="_top" class="btn-black"><?php echo esc_html__('戻る', 'fourier'); ?><span class="material-symbols-outlined">undo</span></a>
</main>

<?php
get_footer();
?>