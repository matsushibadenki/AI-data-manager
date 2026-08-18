<?php
/**
 * 停止されるまで継続するLLM自動蒸留ジョブ。
 * 1回のCronで1バッチだけ処理し、HTTPリクエストの長時間占有を避ける。
 */
if (!defined('ABSPATH')) exit;

add_action('init', function () {
    register_post_type('fourier_distill_job', [
        'label' => 'Auto Distillation Jobs',
        'public' => false,
        'show_ui' => false,
        'supports' => ['title', 'author'],
    ]);
});

function fourier_auto_distill_allowed_formats() {
    return ['plain', 'instruction', 'chatml', 'sharegpt', 'cot', 'dpo', 'episode', 'structured'];
}

function fourier_auto_distill_allowed_methods() {
    return ['self-instruct', 'refinement', 'cot', 'backtranslation', 'format-conversion', 'counterfactual', 'concept-expansion'];
}

/** ジョブごとの処理・LLM通信ログを最大50件保持する（APIキーは含めない）。 */
function fourier_auto_distill_log($job_id, $type, $message, $details = []) {
    $logs = get_post_meta($job_id, '_fourier_auto_logs', true);
    if (!is_array($logs)) $logs = [];
    $safe_details = [];
    foreach ((array) $details as $key => $value) {
        $key = sanitize_key($key);
        if (is_scalar($value) || $value === null) {
            $limit = in_array($key, ['response', 'user_prompt'], true) ? 12000 : 4000;
            $safe_details[$key] = mb_substr((string) $value, 0, $limit);
        } else {
            $safe_details[$key] = mb_substr(wp_json_encode($value, JSON_UNESCAPED_UNICODE), 0, 8000);
        }
    }
    $logs[] = [
        'time' => current_time('Y-m-d H:i:s'),
        'type' => sanitize_key($type),
        'message' => sanitize_text_field($message),
        'details' => $safe_details,
    ];
    update_post_meta($job_id, '_fourier_auto_logs', array_slice($logs, -50));
}

function fourier_auto_distill_schedule($job_id, $delay = 1) {
    $args = [(int) $job_id];
    $next_run = wp_next_scheduled('fourier_auto_distillation_tick', $args);
    if (!$next_run) {
        $next_run = time() + max(0, (int) $delay);
        wp_schedule_single_event($next_run, 'fourier_auto_distillation_tick', $args);
    }
    update_post_meta($job_id, '_fourier_auto_next_run', $next_run);
}

/** DISABLE_WP_CRON環境でも、期限に達したイベントを非同期で起動する。 */
function fourier_auto_distill_dispatch_cron() {
    wp_remote_post(site_url('wp-cron.php'), [
        'timeout' => 0.01,
        'blocking' => false,
        'sslverify' => apply_filters('https_local_ssl_verify', false),
    ]);
}

function fourier_auto_distill_schema_prompt($format) {
    $schemas = [
        'plain' => '{"text":"..."}',
        'instruction' => '{"instruction":"...","input":"...","output":"..."}',
        'chatml' => '{"messages":[{"role":"user","content":"..."},{"role":"assistant","content":"..."}]}',
        'sharegpt' => '{"conversations":[{"from":"human","value":"..."},{"from":"gpt","value":"..."}]}',
        'cot' => '{"question":"...","observable_facts":[],"affected_parties":[],"candidate_actions":[],"predicted_effects":[],"answer":"..."}',
        'dpo' => '{"prompt":"...","chosen":"...","rejected":"...","preference_reasons":[]}',
        'episode' => '{"schema_version":"1.0","data_type":"episode","episode_id":"ep_...","narrative_text":"...","narrative":{"setting":"...","initial_state":"...","goal":"...","events":[],"outcome":"...","long_term_outcome":"..."},"agents":[],"causal_relations":[],"impact":[],"alternatives":[],"interpretations":[],"observable_reasoning":{"observable_facts":[],"affected_parties":[],"candidate_actions":[],"predicted_effects":[],"answer":""},"annotations":{"domain":[],"themes":[],"review_status":"pending_review"},"source":{"type":"derived","license":"unknown"}}',
        'structured' => '{"type":"...","content":{},"metadata":{}}',
    ];
    return $schemas[$format] ?? $schemas['structured'];
}

function fourier_auto_distill_build_prompt($config, $iteration, $recent_examples = '') {
    $method_instructions = [
        'self-instruct' => '多様な難易度・問い方・利用場面を持つ学習データを作成する。',
        'refinement' => '元情報を維持しながら正確性、明確さ、具体性を高める。',
        'cot' => '自由形式の思考過程ではなく、観測事実、関係者、候補行動、予測効果、最終回答を構造化する。',
        'backtranslation' => '妥当な回答から、それを引き出す良質な質問や指示を逆生成する。',
        'format-conversion' => '意味を失わず指定フォーマットへ変換する。',
        'counterfactual' => '同じ条件から異なる行動・結果・反実仮想を生成し、因果を比較可能にする。',
        'concept-expansion' => '中心概念を特徴、関係、誤解、比較、具体例、推論へ分岐させる。',
    ];
    $format = $config['target_format'];
    $batch = (int) $config['batch_size'];
    $method = $method_instructions[$config['method']] ?? $method_instructions['self-instruct'];
    $diversity_axes = [
        '基礎事実と定義',
        '比較と関係性',
        '具体例と実務応用',
        '誤解訂正と反例',
        '境界条件と推論',
    ];
    $diversity = $diversity_axes[($iteration - 1) % count($diversity_axes)];

    $system = "あなたは高品質なAI学習データを継続生成するデータエンジニアです。根拠のない固有事実を作らず、重複・言い換えだけのデータを避けてください。出力はJSONオブジェクトのみです。自由形式の隠れた思考過程は出力せず、必要なら観測可能な根拠だけを構造化してください。";
    $user = "【シード】\n{$config['seed_data']}\n\n";
    $user .= "【反復番号】{$iteration} / 多様性軸: {$diversity}\n";
    $user .= "【蒸留方式】{$method}\n";
    $user .= "【追加指示】{$config['extra_prompt']}\n";
    $user .= "【出力形式】{$format}\n各要素は次の構造に従ってください:\n" . fourier_auto_distill_schema_prompt($format) . "\n";
    if ($recent_examples !== '') {
        $user .= "【直前に生成済みのデータ】\n{$recent_examples}\n同じ問い、結論、具体例の言い換えは生成しないでください。\n";
    }
    $user .= "互いに異なるデータを{$batch}件生成し、必ず {\"items\":[...]} の形で返してください。各要素に説明用のラッパーを付けないでください。";
    return [$system, $user];
}

function fourier_auto_distill_normalize_items($parsed) {
    if (!is_array($parsed)) return [];
    // 共通LLMパーサーは未知のルートオブジェクトを1要素配列で包むため、
    // {"items":[...]} / {"data":...} のラッパーを先に展開する。
    if (wp_is_numeric_array($parsed) && count($parsed) === 1 && is_array($parsed[0])) {
        $wrapped = $parsed[0];
        if (isset($wrapped['items']) && is_array($wrapped['items'])) {
            return wp_is_numeric_array($wrapped['items']) ? $wrapped['items'] : [$wrapped['items']];
        }
        if (isset($wrapped['data']) && is_array($wrapped['data'])) {
            return wp_is_numeric_array($wrapped['data']) ? $wrapped['data'] : [$wrapped['data']];
        }
    }
    if (isset($parsed['items']) && is_array($parsed['items'])) return $parsed['items'];
    if (isset($parsed['data']) && is_array($parsed['data'])) {
        return wp_is_numeric_array($parsed['data']) ? $parsed['data'] : [$parsed['data']];
    }
    return wp_is_numeric_array($parsed) ? $parsed : [$parsed];
}

function fourier_auto_distill_validate_item($format, $item) {
    if (!is_array($item) || !$item) return false;
    $required = [
        'plain' => ['text'],
        'instruction' => ['instruction', 'output'],
        'chatml' => ['messages'],
        'sharegpt' => ['conversations'],
        'cot' => ['question', 'answer'],
        'dpo' => ['prompt', 'chosen', 'rejected'],
        'episode' => ['narrative'],
    ];
    foreach ($required[$format] ?? [] as $key) {
        if (!isset($item[$key]) || $item[$key] === '' || $item[$key] === []) return false;
    }
    if ($format === 'episode') {
        return !is_wp_error(fourier_validate_episode_payload(['format' => 'episode', 'data' => $item]));
    }
    if ($format === 'chatml') {
        if (!is_array($item['messages'])) return false;
        foreach ($item['messages'] as $message) {
            if (!is_array($message) || empty($message['role']) || !isset($message['content']) || $message['content'] === '') return false;
        }
    }
    if ($format === 'sharegpt') {
        if (!is_array($item['conversations'])) return false;
        foreach ($item['conversations'] as $message) {
            if (!is_array($message) || empty($message['from']) || !isset($message['value']) || $message['value'] === '') return false;
        }
    }
    if ($format === 'dpo' && $item['chosen'] === $item['rejected']) return false;
    return true;
}

function fourier_auto_distill_canonicalize($value) {
    if (is_array($value)) {
        if (!wp_is_numeric_array($value)) ksort($value);
        foreach ($value as $key => $item) $value[$key] = fourier_auto_distill_canonicalize($item);
    } elseif (is_string($value)) {
        $value = preg_replace('/\s+/u', ' ', trim($value));
    }
    return $value;
}

function fourier_auto_distill_fingerprint($format, $item) {
    return hash('sha256', $format . '|' . wp_json_encode(fourier_auto_distill_canonicalize($item), JSON_UNESCAPED_UNICODE));
}

function fourier_auto_distill_signature($format, $item) {
    $text = wp_json_encode(fourier_auto_distill_canonicalize($item), JSON_UNESCAPED_UNICODE);
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $text = preg_replace('/[\p{P}\p{S}\s]+/u', '', $text);
    return hash('sha256', $format . '|' . $text);
}

function fourier_auto_distill_is_duplicate($fingerprint, $signature) {
    return (bool) get_posts([
        'post_type' => 'post',
        'post_status' => 'any',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'meta_query' => [
            'relation' => 'OR',
            ['key' => '_fourier_auto_fingerprint', 'value' => $fingerprint],
            ['key' => '_fourier_auto_signature', 'value' => $signature],
        ],
    ]);
}

function fourier_auto_distill_recent_examples($job_id) {
    $post_ids = get_post_meta($job_id, '_fourier_auto_last_post_ids', true);
    if (!is_array($post_ids)) return '';
    $examples = [];
    foreach (array_slice($post_ids, -5) as $post_id) {
        $content = get_post_field('post_content', (int) $post_id);
        if ($content) $examples[] = mb_substr(wp_strip_all_tags($content), 0, 1200);
    }
    return mb_substr(implode("\n---\n", $examples), 0, 6000);
}

function fourier_auto_distill_save_item($job_id, $config, $item, $sequence) {
    $payload = ['format' => $config['target_format'], 'data' => $item];
    $post_id = wp_insert_post([
        'post_title' => sanitize_text_field($config['title_prefix'] . ' #' . $sequence),
        'post_content' => wp_slash(wp_json_encode($payload, JSON_UNESCAPED_UNICODE)),
        'post_status' => 'pending',
        'post_type' => 'post',
        'post_author' => (int) $config['user_id'],
    ]);
    if (is_wp_error($post_id) || !$post_id) return 0;

    $fingerprint = fourier_auto_distill_fingerprint($config['target_format'], $item);
    $signature = fourier_auto_distill_signature($config['target_format'], $item);
    update_post_meta($post_id, 'is_learning_data', '1');
    update_post_meta($post_id, 'learning_format', $config['target_format']);
    update_post_meta($post_id, 'learning_language', $config['language']);
    update_post_meta($post_id, 'learning_source', 'auto_distillation_job:' . $job_id);
    update_post_meta($post_id, 'learning_tags', $config['tags']);
    update_post_meta($post_id, 'learning_char_count', mb_strlen(wp_json_encode($payload, JSON_UNESCAPED_UNICODE)));
    update_post_meta($post_id, '_fourier_auto_job_id', $job_id);
    update_post_meta($post_id, '_fourier_auto_fingerprint', $fingerprint);
    update_post_meta($post_id, '_fourier_auto_signature', $signature);
    update_post_meta($post_id, '_fourier_pipeline_kind', 'auto_distillation');
    update_post_meta($post_id, '_fourier_pipeline_stage', 'review');
    update_post_meta($post_id, '_fourier_pipeline_status', 'review');
    update_post_meta($post_id, '_fourier_pipeline_message', '自動蒸留データのレビュー待ち');
    update_post_meta($post_id, '_fourier_pipeline_updated', current_time('mysql'));
    return $post_id;
}

add_action('fourier_auto_distillation_tick', 'fourier_auto_distillation_worker');
function fourier_auto_distillation_worker($job_id) {
    $job_id = (int) $job_id;
    $job = get_post($job_id);
    if (!$job || $job->post_type !== 'fourier_distill_job') return;
    if (get_post_meta($job_id, '_fourier_auto_status', true) !== 'running') return;
    $lock_key = 'fourier_auto_distill_lock_' . $job_id;
    if (get_transient($lock_key)) return;
    set_transient($lock_key, 1, 10 * MINUTE_IN_SECONDS);

    $config = get_post_meta($job_id, '_fourier_auto_config', true);
    if (!is_array($config)) {
        update_post_meta($job_id, '_fourier_auto_status', 'error');
        update_post_meta($job_id, '_fourier_auto_last_error', 'ジョブ設定を読み込めません。');
        delete_transient($lock_key);
        return;
    }

    $iteration = (int) get_post_meta($job_id, '_fourier_auto_iterations', true) + 1;
    update_post_meta($job_id, '_fourier_auto_phase', 'generating');
    update_post_meta($job_id, '_fourier_auto_updated', current_time('mysql'));
    fourier_auto_distill_log($job_id, 'process', '蒸留バッチを開始しました。', ['iteration' => $iteration]);
    try {
        wp_set_current_user((int) $config['user_id']);
        $recent_examples = fourier_auto_distill_recent_examples($job_id);
        [$system_prompt, $user_prompt] = fourier_auto_distill_build_prompt($config, $iteration, $recent_examples);
        fourier_auto_distill_log($job_id, 'request', 'LLMへリクエストを送信します。', [
            'provider' => $config['provider'],
            'format' => $config['target_format'],
            'system_prompt' => $system_prompt,
            'user_prompt' => $user_prompt,
        ]);
        $raw = llm_api_call_raw($config['provider'], $system_prompt, $user_prompt);
        fourier_auto_distill_log($job_id, 'response', 'LLMから応答を受信しました。', ['response' => $raw]);
        $parsed = _parse_json_from_llm_response($raw);
        $items = array_slice(fourier_auto_distill_normalize_items($parsed), 0, (int) $config['batch_size']);
        if (!$items) throw new UnexpectedValueException('LLM応答に保存可能なitemsがありません。');

        // LLM応答待ちの間に停止された場合は保存しない。
        if (get_post_meta($job_id, '_fourier_auto_status', true) !== 'running') {
            delete_transient($lock_key);
            return;
        }

        $saved_ids = [];
        $duplicates = 0;
        $invalid = 0;
        $generated_total = (int) get_post_meta($job_id, '_fourier_auto_generated', true);
        foreach ($items as $item) {
            if (get_post_meta($job_id, '_fourier_auto_status', true) !== 'running') break;
            if (!fourier_auto_distill_validate_item($config['target_format'], $item)) {
                $invalid++;
                continue;
            }
            $fingerprint = fourier_auto_distill_fingerprint($config['target_format'], $item);
            $signature = fourier_auto_distill_signature($config['target_format'], $item);
            if (fourier_auto_distill_is_duplicate($fingerprint, $signature)) {
                $duplicates++;
                continue;
            }
            $post_id = fourier_auto_distill_save_item($job_id, $config, $item, $generated_total + count($saved_ids) + 1);
            if ($post_id) $saved_ids[] = $post_id;
        }

        update_post_meta($job_id, '_fourier_auto_iterations', $iteration);
        update_post_meta($job_id, '_fourier_auto_generated', $generated_total + count($saved_ids));
        update_post_meta($job_id, '_fourier_auto_duplicates', (int) get_post_meta($job_id, '_fourier_auto_duplicates', true) + $duplicates);
        update_post_meta($job_id, '_fourier_auto_invalid', (int) get_post_meta($job_id, '_fourier_auto_invalid', true) + $invalid);
        update_post_meta($job_id, '_fourier_auto_last_post_ids', $saved_ids);
        $last_validation_error = (!$saved_ids && $invalid > 0)
            ? 'LLM応答は受信しましたが、出力形式の検証を通過しませんでした。次回の反復で再試行します。'
            : '';
        update_post_meta($job_id, '_fourier_auto_last_error', $last_validation_error);
        update_post_meta($job_id, '_fourier_auto_consecutive_errors', 0);
        update_post_meta($job_id, '_fourier_auto_phase', 'waiting');
        update_post_meta($job_id, '_fourier_auto_updated', current_time('mysql'));
        fourier_auto_distill_log($job_id, 'result', 'バッチ処理が完了しました。', [
            'iteration' => $iteration,
            'received' => count($items),
            'saved' => count($saved_ids),
            'duplicates' => $duplicates,
            'invalid' => $invalid,
            'post_ids' => $saved_ids,
        ]);

        if (get_post_meta($job_id, '_fourier_auto_status', true) === 'running') {
            fourier_auto_distill_schedule($job_id, (int) $config['interval_seconds']);
        }
    } catch (Throwable $e) {
        $errors = (int) get_post_meta($job_id, '_fourier_auto_consecutive_errors', true) + 1;
        $delay = min(3600, max((int) $config['interval_seconds'], 60) * (2 ** min($errors, 5)));
        update_post_meta($job_id, '_fourier_auto_consecutive_errors', $errors);
        update_post_meta($job_id, '_fourier_auto_total_errors', (int) get_post_meta($job_id, '_fourier_auto_total_errors', true) + 1);
        update_post_meta($job_id, '_fourier_auto_last_error', $e->getMessage());
        update_post_meta($job_id, '_fourier_auto_phase', 'retrying');
        update_post_meta($job_id, '_fourier_auto_updated', current_time('mysql'));
        fourier_auto_distill_log($job_id, 'error', 'バッチ処理でエラーが発生しました。', [
            'error' => $e->getMessage(),
            'retry_delay_seconds' => $delay,
        ]);
        if (get_post_meta($job_id, '_fourier_auto_status', true) === 'running') {
            fourier_auto_distill_schedule($job_id, $delay);
        }
    }
    delete_transient($lock_key);
}

function fourier_auto_distill_user_job($user_id, $running_only = false) {
    $args = [
        'post_type' => 'fourier_distill_job',
        'post_status' => 'publish',
        'author' => (int) $user_id,
        'posts_per_page' => 1,
        'orderby' => 'date',
        'order' => 'DESC',
    ];
    if ($running_only) $args['meta_query'] = [['key' => '_fourier_auto_status', 'value' => 'running']];
    $jobs = get_posts($args);
    return $jobs ? $jobs[0] : null;
}

add_action('wp_ajax_fourier_auto_distill_start', function () {
    check_ajax_referer('learning_data_action', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'ログインが必要です。'], 403);
    $user_id = get_current_user_id();
    $running_job = fourier_auto_distill_user_job($user_id, true);
    $replace_running = isset($_POST['replace_running']) && sanitize_text_field(wp_unslash($_POST['replace_running'])) === '1';
    if ($running_job && !$replace_running) {
        wp_send_json_error(['message' => '実行中の自動蒸留ジョブがあります。先に停止してください。']);
    }

    $provider = sanitize_key($_POST['provider'] ?? 'openai');
    $format = sanitize_key($_POST['target_format'] ?? 'instruction');
    $method = sanitize_key($_POST['method'] ?? 'self-instruct');
    $seed = sanitize_textarea_field($_POST['seed_data'] ?? '');
    if (!$seed && $running_job && $replace_running) {
        $running_config = get_post_meta($running_job->ID, '_fourier_auto_config', true);
        if (is_array($running_config)) $seed = sanitize_textarea_field($running_config['seed_data'] ?? '');
    }
    if (!$seed) wp_send_json_error(['message' => 'シードデータを入力してください。']);
    if (!in_array($provider, ['openai', 'gemini', 'ollama', 'custom'], true)) wp_send_json_error(['message' => '不正なLLMプロバイダです。']);
    if (!in_array($format, fourier_auto_distill_allowed_formats(), true)) wp_send_json_error(['message' => '不正な出力形式です。']);
    if (!in_array($method, fourier_auto_distill_allowed_methods(), true)) wp_send_json_error(['message' => '不正な蒸留方式です。']);

    $title_prefix = sanitize_text_field($_POST['title_prefix'] ?? 'Auto Distilled');
    if ($title_prefix === '') $title_prefix = 'Auto Distilled';

    $config = [
        'user_id' => $user_id,
        'provider' => $provider,
        'target_format' => $format,
        'method' => $method,
        'seed_data' => $seed,
        'extra_prompt' => sanitize_textarea_field($_POST['extra_prompt'] ?? ''),
        'title_prefix' => $title_prefix,
        'interval_seconds' => max(60, min(3600, absint($_POST['interval_seconds'] ?? 300))),
        'batch_size' => max(1, min(5, absint($_POST['batch_size'] ?? 3))),
        'language' => sanitize_text_field($_POST['language'] ?? 'ja'),
        'tags' => sanitize_text_field($_POST['tags'] ?? 'auto-distillation'),
    ];
    $job_id = wp_insert_post([
        'post_type' => 'fourier_distill_job',
        'post_status' => 'publish',
        'post_title' => $config['title_prefix'],
        'post_author' => $user_id,
    ]);
    if (is_wp_error($job_id) || !$job_id) wp_send_json_error(['message' => 'ジョブを作成できませんでした。']);

    update_post_meta($job_id, '_fourier_auto_config', $config);
    if ($running_job) {
        fourier_auto_distill_log($running_job->ID, 'stop', '新しい設定で再開始するため、実行中ジョブを停止しました。');
        update_post_meta($running_job->ID, '_fourier_auto_status', 'stopped');
        update_post_meta($running_job->ID, '_fourier_auto_phase', 'replaced');
        update_post_meta($running_job->ID, '_fourier_auto_stopped', current_time('mysql'));
        delete_post_meta($running_job->ID, '_fourier_auto_next_run');
        wp_clear_scheduled_hook('fourier_auto_distillation_tick', [(int) $running_job->ID]);
    }
    update_post_meta($job_id, '_fourier_auto_status', 'running');
    update_post_meta($job_id, '_fourier_auto_phase', 'queued');
    update_post_meta($job_id, '_fourier_auto_started', current_time('mysql'));
    fourier_auto_distill_log($job_id, 'start', '自動蒸留ジョブを開始しました。', [
        'provider' => $config['provider'],
        'format' => $config['target_format'],
        'method' => $config['method'],
        'interval_seconds' => $config['interval_seconds'],
        'batch_size' => $config['batch_size'],
    ]);
    fourier_auto_distill_schedule($job_id, 0);
    fourier_auto_distill_dispatch_cron();
    wp_send_json_success(['job_id' => $job_id, 'message' => '自動蒸留を開始しました。停止するまで継続します。']);
});

add_action('wp_ajax_fourier_auto_distill_stop', function () {
    check_ajax_referer('learning_data_action', 'nonce');
    $job_id = absint($_POST['job_id'] ?? 0);
    $job = get_post($job_id);
    if (!$job || $job->post_type !== 'fourier_distill_job' || (int) $job->post_author !== get_current_user_id()) {
        wp_send_json_error(['message' => 'ジョブが見つかりません。'], 404);
    }
    update_post_meta($job_id, '_fourier_auto_status', 'stopped');
    update_post_meta($job_id, '_fourier_auto_phase', 'stopped');
    update_post_meta($job_id, '_fourier_auto_stopped', current_time('mysql'));
    fourier_auto_distill_log($job_id, 'stop', 'ユーザー操作で自動蒸留を停止しました。');
    delete_post_meta($job_id, '_fourier_auto_next_run');
    wp_clear_scheduled_hook('fourier_auto_distillation_tick', [$job_id]);
    wp_send_json_success(['job_id' => $job_id, 'message' => '自動蒸留を停止しました。']);
});

add_action('wp_ajax_fourier_auto_distill_status', function () {
    check_ajax_referer('learning_data_action', 'nonce');
    $job_id = absint($_POST['job_id'] ?? 0);
    $job = $job_id ? get_post($job_id) : fourier_auto_distill_user_job(get_current_user_id(), false);
    if (!$job || $job->post_type !== 'fourier_distill_job' || (int) $job->post_author !== get_current_user_id()) {
        wp_send_json_success(['job' => null]);
    }
    $status = get_post_meta($job->ID, '_fourier_auto_status', true);
    $event_args = [(int) $job->ID];
    if ($status === 'running' && !wp_next_scheduled('fourier_auto_distillation_tick', $event_args) && !get_transient('fourier_auto_distill_lock_' . $job->ID)) {
        fourier_auto_distill_schedule($job->ID, 0);
    }
    $next = (int) get_post_meta($job->ID, '_fourier_auto_next_run', true);
    if ($status === 'running' && $next && $next <= time() && !get_transient('fourier_auto_distill_lock_' . $job->ID)) {
        fourier_auto_distill_dispatch_cron();
    }
    $include_logs = isset($_POST['include_logs']) && sanitize_text_field(wp_unslash($_POST['include_logs'])) === '1';
    $logs = $include_logs ? get_post_meta($job->ID, '_fourier_auto_logs', true) : [];
    wp_send_json_success(['job' => [
        'id' => $job->ID,
        'title' => $job->post_title,
        'status' => $status,
        'phase' => get_post_meta($job->ID, '_fourier_auto_phase', true),
        'iterations' => (int) get_post_meta($job->ID, '_fourier_auto_iterations', true),
        'generated' => (int) get_post_meta($job->ID, '_fourier_auto_generated', true),
        'duplicates' => (int) get_post_meta($job->ID, '_fourier_auto_duplicates', true),
        'invalid' => (int) get_post_meta($job->ID, '_fourier_auto_invalid', true),
        'errors' => (int) get_post_meta($job->ID, '_fourier_auto_total_errors', true),
        'last_error' => get_post_meta($job->ID, '_fourier_auto_last_error', true),
        'started' => get_post_meta($job->ID, '_fourier_auto_started', true),
        'stopped' => get_post_meta($job->ID, '_fourier_auto_stopped', true),
        'updated' => get_post_meta($job->ID, '_fourier_auto_updated', true),
        'next_run' => $next ? wp_date('Y-m-d H:i:s', $next) : '',
        'logs' => $include_logs && is_array($logs) ? array_slice($logs, -20) : null,
    ]]);
});
