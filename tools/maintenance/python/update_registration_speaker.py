import re

file_path = '/Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/page-ai-registration.php'
with open(file_path, 'r') as f:
    content = f.read()

# Scrape UI
scrape_insert = """                    <div class="upload-form-group" style="margin-top: 1rem;">
                        <label for="scrape-speaker-names"><?php echo esc_html__('話者名 (対談・インタビュー等の場合):', 'fourier'); ?></label>
                        <input type="text" id="scrape-speaker-names" class="upload-form-input" placeholder="例: ゲスト名, インタビュアー (空欄の場合は不特定の話し手)" />
                    </div>
                    <div class="upload-form-group" style="margin-top: 1rem;">
                        <label for="scrape-prompt">"""
content = content.replace('                    <div class="upload-form-group" style="margin-top: 1rem;">\n                        <label for="scrape-prompt">', scrape_insert)

# Distill UI
distill_insert = """                    <div class="upload-form-group" style="margin-top: 1rem;">
                        <label for="distill-speaker-names"><?php echo esc_html__('話者名 (対談・インタビュー等の場合):', 'fourier'); ?></label>
                        <input type="text" id="distill-speaker-names" class="upload-form-input" placeholder="例: ゲスト名, インタビュアー (空欄の場合は不特定の話し手)" />
                    </div>
                    <div class="upload-form-group" style="margin-top: 1rem;">
                        <label for="distill-prompt">"""
content = content.replace('                    <div class="upload-form-group" style="margin-top: 1rem;">\n                        <label for="distill-prompt">', distill_insert)


# Scrape JS
scrape_js_insert = """
                formData.append('speaker_names', document.getElementById('scrape-speaker-names').value);
                formData.append('prompt', document.getElementById('scrape-prompt').value);
"""
content = re.sub(r"formData\.append\('prompt', document\.getElementById\('scrape-prompt'\)\.value\);", scrape_js_insert.strip(), content)

# Distill JS
distill_js_insert = """
                formData.append('speaker_names', document.getElementById('distill-speaker-names').value);
                formData.append('prompt', document.getElementById('distill-prompt').value);
"""
content = re.sub(r"formData\.append\('prompt', document\.getElementById\('distill-prompt'\)\.value\);", distill_js_insert.strip(), content)

with open(file_path, 'w') as f:
    f.write(content)

print("page-ai-registration updated.")

api_path = '/Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/inc/functions_llm_api.php'
with open(api_path, 'r') as f:
    api_content = f.read()

# Update Scrape handler
scrape_handler_append = """
    $extra_prompt = isset($_POST['prompt']) ? sanitize_text_field($_POST['prompt']) : '';
    $speaker_names = isset($_POST['speaker_names']) ? sanitize_text_field($_POST['speaker_names']) : '';
"""
api_content = api_content.replace("$extra_prompt = isset($_POST['prompt']) ? sanitize_text_field($_POST['prompt']) : '';", scrape_handler_append, 1)

scrape_prompt_append = """
    $user_prompt = "【指定フォーマット】: {$target_format}\\n";
    if (empty(trim($speaker_names))) {
        $speaker_names = "インタビュアーなど不特定の話し手";
    }
    $user_prompt .= "【話者・登場人物の設定】: {$speaker_names}\\n※会話や対談形式の場合、誰が話したかを明示し、役割や知名度などのコンテキストを反映させてください。\\n";
    if ($extra_prompt) {
"""
api_content = api_content.replace("""
    $user_prompt = "【指定フォーマット】: {$target_format}\\n";
    if ($extra_prompt) {""", scrape_prompt_append.lstrip('\n'), 1)

# Update Distill handler
distill_handler_append = """
    $extra_prompt = isset($_POST['prompt']) ? sanitize_text_field($_POST['prompt']) : '';
    $speaker_names = isset($_POST['speaker_names']) ? sanitize_text_field($_POST['speaker_names']) : '';
"""
# Find the second occurrence of $extra_prompt
api_content = re.sub(r"(\$extra_prompt = isset\(\$_POST\['prompt'\]\) \? sanitize_text_field\(\$_POST\['prompt'\]\) : '';)", distill_handler_append.strip(), api_content, count=0)
# Wait, replacing all is fine if they both use the same structure.

# Let's fix the user_prompt replacement globally for handlers
distill_prompt_append = """
    $user_prompt = "【指定フォーマット】: {$target_format}\\n";
    if (empty(trim($speaker_names))) {
        $speaker_names = "インタビュアーなど不特定の話し手";
    }
    $user_prompt .= "【話者・登場人物の設定】: {$speaker_names}\\n※会話や対談形式の場合、誰が話したかを明示し、役割や知名度などのコンテキストを反映させてください。\\n";
    if ($extra_prompt) {
"""
api_content = re.sub(
    r"\$user_prompt = \"【指定フォーマット】: \{\$target_format\}\\n\";\s*if \(\$extra_prompt\) \{",
    distill_prompt_append.strip(),
    api_content
)

with open(api_path, 'w') as f:
    f.write(api_content)
    
print("functions_llm_api updated.")
