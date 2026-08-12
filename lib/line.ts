import { messagingApi, validateSignature, type WebhookEvent } from '@line/bot-sdk';
import { buffer } from 'node:stream/consumers';

export type { WebhookEvent };

const { MessagingApiClient, MessagingApiBlobClient } = messagingApi;

let client: InstanceType<typeof MessagingApiClient> | null = null;
let blobClient: InstanceType<typeof MessagingApiBlobClient> | null = null;

function getClient(): InstanceType<typeof MessagingApiClient> {
  if (!client) {
    const channelAccessToken = process.env.LINE_CHANNEL_ACCESS_TOKEN;
    if (!channelAccessToken) throw new Error('LINE_CHANNEL_ACCESS_TOKEN is not set');
    client = new MessagingApiClient({ channelAccessToken });
  }
  return client;
}

function getBlobClient(): InstanceType<typeof MessagingApiBlobClient> {
  if (!blobClient) {
    const channelAccessToken = process.env.LINE_CHANNEL_ACCESS_TOKEN;
    if (!channelAccessToken) throw new Error('LINE_CHANNEL_ACCESS_TOKEN is not set');
    blobClient = new MessagingApiBlobClient({ channelAccessToken });
  }
  return blobClient;
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

export type ImageMediaType = 'image/jpeg' | 'image/png' | 'image/gif' | 'image/webp';

function normalizeImageMediaType(contentType: string | null): ImageMediaType {
  if (contentType?.includes('png')) return 'image/png';
  if (contentType?.includes('gif')) return 'image/gif';
  if (contentType?.includes('webp')) return 'image/webp';
  return 'image/jpeg';
}

export async function getImageMessageContent(
  messageId: string
): Promise<{ base64: string; mediaType: ImageMediaType }> {
  const { body, httpResponse } = await getBlobClient().getMessageContentWithHttpInfo(messageId);
  const data = await buffer(body);
  return {
    base64: data.toString('base64'),
    mediaType: normalizeImageMediaType(httpResponse.headers.get('content-type')),
  };
}
