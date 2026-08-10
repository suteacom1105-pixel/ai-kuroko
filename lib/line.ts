import { messagingApi, validateSignature, type WebhookEvent } from '@line/bot-sdk';

export type { WebhookEvent };

const { MessagingApiClient } = messagingApi;

let client: InstanceType<typeof MessagingApiClient> | null = null;

function getClient(): InstanceType<typeof MessagingApiClient> {
  if (!client) {
    const channelAccessToken = process.env.LINE_CHANNEL_ACCESS_TOKEN;
    if (!channelAccessToken) throw new Error('LINE_CHANNEL_ACCESS_TOKEN is not set');
    client = new MessagingApiClient({ channelAccessToken });
  }
  return client;
}

export function verifyLineSignature(rawBody: Buffer, signature: string | undefined): boolean {
  const channelSecret = process.env.LINE_CHANNEL_SECRET;
  if (!channelSecret || !signature) return false;
  return validateSignature(rawBody, channelSecret, signature);
}

// LINEのテキストメッセージは5000文字が上限
function truncateForLine(text: string): string {
  const LIMIT = 4900;
  return text.length > LIMIT ? `${text.slice(0, LIMIT)}…(省略)` : text;
}

export async function replyText(replyToken: string, text: string): Promise<void> {
  await getClient().replyMessage({
    replyToken,
    messages: [{ type: 'text', text: truncateForLine(text) }],
  });
}

export async function pushText(userId: string, text: string): Promise<void> {
  await getClient().pushMessage({
    to: userId,
    messages: [{ type: 'text', text: truncateForLine(text) }],
  });
}

export async function getDisplayName(userId: string): Promise<string> {
  try {
    const profile = await getClient().getProfile(userId);
    return profile.displayName;
  } catch {
    return 'スタッフ';
  }
}
