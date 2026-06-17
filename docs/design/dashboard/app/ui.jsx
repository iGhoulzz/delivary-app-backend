// ui.jsx — shared presentational atoms used across pages.

// Timeline / toast dot tones (semantic).
const TONES = {
  amber:  { dot: '#d97706', cls: 'bg-amber-50 text-amber-700 ring-amber-600/20' },
  blue:   { dot: '#2563eb', cls: 'bg-blue-50 text-blue-700 ring-blue-600/20' },
  violet: { dot: '#7c3aed', cls: 'bg-violet-50 text-violet-700 ring-violet-600/20' },
  green:  { dot: '#059669', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' },
  red:    { dot: '#e11d48', cls: 'bg-rose-50 text-rose-700 ring-rose-600/20' },
  slate:  { dot: '#64748b', cls: 'bg-slate-100 text-slate-600 ring-slate-500/20' },
};

// Bespoke solid status chips — each carries an icon + deliberate hue, so they read
// as a real product rather than default pastel pills.
const STATUS_STYLE = {
  pending:    { bg: '#F0A92B', fg: '#43300a', icon: 'clock' },
  assigned:   { bg: '#3D7DF0', fg: '#ffffff', icon: 'route' },
  in_transit: { bg: '#7E57E6', fg: '#ffffff', icon: 'truck' },
  delivered:  { bg: '#15A06B', fg: '#ffffff', icon: 'check' },
  failed:     { bg: '#EF5160', fg: '#ffffff', icon: 'flag' },
  cancelled:  { bg: '#E3E8F0', fg: '#5b6677', icon: 'close' },
};

const TYPE_STYLE = {
  standard: '#64748b',
  p2p:      '#6366f1',
  merchant: '#0d9488',
};

function StatusPill({ status, lang, size }) {
  const meta = STATUS[status];
  const st = STATUS_STYLE[status];
  const sm = size === 'sm';
  return (
    <span className="status-pill inline-flex items-center gap-1 rounded-full font-semibold whitespace-nowrap"
      style={{ background: st.bg, color: st.fg, padding: sm ? '2px 9px 2px 7px' : '3px 11px 3px 9px', fontSize: sm ? '11.5px' : '12.5px' }}>
      <Icon name={st.icon} size={sm ? 12 : 13} strokeWidth={2.6} />
      {tt(meta, lang)}
    </span>
  );
}

function TypeTag({ type, lang }) {
  return (
    <span className="inline-flex items-center gap-1.5 rounded-md border border-slate-200 px-2 py-[3px] text-[11.5px] font-medium text-slate-600">
      <span className="h-1.5 w-1.5 rounded-[2px]" style={{ background: TYPE_STYLE[type] }} />
      {tt(TYPES[type], lang)}
    </span>
  );
}

function initials(name, lang) {
  const s = tt(name, lang).trim();
  const parts = s.split(/\s+/);
  if (lang === 'ar') return parts[0]?.[0] || '؟';
  return ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase() || '?';
}

function Avatar({ name, lang, size, tone }) {
  const d = size || 34;
  const palette = ['#1e40af', '#0e7490', '#7c3aed', '#b45309', '#be123c', '#047857'];
  const idx = (tt(name, 'en').charCodeAt(0) || 0) % palette.length;
  const bg = tone || palette[idx];
  return (
    <span className="inline-flex shrink-0 items-center justify-center rounded-full font-semibold text-white"
      style={{ width: d, height: d, background: bg, fontSize: d * 0.4 }}>
      {initials(name, lang)}
    </span>
  );
}

function Card({ children, className }) {
  return <div className={`app-card rounded-xl border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)] ${className || ''}`}>{children}</div>;
}

function IconBtn({ name, onClick, label, active }) {
  return (
    <button onClick={onClick} aria-label={label} title={label}
      className={`inline-flex h-9 w-9 items-center justify-center rounded-lg border transition
        ${active ? 'border-slate-300 bg-slate-100 text-slate-900' : 'border-transparent text-slate-500 hover:bg-slate-100 hover:text-slate-800'}`}>
      <Icon name={name} size={19} />
    </button>
  );
}

function Toast({ toast, lang }) {
  if (!toast) return null;
  const tone = TONES[toast.tone || 'slate'];
  return (
    <div className="pointer-events-none fixed inset-x-0 bottom-6 z-[60] flex justify-center px-4">
      <div className="pointer-events-auto flex items-center gap-2.5 rounded-xl bg-slate-900 px-4 py-3 text-sm font-medium text-white shadow-lg ring-1 ring-black/10">
        <span className="h-2 w-2 rounded-full" style={{ background: tone.dot }} />
        {tt(toast.msg, lang)}
      </div>
    </div>
  );
}

Object.assign(window, { StatusPill, TypeTag, Avatar, Card, IconBtn, Toast, initials, TONES, STATUS_STYLE });
