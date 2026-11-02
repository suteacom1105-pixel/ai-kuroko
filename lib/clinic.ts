// ひざスポ接骨院の予約システム(https://yoyaku.hizasupo.com、さくらサーバーのPHP版)の管理APIを呼ぶ。
// 管理画面と同じ権限で操作するため、X-Kuroko-Token ヘッダーに共有トークン(YOYAKU_API_TOKEN)を付ける。
// トークンは予約システム側の deploy/set_kuroko_token.sh で両方に保存される

const BASE_URL = process.env.YOYAKU_API_BASE_URL ?? 'https://yoyaku.hizasupo.com';

// 予約システムが返すエラーコードを、黒子がそのまま返信に使える日本語にする
const ERROR_MESSAGES: Record<string, string> = {
  SLOT_UNAVAILABLE: 'その時間は既に予約か予約不可が入っていて空いていません',
  OUTSIDE_BUSINESS_HOURS: '営業時間外・休診日、または予約不可の時間帯のため予約できません',
  MENU_NOT_FOUND: 'メニューが見つかりません',
  NOT_FOUND: '予約が見つかりません',
  UNAUTHORIZED: '予約システムの認証に失敗しました(YOYAKU_API_TOKENを確認してください)',
};

async function yoyakuFetch(
  method: string,
  path: string,
  options: { query?: Record<string, string>; body?: unknown } = {}
): Promise<Record<string, unknown>> {
  const token = process.env.YOYAKU_API_TOKEN;
  if (!token) {
    throw new Error('YOYAKU_API_TOKENが未設定です。予約システム側で deploy/set_kuroko_token.sh を実行してください。');
  }
  const url = new URL(path, BASE_URL);
  for (const [k, v] of Object.entries(options.query ?? {})) url.searchParams.set(k, v);

  const res = await fetch(url, {
    method,
    headers: {
      'X-Kuroko-Token': token,
      ...(options.body !== undefined ? { 'Content-Type': 'application/json' } : {}),
    },
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
  });
  const data = (await res.json().catch(() => ({}))) as Record<string, unknown>;
  if (!res.ok) {
    const code = typeof data.error === 'string' ? data.error : `HTTP ${res.status}`;
    throw new Error(ERROR_MESSAGES[code] ?? `予約システムのエラー: ${code}`);
  }
  return data;
}

// 担当(リソース)。予約システムの RESOURCES と同じ値
export type ClinicResource = 'treatment' | 'treatment2' | 'training';

export type ClinicMenu = { id: string; name: string; type: string };

export async function listClinicMenus(): Promise<ClinicMenu[]> {
  const data = await yoyakuFetch('GET', '/api/menus');
  return data.menus as ClinicMenu[];
}

type RawBooking = {
  id: string;
  type: string;
  start_time: string;
  end_time: string;
  status: string;
  customer_name: string;
  menu_name: string;
};

type RawBlock = { id: string; batch_id: string; resource: string; start_time: string; end_time: string; note: string | null };

export async function getClinicDay(date: string) {
  const data = await yoyakuFetch('GET', '/api/admin/day', { query: { date } });
  // 体操教室は伊藤列とトレーニング列の両方に出るので id で重複を除く
  const all = [
    ...(data.treatments as RawBooking[]),
    ...(data.treatments2 as RawBooking[]),
    ...(data.trainings as RawBooking[]),
  ];
  const bookings = [...new Map(all.map((b) => [b.id, b])).values()]
    .sort((a, b) => a.start_time.localeCompare(b.start_time))
    .map((b) => ({
      bookingId: b.id,
      start: b.start_time,
      end: b.end_time,
      patientName: b.customer_name,
      menu: b.menu_name,
      type: b.type,
      status: b.status,
    }));
  const blocks = (data.blocks as RawBlock[]).map((b) => ({
    blockId: b.id,
    batchId: b.batch_id,
    resource: b.resource,
    start: b.start_time,
    end: b.end_time,
    note: b.note,
  }));
  return { date, isHoliday: data.isHoliday as boolean, bookings, blocks };
}

export async function createClinicBooking(input: {
  patientName: string;
  menuId: string;
  date: string;
  startTime: string;
  phone?: string;
}): Promise<{ bookingId: string }> {
  const data = await yoyakuFetch('POST', '/api/bookings', {
    body: { name: input.patientName, menuId: input.menuId, date: input.date, startTime: input.startTime, phone: input.phone ?? '' },
  });
  return { bookingId: data.bookingId as string };
}

export async function cancelClinicBooking(bookingId: string): Promise<{ cancelled: true }> {
  await yoyakuFetch('DELETE', `/api/admin/bookings/${encodeURIComponent(bookingId)}`);
  return { cancelled: true };
}

export async function rescheduleClinicBooking(bookingId: string, date: string, startTime: string): Promise<{ rescheduled: true }> {
  await yoyakuFetch('PATCH', `/api/admin/bookings/${encodeURIComponent(bookingId)}`, { body: { date, startTime } });
  return { rescheduled: true };
}

export async function blockClinicTime(input: {
  date: string;
  startTime: string;
  endTime: string;
  resources: ClinicResource[];
  note?: string;
}): Promise<{ blocked: true }> {
  await yoyakuFetch('POST', '/api/admin/blocks', {
    body: { date: input.date, start_time: input.startTime, end_time: input.endTime, resources: input.resources, note: input.note },
  });
  return { blocked: true };
}

export async function unblockClinicTime(batchId: string): Promise<{ unblocked: true }> {
  await yoyakuFetch('DELETE', '/api/admin/blocks', { query: { batch_id: batchId } });
  return { unblocked: true };
}
