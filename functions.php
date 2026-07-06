<?php
/*
 * Name: functions.php
 * Description: テーマ初期設定、スタイル・スクリプトのキューイング、メインクエリの調整処理等を含む関数群。LaTeX (KaTeX) の読み込み処理を追加。
 */
/* ---------------------------------------------------------- */
/* ------------------------- 初期設定 ------------------------ */
/* ---------------------------------------------------------- */

/* ─────────────────────────────────────────────────────────────
 * テーマモード切り替えフラグ
 * 'dark'  → ダークモード（黒背景 + ゴールドアクセント）
 * 'light' → ライトモード（白背景 + ゴールドアクセント）
 * ───────────────────────────────────────────────────────────── */
define('FOURIER_THEME_MODE', 'light');

/* ---------------- カスタム投稿管理画面のメニュー幅 --------------- */
require_once get_template_directory() . '/inc/functions_control-panel.php';
/* ----------------------- 不要なもの削除 ---------------------- */
require_once get_template_directory() . '/inc/functions_remove.php';
/* ----------------------- 必要なもの追加 ---------------------- */
require_once get_template_directory() . '/inc/functions_necessary.php';
/* ----------------------- php・html読み込みの追加 ---------------------- */
require_once get_template_directory() . '/inc/functions_import.php';
/* ----------------------- Filebird制御の設定 ---------------------- */
require_once get_template_directory() . '/inc/functions_filebird-control.php';
require_once get_template_directory() . '/inc/functions_filebirdAPI.php';

/* ----------------------- LLM学習データ関連 ---------------------- */
require_once get_template_directory() . '/inc/functions_learning-data.php';
require_once get_template_directory() . '/inc/functions_llm_api.php';
require_once get_template_directory() . '/inc/functions_rest_api.php';
require_once get_template_directory() . '/inc/functions_sara_event_memory.php';

/* ----------------------- 重複メディア管理の設定 ---------------------- */
require_once get_template_directory() . '/inc/functions_duplicate-media.php';

/* ----------------------- カスタム投稿の設定 ---------------------- */
/* require_once get_template_directory() . '/inc/functions_custumpost.php'; */

/* ----------------------- メイン処理 ---------------------- */
require_once get_template_directory() . '/inc/functions_mainprocess.php';




/* -------------------------- テーマ設定・多言語対応 -------------------------- */
function my_theme_setup() {
    load_theme_textdomain('fourier', get_template_directory() . '/languages');
}
add_action('after_setup_theme', 'my_theme_setup');

/* -------------------------- ファイル読み込み -------------------------- */

/* CSSとJavaScriptの読み込み */
function my_script_init()
{
    //false → </head>の前に書かれる
    //true → </body>の前に書かれる

    // WordPressに含まれているjquery.jsを読み込まない
    // wp_deregister_script('jquery'); // Masonry初期化スクリプトでjQueryが必要なためコメントアウト


    // ウェブフォントの読み込み（Minimal Luxury テーマ用に整理）
    wp_enqueue_style('font_font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css', array(), '6.1.0');

    // Inter + Noto Sans JP（メインフォント）
    wp_enqueue_style('font_inter_noto', 'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Noto+Sans+JP:wght@300;400;500;600;700&display=swap', array(), '2.0.0');

    // Material Symbols Outlined（アイコン）
    wp_enqueue_style('font_material', 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200', array(), '6.1.0');


    //CSS 読み込み
    wp_enqueue_style('color-css', get_template_directory_uri() . '/assets/css/base/color-css.css', array(), '1.0.1');
    wp_enqueue_style('base-css', get_template_directory_uri() . '/assets/css/base/base.css', array(), '1.0.1');
    wp_enqueue_style('font-css', get_template_directory_uri() . '/assets/css/base/font-css.css', array(), '1.0.1');

    // ★★★ 修正 (キャッシュ対策) ★★★
    // バージョンを filemtime に変更してキャッシュを自動的にクリア
    $css_file_path = get_template_directory() . '/assets/css/front-page.css';
    $css_version = file_exists($css_file_path) ? filemtime($css_file_path) : '1.0.2'; // filemtime() を使用
    wp_enqueue_style('front-page-css', get_template_directory_uri() . '/assets/css/front-page.css', array(), $css_version);


    //JavaScript 読み込み
    // wp_enqueue_script('data-background-js', 'https://unpkg.com/masonry-layout@4/dist/masonry.pkgd.min.js', array(), '3.12.5', true); // ハンドル名重複＆古い登録のため削除

    // imagesLoaded (CDN) - jQueryに依存
    wp_enqueue_script('imagesloaded', 'https://unpkg.com/imagesloaded@5/imagesloaded.pkgd.min.js', array('jquery'), '5.0.0', true);

    // Masonry (CDN) - imagesloaded に依存
    wp_enqueue_script('masonry', 'https://unpkg.com/masonry-layout@4/dist/masonry.pkgd.min.js', array('imagesloaded'), '4.2.2', true);

    // data-background.js (ハンドル名を修正)
    wp_enqueue_script('my-data-background', get_template_directory_uri() . '/assets/js/data-background.js', array(), '3.12.5', true);

    // Masonry 初期化スクリプト - masonry と jquery に依存
    wp_enqueue_script('masonry-init', get_template_directory_uri() . '/assets/js/masonry-init.js', array('masonry', 'jquery'), '1.0.0', true);

    // LaTeX (KaTeX) の読み込み
    if (is_front_page() || is_page_template('page-index-text.php') || is_page_template('page-import-export.php')) {
        wp_enqueue_style('katex-css', 'https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/katex.min.css', array(), '0.16.8');
        wp_enqueue_script('katex-js', 'https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/katex.min.js', array(), '0.16.8', true);
        wp_enqueue_script('katex-auto-render-js', 'https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/contrib/auto-render.min.js', array('katex-js'), '0.16.8', true);
    }
}
add_action('wp_enqueue_scripts', 'my_script_init');


/* ----------------------- トップページのみ JS読み込み ---------------------- */
function frontpage_scripts()
{
    // トップページのみで読み込み
    if (is_front_page()) {
        // スクリプトパスを取得
        $script_path = get_template_directory_uri() . '/assets/js/main_front.js';
        // main_frontスクリプトを登録
        wp_enqueue_script('main_front', $script_path, array(), null, true);
    }
}
// アクションフックを使用してスクリプトを登録
//add_action('wp_enqueue_scripts', 'frontpage_scripts');


/* ----------------------- サムネイルのサイズを登録 ---------------------- */
add_image_size('custom-thumbnail', 800, 600, true); // 幅800px、高さ600px、トリミングあり

/* ----------------------- ページネーション ---------------------- */
function my_theme_pre_get_posts($query)
{
    // 管理画面、またはメインクエリでない場合は何もしない
    if (is_admin() || !$query->is_main_query()) {
        return;
    }

    // フロントページ（トップページ）の場合の処理
    if ($query->is_front_page()) {
        // 表示する投稿タイプを「添付ファイル」に限定
        $query->set('post_type', 'attachment');
        // 投稿ステータスを「inherit」に設定
        $query->set('post_status', 'inherit');

        // URLパラメータ（cols と rows）の値に基づいて動的に表示件数（posts_per_page）を設定
        $cols = isset($_GET['cols']) ? intval($_GET['cols']) : 4;
        $rows = isset($_GET['rows']) ? intval($_GET['rows']) : 3;
        if ($cols < 2 || $cols > 6) $cols = 4;
        if ($rows < 1 || $rows > 10) $rows = 3;
        $query->set('posts_per_page', $cols * $rows);
        return;
    }

    // 検索結果ページの場合の処理
    if ($query->is_search()) {
        // 検索対象に「投稿」「固定ページ」「添付ファイル」を含める
        $query->set('post_type', array('post', 'page', 'attachment'));
        // 投稿ステータスを設定
        $query->set('post_status', array('publish', 'inherit'));
        return;
    }
}
add_action('pre_get_posts', 'my_theme_pre_get_posts');





/* ----------------------- 検索 ---------------------- */
function include_attachments_in_search($query)
{
    if ($query->is_search() && !is_admin() && $query->is_main_query()) {
        $query->set('post_type', array('post', 'page', 'attachment'));
        $query->set('post_status', array('inherit', 'publish'));
    }
}
add_action('pre_get_posts', 'include_attachments_in_search');

add_action('init', function () {
    global $wp_rewrite;

    // リライトルールをログに書き込む
    error_log(print_r($wp_rewrite->rules, true));
});


/* ----------------------- attachment.phpを正常に表示させる ---------------------- */
// ★★★ 修正 ★★★
// ターン10〜12の変更をすべて破棄し、オリジナルのコード に復元

// 画像ファイルのリンク先が ?attachment_id=XXXX の形式のときだけ添付ファイルページにリダイレクト
add_filter('wp_get_attachment_url', 'force_attachment_page_url_only_for_attachment_id', 10, 2);

function force_attachment_page_url_only_for_attachment_id($url, $post_id)
{
    // 現在のURLが ?attachment_id=XXXX の形式かどうかを確認
    if (isset($_GET['attachment_id']) && $_GET['attachment_id'] == $post_id) {
        // 添付ファイルページのURLに強制リダイレクト
        $attachment_link = get_attachment_link($post_id);
        if ($attachment_link) {
            return $attachment_link;
        }
    }

    // 通常の img タグなどの場合は元のURLを返す
    return $url;
}

// attachment.php ではこのフィルタを無効化する
add_action('template_redirect', 'disable_attachment_url_filter_in_attachment_template');

function disable_attachment_url_filter_in_attachment_template()
{
    if (is_attachment()) {
        // attachment.php では wp_get_attachment_url フィルタを一時的に削除
        remove_filter('wp_get_attachment_url', 'force_attachment_page_url_only_for_attachment_id', 10);
    }
}


add_filter('wp_robots', function ($robots) {
    unset($robots['noindex']);
    unset($robots['nofollow']);
    return $robots;
});

/*--------------------------------------------------------------
  フロントエンド ドラッグ＆ドロップメディアアップロード用AJAXハンドラ
--------------------------------------------------------------*/
add_action('wp_ajax_frontend_media_upload', 'frontend_media_upload_handler');
add_action('wp_ajax_nopriv_frontend_media_upload', 'frontend_media_upload_handler');

function frontend_media_upload_handler()
{
    // ノンスの検証
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'frontend_upload_action')) {
        wp_send_json_error(array('message' => esc_html__('セッションが切れたか、無効なリクエストです。再読み込みしてください。', 'fourier')), 403);
    }

    if (empty($_FILES['file'])) {
        wp_send_json_error(array('message' => esc_html__('ファイルが送信されていません。', 'fourier')), 400);
    }

    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');

    // メディアライブラリに保存（ファイルアップロード処理）
    $attachment_id = media_handle_upload('file', 0);

    if (is_wp_error($attachment_id)) {
        wp_send_json_error(array('message' => $attachment_id->get_error_message()), 500);
    }

    // メタデータの構築と説明(post_content)への保存
    $meta_name = isset($_POST['meta_name']) ? sanitize_text_field($_POST['meta_name']) : '';
    $meta_space = isset($_POST['meta_space']) ? sanitize_text_field($_POST['meta_space']) : '';
    $meta_type = isset($_POST['meta_type']) ? sanitize_text_field($_POST['meta_type']) : '';
    $meta_detail_str = isset($_POST['meta_detail']) ? sanitize_text_field($_POST['meta_detail']) : '';

    $meta_data = array();
    if (!empty($meta_name)) {
        $meta_data['name'] = $meta_name;
    }
    if (!empty($meta_space)) {
        $meta_data['space'] = $meta_space;
    }
    if (!empty($meta_type)) {
        $meta_data['type'] = $meta_type;
    }
    if (!empty($meta_detail_str)) {
        $tags = array_map('trim', explode(',', $meta_detail_str));
        $meta_data['detail'] = array_values(array_filter($tags));
    }

    // 学習データの取得
    $learning_format = isset($_POST['learning_format']) ? sanitize_text_field($_POST['learning_format']) : '';
    $learning_data_json = isset($_POST['learning_data']) ? stripslashes($_POST['learning_data']) : '';
    
    if (!empty($learning_format) && $learning_format !== 'none') {
        $meta_data['format'] = $learning_format;
        if (!empty($learning_data_json)) {
            $learning_data_arr = json_decode($learning_data_json, true);
            if ($learning_data_arr !== null) {
                $meta_data['data'] = $learning_data_arr;
            }
        }
        update_post_meta($attachment_id, 'is_learning_data', '1');
    }

    if (!empty($meta_data)) {
        $json_content = wp_json_encode($meta_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        wp_update_post(array(
            'ID'           => $attachment_id,
            'post_content' => $json_content
        ));
    }

    // FileBird フォルダの割り当て
    if (isset($_POST['folder_id'])) {
        $folder_id = intval($_POST['folder_id']);
        if ($folder_id > 0 && function_exists('add_attachment_to_filebird_folder')) {
            add_attachment_to_filebird_folder($attachment_id, $folder_id);
        }
    }

    $thumbnail_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
    if (!$thumbnail_url) {
        $thumbnail_url = wp_mime_type_icon($attachment_id);
    }

    wp_send_json_success(array(
        'attachment_id' => $attachment_id,
        'thumbnail'     => $thumbnail_url,
        'url'           => wp_get_attachment_url($attachment_id),
        'message'       => esc_html__('アップロードが正常に完了しました。', 'fourier')
    ));
}

/*--------------------------------------------------------------
  フロントエンド ギガファイル風一時共有アップロード用AJAXハンドラ
--------------------------------------------------------------*/
add_action('wp_ajax_frontend_gigafile_upload', 'frontend_gigafile_upload_handler');
add_action('wp_ajax_nopriv_frontend_gigafile_upload', 'frontend_gigafile_upload_handler');

function frontend_gigafile_upload_handler()
{
    // ノンスの検証
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'frontend_upload_action')) {
        wp_send_json_error(array('message' => esc_html__('セッションが切れたか、無効なリクエストです。再読み込みしてください。', 'fourier')), 403);
    }

    if (empty($_FILES['file'])) {
        wp_send_json_error(array('message' => esc_html__('ファイルが送信されていません。', 'fourier')), 400);
    }

    // 保存ディレクトリの取得・作成
    $upload_dir = wp_upload_dir();
    $giga_dir = $upload_dir['basedir'] . '/gigafile_uploads';
    if (!file_exists($giga_dir)) {
        wp_mkdir_p($giga_dir);
        // 直接アクセスを防ぐための .htaccess
        file_put_contents($giga_dir . '/.htaccess', "Order deny,allow\nDeny from all\n");
    }

    $file = $_FILES['file'];
    $original_name = sanitize_file_name($file['name']);
    
    // 一意のファイル名を生成
    $unique_filename = time() . '_' . rand(1000, 9999) . '_' . $original_name;
    $target_filepath = $giga_dir . '/' . $unique_filename;

    if (!move_uploaded_file($file['tmp_name'], $target_filepath)) {
        wp_send_json_error(array('message' => esc_html__('ファイルの保存に失敗しました。', 'fourier')), 500);
    }

    // 保持期限（日数）の計算
    $expiration_days = isset($_POST['expiration_days']) ? intval($_POST['expiration_days']) : 30; // デフォルト30日
    $expiration_timestamp = time() + ($expiration_days * 24 * 3600);

    // ダウンロードパスワードの設定
    $password_hash = '';
    if (!empty($_POST['download_password'])) {
        $password = sanitize_text_field($_POST['download_password']);
        $password_hash = wp_hash_password($password);
    }

    // メタデータJSONの作成
    $meta_data = array(
        'original_name'        => $file['name'],
        'expiration_timestamp' => $expiration_timestamp,
        'password_hash'        => $password_hash,
        'mime_type'            => $file['type']
    );

    $json_filepath = $target_filepath . '.json';
    file_put_contents($json_filepath, json_encode($meta_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // ファイル種別に応じたアイコンの決定
    $thumbnail_url = '';
    $mime_type = $file['type'];
    if (strpos($mime_type, 'image') !== false) {
        $thumbnail_url = 'data:image/svg+xml;charset=UTF-8,%3csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23C9A96E"%3e%3cpath d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 5H5l3.5-4.5z"/%3e%3c/svg%3e';
    } elseif (strpos($mime_type, 'video') !== false) {
        $thumbnail_url = 'data:image/svg+xml;charset=UTF-8,%3csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23C9A96E"%3e%3cpath d="M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z"/%3e%3c/svg%3e';
    } elseif ($mime_type === 'application/pdf') {
        $thumbnail_url = 'data:image/svg+xml;charset=UTF-8,%3csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23F87171"%3e%3cpath d="M20 2H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-8.5 7.5c0 .83-.67 1.5-1.5 1.5H9v2H7.5V7H10c.83 0 1.5.67 1.5 1.5v1zm5 2c0 .83-.67 1.5-1.5 1.5h-2.5V7H15c.83 0 1.5.67 1.5 1.5v3zm4-3H19v1h1.5V11H19v2h-1.5V7h3v1.5z"/%3e%3c/svg%3e';
    } else {
        $thumbnail_url = 'data:image/svg+xml;charset=UTF-8,%3csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23666666"%3e%3cpath d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/%3e%3c/svg%3e';
    }

    wp_send_json_success(array(
        'file_id'       => $unique_filename,
        'thumbnail'     => $thumbnail_url,
        'original_name' => $file['name'],
        'message'       => esc_html__('アップロードが正常に完了しました。', 'fourier')
    ));
}

/*--------------------------------------------------------------
  期限切れメディア自動削除のWP-Cron登録 (物理走査版)
--------------------------------------------------------------*/
if (!wp_next_scheduled('fourier_delete_expired_media')) {
    wp_schedule_event(time(), 'hourly', 'fourier_delete_expired_media');
}
add_action('fourier_delete_expired_media', 'fourier_delete_expired_media_handler');

function fourier_delete_expired_media_handler() {
    $upload_dir = wp_upload_dir();
    $giga_dir = $upload_dir['basedir'] . '/gigafile_uploads';
    if (!file_exists($giga_dir)) {
        return;
    }

    $current_time = time();
    $files = scandir($giga_dir);
    
    foreach ($files as $file) {
        if (substr($file, -5) === '.json') {
            $json_path = $giga_dir . '/' . $file;
            $meta_content = file_get_contents($json_path);
            if ($meta_content) {
                $meta_data = json_decode($meta_content, true);
                if (isset($meta_data['expiration_timestamp']) && $meta_data['expiration_timestamp'] <= $current_time) {
                    $body_file = substr($file, 0, -5);
                    $body_path = $giga_dir . '/' . $body_file;

                    if (file_exists($body_path)) {
                        @unlink($body_path);
                    }
                    @unlink($json_path);
                }
            }
        }
    }
}

/*--------------------------------------------------------------
  フロントエンド 複数ファイルをZIPにまとめるAJAXハンドラ (物理ファイル版)
--------------------------------------------------------------*/
add_action('wp_ajax_frontend_create_zip_archive', 'frontend_create_zip_archive_handler');
add_action('wp_ajax_nopriv_frontend_create_zip_archive', 'frontend_create_zip_archive_handler');

function frontend_create_zip_archive_handler()
{
    // ノンスの検証
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'frontend_upload_action')) {
        wp_send_json_error(array('message' => esc_html__('セッションが切れたか、無効なリクエストです。再読み込みしてください。', 'fourier')), 403);
    }

    if (empty($_POST['file_ids'])) {
        wp_send_json_error(array('message' => esc_html__('ファイルが選択されていません。', 'fourier')), 400);
    }

    $file_names = array_map('sanitize_file_name', explode(',', $_POST['file_ids']));
    $zip_name = isset($_POST['zip_name']) ? sanitize_file_name($_POST['zip_name']) : '';
    if (empty($zip_name)) {
        $zip_name = 'archive_' . time();
    }
    if (substr($zip_name, -4) !== '.zip') {
        $zip_name .= '.zip';
    }

    $upload_dir = wp_upload_dir();
    $giga_dir = $upload_dir['basedir'] . '/gigafile_uploads';
    if (!file_exists($giga_dir)) {
        wp_mkdir_p($giga_dir);
    }

    // ZIPファイル名の一意化
    $unique_zip_name = time() . '_' . rand(1000, 9999) . '_' . $zip_name;
    $zip_filepath = $giga_dir . '/' . $unique_zip_name;

    $zip = new ZipArchive();
    if ($zip->open($zip_filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        wp_send_json_error(array('message' => esc_html__('ZIPファイルの作成に失敗しました。', 'fourier')), 500);
    }

    $added_files_count = 0;
    foreach ($file_names as $filename) {
        $file_path = $giga_dir . '/' . $filename;
        $json_path = $file_path . '.json';
        
        if (file_exists($file_path) && file_exists($json_path)) {
            $json_data = json_decode(file_get_contents($json_path), true);
            $entry_name = isset($json_data['original_name']) ? sanitize_file_name($json_data['original_name']) : basename($file_path);
            
            if ($zip->locateName($entry_name) !== false) {
                $entry_name = pathinfo($entry_name, PATHINFO_FILENAME) . '_' . rand(100, 999) . '.' . pathinfo($entry_name, PATHINFO_EXTENSION);
            }
            $zip->addFile($file_path, $entry_name);
            $added_files_count++;
        }
    }

    $zip->close();

    if ($added_files_count === 0) {
        if (file_exists($zip_filepath)) {
            @unlink($zip_filepath);
        }
        wp_send_json_error(array('message' => esc_html__('有効なファイルがありませんでした。', 'fourier')), 400);
    }

    // 保持期限（日数）の保存
    $expiration_days = isset($_POST['expiration_days']) ? intval($_POST['expiration_days']) : 30;
    $expiration_timestamp = time() + ($expiration_days * 24 * 3600);

    // ダウンロードパスワードの保存
    $password_hash = '';
    if (!empty($_POST['download_password'])) {
        $password = sanitize_text_field($_POST['download_password']);
        $password_hash = wp_hash_password($password);
    }

    // メタデータJSONの作成
    $meta_data = array(
        'original_name'        => $zip_name,
        'expiration_timestamp' => $expiration_timestamp,
        'password_hash'        => $password_hash,
        'mime_type'            => 'application/zip'
    );

    $json_filepath = $zip_filepath . '.json';
    file_put_contents($json_filepath, json_encode($meta_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $thumbnail_url = 'data:image/svg+xml;charset=UTF-8,%3csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23C9A96E"%3e%3cpath d="M20 6h-8l-2-2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm-6 10h-4v-2h4v2zm0-4h-4V10h4v2z"/%3e%3c/svg%3e';

    wp_send_json_success(array(
        'file_id'       => $unique_zip_name,
        'thumbnail'     => $thumbnail_url,
        'original_name' => $zip_name,
        'message'       => esc_html__('ZIPにまとめました。', 'fourier')
    ));
}

/*--------------------------------------------------------------
  WordPress 6.4以降で添付ファイル詳細ページを強制的に有効化する処理
--------------------------------------------------------------*/
function fourier_enable_attachment_pages() {
    // オプション値が 1 でない場合は 1 (有効) に更新
    if (get_option('wp_attachment_pages_enabled') !== '1') {
        update_option('wp_attachment_pages_enabled', 1);
    }
}
add_action('after_setup_theme', 'fourier_enable_attachment_pages');

/*--------------------------------------------------------------
  テキストベース学習データ登録用AJAXハンドラ
--------------------------------------------------------------*/
add_action('wp_ajax_frontend_learning_data_upload', 'frontend_learning_data_upload_handler');
add_action('wp_ajax_nopriv_frontend_learning_data_upload', 'frontend_learning_data_upload_handler');

function frontend_learning_data_upload_handler()
{
    // ノンスの検証
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'learning_data_action')) {
        wp_send_json_error(array('message' => esc_html__('セッションが切れたか、無効なリクエストです。再読み込みしてください。', 'fourier')), 403);
    }

    $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
    $json_data_str = isset($_POST['json_data']) ? wp_unslash($_POST['json_data']) : '';

    if (empty($title) || empty($json_data_str)) {
        wp_send_json_error(array('message' => esc_html__('タイトルまたはデータが入力されていません。', 'fourier')), 400);
    }

    // JSONとして妥当かチェック
    $decoded = json_decode($json_data_str);
    if (json_last_error() !== JSON_ERROR_NONE) {
        wp_send_json_error(array('message' => esc_html__('無効なJSONデータです。', 'fourier')), 400);
    }

    // 投稿の挿入
    $post_data = array(
        'post_title'   => $title,
        'post_content' => wp_slash($json_data_str), // DB保存用にエスケープ
        'post_status'  => 'publish',
        'post_type'    => 'post'
    );

    $post_id = wp_insert_post($post_data);

    if (is_wp_error($post_id) || $post_id == 0) {
        wp_send_json_error(array('message' => esc_html__('データの保存に失敗しました。', 'fourier')), 500);
    }

    // 学習データであることを示すカスタムメタデータを付与
    update_post_meta($post_id, 'is_learning_data', '1');

    // 追加のメタデータを保存するフックを発火
    do_action('frontend_learning_data_after_save', $post_id, $_POST);

    wp_send_json_success(array(
        'post_id' => $post_id,
        'message' => esc_html__('データが正常に登録されました。', 'fourier')
    ));
}

/*--------------------------------------------------------------
  テキストベース学習データ検索用AJAXハンドラ
--------------------------------------------------------------*/
add_action('wp_ajax_frontend_learning_data_search', 'frontend_learning_data_search_handler');
add_action('wp_ajax_nopriv_frontend_learning_data_search', 'frontend_learning_data_search_handler');

function frontend_learning_data_search_handler()
{
    // ノンスの検証
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'learning_data_action')) {
        wp_send_json_error(array('message' => esc_html__('セッションが切れたか、無効なリクエストです。再読み込みしてください。', 'fourier')), 403);
    }

    $keyword = isset($_POST['keyword']) ? sanitize_text_field($_POST['keyword']) : '';

    $args = array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        's'              => $keyword,
        'meta_query'     => array(
            array(
                'key'   => 'is_learning_data',
                'value' => '1',
            )
        )
    );

    $query = new WP_Query($args);
    $posts_data = array();

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $posts_data[] = array(
                'ID'           => get_the_ID(),
                'post_title'   => get_the_title(),
                'post_content' => get_the_content(), // 中身はJSON文字列
            );
        }
    }
    wp_reset_postdata();

    wp_send_json_success(array(
        'posts'   => $posts_data,
        'message' => esc_html__('検索が完了しました。', 'fourier')
    ));
}

/*--------------------------------------------------------------
  フロントエンド アカウント＆データ削除リクエスト用AJAXハンドラ
--------------------------------------------------------------*/
add_action('wp_ajax_frontend_delete_account', 'frontend_delete_account_handler');
function frontend_delete_account_handler() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'frontend_delete_action')) {
        wp_send_json_error(array('message' => esc_html__('無効なリクエストです。', 'fourier')));
    }

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => esc_html__('ログインしていません。', 'fourier')));
    }

    $user_id = get_current_user_id();
    $user = wp_get_current_user();
    
    // 管理者の場合は削除できないようにする（安全のため）
    if (in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(array('message' => esc_html__('管理者アカウントは削除できません。', 'fourier')));
    }

    require_once(ABSPATH . 'wp-admin/includes/user.php');

    // ユーザーに紐づくデータを削除
    $user_posts = get_posts(array(
        'author' => $user_id,
        'post_type' => 'any',
        'posts_per_page' => -1,
        'post_status' => 'any'
    ));
    foreach ($user_posts as $post) {
        wp_delete_post($post->ID, true);
    }

    $deleted = wp_delete_user($user_id);
    if ($deleted) {
        wp_logout();
        wp_send_json_success(array('message' => esc_html__('アカウントとデータを削除しました。', 'fourier')));
    } else {
        wp_send_json_error(array('message' => esc_html__('アカウントの削除に失敗しました。', 'fourier')));
    }
}

require_once get_template_directory() . '/inc/functions_wiki_dump.php';
