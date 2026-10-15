import { useCallback, useEffect, useState } from 'react';

/** beforeinstallprompt は標準の型定義がないので必要な部分だけ定義する */
type BeforeInstallPromptEvent = Event & {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

/**
 * 「ホーム画面に追加」ボタン用。ブラウザがインストール可能と判断すると beforeinstallprompt が届く。
 * 未対応のブラウザ（iOS Safari など）や、インストール済みの場合は canInstall が false のまま。
 */
export function useInstallPrompt() {
  const [promptEvent, setPromptEvent] = useState<BeforeInstallPromptEvent | null>(null);

  useEffect(() => {
    const onBeforeInstall = (event: Event) => {
      event.preventDefault(); // ブラウザ標準のミニバーを出さず、自前のボタンで案内する
      setPromptEvent(event as BeforeInstallPromptEvent);
    };
    const onInstalled = () => setPromptEvent(null);

    window.addEventListener('beforeinstallprompt', onBeforeInstall);
    window.addEventListener('appinstalled', onInstalled);
    return () => {
      window.removeEventListener('beforeinstallprompt', onBeforeInstall);
      window.removeEventListener('appinstalled', onInstalled);
    };
  }, []);

  const install = useCallback(async () => {
    if (!promptEvent) return;
    await promptEvent.prompt();
    await promptEvent.userChoice;
    setPromptEvent(null); // プロンプトは1回しか使えない
  }, [promptEvent]);

  return { canInstall: promptEvent !== null, install };
}
