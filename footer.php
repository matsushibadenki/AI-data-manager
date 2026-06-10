<?php
/*
 * File: /footer.php
 * Name: footer.php
 * Description: テーマフッターテンプレート
 *              コピーライト表示、wp_footer()フック
 *              多言語対応
 */
?>
<footer id="footer">
    <p>&copy; <?php echo date('Y'); ?> <?php bloginfo('name'); ?>. All rights reserved.</p>
</footer>
<?php wp_footer(); ?>
</body>

</html>