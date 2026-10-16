import type { BoothSort } from '../api/courtq';
import type { BoothListItem } from '../types';

const NONE = Number.MAX_SAFE_INTEGER;

/**
 * ブース一覧の並び順。サーバー（BoothService）と同じ規則: 営業中が先・閉店中は最後、混雑・待ち時間が未入力のものは後ろ、
 * 同じなら名前順。リアルタイムで混雑が変わったとき、取り直さずに並べ替えるために使う。
 */
export function sortBooths(items: BoothListItem[], sort: BoothSort): BoothListItem[] {
  const key = (b: BoothListItem): (boolean | number | string)[] => {
    const level = b.congestion.level ?? NONE;
    const wait = b.congestion.wait_minutes ?? NONE;
    if (sort === 'congestion') return [!b.is_open, level, wait, b.name];
    if (sort === 'wait') return [!b.is_open, wait, level, b.name];
    return [!b.is_open, b.name];
  };

  return [...items].sort((a, b) => {
    const ka = key(a);
    const kb = key(b);
    for (let i = 0; i < ka.length; i++) {
      if (ka[i] < kb[i]) return -1;
      if (ka[i] > kb[i]) return 1;
    }
    return 0;
  });
}

/** 受け取った更新が、いま持っている値より新しいか（イベントの順序が前後しても、古い値で上書きしない） */
export function isNewer(incomingUpdatedAt: string, currentUpdatedAt: string | null): boolean {
  return currentUpdatedAt === null || new Date(incomingUpdatedAt).getTime() >= new Date(currentUpdatedAt).getTime();
}
