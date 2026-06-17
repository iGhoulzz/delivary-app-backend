// settings.jsx — Platform Settings / Pricing Controls. Reflects backend
// platform_settings (source of truth). Edits affect NEW quotes only; historical
// orders keep their snapshot. Not a hardcoded UI constant.

function SettingRow({ label, hint, keyName, children }) {
  return (
    <div className="flex items-center justify-between gap-4 rounded-xl border border-slate-200/80 bg-white/60 px-4 py-3">
      <div className="min-w-0">
        <div className="text-[13px] font-semibold text-slate-700">{label}</div>
        {keyName && <code className="mt-0.5 inline-block rounded bg-slate-100 px-1.5 py-px font-mono text-[10.5px] text-slate-500" style={{ direction: 'ltr' }}>{keyName}</code>}
        {hint && <div className="text-[11.5px] text-slate-400">{hint}</div>}
      </div>
      <div className="shrink-0">{children}</div>
    </div>
  );
}

function NumInput({ value, onChange, suffix, step, w }) {
  return (
    <div className="relative" style={{ width: w || 110 }}>
      <input type="number" step={step || 'any'} value={value} onChange={(e) => onChange(e.target.value)}
        className="h-9 w-full rounded-lg border border-slate-200 bg-white px-2.5 text-[13.5px] font-semibold text-slate-800 outline-none transition focus:border-slate-300 focus:ring-2 focus:ring-[var(--accent-soft)]" style={{ direction: 'ltr' }} />
      {suffix && <span className="pointer-events-none absolute inset-y-0 end-2.5 flex items-center text-[11px] font-medium text-slate-400">{suffix}</span>}
    </div>
  );
}

function Toggle({ on, onClick }) {
  return (
    <button onClick={onClick} className={`inline-flex h-6 w-11 items-center rounded-full px-0.5 transition ${on ? '' : 'bg-slate-200'}`} style={on ? { background: 'var(--accent)' } : undefined}>
      <span className={`h-5 w-5 rounded-full bg-white shadow transition ${on ? (document.dir === 'rtl' ? '-translate-x-5' : 'translate-x-5') : ''}`} />
    </button>
  );
}

function SettingsGroup({ title, icon, children }) {
  return (
    <Card className="p-5">
      <div className="mb-3.5 flex items-center gap-2"><span className="text-slate-400"><Icon name={icon} size={17} /></span><span className="text-[14px] font-bold text-slate-800">{title}</span></div>
      <div className="space-y-2">{children}</div>
    </Card>
  );
}

function Settings({ lang, settings, setSettings, dirty, onSave, onReset }) {
  const pr = settings.pricing, pa = settings.payouts, st = settings.settlement, rk = settings.risk;
  const setP = (k, v) => setSettings((s) => ({ ...s, pricing: { ...s.pricing, [k]: v } }));
  const setPa = (k, v) => setSettings((s) => ({ ...s, payouts: { ...s.payouts, [k]: v } }));
  const setSt = (k, v) => setSettings((s) => ({ ...s, settlement: { ...s.settlement, [k]: v } }));
  const setRk = (k, v) => setSettings((s) => ({ ...s, risk: { ...s.risk, [k]: v } }));
  const pct = (v) => Math.round(v * 1000) / 10; // fraction → % (1 decimal)
  const toFrac = (v) => (+v) / 100;

  return (
    <div className="mx-auto max-w-[1100px] p-5 lg:p-7">
      {/* caveat banner */}
      <div className="mb-4 flex items-start gap-3 rounded-xl border border-blue-200 px-4 py-3" style={{ background: tint('#2563eb', 6) }}>
        <span className="mt-0.5 shrink-0 text-blue-600"><Icon name="overview" size={18} /></span>
        <div className="flex-1 text-[12.5px] leading-relaxed text-blue-900">
          <span className="font-bold">{lang === 'ar' ? 'إعدادات المنصّة (مصدرها الخادم).' : 'Platform settings (backend-controlled).'}</span>{' '}
          {lang === 'ar'
            ? 'هذه القيم تُستخدم لتسعير الطلبات الجديدة فقط. الطلبات السابقة تحتفظ بلقطة أسعارها وقت الإنشاء ولا تتأثر. ليست ثوابت واجهة.'
            : 'These values price NEW quotes only. Historical orders keep the rate snapshot taken at creation and are unaffected. Not hardcoded UI constants.'}
        </div>
      </div>

      {/* legacy-key warning */}
      <div className="mb-4 flex items-start gap-3 rounded-xl border border-amber-200 px-4 py-3" style={{ background: tint('#d97706', 7) }}>
        <span className="mt-0.5 shrink-0 text-amber-600"><Icon name="alert" size={18} /></span>
        <div className="flex-1 text-[12.5px] leading-relaxed text-amber-800">
          <span className="font-bold">{lang === 'ar' ? 'مفاتيح مُنمّطة (namespaced).' : 'Namespaced keys only.'}</span>{' '}
          {lang === 'ar'
            ? 'تُحرّر هذه الشاشة مفاتيح '
            : 'This screen edits the '}
          <code className="rounded bg-white/70 px-1 py-px font-mono text-[11px] text-amber-900" style={{ direction: 'ltr' }}>pricing.*</code>
          {lang === 'ar'
            ? ' الحالية. تجاهل المفاتيح القديمة المتشابهة '
            : ' keys the pricing code uses. Ignore the legacy-looking keys '}
          <code className="rounded bg-white/70 px-1 py-px font-mono text-[10.5px] text-amber-900" style={{ direction: 'ltr' }}>driver_fee_cut_rate</code>, <code className="rounded bg-white/70 px-1 py-px font-mono text-[10.5px] text-amber-900" style={{ direction: 'ltr' }}>commission_rate_default</code>, <code className="rounded bg-white/70 px-1 py-px font-mono text-[10.5px] text-amber-900" style={{ direction: 'ltr' }}>min_payout_amount</code>
          {lang === 'ar' ? ' — فهي غير مستخدمة.' : ' — they are not the ones in use.'}
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <SettingsGroup title={lang === 'ar' ? 'التسعير' : 'Pricing'} icon="coins">
          <SettingRow keyName="pricing.item_commission_rate" label={lang === 'ar' ? 'عمولة السلعة' : 'Item commission rate'} hint={lang === 'ar' ? 'على قيمة السلعة لطلبات البيع' : 'on item_price for sale orders'}>
            <NumInput value={pct(pr.item_commission_rate)} onChange={(v) => setP('item_commission_rate', toFrac(v))} suffix="%" />
          </SettingRow>
          <SettingRow keyName="pricing.driver_fee_cut_rate" label={lang === 'ar' ? 'حصة المنصّة من رسوم التوصيل' : 'Driver fee cut rate'} hint={lang === 'ar' ? 'نسبة المنصّة من رسوم التوصيل' : "platform's cut from delivery fee"}>
            <NumInput value={pct(pr.driver_fee_cut_rate)} onChange={(v) => setP('driver_fee_cut_rate', toFrac(v))} suffix="%" />
          </SettingRow>
          <SettingRow keyName="pricing.delivery_fee_base" label={lang === 'ar' ? 'رسوم التوصيل الأساسية' : 'Delivery base fee'}>
            <NumInput value={pr.delivery_fee_base} onChange={(v) => setP('delivery_fee_base', +v)} suffix={lang === 'ar' ? 'د.ل' : 'LYD'} />
          </SettingRow>
          <SettingRow keyName="pricing.free_km" label={lang === 'ar' ? 'المسافة المجانية' : 'Free distance'} hint={lang === 'ar' ? 'قبل احتساب الكيلومتر' : 'before per-km charge'}>
            <NumInput value={pr.free_km} onChange={(v) => setP('free_km', +v)} suffix={lang === 'ar' ? 'كم' : 'km'} />
          </SettingRow>
          <SettingRow keyName="pricing.per_km_rate" label={lang === 'ar' ? 'سعر الكيلومتر الإضافي' : 'Per-km rate'}>
            <NumInput value={pr.per_km_rate} onChange={(v) => setP('per_km_rate', +v)} suffix={lang === 'ar' ? 'د.ل' : 'LYD'} />
          </SettingRow>
          <div className="flex items-center justify-between rounded-lg bg-slate-50/80 px-3 py-2">
            <span className="text-[11.5px] text-slate-400">{lang === 'ar' ? 'معاملات حجم السلعة — إعداد منفصل' : 'Item size modifiers — separate editor'}</span>
            <code className="rounded bg-white px-1.5 py-px font-mono text-[10.5px] text-slate-500 ring-1 ring-slate-200" style={{ direction: 'ltr' }}>pricing.item_size_modifiers</code>
          </div>
        </SettingsGroup>

        <div className="space-y-4">
          <SettingsGroup title={lang === 'ar' ? 'المدفوعات' : 'Payouts'} icon="send">
            <SettingRow keyName="payouts.clearance_hours" label={lang === 'ar' ? 'مهلة المقاصّة' : 'Clearance window'} hint={lang === 'ar' ? 'تأخير إتاحة أرباح البائع' : 'seller earning clearance delay'}>
              <NumInput value={pa.clearance_hours} onChange={(v) => setPa('clearance_hours', +v)} suffix={lang === 'ar' ? 'ساعة' : 'h'} />
            </SettingRow>
            <SettingRow keyName="payouts.min_amount" label={lang === 'ar' ? 'الحد الأدنى للصرف' : 'Minimum payout'}>
              <NumInput value={pa.min_amount} onChange={(v) => setPa('min_amount', +v)} suffix={lang === 'ar' ? 'د.ل' : 'LYD'} />
            </SettingRow>
            <SettingRow keyName="payouts.allow_partial" label={lang === 'ar' ? 'السماح بالصرف الجزئي' : 'Allow partial payout'} hint={lang === 'ar' ? 'صرف أرباح محدّدة' : 'pay out selected earnings'}>
              <Toggle on={pa.allow_partial} onClick={() => setPa('allow_partial', !pa.allow_partial)} />
            </SettingRow>
          </SettingsGroup>

          <SettingsGroup title={lang === 'ar' ? 'التسوية والمخاطر' : 'Settlement & risk'} icon="shield">
            <SettingRow keyName="settlement.reverse_window_hours" label={lang === 'ar' ? 'مهلة عكس التسوية' : 'Reversal window'} hint={lang === 'ar' ? 'حدّ زمني اختياري للعكس' : 'optional cap on reversal'}>
              <NumInput value={st.reverse_window_hours} onChange={(v) => setSt('reverse_window_hours', +v)} suffix={lang === 'ar' ? 'ساعة' : 'h'} />
            </SettingRow>
            <SettingRow keyName="new_driver_max_liability" label={lang === 'ar' ? 'حد حيازة السائق الجديد' : 'New-driver max liability'} hint={lang === 'ar' ? 'أقصى نقد قبل حظر طلبات النقد' : 'max cash before blocking cash orders'}>
              <NumInput value={rk.new_driver_max_liability} onChange={(v) => setRk('new_driver_max_liability', +v)} suffix={lang === 'ar' ? 'د.ل' : 'LYD'} />
            </SettingRow>
          </SettingsGroup>
        </div>
      </div>

      {/* save bar */}
      <div className="sticky bottom-4 mt-5 flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white/90 px-4 py-3 shadow-lg" style={{ backdropFilter: 'blur(8px)' }}>
        <span className="text-[12.5px] text-slate-500">{dirty ? (lang === 'ar' ? 'تغييرات غير محفوظة — ستُطبّق على التسعير الجديد فقط.' : 'Unsaved changes — will apply to new quotes only.') : (lang === 'ar' ? 'كل الإعدادات محفوظة.' : 'All settings saved.')}</span>
        <div className="flex items-center gap-2.5">
          <button onClick={onReset} disabled={!dirty} className={`h-9 rounded-lg px-3.5 text-[13px] font-semibold transition ${dirty ? 'text-slate-500 hover:bg-slate-100 hover:text-slate-700' : 'cursor-not-allowed text-slate-300'}`}>{lang === 'ar' ? 'تجاهل' : 'Discard'}</button>
          <button onClick={onSave} disabled={!dirty} className={`inline-flex h-9 items-center gap-2 rounded-lg px-4 text-[13px] font-semibold text-white shadow-sm transition ${dirty ? '' : 'cursor-not-allowed opacity-40'}`} style={{ background: 'var(--accent)' }}>
            <Icon name="check" size={16} />{lang === 'ar' ? 'حفظ الإعدادات' : 'Save settings'}
          </button>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { Settings });
