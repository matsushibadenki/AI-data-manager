<?php
/*--------------------------------------------------------------
  必要なもの追加
--------------------------------------------------------------*/

/*--------------------------------------------------------------
  すべてのフィードを出力する
--------------------------------------------------------------*/

add_theme_support('automatic-feed-links');

/*--------------------------------------------------------------
  SEOpress設定
--------------------------------------------------------------*/

add_action('after_setup_theme', function() {
    add_theme_support('title-tag');
});

/*--------------------------------------------------------------
  OGP設定
--------------------------------------------------------------*/

function my_meta_ogp() {
    if (is_front_page() || is_home() || is_singular()) {

        /* 初期設定 */
        $ogp_image = 'https://ts-ikusya.com/screenshot.png';
        $twitter_site = '';
        $twitter_card = '';
        $facebook_app_id = '';

        global $post;
        $ogp_title = '';
        $ogp_description = '';
        $ogp_url = '';

        if (is_singular()) {
            setup_postdata($post);
            $ogp_title = $post->post_title;
            $ogp_description = mb_substr(get_the_excerpt(), 0, 100);
            $ogp_url = get_permalink();
            wp_reset_postdata();
        } elseif (is_front_page() || is_home()) {
            $ogp_title = get_bloginfo('name');
            $ogp_description = get_bloginfo('description');
            $ogp_url = home_url();
        }

        $ogp_type = (is_front_page() || is_home()) ? 'website' : 'article';

        if (is_singular() && has_post_thumbnail()) {
            $ps_thumb = wp_get_attachment_image_src(get_post_thumbnail_id(), 'full');
            $ogp_image = $ps_thumb[0];
        }

        $html = "\n";
        //$html .= '<meta property="og:image" content="' . esc_url($ogp_image) . '">' . "\n";

        if ($facebook_app_id !== "") {
            $html .= '<meta property="fb:app_id" content="' . $facebook_app_id . '">' . "\n";
        }

        echo $html;
    }
}
add_action('wp_head', 'my_meta_ogp');

/*--------------------------------------------------------------
  TinyMCEの自動整形を無効化する
--------------------------------------------------------------*/

add_filter('tiny_mce_before_init', function($init) {
    $init['verify_html'] = false; // HTMLの整形を無効化
    return $init;
});

/*--------------------------------------------------------------
  Javascriptでテーマファイルのパスを取得するためにphpスクリプトを書き込む
--------------------------------------------------------------*/

add_action('wp_head', function() {
    echo '<script type="text/javascript">
    var themeDirectoryUrl = "' . get_template_directory_uri() . '";
    </script>';
});


/*--------------------------------------------------------------
  ログイン画面にロゴを表示
--------------------------------------------------------------*/

add_action('login_enqueue_scripts', function() {
    echo '
    <style type="text/css">
        #login {width:200px;}
        #login h1 a {
            display: block;
            background-repeat: no-repeat;
            background-size: 100%;
            background-image: url(' . get_template_directory_uri() . '/assets/img/logo_tokushiikusya_main.svg);
            width: 70%;
            height:200px;
        }
    </style>
    ';
});

/*--------------------------------------------------------------
  ログイン画面にロゴのリンク先変更
--------------------------------------------------------------*/

add_filter('login_headerurl', function() {
    return get_bloginfo('url');
});

/*--------------------------------------------------------------
  AVIF画像ファイル対応
--------------------------------------------------------------*/

// AVIFファイルの拡張子とMIMEタイプを追加
add_filter('upload_mimes', 'add_avif_to_upload_mimes');
function add_avif_to_upload_mimes($mimes)
{
  $mimes['avif'] = 'image/avif';
  return $mimes;
}
// AVIFファイルのアップロードを許可
add_filter('wp_check_filetype_and_ext', 'wpse_file_and_ext_avif', 10, 4);
function wpse_file_and_ext_avif($types, $file, $filename, $mimes)
{
  if (false !== strpos($filename, '.avif')) {
    $types['ext'] = 'avif';
    $types['type'] = 'image/avif';
  }
  return $types;
}

/*--------------------------------------------------------------
 WebP画像ファイル対応
--------------------------------------------------------------*/

//WebPファイルの拡張子とMIMEタイプを追加
add_filter('upload_mimes', 'add_webp_to_upload_mimes');
function add_webp_to_upload_mimes($mimes)
{
  $mimes['webp'] = 'image/webp';
  return $mimes;
}
//WebPファイルのアップロードを許可
add_filter('wp_check_filetype_and_ext', 'wpse_file_and_ext_webp', 10, 4);
function wpse_file_and_ext_webp($types, $file, $filename, $mimes)
{
  if (false !== strpos($filename, '.webp')) {
    $types['ext'] = 'webp';
    $types['type'] = 'image/webp';
  }
  return $types;
}


?>