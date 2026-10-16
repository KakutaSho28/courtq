type Props = {
  /** 待ち時間（分）。null は「入力しない」 */
  value: number | null;
  onChange: (value: number | null) => void;
  disabled?: boolean;
  max?: number;
  step?: number;
};

/** 待ち時間（分）を ± で変える。数字の入力なしで、片手で操作できる。0〜180 分。 */
export function WaitStepper({ value, onChange, disabled = false, max = 180, step = 5 }: Props) {
  const minus = () => onChange(value === null ? null : Math.max(0, value - step));
  const plus = () => onChange(Math.min(max, (value ?? 0) + step));

  return (
    <div className="stepper" role="group" aria-label="待ち時間（分）">
      <button type="button" className="stepper-btn" aria-label={`待ち時間を${step}分減らす`} disabled={disabled || value === null || value <= 0} onClick={minus}>
        −
      </button>
      <output className="stepper-value" aria-live="polite">
        {value === null ? '未入力' : <><strong>{value}</strong> 分</>}
      </output>
      <button type="button" className="stepper-btn" aria-label={`待ち時間を${step}分増やす`} disabled={disabled || (value ?? 0) >= max} onClick={plus}>
        ＋
      </button>
      <button type="button" className="btn btn-ghost btn-sm stepper-clear" disabled={disabled || value === null} onClick={() => onChange(null)}>
        未入力にする
      </button>
    </div>
  );
}
