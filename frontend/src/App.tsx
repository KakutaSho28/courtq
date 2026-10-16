import { BrowserRouter, Navigate, Outlet, Route, Routes } from 'react-router-dom';
import { useAuthStore } from './store/auth';
import { Layout } from './components/Layout';
import { OfflineBanner } from './components/OfflineBanner';
import { BoothDetailPage } from './pages/BoothDetail';
import { BoothListPage } from './pages/BoothList';
import { FavoritesPage } from './pages/Favorites';
import { GamesPage } from './pages/Games';
import { LoginPage } from './pages/Login';
import { RegisterPage } from './pages/Register';

/** 認証ガード：未ログインなら S01 へ */
function RequireAuth() {
  const token = useAuthStore((s) => s.token);
  return token ? <Outlet /> : <Navigate to="/login" replace />;
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
          </Route>
        </Route>

        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  );
}
