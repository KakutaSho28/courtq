import { isAxiosError } from 'axios';

export type ApiErrorKind =
  /** 端末がオフライン（キャッシュにも無かった） */
  | 'offline'
  /** 対象が無い（404） */
  | 'not_found'
  /** サーバーの不具合・応答なしなど */
  | 'failed';

export type ApiErrorInfo = { kind: ApiErrorKind; message: string };

/** API のエラーを、画面に出す種類とメッセージに分ける。401 は apiClient が自動でログアウトする */
export function describeApiError(error: unknown): ApiErrorInfo {
  if (isAxiosError<{ message?: string }>(error)) {
    if (!error.response) {
      return navigator.onLine
        ? { kind: 'failed', message: 'サーバーに接続できませんでした。電波の良い場所でもう一度お試しください。' }
        : { kind: 'offline', message: 'オフラインです。' };
    }
    if (error.response.status === 404) {
      return { kind: 'not_found', message: error.response.data?.message ?? '見つかりません' };
    }
    if (error.response.status === 429) {
      return { kind: 'failed', message: error.response.data?.message ?? 'リクエストが多すぎます。しばらくしてからもう一度お試しください。' };
    }
    return { kind: 'failed', message: error.response.data?.message ?? '読み込みに失敗しました。' };
  }

  return { kind: 'failed', message: '読み込みに失敗しました。' };
}
