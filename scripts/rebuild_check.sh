#!/bin/bash
# 全会期を落とし終わったあとの仕上げ。作り直して、大きさと速さを実測するところまで。
#
#   bash scripts/rebuild_check.sh
#
# デプロイはしない。heteml へ送るのは中身を見てから（FTPは1接続にまとめる決まりなので、
# 送るときは scripts/deploy.py を1回だけ走らせる）。
set -eu
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
DB=php/kshuisho_data/kshuisho.sqlite

echo "=== 1. 途中で切れた生HTMLを消す ==="
/usr/bin/python3 scripts/verify_raw.py --delete

echo
echo "=== 2. データベースを作り直す ==="
[ -f "$DB" ] && cp "$DB" "$DB.prev"
/usr/bin/python3 scripts/build_db.py

echo
echo "=== 3. 大きさ ==="
ls -lh "$DB" | awk '{print "  ファイル", $5}'
[ -f "$DB.prev" ] && ls -lh "$DB.prev" | awk '{print "  作り直す前", $5}'

echo
echo "=== 4. 検索の速さ（LIKE の全走査。遅いなら索引を考える） ==="
/usr/bin/python3 - <<'PY'
import sqlite3, time
c = sqlite3.connect('php/kshuisho_data/kshuisho.sqlite')
m = dict(c.execute('SELECT k, v FROM meta'))
print(f"  収録 {int(m['count']):,}件 / 答弁 {int(m['answered']):,}件 / {m['date_from']}〜{m['date_to']}")
print(f"  院別 {c.execute('SELECT house, count(*) FROM q GROUP BY house').fetchall()}")
print(f"  提出者 {c.execute('SELECT count(DISTINCT submitter) FROM q').fetchone()[0]:,}人")
for q in ('介護', '遺族年金', '再生可能エネルギー'):
    t = time.time()
    n = c.execute('SELECT count(*) FROM q WHERE title LIKE ? OR q_text LIKE ? OR a_text LIKE ?', (f'%{q}%',) * 3).fetchone()[0]
    print(f"  「{q}」 {n:,}件 {time.time() - t:.2f}秒")
PY

echo
echo "=== 5. 記事・商品の数字を埋め直す ==="
echo "  （__TOTAL__ などが残っているファイルがあれば）"
echo "  /usr/bin/python3 scripts/fill_numbers.py <ファイル>"

echo
echo "次: 中身を見てから"
echo "  /usr/bin/python3 scripts/deploy.py            # PHP と SQLite を heteml へ（FTPは1接続）"
echo "  /usr/bin/python3 scripts/make_zip.py && /usr/bin/python3 scripts/list_on_kappstore.py"
