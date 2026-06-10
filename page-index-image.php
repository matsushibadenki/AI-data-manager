<?php
/*
 * Name: page-index-image.php
 * Description: フロントページテンプレート。FileBird Liteフォルダフィルター（折り畳み機能付き）、メディアギャラリー（Masonry）、検索バー、ページネーション表示。多言語対応。
 * Template Name: Image Data Index
 */
get_header();

// グリッドサイズ（列数と行数）の取得と検証
$cols = isset($_GET['cols']) ? intval($_GET['cols']) : 4;
$rows = isset($_GET['rows']) ? intval($_GET['rows']) : 3;
if ($cols < 2 || $cols > 6) $cols = 4;
if ($rows < 1 || $rows > 10) $rows = 3;
$posts_per_page = $cols * $rows;
?>

<main>
<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1063191590209184"
     crossorigin="anonymous"></script>
     
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

        <div id="folders">
            <div class="folders-control-bar">
                <button type="button" id="toggle-folders-btn" class="toggle-folders-btn" aria-expanded="true" aria-controls="folders-collapsible" aria-label="<?php echo esc_attr__('フォルダで絞り込む', 'fourier'); ?>">
                    <span class="material-symbols-outlined icon-toggle">expand_more</span>
                </button>

                <div class="grid-select-container">
                    <select id="grid-cols-select" class="grid-select" aria-label="<?php echo esc_attr__('列数', 'fourier'); ?>">
                        <?php for ($i = 2; $i <= 6; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php selected($cols, $i); ?>><?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                    <span class="grid-select-separator">×</span>
                    <select id="grid-rows-select" class="grid-select" aria-label="<?php echo esc_attr__('行数', 'fourier'); ?>">
                        <?php for ($j = 1; $j <= 10; $j++): ?>
                            <option value="<?php echo $j; ?>" <?php selected($rows, $j); ?>><?php echo $j; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <div id="folders-collapsible" class="folders-collapsible">
                <?php
                // 現在選択されているフォルダ名を取得（"all" がデフォルト）
                $current_folder_name = isset($_GET['folder_name']) ? sanitize_text_field($_GET['folder_name']) : 'all';

                // フォルダ一覧の表示（選択されているフォルダ名を渡す）
                display_select_folders($current_folder_name);
                ?>
            </div>

            <script>
                // フォルダ（タブ）エリアの折り畳み/展開処理
                document.addEventListener('DOMContentLoaded', function() {
                    var toggleBtn = document.getElementById('toggle-folders-btn');
                    var collapsible = document.getElementById('folders-collapsible');
                    if (toggleBtn && collapsible) {
                        var searchParams = new URLSearchParams(window.location.search);
                        var hasActiveFolder = searchParams.has('folder_name') && searchParams.get('folder_name') !== 'all';

                        // 初期開閉状態の判定
                        var isExpanded = true;
                        if (hasActiveFolder) {
                            // フォルダでの絞り込みが有効な場合は常に展開表示
                            isExpanded = true;
                        } else {
                            // それ以外は localStorage の状態に従う（保存されていない場合のデフォルトは展開）
                            var storedState = localStorage.getItem('folders_collapsed');
                            if (storedState === 'true') {
                                isExpanded = false;
                            }
                        }

                        // 初期状態を適用
                        setExpandedState(isExpanded);

                        // クリック時のトグル処理
                        toggleBtn.addEventListener('click', function() {
                            var currentState = collapsible.classList.contains('expanded');
                            var newState = !currentState;
                            setExpandedState(newState);
                            localStorage.setItem('folders_collapsed', newState ? 'false' : 'true');
                        });

                        function setExpandedState(expand) {
                            if (expand) {
                                collapsible.classList.add('expanded');
                                toggleBtn.setAttribute('aria-expanded', 'true');
                            } else {
                                collapsible.classList.remove('expanded');
                                toggleBtn.setAttribute('aria-expanded', 'false');
                            }
                        }
                    }
                });

                document.getElementById('folder-filter').addEventListener('change', function(e) {
                    var folderName = e.target.value;
                    var searchParams = new URLSearchParams(window.location.search);

                    // ページが変更されたときに paged パラメータがあれば削除してページ1 にリセット
                    if (searchParams.has('paged')) {
                        searchParams.set('paged', 1);
                    }

                    // フォルダ名を検索パラメータに追加または変更
                    if (folderName === 'all') {
                        searchParams.delete('folder_name'); // 「すべて」を選択した場合はフォルダ名を削除
                    } else {
                        searchParams.set('folder_name', folderName);
                    }

                    // ページをリロード
                    window.location.search = searchParams.toString();
                });

                // ラジオボタンの変更イベントリスナー (foldergroup用)
                document.addEventListener('change', function(e) {
                    if (e.target && e.target.name === 'foldergroup') {
                        var folderName = e.target.value;
                        var searchParams = new URLSearchParams(window.location.search);

                        // ページが変更されたときに paged パラメータがあれば削除してページ1 にリセット
                        if (searchParams.has('paged')) {
                            searchParams.set('paged', 1);
                        }

                        // フォルダ名を検索パラメータに追加または変更
                        if (folderName === 'all') {
                            searchParams.delete('folder_name'); // 「すべて」を選択した場合はフォルダ名を削除
                        } else {
                            searchParams.set('folder_name', folderName);
                        }

                        // ページをリロード
                        window.location.search = searchParams.toString();
                    }
                });

                // グリッド列数の選択変更イベント
                document.getElementById('grid-cols-select').addEventListener('change', function(e) {
                    var searchParams = new URLSearchParams(window.location.search);
                    searchParams.set('cols', e.target.value);
                    if (searchParams.has('paged')) {
                        searchParams.set('paged', 1);
                    }
                    window.location.search = searchParams.toString();
                });

                // グリッド行数の選択変更イベント
                document.getElementById('grid-rows-select').addEventListener('change', function(e) {
                    var searchParams = new URLSearchParams(window.location.search);
                    searchParams.set('rows', e.target.value);
                    if (searchParams.has('paged')) {
                        searchParams.set('paged', 1);
                    }
                    window.location.search = searchParams.toString();
                });
            </script>
        </div>

        <!-- パンくずリストを表示 -->
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

        // 現在選択されているフォルダ名を取得
        $folder_id = null;
        $attachment_ids = array();

        // FileBird の専用関数を使用して、フォルダIDからアタッチメントIDを取得
        if ($current_folder_name !== 'all') {
            $attachments = get_attachments_by_folder_name($current_folder_name);
            if ($attachments && is_array($attachments)) {
                $attachment_ids = array_map(function ($attachment) {
                    return isset($attachment->ID) ? (int)$attachment->ID : null; // アタッチメントIDのみを抽出
                }, $attachments);
                $attachment_ids = array_filter($attachment_ids); // NULL 値を削除
            }
        }

        // クエリ引数の構築
        if ($current_folder_name !== 'all' && !empty($attachment_ids)) {
            $args = array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => $posts_per_page,
                'paged'          => $paged,
                'post__in'       => $attachment_ids,
                's'              => $search_query, // 検索クエリを追加
            );
        } elseif ($current_folder_name !== 'all' && empty($attachment_ids)) {
            // フォルダにメディアが存在しない場合、結果を返さないクエリを作成
            $args = array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => $posts_per_page,
                'paged'          => $paged,
                'post__in'       => array(0), // 存在しないIDを指定して結果が空になるようにする
                's'              => $search_query,
            );
            echo '<p class="empty-message">' . esc_html__('選択されたフォルダにはメディアファイルがありません。', 'fourier') . '</p>';
        } else {
            // すべてのメディアを表示
            $args = array(
                'post_type'      => array('attachment'),
                'post_mime_type' => array('image', 'video', 'application/pdf', 'audio'),
                'post_status'    => 'inherit',
                'posts_per_page' => $posts_per_page,
                'paged'          => $paged,
                's'              => $search_query, // 検索クエリを追加
            );
        }

        $media_items = new WP_Query($args);

        // メディアがあるか確認
        if ($media_items->have_posts()) :
            echo '<div class="media-gallery" style="--grid-cols: ' . esc_attr($cols) . ';">';
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
                            <!-- 画像ページリンク -->
                            <a href="<?php echo get_attachment_link($media_item->ID); ?>" class="medialink">
                                <img src="<?php echo esc_url($thumbnail[0]); ?>" alt="<?php echo esc_attr($title); ?>" />
                            </a>
                            <!-- ダウンロードリンク -->
                            <a href="<?php echo esc_url($download_url); ?>" download="<?php echo esc_attr($file_name); ?>" class="downloadlink"><span class="material-symbols-outlined">download</span></a>

                        </div>

                        <!-- 説明（JSON形式データの解析結果を表示） -->
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
                                <?php if (isset($json_data['format'])) : ?>
                                    <li><span><?php echo esc_html__('学習データ', 'fourier'); ?></span><span style="background:var(--accent-subtle); color:var(--accent); padding:0.1rem 0.5rem; border-radius:var(--radius-full); font-size:0.7rem; border:1px solid rgba(201,169,110,0.3);"><?php echo esc_html($json_data['format']); ?></span></li>
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
                            <!-- ダウンロードリンク -->
                            <a href="<?php echo esc_url($download_url); ?>" download="<?php echo esc_attr($file_name); ?>" class="downloadlink"><span class="material-symbols-outlined">download</span></a>
                        </div>
                        <h3><?php echo esc_html($title); ?></h3>
                        <p><?php echo esc_html__('ファイル名', 'fourier'); ?>: <?php echo esc_html($file_name); ?></p>
                    </div>
        <?php
                }
                // 他のメディアタイプの場合、ここに追加のケースを記述できます
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
                'end_size'     => 2,
                'mid_size'     => 2,
                'prev_next'    => true,
                'prev_text'    => __('« Previous'),
                'next_text'    => __('Next »'),
                'type'         => 'plain',
                'add_args'     => array(), // 現在のクエリパラメータを維持
                'add_fragment' => '',
            ));
            echo '</nav>';
        else :
            echo '<p class="empty-message">' . esc_html__('お探しのメディアファイルが見つかりませんでした。', 'fourier') . '</p>';
        endif;
        ?>
    </div>
</main>

<?php get_footer(); ?>