with open('inc/functions_llm_api.php', 'r') as f:
    content = f.read()

content = content.replace("'timeout' => 120, // ローカルは時間がかかる場合がある", "'timeout' => 300, // ローカルは時間がかかる場合があるため長めに設定")

with open('inc/functions_llm_api.php', 'w') as f:
    f.write(content)
