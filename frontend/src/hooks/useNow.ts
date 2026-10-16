import { useEffect, useState } from 'react';

/**
 * 現在時刻（ミリ秒）を一定間隔で更新して返す。「最終更新 ○分前」と「情報が古い可能性」の表示を、
 * 再読み込みしなくても時間の経過に合わせて変えるため。
 */
export function useNow(intervalMs = 30_000): number {
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    const id = window.setInterval(() => setNow(Date.now()), intervalMs);
    return () => window.clearInterval(id);
  }, [intervalMs]);

  return now;
}
