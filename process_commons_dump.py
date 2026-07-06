import sys
import os
import json
import gzip
import bz2
import urllib.request
import hashlib
import urllib.parse
from datetime import datetime

# urlパス、ファイル名、内容のコメントを追加するルール対応
# URLパス: /Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/process_commons_dump.py
# ファイル名: process_commons_dump.py
# コードの内容の説明: Wikimedia CommonsのJSONダンプ(mediainfo)をストリーミングで読み込み、画像URLとメタデータを抽出して分割保存するワーカー

def get_image_url(title):
    filename = title.replace('File:', '').replace(' ', '_')
    m = hashlib.md5(filename.encode('utf-8')).hexdigest()
    encoded = urllib.parse.quote(filename)
    return f"https://upload.wikimedia.org/wikipedia/commons/{m[:1]}/{m[:2]}/{encoded}"

def update_status(state, progress, message, error=None):
    status = {
        "state": state,
        "progress": progress,
        "message": message,
        "updated_at": datetime.now().isoformat()
    }
    if error:
        status["error"] = error

    try:
        with open(status_file, 'w', encoding='utf-8') as f:
            json.dump(status, f, ensure_ascii=False)
    except:
        pass

def extract_metadata(item):
    title = item.get('title', '')
    if not title.startswith('File:'):
        return None

    # Get image URL
    image_url = get_image_url(title)

    # Get labels/descriptions
    labels = item.get('labels', {})
    descriptions = item.get('descriptions', {})
    
    # Try to find Japanese or English caption
    caption = ""
    if 'ja' in labels:
        caption = labels['ja'].get('value', '')
    elif 'en' in labels:
        caption = labels['en'].get('value', '')
        
    if not caption:
        if 'ja' in descriptions:
            caption = descriptions['ja'].get('value', '')
        elif 'en' in descriptions:
            caption = descriptions['en'].get('value', '')

    metadata = {
        "title": title,
        "caption": caption
    }
    
    return {
        "image_url": image_url,
        "metadata": metadata
    }

def main():
    global status_file

    if len(sys.argv) < 6:
        print("Usage: python process_commons_dump.py <url> <output_dir> <chunk_size> <status_file> <log_file>")
        sys.exit(1)

    url = sys.argv[1]
    output_dir = sys.argv[2]
    chunk_size = int(sys.argv[3])
    status_file = sys.argv[4]
    log_file = sys.argv[5]

    update_status("running", 0, "ダウンロードと解析を準備中...")

    if not os.path.exists(output_dir):
        os.makedirs(output_dir, exist_ok=True)

    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0 (AI-Data-Manager)'})
        with urllib.request.urlopen(req) as response:
            if url.endswith('.gz'):
                f = gzip.GzipFile(fileobj=response)
            elif url.endswith('.bz2'):
                f = bz2.BZ2File(response)
            else:
                f = response
                
            processed_count = 0
            saved_count = 0
            chunk_index = 1
            current_chunk = []
            
            # Read line by line
            for line in f:
                line_str = line.decode('utf-8').strip()
                
                # Skip first and last array brackets
                if line_str == '[' or line_str == ']':
                    continue
                
                # Remove trailing comma
                if line_str.endswith(','):
                    line_str = line_str[:-1]
                
                if not line_str:
                    continue
                
                try:
                    item = json.loads(line_str)
                    processed_count += 1
                    
                    data = extract_metadata(item)
                    if data:
                        current_chunk.append({
                            "instruction": "次の画像について説明してください。",
                            "output": data['metadata']['caption'] if data['metadata']['caption'] else data['metadata']['title'],
                            "image_url": data['image_url'],
                            "metadata": data['metadata']
                        })
                        saved_count += 1
                        
                        if len(current_chunk) >= chunk_size:
                            chunk_file = os.path.join(output_dir, f"commons_dataset_part_{chunk_index:04d}.json")
                            with open(chunk_file, 'w', encoding='utf-8') as out_f:
                                json.dump(current_chunk, out_f, ensure_ascii=False, indent=2)
                            
                            update_status("running", saved_count, f"チャンク {chunk_index} を保存しました ({saved_count}件保存済 / {processed_count}件処理済)")
                            
                            chunk_index += 1
                            current_chunk = []
                            
                            # Limit for safety in this version (don't process all 74GB unless really needed)
                            if chunk_index > 200: # 2 million max
                                break
                                
                except json.JSONDecodeError:
                    continue

            # Save remaining
            if current_chunk:
                chunk_file = os.path.join(output_dir, f"commons_dataset_part_{chunk_index:04d}.json")
                with open(chunk_file, 'w', encoding='utf-8') as out_f:
                    json.dump(current_chunk, out_f, ensure_ascii=False, indent=2)
                saved_count += len(current_chunk)
                
            update_status("completed", saved_count, f"処理が完了しました（合計: {saved_count} 件保存）")

    except Exception as e:
        import traceback
        update_status("error", 0, "エラーが発生しました", str(e) + "\n" + traceback.format_exc())

if __name__ == "__main__":
    main()
