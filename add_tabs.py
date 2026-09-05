import re

filepath = '/Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/page-import-export.php'

with open(filepath, 'r') as f:
    content = f.read()

# 1. Add CSS
css_to_add = """
.learning-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    border-bottom: 2px solid var(--border-subtle, #eee);
    padding-bottom: 0.5rem;
    overflow-x: auto;
}
.learning-tab {
    padding: 0.75rem 1.25rem;
    background: transparent;
    border: none;
    cursor: pointer;
    font-weight: 500;
    color: var(--text-secondary, #666);
    border-radius: var(--radius-md, 4px);
    transition: all 0.2s ease;
    white-space: nowrap;
}
.learning-tab:hover {
    background: var(--bg-surface-hover, #f5f5f5);
}
.learning-tab.active {
    background: var(--bg-surface, #fff);
    color: var(--text-primary, #000);
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    border: 1px solid var(--border-subtle, #eee);
}
.learning-tab-content {
    display: none;
}
.learning-tab-content.active {
    display: block;
}
"""
content = content.replace("<style>", "<style>\n" + css_to_add)

# 2. Add Tabs HTML
tabs_html = """
            <div class="learning-tabs">
                <button type="button" class="learning-tab active" data-target="tab-import">インポート</button>
                <button type="button" class="learning-tab" data-target="tab-export">エクスポート</button>
                <button type="button" class="learning-tab" data-target="tab-external">外部オープンデータセット連携</button>
            </div>

            <!-- インポートセクション -->"""
content = content.replace("            <!-- インポートセクション -->", tabs_html)


# 3. Update section tags
content = content.replace(
    "<!-- インポートセクション -->\n            <section class=\"panel-section\">",
    "<!-- インポートセクション -->\n            <section id=\"tab-import\" class=\"panel-section learning-tab-content active\">"
)

content = content.replace(
    "<!-- オープンデータセット連携セクション -->\n            <section class=\"panel-section\">",
    "<!-- オープンデータセット連携セクション -->\n            <section id=\"tab-external\" class=\"panel-section learning-tab-content\">"
)

content = content.replace(
    "<!-- エクスポートセクション -->\n            <section class=\"panel-section\">",
    "<!-- エクスポートセクション -->\n            <section id=\"tab-export\" class=\"panel-section learning-tab-content\">"
)

# 4. Add JS
js_to_add = """<script>
    document.addEventListener('DOMContentLoaded', function() {
        // タブ切り替え処理
        const tabs = document.querySelectorAll('.learning-tab');
        const contents = document.querySelectorAll('.learning-tab-content');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', function() {
                // Remove active from all tabs and contents
                tabs.forEach(t => t.classList.remove('active'));
                contents.forEach(c => c.classList.remove('active'));
                
                // Add active to clicked tab and corresponding content
                this.classList.add('active');
                const targetId = this.getAttribute('data-target');
                document.getElementById(targetId).classList.add('active');
            });
        });
"""
content = content.replace("<script>\n    document.addEventListener('DOMContentLoaded', function() {", js_to_add)

with open(filepath, 'w') as f:
    f.write(content)

print("Tabs added successfully.")
