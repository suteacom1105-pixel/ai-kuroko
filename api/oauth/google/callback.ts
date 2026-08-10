import type { VercelRequest, VercelResponse } from '@vercel/node';
import { exchangeCodeForTokens } from '../../../lib/google/auth';

export default async function handler(req: VercelRequest, res: VercelResponse) {
  const code = req.query.code;
  if (typeof code !== 'string') {
    res.status(400).send('認証コードがありません。もう一度 /api/oauth/google/authorize からやり直してください。');
    return;
  }

  try {
    await exchangeCodeForTokens(code);
    res.status(200).send('Google連携が完了しました。このタブは閉じて問題ありません。');
  } catch (err) {
    console.error('Google OAuth callback error', err);
    res.status(500).send(`Google連携に失敗しました: ${(err as Error).message}`);
  }
}
