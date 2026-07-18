import re

# --- Process page-ai-registration.php ---
with open('page-ai-registration.php', 'r') as f:
    ai_content = f.read()

# Update Header
ai_content = re.sub(
    r'Name: text-based-learning\.php\s*\*\s*Description: ディープラーニング用テキストデータ登録・管理画面。\s*\*\s*Template Name: Text Based Learning',
    'Name: page-ai-registration.php\n * Description: AIを用いた学習データ自動登録・管理画面。\n * Template Name: AI Registration',
    ai_content
)

# Update Title
ai_content = re.sub(
    r'<\?php echo esc_html__\(\'ディープラーニング用データ管理\', \'fourier\'\); \?>',
    "<?php echo esc_html__('AIデータ自動登録', 'fourier'); ?>",
    ai_content
)
ai_content = re.sub(
    r'<\?php echo esc_html__\(\'テキストベースの学習データの登録と検索を行います。\', \'fourier\'\); \?>',
    "<?php echo esc_html__('AIを使った自動データ登録を行います。', 'fourier'); ?>",
    ai_content
)

# Remove Search Section
ai_content = re.sub(
    r'<!-- 検索セクション -->.*?<!-- 登録セクション -->',
    '<!-- 登録セクション -->',
    ai_content,
    flags=re.DOTALL
)

# Remove all tabs except tab-scrape
# Keep the learning-tabs container but only keep tab-scrape
ai_content = re.sub(
    r'<div class="learning-tabs">.*?</div>',
    '''<div class="learning-tabs">
                    <button type="button" class="learning-tab active" data-target="tab-scrape" style="background: var(--accent-subtle, rgba(201,169,110,0.1)); color: var(--accent, #C9A96E); border: 1px solid var(--accent, #C9A96E);">
                        <span class="material-symbols-outlined" style="font-size: 1rem; vertical-align: -2px;">language</span> URL自動取得
                    </button>
                </div>''',
    ai_content,
    flags=re.DOTALL
)

# Remove all tab contents except tab-scrape
# tab contents start with <!-- 1. プレーンテキスト --> and end right before <!-- 8. URLスクレイピング登録 -->
ai_content = re.sub(
    r'<!-- 1\. プレーンテキスト -->.*?<!-- 8\. URLスクレイピング登録 -->',
    '<!-- URLスクレイピング登録 -->',
    ai_content,
    flags=re.DOTALL
)

# Replace 'id="tab-scrape" class="learning-tab-content"' with active class
ai_content = ai_content.replace(
    'id="tab-scrape" class="learning-tab-content"',
    'id="tab-scrape" class="learning-tab-content active"'
)

# Remove #btn-save-data area
ai_content = re.sub(
    r'<div style="text-align: center; margin-top: 2rem;">\s*<input type="hidden" id="edit-post-id" value="" />.*?</div>',
    '<input type="hidden" id="edit-post-id" value="" />',
    ai_content,
    flags=re.DOTALL
)

# Remove Edit Modal area
ai_content = re.sub(
    r'<!-- 編集モーダル -->.*?</div>\s*</div>\s*<\?php endif; \?>',
    '<?php endif; ?>',
    ai_content,
    flags=re.DOTALL
)

# In JS, remove unnecessary logic (save-data, edit modal, search, format tabs other than scrape)
# We can leave the JS as is, it might just have dead code, but let's remove btn-save-data listener and search
ai_content = re.sub(
    r'// データ登録\s*document\.getElementById\(\'btn-save-data\'\)\.addEventListener\(\'click\', function\(\) \{.*?\n        \}\);\s*// URLスクレイピング処理',
    '// URLスクレイピング処理',
    ai_content,
    flags=re.DOTALL
)

ai_content = re.sub(
    r'// 検索処理\s*document\.getElementById\(\'btn-search\'\).*?// ---------------------',
    '// ---------------------',
    ai_content,
    flags=re.DOTALL
)

ai_content = re.sub(
    r'// ChatML 行追加.*?// ShareGPT 行追加.*?// メッセージ表示',
    '// メッセージ表示',
    ai_content,
    flags=re.DOTALL
)

with open('page-ai-registration.php', 'w') as f:
    f.write(ai_content)

# --- Process text-based-learning.php ---
with open('text-based-learning.php', 'r') as f:
    text_content = f.read()

# Remove scrape tab button
text_content = re.sub(
    r'<button type="button" class="learning-tab" data-target="tab-scrape".*?</button>',
    '',
    text_content,
    flags=re.DOTALL
)

# Remove scrape tab content
text_content = re.sub(
    r'<!-- 8\. URLスクレイピング登録 -->\s*<div id="tab-scrape" class="learning-tab-content" data-format="scrape">.*?</div>',
    '',
    text_content,
    flags=re.DOTALL
)

# Remove scrape JS
text_content = re.sub(
    r'// URLスクレイピング処理\s*document\.getElementById\(\'btn-scrape-submit\'\).*?\n        \}\);\s*// 検索処理',
    '// 検索処理',
    text_content,
    flags=re.DOTALL
)

# Also remove the check in tab switching that hides btn-save-data for scrape
text_content = re.sub(
    r'if \(currentFormat === \'scrape\'\) \{.*?\} else \{',
    '',
    text_content,
    flags=re.DOTALL
)
# Fix the dangling closing brace for the removed if
text_content = text_content.replace(
    "                    document.getElementById('btn-save-data').parentElement.style.display = 'block';\n                }",
    "                    document.getElementById('btn-save-data').parentElement.style.display = 'block';"
)

with open('text-based-learning.php', 'w') as f:
    f.write(text_content)

print("Done")
