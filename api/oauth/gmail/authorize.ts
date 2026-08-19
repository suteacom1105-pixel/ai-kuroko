import type { VercelRequest, VercelResponse } from '@vercel/node';
import { getGmailAuthUrl } from '../../../lib/google/gmailAuth';

// 初回のみブラウザでこのURLにアクセスし、YouTube案件メールを受信するGoogleアカウントで認証する
export default function handler(req: VercelRequest, res: VercelResponse) {
  const url = getGmailAuthUrl();
  res.redirect(302, url);
}
