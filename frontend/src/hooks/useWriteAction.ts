import { useCallback, useRef, useState } from 'react';
import { fetchMe } from '../api/courtq';
import { describeApiError, type ApiErrorInfo } from '../lib/apiError';
import { useAuthStore } from '../store/auth';
import { useOnlineStatus } from './useOnlineStatus';

export type WriteAction<A extends unknown[], R> = {
  /** 送信中（二重送信を防ぐため、ボタンを押せなくする） */
  pending: boolean;
  /** 直近の失敗。成功するか clearError() で消える */
  error: ApiErrorInfo | null;
  /** オフラインのとき true。送信前に分かるので、ボタンを無効にして理由を出す */
  offline: boolean;
  /** 実行する。成功したら結果、失敗・オフラインなら null（失敗は error に入る）。成功したふりはしない */
  run: (...args: A) => Promise<R | null>;
  clearError: () => void;
};

/**
 * 書き込み（更新・追加・削除）の共通処理。読み込みと違い、結果を待ってから画面に反映する前提で使う。
 * - オフラインなら送らず、「オフラインのため送信できません」を出す（成功したふりをしない）
 * - 通信に失敗したら error を出す。入力中の内容は呼び出し側が持っているので消えず、そのまま再試行できる
 * - 送信中の二重実行は無視する
 */
export function useWriteAction<A extends unknown[], R>(action: (...args: A) => Promise<R>): WriteAction<A, R> {
  const online = useOnlineStatus();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<ApiErrorInfo | null>(null);
  const actionRef = useRef(action);
  actionRef.current = action;
  const busy = useRef(false);

  const run = useCallback(async (...args: A): Promise<R | null> => {
    if (busy.current) return null;
    if (!navigator.onLine) {
      setError({ kind: 'offline', message: 'オフラインのため送信できません。電波の良い場所で、もう一度お試しください。' });
      return null;
    }
    busy.current = true;
    setPending(true);
    setError(null);
    try {
      return await actionRef.current(...args);
    } catch (err: unknown) {
      const info = describeApiError(err);
      if (info.kind === 'forbidden') {
        // 担当を外された・役割が変わった可能性。保存してある役割を最新にして、スタッフの画面から外れるようにする
        fetchMe()
          .then((user) => useAuthStore.getState().setUser(user))
          .catch(() => undefined);
        setError({ ...info, message: 'この操作の権限がありません。担当ブースが変更された可能性があります。' });
        return null;
      }
      setError(info.kind === 'failed' ? { ...info, message: `更新できませんでした。${info.message}` } : info);
      return null;
    } finally {
      busy.current = false;
      setPending(false);
    }
  }, []);

  return { pending, error, offline: !online, run, clearError: () => setError(null) };
}
