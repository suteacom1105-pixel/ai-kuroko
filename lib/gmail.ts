import { google, gmail_v1 } from 'googleapis';
import { getAuthorizedGmailClient } from './google/gmailAuth';

async function gmailClient() {
  const auth = await getAuthorizedGmailClient();
  return google.gmail({ version: 'v1', auth });
}

export type GmailMessageSummary = {
  id: string;
  from: string;
  subject: string;
  snippet: string;
  receivedAt: string; // ISO8601
};

function getHeader(headers: gmail_v1.Schema$MessagePartHeader[] | undefined, name: string): string {
  return headers?.find((h) => h.name?.toLowerCase() === name.toLowerCase())?.value ?? '';
}

export async function listMessagesSince(afterEpochSeconds: number): Promise<GmailMessageSummary[]> {
  const gmail = await gmailClient();
  const listRes = await gmail.users.messages.list({
    userId: 'me',
    q: `after:${afterEpochSeconds}`,
    maxResults: 50,
  });

  const refs = listRes.data.messages ?? [];

  return Promise.all(
    refs.map(async (ref) => {
      const res = await gmail.users.messages.get({
        userId: 'me',
        id: ref.id as string,
        format: 'metadata',
        metadataHeaders: ['From', 'Subject'],
      });
      const headers = res.data.payload?.headers;
      return {
        id: ref.id as string,
        from: getHeader(headers, 'From'),
        subject: getHeader(headers, 'Subject'),
        snippet: res.data.snippet ?? '',
        receivedAt: new Date(Number(res.data.internalDate ?? '0')).toISOString(),
      };
    })
  );
}
