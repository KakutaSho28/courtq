import { useEffect, useRef, useSyncExternalStore } from 'react';
import { listenChannel } from '../lib/echo';
import { getRealtimeStatus, subscribeRealtimeStatus, type RealtimeStatus } from '../lib/realtime';

export function useRealtimeStatus(): RealtimeStatus {
  return useSyncExternalStore(subscribeRealtimeStatus, getRealtimeStatus, getRealtimeStatus);
}

/** 公開チャンネルのイベントを購読する（アンマウントで解除） */
export function useChannelEvent<T>(channel: string | null, event: string, handler: (payload: T) => void): void {
  const handlerRef = useRef(handler);
  handlerRef.current = handler;

  useEffect(() => {
    if (!channel) return;
    return listenChannel<T>(channel, event, (payload) => handlerRef.current(payload));
  }, [channel, event]);
}

/**
 * 一度つながったあとで接続が途切れ、再びつながったときに呼ぶ。切断中に取りこぼしたイベントを補うため、
 * 一覧の再取得に使う。初回の接続では呼ばない。
 * pusher-js は切断すると connected → connecting → connected と遷移する（disconnected を経由しない）ので、
 * 「connected 以外になった」ことを切断として扱う。
 */
export function useOnReconnect(callback: () => void): void {
  const status = useRealtimeStatus();
  const callbackRef = useRef(callback);
  callbackRef.current = callback;
  const connectedOnce = useRef(false);
  const wasDown = useRef(false);

  useEffect(() => {
    if (status === 'connected') {
      if (wasDown.current) {
        wasDown.current = false;
        callbackRef.current();
      }
      connectedOnce.current = true;
    } else if (connectedOnce.current) {
      wasDown.current = true;
    }
  }, [status]);
}
