#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Yahoo!リアルタイム検索（ログイン不要・curlで読める）で「質問主意書」に触れたXのポストを集め、
国会議員らしいものを選り分ける。X本体はログインが要るので使わない（2026-09-23 実測: 共有Chromeもログアウト）。
  /usr/bin/python3 scripts/yahoo_rt_shuisho.py "質問主意書" "質問主意書 提出" ...
結果: outputs/x_shuisho_posts.json（ポストURLで重複排除・追記）。"""
import json, os, re, sys, time, sqlite3, urllib.parse, urllib.request
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'outputs', 'x_shuisho_posts.json')
UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36'
db = sqlite3.connect(os.path.join(ROOT, 'php', 'kshuisho_data', 'kshuisho.sqlite'))
SUBS = {re.sub(r'[\s　]', '', s): (s, h) for s, h in db.execute('SELECT DISTINCT submitter, house FROM q') if s}
GIIN_WORDS = ('衆議院議員', '参議院議員', '衆院議員', '参院議員', '国会議員')
res = json.load(open(OUT, encoding='utf-8')) if os.path.exists(OUT) else {}

def walk(o, acc):
    if isinstance(o, dict):
        if 'screenName' in o and ('text' in o or 'displayText' in o): acc.append(o)
        for v in o.values(): walk(v, acc)
    elif isinstance(o, list):
        for v in o: walk(v, acc)

def fetch(q, b=1):
    u = 'https://search.yahoo.co.jp/realtime/search?' + urllib.parse.urlencode({'p': q, 'ei': 'UTF-8', 'b': b})
    h = urllib.request.urlopen(urllib.request.Request(u, headers={'User-Agent': UA}), timeout=40).read().decode('utf-8', 'replace')
    m = re.search(r'<script id="__NEXT_DATA__"[^>]*>(.*?)</script>', h, re.S) or re.search(r'<script[^>]*>(\{"props":.*?)</script>', h, re.S)
    if not m: return []
    acc = []; walk(json.loads(m.group(1)), acc); return acc

def giin_of(name):
    n = re.sub(r'[\s　]', '', name or '')
    for key, (sub, house) in SUBS.items():
        if len(key) >= 3 and key in n: return sub, house
    return None

total_new = 0
for q in sys.argv[1:]:
    got = 0
    for b in ((1,) if os.environ.get("PAGES") == "1" else (1, 11, 21, 31)):
        try: items = fetch(q, b)
        except Exception as e: print('  取得失敗', q, b, e); break
        if not items: break
        for t in items:
            sn = t.get('screenName') or ''; tid = str(t.get('id') or t.get('tweetId') or '')
            if not sn or not tid: continue
            url = f'https://x.com/{sn}/status/{tid}'
            if url in res: continue
            name = t.get('name') or t.get('displayName') or ''
            text = t.get('text') or t.get('displayText') or ''
            g = giin_of(name)
            res[url] = {'q': q, 'screen_name': sn, 'name': name, 'time': t.get('time') or t.get('createdAt') or '', 'text': text,
                        'giin': g[0] if g else '', 'house': g[1] if g else '', 'giin_word': any(w in name for w in GIIN_WORDS),
                        'like': t.get('likeCount') or t.get('favoriteCount') or 0, 'rt': t.get('retweetCount') or 0}
            got += 1
        time.sleep(1.2)
    total_new += got; print(f'{q!r}: 新規 {got}件', flush=True)
json.dump(res, open(OUT, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
print('合計', len(res), '件（新規', total_new, '）→', OUT)
