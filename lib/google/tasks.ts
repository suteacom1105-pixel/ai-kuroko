import { google } from 'googleapis';
import { getAuthorizedClient } from './auth';

const DEFAULT_TASKLIST = '@default';

async function tasksClient() {
  const auth = await getAuthorizedClient();
  return google.tasks({ version: 'v1', auth });
}

export type TaskItem = {
  id: string;
  title: string;
  notes: string | null;
  due: string | null; // YYYY-MM-DD
  completed: boolean;
};

function toTaskItem(task: { id?: string | null; title?: string | null; notes?: string | null; due?: string | null; status?: string | null }): TaskItem {
  return {
    id: task.id ?? '',
    title: task.title ?? '(タイトルなし)',
    notes: task.notes ?? null,
    due: task.due ? task.due.slice(0, 10) : null,
    completed: task.status === 'completed',
  };
}

export async function listTasks(includeCompleted = false): Promise<TaskItem[]> {
  const tasks = await tasksClient();
  const res = await tasks.tasks.list({
    tasklist: DEFAULT_TASKLIST,
    showCompleted: includeCompleted,
    showHidden: includeCompleted,
  });
  return (res.data.items ?? []).map(toTaskItem);
}

export async function createTask(title: string, dueDate?: string, notes?: string): Promise<TaskItem> {
  const tasks = await tasksClient();
  const res = await tasks.tasks.insert({
    tasklist: DEFAULT_TASKLIST,
    requestBody: {
      title,
      notes,
      // Google Tasksのdueは時刻を無視して日付のみ扱われるため、00:00:00Zで固定する
      due: dueDate ? `${dueDate}T00:00:00.000Z` : undefined,
    },
  });
  return toTaskItem(res.data);
}

export async function completeTask(taskId: string): Promise<TaskItem> {
  const tasks = await tasksClient();
  const res = await tasks.tasks.patch({
    tasklist: DEFAULT_TASKLIST,
    task: taskId,
    requestBody: { status: 'completed' },
  });
  return toTaskItem(res.data);
}

export async function deleteTask(taskId: string): Promise<void> {
  const tasks = await tasksClient();
  await tasks.tasks.delete({ tasklist: DEFAULT_TASKLIST, task: taskId });
}
