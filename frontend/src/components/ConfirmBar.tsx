type Props = {
  /** 何をするかの説明（「○○を削除します。元に戻せません。」） */
  message: string;
  confirmLabel: string;
  pending: boolean;
  disabled?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
};

/** 取り消せない操作の確認。押し間違いを防ぐため、1 回目の「削除」では実行せず、この確認を出す */
export function ConfirmBar({ message, confirmLabel, pending, disabled = false, onConfirm, onCancel }: Props) {
  return (
    <div className="notice notice-warning confirm-delete" role="alertdialog" aria-label={message}>
      <span>{message}</span>
      <span className="write-error-actions">
        <button type="button" className="btn btn-danger btn-sm" disabled={pending || disabled} onClick={onConfirm}>
          {pending ? '送信中...' : confirmLabel}
        </button>
        <button type="button" className="btn btn-ghost btn-sm" disabled={pending} onClick={onCancel}>
          やめる
        </button>
      </span>
    </div>
  );
}
