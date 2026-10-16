import type { Page, Route } from '@playwright/test';

/**
 * 利用者の画面（S03〜S06）の E2E 用 API モック。page.route で http://api.test/api/** を横取りする。
 * データはすべて架空。時刻は NOW（2026-10-16 19:30 日本時間）を基準にしている（テストでは page.clock で固定する）。
 */
export const API = 'http://api.test/api';
export const NOW = new Date('2026-10-16T19:30:00+09:00');

const minutesAgo = (m: number) => new Date(NOW.getTime() - m * 60_000).toISOString().replace('Z', '+00:00');

export const ALICE = { id: 1, name: 'アリス', email: 'a@example.com', role: 'user', booths: [] as { booth_id: number; name: string }[] };
/** ブース 2（フリースロー・カレー）の担当スタッフ */
export const SAKURA = { id: 2, name: 'サクラ', email: 'sakura@example.com', role: 'staff', booths: [{ booth_id: 2, name: 'フリースロー・カレー' }] };
/** 有効な招待コード（ブース 5「ウィングス・グッズ東」。表示は K7Q2-MX9P） */
export const VALID_CODE = 'K7Q2MX9P';
export const NOW_ISO = '2026-10-16T19:30:00+09:00';

export const TODAY_GAME = {
  id: 1,
  season: '2026-27',
  section: 5,
  home_team: '北浜ウィングス',
  away_team: '南ヶ丘ブレイカーズ',
  venue: { id: 1, name: 'サンプルアリーナ（架空）' },
  tipoff_at: '2026-10-16T19:05:00+09:00',
  status: 'live',
};
export const NEXT_GAME = {
  ...TODAY_GAME,
  id: 2,
  section: 6,
  home_team: '南ヶ丘ブレイカーズ',
  away_team: '北浜ウィングス',
  tipoff_at: '2026-10-23T14:05:00+09:00',
  status: 'scheduled',
};

const congestion = (level: 1 | 2 | 3 | 4 | null, wait: number | null, updatedMinutesAgo: number | null) => ({
  level,
  label: level === null ? null : { 1: '空いている', 2: 'やや混雑', 3: '混雑', 4: '非常に混雑' }[level],
  wait_minutes: wait,
  updated_at: updatedMinutesAgo === null ? null : minutesAgo(updatedMinutesAgo),
  is_stale: updatedMinutesAgo !== null && updatedMinutesAgo > 15,
});

export type Booth = {
  booth_id: number;
  game_booth_id: number;
  name: string;
  category: 'food' | 'merch' | 'both';
  location_label: string;
  is_open: boolean;
  congestion: ReturnType<typeof congestion>;
  has_stock: boolean;
  is_favorite: boolean;
};

export function booths(): Booth[] {
  return [
    { booth_id: 1, game_booth_id: 11, name: 'ダンク・ポップコーン', category: 'food', location_label: '1F コンコース西', is_open: true, congestion: congestion(1, 0, 2), has_stock: true, is_favorite: false },
    { booth_id: 2, game_booth_id: 12, name: 'フリースロー・カレー', category: 'food', location_label: '2F コンコース東', is_open: true, congestion: congestion(2, 8, 20), has_stock: true, is_favorite: true },
    { booth_id: 3, game_booth_id: 13, name: 'ファーストブレイク・バーガー', category: 'food', location_label: '2F コンコース北', is_open: true, congestion: congestion(3, 15, 5), has_stock: true, is_favorite: false },
    { booth_id: 4, game_booth_id: 14, name: 'ハーフタイム・ドリンク', category: 'food', location_label: '1F コンコース東', is_open: true, congestion: congestion(4, 30, 1), has_stock: false, is_favorite: false },
    { booth_id: 5, game_booth_id: 15, name: 'ウィングス・グッズ東', category: 'merch', location_label: '2F コンコース東', is_open: true, congestion: congestion(null, null, null), has_stock: true, is_favorite: false },
    { booth_id: 6, game_booth_id: 16, name: 'ウィングス・グッズ西', category: 'merch', location_label: '2F コンコース西', is_open: false, congestion: congestion(1, 0, 40), has_stock: true, is_favorite: false },
  ];
}

export function boothDetail(boothId: number) {
  const booth = booths().find((b) => b.booth_id === boothId) ?? booths()[1];
  return {
    data: {
      ...booth,
      description: '架空のデモ用ブースです。',
      congestion: booth.congestion,
      menu_items: [
        { id: 21, name: 'ビーフカレー', kind: 'food', price: 900, description: null, stock: { status: 'in_stock', label: '在庫あり', quantity: null, updated_at: minutesAgo(3), is_stale: false } },
        { id: 22, name: 'カツカレー', kind: 'food', price: 1200, description: '数量限定', stock: { status: 'low', label: '残りわずか', quantity: 4, updated_at: minutesAgo(30), is_stale: true } },
        { id: 23, name: 'ナン', kind: 'food', price: 300, description: null, stock: { status: 'sold_out', label: '売り切れ', quantity: 0, updated_at: minutesAgo(10), is_stale: false } },
        { id: 24, name: 'マフラータオル', kind: 'merch', price: 1800, description: null, stock: null },
      ],
    },
    meta: { stale_after_minutes: 15 },
  };
}

export const HISTORY = [
  { level: 1, label: '空いている', wait_minutes: 0, recorded_at: minutesAgo(60) },
  { level: 3, label: '混雑', wait_minutes: 20, recorded_at: minutesAgo(40) },
  { level: 2, label: 'やや混雑', wait_minutes: 8, recorded_at: minutesAgo(20) },
];

type Options = {
  games?: unknown[];
  /** ログインしているユーザー（既定は来場者のアリス） */
  user?: typeof ALICE | typeof SAKURA;
  /** パス（/api を除く）ごとに応答を差し替える。関数を返すと、その呼び出しの応答になる */
  override?: (path: string, route: Route) => Promise<boolean> | boolean;
};

export type Recorded = { method: string; path: string; query: string };

/** ログイン済みの状態にして、API をモックする。呼ばれた API を記録して返す */
export async function mockCourtqApi(page: Page, options: Options = {}): Promise<Recorded[]> {
  const calls: Recorded[] = [];
  const favorites = new Set(booths().filter((b) => b.is_favorite).map((b) => b.booth_id));
  let me: typeof ALICE | typeof SAKURA = options.user ?? ALICE;

  // スタッフの更新が反映される状態（ブース 2 の混雑、メニューの在庫、メニュー一覧）
  const congestionOf: Record<number, ReturnType<typeof congestion>> = {};
  const stockOf: Record<number, Record<string, unknown> | null> = {};
  let menu: { id: number; booth_id: number; name: string; kind: string; price: number; description: string | null; is_active: boolean }[] = [
    { id: 21, booth_id: 2, name: 'ビーフカレー', kind: 'food', price: 900, description: null, is_active: true },
    { id: 22, booth_id: 2, name: 'カツカレー', kind: 'food', price: 1200, description: '数量限定', is_active: true },
    { id: 23, booth_id: 2, name: 'ナン', kind: 'food', price: 300, description: null, is_active: true },
    { id: 24, booth_id: 2, name: 'マフラータオル', kind: 'merch', price: 1800, description: null, is_active: true },
    { id: 25, booth_id: 2, name: 'ホットドッグ', kind: 'food', price: 600, description: null, is_active: false },
  ];
  let nextMenuId = 30;
  const level = (l: number) => ({ 1: '空いている', 2: 'やや混雑', 3: '混雑', 4: '非常に混雑' })[l] as string;
  const stockLabel = (st: string) => ({ in_stock: '在庫あり', low: '残りわずか', sold_out: '売り切れ' })[st] as string;

  await page.addInitScript((user) => {
    if (!localStorage.getItem('courtq_token')) {
      localStorage.setItem('courtq_token', 'alice-token');
      localStorage.setItem('courtq_user', JSON.stringify(user));
    }
  }, me);

  await page.route(`${API}/**`, async (route) => {
    const req = route.request();
    const url = new URL(req.url());
    const path = url.pathname.replace(/^\/api/, '');
    calls.push({ method: req.method(), path, query: url.search });

    if (options.override && (await options.override(path, route))) return;

    if (path === '/me') return route.fulfill({ json: { data: me } });
    if (path === '/auth/logout') return route.fulfill({ json: { message: 'ログアウトしました' } });
    if (path === '/games') return route.fulfill({ json: { data: options.games ?? [TODAY_GAME, NEXT_GAME] } });
    if (path === '/games/1') return route.fulfill({ json: { data: TODAY_GAME } });
    if (path === '/games/2') return route.fulfill({ json: { data: NEXT_GAME } });

    if (/^\/games\/\d+\/booths$/.test(path)) {
      let list = booths().map((b) => ({ ...b, is_favorite: favorites.has(b.booth_id) }));
      const category = url.searchParams.get('category');
      if (category) list = list.filter((b) => b.category === category || b.category === 'both');
      if (url.searchParams.get('in_stock') === '1') list = list.filter((b) => b.has_stock);
      if (url.searchParams.get('favorites') === '1') list = list.filter((b) => favorites.has(b.booth_id));
      return route.fulfill({ json: { data: list, meta: { stale_after_minutes: 15 } } });
    }
    const detail = path.match(/^\/games\/\d+\/booths\/(\d+)$/);
    if (detail) {
      const id = Number(detail[1]);
      const body = boothDetail(id);
      body.data.is_favorite = favorites.has(id);
      if (congestionOf[id]) body.data.congestion = congestionOf[id];
      // スタッフが編集したメニュー（販売中のものだけ）と、更新した在庫を反映する
      if (id === 2) {
        body.data.menu_items = menu
          .filter((m) => m.is_active)
          .map((m) => ({
            id: m.id,
            name: m.name,
            kind: m.kind,
            price: m.price,
            description: m.description,
            stock: (m.id in stockOf ? stockOf[m.id] : (boothDetail(2).data.menu_items.find((x) => x.id === m.id)?.stock ?? null)) as never,
          })) as never;
      }
      return route.fulfill({ json: body });
    }
    if (/^\/games\/\d+\/booths\/\d+\/congestion-history$/.test(path)) return route.fulfill({ json: { data: HISTORY } });

    if (path === '/me/favorites') {
      const list = booths()
        .filter((b) => favorites.has(b.booth_id))
        .map(({ booth_id, name, category, location_label }) => ({ booth_id, name, category, location_label }));
      return route.fulfill({ json: { data: list } });
    }
    const fav = path.match(/^\/booths\/(\d+)\/favorite$/);
    if (fav) {
      const id = Number(fav[1]);
      if (req.method() === 'PUT') favorites.add(id);
      if (req.method() === 'DELETE') favorites.delete(id);
      return route.fulfill({ json: { data: { booth_id: id, is_favorite: req.method() === 'PUT' } } });
    }

    // ===== スタッフ =====
    if (path === '/staff/booths') {
      if (me.role === 'user') return route.fulfill({ status: 403, json: { message: 'この操作は許可されていません' } });
      const mine = booths().filter((b) => me.booths.some((x) => x.booth_id === b.booth_id));
      const data = mine.map((b) => ({
        booth_id: b.booth_id,
        name: b.name,
        category: b.category,
        location_label: b.location_label,
        upcoming_games: [
          { game_id: 1, game_booth_id: b.game_booth_id, home_team: TODAY_GAME.home_team, away_team: TODAY_GAME.away_team, tipoff_at: TODAY_GAME.tipoff_at, status: 'live', is_open: b.is_open, congestion: congestionOf[b.booth_id] ?? b.congestion },
        ],
      }));
      return route.fulfill({ json: { data } });
    }
    const cong = path.match(/^\/staff\/games\/(\d+)\/booths\/(\d+)\/congestion$/);
    if (cong && req.method() === 'PUT') {
      const body = req.postDataJSON() as { level: number; wait_minutes: number | null };
      const id = Number(cong[2]);
      const value = { level: body.level, label: level(body.level), wait_minutes: body.wait_minutes, updated_at: NOW_ISO, is_stale: false };
      congestionOf[id] = value as never;
      const b = booths().find((x) => x.booth_id === id);
      return route.fulfill({ json: { data: { game_id: 1, booth_id: id, game_booth_id: b?.game_booth_id ?? 0, is_open: true, congestion: value } } });
    }
    const stock = path.match(/^\/staff\/games\/(\d+)\/menu-items\/(\d+)\/stock$/);
    if (stock && req.method() === 'PUT') {
      const body = req.postDataJSON() as { status: string; quantity: number | null };
      const itemId = Number(stock[2]);
      const value = { status: body.status, label: stockLabel(body.status), quantity: body.quantity, updated_at: NOW_ISO, is_stale: false };
      stockOf[itemId] = value;
      return route.fulfill({ json: { data: { game_id: 1, menu_item_id: itemId, stock: value } } });
    }
    const menuList = path.match(/^\/staff\/booths\/(\d+)\/menu-items$/);
    if (menuList && req.method() === 'GET') return route.fulfill({ json: { data: menu } });
    if (menuList && req.method() === 'POST') {
      const body = req.postDataJSON() as { name: string; kind: string; price: number; description: string | null; is_active: boolean };
      const created = { id: nextMenuId++, booth_id: Number(menuList[1]), ...body };
      menu = [...menu, created];
      return route.fulfill({ status: 201, json: { data: created } });
    }
    const menuOne = path.match(/^\/staff\/booths\/(\d+)\/menu-items\/(\d+)$/);
    if (menuOne && req.method() === 'PUT') {
      const id = Number(menuOne[2]);
      menu = menu.map((m) => (m.id === id ? { ...m, ...(req.postDataJSON() as object) } : m));
      return route.fulfill({ json: { data: menu.find((m) => m.id === id) } });
    }
    if (menuOne && req.method() === 'DELETE') {
      const id = Number(menuOne[2]);
      // 在庫の記録があるメニュー（21〜23）は削除できない
      if ([21, 22, 23].includes(id)) {
        return route.fulfill({ status: 422, json: { message: '在庫の記録があるメニューは削除できません。販売をやめるときは「販売中」をオフにしてください' } });
      }
      menu = menu.filter((m) => m.id !== id);
      return route.fulfill({ json: { message: 'メニューを削除しました' } });
    }
    if (path === '/staff/invitations/accept' && req.method() === 'POST') {
      const code = String((req.postDataJSON() as { code: string }).code).toUpperCase().replace(/[\s-]/g, '');
      if (code !== VALID_CODE) return route.fulfill({ status: 422, json: { message: '招待コードが正しくないか、有効期限が切れています' } });
      me = { ...ALICE, role: 'staff', booths: [{ booth_id: 5, name: 'ウィングス・グッズ東' }] };
      return route.fulfill({ json: { data: { user: me, booth: { booth_id: 5, name: 'ウィングス・グッズ東' } } } });
    }

    return route.fulfill({ status: 404, json: { message: '見つかりません' } });
  });

  return calls;
}
