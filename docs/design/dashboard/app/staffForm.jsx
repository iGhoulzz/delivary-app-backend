// staffForm.jsx — create staff (admin or office_staff) + one-time temp-password
// display (reused for reset). Phone required/unique; office assignments required
// for office_staff, prohibited for admin. System generates a temp password shown ONCE.

const stInputCls = "h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-[13.5px] text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-slate-300 focus:ring-2 focus:ring-[var(--accent-soft)]";

function StaffField({ label, children, required, hint }) {
  return (
    <label className="block">
      <span className="mb-1 flex items-center gap-2 text-[12px] font-semibold text-slate-500">{label}{required && <span className="text-rose-500">*</span>}{hint && <span className="font-normal text-slate-400">· {hint}</span>}</span>
      {children}
    </label>
  );
}

// One-time temp-password display — shown after create or reset. Cannot be re-shown.
function TempPasswordModal({ data, lang, onClose }) {
  const [copied, setCopied] = React.useState(false);
  React.useEffect(() => {
    const h = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', h);
    return () => document.removeEventListener('keydown', h);
  }, [onClose]);
  function copy() {
    try { navigator.clipboard.writeText(data.password); } catch (e) {}
    setCopied(true); setTimeout(() => setCopied(false), 1800);
  }
  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 sm:p-6">
      <div className="absolute inset-0 bg-slate-900/50" style={{ animation: 'drawerFade .3s ease both', backdropFilter: 'blur(8px)', WebkitBackdropFilter: 'blur(8px)' }} />
      <div className="app-card relative w-full max-w-[460px] overflow-hidden rounded-3xl border border-white/60 bg-white/90 shadow-2xl"
        style={{ animation: 'modalIn .34s cubic-bezier(.22,1,.36,1) both', backdropFilter: 'blur(24px) saturate(1.4)', WebkitBackdropFilter: 'blur(24px) saturate(1.4)' }}>
        <div className="px-6 pt-6 pb-5 text-center">
          <span className="mx-auto grid h-12 w-12 place-items-center rounded-2xl" style={{ background: tint('#d97706', 14), color: '#b45309' }}><Icon name="key" size={24} /></span>
          <h2 className="mt-3 text-[18px] font-bold tracking-tight text-slate-900">{data.reset ? (lang === 'ar' ? 'كلمة مرور مؤقتة جديدة' : 'New temporary password') : (lang === 'ar' ? 'تم إنشاء الموظف' : 'Staff account created')}</h2>
          <p className="mt-1 text-[12.5px] text-slate-500">{lang === 'ar' ? `لـ ${data.name}` : `for ${data.name}`}</p>

          <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
            <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{lang === 'ar' ? 'كلمة المرور المؤقتة' : 'Temporary password'}</div>
            <div className="mt-1.5 flex items-center justify-center gap-2">
              <code className="select-all rounded-lg bg-white px-3 py-2 font-mono text-[18px] font-bold tracking-wide text-slate-800 ring-1 ring-slate-200" style={{ direction: 'ltr' }}>{data.password}</code>
              <button onClick={copy} className="grid h-10 w-10 place-items-center rounded-lg border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-50" title={lang === 'ar' ? 'نسخ' : 'Copy'}>
                <Icon name={copied ? 'check' : 'copy'} size={17} className={copied ? 'text-emerald-500' : ''} />
              </button>
            </div>
          </div>

          <div className="mt-4 flex items-start gap-2 rounded-xl border border-amber-200 px-3.5 py-3 text-start" style={{ background: tint('#d97706', 7) }}>
            <span className="mt-0.5 shrink-0 text-amber-600"><Icon name="alert" size={16} /></span>
            <p className="text-[12px] leading-relaxed text-amber-800">{lang === 'ar' ? 'تُعرض مرة واحدة فقط ولن تظهر مجددًا. سلّمها للموظف عبر قناة آمنة. سيُطلب منه تغييرها عند أول دخول.' : 'Shown once and never again. Deliver it to the staff member out-of-band. They must change it on first login.'}</p>
          </div>
        </div>
        <div className="border-t border-slate-200/70 px-6 py-4">
          <button onClick={onClose} className="inline-flex h-10 w-full items-center justify-center gap-2 rounded-lg text-[13.5px] font-semibold text-white shadow-sm" style={{ background: 'var(--accent)' }}>
            <Icon name="check" size={17} />{lang === 'ar' ? 'تم — أغلقت ونسخت' : 'Done — I saved it'}
          </button>
        </div>
      </div>
    </div>
  );
}

function StaffForm({ lang, onClose, onCreate }) {
  const [f, setF] = React.useState({ role: 'office_staff', first: '', last: '', phone: '', email: '' });
  const [offices, setOffices] = React.useState([]); // [{office, is_manager}]
  const set = (k, v) => setF((p) => ({ ...p, [k]: v }));

  React.useEffect(() => {
    const h = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', h);
    return () => document.removeEventListener('keydown', h);
  }, [onClose]);

  const phoneTaken = STAFF_RECORDS.some((s) => s.phone.replace(/\s/g, '') === f.phone.replace(/\s/g, '') && f.phone.trim());
  const isOfficeStaff = f.role === 'office_staff';
  const valid = f.first.trim() && f.last.trim() && f.phone.trim() && !phoneTaken && (!isOfficeStaff || offices.length > 0);

  const toggleOffice = (id) => setOffices((prev) => prev.some((o) => o.office === id) ? prev.filter((o) => o.office !== id) : [...prev, { office: id, is_manager: false }]);
  const toggleMgr = (id) => setOffices((prev) => prev.map((o) => o.office === id ? { ...o, is_manager: !o.is_manager } : o));

  function submit() {
    if (!valid) return;
    onCreate({ ...f, assignments: isOfficeStaff ? offices : [] });
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6">
      <div onClick={onClose} className="absolute inset-0 bg-slate-900/40" style={{ animation: 'drawerFade .3s ease both', backdropFilter: 'blur(7px)', WebkitBackdropFilter: 'blur(7px)' }} />
      <div className="app-card relative flex max-h-[90vh] w-full max-w-[600px] flex-col overflow-hidden rounded-3xl border border-white/60 bg-white/85 shadow-2xl"
        style={{ animation: 'modalIn .34s cubic-bezier(.22,1,.36,1) both', backdropFilter: 'blur(24px) saturate(1.4)', WebkitBackdropFilter: 'blur(24px) saturate(1.4)' }}>

        <div className="flex shrink-0 items-start justify-between gap-3 border-b border-slate-200/70 px-6 py-5">
          <div>
            <h2 className="text-[18px] font-bold tracking-tight text-slate-900">{lang === 'ar' ? 'إضافة موظف' : 'Add a staff member'}</h2>
            <p className="mt-0.5 text-[12.5px] text-slate-400">{lang === 'ar' ? 'يُنشئ النظام كلمة مرور مؤقتة تُعرض مرة واحدة بعد الإنشاء.' : 'The system generates a one-time temp password shown after creation.'}</p>
          </div>
          <button onClick={onClose} className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"><Icon name="close" size={20} /></button>
        </div>

        <div className="flex-1 space-y-5 overflow-y-auto px-6 py-5">
          {/* role */}
          <div>
            <div className="mb-2 text-[12px] font-semibold uppercase tracking-wide text-slate-400">{lang === 'ar' ? 'الدور' : 'Role'}</div>
            <div className="grid grid-cols-2 gap-2.5">
              {['office_staff', 'admin'].map((k) => (
                <button key={k} onClick={() => set('role', k)}
                  className={`flex items-center gap-2.5 rounded-xl border px-3.5 py-3 text-start transition ${f.role === k ? 'border-[var(--accent)] bg-[var(--accent-soft)]' : 'border-slate-200 bg-white hover:bg-slate-50'}`}>
                  <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg" style={{ background: tint(k === 'admin' ? '#7c3aed' : '#64748b', 13), color: k === 'admin' ? '#7c3aed' : '#64748b' }}><Icon name={k === 'admin' ? 'shield' : 'building'} size={18} /></span>
                  <div>
                    <div className="text-[13px] font-bold text-slate-800">{tt(STAFF_ROLES[k], lang)}</div>
                    <div className="text-[11px] text-slate-400">{k === 'admin' ? (lang === 'ar' ? 'صلاحية عامة' : 'Global authority') : (lang === 'ar' ? 'مقيّد بمكتب' : 'Office-scoped')}</div>
                  </div>
                </button>
              ))}
            </div>
          </div>

          {/* identity */}
          <div>
            <div className="mb-2.5 text-[12px] font-semibold uppercase tracking-wide text-slate-400">{lang === 'ar' ? 'الهوية' : 'Identity'}</div>
            <div className="grid grid-cols-2 gap-3">
              <StaffField label={lang === 'ar' ? 'الاسم الأول' : 'First name'} required><input className={stInputCls} value={f.first} onChange={(e) => set('first', e.target.value)} placeholder={lang === 'ar' ? 'محمد' : 'Mohamed'} /></StaffField>
              <StaffField label={lang === 'ar' ? 'اسم العائلة' : 'Last name'} required><input className={stInputCls} value={f.last} onChange={(e) => set('last', e.target.value)} placeholder={lang === 'ar' ? 'العريبي' : 'Al-Areibi'} /></StaffField>
              <StaffField label={lang === 'ar' ? 'رقم الهاتف' : 'Phone'} required hint={lang === 'ar' ? 'فريد' : 'unique'}>
                <input className={`${stInputCls} ${phoneTaken ? 'border-rose-300 focus:ring-rose-100' : ''}`} value={f.phone} onChange={(e) => set('phone', e.target.value)} placeholder="09X XXX XXXX" style={{ direction: 'ltr' }} />
                {phoneTaken && <span className="mt-1 block text-[11px] font-medium text-rose-500">{lang === 'ar' ? 'هذا الرقم مستخدم بالفعل.' : 'This phone is already in use.'}</span>}
              </StaffField>
              <StaffField label={lang === 'ar' ? 'البريد (اختياري)' : 'Email (optional)'}><input className={stInputCls} value={f.email} onChange={(e) => set('email', e.target.value)} placeholder="staff@tawseel.ly" style={{ direction: 'ltr' }} /></StaffField>
            </div>
          </div>

          {/* office assignments */}
          <div>
            <div className="mb-2 flex items-center gap-2 text-[12px] font-semibold uppercase tracking-wide text-slate-400">
              <Icon name="building" size={14} />{lang === 'ar' ? 'مهام المكاتب' : 'Office assignments'}
              {isOfficeStaff && <span className="font-normal normal-case text-rose-500">{lang === 'ar' ? '· مطلوب' : '· required'}</span>}
            </div>
            {isOfficeStaff ? (
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                {OFFICES.map((o) => {
                  const sel = offices.find((x) => x.office === o.id);
                  return (
                    <div key={o.id} className={`rounded-xl border px-3 py-2.5 transition ${sel ? 'border-[var(--accent)] bg-[var(--accent-soft)]' : 'border-slate-200 bg-white'}`}>
                      <label className="flex cursor-pointer items-center gap-2.5">
                        <input type="checkbox" checked={!!sel} onChange={() => toggleOffice(o.id)} className="h-4 w-4 accent-[var(--accent)]" />
                        <span className="flex-1 text-[13px] font-semibold text-slate-700">{tt(o.district, lang)}</span>
                      </label>
                      {sel && (
                        <label className="mt-2 flex cursor-pointer items-center gap-2 ps-6.5 text-[11.5px] text-slate-500">
                          <input type="checkbox" checked={sel.is_manager} onChange={() => toggleMgr(o.id)} className="h-3.5 w-3.5 accent-[var(--accent)]" />
                          {lang === 'ar' ? 'مدير المكتب' : 'Office manager'}
                        </label>
                      )}
                    </div>
                  );
                })}
              </div>
            ) : (
              <div className="rounded-xl border border-slate-200/80 bg-slate-50/60 px-3.5 py-3 text-[12.5px] text-slate-500">
                <Icon name="shield" size={14} className="me-1.5 inline" />{lang === 'ar' ? 'المدراء غير مرتبطين بمكتب — لا يمكن إسناد مكاتب لهم.' : 'Admins are not office-scoped — assignments are prohibited.'}
              </div>
            )}
          </div>
        </div>

        <div className="flex shrink-0 items-center justify-between gap-2.5 border-t border-slate-200/70 px-6 py-4">
          <span className="text-[11.5px] text-slate-400">{!valid && (isOfficeStaff && offices.length === 0 ? (lang === 'ar' ? 'اختر مكتبًا واحدًا على الأقل' : 'Assign at least one office') : (lang === 'ar' ? 'أكمل الاسم والهاتف' : 'Complete name and phone'))}</span>
          <div className="flex items-center gap-2.5">
            <button onClick={onClose} className="h-10 rounded-lg px-4 text-[13.5px] font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-700">{lang === 'ar' ? 'إلغاء' : 'Cancel'}</button>
            <button onClick={submit} disabled={!valid}
              className={`inline-flex h-10 items-center gap-2 rounded-lg px-4 text-[13.5px] font-semibold text-white shadow-sm transition ${valid ? '' : 'cursor-not-allowed opacity-40'}`} style={{ background: 'var(--accent)' }}>
              <Icon name="userPlus" size={17} />{lang === 'ar' ? 'إنشاء الموظف' : 'Create staff'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { StaffForm, TempPasswordModal });
