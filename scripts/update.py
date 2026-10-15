#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""質問主意書アシストの毎日の更新（kdeck ゴール kshuisho-update → kurage_web/backend/kshuisho_jobs.py）。

**なぜ要るか。** 2026-09-23 に作ったきり更新しておらず、第222回国会（10月〜）の質問主意書が1件も入っていなかった
（2026-10-15 に気づいた。蓮舫議員へ紹介する直前）。

  /usr/bin/python3 scripts/update.py

1. 衆参の一覧を、いま手元にある最新の会期の前後（N-1〜N+1）だけ取り直す（新しい会期が始まれば N+1 で拾う）。
   答弁があとから届いた質問の経過・明細も、ここで取り直す（fetch_*.py 側で対応）
2. 公開している範囲（全会期・第1回〜）で SQLite を作り直す
3. 件数・答弁数・本文の長さが前回と同じなら、ここで終わる（配置しない）
4. 変わっていれば「似た質問主意書」を増えた分だけ計算し（systemd-run でメモリ上限つき）、heteml に1接続で置き、
   公開ページの件数が合うかを確かめる
最後の行に結果を JSON で出す。
"""
from __future__ import annotations

import glob
import json
import os
import re
import sqlite3
import subprocess
import sys
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "php", "kshuisho_data", "kshuisho.sqlite")
RAW = "/mnt/data/kshuisho/raw"
FROM_SESSION = 1   # 2026-10-15 全会期（1947年〜）に広げた
PY = "/usr/bin/python3"
VENV = "/home/kojima/work/kronten/.venv/bin/python"


def stats() -> tuple:
    if not os.path.exists(DB):
        return (0, 0, 0, 0)
    c = sqlite3.connect(DB)
    r = c.execute("SELECT COUNT(*), SUM(a_text<>''), SUM(q_len), SUM(a_len) FROM q").fetchone()
    c.close()
    return tuple(int(x or 0) for x in r)


def run(args, **kw):
    p = subprocess.run(args, cwd=ROOT, capture_output=True, text=True, **kw)
    if p.returncode != 0:
        raise RuntimeError(f"{' '.join(args[:3])} が失敗: {p.stderr[-1500:]}")
    return p.stdout


def main() -> int:
    # 最新の会期＝**一覧のファイルがある**いちばん大きい会期。取得は一覧が無い会期でもフォルダを作るので、
    # フォルダの番号で決めると毎日1つずつずれて、いまの会期を取り直さなくなる（2026-10-15 試運転で 223・224 ができた）
    def has_list(h, d):
        s = int(os.path.basename(d))
        return os.path.exists(os.path.join(d, f"kaiji{s:03d}_l.htm" if h == "shugiin" else "syuisyo.htm"))
    latest = max(int(os.path.basename(d)) for h in ("shugiin", "sangiin")
                 for d in glob.glob(os.path.join(RAW, h, "*")) if os.path.basename(d).isdigit() and has_list(h, d))
    lo, hi = latest - 1, latest + 1
    log = []
    for fetch in ("fetch_shugiin.py", "fetch_sangiin.py"):
        out = run([PY, os.path.join(ROOT, "scripts", fetch), "--from", str(lo), "--to", str(hi)], timeout=3000)
        log += [ln for ln in out.splitlines() if ln.startswith("第")]
    before = stats()
    run([PY, os.path.join(ROOT, "scripts", "build_db.py"), "--from-session", str(FROM_SESSION)], timeout=1800)
    after = stats()
    res = {"sessions": [lo, hi], "fetched": log, "before": before, "after": after}
    if after == before:
        res.update(deployed=False, note="変化なし（配置しない）")
        print(json.dumps(res, ensure_ascii=False))
        return 0
    # 似た質問主意書（埋め込みは /mnt/data/kshuisho/emb に残るので、増えた分だけ計算する）
    run(["systemd-run", "--user", "--wait", "--collect", "-p", "MemoryMax=10G",
         f"--working-directory={ROOT}", VENV, "scripts/build_similar.py"], timeout=3000)
    run([PY, os.path.join(ROOT, "scripts", "deploy.py")], timeout=1800)
    html = urllib.request.urlopen(urllib.request.Request("https://kurage.exbridge.jp/kshuisho.php/",
                                                         headers={"User-Agent": "kshuisho-update/1.0"}), timeout=60).read().decode("utf-8", "replace")
    m = re.search(r"([\d,]+)件", html)
    shown = int(m.group(1).replace(",", "")) if m else -1
    res.update(deployed=True, public_count=shown, verified=shown == after[0])
    print(json.dumps(res, ensure_ascii=False))
    return 0 if shown == after[0] else 1


if __name__ == "__main__":
    sys.exit(main())
