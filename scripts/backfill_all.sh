#!/bin/bash
# 全会期（第1回=1947年〜）の質問主意書を落とす。衆参を別ホストなので並行で走らせる。
#
#   bash scripts/backfill_all.sh [最終会期]     既定 221
#
# 1リクエスト1秒。参 22,000件・衆 同程度なので通しで6〜8時間かかる。
# 落としたものは /mnt/data/kshuisho/raw/<院>/<会期>/ に残るので、途中で止まっても
# 同じコマンドで続きから再開する（取得済みのファイルは取りに行かない）。
# ログは outputs/backfill_<院>.log。
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LAST="${1:-221}"
mkdir -p "$ROOT/outputs"

# 先に走っている取得が終わるのを待つ（同じ会期を2つのプロセスで書かない）。
# pgrep -f は「自分と同じ文字列を持つ見張りプロセス」まで拾って永久に待つ（[[feedback_pgrep_self_match]]）。
# python3 の実行ファイル名で絞り、引数の一致は pgrep に任せない。
while pgrep -af 'scripts/fetch_(shugiin|sangiin)\.py' | grep -q 'python3 scripts/fetch'; do sleep 10; done

cd "$ROOT"
/usr/bin/python3 scripts/fetch_sangiin.py --from 1 --to "$LAST" > outputs/backfill_sangiin.log 2>&1 &
SAN=$!
/usr/bin/python3 scripts/fetch_shugiin.py --from 1 --to "$LAST" > outputs/backfill_shugiin.log 2>&1 &
SHU=$!
echo "参議院 pid=$SAN / 衆議院 pid=$SHU / 最終会期=$LAST"
wait $SAN; echo "参議院 終了 rc=$?"
wait $SHU; echo "衆議院 終了 rc=$?"
echo "取得おわり $(date '+%Y-%m-%d %H:%M:%S')"
