<?php
/**
 * 下書きの検査（draft_check）が、作り話を止めることを確かめる。
 *
 *   php scripts/test_draft_check.php                 # 検査だけ（LLM なし。合成した下書きで）
 *   php scripts/test_draft_check.php --llm "語"      # kshuisho_config.php の LLM で本物の下書きを作り、
 *                                                     # それが通ること・原文に無い引用を混ぜると止まることを確かめる
 *
 * 終了コード 0 = 全部期待どおり。
 */
declare(strict_types=1);
define('KSHUISHO_LIB', 1);
// リポジトリでは php/ の下、配布 ZIP では1つ上に kshuisho.php がある
$APP = is_file(__DIR__ . '/../php/kshuisho.php') ? __DIR__ . '/../php' : __DIR__ . '/..';
require $APP . '/kshuisho.php';

$db = new PDO('sqlite:' . $APP . '/kshuisho_data/kshuisho.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$fails = 0;
function expect($name, $cond) { global $fails; echo ($cond ? '  ok   ' : '  NG   ') . $name . "\n"; if (!$cond) $fails++; }

$q = '質問主意書 答弁';
$i = array_search('--llm', $argv, true);
if ($i !== false && isset($argv[$i + 1])) $q = $argv[$i + 1];
$src = draft_sources($db, $q);
$ans = array_values(array_filter($src, function ($s) { return $s['kind'] === '答弁'; }));
echo "材料: 「{$q}」 出典 " . count($src) . '件（答弁 ' . count($ans) . "）\n";
if (!$ans) { echo "答弁の原文が無い語です\n"; exit(1); }

// ── 合成した下書き（LLM なし） ──
$a = $ans[0]; $plain = preg_replace('/[\s　]+/u', '', $a['text']);
$good = array('title' => 'テストに関する質問主意書',
    'facts' => array(array('text' => '政府は過去の答弁書でこの点に答えている。', 'src' => array($a['n']))),
    'quotes' => array(array('src' => $a['n'], 'quote' => mb_substr($plain, 0, 30))),
    'questions' => array('政府はこの点をどう考えるか。', '期限はいつか。', '公表の方法は何か。'));
expect('原文どおりの引用は通る', draft_check($good, $src) === array());

$x = $good; $x['quotes'][0]['quote'] = mb_substr($plain, 0, 20) . '（原文に無い文）';
expect('原文に無い引用は止まる', (bool)draft_check($x, $src));
$x = $good; $x['quotes'][0]['src'] = 999;
expect('渡していない出典番号は止まる', (bool)draft_check($x, $src));
$x = $good; $x['questions'][0] = '政府は「この答弁は存在しない架空の文である」と述べたのか。';
expect('問いの中の「」も原文に無ければ止まる', (bool)draft_check($x, $src));
$x = $good; $x['facts'][0]['text'] = '令和7年度の予算は12345億円である。';
expect('前提の事実に原文に無い数字があれば止まる', (bool)draft_check($x, $src));
$x = $good; $x['facts'][0]['src'] = array();
expect('前提の事実に出典番号が無ければ止まる', (bool)draft_check($x, $src));
$q_only = array_values(array_filter($src, function ($s) { return $s['kind'] === '質問'; }));
if ($q_only) { $x = $good; $x['quotes'][0] = array('src' => $q_only[0]['n'], 'quote' => mb_substr(preg_replace('/[\s　]+/u', '', $q_only[0]['text']), 0, 30));
    expect('質問の本文を「答弁」として引くと止まる', (bool)draft_check($x, $src)); }

// ── 本物の下書き（LLM あり） ──
if ($i !== false) {
    if (!$LLM_ON) { echo "kshuisho_config.php に LLM の設定がありません\n"; exit(1); }
    list($sys, $u) = draft_prompt($q, $src);
    $t0 = microtime(true);
    $o = llm_chat($sys, $u, draft_schema(), 1500, 180);
    printf("LLM %.1f秒\n", microtime(true) - $t0);
    expect('LLM が JSON を返した', is_array($o));
    if (is_array($o)) {
        $bad = draft_check($o, $src);
        echo "検査: " . ($bad ? implode(' / ', $bad) : '合格') . "\n";
        if (!$bad) { echo "----\n" . draft_text($o, $src) . "\n----\n"; }
        else { echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"; }
        // わざと原文に無い引用を1つ混ぜる
        $x = $o; $x['quotes'][] = array('src' => $ans[0]['n'], 'quote' => '政府としては、御指摘の点について直ちに全面的に見直す考えである');
        $bx = draft_check($x, $src);
        expect('LLM の下書きに原文に無い引用を混ぜると止まる（' . ($bx ? $bx[count($bx) - 1] : '') . '）', (bool)$bx);
        // 引用の1文字だけを変える
        if (!empty($o['quotes'])) {
            $x = $o; $qt = (string)$x['quotes'][0]['quote']; $x['quotes'][0]['quote'] = preg_replace('/である/u', 'でない', $qt, 1, $c);
            if (!$c) $x['quotes'][0]['quote'] = mb_substr($qt, 0, -1) . '。でない';
            expect('引用の語尾を1か所変えると止まる', (bool)draft_check($x, $src));
        }
    }
}
echo $fails ? "NG {$fails}件\n" : "全部期待どおり\n";
exit($fails ? 1 : 0);
