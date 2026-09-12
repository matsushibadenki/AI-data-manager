<?php
/** Integration test: temporary posts only, no LLM or scheduled events. */
require dirname(__DIR__, 6) . '/wp-load.php';
$ids = [];
$term = 'queue-test-' . wp_generate_uuid4();
function queue_check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
try {
    for ($i = 0; $i < 23; $i++) {
        $id = wp_insert_post(['post_type' => 'post', 'post_status' => 'pending', 'post_author' => 1, 'post_title' => "$term $i"], true);
        if (is_wp_error($id)) throw new RuntimeException('Fixture creation failed');
        $ids[] = $id;
        update_post_meta($id, '_fourier_pipeline_stage', 'review');
        update_post_meta($id, '_fourier_pipeline_status', $i % 2 ? 'review' : 'error');
        update_post_meta($id, '_fourier_pipeline_url', "https://example.com/$term/source");
    }
    $first = fourier_pipeline_queue_query(1, $term, 'all', 1);
    $second = fourier_pipeline_queue_query(1, $term, 'all', 2);
    queue_check((int) $first->found_posts === 23 && count($first->posts) === 20, 'First page/count');
    queue_check(count($second->posts) === 3, 'Second page');
    queue_check(!array_intersect(wp_list_pluck($first->posts, 'ID'), wp_list_pluck($second->posts, 'ID')), 'No repeated IDs');
    queue_check((int) fourier_pipeline_queue_query(1, $term, 'error')->found_posts === 12, 'Status count');
    queue_check((int) fourier_pipeline_queue_query(1, "$term/source")->found_posts === 23, 'Source search');
    queue_check((int) fourier_pipeline_queue_query(0, $term)->found_posts === 0, 'Anonymous denied');
    queue_check((int) fourier_pipeline_queue_query(999999, $term)->found_posts === 0, 'Other owner excluded');
    queue_check((int) fourier_pipeline_queue_query(1, "$term%'")->found_posts === 0, 'Literal SQL wildcard');
    queue_check(count(fourier_pipeline_queue_snapshot($ids, 1)) === 20, 'Snapshot bounded');
    queue_check(count(fourier_pipeline_queue_snapshot($ids, 999999)) === 0, 'Snapshot owner');
    queue_check(count(fourier_pipeline_queue_snapshot($ids, 0)) === 0, 'Snapshot anonymous');
    echo "PASS: 11 queue integration checks.\n";
} finally { foreach ($ids as $id) wp_delete_post($id, true); }
