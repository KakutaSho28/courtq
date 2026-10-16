import { DISPLAY_TIME_ZONE } from '../constants';

const MINUTE = 60_000;

/**
 * 「たった今」「3分前」「2時間前」のような相対表示。未来の時刻（端末の時計のずれ）は「たった今」にする。
 */
export function relativeTime(iso: string, now: number = Date.now()): string {
  const diff = now - new Date(iso).getTime();
  if (diff < MINUTE) return 'たった今';
  const minutes = Math.floor(diff / MINUTE);
  if (minutes < 60) return `${minutes}分前`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}時間前`;
  return `${Math.floor(hours / 24)}日前`;
}

/** 最終更新からしきい値（分）を超えていれば true。更新されたことがなければ false（古くなる対象が無い） */
export function isStale(iso: string | null, staleAfterMinutes: number, now: number = Date.now()): boolean {
  if (iso === null) return false;
  return now - new Date(iso).getTime() > staleAfterMinutes * MINUTE;
}

const dateParts = new Intl.DateTimeFormat('ja-JP', {
  timeZone: DISPLAY_TIME_ZONE,
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
});

/** 日本時間の日付キー（YYYY-MM-DD）。「今日の試合」の判定に使う */
export function dateKey(iso: string | number): string {
  return dateParts.format(new Date(iso)).replace(/\//g, '-');
}

export function isToday(iso: string, now: number = Date.now()): boolean {
  return dateKey(iso) === dateKey(now);
}

const tipoffFormat = new Intl.DateTimeFormat('ja-JP', {
  timeZone: DISPLAY_TIME_ZONE,
  month: 'numeric',
  day: 'numeric',
  weekday: 'short',
  hour: '2-digit',
  minute: '2-digit',
});

/** 「10/16(木) 19:05」 */
export function formatTipoff(iso: string): string {
  const parts = Object.fromEntries(tipoffFormat.formatToParts(new Date(iso)).map((p) => [p.type, p.value]));
  return `${parts.month}/${parts.day}(${parts.weekday}) ${parts.hour}:${parts.minute}`;
}

const clockFormat = new Intl.DateTimeFormat('ja-JP', {
  timeZone: DISPLAY_TIME_ZONE,
  hour: '2-digit',
  minute: '2-digit',
});

/** 「19:05」 */
export function formatClock(iso: string): string {
  return clockFormat.format(new Date(iso));
}

/** 「¥1,800」 */
export function formatYen(price: number): string {
  return `¥${price.toLocaleString('ja-JP')}`;
}
