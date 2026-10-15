import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { useAuthStore } from '../store/auth';
import { fromPusherState, setRealtimeState } from './realtime';

declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
}

type PusherConnection = {
  state: string;
  socket_id?: string;
  bind: (event: string, callback: (states: { current: string }) => void) => void;
};

let echo: Echo | null = null;
/** チャンネルごとの購読数。複数コンポーネントが同じチャンネルを使うので、0 になったときだけ leave する */
const refCounts = new Map<string, number>();

function isConfigured(): boolean {
  return Boolean(import.meta.env.VITE_PUSHER_APP_KEY);
}

/**
 * Echo のシングルトン。VITE_PUSHER_APP_KEY が未設定なら null（リアルタイム機能なしで動作する）。
 * 購読するのは公開チャンネル `game.{gameId}` だけ（内容が機微でないため、チャンネル認証は行わない）。
 */
export function getEcho(): Echo | null {
  if (!isConfigured() || !useAuthStore.getState().token) return null;
  if (echo) return echo;

  window.Pusher = Pusher;
  setRealtimeState('connecting');

  echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_PUSHER_APP_KEY,
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
    forceTLS: true,
  });

  const connection = (echo.connector as unknown as { pusher: { connection: PusherConnection } }).pusher
    .connection;
  const sync = (state: string) => setRealtimeState(fromPusherState(state), connection.socket_id ?? null);
  connection.bind('state_change', ({ current }) => sync(current));
  sync(connection.state);

  return echo;
}

export function disconnectEcho(): void {
  echo?.disconnect();
  echo = null;
  refCounts.clear();
  setRealtimeState(isConfigured() ? 'disconnected' : 'disabled');
}

/**
 * 公開チャンネルのイベントを購読する。戻り値の関数で購読解除（最後の購読者がいなくなればチャンネルを離脱）。
 * イベント名はサーバーの broadcastAs() の値（例: "booth.congestion.updated"）。
 */
export function listenChannel<T>(channel: string, event: string, handler: (payload: T) => void): () => void {
  const instance = getEcho();
  if (!instance) return () => undefined;

  const subscription = instance.channel(channel);
  // 先頭の "." は App\Events 名前空間を付けずに broadcastAs の名前をそのまま使う指定
  subscription.listen(`.${event}`, handler);
  refCounts.set(channel, (refCounts.get(channel) ?? 0) + 1);

  return () => {
    subscription.stopListening(`.${event}`, handler);
    const remaining = (refCounts.get(channel) ?? 1) - 1;
    if (remaining <= 0) {
      refCounts.delete(channel);
      echo?.leave(channel);
    } else {
      refCounts.set(channel, remaining);
    }
  };
}

// ログアウト（トークン破棄）されたら接続を閉じる
useAuthStore.subscribe((state) => {
  if (!state.token && echo) disconnectEcho();
});
