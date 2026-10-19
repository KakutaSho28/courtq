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

/** スタッフ画面: 管理できるブースと、今日以降の出店予定（STAFF-01） */
export type StaffBoothGame = {
  game_id: number;
  game_booth_id: number;
  home_team: string;
  away_team: string;
  tipoff_at: string;
  status: GameStatus;
  is_open: boolean;
  congestion: Congestion;
};

export type StaffBooth = {
  booth_id: number;
  name: string;
  category: BoothCategory;
  location_label: string;
  upcoming_games: StaffBoothGame[];
};

/** メニュー（STAFF-04。販売終了のものも含む） */
export type StaffMenuItem = {
  id: number;
  booth_id: number;
  name: string;
  kind: MenuItemKind;
  price: number;
  description: string | null;
  is_active: boolean;
};

export type MenuItemInput = {
  name: string;
  kind: MenuItemKind;
  price: number;
  description: string | null;
  is_active: boolean;
};

/** 混雑の更新結果（STAFF-02） */
export type CongestionUpdateResult = { game_id: number; booth_id: number; game_booth_id: number; is_open: boolean; congestion: Congestion };

/** 在庫の更新結果（STAFF-03） */
export type StockUpdateResult = { game_id: number; menu_item_id: number; stock: Stock };

/** 招待コードを使った結果（STAFF-05） */
export type InvitationAccepted = { user: AuthUser; booth: { booth_id: number; name: string } };

/** リアルタイム: 混雑の更新（チャンネル game.{gameId}、イベント booth.congestion.updated） */
export type CongestionUpdatedEvent = {
  game_id: number;
  booth_id: number;
  game_booth_id: number;
  level: CongestionLevel;
  label: string;
  wait_minutes: number | null;
  updated_at: string;
};

/** リアルタイム: 在庫の更新（イベント item.stock.updated） */
export type StockUpdatedEvent = {
  game_id: number;
  booth_id: number;
  menu_item_id: number;
  status: StockStatus;
  label: string;
  quantity: number | null;
  updated_at: string;
};

// ===== 管理者（role が admin の人だけが使える。サーバー側でも検査している） =====

/** 管理画面の試合（Game に、割り当て済みのブースの数を足したもの） */
export type AdminGame = Game & { booths_count: number };

/** 試合の登録・更新で送る内容（全項目を送り直す） */
export type GameInput = {
  season: string;
  section: number | null;
  home_team: string;
  away_team: string;
  venue_id: number;
  /** ISO 8601（例: 2026-11-01T19:05:00+09:00） */
  tipoff_at: string;
  status: GameStatus;
};

export type Venue = { id: number; name: string; address: string | null; map_note: string | null };

/** 管理画面のブース（ADMIN-03） */
export type AdminBooth = {
  booth_id: number;
  name: string;
  category: BoothCategory;
  location_label: string;
  description: string | null;
  staff_count: number;
  games_count: number;
};

export type BoothInput = { name: string; category: BoothCategory; location_label: string; description: string | null };

/** 試合へのブースの割り当て状況（ADMIN-04）。全ブースについて返る */
export type GameBoothAssignment = {
  booth_id: number;
  name: string;
  category: BoothCategory;
  location_label: string;
  assigned: boolean;
  is_open: boolean;
  /** 混雑・在庫の記録があり、試合から外せない */
  has_records: boolean;
};

/** CSV 取り込みの結果（ADMIN-02）。action は create（登録する）/ skip（登録済みなどで取り込まない） */
export type CsvImportRow = {
  row: number;
  action: 'create' | 'skip';
  reason: string | null;
  season: string;
  section: number | null;
  home_team: string;
  away_team: string;
  venue: string;
  tipoff_at: string;
};
export type CsvImportResult = { dry_run: boolean; created_count: number; skipped_count: number; rows: CsvImportRow[] };

/** 担当スタッフ（ADMIN-06） */
export type BoothStaffMember = { user_id: number; name: string; email: string; role: UserRole };

/** 発行した招待コード（ADMIN-05） */
export type IssuedInvitation = { code: string; booth: { booth_id: number; name: string }; expires_at: string };
