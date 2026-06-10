// ----------------------------------------------
// 背景画像
// ----------------------------------------------

document.addEventListener('DOMContentLoaded', function () {
    // 背景画像読み込み
    var modules = document.querySelectorAll('.bg-img');
    modules.forEach(function(module) {
        var background = module.getAttribute('data-background');
        if (background) {
            module.style.backgroundImage = 'url(' + background + ')';
        }
    });

    // ウィンドウのサイズによって背景画像を切り替える関数
    function setBgImage() {
        var windowWidth = window.innerWidth;
        var mobileModules = document.querySelectorAll('.bg-img-mobile');

        mobileModules.forEach(function(module) {
            var pcImage = module.getAttribute('data-bg');
            var mobileImage = module.getAttribute('data-bg-mobile');

            if (windowWidth < 992) { // 992px以下はスマホ・タブレット用
                if (mobileImage) {
                    module.style.backgroundImage = 'url(' + mobileImage + ')';
                }
            } else { // 992px以上はPC用
                if (pcImage) {
                    module.style.backgroundImage = 'url(' + pcImage + ')';
                }
            }
        });
    }

    // ページ読み込み時とウィンドウサイズ変更時に画像を設定
    setBgImage();
    window.addEventListener('resize', setBgImage);
});
