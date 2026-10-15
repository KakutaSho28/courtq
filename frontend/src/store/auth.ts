import { create } from 'zustand';
import { TOKEN_KEY, USER_KEY } from '../constants';
import { clearApiCache } from '../lib/pwa';
import type { AuthUser } from '../types';

type AuthState = {
  token: string | null;
  user: AuthUser | null;
  setAuth: (token: string, user: AuthUser) => void;
  /** ユーザー情報だけを更新する（トークン・API キャッシュはそのまま） */
  setUser: (user: AuthUser) => void;
  clear: () => void;
};

export const useAuthStore = create<AuthState>((set) => ({
  token: localStorage.getItem(TOKEN_KEY),
  user: JSON.parse(localStorage.getItem(USER_KEY) ?? 'null'),

  setAuth: (token, user) => {
    localStorage.setItem(TOKEN_KEY, token);
    localStorage.setItem(USER_KEY, JSON.stringify(user));
    set({ token, user });
    // 前のユーザーの API キャッシュを残さない（オフライン時に別ユーザーのデータが見えてしまうため）
    void clearApiCache();
  },

  setUser: (user) => {
    localStorage.setItem(USER_KEY, JSON.stringify(user));
    set({ user });
  },

  clear: () => {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
    set({ token: null, user: null });
    void clearApiCache();
  },
}));
