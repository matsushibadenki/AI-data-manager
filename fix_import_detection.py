import re

filepath = '/Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/inc/functions_learning-data.php'

with open(filepath, 'r') as f:
    content = f.read()

old_detect = """    if (isset($raw['instruction']) && isset($raw['output'])) {
        $format = 'instruction';
    } else if (isset($raw['messages']) && is_array($raw['messages'])) {
        $format = 'chatml';
    } else if (isset($raw['conversations']) && is_array($raw['conversations'])) {
        $format = 'sharegpt';
    } else if (isset($raw['question']) && isset($raw['thought']) && isset($raw['answer'])) {
        $format = 'cot';
    } else if (isset($raw['prompt']) && isset($raw['chosen']) && isset($raw['rejected'])) {
        $format = 'dpo';
    } else if (isset($raw['html']) || isset($raw['css']) || isset($raw['js'])) {
        $format = 'frontend_code';
    } else if (isset($raw['text']) && count($raw) === 1) {
        $format = 'plain';
    }"""

new_detect = """    // ネストされたdata配列がある場合のチェック用
    $check_target = isset($raw['data']) && is_array($raw['data']) ? $raw['data'] : $raw;

    if (isset($check_target['instruction']) && isset($check_target['output'])) {
        $format = 'instruction';
    } else if (isset($check_target['messages']) && is_array($check_target['messages'])) {
        $format = 'chatml';
    } else if (isset($check_target['conversations']) && is_array($check_target['conversations'])) {
        $format = 'sharegpt';
    } else if (isset($check_target['question']) && isset($check_target['thought']) && isset($check_target['answer'])) {
        $format = 'cot';
    } else if (isset($check_target['prompt']) && isset($check_target['chosen']) && isset($check_target['rejected'])) {
        $format = 'dpo';
    } else if (isset($check_target['html']) || isset($check_target['css']) || isset($check_target['js'])) {
        $format = 'frontend_code';
    } else if (isset($check_target['text']) && count($check_target) === 1) {
        $format = 'plain';
    } else if (is_array($check_target) && isset($check_target[0]['role'])) {
        $format = 'chatml'; // Ollama等の配列直接出力パターンのフォールバック
    } else if (is_array($check_target) && isset($check_target[0]['from'])) {
        $format = 'sharegpt'; // Ollama等の配列直接出力パターンのフォールバック
    }"""

content = content.replace(old_detect, new_detect)

with open(filepath, 'w') as f:
    f.write(content)

print("Import detection logic updated.")
