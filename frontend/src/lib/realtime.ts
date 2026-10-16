/**
 * リアルタイム接続の状態（Pusher）を保持する小さなストア。
 * api/client.ts（X-Socket-ID の付与）と echo.ts の両方から使うため、他モジュールに依存させない。
 */
export type RealtimeStatus =
  /** Pusher のキー未設定。リアルタイム機能は使わない */
  | 'disabled'
  | 'connecting'
  | 'connected'
  /** 切断・接続不可。pusher-js が自動で再接続を試みる */
  | 'disconnected';

let status: RealtimeStatus = 'disabled';
let socketId: string | null = null;
const listeners = new Set<() => void>();

export function getRealtimeStatus(): RealtimeStatus {
  return status;
}

export function getSocketId(): string | null {
  return socketId;
}

export function setRealtimeState(next: RealtimeStatus, nextSocketId: string | null = socketId): void {
  if (next === status && nextSocketId === socketId) return;
  status = next;
  socketId = next === 'connected' ? nextSocketId : null;
  listeners.forEach((listener) => listener());
}

export function subscribeRealtimeStatus(listener: () => void): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

/** pusher-js の接続状態名 → 画面用の状態 */
export function fromPusherState(state: string): RealtimeStatus {
  switch (state) {
    case 'connected':
      return 'connected';
    case 'initialized':
    case 'connecting':
      return 'connecting';
    default:
      return 'disconnected';
  }
}
