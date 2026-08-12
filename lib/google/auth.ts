import { google } from 'googleapis';
import { kv } from '../kv';

const REFRESH_TOKEN_KEY = 'google:refresh_token';

export const GOOGLE_SCOPES = [
  'https://www.googleapis.com/auth/calendar',
  'https://www.googleapis.com/auth/tasks',
  'https://www.googleapis.com/auth/youtube.readonly',
  'https://www.googleapis.com/auth/yt-analytics.readonly',
  'https://www.googleapis.com/auth/documents',
];

function createOAuth2Client() {
  const clientId = process.env.GOOGLE_CLIENT_ID;
  const clientSecret = process.env.GOOGLE_CLIENT_SECRET;
  const redirectUri = process.env.GOOGLE_REDIRECT_URI;
  if (!clientId || !clientSecret || !redirectUri) {
    throw new Error('Google OAuth環境変数(GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET / GOOGLE_REDIRECT_URI)が未設定です');
  }
  return new google.auth.OAuth2(clientId, clientSecret, redirectUri);
}

export function getGoogleAuthUrl(): string {
  const client = createOAuth2Client();
  return client.generateAuthUrl({
    access_type: 'offline',
    prompt: 'consent', // refresh_tokenを確実に受け取るため毎回同意画面を表示させる
    scope: GOOGLE_SCOPES,
  });
}

export async function saveRefreshToken(refreshToken: string): Promise<void> {
  await kv.set(REFRESH_TOKEN_KEY, refreshToken);
}

async function loadRefreshToken(): Promise<string> {
  const fromKv = await kv.get<string>(REFRESH_TOKEN_KEY);
  const token = fromKv ?? process.env.GOOGLE_REFRESH_TOKEN;
  if (!token) {
    throw new Error(
      'Googleのrefresh_tokenが未設定です。/api/oauth/google/authorize にアクセスして初回認証を行ってください。'
    );
  }
  return token;
}

export async function exchangeCodeForTokens(code: string) {
  const client = createOAuth2Client();
  const { tokens } = await client.getToken(code);
  if (!tokens.refresh_token) {
    throw new Error(
      'refresh_tokenが取得できませんでした。Googleアカウント設定でこのアプリの連携を一度解除してから再度認証してください。'
    );
  }
  await saveRefreshToken(tokens.refresh_token);
  return tokens;
}

export async function getAuthorizedClient() {
  const client = createOAuth2Client();
  const refreshToken = await loadRefreshToken();
  client.setCredentials({ refresh_token: refreshToken });
  return client;
}
