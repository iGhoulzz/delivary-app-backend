// driverForm.jsx — add/onboard driver. Two modes: attach a profile to an
// existing user, or create a brand-new person. A driver is always a user with
// a driver profile; the "existing" mode makes that relationship explicit.

function Field({ label, children, required }) {
  return (
    <label className="block">
      <span className="mb-1 block text-[12px] font-semibold text-slate-500">{label}{required && <span className="text-rose-500"> *</span>}</span>
      {children}
    </label>
  );
}
const fInputCls = "h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-[13.5px] text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-slate-300 focus:ring-2 focus:ring-[var(--accent-soft)] disabled:bg-slate-50 disabled:text-slate-400";

function AddDriverModal({ lang, users, presetUser, onClose, onCreate }) {
  const [mode, setMode] = React.useState('existing'); // 'existing' | 'new'
  const [userQ, setUserQ] = React.useState('');
  const [pickedUser, setPickedUser] = React.useState(presetUser || null);
  const [f, setF] = React.useState({ first: '', last: '', phone: '', email: '', vehicle: 'motorcycle', plate: '', color: '', model: '', office: 'of-01' });
  const set = (k, v) => setF((p) => ({ ...p, [k]: v }));

  React.useEffect(() => {
    const h = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', h);
    return () => document.removeEventListener('keydown', h);
  }, [onClose]);

  const docList = Object.keys(DOC_TYPES);
  const pool = (users || []).filter((u) => {
    if (!userQ.trim()) return true;
    const hay = [u.id, tt(u.name, lang), tt(u.name, 'en'), u.phone].join(' ').toLowerCase();
    return hay.includes(userQ.trim().toLowerCase());
  });

  const identityOk = mode === 'existing' ? !!pickedUser : (f.first.trim() && f.last.trim() && f.phone.trim());
  const valid = identityOk && f.plate.trim();

  function submit() {
    if (!valid) return;
    onCreate(mode === 'existing' ? { mode, userId: pickedUser.id, vehicle: f.vehicle, plate: f.plate, color: f.color, model: f.model, office: f.office } : { mode, ...f });
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6">
      <div onClick={onClose} className="absolute inset-0 bg-slate-900/40"
        style={{ animation: 'drawerFade .3s ease both', backdropFilter: 'blur(7px)', WebkitBackdropFilter: 'blur(7px)' }} />

      <div className="app-card relative flex max-h-[90vh] w-full max-w-[680px] flex-col overflow-hidden rounded-3xl border border-white/60 bg-white/85 shadow-2xl"
        style={{ animation: 'modalIn .34s cubic-bezier(.22,1,.36,1) both', backdropFilter: 'blur(24px) saturate(1.4)', WebkitBackdropFilter: 'blur(24px) saturate(1.4)' }}>

        <div className="shrink-0 border-b border-slate-200/70 px-6 pt-5 pb-4">
          <div className="flex items-start justify-between gap-3">
            <div>
              <h2 className="text-[18px] font-bold tracking-tight text-slate-900">{lang === 'ar' ? 'إضافة سائق' : 'Add a driver'}</h2>
              <p className="mt-0.5 text-[12.5px] text-slate-400">{lang === 'ar' ? 'ملف السائق يُربط بحساب مستخدم. يبدأ بحالة «بانتظار الموافقة».' : 'A driver profile attaches to a user account. Starts as “pending approval”.'}</p>
            </div>
            <button onClick={onClose} className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"><Icon name="close" size={20} /></button>
          </div>

          {/* mode toggle */}
          <div className="mt-3.5 inline-flex rounded-xl border border-slate-200 bg-slate-50/80 p-1">
            {[['existing', lang === 'ar' ? 'من مستخدم موجود' : 'From existing user'], ['new', lang === 'ar' ? 'شخص جديد' : 'New person']].map(([k, label]) => (
              <button key={k} onClick={() => setMode(k)}
                className={`inline-flex items-center gap-1.5 rounded-lg px-3.5 py-1.5 text-[12.5px] font-semibold transition ${mode === k ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}>
                <Icon name={k === 'existing' ? 'user' : 'plus'} size={14} />{label}
              </button>
            ))}
          </div>
        </div>

        <div className="flex-1 space-y-5 overflow-y-auto px-6 py-5">

          {/* ── identity ── */}
          {mode === 'existing' ? (
            <div>
              <div className="mb-2.5 flex items-center gap-2 text-[12px] font-semibold uppercase tracking-wide text-slate-400"><Icon name="user" size={15} />{lang === 'ar' ? 'اختر المستخدم' : 'Select user'}</div>

              {pickedUser ? (
                <div className="flex items-center gap-3 rounded-xl border border-[var(--accent)] bg-[var(--accent-soft)] px-3.5 py-3">
                  <Avatar name={pickedUser.name} lang={lang} size={40} />
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2"><span className="font-semibold text-slate-800">{tt(pickedUser.name, lang)}</span><AccountChip status={pickedUser.accountStatus} lang={lang} /></div>
                    <div className="flex items-center gap-2 text-[12px] text-slate-500"><span className="font-mono" style={{ direction: 'ltr' }}>{pickedUser.phone}</span><span className="text-slate-300">·</span><span className="font-mono text-[11px] text-slate-400" style={{ direction: 'ltr' }}>{pickedUser.id}</span></div>
                  </div>
                  <button onClick={() => setPickedUser(null)} className="rounded-lg px-2.5 py-1.5 text-[12px] font-semibold text-slate-500 transition hover:bg-white hover:text-slate-800">{lang === 'ar' ? 'تغيير' : 'Change'}</button>
                </div>
              ) : (
                <>
                  <div className="relative mb-2">
                    <div className="pointer-events-none absolute inset-y-0 start-3 flex items-center text-slate-400"><Icon name="search" size={17} /></div>
                    <input value={userQ} onChange={(e) => setUserQ(e.target.value)} placeholder={lang === 'ar' ? 'ابحث بالاسم أو الهاتف…' : 'Search name or phone…'}
                      className="h-10 w-full rounded-lg border border-slate-200 bg-white ps-10 pe-3 text-[13.5px] text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-slate-300 focus:ring-2 focus:ring-[var(--accent-soft)]" />
                  </div>
                  <div className="max-h-[244px] space-y-1 overflow-y-auto pe-1">
                    {pool.map((u) => {
                      const isDriver = !!u.driverId;
                      const blocked = u.accountStatus === 'banned';
                      const disabled = isDriver || blocked;
                      return (
                        <button key={u.id} disabled={disabled} onClick={() => setPickedUser(u)}
                          className={`flex w-full items-center gap-3 rounded-lg border px-3 py-2 text-start transition ${disabled ? 'cursor-not-allowed border-slate-100 bg-slate-50/40 opacity-60' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'}`}>
                          <Avatar name={u.name} lang={lang} size={34} />
                          <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-2"><span className="truncate text-[13px] font-semibold text-slate-700">{tt(u.name, lang)}</span>{u.accountStatus !== 'active' && <AccountChip status={u.accountStatus} lang={lang} />}</div>
                            <div className="flex items-center gap-2 text-[11.5px] text-slate-400"><span className="font-mono" style={{ direction: 'ltr' }}>{u.phone}</span><span>·</span><span>{num(u.orders, lang)} {lang === 'ar' ? 'طلب' : 'orders'}</span></div>
                          </div>
                          {isDriver
                            ? <span className="shrink-0 rounded-md bg-slate-100 px-2 py-1 text-[10.5px] font-semibold text-slate-400">{lang === 'ar' ? 'سائق بالفعل' : 'Already a driver'}</span>
                            : blocked
                              ? <span className="shrink-0 text-[11px] font-semibold text-rose-400">{lang === 'ar' ? 'محظور' : 'Banned'}</span>
                              : <span className="shrink-0 text-slate-300"><Icon name={lang === 'ar' ? 'chevronL' : 'chevronR'} size={16} /></span>}
                        </button>
                      );
                    })}
                    {pool.length === 0 && <div className="py-6 text-center text-[13px] text-slate-400">{lang === 'ar' ? 'لا يوجد مستخدمون مطابقون' : 'No matching users'}</div>}
                  </div>
                </>
              )}
            </div>
          ) : (
            <div>
              <div className="mb-2.5 flex items-center gap-2 text-[12px] font-semibold uppercase tracking-wide text-slate-400"><Icon name="user" size={15} />{lang === 'ar' ? 'الهوية' : 'Identity'}</div>
              <p className="mb-2.5 -mt-1 text-[11.5px] text-slate-400">{lang === 'ar' ? 'سيُنشأ حساب مستخدم جديد مرتبط بهذا السائق.' : 'A new user account will be created and linked to this driver.'}</p>
              <div className="grid grid-cols-2 gap-3">
                <Field label={lang === 'ar' ? 'الاسم الأول' : 'First name'} required><input className={fInputCls} value={f.first} onChange={(e) => set('first', e.target.value)} placeholder={lang === 'ar' ? 'محمد' : 'Mohamed'} /></Field>
                <Field label={lang === 'ar' ? 'اسم العائلة' : 'Last name'} required><input className={fInputCls} value={f.last} onChange={(e) => set('last', e.target.value)} placeholder={lang === 'ar' ? 'العريبي' : 'Al-Areibi'} /></Field>
                <Field label={lang === 'ar' ? 'رقم الهاتف' : 'Phone number'} required><input className={fInputCls} value={f.phone} onChange={(e) => set('phone', e.target.value)} placeholder="09X XXX XXXX" style={{ direction: 'ltr' }} /></Field>
                <Field label={lang === 'ar' ? 'البريد (اختياري)' : 'Email (optional)'}><input className={fInputCls} value={f.email} onChange={(e) => set('email', e.target.value)} placeholder="driver@…" style={{ direction: 'ltr' }} /></Field>
              </div>
            </div>
          )}

          {/* ── vehicle ── (both modes) */}
          <div>
            <div className="mb-2.5 flex items-center gap-2 text-[12px] font-semibold uppercase tracking-wide text-slate-400"><Icon name="truck" size={15} />{lang === 'ar' ? 'المركبة' : 'Vehicle'}</div>
            <div className="mb-3 flex gap-2">
              {Object.keys(VEHICLES).map((k) => (
                <button key={k} onClick={() => set('vehicle', k)}
                  className={`inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-lg border text-[13px] font-semibold transition ${f.vehicle === k ? 'text-white shadow-sm' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'}`}
                  style={f.vehicle === k ? { background: 'var(--accent)', borderColor: 'var(--accent)' } : undefined}>
                  <Icon name={VEHICLES[k].icon} size={17} />{tt(VEHICLES[k], lang)}
                </button>
              ))}
            </div>
            <div className="grid grid-cols-3 gap-3">
              <Field label={lang === 'ar' ? 'رقم اللوحة' : 'Plate'} required><input className={fInputCls} value={f.plate} onChange={(e) => set('plate', e.target.value)} placeholder="TR ____" style={{ direction: 'ltr' }} /></Field>
              <Field label={lang === 'ar' ? 'اللون' : 'Colour'}><input className={fInputCls} value={f.color} onChange={(e) => set('color', e.target.value)} placeholder={lang === 'ar' ? 'أحمر' : 'Red'} /></Field>
              <Field label={lang === 'ar' ? 'الطراز' : 'Model'}><input className={fInputCls} value={f.model} onChange={(e) => set('model', e.target.value)} placeholder={lang === 'ar' ? 'هوندا' : 'Honda CG'} /></Field>
            </div>
          </div>

          {/* ── office ── */}
          <div>
            <div className="mb-2.5 flex items-center gap-2 text-[12px] font-semibold uppercase tracking-wide text-slate-400"><Icon name="building" size={15} />{lang === 'ar' ? 'المكتب' : 'Office'}</div>
            <Field label={lang === 'ar' ? 'المكتب المعيّن' : 'Assigned office'}>
              <select value={f.office} onChange={(e) => set('office', e.target.value)} className={fInputCls}>
                {OFFICES.map((o) => <option key={o.id} value={o.id}>{tt(o.name, lang)}</option>)}
              </select>
            </Field>
          </div>

          {/* ── documents ── */}
          <div>
            <div className="mb-2.5 flex items-center gap-2 text-[12px] font-semibold uppercase tracking-wide text-slate-400"><Icon name="doc" size={15} />{lang === 'ar' ? 'المستندات' : 'Documents'}</div>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
              {docList.map((k) => (
                <div key={k} className="grid h-[68px] cursor-pointer place-items-center rounded-xl border border-dashed border-slate-300 bg-slate-50/40 px-2 text-center transition hover:border-slate-400 hover:bg-slate-50">
                  <div>
                    <span className="text-slate-300"><Icon name="download" size={18} /></span>
                    <div className="mt-1 text-[10.5px] font-medium leading-tight text-slate-400">{tt(DOC_TYPES[k], lang)}</div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>

        <div className="flex shrink-0 items-center justify-between gap-2.5 border-t border-slate-200/70 px-6 py-4">
          <span className="text-[11.5px] text-slate-400">{!valid && (lang === 'ar' ? 'أكمل الهوية ورقم اللوحة' : 'Complete identity and plate')}</span>
          <div className="flex items-center gap-2.5">
            <button onClick={onClose} className="h-10 rounded-lg px-4 text-[13.5px] font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-700">{lang === 'ar' ? 'إلغاء' : 'Cancel'}</button>
            <button onClick={submit} disabled={!valid}
              className={`inline-flex h-10 items-center gap-2 rounded-lg px-4 text-[13.5px] font-semibold text-white shadow-sm transition ${valid ? '' : 'cursor-not-allowed opacity-40'}`}
              style={{ background: 'var(--accent)' }}>
              <Icon name="checkCircle" size={17} />{mode === 'existing' ? (lang === 'ar' ? 'ربط ملف السائق' : 'Attach driver profile') : (lang === 'ar' ? 'إنشاء السائق' : 'Create driver')}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { AddDriverModal });
