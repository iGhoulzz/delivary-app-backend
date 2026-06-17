// settlementsUI.jsx — shared atoms for the Settlements section.

function EarningBadge({ status, lang, sm }) {
  const meta = EARNING_STATUS[status] || EARNING_STATUS.pending_settlement;
  const s = SOFT[meta.tone] || SOFT.slate;
  return (
    <span className="inline-flex items-center gap-1 rounded-md font-semibold whitespace-nowrap"
      style={{ background: tint(s.c, 15), color: s.c, padding: sm ? '2px 7px' : '3px 9px', fontSize: sm ? '11px' : '11.5px' }}>
      {tt(meta, lang)}
    </span>
  );
}

function SettlementStatusBadge({ status, lang }) {
  const meta = SETTLEMENT_STATUS[status] || SETTLEMENT_STATUS.processed;
  const s = SOFT[meta.tone] || SOFT.slate;
  return (
    <span className="inline-flex items-center gap-1 rounded-md px-2 py-[3px] text-[11.5px] font-semibold whitespace-nowrap" style={{ background: tint(s.c, 15), color: s.c }}>
      {tt(meta, lang)}
    </span>
  );
}

// 4-stage seller-earnings lifecycle. `current` highlights the active stage.
function LifecyclePipeline({ current, lang, compact }) {
  const curStep = EARNING_STATUS[current] ? EARNING_STATUS[current].step : -1;
  return (
    <div className="flex items-center gap-1.5">
      {EARNING_FLOW.map((k, i) => {
        const meta = EARNING_STATUS[k];
        const done = i < curStep, active = i === curStep;
        const c = (SOFT[meta.tone] || SOFT.slate).c;
        return (
          <React.Fragment key={k}>
            <div className="flex items-center gap-1.5">
              <span className="grid place-items-center rounded-full text-white transition" style={{ width: compact ? 16 : 20, height: compact ? 16 : 20, background: active ? c : done ? tint(c, 55) : '#e2e8f0', color: active || done ? '#fff' : '#94a3b8' }}>
                {done ? <Icon name="check" size={compact ? 10 : 12} strokeWidth={3} /> : <span className="text-[10px] font-bold">{num(i + 1, lang)}</span>}
              </span>
              {!compact && <span className={`text-[11px] font-semibold ${active ? '' : 'text-slate-400'}`} style={active ? { color: c } : undefined}>{tt(meta, lang)}</span>}
            </div>
            {i < EARNING_FLOW.length - 1 && <span className="h-px flex-1 min-w-[10px]" style={{ background: i < curStep ? tint(c, 45) : '#e2e8f0' }} />}
          </React.Fragment>
        );
      })}
    </div>
  );
}

// Three driver buckets, side by side.
function BucketTriad({ b, lang }) {
  const cards = [
    { k: 'cash_to_deposit', v: b.cash, c: '#0f172a' },
    { k: 'earnings_balance', v: b.earnings, c: '#0d9488' },
    { k: 'debt_balance', v: b.debt, c: b.debt > 0 ? '#e11d48' : '#0f172a' },
  ];
  return (
    <div className="grid grid-cols-3 gap-2.5">
      {cards.map((c) => (
        <div key={c.k} className="rounded-xl border border-slate-200/80 bg-white/60 px-3 py-2.5">
          <div className="text-[11px] font-medium text-slate-400">{tt(BUCKETS[c.k].short, lang)}</div>
          <div className="mt-0.5"><Money v={c.v} lang={lang} strong className="text-[15px]" /></div>
        </div>
      ))}
    </div>
  );
}

// Net + direction summary line.
function NetDisplay({ net, lang, size }) {
  const dir = settleDirection(net);
  const tone = dir === 'driver_to_office' ? '#0d9488' : dir === 'office_to_driver' ? '#d97706' : '#64748b';
  const big = size === 'lg';
  return (
    <div className="flex items-center justify-between rounded-xl px-3.5 py-3" style={{ background: tint(tone, 9), border: `1px solid ${tint(tone, 25)}` }}>
      <div className="min-w-0">
        <div className="text-[12px] font-bold" style={{ color: tone }}>{tt(DIRECTION_LABEL[dir], lang)}</div>
        <div className="text-[11.5px] text-slate-500">{tt(DIRECTION_ACTION[dir], lang)}</div>
      </div>
      <Money v={Math.abs(net)} lang={lang} strong className={big ? 'text-[22px]' : 'text-[17px]'} />
    </div>
  );
}

Object.assign(window, { EarningBadge, SettlementStatusBadge, LifecyclePipeline, BucketTriad, NetDisplay });
