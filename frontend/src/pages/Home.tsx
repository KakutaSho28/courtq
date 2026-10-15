import { useEffect } from 'react';
import { apiClient } from '../api/client';
import { APP_NAME } from '../constants';
import { useAuthStore } from '../store/auth';
import type { AuthUser } from '../types';

/** ログイン後のホーム。ゲーム一覧（S03）は WP-B5 で実装する */
export function HomePage() {
  const user = useAuthStore((s) => s.user);
  const token = useAuthStore((s) => s.token);
  const setUser = useAuthStore((s) => s.setUser);

  // サーバー上の最新のユーザー情報（役割など）で、端末に保存した情報を更新する
  useEffect(() => {
    if (!token) return;
    apiClient
      .get<{ data: AuthUser }>('/me')
      .then((res) => setUser(res.data.data))
      .catch(() => undefined); // 401 は apiClient が自動ログアウトする。オフライン時は保存済みの情報を使う
  }, [token, setUser]);

  return (
    <>
      <h1 className="page-title">{APP_NAME}</h1>
      <div className="empty">
        <p>{user ? `${user.name}さん、ようこそ。` : 'ようこそ。'}</p>
        <p className="muted">試合ごとのブースの混雑と在庫が、ここで見られるようになります（準備中）。</p>
      </div>
    </>
  );
}
