// driverUI.jsx — shared driver atoms (presence/lifecycle badges, rating, money, vehicle).

// Tone → a single base color; backgrounds are built as translucent tints so they
// adapt to light OR dark surfaces (color-mix over whatever sits behind).
const SOFT = {
  green:  { c: '#16a34a' },
  violet: { c: '#7c3aed' },
  amber:  { c: '#d97706' },
  red:    { c: '#e11d48' },
  slate:  { c: '#64748b' },
  dark:   { c: '#475569' },
};
const tint = (c, pct) => `color-mix(in srgb, ${c} ${pct}%, transparent)`;

// LYD money — formatted with Arabic digits + currency suffix.
function money(v, lang) {
  const neg = v < 0;
  const s = num(Math.abs(v), lang);
  return (neg ? '−' : '') + s;
}
function Money({ v, lang, className, strong }) {
  return (
    <span className={`whitespace-nowrap font-mono tabular-nums ${strong ? 'font-bold' : ''} ${className || ''}`} style={{ direction: 'ltr', display: 'inline-block' }}>
      {money(v, lang)} <span className="text-[0.82em] font-medium text-slate-400">{lang === 'ar' ? 'د.ل' : 'LYD'}</span>
    </span>
  );
}

// Live presence pill — online / on_order / offline.
function PresencePill({ activity, lang, size }) {
  const meta = PRESENCE[activity] || PRESENCE.offline;
  const s = SOFT[meta.tone];
  const sm = size === 'sm';
  const pulse = activity !== 'offline';
  return (
    <span className="inline-flex items-center gap-1.5 rounded-full font-semibold whitespace-nowrap"
      style={{ background: tint(s.c, 16), color: s.c, padding: sm ? '2px 9px 2px 8px' : '3px 11px 3px 9px', fontSize: sm ? '11.5px' : '12.5px' }}>
      <span className="relative grid place-items-center" style={{ width: 8, height: 8 }}>
        {pulse && <span className="absolute inset-0 rounded-full opacity-40" style={{ background: meta.dot, animation: 'pinBob 1.6s ease-in-out infinite' }} />}
        <span className="rounded-full" style={{ width: 7, height: 7, background: meta.dot }} />
      </span>
      {tt(meta, lang)}
    </span>
  );
}

// Lifecycle badge — shown only when the driver is NOT plainly active.
const LIFECYCLE_ICON = {
  pending_approval: 'clock', suspended: 'pause', suspended_unpaid: 'alert',
  banned: 'ban', rejected: 'xCircle', active: 'check',
};
function LifecycleBadge({ lifecycle, lang, force }) {
  if (lifecycle === 'active' && !force) return null;
  const meta = LIFECYCLE[lifecycle] || LIFECYCLE.active;
  const s = SOFT[meta.tone];
  return (
    <span className="inline-flex items-center gap-1 rounded-md px-1.5 py-[3px] text-[11px] font-semibold whitespace-nowrap"
      style={{ background: tint(s.c, 16), color: s.c }}>
      <Icon name={LIFECYCLE_ICON[lifecycle] || 'dot'} size={12} strokeWidth={2.4} />
      {tt(meta, lang)}
    </span>
  );
}

// Compact rating — star glyph + number.
function Rating({ value, lang, size }) {
  const s = size || 13;
  return (
    <span className="inline-flex items-center gap-1 font-semibold tabular-nums" style={{ fontSize: s }}>
      <span style={{ color: '#f59e0b' }}><Icon name="star" size={s + 2} strokeWidth={1.8} /></span>
      <span className="text-slate-700">{num(value.toFixed(1), lang)}</span>
    </span>
  );
}

// Vehicle chip with the right icon.
function VehicleChip({ type, lang, plate }) {
  const v = VEHICLES[type] || VEHICLES.motorcycle;
  return (
    <span className="inline-flex items-center gap-1.5 text-[12.5px] text-slate-500">
      <span className="text-slate-400"><Icon name={v.icon} size={16} /></span>
      <span className="text-slate-600">{tt(v, lang)}</span>
      {plate && <span className="font-mono text-[11.5px] text-slate-400" style={{ direction: 'ltr' }}>· {plate}</span>}
    </span>
  );
}

// COD-held cell with a tiny liability meter (cash vs ceiling).
function CodCell({ d, lang }) {
  const a = acct(d);
  const danger = a.pct >= 80;
  const bar = danger ? '#e11d48' : a.pct >= 50 ? '#d97706' : '#16a34a';
  if (a.cash === 0) return <span className="text-[13px] text-slate-300">—</span>;
  return (
    <div className="min-w-[92px]">
      <Money v={a.cash} lang={lang} className="text-[13px] text-slate-800" />
      <div className="mt-1 flex items-center gap-1.5">
        <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100">
          <span className="block h-full rounded-full" style={{ width: `${a.pct}%`, background: bar }} />
        </span>
        {a.atCeiling && <span className="text-[10px] font-bold uppercase tracking-wide text-rose-600">{lang === 'ar' ? 'سقف' : 'cap'}</span>}
      </div>
    </div>
  );
}

// Account-status chip (user-side) — distinct from the driver profile badge.
function AccountChip({ status, lang }) {
  const meta = ACCOUNT_STATUS[status] || ACCOUNT_STATUS.active;
  const s = SOFT[meta.tone] || SOFT.slate;
  return (
    <span className="inline-flex items-center gap-1 rounded-md px-2 py-[3px] text-[11.5px] font-semibold whitespace-nowrap"
      style={{ background: tint(s.c, 15), color: s.c }}>
      {tt(meta, lang)}
    </span>
  );
}

// Merchant-status chip (merchant_profiles.status) — independent from account.
function MerchantChip({ status, lang, withIcon }) {
  const meta = MERCHANT_STATUS[status] || MERCHANT_STATUS.active;
  const s = SOFT[meta.tone] || SOFT.slate;
  const icon = { active: 'merchants', suspended: 'pause', banned: 'ban' }[status];
  return (
    <span className="inline-flex items-center gap-1 rounded-md px-2 py-[3px] text-[11.5px] font-semibold whitespace-nowrap"
      style={{ background: tint(s.c, 15), color: s.c }}>
      {withIcon && icon && <Icon name={icon} size={12} strokeWidth={2.2} />}
      {tt(meta, lang)}
    </span>
  );
}

Object.assign(window, { SOFT, tint, money, Money, PresencePill, LifecycleBadge, AccountChip, MerchantChip, Rating, VehicleChip, CodCell });
