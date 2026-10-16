import { useCallback, useEffect, useRef, useState } from 'react';
import { describeApiError, type ApiErrorInfo } from '../lib/apiError';

export type Resource<T> = {
  /** 最後に取得できたデータ（再読み込み中も前のデータを表示し続ける） */
  data: T | null;
  /** 初めての読み込み中（まだデータが無い） */
  loading: boolean;
  /** データがある状態での再読み込み中 */
  refreshing: boolean;
  error: ApiErrorInfo | null;
  reload: () => void;
  /** 取得済みのデータを書き換える（リアルタイムの更新を反映するため）。まだデータが無いときは何もしない */
  update: (updater: (previous: T) => T) => void;
};

/**
 * 画面で使う API の読み込み。読み込み中 / エラー / オフラインを状態として返す。
 * - ネットワークが戻ったとき（online）と、画面に戻ってきたとき（タブ・アプリの切り替え）に自動で取り直す
 * - 古いリクエストの結果が新しい結果を上書きしないよう、最後に始めたリクエストの結果だけを使う
 *
 * @param load データを取ってくる関数。key が変わると取り直す
 */
export function useApiResource<T>(load: () => Promise<T>, key: string): Resource<T> {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<ApiErrorInfo | null>(null);
  const [pending, setPending] = useState(true);
  const loadRef = useRef(load);
  loadRef.current = load;
  const requestId = useRef(0);

  const run = useCallback(() => {
    const id = ++requestId.current;
    setPending(true);
    loadRef
      .current()
      .then((result) => {
        if (id !== requestId.current) return;
        setData(result);
        setError(null);
      })
      .catch((err: unknown) => {
        if (id !== requestId.current) return;
        setError(describeApiError(err));
      })
      .finally(() => {
        if (id === requestId.current) setPending(false);
      });
  }, []);

  // key が変わったら前の画面のデータを捨てて読み直す（別の試合のブースを一瞬表示しないように）
  useEffect(() => {
    setData(null);
    setError(null);
    run();
  }, [key, run]);

  useEffect(() => {
    const onVisible = () => {
      if (document.visibilityState === 'visible') run();
    };
    window.addEventListener('online', run);
    document.addEventListener('visibilitychange', onVisible);
    return () => {
      window.removeEventListener('online', run);
      document.removeEventListener('visibilitychange', onVisible);
    };
  }, [run]);

  const update = useCallback((updater: (previous: T) => T) => {
    setData((previous) => (previous === null ? previous : updater(previous)));
  }, []);

  return { data, loading: pending && data === null, refreshing: pending && data !== null, error, reload: run, update };
}
