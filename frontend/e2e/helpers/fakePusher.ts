import type { Page, WebSocketRoute } from '@playwright/test';

/**
 * 偽の Pusher サーバー。pusher-js の WebSocket 接続を横取りして Pusher プロトコルの最小限を話す。
 * 実際の pusher-js と Laravel Echo をそのまま通すので、キーなしでフロントの配線（接続・購読・受信）を検証できる。
 */
export type FakePusher = {
  /** サーバーからイベントを配信する（Laravel の broadcast に相当） */
  push: (channel: string, event: string, data: unknown) => void;
  /** 購読済みのチャンネル名 */
  subscribed: string[];
  /** 接続を切る（切断表示の確認用） */
  drop: () => void;
};

export async function fakePusher(page: Page): Promise<FakePusher> {
  const sockets: WebSocketRoute[] = [];
  const subscribed: string[] = [];

  await page.routeWebSocket(/ws-ap3\.pusher\.com/, (ws) => {
    sockets.push(ws);
    ws.send(
      JSON.stringify({
        event: 'pusher:connection_established',
        data: JSON.stringify({ socket_id: '1234.5678', activity_timeout: 120 }),
      }),
    );
    ws.onMessage((raw) => {
      const message = JSON.parse(String(raw)) as { event: string; data: { channel?: string } };
      if (message.event === 'pusher:subscribe' && message.data.channel) {
        subscribed.push(message.data.channel);
        ws.send(
          JSON.stringify({
            event: 'pusher_internal:subscription_succeeded',
            channel: message.data.channel,
            data: '{}',
          }),
        );
      } else if (message.event === 'pusher:ping') {
        ws.send(JSON.stringify({ event: 'pusher:pong', data: '{}' }));
      }
    });
  });

  return {
    subscribed,
    push: (channel, event, data) => {
      for (const ws of sockets) {
        ws.send(JSON.stringify({ event, channel, data: JSON.stringify(data) }));
      }
    },
    drop: () => {
      for (const ws of sockets) void ws.close();
    },
  };
}
