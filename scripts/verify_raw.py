#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""落とした生HTMLが最後まで書けているかを検め、壊れているものを消す。

  /usr/bin/python3 scripts/verify_raw.py            # 数えるだけ
  /usr/bin/python3 scripts/verify_raw.py --delete   # 壊れているものを消す（次の取得で落とし直す）

両院とも本文は </html> で終わる（2026-09-23 実測）。途中で切れているファイルは
取得を止めたときや、同じファイルを2つのプロセスが同時に書いたときにできる。
消しておけば fetch_*.py が次に落とし直す（取得済みのファイルは取りに行かない作り）。
"""
from __future__ import annotations

import os
import sys

RAW = "/mnt/data/kshuisho/raw"
MIN_BYTES = 800  # 一覧・経過・本文のどれも、正常なら必ずこれより大きい


def check(path: str) -> str | None:
    """壊れていれば理由を返す。無事なら None。"""
    try:
        size = os.path.getsize(path)
    except OSError as e:
        return f"読めない({e.__class__.__name__})"
    if size < MIN_BYTES:
        return f"小さすぎる({size}B)"
    with open(path, "rb") as f:
        f.seek(max(0, size - 200))
        tail = f.read().decode("utf-8", "replace").lower()
    if "</html>" not in tail:
        return "末尾が </html> でない（途中で切れている）"
    return None


def main() -> int:
    delete = "--delete" in sys.argv
    total = 0
    bad: list[tuple[str, str]] = []
    for house in ("shugiin", "sangiin"):
        root = os.path.join(RAW, house)
        if not os.path.isdir(root):
            continue
        for d, _, names in os.walk(root):
            for nm in names:
                if not nm.endswith(".htm"):
                    continue
                p = os.path.join(d, nm)
                total += 1
                why = check(p)
                if why:
                    bad.append((p, why))
    print(f"生HTML {total:,}件 / 壊れ {len(bad):,}件")
    for p, why in bad[:20]:
        print("  ", p.replace(RAW + "/", ""), why)
    if len(bad) > 20:
        print(f"   …ほか {len(bad) - 20:,}件")
    if bad and delete:
        for p, _ in bad:
            os.remove(p)
        print(f"→ {len(bad):,}件を消した。fetch_shugiin.py / fetch_sangiin.py を同じ会期で流すと落とし直す")
    elif bad:
        print("→ --delete を付けると消す")
    return 0


if __name__ == "__main__":
    sys.exit(main())
