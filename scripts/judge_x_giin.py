#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""集めたXポストの投稿者が国会議員かどうかを、後から本人プロフィールで判定する。
名簿で先に絞らない（絞ると見つからない）。api.fxtwitter.com/<handle> の表示名と自己紹介だけを見る。
  /usr/bin/python3 scripts/judge_x_giin.py
outputs/x_shuisho_posts.json を読み、投稿者ごとに1回だけプロフィールを引いて outputs/x_profiles.json に貯める。"""
import json, os, re, time, urllib.request
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
POSTS = os.path.join(ROOT, 'outputs', 'x_shuisho_posts.json')
PROF = os.path.join(ROOT, 'outputs', 'x_profiles.json')
# 国会議員: 衆参の議員であることが本人の名乗りに出ているか
KOKKAI = re.compile(r'衆議院議員|参議院議員|衆院議員|参院議員|国会議員|衆議院\s*議員|参議院\s*議員')
MOTO   = re.compile(r'前衆議院議員|前参議院議員|元衆議院議員|元参議院議員|前議員|元議員|落選')
CHIHOU = re.compile(r'市議|区議|町議|村議|県議|都議|府議|道議|市議会議員|区議会議員|県議会議員|都議会議員|市長|区長|知事')
CAND   = re.compile(r'候補|支部長|予定者')
def prof(h, cache):
    if h in cache: return cache[h]
    try:
        d = json.load(urllib.request.urlopen(urllib.request.Request(f'https://api.fxtwitter.com/{h}', headers={'User-Agent': 'Mozilla/5.0'}), timeout=30))
        u = (d.get('user') or {}) if d.get('code') == 200 else {}
    except Exception: u = {}
    cache[h] = {'name': u.get('name', ''), 'desc': (u.get('description') or '').replace('\n', ' '), 'followers': u.get('followers'), 'url': u.get('website', {}).get('url') if isinstance(u.get('website'), dict) else ''}
    time.sleep(0.6); return cache[h]
def kind(p):
    t = (p['name'] or '') + ' ' + (p['desc'] or '')
    if KOKKAI.search(t) and not MOTO.search(t): return '現職の国会議員'
    if MOTO.search(t) and re.search(r'衆議院|参議院|衆院|参院', t): return '前職・元職の国会議員'
    if CHIHOU.search(t): return '地方議員・首長'
    if CAND.search(t) and re.search(r'衆議院|参議院|選挙区|支部長', t): return '候補者・支部長'
    return ''
posts = json.load(open(POSTS, encoding='utf-8'))
cache = json.load(open(PROF, encoding='utf-8')) if os.path.exists(PROF) else {}
handles = sorted({v['screen_name'] for v in posts.values()})
print(f'投稿者 {len(handles)}人 のプロフィールを引く')
for i, h in enumerate(handles, 1):
    prof(h, cache)
    if i % 30 == 0: print(f'  {i}/{len(handles)}', flush=True)
json.dump(cache, open(PROF, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
res = {}
for u, v in posts.items():
    p = cache.get(v['screen_name'], {}); k = kind(p)
    if k: res.setdefault(k, []).append({'handle': v['screen_name'], 'name': p.get('name'), 'desc': p.get('desc', '')[:70], 'followers': p.get('followers'), 'time': v['time'], 'text': v['text'], 'url': u})
for k in ('現職の国会議員', '前職・元職の国会議員', '候補者・支部長', '地方議員・首長'):
    vs = res.get(k, [])
    print(f'\n===== {k} {len(vs)}件 =====')
    seen = set()
    for x in sorted(vs, key=lambda x: str(x['time']), reverse=True):
        import datetime
        t = datetime.datetime.fromtimestamp(int(x['time'])).strftime('%m-%d') if str(x['time']).isdigit() else '?'
        print(f"  {t} @{x['handle']:18} {(x['name'] or '')[:24]:24} {x['url']}")
        if x['handle'] not in seen: print(f"      {x['desc']}"); seen.add(x['handle'])
        print(f"      {re.sub(chr(10),' ',x['text'])[:110]}")
json.dump(res, open(os.path.join(ROOT, 'outputs', 'x_shuisho_by_kind.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
