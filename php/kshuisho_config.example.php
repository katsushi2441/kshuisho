<?php
// kshuisho_config.php という名前でコピーして使う（kshuisho.php と同じ階層）。
// このファイルが無ければ、AI（LLM）は使わず、これまでどおり外部に何も送らない。
// 設定すると、検索とアシストに「言い換えでも探した結果」、アシストに「下書きを作る」が出る。
//
// llm_base は OpenAI 互換の chat/completions を受けるところ（末尾の /chat/completions は付けない）。
//   事務所の中の Ollama なら 'http://<OllamaのIP>:11434/v1'（モデルは ollama pull しておく）
// gemma4 のような思考型のモデルは reasoning_effort=none を送って思考を切る（Ollama の think:false に当たる）。
return array(
    'llm_base'  => '',                    // 例: 'http://192.168.0.10:11434/v1'
    'llm_token' => '',                    // 要るときだけ（Bearer で送る）
    'llm_model' => 'gemma4:12b-it-qat',
    // 'expand_timeout'     => 8,         // 言い換えを待つ秒数。過ぎたら元の検索結果だけを出す
    // 'draft_timeout'      => 120,       // 下書きを待つ秒数
    // 'draft_per_hour'     => 10,        // 下書き: IP ごとに1時間の回数
    // 'draft_per_hour_all' => 60,        // 下書き: 全体で1時間の回数
);
