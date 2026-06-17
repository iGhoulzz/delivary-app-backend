// orders.jsx — filterable orders table.

function FilterChip({ active, onClick, children }) {
  return (
    <button onClick={onClick}
      className={`h-9 rounded-lg px-3 text-[13px] font-medium transition
        ${active ? 'text-white shadow-sm' : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50'}`}
      style={active ? { background: 'var(--accent)' } : undefined}>
      {children}
    </button>
  );
}

function Orders({ lang, orders, onOpen, openId }) {
  const [q, setQ] = React.useState('');
  const [status, setStatus] = React.useState('all');
  const [type, setType] = React.useState('all');

  const filtered = orders.filter((o) => {
    if (status !== 'all' && o.status !== status) return false;
    if (type !== 'all' && o.type !== type) return false;
    if (q.trim()) {
      const hay = [o.id, tt(o.sender, lang), tt(o.receiver, lang), tt(o.sender, 'en'), tt(o.receiver, 'en')].join(' ').toLowerCase();
      if (!hay.includes(q.trim().toLowerCase())) return false;
    }
    return true;
  });

  const statusOpts = [['all', { ar: 'الكل', en: 'All' }], ...Object.keys(STATUS).map((k) => [k, STATUS[k]])];
  const typeOpts = [['all', { ar: 'كل الأنواع', en: 'All types' }], ...Object.keys(TYPES).map((k) => [k, TYPES[k]])];

  return (
    <div className="mx-auto max-w-[1400px] p-5 lg:p-7">
      {/* filter bar */}
      <div className="mb-4 flex flex-wrap items-center gap-2.5">
        <div className="relative min-w-[220px] flex-1">
          <div className="pointer-events-none absolute inset-y-0 start-3 flex items-center text-slate-400"><Icon name="search" size={18} /></div>
          <input value={q} onChange={(e) => setQ(e.target.value)}
            placeholder={lang === 'ar' ? 'ابحث برقم الطلب أو الاسم…' : 'Search by order # or name…'}
            className="h-10 w-full rounded-lg border border-slate-200 bg-white ps-10 pe-3 text-[14px] text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-slate-300 focus:ring-2 focus:ring-[var(--accent-soft)]" />
        </div>
        <Dropdown lang={lang} value={status} setValue={setStatus} options={statusOpts} icon="filter"
          label={lang === 'ar' ? 'الحالة' : 'Status'} />
        <div className="hidden items-center gap-1.5 sm:flex">
          {typeOpts.map(([k, v]) => (
            <FilterChip key={k} active={type === k} onClick={() => setType(k)}>{tt(v, lang)}</FilterChip>
          ))}
        </div>
        <div className="ms-auto flex items-center gap-2">
          <span className="text-[13px] text-slate-400 tabular-nums">{num(filtered.length, lang)} {lang === 'ar' ? 'طلب' : 'orders'}</span>
          <button className="inline-flex h-10 items-center gap-2 rounded-lg px-3.5 text-[13.5px] font-semibold text-white shadow-sm" style={{ background: 'var(--accent)' }}>
            <Icon name="plus" size={17} strokeWidth={2.2} />{lang === 'ar' ? 'طلب جديد' : 'New order'}
          </button>
        </div>
      </div>

      <Card className="overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[720px] border-collapse text-start">
            <thead>
              <tr className="border-b border-slate-200/80 bg-slate-50/60 text-[12px] font-semibold uppercase tracking-wide text-slate-400">
                <Th>{lang === 'ar' ? 'رقم الطلب' : 'Order #'}</Th>
                <Th>{lang === 'ar' ? 'النوع' : 'Type'}</Th>
                <Th>{lang === 'ar' ? 'الحالة' : 'Status'}</Th>
                <Th>{lang === 'ar' ? 'المرسِل' : 'Sender'}</Th>
                <Th>{lang === 'ar' ? 'المستلِم' : 'Receiver'}</Th>
                <Th>{lang === 'ar' ? 'السائق' : 'Driver'}</Th>
                <Th className="hidden lg:table-cell">{lang === 'ar' ? 'أُنشئ' : 'Created'}</Th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {filtered.map((o) => (
                <tr key={o.id} onClick={() => onOpen(o.id)}
                  className={`cursor-pointer text-[13.5px] transition hover:bg-slate-50/70 ${openId === o.id ? 'bg-[var(--accent-soft)]' : ''}`}>
                  <Td><span className="font-mono text-[13px] font-medium text-slate-800">{o.id}</span></Td>
                  <Td><TypeTag type={o.type} lang={lang} /></Td>
                  <Td><StatusPill status={o.status} lang={lang} size="sm" /></Td>
                  <Td>
                    <div className="font-medium text-slate-800">{tt(o.sender, lang)}</div>
                    <div className="text-[12px] text-slate-400">{tt(o.senderDist, lang)}</div>
                  </Td>
                  <Td>
                    <div className="font-medium text-slate-800">{tt(o.receiver, lang)}</div>
                    <div className="text-[12px] text-slate-400">{tt(o.receiverDist, lang)}</div>
                  </Td>
                  <Td>
                    {o.driver ? (
                      <div className="flex items-center gap-2">
                        <Avatar name={o.driver} lang={lang} size={26} />
                        <span className="text-slate-700">{tt(o.driver, lang)}</span>
                      </div>
                    ) : <span className="text-[12.5px] italic text-slate-400">{lang === 'ar' ? 'غير مُسند' : 'Unassigned'}</span>}
                  </Td>
                  <Td className="hidden lg:table-cell"><span className="font-mono text-[12.5px] text-slate-500" style={{ direction: 'ltr', display: 'inline-block' }}>{o.created}</span></Td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {filtered.length === 0 && (
          <div className="px-5 py-16 text-center text-[14px] text-slate-400">{lang === 'ar' ? 'لا توجد طلبات مطابقة' : 'No matching orders'}</div>
        )}
      </Card>
    </div>
  );
}

function Th({ children, className }) { return <th className={`whitespace-nowrap px-5 py-3 text-start ${className || ''}`}>{children}</th>; }
function Td({ children, className }) { return <td className={`px-5 py-3.5 align-middle ${className || ''}`}>{children}</td>; }

function Dropdown({ lang, value, setValue, options, icon, label }) {
  const [open, setOpen] = React.useState(false);
  const ref = React.useRef(null);
  React.useEffect(() => {
    const h = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', h);
    return () => document.removeEventListener('mousedown', h);
  }, []);
  const current = options.find(([k]) => k === value)?.[1];
  return (
    <div className="relative" ref={ref}>
      <button onClick={() => setOpen(!open)}
        className="inline-flex h-10 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-[13.5px] font-medium text-slate-600 transition hover:bg-slate-50">
        <Icon name={icon} size={16} />
        <span className="text-slate-400">{label}:</span>
        <span className="text-slate-800">{tt(current, lang)}</span>
        <Icon name="chevronD" size={15} />
      </button>
      {open && (
        <div className="absolute z-30 mt-1.5 max-h-72 w-52 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg"
          style={{ insetInlineStart: 0 }}>
          {options.map(([k, v]) => (
            <button key={k} onClick={() => { setValue(k); setOpen(false); }}
              className={`flex w-full items-center justify-between rounded-lg px-2.5 py-2 text-[13.5px] transition hover:bg-slate-50
                ${value === k ? 'font-semibold text-slate-900' : 'text-slate-600'}`}>
              {tt(v, lang)}
              {value === k && <span style={{ color: 'var(--accent)' }}><Icon name="check" size={16} /></span>}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

Object.assign(window, { Orders, Th, Td, Dropdown, FilterChip });
