import re

filepath = '/Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/inc/functions_learning-data.php'
with open(filepath, 'r') as f:
    content = f.read()

# 1. Update _detect_and_format_import_item definition
content = content.replace("function _detect_and_format_import_item($raw)", "function _detect_and_format_import_item($raw, $force_format = 'auto')")

# 2. Update format assignment inside _detect_and_format_import_item
old_logic = """    $check_target = isset($raw['data']) && is_array($raw['data']) ? $raw['data'] : $raw;

    if (isset($check_target['instruction']) && isset($check_target['output'])) {"""

new_logic = """    $check_target = isset($raw['data']) && is_array($raw['data']) ? $raw['data'] : $raw;
    
    if ($force_format !== 'auto') {
        $format = $force_format;
    } else if (isset($check_target['instruction']) && isset($check_target['output'])) {"""

content = content.replace(old_logic, new_logic)

# 3. Update frontend_learning_data_import_preview_handler to pass $force_format
old_preview_start = """    $file = $_FILES['import_file'];
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $content = file_get_contents($file['tmp_name']);
    
    $parsed_items = [];"""

new_preview_start = """    $file = $_FILES['import_file'];
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $content = file_get_contents($file['tmp_name']);
    
    $force_format = isset($_POST['force_format']) ? sanitize_text_field($_POST['force_format']) : 'auto';
    
    $parsed_items = [];"""

content = content.replace(old_preview_start, new_preview_start)

# Update calls to _detect_and_format_import_item
content = content.replace("_detect_and_format_import_item($json);", "_detect_and_format_import_item($json, $force_format);")
content = content.replace("_detect_and_format_import_item($item);", "_detect_and_format_import_item($item, $force_format);")

with open(filepath, 'w') as f:
    f.write(content)

print("PHP backend updated.")

filepath_js = '/Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/page-import-export.php'
with open(filepath_js, 'r') as f:
    content_js = f.read()

# Add UI
ui_to_add = """                <div style="margin-bottom: 1rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 500; margin-bottom: 0.5rem;"><?php echo esc_html__('読み込みフォーマットの指定 (任意):', 'fourier'); ?></label>
                    <select id="import-force-format" class="auth-input" style="max-width: 300px; margin-bottom: 0;">
                        <option value="auto">自動推定 (Auto)</option>
                        <option value="chatml">ChatML</option>
                        <option value="sharegpt">ShareGPT</option>
                        <option value="instruction">Instruction</option>
                        <option value="cot">CoT</option>
                        <option value="dpo">DPO / RLHF</option>
                        <option value="frontend_code">Frontend Code</option>
                        <option value="plain">プレーンテキスト</option>
                        <option value="structured">構造化データ</option>
                    </select>
                </div>

                <div class="drop-zone" id="drop-zone">"""

content_js = content_js.replace('                <div class="drop-zone" id="drop-zone">', ui_to_add)

# Add fd.append
js_fd_old = """            const fd = new FormData();
            fd.append('action', 'frontend_learning_data_import_preview');
            fd.append('nonce', uploadNonce);
            fd.append('import_file', file);"""

js_fd_new = """            const fd = new FormData();
            fd.append('action', 'frontend_learning_data_import_preview');
            fd.append('nonce', uploadNonce);
            fd.append('import_file', file);
            fd.append('force_format', document.getElementById('import-force-format').value);"""

content_js = content_js.replace(js_fd_old, js_fd_new)

with open(filepath_js, 'w') as f:
    f.write(content_js)

print("JS frontend updated.")
