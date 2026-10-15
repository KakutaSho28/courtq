import { useOnlineStatus } from '../hooks/useOnlineStatus';

/** オフライン中に全画面の上部へ表示する帯。何が使えないかを明示する */
export function OfflineBanner() {
  const online = useOnlineStatus();
  if (online) return null;

  return (
    <div className="offline-banner" role="alert">
      オフラインです。最後に取得した内容を表示しています。最新の混雑・在庫は、接続が戻ると更新されます。
    </div>
  );
}
