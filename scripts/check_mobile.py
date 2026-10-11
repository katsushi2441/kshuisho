#!/usr/bin/env python3
"""公開ページをスマホ幅（320・390）で開き、横にはみ出していないかを scrollWidth で実測する。
言い換えの枠（JS で後から読む）を待ち、--draft のときは「下書きを作る」を押して出来上がりまで待ってから測る。

  /usr/bin/python3 scripts/check_mobile.py [--draft] URL...
（新しいブラウザ（空のプロファイル）で開く。browser_agent/chrome-profile は使わない）
"""
import os
import re
import sys

from playwright.sync_api import sync_playwright

OUT = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "outputs", "mobile")
DRAFT = "--draft" in sys.argv
urls = [a for a in sys.argv[1:] if not a.startswith("--")]
os.makedirs(OUT, exist_ok=True)
bad = 0
with sync_playwright() as p:
    b = p.chromium.launch()
    for url in urls:
        for w in (320, 390):
            pg = b.new_page(viewport={"width": w, "height": 800}, device_scale_factor=1)
            pg.goto(url, wait_until="networkidle", timeout=90000)
            pg.wait_for_function("!document.getElementById('alt') || !/探しています/.test(document.getElementById('alt').textContent)", timeout=30000)
            note = ""
            if DRAFT and pg.query_selector("#mkdraft"):
                pg.click("#mkdraft")
                pg.wait_for_function("document.getElementById('draftout').textContent.length > 0", timeout=240000)
                note = pg.inner_text("#draftout")[:60].replace("\n", " ")
            alt = pg.evaluate("(document.querySelector('#alt h2')||{}).textContent||''")
            sw = pg.evaluate("document.documentElement.scrollWidth")
            # はみ出している要素を探す
            wide = pg.evaluate(f"""Array.from(document.querySelectorAll('body *')).filter(e=>e.getBoundingClientRect().right>{w}+1 && getComputedStyle(e).position!=='fixed').slice(0,3).map(e=>e.tagName+'.'+e.className)""")
            name = re.sub(r"[^A-Za-z0-9]+", "_", url.split("kshuisho.php")[-1])[:60] or "top"
            f = os.path.join(OUT, f"{name}_{w}.png")
            pg.screenshot(path=f, full_page=True)
            ok = sw <= w
            bad += not ok
            print(f"{'ok' if ok else 'はみ出し'} {w}px scrollWidth={sw} {url} {('言い換え: ' + alt) if alt else ''} {('下書き: ' + note) if note else ''} {wide if not ok else ''}")
            pg.close()
    b.close()
sys.exit(1 if bad else 0)
