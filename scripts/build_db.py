#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""落とした生HTML（/mnt/data/kshuisho/raw）から php/kshuisho_data/kshuisho.sqlite を作る。

  /usr/bin/python3 scripts/build_db.py

表
  q     … 質問主意書1件（house, session, no, title, submitter, kaiha, submit_date, transfer_date, answer_date,
           status, q_text, a_text, q_url, a_url, progress_url, a_sections, evasive_json）
  meta  … 集計時刻・件数・出典

読み方の線
  - 本文は機械的に抜き出す。要約・言い換えはしない。
  - 「答えていない型」は答弁書に現れる定型句を数えたもの（evasive_json）。判断ではなく語の一致。
    例: 「お答えすることは困難」「必ずしも明らかではない」「承知していない」「検討してまいりたい」。
  - 日付は和暦（令和・平成・漢数字）を ISO に直す。直せないものは空にして原文を残す。
"""
from __future__ import annotations

import glob
import html as htmlmod
import json
import os
import re
import sqlite3
import sys
from collections import Counter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
RAW = "/mnt/data/kshuisho/raw"
OUT = os.path.join(ROOT, "php", "kshuisho_data", "kshuisho.sqlite")
SHU = "https://www.shugiin.go.jp/internet/itdb_shitsumon.nsf/html/shitsumon/"
SAN = "https://www.sangiin.go.jp/japanese/joho1/kousei/syuisyo/"

# 答弁書の定型句（「答えていない型」）。語の一致で数えるだけ
EVASIVE = [
    ("困難", r"お答えすることは困難|お答えすることが困難|お答えは困難|困難である"),
    ("趣旨不明", r"趣旨が必ずしも明らかではない|意味するところが必ずしも明らかではない|意味するところが明らかではない|必ずしも明らかではない"),
    ("承知せず", r"承知していない|承知しておらず|把握していない|把握しておらず"),
    ("検討中", r"検討してまいりたい|検討を進めてまいりたい|検討しているところ|検討中である"),
    ("仮定", r"仮定の御質問|仮定の質問"),
    ("個別事案", r"個別の事案|個々の事案|個別具体的な"),
]
KANJI = {"〇": 0, "零": 0, "一": 1, "二": 2, "三": 3, "四": 4, "五": 5, "六": 6, "七": 7, "八": 8, "九": 9, "十": 10}
ERA = {"令和": 2018, "平成": 1988, "昭和": 1925}


def norm_name(s: str) -> str:
    """提出者名を揃える。
    古い会期は連名がある。末尾が「名」なので rstrip("君") が効かない（2026-09-23 実測）。
      衆議院「櫻内義雄君外二名」 → 櫻内義雄 外二名
      参議院「北條 秀一君 外4名」 → 北條 秀一 外4名（こちらは君と外のあいだに空白が入る）
    **「満尾君亮」のように名前そのものに君が入る人がいる**ので、外が続くときだけ落とす。
    全角空白は半角に、連続空白は1つに。"""
    s = (s or "").replace("　", " ")
    s = re.sub(r"君(?=\s*外)", " ", s)      # 連名の「君」だけ落とす
    s = re.sub(r"君$", "", s)                # 末尾の敬称
    return re.sub(r"\s+", " ", s).strip()


def kan2int(s: str) -> int | None:
    s = s.strip()
    if s.isdigit():
        return int(s)
    if s == "元":
        return 1
    if not s or any(c not in KANJI for c in s):
        return None
    total, cur = 0, 0
    for c in s:
        v = KANJI[c]
        if v == 10:
            total += (cur or 1) * 10; cur = 0
        else:
            cur = v
    return total + cur


def wareki(s: str) -> str:
    """「令和五年十月二十日」「令和 5年10月20日」「令和8年2月25日」→ 2023-10-20。直せなければ空。"""
    m = re.search(r"(令和|平成|昭和)\s*([0-9元一二三四五六七八九十〇]+)年\s*([0-9一二三四五六七八九十]+)月\s*([0-9一二三四五六七八九十]+)日", s)
    if not m:
        return ""
    y, mo, d = kan2int(m.group(2)), kan2int(m.group(3)), kan2int(m.group(4))
    if y is None or mo is None or d is None:
        return ""
    return f"{ERA[m.group(1)] + y:04d}-{mo:02d}-{d:02d}"


def text_lines(raw: str) -> list[str]:
    s = re.sub(r"(?is)<script.*?</script>|<style.*?</style>", "", raw)
    s = re.sub(r"(?i)<br\s*/?>|</p>|</div>|</tr>|</td>|</li>|</h\d>", "\n", s)
    s = re.sub(r"<[^>]+>", "", s)
    s = htmlmod.unescape(s).replace("\xa0", " ")
    return [l.strip() for l in s.split("\n") if l.strip()]


def between(lines: list[str], start_pred, end_pred) -> list[str]:
    out, on = [], False
    for l in lines:
        if not on and start_pred(l):
            on = True; continue
        if on and end_pred(l):
            break
        if on:
            out.append(l)
    return out


# ---- 衆議院 -----------------------------------------------------------------
def shugiin(session_dir: str) -> list[dict]:
    session = int(os.path.basename(session_dir))
    rows = []
    # ファイル名の会期は3桁ゼロ詰め（第1回= 001001.htm）。ディレクトリ名は "1" なので合わせる
    for p in sorted(glob.glob(os.path.join(session_dir, f"{session:03d}[0-9][0-9][0-9].htm"))):
        base = os.path.basename(p)[:-4]
        no = int(base[-3:])
        L = text_lines(open(p, encoding="utf-8").read())
        kv = {}
        for i, l in enumerate(L):
            if l in ("質問件名", "提出者名", "会派名", "質問主意書提出年月日", "内閣転送年月日", "答弁書受領年月日", "経過状況", "国会区別"):
                nxt = L[i + 1] if i + 1 < len(L) else ""
                # 空欄の項目は次の項目名が来る
                kv[l] = "" if nxt in ("答弁延期通知受領年月日", "答弁延期期限年月日", "答弁書受領年月日", "撤回年月日", "撤回通知年月日", "経過状況", "内閣転送年月日") else nxt
        q_text = a_text = ""
        qp = os.path.join(session_dir, f"a{base}.htm")
        if os.path.exists(qp):
            QL = text_lines(open(qp, encoding="utf-8").read())
            if "質問本文情報" in QL:
                QL = QL[QL.index("質問本文情報") + 1:]
            body = between(QL, lambda l: l.startswith("提出者"), lambda l: l in ("経過へ",))
            # 先頭に件名が1回繰り返される
            if body and body[0] == kv.get("質問件名", ""):
                body = body[1:]
            q_text = "\n".join(body).strip()
        ap = os.path.join(session_dir, f"b{base}.htm")
        if os.path.exists(ap):
            AL = text_lines(open(ap, encoding="utf-8").read())
            # <title> やパンくずにも「…に対する答弁書」が出るので、「答弁本文情報」の印より後ろから探す
            if "答弁本文情報" in AL:
                AL = AL[AL.index("答弁本文情報") + 1:]
            body = between(AL, lambda l: l.endswith("答弁書") and "提出" in l and "送付" not in l, lambda l: l in ("経過へ",))
            a_text = "\n".join(body).strip()
        rows.append({
            "id": f"shu-{session}-{no}", "house": "衆議院", "session": session, "no": no,
            "title": kv.get("質問件名", ""), "submitter": norm_name(kv.get("提出者名", "")),
            "kaiha": kv.get("会派名", ""), "session_kind": kv.get("国会区別", ""),
            "submit_date": wareki(kv.get("質問主意書提出年月日", "")), "transfer_date": wareki(kv.get("内閣転送年月日", "")),
            "answer_date": wareki(kv.get("答弁書受領年月日", "")), "status": kv.get("経過状況", ""),
            "q_text": q_text, "a_text": a_text,
            "q_url": SHU + f"a{base}.htm", "a_url": SHU + f"b{base}.htm" if a_text else "", "progress_url": SHU + f"{base}.htm",
        })
    return rows


# ---- 参議院 -----------------------------------------------------------------
def sangiin(session_dir: str) -> list[dict]:
    session = int(os.path.basename(session_dir))
    rows = []
    for p in sorted(glob.glob(os.path.join(session_dir, f"m{session:03d}[0-9][0-9][0-9].htm"))):
        base = os.path.basename(p)[1:-4]
        no = int(base[-3:])
        L = text_lines(open(p, encoding="utf-8").read())
        kv = {}
        for i, l in enumerate(L):
            if l in ("件名", "提出日", "提出者", "転送日", "答弁書受領日", "備考"):
                nxt = L[i + 1] if i + 1 < len(L) else ""
                kv[l] = "" if nxt in ("提出回次", "提出番号", "提出日", "提出者", "備考", "その他", "転送日", "答弁書受領日", "質問主意書") else nxt
        q_text = a_text = ""
        qp = os.path.join(session_dir, f"s{base}.htm")
        if os.path.exists(qp):
            QL = text_lines(open(qp, encoding="utf-8").read())
            # 「参議院議長　○○　殿」の次に件名がもう一度出て、その後ろが本文。「右質問する。」で終わる
            i = next((k for k, l in enumerate(QL) if "参議院議長" in l and l.endswith("殿")), None)
            if i is not None:
                body = QL[i + 1:]
                if body and body[0] == kv.get("件名", ""):
                    body = body[1:]
                j = next((k for k, l in enumerate(body) if l.startswith("右質問する")), None)
                q_text = "\n".join(body[: j + 1] if j is not None else body).strip()
        ap = os.path.join(session_dir, f"t{base}.htm")
        if os.path.exists(ap):
            AL = text_lines(open(ap, encoding="utf-8").read())
            # 「参議院議員○○君提出…に対する答弁書」が2回（見出しと本文冒頭）出る。2回目の後ろが本文
            idx = [k for k, l in enumerate(AL) if l.startswith("参議院議員") and l.endswith("答弁書")]
            if idx:
                body = AL[idx[-1] + 1:]
                # 末尾のフッター（ページの先頭へ・Copyright 等）を落とす
                j = next((k for k, l in enumerate(body) if l.startswith(("Copyright", "ページの先頭", "本文へ", "〒", "電話：", "アクセス", "利用案内", "著作権", "免責事項", "プライバシー", "参議院  ", "参議院\u3000")) or l in ("トップ", "サイトマップ", "質問主意書", "参議院")), None)
                a_text = "\n".join(body[:j] if j is not None else body).strip()
        rows.append({
            "id": f"san-{session}-{no}", "house": "参議院", "session": session, "no": no,
            "title": kv.get("件名", ""), "submitter": norm_name(kv.get("提出者", "")),
            "kaiha": "", "session_kind": "",
            "submit_date": wareki(kv.get("提出日", "")), "transfer_date": wareki(kv.get("転送日", "")),
            "answer_date": wareki(kv.get("答弁書受領日", "")), "status": "答弁受理" if a_text else "",
            "q_text": q_text, "a_text": a_text,
            "q_url": SAN + f"{session}/syuh/s{base}.htm", "a_url": SAN + f"{session}/touh/t{base}.htm" if a_text else "",
            "progress_url": SAN + f"{session}/meisai/m{base}.htm",
        })
    return rows


def evasive(a_text: str) -> dict:
    return {k: len(re.findall(pat, a_text)) for k, pat in EVASIVE if re.search(pat, a_text)}


def main() -> int:
    rows = []
    def sess_dirs(house):
        ds = [d for d in glob.glob(os.path.join(RAW, house, "*")) if os.path.basename(d).isdigit()]
        return sorted(ds, key=lambda d: int(os.path.basename(d)))   # 文字列順だと第10回が第2回より前に来る
    for d in sess_dirs("shugiin"):
        rows += shugiin(d)
    for d in sess_dirs("sangiin"):
        rows += sangiin(d)
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    if os.path.exists(OUT):
        os.remove(OUT)
    db = sqlite3.connect(OUT)
    db.executescript("""
    CREATE TABLE q (id TEXT PRIMARY KEY, house TEXT, session INTEGER, no INTEGER, title TEXT, submitter TEXT, kaiha TEXT,
      session_kind TEXT, submit_date TEXT, transfer_date TEXT, answer_date TEXT, status TEXT, q_text TEXT, a_text TEXT,
      q_url TEXT, a_url TEXT, progress_url TEXT, a_sections INTEGER, evasive_json TEXT, q_len INTEGER, a_len INTEGER);
    CREATE INDEX q_house_session ON q(house, session, no);
    CREATE INDEX q_submitter ON q(submitter);
    CREATE INDEX q_date ON q(submit_date);
    CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT);
    """)
    for r in rows:
        ev = evasive(r["a_text"])
        db.execute("INSERT INTO q VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                   (r["id"], r["house"], r["session"], r["no"], r["title"], r["submitter"], r["kaiha"], r["session_kind"],
                    r["submit_date"], r["transfer_date"], r["answer_date"], r["status"], r["q_text"], r["a_text"],
                    r["q_url"], r["a_url"], r["progress_url"], len(re.findall(r"^.{1,12}について$", r["a_text"], re.M)),
                    json.dumps(ev, ensure_ascii=False), len(r["q_text"]), len(r["a_text"])))
    n = len(rows); na = sum(1 for r in rows if r["a_text"])
    smin = min((r["submit_date"] for r in rows if r["submit_date"]), default=""); smax = max((r["submit_date"] for r in rows if r["submit_date"]), default="")
    meta = {"count": str(n), "answered": str(na), "date_from": smin, "date_to": smax,
            "sessions": json.dumps(sorted({r["session"] for r in rows})),
            "attribution": "衆議院「質問答弁情報」・参議院「質問主意書」の公開ページから機械的に抜き出したもの（出所明示のうえ転載）",
            "source_shugiin": SHU + "menu_m.htm", "source_sangiin": SAN + "current/syuisyo.htm",
            "built": __import__("datetime").date.today().isoformat()}
    db.executemany("INSERT INTO meta VALUES (?,?)", list(meta.items()))
    db.commit()
    # 行を入れたあとのファイルは実データの3倍ほどに膨らむ。VACUUM すると実サイズに戻る
    # （2,167件のとき 20.2MB → 実データ 6.6MB。全会期だと効きが大きい）
    db.execute("VACUUM")
    bad = [r["id"] for r in rows if not r["title"] or (r["a_url"] and not r["a_text"]) or not r["q_text"]]
    print(f"→ {OUT} 質問 {n}件（答弁あり {na}） 期間 {smin}〜{smax}")
    if bad:
        print(f"!! 本文か件名が取れていないもの {len(bad)}件: {bad[:10]}")
    c = Counter()
    for r in rows:
        for k in evasive(r["a_text"]): c[k] += 1
    print("答えていない型の出現（答弁書の件数）:", dict(c))
    return 0


if __name__ == "__main__":
    sys.exit(main())
