import { getDisplayName, pushText } from '../lib/line';

// スタッフの発言はそのままGO本人にプッシュ通知するだけ。予定への自動反映は行わない。
export async function handleFrontdeskMessage(staffUserId: string, text: string): Promise<string> {
  const ownerUserId = process.env.GO_LINE_USER_ID;
  if (!ownerUserId) throw new Error('GO_LINE_USER_ID is not set');

  const displayName = await getDisplayName(staffUserId);
  await pushText(ownerUserId, `【伝言】${displayName}さんより:\n${text}`);

  return '伝言を確認しました。GOに転送しました。';
}
