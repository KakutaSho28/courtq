import { useCallback, useEffect, useRef } from 'react';

/** 短い間に何度呼ばれても、最後の呼び出しから delayMs たってから 1 回だけ実行する（アンマウントで取り消す） */
export function useDebouncedCallback(callback: () => void, delayMs: number): () => void {
  const callbackRef = useRef(callback);
  callbackRef.current = callback;
  const timer = useRef<number | null>(null);

  useEffect(
    () => () => {
      if (timer.current !== null) window.clearTimeout(timer.current);
    },
    [],
  );

  return useCallback(() => {
    if (timer.current !== null) window.clearTimeout(timer.current);
    timer.current = window.setTimeout(() => {
      timer.current = null;
      callbackRef.current();
    }, delayMs);
  }, [delayMs]);
}
