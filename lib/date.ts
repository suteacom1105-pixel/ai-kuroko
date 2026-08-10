export function jstDateString(offsetDays = 0): string {
  const now = new Date();
  const shifted = new Date(now.getTime() + 9 * 60 * 60 * 1000 + offsetDays * 24 * 60 * 60 * 1000);
  return shifted.toISOString().slice(0, 10);
}

// JMAのtimeDefines等、"+09:00"付きISO文字列から日付部分(JST基準)を取り出す
export function jstDatePart(iso: string): string {
  return iso.slice(0, 10);
}
