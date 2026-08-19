import { google } from 'googleapis';
import { kv } from '../kv';

// 秘書機能(Calendar/Tasks/YouTube/Docs)とは別のGoogleアカウント(YouTube案件メール受信用)を
// 監視するための、独立したOAuth資格情報。同じOAuthクライアント(GOOGLE_CLIENT_ID/SECRET)を使うが、
// refresh_tokenは別のKVキーに保存し、既存の認証を上書きしないようにする。
const GMAIL_REFRESH_TOKEN_KEY = 'google:gmail_refresh_token';

export const GMAIL_SCOPES = ['https://www.googleapis.com/auth/gmail.readonly'];

function createGmailOAuth2Client() {
  const clientId = process.env.GOOGLE_CLIENT_ID;
  const clientSecret = process.env.GOOGLE_CLIENT_SECRET;
  const redirectUri = process.env.GOOGLE_GMAIL_REDIRECT_URI;
  if (!clientId || !clientSecret || !redirectUri) {
    throw new Error(
      'Gmail監視用のOAuth環境変数(GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET / GOOGLE_GMAIL_REDIRECT_URI)が未設定です'
    );
  }
  return new google.auth.OAuth2(clientId, clientSecret, redirectUri);
}

export function getGmailAuthUrl(): string {
  const client = createGmailOAuth2Client();
  return client.generateAuthUrl({
    access_type: 'offline',
    prompt: 'consent',
    scope: GMAIL_SCOPES,
  });
}

export async function exchangeGmailCodeForTokens(code: string) {
  const client = createGmailOAuth2Client();
  const { tokens } = await client.getToken(code);
  if (!tokens.refresh_token) {
    throw new Error(
      'refresh_tokenが取得できませんでした。Googleアカウント設定でこのアプリの連携を一度解除してから再度認証してください。'
    );
  }
  await kv.set(GMAIL_REFRESH_TOKEN_KEY, tokens.refresh_token);
  return tokens;
}

export async function getAuthorizedGmailClient() {
  const client = createGmailOAuth2Client();
  const refreshToken = await kv.get<string>(GMAIL_REFRESH_TOKEN_KEY);
  if (!refreshToken) {
    throw new Error(
      'Gmail監視用のrefresh_tokenが未設定です。/api/oauth/gmail/authorize にアクセスして初回認証を行ってください。'
    );
  }
  client.setCredentials({ refresh_token: refreshToken });
  return client;
}
