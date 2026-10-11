#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""「似た質問主意書」の表（similar）を作って kshuisho.sqlite に足す。build_db.py の後に流す。

  /home/kojima/work/kronten/.venv/bin/python scripts/build_similar.py
  （fastembed と numpy が入った Python なら何でもよい。pip install fastembed numpy）

やること
  1. 質問主意書1件ごとに「件名＋質問本文の冒頭」を multilingual-e5-large で埋め込む（手元の CPU。外部に送らない）
  2. 内積（正規化済みなのでコサイン類似度）から CSLS でハブを割り引き、各件に近い上位 TOP 件を選ぶ
  3. similar(id, sim_id, rank, score) に入れる

PHP 側はこの表を読むだけで、AI は動かさない。表が無い DB でも画面は動く（「似た質問主意書」が出ないだけ）。
build_db.py は DB を作り直すので、データを更新したら毎回これも流す。
埋め込みは /mnt/data/kshuisho/emb/ に id ごとに残し、本文が変わっていない件は計算し直さない。
"""
from __future__ import annotations

import hashlib
import json
import os
import re
import sqlite3
import sys
import time

import numpy as np

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "php", "kshuisho_data", "kshuisho.sqlite")
if not os.path.exists(DB):   # 配布 ZIP では php/ が無く、kshuisho_data/ が1つ上にある
    DB = os.path.join(ROOT, "kshuisho_data", "kshuisho.sqlite")
EMB_DIR = os.environ.get("KSHUISHO_EMB_DIR", "/mnt/data/kshuisho/emb")
CACHE = os.environ.get("FASTEMBED_CACHE_PATH", "/mnt/data/cache/fastembed")
MODEL = "intfloat/multilingual-e5-large"
TOP = 8
HEAD = 600   # 質問本文の冒頭の字数（e5 は 512 トークンまでしか読まない）


# 件名の末尾の「に関する再質問主意書」などは、どの件にもあるので落とす。残すと「再質問主意書」どうしが
# 論点に関係なく近くなった（2026-10-11 初回: 第212回141号の5〜8位が無関係な再質問主意書だった）
SUFFIX = re.compile(r"(?:に関する|についての|に対する)?(?:第[一二三四五六七八九十0-9]+回|再|再々)?質問主意書$")


def doc(title: str, q_text: str) -> str:
    title = SUFFIX.sub("", (title or "").strip()) or title
    body = " ".join((q_text or "").split())[:HEAD]
    return f"passage: {title}\n{body}"


def main() -> int:
    db = sqlite3.connect(DB)
    rows = db.execute("SELECT id, title, q_text FROM q ORDER BY id").fetchall()
    ids = [r[0] for r in rows]
    texts = [doc(r[1], r[2]) for r in rows]
    keys = [hashlib.sha1(t.encode("utf-8")).hexdigest() for t in texts]

    # 作り置き（本文の hash ごと）
    os.makedirs(EMB_DIR, exist_ok=True)
    store = os.path.join(EMB_DIR, "e5_large.npz")
    old = {}
    if os.path.exists(store):
        z = np.load(store)
        old = dict(zip(z["keys"].tolist(), z["vecs"]))
    todo = [i for i, k in enumerate(keys) if k not in old]
    print(f"{len(rows)}件（埋め込み済み {len(rows) - len(todo)}・これから {len(todo)}）", flush=True)
    if todo:
        from fastembed import TextEmbedding
        m = TextEmbedding(MODEL, cache_dir=CACHE)
        t0 = time.time()
        for n, (i, v) in enumerate(zip(todo, m.embed([texts[i] for i in todo], batch_size=16)), 1):
            old[keys[i]] = np.asarray(v, dtype=np.float32)
            if n % 200 == 0:
                print(f"  {n}/{len(todo)} {time.time() - t0:.0f}秒", flush=True)
        np.savez(store, keys=np.array(list(old.keys())), vecs=np.stack(list(old.values())))
    E = np.stack([old[k] for k in keys]).astype(np.float32)
    E /= np.linalg.norm(E, axis=1, keepdims=True)

    S = E @ E.T
    np.fill_diagonal(S, -1.0)
    # 並べる順は CSLS（2cos − 各自の近所の平均の近さ）。どの件の近くにも出てくる「ハブ」の件
    # （論点がぼんやりした質問主意書）を下げる。cos だけだと第212回141号の近くに半導体・孔子学院が並んだ
    r = np.sort(S, axis=1)[:, -10:].mean(axis=1)
    C = 2 * S - r[:, None] - r[None, :]
    np.fill_diagonal(C, -9.0)
    out = []
    for i in range(len(ids)):
        top = np.argpartition(-C[i], TOP)[:TOP]
        top = top[np.argsort(-C[i][top])]
        for rank, j in enumerate(top, 1):
            out.append((ids[i], ids[j], rank, round(float(S[i][j]), 4)))   # score はコサイン類似度

    db.executescript("""
    DROP TABLE IF EXISTS similar;
    CREATE TABLE similar (id TEXT, sim_id TEXT, rank INTEGER, score REAL, PRIMARY KEY (id, sim_id));
    """)
    db.executemany("INSERT INTO similar VALUES (?,?,?,?)", out)
    db.execute("INSERT OR REPLACE INTO meta VALUES ('similar_model', ?)", (f"{MODEL}（件名＋質問本文の冒頭{HEAD}字・CSLS で上位{TOP}件）",))
    db.execute("INSERT OR REPLACE INTO meta VALUES ('similar_built', ?)", (time.strftime("%Y-%m-%d"),))
    db.commit()
    db.execute("VACUUM")
    print(f"→ similar {len(out)}行（{len(ids)}件 × 上位{TOP}） {DB}")
    sc = np.array([o[3] for o in out])
    print(f"  近さ: 中央値 {np.median(sc):.3f}・最小 {sc.min():.3f}・最大 {sc.max():.3f}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
