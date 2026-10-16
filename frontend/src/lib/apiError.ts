import { isAxiosError } from 'axios';

export type ApiErrorKind =
  /** 端末がオフライン（キャッシュにも無かった） */
  | 'offline'
  /** 対象が無い（404） */
  | 'not_found'
  /** 権限が無い（403）。役割が変わった・担当を外された */
  | 'forbidden'
  /** 入力が正しくない / 業務ルール違反（422） */
  | 'invalid'
  /** サーバーの不具合・応答なしなど */
  | 'failed';

export type ApiErrorInfo = { kind: ApiErrorKind; message: string; fieldErrors?: Record<string, string[]> };

/** API のエラーを、画面に出す種類とメッセージに分ける。401 は apiClient が自動でログアウトする */
export function describeApiError(error: unknown): ApiErrorInfo {
  if (isAxiosError<{ message?: string; errors?: Record<string, string[]> }>(error)) {
    if (!error.response) {
      return navigator.onLine
        ? { kind: 'failed', message: 'サーバーに接続できませんでした。電波の良い場所でもう一度お試しください。' }
        : { kind: 'offline', message: 'オフラインです。' };
    }
    const { status, data } = error.response;
    if (status === 404) return { kind: 'not_found', message: data?.message ?? '見つかりません' };
    if (status === 403) return { kind: 'forbidden', message: data?.message ?? 'この操作は許可されていません' };
    if (status === 422) return { kind: 'invalid', message: data?.message ?? '入力内容を確認してください', fieldErrors: data?.errors };
    if (status === 429) return { kind: 'failed', message: data?.message ?? 'リクエストが多すぎます。しばらくしてからもう一度お試しください。' };
    return { kind: 'failed', message: data?.message ?? '読み込みに失敗しました。' };
  }

  return { kind: 'failed', message: '読み込みに失敗しました。' };
}
