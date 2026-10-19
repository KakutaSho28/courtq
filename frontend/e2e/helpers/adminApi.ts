import type { Route } from '@playwright/test';
import { NOW_ISO } from './courtqApi';

/**
 * 管理画面（S11〜S14）の E2E 用 API モック。mockCourtqApi の override に渡して使う。
 * データはすべて架空。状態を持つので、画面で行った変更が次の取得に反映される。
 */
type Game = { id: number; season: string; section: number | null; home_team: string; away_team: string; venue: { id: number; name: string }; tipoff_at: string; status: string };
type Booth = { booth_id: number; name: string; category: string; location_label: string; description: string | null };

export function createAdminMock() {
  const venues = [{ id: 1, name: 'サンプルアリーナ（架空）', address: null, map_note: null }];
  let nextGameId = 10;
  let nextBoothId = 10;
  const games: Game[] = [
    { id: 1, season: '2026-27', section: 5, home_team: '北浜ウィングス', away_team: '南ヶ丘ブレイカーズ', venue: venues[0], tipoff_at: '2026-10-16T19:05:00+09:00', status: 'live' },
    { id: 2, season: '2026-27', section: 6, home_team: '南ヶ丘ブレイカーズ', away_team: '北浜ウィングス', venue: venues[0], tipoff_at: '2026-10-23T14:05:00+09:00', status: 'scheduled' },
  ];
  const booths: Booth[] = [
    { booth_id: 1, name: 'ダンク・ポップコーン', category: 'food', location_label: '1F コンコース西', description: null },
    { booth_id: 2, name: 'フリースロー・カレー', category: 'food', location_label: '2F コンコース東', description: null },
    { booth_id: 3, name: 'ウィングス・グッズ東', category: 'merch', location_label: '2F コンコース東', description: null },
  ];
  /** 試合 id → ブース id → 営業中か */
  const assigned: Record<number, Record<number, boolean>> = { 1: { 1: true, 2: true }, 2: {} };
  /** 混雑・在庫の記録があり、試合 1 から外せないブース */
  const recorded = new Set([2]);
  const staff: Record<number, { user_id: number; name: string; email: string; role: string }[]> = {
    2: [{ user_id: 2, name: 'サクラ', email: 'sakura@example.com', role: 'staff' }],
  };
  const requests: { method: string; path: string; body: unknown }[] = [];

  const withCount = (g: Game) => ({ ...g, booths_count: Object.keys(assigned[g.id] ?? {}).length });
  const boothOut = (b: Booth) => ({ ...b, staff_count: (staff[b.booth_id] ?? []).length, games_count: Object.values(assigned).filter((a) => b.booth_id in a).length });
  const gamesSorted = () => [...games].sort((a, b) => a.tipoff_at.localeCompare(b.tipoff_at));

  async function override(path: string, route: Route): Promise<boolean> {
    if (!path.startsWith('/admin/')) return false;
    const req = route.request();
    const method = req.method();
    const isMultipart = (req.headers()['content-type'] ?? '').startsWith('multipart/');
    const raw = req.postDataBuffer()?.toString('utf8') ?? '';
    const body = !isMultipart && raw ? (JSON.parse(raw) as Record<string, unknown>) : {};
    requests.push({ method, path, body: isMultipart ? raw : body });
    const done = async (response: Promise<void>) => {
      await response;
      return true;
    };
    const ok = (data: unknown, status = 200) => route.fulfill({ status, json: { data } });
    const fail = (message: string, errors?: Record<string, string[]>) => route.fulfill({ status: 422, json: { message, ...(errors ? { errors } : {}) } });

    // ----- 試合 -----
    if (path === '/admin/games' && method === 'GET') return done(ok(gamesSorted().map(withCount)));
    if (path === '/admin/games' && method === 'POST') {
      const input = body as unknown as Omit<Game, 'id' | 'venue'> & { venue_id: number };
      if (games.some((g) => g.season === input.season && g.section === input.section && g.home_team === input.home_team && g.away_team === input.away_team)) {
        return done(fail('同じ試合（シーズン・節・ホーム・アウェイが同じ）がすでに登録されています'));
      }
      const game: Game = { id: nextGameId++, season: input.season, section: input.section, home_team: input.home_team, away_team: input.away_team, venue: venues.find((v) => v.id === input.venue_id) ?? venues[0], tipoff_at: input.tipoff_at, status: input.status ?? 'scheduled' };
      games.push(game);
      assigned[game.id] = {};
      return done(ok(withCount(game), 201));
    }
    if (path === '/admin/games/import' && method === 'POST') {
      const text = raw;
      const dryRun = /name="dry_run"\r?\n\r?\n1/.test(text);
      if (text.includes('BAD')) {
        return done(fail('3 行目: date（日付） は Y-m-d の形式で入力してください', { csv: ['3 行目: date（日付） は Y-m-d の形式で入力してください', '4 行目: 会場「みなと第二」が登録されていません（会場名は完全に一致させてください）'] }));
      }
      const rows = [
        { row: 2, action: 'create', reason: null, season: '2026-27', section: 7, home_team: '北浜ウィングス', away_team: '東町ホークス', venue: venues[0].name, tipoff_at: '2026-11-01T19:05:00+09:00' },
        { row: 3, action: 'skip', reason: 'すでに登録されています', season: '2026-27', section: 5, home_team: '北浜ウィングス', away_team: '南ヶ丘ブレイカーズ', venue: venues[0].name, tipoff_at: '2026-10-16T19:05:00+09:00' },
      ];
      if (!dryRun) {
        games.push({ id: nextGameId++, season: '2026-27', section: 7, home_team: '北浜ウィングス', away_team: '東町ホークス', venue: venues[0], tipoff_at: '2026-11-01T19:05:00+09:00', status: 'scheduled' });
        assigned[nextGameId - 1] = {};
      }
      return done(ok({ dry_run: dryRun, created_count: 1, skipped_count: 1, rows }));
    }
    const gameOne = path.match(/^\/admin\/games\/(\d+)$/);
    if (gameOne && method === 'PUT') {
      const game = games.find((g) => g.id === Number(gameOne[1]));
      if (!game) return done(route.fulfill({ status: 404, json: { message: '見つかりません' } }));
      const input = body as unknown as Omit<Game, 'id' | 'venue'> & { venue_id: number };
      Object.assign(game, { ...input, venue: venues.find((v) => v.id === input.venue_id) ?? game.venue });
      return done(ok(withCount(game)));
    }
    if (gameOne && method === 'DELETE') {
      const id = Number(gameOne[1]);
      if (recorded.size > 0 && id === 1) return done(fail('混雑・在庫の記録がある試合は削除できません。終了した試合は、状態を「終了」にしてください'));
      games.splice(games.findIndex((g) => g.id === id), 1);
      return done(route.fulfill({ json: { message: '試合を削除しました' } }));
    }

    // ----- 会場 -----
    if (path === '/admin/venues' && method === 'GET') return done(ok(venues));
    if (path === '/admin/venues' && method === 'POST') {
      const venue = { id: venues.length + 1, name: String(body.name), address: null, map_note: null };
      venues.push(venue);
      return done(ok(venue, 201));
    }

    // ----- ブース -----
    if (path === '/admin/booths' && method === 'GET') return done(ok([...booths].sort((a, b) => a.name.localeCompare(b.name, 'ja')).map(boothOut)));
    if (path === '/admin/booths' && method === 'POST') {
      const booth: Booth = { booth_id: nextBoothId++, name: String(body.name), category: String(body.category), location_label: String(body.location_label), description: (body.description as string | null) ?? null };
      booths.push(booth);
      return done(ok(boothOut(booth), 201));
    }
    const boothOne = path.match(/^\/admin\/booths\/(\d+)$/);
    if (boothOne) {
      const booth = booths.find((b) => b.booth_id === Number(boothOne[1]));
      if (!booth) return done(route.fulfill({ status: 404, json: { message: '見つかりません' } }));
      if (method === 'GET') return done(ok(boothOut(booth)));
      if (method === 'PUT') {
        Object.assign(booth, body);
        return done(ok(boothOut(booth)));
      }
      if (method === 'DELETE') {
        if (Object.values(assigned).some((a) => booth.booth_id in a)) return done(fail('試合に割り当て済みのブースは削除できません。先に試合への割り当てを外してください'));
        booths.splice(booths.indexOf(booth), 1);
        return done(route.fulfill({ json: { message: 'ブースを削除しました' } }));
      }
    }

    // ----- 割り当て -----
    const assignment = path.match(/^\/admin\/games\/(\d+)\/booths$/);
    if (assignment) {
      const gameId = Number(assignment[1]);
      const rowsOut = () => [...booths].sort((a, b) => a.name.localeCompare(b.name, 'ja')).map((b) => ({
        booth_id: b.booth_id, name: b.name, category: b.category, location_label: b.location_label,
        assigned: b.booth_id in assigned[gameId], is_open: assigned[gameId][b.booth_id] ?? true,
        has_records: gameId === 1 && recorded.has(b.booth_id) && b.booth_id in assigned[gameId],
      }));
      if (method === 'GET') return done(ok(rowsOut()));
      if (method === 'PUT') {
        const items = (body.booths as { booth_id: number; is_open: boolean }[]) ?? [];
        const dropped = Object.keys(assigned[gameId]).map(Number).find((id) => !items.some((i) => i.booth_id === id) && gameId === 1 && recorded.has(id));
        if (dropped !== undefined) {
          const name = booths.find((b) => b.booth_id === dropped)?.name;
          return done(fail(`「${name}」は混雑・在庫の記録があるため、試合から外せません。営業中をオフ（閉店）にしてください`));
        }
        assigned[gameId] = Object.fromEntries(items.map((i) => [i.booth_id, i.is_open]));
        return done(ok(rowsOut()));
      }
    }

    // ----- 招待コードと担当スタッフ -----
    const invite = path.match(/^\/admin\/booths\/(\d+)\/invitations$/);
    if (invite && method === 'POST') {
      const booth = booths.find((b) => b.booth_id === Number(invite[1]));
      return done(ok({ code: 'K7Q2-MX9P', booth: { booth_id: booth?.booth_id, name: booth?.name }, expires_at: '2026-10-23T19:30:00+09:00' }, 201));
    }
    const staffList = path.match(/^\/admin\/booths\/(\d+)\/staff$/);
    if (staffList && method === 'GET') return done(ok(staff[Number(staffList[1])] ?? []));
    const staffOne = path.match(/^\/admin\/booths\/(\d+)\/staff\/(\d+)$/);
    if (staffOne && method === 'DELETE') {
      const boothId = Number(staffOne[1]);
      staff[boothId] = (staff[boothId] ?? []).filter((m) => m.user_id !== Number(staffOne[2]));
      return done(route.fulfill({ json: { message: '担当を解除しました' } }));
    }

    return false;
  }

  return { override, requests, now: NOW_ISO };
}
