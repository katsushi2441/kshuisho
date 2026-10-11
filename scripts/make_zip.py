#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""配布ZIP（kappstore 同梱物）を作る。

  /usr/bin/python3 scripts/make_zip.py

**同梱の LICENSE と README は必ずこのプロジェクトのものを入れる。**
他製品からコピーしたまま末尾の注意書きが別製品になっていた前例がある（kgakudo）。
最後に中身を並べて、製品名が混ざっていないか目で確かめられるように出力する。
"""
from __future__ import annotations

import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TOP = "kurage-shuisho-assist"
OUT = ROOT / "outputs" / f"{TOP}.zip"

FILES = [
    (ROOT / "php" / "LICENSE", "LICENSE"),
    (ROOT / "php" / "README.md", "README.md"),
    (ROOT / "php" / "kshuisho.php", "kshuisho.php"),
    (ROOT / "php" / "kshuisho_data" / "kshuisho.sqlite", "kshuisho_data/kshuisho.sqlite"),
    (ROOT / "php" / "kshuisho_data" / ".htaccess", "kshuisho_data/.htaccess"),
    (ROOT / "outputs" / "kshuisho_ogp.png", "images/ogp/kshuisho.png"),
    (ROOT / "scripts" / "fetch_shugiin.py", "scripts/fetch_shugiin.py"),
    (ROOT / "scripts" / "fetch_sangiin.py", "scripts/fetch_sangiin.py"),
    (ROOT / "scripts" / "build_db.py", "scripts/build_db.py"),
    (ROOT / "scripts" / "build_similar.py", "scripts/build_similar.py"),
    (ROOT / "scripts" / "test_draft_check.php", "scripts/test_draft_check.php"),
    (ROOT / "php" / "kshuisho_config.example.php", "kshuisho_config.example.php"),
]

BAD = ("kkaigo", "khoudei", "kshuro", "kghome", "訪問介護", "ケアマネ", "放課後等デイ", "グループホーム", "共同生活援助")


def main() -> int:
    for src, _ in FILES:
        if not src.exists():
            print(f"! {src} が無い", file=sys.stderr)
            return 1
    # 他製品の文面が混ざっていないか、テキストだけ検査する
    ng = 0
    for src, dst in FILES:
        if src.suffix in (".md", ".py", ".php") or src.name == "LICENSE":
            text = src.read_text(encoding="utf-8", errors="replace")
            hit = [w for w in BAD if w in text]
            if hit:
                print(f"! {dst} に別製品の語: {hit}", file=sys.stderr)
                ng += 1
    if ng:
        return 1
    OUT.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(OUT, "w", zipfile.ZIP_DEFLATED, compresslevel=6) as z:
        for src, dst in FILES:
            z.write(src, f"{TOP}/{dst}")
    with zipfile.ZipFile(OUT) as z:
        for i in z.infolist():
            print(f"  {i.file_size:>11,}  {i.filename}")
    print(f"→ {OUT} ({OUT.stat().st_size/1048576:.1f}MB)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
