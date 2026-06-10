// ============================================================
// File URL: file:///Users/Shared/Docker/material/assets/js/masonry-init.js
// Name: masonry-init.js
// Description: Masonry レイアウト初期化スクリプト。imagesLoadedおよび動画読み込み完了を監視してレイアウトを最適化。
// ============================================================

// WordPressのjQueryセーフモードで実行
jQuery(document).ready(function ($) {
  var $gallery = $('.media-gallery');

  // .media-gallery がページ内に存在する場合のみ実行
  if ($gallery.length) {
    // 1. imagesLoaded を使って、ギャラリー内の画像の読み込みを待つ
    $gallery.imagesLoaded(function () {
      // 画像読み込み完了後に Masonry を初期化
      var msnry = $gallery.masonry({
        // アイテムのセレクタ
        itemSelector: '.media-item',
        // カラム幅の基準をアイテムの幅に合わせる
        percentPosition: true,
        // アイテム間の水平方向の余白（新デザイン用に調整）
        gutter: 20,
      });

      // ギャラリー内の動画のメタデータロード完了を監視して再配置（縦詰まり防止）
      $gallery.find('video').each(function () {
        var video = this;
        // すでにメタデータが読み込まれているか、読み込み済みの状態（readyState >= 1）
        if (video.readyState >= 1) {
          $gallery.masonry('layout');
        } else {
          // メタデータロード完了時にレイアウトを再計算
          video.addEventListener('loadedmetadata', function () {
            $gallery.masonry('layout');
          });
        }
      });
    });
  }
});