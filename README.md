# kshuisho — Kurage 質問主意書アシスト

衆議院・参議院の質問主意書と政府答弁書を、本文の中までことばで引ける形にした道具。議員事務所向け。
同じ論点で過去に誰が何を聞き、政府が何と答え、どこが「お答えすることは困難」とされたかを1画面に並べる。要約も論評も文章の生成もしない。

- 公開: https://kurage.exbridge.jp/kshuisho.php/
- 製品本体と置き方: `php/README.md`
- データ更新: `scripts/fetch_shugiin.py`・`scripts/fetch_sangiin.py`（生HTMLは `/mnt/data/kshuisho/raw/`）→ `scripts/build_db.py`
- 公開: `scripts/deploy.py`（`--php` で PHP だけ）／出品: `scripts/make_zip.py` → `scripts/list_on_kappstore.py`
