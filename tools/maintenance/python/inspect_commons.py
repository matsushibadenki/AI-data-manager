import urllib.request
import gzip
import json

url = "https://dumps.wikimedia.org/other/wikibase/commonswiki/latest-mediainfo.json.gz"
req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
with urllib.request.urlopen(req) as response:
    with gzip.GzipFile(fileobj=response) as f:
        print(f.readline().decode('utf-8'))
        print(f.readline().decode('utf-8'))
        print(f.readline().decode('utf-8'))
