/** ユーザーの役割（サーバーが決める。クライアントから送らない） */
export type UserRole = 'user' | 'staff' | 'admin';

/** ログインユーザー */
export type AuthUser = {
  id: number;
  name: string;
  email: string;
  role?: UserRole;
};
