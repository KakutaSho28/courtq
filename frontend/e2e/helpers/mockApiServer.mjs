// PWA の E2E 用の最小 API サーバー（依存なし）。
// Service Worker の通信は Playwright の route では横取りできないため、実際の HTTP サーバーとして立てる。
import { createServer } from 'node:http';

const PORT = Number(process.env.MOCK_API_PORT ?? 5180);
const ORIGIN = process.env.MOCK_API_ALLOW_ORIGIN ?? 'http://localhost:5176';

const me = { id: 1, name: 'アリス', email: 'a@example.com', role: 'user', booths: [] };

/** 今日（日本時間）の 19:05 開始の試合。PWA のテストは実際の時刻で動くので、毎回「今日」の試合を作る */
function todayGame() {
  const ymd = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Tokyo' }).format(new Date());
  return {
    id: 1,
    season: '2026-27',
    section: 5,
    home_team: '北浜ウィングス',
    away_team: '南ヶ丘ブレイカーズ',
    venue: { id: 1, name: 'サンプルアリーナ（架空）' },
    tipoff_at: `${ymd}T19:05:00+09:00`,
    status: 'live',
  };
}

// ネットワーク断の再現用。context.setOffline() は Service Worker 自身の通信には効かないため、
// サーバー側で接続を切る（GET /__down?on=1 で切断、on=0 で復帰）。
let down = false;

createServer((req, res) => {
  const url = new URL(req.url ?? '/', `http://localhost:${PORT}`);

  if (url.pathname === '/__down') {
    down = url.searchParams.get('on') === '1';
    res.writeHead(200).end(String(down));
    return;
  }
  if (down) {
    req.socket.destroy();
    return;
  }

  res.setHeader('Access-Control-Allow-Origin', ORIGIN);
  res.setHeader('Access-Control-Allow-Headers', 'authorization, content-type, accept, x-socket-id');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
  res.setHeader('Vary', 'Origin');

  if (req.method === 'OPTIONS') {
    res.writeHead(204).end();
    return;
  }

  const json = (body, status = 200) => {
    res.writeHead(status, { 'Content-Type': 'application/json' }).end(JSON.stringify(body));
  };

  if (url.pathname === '/api/health') return json({ data: { status: 'ok', database: 'ok' } });
  if (url.pathname === '/api/me') return json({ data: me });
  if (url.pathname === '/api/games') return json({ data: [todayGame()] });
  // ブース（S04 / S05 のオフライン表示の検証用）。更新時刻は「いまから N 分前」で毎回作る
  const ago = (m) => new Date(Date.now() - m * 60_000).toISOString().replace('Z', '+00:00');
  const boothList = [
    { booth_id: 1, game_booth_id: 11, name: 'ダンク・ポップコーン', category: 'food', location_label: '1F コンコース西', is_open: true, congestion: { level: 1, label: '空いている', wait_minutes: 0, updated_at: ago(2), is_stale: false }, has_stock: true, is_favorite: false },
    { booth_id: 2, game_booth_id: 12, name: 'フリースロー・カレー', category: 'food', location_label: '2F コンコース東', is_open: true, congestion: { level: 3, label: '混雑', wait_minutes: 15, updated_at: ago(3), is_stale: false }, has_stock: true, is_favorite: true },
  ];
  const meta = { stale_after_minutes: 15 };
  if (url.pathname === '/api/games/1') return json({ data: todayGame() });
  if (url.pathname === '/api/games/1/booths') return json({ data: boothList, meta });
  const detail = url.pathname.match(/^\/api\/games\/1\/booths\/(\d+)$/);
  if (detail) {
    const booth = boothList.find((b) => String(b.booth_id) === detail[1]);
    if (!booth) return json({ message: '見つかりません' }, 404);
    return json({
      data: {
        ...booth,
        description: '架空のデモ用ブースです。',
        menu_items: [
          { id: 21, name: 'ビーフカレー', kind: 'food', price: 900, description: null, stock: { status: 'in_stock', label: '在庫あり', quantity: null, updated_at: ago(3), is_stale: false } },
          { id: 23, name: 'ナン', kind: 'food', price: 300, description: null, stock: { status: 'sold_out', label: '売り切れ', quantity: 0, updated_at: ago(10), is_stale: false } },
        ],
      },
      meta,
    });
  }
  if (/^\/api\/games\/1\/booths\/\d+\/congestion-history$/.test(url.pathname)) {
    return json({ data: [{ level: 1, label: '空いている', wait_minutes: 0, recorded_at: ago(40) }, { level: 3, label: '混雑', wait_minutes: 15, recorded_at: ago(3) }] });
  }
  if (url.pathname === '/api/auth/logout') return json({ message: 'ログアウトしました' });
  return json({ message: '見つかりません' }, 404);
}).listen(PORT, () => console.log(`mock api on :${PORT}`));
