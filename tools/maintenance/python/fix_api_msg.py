with open('page-api-settings.php', 'r') as f:
    content = f.read()

target_msg = "resultSpan.textContent = data.data.message;"
replace_msg = "resultSpan.textContent = data.data.message + '（※画面下部の「設定を保存」を押して確定してください）';"

content = content.replace(target_msg, replace_msg)

with open('page-api-settings.php', 'w') as f:
    f.write(content)
