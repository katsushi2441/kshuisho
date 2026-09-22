#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""記事・note・spec の __PLACEHOLDER__ を、DB の実測値で埋める。
  /usr/bin/python3 scripts/fill_numbers.py <入力ファイル>...   （その場で書き換える）
置き換える語: __TOTAL__ __ANSWERED__ __S_FROM__ __S_TO__ __D_FROM__ __D_TO__ __KONNAN__ __KONNAN_PCT__
  __SHUSHI__ __SHUSHI_PCT__ __SHOCHI__ __KENTO__ __AVG_DAYS__ __SUBMITTERS__ __TOP1__ __TOP1_N__ __TOP2__ __TOP2_N__ __TOP3__ __TOP3_N__
  __SHU__ __SAN__ __ANY_EVASIVE__ __ANY_EVASIVE_PCT__
"""
import json, re, sqlite3, sys, os
DB = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "php", "kshuisho_data", "kshuisho.sqlite")
c = sqlite3.connect(DB)
m = dict(c.execute("SELECT k, v FROM meta"))
tot = int(m["count"]); na = int(m["answered"])
def cnt(k): return c.execute("SELECT count(*) FROM q WHERE evasive_json LIKE ?", ('%"' + k + '"%',)).fetchone()[0]
kon, shu, sho, ken = cnt("困難"), cnt("趣旨不明"), cnt("承知せず"), cnt("検討中")
anyev = c.execute("SELECT count(*) FROM q WHERE evasive_json <> '{}' AND a_len > 0").fetchone()[0]
avg = c.execute("SELECT avg(julianday(answer_date) - julianday(submit_date)) FROM q WHERE answer_date<>'' AND submit_date<>''").fetchone()[0] or 0
top = c.execute("SELECT submitter, count(*) FROM q GROUP BY submitter ORDER BY 2 DESC LIMIT 3").fetchall()
sess = json.loads(m["sessions"])
V = {"__TOTAL__": f"{tot:,}", "__ANSWERED__": f"{na:,}", "__S_FROM__": str(min(sess)), "__S_TO__": str(max(sess)),
     "__D_FROM__": m["date_from"], "__D_TO__": m["date_to"], "__KONNAN__": f"{kon:,}", "__KONNAN_PCT__": str(round(kon / na * 100)) if na else "0",
     "__SHUSHI__": f"{shu:,}", "__SHUSHI_PCT__": str(round(shu / na * 100)) if na else "0", "__SHOCHI__": f"{sho:,}", "__KENTO__": f"{ken:,}",
     "__AVG_DAYS__": f"{avg:.1f}", "__SUBMITTERS__": f"{c.execute('SELECT count(DISTINCT submitter) FROM q').fetchone()[0]:,}",
     "__SHU__": f"{c.execute(chr(83)+'ELECT count(*) FROM q WHERE house='+chr(39)+'衆議院'+chr(39)).fetchone()[0]:,}",
     "__SAN__": f"{c.execute(chr(83)+'ELECT count(*) FROM q WHERE house='+chr(39)+'参議院'+chr(39)).fetchone()[0]:,}",
     "__ANY_EVASIVE__": f"{anyev:,}", "__ANY_EVASIVE_PCT__": str(round(anyev / na * 100)) if na else "0"}
for i, (s, n) in enumerate(top, 1):
    V[f"__TOP{i}__"] = s; V[f"__TOP{i}_N__"] = str(n)
for f in sys.argv[1:]:
    t = open(f, encoding="utf-8").read(); o = t
    for k, v in V.items(): t = t.replace(k, v)
    left = re.findall(r"__[A-Z0-9_]+__", t)
    open(f, "w", encoding="utf-8").write(t)
    print(f, "置換", sum(1 for k in V if k in o), "残り", left[:5])
print(json.dumps(V, ensure_ascii=False))
