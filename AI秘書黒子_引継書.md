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
- [ ] YouTube案件メール通知(2026-08-12実装)はデプロイ・実機テストが未完了。必要な手順:
      ① Google Cloud Console `ai-kuroko` プロジェクトで **Gmail API** を有効化
      ② OAuth同意画面のテストユーザーに hello.adhdworld@gmail.com を追加、スコープに `gmail.readonly` を追加
      ③ OAuthクライアントの承認済みリダイレクトURIに `https://ai-kuroko.vercel.app/api/oauth/gmail/callback` を追加
      ④ Vercel環境変数に `GOOGLE_GMAIL_REDIRECT_URI` を設定
      ⑤ `vercel --prod` でデプロイ
      ⑥ ブラウザで `/api/oauth/gmail/authorize` にアクセスし、**hello.adhdworld@gmail.comでログインして認証**
         (「このアプリは確認されていません」の警告が出た場合は「詳細」→進める、を選択。gmail.readonlyは
         Googleの「制限付きスコープ」のため表示される)
      ⑦ hello.adhdworld@gmail.com宛にYouTube案件らしいメールを送り、Vercelダッシュボードから
         `api/cron/gmail-check` を手動実行してLINEに通知が届くか確認

## 7. 補足

- 「AI秘書を外部に販売する」という別件のビジネス検討も同日に話したが、これは黒子本体とは別件のため本引継書には含めていない
- READMEにセットアップ手順の全体像あり(`/Users/a1105/Claude/AI秘書/README.md`)
