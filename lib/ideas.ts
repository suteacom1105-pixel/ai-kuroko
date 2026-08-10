import { kv } from './kv';

const IDEAS_KEY = 'ideas';
const MAX_IDEAS = 500;

export type Idea = {
  content: string;
  createdAt: string; // ISO8601 (JST表示はUI側で変換)
};

export async function addIdea(content: string): Promise<Idea> {
  const idea: Idea = { content, createdAt: new Date().toISOString() };
  await kv.lpush(IDEAS_KEY, idea);
  await kv.ltrim(IDEAS_KEY, 0, MAX_IDEAS - 1);
  return idea;
}

export async function listIdeas(limit = 10): Promise<Idea[]> {
  return kv.lrange<Idea>(IDEAS_KEY, 0, limit - 1);
}
