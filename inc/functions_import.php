<?php
/* 
 * 外部PHPファイルを条件付きで読み込むためのカスタム関数
 */

/**
 * 指定したPHPファイルを読み込む関数
 *
 * @param string $file_name ファイル名(拡張子なし)
 * @param string $directory ファイルの格納ディレクトリ。デフォルトは'includes'
 */
function include_custom_file($file_name, $directory = 'includes') {
    $file_path = get_stylesheet_directory() . '/' . $directory . '/' . $file_name . '.php';
    if (file_exists($file_path)) {
        include_once $file_path;
    } else {
        error_log("Error: Custom file '{$file_name}.php' not found in '{$directory}' directory.");
    }
}

/**
 * メニュージムナスティクスを追加する関数
 */
function menu_gymnastics() {
    include_custom_file('menu_gymnastics', 'includes');
}
/**
 * メニューろんり国語を追加する関数
 */
function menu_ronrikokugo() {
    include_custom_file('menu_ronrikokugo', 'includes');
}
?>
