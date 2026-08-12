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
    '予定の確認・追加・変更・削除、タスク管理、アイデアメモ、YouTube動画分析の依頼には用意されたツールを使って対応してください。',
    'アイデアメモはGoogleドキュメントに保存されます。add_ideaの実行結果にdocUrlが含まれる場合は、保存完了の返信にそのリンクも添えてください。',
    '新幹線・飛行機・コンサート等のチケットを予定に登録する際は、号車・座席番号・搭乗券番号などの詳細をdescriptionに記載してください。当日朝の通知にもその内容が表示されます。',
    '「予定」(list_events/create_event等)と「タスク」(list_tasks/create_task等)は別物です。' +
      '開始・終了時刻が決まっている/終日の日付が決まっている用件は予定、' +
      '「〇〇までに××する」「××しないと」のような特定時刻を伴わないやることはタスクとして扱ってください。',
    '「明日」「来週」「さっきの予定」等の相対的な表現は、現在日時と直前の会話履歴から具体的な日時に変換してください。',
    'どの予定を指しているか曖昧な場合は、list_eventsで一覧を取得したうえで確認質問をしてください。',
    '返信は簡潔で丁寧な日本語のチャットメッセージにしてください。箇条書きが読みやすい場合は活用してください。',
    'LINEのトーク画面はMarkdown記法を装飾表示できません。**太字**や見出し(#)、項目名(「予定:」「日時:」等のラベル)は使わず、プレーンテキストで書いてください。',
  ].join('\n');
}

const TICKET_IMAGE_PROMPT =
  'これはチケットのスクリーンショットです。新幹線・飛行機・コンサート等の日時、座席番号、号車、搭乗券番号など予定管理に必要な情報を読み取り、create_eventツールで予定に登録してください。' +
  '登録後の返信は、項目名のラベルや説明文を付けず、次の内容だけを改行して書いてください: 1行目に列車・便名・催し物名など、2行目に日付と時刻、3行目に座席・号車などの情報。備考は書かないでください。' +
  '情報が読み取れない場合は、その旨を伝えてください。';

export async function handleSecretaryMessage(userId: string, userText: string): Promise<string> {
  const history = await getConversation(userId);
  const messages: Anthropic.MessageParam[] = [
    ...history.map((t) => ({ role: t.role, content: t.content }) as Anthropic.MessageParam),
    { role: 'user', content: userText },
  ];

  const replyText = await runConversation(messages);

  await appendConversation(userId, [
    { role: 'user', content: userText },
    { role: 'assistant', content: replyText },
  ]);

  return replyText;
}

export async function handleSecretaryImage(
  userId: string,
  image: { base64: string; mediaType: 'image/jpeg' | 'image/png' | 'image/gif' | 'image/webp' }
): Promise<string> {
  const history = await getConversation(userId);
  const messages: Anthropic.MessageParam[] = [
    ...history.map((t) => ({ role: t.role, content: t.content }) as Anthropic.MessageParam),
    {
      role: 'user',
      content: [
        { type: 'image', source: { type: 'base64', media_type: image.mediaType, data: image.base64 } },
        { type: 'text', text: TICKET_IMAGE_PROMPT },
      ],
    },
  ];

  const replyText = await runConversation(messages);

  await appendConversation(userId, [
    { role: 'user', content: '[チケット画像を送信]' },
    { role: 'assistant', content: replyText },
  ]);

  return replyText;
}

async function runConversation(messages: Anthropic.MessageParam[]): Promise<string> {
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

  return finalText || 'すみません、うまく処理できませんでした。もう一度お願いします。';
}
