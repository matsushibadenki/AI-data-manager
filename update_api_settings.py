import re

# 1. Update functions_llm_api.php
with open('inc/functions_llm_api.php', 'a') as f:
    f.write('''
// --- 新規：LLM接続確認用API ---
add_action('wp_ajax_test_llm_connection', 'test_llm_connection_handler');
function test_llm_connection_handler() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'test_llm_connection_action')) {
        wp_send_json_error(['message' => 'セッションが無効です。']);
    }

    $provider = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : '';
    
    $system_prompt = "You are a helpful assistant.";
    $user_prompt = "Reply with exactly one word: OK";

    $response_text = "";
    try {
        switch ($provider) {
            case 'openai':
                $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
                $model = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : 'gpt-5.5';
                if (!$api_key) throw new Exception("API Keyが空です。");
                $response_text = llm_api_call_openai($api_key, $model, $system_prompt, $user_prompt);
                break;
            case 'gemini':
                $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
                $model = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : 'gemini-3.1-pro-preview';
                if (!$api_key) throw new Exception("API Keyが空です。");
                $response_text = llm_api_call_gemini($api_key, $model, $system_prompt, $user_prompt);
                break;
            case 'ollama':
                $url = isset($_POST['url']) ? sanitize_text_field($_POST['url']) : 'http://127.0.0.1:11434';
                $model = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : 'gemma4:12b-mlx';
                if (!$url) throw new Exception("URLが空です。");
                $response_text = llm_api_call_ollama($url, $model, $system_prompt, $user_prompt);
                break;
            case 'custom':
                $url = isset($_POST['url']) ? sanitize_text_field($_POST['url']) : 'http://127.0.0.1:8080/v1';
                $model = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : '';
                if (!$url) throw new Exception("URLが空です。");
                $response_text = llm_api_call_custom($url, $model, $system_prompt, $user_prompt);
                break;
            default:
                throw new Exception("不明なプロバイダです。");
        }
        
        if (empty($response_text)) {
             throw new Exception("空の応答が返されました。");
        }

        wp_send_json_success(['message' => '接続成功！ (応答: ' . esc_html(mb_substr($response_text, 0, 30)) . ')']);
    } catch (Exception $e) {
        wp_send_json_error(['message' => '接続失敗: ' . $e->getMessage()]);
    }
}
''')

# 2. Update page-api-settings.php
with open('page-api-settings.php', 'r') as f:
    content = f.read()

# Add nonce for test connection
content = content.replace("<?php wp_nonce_field('save_api_settings_action', 'nonce'); ?>",
                          "<?php wp_nonce_field('save_api_settings_action', 'nonce'); ?>\n        <input type=\"hidden\" id=\"test-nonce\" value=\"<?php echo wp_create_nonce('test_llm_connection_action'); ?>\">")

# Add button to OpenAI
content = content.replace('''            <div class="form-group">
                <label for="openai_model">Default Model</label>
                <input type="text" id="openai_model" name="openai_model" value="<?php echo esc_attr($openai_mod); ?>" placeholder="gpt-5.5, gpt-3.5-turbo">
            </div>
        </div>''', '''            <div class="form-group">
                <label for="openai_model">Default Model</label>
                <input type="text" id="openai_model" name="openai_model" value="<?php echo esc_attr($openai_mod); ?>" placeholder="gpt-5.5, gpt-3.5-turbo">
            </div>
            <div style="margin-top: 1rem;">
                <button type="button" class="btn-test-connection" data-provider="openai" style="padding: 0.5rem 1rem; cursor:pointer; background:#eee; border:1px solid #ccc; border-radius:4px;">接続確認</button>
                <span class="test-result" id="test-result-openai" style="margin-left: 1rem; font-weight: 500;"></span>
            </div>
        </div>''')

# Add button to Gemini
content = content.replace('''            <div class="form-group">
                <label for="gemini_model">Default Model</label>
                <input type="text" id="gemini_model" name="gemini_model" value="<?php echo esc_attr($gemini_mod); ?>" placeholder="gemini-3.1-pro-preview">
            </div>
        </div>''', '''            <div class="form-group">
                <label for="gemini_model">Default Model</label>
                <input type="text" id="gemini_model" name="gemini_model" value="<?php echo esc_attr($gemini_mod); ?>" placeholder="gemini-3.1-pro-preview">
            </div>
            <div style="margin-top: 1rem;">
                <button type="button" class="btn-test-connection" data-provider="gemini" style="padding: 0.5rem 1rem; cursor:pointer; background:#eee; border:1px solid #ccc; border-radius:4px;">接続確認</button>
                <span class="test-result" id="test-result-gemini" style="margin-left: 1rem; font-weight: 500;"></span>
            </div>
        </div>''')

# Add button to Ollama
content = content.replace('''            <div class="form-group">
                <label for="ollama_model">Default Model</label>
                <input type="text" id="ollama_model" name="ollama_model" value="<?php echo esc_attr($ollama_mod); ?>" placeholder="gemma4:12b-mlx, gemma, etc.">
            </div>
        </div>''', '''            <div class="form-group">
                <label for="ollama_model">Default Model</label>
                <input type="text" id="ollama_model" name="ollama_model" value="<?php echo esc_attr($ollama_mod); ?>" placeholder="gemma4:12b-mlx, gemma, etc.">
            </div>
            <div style="margin-top: 1rem;">
                <button type="button" class="btn-test-connection" data-provider="ollama" style="padding: 0.5rem 1rem; cursor:pointer; background:#eee; border:1px solid #ccc; border-radius:4px;">接続確認</button>
                <span class="test-result" id="test-result-ollama" style="margin-left: 1rem; font-weight: 500;"></span>
            </div>
        </div>''')

# Add button to Custom
content = content.replace('''            <div class="form-group">
                <label for="custom_model">Model Name (optional)</label>
                <input type="text" id="custom_model" name="custom_model" value="<?php echo esc_attr($custom_mod); ?>" placeholder="モデル名（サーバー側で固定の場合は空でも可）">
            </div>
        </div>''', '''            <div class="form-group">
                <label for="custom_model">Model Name (optional)</label>
                <input type="text" id="custom_model" name="custom_model" value="<?php echo esc_attr($custom_mod); ?>" placeholder="モデル名（サーバー側で固定の場合は空でも可）">
            </div>
            <div style="margin-top: 1rem;">
                <button type="button" class="btn-test-connection" data-provider="custom" style="padding: 0.5rem 1rem; cursor:pointer; background:#eee; border:1px solid #ccc; border-radius:4px;">接続確認</button>
                <span class="test-result" id="test-result-custom" style="margin-left: 1rem; font-weight: 500;"></span>
            </div>
        </div>''')


# Add JS
js_code = '''
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ajaxUrl = "<?php echo esc_url(admin_url('admin-ajax.php')); ?>";
    const testNonce = document.getElementById('test-nonce').value;

    document.querySelectorAll('.btn-test-connection').forEach(btn => {
        btn.addEventListener('click', function() {
            const provider = this.getAttribute('data-provider');
            const resultSpan = document.getElementById('test-result-' + provider);
            
            let apiKey = '', url = '', model = '';
            
            if (provider === 'openai') {
                apiKey = document.getElementById('openai_api_key').value;
                model = document.getElementById('openai_model').value;
            } else if (provider === 'gemini') {
                apiKey = document.getElementById('gemini_api_key').value;
                model = document.getElementById('gemini_model').value;
            } else if (provider === 'ollama') {
                url = document.getElementById('ollama_url').value;
                model = document.getElementById('ollama_model').value;
            } else if (provider === 'custom') {
                url = document.getElementById('custom_url').value;
                model = document.getElementById('custom_model').value;
            }

            resultSpan.textContent = "確認中...";
            resultSpan.style.color = "#666";
            this.disabled = true;

            const formData = new FormData();
            formData.append('action', 'test_llm_connection');
            formData.append('nonce', testNonce);
            formData.append('provider', provider);
            formData.append('api_key', apiKey);
            formData.append('url', url);
            formData.append('model', model);

            fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                this.disabled = false;
                if (data.success) {
                    resultSpan.textContent = data.data.message;
                    resultSpan.style.color = "green";
                } else {
                    resultSpan.textContent = data.data.message;
                    resultSpan.style.color = "red";
                }
            })
            .catch(err => {
                this.disabled = false;
                resultSpan.textContent = "通信エラーが発生しました。";
                resultSpan.style.color = "red";
            });
        });
    });
});
</script>
'''

content = content.replace("</main>\n\n<?php get_footer(); ?>",
                          "</main>\n" + js_code + "\n<?php get_footer(); ?>")

with open('page-api-settings.php', 'w') as f:
    f.write(content)
