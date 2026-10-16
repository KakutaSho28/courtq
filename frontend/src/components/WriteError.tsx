import type { ApiErrorInfo } from '../lib/apiError';

type Props = {
  error: ApiErrorInfo;
  /** 同じ操作をもう一度送る。渡すと「もう一度送る」ボタンを出す */
  onRetry?: () => void;
  onDismiss?: () => void;
};

/** 書き込みに失敗したときのメッセージ。何が起きたか・何も変わっていないこと・次にすることを伝える */
export function WriteError({ error, onRetry, onDismiss }: Props) {
  return (
    <div className="notice notice-error write-error" role="alert">
      <span>
        {error.message}
        {error.kind !== 'invalid' && error.kind !== 'forbidden' && <span className="write-error-note"> 変更は反映されていません。</span>}
      </span>
      <span className="write-error-actions">
        {onRetry && error.kind !== 'forbidden' && (
          <button type="button" className="btn btn-primary btn-sm" onClick={onRetry}>
            もう一度送る
          </button>
        )}
        {onDismiss && (
          <button type="button" className="btn btn-ghost btn-sm" onClick={onDismiss}>
            閉じる
          </button>
        )}
      </span>
    </div>
  );
}

/** オフライン中に出す案内。更新ボタンが押せない理由を伝える */
export function OfflineWriteNotice() {
  return (
    <div className="notice notice-warning" role="status">
      オフラインのため、更新できません。電波の良い場所に移動すると、また更新できます。
    </div>
  );
}
