import type { VercelRequest, VercelResponse } from '@vercel/node';
import type { WebhookEvent } from '@line/bot-sdk';
import { replyText, verifyLineSignature } from '../lib/line';
import { resolveRole } from '../lib/auth/roles';
import { handleSecretaryMessage } from '../secretary';
import { handleFrontdeskMessage } from '../frontdesk';

// LINEの署名検証には生のリクエストボディが必要なため、Vercelの自動bodyParserを無効化する
export const config = {
  api: {
    bodyParser: false,
  },
};

async function readRawBody(req: VercelRequest): Promise<Buffer> {
  const chunks: Buffer[] = [];
  for await (const chunk of req) {
    chunks.push(typeof chunk === 'string' ? Buffer.from(chunk) : chunk);
  }
  return Buffer.concat(chunks);
}

async function handleEvent(event: WebhookEvent): Promise<void> {
  if (event.type !== 'message' || event.message.type !== 'text') return;

  const userId = event.source.userId;
  const role = resolveRole(userId);

  if (role === 'unknown' || !userId) {
    // 個人利用のシステムのため、GO本人・スタッフ以外には応答しない
    console.log('unrecognized LINE userId (未登録のuserId):', userId);
    return;
  }

  const replyBody =
    role === 'owner'
      ? await handleSecretaryMessage(userId, event.message.text)
      : await handleFrontdeskMessage(userId, event.message.text);

  await replyText(event.replyToken, replyBody);
}

export default async function handler(req: VercelRequest, res: VercelResponse) {
  if (req.method !== 'POST') {
    res.status(405).end();
    return;
  }

  const rawBody = await readRawBody(req);
  const signature = req.headers['x-line-signature'] as string | undefined;

  if (!verifyLineSignature(rawBody, signature)) {
    res.status(401).send('invalid signature');
    return;
  }

  const body = JSON.parse(rawBody.toString('utf-8')) as { events: WebhookEvent[] };

  try {
    await Promise.all(body.events.map((event) => handleEvent(event)));
  } catch (err) {
    console.error('webhook handling error', err);
    // LINE側の再送ループを避けるため、内部エラーでも200を返す
  }

  res.status(200).end();
}
