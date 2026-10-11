# kshuisho — Kurage 質問主意書アシスト

衆議院・参議院の質問主意書と政府答弁書を、本文の中までことばで引ける形にした道具。議員事務所向け。
同じ論点で過去に誰が何を聞き、政府が何と答え、どこが「お答えすることは困難」とされたかを1画面に並べる。要約も論評もしない。
LLM の設定（`php/kshuisho_config.php`）がある環境では、言い換えで探すことと、質問主意書の下書きを作ることもできる。
下書きの引用はサーバーで原文と一字一句照合し、合わなければ出さない。提出の前に人が原文で確かめる前提。設定が無ければ文章は作らず、外部にも何も送らない。
似た質問主意書（e5 の埋め込みで事前計算した表）は LLM の有無にかかわらず出る。詳しくは `php/README.md`。

- 公開: https://kurage.exbridge.jp/kshuisho.php/
- 製品本体と置き方: `php/README.md`
- データ更新: `scripts/fetch_shugiin.py`・`scripts/fetch_sangiin.py`（生HTMLは `/mnt/data/kshuisho/raw/`）→ `scripts/build_db.py` → `scripts/build_similar.py`
  - `build_similar.py` は `/home/kojima/work/kronten/.venv/bin/python` で流す（fastembed 入り）。重いので `systemd-run --user -p MemoryMax=10G` で。埋め込みは `/mnt/data/kshuisho/emb/` に残り、2回目からは増えた分だけ
- 公開デモの LLM: `php/kshuisho_config.php`（リポジトリ外）に、0.3 の共通 gemma4 中継 `http://exbridge.ddns.net:18343/v1` と合言葉（`kaima/.env` の `RELAY_CLIENT_KSHUISHO`）。`deploy.py` が一緒に送る。`deploy.py --php --llm-off` で公開先の設定を消すと LLM なしの画面に戻る
- 下書きの検査のテスト: `php scripts/test_draft_check.php`（LLM なし）・`php scripts/test_draft_check.php --llm "教育費"`
- 公開: `scripts/deploy.py`（`--php` で PHP だけ）／出品: `scripts/make_zip.py` → `scripts/list_on_kappstore.py`
