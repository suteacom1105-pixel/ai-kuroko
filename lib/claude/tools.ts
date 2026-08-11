import type Anthropic from '@anthropic-ai/sdk';
import { createEvent, deleteEvent, listEvents, updateEvent } from '../google/calendar';
import { completeTask, createTask, deleteTask, listTasks } from '../google/tasks';
import { getVideoAnalytics, listRecentVideos } from '../google/youtube';
import { addIdea, listIdeas } from '../ideas';

export const TOOLS: Anthropic.Tool[] = [
  {
    name: 'list_events',
    description:
      '指定した日付範囲(JST)の予定を一覧取得する。時間指定の予定・終日予定の両方を含む。「今日」「明日」「今週」等の相対表現は現在日時から具体的な日付に変換してから呼び出すこと。',
    input_schema: {
      type: 'object',
      properties: {
        startDate: { type: 'string', description: '取得開始日 (YYYY-MM-DD, JST)' },
        endDate: { type: 'string', description: '取得終了日 (YYYY-MM-DD, JST, この日を含む)' },
      },
      required: ['startDate', 'endDate'],
    },
  },
  {
    name: 'create_event',
    description:
      '新しい予定を作成する。時間指定の予定は startDateTime/endDateTime を、終日予定は startDate/endDate を使うこと(両方同時に指定しない)。',
    input_schema: {
      type: 'object',
      properties: {
        summary: { type: 'string', description: '予定のタイトル' },
        description: { type: 'string', description: '予定の詳細メモ(任意)' },
        startDateTime: { type: 'string', description: '開始日時 例: 2026-08-11T15:00:00 (JST, オフセット不要)' },
        endDateTime: { type: 'string', description: '終了日時 例: 2026-08-11T16:00:00 (JST, オフセット不要)' },
        startDate: { type: 'string', description: '終日予定の開始日 YYYY-MM-DD' },
        endDate: { type: 'string', description: '終日予定の終了日(排他的、通常はstartDateの翌日) YYYY-MM-DD' },
      },
      required: ['summary'],
    },
  },
  {
    name: 'update_event',
    description: '既存の予定を変更する。変更したい項目だけ指定すればよい。eventIdはlist_eventsの結果から取得すること。',
    input_schema: {
      type: 'object',
      properties: {
        eventId: { type: 'string', description: '変更対象の予定ID' },
        summary: { type: 'string' },
        description: { type: 'string' },
        startDateTime: { type: 'string', description: '例: 2026-08-11T17:00:00 (JST)' },
        endDateTime: { type: 'string', description: '例: 2026-08-11T18:00:00 (JST)' },
        startDate: { type: 'string', description: '終日予定に変更する場合の開始日 YYYY-MM-DD' },
        endDate: { type: 'string', description: '終日予定に変更する場合の終了日 YYYY-MM-DD' },
      },
      required: ['eventId'],
    },
  },
  {
    name: 'delete_event',
    description: '予定を削除する。eventIdはlist_eventsの結果から取得すること。',
    input_schema: {
      type: 'object',
      properties: {
        eventId: { type: 'string' },
      },
      required: ['eventId'],
    },
  },
  {
    name: 'list_tasks',
    description:
      'Googleタスク(チェックリスト形式のやること)の未完了一覧を取得する。特定の日時に縛られない「〇〇をやる」系の依頼はこちらを使い、時間指定・終日の「予定」にはlist_eventsを使うこと。',
    input_schema: {
      type: 'object',
      properties: {
        includeCompleted: { type: 'boolean', description: '完了済みタスクも含めるか(デフォルトfalse)' },
      },
      required: [],
    },
  },
  {
    name: 'create_task',
    description:
      'Googleタスクを1件作成する。「〇〇までに××する」「××しないと」のような、特定時刻を伴わないやることの依頼に使う。期限は任意。',
    input_schema: {
      type: 'object',
      properties: {
        title: { type: 'string', description: 'タスクの内容' },
        dueDate: { type: 'string', description: '期限日 YYYY-MM-DD(JST, 任意)' },
        notes: { type: 'string', description: '補足メモ(任意)' },
      },
      required: ['title'],
    },
  },
  {
    name: 'complete_task',
    description: 'タスクを完了にする。taskIdはlist_tasksの結果から取得すること。',
    input_schema: {
      type: 'object',
      properties: {
        taskId: { type: 'string' },
      },
      required: ['taskId'],
    },
  },
  {
    name: 'delete_task',
    description: 'タスクを削除する。taskIdはlist_tasksの結果から取得すること。',
    input_schema: {
      type: 'object',
      properties: {
        taskId: { type: 'string' },
      },
      required: ['taskId'],
    },
  },
  {
    name: 'add_idea',
    description: 'アイデアメモを1件保存する。',
    input_schema: {
      type: 'object',
      properties: {
        content: { type: 'string', description: 'メモする内容' },
      },
      required: ['content'],
    },
  },
  {
    name: 'list_ideas',
    description: '保存済みのアイデアメモを新しい順に一覧取得する。',
    input_schema: {
      type: 'object',
      properties: {
        limit: { type: 'integer', description: '取得件数(デフォルト10)' },
      },
      required: [],
    },
  },
  {
    name: 'list_recent_youtube_videos',
    description: '自分のYouTubeチャンネルに最近投稿した動画を新しい順に一覧取得する(動画IDとタイトル)。',
    input_schema: {
      type: 'object',
      properties: {
        maxResults: { type: 'integer', description: '取得件数(デフォルト5)' },
      },
      required: [],
    },
  },
  {
    name: 'analyze_youtube_video',
    description:
      '指定した自分のYouTube動画の再生数・視聴維持率・視聴時間・トラフィックソースを取得する。videoIdはlist_recent_youtube_videosの結果から取得すること。取得したデータをもとに改善アドバイスを自分で組み立てて返信すること。',
    input_schema: {
      type: 'object',
      properties: {
        videoId: { type: 'string' },
        publishedAt: { type: 'string', description: '動画の公開日時ISO8601(list_recent_youtube_videosの結果を利用)' },
      },
      required: ['videoId', 'publishedAt'],
    },
  },
];

function toISOWithJstOffset(dateOnly: string, endOfDay: boolean): string {
  return `${dateOnly}T${endOfDay ? '23:59:59' : '00:00:00'}+09:00`;
}

export async function executeTool(name: string, input: Record<string, unknown>): Promise<unknown> {
  switch (name) {
    case 'list_events': {
      const startDate = input.startDate as string;
      const endDate = input.endDate as string;
      return listEvents(toISOWithJstOffset(startDate, false), toISOWithJstOffset(endDate, true));
    }
    case 'create_event': {
      return createEvent({
        summary: input.summary as string,
        description: input.description as string | undefined,
        startDateTime: input.startDateTime as string | undefined,
        endDateTime: input.endDateTime as string | undefined,
        startDate: input.startDate as string | undefined,
        endDate: input.endDate as string | undefined,
      });
    }
    case 'update_event': {
      const { eventId, ...rest } = input as { eventId: string } & Record<string, unknown>;
      return updateEvent(eventId, rest);
    }
    case 'delete_event': {
      await deleteEvent(input.eventId as string);
      return { deleted: true };
    }
    case 'list_tasks': {
      return listTasks((input.includeCompleted as boolean | undefined) ?? false);
    }
    case 'create_task': {
      return createTask(
        input.title as string,
        input.dueDate as string | undefined,
        input.notes as string | undefined
      );
    }
    case 'complete_task': {
      return completeTask(input.taskId as string);
    }
    case 'delete_task': {
      await deleteTask(input.taskId as string);
      return { deleted: true };
    }
    case 'add_idea': {
      return addIdea(input.content as string);
    }
    case 'list_ideas': {
      return listIdeas((input.limit as number | undefined) ?? 10);
    }
    case 'list_recent_youtube_videos': {
      return listRecentVideos((input.maxResults as number | undefined) ?? 5);
    }
    case 'analyze_youtube_video': {
      return getVideoAnalytics(input.videoId as string, input.publishedAt as string);
    }
    default:
      return { error: `未知のツールです: ${name}` };
  }
}
