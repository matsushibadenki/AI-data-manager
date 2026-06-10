<?php
/*
 * Name: functions_control-panel.php
 * Description: WordPress管理画面のカスタムスタイル・メニュー制御、アタッチメント画像書き出しおよび削除処理。
 */

/*--------------------------------------------------------------
  管理画面の投稿・固定ページ一覧のタイトル幅を400pxにする
--------------------------------------------------------------*/

function ha_admin_title_width400()
{
  echo '<style>
    .posts .column-title,.pages .column-title {
    width: 25rem;
    }

    .posts .column-categories,.posts .column-tags,.pages .column-categories,.pages .column-tags {
      width:5rem;
    }

    .posts .column-seopress_title,.pages .column-seopress_title {
      width:6rem;
    }

    .posts .column-seopress_desc,.pages .column-seopress_desc {
      width:10rem;
    }

    .posts .column-seopress_score,.pages .column-seopress_score {
      width:3rem;
    }

    .posts .column-date,.pages .column-date {
      width:8rem;
    }

    .posts .column-author,.pages .column-author {
      width:3rem;
    }
  </style>';
}

add_action('admin_head', 'ha_admin_title_width400');


/*--------------------------------------------------------------
  管理画面のメニューを分類するために区切り線を追加
--------------------------------------------------------------*/

function custom_admin_menu_styles()
{
  echo '<style>

      #menu-posts-news  {
          margin-top: 2rem !important;
          padding-top:1rem !important;
          border-top:2px solid #999 !important;
      }

      #menu-posts-blog {
          margin-bottom: 0.5rem !important;
          padding-bottom:1rem !important;
          border-bottom:2px solid #999 !important;
      }

      #menu-appearance {
          margin-top: 0.5rem !important;
          padding-top:1rem !important;
          border-top:2px solid #999 !important;
      }
      #menu-settings {
          margin-bottom: 2rem !important;
          padding-bottom:1rem !important;
          border-bottom:2px solid #999 !important;
      }

  </style>';
}
add_action('admin_head', 'custom_admin_menu_styles');


/*--------------------------------------------------------------
  管理画面のメニュー非公開を強調
--------------------------------------------------------------*/

function custom_admin_color_styles()
{
  echo '<style>
      span.private-title {
      font-weight: 600;
      background-color: red;
      color: white;
      padding: 0.1rem 0.5rem 0.15rem 0.5rem;
      margin: 0 0.5rem 0 0;
      border-radius: 0.2rem;
} 

  </style>';
}
add_action('admin_head', 'custom_admin_color_styles');


/*--------------------------------------------------------------
  管理者以外のときに読み込むCSS
--------------------------------------------------------------*/

function load_custom_css_for_non_admins()
{
  // ユーザーの役割を取得
  $current_user = wp_get_current_user();
  $user_roles = $current_user->roles;

  // 管理者でなければカスタムCSSを追加
  if (!in_array('administrator', $user_roles)) {
    wp_enqueue_style('custom-non-admin-css', get_stylesheet_directory_uri() . '/assets/css/non-admin/custom-non-admin.css');
  } else {
    // デバッグ用のログ出力
    error_log('User is an administrator. Custom CSS not enqueued.');
  }
}
add_action('admin_enqueue_scripts', 'load_custom_css_for_non_admins');



/*--------------------------------------------------------------
  Filebird画像リストのカラム数を変更
--------------------------------------------------------------*/
function custom_media_grid_styles() {
    echo '<style>
        .attachments-wrapper>ul {
            display: grid !important;
            grid-template-columns: repeat(var(--custom-grid-columns, 4), 1fr);
            gap: 10px;
        }
        .attachments-wrapper>ul li {
            width: 100% !important;
        }
        .attachments-wrapper>ul li div.thumbnail img {
            width: 100% !important;
        }
        #custom-grid-control {
            margin:0 0 0.5rem 2rem;
            display:inline-block;
            vertical-align: middle;
            font-size:1rem;
        }
    </style>';

    echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            function addGridControl() {
                if (document.getElementById("custom-grid-control")) return;

                let mediaGrid = document.querySelector("#wp-media-grid");
                if (!mediaGrid) return;

                let controlPanel = document.createElement("div");
                controlPanel.id = "custom-grid-control";
                controlPanel.innerHTML = `
                    <label for="custom-grid-columns">カラム数: </label>
                    <select id="custom-grid-columns">
                        <option value="2">2</option>
                        <option value="3">3</option>
                        <option value="4" selected>4</option>
                        <option value="5">5</option>
                        <option value="6">6</option>
                        <option value="7">7</option>
                        <option value="8">8</option>
                        <option value="9">9</option>
                        <option value="10">10</option>
                    </select>
                    <button type="button" id="btn-export-images" class="button button-secondary" style="margin-left: 15px; vertical-align: middle;">画像を書き出す</button>
                    <button type="button" id="btn-delete-exported" class="button" style="margin-left: 10px; vertical-align: middle; color: #b32d2e; border-color: #b32d2e;" onmouseover="this.style.backgroundColor=\'#fcf0f0\'" onmouseout="this.style.backgroundColor=\'transparent\'">書き出し画像を削除</button>
                    <span id="export-status-message" style="margin-left: 15px; font-size: 0.9rem; font-weight: 500; vertical-align: middle; transition: color 0.2s;"></span>
                `;

                let hrElement = mediaGrid.querySelector("hr");
                if (hrElement) {
                    hrElement.parentNode.insertBefore(controlPanel, hrElement);
                }

                let selectElement = document.getElementById("custom-grid-columns");
                selectElement.addEventListener("change", function() {
                    document.documentElement.style.setProperty("--custom-grid-columns", this.value);
                    localStorage.setItem("customGridColumns", this.value);
                });

                let savedColumns = localStorage.getItem("customGridColumns");
                if (savedColumns) {
                    selectElement.value = savedColumns;
                    document.documentElement.style.setProperty("--custom-grid-columns", savedColumns);
                }

                // 画像書き出しボタンのイベント
                let btnExport = document.getElementById("btn-export-images");
                if (btnExport) {
                    btnExport.addEventListener("click", function() {
                        let selectedItems = document.querySelectorAll(".attachments-wrapper>ul li.selected");
                        let ids = [];
                        selectedItems.forEach(item => {
                            let id = item.getAttribute("data-id");
                            if (id) ids.push(parseInt(id, 10));
                        });

                        let statusSpan = document.getElementById("export-status-message");

                        if (ids.length === 0) {
                            alert("画像が選択されていません。「一括選択」ボタンを押して、書き出す画像を選択してください。");
                            return;
                        }

                        statusSpan.textContent = "書き出し中...";
                        statusSpan.style.color = "#666";
                        btnExport.disabled = true;

                        let fd = new FormData();
                        fd.append("action", "export_selected_images");
                        fd.append("ids", JSON.stringify(ids));

                        fetch(ajaxurl, {
                            method: "POST",
                            body: fd
                        })
                        .then(r => r.json())
                        .then(res => {
                            btnExport.disabled = false;
                            if (res.success) {
                                statusSpan.textContent = res.data.message;
                                statusSpan.style.color = "#46b450";
                                if (res.data.zip_url) {
                                    // ZIPダウンロードのトリガー (ポップアップブロックを回避するため window.location.href を使用)
                                    window.location.href = res.data.zip_url + "?t=" + new Date().getTime();
                                }
                            } else {
                                statusSpan.textContent = "エラー: " + res.data.message;
                                statusSpan.style.color = "#dc3232";
                            }
                        })
                        .catch(err => {
                            btnExport.disabled = false;
                            statusSpan.textContent = "通信エラー";
                            statusSpan.style.color = "#dc3232";
                            console.error(err);
                        });
                    });
                }

                // 書き出し画像削除ボタンのイベント
                let btnDelete = document.getElementById("btn-delete-exported");
                if (btnDelete) {
                    btnDelete.addEventListener("click", function() {
                        if (!confirm("サーバー上に書き出されたすべての画像を物理削除しますか？\n（メディアライブラリの元の画像は削除されません）")) {
                            return;
                        }

                        let statusSpan = document.getElementById("export-status-message");
                        statusSpan.textContent = "削除中...";
                        statusSpan.style.color = "#666";
                        btnDelete.disabled = true;

                        let fd = new FormData();
                        fd.append("action", "delete_exported_images");

                        fetch(ajaxurl, {
                            method: "POST",
                            body: fd
                        })
                        .then(r => r.json())
                        .then(res => {
                            btnDelete.disabled = false;
                            if (res.success) {
                                statusSpan.textContent = res.data.message;
                                statusSpan.style.color = "#46b450";
                            } else {
                                statusSpan.textContent = "エラー: " + res.data.message;
                                statusSpan.style.color = "#dc3232";
                            }
                        })
                        .catch(err => {
                            btnDelete.disabled = false;
                            statusSpan.textContent = "通信エラー";
                            statusSpan.style.color = "#dc3232";
                            console.error(err);
                        });
                    });
                }
            }

            const observer = new MutationObserver(() => {
                if (document.querySelector("#wp-media-grid hr")) {
                    addGridControl();
                    observer.disconnect();
                }
            });

            observer.observe(document.body, { childList: true, subtree: true });
        });
    </script>';
}
add_action('admin_head-upload.php', 'custom_media_grid_styles');


/*--------------------------------------------------------------
  選択した画像を書き出すAJAX処理
--------------------------------------------------------------*/
add_action('wp_ajax_export_selected_images', 'export_selected_images_handler');

function export_selected_images_handler() {
    if (!current_user_can('upload_files')) {
        wp_send_json_error(array('message' => '権限がありません。'));
    }

    $ids_str = isset($_POST['ids']) ? wp_unslash($_POST['ids']) : '';
    $ids = json_decode($ids_str, true);

    if (empty($ids) || !is_array($ids)) {
        wp_send_json_error(array('message' => '画像が選択されていません。'));
    }

    $upload_dir = wp_upload_dir();
    $export_dir = $upload_dir['basedir'] . '/exported_images';
    $zip_filepath = $upload_dir['basedir'] . '/exported_images.zip';
    $zip_url = $upload_dir['baseurl'] . '/exported_images.zip';

    if (!file_exists($export_dir)) {
        if (!wp_mkdir_p($export_dir)) {
            wp_send_json_error(array('message' => '書き出しディレクトリの作成に失敗しました。'));
        }
    }

    // ZipArchiveの初期化
    $zip = new ZipArchive();
    $zip_res = $zip->open($zip_filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($zip_res !== true) {
        wp_send_json_error(array('message' => 'ZIPファイルの作成に失敗しました。（エラーコード: ' . $zip_res . '）'));
    }

    $success_count = 0;
    foreach ($ids as $id) {
        $id = intval($id);
        $file_path = get_attached_file($id);
        if ($file_path && file_exists($file_path)) {
            $dest = $export_dir . '/' . basename($file_path);
            if (copy($file_path, $dest)) {
                $success_count++;
                $zip->addFile($file_path, basename($file_path));
            }
        }
    }

    $zip->close();

    if ($success_count === 0) {
        wp_send_json_error(array('message' => '画像の書き出しに失敗しました。'));
    }

    // 書き出したアタッチメントIDをオプションに一時保存
    update_option('exported_attachment_ids', $ids);

    wp_send_json_success(array(
        'message' => sprintf('%d枚の画像を書き出し、ZIPを作成しました。', $success_count),
        'zip_url' => $zip_url
    ));
}

/*--------------------------------------------------------------
  書き出した画像を削除するAJAX処理
--------------------------------------------------------------*/
add_action('wp_ajax_delete_exported_images', 'delete_exported_images_handler');

function delete_exported_images_handler() {
    if (!current_user_can('upload_files')) {
        wp_send_json_error(array('message' => '権限がありません。'));
    }

    $upload_dir = wp_upload_dir();
    $export_dir = $upload_dir['basedir'] . '/exported_images';
    $zip_filepath = $upload_dir['basedir'] . '/exported_images.zip';

    // ZIPファイルを削除
    if (file_exists($zip_filepath)) {
        @unlink($zip_filepath);
    }

    // メディアライブラリのアタッチメント削除処理に必要なファイルをインクルード
    if (!function_exists('wp_delete_attachment')) {
        require_once(ABSPATH . 'wp-admin/includes/post.php');
    }

    $exported_ids = get_option('exported_attachment_ids', array());

    // フォールバック: オプションが空の場合、書き出しフォルダ内のファイル名からアタッチメントを逆引き
    if (empty($exported_ids) && file_exists($export_dir)) {
        global $wpdb;
        $files = glob($export_dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                $filename = basename($file);
                $attachment_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
                    '%' . $wpdb->esc_like($filename)
                ));
                if ($attachment_id) {
                    $exported_ids[] = intval($attachment_id);
                }
            }
        }
    }

    // メディアライブラリからアタッチメントを完全に削除 (データベースおよび物理ファイル)
    $deleted_media_count = 0;
    if (is_array($exported_ids) && !empty($exported_ids)) {
        $exported_ids = array_unique($exported_ids);
        foreach ($exported_ids as $id) {
            $id = intval($id);
            if (wp_delete_attachment($id, true)) {
                $deleted_media_count++;
            }
        }
        delete_option('exported_attachment_ids');
    }

    // 書き出しフォルダ内の複製（キャッシュ）ファイルを削除
    $deleted_copy_count = 0;
    if (file_exists($export_dir)) {
        $files = glob($export_dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                if (@unlink($file)) {
                    $deleted_copy_count++;
                }
            }
        }
    }

    wp_send_json_success(array(
        'message' => sprintf('メディアから画像%d枚、および書き出し用フォルダの複製%d枚とZIPファイルを削除しました。', $deleted_media_count, $deleted_copy_count)
    ));
}


