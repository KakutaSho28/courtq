import { BrowserRouter, Navigate, Outlet, Route, Routes } from 'react-router-dom';
import { useAuthStore } from './store/auth';
import { canUseStaffScreens } from './lib/role';
import { useOnlineStatus } from './hooks/useOnlineStatus';
import { LoadError, Loading } from './components/LoadState';
import { Layout } from './components/Layout';
import { OfflineBanner } from './components/OfflineBanner';
import { BoothDetailPage } from './pages/BoothDetail';
import { BoothListPage } from './pages/BoothList';
import { FavoritesPage } from './pages/Favorites';
import { GamesPage } from './pages/Games';
import { InvitePage } from './pages/Invite';
import { StaffBoothsPage } from './pages/StaffBooths';
import { StaffMenuPage } from './pages/StaffMenu';
import { StaffUpdatePage } from './pages/StaffUpdate';
import { LoginPage } from './pages/Login';
import { RegisterPage } from './pages/Register';

/** 認証ガード：未ログインなら S01 へ */
function RequireAuth() {
  const token = useAuthStore((s) => s.token);
  return token ? <Outlet /> : <Navigate to="/login" replace />;
}

/**
 * スタッフ専用の画面のガード。来場者（role=user）は、スタッフの画面を開こうとしても試合一覧に戻される。
 * 役割が分からないとき（古い保存データ）は、/me で確認できるまで待つ。画面の出し分けだけで、
 * 本当の防御はサーバー側（role ミドルウェアと Policy）が行っている。
 */
function RequireStaff() {
  const user = useAuthStore((s) => s.user);
  const online = useOnlineStatus();

  if (user?.role === undefined) {
    return online ? (
      <Loading what="権限の確認" />
    ) : (
      <LoadError what="スタッフの画面" error={{ kind: 'offline', message: 'オフラインです。' }} onRetry={() => window.location.reload()} />
    );
  }

  return canUseStaffScreens(user) ? <Outlet /> : <Navigate to="/" replace />;
}

/** 逆ガード：ログイン済みなら試合一覧へ */
function GuestOnly() {
  const token = useAuthStore((s) => s.token);
  return token ? <Navigate to="/" replace /> : <Outlet />;
}

export function App() {
  return (
    <BrowserRouter>
      <OfflineBanner />
      <Routes>
        <Route element={<GuestOnly />}>
          {/* S01 / S02 */}
          <Route path="/login" element={<LoginPage />} />
          <Route path="/register" element={<RegisterPage />} />
        </Route>

        <Route element={<RequireAuth />}>
          <Route element={<Layout />}>
            {/* S03 試合一覧 / S04 ブース一覧 / S05 ブース詳細 / S06 お気に入り */}
            <Route path="/" element={<GamesPage />} />
            <Route path="/games/:gameId" element={<BoothListPage />} />
            <Route path="/games/:gameId/booths/:boothId" element={<BoothDetailPage />} />
            <Route path="/favorites" element={<FavoritesPage />} />

            {/* S10 招待コードの入力（ログインしていれば誰でも） */}
            <Route path="/invite" element={<InvitePage />} />

            {/* S07 担当ブース / S08 混雑・在庫の更新 / S09 メニュー管理（スタッフ・管理者だけ） */}
            <Route element={<RequireStaff />}>
              <Route path="/staff" element={<StaffBoothsPage />} />
              <Route path="/staff/games/:gameId/booths/:boothId" element={<StaffUpdatePage />} />
              <Route path="/staff/booths/:boothId/menu" element={<StaffMenuPage />} />
            </Route>
          </Route>
        </Route>

        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  );
}
