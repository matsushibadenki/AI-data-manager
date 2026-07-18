import re

file_path = '/Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/text-based-learning.php'

with open(file_path, 'r') as f:
    content = f.read()

helper_func = """                // フィールド動的生成
                function extractDataVal(obj, key) {
                    if (obj && typeof obj[key] !== 'undefined') {
                        return typeof obj[key] === 'string' ? obj[key] : JSON.stringify(obj[key]);
                    }
                    if (Array.isArray(obj) && obj.length > 0 && typeof obj[0][key] !== 'undefined') {
                        return obj.map(item => {
                            return typeof item[key] === 'string' ? item[key] : JSON.stringify(item[key]);
                        }).join('\\n\\n---\\n\\n');
                    }
                    return '';
                }
                var container = document.getElementById('edit-fields-container');
"""

content = content.replace("                // フィールド動的生成\n                var container = document.getElementById('edit-fields-container');\n", helper_func)

# Replace data.FIELD || '' with extractDataVal(data, 'FIELD')
pattern = r"data\.([a-zA-Z_]+)\s*\|\|\s*''"
# Wait, data.text || '' -> extractDataVal(data, 'text')
content = re.sub(pattern, r"extractDataVal(data, '\1')", content)

with open(file_path, 'w') as f:
    f.write(content)

print("done")
