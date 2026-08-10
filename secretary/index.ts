import Anthropic from '@anthropic-ai/sdk';
import { appendConversation, getConversation } from '../lib/kv';
import { executeTool, TOOLS } from '../lib/claude/tools';

const anthropic = new Anthropic({ apiKey: process.env.ANTHROPIC_API_KEY });
const MODEL = process.env.CLAUDE_MODEL ?? 'claude-sonnet-5';
const MAX_TOOL_TURNS = 6;

function jstNowLabel(): string {
  const shifted = new Date(Date.now() + 9 * 60 * 60 * 1000);
  return shifted.toISOString().slice(0, 16).replace('T', ' ');
}

function systemPrompt(): string {
  return [
    'あなたはGO(接骨院経営、フリースクール「revision」運営、YouTube運用)専属のAI秘書です。',
    `現在の日時(JST)は ${jstNowLabel()} です。`,
    '予定の確認・追加・変更・削除、アイデアメモ、YouTube動画分析の依頼には用意されたツールを使って対応してください。',
    '「明日」「来週」「さっきの予定」等の相対的な表現は、現在日時と直前の会話履歴から具体的な日時に変換してください。',
    'どの予定を指しているか曖昧な場合は、list_eventsで一覧を取得したうえで確認質問をしてください。',
    '返信は簡潔で丁寧な日本語のチャットメッセージにしてください。箇条書きが読みやすい場合は活用してください。',
  ].join('\n');
}

export async function handleSecretaryMessage(userId: string, userText: string): Promise<string> {
  const history = await getConversation(userId);
  const messages: Anthropic.MessageParam[] = [
    ...history.map((t) => ({ role: t.role, content: t.content }) as Anthropic.MessageParam),
    { role: 'user', content: userText },
  ];

  let finalText = '';

  for (let turn = 0; turn < MAX_TOOL_TURNS; turn++) {
    const response = await anthropic.messages.create({
      model: MODEL,
      max_tokens: 1024,
      system: systemPrompt(),
      tools: TOOLS,
      messages,
    });

    finalText = response.content
      .filter((block): block is Anthropic.TextBlock => block.type === 'text')
      .map((block) => block.text)
      .join('\n');

    if (response.stop_reason !== 'tool_use') {
      break;
    }

    messages.push({ role: 'assistant', content: response.content });

    const toolUseBlocks = response.content.filter(
      (block): block is Anthropic.ToolUseBlock => block.type === 'tool_use'
    );

    const toolResults: Anthropic.ToolResultBlockParam[] = await Promise.all(
      toolUseBlocks.map(async (block) => {
        try {
          const result = await executeTool(block.name, (block.input ?? {}) as Record<string, unknown>);
          return {
            type: 'tool_result' as const,
            tool_use_id: block.id,
            content: JSON.stringify(result),
          };
        } catch (err) {
          return {
            type: 'tool_result' as const,
            tool_use_id: block.id,
            is_error: true,
            content: `エラー: ${(err as Error).message}`,
          };
        }
      })
    );

    messages.push({ role: 'user', content: toolResults });
  }

  const replyText = finalText || 'すみません、うまく処理できませんでした。もう一度お願いします。';

  await appendConversation(userId, [
    { role: 'user', content: userText },
    { role: 'assistant', content: replyText },
  ]);

  return replyText;
}
