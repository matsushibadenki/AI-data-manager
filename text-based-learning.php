<?php
/*
 * Name: text-based-learning.php
 * Description: ディープラーニング用テキストデータ登録・管理画面。
 * Template Name: Text Based Learning
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
                <h2 class="upload-title" style="margin-top: 0; margin-bottom: 0.25rem;"><?php echo esc_html__('ディープラーニング用データ管理', 'fourier'); ?></h2>
                <p class="upload-desc" style="margin-bottom: 0;"><?php echo esc_html__('テキストベースの学習データの登録と検索を行います。', 'fourier'); ?></p>
            </div>

            <!-- 検索セクション -->
            <div class="search-section">
                <h3><?php echo esc_html__('データ検索', 'fourier'); ?></h3>
                <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                    <input type="text" id="search-keyword" class="upload-form-input" placeholder="<?php echo esc_attr__('キーワードを入力...', 'fourier'); ?>" style="flex-grow: 1;" />
                    <button type="button" id="btn-search" class="btn-base btn-primary">
                        <span class="material-symbols-outlined">search</span>
                        <?php echo esc_html__('検索', 'fourier'); ?>
                    </button>
                </div>
                <div id="search-results" class="search-results-container"></div>
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
                            <label for="meta-speakers"><?php echo esc_html__('話者名/登場人物', 'fourier'); ?></label>
                            <input type="text" id="meta-speakers" class="upload-form-input" placeholder="<?php echo esc_attr__('例: ゲスト名, 司会者', 'fourier'); ?>" />
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

                <div class="learning-tabs">
                    <button type="button" class="learning-tab active" data-target="tab-plain">プレーンテキスト</button>
                    <button type="button" class="learning-tab" data-target="tab-instruction">Instruction</button>
                    <button type="button" class="learning-tab" data-target="tab-chatml">ChatML</button>
                    <button type="button" class="learning-tab" data-target="tab-sharegpt">ShareGPT</button>
                    <button type="button" class="learning-tab" data-target="tab-cot">CoT (思考過程)</button>
                    <button type="button" class="learning-tab" data-target="tab-dpo">DPO / RLHF</button>
                    <button type="button" class="learning-tab" data-target="tab-frontend">HTML/CSS/JS</button>
                    <button type="button" class="learning-tab" data-target="tab-structured">構造化データ</button>
                    
                </div>

                <!-- 1. プレーンテキスト -->
                <div id="tab-plain" class="learning-tab-content active" data-format="plain">
                    <div class="upload-form-group">
                        <label for="plain-text"><?php echo esc_html__('テキスト本文:', 'fourier'); ?></label>
                        <textarea id="plain-text" class="upload-form-input" rows="8" placeholder="事前学習などに用いるプレーンテキストを入力してください。"></textarea>
                    </div>
                </div>

                <!-- 2. Instruction形式 -->
                <div id="tab-instruction" class="learning-tab-content" data-format="instruction">
                    <div class="upload-form-group">
                        <label for="inst-instruction"><?php echo esc_html__('Instruction (指示):', 'fourier'); ?></label>
                        <textarea id="inst-instruction" class="upload-form-input" rows="3" placeholder="タスクの指示を入力"></textarea>
                    </div>
                    <div class="upload-form-group">
                        <label for="inst-input"><?php echo esc_html__('Input (入力/文脈 - オプション):', 'fourier'); ?></label>
                        <textarea id="inst-input" class="upload-form-input" rows="3" placeholder="指示に対する追加の入力や文脈"></textarea>
                    </div>
                    <div class="upload-form-group">
                        <label for="inst-output"><?php echo esc_html__('Output (出力):', 'fourier'); ?></label>
                        <textarea id="inst-output" class="upload-form-input" rows="5" placeholder="期待される出力・回答"></textarea>
                    </div>
                </div>

                <!-- 3. ChatML形式 -->
                <div id="tab-chatml" class="learning-tab-content" data-format="chatml">
                    <p style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 1rem;">System, User, Assistantの対話形式で入力します。</p>
                    <div id="chatml-container">
                        <!-- 動的追加行 -->
                    </div>
                    <button type="button" id="btn-add-chatml" class="btn-base btn-secondary" style="width: 100%; margin-top: 1rem; border-style: dashed;">+ メッセージを追加</button>
                </div>

                <!-- 4. ShareGPT形式 -->
                <div id="tab-sharegpt" class="learning-tab-content" data-format="sharegpt">
                    <p style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 1rem;">human, gptなどのロールで会話を入力します。</p>
                    <div id="sharegpt-container">
                        <!-- 動的追加行 -->
                    </div>
                    <button type="button" id="btn-add-sharegpt" class="btn-base btn-secondary" style="width: 100%; margin-top: 1rem; border-style: dashed;">+ 会話を追加</button>
                </div>

                <!-- 5. 思考過程(CoT) -->
                <div id="tab-cot" class="learning-tab-content" data-format="cot">
                    <div class="upload-form-group">
                        <label for="cot-question"><?php echo esc_html__('Question (質問):', 'fourier'); ?></label>
                        <textarea id="cot-question" class="upload-form-input" rows="3" placeholder="解決すべき問題や質問"></textarea>
                    </div>
                    <div class="upload-form-group">
                        <label for="cot-thought"><?php echo esc_html__('Thought (思考過程):', 'fourier'); ?></label>
                        <textarea id="cot-thought" class="upload-form-input" rows="6" placeholder="問題を解決するためのステップバイステップの推論プロセス"></textarea>
                    </div>
                    <div class="upload-form-group">
                        <label for="cot-answer"><?php echo esc_html__('Answer (最終回答):', 'fourier'); ?></label>
                        <textarea id="cot-answer" class="upload-form-input" rows="3" placeholder="推論に基づく最終的な回答"></textarea>
                    </div>
                </div>

                <!-- 5.5 DPO / RLHF -->
                <div id="tab-dpo" class="learning-tab-content" data-format="dpo">
                    <p style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 1rem;">RLHFやDPO学習用に、プロンプトと良い回答/悪い回答のペアを登録します。</p>
                    <div class="upload-form-group">
                        <label for="dpo-prompt"><?php echo esc_html__('Prompt (プロンプト/指示):', 'fourier'); ?></label>
                        <textarea id="dpo-prompt" class="upload-form-input" rows="3" placeholder="ユーザーからのプロンプトやシステム指示を入力"></textarea>
                    </div>
                    <div class="upload-form-group">
                        <label for="dpo-chosen"><?php echo esc_html__('Chosen (良い回答):', 'fourier'); ?></label>
                        <textarea id="dpo-chosen" class="upload-form-input" rows="4" placeholder="モデルが学習すべき望ましい回答"></textarea>
                    </div>
                    <div class="upload-form-group">
                        <label for="dpo-rejected"><?php echo esc_html__('Rejected (悪い回答):', 'fourier'); ?></label>
                        <textarea id="dpo-rejected" class="upload-form-input" rows="4" placeholder="モデルに避けさせたい好ましくない回答"></textarea>
                    </div>
                </div>

                <!-- 6. フロントエンドコード (HTML/CSS/JS) -->
                <div id="tab-frontend" class="learning-tab-content" data-format="frontend_code">
                    <p style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 1rem;">Webフロントエンドのコードスニペットを登録します。</p>
                    <div class="upload-form-group">
                        <label for="fe-explanation"><?php echo esc_html__('説明・目的:', 'fourier'); ?></label>
                        <textarea id="fe-explanation" class="upload-form-input" rows="2" placeholder="コードの目的や動作の説明"></textarea>
                    </div>
                    <div class="upload-form-group">
                        <label for="fe-html"><?php echo esc_html__('HTML:', 'fourier'); ?></label>
                        <textarea id="fe-html" class="upload-form-input" rows="5" placeholder="<div>...</div>" style="font-family: monospace;"></textarea>
                    </div>
                    <div class="upload-form-group">
                        <label for="fe-css"><?php echo esc_html__('CSS:', 'fourier'); ?></label>
                        <textarea id="fe-css" class="upload-form-input" rows="5" placeholder=".class { ... }" style="font-family: monospace;"></textarea>
                    </div>
                    <div class="upload-form-group">
                        <label for="fe-js"><?php echo esc_html__('JavaScript:', 'fourier'); ?></label>
                        <textarea id="fe-js" class="upload-form-input" rows="5" placeholder="console.log('...');" style="font-family: monospace;"></textarea>
                    </div>
                </div>

                <!-- 7. 構造化データ -->
                <div id="tab-structured" class="learning-tab-content" data-format="structured">
                    <div class="upload-form-group">
                        <label for="structured-json"><?php echo esc_html__('JSONデータ:', 'fourier'); ?></label>
                        <textarea id="structured-json" class="upload-form-input" rows="10" placeholder='{"key": "value", ...}' style="font-family: monospace;"></textarea>
                        <small style="color: var(--text-secondary);">※有効なJSON形式で入力してください。</small>
                    </div>
                </div>

                
                    <div class="upload-form-group" style="margin-top: 1rem;">
                        <label for="scrape-target-format"><?php echo esc_html__('生成するデータ形式:', 'fourier'); ?></label>
                        <select id="scrape-target-format" class="upload-form-input">
                            <option value="instruction">Instruction (QAペア)</option>
                            <option value="chatml">ChatML (会話形式)</option>
                            <option value="cot">CoT (思考過程付き)</option>
                            <option value="dpo">DPO / RLHF (比較データ)</option>
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
                        <label for="scrape-prompt"><?php echo esc_html__('追加の指示（任意）:', 'fourier'); ?></label>
                        <textarea id="scrape-prompt" class="upload-form-input" rows="3" placeholder="例: 内容を小学生にもわかるように易しく解説するQAセットを作成して。"></textarea>
                    </div>
                    <div style="margin-top: 1.5rem; text-align: center;">
                        <button type="button" id="btn-scrape-submit" class="btn-base btn-primary" style="background: var(--accent); border-color: var(--accent); color: var(--text-inverse);">
                            <span class="material-symbols-outlined">language</span> 自動取得・生成して登録
                        </button>
                    </div>
                </div>

                <div id="status-message" class="status-msg"></div>

                <div style="text-align: center; margin-top: 2rem;">
                    <input type="hidden" id="edit-post-id" value="" />
                    <button type="button" id="btn-save-data" class="btn-base btn-primary" style="margin: 0 auto;">
                        <span class="material-symbols-outlined">save</span>
                        <?php echo esc_html__('データを登録', 'fourier'); ?>
                    </button>
                </div>

            </div>



            <!-- 編集モーダル -->
            <div class="edit-modal-overlay" id="edit-modal-overlay">
                <div class="edit-modal">
                    <button class="edit-modal-close" id="edit-modal-close">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                    <h3 id="edit-modal-title"><?php echo esc_html__('データを編集', 'fourier'); ?></h3>
                    <div class="upload-form-group" style="margin-bottom: 1rem;">
                        <label for="edit-title" style="font-weight: 600;"><?php echo esc_html__('タイトル:', 'fourier'); ?></label>
                        <input type="text" id="edit-title" class="upload-form-input" />
                    </div>
                    <div class="upload-form-group" style="margin-bottom: 1rem;">
                        <label style="font-weight: 600;"><?php echo esc_html__('フォーマット:', 'fourier'); ?></label>
                        <span id="edit-format-label" style="font-size: 0.9rem; color: var(--text-secondary);"></span>
                    </div>
                    <div id="edit-fields-container">
                        <!-- 動的にフィールドを生成 -->
                    </div>
                    <details class="metadata-section" style="margin-top: 1rem;">
                        <summary>
                            <span class="material-symbols-outlined arrow" style="font-size: 1rem;">chevron_right</span>
                            <?php echo esc_html__('メタデータ', 'fourier'); ?>
                        </summary>
                        <div class="metadata-grid" style="margin-top: 1rem;">
                            <div>
                                <label for="edit-meta-language"><?php echo esc_html__('言語', 'fourier'); ?></label>
                                <select id="edit-meta-language" class="upload-form-input">
                                    <option value=""><?php echo esc_html__('-- 選択 --', 'fourier'); ?></option>
                                    <option value="ja"><?php echo esc_html__('日本語', 'fourier'); ?></option>
                                    <option value="en"><?php echo esc_html__('英語', 'fourier'); ?></option>
                                    <option value="zh"><?php echo esc_html__('中国語', 'fourier'); ?></option>
                                    <option value="ko"><?php echo esc_html__('韓国語', 'fourier'); ?></option>
                                    <option value="multi"><?php echo esc_html__('多言語', 'fourier'); ?></option>
                                </select>
                            </div>
                            <div>
                                <label for="edit-meta-category"><?php echo esc_html__('カテゴリ', 'fourier'); ?></label>
                                <input type="text" id="edit-meta-category" class="upload-form-input" />
                            </div>
                            <div>
                                <label for="edit-meta-difficulty"><?php echo esc_html__('難易度', 'fourier'); ?></label>
                                <select id="edit-meta-difficulty" class="upload-form-input">
                                    <option value=""><?php echo esc_html__('-- 選択 --', 'fourier'); ?></option>
                                    <option value="beginner"><?php echo esc_html__('初級', 'fourier'); ?></option>
                                    <option value="intermediate"><?php echo esc_html__('中級', 'fourier'); ?></option>
                                    <option value="advanced"><?php echo esc_html__('上級', 'fourier'); ?></option>
                                </select>
                            </div>
                            <div>
                                <label><?php echo esc_html__('品質スコア', 'fourier'); ?></label>
                                <div class="quality-stars" id="edit-quality-stars">
                                    <span class="star" data-value="1">★</span>
                                    <span class="star" data-value="2">★</span>
                                    <span class="star" data-value="3">★</span>
                                    <span class="star" data-value="4">★</span>
                                    <span class="star" data-value="5">★</span>
                                </div>
                                <input type="hidden" id="edit-meta-quality" value="0" />
                            </div>
                            <div>
                                <label for="edit-meta-source"><?php echo esc_html__('出典元', 'fourier'); ?></label>
                                <input type="text" id="edit-meta-source" class="upload-form-input" />
                            </div>
                            <div>
                                <label for="edit-meta-speakers"><?php echo esc_html__('話者名/登場人物', 'fourier'); ?></label>
                                <input type="text" id="edit-meta-speakers" class="upload-form-input" />
                            </div>
                            <div>
                                <label for="edit-meta-tags"><?php echo esc_html__('タグ（カンマ区切り）', 'fourier'); ?></label>
                                <input type="text" id="edit-meta-tags" class="upload-form-input" />
                            </div>
                        </div>
                    </details>
                    <div id="edit-status-message" class="status-msg" style="margin-top: 1rem;"></div>
                    <div style="text-align: center; margin-top: 1.5rem; display: flex; gap: 1rem; justify-content: center;">
                        <button type="button" id="btn-edit-save" class="btn-base btn-primary">
                            <span class="material-symbols-outlined">save</span>
                            <?php echo esc_html__('更新', 'fourier'); ?>
                        </button>
                        <button type="button" id="btn-edit-cancel" class="btn-base btn-primary" style="opacity: 0.7;">
                            <?php echo esc_html__('キャンセル', 'fourier'); ?>
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (!document.getElementById('btn-save-data')) return;

        var ajaxUrl = "<?php echo esc_url(admin_url('admin-ajax.php')); ?>";
        var uploadNonce = "<?php echo wp_create_nonce('learning_data_action'); ?>";

        // タブ切り替え制御
        const tabs = document.querySelectorAll('.learning-tab');
        const contents = document.querySelectorAll('.learning-tab-content');
        let currentFormat = 'plain';

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                tabs.forEach(t => t.classList.remove('active'));
                contents.forEach(c => c.classList.remove('active'));
                
                tab.classList.add('active');
                const targetId = tab.getAttribute('data-target');
                const targetContent = document.getElementById(targetId);
                targetContent.classList.add('active');
                currentFormat = targetContent.getAttribute('data-format');

                
                    document.getElementById('btn-save-data').parentElement.style.display = 'block';
            });
        });

        // ChatML 行追加
        const chatmlContainer = document.getElementById('chatml-container');
        const btnAddChatml = document.getElementById('btn-add-chatml');
        
        function addChatmlRow(role = 'user', content = '') {
            const row = document.createElement('div');
            row.className = 'dynamic-row chatml-row';
            row.innerHTML = `
                <select class="upload-form-input chatml-role">
                    <option value="system" ${role === 'system' ? 'selected' : ''}>system</option>
                    <option value="user" ${role === 'user' ? 'selected' : ''}>user</option>
                    <option value="assistant" ${role === 'assistant' ? 'selected' : ''}>assistant</option>
                </select>
                <textarea class="upload-form-input chatml-content" rows="2" placeholder="メッセージを入力...">${content}</textarea>
                <button type="button" class="btn-base btn-danger" style="padding: 0.5rem; border-radius: 4px;" title="削除"><span class="material-symbols-outlined">delete</span></button>
            `;
            row.querySelector('.btn-danger').addEventListener('click', () => row.remove());
            chatmlContainer.appendChild(row);
        }
        
        if (btnAddChatml) {
            btnAddChatml.addEventListener('click', () => addChatmlRow('user', ''));
            // 初期状態としてシステムとユーザーを用意
            addChatmlRow('system', 'You are a helpful assistant.');
            addChatmlRow('user', '');
        }

        // ShareGPT 行追加
        const sharegptContainer = document.getElementById('sharegpt-container');
        const btnAddSharegpt = document.getElementById('btn-add-sharegpt');
        
        function addSharegptRow(from = 'human', value = '') {
            const row = document.createElement('div');
            row.className = 'dynamic-row sharegpt-row';
            row.innerHTML = `
                <select class="upload-form-input sharegpt-from">
                    <option value="system" ${from === 'system' ? 'selected' : ''}>system</option>
                    <option value="human" ${from === 'human' ? 'selected' : ''}>human</option>
                    <option value="gpt" ${from === 'gpt' ? 'selected' : ''}>gpt</option>
                </select>
                <textarea class="upload-form-input sharegpt-value" rows="2" placeholder="会話を入力...">${value}</textarea>
                <button type="button" class="btn-base btn-danger" style="padding: 0.5rem; border-radius: 4px;" title="削除"><span class="material-symbols-outlined">delete</span></button>
            `;
            row.querySelector('.btn-danger').addEventListener('click', () => row.remove());
            sharegptContainer.appendChild(row);
        }
        
        if (btnAddSharegpt) {
            btnAddSharegpt.addEventListener('click', () => addSharegptRow('human', ''));
            addSharegptRow('human', '');
            addSharegptRow('gpt', '');
        }

        // メッセージ表示
        function showStatus(message, isError = false) {
            const statusDiv = document.getElementById('status-message');
            statusDiv.textContent = message;
            statusDiv.className = 'status-msg ' + (isError ? 'error' : 'success');
            setTimeout(() => {
                statusDiv.style.display = 'none';
            }, 5000);
        }

        // データ登録
        document.getElementById('btn-save-data').addEventListener('click', function() {
            const title = document.getElementById('data-title').value.trim();
            if (!title) {
                showStatus('タイトルを入力してください。', true);
                return;
            }

            let formatData = {};

            try {
                if (currentFormat === 'plain') {
                    formatData = { text: document.getElementById('plain-text').value };
                } 
                else if (currentFormat === 'instruction') {
                    formatData = {
                        instruction: document.getElementById('inst-instruction').value,
                        input: document.getElementById('inst-input').value,
                        output: document.getElementById('inst-output').value
                    };
                }
                else if (currentFormat === 'chatml') {
                    const messages = [];
                    document.querySelectorAll('.chatml-row').forEach(row => {
                        const role = row.querySelector('.chatml-role').value;
                        const content = row.querySelector('.chatml-content').value;
                        if (content.trim()) messages.push({ role, content });
                    });
                    formatData = { messages };
                }
                else if (currentFormat === 'sharegpt') {
                    const conversations = [];
                    document.querySelectorAll('.sharegpt-row').forEach(row => {
                        const from = row.querySelector('.sharegpt-from').value;
                        const value = row.querySelector('.sharegpt-value').value;
                        if (value.trim()) conversations.push({ from, value });
                    });
                    formatData = { conversations };
                }
                else if (currentFormat === 'cot') {
                    formatData = {
                        question: document.getElementById('cot-question').value,
                        thought: document.getElementById('cot-thought').value,
                        answer: document.getElementById('cot-answer').value
                    };
                }
                else if (currentFormat === 'dpo') {
                    formatData = {
                        prompt: document.getElementById('dpo-prompt').value,
                        chosen: document.getElementById('dpo-chosen').value,
                        rejected: document.getElementById('dpo-rejected').value
                    };
                }
                else if (currentFormat === 'frontend_code') {
                    formatData = {
                        explanation: document.getElementById('fe-explanation').value,
                        html: document.getElementById('fe-html').value,
                        css: document.getElementById('fe-css').value,
                        js: document.getElementById('fe-js').value
                    };
                }
                else if (currentFormat === 'structured') {
                    const jsonStr = document.getElementById('structured-json').value;
                    if (jsonStr.trim() === '') {
                        formatData = {};
                    } else {
                        formatData = JSON.parse(jsonStr);
                    }
                }
            } catch (e) {
                showStatus('JSONのパースに失敗しました。構造化データの書式を確認してください。', true);
                return;
            }

            const payload = {
                format: currentFormat,
                data: formatData
            };

            const formData = new FormData();
            const editPostId = document.getElementById('edit-post-id').value;
            formData.append('action', editPostId ? 'frontend_learning_data_update' : 'frontend_learning_data_upload');
            formData.append('nonce', uploadNonce);
            formData.append('title', title);
            formData.append('json_data', JSON.stringify(payload));
            if (editPostId) formData.append('post_id', editPostId);

            // メタデータの送信
            var metaLang = document.getElementById('meta-language');
            var metaCat = document.getElementById('meta-category');
            var metaDiff = document.getElementById('meta-difficulty');
            var metaQuality = document.getElementById('meta-quality');
            var metaSource = document.getElementById('meta-source');
            var metaTags = document.getElementById('meta-tags');
            var metaSpeakers = document.getElementById('meta-speakers');
            if (metaLang && metaLang.value) formData.append('language', metaLang.value);
            if (metaCat && metaCat.value) formData.append('category', metaCat.value);
            if (metaDiff && metaDiff.value) formData.append('difficulty', metaDiff.value);
            if (metaQuality && metaQuality.value) formData.append('quality', metaQuality.value);
            if (metaSource && metaSource.value) formData.append('source', metaSource.value);
            if (metaTags && metaTags.value) formData.append('tags', metaTags.value);
            if (metaSpeakers && metaSpeakers.value) formData.append('speakers', metaSpeakers.value);

            // 送信
            fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(response => {
                if (response.success) {
                    showStatus(editPostId ? 'データが正常に更新されました。(ID: ' + response.data.post_id + ')' : 'データが正常に登録されました。(ID: ' + response.data.post_id + ')');
                    document.getElementById('edit-post-id').value = '';
                    // フォームクリア
                    document.getElementById('data-title').value = '';
                    if (currentFormat === 'plain') document.getElementById('plain-text').value = '';
                    if (currentFormat === 'instruction') {
                        document.getElementById('inst-instruction').value = '';
                        document.getElementById('inst-input').value = '';
                        document.getElementById('inst-output').value = '';
                    }
                    if (currentFormat === 'cot') {
                        document.getElementById('cot-question').value = '';
                        document.getElementById('cot-thought').value = '';
                        document.getElementById('cot-answer').value = '';
                    }
                    if (currentFormat === 'frontend_code') {
                        document.getElementById('fe-explanation').value = '';
                        document.getElementById('fe-html').value = '';
                        document.getElementById('fe-css').value = '';
                        document.getElementById('fe-js').value = '';
                    }
                    if (currentFormat === 'structured') document.getElementById('structured-json').value = '';
                    // chatml/sharegpt は一旦リセット
                    if (currentFormat === 'chatml') {
                        chatmlContainer.innerHTML = '';
                        addChatmlRow('system', 'You are a helpful assistant.');
                        addChatmlRow('user', '');
                    }
                    if (currentFormat === 'sharegpt') {
                        sharegptContainer.innerHTML = '';
                        addSharegptRow('human', '');
                        addSharegptRow('gpt', '');
                    }
                } else {
                    showStatus(response.data.message || '登録に失敗しました。', true);
                }
            })
            .catch(error => {
                showStatus('通信エラーが発生しました。', true);
            });
        });

        // 検索処理
        document.getElementById('btn-search').addEventListener('click', function() {
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
                    document.getElementById('edit-meta-speakers').value = d.meta.speakers || '';
                    var q = parseInt(d.meta.quality) || 0;
                    document.getElementById('edit-meta-quality').value = q;
                    document.getElementById('edit-quality-stars').querySelectorAll('.star').forEach((s, i) => {
                        s.classList.toggle('active', i < q);
                    });
                }

                // フィールド動的生成
                function extractDataVal(obj, key) {
                    if (obj && typeof obj[key] !== 'undefined') {
                        return typeof obj[key] === 'string' ? obj[key] : JSON.stringify(obj[key]);
                    }
                    if (Array.isArray(obj) && obj.length > 0 && typeof obj[0][key] !== 'undefined') {
                        return obj.map(item => {
                            return typeof item[key] === 'string' ? item[key] : JSON.stringify(item[key]);
                        }).join('\n\n---\n\n');
                    }
                    return '';
                }
                var container = document.getElementById('edit-fields-container');
                container.innerHTML = '';
                var data = d.data || {};
                var format = d.format;

                if (format === 'plain') {
                    container.innerHTML = '<div class="upload-form-group"><label>テキスト本文:</label><textarea id="edit-field-text" class="upload-form-input" rows="8">' + escHtml(extractDataVal(data, 'text')) + '</textarea></div>';
                } else if (format === 'instruction') {
                    container.innerHTML = '<div class="upload-form-group"><label>Instruction:</label><textarea id="edit-field-instruction" class="upload-form-input" rows="3">' + escHtml(extractDataVal(data, 'instruction')) + '</textarea></div>'
                        + '<div class="upload-form-group"><label>Input:</label><textarea id="edit-field-input" class="upload-form-input" rows="3">' + escHtml(extractDataVal(data, 'input')) + '</textarea></div>'
                        + '<div class="upload-form-group"><label>Output:</label><textarea id="edit-field-output" class="upload-form-input" rows="5">' + escHtml(extractDataVal(data, 'output')) + '</textarea></div>';
                } else if (format === 'cot') {
                    container.innerHTML = '<div class="upload-form-group"><label>Question:</label><textarea id="edit-field-question" class="upload-form-input" rows="3">' + escHtml(extractDataVal(data, 'question')) + '</textarea></div>'
                        + '<div class="upload-form-group"><label>Thought:</label><textarea id="edit-field-thought" class="upload-form-input" rows="6">' + escHtml(extractDataVal(data, 'thought')) + '</textarea></div>'
                        + '<div class="upload-form-group"><label>Answer:</label><textarea id="edit-field-answer" class="upload-form-input" rows="3">' + escHtml(extractDataVal(data, 'answer')) + '</textarea></div>';
                } else if (format === 'frontend_code') {
                    container.innerHTML = '<div class="upload-form-group"><label>説明:</label><textarea id="edit-field-explanation" class="upload-form-input" rows="2">' + escHtml(extractDataVal(data, 'explanation')) + '</textarea></div>'
                        + '<div class="upload-form-group"><label>HTML:</label><textarea id="edit-field-html" class="upload-form-input" rows="4" style="font-family:monospace;">' + escHtml(extractDataVal(data, 'html')) + '</textarea></div>'
                        + '<div class="upload-form-group"><label>CSS:</label><textarea id="edit-field-css" class="upload-form-input" rows="4" style="font-family:monospace;">' + escHtml(extractDataVal(data, 'css')) + '</textarea></div>'
                        + '<div class="upload-form-group"><label>JavaScript:</label><textarea id="edit-field-js" class="upload-form-input" rows="4" style="font-family:monospace;">' + escHtml(extractDataVal(data, 'js')) + '</textarea></div>';
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
            fd.append('speakers', document.getElementById('edit-meta-speakers').value);

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

<?php
get_footer();
?>
