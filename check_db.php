<?php
require_once('../../../wp-load.php');
global $wpdb;
$results = $wpdb->get_results("SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' AND post_content LIKE '%chatml%' ORDER BY ID DESC LIMIT 3", ARRAY_A);
foreach ($results as $r) {
    echo "ID: " . $r['ID'] . "\n";
    echo "Title: " . $r['post_title'] . "\n";
    echo "Content: \n" . $r['post_content'] . "\n\n";
}
