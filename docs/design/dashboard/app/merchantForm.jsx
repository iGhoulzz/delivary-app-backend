// merchantForm.jsx — onboard a merchant (from an existing user, created
// directly ACTIVE) or edit an existing merchant. No application/approval step.

const mInputCls = "h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-[13.5px] text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-slate-300 focus:ring-2 focus:ring-[var(--accent-soft)]";

function MField({ label, children, required, hint }) {
  return (
    <label className="block">
      <span className="mb-1 flex items-center gap-2 text-[12px] font-semibold text-slate-500">{label}{required && <span className="text-rose-500">*</span>}{hint && <span className="font-normal text-slate-400">· {hint}</span>}</span>
      {children}
    </label>
  );
}

function MerchantForm({ lang, users, editing, onClose, onSubmit }) {
  const editOwner = editing ? users.find((u) => u.id === editing.ownerUserId) : null;
  const [userQ, setUserQ] = React.useState('');
  const [pickedUser, setPickedUser] = React.useState(editOwner || null);
  const [f, setF] = React.useState({
    business: editing ? tt(editing.business, 'en') : '',
    businessAr: editing ? tt(editing.business, 'ar') : '',
    businessPhone: editing ? editing.businessPhone : '',
    commission: editing && editing.commissionOverride != null ? String(Math.round(editing.commissionOverride * 100)) : '',
    driverCut: editing && editing.driverFeeCutOverride != null ? String(Math.round(editing.driverFeeCutOverride * 100)) : '',
    pickup: editing ? tt(editing.pickup, 'en') : '',
    pickupAr: editing ? tt(editing.pickup, 'ar') : '',
    pickupDist: editing ? tt(editing.pickupDist, lang) : '',
    notes: editing ? tt(editing.notes, lang) : '',
  });
  const set = (k, v) => setF((p) => ({ ...p, [k]: v }));

  React.useEffect(() => {
    const h = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', h);
    return () => document.removeEventListener('keydown', h);
  }, [onClose]);

  const pool = (users || []).filter((u) => {
    if (!userQ.trim()) return true;
    const hay = [u.id, tt(u.name, lang), tt(u.name, 'en'), u.phone].join(' ').toLowerCase();
    return hay.includes(userQ.trim().toLowerCase());
  });

  const ownerOk = editing ? true : !!pickedUser;
  const valid = ownerOk && (f.business.trim() || f.businessAr.trim()) && f.businessPhone.trim();

  function submit() {
    if (!valid) return;
    onSubmit({
      editingId: editing ? editing.id : null,
      ownerUserId: editing ? editing.ownerUserId : pickedUser.id,
      business: { ar: f.businessAr.trim() || f.business.trim(), en: f.business.trim() || f.businessAr.trim() },
      businessPhone: f.businessPhone.trim(),
      commissionOverride: f.commission.trim() === '' ? null : (+f.commission) / 100,
      driverFeeCutOverride: f.driverCut.trim() === '' ? null : (+f.driverCut) / 100,
      pickup: { ar: f.pickupAr.trim() || f.pickup.trim(), en: f.pickup.trim() || f.pickupAr.trim() },
      pickupDist: { ar: f.pickupDist.trim(), en: f.pickupDist.trim() },
      notes: { ar: f.notes.trim(), en: f.notes.trim() },
    });
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6">
      <div onClick={onClose} className="absolute inset-0 bg-slate-900/40"
        style={{ animation: 'drawerFade .3s ease both', backdropFilter: 'blur(7px)', WebkitBackdropFilter: 'blur(7px)' }} />

      <div className="app-card relative flex max-h-[90vh] w-full max-w-[680px] flex-col overflow-hidden rounded-3xl border border-white/60 bg-white/85 shadow-2xl"
        style={{ animation: 'modalIn .34s cubic-bezier(.22,1,.36,1) both', backdropFilter: 'blur(24px) saturate(1.4)', WebkitBackdropFilter: 'blur(24px) saturate(1.4)' }}>

        <div className="flex shrink-0 items-start justify-between gap-3 border-b border-slate-200/70 px-6 py-5">
          <div>
            <h2 className="text-[18px] font-bold tracking-tight text-slate-900">{editing ? (lang === 'ar' ? 'تعديل التاجر' : 'Edit merchant') : (lang === 'ar' ? 'تسجيل تاجر جديد' : 'Onboard a merchant')}</h2>
            <p className="mt-0.5 text-[12.5px] text-slate-400">{editing ? (lang === 'ar' ? 'تعديل بيانات النشاط والأسعار والاستلام.' : 'Update business fields, rates and pickup.') : (lang === 'ar' ? 'يُربط ملف التاجر بمستخدم موجود ويُنشأ «نشطًا» مباشرةً — لا توجد مراجعة.' : 'Attaches to an existing user and is created active immediately — no review step.')}</p>
          </div>
          <button onClick={onClose} className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"><Icon name="close" size={20} /></button>
        </div>

        <div className="flex-1 space-y-5 overflow-y-auto px-6 py-5">

          {/* owner */}
          <div>
            <div className="mb-2.5 flex items-center gap-2 text-[12px] font-semibold uppercase tracking-wide text-slate-400"><Icon name="user" size={15} />{lang === 'ar' ? 'المالك (مستخدم موجود)' : 'Owner (existing user)'}</div>
            {editing ? (
              <div className="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50/60 px-3.5 py-3">
                {editOwner && <Avatar name={editOwner.name} lang={lang} size={38} />}
                <div className="min-w-0 flex-1">
                  <div className="font-semibold text-slate-700">{editOwner ? tt(editOwner.name, lang) : '—'}</div>
                  <div className="font-mono text-[11.5px] text-slate-400" style={{ direction: 'ltr' }}>{editing.ownerUserId}</div>
                </div>
                <span className="rounded-md bg-slate-100 px-2 py-1 text-[10.5px] font-semibold text-slate-400">{lang === 'ar' ? 'غير قابل للتغيير' : 'Locked'}</span>
              </div>
            ) : pickedUser ? (
              <div className="flex items-center gap-3 rounded-xl border border-[var(--accent)] bg-[var(--accent-soft)] px-3.5 py-3">
                <Avatar name={pickedUser.name} lang={lang} size={40} />
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2"><span className="font-semibold text-slate-800">{tt(pickedUser.name, lang)}</span><AccountChip status={pickedUser.accountStatus} lang={lang} /></div>
                  <div className="font-mono text-[11.5px] text-slate-400" style={{ direction: 'ltr' }}>{pickedUser.id} · {pickedUser.phone}</div>
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
                <div className="max-h-[200px] space-y-1 overflow-y-auto pe-1">
                  {pool.map((u) => {
                    const isMerchant = !!u.merchantId;
                    const blocked = u.accountStatus === 'banned';
                    const disabled = isMerchant || blocked;
                    return (
                      <button key={u.id} disabled={disabled} onClick={() => setPickedUser(u)}
                        className={`flex w-full items-center gap-3 rounded-lg border px-3 py-2 text-start transition ${disabled ? 'cursor-not-allowed border-slate-100 bg-slate-50/40 opacity-60' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'}`}>
                        <Avatar name={u.name} lang={lang} size={34} />
                        <div className="min-w-0 flex-1">
                          <div className="flex items-center gap-2"><span className="truncate text-[13px] font-semibold text-slate-700">{tt(u.name, lang)}</span>{u.accountStatus !== 'active' && <AccountChip status={u.accountStatus} lang={lang} />}</div>
                          <div className="font-mono text-[11.5px] text-slate-400" style={{ direction: 'ltr' }}>{u.phone}</div>
                        </div>
                        {isMerchant
                          ? <span className="shrink-0 rounded-md bg-slate-100 px-2 py-1 text-[10.5px] font-semibold text-slate-400">{lang === 'ar' ? 'تاجر بالفعل' : 'Already a merchant'}</span>
                          : blocked ? <span className="shrink-0 text-[11px] font-semibold text-rose-400">{lang === 'ar' ? 'محظور' : 'Banned'}</span>
                          : <span className="shrink-0 text-slate-300"><Icon name={lang === 'ar' ? 'chevronL' : 'chevronR'} size={16} /></span>}
                      </button>
                    );
                  })}
                  {pool.length === 0 && <div className="py-6 text-center text-[13px] text-slate-400">{lang === 'ar' ? 'لا يوجد مستخدمون مطابقون' : 'No matching users'}</div>}
                </div>
              </>
            )}
          </div>

          {/* business */}
          <div>
            <div className="mb-2.5 flex items-center gap-2 text-[12px] font-semibold uppercase tracking-wide text-slate-400"><Icon name="merchants" size={15} />{lang === 'ar' ? 'النشاط التجاري' : 'Business'}</div>
            <div className="grid grid-cols-2 gap-3">
              <MField label={lang === 'ar' ? 'اسم النشاط (عربي)' : 'Business name (Arabic)'} required><input className={mInputCls} value={f.businessAr} onChange={(e) => set('businessAr', e.target.value)} placeholder="صيدلية النهضة" /></MField>
              <MField label={lang === 'ar' ? 'اسم النشاط (إنجليزي)' : 'Business name (English)'}><input className={mInputCls} value={f.business} onChange={(e) => set('business', e.target.value)} placeholder="Al-Nahda Pharmacy" style={{ direction: 'ltr' }} /></MField>
              <MField label={lang === 'ar' ? 'هاتف النشاط' : 'Business phone'} required><input className={mInputCls} value={f.businessPhone} onChange={(e) => set('businessPhone', e.target.value)} placeholder="021 XXX XXXX" style={{ direction: 'ltr' }} /></MField>
            </div>
          </div>

          {/* rates */}
          <div>
            <div className="mb-2.5 flex items-center gap-2 text-[12px] font-semibold uppercase tracking-wide text-slate-400"><Icon name="coins" size={15} />{lang === 'ar' ? 'تجاوز الأسعار (اختياري)' : 'Rate overrides (optional)'}</div>
            <div className="grid grid-cols-2 gap-3">
              <MField label={lang === 'ar' ? 'العمولة %' : 'Commission %'} hint={lang === 'ar' ? `افتراضي ${Math.round(PLATFORM_SETTINGS.pricing.item_commission_rate * 100)}%` : `default ${Math.round(PLATFORM_SETTINGS.pricing.item_commission_rate * 100)}%`}>
                <input type="number" className={mInputCls} value={f.commission} onChange={(e) => set('commission', e.target.value)} placeholder={String(Math.round(PLATFORM_SETTINGS.pricing.item_commission_rate * 100))} style={{ direction: 'ltr' }} />
              </MField>
              <MField label={lang === 'ar' ? 'حصة التوصيل %' : 'Delivery cut %'} hint={lang === 'ar' ? `افتراضي ${Math.round(PLATFORM_SETTINGS.pricing.driver_fee_cut_rate * 100)}%` : `default ${Math.round(PLATFORM_SETTINGS.pricing.driver_fee_cut_rate * 100)}%`}>
                <input type="number" className={mInputCls} value={f.driverCut} onChange={(e) => set('driverCut', e.target.value)} placeholder={String(Math.round(PLATFORM_SETTINGS.pricing.driver_fee_cut_rate * 100))} style={{ direction: 'ltr' }} />
              </MField>
            </div>
            <p className="mt-1.5 text-[11px] text-slate-400">{lang === 'ar' ? 'اتركه فارغًا لاستخدام سعر المنصّة الافتراضي.' : 'Leave blank to use the platform default.'}</p>
          </div>

          {/* pickup */}
          <div>
            <div className="mb-2.5 flex items-center gap-2 text-[12px] font-semibold uppercase tracking-wide text-slate-400"><Icon name="pin" size={15} />{lang === 'ar' ? 'الاستلام الافتراضي' : 'Default pickup'}</div>
            <div className="grid grid-cols-1 gap-3">
              <MField label={lang === 'ar' ? 'العنوان' : 'Address'}><input className={mInputCls} value={lang === 'ar' ? f.pickupAr : f.pickup} onChange={(e) => set(lang === 'ar' ? 'pickupAr' : 'pickup', e.target.value)} placeholder={lang === 'ar' ? 'شارع بن عاشور…' : 'Ben Ashour St…'} /></MField>
              <MField label={lang === 'ar' ? 'المنطقة' : 'District'}><input className={mInputCls} value={f.pickupDist} onChange={(e) => set('pickupDist', e.target.value)} placeholder={lang === 'ar' ? 'بن عاشور' : 'Ben Ashour'} /></MField>
            </div>
          </div>

          {/* notes */}
          <div>
            <div className="mb-2.5 flex items-center gap-2 text-[12px] font-semibold uppercase tracking-wide text-slate-400"><Icon name="doc" size={15} />{lang === 'ar' ? 'ملاحظات' : 'Notes'}</div>
            <textarea value={f.notes} onChange={(e) => set('notes', e.target.value)} rows={2} placeholder={lang === 'ar' ? 'تعليمات الاستلام، أوقات الذروة…' : 'Pickup instructions, peak hours…'}
              className="w-full resize-none rounded-lg border border-slate-200 bg-white px-3 py-2 text-[13px] text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-slate-300 focus:ring-2 focus:ring-[var(--accent-soft)]" />
          </div>
        </div>

        <div className="flex shrink-0 items-center justify-between gap-2.5 border-t border-slate-200/70 px-6 py-4">
          <span className="text-[11.5px] text-slate-400">{!valid && (lang === 'ar' ? 'أكمل المالك واسم النشاط والهاتف' : 'Complete owner, business name and phone')}</span>
          <div className="flex items-center gap-2.5">
            <button onClick={onClose} className="h-10 rounded-lg px-4 text-[13.5px] font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-700">{lang === 'ar' ? 'إلغاء' : 'Cancel'}</button>
            <button onClick={submit} disabled={!valid}
              className={`inline-flex h-10 items-center gap-2 rounded-lg px-4 text-[13.5px] font-semibold text-white shadow-sm transition ${valid ? '' : 'cursor-not-allowed opacity-40'}`}
              style={{ background: 'var(--accent)' }}>
              <Icon name="checkCircle" size={17} />{editing ? (lang === 'ar' ? 'حفظ التغييرات' : 'Save changes') : (lang === 'ar' ? 'إنشاء التاجر (نشط)' : 'Create merchant (active)')}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { MerchantForm });
