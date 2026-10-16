import { useEffect, useRef, useState } from 'react';
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { apiClient } from '../api/client';
import { fetchMe } from '../api/courtq';
import { getEcho } from '../lib/echo';
import { canUseStaffScreens } from '../lib/role';
import { useAuthStore } from '../store/auth';
import { InstallButton } from './InstallButton';
import { Logo } from './Logo';
import { RealtimeBadge } from './RealtimeBadge';

export function Layout() {
  const navigate = useNavigate();
  const { pathname } = useLocation();
  const onFavorites = pathname.startsWith('/favorites');
  const onStaff = pathname.startsWith('/staff') || pathname.startsWith('/invite');
  const token = useAuthStore((s) => s.token);
  const clear = useAuthStore((s) => s.clear);
  const setUser = useAuthStore((s) => s.setUser);
  const user = useAuthStore((s) => s.user);
  const isStaff = canUseStaffScreens(user);

  // スタッフだった人の役割が、使っている途中でなくなったら（担当を外された）、画面から外れたことを知らせる
  const wasStaff = useRef(isStaff);
  const [roleLost, setRoleLost] = useState(false);
  useEffect(() => {
    if (wasStaff.current && !isStaff) setRoleLost(true);
    if (isStaff) setRoleLost(false);
    wasStaff.current = isStaff;
  }, [isStaff]);

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
        <Link to="/" className={`tab${onFavorites || onStaff ? '' : ' is-active'}`} aria-current={onFavorites || onStaff ? undefined : 'page'}>
          試合・ブース
        </Link>
        <Link to="/favorites" className={`tab${onFavorites ? ' is-active' : ''}`} aria-current={onFavorites ? 'page' : undefined}>
          <span aria-hidden="true">★ </span>お気に入り
        </Link>
        {/* スタッフ・管理者にはスタッフの画面、来場者には招待コードの入口を出す（来場者にスタッフの画面は見せない） */}
        <Link to={isStaff ? '/staff' : '/invite'} className={`tab${onStaff ? ' is-active' : ''}`} aria-current={onStaff ? 'page' : undefined}>
          {isStaff ? 'スタッフ' : 'スタッフ登録'}
        </Link>
      </nav>
      {roleLost && (
        <div className="notice notice-warning role-lost" role="alert">
          <span>担当ブースが変更されたため、スタッフの画面を閉じました。心当たりがない場合は、管理者に確認してください。</span>
          <button type="button" className="btn btn-ghost btn-sm" onClick={() => setRoleLost(false)}>
            閉じる
          </button>
        </div>
      )}
      <main className="container">
        <Outlet />
      </main>
    </>
  );
}
