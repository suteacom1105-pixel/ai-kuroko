import { kv } from '@vercel/kv';

export { kv };

const CONVERSATION_TTL_SECONDS = 60 * 60 * 6; // 6時間で会話文脈を破棄
const CONVERSATION_MAX_TURNS = 12;

export type ConversationTurn = {
  role: 'user' | 'assistant';
  content: string;
};

function conversationKey(userId: string): string {
  return `conv:${userId}`;
}

export async function getConversation(userId: string): Promise<ConversationTurn[]> {
  const turns = await kv.get<ConversationTurn[]>(conversationKey(userId));
  return turns ?? [];
}

export async function appendConversation(userId: string, turns: ConversationTurn[]): Promise<void> {
  const existing = await getConversation(userId);
  const merged = [...existing, ...turns].slice(-CONVERSATION_MAX_TURNS);
  await kv.set(conversationKey(userId), merged, { ex: CONVERSATION_TTL_SECONDS });
}

export async function clearConversation(userId: string): Promise<void> {
  await kv.del(conversationKey(userId));
}
