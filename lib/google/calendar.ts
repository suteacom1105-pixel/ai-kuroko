import { google, calendar_v3 } from 'googleapis';
import { getAuthorizedClient } from './auth';

const TIMEZONE = 'Asia/Tokyo';

function calendarId(): string {
  return process.env.GOOGLE_CALENDAR_ID ?? 'primary';
}

async function calendarClient() {
  const auth = await getAuthorizedClient();
  return google.calendar({ version: 'v3', auth });
}

export type EventInput = {
  summary: string;
  description?: string;
  // 時間指定の予定は startDateTime/endDateTime(ISO8601)、終日予定は startDate/endDate(YYYY-MM-DD) を使う
  startDateTime?: string;
  endDateTime?: string;
  startDate?: string;
  endDate?: string;
};

export type CalendarEvent = {
  id: string;
  summary: string;
  start: string;
  end: string;
  isAllDay: boolean;
};

function toCalendarEvent(event: calendar_v3.Schema$Event): CalendarEvent {
  return {
    id: event.id ?? '',
    summary: event.summary ?? '(タイトルなし)',
    start: event.start?.dateTime ?? event.start?.date ?? '',
    end: event.end?.dateTime ?? event.end?.date ?? '',
    isAllDay: Boolean(event.start?.date),
  };
}

export async function listEvents(timeMinISO: string, timeMaxISO: string): Promise<CalendarEvent[]> {
  const cal = await calendarClient();
  const res = await cal.events.list({
    calendarId: calendarId(),
    timeMin: timeMinISO,
    timeMax: timeMaxISO,
    singleEvents: true,
    orderBy: 'startTime',
  });
  return (res.data.items ?? []).map(toCalendarEvent);
}

export async function createEvent(input: EventInput): Promise<CalendarEvent> {
  const cal = await calendarClient();
  const requestBody: calendar_v3.Schema$Event = {
    summary: input.summary,
    description: input.description,
    start: input.startDate ? { date: input.startDate } : { dateTime: input.startDateTime, timeZone: TIMEZONE },
    end: input.endDate ? { date: input.endDate } : { dateTime: input.endDateTime, timeZone: TIMEZONE },
  };
  const res = await cal.events.insert({ calendarId: calendarId(), requestBody });
  return toCalendarEvent(res.data);
}

export async function updateEvent(eventId: string, input: Partial<EventInput>): Promise<CalendarEvent> {
  const cal = await calendarClient();
  const requestBody: calendar_v3.Schema$Event = {};
  if (input.summary) requestBody.summary = input.summary;
  if (input.description) requestBody.description = input.description;
  if (input.startDate) requestBody.start = { date: input.startDate };
  else if (input.startDateTime) requestBody.start = { dateTime: input.startDateTime, timeZone: TIMEZONE };
  if (input.endDate) requestBody.end = { date: input.endDate };
  else if (input.endDateTime) requestBody.end = { dateTime: input.endDateTime, timeZone: TIMEZONE };

  const res = await cal.events.patch({ calendarId: calendarId(), eventId, requestBody });
  return toCalendarEvent(res.data);
}

export async function deleteEvent(eventId: string): Promise<void> {
  const cal = await calendarClient();
  await cal.events.delete({ calendarId: calendarId(), eventId });
}
