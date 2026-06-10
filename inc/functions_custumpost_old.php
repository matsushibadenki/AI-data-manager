<?php

/*--------------------------------------------------------------
  カスタム投稿タイプの定義
--------------------------------------------------------------*/
function create_custom_post_types()
{
  $post_types = array(
    'news' => array(
      'name' => 'お知らせ',
      'singular_name' => 'お知らせ',
      'slug' => 'news'
    ),
    'blog' => array(
      'name' => 'コラム',
      'singular_name' => 'コラム',
      'slug' => 'blog'
    )
  );

  foreach ($post_types as $type => $args) {
    register_post_type($type, array(
      'labels' => array(
        'name' => $args['name'],
        'singular_name' => $args['singular_name']
      ),
      'public' => true,
      'has_archive' => true,
      'rewrite' => array('slug' => $args['slug'], 'with_front' => false),
      'supports' => array('title', 'editor', 'thumbnail'),
      'taxonomies' => array('category', 'post_tag'),
    ));
  }
}
add_action('init', 'create_custom_post_types');

/*--------------------------------------------------------------
  プラグイン有効化時にリライトルールをフラッシュ
--------------------------------------------------------------*/
function flush_rewrite_rules_on_activation()
{
  create_custom_post_types();
  flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'flush_rewrite_rules_on_activation');

/*--------------------------------------------------------------
  デフォルトカテゴリを設定
--------------------------------------------------------------*/
function set_default_category($post_id)
{
  remove_action('save_post', 'set_default_category');

  if (get_post_type($post_id) == 'news') {
    $default_category_id = 2;
    wp_set_post_terms($post_id, array($default_category_id), 'category');
  }

  add_action('save_post', 'set_default_category');
}
add_action('save_post', 'set_default_category');

/*--------------------------------------------------------------
  カスタム投稿タイプのリライトルールを追加
--------------------------------------------------------------*/
function custom_post_type_rewrite_rules($rules)
{
  $new_rules = array(
    'news/page/([0-9]+)/?$' => 'index.php?post_type=news&paged=$matches[1]',
    'news/([^/]+)/?$' => 'index.php?post_type=news&name=$matches[1]',
    'news/category/([^/]+)/?$' => 'index.php?post_type=news&news_category=$matches[1]',
    'news/tag/([^/]+)/page/([0-9]+)/?$' => 'index.php?post_type=news&news_tag=$matches[1]&paged=$matches[2]',
    'news/tag/([^/]+)/?$' => 'index.php?post_type=news&news_tag=$matches[1]',
  );
  return $new_rules + $rules;
}
add_filter('rewrite_rules_array', 'custom_post_type_rewrite_rules');

/*--------------------------------------------------------------
  クエリのカスタマイズ
--------------------------------------------------------------*/
function custom_pre_get_posts($query)
{
  if (!is_admin() && $query->is_main_query()) {
    if (is_category() || is_tag() || is_post_type_archive(array('news', 'blog'))) {
      $query->set('posts_per_page', 8);
    }
  }
}
add_action('pre_get_posts', 'custom_pre_get_posts');

/*--------------------------------------------------------------
  カスタムカテゴリのリライトルールを追加
--------------------------------------------------------------*/
function custom_category_rewrite()
{
  add_rewrite_rule(
    '^([^/]+)/page/([0-9]+)/?$',
    'index.php?category_name=$matches[1]&paged=$matches[2]',
    'top'
  );
}
add_action('init', 'custom_category_rewrite');

/*--------------------------------------------------------------
  カスタムタクソノミーのリライトルールを追加
--------------------------------------------------------------*/
function custom_taxonomy_rewrite_rules($rules)
{
  $new_rules = array(
    'category/news/([^/]+)/page/([0-9]+)/?$' => 'index.php?news_category=$matches[1]&paged=$matches[2]',
    'category/news/([^/]+)/?$' => 'index.php?news_category=$matches[1]',
    'tag/news/([^/]+)/page/([0-9]+)/?$' => 'index.php?news_tag=$matches[1]&paged=$matches[2]',
    'tag/news/([^/]+)/?$' => 'index.php?news_tag=$matches[1]',
  );
  return $new_rules + $rules;
}
add_filter('rewrite_rules_array', 'custom_taxonomy_rewrite_rules');

/*--------------------------------------------------------------
  パーマリンク変更
--------------------------------------------------------------*/
function auto_post_slug($slug, $post_ID, $post_status, $post_type)
{
  if (preg_match('/(%[0-9a-f]{2})+/', $slug)) {
    $slug = utf8_uri_encode($post_type) . '-' . $post_ID;
  }
  return $slug;
}
add_filter('wp_unique_post_slug', 'auto_post_slug', 10, 4);

/*--------------------------------------------------------------
  カスタムカテゴリーリンクを生成する関数
--------------------------------------------------------------*/
function custom_the_category($separator = ', ')
{
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
function custom_private_title_format($title, $id)
{
  $post = get_post($id);
  if ($post && $post->post_status === 'private') {
    $title = str_replace('非公開: ', '', $title);
    $title = '<span class="private-title">非公開中</span> ' . $title;
  }
  return $title;
}
add_filter('the_title', 'custom_private_title_format', 10, 2);

?>