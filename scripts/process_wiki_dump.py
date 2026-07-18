import sys
import os
import urllib.request
import bz2
import gzip
import json
import re
import xml.etree.ElementTree as ET
from datetime import datetime

def update_status(status_file, state, progress, message, current_file=None):
    data = {
        "state": state,
        "progress": progress,
        "message": message,
        "current_file": current_file,
        "updated_at": datetime.now().isoformat()
    }
    with open(status_file, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False)

def clean_wikitext(text):
    if not text:
        return ""
    # Remove HTML comments
    text = re.sub(r'<!--.*?-->', '', text, flags=re.DOTALL)
    # Remove {{...}} templates (nested up to 2 levels)
    text = re.sub(r'\{\{[^{}]*?\}\}', '', text)
    text = re.sub(r'\{\{[^{}]*?\}\}', '', text)
    # Remove [[File:...]] and [[Image:...]]
    text = re.sub(r'\[\[(?:File|Image|ファイル|画像):.*?\]\]', '', text, flags=re.IGNORECASE)
    # Extract text from simple links [[Link|Text]] -> Text or [[Link]] -> Link
    text = re.sub(r'\[\[[^|\]]+\|([^\]]+)\]\]', r'\1', text)
    text = re.sub(r'\[\[([^\]]+)\]\]', r'\1', text)
    # Remove URLs
    text = re.sub(r'https?://[^\s]+', '', text)
    # Remove tables {| ... |}
    text = re.sub(r'\{\|.*?\|\}', '', text, flags=re.DOTALL)
    # Remove bold/italic ''' or ''
    text = re.sub(r"'''?", "", text)
    # Remove headers == Header ==
    text = re.sub(r'==+\s*(.*?)\s*==+', r'\1', text)
    # Remove references <ref>...</ref>
    text = re.sub(r'<ref.*?>.*?</ref>', '', text, flags=re.DOTALL)
    text = re.sub(r'<ref.*?/>', '', text)
    # Basic cleanup
    text = re.sub(r'\n{3,}', '\n\n', text)
    return text.strip()

def process_dump(url, output_dir, chunk_size, status_file):
    os.makedirs(output_dir, exist_ok=True)
    update_status(status_file, "downloading", 0, "ダウンロード準備中...")

    # Download file
    filename = url.split('/')[-1]
    filepath = os.path.join(output_dir, filename)
    
    if not os.path.exists(filepath):
        update_status(status_file, "downloading", 0, f"{filename} をダウンロード中...")
        try:
            req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
            with urllib.request.urlopen(req) as response, open(filepath, 'wb') as out_file:
                data = response.read()
                out_file.write(data)
        except Exception as e:
            update_status(status_file, "error", 0, f"ダウンロード失敗: {str(e)}")
            return

    update_status(status_file, "processing", 0, "ダンプファイルの解析中...")

    # Open file
    if filepath.endswith('.bz2'):
        f = bz2.open(filepath, 'rt', encoding='utf-8')
    elif filepath.endswith('.gz'):
        f = gzip.open(filepath, 'rt', encoding='utf-8')
    else:
        f = open(filepath, 'rt', encoding='utf-8')

    context = ET.iterparse(f, events=('end',))
    
    current_chunk = []
    chunk_index = 1
    total_processed = 0

    namespace = ''

    for event, elem in context:
        if '}' in elem.tag:
            namespace = elem.tag.split('}')[0] + '}'
            
        if elem.tag == f'{namespace}page' or elem.tag == 'doc':
            title_elem = elem.find(f'{namespace}title') if namespace else elem.find('title')
            
            # Check for text or abstract
            text_elem = None
            if namespace:
                revision = elem.find(f'{namespace}revision')
                if revision is not None:
                    text_elem = revision.find(f'{namespace}text')
            else:
                text_elem = elem.find('abstract') or elem.find('text')

            title = title_elem.text if title_elem is not None else ''
            raw_text = text_elem.text if text_elem is not None else ''

            if title and raw_text and not title.startswith('Wikipedia:') and not title.startswith('File:'):
                clean_text = clean_wikitext(raw_text)
                if len(clean_text) > 50: # Skip very short/empty articles
                    import datetime
                    import urllib.parse
                    source_url = f"https://ja.wikipedia.org/wiki/{urllib.parse.quote(title)}"
                    imported_at = datetime.datetime.now().isoformat()
                    current_chunk.append({
                        "title": title,
                        "text": clean_text,
                        "source_url": source_url,
                        "imported_at": imported_at
                    })
                    total_processed += 1

            # Clear element to save memory
            elem.clear()

            if len(current_chunk) >= chunk_size:
                out_file = os.path.join(output_dir, f"wiki_dataset_part_{chunk_index}.json")
                with open(out_file, 'w', encoding='utf-8') as out_f:
                    json.dump(current_chunk, out_f, ensure_ascii=False, indent=2)
                
                update_status(status_file, "processing", total_processed, f"{total_processed}件処理完了...", current_file=out_file)
                current_chunk = []
                chunk_index += 1

    # Save remaining
    if current_chunk:
        out_file = os.path.join(output_dir, f"wiki_dataset_part_{chunk_index}.json")
        with open(out_file, 'w', encoding='utf-8') as out_f:
            json.dump(current_chunk, out_f, ensure_ascii=False, indent=2)
        total_processed += len(current_chunk)

    f.close()
    
    # Optionally remove the downloaded dump to save space
    if os.path.exists(filepath):
        os.remove(filepath)

    update_status(status_file, "completed", total_processed, "処理が完了しました。")

if __name__ == "__main__":
    if len(sys.argv) < 5:
        print("Usage: python process_wiki_dump.py <url> <output_dir> <chunk_size> <status_file>")
        sys.exit(1)
        
    url = sys.argv[1]
    output_dir = sys.argv[2]
    chunk_size = int(sys.argv[3])
    status_file = sys.argv[4]
    
    try:
        process_dump(url, output_dir, chunk_size, status_file)
    except Exception as e:
        update_status(status_file, "error", 0, f"エラーが発生しました: {str(e)}")
