<?php
/*
 * Name: functions_filebird-control.php
 * Description: FileBirdデータベース操作用関数群（フォルダ表示および取得処理）。多言語対応。
 */

/**
 * FileBird データベース操作用関数群
 * この関数群を functions.php に追加し、FileBird のデータベースを操作する
 */

// グローバル変数を使うために $wpdb を宣言
global $wpdb;

/*--------------------------------------------------------------
  FileBird 関連データベース操作の基本関数
--------------------------------------------------------------*/

// 1. wp_fbv テーブルのすべてのフォルダを取得する関数
function get_all_filebird_folders()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'fbv';
    return $wpdb->get_results("SELECT * FROM {$table_name}");
}

// 2. フォルダIDからフォルダ名を取得する関数
function get_filebird_folder_name_by_id($folder_id)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'fbv';
    return $wpdb->get_var($wpdb->prepare("SELECT name FROM {$table_name} WHERE id = %d", $folder_id));
}

// 3. アタッチメントIDからフォルダ名を取得する関数（統合済み）
function get_folder_name_by_attachment_id($attachment_id)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'fbv_attachment_folder';
    $folder_id = $wpdb->get_var($wpdb->prepare("SELECT folder_id FROM {$table_name} WHERE attachment_id = %d", $attachment_id));

    if (empty($folder_id)) {
        return 'フォルダ情報が見つかりません';
    }

    return get_filebird_folder_name_by_id($folder_id);
}

// 4. 新規フォルダを作成する関数
function create_filebird_folder($folder_name, $parent_id = 0)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'fbv';
    $wpdb->insert(
        $table_name,
        array(
            'name' => $folder_name,
            'parent' => $parent_id,
            'type' => 0,
            'ord' => 0,
            'created_by' => 0
        ),
        array(
            '%s',
            '%d',
            '%d',
            '%d',
            '%d'
        )
    );
    return $wpdb->insert_id; // 作成されたフォルダのIDを返す
}

// 5. フォルダIDからフォルダを削除する関数
function delete_filebird_folder_by_id($folder_id)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'fbv';
    $deleted = $wpdb->delete($table_name, array('id' => $folder_id), array('%d'));
    return $deleted !== false;
}

// 6. アタッチメントIDとフォルダIDを関連付ける関数
function add_attachment_to_filebird_folder($attachment_id, $folder_id)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'fbv_attachment_folder';
    $wpdb->insert(
        $table_name,
        array(
            'attachment_id' => $attachment_id,
            'folder_id' => $folder_id
        ),
        array(
            '%d',
            '%d'
        )
    );
    return $wpdb->insert_id;
}

// 7. アタッチメントIDとフォルダIDの関連付けを削除する関数
function remove_attachment_from_filebird_folder($attachment_id, $folder_id)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'fbv_attachment_folder';
    $deleted = $wpdb->delete($table_name, array('attachment_id' => $attachment_id, 'folder_id' => $folder_id), array('%d', '%d'));
    return $deleted !== false;
}

// 8. 特定のフォルダIDに含まれるアタッチメントID一覧を取得する関数
function get_attachments_by_filebird_folder_id($folder_id)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'fbv_attachment_folder';
    return $wpdb->get_col($wpdb->prepare("SELECT attachment_id FROM {$table_name} WHERE folder_id = %d", $folder_id));
}

// 9. wp_fbv テーブルのすべてのフォルダをHTML形式で表示する関数
function display_filebird_folders_html()
{
    $folders = get_all_filebird_folders();
    echo '<ul>';
    foreach ($folders as $folder) {
        echo '<li>' . esc_html($folder->name) . ' (ID: ' . esc_html($folder->id) . ')</li>';
    }
    echo '</ul>';
}

// 10.FileBird のフォルダ一覧を表示する関数
function display_select_folders($current_folder_name)
{
    if (class_exists('FileBird\\Model\\Folder')) {
        echo '<form id="folder-filter">'; // フォームのIDを保持
        echo '<fieldset id="folders">'; // 元のIDを変更しない

        // デフォルトは「すべて」を選択した状態にする
        $checked = ($current_folder_name === 'all') ? 'checked' : '';
        echo '<span class="foldername"><input type="radio" name="foldergroup" id="all" value="all" ' . $checked . ' /><label for="all">すべて</label></span>';

        // FileBird のフォルダ一覧を取得して表示
        $folders = FileBird\Model\Folder::allFolders();
        foreach ($folders as $folder) {
            $folder_name = esc_html($folder->name);
            // 現在選択されているフォルダかどうかをチェック
            $checked = ($current_folder_name === $folder_name) ? 'checked' : '';
            echo '<span class="foldername"><input type="radio" name="foldergroup" id="' . esc_attr($folder_name) . '" value="' . esc_attr($folder_name) . '" ' . $checked . ' /><label for="' . esc_attr($folder_name) . '">' . $folder_name . '</label></span>';
        }
        echo '</fieldset>';
        echo '</form>';
    } else {
        echo 'FileBird のフォルダ情報を取得できません。';
    }
}

/*--------------------------------------------------------------
 * 11.関数display_select_foldersの現在選択されているフォルダ名を取得する関数
 * @return string 選択されているフォルダ名（デフォルトは "all"）
--------------------------------------------------------------*/
function get_current_selected_folder_name()
{
    // URL の GET パラメータから folder_name を取得（存在しない場合は 'all' を返す）
    return isset($_GET['folder_name']) ? sanitize_text_field($_GET['folder_name']) : 'all';
}


/*--------------------------------------------------------------
 * 12.フォルダ名からフォルダIDを取得する関数
 * @param string $folder_name フォルダ名
 * @return int|null フォルダID（該当するフォルダが存在しない場合は null）
--------------------------------------------------------------*/
function get_folder_id_by_folder_name($folder_name)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'fbv';
    $folder_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_name} WHERE name = %s", $folder_name));

    // フォルダIDが存在しない場合は null を返す
    return $folder_id ? intval($folder_id) : null;
}

/*--------------------------------------------------------------
  FileBird の REST API を初期化する関数
  - REST API の初期化を行い、データベース操作を補助
--------------------------------------------------------------*/
function initialize_filebird_rest_apis()
{
    if (class_exists('FileBird\\Rest\\RestApi')) {
        $rest_api = new FileBird\Rest\RestApi();
        $rest_api->rest_api_init();
        error_log("FileBird REST API が初期化されました。");
    } else {
        error_log("FileBird REST API クラスが存在しません。FileBird プラグインが有効か確認してください。");
    }
}
add_action('plugins_loaded', 'initialize_filebird_rest_apis', 20);

/**
 * 選択されたフォルダ名に基づいて画像を取得する関数
 * @param string $folder_name 選択されたフォルダ名
 * @return array 画像情報の配列
 */
function get_attachments_by_folder_name($folder_name)
{
    global $wpdb;

    // フォルダ名からフォルダIDを取得
    $folder_table = $wpdb->prefix . 'fbv';
    $folder_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$folder_table} WHERE name = %s", $folder_name));

    if (!$folder_id) {
        return []; // フォルダIDが取得できなかった場合、空配列を返す
    }

    // フォルダIDを使ってアタッチメントIDを取得
    $attachment_folder_table = $wpdb->prefix . 'fbv_attachment_folder';
    $attachment_ids = $wpdb->get_col($wpdb->prepare("SELECT attachment_id FROM {$attachment_folder_table} WHERE folder_id = %d", $folder_id));

    if (empty($attachment_ids)) {
        return []; // アタッチメントIDが取得できなかった場合、空配列を返す
    }

    // アタッチメントIDに基づいて画像を取得
    $placeholders = implode(',', array_fill(0, count($attachment_ids), '%d'));
    $query = $wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID IN ($placeholders)", ...$attachment_ids);


    // デバッグ用ログ
    error_log("=== デバッグ開始 ===");
    error_log("フォルダ名: " . $folder_name);

    // フォルダIDの取得を試行し、結果をログに出力
    $folder_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$folder_table} WHERE name = %s", $folder_name));
    error_log("取得したフォルダID: " . ($folder_id ? $folder_id : "取得できず"));

    if (!$folder_id) {
        error_log("フォルダIDが見つかりません。フォルダ名: " . $folder_name);
        return []; // フォルダIDが取得できなかった場合、ログを出力し空配列を返す
    }

    // アタッチメントIDの取得状況をログに出力
    $attachment_ids = $wpdb->get_col($wpdb->prepare("SELECT attachment_id FROM {$attachment_folder_table} WHERE folder_id = %d", $folder_id));
    error_log("取得したアタッチメントID: " . (empty($attachment_ids) ? "取得できず" : implode(', ', $attachment_ids)));

    if (empty($attachment_ids)) {
        error_log("アタッチメントIDが取得できません。フォルダID: " . $folder_id);
        return []; // アタッチメントIDが取得できなかった場合、ログを出力し空配列を返す
    }

    // アタッチメント情報の取得状況をログに出力
    $placeholders = implode(',', array_fill(0, count($attachment_ids), '%d'));
    $query = $wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID IN ($placeholders)", ...$attachment_ids);
    $attachments = $wpdb->get_results($query);
    error_log("取得したアタッチメント情報: " . print_r($attachments, true));

    error_log("=== デバッグ終了 ===");
    return $attachments;
}
