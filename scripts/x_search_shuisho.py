#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""X で「質問主意書」に触れている国会議員のポストを集める（読むだけ。投稿しない）。
  /usr/bin/python3 scripts/x_search_shuisho.py "検索語1" "検索語2" ...
起動済み Chrome（CDP 19222・chrome-profile）に新しいタブを開き、検索(最新)を読んで数回スクロールする。
Playwright/browser_use でプロファイルを開かない。結果は outputs/x_shuisho_posts.json に追記（URLで重複排除）。
投稿者の表示名を kshuisho の提出者名（DB）と突き合わせて「国会議員らしい」ものに印を付ける。"""
import json, os, re, sys, time, sqlite3, urllib.parse, urllib.request, websocket
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'outputs', 'x_shuisho_posts.json')
db = sqlite3.connect(os.path.join(ROOT, 'php', 'kshuisho_data', 'kshuisho.sqlite'))
SUBS = {re.sub(r'\s+', '', s): (s, h) for s, h in db.execute('SELECT DISTINCT submitter, house FROM q')}
res = json.load(open(OUT, encoding='utf-8')) if os.path.exists(OUT) else {}
JS = """JSON.stringify([...document.querySelectorAll('article')].map(a=>({
  user:((a.querySelector('[data-testid=User-Name]')||{}).innerText||'').split('\\n').slice(0,2).join(' '),
  time:(a.querySelector('time')||{getAttribute:()=>''}).getAttribute('datetime')||'',
  text:(a.querySelector('[data-testid=tweetText]')||{}).innerText||'',
  url:((a.querySelector('a[href*="/status/"]')||{}).href||'')})))"""
def match_giin(user):
    u = re.sub(r'[\s　]', '', user)
    for key, (name, house) in SUBS.items():
        if key and key in u: return name, house
    return None
t = json.load(urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:19222/json/new?about:blank', method='PUT'), timeout=15))
ws = websocket.create_connection(t['webSocketDebuggerUrl'], timeout=90, suppress_origin=True); n = [0]
def call(m, p=None):
    n[0] += 1; ws.send(json.dumps({'id': n[0], 'method': m, 'params': p or {}}))
    while True:
        r = json.loads(ws.recv())
        if r.get('id') == n[0]: return r
def ev(e): return call('Runtime.evaluate', {'expression': e, 'returnByValue': True}).get('result', {}).get('result', {}).get('value')
for q in sys.argv[1:]:
    call('Page.navigate', {'url': 'https://x.com/search?q=' + urllib.parse.quote(q) + '&src=typed_query&f=live'})
    for i in range(40):
        time.sleep(0.5)
        if ev("document.querySelectorAll('article').length>0"): break
    time.sleep(3); got = 0
    for k in range(6):
        for p in json.loads(ev(JS) or '[]'):
            if not p['text'] or not p['url']: continue
            u = p['url'].split('?')[0]
            if u in res: continue
            g = match_giin(p['user'])
            res[u] = {'q': q, 'user': p['user'], 'time': p['time'], 'text': p['text'], 'giin': g[0] if g else '', 'house': g[1] if g else ''}
            got += 1
        ev("window.scrollBy(0, 2500)"); time.sleep(2.5)
    print(f'{q!r}: 新規 {got}件', flush=True)
ws.close(); urllib.request.urlopen(f"http://127.0.0.1:19222/json/close/{t['id']}", timeout=10)
json.dump(res, open(OUT, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
print('合計', len(res), '件 →', OUT)
