type Props = {
  /** 画面名（例: 「ゲーム一覧」） */
  what: string;
  onRetry?: () => void;
};

/** オフラインでデータを取得できなかった画面に出す案内（キャッシュに無い画面を開いた場合など） */
export function OfflineFallback({ what, onRetry }: Props) {
  return (
    <div className="empty" role="status">
      <p>📡 オフラインのため{what}を表示できません。</p>
      <p className="muted">以前に開いた画面は、接続できなくても一定時間は表示されます。ネットワークに接続してからもう一度お試しください。</p>
      {onRetry && (
        <button className="btn btn-primary" onClick={onRetry}>
          再読み込み
        </button>
      )}
    </div>
  );
}
