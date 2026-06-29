import re

filepath = '/Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/inc/functions_llm_api.php'
with open(filepath, 'r') as f:
    content = f.read()

# 1. Scrape URL
content = content.replace(
    "update_post_meta($post_id, 'is_learning_data', '1');\n    $meta_fields =",
    "update_post_meta($post_id, 'is_learning_data', '1');\n    update_post_meta($post_id, 'learning_format', $target_format);\n    $meta_fields ="
)

# 2. Distill from seed
content = content.replace(
    "update_post_meta($post_id, 'is_learning_data', '1');\n\n    $meta_fields =",
    "update_post_meta($post_id, 'is_learning_data', '1');\n    update_post_meta($post_id, 'learning_format', $target_format);\n\n    $meta_fields ="
)

# 3. Bot Crawl
# Wait, let's check how bot crawl saves it
content = content.replace(
    "update_post_meta($post_id, 'is_learning_data', '1');\n    update_post_meta($post_id, 'learning_source', $url);",
    "update_post_meta($post_id, 'is_learning_data', '1');\n    update_post_meta($post_id, 'learning_format', 'structured'); // Bot default\n    update_post_meta($post_id, 'learning_source', $url);"
)

with open(filepath, 'w') as f:
    f.write(content)

print("functions_llm_api.php updated")
