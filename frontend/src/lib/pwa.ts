import { API_CACHE_NAME } from '../constants';

/**
 * 端末に残っている API キャッシュを破棄する。
 * お気に入りなどユーザーごとのデータが入るため、ログイン/ログアウトのたびに消して
 * 別ユーザーのデータをオフライン時に見せないようにする。
 */
export async function clearApiCache(): Promise<void> {
  try {
    if ('caches' in window) await caches.delete(API_CACHE_NAME);
  } catch {
    // キャッシュが使えない環境では何もしない
  }
}
