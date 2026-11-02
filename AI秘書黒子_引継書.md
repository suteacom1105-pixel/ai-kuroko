# AI秘書「黒子」引継書

作成日: 2026-08-12
プロジェクトディレクトリ: `/Users/a1105/Claude/AI秘書`

## 1. プロジェクト概要

GO(接骨院経営、フリースクール「revision」運営、YouTube運用)専属のLINE AI秘書。
LINE公式アカウントを窓口に、Claude API(Tool Use)でスケジュール・タスク・アイデアメモ・
YouTube分析を扱う。Vercelのサーバーレス関数で動作。**現在、本番稼働中。**

## 2. インフラ・アカウント一覧

| 項目 | 内容 |
|---|---|
| GitHubリポジトリ | https://github.com/suteacom1105-pixel/ai-kuroko (Public) |
| Vercelチーム/プロジェクト | チーム `hizaspo`(Hobbyプラン)/ プロジェクト `ai-kuroko` |
| 本番ドメイン | https://ai-kuroko.vercel.app |
| Vercel KV | Upstash Redis連携(Freeプラン)。KV_REST_API_URL等は自動設定済み |
| LINE公式アカウント | 「AI秘書 黒子」(ID: `@167dwtbz`)、プロバイダー「岩切整骨院」配下 |
| Google Cloudプロジェクト | `ai-kuroko`。OAuth同意画面は**本番環境に公開済み**(外部/機密性の高いスコープ含む) |
| Googleアカウント(OAuth認証済み) | sutea.com1105@gmail.com |
| Anthropic (Claude API) | console.anthropic.com、$5クレジット購入済み |
| 連携中YouTubeチャンネル | 「ウーゴ / ADHD×ガジェット」(Channel ID: `UCGHizl3f3lgu6iflVMyfJTQ`)。所有者は別アカウント(hello.adhdworld@gmail.com)、sutea.com1105@gmail.comをマネージャーとして招待済み |
| GOのLINE userId | `U345fd0ef9828fcfe3324b3c15242f373`(`GO_LINE_USER_ID`に設定済み) |
| Gmail監視用Googleアカウント | hello.adhdworld@gmail.com(YouTube案件メール受信用。sutea.com1105@gmail.comとは別のOAuth認証・別のrefresh_token) |

## 3. 実装済み機能(すべて動作確認済み)

- 予定管理(Google Calendar): 確認・追加・変更・削除、自然文対応
- タスク管理(Google Tasks): 一覧・追加・完了・削除(予定とは別概念として区別)
- 毎朝の自動通知(Cron, 8:00 JST): 仙台の天気 + 今日/明日の予定 + 未完了タスク
- アイデアメモ(2026-08-12にGoogleドキュメント保存へ切り替え。専用ドキュメントを自動作成し、以後はそこに追記。ドキュメントIDのみVercel KVに保持。デプロイ・Docs API有効化・再認証・実機テスト済み)
- チケット(新幹線・飛行機・コンサート等)の予定登録: テキストでの依頼、または**チケットのスクリーンショット画像をLINEで送るだけ**で日時・座席番号等をClaudeが読み取り予定に登録(2026-08-12実装、GO本人のみ対応)。座席番号等の詳細は予定のメモ欄に入り、当日朝の通知にも表示される
- YouTube動画分析(再生数・視聴維持率・トラフィックソース + 改善アドバイス)
- YouTube案件メール通知(2026-08-12実装): hello.adhdworld@gmail.comを1日1回(朝8:15 JST)チェックし、
  Claudeが「YouTube案件(広告・PR・コラボ・タイアップ等)」と判定したメールだけLINEに通知。
  Vercel HobbyプランのCron制限(1日1回まで)のため、この頻度にしている。デプロイ・Gmail API有効化・
  監視用アカウントでの再認証・実機テストが未完了(下記6参照)
- ひざスポ接骨院の予約表操作(2026-11-02実装): LINEで「〇日〇時 〇〇さん入れといて」「〇時〜〇時を予約不可にして」等と送ると、
  本番予約システム(https://yoyaku.hizasupo.com、コードは`/Users/a1105/Claude/予約システム/yoyaku-sakura`)の管理APIで
  予約追加・キャンセル・日時変更・予約不可の設定/解除を行う。GO本人のみ。認証は`X-Kuroko-Token`ヘッダー(Vercel環境変数`YOYAKU_API_TOKEN`と
  さくらの`~/yoyaku_app/config.ai.php`に同じ値)。メニュー・止める担当の指定がなければ黒子が確認する
- スタッフ伝言取次(窓口モード): 実装済みだが**実際のスタッフでの動作テスト未実施**

## 4. 環境変数(Vercel Environment Variablesに設定済み。値はVercelダッシュボードで確認)

```
LINE_CHANNEL_ACCESS_TOKEN
LINE_CHANNEL_SECRET
CRON_SECRET
GOOGLE_CLIENT_ID
GOOGLE_CLIENT_SECRET
GOOGLE_REDIRECT_URI       = https://ai-kuroko.vercel.app/api/oauth/google/callback
GOOGLE_CALENDAR_ID        = primary
ANTHROPIC_API_KEY
GO_LINE_USER_ID
YOUTUBE_CHANNEL_ID        = UCGHizl3f3lgu6iflVMyfJTQ
GOOGLE_GMAIL_REDIRECT_URI = https://ai-kuroko.vercel.app/api/oauth/gmail/callback (Gmail監視用、未設定)
KV_*                      (Upstash連携で自動設定)
```

**未設定**: `STAFF_LINE_USER_IDS`(スタッフ機能を使うタイミングで追加)

## 5. 開発中に解決した不具合(参考)

1. package.jsonの不要な`build`スクリプトがVercelのデプロイを失敗させていた → 削除
2. `GO_LINE_USER_ID`未設定時にWebhookが例外で落ち、userIdのログ取得すらできなかった → 例外を投げないよう修正
3. Googleカレンダーの予定時刻が9時間ずれる(アカウントのデフォルトタイムゾーン依存) → `events.list`に`timeZone: 'Asia/Tokyo'`を明示
4. 今日の気温が常に「不明」になる(気象庁の週間予報は当日分が空欄の仕様) → 短期予報データから当日分を取得するよう修正
5. YouTube「mine:true」がブランドアカウントのチャンネルを解決できなかった → `YOUTUBE_CHANNEL_ID`を明示指定する方式に変更

## 6. 未完了・保留中の項目

- [ ] スタッフの誰かに一度メッセージを送ってもらい、VercelログからuserIdを確認して`STAFF_LINE_USER_IDS`に追加(伝言取次のテスト)
- [ ] 8:00 JSTの自動Cron通知が実際に毎朝正常に届いているかの継続確認(手動実行では正常動作を確認済み)
- [ ] 会話中に平文で共有されてしまった各種シークレット(LINEチャネルシークレット/アクセストークン、Google Client Secret、Anthropic APIキー)の再発行(推奨、未実施)
- [ ] Web検索機能(市場調査・お店検索等に使える)は2026-08-12に一度実装を試みたが、コストが高い(検索$10/1000回+トークン費用)との理由で保留に。実装は行わない方針
- [ ] チケット画像からの予定登録(2026-08-12実装)は実機での複数パターンのテスト(新幹線・飛行機・コンサート等、画像が不鮮明な場合の挙動)が未実施
- [x] YouTube案件メール通知(2026-08-12実装): Gmail API有効化・hello.adhdworld@gmail.comでの認証・実機テスト(微妙な文面の案件メールも検知)まで完了

## 7. 補足

- 「AI秘書を外部に販売する」という別件のビジネス検討も同日に話したが、これは黒子本体とは別件のため本引継書には含めていない
- READMEにセットアップ手順の全体像あり(`/Users/a1105/Claude/AI秘書/README.md`)

## 8. さくらサーバーへの移行（2026-11-02開始、進行中）

Vercel(Node.js)版をさくらサーバーのPHP版に作り直し中。予約システム(yoyaku.hizasupo.com)と同じ「並行で作って最後に切り替える」方式。
**切り替えまで Vercel 版がそのまま本番。** 戻す時は LINE の Webhook URL を Vercel に戻すだけ。

- コード: `sakura/`（このリポジトリ内。Composer 無しの素の PHP 8.2、保存は SQLite）。テスト `php sakura/tests/run.php`（32件）
- 設置先: `~/kuroko_app/`（非公開）、`~/www/kuroko.hizasupo.com/`（公開）。`sh sakura/deploy/deploy.sh` で反映（Claude が実行可）
- 鍵: `~/kuroko_app/config.secrets.php`（`sh sakura/deploy/setup_secrets.sh` をユーザーが実行。Claude は見ない）
- ツール定義 `sakura/app/tools.json` は TS 版から書き出したもの。以後はこちらが正
- 確認用: `php kuroko_app/cli/status.php`（設定状況）、`cli/try.php "メッセージ"`（LINE を通さず会話。ツールは本物が動く）、
  `cron/morning.php --dry-run`・`cron/gmail_check.php --dry-run`（送信しない）
- 切り替え前は `live` ファイルが無いので、定期実行は LINE に送らない

### 進捗
- [x] PHP 版の作成・テスト・サーバーへ設置（2026-11-02。天気取得まで本番サーバーで動作確認）
- [x] サブドメイン `kuroko.hizasupo.com` 追加（www なし・IPv6 なし）（2026-11-02、http で到達確認）
- [ ] 無料SSL の反映待ち（ユーザーが申請済み）
- [x] `sh sakura/deploy/setup_secrets.sh`（2026-11-02。LINE トークン・新しい Claude キー(`kuroko-sakura`、期限なし)はサーバー上で疎通確認済み。アイデアメモは元のドキュメントを引き継ぎ）
- [x] Google Cloud のクライアントに承認済みリダイレクトURI 2つ追加、シークレットを追加（2026-11-02。旧 URI・旧シークレット `****B9ET` は Vercel 版用に残してある）。※Google Cloud は2026-08-26から2段階認証が必須（設定済み）
- [ ] `https://kuroko.hizasupo.com/api/oauth/google/authorize`（sutea.com1105@gmail.com）と `…/api/oauth/gmail/authorize`（hello.adhdworld@gmail.com）で認証（ユーザー）
- [ ] 予約システム側: `sh deploy/deploy.sh` と `sh deploy/set_kuroko_token.sh`（予約システムのフォルダで、ユーザー）
- [ ] サーバー上で動作確認（status / try.php / dry-run）（Claude）
- [ ] 切り替え: LINE Developers で Webhook URL を `https://kuroko.hizasupo.com/api/webhook` に変更して「検証」（ユーザー）→
      `php kuroko_app/cli/go_live.php` と crontab 登録（朝8:00 morning、gmail_check は**1時間おき・毎時5分**＝ユーザー指定、**23:00 evening**＝翌日に予定がある時だけ明日の予定を送る）→ Vercel の vercel.json の crons を外して再デプロイ（二重通知防止）（Claude）
- [ ] 2週間ほど様子を見てから Vercel プロジェクト・Upstash・古い Claude キー・古い Google シークレットを削除
