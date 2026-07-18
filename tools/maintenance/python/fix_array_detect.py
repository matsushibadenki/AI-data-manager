import re

filepath = '/Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/inc/functions_learning-data.php'
with open(filepath, 'r') as f:
    content = f.read()

old_logic = """    if ($force_format !== 'auto') {
        $format = $force_format;
    } else if (isset($check_target['instruction']) && isset($check_target['output'])) {
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
        $format = 'sharegpt';
    }"""

new_logic = """    if ($force_format !== 'auto') {
        $format = $force_format;
    } else if (isset($check_target['instruction']) && isset($check_target['output'])) {
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
    } else if (is_array($check_target) && wp_is_numeric_array($check_target) && !empty($check_target)) {
        // 配列（リスト）パターンのフォールバック
        $first = $check_target[0];
        if (isset($first['instruction']) && isset($first['output'])) {
            $format = 'instruction';
        } else if (isset($first['role'])) {
            $format = 'chatml';
        } else if (isset($first['from'])) {
            $format = 'sharegpt';
        } else if (isset($first['question']) && isset($first['thought']) && isset($first['answer'])) {
            $format = 'cot';
        } else if (isset($first['prompt']) && isset($first['chosen']) && isset($first['rejected'])) {
            $format = 'dpo';
        }
    }"""

content = content.replace(old_logic, new_logic)
with open(filepath, 'w') as f:
    f.write(content)

filepath_js = '/Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/page-import-export.php'
with open(filepath_js, 'r') as f:
    content_js = f.read()

old_js_logic = """            if (forceFormat !== 'auto') {
                format = forceFormat;
            } else if (checkTarget.instruction && checkTarget.output) {
                format = 'instruction';
            } else if (checkTarget.messages && Array.isArray(checkTarget.messages)) {
                format = 'chatml';
            } else if (checkTarget.conversations && Array.isArray(checkTarget.conversations)) {
                format = 'sharegpt';
            } else if (checkTarget.question && checkTarget.thought && checkTarget.answer) {
                format = 'cot';
            } else if (checkTarget.prompt && checkTarget.chosen && checkTarget.rejected) {
                format = 'dpo';
            } else if (checkTarget.html || checkTarget.css || checkTarget.js) {
                format = 'frontend_code';
            } else if (checkTarget.text && Object.keys(checkTarget).length === 1) {
                format = 'plain';
            } else if (Array.isArray(checkTarget) && checkTarget.length > 0 && checkTarget[0].role) {
                format = 'chatml';
            } else if (Array.isArray(checkTarget) && checkTarget.length > 0 && checkTarget[0].from) {
                format = 'sharegpt';
            }"""

new_js_logic = """            if (forceFormat !== 'auto') {
                format = forceFormat;
            } else if (checkTarget.instruction && checkTarget.output) {
                format = 'instruction';
            } else if (checkTarget.messages && Array.isArray(checkTarget.messages)) {
                format = 'chatml';
            } else if (checkTarget.conversations && Array.isArray(checkTarget.conversations)) {
                format = 'sharegpt';
            } else if (checkTarget.question && checkTarget.thought && checkTarget.answer) {
                format = 'cot';
            } else if (checkTarget.prompt && checkTarget.chosen && checkTarget.rejected) {
                format = 'dpo';
            } else if (checkTarget.html || checkTarget.css || checkTarget.js) {
                format = 'frontend_code';
            } else if (checkTarget.text && Object.keys(checkTarget).length === 1) {
                format = 'plain';
            } else if (Array.isArray(checkTarget) && checkTarget.length > 0) {
                const first = checkTarget[0];
                if (first.instruction && first.output) {
                    format = 'instruction';
                } else if (first.role) {
                    format = 'chatml';
                } else if (first.from) {
                    format = 'sharegpt';
                } else if (first.question && first.thought && first.answer) {
                    format = 'cot';
                } else if (first.prompt && first.chosen && first.rejected) {
                    format = 'dpo';
                }
            }"""

content_js = content_js.replace(old_js_logic, new_js_logic)
with open(filepath_js, 'w') as f:
    f.write(content_js)

print("Updated array fallback detection in both PHP and JS.")
