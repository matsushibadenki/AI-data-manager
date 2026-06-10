<?php
/*
 * Name: page-media-upload.php
 * Description: ドラッグ＆ドロップ式メディアアップロード用カスタムページテンプレート。
 *              クッキー/セッションによる個別ログイン画面、FileBirdフォルダ選択機能付き。
 *              Template Name: Media Drag & Drop Upload
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
.learning-tabs { display: flex; gap: 0.5rem; margin-bottom: 1rem; border-bottom: 2px solid var(--border-subtle, #eee); padding-bottom: 0.5rem; overflow-x: auto; }
.learning-tab { padding: 0.75rem 1.25rem; background: transparent; border: none; cursor: pointer; font-weight: 500; color: var(--text-secondary, #666); border-radius: var(--radius-md, 4px); transition: all 0.2s ease; white-space: nowrap; }
.learning-tab:hover { background: var(--bg-surface-hover, #f5f5f5); }
.learning-tab.active { background: var(--bg-surface, #fff); color: var(--text-primary, #000); box-shadow: 0 2px 4px rgba(0,0,0,0.05); border: 1px solid var(--border-subtle, #eee); }
.learning-tab-content { display: none; background: var(--bg-surface, #fff); padding: 1.5rem; border: 1px solid var(--border-subtle, #eee); border-radius: var(--radius-lg, 8px); text-align: left; }
.learning-tab-content.active { display: block; }
.dynamic-row { display: flex; gap: 1rem; margin-bottom: 1rem; align-items: flex-start; }
.dynamic-row select { width: 150px; }
.dynamic-row textarea { flex-grow: 1; }
.btn-remove-row { background: transparent; color: var(--error, #ef4444); border: 1px solid var(--error, #ef4444); padding: 0.5rem; border-radius: 4px; cursor: pointer; }
.btn-add-row { background: var(--bg-surface-hover, #f5f5f5); color: var(--text-primary, #000); border: 1px dashed var(--border-subtle, #ccc); padding: 0.75rem 1.5rem; border-radius: 4px; cursor: pointer; width: 100%; margin-top: 1rem; }
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
                    <h2 class="upload-login-title"><?php echo esc_html__('メディアアップローダー', 'fourier'); ?></h2>
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
                        <button type="submit" name="upload_login_submit" class="btn-black upload-login-btn">
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
            <!-- アップロード画面 -->
            <div class="logout-wrapper" style="text-align: right; margin-bottom: 1rem;">
                <a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>" class="btn-logout" style="font-size: 0.85rem; color: var(--text-secondary); text-decoration: underline;">
                    <?php echo esc_html__('ログアウト', 'fourier'); ?>
                </a>
            </div>

            <h2 class="upload-title"><?php echo esc_html__('メディアの登録', 'fourier'); ?></h2>
            <p class="upload-desc"><?php echo esc_html__('ファイルをドラッグ＆ドロップするか、「ファイルを選択」ボタンをクリックしてアップロードしてください。', 'fourier'); ?></p>

            <div class="upload-controls" style="flex-direction: column; align-items: center; gap: 1.5rem; margin-bottom: 2rem;">
                <div class="folder-select-wrapper">
                    <label for="upload-folder-select"><?php echo esc_html__('追加先フォルダー:', 'fourier'); ?></label>
                    <select id="upload-folder-select" class="grid-select">
                        <option value="0"><?php echo esc_html__('未分類 (フォルダーなし)', 'fourier'); ?></option>
                        <?php
                        if (function_exists('get_all_filebird_folders')) {
                            $folders = get_all_filebird_folders();
                            foreach ($folders as $folder) {
                                echo '<option value="' . esc_attr($folder->id) . '">' . esc_html($folder->name) . '</option>';
                            }
                        }
                        ?>
                    </select>
                </div>

                <!-- メタデータオプション入力 -->
                <div class="meta-inputs-container" style="width: 100%; max-width: 100%; background: var(--bg-surface); padding: 1.5rem; border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); box-sizing: border-box;">
                    <h3 style="font-size: 0.95rem; font-weight: 600; margin-top: 0; margin-bottom: 1rem; border-bottom: 1px solid var(--border-subtle); padding-bottom: 0.5rem; color: var(--text-primary); text-align: left;"><?php echo esc_html__('メディア情報設定 (オプション)', 'fourier'); ?></h3>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem;">
                        <div class="upload-form-group" style="margin-bottom: 0; display: flex; flex-direction: column; gap: 0.4rem; text-align: left;">
                            <label for="upload-meta-name" style="font-size: 0.8rem; font-weight: 500; color: var(--text-secondary);"><?php echo esc_html__('撮影者 (name):', 'fourier'); ?></label>
                            <input type="text" id="upload-meta-name" class="upload-form-input" placeholder="<?php echo esc_attr__('撮影者名', 'fourier'); ?>" style="height:2.5rem; padding:0.5rem 1rem;" />
                        </div>
                        <div class="upload-form-group" style="margin-bottom: 0; display: flex; flex-direction: column; gap: 0.4rem; text-align: left;">
                            <label for="upload-meta-space" style="font-size: 0.8rem; font-weight: 500; color: var(--text-secondary);"><?php echo esc_html__('場所 (space):', 'fourier'); ?></label>
                            <input type="text" id="upload-meta-space" class="upload-form-input" placeholder="<?php echo esc_attr__('撮影場所', 'fourier'); ?>" style="height:2.5rem; padding:0.5rem 1rem;" />
                        </div>
                        <div class="upload-form-group" style="margin-bottom: 0; display: flex; flex-direction: column; gap: 0.4rem; text-align: left;">
                            <label for="upload-meta-type" style="font-size: 0.8rem; font-weight: 500; color: var(--text-secondary);"><?php echo esc_html__('データタイプ (type):', 'fourier'); ?></label>
                            <input type="text" id="upload-meta-type" class="upload-form-input" placeholder="<?php echo esc_attr__('データタイプ', 'fourier'); ?>" style="height:2.5rem; padding:0.5rem 1rem;" />
                        </div>
                        <div class="upload-form-group" style="margin-bottom: 0; display: flex; flex-direction: column; gap: 0.4rem; text-align: left;">
                            <label for="upload-meta-detail" style="font-size: 0.8rem; font-weight: 500; color: var(--text-secondary);"><?php echo esc_html__('タグ (カンマ区切り):', 'fourier'); ?></label>
                            <input type="text" id="upload-meta-detail" class="upload-form-input" placeholder="<?php echo esc_attr__('タグ1, タグ2', 'fourier'); ?>" style="height:2.5rem; padding:0.5rem 1rem;" />
                        </div>
                    </div>
                </div>
                <!-- 学習データ入力 (オプション) -->
                <div class="learning-data-container" style="width: 100%; max-width: 100%; background: var(--bg-surface); padding: 1.5rem; border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); box-sizing: border-box; margin-top: 1.5rem;">
                    <h3 style="font-size: 0.95rem; font-weight: 600; margin-top: 0; margin-bottom: 1rem; border-bottom: 1px solid var(--border-subtle); padding-bottom: 0.5rem; color: var(--text-primary); text-align: left;"><?php echo esc_html__('学習データ付与 (オプション)', 'fourier'); ?></h3>
                    
                    <div class="learning-tabs">
                        <button type="button" class="learning-tab active" data-target="tab-none">なし</button>
                        <button type="button" class="learning-tab" data-target="tab-caption">Caption</button>
                        <button type="button" class="learning-tab" data-target="tab-vqa">VQA</button>
                        <button type="button" class="learning-tab" data-target="tab-instruction">Instruction</button>
                        <button type="button" class="learning-tab" data-target="tab-chatml">ChatML</button>
                        <button type="button" class="learning-tab" data-target="tab-bbox">Bounding Box</button>
                        <button type="button" class="learning-tab" data-target="tab-dpo">DPO / RLHF</button>
                    </div>

                    <div id="tab-none" class="learning-tab-content active" data-format="none">
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0;">このファイルには学習データを付与しません。</p>
                    </div>

                    <div id="tab-caption" class="learning-tab-content" data-format="caption">
                        <div class="upload-form-group" style="margin-bottom:0; text-align: left;">
                            <label for="caption-text" style="font-size: 0.8rem; font-weight: 500; color: var(--text-secondary);"><?php echo esc_html__('Caption (キャプション):', 'fourier'); ?></label>
                            <textarea id="caption-text" class="upload-form-input" rows="4" placeholder="画像の詳細な説明を入力（例：A highly detailed photo of...）"></textarea>
                        </div>
                    </div>

                    <div id="tab-vqa" class="learning-tab-content" data-format="vqa">
                        <div class="upload-form-group" style="text-align: left;">
                            <label for="vqa-question" style="font-size: 0.8rem; font-weight: 500; color: var(--text-secondary);"><?php echo esc_html__('Question (質問):', 'fourier'); ?></label>
                            <textarea id="vqa-question" class="upload-form-input" rows="2" placeholder="画像に関する質問を入力"></textarea>
                        </div>
                        <div class="upload-form-group" style="margin-bottom:0; text-align: left;">
                            <label for="vqa-answer" style="font-size: 0.8rem; font-weight: 500; color: var(--text-secondary);"><?php echo esc_html__('Answer (回答):', 'fourier'); ?></label>
                            <textarea id="vqa-answer" class="upload-form-input" rows="2" placeholder="質問に対する回答を入力"></textarea>
                        </div>
                    </div>

                    <div id="tab-instruction" class="learning-tab-content" data-format="instruction">
                        <div class="upload-form-group" style="text-align: left;">
                            <label for="inst-instruction" style="font-size: 0.8rem; font-weight: 500; color: var(--text-secondary);"><?php echo esc_html__('Instruction (指示):', 'fourier'); ?></label>
                            <textarea id="inst-instruction" class="upload-form-input" rows="2" placeholder="モデルへの指示（例：この画像に写っているものを詳細に説明してください）"></textarea>
                        </div>
                        <div class="upload-form-group" style="margin-bottom:0; text-align: left;">
                            <label for="inst-output" style="font-size: 0.8rem; font-weight: 500; color: var(--text-secondary);"><?php echo esc_html__('Output (出力):', 'fourier'); ?></label>
                            <textarea id="inst-output" class="upload-form-input" rows="4" placeholder="期待される出力"></textarea>
                        </div>
                    </div>

                    <div id="tab-chatml" class="learning-tab-content" data-format="chatml">
                        <div id="chatml-container"></div>
                        <button type="button" id="btn-add-chatml" class="btn-add-row">+ メッセージを追加</button>
                    </div>

                    <div id="tab-bbox" class="learning-tab-content" data-format="bbox">
                        <div class="upload-form-group" style="margin-bottom:0; text-align: left;">
                            <label for="bbox-json" style="font-size: 0.8rem; font-weight: 500; color: var(--text-secondary);"><?php echo esc_html__('Bounding Box (JSON):', 'fourier'); ?></label>
                            <textarea id="bbox-json" class="upload-form-input" rows="5" placeholder='[ {"label": "dog", "bbox": [10, 20, 150, 200]} ]' style="font-family: monospace;"></textarea>
                        </div>
                    </div>

                    <div id="tab-dpo" class="learning-tab-content" data-format="dpo">
                        <div class="upload-form-group" style="text-align: left;">
                            <label for="dpo-prompt" style="font-size: 0.8rem; font-weight: 500; color: var(--text-secondary);"><?php echo esc_html__('Prompt (プロンプト):', 'fourier'); ?></label>
                            <textarea id="dpo-prompt" class="upload-form-input" rows="2" placeholder="画像に関するプロンプト"></textarea>
                        </div>
                        <div class="upload-form-group" style="text-align: left;">
                            <label for="dpo-chosen" style="font-size: 0.8rem; font-weight: 500; color: var(--text-secondary);"><?php echo esc_html__('Chosen (良い回答):', 'fourier'); ?></label>
                            <textarea id="dpo-chosen" class="upload-form-input" rows="3" placeholder="期待される良い回答"></textarea>
                        </div>
                        <div class="upload-form-group" style="margin-bottom:0; text-align: left;">
                            <label for="dpo-rejected" style="font-size: 0.8rem; font-weight: 500; color: var(--text-secondary);"><?php echo esc_html__('Rejected (悪い回答):', 'fourier'); ?></label>
                            <textarea id="dpo-rejected" class="upload-form-input" rows="3" placeholder="避けさせたい悪い回答"></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ドラッグ＆ドロップ領域 -->
            <div id="drop-zone" class="drop-zone">
                <div class="drop-zone-content">
                    <span class="material-symbols-outlined upload-icon">cloud_upload</span>
                    <p class="drop-zone-text"><?php echo esc_html__('ここにファイルをドラッグ＆ドロップ', 'fourier'); ?></p>
                    <span class="drop-zone-or"><?php echo esc_html__('または', 'fourier'); ?></span>
                    <button type="button" id="browse-btn" class="btn-black"><?php echo esc_html__('ファイルを選択', 'fourier'); ?></button>
                    <input type="file" id="file-input" multiple accept="image/*,video/*,application/pdf,audio/*" style="display: none;" />
                </div>
            </div>

            <!-- アップロード状況・プレビュー領域 -->
            <div id="upload-preview-container" class="upload-preview-container" style="display: none;">
                <h3><?php echo esc_html__('選択されたファイル', 'fourier'); ?></h3>
                <div id="preview-list" class="preview-list"></div>
                <button type="button" id="register-btn" class="btn-black" style="margin-top: 1rem; width: 100%; justify-content: center; font-size: 1.1rem; padding: 1rem; border-radius: var(--radius-md);"><?php echo esc_html__('データと画像を登録', 'fourier'); ?></button>
            </div>

            <div class="back-home">
                <a href="<?php echo esc_url(home_url('/')); ?>" class="btn-black">
                    <span class="material-symbols-outlined">arrow_back</span>
                    <?php echo esc_html__('フロントページに戻る', 'fourier'); ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var dropZone = document.getElementById('drop-zone');
        var fileInput = document.getElementById('file-input');
        var browseBtn = document.getElementById('browse-btn');
        var previewContainer = document.getElementById('upload-preview-container');
        var previewList = document.getElementById('preview-list');
        var folderSelect = document.getElementById('upload-folder-select');
        var metaNameInput = document.getElementById('upload-meta-name');
        var metaSpaceInput = document.getElementById('upload-meta-space');
        var metaTypeInput = document.getElementById('upload-meta-type');
        var metaDetailInput = document.getElementById('upload-meta-detail');

        if (!dropZone || !fileInput) return; // ログインしていない場合は処理をスキップ

        // 学習データタブ切り替え
        let currentLearningFormat = 'none';
        const learningTabs = document.querySelectorAll('.learning-tab');
        const learningContents = document.querySelectorAll('.learning-tab-content');

        if (learningTabs.length > 0) {
            learningTabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    learningTabs.forEach(t => t.classList.remove('active'));
                    learningContents.forEach(c => c.classList.remove('active'));
                    
                    tab.classList.add('active');
                    const targetId = tab.getAttribute('data-target');
                    const targetContent = document.getElementById(targetId);
                    if (targetContent) {
                        targetContent.classList.add('active');
                        currentLearningFormat = targetContent.getAttribute('data-format');
                    }
                });
            });

            // 動的行追加
            function addDynamicRow(containerId, roleClass, contentClass, roles, defaultRole) {
                const container = document.getElementById(containerId);
                if (!container) return;
                const row = document.createElement('div');
                row.className = 'dynamic-row';
                let options = '';
                roles.forEach(r => {
                    options += `<option value="${r}" ${r === defaultRole ? 'selected' : ''}>${r}</option>`;
                });
                row.innerHTML = `
                    <select class="upload-form-input ${roleClass}">${options}</select>
                    <textarea class="upload-form-input ${contentClass}" rows="2"></textarea>
                    <button type="button" class="btn-remove-row" title="削除"><span class="material-symbols-outlined">delete</span></button>
                `;
                row.querySelector('.btn-remove-row').addEventListener('click', () => row.remove());
                container.appendChild(row);
            }

            const btnAddChatml = document.getElementById('btn-add-chatml');
            if (btnAddChatml) {
                btnAddChatml.addEventListener('click', () => addDynamicRow('chatml-container', 'chatml-role', 'chatml-content', ['system','user','assistant'], 'user'));
                addDynamicRow('chatml-container', 'chatml-role', 'chatml-content', ['system','user','assistant'], 'system');
                addDynamicRow('chatml-container', 'chatml-role', 'chatml-content', ['system','user','assistant'], 'user');
            }
        }
        // WordPress REST/AJAX ノンスとURLの設定
        var uploadNonce = "<?php echo wp_create_nonce('frontend_upload_action'); ?>";
        var ajaxUrl = "<?php echo esc_url(admin_url('admin-ajax.php')); ?>";
        var fallbackImgUrl = typeof themeDirectoryUrl !== 'undefined' ? themeDirectoryUrl + '/assets/img/logo_tokushiikusya_main.svg' : '';

        // ファイル選択ダイアログの表示
        browseBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            fileInput.click();
        });

        dropZone.addEventListener('click', function() {
            fileInput.click();
        });

        // ドラッグ＆ドロップイベント
        ['dragenter', 'dragover'].forEach(function(eventName) {
            dropZone.addEventListener(eventName, function(e) {
                e.preventDefault();
                e.stopPropagation();
                dropZone.classList.add('dragover');
            }, false);
        });

        ['dragleave', 'drop'].forEach(function(eventName) {
            dropZone.addEventListener(eventName, function(e) {
                e.preventDefault();
                e.stopPropagation();
                dropZone.classList.remove('dragover');
            }, false);
        });

        dropZone.addEventListener('drop', function(e) {
            var dt = e.dataTransfer;
            var files = dt.files;
            handleFiles(files);
        }, false);

        fileInput.addEventListener('change', function() {
            handleFiles(fileInput.files);
        });

        var pendingFilesList = [];
        var registerBtn = document.getElementById('register-btn');

        function handleFiles(files) {
            if (files.length === 0) return;

            previewContainer.style.display = 'block';

            Array.from(files).forEach(function(file) {
                var fileObj = {
                    file: file,
                    dom: createPreviewItem(file)
                };
                pendingFilesList.push(fileObj);
                
                // 削除ボタンの処理
                fileObj.dom.removeBtn.addEventListener('click', function() {
                    fileObj.dom.previewItem.remove();
                    pendingFilesList = pendingFilesList.filter(f => f !== fileObj);
                    if (pendingFilesList.length === 0) {
                        previewContainer.style.display = 'none';
                        registerBtn.disabled = false;
                        registerBtn.textContent = 'データと画像を登録';
                    }
                });
            });
        }

        if (registerBtn) {
            registerBtn.addEventListener('click', function() {
                if (pendingFilesList.length === 0) return;
                registerBtn.disabled = true;
                registerBtn.textContent = '登録中...';
                
                var filesToUpload = pendingFilesList;
                pendingFilesList = []; // すぐにクリア
                
                let completedCount = 0;
                let totalFiles = filesToUpload.length;

                filesToUpload.forEach(function(fileObj) {
                    uploadFile(fileObj, function() {
                        completedCount++;
                        if (completedCount === totalFiles) {
                            registerBtn.textContent = '完了しました';
                            setTimeout(() => {
                                registerBtn.disabled = false;
                                registerBtn.textContent = 'データと画像を登録';
                                if (pendingFilesList.length === 0) {
                                    previewContainer.style.display = 'none';
                                }
                            }, 3000);
                        }
                    });
                });
            });
        }

        function createPreviewItem(file) {
            // プレビュー用要素の作成
            var previewItem = document.createElement('div');
            previewItem.className = 'preview-item';

            var previewInfo = document.createElement('div');
            previewInfo.className = 'preview-info';

            // サムネイル画像プレビュー（画像ファイルの場合）
            var img = document.createElement('img');
            img.className = 'preview-thumb';
            img.src = fallbackImgUrl; // fallback

            if (file.type.startsWith('image/')) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    img.src = e.target.result;
                };
                reader.readAsDataURL(file);
            } else if (file.type.startsWith('video/')) {
                img.src = 'data:image/svg+xml;charset=UTF-8,%3csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23C9A96E"%3e%3cpath d="M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z"/%3e%3c/svg%3e';
            } else if (file.type === 'application/pdf') {
                img.src = 'data:image/svg+xml;charset=UTF-8,%3csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23F87171"%3e%3cpath d="M20 2H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-8.5 7.5c0 .83-.67 1.5-1.5 1.5H9v2H7.5V7H10c.83 0 1.5.67 1.5 1.5v1zm5 2c0 .83-.67 1.5-1.5 1.5h-2.5V7H15c.83 0 1.5.67 1.5 1.5v3zm4-3H19v1h1.5V11H19v2h-1.5V7h3v1.5z"/%3e%3c/svg%3e';
            }

            var nameSpan = document.createElement('span');
            nameSpan.className = 'preview-name';
            nameSpan.textContent = file.name;

            previewInfo.appendChild(img);
            previewInfo.appendChild(nameSpan);

            var statusDiv = document.createElement('div');
            statusDiv.className = 'preview-status pending';
            
            var statusText = document.createElement('span');
            statusText.textContent = '待機中';
            statusDiv.appendChild(statusText);
            
            var removeBtn = document.createElement('span');
            removeBtn.className = 'material-symbols-outlined';
            removeBtn.style.cursor = 'pointer';
            removeBtn.style.marginLeft = '10px';
            removeBtn.style.color = 'var(--error)';
            removeBtn.textContent = 'close';
            statusDiv.appendChild(removeBtn);

            previewItem.appendChild(previewInfo);
            previewItem.appendChild(statusDiv);
            previewList.insertBefore(previewItem, previewList.firstChild);

            return {
                previewItem: previewItem,
                statusDiv: statusDiv,
                statusText: statusText,
                removeBtn: removeBtn,
                img: img
            };
        }

        function uploadFile(fileObj, onComplete) {
            var file = fileObj.file;
            var dom = fileObj.dom;
            
            dom.removeBtn.remove();
            
            dom.statusDiv.className = 'preview-status uploading';
            dom.statusText.textContent = '0%';
            
            var progressContainer = document.createElement('div');
            progressContainer.className = 'preview-progress-bar';
            var progressFill = document.createElement('div');
            progressFill.className = 'preview-progress-fill';
            progressContainer.appendChild(progressFill);
            dom.statusDiv.insertBefore(progressContainer, dom.statusText);

            // AJAXによる送信処理（進捗追跡可能）
            var xhr = new XMLHttpRequest();
            var formData = new FormData();

            formData.append('action', 'frontend_media_upload');
            formData.append('nonce', uploadNonce);
            formData.append('folder_id', folderSelect.value);
            formData.append('meta_name', metaNameInput ? metaNameInput.value : '');
            formData.append('meta_space', metaSpaceInput ? metaSpaceInput.value : '');
            formData.append('meta_type', metaTypeInput ? metaTypeInput.value : '');
            formData.append('meta_detail', metaDetailInput ? metaDetailInput.value : '');
            
            // 学習データの収集
            if (currentLearningFormat !== 'none') {
                let learningData = {};
                try {
                    if (currentLearningFormat === 'caption') {
                        learningData = { text: document.getElementById('caption-text').value };
                    } else if (currentLearningFormat === 'vqa') {
                        learningData = {
                            question: document.getElementById('vqa-question').value,
                            answer: document.getElementById('vqa-answer').value
                        };
                    } else if (currentLearningFormat === 'instruction') {
                        learningData = {
                            instruction: document.getElementById('inst-instruction').value,
                            output: document.getElementById('inst-output').value
                        };
                    } else if (currentLearningFormat === 'chatml') {
                        const messages = [];
                        document.querySelectorAll('#chatml-container .dynamic-row').forEach(row => {
                            const role = row.querySelector('.chatml-role').value;
                            const content = row.querySelector('.chatml-content').value;
                            if (content.trim()) messages.push({ role, content });
                        });
                        learningData = { messages };
                    } else if (currentLearningFormat === 'bbox') {
                        const jsonStr = document.getElementById('bbox-json').value;
                        if (jsonStr.trim()) {
                            learningData = JSON.parse(jsonStr);
                        }
                    } else if (currentLearningFormat === 'dpo') {
                        learningData = {
                            prompt: document.getElementById('dpo-prompt').value,
                            chosen: document.getElementById('dpo-chosen').value,
                            rejected: document.getElementById('dpo-rejected').value
                        };
                    }
                } catch (e) {
                    console.error("Learning data parse error:", e);
                }
                
                formData.append('learning_format', currentLearningFormat);
                formData.append('learning_data', JSON.stringify(learningData));
            }

            formData.append('file', file);

            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    var percent = Math.round((e.loaded / e.total) * 100);
                    progressFill.style.width = percent + '%';
                    dom.statusText.textContent = percent + '%';
                }
            });

            xhr.open('POST', ajaxUrl, true);

            xhr.addEventListener('load', function() {
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            statusDiv.className = 'preview-status success';
                            statusDiv.innerHTML = '<span class="material-symbols-outlined" style="color:var(--success);">check_circle</span> 完了';
                            if (response.data.thumbnail) {
                                img.src = response.data.thumbnail;
                            }
                        } else {
                            showError(response.data.message || 'アップロード失敗');
                        }
                    } catch (e) {
                        showError('エラーが発生しました。');
                    }
                } else {
                    showError('通信エラー (' + xhr.status + ')');
                }
            });

            xhr.addEventListener('error', function() {
                showError('通信エラーが発生しました。');
            });

            function showError(message) {
                statusDiv.className = 'preview-status error';
                statusDiv.innerHTML = '<span class="material-symbols-outlined" style="color:var(--error);vertical-align:middle;font-size:1.1rem!important;">error</span> <span style="font-size:0.75rem;">' + message + '</span>';
            }

            xhr.send(formData);
        }
    });
</script>

<?php
get_footer();
?>