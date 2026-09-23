#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""衆議院「質問答弁情報」から、会期ごとの質問主意書の一覧・経過・質問本文・答弁本文を落とす。

  /usr/bin/python3 scripts/fetch_shugiin.py --from 1 --to 221
  /usr/bin/python3 scripts/fetch_shugiin.py --session 221        # 1会期だけ

生HTMLは /mnt/data/kshuisho/raw/shugiin/<会期>/ に置く（取り直さない。落としたものは捨てない）。
一覧 kaiji<NNN>_l.htm ／ 経過 <NNN><nnn>.htm ／ 質問本文 a<NNN><nnn>.htm ／ 答弁本文 b<NNN><nnn>.htm。
**会期は必ず3桁ゼロ詰め**（kaiji001_l.htm。kaiji1_l.htm は404）。

**衆議院はデータベースが2つに分かれている（2026-09-23 実測）**:
  第148回以降 … itdb_shitsumon.nsf  （BASE_NEW）
  第147回以前 … itdb_shitsumona.nsf （BASE_OLD。第1回=1947年から本文HTMLがある）
ファイル名の付け方は両方まったく同じ。間違えると404になる。
文字コードは Shift_JIS。1リクエストごとに1秒あける。
既に落とした会期でも、一覧に「答弁受理」になった新しい質問があれば、その分だけ落とす。
"""
from __future__ import annotations

import argparse
import os
import re
import sys
import time
import urllib.error
import urllib.request

BASE_NEW = "https://www.shugiin.go.jp/internet/itdb_shitsumon.nsf/html/shitsumon/"
BASE_OLD = "https://www.shugiin.go.jp/internet/itdb_shitsumona.nsf/html/shitsumon/"
OLD_MAX = 147  # この会期までが itdb_shitsumona.nsf


def base_for(session: int) -> str:
    return BASE_OLD if session <= OLD_MAX else BASE_NEW
RAW = "/mnt/data/kshuisho/raw/shugiin"
UA = "kshuisho/1.0 (+https://kurage.exbridge.jp/kshuisho.php/; contact info@exbridge.jp)"
WAIT = 1.0


def get(url: str) -> str:
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    for i in range(4):  # 5xx が散発したら少し待って取り直す
        try:
            with urllib.request.urlopen(req, timeout=60) as r:
                b = r.read()
            time.sleep(WAIT)
            return b.decode("shift_jis", "replace")
        except urllib.error.HTTPError as e:
            if e.code >= 500 and i < 3:
                time.sleep(5 * (i + 1)); continue
            raise


def fetch_file(session: int, name: str, force: bool = False) -> str | None:
    d = os.path.join(RAW, str(session)); os.makedirs(d, exist_ok=True)
    p = os.path.join(d, name)
    if os.path.exists(p) and not force:
        return open(p, encoding="utf-8").read()
    try:
        s = get(base_for(session) + name)
    except urllib.error.HTTPError as e:
        if e.code == 404:
            return None
        raise
    open(p, "w", encoding="utf-8").write(s)
    return s


def parse_list(html: str) -> list[dict]:
    """一覧の行 → {no, title, submitter, status}。列は 番号/件名/提出者/経過状況/経過/質問HTML/質問PDF/答弁HTML/答弁PDF。"""
    rows = []
    for tr in re.findall(r"(?is)<tr[^>]*>(.*?)</tr>", html):
        tds = [re.sub(r"(?is)<[^>]+>", "", t).strip() for t in re.findall(r"(?is)<td[^>]*>(.*?)</td>", tr)]
        if len(tds) < 4 or not tds[0].isdigit():
            continue
        rows.append({"no": int(tds[0]), "title": tds[1], "submitter": tds[2].rstrip("君"), "status": tds[3],
                     "has_answer": re.search(r'href=["\x27]?b\d{6}\.htm', tr, re.I) is not None})
    return rows


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--from", dest="s_from", type=int)
    ap.add_argument("--to", dest="s_to", type=int)
    ap.add_argument("--session", type=int)
    a = ap.parse_args()
    sessions = [a.session] if a.session else list(range(a.s_from, a.s_to + 1))
    total = 0
    for s in sessions:
        lst = fetch_file(s, f"kaiji{s:03d}_l.htm", force=True)   # 一覧は毎回取り直す（答弁が増える）
        if not lst:
            print(f"第{s}回: 一覧なし"); continue
        rows = parse_list(lst)
        got = 0
        for r in rows:
            base = f"{s:03d}{r['no']:03d}"
            fetch_file(s, f"{base}.htm")        # 経過
            fetch_file(s, f"a{base}.htm")       # 質問本文
            if r["has_answer"]:
                fetch_file(s, f"b{base}.htm")   # 答弁本文（無ければ次回）
            got += 1
        total += got
        print(f"第{s}回: {len(rows)}件（答弁あり {sum(1 for r in rows if r['has_answer'])}）", flush=True)
    print(f"→ {total}件 / raw={RAW}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
