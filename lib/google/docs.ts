import { google } from 'googleapis';
import { getAuthorizedClient } from './auth';
import { kv } from '../kv';

const IDEA_DOC_ID_KEY = 'google:idea_doc_id';
const IDEA_DOC_TITLE = 'AI秘書 黒子 - アイデアメモ';

async function docsClient() {
  const auth = await getAuthorizedClient();
  return google.docs({ version: 'v1', auth });
}

function ideaDocUrl(documentId: string): string {
  return `https://docs.google.com/document/d/${documentId}/edit`;
}

function jstTimestampLabel(): string {
  const shifted = new Date(Date.now() + 9 * 60 * 60 * 1000);
  return shifted.toISOString().slice(0, 16).replace('T', ' ');
}

async function getOrCreateIdeaDocId(): Promise<string> {
  const existing = await kv.get<string>(IDEA_DOC_ID_KEY);
  if (existing) return existing;

  const docs = await docsClient();
  const res = await docs.documents.create({ requestBody: { title: IDEA_DOC_TITLE } });
  const documentId = res.data.documentId;
  if (!documentId) {
    throw new Error('アイデアメモ用のGoogleドキュメント作成に失敗しました');
  }
  await kv.set(IDEA_DOC_ID_KEY, documentId);
  return documentId;
}

export async function addIdea(content: string): Promise<{ line: string; docUrl: string }> {
  const documentId = await getOrCreateIdeaDocId();
  const docs = await docsClient();
  const line = `[${jstTimestampLabel()}] ${content}`;

  await docs.documents.batchUpdate({
    documentId,
    requestBody: {
      requests: [{ insertText: { endOfSegmentLocation: {}, text: `${line}\n` } }],
    },
  });

  return { line, docUrl: ideaDocUrl(documentId) };
}

export async function listIdeas(limit = 10): Promise<string[]> {
  const documentId = await getOrCreateIdeaDocId();
  const docs = await docsClient();
  const res = await docs.documents.get({ documentId });

  const lines: string[] = [];
  for (const element of res.data.body?.content ?? []) {
    const text = (element.paragraph?.elements ?? [])
      .map((e) => e.textRun?.content ?? '')
      .join('')
      .replace(/\n$/, '');
    if (text.trim()) lines.push(text);
  }

  return lines.slice(-limit).reverse();
}
