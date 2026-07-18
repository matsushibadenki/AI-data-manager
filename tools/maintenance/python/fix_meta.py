import re
files_to_fix = [
    '/Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/inc/functions_llm_api.php',
    '/Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/inc/functions_rest_api.php'
]

for filepath in files_to_fix:
    with open(filepath, 'r') as f:
        content = f.read()

    # We want to replace update_post_meta(..., 'learning_data_...', ...)
    # with update_post_meta(..., 'learning_...', ...)
    content = re.sub(r"'learning_data_'", r"'learning_'", content)
    content = re.sub(r"'learning_data_", r"'learning_", content)
    
    # We should NOT touch 'learning_data_action' nonce strings!
    content = re.sub(r"'learning_action'", r"'learning_data_action'", content)
    
    with open(filepath, 'w') as f:
        f.write(content)

print("Meta keys fixed.")
