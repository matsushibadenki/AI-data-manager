<?php
/*
 * Name: functions_rest_api.php
 * Description: 外部パイプライン等から学習データを取得するためのREST APIエンドポイント
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', 'fourier_register_rest_routes');

function fourier_register_rest_routes() {
    register_rest_route('fourier/v1', '/export-data', [
        'methods'  => 'GET',
        'callback' => 'fourier_rest_export_data',
        'permission_callback' => 'fourier_rest_permission_check',
    ]);

    register_rest_route('fourier/v1', '/learning-data', [
        'methods'  => 'POST',
        'callback' => 'fourier_rest_create_learning_data',
        'permission_callback' => 'fourier_rest_permission_check',
    ]);

    register_rest_route('fourier/v1', '/learning-data/(?P<id>\d+)', [
        [
            'methods'  => 'PUT',
            'callback' => 'fourier_rest_update_learning_data',
            'permission_callback' => 'fourier_rest_permission_check',
            'args'     => [
                'id' => ['validate_callback' => function($param, $request, $key) { return is_numeric($param); }]
            ],
        ],
        [
            'methods'  => 'DELETE',
            'callback' => 'fourier_rest_delete_learning_data',
            'permission_callback' => 'fourier_rest_permission_check',
            'args'     => [
                'id' => ['validate_callback' => function($param, $request, $key) { return is_numeric($param); }]
            ],
        ]
    ]);
}

/**
 * Bearer Tokenによる認証チェック
 */
function fourier_rest_permission_check($request) {
    $token = '';
    
    // 1. Check Header
    $auth_header = $request->get_header('authorization');
    if ($auth_header && preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
        $token = trim($matches[1]);
    } 
    // 2. Check Query Parameter
    else if ($request->get_param('token')) {
        $token = sanitize_text_field($request->get_param('token'));
    }

    if (empty($token)) {
        return new WP_Error('rest_forbidden', 'Authorization header or token parameter is missing.', ['status' => 401]);
    }
        
    // 全ユーザーの中からこのトークンを持つユーザーを探す
    // (大規模サイトではない前提のため、meta_queryで直接検索)
    $users = get_users([
        'meta_key' => 'fourier_server_access_token',
        'meta_value' => $token,
        'number' => 1
    ]);

    if (!empty($users)) {
        // 認証成功：特定のユーザーとして振る舞う場合は wp_set_current_user($users[0]->ID);
        return true;
    }

    return new WP_Error('rest_forbidden', 'Invalid token.', ['status' => 403]);
}

/**
 * データ抽出ロジック (JSONレスポンス)
 */
function fourier_rest_export_data($request) {
    $target_formats = $request->get_param('format');
    $format_array = [];
    if (!empty($target_formats)) {
        $format_array = explode(',', sanitize_text_field($target_formats));
    }

    $args = [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => [
            ['key' => 'is_learning_data', 'value' => '1']
        ]
    ];

    if (!empty($format_array) && !in_array('all', $format_array)) {
        $args['meta_query'][] = [
            'key' => 'learning_format',
            'value' => $format_array,
            'compare' => 'IN'
        ];
    }

    $query = new WP_Query($args);
    $export_data = [];

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $content = json_decode(get_the_content(), true);
            if (!$content) continue;

            $item = [
                'title' => get_the_title(),
                'format' => isset($content['format']) ? $content['format'] : '',
                'data' => isset($content['data']) ? $content['data'] : []
            ];

            $output_style = $request->get_param('output_style');
            if (empty($output_style)) {
                $output_style = 'raw';
            }

            if (function_exists('fourier_format_learning_data')) {
                $formatted_item = fourier_format_learning_data($item, $output_style);
                $export_data[] = $formatted_item;
            } else {
                $data = is_array($item['data']) ? $item['data'] : ['text' => $item['data']];
                $is_list = false;
                if (!empty($data)) {
                    $is_list = true;
                    $i = 0;
                    foreach ($data as $k => $v) {
                        if ($k !== $i++) {
                            $is_list = false;
                            break;
                        }
                    }
                }
                if ($is_list) {
                    $export_data[] = ['title' => $item['title'], 'data' => $data];
                } else {
                    $export_data[] = array_merge(['title' => $item['title']], $data);
                }
            }
        }
    }
    wp_reset_postdata();

    return rest_ensure_response($export_data);
}

/**
 * 学習データの登録 (POST)
 */
function fourier_rest_create_learning_data($request) {
    $params = $request->get_json_params();
    if (empty($params)) {
        return new WP_Error('invalid_payload', 'Payload must be JSON.', ['status' => 400]);
    }

    // もし配列が渡された場合はバルクインサートとして処理
    $is_bulk = isset($params[0]) && is_array($params[0]);
    $items = $is_bulk ? $params : [$params];
    $inserted_ids = [];

    foreach ($items as $item) {
        if (!isset($item['format']) || !isset($item['data'])) {
            continue; // 必須項目がない場合はスキップ
        }

        $title = isset($item['title']) ? sanitize_text_field($item['title']) : '[Auto] ' . sanitize_text_field($item['format']) . ' Data';
        $payload = [
            'format' => sanitize_text_field($item['format']),
            'data'   => $item['data']
        ];

        $post_id = wp_insert_post([
            'post_title'   => $title,
            'post_content' => wp_slash(json_encode($payload, JSON_UNESCAPED_UNICODE)),
            'post_status'  => 'publish',
            'post_type'    => 'post'
        ]);

        if (!is_wp_error($post_id)) {
            update_post_meta($post_id, 'is_learning_data', '1');
            update_post_meta($post_id, 'learning_format', sanitize_text_field($item['format']));
            if (isset($item['source'])) {
                update_post_meta($post_id, 'learning_data_source', sanitize_text_field($item['source']));
            }
            $inserted_ids[] = $post_id;
        }
    }

    if (empty($inserted_ids)) {
        return new WP_Error('insert_failed', 'Failed to insert any learning data. Check payload format.', ['status' => 400]);
    }

    return rest_ensure_response([
        'success' => true,
        'message' => count($inserted_ids) . ' item(s) created successfully.',
        'inserted_ids' => $inserted_ids
    ]);
}

/**
 * 学習データの更新 (PUT)
 */
function fourier_rest_update_learning_data($request) {
    $post_id = (int) $request->get_param('id');
    
    // 存在チェック & 学習データであるかチェック
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'post' || get_post_meta($post_id, 'is_learning_data', true) !== '1') {
        return new WP_Error('not_found', 'Learning data not found.', ['status' => 404]);
    }

    $params = $request->get_json_params();
    if (empty($params)) {
        return new WP_Error('invalid_payload', 'Payload must be JSON.', ['status' => 400]);
    }

    $update_args = ['ID' => $post_id];
    $meta_updates = [];

    // タイトル更新
    if (isset($params['title'])) {
        $update_args['post_title'] = sanitize_text_field($params['title']);
    }

    // コンテンツ(JSON)更新
    if (isset($params['format']) || isset($params['data'])) {
        $current_content = json_decode($post->post_content, true);
        if (!is_array($current_content)) {
            $current_content = [];
        }

        if (isset($params['format'])) {
            $current_content['format'] = sanitize_text_field($params['format']);
            $meta_updates['learning_format'] = $current_content['format'];
        }
        if (isset($params['data'])) {
            $current_content['data'] = $params['data'];
        }
        $update_args['post_content'] = wp_slash(json_encode($current_content, JSON_UNESCAPED_UNICODE));
    }

    $result = wp_update_post($update_args);
    if (is_wp_error($result)) {
        return new WP_Error('update_failed', 'Failed to update learning data.', ['status' => 500]);
    }

    foreach ($meta_updates as $key => $value) {
        update_post_meta($post_id, $key, $value);
    }
    
    if (isset($params['source'])) {
        update_post_meta($post_id, 'learning_data_source', sanitize_text_field($params['source']));
    }

    return rest_ensure_response([
        'success' => true,
        'message' => 'Learning data updated successfully.',
        'id' => $post_id
    ]);
}

/**
 * 学習データの削除 (DELETE)
 */
function fourier_rest_delete_learning_data($request) {
    $post_id = (int) $request->get_param('id');
    
    // 存在チェック & 学習データであるかチェック
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'post' || get_post_meta($post_id, 'is_learning_data', true) !== '1') {
        return new WP_Error('not_found', 'Learning data not found.', ['status' => 404]);
    }

    $result = wp_delete_post($post_id, true); // true = force delete (skip trash)
    
    if (!$result) {
        return new WP_Error('delete_failed', 'Failed to delete learning data.', ['status' => 500]);
    }

    return rest_ensure_response([
        'success' => true,
        'message' => 'Learning data deleted successfully.',
        'id' => $post_id
    ]);
}
