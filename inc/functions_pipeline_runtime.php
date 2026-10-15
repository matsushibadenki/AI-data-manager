<?php
/** Pipeline execution lease and bounded operational metadata. No prompts or credentials. */
if (!defined('ABSPATH')) exit;

function fourier_pipeline_runtime_lock_key($id) { return 'fourier_pipeline_run_' . (int) $id; }

function fourier_pipeline_runtime_release($id, $lease) {
    global $wpdb;
    // Compare-and-delete prevents an old process from releasing a newer lease.
    $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", fourier_pipeline_runtime_lock_key($id), maybe_serialize($lease)));
    if ($deleted) wp_cache_delete(fourier_pipeline_runtime_lock_key($id), 'options');
    return (bool) $deleted;
}

function fourier_pipeline_runtime_acquire($id) {
    $lease = ['token' => wp_generate_uuid4(), 'started' => time(), 'expires' => time() + 2100];
    $key = fourier_pipeline_runtime_lock_key($id);
    if (add_option($key, $lease, '', false)) return $lease;
    $old = get_option($key);
    // Only queued jobs may reclaim an expired lease. Processing jobs require diagnosis.
    if (is_array($old) && ($old['expires'] ?? PHP_INT_MAX) < time() && get_post_meta($id, '_fourier_pipeline_status', true) === 'queued') {
        if (fourier_pipeline_runtime_release($id, $old) && add_option($key, $lease, '', false)) return $lease;
    }
    return false;
}

function fourier_pipeline_runtime_save($id, $changes) {
    $runtime = get_post_meta($id, '_fourier_pipeline_runtime', true);
    $runtime = is_array($runtime) ? $runtime : [];
    update_post_meta($id, '_fourier_pipeline_runtime', array_merge($runtime, $changes, ['checkpoint_at' => time()]));
}

function fourier_pipeline_run_guarded($id, $callback) {
    $id = (int) $id;
    $post = get_post($id);
    if (!$post || $post->post_type !== 'post' || get_post_meta($id, '_fourier_pipeline_status', true) !== 'queued') return;
    $lease = fourier_pipeline_runtime_acquire($id);
    if (!$lease) {
        wp_cache_delete($id, 'post_meta');
        // A next-stage event can arrive just before the previous stage releases its lock.
        if (get_post_meta($id, '_fourier_pipeline_status', true) === 'queued') fourier_pipeline_enqueue($id, 15);
        return;
    }
    // A competing process may have finished while this process was acquiring the lease.
    wp_cache_delete($id, 'post_meta');
    // The status might have changed while acquiring the database lease.
    if (get_post_meta($id, '_fourier_pipeline_status', true) !== 'queued') { fourier_pipeline_runtime_release($id, $lease); return; }
    $previous = $GLOBALS['fourier_pipeline_runtime_job'] ?? null;
    $GLOBALS['fourier_pipeline_runtime_job'] = $id;
    $start = microtime(true);
    $finished = false;
    fourier_pipeline_runtime_save($id, ['run_id' => $lease['token'], 'phase' => 'worker', 'started_at' => time(), 'finished_at' => null, 'duration_ms' => null,
        'stage' => get_post_meta($id, '_fourier_pipeline_stage', true), 'lease_until' => $lease['expires'], 'llm' => null]);
    register_shutdown_function(function () use ($id, $lease, &$finished) {
        if ($finished) return;
        $error = error_get_last();
        if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
        fourier_pipeline_runtime_save($id, ['phase' => 'interrupted', 'finished_at' => time()]);
        fourier_pipeline_update($id, get_post_meta($id, '_fourier_pipeline_stage', true), 'error', 'Worker interrupted. Retry this stage.');
        fourier_pipeline_runtime_release($id, $lease);
    });
    try {
        $callback($id);
    } catch (Throwable $error) {
        fourier_pipeline_update($id, get_post_meta($id, '_fourier_pipeline_stage', true), 'error', $error->getMessage());
    } finally {
        $status = get_post_meta($id, '_fourier_pipeline_status', true);
        if ($status === 'processing') {
            fourier_pipeline_update($id, get_post_meta($id, '_fourier_pipeline_stage', true), 'error', 'Worker ended without completing the stage.');
            $status = 'error';
        }
        fourier_pipeline_runtime_save($id, ['phase' => $status === 'error' ? 'failed' : 'finished', 'finished_at' => time(), 'duration_ms' => (int) round((microtime(true) - $start) * 1000)]);
        $finished = true;
        $GLOBALS['fourier_pipeline_runtime_job'] = $previous;
        fourier_pipeline_runtime_release($id, $lease);
    }
}

function fourier_pipeline_runtime_llm($id, $provider, $callback) {
    $start = microtime(true);
    $llm = ['provider' => sanitize_key($provider), 'state' => 'waiting', 'started_at' => time(), 'finished_at' => null, 'duration_ms' => null];
    fourier_pipeline_runtime_save($id, ['phase' => 'llm_wait', 'llm' => $llm]);
    try {
        $result = $callback();
        if (!is_string($result) || trim($result) === '') throw new RuntimeException('LLM returned an empty response.');
        $llm['state'] = 'received';
        return $result;
    } catch (Throwable $error) {
        $llm['state'] = 'failed';
        throw $error;
    } finally {
        $llm['finished_at'] = time();
        $llm['duration_ms'] = (int) round((microtime(true) - $start) * 1000);
        fourier_pipeline_runtime_save($id, ['phase' => 'worker', 'llm' => $llm]);
    }
}

function fourier_pipeline_runtime_snapshot($id, $now = null) {
    $now = $now ?? time();
    $data = get_post_meta($id, '_fourier_pipeline_runtime', true);
    if (!is_array($data) || empty($data['started_at'])) return ['state' => 'untracked'];
    $phase = $data['phase'] ?? 'worker';
    $active = empty($data['finished_at']);
    $state = $active && $now > ($data['lease_until'] ?? 0) ? 'overdue' : $phase;
    $llm = is_array($data['llm'] ?? null) ? $data['llm'] : [];
    return ['state' => $state, 'stage' => $data['stage'] ?? '', 'started_at' => (int) $data['started_at'],
        'elapsed_seconds' => $active ? max(0, $now - $data['started_at']) : (int) round(($data['duration_ms'] ?? 0) / 1000),
        'llm_state' => $llm['state'] ?? null, 'provider' => $llm['provider'] ?? null,
        'llm_seconds' => !$llm ? null : (isset($llm['duration_ms']) ? round($llm['duration_ms'] / 1000, 1) : max(0, $now - $llm['started_at'])),
        'checkpoint_at' => (int) ($data['checkpoint_at'] ?? 0)];
}
