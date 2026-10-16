import { useRealtimeStatus } from '../hooks/useRealtime';

/** リアルタイム接続の状態表示（キー未設定の環境では何も出さない） */
export function RealtimeBadge() {
  const status = useRealtimeStatus();
  if (status === 'disabled') return null;

  const label =
    status === 'connected' ? 'リアルタイム接続中' : status === 'connecting' ? '接続中...' : '切断中（再接続を試行）';

  return (
    <span className={`realtime-badge realtime-${status}`} role="status" aria-live="polite">
      <span className="realtime-dot" aria-hidden="true" />
      {label}
    </span>
  );
}
