import { apiClient } from './client';
import type {
  ApiResponse,
  ApiResponseWithMeta,
  AuthUser,
  BoothDetail,
  BoothListItem,
  CongestionPoint,
  FavoriteBooth,
  Game,
} from '../types';

/** 画面から API を呼ぶ関数。URL とレスポンスの型はここに集める（docs/api-endpoints.md と同じ） */

export async function fetchMe(): Promise<AuthUser> {
  return (await apiClient.get<ApiResponse<AuthUser>>('/me')).data.data;
}

/** GAME-01 今日の試合が先頭 */
export async function fetchGames(): Promise<Game[]> {
  return (await apiClient.get<ApiResponse<Game[]>>('/games')).data.data;
}

/** GAME-02 */
export async function fetchGame(gameId: string): Promise<Game> {
  return (await apiClient.get<ApiResponse<Game>>(`/games/${encodeURIComponent(gameId)}`)).data.data;
}

export type BoothSort = 'congestion' | 'wait' | 'name';
export type BoothFilters = {
  category?: 'food' | 'merch';
  sort: BoothSort;
  inStock?: boolean;
  favorites?: boolean;
};

/** BOOTH-01 */
export async function fetchBooths(gameId: string, filters: BoothFilters): Promise<ApiResponseWithMeta<BoothListItem[]>> {
  const params: Record<string, string> = { sort: filters.sort };
  if (filters.category) params.category = filters.category;
  if (filters.inStock) params.in_stock = '1';
  if (filters.favorites) params.favorites = '1';

  return (await apiClient.get<ApiResponseWithMeta<BoothListItem[]>>(`/games/${encodeURIComponent(gameId)}/booths`, { params })).data;
}

/** BOOTH-02 */
export async function fetchBoothDetail(gameId: string, boothId: string): Promise<ApiResponseWithMeta<BoothDetail>> {
  return (await apiClient.get<ApiResponseWithMeta<BoothDetail>>(`/games/${encodeURIComponent(gameId)}/booths/${encodeURIComponent(boothId)}`)).data;
}

/** BOOTH-03 */
export async function fetchCongestionHistory(gameId: string, boothId: string): Promise<CongestionPoint[]> {
  return (
    await apiClient.get<ApiResponse<CongestionPoint[]>>(
      `/games/${encodeURIComponent(gameId)}/booths/${encodeURIComponent(boothId)}/congestion-history`,
    )
  ).data.data;
}

/** FAV-01 */
export async function fetchFavorites(): Promise<FavoriteBooth[]> {
  return (await apiClient.get<ApiResponse<FavoriteBooth[]>>('/me/favorites')).data.data;
}

/** FAV-02 / 03（どちらも冪等） */
export async function setFavorite(boothId: number, favorite: boolean): Promise<void> {
  if (favorite) {
    await apiClient.put(`/booths/${boothId}/favorite`);
  } else {
    await apiClient.delete(`/booths/${boothId}/favorite`);
  }
}
