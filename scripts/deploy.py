#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""kshuisho を heteml（kurage.exbridge.jp）へ上げる。

  /usr/bin/python3 scripts/deploy.py           # 全部
  /usr/bin/python3 scripts/deploy.py --php     # PHP だけ（SQLite を送らない）
  /usr/bin/python3 scripts/deploy.py --extra <ローカル>:<リモート>   # ついでに1本送る

--extra は **FTP を2回張らないため**にある。別プロジェクトの小さな直しを
同じ接続で片づけたいときだけ使う（heteml はうちのIPを短時間で遮断する）。

**FTP は1接続にまとめる。** 短時間に接続を重ねると、うちのIPが全ポートで
15〜20分遮断される（[[reference_heteml_ftp_block]]）。確認は HTTPS で行う。

置き場所:
  /web/kurage_exbridge_jp/kshuisho.php
  /web/kurage_exbridge_jp/kshuisho_data/kshuisho.sqlite … 21MB。.htaccess で直読み禁止
  /web/kurage_exbridge_jp/images/ogp/kshuisho.png     … OGP（kshuisho_data 配下は拒否なので別の場所）
"""
import ftplib
import os
import sys
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BASE = "https://kurage.exbridge.jp"
REMOTE = "/web/kurage_exbridge_jp"
PHP_ONLY = "--php" in sys.argv
EXTRA = []
if "--extra" in sys.argv:
    for spec in sys.argv[sys.argv.index("--extra") + 1:]:
        if spec.startswith("--"):
            break
        local, remote = spec.split(":", 1)
        EXTRA.append((local, remote if remote.startswith("/") else f"{REMOTE}/{remote}"))

FILES = [(f"{ROOT}/php/kshuisho.php", f"{REMOTE}/kshuisho.php"),
         (f"{ROOT}/php/kshuisho_data/.htaccess", f"{REMOTE}/kshuisho_data/.htaccess"),
         (f"{ROOT}/outputs/kshuisho_ogp.png", f"{REMOTE}/images/ogp/kshuisho.png")]
if not PHP_ONLY:
    FILES.insert(1, (f"{ROOT}/php/kshuisho_data/kshuisho.sqlite", f"{REMOTE}/kshuisho_data/kshuisho.sqlite"))
FILES += EXTRA


def env():
    for line in open("/home/kojima/work/aixec/.env", encoding="utf-8"):
        if "=" in line and not line.startswith("#"):
            k, v = line.rstrip("\n").split("=", 1)
            os.environ.setdefault(k, v.strip().strip('"').strip("'"))


def main() -> int:
    env()
    f = ftplib.FTP(os.environ["FTP_HOST"], timeout=600)
    f.login(os.environ["FTP_USER"], os.environ["FTP_PASS"])
    for local, remote in FILES:
        d = os.path.dirname(remote)
        try:
            f.cwd(d)
        except ftplib.error_perm:
            f.mkd(d)
            f.cwd(d)
        size = os.path.getsize(local)
        with open(local, "rb") as fh:
            f.storbinary("STOR " + os.path.basename(remote), fh, blocksize=1 << 18)
        print(f"  {remote}  {size/1024:.0f}KB")
    f.quit()

    # 確認は HTTPS（FTP を再接続しない）
    for path in ("/kshuisho.php/", "/kshuisho.php/assist", "/kshuisho.php/giin", "/kshuisho.php/about", "/images/ogp/kshuisho.png"):
        req = urllib.request.Request(BASE + path, headers={"User-Agent": "kshuisho-deploy/1.0"})
        try:
            with urllib.request.urlopen(req, timeout=90) as r:
                body = r.read(400)
                print(f"  {r.status} {len(body)}B+ {BASE}{path}")
        except Exception as e:
            print(f"  ! {BASE}{path}: {e}")
    # データが直読みできないことも確認する
    try:
        with urllib.request.urlopen(BASE + "/kshuisho_data/kshuisho.sqlite", timeout=60) as r:
            print(f"  ! SQLite が直接読めてしまう: {r.status}")
    except urllib.error.HTTPError as e:
        print(f"  {e.code} SQLite の直読みは拒否されている（想定どおり）")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
