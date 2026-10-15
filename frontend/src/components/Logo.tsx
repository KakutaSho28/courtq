import logo from '../assets/courtq-logo.svg';
import logoDark from '../assets/courtq-logo-dark.svg';
import { APP_NAME } from '../constants';

type Props = {
  /** dark: 紺の背景（ヘッダー）用 / light: 白い背景用 */
  variant?: 'light' | 'dark';
  height?: number;
};

export function Logo({ variant = 'light', height = 40 }: Props) {
  return <img src={variant === 'dark' ? logoDark : logo} alt={APP_NAME} height={height} className="logo" />;
}
