<?php
/**
 * Kurage 質問主意書アシスト（kshuisho）
 *
 * 衆議院・参議院の質問主意書と政府答弁書を、ことばで引ける形にしたもの。議員事務所の道具。
 *   /                 ことばで探す（過去の質問と答弁）
 *   /search?q=…       検索結果（質問本文・答弁本文・件名）
 *   /assist?q=…       アシスト: 同じ論点で「何が聞かれ・政府は何と答え・どこが答えられていないか」を1画面に
 *   /q/{id}           質問主意書1件（質問本文・答弁本文・日付・型）
 *   /giin/{名前}      提出者ごと
 *   /session/{院}/{回次}
 *   /pattern/{型}     「答えていない型」ごとの答弁
 *   /data /about /api?q= /llms.txt /robots.txt /sitemap.xml
 *
 * **この道具がしないこと**: 要約・論評・賛否の判定。出すのは本文の機械的な抜粋と、定型句の一致だけ。
 * 「答えていない型」は答弁書に現れる定型句（「お答えすることは困難」等）の語の一致で、答弁の評価ではない。
 *
 * heteml に置くときは、その階層の .htaccess に `AddHandler php-script .php` が要る（既定はPHP5.6）。
 * PHP 8 + PDO SQLite だけで動く。外部のAIやAPIには何も送らない。
 */
declare(strict_types=1);
mb_internal_encoding('UTF-8');

$SITE = 'Kurage 質問主意書アシスト';
$SELF = '/kshuisho.php';
$OGP  = 'https://kurage.exbridge.jp/images/ogp/kshuisho.png';
$STORE = 'https://kappstore.exbridge.jp/?ref=kshuisho';   // 出品後に app.php?id=… へ差し替える
$DBP  = __DIR__ . '/kshuisho_data/kshuisho.sqlite';
$PATTERNS = array(
    '困難'   => array('お答えすることは困難', '「お答えすることは困難である」「困難である」と書いてある答弁'),
    '趣旨不明' => array('趣旨が明らかではない', '「御質問の趣旨が必ずしも明らかではない」「意味するところが明らかではない」と書いてある答弁'),
    '承知せず' => array('承知していない', '「承知していない」「把握していない」と書いてある答弁'),
    '検討中' => array('検討してまいりたい', '「検討してまいりたい」「検討中である」と書いてある答弁'),
    '仮定'   => array('仮定の質問', '「仮定の御質問」と書いてある答弁'),
    '個別事案' => array('個別の事案', '「個別の事案」「個別具体的な」と書いてある答弁'),
);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function n($v) { return number_format((int)$v); }
function jdate($iso) { if (!$iso) return '—'; $p = explode('-', $iso); return (int)$p[0] . '年' . (int)$p[1] . '月' . (int)$p[2] . '日'; }
function paras($text) { $out = ''; foreach (explode("\n", (string)$text) as $l) { $l = rtrim($l); if ($l === '') continue; $cls = preg_match('/^.{1,12}について$/u', $l) ? ' class="sec"' : ''; $out .= '<p' . $cls . '>' . h($l) . '</p>'; } return $out; }
function ev($json) { $a = json_decode((string)$json, true); return is_array($a) ? $a : array(); }

try { $db = new PDO('sqlite:' . $DBP); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); }
catch (Exception $e) { http_response_code(503); header('Content-Type: text/plain; charset=UTF-8'); echo "データベースがありません。scripts/build_db.py で作って kshuisho_data/ に置いてください。"; exit; }
$META = array(); foreach ($db->query('SELECT k, v FROM meta') as $r) { $META[$r['k']] = $r['v']; }

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (strpos($path, $SELF) === 0) { $path = substr($path, strlen($SELF)); }
$path = '/' . trim(rawurldecode((string)$path), '/');
$path = $path === '/' ? '' : $path;

// ── 検索 ───────────────────────────────────────────────
function terms($q) { $q = trim(preg_replace('/[\s　]+/u', ' ', (string)$q)); return $q === '' ? array() : array_slice(array_unique(explode(' ', $q)), 0, 5); }
function search($db, $q, $limit = 200) {
    $ts = terms($q); if (!$ts) return array();
    $w = array(); $a = array();
    foreach ($ts as $t) { $w[] = '(title LIKE ? OR q_text LIKE ? OR a_text LIKE ?)'; $a[] = "%$t%"; $a[] = "%$t%"; $a[] = "%$t%"; }
    $st = $db->prepare('SELECT id, house, session, no, title, submitter, kaiha, submit_date, answer_date, status, evasive_json, a_len, q_text, a_text FROM q WHERE ' . implode(' AND ', $w) . ' ORDER BY submit_date DESC LIMIT ' . (int)$limit);
    $st->execute($a); return $st->fetchAll();
}
function snippet($text, $ts, $width = 110) {
    $text = preg_replace('/\s+/u', ' ', (string)$text);
    foreach ($ts as $t) { $p = mb_strpos($text, $t); if ($p !== false) { $s = max(0, $p - (int)($width / 3)); $sn = mb_substr($text, $s, $width); return ($s > 0 ? '…' : '') . h($sn) . '…'; } }
    return h(mb_substr($text, 0, $width)) . '…';
}
function mark($html, $ts) { foreach ($ts as $t) { $html = str_replace(h($t), '<mark>' . h($t) . '</mark>', $html); } return $html; }

// ── 画面の部品 ─────────────────────────────────────────
function head_html($title, $desc, $canon, $ld_extra = null) {
    global $SELF, $SITE, $OGP, $META;
    $base = 'https://kurage.exbridge.jp' . $SELF;
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title><meta name="description" content="' . h($desc) . '">';
    echo '<link rel="canonical" href="' . h($base . $canon) . '">';
    echo '<meta property="og:title" content="' . h($title) . '"><meta property="og:description" content="' . h($desc) . '"><meta property="og:type" content="website"><meta property="og:image" content="' . h($OGP) . '"><meta property="og:site_name" content="' . h($SITE) . '"><meta property="og:url" content="' . h($base . $canon) . '">';
    echo '<meta name="twitter:card" content="summary_large_image"><meta name="twitter:image" content="' . h($OGP) . '">';
    echo '<style>'
       . ':root{--ink:#12202f;--mut:#5d6b7a;--teal:#0a9a8f;--teal-d:#087f76;--line:#dfe7ec;--bg:#f5f8fa;--red-l:#fdecea;--amber-l:#fdf6e3;--blue:#2c6fbb;--blue-l:#eaf2fb}'
       . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.85 "Noto Sans JP",system-ui,sans-serif}a{color:var(--teal-d)}.wrap{width:min(960px,100% - 32px);margin:0 auto}'
       . 'header{background:#fff;border-bottom:1px solid var(--line)}.brand{display:block;padding:14px 0 6px;font-weight:800;font-size:18px;text-decoration:none;color:var(--ink)}'
       . '.menu{display:flex;gap:14px;flex-wrap:wrap;padding-bottom:12px;font-size:14px}.menu a{text-decoration:none;color:var(--mut)}'
       . 'main{padding:22px 0 40px}h1{font-size:25px;line-height:1.4;margin:0 0 10px}h2{font-size:20px;margin:26px 0 10px}h3{font-size:16px;margin:18px 0 8px}.lead{color:var(--mut)}'
       . '.panel{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px;margin:14px 0;min-width:0}'
       . '.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}.card{border:1px solid var(--line);border-radius:12px;padding:14px;background:#fff;min-width:0}'
       . '.card .k{font-size:12px;color:var(--mut)}.card .v{font-size:22px;font-weight:800;margin-top:4px}.card .s{font-size:12px;color:var(--mut);margin-top:4px}'
       . '.form{display:flex;gap:10px;flex-wrap:wrap;align-items:center}input[type=text]{flex:1 1 260px;min-width:0;font-size:17px;padding:12px 14px;border:2px solid var(--line);border-radius:10px}'
       . '.btn{display:inline-block;background:var(--teal);color:#fff;border:0;border-radius:10px;padding:12px 20px;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}.btn.ghost{background:#fff;color:var(--teal-d);border:1px solid var(--line)}'
       . '.src{font-size:12.5px;color:var(--mut);line-height:1.8}.tscroll{overflow-x:auto}table.t{width:100%;border-collapse:collapse;font-size:14px;min-width:460px}table.t th,table.t td{border-bottom:1px solid var(--line);padding:8px 10px;text-align:left;vertical-align:top}table.t th{color:var(--mut);font-size:12px}td.n,th.n{text-align:right;font-variant-numeric:tabular-nums}'
       . '.q{border:1px solid var(--line);border-radius:12px;padding:14px;background:#fff;margin:10px 0;min-width:0}.q .nm{font-weight:700;font-size:17px;line-height:1.5}.q .meta{font-size:13.5px;color:var(--mut);margin-top:4px}.q .sn{font-size:14px;margin-top:8px;color:#334}'
       . '.tag{display:inline-block;font-size:12px;font-weight:700;border-radius:999px;padding:2px 10px;border:1px solid var(--line);background:var(--bg);color:var(--mut);margin:0 6px 4px 0}.tag.e{background:var(--amber-l);border-color:#e6c98b;color:#7a5a00}.tag.h{background:#eaf7f5;border-color:#a9ddd6;color:var(--teal-d)}'
       . 'mark{background:#fff3b0;padding:0 2px}.body p{margin:0 0 10px}.body p.sec{font-weight:700;margin-top:18px;color:var(--teal-d)}.two{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px}'
       . 'pre.draft{white-space:pre-wrap;font:14px/1.8 "Noto Sans JP",system-ui,sans-serif;background:#fbfcfd;border:1px dashed var(--line);border-radius:10px;padding:14px;margin:0}'
       . 'footer{border-top:1px solid var(--line);padding:22px 0 40px;color:var(--mut);font-size:13px;background:#fff}ul.plain{margin:0;padding-left:20px}'
       . '</style>';
    echo '<script>(function(){var s=document.createElement("script");s.src="https://kurage.exbridge.jp/simpletrack.php?url="+encodeURIComponent(location.href)+"&ref="+encodeURIComponent(document.referrer);s.async=true;document.head.appendChild(s)})();</script>';
    $graph = array(
        array('@type' => 'WebApplication', 'name' => $SITE, 'url' => $base . '/', 'applicationCategory' => 'GovernmentApplication', 'operatingSystem' => 'Web', 'inLanguage' => 'ja',
              'description' => '衆議院・参議院の質問主意書と政府答弁書を、ことばで引けるようにした道具。同じ論点で過去に何が聞かれ、政府が何と答え、どこが「お答えすることは困難」とされたかを1画面に並べる。要約も論評もしない。',
              'offers' => array('@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'JPY'),
              'publisher' => array('@type' => 'Organization', 'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/')),
        array('@type' => 'Dataset', 'name' => '質問主意書と政府答弁書（衆議院・参議院、第' . implode('〜', array_map('strval', array_slice(json_decode($META['sessions'] ?? '[]', true) ?: array(0), 0, 1))) . '回以降）',
              'description' => '衆議院「質問答弁情報」と参議院「質問主意書」の公開ページから、質問本文・答弁本文・提出日・転送日・答弁書受領日・提出者を機械的に抜き出したもの' . n($META['count'] ?? 0) . '件。',
              'url' => $base . '/data', 'inLanguage' => 'ja', 'creator' => array('@type' => 'Organization', 'name' => '衆議院・参議院'),
              'isBasedOn' => array($META['source_shugiin'] ?? '', $META['source_sangiin'] ?? ''),
              'distribution' => array(array('@type' => 'DataDownload', 'encodingFormat' => 'text/csv', 'contentUrl' => $base . '/data/questions.csv'))),
        array('@type' => 'FAQPage', 'mainEntity' => array(
            array('@type' => 'Question', 'name' => '質問主意書とは何ですか', 'acceptedAnswer' => array('@type' => 'Answer', 'text' => '国会議員が議長を経由して内閣に文書で質問する制度です（国会法74条）。内閣は原則7日以内に閣議決定した答弁書で答えます。このサイトは衆議院・参議院が公開している質問本文と答弁本文を、ことばで引ける形に並べています。')),
            array('@type' => 'Question', 'name' => '「答えていない型」とは何ですか', 'acceptedAnswer' => array('@type' => 'Answer', 'text' => '答弁書に現れる定型句（「お答えすることは困難である」「御質問の趣旨が必ずしも明らかではない」「承知していない」「検討してまいりたい」など）を語の一致で数えたものです。答弁の良し悪しの判定ではありません。同じ論点で過去にどの型の答弁が返ったかを、次の質問を書く前に確かめるためのものです。')),
            array('@type' => 'Question', 'name' => 'このサイトは質問を書いてくれますか', 'acceptedAnswer' => array('@type' => 'Answer', 'text' => '書きません。過去の質問と答弁を並べ、答弁書の定型句を数え、質問主意書の型（前提の事実・過去の答弁の引用・問い）を空欄つきで示すところまでです。文章を作るのは人です。')))),
    );
    if ($ld_extra) { $graph[] = $ld_extra; }
    echo '<script type="application/ld+json">' . json_encode(array('@context' => 'https://schema.org', '@graph' => $graph), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    echo '</head><body><header><div class="wrap"><a class="brand" href="' . h($SELF) . '/">' . h($SITE) . '</a><nav class="menu">';
    foreach (array('/' => 'ことばで探す', '/assist' => 'アシスト', '/giin' => '提出者から', '/pattern' => '答えていない型', '/data' => 'データ', '/about' => 'このサイトについて') as $u => $t) { echo '<a href="' . h($SELF . $u) . '">' . h($t) . '</a>'; }
    echo '</nav></div></header><main><div class="wrap">';
}
function foot_html() {
    global $SELF, $META, $STORE;
    echo '</div></main><footer><div class="wrap">';
    echo '<p class="src">出典: <a href="' . h($META['source_shugiin'] ?? '') . '" rel="nofollow">衆議院 質問答弁情報</a>・<a href="' . h($META['source_sangiin'] ?? '') . '" rel="nofollow">参議院 質問主意書</a>の公開ページから機械的に抜き出したもの（第' . h(implode('・', json_decode($META['sessions'] ?? '[]', true) ?: array())) . '回国会、' . n($META['count'] ?? 0) . '件、' . h($META['built'] ?? '') . '集計）。本文は原文のまま、要約も論評もしていません。</p>';
    echo '<p class="src">提供: <a href="https://exbridge.jp/">株式会社エクスブリッジ</a>（名古屋市）／<a href="' . h($STORE) . '">オンプレミス版</a>（議員事務所の中で、相談記録と一緒に動かす版）もあります。</p>';
    echo '</div></footer></body></html>';
}
function search_form($q = '', $action = '/search', $ph = 'ことばで探す（例: 遺族年金 養育費、土砂災害 警戒区域）') {
    global $SELF;
    echo '<form class="form" method="get" action="' . h($SELF . $action) . '"><input type="text" name="q" value="' . h($q) . '" placeholder="' . h($ph) . '" aria-label="検索語"><button class="btn" type="submit">探す</button></form>';
}
function tags($r) {
    global $PATTERNS, $SELF;
    $o = '<span class="tag h">' . h($r['house']) . ' 第' . (int)$r['session'] . '回 第' . (int)$r['no'] . '号</span>';
    foreach (ev($r['evasive_json']) as $k => $c) { if (isset($PATTERNS[$k])) $o .= '<a class="tag e" href="' . h($SELF . '/pattern/' . rawurlencode($k)) . '">' . h($PATTERNS[$k][0]) . ' ×' . (int)$c . '</a>'; }
    if ($r['status'] !== '答弁受理' && !$r['a_len']) { $o .= '<span class="tag">答弁書なし（' . h($r['status'] ?: '未受理') . '）</span>'; }
    return $o;
}
function q_card($r, $ts = array()) {
    global $SELF;
    echo '<div class="q"><div class="nm"><a href="' . h($SELF . '/q/' . $r['id']) . '">' . mark(h($r['title']), $ts) . '</a></div>';
    echo '<div class="meta">提出 ' . h(jdate($r['submit_date'])) . '　<a href="' . h($SELF . '/giin/' . rawurlencode($r['submitter'])) . '">' . h($r['submitter']) . '</a>' . ($r['kaiha'] ? '（' . h($r['kaiha']) . '）' : '') . '　答弁 ' . h(jdate($r['answer_date'])) . '</div>';
    echo '<div class="meta">' . tags($r) . '</div>';
    if ($ts) { echo '<div class="sn">質問: ' . mark(snippet($r['q_text'], $ts), $ts) . '</div>'; if ($r['a_text']) echo '<div class="sn">答弁: ' . mark(snippet($r['a_text'], $ts), $ts) . '</div>'; }
    echo '</div>';
}

// ── robots / sitemap / llms / api / data ────────────────
if ($path === '/robots.txt') { header('Content-Type: text/plain; charset=UTF-8'); echo "User-agent: *\nAllow: /\nSitemap: https://kurage.exbridge.jp{$SELF}/sitemap.xml\n"; exit; }
if ($path === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=UTF-8'); $base = 'https://kurage.exbridge.jp' . $SELF; $lm = $META['built'] ?? date('Y-m-d');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.w3.org/1999/xmlns/sitemap/0.9">';
    foreach (array('/', '/assist', '/giin', '/pattern', '/data', '/about') as $u) { echo '<url><loc>' . h($base . $u) . '</loc><lastmod>' . $lm . '</lastmod></url>'; }
    foreach ($db->query('SELECT id, COALESCE(NULLIF(answer_date, ""), submit_date) d FROM q ORDER BY submit_date DESC') as $r) { echo '<url><loc>' . h($base . '/q/' . $r['id']) . '</loc><lastmod>' . h($r['d'] ?: $lm) . '</lastmod></url>'; }
    foreach ($db->query('SELECT submitter, MAX(submit_date) d FROM q GROUP BY submitter') as $r) { echo '<url><loc>' . h($base . '/giin/' . rawurlencode($r['submitter'])) . '</loc><lastmod>' . h($r['d'] ?: $lm) . '</lastmod></url>'; }
    echo '</urlset>'; exit;
}
if ($path === '/llms.txt') {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "# $SITE\n\n衆議院・参議院の質問主意書と政府答弁書" . n($META['count'] ?? 0) . "件（第" . implode('〜', array_slice(json_decode($META['sessions'] ?? '[]', true) ?: array(), 0, 1)) . "回国会以降）を、ことばで引ける形にした道具。要約も論評もしない。\n\n";
    echo "- 検索: https://kurage.exbridge.jp{$SELF}/search?q=<語>\n- アシスト（同じ論点の質問・答弁・答えていない型を1画面に）: https://kurage.exbridge.jp{$SELF}/assist?q=<語>\n- API: https://kurage.exbridge.jp{$SELF}/api?q=<語>（JSON）\n- CSV: https://kurage.exbridge.jp{$SELF}/data/questions.csv\n";
    echo "- 「答えていない型」は答弁書の定型句の語の一致で、答弁の評価ではない。\n- 出典: 衆議院 質問答弁情報・参議院 質問主意書（出所明示のうえ転載）。\n"; exit;
}
if ($path === '/api') {
    header('Content-Type: application/json; charset=UTF-8'); $rows = search($db, $_GET['q'] ?? '', 50); $out = array();
    foreach ($rows as $r) { $out[] = array('id' => $r['id'], 'house' => $r['house'], 'session' => (int)$r['session'], 'no' => (int)$r['no'], 'title' => $r['title'], 'submitter' => $r['submitter'], 'submit_date' => $r['submit_date'], 'answer_date' => $r['answer_date'], 'evasive' => ev($r['evasive_json']), 'url' => 'https://kurage.exbridge.jp' . $SELF . '/q/' . $r['id']); }
    echo json_encode(array('q' => $_GET['q'] ?? '', 'count' => count($out), 'items' => $out, 'note' => '本文は /q/{id} で。evasive は答弁書の定型句の一致で、答弁の評価ではありません。'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}
if ($path === '/data/questions.csv') {
    header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="kshuisho_questions.csv"'); echo "\xEF\xBB\xBF";
    $o = fopen('php://output', 'w'); fputcsv($o, array('id', '院', '回次', '番号', '件名', '提出者', '会派', '提出日', '転送日', '答弁書受領日', '経過状況', '答弁の型', '質問URL', '答弁URL'));
    foreach ($db->query('SELECT * FROM q ORDER BY house, session, no') as $r) { fputcsv($o, array($r['id'], $r['house'], $r['session'], $r['no'], $r['title'], $r['submitter'], $r['kaiha'], $r['submit_date'], $r['transfer_date'], $r['answer_date'], $r['status'], implode(' ', array_keys(ev($r['evasive_json']))), $r['q_url'], $r['a_url'])); }
    exit;
}

// ── 質問1件 ─────────────────────────────────────────────
if (preg_match('#^/q/((?:shu|san)-\d+-\d+)$#', $path, $m)) {
    $st = $db->prepare('SELECT * FROM q WHERE id=?'); $st->execute(array($m[1])); $r = $st->fetch();
    if (!$r) { http_response_code(404); head_html('見つかりません｜' . $SITE, '', '/'); echo '<h1>その質問主意書は収録していません</h1>'; foot_html(); exit; }
    $ev = ev($r['evasive_json']);
    $desc = $r['house'] . '第' . $r['session'] . '回国会 質問第' . $r['no'] . '号「' . $r['title'] . '」（' . $r['submitter'] . '、' . jdate($r['submit_date']) . '提出）の質問本文と政府答弁書' . ($r['a_text'] ? '（' . jdate($r['answer_date']) . '受領・' . (int)$r['a_sections'] . '項目' . ($ev ? '・' . implode('、', array_map(function ($k) use ($PATTERNS) { return $PATTERNS[$k][0]; }, array_keys($ev))) : '') . '）' : '（答弁書未受理）') . '。原文のまま。';
    head_html($r['title'] . '（' . $r['house'] . '・' . $r['submitter'] . '・' . jdate($r['submit_date']) . '）質問本文と政府答弁書｜' . $SITE, $desc, '/q/' . $r['id']);
    echo '<p class="src"><a href="' . h($SELF) . '/">ことばで探す</a> › ' . h($r['house']) . ' 第' . (int)$r['session'] . '回</p>';
    echo '<h1>' . h($r['title']) . '</h1>';
    echo '<p class="lead">' . tags($r) . '</p>';
    echo '<div class="grid"><div class="card"><div class="k">提出者</div><div class="v" style="font-size:18px"><a href="' . h($SELF . '/giin/' . rawurlencode($r['submitter'])) . '">' . h($r['submitter']) . '</a></div><div class="s">' . h($r['kaiha'] ?: $r['house']) . '</div></div>'
       . '<div class="card"><div class="k">提出日</div><div class="v" style="font-size:18px">' . h(jdate($r['submit_date'])) . '</div><div class="s">内閣転送 ' . h(jdate($r['transfer_date'])) . '</div></div>'
       . '<div class="card"><div class="k">答弁書受領日</div><div class="v" style="font-size:18px">' . h(jdate($r['answer_date'])) . '</div><div class="s">' . ($r['submit_date'] && $r['answer_date'] ? '提出から' . (int)((strtotime($r['answer_date']) - strtotime($r['submit_date'])) / 86400) . '日' : h($r['status'])) . '</div></div>'
       . '<div class="card"><div class="k">答弁の項目数</div><div class="v" style="font-size:18px">' . (int)$r['a_sections'] . '</div><div class="s">質問 ' . n($r['q_len']) . '字／答弁 ' . n($r['a_len']) . '字</div></div></div>';
    echo '<div class="two"><div class="panel"><h2 style="margin-top:0">質問本文</h2><div class="body">' . paras($r['q_text']) . '</div><p class="src"><a href="' . h($r['q_url']) . '" rel="nofollow">' . h($r['house']) . 'の原文</a>・<a href="' . h($r['progress_url']) . '" rel="nofollow">経過</a></p></div>';
    echo '<div class="panel"><h2 style="margin-top:0">政府答弁書</h2>' . ($r['a_text'] ? '<div class="body">' . paras($r['a_text']) . '</div><p class="src"><a href="' . h($r['a_url']) . '" rel="nofollow">' . h($r['house']) . 'の原文</a></p>' : '<p>答弁書はまだ公開されていません（' . h($r['status'] ?: '未受理') . '）。</p>') . '</div></div>';
    if ($ev) { echo '<div class="panel"><h3>この答弁書に出てくる定型句</h3><p>'; foreach ($ev as $k => $c) { echo '<a class="tag e" href="' . h($SELF . '/pattern/' . rawurlencode($k)) . '">' . h($PATTERNS[$k][0]) . ' ×' . (int)$c . '</a>'; } echo '</p><p class="src">語の一致で数えたものです。答弁の評価ではありません。同じ型の答弁が過去にどれだけあるかは、型を押すと出ます。</p></div>'; }
    // 件名の語が重なる他の質問
    $kw = array_values(array_filter(preg_split('/に関する|について|の|と|・|、|及び|等|質問主意書|再/u', $r['title']), function ($s) { return mb_strlen($s) >= 2; }));
    if ($kw) { $rel = search($db, implode(' ', array_slice($kw, 0, 2)), 8); $rel = array_filter($rel, function ($x) use ($r) { return $x['id'] !== $r['id']; }); if ($rel) { echo '<h2>件名の語が重なる質問主意書</h2>'; foreach ($rel as $x) q_card($x); } }
    echo '<div class="panel"><h3>この論点で次の質問を書くなら</h3><p><a class="btn" href="' . h($SELF . '/assist?q=' . rawurlencode(implode(' ', array_slice($kw, 0, 2)))) . '">アシストで並べる</a></p></div>';
    foot_html(); exit;
}

// ── 提出者 ──────────────────────────────────────────────
if ($path === '/giin') {
    head_html('提出者から探す（質問主意書の提出者一覧）｜' . $SITE, '質問主意書の提出者ごとに、件数と答弁の型を並べました。', '/giin');
    echo '<h1>提出者から探す</h1>';
    echo '<div class="tscroll"><table class="t"><tr><th>提出者</th><th>院</th><th class="n">質問</th><th class="n">答弁「困難」</th><th class="n">答弁「趣旨不明」</th><th>最新</th></tr>';
    foreach ($db->query('SELECT submitter, house, count(*) n, max(submit_date) d, sum(evasive_json LIKE \'%"困難"%\') k, sum(evasive_json LIKE \'%"趣旨不明"%\') f FROM q GROUP BY submitter, house ORDER BY n DESC, d DESC') as $r) {
        echo '<tr><td><a href="' . h($SELF . '/giin/' . rawurlencode($r['submitter'])) . '">' . h($r['submitter']) . '</a></td><td>' . h($r['house']) . '</td><td class="n">' . n($r['n']) . '</td><td class="n">' . n($r['k']) . '</td><td class="n">' . n($r['f']) . '</td><td>' . h(jdate($r['d'])) . '</td></tr>';
    }
    echo '</table></div>'; foot_html(); exit;
}
if (preg_match('#^/giin/(.+)$#', $path, $m)) {
    $st = $db->prepare('SELECT id, house, session, no, title, submitter, kaiha, submit_date, answer_date, status, evasive_json, a_len, q_text, a_text FROM q WHERE submitter=? ORDER BY submit_date DESC'); $st->execute(array($m[1])); $rows = $st->fetchAll();
    if (!$rows) { http_response_code(404); head_html('見つかりません｜' . $SITE, '', '/giin'); echo '<h1>その提出者の質問主意書は収録していません</h1>'; foot_html(); exit; }
    head_html($m[1] . 'の質問主意書' . count($rows) . '件と政府答弁書｜' . $SITE, $m[1] . '（' . $rows[0]['house'] . ($rows[0]['kaiha'] ? '・' . $rows[0]['kaiha'] : '') . '）が提出した質問主意書' . count($rows) . '件を、提出日・件名・答弁の型つきで並べました。', '/giin/' . rawurlencode($m[1]));
    echo '<h1>' . h($m[1]) . 'の質問主意書</h1><p class="lead">' . h($rows[0]['house']) . ($rows[0]['kaiha'] ? '・' . h($rows[0]['kaiha']) : '') . '。' . count($rows) . '件。新しい順。</p>';
    foreach ($rows as $r) q_card($r); foot_html(); exit;
}

// ── 型 ──────────────────────────────────────────────────
if ($path === '/pattern') {
    head_html('答えていない型（答弁書の定型句）一覧｜' . $SITE, '政府答弁書に出てくる定型句を語の一致で数え、型ごとに件数を並べました。', '/pattern');
    echo '<h1>答えていない型</h1><p class="lead">答弁書に出てくる定型句を、語の一致で数えたものです。<strong>答弁の評価ではありません。</strong>同じ論点で過去にどの型が返ったかを、次の質問を書く前に確かめるためのものです。</p>';
    $tot = (int)$db->query('SELECT count(*) FROM q WHERE a_len>0')->fetchColumn();
    echo '<div class="grid">';
    foreach ($PATTERNS as $k => $d) { $c = (int)$db->query('SELECT count(*) FROM q WHERE evasive_json LIKE ' . $db->quote('%"' . $k . '"%'))->fetchColumn(); echo '<a class="card" href="' . h($SELF . '/pattern/' . rawurlencode($k)) . '" style="text-decoration:none;color:inherit"><div class="k">' . h($d[0]) . '</div><div class="v">' . n($c) . '<span style="font-size:14px">件</span></div><div class="s">答弁書' . n($tot) . '件中 ' . ($tot ? round($c / $tot * 100) : 0) . '%</div></a>'; }
    echo '</div>'; foot_html(); exit;
}
if (preg_match('#^/pattern/(.+)$#', $path, $m) && isset($PATTERNS[$m[1]])) {
    $k = $m[1]; $st = $db->prepare('SELECT id, house, session, no, title, submitter, kaiha, submit_date, answer_date, status, evasive_json, a_len, q_text, a_text FROM q WHERE evasive_json LIKE ? ORDER BY submit_date DESC LIMIT 300'); $st->execute(array('%"' . $k . '"%')); $rows = $st->fetchAll();
    head_html('「' . $PATTERNS[$k][0] . '」と答えられた質問主意書' . count($rows) . '件｜' . $SITE, $PATTERNS[$k][1] . '。新しい順に' . count($rows) . '件。', '/pattern/' . rawurlencode($k));
    echo '<h1>「' . h($PATTERNS[$k][0]) . '」の型</h1><p class="lead">' . h($PATTERNS[$k][1]) . '。' . count($rows) . '件（新しい順）。語の一致で、答弁の評価ではありません。</p>';
    foreach ($rows as $r) q_card($r); foot_html(); exit;
}

// ── 回次 ────────────────────────────────────────────────
if (preg_match('#^/session/(衆議院|参議院)/(\d+)$#u', $path, $m)) {
    $st = $db->prepare('SELECT id, house, session, no, title, submitter, kaiha, submit_date, answer_date, status, evasive_json, a_len, q_text, a_text FROM q WHERE house=? AND session=? ORDER BY no'); $st->execute(array($m[1], (int)$m[2])); $rows = $st->fetchAll();
    head_html($m[1] . ' 第' . $m[2] . '回国会の質問主意書' . count($rows) . '件｜' . $SITE, '', '/session/' . $m[1] . '/' . $m[2]);
    echo '<h1>' . h($m[1]) . ' 第' . (int)$m[2] . '回国会</h1><p class="lead">' . count($rows) . '件。番号順。</p>'; foreach ($rows as $r) q_card($r); foot_html(); exit;
}

// ── 検索 ────────────────────────────────────────────────
if ($path === '/search') {
    $q = trim((string)($_GET['q'] ?? '')); $ts = terms($q); $rows = $ts ? search($db, $q) : array();
    head_html(($q !== '' ? '「' . $q . '」の質問主意書と答弁書 ' . count($rows) . '件' : 'ことばで探す') . '｜' . $SITE, '質問本文・答弁本文・件名から「' . $q . '」を含む質問主意書を並べました。', '/search');
    echo '<h1>' . ($q !== '' ? '「' . h($q) . '」' : 'ことばで探す') . '</h1>'; search_form($q);
    if ($q !== '') {
        echo '<p class="lead">' . count($rows) . '件' . (count($rows) >= 200 ? '（新しい順に200件まで）' : '') . '。質問本文・答弁本文・件名のどれかに全部の語を含むものです。 <a class="btn ghost" href="' . h($SELF . '/assist?q=' . rawurlencode($q)) . '">この論点をアシストで並べる</a></p>';
        if (!$rows) echo '<div class="panel"><p>その語を含む質問主意書は収録分にありません。語を減らすか、言い換えてみてください（例: 「養育費」だけ）。</p></div>';
        foreach ($rows as $r) q_card($r, $ts);
    }
    foot_html(); exit;
}

// ── アシスト ────────────────────────────────────────────
if ($path === '/assist') {
    $q = trim((string)($_GET['q'] ?? '')); $ts = terms($q); $rows = $ts ? search($db, $q, 60) : array();
    head_html(($q !== '' ? '「' . $q . '」で質問主意書を書く前に — 過去の質問・答弁・答えていない型' : '質問主意書アシスト') . '｜' . $SITE, '同じ論点で過去に何が聞かれ、政府が何と答え、どこが「お答えすることは困難」とされたかを1画面に並べます。文章は作りません。', '/assist');
    echo '<h1>' . ($q !== '' ? '「' . h($q) . '」で書く前に' : '質問主意書アシスト') . '</h1>';
    echo '<p class="lead">論点の語を入れると、<b>過去の質問</b>・<b>政府の答弁</b>・<b>答えていない型</b>を1画面に並べます。文章は作りません。並べたものを見て、まだ聞かれていない問いを人が決めるための道具です。</p>';
    search_form($q, '/assist', '論点の語（例: 遺族年金 養育費）');
    if ($q !== '') {
        if (!$rows) { echo '<div class="panel"><p>収録分に、その語を含む質問主意書はありません。<b>過去に聞かれていない論点</b>の可能性があります（収録は第' . h(implode('〜', array_map('strval', array(min(json_decode($META['sessions'] ?? '[0]', true)), max(json_decode($META['sessions'] ?? '[0]', true)))))) . '回国会）。語を減らして再検索もお試しください。</p></div>'; }
        else {
            $na = 0; $evs = array(); $subs = array();
            foreach ($rows as $r) { if ($r['a_text']) $na++; foreach (ev($r['evasive_json']) as $k => $c) { $evs[$k] = ($evs[$k] ?? 0) + 1; } $subs[$r['submitter']] = ($subs[$r['submitter']] ?? 0) + 1; }
            arsort($evs); arsort($subs);
            echo '<div class="grid"><div class="card"><div class="k">過去の質問主意書</div><div class="v">' . count($rows) . '<span style="font-size:14px">件</span></div><div class="s">' . h(jdate(end($rows)['submit_date'])) . '〜' . h(jdate($rows[0]['submit_date'])) . '</div></div>'
               . '<div class="card"><div class="k">答弁書あり</div><div class="v">' . $na . '<span style="font-size:14px">件</span></div><div class="s">提出者 ' . count($subs) . '人（多い順: ' . h(implode('、', array_slice(array_keys($subs), 0, 3))) . '）</div></div>'
               . '<div class="card"><div class="k">答えていない型が出た答弁</div><div class="v">' . ($evs ? array_sum(array_map(function ($k) use ($rows) { return 1; }, array())) + count(array_filter($rows, function ($r) { return ev($r['evasive_json']); })) : 0) . '<span style="font-size:14px">件</span></div><div class="s">' . h(implode('、', array_map(function ($k, $c) use ($PATTERNS) { return $PATTERNS[$k][0] . ' ' . $c; }, array_keys($evs), $evs))) . '</div></div></div>';
            // 1. 政府が答えた文（語を含む答弁の段落）
            echo '<h2>1. 政府はこう答えている（語を含む答弁の段落・原文）</h2><p class="src">答弁書の段落のうち、検索語を含むものだけを新しい順に抜きました。要約していません。</p>';
            $shown = 0;
            foreach ($rows as $r) {
                if (!$r['a_text']) continue;
                foreach (explode("\n", $r['a_text']) as $pg) { $hit = true; foreach ($ts as $t) { if (mb_strpos($pg, $t) === false) { $hit = false; break; } } if (!$hit || mb_strlen($pg) < 20) continue;
                    echo '<div class="q"><div class="sn">' . mark(h($pg), $ts) . '</div><div class="meta"><a href="' . h($SELF . '/q/' . $r['id']) . '">' . h($r['title']) . '</a>　' . h($r['submitter']) . '　答弁 ' . h(jdate($r['answer_date'])) . '</div></div>';
                    if (++$shown >= 12) break 2; }
            }
            if (!$shown) echo '<div class="panel"><p>答弁の段落に検索語そのものは出てきません（質問側だけに出ています）。答弁は言い換えて答えることが多いので、下の一覧から本文をご覧ください。</p></div>';
            // 2. 答えていない型
            $evrows = array_filter($rows, function ($r) { return ev($r['evasive_json']); });
            echo '<h2>2. 答えていない型が返った質問（' . count($evrows) . '件）</h2><p class="src">「お答えすることは困難」「趣旨が明らかではない」などの定型句が答弁書にあるもの。語の一致で、答弁の評価ではありません。同じ聞き方をすると同じ型が返りやすい、という材料です。</p>';
            foreach (array_slice(array_values($evrows), 0, 10) as $r) q_card($r, $ts);
            // 3. 全部
            echo '<h2>3. 過去の質問主意書（' . count($rows) . '件・新しい順）</h2>';
            foreach ($rows as $r) q_card($r, $ts);
            // 4. 型
            $y = date('Y'); $sess = max(json_decode($META['sessions'] ?? '[0]', true));
            echo '<h2>4. 質問主意書の型（空欄を人が埋める）</h2><div class="panel"><pre class="draft">' . h("○○に関する質問主意書\n\n　提出者　　（氏名）\n\n○○に関する質問主意書\n\n【前提の事実】\n　（出典と日付つきで、争いのない事実を書く。例: 「令和○年○月○日の○○省の発表によれば…」）\n\n【過去の答弁】\n　（上の「1. 政府はこう答えている」から、引用する答弁書と日付を書く。例: 「令和○年○月○日受領の答弁書（内閣衆質○第○号）では…と答弁している」）\n\n一　（過去の答弁で「お答えすることは困難」とされた点を、数字・期間・対象を限定して聞き直す）\n\n二　（答弁が「検討してまいりたい」だった点は、検討の主体・期限・結論の公表方法を聞く）\n\n三　（答弁が「承知していない」だった点は、把握する予定の有無と、把握しない理由を聞く）\n\n　右質問する。") . '</pre><p class="src">これは型です。文章は作りません。国会法74条の質問主意書は議長の承認を経て内閣に転送され、原則7日以内に答弁書が閣議決定されます。</p></div>';
        }
    } else {
        echo '<div class="panel"><h3>使い方</h3><ol><li>論点の語を1〜3つ入れる（例: 「学童保育 待機」「土砂災害 警戒区域 指定」）</li><li>過去の質問と、政府の答弁の段落（原文）と、答えていない型が並ぶ</li><li>まだ聞かれていない問い、答えが「困難」とされた問いを人が選ぶ</li><li>下の型に埋めて、事務所で仕上げる</li></ol></div>';
    }
    foot_html(); exit;
}

// ── データ・about ────────────────────────────────────────
if ($path === '/data') {
    head_html('データ（CSV・API）｜' . $SITE, '質問主意書' . n($META['count'] ?? 0) . '件の一覧CSVと、ことばで引けるJSON API。', '/data');
    echo '<h1>データ</h1><div class="panel"><ul class="plain"><li><a href="' . h($SELF . '/data/questions.csv') . '">questions.csv</a> — ' . n($META['count'] ?? 0) . '件（院・回次・番号・件名・提出者・会派・日付・答弁の型・原文URL）</li><li>JSON API: <code>' . h('https://kurage.exbridge.jp' . $SELF . '/api?q=語') . '</code></li><li><a href="' . h($SELF . '/llms.txt') . '">llms.txt</a></li></ul></div>';
    echo '<div class="panel"><h3>収録</h3><div class="tscroll"><table class="t"><tr><th>院</th><th>回次</th><th class="n">質問</th><th class="n">答弁あり</th><th>期間</th></tr>';
    foreach ($db->query('SELECT house, session, count(*) n, sum(a_len>0) a, min(submit_date) f, max(submit_date) t FROM q GROUP BY house, session ORDER BY session DESC, house') as $r) { echo '<tr><td>' . h($r['house']) . '</td><td><a href="' . h($SELF . '/session/' . rawurlencode($r['house']) . '/' . (int)$r['session']) . '">第' . (int)$r['session'] . '回</a></td><td class="n">' . n($r['n']) . '</td><td class="n">' . n($r['a']) . '</td><td>' . h(jdate($r['f'])) . '〜' . h(jdate($r['t'])) . '</td></tr>'; }
    echo '</table></div></div>'; foot_html(); exit;
}
if ($path === '/about') {
    head_html('このサイトについて｜' . $SITE, 'データの出どころ、分かること、分からないこと。', '/about');
    echo '<h1>このサイトについて</h1>';
    echo '<div class="panel"><h3>何ができるか</h3><ul class="plain"><li>質問主意書の本文と政府答弁書の本文を、ことばで探す（件名だけでなく本文の中も）</li><li>同じ論点で、過去に誰が何を聞き、政府が何と答えたかを1画面に並べる</li><li>答弁書の定型句（「お答えすることは困難」など）を型として数える</li><li>提出者ごと・回次ごとに一覧する。CSV と API で持ち出す</li></ul></div>';
    echo '<div class="panel"><h3>しないこと</h3><ul class="plain"><li>要約・論評・答弁の良し悪しの判定。「答えていない型」は語の一致であって評価ではありません</li><li>文章を作ること。型に空欄を示すところまでです</li><li>外部のAIやAPIに送ること。検索は置いた場所で完結します</li></ul></div>';
    echo '<div class="panel"><h3>出典と転載</h3><p>衆議院「質問答弁情報」と参議院「質問主意書」の公開ページから、質問本文・答弁本文・日付・提出者を機械的に抜き出しています。衆議院ウェブサイトは「著作権法上認められた行為として、適宜の方法により出所を明示することにより、引用・転載・複製を行うことができます」としており、各ページに原文へのリンクを置いています。答弁書は閣議決定された行政文書です。</p><p class="src">収録: 第' . h(implode('・', json_decode($META['sessions'] ?? '[]', true) ?: array())) . '回国会（' . n($META['count'] ?? 0) . '件、' . h($META['built'] ?? '') . '集計）。古い回次は順次足します。</p></div>';
    echo '<div class="panel"><h3>オンプレミス版</h3><p>議員事務所の中で、相談記録（Kurage 制度ナビ）と一緒に置いて、事務所だけの論点メモを本文に紐づける版があります。PHP 1ファイルと SQLite で動き、外に何も送りません。</p><p><a class="btn" href="' . h($STORE) . '">商品ページを見る</a></p></div>';
    foot_html(); exit;
}

// ── トップ ──────────────────────────────────────────────
if ($path !== '') { http_response_code(404); head_html('見つかりません｜' . $SITE, '', '/'); echo '<h1>ページがありません</h1><p><a href="' . h($SELF) . '/">トップへ</a></p>'; foot_html(); exit; }
$tot = (int)($META['count'] ?? 0); $na = (int)($META['answered'] ?? 0);
$k = (int)$db->query('SELECT count(*) FROM q WHERE evasive_json LIKE \'%"困難"%\'')->fetchColumn();
head_html('質問主意書と政府答弁書をことばで探す｜' . $SITE, '衆議院・参議院の質問主意書' . n($tot) . '件と政府答弁書を、本文の中まで含めてことばで引けます。同じ論点で過去に何が聞かれ、政府が何と答え、どこが「お答えすることは困難」とされたかを1画面に。要約も論評もしません。', '/');
echo '<h1>質問主意書と政府答弁書を、ことばで探す</h1>';
echo '<p class="lead">衆議院・参議院の質問主意書<b>' . n($tot) . '件</b>と政府答弁書<b>' . n($na) . '件</b>（第' . h(implode('〜', array_map('strval', array(min(json_decode($META['sessions'] ?? '[0]', true)), max(json_decode($META['sessions'] ?? '[0]', true)))))) . '回国会）を、件名だけでなく<b>本文の中まで</b>ことばで引けます。同じ論点で過去に誰が何を聞き、政府が何と答えたかが並びます。要約も論評もしません。</p>';
echo '<div class="panel">'; search_form(); echo '</div>';
echo '<div class="grid"><div class="card"><div class="k">質問主意書</div><div class="v">' . n($tot) . '<span style="font-size:14px">件</span></div><div class="s">答弁書あり ' . n($na) . '件</div></div>'
   . '<div class="card"><div class="k">「お答えすることは困難」と答えられた</div><div class="v">' . n($k) . '<span style="font-size:14px">件</span></div><div class="s">答弁書の' . ($na ? round($k / $na * 100) : 0) . '%</div></div>'
   . '<div class="card"><div class="k">提出者</div><div class="v">' . n($db->query('SELECT count(DISTINCT submitter) FROM q')->fetchColumn()) . '<span style="font-size:14px">人</span></div><div class="s">衆参の議員</div></div>'
   . '<div class="card"><div class="k">集計</div><div class="v" style="font-size:18px">' . h($META['built'] ?? '') . '</div><div class="s">毎日、新しい答弁書を足します</div></div></div>';
echo '<h2>質問を書く前に、同じ論点を並べる</h2><div class="panel"><p>論点の語を入れると、過去の質問・政府の答弁の段落（原文）・答えていない型を1画面に並べます。文章は作りません。</p>'; search_form('', '/assist', '論点の語（例: 遺族年金 養育費）'); echo '</div>';
echo '<h2>新しい質問主意書</h2>';
foreach ($db->query('SELECT id, house, session, no, title, submitter, kaiha, submit_date, answer_date, status, evasive_json, a_len, q_text, a_text FROM q ORDER BY submit_date DESC, no DESC LIMIT 12') as $r) q_card($r);
echo '<h2>答えていない型</h2><p class="src">答弁書の定型句を語の一致で数えたもの。答弁の評価ではありません。</p><div class="grid">';
foreach ($PATTERNS as $key => $d) { $c = (int)$db->query('SELECT count(*) FROM q WHERE evasive_json LIKE ' . $db->quote('%"' . $key . '"%'))->fetchColumn(); echo '<a class="card" href="' . h($SELF . '/pattern/' . rawurlencode($key)) . '" style="text-decoration:none;color:inherit"><div class="k">' . h($d[0]) . '</div><div class="v">' . n($c) . '<span style="font-size:14px">件</span></div></a>'; }
echo '</div>';
foot_html();
