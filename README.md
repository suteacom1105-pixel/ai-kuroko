# LINE AI秘書

GO専用のLINE公式アカウント秘書システム。Vercel Functions + Claude API (Tool Use) + Google Calendar/YouTube API + 気象庁APIで構成。

## ディレクトリ構成

```
api/
  webhook.ts              LINE Webhook受信口(userId判定→秘書/窓口モードに振り分け)
  cron/morning.ts          毎朝の天気+予定通知(Vercel Cron)
  oauth/google/
    authorize.ts           Google初回認証の入口(ブラウザでアクセス)
    callback.ts            認証コールバック(refresh_tokenをKVに保存)
secretary/index.ts         GO本人向け:Claude Tool Useの会話ループ
frontdesk/index.ts         スタッフ向け:伝言をGOにプッシュ転送するだけ
lib/
  line.ts                  LINE Messaging APIラッパー(署名検証/reply/push)
  claude/tools.ts          Claude Tool Use定義とディスパッチャ
  google/{auth,calendar,tasks,youtube,docs}.ts
  weather.ts               気象庁(JMA)から仙台の天気を取得
  kv.ts                    会話履歴の一時保存(アイデアメモ本体はGoogleドキュメントに保存、ドキュメントIDのみKVに保持)
  auth/roles.ts            userId→owner/staff判定
  date.ts                  JST日付ユーティリティ
```

将来LINE公式アカウントを2つに分ける場合は `secretary/` と `frontdesk/` を別プロジェクトに切り出し、
`LINE_CHANNEL_ACCESS_TOKEN` 等の環境変数を分けるだけで対応できる構成にしてあります。

---

## セットアップ手順

ここから先はブラウザでの手動操作が必要です(アカウント作成やAPIキー発行はご本人の操作が必要なため)。
必要になり次第、画面遷移を一緒に確認しながら進めます。

### 1. Vercelプロジェクト作成 + KV

1. [Vercel](https://vercel.com/) にログインし、新規プロジェクトを作成(既存の接骨院プロジェクトとは別で)。
2. このディレクトリをGitリポジトリ化してVercelに接続、またはVercel CLIでデプロイ。
3. Vercelダッシュボード → Storage → Create Database → **KV** を作成し、プロジェクトに接続。
   → `KV_REST_API_URL` / `KV_REST_API_TOKEN` が自動で環境変数に注入されます。

### 2. LINE Developersでチャンネル作成

1. [LINE Developers Console](https://developers.line.biz/console/) でプロバイダーを作成。
2. 「Messaging API」チャンネルを新規作成。
3. チャンネル基本設定から **チャンネルシークレット** を取得 → `LINE_CHANNEL_SECRET`
4. Messaging API設定タブから **チャンネルアクセストークン(長期)** を発行 → `LINE_CHANNEL_ACCESS_TOKEN`
5. Webhook URLに `https://<デプロイ先ドメイン>/api/webhook` を設定し、Webhookの利用を **オン**。
6. 応答メッセージ・あいさつメッセージ等のデフォルト自動応答は **オフ** にする(Claude側で処理するため)。

### 3. Google Cloud ConsoleでOAuth設定

1. [Google Cloud Console](https://console.cloud.google.com/) で新規プロジェクトを作成。
2. 「APIとサービス」→「ライブラリ」から以下を有効化:
   - Google Calendar API
   - Google Tasks API
   - YouTube Data API v3
   - YouTube Analytics API
   - Google Docs API(アイデアメモの保存先)
3. 「OAuth同意画面」を設定:
   - User Type: 外部(個人利用なので審査不要の「テスト」状態のままでOK)
   - テストユーザーにGO本人のGoogleアカウントを追加
   - スコープ: Calendar読み書き、Tasks読み書き、YouTube Data読み取り、YouTube Analytics読み取り、Docs読み書き
   - ⚠️ テストユーザー状態だとrefresh_tokenが7日で失効する場合があります。長期運用する場合は
     OAuth同意画面を「本番環境に公開」に切り替えてください(個人利用のみなら審査は基本不要)。
4. 「認証情報」→「OAuth クライアント ID を作成」(アプリケーションの種類: ウェブアプリケーション)
   - 承認済みのリダイレクトURIに `https://<デプロイ先ドメイン>/api/oauth/google/callback` を追加
   - クライアントID/シークレットを `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` に設定
   - `GOOGLE_REDIRECT_URI` にも同じコールバックURLを設定

### 4. 環境変数の設定

`.env.example` を参照し、Vercelプロジェクトの Settings → Environment Variables に以下を設定:

- `LINE_CHANNEL_ACCESS_TOKEN` / `LINE_CHANNEL_SECRET`
- `GO_LINE_USER_ID`(下記手順で取得)
- `STAFF_LINE_USER_IDS`(カンマ区切り、複数スタッフ対応)
- `ANTHROPIC_API_KEY` / `CLAUDE_MODEL`
- `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` / `GOOGLE_REDIRECT_URI` / `GOOGLE_CALENDAR_ID`
- `JMA_OFFICE_CODE` / `JMA_AREA_CODE` / `JMA_AMEDAS_CODE`(仙台用のデフォルト値のまま変更不要)
- `CRON_SECRET`(ランダムな文字列を生成して設定。Vercel Cronが自動でAuthorizationヘッダに付与し、
  `api/cron/morning.ts` 側で検証することで第三者からの不正呼び出しを防ぐ)

#### GO本人・スタッフのLINE userIdの調べ方

1. まず `GO_LINE_USER_ID` は空のまま一度デプロイ。
2. GO本人がLINE公式アカウントを友だち追加してメッセージを送る。
3. Vercelのダッシュボード → Functions → `api/webhook` のログを開くと、Webhookイベントの
   `source.userId` が確認できるので、それを `GO_LINE_USER_ID` に設定して再デプロイ。
4. 同様の手順でスタッフにも一度メッセージを送ってもらい、userIdを `STAFF_LINE_USER_IDS` に追加。

### 5. デプロイ

```bash
npm install
vercel --prod
```

### 6. Google初回認証

デプロイ後、ブラウザで一度だけ以下にアクセスしてGoogleアカウントを認証してください:

```
https://<デプロイ先ドメイン>/api/oauth/google/authorize
```

「Google連携が完了しました」と表示されれば成功です。以降はrefresh_tokenがVercel KVに保存され、
自動的にアクセストークンが更新されます。

---

## 動作確認のポイント

- LINEでGO本人から「今日の予定教えて」「明日15時に歯医者」等を送って秘書モードの動作を確認。
- 「〇〇をアイデアメモして」等を送り、専用のGoogleドキュメントに追記されることを確認(初回はドキュメントが自動作成され、そのIDがVercel KVに保存される)。
- 「新幹線予約した、〇月〇日15時、のぞみ23号3号車5A、予定に入れて」等を送り、予定のメモ欄に座席番号等の詳細が入ることを確認。当日朝の通知にもその内容が表示される。
- スタッフのアカウントから何か送信し、GO本人に「【伝言】〇〇さんより:」という形でプッシュ通知が
  届くことを確認(この際、予定には自動反映されないことも確認)。
- `api/cron/morning.ts` はVercelダッシュボードの Cron Jobs 画面から手動実行(Run)して
  通知内容を確認できます。

## 既知の制約・注意点

- Webhook処理はClaude APIやGoogle APIの応答を待ってから200を返す同期処理のため、
  Vercelのタイムアウト設定(Hobby: 10秒 / Pro: 最大60秒などプランに依存)に注意してください。
  ツール呼び出しが複数回連鎖する複雑な依頼では時間がかかる場合があります。
- LINEの無料枠(200通/月)はpush(能動送信)のみが対象。毎朝通知(月30通程度)+伝言転送分は
  通常の利用であれば収まる見込みですが、実際の料金体系は
  [LINE Developers公式ドキュメント](https://developers.line.biz/ja/docs/messaging-api/)で
  都度確認してください。
- OAuth同意画面が「テスト」状態のままだとrefresh_tokenが7日で失効することがあります。
  「Googleのrefresh_tokenが未設定です」というエラーが出た場合は `/api/oauth/google/authorize`
  から再認証するか、本番運用への切り替えを検討してください。
- Googleの認証スコープを追加・変更した場合(例: アイデアメモ機能でのDocs APIスコープ追加)、
  既存のrefresh_tokenには新スコープの権限が含まれていません。`/api/oauth/google/authorize` から
  再認証してrefresh_tokenを更新してください。
