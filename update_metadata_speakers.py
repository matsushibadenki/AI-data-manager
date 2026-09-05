import re

# 1. Update functions_learning-data.php
func_path = '/Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/inc/functions_learning-data.php'
with open(func_path, 'r') as f:
    func_content = f.read()

func_content = func_content.replace(
    "$fields = ['language', 'category', 'difficulty', 'quality', 'source', 'tags'];",
    "$fields = ['language', 'category', 'difficulty', 'quality', 'source', 'tags', 'speakers'];"
)

func_content = func_content.replace(
    "'tags'       => get_post_meta($post_id, 'learning_tags', true),",
    "'tags'       => get_post_meta($post_id, 'learning_tags', true),\n        'speakers'   => get_post_meta($post_id, 'learning_speakers', true),"
)
with open(func_path, 'w') as f:
    f.write(func_content)


# 2. Update text-based-learning.php
tbl_path = '/Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/text-based-learning.php'
with open(tbl_path, 'r') as f:
    tbl_content = f.read()

html_speaker = """                        <div>
                            <label for="meta-tags"><?php echo esc_html__('タグ', 'fourier'); ?></label>"""
html_speaker_repl = """                        <div>
                            <label for="meta-speakers"><?php echo esc_html__('話者名/登場人物', 'fourier'); ?></label>
                            <input type="text" id="meta-speakers" class="upload-form-input" placeholder="<?php echo esc_attr__('例: ゲスト名, 司会者', 'fourier'); ?>" />
                        </div>
                        <div>
                            <label for="meta-tags"><?php echo esc_html__('タグ', 'fourier'); ?></label>"""

tbl_content = tbl_content.replace(
    "                        <div>\n                            <label><?php echo esc_html__('タグ', 'fourier'); ?></label>",
    """                        <div>
                            <label for="meta-speakers"><?php echo esc_html__('話者名/登場人物', 'fourier'); ?></label>
                            <input type="text" id="meta-speakers" class="upload-form-input" placeholder="<?php echo esc_attr__('例: ゲスト名, 司会者', 'fourier'); ?>" />
                        </div>
                        <div>
                            <label><?php echo esc_html__('タグ', 'fourier'); ?></label>"""
)

tbl_content = tbl_content.replace(
    "                        <div class=\"upload-form-group\" style=\"margin-top: 1rem;\">\n                            <label><?php echo esc_html__('タグ', 'fourier'); ?></label>",
    """                        <div class="upload-form-group" style="margin-top: 1rem;">
                            <label for="edit-meta-speakers"><?php echo esc_html__('話者名/登場人物', 'fourier'); ?></label>
                            <input type="text" id="edit-meta-speakers" class="upload-form-input" />
                        </div>
                        <div class="upload-form-group" style="margin-top: 1rem;">
                            <label><?php echo esc_html__('タグ', 'fourier'); ?></label>"""
)

tbl_content = tbl_content.replace(
    "document.getElementById('edit-meta-tags').value = d.meta.tags || '';",
    "document.getElementById('edit-meta-tags').value = d.meta.tags || '';\n                    document.getElementById('edit-meta-speakers').value = d.meta.speakers || '';"
)

tbl_content = tbl_content.replace(
    "var metaTags = document.getElementById('meta-tags');",
    "var metaTags = document.getElementById('meta-tags');\n            var metaSpeakers = document.getElementById('meta-speakers');"
)

tbl_content = tbl_content.replace(
    "if (metaTags && metaTags.value) formData.append('tags', metaTags.value);",
    "if (metaTags && metaTags.value) formData.append('tags', metaTags.value);\n            if (metaSpeakers && metaSpeakers.value) formData.append('speakers', metaSpeakers.value);"
)

tbl_content = tbl_content.replace(
    "fd.append('tags', document.getElementById('edit-meta-tags').value);",
    "fd.append('tags', document.getElementById('edit-meta-tags').value);\n            fd.append('speakers', document.getElementById('edit-meta-speakers').value);"
)

with open(tbl_path, 'w') as f:
    f.write(tbl_content)


# 3. Update page-index-text.php
pit_path = '/Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/page-index-text.php'
with open(pit_path, 'r') as f:
    pit_content = f.read()

pit_content = pit_content.replace(
    "                        <div class=\"upload-form-group\" style=\"margin-top: 1rem;\">\n                            <label><?php echo esc_html__('タグ', 'fourier'); ?></label>",
    """                        <div class="upload-form-group" style="margin-top: 1rem;">
                            <label for="edit-meta-speakers"><?php echo esc_html__('話者名/登場人物', 'fourier'); ?></label>
                            <input type="text" id="edit-meta-speakers" class="upload-form-input" />
                        </div>
                        <div class="upload-form-group" style="margin-top: 1rem;">
                            <label><?php echo esc_html__('タグ', 'fourier'); ?></label>"""
)

pit_content = pit_content.replace(
    "document.getElementById('edit-meta-tags').value = d.meta.tags || '';",
    "document.getElementById('edit-meta-tags').value = d.meta.tags || '';\n                    document.getElementById('edit-meta-speakers').value = d.meta.speakers || '';"
)

pit_content = pit_content.replace(
    "fd.append('tags', document.getElementById('edit-meta-tags').value);",
    "fd.append('tags', document.getElementById('edit-meta-tags').value);\n            fd.append('speakers', document.getElementById('edit-meta-speakers').value);"
)

with open(pit_path, 'w') as f:
    f.write(pit_content)

print("metadata update complete")
