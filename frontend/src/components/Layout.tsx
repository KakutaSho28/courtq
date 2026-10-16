import { useEffect } from 'react';
import { Link, Outlet, useNavigate } from 'react-router-dom';
import { apiClient } from '../api/client';
import { getEcho } from '../lib/echo';
import { useAuthStore } from '../store/auth';
import { InstallButton } from './InstallButton';
import { Logo } from './Logo';
import { RealtimeBadge } from './RealtimeBadge';

export function Layout() {
  const navigate = useNavigate();
  const clear = useAuthStore((s) => s.clear);

  // ログイン中は常にリアルタイム接続を張る（接続状態をヘッダーに表示するため）
  useEffect(() => {
    getEcho();
  }, []);

  const handleLogout = async () => {
    try {
      await apiClient.post('/auth/logout');
    } finally {
      clear();
      navigate('/login');
    }
  };

  return (
    <>
      <header className="app-header">
        <Link to="/" className="brand" aria-label="ホーム">
          <Logo variant="dark" height={34} />
        </Link>
        <nav className="app-nav">
          <RealtimeBadge />
          <InstallButton />
          <button className="btn btn-ghost btn-sm" onClick={handleLogout}>
            ログアウト
          </button>
        </nav>
      </header>
      <main className="container">
        <Outlet />
      </main>
    </>
  );
}
