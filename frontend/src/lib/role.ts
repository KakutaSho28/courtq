import type { AuthUser } from '../types';

/** スタッフの画面を使えるか（サーバー側でも検査している。ここは画面の出し分け用） */
export function canUseStaffScreens(user: AuthUser | null): boolean {
  return user?.role === 'staff' || user?.role === 'admin';
}

/** 管理画面を使えるか（サーバー側でも検査している。ここは画面の出し分け用） */
export function canUseAdminScreens(user: AuthUser | null): boolean {
  return user?.role === 'admin';
}
