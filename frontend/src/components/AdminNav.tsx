import { NavLink } from 'react-router-dom';

/** 管理画面の上部のメニュー（試合 / ブース）。試合の割り当て・スタッフの画面は、どちらかの下に属する */
export function AdminNav() {
  return (
    <nav className="admin-nav" aria-label="管理">
      <NavLink to="/admin/games" className={({ isActive }) => `admin-nav-link${isActive ? ' is-active' : ''}`}>
        試合
      </NavLink>
      <NavLink to="/admin/booths" className={({ isActive }) => `admin-nav-link${isActive ? ' is-active' : ''}`}>
        ブース
      </NavLink>
    </nav>
  );
}
