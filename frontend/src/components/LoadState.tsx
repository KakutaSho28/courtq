import type { ApiErrorInfo } from '../lib/apiError';
import { OfflineFallback } from './OfflineFallback';

/** 初めての読み込み中 */
export function Loading({ what }: { what: string }) {
  return (
    <div className="empty" role="status" aria-live="polite">
      <span className="spinner" aria-hidden="true" />
      <p>{what}を読み込み中...</p>
    </div>
  );
}

/** 読み込みに失敗した（データが無い）ときの表示。オフラインなら専用の案内にする */
export function LoadError({ what, error, onRetry }: { what: string; error: ApiErrorInfo; onRetry: () => void }) {
  if (error.kind === 'offline') {
    return <OfflineFallback what={what} onRetry={onRetry} />;
  }

  return (
    <div className="empty" role="alert">
      <p>{error.kind === 'not_found' ? `${what}が見つかりません。` : `${what}を読み込めませんでした。`}</p>
      <p className="muted">{error.message}</p>
      {error.kind !== 'not_found' && (
        <button type="button" className="btn btn-primary" onClick={onRetry}>
          もう一度読み込む
        </button>
      )}
    </div>
  );
}

/** データを表示したまま再読み込みに失敗したとき（前回の内容を出しつつ知らせる） */
export function RefreshError({ error, onRetry }: { error: ApiErrorInfo; onRetry: () => void }) {
  return (
    <div className="notice notice-warning" role="alert">
      <span>
        {error.kind === 'offline' ? 'オフラインのため、前回の内容を表示しています。' : '最新の情報を取得できませんでした。前回の内容を表示しています。'}
      </span>
      <button type="button" className="btn btn-ghost btn-sm" onClick={onRetry}>
        再試行
      </button>
    </div>
  );
}
