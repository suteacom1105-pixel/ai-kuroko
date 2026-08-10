import type { VercelRequest, VercelResponse } from '@vercel/node';
import { formatWeatherLine, getSendaiWeatherToday } from '../../lib/weather';
import { listEvents, type CalendarEvent } from '../../lib/google/calendar';
import { pushText } from '../../lib/line';
import { jstDateString } from '../../lib/date';

function formatEventLine(event: CalendarEvent): string {
  if (event.isAllDay) return `・終日 ${event.summary}`;
  const time = event.start.slice(11, 16); // "HH:mm" (JSTオフセット付きISOのため)
  return `・${time} ${event.summary}`;
}

function formatEventsSection(events: CalendarEvent[], date: string): string {
  const dayEvents = events.filter((e) => e.start.slice(0, 10) === date);
  if (dayEvents.length === 0) return '予定はありません';
  return dayEvents.map(formatEventLine).join('\n');
}

function isAuthorizedCronRequest(req: VercelRequest): boolean {
  const cronSecret = process.env.CRON_SECRET;
  if (!cronSecret) return true; // 未設定時は保護しない(設定を強く推奨)
  return req.headers.authorization === `Bearer ${cronSecret}`;
}

export default async function handler(req: VercelRequest, res: VercelResponse) {
  if (!isAuthorizedCronRequest(req)) {
    res.status(401).end();
    return;
  }

  const ownerUserId = process.env.GO_LINE_USER_ID;
  if (!ownerUserId) {
    res.status(500).send('GO_LINE_USER_ID is not set');
    return;
  }

  const today = jstDateString(0);
  const tomorrow = jstDateString(1);

  try {
    const [weather, events] = await Promise.all([
      getSendaiWeatherToday(),
      listEvents(`${today}T00:00:00+09:00`, `${tomorrow}T23:59:59+09:00`),
    ]);

    const message = [
      'おはようございます☀️',
      '【今日の天気(仙台)】',
      formatWeatherLine(weather),
      '',
      '【今日の予定】',
      formatEventsSection(events, today),
      '',
      '【明日の予定】',
      formatEventsSection(events, tomorrow),
    ].join('\n');

    await pushText(ownerUserId, message);
    res.status(200).json({ ok: true });
  } catch (err) {
    console.error('morning notification error', err);
    res.status(500).json({ ok: false, error: (err as Error).message });
  }
}
