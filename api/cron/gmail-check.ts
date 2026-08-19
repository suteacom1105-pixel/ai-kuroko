import type { VercelRequest, VercelResponse } from '@vercel/node';
import { listMessagesSince } from '../../lib/gmail';
import { classifyYoutubeBusinessEmails } from '../../lib/claude/classifyEmails';
import { pushText } from '../../lib/line';
import { kv } from '../../lib/kv';

const LAST_CHECKED_KEY = 'gmail:last_checked_epoch';
const INITIAL_LOOKBACK_SECONDS = 25 * 60 * 60; // 初回実行時は直近25時間分を確認する

function isAuthorizedCronRequest(req: VercelRequest): boolean {
  const cronSecret = process.env.CRON_SECRET;
  if (!cronSecret) return true; // 未設定時は保護しない(設定を強く推奨)
  return req.headers.authorization === `Bearer ${cronSecret}`;
}

export default async function handler(req: VercelRequest, res: VercelResponse) {
  if (!isAuthorizedCronRequest(req)) {
    res.status(401).end();
    return;
  }

  const ownerUserId = process.env.GO_LINE_USER_ID;
  if (!ownerUserId) {
    res.status(500).send('GO_LINE_USER_ID is not set');
    return;
  }

  try {
    const now = Math.floor(Date.now() / 1000);
    const lastChecked = (await kv.get<number>(LAST_CHECKED_KEY)) ?? now - INITIAL_LOOKBACK_SECONDS;

    const messages = await listMessagesSince(lastChecked);
    const matchingIds = await classifyYoutubeBusinessEmails(messages);
    const matched = messages.filter((m) => matchingIds.includes(m.id));

    if (matched.length > 0) {
      const body = matched.map((m) => `差出人: ${m.from}\n件名: ${m.subject}`).join('\n\n');
      await pushText(ownerUserId, `YouTube案件と思われるメールが届いています。\n\n${body}`);
    }

    await kv.set(LAST_CHECKED_KEY, now);
    res.status(200).json({ ok: true, checked: messages.length, matched: matched.length });
  } catch (err) {
    console.error('gmail check error', err);
    res.status(500).json({ ok: false, error: (err as Error).message });
  }
}
