<?php
/** WordPress integration: locks and telemetry, no external network or model calls. */
require dirname(__DIR__, 6) . '/wp-load.php';
$id = wp_insert_post(['post_title' => 'Runtime integration ' . wp_generate_uuid4(), 'post_type' => 'post', 'post_status' => 'pending', 'post_author' => 1], true);
if (is_wp_error($id)) throw new RuntimeException('Cannot create fixture.');
$checks = 0;
function runtime_check($condition, $label) { global $checks; $checks++; if (!$condition) throw new RuntimeException($label); }
$mock = static function ($pre, $args, $url) {
    if (strpos($url, '/api/chat') === false) return new WP_Error('test_network_blocked', 'External request blocked by test.');
    return ['headers' => [], 'body' => wp_json_encode(['message' => ['content' => '{"concept":"water","definition":"test","branches":[]}']]), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
};
try {
    update_post_meta($id, '_fourier_pipeline_status', 'queued');
    update_post_meta($id, '_fourier_pipeline_stage', 'concept_map');
    $lease = fourier_pipeline_runtime_acquire($id);
    runtime_check((bool) $lease, 'Acquire lease');
    runtime_check(fourier_pipeline_runtime_acquire($id) === false, 'Active lease blocks duplicate');
    runtime_check(!fourier_pipeline_runtime_release($id, ['token' => 'wrong']), 'Wrong token cannot release');
    runtime_check(fourier_pipeline_runtime_release($id, $lease), 'Correct release');
    $expired = ['token' => 'expired', 'started' => time() - 2200, 'expires' => time() - 1];
    add_option(fourier_pipeline_runtime_lock_key($id), $expired, '', false);
    $replacement = fourier_pipeline_runtime_acquire($id);
    runtime_check((bool) $replacement && $replacement['token'] !== 'expired', 'Queued job reclaims expired lease');
    runtime_check(!fourier_pipeline_runtime_release($id, $expired), 'Old lease cannot remove replacement');
    fourier_pipeline_runtime_release($id, $replacement);
    $calls = 0;
    fourier_pipeline_run_guarded($id, function ($id) use (&$calls) {
        $calls++;
        // Re-entry must be blocked even before the callback updates its status.
        fourier_pipeline_run_guarded($id, function () use (&$calls) { $calls++; });
        fourier_pipeline_update($id, 'concept_map', 'processing');
        $value = fourier_pipeline_runtime_llm($id, 'ollama', function () use ($id) {
            runtime_check(fourier_pipeline_runtime_snapshot($id)['state'] === 'llm_wait', 'Waiting checkpoint visible during request');
            return '{"ok":true}';
        });
        runtime_check($value === '{"ok":true}', 'Result unchanged');
        fourier_pipeline_update($id, 'branch_questions', 'queued');
    });
    runtime_check($calls === 1, 'One execution on re-entry');
    runtime_check((bool) wp_next_scheduled('fourier_pipeline_worker', [(int) $id]), 'Queued event is deferred while previous lease is held');
    $snapshot = fourier_pipeline_runtime_snapshot($id);
    runtime_check($snapshot['state'] === 'finished' && $snapshot['llm_state'] === 'received' && is_numeric($snapshot['llm_seconds']), 'Completion timing');
    runtime_check(!get_option(fourier_pipeline_runtime_lock_key($id)), 'No lock after completion');
    fourier_pipeline_run_guarded($id, function ($id) {
        fourier_pipeline_runtime_llm($id, 'ollama', function () { throw new RuntimeException('Injected timeout'); });
    });
    runtime_check(get_post_meta($id, '_fourier_pipeline_status', true) === 'error', 'Failure changes status');
    runtime_check(fourier_pipeline_runtime_snapshot($id)['llm_state'] === 'failed', 'Failed request timing');
    runtime_check(!get_option(fourier_pipeline_runtime_lock_key($id)), 'Failure releases lease');
    update_post_meta($id, '_fourier_pipeline_status', 'queued');
    fourier_pipeline_run_guarded($id, function ($id) { fourier_pipeline_update($id, 'concept_map', 'processing'); });
    runtime_check(get_post_meta($id, '_fourier_pipeline_status', true) === 'error', 'Incomplete callback cannot leave processing forever');
    fourier_pipeline_runtime_save($id, ['started_at' => time() - 2200, 'finished_at' => null, 'lease_until' => time() - 1, 'phase' => 'llm_wait']);
    runtime_check(fourier_pipeline_runtime_snapshot($id)['state'] === 'overdue', 'Overdue detection');
    update_post_meta($id, '_fourier_pipeline_status', 'processing');
    fourier_pipeline_run_guarded($id, function () { throw new RuntimeException('Must not enter'); });
    runtime_check(get_post_meta($id, '_fourier_pipeline_status', true) === 'processing', 'Do not restart a processing job');
    update_post_meta($id, '_fourier_pipeline_status', 'queued');
    update_post_meta($id, '_fourier_pipeline_kind', 'concept');
    update_post_meta($id, '_fourier_pipeline_stage', 'concept_map');
    update_post_meta($id, '_fourier_pipeline_concept', 'water');
    update_post_meta($id, '_fourier_pipeline_owner', 1);
    update_post_meta($id, '_fourier_pipeline_provider', 'ollama');
    add_filter('pre_http_request', $mock, 10, 3);
    fourier_pipeline_worker($id);
    runtime_check(get_post_meta($id, '_fourier_pipeline_stage', true) === 'branch_questions', 'Real concept worker advances stage with mocked HTTP');
    runtime_check(fourier_pipeline_runtime_snapshot($id)['llm_state'] === 'received', 'Worker records model call');
    $data = get_post_meta($id, '_fourier_pipeline_runtime', true);
    runtime_check(!isset($data['prompt']) && !isset($data['response']) && !isset($data['llm']['url']), 'Metrics contain no payload or endpoint');
    $previous_user = get_current_user_id();
    wp_set_current_user(1);
    $mcp = fourier_mcp_call('job_status', ['id' => $id]);
    wp_set_current_user($previous_user);
    runtime_check(!is_wp_error($mcp) && $mcp['runtime']['llm_state'] === 'received', 'MCP status exposes runtime for owner');
    echo "PASS: $checks pipeline runtime integration checks.\n";
} finally {
    remove_filter('pre_http_request', $mock, 10);
    wp_clear_scheduled_hook('fourier_pipeline_worker', [(int) $id]);
    delete_option(fourier_pipeline_runtime_lock_key($id));
    wp_delete_post($id, true);
}
