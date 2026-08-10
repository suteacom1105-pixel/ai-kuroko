import type { VercelRequest, VercelResponse } from '@vercel/node';
import { getGoogleAuthUrl } from '../../../lib/google/auth';

// 初回のみブラウザでこのURLにアクセスしてGoogleアカウントを認証する
export default function handler(req: VercelRequest, res: VercelResponse) {
  const url = getGoogleAuthUrl();
  res.redirect(302, url);
}
