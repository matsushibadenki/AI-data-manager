import re

filepath = '/Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/inc/functions_learning-data.php'
with open(filepath, 'r') as f:
    content = f.read()

old_query_logic = """    $args = [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => [
            ['key' => 'is_learning_data', 'value' => '1']
        ]
    ];

    if (!empty($target_formats) && !in_array('all', $target_formats)) {
        $args['meta_query'][] = [
            'key' => 'learning_format',
            'value' => $target_formats,
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
            ];"""

new_query_logic = """    $args = [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => [
            ['key' => 'is_learning_data', 'value' => '1']
        ]
    ];

    $query = new WP_Query($args);
    $export_data = [];

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $content = json_decode(get_the_content(), true);
            if (!$content) continue;

            $post_format = isset($content['format']) ? $content['format'] : 'structured';
            
            // フィルタリング: target_formatsがallでない場合、JSON内のformatが一致するかチェック
            if (!empty($target_formats) && !in_array('all', $target_formats)) {
                if (!in_array($post_format, $target_formats)) {
                    continue;
                }
            }

            $item = [
                'title' => get_the_title(),
                'format' => $post_format,
                'data' => isset($content['data']) ? $content['data'] : []
            ];"""

content = content.replace(old_query_logic, new_query_logic)

with open(filepath, 'w') as f:
    f.write(content)

print("Export logic updated to filter by JSON format instead of meta_query.")
