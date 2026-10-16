/** ユーザーの役割（サーバーが決める。クライアントから送らない） */
export type UserRole = 'user' | 'staff' | 'admin';

/** ログインユーザー（GET /api/me） */
export type AuthUser = {
  id: number;
  name: string;
  email: string;
  role?: UserRole;
  booths?: { booth_id: number; name: string }[];
};

/** API の成功レスポンス `{ data, meta? }` */
export type ApiResponse<T> = { data: T };
export type FreshnessMeta = { stale_after_minutes: number };
export type ApiResponseWithMeta<T> = { data: T; meta: FreshnessMeta };

export type GameStatus = 'scheduled' | 'live' | 'finished';

/** 試合（GAME-01 / 02） */
export type Game = {
  id: number;
  season: string;
  section: number | null;
  home_team: string;
  away_team: string;
  venue: { id: number; name: string };
  /** ISO 8601（+09:00 付き） */
  tipoff_at: string;
  status: GameStatus;
};

export type BoothCategory = 'food' | 'merch' | 'both';

/** 混雑レベル 1 空いている / 2 やや混雑 / 3 混雑 / 4 非常に混雑 */
export type CongestionLevel = 1 | 2 | 3 | 4;

/** 混雑の現在値。一度も更新されていないときは level / label / updated_at が null */
export type Congestion = {
  level: CongestionLevel | null;
  label: string | null;
  wait_minutes: number | null;
  updated_at: string | null;
  is_stale: boolean;
};

/** ブース一覧の 1 件（BOOTH-01） */
export type BoothListItem = {
  booth_id: number;
  game_booth_id: number;
  name: string;
  category: BoothCategory;
  location_label: string;
  is_open: boolean;
  congestion: Congestion;
  has_stock: boolean;
  is_favorite: boolean;
};

export type StockStatus = 'in_stock' | 'low' | 'sold_out';

export type Stock = {
  status: StockStatus;
  label: string;
  quantity: number | null;
  updated_at: string;
  is_stale: boolean;
};

export type MenuItemKind = 'food' | 'merch';

export type MenuItem = {
  id: number;
  name: string;
  kind: MenuItemKind;
  price: number;
  description: string | null;
  /** この試合の在庫がまだ登録されていなければ null */
  stock: Stock | null;
};

/** ブース詳細（BOOTH-02） */
export type BoothDetail = {
  booth_id: number;
  game_booth_id: number;
  name: string;
  category: BoothCategory;
  location_label: string;
  description: string | null;
  is_open: boolean;
  congestion: Congestion;
  menu_items: MenuItem[];
  is_favorite: boolean;
};

/** 混雑の履歴の 1 点（BOOTH-03） */
export type CongestionPoint = {
  level: CongestionLevel;
  label: string;
  wait_minutes: number | null;
  recorded_at: string;
};

/** お気に入りのブース（FAV-01） */
export type FavoriteBooth = {
  booth_id: number;
  name: string;
  category: BoothCategory;
  location_label: string;
};
