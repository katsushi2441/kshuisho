#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""提出者（kshuisho DB）のXアカウントを、候補ハンドルを api.fxtwitter.com/<handle>（url2ai の ustory と同じ・ログイン不要）
で引いて「表示名に本人の名前が入っているか」で確かめる。当たったものだけ data/x_handles.json に書く。推測のまま使わない。
  /usr/bin/python3 scripts/resolve_x_handles.py
"""
import json, os, re, sqlite3, time, urllib.request
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'data', 'x_handles.json')
# 候補（人名 → ハンドル候補）。確認できたものだけ残す
CAND = {
 '石垣 のりこ': ['norinotes'], '原口 一博': ['kharaguchi'], '松原 仁': ['jin_matsubara', 'matsubara_jin'], '神谷 宗幣': ['jinkamiya'],
 '櫻井 周': ['sakurai_shu', 'sakuraishu'], '屋良 朝博': ['yaratomohiro', 'yara_tomohiro'], '八幡 愛': ['ai_yahata', 'yahata_ai'],
 '水野 素子': ['mizuno_motoko', 'motoko_mizuno'], '牧山 ひろえ': ['makiyamahiroe', 'hiroe_makiyama'], '山本 太郎': ['yamamototaro0'],
 '中谷 一馬': ['kazuma_nakatani', 'nakatanikazuma'], '井坂 信彦': ['isakanobuhiko', 'nobuhiko_isaka'], '緒方 林太郎': ['ogata_rintaro', 'rintaro_ogata'],
 '山井 和則': ['yamanoi_kazunori', 'kazunori_yamanoi'], '福島 みずほ': ['mizuhofukushima'], '小西 洋之': ['konishihiroyuki'],
 '杉村 慎治': ['sugimurashinji', 'shinji_sugimura'], '長妻 昭': ['nagatsumaakira'], '島田 洋一': ['ProfShimada'], '齊藤 健一郎': ['kenichiro_saito', 'saitokenichiro'],
 '塩村 あやか': ['shiomura'], '福田 玄': ['fukudagen', 'gen_fukuda'], '長友 よしひろ': ['nagatomo_yoshi', 'nagatomoyoshihiro'], '阿部 知子': ['abe_tomoko'],
 '須藤 元気': ['sudogenki'], '吉田 はるみ': ['YoshidaHarumi'], '有田 芳生': ['aritayoshifu'], '鈴木 庸介': ['suzukiyosuke', 'yosuke_suzuki'],
 '大石 あきこ': ['oishiakiko'], '早稲田 ゆき': ['wasedayuki', 'yuki_waseda'], '宮本 徹': ['miyamototooru'], '辻元 清美': ['tsujimotokiyomi'],
 'ラサール 石井': ['lasar141'], '竹上 裕子': ['takegami_yuko', 'yuko_takegami'], '馬場 雄基': ['babayuki_', 'yuki_baba'], '吉川 里奈': ['rina_yoshikawa_'],
 '伊勢崎 賢治': ['isezakikenji'], '山崎 誠': ['yamazakimakoto', 'makoto_yamazaki'], '高良 沙哉': ['takara_sachika', 'sachika_takara'],
 'たがや 亮': ['tagaya_ryo', 'ryotagaya'], '緑川 貴士': ['midorikawatakashi', 'takashi_midorikawa'], '藤原 規眞': ['fujiwara_norima', 'norimafujiwara'],
 '鈴木 宗男': ['muneosuzuki', 'suzuki_muneo'], '阪口 直人': ['sakaguchinaoto'], '上村 英明': ['uemura_hideaki'], '日野 紗里亜': ['saria_hino'],
 '伊藤 孝恵': ['itotakae0630'], '福田 徹': ['Toru_Fukuta'], '丹野 みどり': ['tannomidori'], '浜田 聡': ['satoshi_hamada'],
}
def norm(s): return re.sub(r'[\s　]', '', s or '')
def fx(h):
    try:
        d = json.load(urllib.request.urlopen(urllib.request.Request(f'https://api.fxtwitter.com/{h}', headers={'User-Agent': 'Mozilla/5.0'}), timeout=30))
        return (d.get('user') or {}) if d.get('code') == 200 else {}
    except Exception: return {}
db = sqlite3.connect(os.path.join(ROOT, 'php', 'kshuisho_data', 'kshuisho.sqlite'))
counts = dict(db.execute('SELECT submitter, count(*) FROM q GROUP BY submitter'))
res = json.load(open(OUT, encoding='utf-8')) if os.path.exists(OUT) else {}
for name, hs in CAND.items():
    if name in res: continue
    sur, giv = (name.split(' ') + [''])[:2]
    for h in hs:
        u = fx(h); dn = norm(u.get('name'))
        ok = bool(dn) and sur in dn and (giv[:2] in dn if giv else True)
        print(f'{name:10} @{h:18} → {"OK " if ok else "NG "} {u.get("name","")[:30]}', flush=True)
        if ok:
            res[name] = {'handle': h, 'display': u.get('name'), 'followers': u.get('followers'), 'desc': (u.get('description') or '')[:80], 'n_q': counts.get(name, 0)}
            break
        time.sleep(0.7)
json.dump(res, open(OUT, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
print('確定', len(res), '人 →', OUT)
