import { useEffect } from 'react';
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { apiClient } from '../api/client';
import { fetchMe } from '../api/courtq';
import { getEcho } from '../lib/echo';
import { useAuthStore } from '../store/auth';
import { InstallButton } from './InstallButton';
import { Logo } from './Logo';
import { RealtimeBadge } from './RealtimeBadge';

export function Layout() {
  const navigate = useNavigate();
  const { pathname } = useLocation();
  const onFavorites = pathname.startsWith('/favorites');
  const token = useAuthStore((s) => s.token);
  const clear = useAuthStore((s) => s.clear);
  const setUser = useAuthStore((s) => s.setUser);

  // ログイン中は常にリアルタイム接続を張る（接続状態をヘッダーに表示するため）
  useEffect(() => {
    getEcho();
  }, []);

  // サーバー上の最新のユーザー情報（役割・担当ブース）で、端末に保存した情報を更新する
  useEffect(() => {
    if (!token) return;
    fetchMe()
      .then(setUser)
      .catch(() => undefined); // 401 は apiClient が自動ログアウトする。オフライン時は保存済みの情報を使う
  }, [token, setUser]);

  const handleLogout = async () => {
    try {
      await apiClient.post('/auth/logout');
    } catch {
      // オフラインなどでサーバーに届かなくても、端末からはログアウトする
    } finally {
      clear();
      navigate('/login');
    }
  };

  return (
    <>
      <header className="app-header">
        <Link to="/" className="brand" aria-label="試合を選ぶ">
          <Logo variant="dark" height={30} />
        </Link>
        <div className="app-nav">
          <RealtimeBadge />
          <InstallButton />
          <button type="button" className="btn btn-ghost btn-sm" onClick={handleLogout}>
            ログアウト
          </button>
        </div>
      </header>
      <nav className="tab-nav" aria-label="メイン">
        <Link to="/" className={`tab${onFavorites ? '' : ' is-active'}`} aria-current={onFavorites ? undefined : 'page'}>
          試合・ブース
        </Link>
        <Link to="/favorites" className={`tab${onFavorites ? ' is-active' : ''}`} aria-current={onFavorites ? 'page' : undefined}>
          <span aria-hidden="true">★ </span>お気に入り
        </Link>
      </nav>
      <main className="container">
        <Outlet />
      </main>
    </>
  );
}
