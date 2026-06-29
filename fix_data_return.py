import re

filepath = '/Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/inc/functions_learning-data.php'
with open(filepath, 'r') as f:
    content = f.read()

# Replace return array in _detect_and_format_import_item
content = re.sub(
    r'return \[\n\s*\'title\' => (.*),\n\s*\'format\' => \$format,\n\s*\'data\' => \$data\n\s*\];',
    r"return [\n        'title' => \1,\n        'format' => $format,\n        'data' => $check_target\n    ];",
    content
)

with open(filepath, 'w') as f:
    f.write(content)

filepath_js = '/Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/page-import-export.php'
with open(filepath_js, 'r') as f:
    content_js = f.read()

# Replace return object in detectFormatLocally
content_js = re.sub(
    r'return \{\n\s*title: raw\.title \|\| \'\',\n\s*format: format,\n\s*data: raw\n\s*\};',
    r"return {\n                title: raw.title || '',\n                format: format,\n                data: checkTarget\n            };",
    content_js
)

with open(filepath_js, 'w') as f:
    f.write(content_js)

print("Fixed data return payload unwrapping.")
