<?php

/*--------------------------------------------------------------
  サムネイル生成
--------------------------------------------------------------*/

add_theme_support('post-thumbnails');
add_image_size('custom-thumbnail', 200, 200, ['top', 'center']);

function set_custom_thumbnail($post_id) {
    if (has_post_thumbnail($post_id)) return;

    $content = get_post_field('post_content', $post_id);
    preg_match_all('/<img.+src=[\'"]([^\'"]+)[\'"].*>/i', $content, $matches);

    if (!empty($matches[1])) {
        $attach_id = attachment_url_to_postid($matches[1][0]);
        if ($attach_id) set_post_thumbnail($post_id, $attach_id);
    }
}
add_action('save_post', 'set_custom_thumbnail');


/*--------------------------------------------------------------
  ウィジェット処理
--------------------------------------------------------------*/

function theme_widgets_init() {
    register_sidebar([
        'name' => 'メインサイドバー',
        'id' => 'main-sidebar',
        'before_widget' => '<div class="widget">',
        'after_widget' => '</div>',
        'before_title' => '<h2>',
        'after_title' => '</h2>',
    ]);
}
add_action('widgets_init', 'theme_widgets_init');

?>