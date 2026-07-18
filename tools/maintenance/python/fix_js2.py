with open('page-ai-registration.php', 'r') as f:
    content = f.read()

target_js = '''            if (!message.includes('数分かかる場合があります')) {
                window.statusTimeout = setTimeout(() => {
                    statusDiv.style.display = 'none';
                }, 5000);
            }'''

replace_js = '''            if (!message.includes('数分かかる場合があります') && !isError) {
                window.statusTimeout = setTimeout(() => {
                    statusDiv.style.display = 'none';
                }, 5000);
            }'''

content = content.replace(target_js, replace_js)

with open('page-ai-registration.php', 'w') as f:
    f.write(content)
