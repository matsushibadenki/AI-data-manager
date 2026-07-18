import re

file_path = '/Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/page-index-text.php'

with open(file_path, 'r') as f:
    content = f.read()

helper_func = """<?php
/*
 * URL: /wp-content/themes/AI-data-manager/page-index-text.php
 * File Name: page-index-text.php
 * Description: 登録されたテキスト学習データをシート（テーブル）形式で表示するページ。LaTeX数式表示に対応し、一覧からのデータ削除などのアクションもサポート。
 * Template Name: Text Data Index
 */

if (!function_exists('_fourier_get_render_value')) {
    function _fourier_get_render_value($data, $key) {
        if (isset($data[$key])) {
            return is_string($data[$key]) ? $data[$key] : print_r($data[$key], true);
        } elseif (isset($data[0]) && is_array($data[0]) && isset($data[0][$key])) {
            $values = array_column($data, $key);
            return implode("\\n\\n---\\n\\n", array_map(function($v) {
                return is_string($v) ? $v : print_r($v, true);
            }, $values));
        }
        return '';
    }
}
"""

content = re.sub(r'<\?php\n/\*\n \* URL: /wp-content/themes/AI-data-manager/page-index-text.php\n \* File Name: page-index-text.php\n \* Description: 登録されたテキスト学習データをシート（テーブル）形式で表示するページ。LaTeX数式表示に対応し、一覧からのデータ削除などのアクションもサポート。\n \* Template Name: Text Data Index\n \*/\n', helper_func, content)

# Replace <?php echo esc_html(isset($item['data']['KEY']) ? $item['data']['KEY'] : ''); ?>
# with <?php echo esc_html(_fourier_get_render_value($item['data'], 'KEY')); ?>
pattern = r"<\?php echo esc_html\(isset\(\$item\['data'\]\['(.*?)'\]\) \? \$item\['data'\]\['\1'\] : ''\); \?>"
replacement = r"<?php echo esc_html(_fourier_get_render_value($item['data'], '\1')); ?>"

content = re.sub(pattern, replacement, content)

with open(file_path, 'w') as f:
    f.write(content)

print("done")
