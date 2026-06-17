<?php
/*
 * Template Name: API Settings
 * Description: LLM API設定画面（OpenAI, Gemini, Ollama, Llama.cpp等）
 */

// 認証状態の確認
if (!is_user_logged_in()) {
    auth_redirect();
    exit;
}

$current_user_id = get_current_user_id();
$status_message = '';

// 保存処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_api_settings'])) {
    if (isset($_POST['nonce']) && wp_verify_nonce($_POST['nonce'], 'save_api_settings_action')) {
        // OpenAI
        update_user_meta($current_user_id, 'llm_openai_api_key', sanitize_text_field($_POST['openai_api_key']));
        update_user_meta($current_user_id, 'llm_openai_model', sanitize_text_field($_POST['openai_model']));

        // Gemini
        update_user_meta($current_user_id, 'llm_gemini_api_key', sanitize_text_field($_POST['gemini_api_key']));
        update_user_meta($current_user_id, 'llm_gemini_model', sanitize_text_field($_POST['gemini_model']));

        // Ollama
        update_user_meta($current_user_id, 'llm_ollama_url', sanitize_url($_POST['ollama_url']));
        update_user_meta($current_user_id, 'llm_ollama_model', sanitize_text_field($_POST['ollama_model']));

        // Custom / Llama.cpp
        update_user_meta($current_user_id, 'llm_custom_url', sanitize_url($_POST['custom_url']));
        update_user_meta($current_user_id, 'llm_custom_model', sanitize_text_field($_POST['custom_model']));

        // Server Access Token
        if (isset($_POST['server_access_token'])) {
            update_user_meta($current_user_id, 'fourier_server_access_token', sanitize_text_field($_POST['server_access_token']));
        }

        $status_message = '<div class="status-msg success" style="margin-bottom: 1.5rem;">' . esc_html__('設定を保存しました。', 'fourier') . '</div>';
    } else {
        $status_message = '<div class="status-msg error" style="margin-bottom: 1.5rem;">' . esc_html__('不正なリクエストです。', 'fourier') . '</div>';
    }
}

// 現在の値を取得
$openai_key = get_user_meta($current_user_id, 'llm_openai_api_key', true);
$openai_mod = get_user_meta($current_user_id, 'llm_openai_model', true) ?: 'gpt-5.5';

$gemini_key = get_user_meta($current_user_id, 'llm_gemini_api_key', true);
$gemini_mod = get_user_meta($current_user_id, 'llm_gemini_model', true) ?: 'gemini-3.1-pro-preview';

$ollama_url = get_user_meta($current_user_id, 'llm_ollama_url', true) ?: 'http://127.0.0.1:11434';
$ollama_mod = get_user_meta($current_user_id, 'llm_ollama_model', true) ?: 'gemma4:12b-mlx';

$custom_url = get_user_meta($current_user_id, 'llm_custom_url', true) ?: 'http://127.0.0.1:8080/v1';
$custom_mod = get_user_meta($current_user_id, 'llm_custom_model', true) ?: '';

$server_access_token = get_user_meta($current_user_id, 'fourier_server_access_token', true);
if (!$server_access_token) {
    // 初回は自動生成
    $server_access_token = wp_generate_password(32, false);
    update_user_meta($current_user_id, 'fourier_server_access_token', $server_access_token);
}

get_header();
?>

<style>
    .settings-container {
        max-width: 1000px;
        margin: 3rem auto;
        padding: 0 1rem;
        font-family: var(--font-primary, 'Inter', 'Noto Sans JP', sans-serif);
    }

    .settings-card {
        background: var(--bg-surface, #fff);
        padding: 2.5rem;
        border-radius: var(--radius-lg, 8px);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid var(--border-subtle, #eee);
        margin-bottom: 2rem;
    }

    .settings-card h2 {
        margin-top: 0;
        margin-bottom: 1.5rem;
        font-size: 1.5rem;
        border-bottom: 2px solid var(--border-subtle, #eee);
        padding-bottom: 0.5rem;
        color: var(--text-primary, #111);
    }

    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-group label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 600;
        color: var(--text-primary, #333);
    }

    .form-group input[type="text"],
    .form-group input[type="password"],
    .form-group input[type="url"] {
        width: 100%;
        padding: 0.8rem;
        border: 1px solid var(--border-subtle, #ccc);
        border-radius: 4px;
        box-sizing: border-box;
        font-size: 1rem;
    }

    .form-group input:focus {
        border-color: var(--accent, #C9A96E);
        outline: none;
        box-shadow: 0 0 0 2px rgba(201, 169, 110, 0.2);
    }

    .btn-save {
        background-color: var(--text-primary, #111);
        color: #fff;
        border: none;
        padding: 0.8rem 2rem;
        font-size: 1rem;
        border-radius: 4px;
        cursor: pointer;
        transition: opacity 0.2s;
        font-weight: 600;
    }

    .btn-save:hover {
        opacity: 0.8;
    }

    .help-text {
        font-size: 0.85rem;
        color: var(--text-secondary, #666);
        margin-top: 0.4rem;
    }
</style>

<main class="settings-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <h1 style="margin:0; font-size: 2rem;"><?php echo esc_html__('API設定', 'fourier'); ?></h1>
    </div>

    <?php echo $status_message; ?>

    <form method="post" action="">
        <?php wp_nonce_field('save_api_settings_action', 'nonce'); ?>
        <input type="hidden" id="test-nonce" value="<?php echo wp_create_nonce('test_llm_connection_action'); ?>">

        <div class="settings-card">
            <h2>OpenAI API</h2>
            <div class="form-group">
                <label for="openai_api_key">API Key</label>
                <input type="password" id="openai_api_key" name="openai_api_key" value="<?php echo esc_attr($openai_key); ?>" placeholder="sk-..." autocomplete="off">
            </div>
            <div class="form-group">
                <label for="openai_model">Default Model</label>
                <input type="text" id="openai_model" name="openai_model" value="<?php echo esc_attr($openai_mod); ?>" placeholder="gpt-5.5, gpt-3.5-turbo">
            </div>
            <div style="margin-top: 1rem;">
                <button type="button" class="btn-test-connection" data-provider="openai" style="padding: 0.5rem 1rem; cursor:pointer; background:#eee; border:1px solid #ccc; border-radius:4px;">接続確認</button>
                <span class="test-result" id="test-result-openai" style="margin-left: 1rem; font-weight: 500;"></span>
            </div>
        </div>

        <div class="settings-card">
            <h2>Google Gemini API</h2>
            <div class="form-group">
                <label for="gemini_api_key">API Key</label>
                <input type="password" id="gemini_api_key" name="gemini_api_key" value="<?php echo esc_attr($gemini_key); ?>" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="gemini_model">Default Model</label>
                <input type="text" id="gemini_model" name="gemini_model" value="<?php echo esc_attr($gemini_mod); ?>" placeholder="gemini-3.1-pro-preview">
            </div>
            <div style="margin-top: 1rem;">
                <button type="button" class="btn-test-connection" data-provider="gemini" style="padding: 0.5rem 1rem; cursor:pointer; background:#eee; border:1px solid #ccc; border-radius:4px;">接続確認</button>
                <span class="test-result" id="test-result-gemini" style="margin-left: 1rem; font-weight: 500;"></span>
            </div>
        </div>

        <div class="settings-card">
            <h2>Ollama (ローカルサーバー)</h2>
            <div class="form-group">
                <label for="ollama_url">Endpoint URL</label>
                <input type="url" id="ollama_url" name="ollama_url" value="<?php echo esc_attr($ollama_url); ?>" placeholder="http://host.docker.internal:11434">
                <div class="help-text">Ollamaが稼働しているサーバーのURLを指定します。<br><span style="color:var(--accent);">※Docker上で動かしている場合、母艦のMac/PCに接続するには <code>http://host.docker.internal:11434</code> を指定してください。</span></div>
            </div>
            <div class="form-group">
                <label for="ollama_model">Default Model</label>
                <input type="text" id="ollama_model" name="ollama_model" value="<?php echo esc_attr($ollama_mod); ?>" placeholder="gemma4:12b-mlx, gemma, etc.">
            </div>
            <div style="margin-top: 1rem;">
                <button type="button" class="btn-test-connection" data-provider="ollama" style="padding: 0.5rem 1rem; cursor:pointer; background:#eee; border:1px solid #ccc; border-radius:4px;">接続確認</button>
                <span class="test-result" id="test-result-ollama" style="margin-left: 1rem; font-weight: 500;"></span>
            </div>
        </div>

        <div class="settings-card">
            <h2>Llama.cpp / OpenAI互換サーバー (ローカル)</h2>
            <div class="form-group">
                <label for="custom_url">Endpoint Base URL</label>
                <input type="url" id="custom_url" name="custom_url" value="<?php echo esc_attr($custom_url); ?>" placeholder="http://host.docker.internal:8080/v1">
                <div class="help-text">Llama.cppのサーバーやvLLMなど、OpenAI互換の/v1エンドポイントURLを指定します。<br><span style="color:var(--accent);">※Docker上で動かしている場合、母艦のMac/PCに接続するには <code>http://host.docker.internal:8080/v1</code> などを指定してください。</span></div>
            </div>
            <div class="form-group">
                <label for="custom_model">Model Name (optional)</label>
                <input type="text" id="custom_model" name="custom_model" value="<?php echo esc_attr($custom_mod); ?>" placeholder="モデル名（サーバー側で固定の場合は空でも可）">
            </div>
            <div style="margin-top: 1rem;">
                <button type="button" class="btn-test-connection" data-provider="custom" style="padding: 0.5rem 1rem; cursor:pointer; background:#eee; border:1px solid #ccc; border-radius:4px;">接続確認</button>
                <span class="test-result" id="test-result-custom" style="margin-left: 1rem; font-weight: 500;"></span>
            </div>
        </div>

        <div class="settings-card" style="border-color: var(--accent, #C9A96E);">
            <h2>データ取得用 アクセストークン</h2>
            <p class="help-text" style="margin-bottom: 1rem;">外部のPythonスクリプトや学習パイプラインから、このサーバーの学習データを自動取得するためのトークンです。</p>
            <div class="form-group">
                <label for="server_access_token">Server Access Token (Bearer Token)</label>
                <input type="text" id="server_access_token" name="server_access_token" value="<?php echo esc_attr($server_access_token); ?>" autocomplete="off" readonly style="background-color: #f9f9f9; cursor: text;">
                <div class="help-text">
                    利用例:<br>
                    <code>curl -H "Authorization: Bearer <?php echo esc_html($server_access_token); ?>" "<?php echo esc_url(rest_url('fourier/v1/export-data')); ?>?format=dpo"</code>
                </div>
            </div>
        </div>

        <div style="text-align: right; margin-bottom: 4rem;">
            <button type="submit" name="save_api_settings" class="btn-save"><?php echo esc_html__('設定を保存', 'fourier'); ?></button>
        </div>
    </form>

</main>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const ajaxUrl = "<?php echo esc_url(admin_url('admin-ajax.php')); ?>";
        const testNonce = document.getElementById('test-nonce').value;

        document.querySelectorAll('.btn-test-connection').forEach(btn => {
            btn.addEventListener('click', function() {
                const provider = this.getAttribute('data-provider');
                const resultSpan = document.getElementById('test-result-' + provider);

                let apiKey = '',
                    url = '',
                    model = '';

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
                            resultSpan.textContent = data.data.message + '（※画面下部の「設定を保存」を押して確定してください）';
                            resultSpan.style.color = "green";
                        } else {
                            resultSpan.textContent = data.data.message + '（※画面下部の「設定を保存」を押して確定してください）';
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

<?php get_footer(); ?>