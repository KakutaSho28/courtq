import { useCallback, useState } from 'react';
import { setFavorite } from '../api/courtq';

/**
 * お気に入りの切り替え（楽観的更新）。押した瞬間に表示を変え、失敗したら元に戻してメッセージを出す。
 * 会場の弱い回線でもボタンの反応が遅れないようにするため。
 */
export function useFavoriteToggle(onChanged?: (boothId: number, favorite: boolean) => void) {
  /** サーバーの値を上書きしている分（boothId → お気に入りか） */
  const [overrides, setOverrides] = useState<Record<number, boolean>>({});
  const [busy, setBusy] = useState<Record<number, boolean>>({});
  const [error, setError] = useState<string | null>(null);

  const isFavorite = useCallback(
    (boothId: number, serverValue: boolean) => overrides[boothId] ?? serverValue,
    [overrides],
  );

  const toggle = useCallback(
    async (boothId: number, current: boolean) => {
      const next = !current;
      setError(null);
      setOverrides((o) => ({ ...o, [boothId]: next }));
      setBusy((b) => ({ ...b, [boothId]: true }));
      try {
        await setFavorite(boothId, next);
        onChanged?.(boothId, next);
      } catch {
        setOverrides((o) => ({ ...o, [boothId]: current }));
        setError('お気に入りを変更できませんでした。電波の良い場所でもう一度お試しください。');
      } finally {
        setBusy((b) => ({ ...b, [boothId]: false }));
      }
    },
    [onChanged],
  );

  return { isFavorite, toggle, busy, error, clearError: () => setError(null) };
}
