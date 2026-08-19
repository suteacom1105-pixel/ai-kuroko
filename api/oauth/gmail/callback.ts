import type { VercelRequest, VercelResponse } from '@vercel/node';
import { exchangeGmailCodeForTokens } from '../../../lib/google/gmailAuth';

export default async function handler(req: VercelRequest, res: VercelResponse) {
  const code = req.query.code;
  if (typeof code !== 'string') {
    res.status(400).send('認証コードがありません。もう一度 /api/oauth/gmail/authorize からやり直してください。');
    return;
  }

  try {
    await exchangeGmailCodeForTokens(code);
    res.status(200).send('Gmail連携が完了しました。このタブは閉じて問題ありません。');
  } catch (err) {
    console.error('Gmail OAuth callback error', err);
    res.status(500).send(`Gmail連携に失敗しました: ${(err as Error).message}`);
  }
}
