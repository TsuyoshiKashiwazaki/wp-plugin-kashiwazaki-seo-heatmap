# Kashiwazaki SEO Heatmap

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)
![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)
![Version](https://img.shields.io/badge/version-1.0.1-blue.svg)

WordPress の投稿・固定ページ・カスタム投稿の「どこがクリックされているか」を可視化するヒートマップ解析プラグインです。

設定画面でチェックを入れた投稿タイプだけを対象に、クリック・スクロール・マウスムーブを記録し、
実際のページの上に色で重ねて表示します。

- プラグイン名: **Kashiwazaki SEO Heatmap**
- ディレクトリ名: `wp-plugin-kashiwazaki-seo-heatmap`
- バージョン: 1.0.1
- 動作要件: WordPress 6.0 以上 / PHP 7.4 以上
- ライセンス: GPLv2 またはそれ以降

> 名称は省略しません。管理画面のメニューも「Kashiwazaki SEO Heatmap」と表示します。

## 主な機能

| 機能 | 説明 |
| --- | --- |
| クリックヒートマップ | クリックの集中している場所をページの上に色で重ねて表示します。赤いほどよく押されています。 |
| 要素ごとの集計 | 座標だけでなく「どのリンク・どのボタンが押されたか」を CSS セレクタ単位で数えます。 |
| よくクリックされている要素ランキング | 回数と割合を一覧表示します。CSV で書き出せます。 |
| スクロール到達率 | 10% 刻みで、どこまで読まれたかを表示します。 |
| マウスムーブ | マウスの動きの分布を重ねて表示します（任意）。 |
| レイジクリック | 同じ場所を短時間に連打した箇所を検出します。 |
| デッドクリック | 押しても何も起きなかったクリックを検出します。 |
| 外部リンクのクリック | どの外部リンクが押されたかを記録します。 |
| 絞り込み | ページ・期間・端末で絞り込めます。 |
| 配色の変更 | ヒートマップの配色を選べます。 |

## 使い方

1. プラグインを有効化します。
2. 管理画面の左メニュー「Kashiwazaki SEO Heatmap」→「設定」を開きます。
3. 「計測する投稿タイプ」で、計測したい投稿タイプにチェックを入れて保存します。
4. そのページを普通に閲覧すると計測が始まります。
5. 「ヒートマップ」画面で、ページ・期間・端末・表示するデータを選ぶと結果が表示されます。
   3 つの画面（ヒートマップ・ダッシュボード・設定）は、見出しの下のタブで行き来できます。

ログイン中の管理者は、既定では計測の対象から外れています。自分の操作を記録したい場合は
「設定」画面の「ログイン中のユーザーを計測から除外する」のチェックを外してください。

## プライバシーへの配慮

- IP アドレスは保存しません（除外判定に使うだけで、データベースには残しません）。
- 訪問者の識別には、ブラウザの Cookie に保存したランダムな文字列だけを使います。
- 通信の都合でまだ届いていない記録は、届くまでの間だけ訪問者のブラウザの保存領域（localStorage）に残して送り直します。届いたものはブラウザから消し、届かないまま 7 日を過ぎたものは送らずに消します。ブラウザに保存できないときだけ、その記録の番号（ランダムな文字列）と操作の件数を Cookie に最長 7 日残します。
- 「ログイン中のユーザーを計測から除外する」がオンのときは、ログインしている間だけ、記録しない人であることを示す Cookie を使います。
- ブラウザの「追跡しない（Do Not Track）」設定を尊重できます。
- ログイン中のユーザーや、指定した権限グループを計測から除外できます。
- 特定の IP アドレスや URL を除外できます。
- サンプリング（何 % の訪問者を計測するか）を設定できます。
- 生データの保持期間を決め、古いものを毎日自動で削除できます。
- データはこのサイトのデータベースの中だけに保存されます。外部のサーバーへは送信しません。
- 「設定 → プライバシー」の「プライバシーポリシーの下書きを提案」に、集める情報の説明を差し込めます。

## ディレクトリ構成

```
wp-plugin-kashiwazaki-seo-heatmap/
├── wp-plugin-kashiwazaki-seo-heatmap.php   プラグイン本体（ヘッダ・定数・起動）
├── uninstall.php                           削除時のデータ消去
├── readme.txt                              WordPress 標準の readme
├── CHANGELOG.md                            変更履歴
├── assets/
│   ├── css/kashiwazaki-seo-heatmap-admin.css
│   └── js/
│       ├── kashiwazaki-seo-heatmap-admin.js      管理画面のヒートマップ表示
│       └── kashiwazaki-seo-heatmap-tracker.js    フロント側の計測
├── includes/
│   ├── kashiwazaki-seo-heatmap-functions.php     共通関数
│   ├── kashiwazaki-seo-heatmap-db.php            テーブル定義と読み書き
│   ├── kashiwazaki-seo-heatmap-settings.php      設定の検証
│   ├── kashiwazaki-seo-heatmap-activation.php    有効化・無効化
│   ├── kashiwazaki-seo-heatmap-storage.php       受信データの保存
│   ├── kashiwazaki-seo-heatmap-rest-api.php      受信用 REST エンドポイント
│   ├── kashiwazaki-seo-heatmap-tracker.php       計測スクリプトの読み込み
│   ├── kashiwazaki-seo-heatmap-retention.php     保持期間を過ぎたデータの削除
│   ├── kashiwazaki-seo-heatmap-privacy.php       プライバシーポリシーへの提案文
│   └── admin/
│       ├── class-kashiwazaki-seo-heatmap-admin.php         管理画面の共通処理
│       ├── kashiwazaki-seo-heatmap-admin-heatmap.php       ヒートマップ画面
│       ├── kashiwazaki-seo-heatmap-admin-dashboard.php     ダッシュボード画面
│       └── kashiwazaki-seo-heatmap-admin-settings.php      設定画面
└── docs/
    ├── index.html                    利用者向けマニュアル（概要）
    ├── setup.html                    利用者向けマニュアル（インストール・初期設定）
    ├── heatmap.html                  利用者向けマニュアル（ヒートマップ画面）
    ├── dashboard.html                利用者向けマニュアル（ダッシュボード画面）
    ├── settings.html                 利用者向けマニュアル（設定画面）
    ├── troubleshooting.html          利用者向けマニュアル（トラブルシューティング）
    ├── css/style.css                 マニュアルのスタイル
    ├── js/manual.js                  マニュアルのスクリプト
    ├── images/
    │   ├── main-visual.png           メインビジュアル
    │   ├── screenshots/              画面のスクリーンショット
    │   └── infographics/             インフォグラフィック
```

## データの持ち方

- **生データ**（`{prefix}kashiwazaki_seo_heatmap_events`）— 1 件 1 行。保持期間を過ぎると自動で削除されます。
- **集計**（`{prefix}kashiwazaki_seo_heatmap_stats`）— ページ × 日付 × 端末 × 要素ごとの合計。
- **ページ**（`{prefix}kashiwazaki_seo_heatmap_pages`）— ページ × 日付 × 端末ごとのページビュー・訪問者・入り口・滞在時間。

管理画面は常に集計テーブルを読むため、生データが増えても表示は遅くなりません。

## 開発

ローカルでの確認は `http://localhost/` で行っています。

```bash
# JavaScript の構文チェック
node --check assets/js/kashiwazaki-seo-heatmap-admin.js
node --check assets/js/kashiwazaki-seo-heatmap-tracker.js

# PHP の構文チェック
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

## ライセンス

GPLv2 またはそれ以降。詳しくは [LICENSE](LICENSE) を参照してください。

Copyright (C) 2026 柏崎剛 (Tsuyoshi Kashiwazaki)
