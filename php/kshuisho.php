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
 * 「似た質問主意書」は scripts/build_similar.py が事前に計算した表（similar）を読むだけ。表が無ければ出さない。
 *
 * LLM（OpenAI 互換の chat/completions）を kshuisho_config.php に設定したときだけ、次の2つが増える。
 *   - 言い換えで探す（/search・/assist）… 入れた語の言い換えを LLM に出させ、その語でも探して別枠で足す
 *   - 下書きを作る（/assist）… 集めた原文を番号つきで渡し、質問主意書の下書きを作らせる。
 *     「」の引用が渡した原文に一字一句含まれるか、出典番号が渡したものか、前提の事実の数字が原文にあるかを
 *     サーバーで検査し、外れたら下書きを出さない。下書きは保存しない。
 * 設定が無ければ、この2つは画面に出ず、外部には何も送らない（これまでと同じ動き）。
 *
 * heteml に置くときは、その階層の .htaccess に `AddHandler php-script .php` が要る（既定はPHP5.6）。
 * PHP 8 + PDO SQLite だけで動く。
 */
declare(strict_types=1);
mb_internal_encoding('UTF-8');

$SITE = 'Kurage 質問主意書アシスト';
$SELF = '/kshuisho.php';
$OGP  = 'https://kurage.exbridge.jp/images/ogp/kshuisho.png';
$STORE = 'https://kappstore.exbridge.jp/app.php?id=9701841975d2ed6a&ref=kshuisho';
$DBP  = __DIR__ . '/kshuisho_data/kshuisho.sqlite';
// LLM の設定（任意）。無ければ「言い換えで探す」「下書きを作る」は出ない。kshuisho_config.example.php を参照
$CFG = array();
if (is_file(__DIR__ . '/kshuisho_config.php')) { $c = include __DIR__ . '/kshuisho_config.php'; if (is_array($c)) $CFG = $c; }
$LLM_ON = !empty($CFG['llm_base']);
// 答えていない型の語（scripts/build_db.py の EVASIVE と同じ）。下書きの材料で、その型が出る段落を拾うのに使う
$EVRE = array(
    '困難' => 'お答えすることは困難|お答えすることが困難|お答えは困難|困難である',
    '趣旨不明' => '趣旨が必ずしも明らかではない|意味するところが必ずしも明らかではない|意味するところが明らかではない|必ずしも明らかではない',
    '承知せず' => '承知していない|承知しておらず|把握していない|把握しておらず',
    '検討中' => '検討してまいりたい|検討を進めてまいりたい|検討しているところ|検討中である',
    '仮定' => '仮定の御質問|仮定の質問',
    '個別事案' => '個別の事案|個々の事案|個別具体的な',
);
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

if (defined('KSHUISHO_LIB')) return;   // scripts/test_draft_check.php が関数だけを読むとき
try { $db = new PDO('sqlite:' . $DBP); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); }
catch (Exception $e) { http_response_code(503); header('Content-Type: text/plain; charset=UTF-8'); echo "データベースがありません。scripts/build_db.py で作って kshuisho_data/ に置いてください。"; exit; }
$META = array(); foreach ($db->query('SELECT k, v FROM meta') as $r) { $META[$r['k']] = $r['v']; }

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (strpos($path, $SELF) === 0) { $path = substr($path, strlen($SELF)); }
$path = '/' . trim(rawurldecode((string)$path), '/');
$path = $path === '/' ? '' : $path;

// ── 検索 ───────────────────────────────────────────────
function terms($q) { $q = trim(preg_replace('/[\s　]+/u', ' ', (string)$q)); return $q === '' ? array() : array_slice(array_unique(explode(' ', $q)), 0, 5); }

/** 絞り込み条件を $_GET から。値は必ずここで検めてから SQL に渡す。 */
function get_filters() {
    global $PATTERNS;
    $house = (string)($_GET['house'] ?? '');
    $pat = (string)($_GET['pat'] ?? '');
    return array(
        'house'   => ($house === '衆議院' || $house === '参議院') ? $house : '',
        'session' => max(0, (int)($_GET['session'] ?? 0)),
        'giin'    => trim((string)($_GET['giin'] ?? '')),
        'pat'     => isset($PATTERNS[$pat]) ? $pat : '',
        'ans'     => (($_GET['ans'] ?? '') === '1') ? '1' : '',
    );
}
function has_filters($f) { return $f['house'] || $f['session'] || $f['giin'] !== '' || $f['pat'] || $f['ans']; }
/** 絞り込みを URL のクエリに戻す（$q を差し替えられる） */
function filter_qs($f, $q = null) {
    $p = array();
    if ($q !== null && $q !== '') $p['q'] = $q;
    foreach (array('house', 'session', 'giin', 'pat', 'ans') as $k) { if (!empty($f[$k])) $p[$k] = $f[$k]; }
    return $p ? '?' . http_build_query($p) : '';
}
/** ことば（AND）と絞り込みの両方で引く。どちらも無ければ何も返さない。 */
function search($db, $q, $limit = 200, $f = null) {
    $ts = terms($q); $f = $f ?: array('house' => '', 'session' => 0, 'giin' => '', 'pat' => '', 'ans' => '');
    $w = array(); $a = array();
    foreach ($ts as $t) { $w[] = '(title LIKE ? OR q_text LIKE ? OR a_text LIKE ?)'; $a[] = "%$t%"; $a[] = "%$t%"; $a[] = "%$t%"; }
    if ($f['house'])        { $w[] = 'house = ?';            $a[] = $f['house']; }
    if ($f['session'])      { $w[] = 'session = ?';          $a[] = (int)$f['session']; }
    if ($f['giin'] !== '')  { $w[] = 'submitter LIKE ?';     $a[] = '%' . $f['giin'] . '%'; }
    if ($f['pat'])          { $w[] = 'evasive_json LIKE ?';  $a[] = '%"' . $f['pat'] . '"%'; }
    if ($f['ans'])          { $w[] = 'a_len > 0'; }
    if (!$w) return array();
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
    global $SELF, $SITE, $OGP, $META, $LLM_ON;
    $base = 'https://kurage.exbridge.jp' . $SELF;
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title><meta name="description" content="' . h($desc) . '">';
    echo '<link rel="canonical" href="' . h($base . $canon) . '">';
    echo '<meta property="og:title" content="' . h($title) . '"><meta property="og:description" content="' . h($desc) . '"><meta property="og:type" content="website"><meta property="og:image" content="' . h($OGP) . '"><meta property="og:site_name" content="' . h($SITE) . '"><meta property="og:url" content="' . h($base . $canon) . '">';
    echo '<meta property="og:locale" content="ja_JP">';
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
       . '.form{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.fp{margin:0 0 18px}.fr{display:flex;gap:10px 14px;flex-wrap:wrap;align-items:center;margin-top:10px;padding:12px 14px;background:#fff;border:1px solid var(--line);border-radius:10px}.fr label{font-size:13px;color:var(--mut);font-weight:700;display:flex;gap:6px;align-items:center}.fr select,.fr input[type=text]{font:inherit;font-size:15px;font-weight:400;color:var(--ink);padding:7px 9px;border:1px solid var(--line);border-radius:8px;background:#fff;max-width:100%;flex:0 1 auto;min-width:0}.fr label.cb{font-weight:400;font-size:14px}.fr .btn{padding:8px 16px;font-size:15px}.nf{margin:0 0 18px}input[type=text]{flex:1 1 260px;min-width:0;font-size:17px;padding:12px 14px;border:2px solid var(--line);border-radius:10px}'
       . '.btn{display:inline-block;background:var(--teal);color:#fff;border:0;border-radius:10px;padding:12px 20px;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}.btn.ghost{background:#fff;color:var(--teal-d);border:1px solid var(--line)}'
       . '.src{font-size:12.5px;color:var(--mut);line-height:1.8}.tscroll{overflow-x:auto}table.t{width:100%;border-collapse:collapse;font-size:14px;min-width:460px}table.t th,table.t td{border-bottom:1px solid var(--line);padding:8px 10px;text-align:left;vertical-align:top}table.t th{color:var(--mut);font-size:12px}td.n,th.n{text-align:right;font-variant-numeric:tabular-nums}'
       . '.q{border:1px solid var(--line);border-radius:12px;padding:14px;background:#fff;margin:10px 0;min-width:0}.q .nm{font-weight:700;font-size:17px;line-height:1.5}.q .meta{font-size:13.5px;color:var(--mut);margin-top:4px}.q .sn{font-size:14px;margin-top:8px;color:#334}'
       . '.tag{display:inline-block;font-size:12px;font-weight:700;border-radius:999px;padding:2px 10px;border:1px solid var(--line);background:var(--bg);color:var(--mut);margin:0 6px 4px 0}.tag.e{background:var(--amber-l);border-color:#e6c98b;color:#7a5a00}.tag.h{background:#eaf7f5;border-color:#a9ddd6;color:var(--teal-d)}'
       . 'mark{background:#fff3b0;padding:0 2px}.body p{margin:0 0 10px}.body p.sec{font-weight:700;margin-top:18px;color:var(--teal-d)}.two{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px}'
       . 'pre.draft{white-space:pre-wrap;font:14px/1.8 "Noto Sans JP",system-ui,sans-serif;background:#fbfcfd;border:1px dashed var(--line);border-radius:10px;padding:14px;margin:0}'
       . '.q .why{font-size:12.5px;color:var(--blue);font-weight:700;margin-bottom:4px}.ai{background:var(--blue-l);border:1px solid #bcd3ee;border-radius:10px;padding:10px 14px;margin:0 0 12px;font-weight:700;color:#1d4f86}.bad{background:var(--red-l);border:1px solid #f0b8b0;border-radius:10px;padding:10px 14px;margin:0}'
       . 'details.sv{margin:6px 0;font-size:14px}details.sv summary{cursor:pointer;color:var(--teal-d)}details.sv div{padding:6px 0 6px 14px;color:#334;overflow-wrap:anywhere}ol.srcs{padding-left:22px;margin:6px 0}ol.srcs li{margin:6px 0;overflow-wrap:anywhere}'
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
            array('@type' => 'Question', 'name' => 'このサイトは質問を書いてくれますか', 'acceptedAnswer' => array('@type' => 'Answer', 'text' => $LLM_ON
                ? '下書きまでは作ります。アシストの画面で「下書きを作る」を押すと、集めた答弁書の原文を番号つきの出典としてAIに渡し、件名・前提の事実・過去の答弁の引用・問いの下書きを作ります。引用は渡した原文に一字一句含まれるかをサーバーで照合し、合わないものは出しません。提出する文章として仕上げ、引用と事実を原文で確かめるのは人です。'
                : '書きません。過去の質問と答弁を並べ、答弁書の定型句を数え、質問主意書の型（前提の事実・過去の答弁の引用・問い）を空欄つきで示すところまでです。文章を作るのは人です。')))),
    );
    if ($ld_extra) { $graph[] = $ld_extra; }
    echo '<script type="application/ld+json">' . json_encode(array('@context' => 'https://schema.org', '@graph' => $graph), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    // 再販パートナー募集の枠（中身は kurage_web/partner-bar.js）。当社の公開先でだけ読む（配布版を置いたサイトからは当社へ通信しない）
    if (($_SERVER['HTTP_HOST'] ?? '') === 'kurage.exbridge.jp') echo '<script src="https://kurage.exbridge.jp/partner-bar.js" defer></script>';
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
/** ことば＋会期・提出者・院・型をひとつのフォームにまとめた絞り込み。
 *  吉田はるみ議員が第212回の質問主意書(shu-212-141)で政府に求めたのがこの形。 */
function filter_panel($db, $q, $f, $action = '/search') {
    global $SELF, $PATTERNS;
    static $sessions = null, $houses = null;
    if ($sessions === null) {
        $sessions = $db->query('SELECT session, min(substr(submit_date,1,4)) y, count(*) n FROM q GROUP BY session ORDER BY session DESC')->fetchAll();
        $houses = $db->query('SELECT house, count(*) n FROM q GROUP BY house ORDER BY house')->fetchAll();
    }
    echo '<form class="fp" method="get" action="' . h($SELF . $action) . '">';
    echo '<div class="form"><input type="text" name="q" value="' . h($q) . '" placeholder="ことばで探す（例: 遺族年金 養育費）" aria-label="検索語"><button class="btn" type="submit">探す</button></div>';
    echo '<div class="fr">';
    echo '<label>院 <select name="house"><option value="">衆参どちらも</option>';
    foreach ($houses as $r) { echo '<option value="' . h($r['house']) . '"' . ($f['house'] === $r['house'] ? ' selected' : '') . '>' . h($r['house']) . '（' . n($r['n']) . '）</option>'; }
    echo '</select></label>';
    echo '<label>会期 <select name="session"><option value="">すべての会期</option>';
    foreach ($sessions as $r) { echo '<option value="' . (int)$r['session'] . '"' . ((int)$f['session'] === (int)$r['session'] ? ' selected' : '') . '>第' . (int)$r['session'] . '回' . ($r['y'] ? '（' . h($r['y']) . '年）' : '') . '</option>'; }
    echo '</select></label>';
    echo '<label>提出者 <input type="text" name="giin" value="' . h($f['giin']) . '" placeholder="名字だけでも" size="10"></label>';
    echo '<label>答弁の型 <select name="pat"><option value="">問わない</option>';
    foreach ($PATTERNS as $k => $d) { echo '<option value="' . h($k) . '"' . ($f['pat'] === $k ? ' selected' : '') . '>' . h($d[0]) . '</option>'; }
    echo '</select></label>';
    echo '<label class="cb"><input type="checkbox" name="ans" value="1"' . ($f['ans'] ? ' checked' : '') . '> 答弁書があるものだけ</label>';
    echo '<button class="btn ghost" type="submit">絞り込む</button>';
    if (has_filters($f) || $q !== '') echo '<a class="btn ghost" href="' . h($SELF . '/search') . '">条件を消す</a>';
    echo '</div></form>';
}
/** 一覧ページ（提出者・会期・型）から、その条件を保ったまま本文をことばで引く小さなフォーム */
function narrow_form($fixed, $note) {
    global $SELF;
    echo '<form class="form nf" method="get" action="' . h($SELF . '/search') . '">';
    foreach ($fixed as $k => $v) { echo '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">'; }
    echo '<input type="text" name="q" placeholder="' . h($note) . '" aria-label="' . h($note) . '"><button class="btn" type="submit">この中を探す</button></form>';
}
/** いま効いている条件を日本語の1行に */
function filter_words($q, $f) {
    global $PATTERNS;
    $w = array();
    if ($q !== '') $w[] = '「' . $q . '」を含む';
    if ($f['house']) $w[] = $f['house'];
    if ($f['session']) $w[] = '第' . (int)$f['session'] . '回国会';
    if ($f['giin'] !== '') $w[] = '提出者に「' . $f['giin'] . '」';
    if ($f['pat']) $w[] = '答弁に「' . $PATTERNS[$f['pat']][0] . '」';
    if ($f['ans']) $w[] = '答弁書あり';
    return implode('・', $w);
}
function tags($r) {
    global $PATTERNS, $SELF;
    $o = '<span class="tag h">' . h($r['house']) . ' 第' . (int)$r['session'] . '回 第' . (int)$r['no'] . '号</span>';
    foreach (ev($r['evasive_json']) as $k => $c) { if (isset($PATTERNS[$k])) $o .= '<a class="tag e" href="' . h($SELF . '/pattern/' . rawurlencode($k)) . '">' . h($PATTERNS[$k][0]) . ' ×' . (int)$c . '</a>'; }
    if ($r['status'] !== '答弁受理' && !$r['a_len']) { $o .= '<span class="tag">答弁書なし（' . h($r['status'] ?: '未受理') . '）</span>'; }
    return $o;
}
function q_card($r, $ts = array(), $note = '') {
    global $SELF;
    echo '<div class="q">' . ($note !== '' ? '<div class="why">' . $note . '</div>' : '') . '<div class="nm"><a href="' . h($SELF . '/q/' . $r['id']) . '">' . mark(h($r['title']), $ts) . '</a></div>';
    echo '<div class="meta">提出 ' . h(jdate($r['submit_date'])) . '　<a href="' . h($SELF . '/giin/' . rawurlencode($r['submitter'])) . '">' . h($r['submitter']) . '</a>' . ($r['kaiha'] ? '（' . h($r['kaiha']) . '）' : '') . '　答弁 ' . h(jdate($r['answer_date'])) . '</div>';
    echo '<div class="meta">' . tags($r) . '</div>';
    if ($ts) { echo '<div class="sn">質問: ' . mark(snippet($r['q_text'], $ts), $ts) . '</div>'; if ($r['a_text']) echo '<div class="sn">答弁: ' . mark(snippet($r['a_text'], $ts), $ts) . '</div>'; }
    echo '</div>';
}


// ── 似た質問主意書（事前計算の表 similar を読むだけ） ─────────────
function qcols() { return 'q.id, q.house, q.session, q.no, q.title, q.submitter, q.kaiha, q.submit_date, q.answer_date, q.status, q.evasive_json, q.a_len, q.q_text, q.a_text'; }
function has_similar($db) {
    static $has = null;
    if ($has === null) { try { $has = (bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='similar'")->fetchColumn(); } catch (Exception $e) { $has = false; } }
    return $has;
}
/** 1件に近い上位の質問主意書（近い順） */
function similar_of($db, $id, $limit = 8) {
    if (!has_similar($db)) return array();
    $st = $db->prepare('SELECT ' . qcols() . ', s.score FROM similar s JOIN q ON q.id = s.sim_id WHERE s.id = ? ORDER BY s.rank LIMIT ' . (int)$limit);
    $st->execute(array($id)); return $st->fetchAll();
}
/** 何件かの質問主意書に近いもののうち、$skip に入っていないもの。近い元の件数が多い順・近さ順 */
function similar_of_many($db, $ids, $skip, $limit = 10) {
    if (!has_similar($db) || !$ids) return array();
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare('SELECT s.id seed, s.sim_id, s.score FROM similar s WHERE s.id IN (' . $in . ')'); $st->execute(array_values($ids));
    $agg = array();
    foreach ($st->fetchAll() as $r) {
        if (isset($skip[$r['sim_id']])) continue;
        $a = $agg[$r['sim_id']] ?? array('n' => 0, 'best' => 0.0, 'seed' => '');
        $a['n']++; if ((float)$r['score'] > $a['best']) { $a['best'] = (float)$r['score']; $a['seed'] = $r['seed']; }
        $agg[$r['sim_id']] = $a;
    }
    uasort($agg, function ($x, $y) { return $y['n'] <=> $x['n'] ?: $y['best'] <=> $x['best']; });
    $agg = array_slice($agg, 0, $limit, true);
    if (!$agg) return array();
    $in = implode(',', array_fill(0, count($agg), '?'));
    $st = $db->prepare('SELECT ' . qcols() . ' FROM q WHERE q.id IN (' . $in . ')'); $st->execute(array_keys($agg));
    $by = array(); foreach ($st->fetchAll() as $r) { $by[$r['id']] = $r; }
    $out = array();
    foreach ($agg as $id => $a) { if (isset($by[$id])) { $r = $by[$id]; $r['_seed'] = $a['seed']; $r['_n'] = $a['n']; $out[] = $r; } }
    return $out;
}
/** その行が、語を全部含むか（件名・質問・答弁のどれかに） */
function row_matches($r, $ts) {
    foreach ($ts as $t) { if (mb_strpos($r['title'] . "\n" . $r['q_text'] . "\n" . $r['a_text'], $t) === false) return false; }
    return true;
}

// ── LLM（設定があるときだけ） ─────────────────────────────────
/** LLM 用の書き込み先（キャッシュと回数制限）。本体の SQLite は読むだけのまま、別ファイルに書く */
function ldb() {
    static $l = false;
    if ($l !== false) return $l;
    try {
        $l = new PDO('sqlite:' . __DIR__ . '/kshuisho_data/kshuisho_llm.sqlite');
        $l->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $l->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $l->exec('PRAGMA busy_timeout=3000');
        $l->exec('CREATE TABLE IF NOT EXISTS expand (q TEXT PRIMARY KEY, words TEXT, t INTEGER)');
        $l->exec('CREATE TABLE IF NOT EXISTS hits (kind TEXT, ip TEXT, t INTEGER)');
        $l->exec('CREATE TABLE IF NOT EXISTS state (k TEXT PRIMARY KEY, v TEXT)');
    } catch (Exception $e) { $l = null; }
    return $l;
}
/** 回数制限。数えられないとき（書き込めない）は通さない */
function rate_ok($kind, $limit, $global = false) {
    $l = ldb(); if (!$l) return false;
    $ip = $global ? '*' : (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $now = time();
    try {
        $l->prepare('DELETE FROM hits WHERE t < ?')->execute(array($now - 3600));
        $st = $l->prepare('SELECT count(*) FROM hits WHERE kind = ? AND ip = ? AND t >= ?'); $st->execute(array($kind, $ip, $now - 3600));
        if ((int)$st->fetchColumn() >= $limit) return false;
        $l->prepare('INSERT INTO hits VALUES (?,?,?)')->execute(array($kind, $ip, $now));
        return true;
    } catch (Exception $e) { return false; }
}
function lstate($k, $v = null) {
    $l = ldb(); if (!$l) return null;
    try {
        if ($v === null) { $st = $l->prepare('SELECT v FROM state WHERE k = ?'); $st->execute(array($k)); $x = $st->fetchColumn(); return $x === false ? null : $x; }
        $l->prepare('INSERT OR REPLACE INTO state VALUES (?,?)')->execute(array($k, (string)$v)); return $v;
    } catch (Exception $e) { return null; }
}
function is_bot() { return (bool)preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|headless|curl|wget|python|go-http/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')); }
/** OpenAI 互換 chat/completions を1回呼ぶ。失敗したら null。$schema があれば JSON で返させて配列で返す */
function llm_chat($system, $user, $schema, $max_tokens, $timeout, $temperature = 0) {
    global $CFG;
    $base = rtrim((string)($CFG['llm_base'] ?? ''), '/'); if ($base === '') return null;
    $req = array('model' => (string)($CFG['llm_model'] ?? 'gemma4:12b-it-qat'), 'temperature' => $temperature, 'max_tokens' => $max_tokens,
                 'reasoning_effort' => 'none',   // gemma4 は思考型。切らないと隠れ推論が max_tokens を食って空になる（Ollama の think:false）
                 'messages' => array(array('role' => 'system', 'content' => $system), array('role' => 'user', 'content' => $user)));
    if ($schema) $req['response_format'] = array('type' => 'json_schema', 'json_schema' => array('name' => 'out', 'schema' => $schema));
    $body = json_encode($req, JSON_UNESCAPED_UNICODE);
    $hd = array('Content-Type: application/json'); if (!empty($CFG['llm_token'])) $hd[] = 'Authorization: Bearer ' . $CFG['llm_token'];
    if (function_exists('curl_init')) {
        $ch = curl_init($base . '/chat/completions');
        curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $hd, CURLOPT_RETURNTRANSFER => true,
                                     CURLOPT_CONNECTTIMEOUT => min(5, $timeout), CURLOPT_TIMEOUT => $timeout));
        $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($res === false || $code !== 200) return null;
    } else {
        $ctx = stream_context_create(array('http' => array('method' => 'POST', 'timeout' => $timeout, 'header' => implode("\r\n", $hd) . "\r\n", 'content' => $body)));
        $res = @file_get_contents($base . '/chat/completions', false, $ctx); if ($res === false) return null;
    }
    $j = json_decode((string)$res, true); $txt = $j['choices'][0]['message']['content'] ?? null;
    if (!is_string($txt) || trim($txt) === '') return null;
    if (!$schema) return $txt;
    $txt = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($txt)));
    $o = json_decode($txt, true); return is_array($o) ? $o : null;
}

/** 言い換え・関連語（最大5語）。同じ語はキャッシュから。LLM が無い・遅いときは空 */
function expand_words($q) {
    global $CFG;
    $key = implode(' ', terms($q)); if ($key === '' || mb_strlen($key) > 40) return array();
    $l = ldb();
    if ($l) { $st = $l->prepare('SELECT words FROM expand WHERE q = ?'); $st->execute(array($key)); $w = $st->fetchColumn(); if ($w !== false) return json_decode($w, true) ?: array(); }
    if ((int)lstate('down_until') > time()) return array();            // さっき応答が無かった。しばらく呼ばない
    if (is_bot() || !rate_ok('expand', 60) || !rate_ok('expand', 300, true)) return array();
    $sys = "あなたは、国会の質問主意書と政府答弁書を検索する係です。\n"
         . "利用者が入れた検索語について、答弁書や質問主意書の本文で同じことを指して使われそうな別の言い方と、関連の深い語を、最大5個返してください。\n"
         . "各語は2〜12字の日本語の名詞句にします。元の語そのもの、元の語を含むだけの語、「支援」「対策」「制度」「政策」のように広すぎる語は入れません。\n"
         . "検索語が空白で区切られた複数の語のときは、全体が指すことの言い換えを返します。\n"
         . '{"words": ["…"]} の形の JSON だけを返します。';
    $o = llm_chat($sys, $key, array('type' => 'object', 'properties' => array('words' => array('type' => 'array', 'items' => array('type' => 'string'))), 'required' => array('words')),
                  200, (int)($CFG['expand_timeout'] ?? 8));
    if (!is_array($o) || !isset($o['words']) || !is_array($o['words'])) { lstate('down_until', time() + 60); return array(); }
    $out = array();
    foreach ($o['words'] as $w) {
        $w = trim(preg_replace('/[\s　]+/u', ' ', (string)$w)); $len = mb_strlen($w);
        if ($len < 2 || $len > 20 || $w === $key || mb_strpos($w, $key) !== false || in_array($w, $out, true)) continue;
        $out[] = $w; if (count($out) >= 5) break;
    }
    if ($l) { try { $l->prepare('INSERT OR REPLACE INTO expand VALUES (?,?,?)')->execute(array($key, json_encode($out, JSON_UNESCAPED_UNICODE), time())); } catch (Exception $e) {} }
    return $out;
}
/** 言い換えの語で探した結果のうち、元の語では一致しないもの。array(語ごとの件数, 行) */
function expand_search($db, $q, $words, $f = null, $limit = 30) {
    $ts = terms($q); $cnt = array(); $rows = array();
    foreach ($words as $w) {
        $cnt[$w] = 0;
        foreach (search($db, $w, 60, $f) as $r) {
            if (row_matches($r, $ts)) continue;          // 元の語で一致するものは上に出ている
            $cnt[$w]++;
            if (!isset($rows[$r['id']])) { $r['_words'] = array(); $rows[$r['id']] = $r; }
            $rows[$r['id']]['_words'][] = $w;
        }
    }
    uasort($rows, function ($a, $b) { return count($b['_words']) <=> count($a['_words']) ?: strcmp($b['submit_date'], $a['submit_date']); });
    return array($cnt, array_slice(array_values($rows), 0, $limit));
}
/** 言い換えの結果を後から読み込む枠（JS）。LLM が無ければ何も出さない */
function expand_slot($q, $f = null) {
    global $SELF, $LLM_ON;
    if (!$LLM_ON || !terms($q)) return;
    $qs = filter_qs($f ?: get_filters(), $q);
    echo '<div id="alt" data-src="' . h($SELF . '/expand' . $qs) . '"><p class="src">入れた語の言い換えでも探しています（AI）…</p></div>';
    echo '<script>(function(){var d=document.getElementById("alt");if(!window.fetch){d.remove();return}var c=new AbortController();setTimeout(function(){c.abort()},15000);'
       . 'fetch(d.getAttribute("data-src"),{signal:c.signal,headers:{"X-Requested-With":"kshuisho"}}).then(function(r){return r.ok?r.text():""}).then(function(t){if(t.trim()){d.innerHTML=t}else{d.remove()}}).catch(function(){d.remove()})})();</script>';
}

// ── 下書き（設定があるときだけ） ──────────────────────────────
function wareki_date($iso) {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$iso, $m)) return '';
    $y = (int)$m[1]; $md = (int)$m[2] . '月' . (int)$m[3] . '日';
    if ($iso >= '2019-05-01') return '令和' . ($y - 2018 === 1 ? '元' : $y - 2018) . '年' . $md;
    if ($iso >= '1989-01-08') return '平成' . ($y - 1988 === 1 ? '元' : $y - 1988) . '年' . $md;
    return '昭和' . ($y - 1925) . '年' . $md;
}
function norm_ws($s) { return preg_replace('/[\s　]+/u', '', (string)$s); }
/** 下書きに渡す材料。番号つきの出典の配列（番号は 1 から） */
function draft_sources($db, $q) {
    global $EVRE, $PATTERNS;
    $ts = terms($q); $rows = $ts ? search($db, $q, 60) : array();
    $src = array(); $seen = array(); $chars = 0; $CAP = 7000;
    $label = function ($r) { return $r['house'] . '第' . (int)$r['session'] . '回国会質問第' . (int)$r['no'] . '号「' . $r['title'] . '」'; };
    $add = function ($kind, $r, $text, $note = '') use (&$src, &$chars, $CAP, $label) {
        $text = trim(preg_replace('/[ \t　]+/u', '　', $text));
        if (mb_strlen($text) > 500) $text = mb_substr($text, 0, 500);
        if ($chars + mb_strlen($text) > $CAP) return false;
        $chars += mb_strlen($text);
        $src[] = array('n' => count($src) + 1, 'kind' => $kind, 'id' => $r['id'], 'note' => $note,
            'label' => $kind === '答弁' ? $label($r) . 'に対する答弁書（' . wareki_date($r['answer_date']) . '受領）' : $label($r) . '（' . $r['submitter'] . '、' . wareki_date($r['submit_date']) . '提出）',
            'text' => $text);
        return true;
    };
    // 1. 語を含む答弁の段落（画面の「1. 政府はこう答えている」と同じ拾い方）
    $n1 = 0;
    foreach ($rows as $r) {
        if (!$r['a_text']) continue;
        foreach (explode("\n", $r['a_text']) as $pg) {
            if (mb_strlen($pg) < 20 || !row_matches(array('title' => '', 'q_text' => '', 'a_text' => $pg), $ts)) continue;
            if (isset($seen[$r['id'] . md5($pg)])) continue; $seen[$r['id'] . md5($pg)] = 1;
            if ($add('答弁', $r, $pg)) $n1++;
            if ($n1 >= 6) break 2;
        }
    }
    // 2. 答えていない型が出た答弁の、その段落
    $n2 = 0;
    foreach ($rows as $r) {
        $ev = ev($r['evasive_json']); if (!$ev || !$r['a_text']) continue;
        foreach (explode("\n", $r['a_text']) as $pg) {
            $k = null; foreach ($EVRE as $kk => $re) { if (isset($ev[$kk]) && preg_match('/' . $re . '/u', $pg)) { $k = $kk; break; } }
            if (!$k || isset($seen[$r['id'] . md5($pg)])) continue; $seen[$r['id'] . md5($pg)] = 1;
            if ($add('答弁', $r, $pg, '答えていない型: ' . $PATTERNS[$k][0])) $n2++;
            break;
        }
        if ($n2 >= 4) break;
    }
    // 3. 過去の質問（冒頭）
    $n3 = 0;
    foreach ($rows as $r) { if ($add('質問', $r, mb_substr(preg_replace('/\s+/u', ' ', $r['q_text']), 0, 300))) $n3++; if ($n3 >= 4) break; }
    return $src;
}
function draft_schema() { return array('type' => 'object', 'properties' => array(
    'title' => array('type' => 'string'),
    'facts' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array('text' => array('type' => 'string'), 'src' => array('type' => 'array', 'items' => array('type' => 'integer'))), 'required' => array('text', 'src'))),
    'quotes' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array('src' => array('type' => 'integer'), 'quote' => array('type' => 'string')), 'required' => array('src', 'quote'))),
    'questions' => array('type' => 'array', 'items' => array('type' => 'string'))),
    'required' => array('title', 'facts', 'quotes', 'questions')); }
function draft_prompt($q, $src) {
    $sys = "あなたは国会議員事務所の秘書です。質問主意書の下書きを作ります。\n"
         . "使ってよい材料は、利用者が渡す【出典】だけです。出典に書いていない事実・数字・日付・法律名・答弁を作ってはいけません。\n"
         . "次の形の JSON だけを返します。\n"
         . "- title: 「○○に関する質問主意書」の形の件名。\n"
         . "- facts: 前提の事実を2〜3個。text は1文で、出典から読み取れることだけを書きます。src にはその根拠の出典番号を入れます。数字や日付は、その出典の原文にそのまま書いてあるものだけを使い、無ければ（　）と空欄にします。種類が「質問」の出典に書かれた主張は事実として書かず、「過去の質問主意書で、…と指摘されている」の形にします。\n"
         . "- quotes: 過去の答弁の引用を1〜3個。src は種類が「答弁」の出典の番号、quote はその出典の原文から一字一句そのまま写した部分（1文か文の一部、20〜120字）。言い換え・要約・省略・語尾の変更をしてはいけません。できるだけ、中に「」を含まない部分を選びます。\n"
         . "- questions: 政府への問いを3個。どれも論点の語に直接かかわる問いにし、出典のうち論点から外れた話題は使いません。それぞれ1文で「〜か。」で終わる日本語にします。過去の答弁で「お答えすることは困難」「承知していない」「検討してまいりたい」とされた点は、対象・期間・数字の範囲を限って聞き直します。番号（一、二など）は付けません。\n"
         . "「」は、出典の原文をそのまま写すときだけ使います。それ以外で「」を使ってはいけません。";
    $u = "論点の語: " . $q . "\n\n【出典】\n";
    foreach ($src as $s) { $u .= '[' . $s['n'] . '] 種類: ' . $s['kind'] . ($s['note'] ? '（' . $s['note'] . '）' : '') . "\n出典: " . $s['label'] . "\n原文: " . $s['text'] . "\n\n"; }
    return array($sys, $u);
}
/** 下書きの検査。違反の一覧を返す（空なら合格）。作り話を出さないための関門 */
function draft_check($o, $src) {
    $bad = array(); $by = array(); $all = '';
    foreach ($src as $s) { $by[(int)$s['n']] = $s; $all .= norm_ws($s['text']) . "\n"; }
    if (!is_array($o)) return array('形式: JSON として読めない');
    foreach (array('title', 'facts', 'quotes', 'questions') as $k) { if (!isset($o[$k])) $bad[] = "形式: {$k} が無い"; }
    if ($bad) return $bad;
    if (!is_string($o['title']) || trim($o['title']) === '') $bad[] = '形式: 件名が空';
    if (!is_array($o['questions']) || count($o['questions']) < 1 || count($o['questions']) > 5) $bad[] = '形式: 問いの数が1〜5でない';
    if (!is_array($o['quotes']) || !is_array($o['facts'])) return array_merge($bad, array('形式: facts か quotes が配列でない'));
    foreach ($o['quotes'] as $i => $x) {
        $n = (int)($x['src'] ?? 0); $qt = norm_ws($x['quote'] ?? '');
        $qt = trim($qt, '「」');
        if (!isset($by[$n])) { $bad[] = '引用' . ($i + 1) . ': 出典番号 [' . $n . '] は渡していない'; continue; }
        if ($by[$n]['kind'] !== '答弁') $bad[] = '引用' . ($i + 1) . ': 出典 [' . $n . '] は答弁ではない';
        if (mb_strlen($qt) < 8) $bad[] = '引用' . ($i + 1) . ': 短すぎる';
        elseif (mb_strpos(norm_ws($by[$n]['text']), $qt) === false) $bad[] = '引用' . ($i + 1) . ': 出典 [' . $n . '] の原文に一字一句は含まれていない';
    }
    $texts = array((string)$o['title']);
    foreach ($o['facts'] as $i => $x) {
        $t = (string)($x['text'] ?? ''); $texts[] = $t; $ns = is_array($x['src'] ?? null) ? $x['src'] : array();
        if (!$ns) $bad[] = '前提の事実' . ($i + 1) . ': 出典番号が無い';
        $cited = '';
        foreach ($ns as $n) { if (!isset($by[(int)$n])) $bad[] = '前提の事実' . ($i + 1) . ': 出典番号 [' . (int)$n . '] は渡していない'; else $cited .= norm_ws($by[(int)$n]['text']) . "\n" . norm_ws($by[(int)$n]['label']) . "\n"; }
        // 数字・日付は、引いた出典の原文にあるものだけ
        $tt = mb_convert_kana($t, 'n'); $cc = mb_convert_kana($cited, 'n');
        if (preg_match_all('/[0-9][0-9,.]*|[〇一二三四五六七八九十百千万億兆]{2,}/u', $tt, $m)) {
            foreach ($m[0] as $num) { $num = rtrim($num, ',.'); if ($num !== '' && mb_strpos($cc, $num) === false) $bad[] = '前提の事実' . ($i + 1) . ': 数字「' . $num . '」が出典の原文に無い'; }
        }
    }
    foreach ($o['questions'] as $t) $texts[] = (string)$t;
    // 「」で括ったものは、渡した原文のどれかに一字一句含まれること
    foreach ($texts as $t) {
        if (preg_match_all('/「([^「」]+)」/u', $t, $m)) {
            foreach ($m[1] as $qt) { if (mb_strpos($all, norm_ws($qt)) === false) $bad[] = '「' . mb_substr($qt, 0, 30) . '」が渡した原文に無い'; }
        }
    }
    return $bad;
}
/** 検査に通った下書きを、質問主意書の形の文にする */
function draft_text($o, $src) {
    $by = array(); foreach ($src as $s) { $by[(int)$s['n']] = $s; }
    $title = trim((string)$o['title']); if (!preg_match('/質問主意書$/u', $title)) $title .= 'に関する質問主意書';
    $t = $title . "\n\n【前提の事実】\n";
    foreach ($o['facts'] as $x) { $t .= '　' . trim((string)$x['text']) . '［出典' . implode('・', array_map('intval', (array)$x['src'])) . "］\n"; }
    $t .= "\n【過去の答弁】\n";
    foreach ($o['quotes'] as $x) { $s = $by[(int)$x['src']]; $t .= '　' . $s['label'] . 'において、政府は「' . trim(trim((string)$x['quote']), '「」') . '」と答弁している。［出典' . (int)$x['src'] . "］\n"; }
    $t .= "\n";
    $kn = array('一', '二', '三', '四', '五');
    foreach (array_values($o['questions']) as $i => $x) { $t .= $kn[$i] . '　' . trim((string)$x) . "\n\n"; }
    return $t . '　右質問する。';
}

// ── robots / sitemap / llms / api / data ────────────────
if ($path === '/robots.txt') { header('Content-Type: text/plain; charset=UTF-8'); echo "User-agent: *\nAllow: /\nSitemap: https://kurage.exbridge.jp{$SELF}/sitemap.xml\n"; exit; }
if ($path === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=UTF-8'); $base = 'https://kurage.exbridge.jp' . $SELF; $lm = $META['built'] ?? date('Y-m-d');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
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


// ── 言い換えで探す・下書き（LLM の設定があるときだけ） ─────────────
if ($LLM_ON && $path === '/expand') {   // 設定が無ければ、ふつうの「ページがありません」
    header('Content-Type: text/html; charset=UTF-8'); header('X-Robots-Tag: noindex'); header('Cache-Control: no-store');
    $q = trim((string)($_GET['q'] ?? '')); $f = get_filters();
    if (mb_strlen($q) > 60 || !terms($q)) exit;
    $words = expand_words($q); if (!$words) exit;
    list($cnt, $rows) = expand_search($db, $q, $words, $f, 30);
    echo '<h2>言い換えでも探した結果（' . h(implode('・', $words)) . '）</h2>';
    echo '<p class="src">「' . h($q) . '」の言い換えと関連する語を AI（' . h((string)($CFG['llm_model'] ?? 'gemma4:12b-it-qat')) . '）に出させ、その語でも探しました。上の結果（入れた語そのものの一致）に入っていないものだけを並べています。語ごとの件数は '
       . h(implode('、', array_map(function ($w, $c) { return $w . ' ' . $c . '件'; }, array_keys($cnt), $cnt))) . 'です。言い換えが論点に合っているかは、人が確かめてください。</p>';
    if (!$rows) echo '<div class="panel"><p>言い換えの語でも、ほかに当たる質問主意書はありませんでした。</p></div>';
    foreach ($rows as $r) q_card($r, $r['_words'], h('言い換え「' . implode('」「', $r['_words']) . '」で一致'));
    exit;
}
if ($LLM_ON && $path === '/draft') {
    header('Content-Type: application/json; charset=UTF-8'); header('X-Robots-Tag: noindex'); header('Cache-Control: no-store');
    $fail = function ($code, $msg, $extra = array()) { http_response_code($code); echo json_encode(array('ok' => false, 'message' => $msg) + $extra, JSON_UNESCAPED_UNICODE); exit; };
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'kshuisho') $fail(405, '画面の「下書きを作る」から使ってください。');
    $q = trim(preg_replace('/[\s　]+/u', ' ', (string)($_POST['q'] ?? '')));
    if ($q === '' || mb_strlen($q) > 40 || count(terms($q)) > 3) $fail(400, '論点の語は3つまで、全部で40字までにしてください。');
    $src = draft_sources($db, $q);
    if (!array_filter($src, function ($s) { return $s['kind'] === '答弁'; })) $fail(422, '検索語を含む答弁の原文が見つからないので、下書きを作れません。語を変えてみてください。');
    if (!rate_ok('draft', (int)($CFG['draft_per_hour'] ?? 10))) $fail(429, '下書きは1時間に' . (int)($CFG['draft_per_hour'] ?? 10) . '回までです。時間をおいてお試しください。');
    if (!rate_ok('draft', (int)($CFG['draft_per_hour_all'] ?? 60), true)) $fail(429, 'いま下書きの依頼が多く、受け付けられません。時間をおいてお試しください。');
    @set_time_limit(300);
    list($sys, $u) = draft_prompt($q, $src);
    $o = null; $bad = array();
    foreach (array(0, 0.4) as $temp) {   // 検査に通らなければ、1回だけ作り直す（出すのは検査に通ったものだけ）
        $o = llm_chat($sys, $u, draft_schema(), 1500, (int)($CFG['draft_timeout'] ?? 120), $temp);
        if (!$o) $fail(503, 'AIから応答がありませんでした。時間をおいてもう一度お試しください。');
        $bad = draft_check($o, $src);
        if (!$bad) break;
    }
    if ($bad) $fail(200, '作れませんでした。AIが出した文に、渡した原文と一致しない引用か数字がありました。作り話を出さないため、この下書きは捨てています。上の1〜3の原文から、人が書いてください。', array('checks' => $bad));
    $used = array(); foreach ($o['quotes'] as $x) $used[(int)$x['src']] = 1; foreach ($o['facts'] as $x) foreach ((array)$x['src'] as $n) $used[(int)$n] = 1;
    $html = '<p class="ai">AIが作った下書きです。提出の前に、引用と事実を原文で必ず確かめてください。</p>'
          . '<pre class="draft">' . h(draft_text($o, $src)) . '</pre>'
          . '<p class="src">検査の結果: 「」で括った引用 ' . count($o['quotes']) . '件は、どれも出典の原文に一字一句含まれていました。出典番号はすべてこちらから渡した材料のもので、前提の事実の数字も、引いた出典の原文にあるものだけです。下書きはこのサーバーに保存していません。</p>'
          . '<h3>出典（AIに渡した原文）</h3><ol class="srcs">';
    foreach ($src as $sv) {
        $html .= '<li value="' . (int)$sv['n'] . '">' . (isset($used[$sv['n']]) ? '<b>' : '') . h($sv['kind'] . '｜' . $sv['label']) . (isset($used[$sv['n']]) ? '</b>（下書きで使用）' : '') . ($sv['note'] ? '　' . h($sv['note']) : '')
               . '　<a href="' . h($SELF . '/q/' . $sv['id']) . '">本文</a><details class="sv"><summary>渡した原文</summary><div>' . h($sv['text']) . '</div></details></li>';
    }
    echo json_encode(array('ok' => true, 'html' => $html . '</ol>'), JSON_UNESCAPED_UNICODE); exit;
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
    // 似た質問主意書（事前計算の表があるときだけ）
    $sims = similar_of($db, $r['id'], 8); $shown_ids = array($r['id'] => 1);
    if ($sims) {
        echo '<h2>似た質問主意書</h2><p class="src">件名と質問本文の冒頭の意味が近いものを、前もって計算しておいた表から近い順に8件並べました（multilingual-e5-large）。同じ言葉を使っていなくても出ます。論点が少しずれていることもあります。</p>';
        foreach ($sims as $x) { $shown_ids[$x['id']] = 1; q_card($x); }
    }
    // 件名の語が重なる他の質問
    $kw = array_values(array_filter(preg_split('/に関する|について|の|と|・|、|及び|等|質問主意書|再/u', $r['title']), function ($s) { return mb_strlen($s) >= 2; }));
    if ($kw) { $rel = search($db, implode(' ', array_slice($kw, 0, 2)), 8); $rel = array_filter($rel, function ($x) use ($shown_ids) { return !isset($shown_ids[$x['id']]); }); if ($rel) { echo '<h2>件名の語が重なる質問主意書</h2>'; foreach ($rel as $x) q_card($x); } }
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
    echo '<h1>' . h($m[1]) . 'の質問主意書</h1><p class="lead">' . h($rows[0]['house']) . ($rows[0]['kaiha'] ? '・' . h($rows[0]['kaiha']) : '') . '。' . n(count($rows)) . '件。新しい順。</p>';
    narrow_form(array('giin' => $m[1]), $m[1] . 'の質問主意書の中を、ことばで探す');
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
    echo '<h1>「' . h($PATTERNS[$k][0]) . '」の型</h1><p class="lead">' . h($PATTERNS[$k][1]) . '。' . n(count($rows)) . '件（新しい順）。語の一致で、答弁の評価ではありません。</p>';
    narrow_form(array('pat' => $k), 'この型が返った答弁の中を、ことばで探す');
    foreach ($rows as $r) q_card($r); foot_html(); exit;
}

// ── 回次 ────────────────────────────────────────────────
if (preg_match('#^/session/(衆議院|参議院)/(\d+)$#u', $path, $m)) {
    $st = $db->prepare('SELECT id, house, session, no, title, submitter, kaiha, submit_date, answer_date, status, evasive_json, a_len, q_text, a_text FROM q WHERE house=? AND session=? ORDER BY no'); $st->execute(array($m[1], (int)$m[2])); $rows = $st->fetchAll();
    head_html($m[1] . ' 第' . $m[2] . '回国会の質問主意書' . count($rows) . '件｜' . $SITE, '', '/session/' . $m[1] . '/' . $m[2]);
    echo '<h1>' . h($m[1]) . ' 第' . (int)$m[2] . '回国会</h1><p class="lead">' . n(count($rows)) . '件。番号順。</p>';
    narrow_form(array('house' => $m[1], 'session' => (int)$m[2]), 'この会期の中を、ことばで探す');
    foreach ($rows as $r) q_card($r); foot_html(); exit;
}

// ── 検索 ────────────────────────────────────────────────
if ($path === '/search') {
    $q = trim((string)($_GET['q'] ?? '')); $ts = terms($q); $f = get_filters();
    $on = ($q !== '' || has_filters($f));
    $rows = $on ? search($db, $q, 200, $f) : array();
    $words = filter_words($q, $f);
    head_html(($on ? $words . 'の質問主意書と答弁書 ' . count($rows) . '件' : '衆参の質問主意書をことばで探す') . '｜' . $SITE,
        ($on ? $words . '質問主意書を、衆議院・参議院あわせて新しい順に並べました。' : '衆議院・参議院の質問主意書と政府答弁書を、本文の中までことばで引けます。会期・提出者・院・答弁の型でも絞れます。'), '/search');
    echo '<h1>' . ($on ? h($words) : '衆参の質問主意書をことばで探す') . '</h1>';
    filter_panel($db, $q, $f);
    if ($on) {
        echo '<p class="lead">' . n(count($rows)) . '件' . (count($rows) >= 200 ? '（新しい順に200件まで）' : '')
           . '。' . ($ts ? '質問本文・答弁本文・件名のどれかに全部の語を含み、' : '') . '衆参をまとめて新しい順です。'
           . ($ts ? ' <a class="btn ghost" href="' . h($SELF . '/assist?q=' . rawurlencode($q)) . '">この論点をアシストで並べる</a>' : '') . '</p>';
        if (!$rows) echo '<div class="panel"><p>その条件に当たる質問主意書は収録分にありません。語を減らすか、絞り込みを外してみてください。</p></div>';
        foreach ($rows as $r) q_card($r, $ts);
        expand_slot($q, $f);
    } else {
        echo '<div class="panel"><p>ことばだけでも、会期や提出者だけでも引けます。両方を重ねると「その会期に、その議員が、その論点で」何を聞いたかが出ます。</p></div>';
    }
    foot_html(); exit;
}

// ── アシスト ────────────────────────────────────────────
if ($path === '/assist') {
    $q = trim((string)($_GET['q'] ?? '')); $ts = terms($q); $rows = $ts ? search($db, $q, 60) : array();
    head_html(($q !== '' ? '「' . $q . '」で質問主意書を書く前に — 過去の質問・答弁・答えていない型' : '質問主意書アシスト') . '｜' . $SITE, '同じ論点で過去に何が聞かれ、政府が何と答え、どこが「お答えすることは困難」とされたかを1画面に並べます。' . ($LLM_ON ? '集めた原文をもとに、AIで質問主意書の下書きも作れます（引用は原文と照合）。' : '文章は作りません。'), '/assist');
    echo '<h1>' . ($q !== '' ? '「' . h($q) . '」で書く前に' : '質問主意書アシスト') . '</h1>';
    if ($LLM_ON) echo '<p class="lead">論点の語を入れると、<b>過去の質問</b>・<b>政府の答弁</b>・<b>答えていない型</b>を1画面に並べます。並べた原文をもとに、AIで<b>質問主意書の下書き</b>も作れます。下書きの引用は原文と一字一句照合し、合わないものは出しません。どの問いを出すかを決め、仕上げるのは人です。</p>';
    else echo '<p class="lead">論点の語を入れると、<b>過去の質問</b>・<b>政府の答弁</b>・<b>答えていない型</b>を1画面に並べます。文章は作りません。並べたものを見て、まだ聞かれていない問いを人が決めるための道具です。</p>';
    search_form($q, '/assist', '論点の語（例: 遺族年金 養育費）');
    if ($q !== '') {
        if (!$rows) { echo '<div class="panel"><p>収録分に、その語を含む質問主意書はありません。<b>過去に聞かれていない論点</b>の可能性があります（収録は第' . h(implode('〜', array_map('strval', array(min(json_decode($META['sessions'] ?? '[0]', true)), max(json_decode($META['sessions'] ?? '[0]', true)))))) . '回国会）。語を減らして再検索もお試しください。</p></div>'; expand_slot($q); }
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
            // 3 の続き: 意味の近いもの（事前計算の表）と、言い換えの語で当たったもの（AI）。どちらも元の語では一致しないものだけ
            $skip = array(); foreach ($rows as $r) $skip[$r['id']] = 1;
            $near = array_values(array_filter(similar_of_many($db, array_slice(array_keys($skip), 0, 20), $skip, 30), function ($x) use ($ts) { return !row_matches($x, $ts); }));
            if ($near) {
                $near = array_slice($near, 0, 10); $tt = array(); foreach ($rows as $r) $tt[$r['id']] = $r['title'];
                echo '<h2>意味の近い過去の質問（ことばは一致しないもの・' . count($near) . '件）</h2><p class="src">上の質問主意書と件名・質問本文の意味が近いものを、前もって計算しておいた表から足しました。「' . h($q) . '」をそのままは含まないので、論点がずれていることもあります。</p>';
                foreach ($near as $x) q_card($x, array(), '「' . h(mb_strimwidth($tt[$x['_seed']] ?? '', 0, 60, '…')) . '」' . ($x['_n'] > 1 ? 'ほか' . ($x['_n'] - 1) . '件' : '') . 'に近い');
            }
            expand_slot($q);
            // 4. 型
            $y = date('Y'); $sess = max(json_decode($META['sessions'] ?? '[0]', true));
            echo '<h2>4. 質問主意書の型（空欄を人が埋める）</h2><div class="panel"><pre class="draft">' . h("○○に関する質問主意書\n\n　提出者　　（氏名）\n\n○○に関する質問主意書\n\n【前提の事実】\n　（出典と日付つきで、争いのない事実を書く。例: 「令和○年○月○日の○○省の発表によれば…」）\n\n【過去の答弁】\n　（上の「1. 政府はこう答えている」から、引用する答弁書と日付を書く。例: 「令和○年○月○日受領の答弁書（内閣衆質○第○号）では…と答弁している」）\n\n一　（過去の答弁で「お答えすることは困難」とされた点を、数字・期間・対象を限定して聞き直す）\n\n二　（答弁が「検討してまいりたい」だった点は、検討の主体・期限・結論の公表方法を聞く）\n\n三　（答弁が「承知していない」だった点は、把握する予定の有無と、把握しない理由を聞く）\n\n　右質問する。") . '</pre><p class="src">これは型です。' . ($LLM_ON ? '下の「5. 下書きを作る」では、この型に沿って AI が下書きを埋めます。' : '文章は作りません。') . '国会法74条の質問主意書は議長の承認を経て内閣に転送され、原則7日以内に答弁書が閣議決定されます。</p></div>';
            // 5. 下書き（LLM の設定があるときだけ）
            if ($LLM_ON) {
                $src = draft_sources($db, $q); $na_ = count(array_filter($src, function ($s) { return $s['kind'] === '答弁'; }));
                echo '<h2 id="draft">5. 下書きを作る（AI）</h2><div class="panel">';
                if (!$na_ || count($ts) > 3 || mb_strlen($q) > 40) { echo '<p>' . (!$na_ ? '検索語を含む答弁の原文が見つからないので、この語では下書きを作れません。' : '下書きは、論点の語が3つまで・全部で40字までのときに作れます。') . '</p>'; }
                else {
                    echo '<p>上の1〜3から、答弁の原文' . $na_ . '段落と過去の質問' . (count($src) - $na_) . '件を、番号つきの出典として AI（' . h((string)($CFG['llm_model'] ?? 'gemma4:12b-it-qat')) . '）に渡し、4 の型に沿った下書き（件名・前提の事実・過去の答弁の引用・問い）を作らせます。</p>'
                       . '<p class="src">作ったあと、サーバーで次を確かめます。「」で括った引用が渡した原文に一字一句含まれるか、出典番号が渡したものか、前提の事実の数字が引いた出典の原文にあるか。1つでも外れたら下書きは出しません。1回に30秒〜1分ほどかかります。下書きは保存しません。1時間に' . (int)($CFG['draft_per_hour'] ?? 10) . '回まで。</p>'
                       . '<p><button class="btn" id="mkdraft" type="button" data-q="' . h($q) . '" data-u="' . h($SELF . '/draft') . '">下書きを作る</button></p><div id="draftout"></div><noscript><p>下書きを作るには JavaScript を有効にしてください。</p></noscript>';
                    echo '<script>(function(){var b=document.getElementById("mkdraft"),o=document.getElementById("draftout");b.addEventListener("click",function(){b.disabled=true;var t=b.textContent;b.textContent="作っています（30秒〜1分）…";o.innerHTML="";var fd=new FormData();fd.append("q",b.getAttribute("data-q"));'
                       . 'fetch(b.getAttribute("data-u"),{method:"POST",body:fd,headers:{"X-Requested-With":"kshuisho"}}).then(function(r){return r.json()}).then(function(j){if(j.ok){o.innerHTML=j.html}else{var p=document.createElement("p");p.className="bad";p.textContent=j.message||"作れませんでした。";o.appendChild(p)}}).catch(function(){var p=document.createElement("p");p.className="bad";p.textContent="AIから応答がありませんでした。時間をおいてもう一度お試しください。";o.appendChild(p)}).then(function(){b.disabled=false;b.textContent=t})})})();</script>';
                }
                echo '</div>';
            }
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
    if (has_similar($db)) echo '<div class="panel"><h3>似た質問主意書</h3><p>質問主意書のページと、アシストの「過去の質問」に出る「似た質問主意書」「意味の近い過去の質問」は、件名と質問本文の冒頭を multilingual-e5-large で数値にし、意味の近いものを前もって計算しておいた表です（' . h($META['similar_built'] ?? '') . '計算）。画面を開いたときに AI は動いていません。言葉が一致していなくても出る代わりに、論点が少しずれていることもあります。</p></div>';
    if ($LLM_ON) {
        echo '<div class="panel"><h3>AI（LLM）を使うところ</h3><p>この設置では、次の2つにだけ AI（' . h((string)($CFG['llm_model'] ?? 'gemma4:12b-it-qat')) . '）を使っています。</p><ul class="plain">'
           . '<li><b>言い換えで探す</b> … 検索とアシストで、入れた語の言い換えと関連する語を AI に出させ、その語でも探します。入れた語そのものの結果とは分けて「言い換えでも探した結果」として出します。AI の応答が無いときや遅いときは、出しません。</li>'
           . '<li><b>下書きを作る</b> … アシストで、集めた答弁書の原文と過去の質問を番号つきの出典として AI に渡し、質問主意書の下書きを作ります。「」で括った引用が渡した原文に一字一句含まれるか、出典番号が渡したものか、前提の事実の数字が出典の原文にあるかをサーバーで確かめ、1つでも外れたら下書きを出しません。下書きは保存しません。</li></ul>'
           . '<p>下書きは AI が作ったものです。提出の前に、引用と事実を原文で必ず確かめてください。どの問いを出すか、どう仕上げるかを決めるのは人です。</p>'
           . '<p class="src">AI に送るのは、入れた検索語と、収録している質問本文・答弁本文の抜粋だけです。当社の公開版では、当社のサーバーで動かしている gemma4 に送っており、外部の AI サービスには送っていません。オンプレミス版は、AI の設定をしなければこれまでどおり外部に何も送らず、この2つの機能も出ません。</p></div>';
        echo '<div class="panel"><h3>しないこと</h3><ul class="plain"><li>要約・論評・答弁の良し悪しの判定。「答えていない型」は語の一致であって評価ではありません</li><li>原文に無い答弁を引用として出すこと。照合で外れた下書きは捨てます</li><li>下書きを保存すること。控えるのは、同じ語で何度も AI を呼ばないための言い換えの語と、回数制限のための IP アドレスと時刻（1時間で消します）だけです</li></ul></div>';
    } else {
        echo '<div class="panel"><h3>しないこと</h3><ul class="plain"><li>要約・論評・答弁の良し悪しの判定。「答えていない型」は語の一致であって評価ではありません</li><li>文章を作ること。型に空欄を示すところまでです（AI の設定をした設置では、引用を原文と照合した下書きを作れます）</li><li>外部のAIやAPIに送ること。検索は置いた場所で完結します</li></ul></div>';
    }
    echo '<div class="panel"><h3>出典と転載</h3><p>衆議院「質問答弁情報」と参議院「質問主意書」の公開ページから、質問本文・答弁本文・日付・提出者を機械的に抜き出しています。衆議院ウェブサイトは「著作権法上認められた行為として、適宜の方法により出所を明示することにより、引用・転載・複製を行うことができます」としており、各ページに原文へのリンクを置いています。答弁書は閣議決定された行政文書です。</p><p class="src">収録: 第' . h(implode('・', json_decode($META['sessions'] ?? '[]', true) ?: array())) . '回国会（' . n($META['count'] ?? 0) . '件、' . h($META['built'] ?? '') . '集計）。古い回次は順次足します。</p></div>';
    echo '<div class="panel"><h3>オンプレミス版</h3><p>議員事務所の中で、相談記録（Kurage 制度ナビ）と一緒に置いて、事務所だけの論点メモを本文に紐づける版があります。PHP 1ファイルと SQLite で動き、AI の設定をしなければ外に何も送りません。事務所の中の AI（Ollama など）を設定すれば、言い換え検索と下書きも使えます。</p><p><a class="btn" href="' . h($STORE) . '">商品ページを見る</a></p></div>';
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
echo '<h2>質問を書く前に、同じ論点を並べる</h2><div class="panel"><p>論点の語を入れると、過去の質問・政府の答弁の段落（原文）・答えていない型を1画面に並べます。' . ($LLM_ON ? '並べた原文から、AIで質問主意書の下書きも作れます（引用は原文と照合）。' : '文章は作りません。') . '</p>'; search_form('', '/assist', '論点の語（例: 遺族年金 養育費）'); echo '</div>';
echo '<h2>新しい質問主意書</h2>';
foreach ($db->query('SELECT id, house, session, no, title, submitter, kaiha, submit_date, answer_date, status, evasive_json, a_len, q_text, a_text FROM q ORDER BY submit_date DESC, no DESC LIMIT 12') as $r) q_card($r);
echo '<h2>答えていない型</h2><p class="src">答弁書の定型句を語の一致で数えたもの。答弁の評価ではありません。</p><div class="grid">';
foreach ($PATTERNS as $key => $d) { $c = (int)$db->query('SELECT count(*) FROM q WHERE evasive_json LIKE ' . $db->quote('%"' . $key . '"%'))->fetchColumn(); echo '<a class="card" href="' . h($SELF . '/pattern/' . rawurlencode($key)) . '" style="text-decoration:none;color:inherit"><div class="k">' . h($d[0]) . '</div><div class="v">' . n($c) . '<span style="font-size:14px">件</span></div></a>'; }
echo '</div>';
foot_html();
