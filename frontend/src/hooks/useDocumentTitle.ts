import { useEffect } from 'react';
import { APP_NAME } from '../constants';

/** ページのタイトル（「ブース一覧 | CourtQ」）。ブラウザのタブや履歴で画面を区別できるように */
export function useDocumentTitle(title: string | null): void {
  useEffect(() => {
    document.title = title ? `${title} | ${APP_NAME}` : APP_NAME;
  }, [title]);
}
