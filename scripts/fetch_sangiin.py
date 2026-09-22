#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""参議院「質問主意書」から、会期ごとの一覧・明細（日付）・質問本文・答弁本文を落とす。

  /usr/bin/python3 scripts/fetch_sangiin.py --from 200 --to 221
  /usr/bin/python3 scripts/fetch_sangiin.py --session 221

生HTMLは /mnt/data/kshuisho/raw/sangiin/<会期>/ に置く。
一覧 syuisyo/<NNN>/syuisyo.htm ／ 明細 meisai/m<NNN><nnn>.htm ／ 質問 syuh/s<NNN><nnn>.htm ／ 答弁 touh/t<NNN><nnn>.htm。
UTF-8。1リクエストごとに1秒あける。
"""
from __future__ import annotations

import argparse
import os
import re
import sys
import time
import subprocess

BASE = "https://www.sangiin.go.jp/japanese/joho1/kousei/syuisyo/"
RAW = "/mnt/data/kshuisho/raw/sangiin"
# 参議院は素の識別UAだと 502 を返す（WAF）。ブラウザ型の先頭に識別子を足す
# 参議院の WAF は UA に製品名が入ると 502 を返す（2026-09-23 実測。curl でも同じ）。素の UA で、1秒あけて取る
UA = "Mozilla/5.0 (X11; Linux x86_64)"
WAIT = 1.0


def get(url: str) -> str | None:
    """参議院は urllib だと（UAを変えても）502 を返し、curl だと 200 が返る（TLS の指紋で弾かれている模様。2026-09-23 実測）。
    なので curl で取る。404 は None、5xx は少し待って取り直す。"""
    for i in range(4):
        r = subprocess.run(["curl", "-s", "-m", "60", "-A", UA, "-w", "\n%{http_code}", url], capture_output=True)
        out = r.stdout.decode("utf-8", "replace")
        body, _, code = out.rpartition("\n")
        time.sleep(WAIT)
        if code == "200":
            return body
        if code == "404":
            return None
        if i < 3:
            time.sleep(5 * (i + 1))
    raise RuntimeError(f"{url}: HTTP {code}")


def fetch_file(session: int, rel: str, force: bool = False) -> str | None:
    d = os.path.join(RAW, str(session)); os.makedirs(d, exist_ok=True)
    p = os.path.join(d, os.path.basename(rel))
    if os.path.exists(p) and not force:
        return open(p, encoding="utf-8").read()
    s = get(BASE + f"{session}/" + rel)
    if s is None:
        return None
    open(p, "w", encoding="utf-8").write(s)
    return s


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--from", dest="s_from", type=int)
    ap.add_argument("--to", dest="s_to", type=int)
    ap.add_argument("--session", type=int)
    a = ap.parse_args()
    sessions = [a.session] if a.session else list(range(a.s_from, a.s_to + 1))
    total = 0
    for s in sessions:
        lst = fetch_file(s, "syuisyo.htm", force=True)
        if not lst:
            print(f"第{s}回: 一覧なし"); continue
        nos = sorted({int(m) for m in re.findall(r'meisai/m%d(\d{3})\.htm' % s, lst)})
        for n in nos:
            base = f"{s}{n:03d}"
            m = fetch_file(s, f"meisai/m{base}.htm")
            if not m:
                continue
            fetch_file(s, f"syuh/s{base}.htm")
            if re.search(r'touh/t%s\.htm' % base, m):
                fetch_file(s, f"touh/t{base}.htm")
        total += len(nos)
        print(f"第{s}回: {len(nos)}件", flush=True)
    print(f"→ {total}件 / raw={RAW}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
