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
}

/**
 * Bearer Tokenによる認証チェック
 */
function fourier_rest_permission_check($request) {
    $auth_header = $request->get_header('authorization');
    if (!$auth_header) {
        return new WP_Error('rest_forbidden', 'Authorization header is missing.', ['status' => 401]);
    }

    if (preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
        $token = trim($matches[1]);
        
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
                $merged = array_merge(['title' => $item['title']], is_array($item['data']) ? $item['data'] : ['text' => $item['data']]);
                $export_data[] = $merged;
            }
        }
    }
    wp_reset_postdata();

    return rest_ensure_response($export_data);
}
