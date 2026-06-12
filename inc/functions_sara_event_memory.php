<?php
/*
 * Path: /wp-content/themes/AI-data-manager/inc/functions_sara_event_memory.php
 * Name: functions_sara_event_memory.php
 * Description: SARA Engine向けのEvent Memory CMS。WordPressを海馬相当のイベント記憶層として使い、sparse events / experience records / curriculum manifestをREST APIとJSONLで外部出力する。
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ---------------------------------------------------------- */
/* -------------------- テーブル作成・更新 -------------------- */
/* ---------------------------------------------------------- */

add_action('after_switch_theme', 'fourier_sara_event_memory_install');
add_action('admin_init', 'fourier_sara_event_memory_maybe_install');

function fourier_sara_event_memory_maybe_install() {
    $installed_version = get_option('fourier_sara_event_memory_schema_version');
    if ($installed_version !== '1.0.0') {
        fourier_sara_event_memory_install();
    }
}

function fourier_sara_event_memory_install() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $sources_table = $wpdb->prefix . 'sara_sources';
    $events_table = $wpdb->prefix . 'sara_events';
    $experiences_table = $wpdb->prefix . 'sara_experiences';

    $sql_sources = "CREATE TABLE {$sources_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        source_uid VARCHAR(128) NOT NULL,
        source_type VARCHAR(64) NOT NULL DEFAULT 'manual',
        title TEXT NULL,
        source_url TEXT NULL,
        license_hint VARCHAR(128) NULL,
        meta_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY source_uid (source_uid),
        KEY source_type (source_type)
    ) {$charset_collate};";

    $sql_events = "CREATE TABLE {$events_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_uid VARCHAR(128) NOT NULL,
        source_id BIGINT UNSIGNED NULL,
        experience_uid VARCHAR(128) NULL,
        t DOUBLE NOT NULL DEFAULT 0,
        dt DOUBLE NOT NULL DEFAULT 0,
        modality VARCHAR(64) NOT NULL DEFAULT 'text',
        channel VARCHAR(128) NULL,
        event_type VARCHAR(128) NOT NULL DEFAULT 'symbol',
        symbol TEXT NULL,
        payload_json LONGTEXT NULL,
        state_before_json LONGTEXT NULL,
        state_after_json LONGTEXT NULL,
        reward DOUBLE NOT NULL DEFAULT 0,
        prediction_error DOUBLE NOT NULL DEFAULT 0,
        confidence DOUBLE NOT NULL DEFAULT 1,
        quality_score DOUBLE NOT NULL DEFAULT 0.5,
        tags TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY event_uid (event_uid),
        KEY source_id (source_id),
        KEY experience_uid (experience_uid),
        KEY modality (modality),
        KEY event_type (event_type),
        KEY t (t),
        KEY prediction_error (prediction_error),
        KEY quality_score (quality_score)
    ) {$charset_collate};";

    $sql_experiences = "CREATE TABLE {$experiences_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        experience_uid VARCHAR(128) NOT NULL,
        source_id BIGINT UNSIGNED NULL,
        title TEXT NULL,
        state_before_json LONGTEXT NULL,
        event_summary_json LONGTEXT NULL,
        state_after_json LONGTEXT NULL,
        reward DOUBLE NOT NULL DEFAULT 0,
        prediction_error DOUBLE NOT NULL DEFAULT 0,
        quality_score DOUBLE NOT NULL DEFAULT 0.5,
        curriculum_level VARCHAR(64) NOT NULL DEFAULT 'medium',
        split_name VARCHAR(64) NOT NULL DEFAULT 'train',
        tags TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY experience_uid (experience_uid),
        KEY source_id (source_id),
        KEY curriculum_level (curriculum_level),
        KEY split_name (split_name),
        KEY prediction_error (prediction_error),
        KEY quality_score (quality_score)
    ) {$charset_collate};";

    dbDelta($sql_sources);
    dbDelta($sql_events);
    dbDelta($sql_experiences);

    update_option('fourier_sara_event_memory_schema_version', '1.0.0');
}

/* ---------------------------------------------------------- */
/* ------------------------- 管理画面 ------------------------- */
/* ---------------------------------------------------------- */

add_action('admin_menu', 'fourier_sara_event_memory_admin_menu');

function fourier_sara_event_memory_admin_menu() {
    add_menu_page(
        'SARA Event Memory',
        'SARA Memory',
        'manage_options',
        'sara-event-memory',
        'fourier_sara_event_memory_admin_page',
        'dashicons-database-view',
        26
    );
}

function fourier_sara_event_memory_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('権限がありません。', 'fourier'));
    }

    global $wpdb;
    $sources_table = $wpdb->prefix . 'sara_sources';
    $events_table = $wpdb->prefix . 'sara_events';
    $experiences_table = $wpdb->prefix . 'sara_experiences';

    $source_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sources_table}");
    $event_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$events_table}");
    $experience_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$experiences_table}");
    $latest_events = $wpdb->get_results("SELECT id, event_uid, modality, event_type, symbol, prediction_error, quality_score, created_at FROM {$events_table} ORDER BY id DESC LIMIT 20", ARRAY_A);
    $token = fourier_sara_get_current_user_token();
    $endpoint = esc_url_raw(rest_url('fourier/v1/sara/events'));
    $jsonl_endpoint = esc_url_raw(rest_url('fourier/v1/sara/export-jsonl'));

    ?>
    <div class="wrap">
        <h1>SARA Event Memory</h1>
        <p>WordPressをSARA Engine用の海馬相当 Event Memory として使うための管理画面です。SNN本体はここでは動かさず、sparse events / experience records / curriculum manifestを管理・出力します。</p>

        <h2>概要</h2>
        <table class="widefat striped" style="max-width: 760px;">
            <tbody>
                <tr><th>Sources</th><td><?php echo esc_html($source_count); ?></td></tr>
                <tr><th>Events</th><td><?php echo esc_html($event_count); ?></td></tr>
                <tr><th>Experiences</th><td><?php echo esc_html($experience_count); ?></td></tr>
                <tr><th>Events API</th><td><code><?php echo esc_html($endpoint); ?></code></td></tr>
                <tr><th>JSONL Export</th><td><code><?php echo esc_html($jsonl_endpoint); ?></code></td></tr>
            </tbody>
        </table>

        <h2>現在ユーザーのBearer Token</h2>
        <p>既存の <code>fourier_server_access_token</code> を使います。未設定の場合はユーザーメタに保存してください。</p>
        <textarea readonly rows="2" style="width: 760px; max-width: 100%;"><?php echo esc_textarea($token); ?></textarea>

        <h2>最新イベント</h2>
        <table class="widefat striped">
            <thead><tr><th>ID</th><th>UID</th><th>Modality</th><th>Type</th><th>Symbol</th><th>Prediction Error</th><th>Quality</th><th>Created</th></tr></thead>
            <tbody>
            <?php if (empty($latest_events)) : ?>
                <tr><td colspan="8">イベントはまだありません。</td></tr>
            <?php else : ?>
                <?php foreach ($latest_events as $event) : ?>
                    <tr>
                        <td><?php echo esc_html($event['id']); ?></td>
                        <td><code><?php echo esc_html($event['event_uid']); ?></code></td>
                        <td><?php echo esc_html($event['modality']); ?></td>
                        <td><?php echo esc_html($event['event_type']); ?></td>
                        <td><?php echo esc_html(mb_strimwidth((string) $event['symbol'], 0, 80, '…')); ?></td>
                        <td><?php echo esc_html($event['prediction_error']); ?></td>
                        <td><?php echo esc_html($event['quality_score']); ?></td>
                        <td><?php echo esc_html($event['created_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function fourier_sara_get_current_user_token() {
    $user_id = get_current_user_id();
    if (!$user_id) {
        return '';
    }
    return (string) get_user_meta($user_id, 'fourier_server_access_token', true);
}

/* ---------------------------------------------------------- */
/* ----------------------- RESTルート登録 ---------------------- */
/* ---------------------------------------------------------- */

add_action('rest_api_init', 'fourier_sara_register_rest_routes');

function fourier_sara_register_rest_routes() {
    register_rest_route('fourier/v1', '/sara/sources', [
        [
            'methods' => 'GET',
            'callback' => 'fourier_sara_rest_get_sources',
            'permission_callback' => 'fourier_sara_rest_permission_check',
        ],
        [
            'methods' => 'POST',
            'callback' => 'fourier_sara_rest_upsert_source',
            'permission_callback' => 'fourier_sara_rest_permission_check',
        ],
    ]);

    register_rest_route('fourier/v1', '/sara/events', [
        [
            'methods' => 'GET',
            'callback' => 'fourier_sara_rest_get_events',
            'permission_callback' => 'fourier_sara_rest_permission_check',
        ],
        [
            'methods' => 'POST',
            'callback' => 'fourier_sara_rest_insert_events',
            'permission_callback' => 'fourier_sara_rest_permission_check',
        ],
    ]);

    register_rest_route('fourier/v1', '/sara/experiences', [
        [
            'methods' => 'GET',
            'callback' => 'fourier_sara_rest_get_experiences',
            'permission_callback' => 'fourier_sara_rest_permission_check',
        ],
        [
            'methods' => 'POST',
            'callback' => 'fourier_sara_rest_upsert_experience',
            'permission_callback' => 'fourier_sara_rest_permission_check',
        ],
    ]);

    register_rest_route('fourier/v1', '/sara/text-to-events', [
        'methods' => 'POST',
        'callback' => 'fourier_sara_rest_text_to_events',
        'permission_callback' => 'fourier_sara_rest_permission_check',
    ]);

    register_rest_route('fourier/v1', '/sara/export-jsonl', [
        'methods' => 'GET',
        'callback' => 'fourier_sara_rest_export_jsonl',
        'permission_callback' => 'fourier_sara_rest_permission_check',
    ]);

    register_rest_route('fourier/v1', '/sara/curriculum-manifest', [
        'methods' => 'GET',
        'callback' => 'fourier_sara_rest_curriculum_manifest',
        'permission_callback' => 'fourier_sara_rest_permission_check',
    ]);
}

function fourier_sara_rest_permission_check($request) {
    if (function_exists('fourier_rest_permission_check')) {
        return fourier_rest_permission_check($request);
    }

    return current_user_can('manage_options');
}

/* ---------------------------------------------------------- */
/* -------------------- REST: Source操作 ---------------------- */
/* ---------------------------------------------------------- */

function fourier_sara_rest_get_sources($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'sara_sources';
    $limit = max(1, min(500, (int) ($request->get_param('limit') ?: 100)));
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
    return rest_ensure_response(array_map('fourier_sara_decode_source_row', $rows));
}

function fourier_sara_rest_upsert_source($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'sara_sources';
    $body = fourier_sara_get_json_body($request);

    $source_uid = fourier_sara_clean_uid($body['source_uid'] ?? ('source_' . wp_generate_uuid4()));
    $source_type = sanitize_key($body['source_type'] ?? 'manual');
    $title = sanitize_text_field($body['title'] ?? '');
    $source_url = esc_url_raw($body['source_url'] ?? '');
    $license_hint = sanitize_text_field($body['license_hint'] ?? 'review_required');
    $meta_json = fourier_sara_json_encode($body['meta'] ?? []);

    $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE source_uid = %s", $source_uid));

    $data = [
        'source_uid' => $source_uid,
        'source_type' => $source_type,
        'title' => $title,
        'source_url' => $source_url,
        'license_hint' => $license_hint,
        'meta_json' => $meta_json,
        'updated_at' => current_time('mysql'),
    ];

    if ($existing_id) {
        $wpdb->update($table, $data, ['id' => (int) $existing_id]);
        $id = (int) $existing_id;
    } else {
        $data['created_at'] = current_time('mysql');
        $wpdb->insert($table, $data);
        $id = (int) $wpdb->insert_id;
    }

    return rest_ensure_response(['id' => $id, 'source_uid' => $source_uid]);
}

/* ---------------------------------------------------------- */
/* --------------------- REST: Event操作 ---------------------- */
/* ---------------------------------------------------------- */

function fourier_sara_rest_get_events($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'sara_events';

    $limit = max(1, min(2000, (int) ($request->get_param('limit') ?: 200)));
    $after_id = max(0, (int) ($request->get_param('after_id') ?: 0));
    $modality = sanitize_key($request->get_param('modality') ?: '');
    $event_type = sanitize_key($request->get_param('event_type') ?: '');

    $where = ['id > %d'];
    $params = [$after_id];

    if ($modality !== '') {
        $where[] = 'modality = %s';
        $params[] = $modality;
    }
    if ($event_type !== '') {
        $where[] = 'event_type = %s';
        $params[] = $event_type;
    }

    $params[] = $limit;
    $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY id ASC LIMIT %d";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

    return rest_ensure_response(array_map('fourier_sara_decode_event_row', $rows));
}

function fourier_sara_rest_insert_events($request) {
    $body = fourier_sara_get_json_body($request);
    $items = isset($body['events']) && is_array($body['events']) ? $body['events'] : [$body];

    $inserted = [];
    foreach ($items as $item) {
        $result = fourier_sara_insert_event($item);
        if (is_wp_error($result)) {
            return $result;
        }
        $inserted[] = $result;
    }

    return rest_ensure_response(['count' => count($inserted), 'events' => $inserted]);
}

function fourier_sara_insert_event($item) {
    global $wpdb;
    $table = $wpdb->prefix . 'sara_events';

    if (!is_array($item)) {
        return new WP_Error('invalid_event', 'Event item must be an object.', ['status' => 400]);
    }

    $event_uid = fourier_sara_clean_uid($item['event_uid'] ?? ('event_' . wp_generate_uuid4()));
    $source_id = isset($item['source_id']) ? (int) $item['source_id'] : null;
    $experience_uid = isset($item['experience_uid']) ? fourier_sara_clean_uid($item['experience_uid']) : null;

    $data = [
        'event_uid' => $event_uid,
        'source_id' => $source_id,
        'experience_uid' => $experience_uid,
        't' => fourier_sara_float($item['t'] ?? 0),
        'dt' => fourier_sara_float($item['dt'] ?? 0),
        'modality' => sanitize_key($item['modality'] ?? 'text'),
        'channel' => sanitize_text_field($item['channel'] ?? ''),
        'event_type' => sanitize_key($item['event_type'] ?? 'symbol'),
        'symbol' => sanitize_textarea_field($item['symbol'] ?? ''),
        'payload_json' => fourier_sara_json_encode($item['payload'] ?? []),
        'state_before_json' => fourier_sara_json_encode($item['state_before'] ?? []),
        'state_after_json' => fourier_sara_json_encode($item['state_after'] ?? []),
        'reward' => fourier_sara_float($item['reward'] ?? 0),
        'prediction_error' => fourier_sara_float($item['prediction_error'] ?? 0),
        'confidence' => fourier_sara_clamp(fourier_sara_float($item['confidence'] ?? 1), 0, 1),
        'quality_score' => fourier_sara_clamp(fourier_sara_float($item['quality_score'] ?? 0.5), 0, 1),
        'tags' => sanitize_text_field(fourier_sara_tags_to_string($item['tags'] ?? '')),
        'created_at' => current_time('mysql'),
    ];

    $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE event_uid = %s", $event_uid));
    if ($existing_id) {
        $wpdb->update($table, $data, ['id' => (int) $existing_id]);
        return ['id' => (int) $existing_id, 'event_uid' => $event_uid, 'updated' => true];
    }

    $ok = $wpdb->insert($table, $data);
    if (!$ok) {
        return new WP_Error('insert_failed', 'Failed to insert SARA event.', ['status' => 500]);
    }

    return ['id' => (int) $wpdb->insert_id, 'event_uid' => $event_uid, 'updated' => false];
}

/* ---------------------------------------------------------- */
/* ------------------ REST: Experience操作 ------------------- */
/* ---------------------------------------------------------- */

function fourier_sara_rest_get_experiences($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'sara_experiences';
    $limit = max(1, min(1000, (int) ($request->get_param('limit') ?: 100)));
    $split_name = sanitize_key($request->get_param('split') ?: '');

    if ($split_name !== '') {
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE split_name = %s ORDER BY id ASC LIMIT %d", $split_name, $limit), ARRAY_A);
    } else {
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id ASC LIMIT %d", $limit), ARRAY_A);
    }

    return rest_ensure_response(array_map('fourier_sara_decode_experience_row', $rows));
}

function fourier_sara_rest_upsert_experience($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'sara_experiences';
    $body = fourier_sara_get_json_body($request);

    $experience_uid = fourier_sara_clean_uid($body['experience_uid'] ?? ('experience_' . wp_generate_uuid4()));
    $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE experience_uid = %s", $experience_uid));

    $data = [
        'experience_uid' => $experience_uid,
        'source_id' => isset($body['source_id']) ? (int) $body['source_id'] : null,
        'title' => sanitize_text_field($body['title'] ?? ''),
        'state_before_json' => fourier_sara_json_encode($body['state_before'] ?? []),
        'event_summary_json' => fourier_sara_json_encode($body['event_summary'] ?? []),
        'state_after_json' => fourier_sara_json_encode($body['state_after'] ?? []),
        'reward' => fourier_sara_float($body['reward'] ?? 0),
        'prediction_error' => fourier_sara_float($body['prediction_error'] ?? 0),
        'quality_score' => fourier_sara_clamp(fourier_sara_float($body['quality_score'] ?? 0.5), 0, 1),
        'curriculum_level' => sanitize_key($body['curriculum_level'] ?? 'medium'),
        'split_name' => sanitize_key($body['split_name'] ?? 'train'),
        'tags' => sanitize_text_field(fourier_sara_tags_to_string($body['tags'] ?? '')),
        'updated_at' => current_time('mysql'),
    ];

    if ($existing_id) {
        $wpdb->update($table, $data, ['id' => (int) $existing_id]);
        $id = (int) $existing_id;
        $updated = true;
    } else {
        $data['created_at'] = current_time('mysql');
        $wpdb->insert($table, $data);
        $id = (int) $wpdb->insert_id;
        $updated = false;
    }

    return rest_ensure_response(['id' => $id, 'experience_uid' => $experience_uid, 'updated' => $updated]);
}

/* ---------------------------------------------------------- */
/* ------------------- REST: Text to Events ------------------ */
/* ---------------------------------------------------------- */

function fourier_sara_rest_text_to_events($request) {
    $body = fourier_sara_get_json_body($request);
    $text = (string) ($body['text'] ?? '');
    $source_id = isset($body['source_id']) ? (int) $body['source_id'] : null;
    $experience_uid = isset($body['experience_uid']) ? fourier_sara_clean_uid($body['experience_uid']) : ('experience_' . wp_generate_uuid4());
    $insert = !empty($body['insert']);

    if ($text === '') {
        return new WP_Error('empty_text', 'text is required.', ['status' => 400]);
    }

    $events = fourier_sara_text_to_relative_events($text, [
        'source_id' => $source_id,
        'experience_uid' => $experience_uid,
        'base_uid' => $body['base_uid'] ?? ('text_' . wp_generate_uuid4()),
    ]);

    if ($insert) {
        $inserted = [];
        foreach ($events as $event) {
            $inserted[] = fourier_sara_insert_event($event);
        }
        return rest_ensure_response(['experience_uid' => $experience_uid, 'count' => count($inserted), 'inserted' => $inserted, 'events' => $events]);
    }

    return rest_ensure_response(['experience_uid' => $experience_uid, 'count' => count($events), 'events' => $events]);
}

function fourier_sara_text_to_relative_events($text, $options = []) {
    $source_id = $options['source_id'] ?? null;
    $experience_uid = $options['experience_uid'] ?? null;
    $base_uid = fourier_sara_clean_uid($options['base_uid'] ?? ('text_' . wp_generate_uuid4()));

    $normalized = preg_replace('/\s+/u', ' ', trim($text));
    $chars = preg_split('//u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
    $events = [];
    $t = 0.0;
    $index = 0;
    $previous_symbol = '';

    foreach ($chars as $char) {
        $dt = fourier_sara_estimate_symbol_dt($char, $previous_symbol);
        $t += $dt;
        $events[] = [
            'event_uid' => $base_uid . '_char_' . str_pad((string) $index, 6, '0', STR_PAD_LEFT),
            'source_id' => $source_id,
            'experience_uid' => $experience_uid,
            't' => round($t, 4),
            'dt' => round($dt, 4),
            'modality' => 'text',
            'channel' => 'char',
            'event_type' => 'symbol_onset',
            'symbol' => $char,
            'payload' => [
                'unicode' => $char,
                'index' => $index,
                'relative_encoding' => 'dt_from_previous_symbol',
            ],
            'state_before' => $previous_symbol !== '' ? ['previous_symbol:' . $previous_symbol] : [],
            'state_after' => ['current_symbol:' . $char],
            'reward' => 0.0,
            'prediction_error' => 0.0,
            'confidence' => 1.0,
            'quality_score' => 0.7,
            'tags' => ['text', 'relative_time', 'sara'],
        ];
        $previous_symbol = $char;
        $index++;
    }

    return $events;
}

function fourier_sara_estimate_symbol_dt($char, $previous_symbol = '') {
    if ($char === '。' || $char === '!' || $char === '！' || $char === '?' || $char === '？') {
        return 0.45;
    }
    if ($char === '、' || $char === ',' || $char === '，') {
        return 0.22;
    }
    if ($char === ' ' || $char === "\n" || $char === "\t") {
        return 0.16;
    }
    if (preg_match('/[ぁ-んァ-ンーa-zA-Z0-9]/u', $char)) {
        return 0.08;
    }
    return 0.10;
}

/* ---------------------------------------------------------- */
/* ---------------------- REST: Export ----------------------- */
/* ---------------------------------------------------------- */

function fourier_sara_rest_export_jsonl($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'sara_events';
    $limit = max(1, min(10000, (int) ($request->get_param('limit') ?: 5000)));
    $after_id = max(0, (int) ($request->get_param('after_id') ?: 0));
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", $after_id, $limit), ARRAY_A);

    $lines = [];
    foreach ($rows as $row) {
        $lines[] = wp_json_encode(fourier_sara_decode_event_row($row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $response = new WP_REST_Response(implode("\n", $lines) . (empty($lines) ? '' : "\n"));
    $response->header('Content-Type', 'application/x-ndjson; charset=utf-8');
    $response->header('X-SARA-Event-Count', (string) count($lines));
    return $response;
}

function fourier_sara_rest_curriculum_manifest($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'sara_experiences';
    $limit = max(1, min(5000, (int) ($request->get_param('limit') ?: 1000)));
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY quality_score DESC, prediction_error DESC LIMIT %d", $limit), ARRAY_A);

    $manifest = [
        'schema' => 'sara_curriculum_manifest_v1',
        'created_at' => gmdate('c'),
        'source' => home_url('/'),
        'splits' => [
            'train' => [],
            'eval' => [],
            'repair' => [],
        ],
    ];

    foreach ($rows as $row) {
        $item = [
            'experience_uid' => $row['experience_uid'],
            'curriculum_level' => $row['curriculum_level'],
            'quality_score' => (float) $row['quality_score'],
            'prediction_error' => (float) $row['prediction_error'],
            'tags' => fourier_sara_string_to_tags($row['tags']),
        ];
        $split = isset($manifest['splits'][$row['split_name']]) ? $row['split_name'] : 'train';
        $manifest['splits'][$split][] = $item;
    }

    return rest_ensure_response($manifest);
}

/* ---------------------------------------------------------- */
/* ---------------------- デコード補助 ------------------------ */
/* ---------------------------------------------------------- */

function fourier_sara_decode_source_row($row) {
    return [
        'id' => (int) $row['id'],
        'source_uid' => $row['source_uid'],
        'source_type' => $row['source_type'],
        'title' => $row['title'],
        'source_url' => $row['source_url'],
        'license_hint' => $row['license_hint'],
        'meta' => fourier_sara_json_decode($row['meta_json']),
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

function fourier_sara_decode_event_row($row) {
    return [
        'id' => (int) $row['id'],
        'event_uid' => $row['event_uid'],
        'source_id' => $row['source_id'] !== null ? (int) $row['source_id'] : null,
        'experience_uid' => $row['experience_uid'],
        't' => (float) $row['t'],
        'dt' => (float) $row['dt'],
        'modality' => $row['modality'],
        'channel' => $row['channel'],
        'event_type' => $row['event_type'],
        'symbol' => $row['symbol'],
        'payload' => fourier_sara_json_decode($row['payload_json']),
        'state_before' => fourier_sara_json_decode($row['state_before_json']),
        'state_after' => fourier_sara_json_decode($row['state_after_json']),
        'reward' => (float) $row['reward'],
        'prediction_error' => (float) $row['prediction_error'],
        'confidence' => (float) $row['confidence'],
        'quality_score' => (float) $row['quality_score'],
        'tags' => fourier_sara_string_to_tags($row['tags']),
        'created_at' => $row['created_at'],
    ];
}

function fourier_sara_decode_experience_row($row) {
    return [
        'id' => (int) $row['id'],
        'experience_uid' => $row['experience_uid'],
        'source_id' => $row['source_id'] !== null ? (int) $row['source_id'] : null,
        'title' => $row['title'],
        'state_before' => fourier_sara_json_decode($row['state_before_json']),
        'event_summary' => fourier_sara_json_decode($row['event_summary_json']),
        'state_after' => fourier_sara_json_decode($row['state_after_json']),
        'reward' => (float) $row['reward'],
        'prediction_error' => (float) $row['prediction_error'],
        'quality_score' => (float) $row['quality_score'],
        'curriculum_level' => $row['curriculum_level'],
        'split_name' => $row['split_name'],
        'tags' => fourier_sara_string_to_tags($row['tags']),
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

/* ---------------------------------------------------------- */
/* ------------------------- 汎用補助 ------------------------- */
/* ---------------------------------------------------------- */

function fourier_sara_get_json_body($request) {
    $body = $request->get_json_params();
    if (!is_array($body)) {
        $body = [];
    }
    return $body;
}

function fourier_sara_json_encode($value) {
    return wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function fourier_sara_json_decode($value) {
    if ($value === null || $value === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function fourier_sara_clean_uid($uid) {
    $uid = sanitize_text_field((string) $uid);
    $uid = preg_replace('/[^a-zA-Z0-9_\-:.]/', '_', $uid);
    return mb_substr($uid, 0, 128);
}

function fourier_sara_float($value) {
    if (is_numeric($value)) {
        return (float) $value;
    }
    return 0.0;
}

function fourier_sara_clamp($value, $min, $max) {
    return max($min, min($max, $value));
}

function fourier_sara_tags_to_string($tags) {
    if (is_array($tags)) {
        return implode(',', array_map('sanitize_key', $tags));
    }
    return sanitize_text_field((string) $tags);
}

function fourier_sara_string_to_tags($tags) {
    if (!$tags) {
        return [];
    }
    $items = array_filter(array_map('trim', explode(',', (string) $tags)));
    return array_values($items);
}
