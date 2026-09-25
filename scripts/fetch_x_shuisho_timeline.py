#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""国会議員のXタイムラインを、埋め込みウィジェットと同じ公開ページ（ログイン不要・キー不要）から読み、
「質問主意書」「主意書」「答弁書」を含む投稿だけ集める。giin/scripts/fetch_x.py と同じ経路・同じ間合い。
  /usr/bin/python3 scripts/fetch_x_shuisho_timeline.py
- 同じURLでも urllib だと 429、curl だと 200（2026-09-14 実測）。IP単位で締まるので1人ごとに20秒あけ、429は30/60/90秒待つ。
- 1人あたり直近20件ほどしか返らない。
結果: outputs/x_giin_timeline_hits.json（本文・日付・URL）。全投稿は outputs/x_giin_timeline_all.json。"""
import json, os, re, subprocess, time
from datetime import datetime
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
H = json.load(open(os.path.join(ROOT, 'data', 'x_handles.json'), encoding='utf-8'))
EXTRA = {'日野 紗里亜': 'saria_hino', '伊藤 孝恵': 'itotakae0630', '福田 徹': 'Toru_Fukuta', '水野 孝一': 'mizuno_koichi',
         '古川 元久': 'Fullgen', '河村 たかし': 'kawamura758', '須田 英太郎': 'Btaros'}
targets = {n: v['handle'] for n, v in H.items() if n != '浜田 聡'} | {n: h for n, h in EXTRA.items() if n not in H}
BASE = 'https://syndication.twitter.com/srv/timeline-profile/screen-name/'
KW = re.compile(r'質問主意書|主意書|答弁書')
def timeline(name, tries=4):
    cmd = ['curl', '-sS', '--compressed', '--max-time', '40', '-A', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
           '-H', 'Accept-Language: ja,en;q=0.9', '-H', 'Referer: https://platform.twitter.com/', '-w', '\n%{http_code}', BASE + name]
    for i in range(tries):
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=60)
        body, _, code = (r.stdout or '').rpartition('\n')
        if code.strip() == '200' and body: break
        if i == tries - 1: raise RuntimeError(f'HTTP {code.strip() or "?"}')
        w = 30 * (i + 1); print(f'    HTTP {code.strip()}。{w}秒待つ', flush=True); time.sleep(w)
    m = re.search(r'<script id="__NEXT_DATA__" type="application/json">(.*?)</script>', body, re.S)
    if not m: return []
    ents = ((json.loads(m.group(1)).get('props', {}).get('pageProps', {}).get('timeline', {}) or {}).get('entries') or [])
    out = []
    for e in ents:
        t = (e.get('content') or {}).get('tweet') or {}
        pid, text = t.get('id_str'), (t.get('full_text') or t.get('text') or '')
        if not pid or not text: continue
        try: posted = datetime.strptime(t.get('created_at', ''), '%a %b %d %H:%M:%S %z %Y').astimezone().strftime('%Y-%m-%d %H:%M')
        except Exception: posted = ''
        out.append({'post_id': pid, 'body': re.sub(r'\s+', ' ', text).strip(), 'posted': posted, 'is_repost': text.startswith('RT @')})
    return out
allp, hits = {}, []
for name, h in targets.items():
    try: posts = timeline(h)
    except Exception as e: print(f'  ! {name} @{h}: {e}', flush=True); time.sleep(20); continue
    allp[name] = {'handle': h, 'posts': posts}
    for p in posts:
        if KW.search(p['body']):
            hits.append({'name': name, 'handle': h, 'posted': p['posted'], 'repost': p['is_repost'], 'body': p['body'], 'url': f'https://x.com/{h}/status/{p["post_id"]}'})
    n_hit = sum(1 for p in posts if KW.search(p['body']))
    print(f'  {name:10} @{h:16} {len(posts):>3}件  主意書 {n_hit}', flush=True)
    time.sleep(20)
os.makedirs(os.path.join(ROOT, 'outputs'), exist_ok=True)
json.dump(allp, open(os.path.join(ROOT, 'outputs', 'x_giin_timeline_all.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
json.dump(hits, open(os.path.join(ROOT, 'outputs', 'x_giin_timeline_hits.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
print(f'\n{len(allp)}人 / 主意書に触れた投稿 {len(hits)}件 → outputs/x_giin_timeline_hits.json')
