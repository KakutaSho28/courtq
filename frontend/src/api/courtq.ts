import { apiClient } from './client';
import type {
  AdminBooth,
  AdminGame,
  ApiResponse,
  ApiResponseWithMeta,
  AuthUser,
  BoothDetail,
  BoothInput,
  BoothStaffMember,
  BoothListItem,
  CongestionLevel,
  CongestionPoint,
  CongestionUpdateResult,
  CsvImportResult,
  FavoriteBooth,
  Game,
  GameBoothAssignment,
  GameInput,
  InvitationAccepted,
  IssuedInvitation,
  MenuItemInput,
  StaffBooth,
  StaffMenuItem,
  StockStatus,
  StockUpdateResult,
  Venue,
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

// ===== スタッフ（role が staff / admin の人だけが使える。サーバー側でも検査している） =====

/** STAFF-01 管理できるブース */
export async function fetchStaffBooths(): Promise<StaffBooth[]> {
  return (await apiClient.get<ApiResponse<StaffBooth[]>>('/staff/booths')).data.data;
}

/** STAFF-02 混雑の更新 */
export async function updateCongestion(gameId: number | string, boothId: number | string, level: CongestionLevel, waitMinutes: number | null): Promise<CongestionUpdateResult> {
  return (await apiClient.put<ApiResponse<CongestionUpdateResult>>(`/staff/games/${gameId}/booths/${boothId}/congestion`, { level, wait_minutes: waitMinutes })).data.data;
}

/** STAFF-03 在庫の更新 */
export async function updateStock(gameId: number | string, menuItemId: number, status: StockStatus, quantity: number | null): Promise<StockUpdateResult> {
  return (await apiClient.put<ApiResponse<StockUpdateResult>>(`/staff/games/${gameId}/menu-items/${menuItemId}/stock`, { status, quantity })).data.data;
}

/** STAFF-04 メニューの一覧（販売終了のものも含む） */
export async function fetchStaffMenuItems(boothId: number | string): Promise<StaffMenuItem[]> {
  return (await apiClient.get<ApiResponse<StaffMenuItem[]>>(`/staff/booths/${boothId}/menu-items`)).data.data;
}

export async function createMenuItem(boothId: number | string, input: MenuItemInput): Promise<StaffMenuItem> {
  return (await apiClient.post<ApiResponse<StaffMenuItem>>(`/staff/booths/${boothId}/menu-items`, input)).data.data;
}

export async function patchMenuItem(boothId: number | string, itemId: number, input: Partial<MenuItemInput>): Promise<StaffMenuItem> {
  return (await apiClient.put<ApiResponse<StaffMenuItem>>(`/staff/booths/${boothId}/menu-items/${itemId}`, input)).data.data;
}

export async function deleteMenuItem(boothId: number | string, itemId: number): Promise<void> {
  await apiClient.delete(`/staff/booths/${boothId}/menu-items/${itemId}`);
}

/** STAFF-05 招待コードを使う（ログインしていれば誰でも） */
export async function acceptInvitation(code: string): Promise<InvitationAccepted> {
  return (await apiClient.post<ApiResponse<InvitationAccepted>>('/staff/invitations/accept', { code })).data.data;
}

// ===== 管理者（role が admin の人だけが使える） =====

/** ADMIN-01 試合の一覧（今日以降が先） */
export async function fetchAdminGames(): Promise<AdminGame[]> {
  return (await apiClient.get<ApiResponse<AdminGame[]>>('/admin/games')).data.data;
}

export async function createGame(input: GameInput): Promise<AdminGame> {
  return (await apiClient.post<ApiResponse<AdminGame>>('/admin/games', input)).data.data;
}

export async function updateGame(gameId: number, input: GameInput): Promise<AdminGame> {
  return (await apiClient.put<ApiResponse<AdminGame>>(`/admin/games/${gameId}`, input)).data.data;
}

export async function deleteGame(gameId: number): Promise<void> {
  await apiClient.delete(`/admin/games/${gameId}`);
}

/** ADMIN-02 CSV の取り込み。dryRun なら保存せずに結果だけ返す */
export async function importGamesCsv(file: File, dryRun: boolean): Promise<CsvImportResult> {
  const body = new FormData();
  body.append('file', file);
  body.append('dry_run', dryRun ? '1' : '0');
  // apiClient の既定は application/json で、そのままだと axios が FormData を JSON に変換してしまう（ファイルが空の {} になる）。
  // multipart と指定すれば FormData のまま送られ、実際の Content-Type（境界つき）はブラウザが付ける
  return (await apiClient.post<ApiResponse<CsvImportResult>>('/admin/games/import', body, { headers: { 'Content-Type': 'multipart/form-data' } })).data.data;
}

export async function fetchVenues(): Promise<Venue[]> {
  return (await apiClient.get<ApiResponse<Venue[]>>('/admin/venues')).data.data;
}

export async function createVenue(name: string): Promise<Venue> {
  return (await apiClient.post<ApiResponse<Venue>>('/admin/venues', { name })).data.data;
}

/** ADMIN-03 ブースの管理 */
export async function fetchAdminBooths(): Promise<AdminBooth[]> {
  return (await apiClient.get<ApiResponse<AdminBooth[]>>('/admin/booths')).data.data;
}

export async function fetchAdminBooth(boothId: number | string): Promise<AdminBooth> {
  return (await apiClient.get<ApiResponse<AdminBooth>>(`/admin/booths/${encodeURIComponent(String(boothId))}`)).data.data;
}

export async function createBooth(input: BoothInput): Promise<AdminBooth> {
  return (await apiClient.post<ApiResponse<AdminBooth>>('/admin/booths', input)).data.data;
}

export async function updateBooth(boothId: number, input: BoothInput): Promise<AdminBooth> {
  return (await apiClient.put<ApiResponse<AdminBooth>>(`/admin/booths/${boothId}`, input)).data.data;
}

export async function deleteBooth(boothId: number): Promise<void> {
  await apiClient.delete(`/admin/booths/${boothId}`);
}

/** ADMIN-04 試合へのブースの割り当て */
export async function fetchGameBooths(gameId: number | string): Promise<GameBoothAssignment[]> {
  return (await apiClient.get<ApiResponse<GameBoothAssignment[]>>(`/admin/games/${encodeURIComponent(String(gameId))}/booths`)).data.data;
}

export async function syncGameBooths(gameId: number | string, booths: { booth_id: number; is_open: boolean }[]): Promise<GameBoothAssignment[]> {
  return (await apiClient.put<ApiResponse<GameBoothAssignment[]>>(`/admin/games/${encodeURIComponent(String(gameId))}/booths`, { booths })).data.data;
}

/** ADMIN-05 / 06 招待コードの発行と、担当スタッフの一覧・解除 */
export async function issueInvitation(boothId: number | string): Promise<IssuedInvitation> {
  return (await apiClient.post<ApiResponse<IssuedInvitation>>(`/admin/booths/${encodeURIComponent(String(boothId))}/invitations`)).data.data;
}

export async function fetchBoothStaff(boothId: number | string): Promise<BoothStaffMember[]> {
  return (await apiClient.get<ApiResponse<BoothStaffMember[]>>(`/admin/booths/${encodeURIComponent(String(boothId))}/staff`)).data.data;
}

export async function removeBoothStaff(boothId: number | string, userId: number): Promise<void> {
  await apiClient.delete(`/admin/booths/${encodeURIComponent(String(boothId))}/staff/${userId}`);
}
