import Anthropic from '@anthropic-ai/sdk';
import type { GmailMessageSummary } from '../gmail';

const anthropic = new Anthropic({ apiKey: process.env.ANTHROPIC_API_KEY });
const MODEL = process.env.CLAUDE_MODEL ?? 'claude-sonnet-5';

const CLASSIFY_TOOL: Anthropic.Tool = {
  name: 'report_matches',
  description: 'YouTubeチャンネルへの案件(広告出演・PR・コラボ・タイアップ・商品提供等)の問い合わせに該当するメールのIDを報告する。',
  input_schema: {
    type: 'object',
    properties: {
      matchingIds: {
        type: 'array',
        items: { type: 'string' },
        description: '該当するメッセージIDの一覧。該当なしの場合は空配列。',
      },
    },
    required: ['matchingIds'],
  },
};

export async function classifyYoutubeBusinessEmails(messages: GmailMessageSummary[]): Promise<string[]> {
  if (messages.length === 0) return [];

  const listText = messages
    .map((m) => `id=${m.id}\n差出人: ${m.from}\n件名: ${m.subject}\n本文冒頭: ${m.snippet}`)
    .join('\n\n');

  const response = await anthropic.messages.create({
    model: MODEL,
    max_tokens: 1024,
    tools: [CLASSIFY_TOOL],
    tool_choice: { type: 'tool', name: 'report_matches' },
    messages: [
      {
        role: 'user',
        content:
          'これはGmailに届いた新着メールの一覧です。YouTubeチャンネル運営に関する案件' +
          '(企業・PR会社等からの広告出演・コラボ・タイアップ・商品提供・スポンサー依頼など)に該当するメールだけを' +
          'report_matchesツールで報告してください。通知メールやニュースレター、無関係な問い合わせは含めないでください。\n\n' +
          listText,
      },
    ],
  });

  const toolUse = response.content.find(
    (block): block is Anthropic.ToolUseBlock => block.type === 'tool_use'
  );
  const input = (toolUse?.input ?? {}) as { matchingIds?: string[] };
  return input.matchingIds ?? [];
}
