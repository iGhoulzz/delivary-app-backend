// driverDetail.jsx — glass detail modal over the blurred page. Admin full control.

function Section({ title, icon, right, children, className }) {
  return (
    <div className={`rounded-2xl border border-slate-200/80 bg-white/70 ${className || ''}`}>
      <div className="flex items-center gap-2 px-4 pt-3.5 pb-2">
        {icon && <span className="text-slate-400"><Icon name={icon} size={16} /></span>}
        <span className="text-[12px] font-semibold uppercase tracking-wide text-slate-400">{title}</span>
        <div className="ms-auto">{right}</div>
      </div>
      <div className="px-4 pb-4">{children}</div>
    </div>
  );
}

function MiniBtn({ children, icon, onClick, tone, solid }) {
  let cls = 'border border-slate-200 bg-white/70 text-slate-600 hover:bg-slate-50';
  if (tone === 'red') cls = 'border border-rose-200 bg-white/70 text-rose-600 hover:bg-rose-50';
  if (tone === 'green') cls = 'border border-emerald-200 bg-white/70 text-emerald-700 hover:bg-emerald-50';
  if (tone === 'amber') cls = 'border border-amber-200 bg-white/70 text-amber-700 hover:bg-amber-50';
  if (solid) cls = 'text-white shadow-sm';
  return (
    <button onClick={onClick}
      className={`inline-flex h-9 items-center justify-center gap-1.5 rounded-lg px-3 text-[12.5px] font-semibold transition ${cls}`}
      style={solid ? { background: 'var(--accent)' } : undefined}>
      {icon && <Icon name={icon} size={15} />}{children}
    </button>
  );
}

function BucketCard({ bucket, value, lang, accent }) {
  const meta = BUCKETS[bucket];
  return (
    <div className="rounded-xl border border-slate-200/80 bg-white/60 px-3 py-2.5">
      <div className="text-[11px] font-medium text-slate-400">{tt(meta.short, lang)}</div>
      <div className="mt-0.5"><Money v={value} lang={lang} strong className={`text-[15px] ${accent || 'text-slate-800'}`} /></div>
    </div>
  );
}

function DriverModal({ driverId, drivers, lang, onClose, onAction, onOpenOrder }) {
  const [mode, setMode] = React.useState(null); // null | 'assign' | 'strike'
  const [strikeReason, setStrikeReason] = React.useState('manual_admin');
  const [strikeFee, setStrikeFee] = React.useState(0);
  const d = drivers.find((x) => x.id === driverId) || null;

  React.useEffect(() => { setMode(null); }, [driverId]);
  React.useEffect(() => {
    const h = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', h);
    return () => document.removeEventListener('keydown', h);
  }, [onClose]);

  if (!driverId || !d) return null;

  const a = acct(d);
  const loads = driverLoads(d);
  const history = ORDERS.filter((o) => o.driver && tt(o.driver, 'en') === tt(d.name, 'en') && ['delivered', 'failed', 'cancelled'].includes(o.status))
    .sort((x, y) => (x.created < y.created ? 1 : -1));
  const delivered = history.filter((o) => o.status === 'delivered').length;
  const strikes = d.strikes || [];
  const active = activeStrikes(d);
  const off = OFFICES.find((o) => o.id === d.office);
  const docList = Object.keys(DOC_TYPES);
  const verifiedCount = docList.filter((k) => d.docs[k] && d.docs[k].v).length;
  const pendingOrders = ORDERS.filter((o) => o.status === 'pending' && !o.driver);

  const isPending = d.profileStatus === 'pending_approval';
  const isSuspended = d.profileStatus === 'suspended';
  const isBanned = d.profileStatus === 'banned';
  const user = userById(d.userId);
  const custCount = user ? user.orders : customerOrders(d.name).length;

  const act = (kind, payload) => onAction(kind, d.id, payload);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6">
      <div onClick={onClose} className="absolute inset-0 bg-slate-900/40"
        style={{ animation: 'drawerFade .3s ease both', backdropFilter: 'blur(7px)', WebkitBackdropFilter: 'blur(7px)' }} />

      <div className="app-card relative flex max-h-[90vh] w-full max-w-[840px] flex-col overflow-hidden rounded-3xl border border-white/60 bg-white/85 shadow-2xl"
        style={{ animation: 'modalIn .34s cubic-bezier(.22,1,.36,1) both', backdropFilter: 'blur(24px) saturate(1.4)', WebkitBackdropFilter: 'blur(24px) saturate(1.4)' }}>

        {/* ── header ── */}
        <div className="relative shrink-0 border-b border-slate-200/70 px-6 pt-6 pb-5">
          <button onClick={onClose} className="absolute end-5 top-5 inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">
            <Icon name="close" size={20} />
          </button>

          <div className="flex items-start gap-4">
            <div className="relative">
              <Avatar name={d.name} lang={lang} size={60} />
              <span className="absolute -bottom-1 -end-1 h-5 w-5 rounded-full ring-[3px] ring-white grid place-items-center" style={{ background: (PRESENCE[d.activity] || PRESENCE.offline).dot }}>
                {d.activity === 'on_order' && <Icon name="box" size={11} className="text-white" />}
              </span>
            </div>
            <div className="min-w-0 flex-1 pe-8">
              <div className="flex flex-wrap items-center gap-2.5">
                <h2 className="text-[20px] font-bold tracking-tight text-slate-900">{tt(d.name, lang)}</h2>
                <PresencePill activity={d.activity} lang={lang} size="sm" />
                <LifecycleBadge lifecycle={d.profileStatus} lang={lang} />
              </div>
              <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-[12.5px] text-slate-500">
                <span className="font-mono text-slate-400" style={{ direction: 'ltr' }}>{d.id}</span>
                <span className="inline-flex items-center gap-1.5"><Icon name="phone" size={14} /><span className="font-mono" style={{ direction: 'ltr' }}>{d.phone}</span></span>
                <Rating value={d.rating} lang={lang} />
                <VehicleChip type={d.vehicle} lang={lang} plate={d.plate} />
                {off && <span className="inline-flex items-center gap-1.5"><Icon name="building" size={14} />{tt(off.district, lang)}</span>}
              </div>
            </div>
          </div>

          {/* action bar */}
          <div className="mt-4 flex flex-wrap items-center gap-2">
            {!isBanned && !isPending && (
              <MiniBtn icon="box" onClick={() => setMode(mode === 'assign' ? null : 'assign')}>{lang === 'ar' ? 'إسناد طلب' : 'Assign order'}</MiniBtn>
            )}

            <div className="ms-auto flex flex-wrap items-center gap-2">
              {d.activity !== 'offline' && !isBanned && (
                <MiniBtn icon="power" onClick={() => act('offline')}>{lang === 'ar' ? 'فصل' : 'Force offline'}</MiniBtn>
              )}
              {isPending && (
                <>
                  <MiniBtn icon="xCircle" tone="red" onClick={() => act('reject')}>{lang === 'ar' ? 'رفض' : 'Reject'}</MiniBtn>
                  <MiniBtn icon="checkCircle" solid onClick={() => act('approve')}>{lang === 'ar' ? 'الموافقة' : 'Approve'}</MiniBtn>
                </>
              )}
              {d.profileStatus === 'active' && (
                <>
                  <MiniBtn icon="pause" tone="amber" onClick={() => act('suspend')}>{lang === 'ar' ? 'إيقاف' : 'Suspend'}</MiniBtn>
                  <MiniBtn icon="ban" tone="red" onClick={() => act('ban')}>{lang === 'ar' ? 'حظر' : 'Ban'}</MiniBtn>
                </>
              )}
              {isSuspended && (
                <MiniBtn icon="power" tone="green" onClick={() => act('reactivate')}>{lang === 'ar' ? 'إعادة تفعيل' : 'Reactivate'}</MiniBtn>
              )}
              {isBanned && (
                <MiniBtn icon="undo" onClick={() => act('reinstate')}>{lang === 'ar' ? 'رفع الحظر' : 'Reinstate'}</MiniBtn>
              )}
            </div>
          </div>

          {/* inline assign-order */}
          {mode === 'assign' && (
            <div className="mt-3 rounded-xl border border-slate-200 bg-white/80 p-3">
              <div className="mb-2 text-[12px] font-semibold text-slate-600">{lang === 'ar' ? 'طلبات بانتظار الإسناد' : 'Orders awaiting assignment'}</div>
              {pendingOrders.length ? (
                <div className="flex flex-col gap-1.5">
                  {pendingOrders.map((o) => (
                    <button key={o.id} onClick={() => { act('assign_order', o.id); setMode(null); }}
                      className="flex items-center gap-3 rounded-lg border border-slate-200 px-3 py-2 text-start transition hover:border-slate-300 hover:bg-slate-50">
                      <span className="font-mono text-[12.5px] font-semibold text-slate-800" style={{ direction: 'ltr' }}>{o.id}</span>
                      <span className="truncate text-[12.5px] text-slate-500">{tt(o.sender, lang)} → {tt(o.receiver, lang)}</span>
                      <span className="ms-auto"><StatusPill status={o.status} lang={lang} size="sm" /></span>
                    </button>
                  ))}
                </div>
              ) : <div className="text-[12.5px] italic text-slate-400">{lang === 'ar' ? 'لا توجد طلبات معلّقة' : 'No pending orders'}</div>}
            </div>
          )}
        </div>

        {/* ── body ── */}
        <div className="grid flex-1 grid-cols-1 gap-4 overflow-y-auto p-5 lg:grid-cols-2">

          {/* User account & roles — a driver is also a normal app user */}
          <Section title={lang === 'ar' ? 'الحساب والأدوار' : 'Account & roles'} icon="user" className="lg:col-span-2"
            right={<span className="font-mono text-[11px] text-slate-400" style={{ direction: 'ltr' }}>{d.userId}</span>}>
            <div className="flex flex-wrap items-center gap-x-6 gap-y-2.5">
              <div className="flex items-center gap-2">
                <span className="text-[11.5px] text-slate-400">{lang === 'ar' ? 'الأدوار' : 'Roles'}</span>
                <span className="inline-flex items-center gap-1 rounded-md px-2 py-[3px] text-[11.5px] font-semibold" style={{ background: tint('#2563eb', 14), color: '#2563eb' }}><Icon name="drivers" size={13} />{lang === 'ar' ? 'سائق' : 'Driver'}</span>
                <span className="inline-flex items-center gap-1 rounded-md px-2 py-[3px] text-[11.5px] font-semibold" style={{ background: tint('#64748b', 14), color: '#64748b' }}><Icon name="user" size={13} />{lang === 'ar' ? 'عميل' : 'Customer'}</span>
              </div>
              <div className="flex items-center gap-2">
                <span className="text-[11.5px] text-slate-400">{lang === 'ar' ? 'حالة الحساب' : 'Account'}</span>
                <AccountChip status={d.accountStatus} lang={lang} />
              </div>
              <div className="flex items-center gap-2">
                <span className="text-[11.5px] text-slate-400">{lang === 'ar' ? 'طلبات كعميل' : 'As customer'}</span>
                <span className="font-semibold text-slate-700 tabular-nums">{num(custCount, lang)} <span className="text-[11.5px] font-normal text-slate-400">{lang === 'ar' ? 'طلب' : 'orders'}</span></span>
              </div>
            </div>
            <div className="mt-2.5 flex items-start gap-1.5 text-[11.5px] leading-relaxed text-slate-400">
              <span className="mt-px shrink-0"><Icon name="overview" size={13} /></span>
              <span>{lang === 'ar' ? 'يستخدم هذا الشخص التطبيق كعميل أيضًا — ويبدّل بين وضع العميل ووضع السائق داخل التطبيق. حالة الحساب وحالة ملف السائق مستقلتان.' : 'This person also uses the app as a customer, toggling between customer and driver mode. Account status and driver-profile status are tracked separately.'}</span>
            </div>
          </Section>

          {/* Active loads */}
          <Section title={lang === 'ar' ? 'الحمولة النشطة' : 'Active load'} icon="box"
            right={<span className="rounded-full px-2 py-0.5 text-[11px] font-bold tabular-nums" style={{ background: tint('#7c3aed', 16), color: '#7c3aed' }}>{num(loads.length, lang)}</span>}>
            {loads.length ? (
              <div className="flex flex-col gap-2">
                {loads.map((o) => (
                  <button key={o.id} onClick={() => onOpenOrder && onOpenOrder(o.id)} className="flex w-full items-center gap-3 rounded-lg border border-slate-200/80 bg-white/60 px-3 py-2 text-start transition hover:border-slate-300 hover:bg-slate-50">
                    <span className="font-mono text-[12.5px] font-semibold text-slate-800" style={{ direction: 'ltr' }}>{o.id}</span>
                    <span className="truncate text-[12px] text-slate-500">{tt(o.receiverDist, lang)}</span>
                    <span className="ms-auto flex items-center gap-1.5"><StatusPill status={o.status} lang={lang} size="sm" /><span className="text-slate-300"><Icon name={lang === 'ar' ? 'chevronL' : 'chevronR'} size={15} /></span></span>
                  </button>
                ))}
              </div>
            ) : <div className="py-2 text-[13px] text-slate-400">{lang === 'ar' ? 'لا توجد طلبات بحوزة السائق حاليًا.' : 'No orders in hand right now.'}</div>}
          </Section>

          {/* Performance */}
          <Section title={lang === 'ar' ? 'الأداء' : 'Performance'} icon="overview">
            <div className="grid grid-cols-3 gap-2 text-center">
              <Stat label={lang === 'ar' ? 'اليوم' : 'Today'} value={num(d.deliveriesToday, lang)} />
              <Stat label={lang === 'ar' ? 'الإجمالي' : 'Lifetime'} value={num(d.lifetimeDeliveries.toLocaleString('en-US'), lang)} />
              <Stat label={lang === 'ar' ? 'التقييم' : 'Rating'} value={num(d.rating.toFixed(1), lang)} accent="#f59e0b" />
            </div>
            <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[12px] text-slate-500">
              <span className="inline-flex items-center gap-1.5"><Icon name="calendar" size={14} />{lang === 'ar' ? 'انضمّ' : 'Joined'} <span className="font-mono" style={{ direction: 'ltr' }}>{d.joined}</span></span>
              <span className="inline-flex items-center gap-1.5"><Icon name="clock" size={14} />{lang === 'ar' ? 'آخر نشاط' : 'Last active'} {tt(d.lastActive, lang)}</span>
            </div>
            {d.regions && d.regions.length > 0 && (
              <div className="mt-2.5 flex flex-wrap items-center gap-1.5">
                <span className="text-[11.5px] text-slate-400">{lang === 'ar' ? 'مناطق الخدمة:' : 'Serves:'}</span>
                {d.regions.map((r, i) => (
                  <span key={i} className="rounded-md bg-slate-100 px-2 py-0.5 text-[11.5px] font-medium text-slate-600">{tt(r, lang)}</span>
                ))}
              </div>
            )}
          </Section>

          {/* Order history */}
          <Section title={lang === 'ar' ? 'سجل الطلبات' : 'Order history'} icon="history" className="lg:col-span-2"
            right={history.length ? <span className="text-[11.5px] text-slate-400">{num(delivered, lang)} {lang === 'ar' ? 'مُسلّم من' : 'delivered of'} {num(history.length, lang)}</span> : null}>
            {history.length ? (
              <div className="max-h-56 space-y-1.5 overflow-y-auto pe-1">
                {history.map((o) => (
                  <button key={o.id} onClick={() => onOpenOrder && onOpenOrder(o.id)} className="flex w-full items-center gap-3 rounded-lg border border-slate-200/80 bg-white/60 px-3 py-2 text-start transition hover:border-slate-300 hover:bg-slate-50">
                    <span className="font-mono text-[12.5px] font-semibold text-slate-800" style={{ direction: 'ltr' }}>{o.id}</span>
                    <span className="min-w-0 flex-1 truncate text-[12px] text-slate-500">{tt(o.senderDist, lang)} <span className="text-slate-300">→</span> {tt(o.receiverDist, lang)}</span>
                    {o.cod > 0 && <span className="hidden sm:inline"><Money v={o.cod} lang={lang} className="text-[11.5px] text-amber-600" /></span>}
                    <StatusPill status={o.status} lang={lang} size="sm" />
                    <span className="hidden font-mono text-[11px] text-slate-400 md:inline" style={{ direction: 'ltr' }}>{o.created.slice(5, 10)}</span>
                  </button>
                ))}
              </div>
            ) : <div className="py-2 text-[13px] text-slate-400">{lang === 'ar' ? 'لا توجد طلبات مكتملة لهذا السائق بعد.' : 'No completed orders on record yet.'}</div>}
          </Section>

          {/* Finance */}
          <Section title={lang === 'ar' ? 'الحساب المالي' : 'Finance'} icon="wallet" className="lg:col-span-2">
            <div className="grid grid-cols-3 gap-2.5">
              <BucketCard bucket="cash_to_deposit" value={a.cash} lang={lang} accent="text-slate-900" />
              <BucketCard bucket="earnings_balance" value={a.earnings} lang={lang} accent="text-emerald-600" />
              <BucketCard bucket="debt_balance" value={a.debt} lang={lang} accent={a.debt > 0 ? 'text-rose-600' : 'text-slate-800'} />
            </div>

            {/* liability meter */}
            <div className="mt-3 rounded-xl border border-slate-200/80 bg-white/60 px-3.5 py-3">
              <div className="flex items-center justify-between text-[12px]">
                <span className="font-medium text-slate-500">{lang === 'ar' ? 'سقف الحيازة النقدية' : 'Cash liability ceiling'}</span>
                <span className="font-mono text-slate-600" style={{ direction: 'ltr' }}>{num(a.cash, lang)} / {num(a.ceiling, lang)}</span>
              </div>
              <div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                <span className="block h-full rounded-full transition-all" style={{ width: `${a.pct}%`, background: a.pct >= 80 ? '#e11d48' : a.pct >= 50 ? '#d97706' : '#16a34a' }} />
              </div>
              <div className="mt-1.5 flex items-center justify-between text-[11.5px]">
                <span className={a.atCeiling ? 'font-semibold text-rose-600' : 'text-slate-400'}>
                  {a.atCeiling ? (lang === 'ar' ? 'بلغ السقف — التوصيل محظور' : 'At ceiling — delivery blocked') : (lang === 'ar' ? `متبقٍ ${num(a.remaining, lang)} د.ل` : `${num(a.remaining, lang)} LYD headroom`)}
                </span>
                <span className="text-slate-400">{lang === 'ar' ? 'صافي التسوية' : 'Settlement net'} <span className="font-mono font-semibold text-slate-600" style={{ direction: 'ltr' }}>{money(a.settlementNet, lang)}</span></span>
              </div>
            </div>

            {/* finance actions */}
            <div className="mt-3 flex flex-wrap gap-2">
              {a.cash > 0 && <MiniBtn icon="coins" solid onClick={() => act('settle')}>{lang === 'ar' ? `تسوية ${num(a.cash, lang)} د.ل` : `Settle ${num(a.cash, lang)} LYD`}</MiniBtn>}
              {a.earnings > 0 && <MiniBtn icon="send" tone="green" onClick={() => act('payout')}>{lang === 'ar' ? `صرف ${num(a.earnings, lang)} د.ل` : `Pay out ${num(a.earnings, lang)} LYD`}</MiniBtn>}
              <MiniBtn icon="edit" onClick={() => act('adjust')}>{lang === 'ar' ? 'تعديل يدوي' : 'Adjust'}</MiniBtn>
            </div>

            {/* ledger */}
            {d.ledger && d.ledger.length > 0 && (
              <div className="mt-3">
                <div className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{lang === 'ar' ? 'آخر الحركات' : 'Recent ledger'}</div>
                <div className="max-h-40 space-y-1 overflow-y-auto pe-1">
                  {d.ledger.map((row, i) => {
                    const credit = row.amount >= 0;
                    return (
                      <div key={i} className="flex items-center gap-2 rounded-lg bg-white/50 px-2.5 py-1.5 text-[12px]">
                        <span className="text-slate-600">{tt(LEDGER_REASONS[row.reason], lang)}</span>
                        <span className="rounded bg-slate-100 px-1.5 py-px text-[10px] font-medium text-slate-400">{tt(BUCKETS[row.bucket].short, lang)}</span>
                        <span className="ms-auto font-mono tabular-nums" style={{ direction: 'ltr', color: credit ? '#059669' : '#e11d48' }}>{credit ? '+' : '−'}{num(Math.abs(row.amount), lang)}</span>
                        <span className="w-7 text-end text-[11px] text-slate-300" style={{ direction: 'ltr' }}>{row.ago}</span>
                      </div>
                    );
                  })}
                </div>
              </div>
            )}
          </Section>

          {/* Strikes */}
          <Section title={lang === 'ar' ? 'المخالفات' : 'Strikes'} icon="alert"
            right={
              <span className="rounded-full px-2 py-0.5 text-[11px] font-bold tabular-nums" style={active.length ? { background: tint('#e11d48', 16), color: '#e11d48' } : { background: tint('#64748b', 14), color: '#64748b' }}>
                {num(active.length, lang)} {lang === 'ar' ? 'نشطة' : 'active'}
              </span>
            }>
            {strikes.length ? (
              <div className="flex flex-col gap-1.5">
                {strikes.map((s, i) => {
                  const isActive = !s.voided && s.daysAgo <= 30;
                  return (
                    <div key={i} className={`flex items-center gap-2.5 rounded-lg border px-3 py-2 ${s.voided ? 'border-slate-200/70 bg-white/40 opacity-60' : isActive ? 'border-rose-200' : 'border-slate-200/70 bg-white/40'}`}
                      style={!s.voided && isActive ? { background: tint('#e11d48', 9) } : undefined}>
                      <span className={s.voided ? 'text-slate-400' : 'text-rose-500'}><Icon name={s.voided ? 'undo' : 'alert'} size={15} /></span>
                      <div className="min-w-0 flex-1">
                        <div className={`text-[12.5px] font-medium ${s.voided ? 'text-slate-400 line-through' : 'text-slate-700'}`}>{tt(STRIKE_REASONS[s.reason], lang)}</div>
                        <div className="flex items-center gap-1.5 text-[11px] text-slate-400">
                          <span>{s.by === 'system' ? (lang === 'ar' ? 'تلقائي' : 'system') : (lang === 'ar' ? 'إداري' : 'admin')}</span>
                          {s.order && <span className="font-mono" style={{ direction: 'ltr' }}>· {s.order}</span>}
                          {s.fee > 0 && <span>· {lang === 'ar' ? `رسم ${num(s.fee, lang)}` : `${num(s.fee, lang)} fee`}</span>}
                          <span style={{ direction: 'ltr' }}>· {num(s.daysAgo, lang)}d</span>
                        </div>
                      </div>
                      {!s.voided && (
                        <button onClick={() => act('strike_void', i)} className="shrink-0 rounded-md px-2 py-1 text-[11.5px] font-semibold text-slate-500 transition hover:bg-white hover:text-slate-800">{lang === 'ar' ? 'إلغاء' : 'Void'}</button>
                      )}
                    </div>
                  );
                })}
              </div>
            ) : <div className="py-2 text-[13px] text-slate-400">{lang === 'ar' ? 'سجلّ نظيف — لا مخالفات.' : 'Clean record — no strikes.'}</div>}

            {mode === 'strike' ? (
              <div className="mt-2.5 rounded-xl border border-slate-200 bg-white/80 p-3">
                <div className="flex flex-col gap-2">
                  <select value={strikeReason} onChange={(e) => setStrikeReason(e.target.value)}
                    className="h-9 rounded-lg border border-slate-200 bg-white px-2.5 text-[12.5px] text-slate-700 outline-none focus:border-slate-300">
                    {Object.keys(STRIKE_REASONS).map((k) => <option key={k} value={k}>{tt(STRIKE_REASONS[k], lang)}</option>)}
                  </select>
                  <div className="flex items-center gap-2">
                    <input type="number" value={strikeFee} min="0" onChange={(e) => setStrikeFee(+e.target.value)}
                      placeholder={lang === 'ar' ? 'رسم (د.ل)' : 'Fee (LYD)'}
                      className="h-9 w-28 rounded-lg border border-slate-200 bg-white px-2.5 text-[12.5px] text-slate-700 outline-none focus:border-slate-300" />
                    <div className="ms-auto flex gap-2">
                      <button onClick={() => setMode(null)} className="h-9 rounded-lg px-3 text-[12.5px] font-semibold text-slate-500 hover:text-slate-700">{lang === 'ar' ? 'إلغاء' : 'Cancel'}</button>
                      <MiniBtn icon="plus" tone="red" onClick={() => { act('strike_add', { reason: strikeReason, fee: strikeFee }); setMode(null); setStrikeFee(0); setStrikeReason('manual_admin'); }}>{lang === 'ar' ? 'تسجيل' : 'Issue'}</MiniBtn>
                    </div>
                  </div>
                </div>
              </div>
            ) : (
              <button onClick={() => setMode('strike')} className="mt-2.5 inline-flex items-center gap-1.5 text-[12.5px] font-semibold text-rose-600 hover:text-rose-700">
                <Icon name="plus" size={15} strokeWidth={2.2} />{lang === 'ar' ? 'تسجيل مخالفة' : 'Add strike'}
              </button>
            )}
          </Section>

          {/* Documents */}
          <Section title={lang === 'ar' ? 'المستندات' : 'Documents'} icon="doc"
            right={<span className="rounded-full px-2 py-0.5 text-[11px] font-bold tabular-nums" style={verifiedCount === docList.length ? { background: tint('#16a34a', 16), color: '#16a34a' } : { background: tint('#d97706', 16), color: '#d97706' }}>{num(verifiedCount, lang)}/{num(docList.length, lang)}</span>}
            className="lg:col-span-2">
            <div className="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
              {docList.map((k) => {
                const doc = d.docs[k] || { v: false };
                const meta = DOC_TYPES[k];
                const expired = doc.exp && doc.exp <= '2026-06';
                return (
                  <div key={k} className="flex items-center gap-2.5 rounded-lg border border-slate-200/80 bg-white/60 px-3 py-2">
                    <span className={doc.v ? 'text-emerald-500' : 'text-slate-300'}><Icon name={doc.v ? 'checkCircle' : 'doc'} size={16} /></span>
                    <div className="min-w-0 flex-1">
                      <div className="truncate text-[12.5px] font-medium text-slate-700">{tt(meta, lang)}</div>
                      {doc.exp && (
                        <div className={`text-[11px] ${expired ? 'font-semibold text-rose-500' : 'text-slate-400'}`} style={{ direction: 'ltr' }}>
                          {lang === 'ar' ? 'ينتهي' : 'exp'} {doc.exp}{expired ? (lang === 'ar' ? ' — منتهٍ' : ' — expired') : ''}
                        </div>
                      )}
                    </div>
                    {doc.v
                      ? <span className="shrink-0 text-[11px] font-semibold text-emerald-600">{lang === 'ar' ? 'موثّق' : 'Verified'}</span>
                      : <button onClick={() => act('doc_verify', k)} className="shrink-0 rounded-md border border-slate-200 bg-white px-2 py-1 text-[11px] font-semibold text-slate-600 transition hover:bg-slate-50">{lang === 'ar' ? 'توثيق' : 'Verify'}</button>}
                  </div>
                );
              })}
            </div>
          </Section>
        </div>
      </div>
    </div>
  );
}

function Stat({ label, value, accent }) {
  return (
    <div className="rounded-xl border border-slate-200/80 bg-white/60 py-2.5">
      <div className="text-[18px] font-bold tabular-nums" style={{ color: accent || '#0f172a' }}>{value}</div>
      <div className="text-[11px] text-slate-400">{label}</div>
    </div>
  );
}

Object.assign(window, { DriverModal, Section, MiniBtn, Stat });
