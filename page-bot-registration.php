<?php
/*
 * Name: page-bot-registration.php
 * Description: URLからWebページを自動クロールして学習データを生成・登録する機能。
 * Template Name: Bot Registration
 */

// 認証状態の確認
$is_authenticated = is_user_logged_in();

// ログイン処理
$login_error = '';
if (!$is_authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_login_submit'])) {
    $creds = array(
        'user_login'    => isset($_POST['username']) ? sanitize_user($_POST['username']) : '',
        'user_password' => isset($_POST['password']) ? $_POST['password'] : '',
        'remember'      => true
    );
    $user = wp_signon($creds, false);
    if (is_wp_error($user)) {
        $login_error = $user->get_error_message();
    } else {
        wp_safe_redirect($_SERVER['REQUEST_URI']);
        exit;
    }
}

get_header();
?>

<style>
.learning-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    border-bottom: 2px solid var(--border-subtle, #eee);
    padding-bottom: 0.5rem;
    overflow-x: auto;
}
.learning-tab {
    padding: 0.75rem 1.25rem;
    background: transparent;
    border: none;
    cursor: pointer;
    font-weight: 500;
    color: var(--text-secondary, #666);
    border-radius: var(--radius-md, 4px);
    transition: all 0.2s ease;
    white-space: nowrap;
}
.learning-tab:hover {
    background: var(--bg-surface-hover, #f5f5f5);
}
.learning-tab.active {
    background: var(--bg-surface, #fff);
    color: var(--text-primary, #000);
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    border: 1px solid var(--border-subtle, #eee);
}
.learning-tab-content {
    display: none;
    background: var(--bg-surface, #fff);
    padding: 2rem;
    border: 1px solid var(--border-subtle, #eee);
    border-radius: var(--radius-lg, 8px);
}
.learning-tab-content.active {
    display: block;
}

/* カスタムボタンと入力 */
.upload-form-input {
    background-color: var(--bg-primary);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-md);
    padding: 0.75rem 1rem;
    color: var(--text-primary);
    font-size: 0.95rem;
    font-family: inherit;
    outline: none;
    transition: all 0.2s;
    width: 100%;
    box-sizing: border-box;
}

.upload-form-input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(201, 169, 110, 0.2);
}



.status-msg {
    margin-top: 1rem;
    padding: 1rem;
    border-radius: 4px;
    display: none;
}

.status-msg.success {
    background: rgba(34, 197, 94, 0.1);
    color: #166534;
    border: 1px solid rgba(34, 197, 94, 0.2);
    display: block;
}

.status-msg.error {
    background: rgba(239, 68, 68, 0.1);
    color: #991b1b;
    border: 1px solid rgba(239, 68, 68, 0.2);
    display: block;
}

.progress-container {
    margin-top: 1.5rem;
    display: none;
}

.progress-bar-bg {
    width: 100%;
    height: 8px;
    background: var(--border-subtle, #eee);
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 0.5rem;
}

.progress-bar-fill {
    height: 100%;
    background: var(--accent, #C9A96E);
    width: 0%;
    transition: width 0.3s ease;
}

.log-container {
    margin-top: 1rem;
    background: var(--bg-primary, #f9f9f9);
    border: 1px solid var(--border-subtle, #eee);
    padding: 1rem;
    border-radius: 4px;
    height: 200px;
    overflow-y: auto;
    font-family: monospace;
    font-size: 0.85rem;
    color: var(--text-secondary, #666);
}

.log-entry.success { color: #16a34a; }
.log-entry.error { color: #dc2626; }
.log-entry.info { color: #2563eb; }

</style>

<main>
    <div id="primary" class="upload-page-container">
        <?php if (!$is_authenticated) : ?>
            <!-- ログイン画面 -->
            <div class="upload-login-wrapper">
                <div class="upload-login-box">
                    <?php
                    $logo_url = get_template_directory_uri() . '/assets/img/logo_tokushiikusya_main.svg';
                    ?>
                    <img src="<?php echo esc_url($logo_url); ?>" alt="Site Logo" class="upload-login-logo" />
                    <h2 class="upload-login-title"><?php echo esc_html__('データ管理ログイン', 'fourier'); ?></h2>
                    <p class="upload-login-subtitle"><?php echo esc_html__('認証情報を入力してログインしてください。', 'fourier'); ?></p>

                    <?php if (!empty($login_error)) : ?>
                        <div class="upload-login-error"><?php echo $login_error; ?></div>
                    <?php endif; ?>

                    <form method="post" action="" class="upload-login-form" autocomplete="off">
                        <div class="upload-form-group">
                            <label for="username"><?php echo esc_html__('ユーザー名', 'fourier'); ?></label>
                            <input type="text" name="username" id="username" class="upload-form-input" required autofocus autocomplete="username" />
                        </div>
                        <div class="upload-form-group">
                            <label for="password"><?php echo esc_html__('パスワード', 'fourier'); ?></label>
                            <input type="password" name="password" id="password" class="upload-form-input" required autocomplete="current-password" />
                        </div>
                        <button type="submit" name="upload_login_submit" class="btn-base btn-primary upload-login-btn">
                            <?php echo esc_html__('ログイン', 'fourier'); ?>
                        </button>
                    </form>
                    <div style="margin-top: 1.5rem; text-align: left; font-size: 0.8rem; color: var(--text-tertiary);">
                        <p style="margin-bottom: 0.5rem;"><?php echo esc_html__('※新規登録は管理者へお問い合わせください', 'fourier'); ?></p>
                    </div>
                </div>
            </div>
        <?php else : ?>
            <!-- メイン画面 -->
            <div style="margin-bottom: 1.5rem;">
                <h2 class="upload-title" style="margin-top: 0; margin-bottom: 0.25rem;"><?php echo esc_html__('Bot（クローラー）自動登録', 'fourier'); ?></h2>
                <p class="upload-desc" style="margin-bottom: 0;"><?php echo esc_html__('指定したURLリストを順番にクロールし、テキストを抽出・フォーマットして自動登録します。', 'fourier'); ?></p>
            </div>

            <div class="learning-tabs">
                <button class="learning-tab active" data-target="tab-bot-crawl"><?php echo esc_html__('URLクロール実行', 'fourier'); ?></button>
                <button class="learning-tab" data-target="tab-bot-archive"><?php echo esc_html__('アーカイブ自動収集・クロール', 'fourier'); ?></button>
            </div>

            <!-- クロール実行タブ -->
            <div id="tab-bot-crawl" class="learning-tab-content active">
                <div class="upload-controls" style="flex-direction: column; align-items: stretch; margin-bottom: 2rem;">
                    
                    <div class="upload-form-group" style="margin-bottom: 1.5rem;">
                        <label for="target-urls" style="font-weight: 600; margin-bottom: 0.5rem; display: block;">
                            <?php echo esc_html__('対象URLリスト (1行に1つのURL)', 'fourier'); ?>
                        </label>
                        <textarea id="target-urls" class="upload-form-input" style="min-height: 150px; font-family: monospace;" placeholder="https://example.com/article1&#10;https://example.com/article2"></textarea>
                    </div>

                    <div style="display: flex; gap: 1.5rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
                        <div class="upload-form-group" style="flex: 1; min-width: 200px;">
                            <label for="output-format" style="font-weight: 600; margin-bottom: 0.5rem; display: block;"><?php echo esc_html__('抽出フォーマット', 'fourier'); ?></label>
                            <select id="output-format" class="upload-form-input">
                                <option value="plain"><?php echo esc_html__('プレーンテキスト (Plain Text)', 'fourier'); ?></option>
                                <option value="instruction"><?php echo esc_html__('Instruction (Q&A形式)', 'fourier'); ?></option>
                                <option value="cot"><?php echo esc_html__('Chain of Thought (思考プロセス付き)', 'fourier'); ?></option>
                                <option value="structured"><?php echo esc_html__('構造化データ (JSON)', 'fourier'); ?></option>
                            </select>
                        </div>
                        
                        <div class="upload-form-group" style="flex: 1; min-width: 200px;">
                            <label for="llm-provider" style="font-weight: 600; margin-bottom: 0.5rem; display: block;"><?php echo esc_html__('LLMプロバイダー', 'fourier'); ?></label>
                            <select id="llm-provider" class="upload-form-input">
                                <option value="openai">OpenAI (API設定準拠)</option>
                                <option value="gemini">Google Gemini (API設定準拠)</option>
                            </select>
                        </div>
                    </div>

                    <div class="upload-form-group" style="margin-bottom: 1.5rem;">
                        <label for="extra-prompt" style="font-weight: 600; margin-bottom: 0.5rem; display: block;">
                            <?php echo esc_html__('追加抽出プロンプト (任意)', 'fourier'); ?>
                        </label>
                        <textarea id="extra-prompt" class="upload-form-input" style="min-height: 80px;" placeholder="<?php echo esc_attr__('例: 記事の中から重要な用語とその解説を抽出し、Instruction形式にしてください。', 'fourier'); ?>"></textarea>
                    </div>

                    <div style="text-align: right;">
                        <button type="button" id="btn-start-crawl" class="btn-base btn-primary">
                            <?php echo esc_html__('クロール開始', 'fourier'); ?>
                        </button>
                    </div>

                </div>
            </div>

            <!-- アーカイブ自動収集・クロールタブ -->
            <div id="tab-bot-archive" class="learning-tab-content">
                <div class="upload-controls" style="flex-direction: column; align-items: stretch; margin-bottom: 2rem;">
                    
                    <div class="upload-form-group" style="margin-bottom: 1.5rem;">
                        <label for="archive-pattern" style="font-weight: 600; margin-bottom: 0.5rem; display: block;">
                            <?php echo esc_html__('対象URLパターン (例: *.example.com/*)', 'fourier'); ?>
                        </label>
                        <input type="text" id="archive-pattern" class="upload-form-input" placeholder="*.example.com/*" />
                    </div>

                    <div style="display: flex; gap: 1.5rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
                        <div class="upload-form-group" style="flex: 1; min-width: 200px;">
                            <label for="archive-source" style="font-weight: 600; margin-bottom: 0.5rem; display: block;"><?php echo esc_html__('データソース', 'fourier'); ?></label>
                            <select id="archive-source" class="upload-form-input">
                                <option value="internet_archive">Internet Archive (Wayback Machine)</option>
                                <option value="common_crawl">Common Crawl</option>
                            </select>
                        </div>
                        
                        <div class="upload-form-group" style="flex: 1; min-width: 200px;">
                            <label for="archive-limit" style="font-weight: 600; margin-bottom: 0.5rem; display: block;"><?php echo esc_html__('取得上限数', 'fourier'); ?></label>
                            <input type="number" id="archive-limit" class="upload-form-input" value="10" min="1" max="500" />
                        </div>
                    </div>

                    <div style="text-align: right;">
                        <button type="button" id="btn-start-archive" class="btn-base btn-primary">
                            <?php echo esc_html__('自動収集＆クロール開始', 'fourier'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <div id="status-message" class="status-msg"></div>

            <!-- プログレスバー・ログ表示 -->
            <div id="progress-area" class="progress-container">
                <div style="display: flex; justify-content: space-between; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.5rem;">
                    <span>進行状況</span>
                    <span id="progress-text">0 / 0</span>
                </div>
                <div class="progress-bar-bg">
                    <div id="progress-bar-fill" class="progress-bar-fill"></div>
                </div>
                
                <div id="log-area" class="log-container"></div>
            </div>

            <div class="logout-wrapper">
                <a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>" class="btn-logout">ログアウト</a>
            </div>

        <?php endif; ?>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const btnStart = document.getElementById('btn-start-crawl');
    if (!btnStart) return;

    const urlsInput = document.getElementById('target-urls');
    const formatInput = document.getElementById('output-format');
    const providerInput = document.getElementById('llm-provider');
    const promptInput = document.getElementById('extra-prompt');
    const statusMsg = document.getElementById('status-message');
    const progressArea = document.getElementById('progress-area');
    const progressBar = document.getElementById('progress-bar-fill');
    const progressText = document.getElementById('progress-text');
    const logArea = document.getElementById('log-area');
    
    const btnStartArchive = document.getElementById('btn-start-archive');
    const archivePattern = document.getElementById('archive-pattern');
    const archiveSource = document.getElementById('archive-source');
    const archiveLimit = document.getElementById('archive-limit');

    const ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
    const nonce = '<?php echo wp_create_nonce('learning_data_action'); ?>';

    // Tab switching
    const tabs = document.querySelectorAll('.learning-tab');
    const tabContents = document.querySelectorAll('.learning-tab-content');
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.remove('active'));
            tabContents.forEach(c => c.classList.remove('active'));
            tab.classList.add('active');
            document.getElementById(tab.dataset.target).classList.add('active');
        });
    });

    function addLog(msg, type = 'info') {
        const div = document.createElement('div');
        div.className = `log-entry ${type}`;
        const time = new Date().toLocaleTimeString();
        div.textContent = `[${time}] ${msg}`;
        logArea.appendChild(div);
        logArea.scrollTop = logArea.scrollHeight;
    }

    async function performCrawlFlow(rawUrls) {
        if (rawUrls.length === 0) {
            statusMsg.textContent = 'URLを1つ以上入力してください。';
            statusMsg.className = 'status-msg error';
            statusMsg.style.display = 'block';
            return;
        }

        btnStart.disabled = true;
        if(btnStartArchive) btnStartArchive.disabled = true;
        urlsInput.disabled = true;
        formatInput.disabled = true;
        providerInput.disabled = true;
        promptInput.disabled = true;
        if(archivePattern) archivePattern.disabled = true;
        if(archiveSource) archiveSource.disabled = true;
        if(archiveLimit) archiveLimit.disabled = true;
        
        statusMsg.style.display = 'none';
        progressArea.style.display = 'block';
        logArea.innerHTML = '';
        addLog('クロール処理を開始します...', 'info');

        let successCount = 0;
        let errorCount = 0;
        const total = rawUrls.length;

        for (let i = 0; i < total; i++) {
            const url = rawUrls[i];
            const percent = Math.round((i / total) * 100);
            progressBar.style.width = `${percent}%`;
            progressText.textContent = `${i} / ${total}`;
            
            addLog(`処理中: ${url}`, 'info');

            const formData = new FormData();
            formData.append('action', 'frontend_learning_data_bot_crawl');
            formData.append('nonce', nonce);
            formData.append('url', url);
            formData.append('format', formatInput.value);
            formData.append('provider', providerInput.value);
            formData.append('extra_prompt', promptInput.value);

            try {
                const response = await fetch(ajaxUrl, {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success) {
                    successCount++;
                    addLog(`成功: ${data.data.title || url} を登録しました。`, 'success');
                } else {
                    errorCount++;
                    addLog(`エラー: ${url} - ${data.data.message || '不明なエラー'}`, 'error');
                }
            } catch (err) {
                errorCount++;
                addLog(`通信エラー: ${url}`, 'error');
            }
        }

        // 完了処理
        progressBar.style.width = '100%';
        progressText.textContent = `${total} / ${total}`;
        addLog(`全処理が完了しました。(成功: ${successCount}, エラー: ${errorCount})`, 'info');

        btnStart.disabled = false;
        if(btnStartArchive) btnStartArchive.disabled = false;
        urlsInput.disabled = false;
        formatInput.disabled = false;
        providerInput.disabled = false;
        promptInput.disabled = false;
        if(archivePattern) archivePattern.disabled = false;
        if(archiveSource) archiveSource.disabled = false;
        if(archiveLimit) archiveLimit.disabled = false;

        statusMsg.textContent = `クロール完了 (成功: ${successCount}, エラー: ${errorCount})`;
        statusMsg.className = successCount > 0 ? 'status-msg success' : 'status-msg error';
        statusMsg.style.display = 'block';
    }

    btnStart.addEventListener('click', () => {
        const rawUrls = urlsInput.value.split('\n').map(u => u.trim()).filter(u => u !== '');
        performCrawlFlow(rawUrls);
    });

    if (btnStartArchive) {
        btnStartArchive.addEventListener('click', async () => {
            const pattern = archivePattern.value.trim();
            if (!pattern) {
                statusMsg.textContent = '対象URLパターンを入力してください。';
                statusMsg.className = 'status-msg error';
                statusMsg.style.display = 'block';
                return;
            }

            btnStartArchive.disabled = true;
            statusMsg.style.display = 'none';
            progressArea.style.display = 'block';
            logArea.innerHTML = '';
            addLog(`[自動収集] ${archiveSource.options[archiveSource.selectedIndex].text} に問い合わせ中...`, 'info');

            const fd = new FormData();
            fd.append('action', 'frontend_learning_data_bot_auto_collect_urls');
            fd.append('nonce', nonce);
            fd.append('pattern', pattern);
            fd.append('source', archiveSource.value);
            fd.append('limit', archiveLimit.value);

            try {
                const res = await fetch(ajaxUrl, { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success && data.data.urls) {
                    addLog(`[自動収集] ${data.data.urls.length}件のURLが見つかりました。クロールに移行します。`, 'success');
                    urlsInput.value = data.data.urls.join('\n'); // textareaに反映
                    btnStartArchive.disabled = false;
                    performCrawlFlow(data.data.urls);
                } else {
                    addLog(`[自動収集エラー] ${data.data.message || 'URLが見つかりませんでした。'}`, 'error');
                    btnStartArchive.disabled = false;
                }
            } catch (err) {
                addLog(`[自動収集 通信エラー] APIへの接続に失敗しました。`, 'error');
                btnStartArchive.disabled = false;
            }
        });
    }
});
</script>

<?php get_footer(); ?>
