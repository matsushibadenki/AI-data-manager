<?php
/** Owner-scoped, server-side queue browsing. Never dispatches jobs. */
if (!defined('ABSPATH')) exit;

function fourier_pipeline_queue_snapshot($ids, $user_id) {
    $result = [];
    foreach (array_slice(array_unique(array_map('absint', $ids)), 0, 20) as $id) {
        $post = get_post($id);
        if (!$post || $post->post_type !== 'post' || (int) $post->post_author !== (int) $user_id || $user_id < 1 || !in_array($post->post_status, ['pending', 'publish'], true)) continue;
        $updated = get_post_meta($id, '_fourier_pipeline_updated', true);
        $date = $updated ? date_create_immutable($updated, wp_timezone()) : false;
        $result[] = ['id' => $id, 'status' => get_post_meta($id, '_fourier_pipeline_status', true),
            'stage' => get_post_meta($id, '_fourier_pipeline_stage', true), 'updated' => $updated,
            'age_minutes' => $date ? (int) floor(max(0, time() - $date->getTimestamp()) / 60) : null];
    }
    return $result;
}

add_action('wp_ajax_fourier_pipeline_queue_snapshot', function () {
    check_ajax_referer('learning_data_action', 'nonce');
    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids) || count($ids) > 20 || array_filter($ids, 'is_array')) wp_send_json_error(['message' => 'Invalid IDs'], 400);
    wp_send_json_success(['items' => fourier_pipeline_queue_snapshot($ids, get_current_user_id()), 'checked_at' => current_time('mysql')]);
});

function fourier_pipeline_queue_query($user_id, $search = '', $status = 'all', $page = 1) {
    $statuses = ['queued', 'processing', 'review', 'error', 'approved', 'rejected'];
    $status = in_array($status, $statuses, true) ? $status : 'all';
    $search = mb_substr(sanitize_text_field($search), 0, 200);
    $args = ['post_type' => 'post', 'post_status' => ['pending', 'publish'],
        'author' => max(1, (int) $user_id), 'posts_per_page' => 20,
        'paged' => max(1, min(100000, (int) $page)), 'orderby' => ['modified' => 'DESC', 'ID' => 'DESC'],
        'meta_query' => [['key' => '_fourier_pipeline_stage', 'compare' => 'EXISTS']],
        'fourier_queue_search' => $search, 'fourier_queue_query' => true];
    // An anonymous caller must never fall back to an unrestricted author query.
    if ((int) $user_id < 1) $args['post__in'] = [0];
    if ($status !== 'all') $args['meta_query'][] = ['key' => '_fourier_pipeline_status', 'value' => $status];
    return new WP_Query($args);
}

add_filter('posts_where', function ($where, $query) {
    global $wpdb;
    if (!$query->get('fourier_queue_query') || !$query->get('fourier_queue_search')) return $where;
    $like = '%' . $wpdb->esc_like($query->get('fourier_queue_search')) . '%';
    return $where . $wpdb->prepare(" AND ({$wpdb->posts}.post_title LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} queue_source WHERE queue_source.post_id = {$wpdb->posts}.ID AND queue_source.meta_key IN ('_fourier_pipeline_url', '_fourier_pipeline_concept') AND queue_source.meta_value LIKE %s))", $like, $like);
}, 10, 2);
