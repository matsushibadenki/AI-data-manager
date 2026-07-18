<?php
require_once('/Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-load.php');

$args = array(
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'meta_query'     => array(
        array(
            'key'   => 'is_learning_data',
            'value' => '1',
        )
    )
);

$query = new WP_Query($args);
$fixed_count = 0;

if ($query->have_posts()) {
    while ($query->have_posts()) {
        $query->the_post();
        $post_id = get_the_ID();
        
        $current_format_meta = get_post_meta($post_id, 'learning_format', true);
        if (empty($current_format_meta)) {
            $content = get_the_content();
            $decoded = json_decode($content, true);
            if ($decoded && isset($decoded['format'])) {
                update_post_meta($post_id, 'learning_format', sanitize_text_field($decoded['format']));
                $fixed_count++;
            }
        }
    }
}
wp_reset_postdata();

echo "Fixed $fixed_count posts.";
