<?php
/*
 * Name: page-ai-registration.php
 * Description: AIを用いた学習データ自動登録・管理画面。
 * Template Name: AI Registration
 */

// 認証状態の確認
$is_authenticated = is_user_logged_in();
$ai_registration_locale = function_exists('determine_locale') ? determine_locale() : get_locale();
$ai_registration_text = static function ($ja, $en, $zh) use ($ai_registration_locale) {
    if (strpos($ai_registration_locale, 'zh') === 0) return $zh;
    if (strpos($ai_registration_locale, 'en') === 0) return $en;
    return $ja;
};

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
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.65rem;
    margin-bottom: 1.5rem;
    padding: 0.5rem;
    background: var(--bg-subtle, #f4f5f4);
    border: 1px solid var(--border-subtle, #e4e6e4);
    border-radius: 14px;
}
.learning-tab {
    position: relative;
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    align-items: center;
    gap: 0.75rem;
    min-height: 64px;
    padding: 0.75rem 1rem;
    background: transparent;
    border: 2px solid transparent;
    cursor: pointer;
    color: var(--text-secondary, #666);
    border-radius: 10px;
    text-align: left;
    transition: background-color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease, color 0.2s ease;
}
.learning-tab:hover {
    background: var(--bg-surface-hover, #fafafa);
    color: var(--text-primary, #111);
}
.learning-tab.active {
    background: var(--bg-surface, #fff);
    color: var(--text-primary, #000);
    box-shadow: 0 5px 16px rgba(0,0,0,0.09);
    border-color: var(--accent, #9b762f);
}
.learning-tab:focus-visible {
    outline: 3px solid rgba(155, 118, 47, 0.3);
    outline: 3px solid color-mix(in srgb, var(--accent, #9b762f) 30%, transparent);
    outline-offset: 2px;
}
.learning-tab > .material-symbols-outlined {
    display: grid;
    place-items: center;
    width: 36px;
    height: 36px;
    border-radius: 9px;
    background: var(--bg-surface, #fff);
    color: var(--text-secondary, #666);
}
.learning-tab.active > .material-symbols-outlined {
    background: var(--accent-subtle, #f5efe3);
    color: var(--accent, #9b762f);
}
.learning-tab-copy {
    min-width: 0;
}
.learning-tab-label,
.learning-tab-description {
    display: block;
}
.learning-tab-label {
    font-size: 0.95rem;
    font-weight: 700;
    line-height: 1.35;
}
.learning-tab-description {
    margin-top: 0.15rem;
    font-size: 0.75rem;
    line-height: 1.4;
    color: var(--text-secondary, #777);
}
.learning-tab-indicator {
    display: none;
    align-items: center;
    gap: 0.2rem;
    color: var(--accent, #9b762f);
    font-size: 0.72rem;
    font-weight: 700;
    white-space: nowrap;
}
.learning-tab.active .learning-tab-indicator {
    display: inline-flex;
}
.learning-tab-indicator .material-symbols-outlined {
    font-size: 1.1rem;
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
    border-top: 3px solid var(--accent, #9b762f);
}
.auto-runtime-card {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.75rem 1rem;
    margin: 1rem 0;
    padding: 0.9rem 1rem;
    border: 1px solid var(--border-subtle, #ddd);
    border-radius: 10px;
    background: var(--bg-subtle, #f5f5f5);
}
.auto-runtime-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    font-size: 0.85rem;
    font-weight: 700;
}
.auto-runtime-dot {
    width: 0.7rem;
    height: 0.7rem;
    border-radius: 50%;
    background: #8b949e;
}
.auto-runtime-badge.is-running .auto-runtime-dot,
.auto-runtime-badge.is-waiting .auto-runtime-dot {
    background: #16a34a;
    box-shadow: 0 0 0 4px rgba(22, 163, 74, 0.13);
}
.auto-runtime-badge.is-generating .auto-runtime-dot,
.auto-runtime-badge.is-operating .auto-runtime-dot {
    background: #d97706;
    box-shadow: 0 0 0 4px rgba(217, 119, 6, 0.15);
    animation: auto-runtime-pulse 1.2s ease-in-out infinite;
}
.auto-runtime-badge.is-retrying .auto-runtime-dot,
.auto-runtime-badge.is-error .auto-runtime-dot {
    background: #dc2626;
    box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.12);
}
.auto-runtime-detail {
    color: var(--text-secondary, #666);
    font-size: 0.82rem;
}
.auto-runner-health {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    align-items: start;
    flex-basis: 100%;
    gap: 0.45rem;
    padding-top: 0.7rem;
    border-top: 1px solid var(--border-subtle, #ddd);
    color: var(--text-secondary, #666);
    font-size: 0.8rem;
}
.auto-runner-health::before {
    grid-column: 1;
    grid-row: 1;
    width: 0.55rem;
    height: 0.55rem;
    margin-top: 0.3em;
    flex: 0 0 auto;
    border-radius: 50%;
    background: #8b949e;
    content: '';
}
.auto-runner-main,
.auto-runner-heartbeat {
    grid-column: 2;
    min-width: 0;
    line-height: 1.5;
}
.auto-runner-heartbeat { grid-row: 2; }
.auto-runner-health.is-active::before { background: #16a34a; }
.auto-runner-health.is-delayed::before { background: #d97706; }
.auto-runner-health.is-offline::before { background: #dc2626; }
.auto-action-notice {
    margin: 0 0 1rem;
    padding: 0.8rem 1rem;
    border-radius: 8px;
    font-size: 0.86rem;
    font-weight: 600;
}
.auto-action-notice.is-working {
    background: #fff7ed;
    color: #9a3412;
}
.auto-action-notice.is-success {
    background: #ecfdf3;
    color: #166534;
}
.auto-action-notice.is-error {
    background: #fef2f2;
    color: #991b1b;
}
#btn-auto-distill-start.is-loading {
    cursor: progress;
    opacity: 0.75;
}
@keyframes auto-runtime-pulse {
    50% { opacity: 0.35; transform: scale(0.8); }
}
@media (max-width: 620px) {
    .learning-tabs {
        grid-template-columns: 1fr;
    }
    .learning-tab {
        min-height: 58px;
    }
}
.dynamic-row {
    display: flex;
    gap: 1rem;
    margin-bottom: 1rem;
    align-items: flex-start;
}
.dynamic-row select {
    width: 150px;
}
.dynamic-row textarea {
    flex-grow: 1;
}

.search-section {
    background: var(--bg-surface, #fff);
    padding: 1.5rem;
    border: 1px solid var(--border-subtle, #eee);
    border-radius: var(--radius-lg, 8px);
    margin-bottom: 2rem;
}
.search-results-container {
    margin-top: 1.5rem;
}
.search-result-item {
    padding: 1rem;
    border-bottom: 1px solid var(--border-subtle, #eee);
}
.search-result-item:last-child {
    border-bottom: none;
}
.search-result-json {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 4px;
    font-family: monospace;
    font-size: 0.85rem;
    white-space: pre-wrap;
    max-height: 200px;
    overflow-y: auto;
}
.status-msg {
    margin-top: 1rem;
    padding: 1rem;
    border-radius: 4px;
    display: none;
}
.status-msg.success {
    background: #dcfce7;
    color: #166534;
    display: block;
}
.status-msg.error {
    background: #fee2e2;
    color: #991b1b;
    display: block;
}
/* a.btn-black にデザインを合わせる buttonタグ用のスタイル */
button.btn-black {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.85rem;
    line-height: 1;
    text-decoration: none;
    background-color: var(--bg-surface);
    color: var(--text-primary);
    border: 1px solid var(--border-subtle);
    margin: 0;
    padding: 0.7rem 1.8rem;
    border-radius: var(--radius-full, 50px);
    font-weight: 400;
    letter-spacing: 0.03em;
    transition: all var(--transition-base, 0.3s ease);
    cursor: pointer;
}
button.btn-black:hover {
    color: var(--accent);
    border-color: var(--accent);
    background-color: var(--accent-subtle);
    box-shadow: var(--shadow-gold, 0 4px 15px rgba(201, 169, 110, 0.15));
    transform: translateY(-1px);
}
button.btn-black span.material-symbols-outlined {
    font-size: 1rem !important;
    line-height: 1;
    color: inherit;
    vertical-align: middle;
}
/* メタデータ入力セクション */
.metadata-section {
    background: var(--bg-surface, #fff);
    padding: 1.5rem;
    border: 1px solid var(--border-subtle, #eee);
    border-radius: var(--radius-lg, 8px);
    margin-bottom: 1.5rem;
}
.metadata-section summary {
    cursor: pointer;
    font-weight: 600;
    font-size: 0.95rem;
    color: var(--text-primary, #000);
    padding: 0.5rem 0;
    user-select: none;
    list-style: none;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.metadata-section summary::-webkit-details-marker { display: none; }
.metadata-section summary .arrow { transition: transform 0.2s; }
.metadata-section[open] summary .arrow { transform: rotate(90deg); }
.metadata-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 1rem;
    margin-top: 1rem;
}
@media (max-width: 768px) {
    .metadata-grid { grid-template-columns: 1fr; }
}
.metadata-grid label {
    font-size: 0.85rem;
    font-weight: 500;
    display: block;
    margin-bottom: 0.25rem;
    color: var(--text-secondary, #666);
}
.metadata-grid select,
.metadata-grid input {
    width: 100%;
    box-sizing: border-box;
}
/* 品質スコアUI */
.quality-stars {
    display: flex;
    gap: 0.25rem;
    margin-top: 0.25rem;
}
.quality-stars .star {
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--border-subtle, #ccc);
    transition: color 0.15s;
    user-select: none;
}
.quality-stars .star.active {
    color: var(--accent, #C9A96E);
}
.quality-stars .star:hover {
    color: var(--accent, #C9A96E);
}
/* タグ入力 */
.tag-input-wrapper {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem;
    padding: 0.4rem;
    border: 1px solid var(--border-subtle, #ccc);
    border-radius: var(--radius-md, 4px);
    min-height: 38px;
    cursor: text;
    background: var(--bg-surface, #fff);
}
.tag-input-wrapper .tag-chip {
    background: var(--accent-subtle, #f5f0e6);
    color: var(--text-primary, #000);
    padding: 0.2rem 0.5rem;
    border-radius: 50px;
    font-size: 0.8rem;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}
.tag-input-wrapper .tag-chip .remove-tag {
    cursor: pointer;
    font-size: 0.7rem;
    opacity: 0.6;
}
.tag-input-wrapper .tag-chip .remove-tag:hover { opacity: 1; }
.tag-input-wrapper input {
    border: none;
    outline: none;
    flex: 1;
    min-width: 80px;
    font-size: 0.85rem;
    background: transparent;
}
/* 文字数カウンター */
.char-counter {
    text-align: right;
    font-size: 0.75rem;
    color: var(--text-secondary, #999);
    margin-top: 0.25rem;
}
.char-counter.warning { color: #f59e0b; }
.char-counter.error { color: #ef4444; }
/* 編集モーダル */
.edit-modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    justify-content: center;
    align-items: center;
    padding: 2rem;
}
.edit-modal-overlay.active { display: flex; }
.edit-modal {
    background: var(--bg-surface, #fff);
    border-radius: var(--radius-lg, 8px);
    max-width: 800px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    padding: 2rem;
    box-shadow: 0 25px 50px rgba(0,0,0,0.25);
    position: relative;
}
.edit-modal-close {
    position: absolute;
    top: 1rem; right: 1rem;
    background: none; border: none;
    cursor: pointer;
    font-size: 1.5rem;
    color: var(--text-secondary, #666);
}
.edit-modal-close:hover { color: var(--text-primary, #000); }
.edit-modal h3 {
    margin-top: 0;
    margin-bottom: 1.5rem;
    font-size: 1.2rem;
}
/* 検索結果アクションボタン */
.result-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.5rem;
}
.result-actions button {
    padding: 0.3rem 0.8rem;
    font-size: 0.75rem;
    border-radius: 4px;
    border: 1px solid var(--border-subtle, #ccc);
    background: var(--bg-surface, #fff);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    transition: all 0.2s;
}
.result-actions button:hover {
    border-color: var(--accent, #C9A96E);
    color: var(--accent, #C9A96E);
}
.result-actions button.btn-danger:hover {
    border-color: #ef4444;
    color: #ef4444;
}
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
                    <div style="margin-top: 1.5rem; text-align: center; font-size: 0.8rem; color: var(--text-secondary);">
                        <p style="margin-bottom: 0.5rem;"><?php echo esc_html__('※新規登録は管理者へお問い合わせください', 'fourier'); ?></p>
                        <a href="#" style="color: var(--text-secondary); text-decoration: underline; margin-right: 0.5rem;"><?php echo esc_html__('プライバシーポリシー', 'fourier'); ?></a>
                        <a href="#" style="color: var(--text-secondary); text-decoration: underline;"><?php echo esc_html__('利用規約', 'fourier'); ?></a>
                    </div>
                </div>
            </div>
        <?php else : ?>
            <!-- メイン画面 -->


            <div style="margin-bottom: 1.5rem;">
                <h2 class="upload-title" style="margin-top: 0; margin-bottom: 0.25rem;"><?php echo esc_html__('AIデータ自動登録', 'fourier'); ?></h2>
                <p class="upload-desc" style="margin-bottom: 0;"><?php echo esc_html__('AIを使った自動データ登録を行います。', 'fourier'); ?></p>
            </div>

            <!-- 登録セクション -->
            <div class="upload-controls" style="flex-direction: column; align-items: stretch; margin-bottom: 2rem;">
                
                <div class="upload-form-group" style="margin-bottom: 1.5rem;">
                    <label for="data-title" style="font-weight: 600; margin-bottom: 0.5rem; display: block;"><?php echo esc_html__('タイトル:', 'fourier'); ?></label>
                    <input type="text" id="data-title" class="upload-form-input" placeholder="<?php echo esc_attr__('データのタイトルまたは概要', 'fourier'); ?>" required />
                </div>

                <!-- メタデータ入力セクション -->
                <details class="metadata-section" id="metadata-section">
                    <summary>
                        <span class="material-symbols-outlined arrow" style="font-size: 1rem;">chevron_right</span>
                        <?php echo esc_html__('メタデータ設定（任意）', 'fourier'); ?>
                    </summary>
                    <div class="metadata-grid">
                        <div>
                            <label for="meta-language"><?php echo esc_html__('言語', 'fourier'); ?></label>
                            <select id="meta-language" class="upload-form-input">
                                <option value=""><?php echo esc_html__('-- 選択 --', 'fourier'); ?></option>
                                <option value="ja" selected><?php echo esc_html__('日本語', 'fourier'); ?></option>
                                <option value="en"><?php echo esc_html__('英語', 'fourier'); ?></option>
                                <option value="zh"><?php echo esc_html__('中国語', 'fourier'); ?></option>
                                <option value="ko"><?php echo esc_html__('韓国語', 'fourier'); ?></option>
                                <option value="multi"><?php echo esc_html__('多言語', 'fourier'); ?></option>
                            </select>
                        </div>
                        <div>
                            <label for="meta-category"><?php echo esc_html__('カテゴリ', 'fourier'); ?></label>
                            <input type="text" id="meta-category" class="upload-form-input" placeholder="<?php echo esc_attr__('例: 一般知識', 'fourier'); ?>" />
                        </div>
                        <div>
                            <label for="meta-difficulty"><?php echo esc_html__('難易度', 'fourier'); ?></label>
                            <select id="meta-difficulty" class="upload-form-input">
                                <option value=""><?php echo esc_html__('-- 選択 --', 'fourier'); ?></option>
                                <option value="beginner"><?php echo esc_html__('初級', 'fourier'); ?></option>
                                <option value="intermediate"><?php echo esc_html__('中級', 'fourier'); ?></option>
                                <option value="advanced"><?php echo esc_html__('上級', 'fourier'); ?></option>
                            </select>
                        </div>
                        <div>
                            <label><?php echo esc_html__('品質スコア', 'fourier'); ?></label>
                            <div class="quality-stars" id="quality-stars">
                                <span class="star" data-value="1">★</span>
                                <span class="star" data-value="2">★</span>
                                <span class="star" data-value="3">★</span>
                                <span class="star" data-value="4">★</span>
                                <span class="star" data-value="5">★</span>
                            </div>
                            <input type="hidden" id="meta-quality" value="0" />
                        </div>
                        <div>
                            <label for="meta-source"><?php echo esc_html__('出典元', 'fourier'); ?></label>
                            <input type="text" id="meta-source" class="upload-form-input" placeholder="<?php echo esc_attr__('URL or 出典名', 'fourier'); ?>" />
                        </div>
                        <div>
                            <label><?php echo esc_html__('タグ', 'fourier'); ?></label>
                            <div class="tag-input-wrapper" id="tag-input-wrapper">
                                <input type="text" id="meta-tags-input" placeholder="<?php echo esc_attr__('Enterで追加', 'fourier'); ?>" />
                            </div>
                            <input type="hidden" id="meta-tags" value="" />
                        </div>
                    </div>
                </details>

                <div class="learning-tabs" role="tablist" aria-label="<?php echo esc_attr($ai_registration_text('登録方法', 'Registration method', '注册方式')); ?>">
                    <button type="button" id="learning-tab-scrape" class="learning-tab active" data-target="tab-scrape" role="tab" aria-controls="tab-scrape" aria-selected="true" tabindex="0">
                        <span class="material-symbols-outlined" aria-hidden="true">language</span>
                        <span class="learning-tab-copy">
                            <span class="learning-tab-label"><?php echo esc_html($ai_registration_text('URLから生成', 'Generate from URL', '从网址生成')); ?></span>
                            <span class="learning-tab-description"><?php echo esc_html($ai_registration_text('Webページを取得して学習データ化', 'Import a web page as training data', '抓取网页并生成训练数据')); ?></span>
                        </span>
                        <span class="learning-tab-indicator"><span class="material-symbols-outlined" aria-hidden="true">check_circle</span><?php echo esc_html($ai_registration_text('選択中', 'Selected', '已选择')); ?></span>
                    </button>
                    <button type="button" id="learning-tab-distillation" class="learning-tab" data-target="tab-distillation" role="tab" aria-controls="tab-distillation" aria-selected="false" tabindex="-1">
                        <span class="material-symbols-outlined" aria-hidden="true">science</span>
                        <span class="learning-tab-copy">
                            <span class="learning-tab-label"><?php echo esc_html($ai_registration_text('データを蒸留', 'Distill data', '蒸馏数据')); ?></span>
                            <span class="learning-tab-description"><?php echo esc_html($ai_registration_text('シードから高品質データを生成', 'Generate refined data from a seed', '根据种子生成高质量数据')); ?></span>
                        </span>
                        <span class="learning-tab-indicator"><span class="material-symbols-outlined" aria-hidden="true">check_circle</span><?php echo esc_html($ai_registration_text('選択中', 'Selected', '已选择')); ?></span>
                    </button>
                </div>

                <!-- URLスクレイピング登録 -->
                <div id="tab-scrape" class="learning-tab-content active" data-format="scrape" role="tabpanel" aria-labelledby="learning-tab-scrape">
                    <div class="upload-form-group">
                        <label for="scrape-url"><?php echo esc_html__('対象URL (Wikipediaやブログ記事など):', 'fourier'); ?></label>
                        <input type="url" id="scrape-url" class="upload-form-input" placeholder="https://..." />
                    </div>
                    <div class="upload-form-group" style="margin-top: 1rem;">
                        <label for="scrape-target-format"><?php echo esc_html__('生成するデータ形式:', 'fourier'); ?></label>
                        <select id="scrape-target-format" class="upload-form-input">
                            <option value="instruction">Instruction (QAペア)</option>
                            <option value="chatml">ChatML (会話形式)</option>
                            <option value="cot">CoT (思考過程付き)</option>
                            <option value="dpo">DPO / RLHF (比較データ)</option>
                            <option value="episode">Episode / Causal Narrative (物語・因果構造)</option>
                            <option value="plain">プレーンテキスト要約</option>
                        </select>
                    </div>
                    <div class="upload-form-group" style="margin-top: 1rem;">
                        <label for="scrape-provider"><?php echo esc_html__('LLMプロバイダ:', 'fourier'); ?></label>
                        <select id="scrape-provider" class="upload-form-input">
                            <option value="openai">OpenAI (推奨)</option>
                            <option value="gemini">Google Gemini</option>
                            <option value="ollama">Ollama (Local)</option>
                            <option value="custom">Custom (Llama.cpp等)</option>
                        </select>
                    </div>
                    <div class="upload-form-group" style="margin-top: 1rem;">
                        <label for="scrape-speaker-names"><?php echo esc_html__('話者名 (対談・インタビュー等の場合):', 'fourier'); ?></label>
                        <input type="text" id="scrape-speaker-names" class="upload-form-input" placeholder="例: ゲスト名, インタビュアー (空欄の場合は不特定の話し手)" />
                    </div>
                    <div class="upload-form-group" style="margin-top: 1rem;">
                        <label for="scrape-prompt"><?php echo esc_html__('追加の指示（任意）:', 'fourier'); ?></label>
                        <textarea id="scrape-prompt" class="upload-form-input" rows="3" placeholder="例: 内容を小学生にもわかるように易しく解説するQAセットを作成して。"></textarea>
                    </div>
                    <div style="margin-top: 1.5rem; text-align: center;">
                        <button type="button" id="btn-scrape-submit" class="btn-base btn-primary" style="background: var(--accent); border-color: var(--accent); color: var(--text-inverse);">
                            <span class="material-symbols-outlined">language</span> 自動取得・生成して登録
                        </button>
                    </div>
                </div>

                <!-- 蒸留生成登録 -->
                <div id="tab-distillation" class="learning-tab-content" data-format="distillation" role="tabpanel" aria-labelledby="learning-tab-distillation" hidden>
                    <div class="upload-form-group">
                        <label for="distill-seed"><?php echo esc_html__('シードデータ / トピック:', 'fourier'); ?></label>
                        <textarea id="distill-seed" class="upload-form-input" rows="4" placeholder="例: 「日本の歴史に関するQAを作成して」「以下のテキストを元に、より詳細な解説を作成して: [テキスト]」"></textarea>
                    </div>
                    <div class="upload-form-group" style="margin-top: 1rem;">
                        <label for="distill-method"><?php echo esc_html__('蒸留方式:', 'fourier'); ?></label>
                        <select id="distill-method" class="upload-form-input">
                            <option value="self-instruct">Self-Instruct (トピックから多様な指示・回答ペア生成)</option>
                            <option value="refinement">Refinement (入力データの高品質化・詳細化)</option>
                            <option value="cot">CoT Generation (論理的思考プロセスの付加)</option>
                            <option value="backtranslation">Backtranslation (回答から最適なプロンプトを逆生成)</option>
                            <option value="format-conversion">Format Conversion (特定フォーマットへの構造化)</option>
                        </select>
                    </div>
                    <div class="upload-form-group" style="margin-top: 1rem;">
                        <label for="distill-target-format"><?php echo esc_html__('生成するデータ形式:', 'fourier'); ?></label>
                        <select id="distill-target-format" class="upload-form-input">
                            <option value="instruction">Instruction (QAペア)</option>
                            <option value="chatml">ChatML (会話形式)</option>
                            <option value="cot">CoT (思考過程付き)</option>
                            <option value="dpo">DPO / RLHF (比較データ)</option>
                            <option value="episode">Episode / Causal Narrative (物語・因果構造)</option>
                            <option value="plain">プレーンテキスト</option>
                        </select>
                    </div>
                    <div class="upload-form-group" style="margin-top: 1rem;">
                        <label for="distill-provider"><?php echo esc_html__('教師モデル (LLMプロバイダ):', 'fourier'); ?></label>
                        <select id="distill-provider" class="upload-form-input">
                            <option value="openai">OpenAI (推奨: gpt-5.5等)</option>
                            <option value="gemini">Google Gemini</option>
                            <option value="ollama">Ollama (ローカルサーバー)</option>
                            <option value="custom">Custom (Llama.cpp等)</option>
                        </select>
                    </div>
                    <div class="upload-form-group" style="margin-top: 1rem;">
                        <label for="distill-speaker-names"><?php echo esc_html__('話者名 (対談・インタビュー等の場合):', 'fourier'); ?></label>
                        <input type="text" id="distill-speaker-names" class="upload-form-input" placeholder="例: ゲスト名, インタビュアー (空欄の場合は不特定の話し手)" />
                    </div>
                    <div class="upload-form-group" style="margin-top: 1rem;">
                        <label for="distill-prompt"><?php echo esc_html__('追加の指示（任意）:', 'fourier'); ?></label>
                        <textarea id="distill-prompt" class="upload-form-input" rows="2" placeholder="例: 出力は小学生でもわかる言葉遣いにしてください。"></textarea>
                    </div>
                    <div style="margin-top: 1.5rem; text-align: center;">
                        <button type="button" id="btn-distill-submit" class="btn-base btn-primary" style="background: var(--accent); border-color: var(--accent); color: var(--text-inverse);">
                            <span class="material-symbols-outlined">science</span> 蒸留処理を実行して登録
                        </button>
                    </div>

                    <section id="auto-distill-panel" style="margin-top:2rem; padding:1.25rem; border:1px solid var(--border-subtle,#ddd); border-radius:12px; background:var(--bg-surface,#fff);">
                        <h3 style="margin:0 0 .5rem; font-size:1.05rem;"><?php echo esc_html($ai_registration_text('連続自動蒸留', 'Continuous distillation', '连续自动蒸馏')); ?></h3>
                        <p style="margin:0 0 1rem; color:var(--text-secondary,#666); font-size:.88rem; line-height:1.7;"><?php echo esc_html($ai_registration_text('停止するまで、生成・検証・重複除去・保存を自動で繰り返します。API利用料金とローカル計算資源を継続的に消費します。', 'Generation, validation, deduplication, and saving continue until you stop the job. This continuously consumes API credits or local compute resources.', '系统会持续执行生成、验证、去重和保存，直到您停止任务。此功能会持续消耗 API 额度或本地计算资源。')); ?></p>
                        <div class="auto-runtime-card" aria-live="polite">
                            <span id="auto-runtime-badge" class="auto-runtime-badge is-idle"><span class="auto-runtime-dot" aria-hidden="true"></span><span id="auto-runtime-label"><?php echo esc_html($ai_registration_text('停止中', 'Stopped', '已停止')); ?></span></span>
                            <span id="auto-runtime-detail" class="auto-runtime-detail"><?php echo esc_html($ai_registration_text('実行中のジョブはありません。', 'There is no running job.', '当前没有运行中的任务。')); ?></span>
                            <span id="auto-cron-health" class="auto-runner-health is-unknown"><span class="auto-runner-main"><?php echo esc_html($ai_registration_text('Cron Runnerを確認しています…', 'Checking the Cron Runner…', '正在检查 Cron Runner…')); ?></span></span>
                        </div>
                        <div id="auto-action-notice" class="auto-action-notice" role="status" hidden></div>
                        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:1rem;">
                            <div class="upload-form-group" style="margin:0;"><label for="auto-distill-interval"><?php echo esc_html($ai_registration_text('実行間隔（分）', 'Interval (minutes)', '执行间隔（分钟）')); ?></label><input type="number" id="auto-distill-interval" class="upload-form-input" min="1" max="60" value="5"></div>
                            <div class="upload-form-group" style="margin:0;"><label for="auto-distill-batch"><?php echo esc_html($ai_registration_text('1回の生成数', 'Items per batch', '每批生成数量')); ?></label><input type="number" id="auto-distill-batch" class="upload-form-input" min="1" max="5" value="3"></div>
                        </div>
                        <div style="display:flex; flex-wrap:wrap; gap:.75rem; margin-top:1rem;">
                            <button type="button" id="btn-auto-distill-start" class="btn-base btn-primary" style="min-height:44px; white-space:normal;"><?php echo esc_html($ai_registration_text('自動蒸留を開始', 'Start automatic distillation', '开始自动蒸馏')); ?></button>
                            <button type="button" id="btn-auto-distill-stop" class="btn-base btn-danger" style="min-height:44px; white-space:normal;" disabled><?php echo esc_html($ai_registration_text('停止', 'Stop', '停止')); ?></button>
                        </div>
                        <input type="hidden" id="auto-distill-job-id" value="">
                        <div id="auto-distill-status" role="status" aria-live="polite" style="margin-top:1rem; padding:1rem; border-radius:8px; background:var(--bg-subtle,#f5f5f5); color:var(--text-secondary,#555); font-size:.88rem; line-height:1.7;"><?php echo esc_html($ai_registration_text('ジョブは開始されていません。', 'No job has been started.', '尚未启动任务。')); ?></div>
                    </section>
                </div>

                <div id="status-message" class="status-msg"></div>

    <!-- ログ表示トグル -->
    <div class="log-toggle" style="margin-top: 1rem;">
        <label style="display: flex; align-items: center; cursor: pointer;">
            <input type="checkbox" id="log-toggle-checkbox" style="margin-right: 0.5rem;" checked>
            <span><?php echo esc_html($ai_registration_text('AIとのやり取りと処理ログを表示する', 'Show AI interaction and processing logs', '显示 AI 交互和处理日志')); ?></span>
        </label>
    </div>
    <!-- ログ表示領域 -->
    <pre id="ai-log" class="ai-log" aria-live="polite" style="background:#f5f5f5; padding:1rem; margin-top:0.5rem; overflow:auto; max-height:420px; display:none; white-space:pre-wrap; overflow-wrap:anywhere;"></pre>

    <input type="hidden" id="edit-post-id" value="" />

            </div>

            <?php endif; ?>
    </div>
</main>

<?php if ($is_authenticated) : ?>
<script>
    function handleAjaxSuccess(response) {
        if (response.success) {
            document.getElementById('status-message').textContent = '登録が完了しました。投稿ID: ' + response.data.post_id;
            // ログを表示
            const logContainer = document.getElementById('ai-log');
            if (logContainer) {
                logContainer.textContent = response.data.log || '';
                const toggle = document.getElementById('log-toggle-checkbox');
                if (toggle && toggle.checked) {
                    logContainer.style.display = 'block';
                } else {
                    logContainer.style.display = 'none';
                }
            }
        } else {
            document.getElementById('status-message').textContent = 'エラー: ' + (response.data.message || '不明なエラー');
        }
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        // ログ表示トグルのリスナー
        const logToggle = document.getElementById('log-toggle-checkbox');
        if (logToggle) {
            const syncLogVisibility = function() {
                const logContainer = document.getElementById('ai-log');
                if (logToggle.checked) {
                    logContainer.style.display = 'block';
                } else {
                    logContainer.style.display = 'none';
                }
            };
            logToggle.addEventListener('change', function() {
                syncLogVisibility();
                if (this.checked && typeof refreshAutoDistillStatus === 'function') {
                    refreshAutoDistillStatus(document.getElementById('auto-distill-job-id').value);
                }
            });
            syncLogVisibility();
        }

        var ajaxUrl = "<?php echo esc_url(admin_url('admin-ajax.php')); ?>";
        var uploadNonce = "<?php echo wp_create_nonce('learning_data_action'); ?>";
        const autoDistillI18n = <?php echo wp_json_encode([
            'noJob' => $ai_registration_text('ジョブは開始されていません。', 'No job has been started.', '尚未启动任务。'),
            'statusFailed' => $ai_registration_text('状態を取得できませんでした。', 'Could not retrieve job status.', '无法获取任务状态。'),
            'seedRequired' => $ai_registration_text('シードデータまたはトピックを入力してください。', 'Enter seed data or a topic.', '请输入种子数据或主题。'),
            'starting' => $ai_registration_text('自動蒸留ジョブを開始しています…', 'Starting the automatic distillation job…', '正在启动自动蒸馏任务…'),
            'startLabel' => $ai_registration_text('自動蒸留を開始', 'Start automatic distillation', '开始自动蒸馏'),
            'restartLabel' => $ai_registration_text('設定を反映して再開始', 'Restart with these settings', '使用当前设置重新启动'),
            'startWorking' => $ai_registration_text('開始処理中…', 'Starting…', '正在启动…'),
            'restartWorking' => $ai_registration_text('再開始処理中…', 'Restarting…', '正在重新启动…'),
            'startAccepted' => $ai_registration_text('自動蒸留を開始しました。', 'Automatic distillation started.', '自动蒸馏已启动。'),
            'restartAccepted' => $ai_registration_text('設定を反映し、新しいジョブへ切り替えました。', 'Settings applied and switched to a new job.', '已应用设置并切换到新任务。'),
            'startFailed' => $ai_registration_text('自動蒸留を開始できませんでした。', 'Could not start automatic distillation.', '无法启动自动蒸馏。'),
            'startNetworkFailed' => $ai_registration_text('通信エラーで開始できませんでした。', 'A network error prevented the job from starting.', '由于网络错误，任务无法启动。'),
            'stopping' => $ai_registration_text('停止しています。生成中の応答は保存前に破棄されます。', 'Stopping. Any response currently being generated will be discarded before saving.', '正在停止。当前生成的响应将在保存前被丢弃。'),
            'stopAccepted' => $ai_registration_text('自動蒸留を停止しました。', 'Automatic distillation stopped.', '自动蒸馏已停止。'),
            'stopFailed' => $ai_registration_text('自動蒸留を停止できませんでした。', 'Could not stop automatic distillation.', '无法停止自动蒸馏。'),
            'stopNetworkFailed' => $ai_registration_text('通信エラーで停止できませんでした。', 'A network error prevented the job from stopping.', '由于网络错误，任务无法停止。'),
            'iterations' => $ai_registration_text('反復', 'Iterations', '迭代'),
            'saved' => $ai_registration_text('保存', 'Saved', '已保存'),
            'duplicates' => $ai_registration_text('重複除外', 'Duplicates', '已排除重复'),
            'invalid' => $ai_registration_text('無効', 'Invalid', '无效'),
            'errors' => $ai_registration_text('エラー', 'Errors', '错误'),
            'nextRun' => $ai_registration_text('次回実行', 'Next run', '下次执行'),
            'lastError' => $ai_registration_text('直近エラー', 'Latest error', '最近错误'),
            'job' => $ai_registration_text('ジョブ', 'Job', '任务'),
            'startedAt' => $ai_registration_text('開始', 'Started', '开始'),
            'lastUpdate' => $ai_registration_text('最終更新', 'Last update', '最后更新'),
            'cronRunner' => $ai_registration_text('Cron Runner', 'Cron Runner', 'Cron Runner'),
            'lastHeartbeat' => $ai_registration_text('最終ハートビート', 'Last heartbeat', '最后心跳'),
            'runnerHealth' => [
                'active' => $ai_registration_text('稼働中', 'Active', '运行中'),
                'delayed' => $ai_registration_text('遅延', 'Delayed', '延迟'),
                'offline' => $ai_registration_text('停止または未到達', 'Offline or unreachable', '已停止或无法访问'),
                'unknown' => $ai_registration_text('未確認', 'Not confirmed', '未确认'),
            ],
            'noLogs' => $ai_registration_text('まだ自動蒸留ログはありません。', 'No automatic distillation logs yet.', '尚无自动蒸馏日志。'),
            'noRunningJob' => $ai_registration_text('実行中のジョブはありません。', 'There is no running job.', '当前没有运行中的任务。'),
            'operationStarting' => $ai_registration_text('開始要求を送信しています。', 'Sending the start request.', '正在发送启动请求。'),
            'operationRestarting' => $ai_registration_text('現在のジョブを停止し、新しい設定へ切り替えています。', 'Stopping the current job and applying the new settings.', '正在停止当前任务并应用新设置。'),
            'phaseLabel' => [
                'idle' => $ai_registration_text('停止中', 'Stopped', '已停止'),
                'operating' => $ai_registration_text('操作処理中', 'Applying operation', '正在处理操作'),
                'queued' => $ai_registration_text('開始待機中', 'Queued', '等待启动'),
                'generating' => $ai_registration_text('AI生成中', 'AI generating', 'AI 生成中'),
                'waiting' => $ai_registration_text('稼働中・次回待機', 'Running · waiting', '运行中・等待下次执行'),
                'retrying' => $ai_registration_text('エラー・再試行待ち', 'Error · retrying', '错误・等待重试'),
                'stopped' => $ai_registration_text('停止済み', 'Stopped', '已停止'),
                'error' => $ai_registration_text('停止・エラー', 'Stopped · error', '已停止・错误'),
            ],
            'status' => [
                'running' => $ai_registration_text('実行中', 'Running', '运行中'),
                'stopped' => $ai_registration_text('停止済み', 'Stopped', '已停止'),
                'error' => $ai_registration_text('エラー', 'Error', '错误'),
            ],
            'phase' => [
                'queued' => $ai_registration_text('待機中', 'Queued', '排队中'),
                'generating' => $ai_registration_text('生成中', 'Generating', '生成中'),
                'waiting' => $ai_registration_text('次回待機中', 'Waiting', '等待下次执行'),
                'retrying' => $ai_registration_text('再試行待ち', 'Retrying', '等待重试'),
                'stopped' => $ai_registration_text('停止済み', 'Stopped', '已停止'),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        // タブ切り替え制御
        const tabs = document.querySelectorAll('.learning-tab');
        const contents = document.querySelectorAll('.learning-tab-content');
        let currentFormat = 'plain';

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                tabs.forEach(t => {
                    t.classList.remove('active');
                    t.setAttribute('aria-selected', 'false');
                    t.tabIndex = -1;
                });
                contents.forEach(c => {
                    c.classList.remove('active');
                    c.hidden = true;
                });
                
                tab.classList.add('active');
                tab.setAttribute('aria-selected', 'true');
                tab.tabIndex = 0;
                const targetId = tab.getAttribute('data-target');
                const targetContent = document.getElementById(targetId);
                targetContent.classList.add('active');
                targetContent.hidden = false;
                currentFormat = targetContent.getAttribute('data-format');

                const saveButton = document.getElementById('btn-save-data');
                if (saveButton?.parentElement) {
                    saveButton.parentElement.style.display = currentFormat === 'scrape' ? 'none' : 'block';
                }
            });
            tab.addEventListener('keydown', event => {
                if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return;
                event.preventDefault();
                const currentIndex = Array.from(tabs).indexOf(tab);
                const direction = ['ArrowRight', 'ArrowDown'].includes(event.key) ? 1 : -1;
                const nextTab = tabs[(currentIndex + direction + tabs.length) % tabs.length];
                nextTab.focus();
                nextTab.click();
            });
        });

        // メッセージ表示
        function showStatus(message, isError = false) {
            const statusDiv = document.getElementById('status-message');
            statusDiv.textContent = message;
            statusDiv.className = 'status-msg ' + (isError ? 'error' : 'success');
            statusDiv.style.display = 'block';
            
            if (window.statusTimeout) clearTimeout(window.statusTimeout);
            
            if (!message.includes('数分かかる場合があります') && !isError) {
                window.statusTimeout = setTimeout(() => {
                    statusDiv.style.display = 'none';
                }, 5000);
            }
        }

        // URLスクレイピング処理
        document.getElementById('btn-scrape-submit').addEventListener('click', function() {
            const url = document.getElementById('scrape-url').value.trim();
            const targetFormat = document.getElementById('scrape-target-format').value;
            const provider = document.getElementById('scrape-provider').value;
            const extraPrompt = document.getElementById('scrape-prompt').value.trim();

            if (!url) {
                showStatus('URLを入力してください。', true);
                return;
            }

            const titleInput = document.getElementById('data-title').value.trim();
            if (!titleInput) {
                showStatus('タイトルを入力してください。自動取得の場合もタイトルは必須です。', true);
                return;
            }

            // メタデータ収集
            var metaLang = document.getElementById('meta-language');
            var metaCat = document.getElementById('meta-category');
            var metaDiff = document.getElementById('meta-difficulty');
            var metaQuality = document.getElementById('meta-quality');
            var metaSource = document.getElementById('meta-source');
            var metaTags = document.getElementById('meta-tags');

            const formData = new FormData();
            formData.append('action', 'frontend_learning_data_scrape_url');
            formData.append('nonce', uploadNonce);
            formData.append('url', url);
            formData.append('target_format', targetFormat);
            formData.append('provider', provider);
            formData.append('extra_prompt', extraPrompt);
            formData.append('title', titleInput);

            if (metaLang && metaLang.value) formData.append('language', metaLang.value);
            if (metaCat && metaCat.value) formData.append('category', metaCat.value);
            if (metaDiff && metaDiff.value) formData.append('difficulty', metaDiff.value);
            if (metaQuality && metaQuality.value) formData.append('quality', metaQuality.value);
            if (metaSource && metaSource.value) {
                formData.append('source', metaSource.value);
            } else {
                formData.append('source', url); // 入力がなければURLをソースに設定
            }
            if (metaTags && metaTags.value) formData.append('tags', metaTags.value);

            showStatus('URLからデータを取得・生成しています... (数分かかる場合があります)', false);
            this.disabled = true;
            this.style.opacity = '0.5';

            fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(response => {
                this.disabled = false;
                this.style.opacity = '1';
                
                if (response.success) {
                    const originalText = this.innerHTML;
                    this.innerHTML = '<span class="material-symbols-outlined">check_circle</span> 登録完了';
                    this.style.backgroundColor = '#10B981';
                    this.style.borderColor = '#10B981';
                    showStatus('データの自動取得と登録が完了しました！(ID: ' + response.data.post_id + ')', false);
                    document.getElementById('scrape-url').value = '';
                    document.getElementById('data-title').value = '';
                    setTimeout(() => {
                        this.innerHTML = originalText;
                        this.style.backgroundColor = 'var(--accent)';
                        this.style.borderColor = 'var(--accent)';
                    }, 3000);
                } else {
                    showStatus(response.data.message || '処理に失敗しました。', true);
                }
            })
            .catch(error => {
                this.disabled = false;
                this.style.opacity = '1';
                showStatus('通信エラーが発生しました。', true);
            });
        });

        // データ蒸留処理
        document.getElementById('btn-distill-submit').addEventListener('click', function() {
            const seed = document.getElementById('distill-seed').value.trim();
            const method = document.getElementById('distill-method').value;
            const targetFormat = document.getElementById('distill-target-format').value;
            const provider = document.getElementById('distill-provider').value;
            const extraPrompt = document.getElementById('distill-prompt').value.trim();

            if (!seed) {
                showStatus('シードデータまたはトピックを入力してください。', true);
                return;
            }

            const titleInput = document.getElementById('data-title').value.trim();
            if (!titleInput) {
                showStatus('タイトルを入力してください。', true);
                return;
            }

            // メタデータ収集
            var metaLang = document.getElementById('meta-language');
            var metaCat = document.getElementById('meta-category');
            var metaDiff = document.getElementById('meta-difficulty');
            var metaQuality = document.getElementById('meta-quality');
            var metaSource = document.getElementById('meta-source');
            var metaTags = document.getElementById('meta-tags');

            const formData = new FormData();
            formData.append('action', 'frontend_learning_data_distill_from_seed');
            formData.append('nonce', uploadNonce);
            formData.append('seed_data', seed);
            formData.append('distill_method', method);
            formData.append('target_format', targetFormat);
            formData.append('provider', provider);
            formData.append('extra_prompt', extraPrompt);
            formData.append('title', titleInput);

            if (metaLang && metaLang.value) formData.append('language', metaLang.value);
            if (metaCat && metaCat.value) formData.append('category', metaCat.value);
            if (metaDiff && metaDiff.value) formData.append('difficulty', metaDiff.value);
            if (metaQuality && metaQuality.value) formData.append('quality', metaQuality.value);
            if (metaSource && metaSource.value) formData.append('source', metaSource.value);
            if (metaTags && metaTags.value) formData.append('tags', metaTags.value);

            showStatus('教師モデルから蒸留データを生成しています... (数分かかる場合があります)', false);
            this.disabled = true;
            this.style.opacity = '0.5';

            fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(response => {
                this.disabled = false;
                this.style.opacity = '1';
                
                handleAjaxSuccess(response);
                if (response.success) {
                    const originalText = this.innerHTML;
                    this.innerHTML = '<span class="material-symbols-outlined">check_circle</span> 登録完了';
                    this.style.backgroundColor = '#10B981';
                    this.style.borderColor = '#10B981';
                    document.getElementById('distill-seed').value = '';
                    document.getElementById('data-title').value = '';
                    setTimeout(() => {
                        this.innerHTML = originalText;
                        this.style.backgroundColor = 'var(--accent)';
                        this.style.borderColor = 'var(--accent)';
                    }, 3000);
                }
            })
            .catch(error => {
                this.disabled = false;
                this.style.opacity = '1';
                showStatus('通信エラーが発生しました。', true);
            });
        });

        // 停止されるまで継続する自動蒸留ジョブ
        const autoStartButton = document.getElementById('btn-auto-distill-start');
        const autoStopButton = document.getElementById('btn-auto-distill-stop');
        const autoStatusBox = document.getElementById('auto-distill-status');
        const autoJobInput = document.getElementById('auto-distill-job-id');
        const autoRuntimeBadge = document.getElementById('auto-runtime-badge');
        const autoRuntimeLabel = document.getElementById('auto-runtime-label');
        const autoRuntimeDetail = document.getElementById('auto-runtime-detail');
        const autoCronHealth = document.getElementById('auto-cron-health');
        const autoActionNotice = document.getElementById('auto-action-notice');
        let autoStatusTimer = null;

        function setAutoRuntimeState(state, label, detail = '') {
            autoRuntimeBadge.className = 'auto-runtime-badge is-' + state;
            autoRuntimeLabel.textContent = label;
            autoRuntimeDetail.textContent = detail;
        }

        function showAutoActionNotice(message, type) {
            autoActionNotice.hidden = false;
            autoActionNotice.className = 'auto-action-notice is-' + type;
            autoActionNotice.textContent = message;
        }

        function renderAutoDistillRunner(runner) {
            const health = runner?.health || 'unknown';
            const healthLabel = autoDistillI18n.runnerHealth[health] || autoDistillI18n.runnerHealth.unknown;
            const summary = document.createElement('span');
            summary.className = 'auto-runner-main';
            summary.textContent = autoDistillI18n.cronRunner + ': ' + healthLabel;
            autoCronHealth.className = 'auto-runner-health is-' + health;
            autoCronHealth.replaceChildren(summary);
            if (runner?.heartbeat) {
                const heartbeat = document.createElement('span');
                heartbeat.className = 'auto-runner-heartbeat';
                heartbeat.textContent = autoDistillI18n.lastHeartbeat + ': ' + runner.heartbeat;
                autoCronHealth.appendChild(heartbeat);
            }
        }

        function resetAutoStartButton() {
            autoStartButton.classList.remove('is-loading');
            autoStartButton.removeAttribute('aria-busy');
        }

        function autoDistillRequest(action, values = {}) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('nonce', uploadNonce);
            Object.keys(values).forEach(key => formData.append(key, values[key]));
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 30000);
            return fetch(ajaxUrl, { method: 'POST', body: formData, signal: controller.signal })
                .then(response => response.json())
                .finally(() => clearTimeout(timeout));
        }

        function renderAutoDistillJob(job) {
            if (!job) {
                autoJobInput.value = '';
                autoStartButton.disabled = false;
                autoStartButton.dataset.mode = 'start';
                autoStartButton.textContent = autoDistillI18n.startLabel;
                autoStopButton.disabled = true;
                autoStatusBox.textContent = autoDistillI18n.noJob;
                setAutoRuntimeState('idle', autoDistillI18n.phaseLabel.idle, autoDistillI18n.noRunningJob);
                resetAutoStartButton();
                renderAutoDistillLogs([]);
                return;
            }
            autoJobInput.value = job.id;
            const running = job.status === 'running';
            autoStartButton.disabled = false;
            autoStartButton.dataset.mode = running ? 'restart' : 'start';
            autoStartButton.textContent = running ? autoDistillI18n.restartLabel : autoDistillI18n.startLabel;
            autoStopButton.disabled = !running;
            resetAutoStartButton();
            const runtimeState = job.status === 'error' ? 'error' : (job.runtime_state || job.phase || (running ? 'running' : 'stopped'));
            const runtimeLabel = autoDistillI18n.phaseLabel[runtimeState] || autoDistillI18n.status[job.status] || job.status;
            const runtimeDetails = [
                autoDistillI18n.job + ' #' + job.id,
                job.started ? autoDistillI18n.startedAt + ': ' + job.started : '',
                job.updated ? autoDistillI18n.lastUpdate + ': ' + job.updated : '',
                job.next_run ? autoDistillI18n.nextRun + ': ' + job.next_run : ''
            ].filter(Boolean).join(' ・ ');
            setAutoRuntimeState(runtimeState, runtimeLabel, runtimeDetails);
            const lines = [
                autoDistillI18n.job + ' #' + job.id + ' — ' + (autoDistillI18n.status[job.status] || job.status) + ' ・ ' + (autoDistillI18n.phase[job.phase] || job.phase),
                autoDistillI18n.iterations + ': ' + job.iterations + '　' + autoDistillI18n.saved + ': ' + job.generated + '　' + autoDistillI18n.duplicates + ': ' + job.duplicates + '　' + autoDistillI18n.invalid + ': ' + job.invalid + '　' + autoDistillI18n.errors + ': ' + job.errors,
                job.next_run ? autoDistillI18n.nextRun + ': ' + job.next_run : '',
                job.last_error ? autoDistillI18n.lastError + ': ' + job.last_error : ''
            ].filter(Boolean);
            autoStatusBox.textContent = lines.join('\n');
            autoStatusBox.style.whiteSpace = 'pre-line';
            if (Array.isArray(job.logs)) renderAutoDistillLogs(job.logs);
        }

        function renderAutoDistillLogs(logs) {
            const logContainer = document.getElementById('ai-log');
            if (!logContainer) return;
            if (!logs.length) {
                logContainer.textContent = autoDistillI18n.noLogs;
                return;
            }
            logContainer.textContent = logs.map(entry => {
                const details = Object.entries(entry.details || {})
                    .map(([key, value]) => key + ':\n' + value)
                    .join('\n');
                const type = String(entry.type || 'log').toUpperCase();
                return '[' + entry.time + '] [' + type + '] ' + entry.message + (details ? '\n' + details : '');
            }).join('\n\n' + '─'.repeat(48) + '\n\n');
            logContainer.scrollTop = logContainer.scrollHeight;
        }

        function refreshAutoDistillStatus(jobId = '') {
            if (autoStatusTimer) clearTimeout(autoStatusTimer);
            const statusValues = jobId ? { job_id: jobId } : {};
            statusValues.include_logs = document.getElementById('log-toggle-checkbox')?.checked ? '1' : '0';
            autoDistillRequest('fourier_auto_distill_status', statusValues)
                .then(response => {
                    if (!response.success) return;
                    renderAutoDistillRunner(response.data.runner);
                    const job = response.data.job;
                    renderAutoDistillJob(job);
                    if (job && job.status === 'running') {
                        autoStatusTimer = setTimeout(() => refreshAutoDistillStatus(job.id), 10000);
                    }
                })
                .catch(() => {
                    autoStatusBox.textContent = autoDistillI18n.statusFailed;
                });
        }

        autoStartButton.addEventListener('click', function() {
            const seed = document.getElementById('distill-seed').value.trim();
            const replaceRunning = this.dataset.mode === 'restart';
            if (!seed && !replaceRunning) {
                showStatus(autoDistillI18n.seedRequired, true);
                return;
            }
            const intervalMinutes = Math.max(1, Math.min(60, parseInt(document.getElementById('auto-distill-interval').value, 10) || 5));
            const batchSize = Math.max(1, Math.min(5, parseInt(document.getElementById('auto-distill-batch').value, 10) || 3));
            const previousJobId = autoJobInput.value;
            if (autoStatusTimer) clearTimeout(autoStatusTimer);
            this.disabled = true;
            this.classList.add('is-loading');
            this.setAttribute('aria-busy', 'true');
            this.textContent = replaceRunning ? autoDistillI18n.restartWorking : autoDistillI18n.startWorking;
            const operationMessage = replaceRunning ? autoDistillI18n.operationRestarting : autoDistillI18n.operationStarting;
            setAutoRuntimeState('operating', autoDistillI18n.phaseLabel.operating, operationMessage);
            showAutoActionNotice(operationMessage, 'working');
            autoStatusBox.textContent = autoDistillI18n.starting;
            autoDistillRequest('fourier_auto_distill_start', {
                seed_data: seed,
                method: document.getElementById('distill-method').value,
                target_format: document.getElementById('distill-target-format').value,
                provider: document.getElementById('distill-provider').value,
                extra_prompt: document.getElementById('distill-prompt').value.trim(),
                title_prefix: document.getElementById('data-title').value.trim() || 'Auto Distilled',
                interval_seconds: intervalMinutes * 60,
                batch_size: batchSize,
                language: document.getElementById('meta-language')?.value || 'ja',
                tags: document.getElementById('meta-tags')?.value || 'auto-distillation',
                replace_running: replaceRunning ? '1' : '0'
            }).then(response => {
                if (!response.success) {
                    this.disabled = false;
                    resetAutoStartButton();
                    const errorMessage = response.data?.message || autoDistillI18n.startFailed;
                    autoStatusBox.textContent = errorMessage;
                    showAutoActionNotice(errorMessage, 'error');
                    refreshAutoDistillStatus(previousJobId);
                    return;
                }
                autoJobInput.value = response.data.job_id;
                autoStatusBox.textContent = response.data.message;
                const acceptedMessage = replaceRunning
                    ? autoDistillI18n.restartAccepted + ' ' + autoDistillI18n.job + ' #' + previousJobId + ' → #' + response.data.job_id
                    : autoDistillI18n.startAccepted + ' ' + autoDistillI18n.job + ' #' + response.data.job_id;
                showAutoActionNotice(acceptedMessage, 'success');
                refreshAutoDistillStatus(response.data.job_id);
            }).catch(() => {
                this.disabled = false;
                resetAutoStartButton();
                autoStatusBox.textContent = autoDistillI18n.startNetworkFailed;
                showAutoActionNotice(autoDistillI18n.startNetworkFailed, 'error');
                refreshAutoDistillStatus(previousJobId);
            });
        });

        autoStopButton.addEventListener('click', function() {
            const jobId = autoJobInput.value;
            if (!jobId) return;
            if (autoStatusTimer) clearTimeout(autoStatusTimer);
            this.disabled = true;
            setAutoRuntimeState('operating', autoDistillI18n.phaseLabel.operating, autoDistillI18n.stopping);
            showAutoActionNotice(autoDistillI18n.stopping, 'working');
            autoStatusBox.textContent = autoDistillI18n.stopping;
            autoDistillRequest('fourier_auto_distill_stop', { job_id: jobId }).then(response => {
                if (!response.success) {
                    this.disabled = false;
                    const errorMessage = response.data?.message || autoDistillI18n.stopFailed;
                    autoStatusBox.textContent = errorMessage;
                    showAutoActionNotice(errorMessage, 'error');
                    return;
                }
                showAutoActionNotice(autoDistillI18n.stopAccepted, 'success');
                refreshAutoDistillStatus(jobId);
            }).catch(() => {
                this.disabled = false;
                autoStatusBox.textContent = autoDistillI18n.stopNetworkFailed;
                showAutoActionNotice(autoDistillI18n.stopNetworkFailed, 'error');
            });
        });

        refreshAutoDistillStatus();

        // 検索処理
        const searchButton = document.getElementById('btn-search');
        if (searchButton) searchButton.addEventListener('click', function() {
            const keyword = document.getElementById('search-keyword').value.trim();
            const resultsContainer = document.getElementById('search-results');
            
            resultsContainer.innerHTML = '<p>検索中...</p>';
            
            const formData = new FormData();
            formData.append('action', 'frontend_learning_data_search');
            formData.append('nonce', uploadNonce);
            formData.append('keyword', keyword);

            fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(response => {
                if (response.success) {
                    const posts = response.data.posts;
                    if (posts.length === 0) {
                        resultsContainer.innerHTML = '<p>該当するデータが見つかりませんでした。</p>';
                        return;
                    }

                    let html = `<h4>検索結果: ${posts.length}件</h4>`;
                    posts.forEach(post => {
                        let jsonStr = '';
                        try {
                            const parsed = JSON.parse(post.post_content);
                            jsonStr = JSON.stringify(parsed, null, 2);
                        } catch(e) {
                            jsonStr = post.post_content;
                        }

                        html += `
                            <div class="search-result-item">
                                <h5 style="margin:0 0 0.5rem 0; font-size: 1.1rem;">
                                    ${escHtml(post.post_title)} 
                                    <span style="font-size:0.8rem; color:#666; font-weight:normal; background:#eee; padding:2px 6px; border-radius:4px; margin-left:0.5rem;">ID: ${post.ID}</span>
                                </h5>
                                <div class="search-result-json">${escHtml(jsonStr)}</div>
                                <div class="result-actions">
                                    <button type="button" onclick="openEditModal(${post.ID})">
                                        <span class="material-symbols-outlined" style="font-size:0.9rem;">edit</span> 編集
                                    </button>
                                    <button type="button" onclick="duplicateData(${post.ID})">
                                        <span class="material-symbols-outlined" style="font-size:0.9rem;">content_copy</span> 複製
                                    </button>
                                    <button type="button" class="btn-base btn-danger" onclick="deleteData(${post.ID}, this)">
                                        <span class="material-symbols-outlined" style="font-size:0.9rem;">delete</span> 削除
                                    </button>
                                </div>
                            </div>
                        `;
                    });
                    resultsContainer.innerHTML = html;
                } else {
                    resultsContainer.innerHTML = `<p style="color:red;">${response.data.message}</p>`;
                }
            })
            .catch(error => {
                resultsContainer.innerHTML = `<p style="color:red;">通信エラーが発生しました。</p>`;
            });
        });

        // HTMLエスケープヘルパー
        function escHtml(str) {
            const div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }

        // 品質スコア星クリック
        function initStars(containerId, hiddenId) {
            const container = document.getElementById(containerId);
            const hidden = document.getElementById(hiddenId);
            if (!container || !hidden) return;
            container.querySelectorAll('.star').forEach(star => {
                star.addEventListener('click', function() {
                    const val = parseInt(this.getAttribute('data-value'));
                    hidden.value = val;
                    container.querySelectorAll('.star').forEach((s, i) => {
                        s.classList.toggle('active', i < val);
                    });
                });
            });
        }
        initStars('quality-stars', 'meta-quality');
        initStars('edit-quality-stars', 'edit-meta-quality');

        // タグ入力
        (function() {
            const wrapper = document.getElementById('tag-input-wrapper');
            const input = document.getElementById('meta-tags-input');
            const hidden = document.getElementById('meta-tags');
            if (!wrapper || !input || !hidden) return;
            let tags = [];
            function renderTags() {
                wrapper.querySelectorAll('.tag-chip').forEach(c => c.remove());
                tags.forEach((tag, i) => {
                    const chip = document.createElement('span');
                    chip.className = 'tag-chip';
                    chip.innerHTML = escHtml(tag) + ' <span class="remove-tag" data-index="' + i + '">×</span>';
                    wrapper.insertBefore(chip, input);
                });
                hidden.value = tags.join(',');
            }
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && this.value.trim()) {
                    e.preventDefault();
                    const tag = this.value.trim();
                    if (!tags.includes(tag)) { tags.push(tag); renderTags(); }
                    this.value = '';
                }
            });
            wrapper.addEventListener('click', function(e) {
                if (e.target.classList.contains('remove-tag')) {
                    tags.splice(parseInt(e.target.getAttribute('data-index')), 1);
                    renderTags();
                }
                input.focus();
            });
        })();

        // 文字数カウンター
        function addCharCounter(textareaId, maxChars) {
            const textarea = document.getElementById(textareaId);
            if (!textarea) return;
            let counter = textarea.parentElement.querySelector('.char-counter');
            if (!counter) {
                counter = document.createElement('div');
                counter.className = 'char-counter';
                textarea.parentElement.appendChild(counter);
            }
            function update() {
                const len = textarea.value.length;
                counter.textContent = len.toLocaleString() + ' 文字';
                counter.classList.remove('warning', 'error');
                if (maxChars && len > maxChars * 0.9) counter.classList.add('warning');
                if (maxChars && len > maxChars) counter.classList.add('error');
            }
            textarea.addEventListener('input', update);
            update();
        }
        addCharCounter('plain-text', 100000);
        addCharCounter('inst-instruction', 50000);
        addCharCounter('inst-input', 50000);
        addCharCounter('inst-output', 50000);
        addCharCounter('cot-question', 50000);
        addCharCounter('cot-thought', 100000);
        addCharCounter('cot-answer', 50000);

        // 編集モーダル制御
        var editModalOverlay = document.getElementById('edit-modal-overlay');
        var editModalClose = document.getElementById('edit-modal-close');
        var btnEditCancel = document.getElementById('btn-edit-cancel');
        if (editModalClose) editModalClose.addEventListener('click', closeEditModal);
        if (btnEditCancel) btnEditCancel.addEventListener('click', closeEditModal);
        if (editModalOverlay) editModalOverlay.addEventListener('click', function(e) {
            if (e.target === editModalOverlay) closeEditModal();
        });

        function closeEditModal() {
            editModalOverlay.classList.remove('active');
        }

        // 編集モーダルを開く
        window.openEditModal = function(postId) {
            const fd = new FormData();
            fd.append('action', 'frontend_learning_data_get_single');
            fd.append('nonce', uploadNonce);
            fd.append('post_id', postId);

            fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (!res.success) { alert(res.data.message || 'エラー'); return; }
                const d = res.data;
                document.getElementById('edit-title').value = d.title;
                document.getElementById('edit-format-label').textContent = d.format;

                // メタデータ設定
                if (d.meta) {
                    document.getElementById('edit-meta-language').value = d.meta.language || '';
                    document.getElementById('edit-meta-category').value = d.meta.category || '';
                    document.getElementById('edit-meta-difficulty').value = d.meta.difficulty || '';
                    document.getElementById('edit-meta-source').value = d.meta.source || '';
                    document.getElementById('edit-meta-tags').value = d.meta.tags || '';
                    var q = parseInt(d.meta.quality) || 0;
                    document.getElementById('edit-meta-quality').value = q;
                    document.getElementById('edit-quality-stars').querySelectorAll('.star').forEach((s, i) => {
                        s.classList.toggle('active', i < q);
                    });
                }

                // フィールド動的生成
                var container = document.getElementById('edit-fields-container');
                container.innerHTML = '';
                var data = d.data || {};
                var format = d.format;

                if (format === 'plain') {
                    container.innerHTML = '<div class="upload-form-group"><label>テキスト本文:</label><textarea id="edit-field-text" class="upload-form-input" rows="8">' + escHtml(data.text || '') + '</textarea></div>';
                } else if (format === 'instruction') {
                    container.innerHTML = '<div class="upload-form-group"><label>Instruction:</label><textarea id="edit-field-instruction" class="upload-form-input" rows="3">' + escHtml(data.instruction || '') + '</textarea></div>'
                        + '<div class="upload-form-group"><label>Input:</label><textarea id="edit-field-input" class="upload-form-input" rows="3">' + escHtml(data.input || '') + '</textarea></div>'
                        + '<div class="upload-form-group"><label>Output:</label><textarea id="edit-field-output" class="upload-form-input" rows="5">' + escHtml(data.output || '') + '</textarea></div>';
                } else if (format === 'cot') {
                    container.innerHTML = '<div class="upload-form-group"><label>Question:</label><textarea id="edit-field-question" class="upload-form-input" rows="3">' + escHtml(data.question || '') + '</textarea></div>'
                        + '<div class="upload-form-group"><label>Thought:</label><textarea id="edit-field-thought" class="upload-form-input" rows="6">' + escHtml(data.thought || '') + '</textarea></div>'
                        + '<div class="upload-form-group"><label>Answer:</label><textarea id="edit-field-answer" class="upload-form-input" rows="3">' + escHtml(data.answer || '') + '</textarea></div>';
                } else if (format === 'frontend_code') {
                    container.innerHTML = '<div class="upload-form-group"><label>説明:</label><textarea id="edit-field-explanation" class="upload-form-input" rows="2">' + escHtml(data.explanation || '') + '</textarea></div>'
                        + '<div class="upload-form-group"><label>HTML:</label><textarea id="edit-field-html" class="upload-form-input" rows="4" style="font-family:monospace;">' + escHtml(data.html || '') + '</textarea></div>'
                        + '<div class="upload-form-group"><label>CSS:</label><textarea id="edit-field-css" class="upload-form-input" rows="4" style="font-family:monospace;">' + escHtml(data.css || '') + '</textarea></div>'
                        + '<div class="upload-form-group"><label>JavaScript:</label><textarea id="edit-field-js" class="upload-form-input" rows="4" style="font-family:monospace;">' + escHtml(data.js || '') + '</textarea></div>';
                } else {
                    // chatml, sharegpt, structured 等はJSON編集
                    container.innerHTML = '<div class="upload-form-group"><label>JSONデータ:</label><textarea id="edit-field-json" class="upload-form-input" rows="10" style="font-family:monospace;">' + escHtml(JSON.stringify(data, null, 2)) + '</textarea></div>';
                }

                // モーダル内保存ボタン
                document.getElementById('btn-edit-save').onclick = function() {
                    saveEditData(postId, format);
                };

                editModalOverlay.classList.add('active');
            });
        };

        function saveEditData(postId, format) {
            var title = document.getElementById('edit-title').value.trim();
            if (!title) { showEditStatus('タイトルを入力してください。', true); return; }
            var data = {};
            try {
                if (format === 'plain') {
                    data = { text: document.getElementById('edit-field-text').value };
                } else if (format === 'instruction') {
                    data = { instruction: document.getElementById('edit-field-instruction').value, input: document.getElementById('edit-field-input').value, output: document.getElementById('edit-field-output').value };
                } else if (format === 'cot') {
                    data = { question: document.getElementById('edit-field-question').value, thought: document.getElementById('edit-field-thought').value, answer: document.getElementById('edit-field-answer').value };
                } else if (format === 'frontend_code') {
                    data = { explanation: document.getElementById('edit-field-explanation').value, html: document.getElementById('edit-field-html').value, css: document.getElementById('edit-field-css').value, js: document.getElementById('edit-field-js').value };
                } else {
                    data = JSON.parse(document.getElementById('edit-field-json').value);
                }
            } catch(e) { showEditStatus('JSONの解析に失敗しました。', true); return; }

            var fd = new FormData();
            fd.append('action', 'frontend_learning_data_update');
            fd.append('nonce', uploadNonce);
            fd.append('post_id', postId);
            fd.append('title', title);
            fd.append('json_data', JSON.stringify({ format: format, data: data }));
            fd.append('language', document.getElementById('edit-meta-language').value);
            fd.append('category', document.getElementById('edit-meta-category').value);
            fd.append('difficulty', document.getElementById('edit-meta-difficulty').value);
            fd.append('quality', document.getElementById('edit-meta-quality').value);
            fd.append('source', document.getElementById('edit-meta-source').value);
            fd.append('tags', document.getElementById('edit-meta-tags').value);

            fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    showEditStatus('更新しました。');
                    setTimeout(closeEditModal, 1500);
                } else {
                    showEditStatus(res.data.message || '更新に失敗しました。', true);
                }
            }).catch(() => showEditStatus('通信エラー', true));
        }

        function showEditStatus(msg, isError) {
            var el = document.getElementById('edit-status-message');
            el.textContent = msg;
            el.className = 'status-msg ' + (isError ? 'error' : 'success');
            setTimeout(() => { el.style.display = 'none'; }, 4000);
        }

        // データ複製
        window.duplicateData = function(postId) {
            if (!confirm('このデータを複製しますか？')) return;
            var fd = new FormData();
            fd.append('action', 'frontend_learning_data_duplicate');
            fd.append('nonce', uploadNonce);
            fd.append('post_id', postId);
            fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    showStatus('データを複製しました。(新ID: ' + res.data.post_id + ')');
                } else {
                    showStatus(res.data.message || '複製に失敗しました。', true);
                }
            }).catch(() => showStatus('通信エラー', true));
        };

        // データ削除
        window.deleteData = function(postId, btn) {
            if (!confirm('このデータを削除しますか？この操作は取り消せません。')) return;
            var fd = new FormData();
            fd.append('action', 'frontend_learning_data_delete');
            fd.append('nonce', uploadNonce);
            fd.append('post_id', postId);
            fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    if (btn) {
                        var item = btn.closest('.search-result-item');
                        if (item) item.style.display = 'none';
                    }
                    showStatus('データを削除しました。(ID: ' + postId + ')');
                } else {
                    showStatus(res.data.message || '削除に失敗しました。', true);
                }
            }).catch(() => showStatus('通信エラー', true));
        };

        // URLパラメータによる自動編集モーダル起動
        const urlParams = new URLSearchParams(window.location.search);
        const editId = urlParams.get('edit_id');
        if (editId) {
            openEditModal(editId);
        }
    });
</script>
<?php endif; ?>

<?php
get_footer();
?>
