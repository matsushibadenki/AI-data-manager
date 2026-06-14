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
    if ($installed_version !== '2.0.0') {
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
    $relations_table = $wpdb->prefix . 'sara_relations';
    $concepts_table = $wpdb->prefix . 'sara_concepts';
    $priority_table = $wpdb->prefix . 'sara_priority';

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
        proposal_source VARCHAR(32) NOT NULL DEFAULT 'manual',
        extractor_name VARCHAR(128) NULL,
        extractor_version VARCHAR(64) NULL,
        verification_state VARCHAR(32) NOT NULL DEFAULT 'unverified',
        evidence_type VARCHAR(64) NOT NULL DEFAULT 'candidate',
        source_hash VARCHAR(128) NULL,
        event_cost DOUBLE NOT NULL DEFAULT 1,
        novelty DOUBLE NOT NULL DEFAULT 0,
        redundancy DOUBLE NOT NULL DEFAULT 0,
        coverage DOUBLE NOT NULL DEFAULT 0,
        priority_score DOUBLE NOT NULL DEFAULT 0,
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
        KEY quality_score (quality_score),
        KEY proposal_source (proposal_source),
        KEY verification_state (verification_state),
        KEY priority_score (priority_score)
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


    $sql_relations = "CREATE TABLE {$relations_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        relation_uid VARCHAR(128) NOT NULL,
        source_event_uid VARCHAR(128) NOT NULL,
        relation_type VARCHAR(64) NOT NULL DEFAULT 'predicts',
        target_event_uid VARCHAR(128) NOT NULL,
        min_delay_ms DOUBLE NOT NULL DEFAULT 0,
        max_delay_ms DOUBLE NOT NULL DEFAULT 0,
        confidence DOUBLE NOT NULL DEFAULT 0.5,
        evidence_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        counterexample_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        verification_state VARCHAR(32) NOT NULL DEFAULT 'unverified',
        proposal_source VARCHAR(32) NOT NULL DEFAULT 'manual',
        payload_json LONGTEXT NULL,
        expiry DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY relation_uid (relation_uid),
        KEY source_event_uid (source_event_uid),
        KEY target_event_uid (target_event_uid),
        KEY relation_type (relation_type),
        KEY verification_state (verification_state)
    ) {$charset_collate};";

    $sql_concepts = "CREATE TABLE {$concepts_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        concept_uid VARCHAR(128) NOT NULL,
        label TEXT NULL,
        concept_type VARCHAR(64) NOT NULL DEFAULT 'dynamic_mode',
        evidence_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        contradiction_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        verification_state VARCHAR(32) NOT NULL DEFAULT 'candidate',
        utility_score DOUBLE NOT NULL DEFAULT 0,
        event_pattern_json LONGTEXT NULL,
        source_refs_json LONGTEXT NULL,
        payload_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY concept_uid (concept_uid),
        KEY concept_type (concept_type),
        KEY verification_state (verification_state),
        KEY utility_score (utility_score)
    ) {$charset_collate};";

    $sql_priority = "CREATE TABLE {$priority_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        item_uid VARCHAR(128) NOT NULL,
        item_type VARCHAR(32) NOT NULL DEFAULT 'event',
        priority_score DOUBLE NOT NULL DEFAULT 0,
        prediction_error DOUBLE NOT NULL DEFAULT 0,
        novelty DOUBLE NOT NULL DEFAULT 0,
        reward DOUBLE NOT NULL DEFAULT 0,
        coverage DOUBLE NOT NULL DEFAULT 0,
        redundancy DOUBLE NOT NULL DEFAULT 0,
        reason_json LONGTEXT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'queued',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY item_uid_type (item_uid, item_type),
        KEY priority_score (priority_score),
        KEY status (status)
    ) {$charset_collate};";

    dbDelta($sql_sources);
    dbDelta($sql_events);
    dbDelta($sql_experiences);

    dbDelta($sql_relations);
    dbDelta($sql_concepts);
    dbDelta($sql_priority);

    update_option('fourier_sara_event_memory_schema_version', '2.0.0');
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
    $relations_table = $wpdb->prefix . 'sara_relations';
    $concepts_table = $wpdb->prefix . 'sara_concepts';
    $priority_table = $wpdb->prefix . 'sara_priority';
    $relation_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$relations_table}");
    $concept_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$concepts_table}");
    $priority_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$priority_table}");
    $latest_events = $wpdb->get_results("SELECT id, event_uid, modality, event_type, symbol, prediction_error, quality_score, created_at FROM {$events_table} ORDER BY id DESC LIMIT 20", ARRAY_A);
    $token = fourier_sara_get_current_user_token();
    $endpoint = esc_url_raw(rest_url('fourier/v1/sara/events'));
    $jsonl_endpoint = esc_url_raw(rest_url('fourier/v1/sara/export-jsonl'));

    ?>
    <div class="wrap">
        <h1>SARA Event Memory</h1>
        <p>WordPressをSARA Engine用の海馬相当 Event Memory として使うための管理画面です。SNN本体はここでは動かさず、sparse events / experience records / relation graph / concept crystals / curriculum manifestを管理・出力します。</p>
        <p><strong>研究モード:</strong> SNN-only / ANN-assisted / Hybrid の候補イベントを同じEvent Memoryへ保存し、<code>proposal_source</code> と <code>verification_state</code> で比較・検証します。WordPressは正解判定器ではなく、候補・証拠・検証状態・履歴を保存する層です。</p>

        <h2>概要</h2>
        <table class="widefat striped" style="max-width: 760px;">
            <tbody>
                <tr><th>Sources</th><td><?php echo esc_html($source_count); ?></td></tr>
                <tr><th>Events</th><td><?php echo esc_html($event_count); ?></td></tr>
                <tr><th>Experiences</th><td><?php echo esc_html($experience_count); ?></td></tr>
                <tr><th>Relations</th><td><?php echo esc_html($relation_count); ?></td></tr>
                <tr><th>Concept Crystals</th><td><?php echo esc_html($concept_count); ?></td></tr>
                <tr><th>Priority Queue</th><td><?php echo esc_html($priority_count); ?></td></tr>
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


    register_rest_route('fourier/v1', '/sara/relations', [
        [
            'methods' => 'GET',
            'callback' => 'fourier_sara_rest_get_relations',
            'permission_callback' => 'fourier_sara_rest_permission_check',
        ],
        [
            'methods' => 'POST',
            'callback' => 'fourier_sara_rest_upsert_relation',
            'permission_callback' => 'fourier_sara_rest_permission_check',
        ],
    ]);

    register_rest_route('fourier/v1', '/sara/concepts', [
        [
            'methods' => 'GET',
            'callback' => 'fourier_sara_rest_get_concepts',
            'permission_callback' => 'fourier_sara_rest_permission_check',
        ],
        [
            'methods' => 'POST',
            'callback' => 'fourier_sara_rest_upsert_concept',
            'permission_callback' => 'fourier_sara_rest_permission_check',
        ],
    ]);

    register_rest_route('fourier/v1', '/sara/priority', [
        [
            'methods' => 'GET',
            'callback' => 'fourier_sara_rest_get_priority',
            'permission_callback' => 'fourier_sara_rest_permission_check',
        ],
        [
            'methods' => 'POST',
            'callback' => 'fourier_sara_rest_upsert_priority',
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
        'proposal_source' => fourier_sara_clean_proposal_source($item['proposal_source'] ?? 'manual'),
        'extractor_name' => sanitize_text_field($item['extractor_name'] ?? ''),
        'extractor_version' => sanitize_text_field($item['extractor_version'] ?? ''),
        'verification_state' => fourier_sara_clean_verification_state($item['verification_state'] ?? 'unverified'),
        'evidence_type' => sanitize_key($item['evidence_type'] ?? 'candidate'),
        'source_hash' => sanitize_text_field($item['source_hash'] ?? ''),
        'event_cost' => max(0.0, fourier_sara_float($item['event_cost'] ?? 1)),
        'novelty' => fourier_sara_clamp(fourier_sara_float($item['novelty'] ?? 0), 0, 1),
        'redundancy' => fourier_sara_clamp(fourier_sara_float($item['redundancy'] ?? 0), 0, 1),
        'coverage' => fourier_sara_clamp(fourier_sara_float($item['coverage'] ?? 0), 0, 1),
        'priority_score' => fourier_sara_calculate_priority($item),
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
/* -------- REST: Relation / Concept / Priority操作 ---------- */
/* ---------------------------------------------------------- */

function fourier_sara_rest_get_relations($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'sara_relations';
    $limit = max(1, min(2000, (int) ($request->get_param('limit') ?: 200)));
    $verification_state = sanitize_key($request->get_param('verification_state') ?: '');

    if ($verification_state !== '') {
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE verification_state = %s ORDER BY id DESC LIMIT %d", $verification_state, $limit), ARRAY_A);
    } else {
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
    }

    return rest_ensure_response(array_map('fourier_sara_decode_relation_row', $rows));
}

function fourier_sara_rest_upsert_relation($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'sara_relations';
    $body = fourier_sara_get_json_body($request);

    $relation_uid = fourier_sara_clean_uid($body['relation_uid'] ?? ('relation_' . wp_generate_uuid4()));
    $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE relation_uid = %s", $relation_uid));

    $data = [
        'relation_uid' => $relation_uid,
        'source_event_uid' => fourier_sara_clean_uid($body['source_event_uid'] ?? ''),
        'relation_type' => sanitize_key($body['relation_type'] ?? 'predicts'),
        'target_event_uid' => fourier_sara_clean_uid($body['target_event_uid'] ?? ''),
        'min_delay_ms' => fourier_sara_float($body['min_delay_ms'] ?? 0),
        'max_delay_ms' => fourier_sara_float($body['max_delay_ms'] ?? 0),
        'confidence' => fourier_sara_clamp(fourier_sara_float($body['confidence'] ?? 0.5), 0, 1),
        'evidence_count' => max(0, (int) ($body['evidence_count'] ?? 0)),
        'counterexample_count' => max(0, (int) ($body['counterexample_count'] ?? 0)),
        'verification_state' => fourier_sara_clean_verification_state($body['verification_state'] ?? 'unverified'),
        'proposal_source' => fourier_sara_clean_proposal_source($body['proposal_source'] ?? 'manual'),
        'payload_json' => fourier_sara_json_encode($body['payload'] ?? []),
        'expiry' => !empty($body['expiry']) ? sanitize_text_field($body['expiry']) : null,
        'updated_at' => current_time('mysql'),
    ];

    if ($data['source_event_uid'] === '' || $data['target_event_uid'] === '') {
        return new WP_Error('invalid_relation', 'source_event_uid and target_event_uid are required.', ['status' => 400]);
    }

    if ($existing_id) {
        $wpdb->update($table, $data, ['id' => (int) $existing_id]);
        return rest_ensure_response(['id' => (int) $existing_id, 'relation_uid' => $relation_uid, 'updated' => true]);
    }

    $data['created_at'] = current_time('mysql');
    $wpdb->insert($table, $data);
    return rest_ensure_response(['id' => (int) $wpdb->insert_id, 'relation_uid' => $relation_uid, 'updated' => false]);
}

function fourier_sara_rest_get_concepts($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'sara_concepts';
    $limit = max(1, min(2000, (int) ($request->get_param('limit') ?: 200)));
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY utility_score DESC, id DESC LIMIT %d", $limit), ARRAY_A);
    return rest_ensure_response(array_map('fourier_sara_decode_concept_row', $rows));
}

function fourier_sara_rest_upsert_concept($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'sara_concepts';
    $body = fourier_sara_get_json_body($request);

    $concept_uid = fourier_sara_clean_uid($body['concept_uid'] ?? ('concept_' . wp_generate_uuid4()));
    $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE concept_uid = %s", $concept_uid));

    $data = [
        'concept_uid' => $concept_uid,
        'label' => sanitize_text_field($body['label'] ?? ''),
        'concept_type' => sanitize_key($body['concept_type'] ?? 'dynamic_mode'),
        'evidence_count' => max(0, (int) ($body['evidence_count'] ?? 0)),
        'contradiction_count' => max(0, (int) ($body['contradiction_count'] ?? 0)),
        'verification_state' => fourier_sara_clean_verification_state($body['verification_state'] ?? 'candidate'),
        'utility_score' => fourier_sara_float($body['utility_score'] ?? 0),
        'event_pattern_json' => fourier_sara_json_encode($body['event_pattern'] ?? []),
        'source_refs_json' => fourier_sara_json_encode($body['source_refs'] ?? []),
        'payload_json' => fourier_sara_json_encode($body['payload'] ?? []),
        'updated_at' => current_time('mysql'),
    ];

    if ($existing_id) {
        $wpdb->update($table, $data, ['id' => (int) $existing_id]);
        return rest_ensure_response(['id' => (int) $existing_id, 'concept_uid' => $concept_uid, 'updated' => true]);
    }

    $data['created_at'] = current_time('mysql');
    $wpdb->insert($table, $data);
    return rest_ensure_response(['id' => (int) $wpdb->insert_id, 'concept_uid' => $concept_uid, 'updated' => false]);
}

function fourier_sara_rest_get_priority($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'sara_priority';
    $limit = max(1, min(2000, (int) ($request->get_param('limit') ?: 200)));
    $status = sanitize_key($request->get_param('status') ?: 'queued');
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE status = %s ORDER BY priority_score DESC, id ASC LIMIT %d", $status, $limit), ARRAY_A);
    return rest_ensure_response(array_map('fourier_sara_decode_priority_row', $rows));
}

function fourier_sara_rest_upsert_priority($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'sara_priority';
    $body = fourier_sara_get_json_body($request);

    $item_uid = fourier_sara_clean_uid($body['item_uid'] ?? '');
    $item_type = sanitize_key($body['item_type'] ?? 'event');
    if ($item_uid === '') {
        return new WP_Error('invalid_priority', 'item_uid is required.', ['status' => 400]);
    }

    $priority_score = isset($body['priority_score']) ? fourier_sara_float($body['priority_score']) : fourier_sara_calculate_priority($body);
    $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE item_uid = %s AND item_type = %s", $item_uid, $item_type));

    $data = [
        'item_uid' => $item_uid,
        'item_type' => $item_type,
        'priority_score' => $priority_score,
        'prediction_error' => fourier_sara_clamp(fourier_sara_float($body['prediction_error'] ?? 0), 0, 1),
        'novelty' => fourier_sara_clamp(fourier_sara_float($body['novelty'] ?? 0), 0, 1),
        'reward' => fourier_sara_float($body['reward'] ?? 0),
        'coverage' => fourier_sara_clamp(fourier_sara_float($body['coverage'] ?? 0), 0, 1),
        'redundancy' => fourier_sara_clamp(fourier_sara_float($body['redundancy'] ?? 0), 0, 1),
        'reason_json' => fourier_sara_json_encode($body['reason'] ?? []),
        'status' => sanitize_key($body['status'] ?? 'queued'),
        'updated_at' => current_time('mysql'),
    ];

    if ($existing_id) {
        $wpdb->update($table, $data, ['id' => (int) $existing_id]);
        return rest_ensure_response(['id' => (int) $existing_id, 'item_uid' => $item_uid, 'updated' => true]);
    }

    $data['created_at'] = current_time('mysql');
    $wpdb->insert($table, $data);
    return rest_ensure_response(['id' => (int) $wpdb->insert_id, 'item_uid' => $item_uid, 'updated' => false]);
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
            'proposal_source' => 'signal_processing',
            'extractor_name' => 'fourier_sara_text_to_relative_events',
            'extractor_version' => '2.0.0',
            'verification_state' => 'observed',
            'evidence_type' => 'observed_text',
            'event_cost' => 1.0,
            'novelty' => 0.0,
            'redundancy' => 0.0,
            'coverage' => 0.5,
            'tags' => ['text', 'relative_time', 'sara', 'snn_only_compatible'],
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
        'proposal_source' => $row['proposal_source'] ?? 'manual',
        'extractor_name' => $row['extractor_name'] ?? '',
        'extractor_version' => $row['extractor_version'] ?? '',
        'verification_state' => $row['verification_state'] ?? 'unverified',
        'evidence_type' => $row['evidence_type'] ?? 'candidate',
        'source_hash' => $row['source_hash'] ?? '',
        'event_cost' => isset($row['event_cost']) ? (float) $row['event_cost'] : 1.0,
        'novelty' => isset($row['novelty']) ? (float) $row['novelty'] : 0.0,
        'redundancy' => isset($row['redundancy']) ? (float) $row['redundancy'] : 0.0,
        'coverage' => isset($row['coverage']) ? (float) $row['coverage'] : 0.0,
        'priority_score' => isset($row['priority_score']) ? (float) $row['priority_score'] : 0.0,
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


function fourier_sara_decode_relation_row($row) {
    return [
        'id' => (int) $row['id'],
        'relation_uid' => $row['relation_uid'],
        'source_event_uid' => $row['source_event_uid'],
        'relation_type' => $row['relation_type'],
        'target_event_uid' => $row['target_event_uid'],
        'min_delay_ms' => (float) $row['min_delay_ms'],
        'max_delay_ms' => (float) $row['max_delay_ms'],
        'confidence' => (float) $row['confidence'],
        'evidence_count' => (int) $row['evidence_count'],
        'counterexample_count' => (int) $row['counterexample_count'],
        'verification_state' => $row['verification_state'],
        'proposal_source' => $row['proposal_source'],
        'payload' => fourier_sara_json_decode($row['payload_json']),
        'expiry' => $row['expiry'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

function fourier_sara_decode_concept_row($row) {
    return [
        'id' => (int) $row['id'],
        'concept_uid' => $row['concept_uid'],
        'label' => $row['label'],
        'concept_type' => $row['concept_type'],
        'evidence_count' => (int) $row['evidence_count'],
        'contradiction_count' => (int) $row['contradiction_count'],
        'verification_state' => $row['verification_state'],
        'utility_score' => (float) $row['utility_score'],
        'event_pattern' => fourier_sara_json_decode($row['event_pattern_json']),
        'source_refs' => fourier_sara_json_decode($row['source_refs_json']),
        'payload' => fourier_sara_json_decode($row['payload_json']),
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

function fourier_sara_decode_priority_row($row) {
    return [
        'id' => (int) $row['id'],
        'item_uid' => $row['item_uid'],
        'item_type' => $row['item_type'],
        'priority_score' => (float) $row['priority_score'],
        'prediction_error' => (float) $row['prediction_error'],
        'novelty' => (float) $row['novelty'],
        'reward' => (float) $row['reward'],
        'coverage' => (float) $row['coverage'],
        'redundancy' => (float) $row['redundancy'],
        'reason' => fourier_sara_json_decode($row['reason_json']),
        'status' => $row['status'],
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


function fourier_sara_clean_proposal_source($value) {
    $value = sanitize_key((string) $value);
    $allowed = ['manual', 'snn', 'ann', 'hybrid', 'signal_processing', 'rule', 'import'];
    return in_array($value, $allowed, true) ? $value : 'manual';
}

function fourier_sara_clean_verification_state($value) {
    $value = sanitize_key((string) $value);
    $allowed = ['observed', 'unverified', 'candidate', 'provisional', 'verified', 'contradicted', 'quarantined', 'rejected'];
    return in_array($value, $allowed, true) ? $value : 'unverified';
}

function fourier_sara_calculate_priority($item) {
    $prediction_error = fourier_sara_clamp(fourier_sara_float($item['prediction_error'] ?? 0), 0, 1);
    $novelty = fourier_sara_clamp(fourier_sara_float($item['novelty'] ?? 0), 0, 1);
    $reward = fourier_sara_clamp(abs(fourier_sara_float($item['reward'] ?? 0)), 0, 1);
    $coverage = fourier_sara_clamp(fourier_sara_float($item['coverage'] ?? 0), 0, 1);
    $redundancy = fourier_sara_clamp(fourier_sara_float($item['redundancy'] ?? 0), 0, 1);
    $event_cost = max(0.01, fourier_sara_float($item['event_cost'] ?? 1));

    $score = ($prediction_error * 0.32) + ($novelty * 0.22) + ($reward * 0.18) + ($coverage * 0.18) - ($redundancy * 0.20);
    return round($score / sqrt($event_cost), 6);
}

