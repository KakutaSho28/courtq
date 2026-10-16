import { useEffect, useMemo, useRef, useState, type PointerEvent, type RefObject } from 'react';
import { CONGESTION_LABELS } from '../constants';
import { formatClock } from '../lib/time';
import type { CongestionLevel, CongestionPoint } from '../types';

type Props = {
  points: CongestionPoint[];
  now: number;
};

const HEIGHT = 196;
const PAD = { top: 14, right: 16, bottom: 30, left: 82 };
const LEVELS: CongestionLevel[] = [1, 2, 3, 4];

/** 実際の表示幅に合わせて描く（SVG を拡大縮小すると、スマホで文字が小さくなりすぎるため） */
function useWidth<T extends HTMLElement>(): [RefObject<T>, number] {
  const ref = useRef<T>(null);
  const [width, setWidth] = useState(0);

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    setWidth(el.clientWidth);
    const observer = new ResizeObserver(() => setWidth(el.clientWidth));
    observer.observe(el);
    return () => observer.disconnect();
  }, []);

  return [ref, width];
}

function waitText(minutes: number | null): string {
  return minutes === null ? '待ち時間 —' : `待ち ${minutes}分`;
}

/**
 * 混雑の推移（BOOTH-03）。1 系列なので凡例は置かず、見出しで名前を示す。
 * - 混雑は次の更新まで続くので階段状の線（step-after）。最後の値は「いま」まで伸ばす
 * - 点は混雑の状態色。縦軸は文言（空いている〜非常に混雑）で、色だけに頼らない
 * - 指・マウスを置くとその時刻の値を出す。同じ内容は「表で見る」でも読める
 */
export function CongestionChart({ points, now }: Props) {
  const [wrapRef, width] = useWidth<HTMLDivElement>();
  const [active, setActive] = useState<number | null>(null);

  const geometry = useMemo(() => {
    if (points.length === 0 || width === 0) return null;
    const times = points.map((p) => new Date(p.recorded_at).getTime());
    const start = times[0];
    const end = Math.max(times[times.length - 1], now, start + 60_000);
    const plotW = Math.max(width - PAD.left - PAD.right, 40);
    const plotH = HEIGHT - PAD.top - PAD.bottom;
    const x = (t: number) => PAD.left + ((t - start) / (end - start)) * plotW;
    const y = (level: number) => PAD.top + ((4 - level) / 3) * plotH;
    const coords = points.map((p, i) => ({ x: x(times[i]), y: y(p.level) }));

    let d = `M ${coords[0].x} ${coords[0].y}`;
    for (let i = 1; i < coords.length; i++) {
      d += ` H ${coords[i].x} V ${coords[i].y}`;
    }
    d += ` H ${x(end)}`;

    return { coords, d, y, plotW, start, end, endX: x(end) };
  }, [points, width, now]);

  if (points.length === 0) {
    return <p className="muted">まだ混雑の記録がありません。スタッフが更新すると、ここに推移が表示されます。</p>;
  }

  const latest = points[points.length - 1];
  const summary = `混雑の推移: ${formatClock(points[0].recorded_at)} から ${points.length} 件の記録。最新は ${formatClock(latest.recorded_at)} の「${latest.label}」`;

  const onPointer = (e: PointerEvent<SVGRectElement>) => {
    if (!geometry) return;
    const rect = e.currentTarget.ownerSVGElement?.getBoundingClientRect();
    if (!rect) return;
    const px = e.clientX - rect.left;
    let nearest = 0;
    geometry.coords.forEach((c, i) => {
      if (Math.abs(c.x - px) < Math.abs(geometry.coords[nearest].x - px)) nearest = i;
    });
    setActive(nearest);
  };

  const activePoint = active !== null ? points[active] : null;
  const activeCoord = active !== null && geometry ? geometry.coords[active] : null;

  return (
    <div className="chart">
      <div className="chart-plot" ref={wrapRef}>
        {geometry && (
          <svg width={width} height={HEIGHT} role="img" aria-label={summary}>
            {LEVELS.map((level) => (
              <g key={level}>
                <line className="chart-grid" x1={PAD.left} x2={PAD.left + geometry.plotW} y1={geometry.y(level)} y2={geometry.y(level)} />
                <text className="chart-axis" x={PAD.left - 10} y={geometry.y(level)} textAnchor="end" dominantBaseline="middle">
                  {CONGESTION_LABELS[level]}
                </text>
              </g>
            ))}
            <text className="chart-axis" x={PAD.left} y={HEIGHT - 8} textAnchor="start">
              {formatClock(points[0].recorded_at)}
            </text>
            <text className="chart-axis" x={geometry.endX} y={HEIGHT - 8} textAnchor="end">
              いま
            </text>

            {activeCoord && <line className="chart-crosshair" x1={activeCoord.x} x2={activeCoord.x} y1={PAD.top} y2={HEIGHT - PAD.bottom} />}
            <path className="chart-line" d={geometry.d} />
            {geometry.coords.map((c, i) => (
              <circle
                key={points[i].recorded_at + i}
                className={`chart-dot status-fill-${points[i].level}${i === active ? ' is-active' : ''}`}
                cx={c.x}
                cy={c.y}
                r={i === active ? 6 : 4.5}
              />
            ))}
            {/* 当たり判定は線や点より広く取る（指で触りやすいように） */}
            <rect
              className="chart-hit"
              x={PAD.left - 8}
              y={0}
              width={geometry.plotW + 16}
              height={HEIGHT}
              onPointerMove={onPointer}
              onPointerDown={onPointer}
              onPointerLeave={() => setActive(null)}
            />
          </svg>
        )}
        {activePoint && activeCoord && (
          <div
            className="chart-tooltip"
            role="status"
            style={{
              left: Math.min(Math.max(activeCoord.x, 70), width - 70),
              top: Math.max(activeCoord.y - 12, 0),
            }}
          >
            <strong>{formatClock(activePoint.recorded_at)}</strong> {activePoint.label}（{waitText(activePoint.wait_minutes)}）
          </div>
        )}
      </div>

      <details className="chart-table">
        <summary>表で見る</summary>
        <table>
          <thead>
            <tr>
              <th scope="col">時刻</th>
              <th scope="col">混雑</th>
              <th scope="col">待ち時間</th>
            </tr>
          </thead>
          <tbody>
            {[...points].reverse().map((p, i) => (
              <tr key={p.recorded_at + i}>
                <td>{formatClock(p.recorded_at)}</td>
                <td>{p.label}</td>
                <td>{p.wait_minutes === null ? '—' : `${p.wait_minutes}分`}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </details>
    </div>
  );
}
