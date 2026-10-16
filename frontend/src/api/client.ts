import axios from 'axios';
import { getSocketId } from '../lib/realtime';
import { useAuthStore } from '../store/auth';

export const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL ?? 'http://localhost/api',
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
});

// リクエスト時にトークンを自動付与
apiClient.interceptors.request.use((config) => {
  const token = useAuthStore.getState().token;
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  // 自分の操作を自分の WebSocket に送り返さない（サーバーの toOthers() が参照する）
  const socketId = getSocketId();
  if (socketId) {
    config.headers['X-Socket-ID'] = socketId;
  }
  return config;
});

// 401が返ったら自動ログアウト
apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      useAuthStore.getState().clear();
    }
    return Promise.reject(error);
  },
);
