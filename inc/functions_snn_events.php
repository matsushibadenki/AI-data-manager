<?php
/*
 * Path: wp-content/themes/AI-data-manager/inc/functions_snn_events.php
 * Description: WordPress内の学習データをSNN向けの相対時間イベント列へ変換し、REST APIで外部へ渡す処理。
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', 'fourier_snn_register_rest_routes');

function fourier_snn_register_rest_routes() {
    register_rest_route('fourier/v1', '/snn-events', [
        'methods'  => 'GET',
        'callback' => 'fourier_snn_rest_export_events',
        'permission_callback' => 'fourier_rest_permission_check',
    ]);
}

/**
 * SNNイベント列エクスポートAPI。
 * Query params:
 * - format: plain,instruction,chatml など。all または未指定で全件。
 * - limit: 1〜500。既定100。
 * - after_id: 指定IDより大きい投稿だけ返す。増分取得用。
 * - include_raw: 1なら元JSONも含める。
 */
function fourier_snn_rest_export_events($request) {
    $target_formats = $request->get_param('format');
    $format_array = [];
    if (!empty($target_formats)) {
        $format_array = array_filter(array_map('sanitize_text_field', explode(',', $target_formats)));
    }

    $limit = intval($request->get_param('limit'));
    if ($limit <= 0 || $limit > 500) {
        $limit = 100;
    }

    $after_id = intval($request->get_param('after_id'));
    $include_raw = $request->get_param('include_raw') === '1';

    $meta_query = [
        ['key' => 'is_learning_data', 'value' => '1']
    ];

    if (!empty($format_array) && !in_array('all', $format_array, true)) {
        $meta_query[] = [
            'key' => 'learning_format',
            'value' => $format_array,
            'compare' => 'IN'
        ];
    }

    $args = [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'meta_query'     => $meta_query,
    ];

    if ($after_id > 0) {
        $args['post__not_in'] = range(1, $after_id);
    }

    $query = new WP_Query($args);
    $items = [];
    $last_id = $after_id;

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $last_id = max($last_id, $post_id);

            $content = json_decode(get_the_content(), true);
            if (!is_array($content)) {
                continue;
            }

            $sequence = fourier_snn_build_event_sequence($post_id, get_the_title(), $content);
            if ($include_raw) {
                $sequence['raw'] = $content;
            }
            $items[] = $sequence;
        }
    }
    wp_reset_postdata();

    return rest_ensure_response([
        'schema' => 'fourier_snn_export_v1',
        'count' => count($items),
        'last_id' => $last_id,
        'items' => $items,
    ]);
}

/**
 * 既存のLLM用JSONをSNN向けの相対時間イベント列へ変換する。
 */
function fourier_snn_build_event_sequence($post_id, $title, $payload) {
    $format = isset($payload['format']) ? sanitize_text_field($payload['format']) : 'unknown';
    $data = isset($payload['data']) ? $payload['data'] : [];
    $text_segments = fourier_snn_extract_text_segments($format, $data);

    $events = [];
    $links = [];
    $index = 0;
    $prev_event_id = null;

    foreach ($text_segments as $segment) {
        $role = isset($segment['role']) ? $segment['role'] : 'text';
        $text = isset($segment['text']) ? $segment['text'] : '';
        $tokens = fourier_snn_tokenize_text($text);

        foreach ($tokens as $token) {
            $event_id = 'e' . $index;
            $events[] = [
                'id' => $event_id,
                'dt' => $prev_event_id === null ? 0 : 1,
                't_rel' => $index,
                'channel' => 'text_token',
                'role' => $role,
                'symbol' => $token,
                'value' => 1.0,
            ];

            if ($prev_event_id !== null) {
                $links[] = [
                    'src' => $prev_event_id,
                    'dst' => $event_id,
                    'delay' => 1,
                    'type' => 'next_token'
                ];
            }

            $prev_event_id = $event_id;
            $index++;
        }
    }

    return [
        'schema' => 'snn_event_sequence_v1',
        'source' => [
            'wp_post_id' => $post_id,
            'title' => $title,
            'format' => $format,
        ],
        'time_unit' => 'relative_token_step',
        'channels' => ['text_token'],
        'events' => $events,
        'links' => $links,
        'stats' => [
            'event_count' => count($events),
            'link_count' => count($links),
        ],
    ];
}

/**
 * 学習データ形式ごとにテキスト断片へ正規化する。
 */
function fourier_snn_extract_text_segments($format, $data) {
    $segments = [];

    if ($format === 'plain') {
        $segments[] = ['role' => 'plain', 'text' => isset($data['text']) ? $data['text'] : ''];
    } elseif ($format === 'instruction') {
        $segments[] = ['role' => 'instruction', 'text' => isset($data['instruction']) ? $data['instruction'] : ''];
        $segments[] = ['role' => 'input', 'text' => isset($data['input']) ? $data['input'] : ''];
        $segments[] = ['role' => 'output', 'text' => isset($data['output']) ? $data['output'] : ''];
    } elseif ($format === 'chatml' && isset($data['messages']) && is_array($data['messages'])) {
        foreach ($data['messages'] as $message) {
            $segments[] = [
                'role' => isset($message['role']) ? sanitize_text_field($message['role']) : 'message',
                'text' => isset($message['content']) ? $message['content'] : ''
            ];
        }
    } elseif ($format === 'sharegpt' && isset($data['conversations']) && is_array($data['conversations'])) {
        foreach ($data['conversations'] as $message) {
            $segments[] = [
                'role' => isset($message['from']) ? sanitize_text_field($message['from']) : 'message',
                'text' => isset($message['value']) ? $message['value'] : ''
            ];
        }
    } elseif ($format === 'cot') {
        $segments[] = ['role' => 'question', 'text' => isset($data['question']) ? $data['question'] : ''];
        $segments[] = ['role' => 'thought', 'text' => isset($data['thought']) ? $data['thought'] : ''];
        $segments[] = ['role' => 'answer', 'text' => isset($data['answer']) ? $data['answer'] : ''];
    } elseif ($format === 'dpo') {
        $segments[] = ['role' => 'prompt', 'text' => isset($data['prompt']) ? $data['prompt'] : ''];
        $segments[] = ['role' => 'chosen', 'text' => isset($data['chosen']) ? $data['chosen'] : ''];
        $segments[] = ['role' => 'rejected', 'text' => isset($data['rejected']) ? $data['rejected'] : ''];
    } else {
        $segments[] = ['role' => $format, 'text' => wp_json_encode($data, JSON_UNESCAPED_UNICODE)];
    }

    return array_values(array_filter($segments, function($segment) {
        return isset($segment['text']) && trim((string)$segment['text']) !== '';
    }));
}

/**
 * 軽量トークナイザ。
 * 日本語はIntlBreakIteratorが使えれば語境界、なければ文字単位にフォールバックする。
 */
function fourier_snn_tokenize_text($text) {
    $text = trim(wp_strip_all_tags((string)$text));
    $text = preg_replace('/\s+/u', ' ', $text);
    if ($text === '') {
        return [];
    }

    if (class_exists('IntlBreakIterator')) {
        $iterator = IntlBreakIterator::createWordInstance('ja_JP');
        $iterator->setText($text);
        $tokens = [];
        $start = $iterator->first();
        for ($end = $iterator->next(); $end !== IntlBreakIterator::DONE; $start = $end, $end = $iterator->next()) {
            $token = trim(mb_substr($text, $start, $end - $start));
            if ($token !== '') {
                $tokens[] = $token;
            }
        }
        return $tokens;
    }

    preg_match_all('/[A-Za-z0-9_]+|[\p{Han}\p{Hiragana}\p{Katakana}]|[^\s]/u', $text, $matches);
    return isset($matches[0]) ? $matches[0] : [];
}
