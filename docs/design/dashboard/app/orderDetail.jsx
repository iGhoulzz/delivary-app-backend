// orderDetail.jsx — sliding order detail drawer with timeline, parties, pricing, actions.

function OrderDrawer({ orderId, orders, lang, onClose, onAction }) {
  const [assigning, setAssigning] = React.useState(false);
  const order = orders.find((o) => o.id === orderId) || null;

  React.useEffect(() => { setAssigning(false); }, [orderId]);

  React.useEffect(() => {
    const h = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', h);
    return () => document.removeEventListener('keydown', h);
  }, [onClose]);

  if (!orderId || !order) return null;
  const terminal = ['delivered', 'failed', 'cancelled'].includes(order.status);
  const idleDrivers = DRIVERS.filter((d) => d.status !== 'offline');

  return (
    <div className="fixed inset-0 z-50">
      <div onClick={onClose}
        className="absolute inset-0 bg-slate-900/30 backdrop-blur-[1px]"
        style={{ animation: 'drawerFade .32s ease both' }} />
      <div
        className="absolute inset-y-0 end-0 flex w-full max-w-[480px] flex-col bg-white shadow-2xl"
        style={{ animation: (lang === 'ar' ? 'drawerInRtl' : 'drawerInLtr') + ' .34s cubic-bezier(.22,1,.36,1) both' }}>

        {/* header */}
        <div className="flex items-start justify-between gap-3 border-b border-slate-200/80 px-6 py-5">
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2.5">
              <span className="whitespace-nowrap font-mono text-[18px] font-bold tracking-tight text-slate-900" style={{ direction: 'ltr' }}>{order.id}</span>
              <TypeTag type={order.type} lang={lang} />
            </div>
            <div className="mt-2"><StatusPill status={order.status} lang={lang} /></div>
          </div>
          <button onClick={onClose} className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">
            <Icon name="close" size={20} />
          </button>
        </div>

        <div className="flex-1 overflow-y-auto px-6 py-5">
          {/* parties */}
          <div className="grid grid-cols-1 gap-3">
            <PartyRow label={lang === 'ar' ? 'المرسِل' : 'Sender'} name={order.sender} dist={order.senderDist} lang={lang} tone="var(--accent)" />
            <div className="flex justify-center"><span className="text-slate-300"><Icon name={lang === 'ar' ? 'chevronD' : 'chevronD'} size={18} /></span></div>
            <PartyRow label={lang === 'ar' ? 'المستلِم' : 'Receiver'} name={order.receiver} dist={order.receiverDist} lang={lang} tone="#047857" />
          </div>

          {/* driver */}
          <div className="mt-5 rounded-xl border border-slate-200/80 bg-slate-50/60 p-4">
            <div className="mb-1 text-[12px] font-semibold uppercase tracking-wide text-slate-400">{lang === 'ar' ? 'السائق' : 'Driver'}</div>
            {order.driver ? (
              <div className="flex items-center gap-3">
                <Avatar name={order.driver} lang={lang} size={38} />
                <div className="flex-1">
                  <div className="text-[14px] font-semibold text-slate-800">{tt(order.driver, lang)}</div>
                  <div className="text-[12px] text-slate-400">{lang === 'ar' ? 'مُسند لهذا الطلب' : 'Assigned to this order'}</div>
                </div>
                <button className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-white hover:text-slate-700"><Icon name="phone" size={16} /></button>
              </div>
            ) : (
              <div className="text-[13.5px] italic text-slate-400">{lang === 'ar' ? 'لم يُسند سائق بعد' : 'No driver assigned yet'}</div>
            )}
          </div>

          {/* pricing */}
          <div className="mt-5">
            <div className="mb-2 text-[12px] font-semibold uppercase tracking-wide text-slate-400">{lang === 'ar' ? 'تفصيل التسعير' : 'Pricing breakdown'}</div>
            <div className="rounded-xl border border-slate-200/80">
              <PriceRow label={lang === 'ar' ? 'الأساسي' : 'Base fare'} v={order.price.base} lang={lang} />
              <PriceRow label={lang === 'ar' ? 'المسافة' : 'Distance'} v={order.price.distance} lang={lang} />
              {order.price.surge > 0 && <PriceRow label={lang === 'ar' ? 'ذروة الطلب' : 'Surge'} v={order.price.surge} lang={lang} />}
              <div className="flex items-center justify-between px-4 py-3">
                <span className="text-[14px] font-semibold text-slate-900">{lang === 'ar' ? 'الإجمالي' : 'Total'}</span>
                <span className="whitespace-nowrap font-mono text-[15px] font-bold" style={{ color: 'var(--accent)' }}>{num(order.price.total, lang)} <span className="text-[12px] font-medium text-slate-400">{lang === 'ar' ? 'د.ل' : 'LYD'}</span></span>
              </div>
              {order.cod > 0 && (
                <div className="flex items-center justify-between border-t border-dashed border-slate-200 bg-amber-50/50 px-4 py-2.5">
                  <span className="text-[13px] font-medium text-amber-700">{lang === 'ar' ? 'تحصيل عند التسليم' : 'Cash on delivery'}</span>
                  <span className="whitespace-nowrap font-mono text-[13.5px] font-semibold text-amber-700">{num(order.cod, lang)} {lang === 'ar' ? 'د.ل' : 'LYD'}</span>
                </div>
              )}
            </div>
          </div>

          {/* timeline */}
          <div className="mt-6">
            <div className="mb-3 text-[12px] font-semibold uppercase tracking-wide text-slate-400">{lang === 'ar' ? 'سجل الحالة' : 'Status history'}</div>
            <ol className="relative">
              {order.timeline.map((step, i) => {
                const meta = STATUS[step.s];
                const tone = TONES[meta.tone];
                const last = i === order.timeline.length - 1;
                return (
                  <li key={i} className="relative flex gap-3.5 pb-5 last:pb-0">
                    {!last && <span className="absolute top-5 h-full w-px bg-slate-200" style={{ insetInlineStart: '8.5px' }} />}
                    <span className="relative z-10 mt-0.5 h-[18px] w-[18px] shrink-0 rounded-full ring-4 ring-white" style={{ background: tone.dot }} />
                    <div className="-mt-0.5 flex-1">
                      <div className="flex items-center justify-between">
                        <span className="text-[13.5px] font-semibold text-slate-800">{tt(meta, lang)}</span>
                        <span className="font-mono text-[12px] text-slate-400" style={{ direction: 'ltr' }}>{step.at}</span>
                      </div>
                      <div className="text-[12.5px] text-slate-500">{tt(step.by, lang)}</div>
                      {step.note && <div className="mt-1 rounded-md bg-slate-50 px-2 py-1 text-[12px] text-slate-500">{tt(step.note, lang)}</div>}
                    </div>
                  </li>
                );
              })}
            </ol>
          </div>
        </div>

        {/* actions */}
        <div className="border-t border-slate-200/80 px-6 py-4">
          {assigning ? (
            <div>
              <div className="mb-2 flex items-center justify-between">
                <span className="text-[13px] font-semibold text-slate-700">{lang === 'ar' ? 'اختر سائقًا' : 'Choose a driver'}</span>
                <button onClick={() => setAssigning(false)} className="text-[12.5px] text-slate-400 hover:text-slate-600">{lang === 'ar' ? 'إلغاء' : 'Cancel'}</button>
              </div>
              <div className="max-h-44 space-y-1 overflow-y-auto">
                {idleDrivers.map((d) => (
                  <button key={d.id} onClick={() => { onAction('assign', order.id, d.name); setAssigning(false); }}
                    className="flex w-full items-center gap-2.5 rounded-lg border border-slate-200 px-3 py-2 text-start transition hover:border-slate-300 hover:bg-slate-50">
                    <Avatar name={d.name} lang={lang} size={30} />
                    <div className="flex-1">
                      <div className="text-[13px] font-semibold text-slate-800">{tt(d.name, lang)}</div>
                      <div className="text-[11.5px] text-slate-400">{tt(d.vehicle, lang)} · {num(d.orders, lang)} {lang === 'ar' ? 'طلب' : 'active'}</div>
                    </div>
                    <span className={`h-2 w-2 rounded-full ${d.status === 'idle' ? 'bg-slate-300' : 'bg-emerald-500'}`} />
                  </button>
                ))}
              </div>
            </div>
          ) : terminal ? (
            <div className="text-center text-[13px] text-slate-400">{lang === 'ar' ? 'لا توجد إجراءات متاحة لهذا الطلب' : 'No actions available for this order'}</div>
          ) : (
            <div className="flex flex-wrap gap-2">
              {!order.driver
                ? <ActBtn primary icon="route" onClick={() => setAssigning(true)}>{lang === 'ar' ? 'إسناد سائق' : 'Assign driver'}</ActBtn>
                : <ActBtn icon="route" onClick={() => setAssigning(true)}>{lang === 'ar' ? 'إعادة إسناد' : 'Reassign'}</ActBtn>}
              {order.driver && <ActBtn icon="flag" tone="amber" onClick={() => onAction('fail', order.id)}>{lang === 'ar' ? 'تعليم كفاشل' : 'Mark failed'}</ActBtn>}
              <ActBtn icon="xCircle" tone="red" onClick={() => onAction('cancel', order.id)}>{lang === 'ar' ? 'إلغاء الطلب' : 'Cancel'}</ActBtn>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

function PartyRow({ label, name, dist, lang, tone }) {
  return (
    <div className="flex items-center gap-3 rounded-xl border border-slate-200/80 p-3.5">
      <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full" style={{ background: tone + '18', color: tone }}><Icon name="pin" size={20} /></span>
      <div className="flex-1">
        <div className="text-[11.5px] font-semibold uppercase tracking-wide text-slate-400">{label}</div>
        <div className="text-[14.5px] font-semibold text-slate-800">{tt(name, lang)}</div>
        <div className="text-[12.5px] text-slate-400">{tt(dist, lang)}</div>
      </div>
    </div>
  );
}

function PriceRow({ label, v, lang }) {
  return (
    <div className="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
      <span className="text-[13px] text-slate-500">{label}</span>
      <span className="whitespace-nowrap font-mono text-[13px] text-slate-700">{num(v, lang)} <span className="text-[11px] text-slate-400">{lang === 'ar' ? 'د.ل' : 'LYD'}</span></span>
    </div>
  );
}

function ActBtn({ children, icon, onClick, primary, tone }) {
  let cls = 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50';
  if (tone === 'red') cls = 'border border-rose-200 bg-white text-rose-600 hover:bg-rose-50';
  if (tone === 'amber') cls = 'border border-amber-200 bg-white text-amber-700 hover:bg-amber-50';
  return (
    <button onClick={onClick}
      className={`inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-lg px-3 text-[13.5px] font-semibold transition ${primary ? 'text-white shadow-sm' : cls}`}
      style={primary ? { background: 'var(--accent)' } : undefined}>
      <Icon name={icon} size={17} />{children}
    </button>
  );
}

Object.assign(window, { OrderDrawer });
