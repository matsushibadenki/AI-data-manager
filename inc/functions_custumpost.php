<?php

/*--------------------------------------------------------------
  カスタム投稿タイプおよびデフォルトカテゴリーの定義
--------------------------------------------------------------*/
function create_custom_post_types_and_defaults() {
    // カスタム投稿タイプの定義
    $post_types = array(
        'news' => array(
            'name'           => 'お知らせ',
            'singular_name'  => 'お知らせ',
            'slug'           => 'news',
            'default_cat_id' => 2, // 'news' のデフォルトカテゴリーID
        ),
        'blog' => array(
            'name'           => 'コラム',
            'singular_name'  => 'コラム',
            'slug'           => 'blog',
            'default_cat_id' => 4, // 'blog' のデフォルトカテゴリーID
        ),
        // 追加の投稿タイプをここに追加可能
        /*
        'example' => array(
            'name'           => '例',
            'singular_name'  => '例',
            'slug'           => 'example',
            'default_cat_id' => 5, // 'example' のデフォルトカテゴリーID
        ),
        */
    );

    foreach ($post_types as $type => $args) {
        register_post_type($type, array(
            'labels' => array(
                'name'          => $args['name'],
                'singular_name' => $args['singular_name'],
            ),
            'public'      => true,
            'has_archive' => true,
            'rewrite'     => array('slug' => $args['slug'], 'with_front' => false),
            'supports'    => array('title', 'editor', 'thumbnail'),
            'taxonomies'  => array('category', 'post_tag'),
        ));
    }

    // デフォルトカテゴリーの定義（投稿タイプごとに設定）
    $GLOBALS['custom_default_categories'] = array();

    foreach ($post_types as $type => $args) {
        $GLOBALS['custom_default_categories'][$type] = $args['default_cat_id'];
    }
}
add_action('init', 'create_custom_post_types_and_defaults');

/*--------------------------------------------------------------
  プラグイン有効化時にリライトルールをフラッシュ
--------------------------------------------------------------*/
function flush_rewrite_rules_on_activation() {
    create_custom_post_types_and_defaults();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'flush_rewrite_rules_on_activation');

/*--------------------------------------------------------------
  デフォルトカテゴリを設定
--------------------------------------------------------------*/
function set_default_category($post_id) {
    // 投稿の自動保存やリビジョン保存時は処理をスキップ
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // 投稿タイプを取得
    $post_type = get_post_type($post_id);

    // カスタム投稿タイプのデフォルトカテゴリー設定があるか確認
    if (isset($GLOBALS['custom_default_categories'][$post_type])) {
        $default_category_id = $GLOBALS['custom_default_categories'][$post_type];
    } else {
        // デフォルトのデフォルトカテゴリー
        $default_category_id = 1; // 'uncategorized' のID
    }

    // 現在のカテゴリが設定されていない場合のみデフォルトカテゴリを設定
    if (!has_term('', 'category', $post_id)) {
        wp_set_post_terms($post_id, array($default_category_id), 'category');
    }
}
add_action('save_post', 'set_default_category');

/*--------------------------------------------------------------
  リライトルールの統合（複数のカスタム投稿タイプに対応）
--------------------------------------------------------------*/
function custom_rewrite_rules($rules) {
    $new_rules = array();
    $post_types = array(
        'news' => 'news',
        'blog' => 'blog',
        // 追加の投稿タイプをここに追加可能
        /*
        'example' => 'example',
        */
    );

    foreach ($post_types as $type => $slug) {
        // ページング
        $new_rules["$slug/page/([0-9]+)/?$"] = "index.php?post_type=$type&paged=\$matches[1]";
        // 個別投稿
        $new_rules["$slug/([^/]+)/?$"] = "index.php?post_type=$type&name=\$matches[1]";
        // カテゴリーアーカイブ（ページ付き）
        $new_rules["$slug/category/([^/]+)/page/([0-9]+)/?$"] = "index.php?category_name=\$matches[1]&post_type=$type&paged=\$matches[2]";
        // カテゴリーアーカイブ
        $new_rules["$slug/category/([^/]+)/?$"] = "index.php?category_name=\$matches[1]&post_type=$type";
        // タグアーカイブ（ページ付き）
        $new_rules["$slug/tag/([^/]+)/page/([0-9]+)/?$"] = "index.php?tag=\$matches[1]&post_type=$type&paged=\$matches[2]";
        // タグアーカイブ
        $new_rules["$slug/tag/([^/]+)/?$"] = "index.php?tag=\$matches[1]&post_type=$type";
    }

    return $new_rules + $rules;
}
add_filter('rewrite_rules_array', 'custom_rewrite_rules');

/*--------------------------------------------------------------
  クエリのカスタマイズ
--------------------------------------------------------------*/
function custom_pre_get_posts($query) {
    if (!is_admin() && $query->is_main_query()) {
        if (is_category() || is_tag() || is_post_type_archive(array('news', 'blog'))) {
            $query->set('posts_per_page', 8);
        }
    }
}
add_action('pre_get_posts', 'custom_pre_get_posts');

/*--------------------------------------------------------------
  パーマリンク変更
--------------------------------------------------------------*/
function auto_post_slug($slug, $post_ID, $post_status, $post_type) {
    if (preg_match('/(%[0-9a-f]{2})+/', $slug)) {
        $slug = sanitize_title($post_type) . '-' . $post_ID;
    }
    return $slug;
}
add_filter('wp_unique_post_slug', 'auto_post_slug', 10, 4);

/*--------------------------------------------------------------
  カスタムカテゴリーリンクを生成する関数
--------------------------------------------------------------*/
function custom_the_category($separator = ', ') {
    $categories = get_the_category();
    $output = '';

    if ($categories) {
        foreach ($categories as $category) {
            $category_link = get_category_link($category->term_id);
            $category_name = esc_html($category->name);
            $output .= '<a href="' . esc_url($category_link) . '">' . $category_name . '</a>' . $separator;
        }
        echo trim($output, $separator);
    }
}

/*--------------------------------------------------------------
  カスタム投稿の記事で非公開のものに「非公開」と表示させる
--------------------------------------------------------------*/
function custom_private_title_format($title, $id) {
    $post = get_post($id);
    if ($post && $post->post_status === 'private') {
        $title = '<span class="private-title">非公開中</span> ' . esc_html($post->post_title);
    }
    return $title;
}
add_filter('the_title', 'custom_private_title_format', 10, 2);

?>
