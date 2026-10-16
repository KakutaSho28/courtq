import { useEffect, useRef } from 'react';
import type { CongestionUpdatedEvent, StockUpdatedEvent } from '../types';
import { useChannelEvent, useOnReconnect, useRealtimeStatus } from './useRealtime';

/** リアルタイムがつながっていないとき（キー未設定・切断中）に、取り直す間隔 */
export const FALLBACK_POLL_MS = 30_000;

type Handlers = {
  onCongestion?: (event: CongestionUpdatedEvent) => void;
  onStock?: (event: StockUpdatedEvent) => void;
  /** 画面のデータを取り直す。再接続したとき（切断中に取りこぼしたイベントを補う）と、つながっていない間の定期的な取り直しで呼ぶ */
  onResync: () => void;
};

/**
 * 試合の公開チャンネル `game.{gameId}` を購読して、混雑・在庫の更新を受け取る。
 * - 画面を離れたら購読をやめる（別の試合に移ったら、前の試合のチャンネルから抜ける）
 * - 一度つながったあとで切れて、再びつながったら onResync（取りこぼしを補う）
 * - リアルタイムが使えない間（Pusher のキー未設定・切断中・接続中）は、FALLBACK_POLL_MS ごとに onResync（画面が見えていてオンラインのときだけ）。
 *   リアルタイムが無くても、画面は古いまま止まらない
 */
export function useGameRealtime(gameId: string | undefined, handlers: Handlers): void {
  const channel = gameId ? `game.${gameId}` : null;
  const gameNumber = Number(gameId);
  const status = useRealtimeStatus();
  const resyncRef = useRef(handlers.onResync);
  resyncRef.current = handlers.onResync;

  useChannelEvent<CongestionUpdatedEvent>(channel, 'booth.congestion.updated', (event) => {
    if (event.game_id === gameNumber) handlers.onCongestion?.(event);
  });
  useChannelEvent<StockUpdatedEvent>(channel, 'item.stock.updated', (event) => {
    if (event.game_id === gameNumber) handlers.onStock?.(event);
  });
  useOnReconnect(() => resyncRef.current());

  useEffect(() => {
    if (status === 'connected') return;
    const id = window.setInterval(() => {
      if (document.visibilityState === 'visible' && navigator.onLine) resyncRef.current();
    }, FALLBACK_POLL_MS);
    return () => window.clearInterval(id);
  }, [status]);
}
