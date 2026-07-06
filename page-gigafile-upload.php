<?php
/*
 * Name: page-gigafile-upload.php
 * Description: 一時保管・共有用（ギガファイル風）のドラッグ＆ドロップ式アップローダーカスタムテンプレート。
 *              ログイン、保持期限選択、ダウンロードパスワード制限、複数ファイルのZIPまとめ、URLコピー機能付き。
 *              Template Name: GigaFile-like Temporary Upload
 */

// ダウンロードゲートウェイの処理
if (isset($_GET['dl'])) {
    $file_id = sanitize_file_name($_GET['dl']);
    $upload_dir = wp_upload_dir();
    $giga_dir = $upload_dir['basedir'] . '/gigafile_uploads';
    $filepath = $giga_dir . '/' . $file_id;
    $json_path = $filepath . '.json';

    if (!file_exists($filepath) || !file_exists($json_path)) {
        wp_die(esc_html__('ファイルが存在しないか、すでに保持期限が切れて削除されています。', 'fourier'), esc_html__('ファイルが見つかりません', 'fourier'), array('response' => 404));
    }

    $meta_data = json_decode(file_get_contents($json_path), true);
    $original_name = isset($meta_data['original_name']) ? $meta_data['original_name'] : $file_id;
    $hash = isset($meta_data['password_hash']) ? $meta_data['password_hash'] : '';

    $password_required = false;
    $password_error = false;

    if (!empty($hash)) {
        $cookie_name = 'giga_unlocked_' . md5($file_id);
        $expected_cookie_val = md5($hash . '_giga_salt_2026');
        if (isset($_COOKIE[$cookie_name]) && $_COOKIE[$cookie_name] === $expected_cookie_val) {
            $password_required = false;
        } else {
            $password_required = true;
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download_password'])) {
                $input_password = sanitize_text_field($_POST['download_password']);
                if (wp_check_password($input_password, $hash)) {
                    setcookie($cookie_name, $expected_cookie_val, time() + 3600, '/');
                    $password_required = false;
                    wp_safe_redirect(add_query_arg('dl', $file_id, strtok($_SERVER['REQUEST_URI'], '?')));
                    exit;
                } else {
                    $password_error = true;
                }
            }
        }
    }

    // ダウンロードの実行
    if (isset($_GET['action']) && $_GET['action'] === 'download' && !$password_required) {
        if (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Description: File Transfer');
        header('Content-Type: ' . (!empty($meta_data['mime_type']) ? $meta_data['mime_type'] : 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . rawurlencode($original_name) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }

    get_header();
    ?>
    <main>
        <div id="primary" class="upload-page-container">
            <div class="download-box" style="max-width: 500px; margin: 4rem auto; background-color: var(--bg-surface); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 3rem 2rem; text-align: center; box-shadow: var(--shadow-lg);">
                <?php if ($password_required) : ?>
                    <span class="material-symbols-outlined" style="font-size: 3rem !important; color: var(--accent); margin-bottom: 1rem;">lock</span>
                    <h2 class="upload-title" style="font-size: 1.3rem; margin-bottom: 1rem;"><?php echo esc_html__('パスワード保護', 'fourier'); ?></h2>
                    <p style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 2rem; line-height: 1.6;">
                        <?php echo esc_html__('このファイルはダウンロードパスワードで保護されています。', 'fourier'); ?>
                    </p>
                    <?php if ($password_error) : ?>
                        <p style="color: var(--error); font-size: 0.85rem; margin-bottom: 1.25rem; font-weight: 500;">
                            <?php echo esc_html__('パスワードが正しくありません。', 'fourier'); ?>
                        </p>
                    <?php endif; ?>
                    <form method="post" action="" class="upload-login-form">
                        <div class="upload-form-group">
                            <label for="download_password" style="font-size: 0.8rem; font-weight: 500; color: var(--text-secondary);"><?php echo esc_html__('パスワード', 'fourier'); ?></label>
                            <input type="password" name="download_password" id="download_password" class="upload-form-input" required autofocus autocomplete="new-password" />
                        </div>
                        <button type="submit" class="btn-base btn-primary" style="width: 100%; padding: 0.85rem; font-size: 0.9rem; font-weight: 500; justify-content: center; margin-top: 0.5rem;">
                            <?php echo esc_html__('認証して進む', 'fourier'); ?>
                        </button>
                    </form>
                <?php else : ?>
                    <span class="material-symbols-outlined" style="font-size: 4rem !important; color: var(--accent); margin-bottom: 1rem;">download_for_offline</span>
                    <h2 class="upload-title" style="font-size: 1.4rem; margin-bottom: 0.5rem; word-break: break-all;"><?php echo esc_html($original_name); ?></h2>
                    <p style="font-size: 0.85rem; color: var(--text-tertiary); margin-bottom: 2rem;">
                        <?php echo esc_html__('ファイルサイズ:', 'fourier'); ?> <?php echo size_format(filesize($filepath)); ?>
                    </p>
                    <a href="<?php echo esc_url(add_query_arg('action', 'download')); ?>" class="btn-base btn-primary" style="display: inline-flex; width: 100%; padding: 1rem; font-size: 1rem; font-weight: 600; justify-content: center; background-color: var(--accent); color: var(--text-inverse); border-color: var(--accent); box-shadow: var(--shadow-gold);">
                        <?php echo esc_html__('ファイルをダウンロードする', 'fourier'); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <?php
    get_footer();
    exit;
}

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
                    <h2 class="upload-login-title"><?php echo esc_html__('一時共有アップローダー', 'fourier'); ?></h2>
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
            <!-- アップロード画面 -->


            <h2 class="upload-title"><?php echo esc_html__('ファイルのアップロード', 'fourier'); ?></h2>
            <p class="upload-desc"><?php echo esc_html__('保持期限とパスワードを設定して、ファイルをアップロードしてください。', 'fourier'); ?></p>

            <!-- 保持期限設定（GigaFile風） -->
            <div class="expiration-control-wrapper">
                <span class="expiration-label"><?php echo esc_html__('保持期限変更', 'fourier'); ?></span>
                <div class="expiration-buttons" id="expiration-buttons">
                    <button type="button" class="btn-exp" data-days="3">3<?php echo esc_html__('日', 'fourier'); ?></button>
                    <button type="button" class="btn-exp" data-days="5">5<?php echo esc_html__('日', 'fourier'); ?></button>
                    <button type="button" class="btn-exp" data-days="7">7<?php echo esc_html__('日', 'fourier'); ?></button>
                    <button type="button" class="btn-exp" data-days="14">14<?php echo esc_html__('日', 'fourier'); ?></button>
                    <button type="button" class="btn-exp active" data-days="30">30<?php echo esc_html__('日', 'fourier'); ?></button>
                    <button type="button" class="btn-exp" data-days="60">60<?php echo esc_html__('日', 'fourier'); ?></button>
                    <button type="button" class="btn-exp" data-days="100">100<?php echo esc_html__('日', 'fourier'); ?></button>
                </div>
                <input type="hidden" id="selected-expiration-days" value="30" />
            </div>

            <div class="upload-controls">
                <div class="password-input-wrapper" style="flex: 1;">
                    <label for="upload-password-input"><?php echo esc_html__('ダウンロードパスワード(任意):', 'fourier'); ?></label>
                    <input type="text" id="upload-password-input" class="upload-form-input" placeholder="<?php echo esc_attr__('パスワードを設定', 'fourier'); ?>" style="height:2.5rem; padding:0.5rem 1rem; width: 100%;" />
                </div>
            </div>

            <!-- ドラッグ＆ドロップ領域 -->
            <div id="drop-zone" class="drop-zone">
                <div class="drop-zone-content">
                    <span class="material-symbols-outlined upload-icon">cloud_upload</span>
                    <p class="drop-zone-text"><?php echo esc_html__('ここにファイルをドラッグ＆ドロップ', 'fourier'); ?></p>
                    <span class="drop-zone-or"><?php echo esc_html__('または', 'fourier'); ?></span>
                    <button type="button" id="browse-btn" class="btn-base btn-primary"><?php echo esc_html__('ファイルを選択', 'fourier'); ?></button>
                    <input type="file" id="file-input" multiple accept="image/*,video/*,application/pdf,audio/*" style="display: none;" />
                </div>
            </div>

            <!-- アップロード状況・プレビュー領域 -->
            <div id="upload-preview-container" class="upload-preview-container" style="display: none;">
                <h3><?php echo esc_html__('アップロード状況', 'fourier'); ?></h3>
                <div id="preview-list" class="preview-list"></div>
            </div>

            <!-- ZIPまとめパネル -->
            <div id="zip-panel" class="zip-panel" style="display: none;">
                <div class="zip-panel-title"><?php echo esc_html__('アップロード済みのファイルをまとめる', 'fourier'); ?></div>
                <div class="zip-controls">
                    <div class="zip-input-group">
                        <label for="zip-name-input"><?php echo esc_html__('ファイル名:', 'fourier'); ?></label>
                        <input type="text" id="zip-name-input" class="upload-form-input" placeholder="archive" />
                        <span class="zip-ext">.zip</span>
                    </div>
                    <div class="zip-input-group">
                        <label for="zip-password-input"><?php echo esc_html__('ダウンロードパスワード:', 'fourier'); ?></label>
                        <input type="text" id="zip-password-input" class="upload-form-input" placeholder="<?php echo esc_attr__('パスワード', 'fourier'); ?>" />
                    </div>
                    <button type="button" id="zip-submit-btn" class="btn-base btn-primary btn-zip"><?php echo esc_html__('まとめる', 'fourier'); ?></button>
                </div>
                <div id="zip-result" class="zip-result" style="display: none;">
                    <p><?php echo esc_html__('まとめZIPのダウンロードURL:', 'fourier'); ?></p>
                    <div class="zip-url-wrapper">
                        <input type="text" id="zip-url-input" class="upload-form-input" readonly />
                        <button type="button" id="copy-zip-url-btn" class="btn-base btn-primary"><?php echo esc_html__('コピー', 'fourier'); ?></button>
                    </div>
                </div>
            </div>

            <div class="back-home">
                <a href="<?php echo esc_url(home_url('/')); ?>" class="btn-base btn-primary">
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
        var passwordInput = document.getElementById('upload-password-input');
        var expirationHidden = document.getElementById('selected-expiration-days');

        // ZIPまとめ用要素
        var zipPanel = document.getElementById('zip-panel');
        var zipSubmitBtn = document.getElementById('zip-submit-btn');
        var zipNameInput = document.getElementById('zip-name-input');
        var zipPasswordInput = document.getElementById('zip-password-input');
        var zipResult = document.getElementById('zip-result');
        var zipUrlInput = document.getElementById('zip-url-input');
        var copyZipUrlBtn = document.getElementById('copy-zip-url-btn');

        // アップロードに成功したファイルの識別名（物理ファイル名）を記録する配列
        var uploadedFileIds = [];

        if (!dropZone || !fileInput) return; // ログインしていない場合は処理をスキップ

        // WordPress REST/AJAX ノンスとURLの設定
        var uploadNonce = "<?php echo wp_create_nonce('frontend_upload_action'); ?>";
        var ajaxUrl = "<?php echo esc_url(admin_url('admin-ajax.php')); ?>";
        var fallbackImgUrl = typeof themeDirectoryUrl !== 'undefined' ? themeDirectoryUrl + '/assets/img/logo_tokushiikusya_main.svg' : '';

        // 保持期限ボタンのトグル処理
        var expButtons = document.querySelectorAll('#expiration-buttons .btn-exp');
        expButtons.forEach(function(btn) {
            btn.addEventListener('click', function() {
                expButtons.forEach(function(b) { b.classList.remove('active'); });
                btn.classList.add('active');
                expirationHidden.value = btn.getAttribute('data-days');
            });
        });

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

        function handleFiles(files) {
            if (files.length === 0) return;

            previewContainer.style.display = 'block';

            Array.from(files).forEach(function(file) {
                uploadFile(file);
            });
        }

        function uploadFile(file) {
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
            statusDiv.className = 'preview-status uploading';

            var progressContainer = document.createElement('div');
            progressContainer.className = 'preview-progress-bar';
            var progressFill = document.createElement('div');
            progressFill.className = 'preview-progress-fill';
            progressContainer.appendChild(progressFill);

            var statusText = document.createElement('span');
            statusText.textContent = '0%';

            statusDiv.appendChild(progressContainer);
            statusDiv.appendChild(statusText);

            previewItem.appendChild(previewInfo);
            previewItem.appendChild(statusDiv);
            previewList.insertBefore(previewItem, previewList.firstChild);

            // AJAXによる送信処理（ギガファイル用アクション）
            var xhr = new XMLHttpRequest();
            var formData = new FormData();

            formData.append('action', 'frontend_gigafile_upload');
            formData.append('nonce', uploadNonce);
            formData.append('expiration_days', expirationHidden.value);
            formData.append('download_password', passwordInput.value);
            formData.append('file', file);

            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    var percent = Math.round((e.loaded / e.total) * 100);
                    progressFill.style.width = percent + '%';
                    statusText.textContent = percent + '%';
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

                            // 成功したファイル識別名（file_id）を記録
                            uploadedFileIds.push(response.data.file_id);
                            
                            // ZIPまとめパネルを表示
                            zipPanel.style.display = 'block';

                            // ゲートウェイ用ダウンロードURLを生成
                            var dlUrl = window.location.origin + window.location.pathname + '?dl=' + response.data.file_id;

                            // コピー用のURLインプットとボタンを追加
                            var urlGroup = document.createElement('div');
                            urlGroup.className = 'url-copy-group';
                            urlGroup.innerHTML = '<input type="text" class="upload-form-input url-input" value="' + dlUrl + '" readonly />' +
                                                 '<button type="button" class="btn-base btn-primary copy-url-btn">コピー</button>';
                            
                            previewItem.appendChild(urlGroup);

                            // コピーイベントの登録
                            var copyBtn = urlGroup.querySelector('.copy-url-btn');
                            var urlInput = urlGroup.querySelector('.url-input');
                            copyBtn.addEventListener('click', function() {
                                urlInput.select();
                                document.execCommand('copy');
                                copyBtn.textContent = 'コピー完了';
                                setTimeout(function() {
                                    copyBtn.textContent = 'コピー';
                                }, 2000);
                            });

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

        // ZIPまとめ処理
        zipSubmitBtn.addEventListener('click', function() {
            if (uploadedFileIds.length === 0) return;

            zipSubmitBtn.disabled = true;
            zipSubmitBtn.textContent = '作成中...';

            var xhr = new XMLHttpRequest();
            var formData = new FormData();

            formData.append('action', 'frontend_create_zip_archive');
            formData.append('nonce', uploadNonce);
            formData.append('file_ids', uploadedFileIds.join(','));
            formData.append('zip_name', zipNameInput.value);
            formData.append('download_password', zipPasswordInput.value);
            formData.append('expiration_days', expirationHidden.value);

            xhr.open('POST', ajaxUrl, true);

            xhr.addEventListener('load', function() {
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            zipSubmitBtn.innerHTML = '<span class="material-symbols-outlined">check_circle</span> 完了';
                            zipSubmitBtn.style.backgroundColor = '#10B981';
                            zipSubmitBtn.style.borderColor = '#10B981';
                            zipResult.style.display = 'block';
                            var zipDlUrl = window.location.origin + window.location.pathname + '?dl=' + response.data.file_id;
                            zipUrlInput.value = zipDlUrl;
                            setTimeout(() => {
                                zipSubmitBtn.disabled = false;
                                zipSubmitBtn.textContent = 'まとめる';
                                zipSubmitBtn.style.backgroundColor = '';
                                zipSubmitBtn.style.borderColor = '';
                            }, 3000);
                        } else {
                            alert(response.data.message || 'ZIPの作成に失敗しました。');
                            zipSubmitBtn.disabled = false;
                            zipSubmitBtn.textContent = 'まとめる';
                        }
                    } catch (e) {
                        alert('エラーが発生しました。');
                        zipSubmitBtn.disabled = false;
                        zipSubmitBtn.textContent = 'まとめる';
                    }
                } else {
                    alert('通信エラー (' + xhr.status + ')');
                }
            });

            xhr.addEventListener('error', function() {
                zipSubmitBtn.disabled = false;
                zipSubmitBtn.textContent = 'まとめる';
                alert('通信エラーが発生しました。');
            });

            xhr.send(formData);
        });

        // ZIP URLのコピー処理
        copyZipUrlBtn.addEventListener('click', function() {
            zipUrlInput.select();
            document.execCommand('copy');
            copyZipUrlBtn.textContent = 'コピー完了';
            setTimeout(function() {
                copyZipUrlBtn.textContent = 'コピー';
            }, 2000);
        });
    });
</script>

<?php
get_footer();
?>
