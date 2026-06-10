<?php
/*--------------------------------------------------------------
  不要なもの削除
--------------------------------------------------------------*/

/*--------------------------------------------------------------
  ツールバーを表示させない
--------------------------------------------------------------*/

add_filter('show_admin_bar', '__return_false');

/*--------------------------------------------------------------
  キャッチフレーズをtitleタグ内から除去
--------------------------------------------------------------*/

add_filter('document_title_parts', function($title) {
    unset($title['tagline']);
    return $title;
});

/*--------------------------------------------------------------
  emojiを排除
--------------------------------------------------------------*/

function disable_emojis() {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
}
add_action('init', 'disable_emojis');
add_filter('emoji_svg_url', '__return_false');

/*--------------------------------------------------------------
  global-styles-inline-cssを排除 
--------------------------------------------------------------*/

function remove_my_global_styles() {
    wp_dequeue_style('global-styles');
}
add_action('wp_enqueue_scripts', 'remove_my_global_styles');

/*--------------------------------------------------------------
  Gutenberg用CSSを削除
--------------------------------------------------------------*/

function remove_block_library_style() {
    wp_dequeue_style('wp-block-library');
    wp_dequeue_style('wp-block-library-theme');
}
add_action('wp_enqueue_scripts', 'remove_block_library_style');

/*--------------------------------------------------------------
  デフォルトのウェブフォントを停止
--------------------------------------------------------------*/

function mytheme_dequeue_fonts() {
    wp_dequeue_style('twentytwelve-fonts');
}
add_action('wp_enqueue_scripts', 'mytheme_dequeue_fonts', 11);

/*--------------------------------------------------------------
  classic-theme-stylesを排除
--------------------------------------------------------------*/

function remove_classic_theme_style() {
    wp_dequeue_style('classic-theme-styles');
}
add_action('wp_enqueue_scripts', 'remove_classic_theme_style');

/*--------------------------------------------------------------
  global-styles-inline-cssを正しく排除
--------------------------------------------------------------*/

function remove_global_styles_inline() {
    wp_dequeue_style('global-styles-inline');
}
add_action('wp_enqueue_scripts', 'remove_global_styles_inline');

/*--------------------------------------------------------------
  DNS prefetchを削除
--------------------------------------------------------------*/

add_filter('wp_resource_hints', function($hints, $relation_type) {
    return 'dns-prefetch' === $relation_type ? array_diff(wp_dependencies_unique_hosts(), $hints) : $hints;
}, 10, 2);

/*--------------------------------------------------------------
  インラインスタイル削除
--------------------------------------------------------------*/

function remove_recent_comments_style() {
    global $wp_widget_factory;
    remove_action('wp_head', [$wp_widget_factory->widgets['WP_Widget_Recent_Comments'], 'recent_comments_style']);
}
add_action('widgets_init', 'remove_recent_comments_style');

/*--------------------------------------------------------------
  WP-APIの不要な項目を削除
--------------------------------------------------------------*/

add_filter('rest_prepare_post', function ($response) {
    unset($response->data['author'], $response->data['featured_media']);
    return $response;
}, 10, 3);

/*--------------------------------------------------------------
  WordpressPopularPostsのスタイルを削除
--------------------------------------------------------------*/

function remove_widget_action() {
    global $wp_widget_factory;
    if (isset($wp_widget_factory->widgets['WordpressPopularPosts'])) {
        remove_action('wp_head', [$wp_widget_factory->widgets['WordpressPopularPosts'], 'wpp_print_stylesheet']);
    }
}
add_action('init', 'remove_widget_action'); 

/*--------------------------------------------------------------
  諸々を削除
--------------------------------------------------------------*/

remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wp_shortlink_wp_head', 10, 0);
remove_action('wp_head', 'rest_output_link_wp_head');
remove_action('wp_head', 'wp_oembed_add_host_js');
remove_action('wp_head', 'wp_oembed_add_discovery_links');
remove_action('wp_head', 'rel_canonical');
remove_action('wp_head', 'wp_generator');
remove_action('wp_head', 'index_rel_link');
remove_action('wp_head', 'parent_post_rel_link', 10);
remove_action('wp_head', 'start_post_rel_link', 10);
remove_action('wp_head', 'adjacent_posts_rel_link_wp_head', 10);
?>
