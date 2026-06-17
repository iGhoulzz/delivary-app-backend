// map.jsx — stylized, self-contained vector map of Tripoli with pan + zoom.
// Wheel to zoom toward the cursor, drag to pan, +/−/reset controls.

function TripoliMap({ lang, dark, playful, selectedOffice, onSelectOffice }) {
  const ROADS = {
    'road-a': 'M120,186 C300,150 430,250 560,224 S792,300 888,250',
    'road-b': 'M512,168 C496,286 620,356 600,468 S566,556 648,584',
    'road-c': 'M828,210 C708,266 724,360 644,422 S520,462 444,420',
    'road-d': 'M244,206 C326,322 448,360 528,442 S704,498 762,462',
  };
  const accent = 'var(--accent)';
  const C = dark ? {
    land1: '#131c2e', land2: '#0e1525', sea1: '#16243d', sea2: '#101b30', coast: '#27405f',
    grid: '#ffffff', gridOp: 0.04, zoneFill: '#5b7da6', zoneOp: 0.10, zoneStroke: '#7d97b8', zoneStrokeOp: 0.20,
    road: '#26344c', roadCore: '#3c4d6b', label: '#9fb2cc', seaLabel: '#3f5f86', idle: '#64748b',
    tipBg: '#0b1120', tipName: '#f1f5f9', tipSub: '#94a3b8',
  } : {
    land1: '#f6f8f6', land2: '#eef1ee', sea1: '#dbeafe', sea2: '#eaf2fb', coast: '#bcd3ee',
    grid: '#0f172a', gridOp: 0.035, zoneFill: '#9fb0a6', zoneOp: 0.10, zoneStroke: '#94a3a0', zoneStrokeOp: 0.22,
    road: '#ffffff', roadCore: '#cdd7d2', label: '#5b6b64', seaLabel: '#7ba0cf', idle: '#94a3b8',
    tipBg: '#0f172a', tipName: '#fff', tipSub: '#94a3b8',
  };
  const fontFam = playful ? "'Baloo Bhaijaan 2','Fredoka',sans-serif" : (lang === 'ar' ? "'IBM Plex Sans Arabic'" : "'IBM Plex Sans'");
  const VW = 1000, VH = 620;
  const MIN = 1, MAX = 6;

  const svgRef = React.useRef(null);
  const [view, setView] = React.useState({ s: 1, tx: 0, ty: 0 });
  const drag = React.useRef(null);

  // clamp pan so the (scaled) map never reveals empty gutters
  function clampView(v) {
    const s = Math.min(MAX, Math.max(MIN, v.s));
    const minTx = VW - VW * s, minTy = VH - VH * s;
    return { s, tx: Math.min(0, Math.max(minTx, v.tx)), ty: Math.min(0, Math.max(minTy, v.ty)) };
  }

  // client coords -> viewBox coords, honoring preserveAspectRatio
  function toViewBox(clientX, clientY) {
    const svg = svgRef.current;
    const ctm = svg.getScreenCTM();
    const p = svg.createSVGPoint();
    p.x = clientX; p.y = clientY;
    return p.matrixTransform(ctm.inverse());
  }

  function zoomAt(clientX, clientY, factor) {
    setView((prev) => {
      const sNew = Math.min(MAX, Math.max(MIN, prev.s * factor));
      if (sNew === prev.s) return prev;
      const vb = toViewBox(clientX, clientY);
      // keep the point under the cursor fixed: vb = tx + s * p
      const px = (vb.x - prev.tx) / prev.s;
      const py = (vb.y - prev.ty) / prev.s;
      return clampView({ s: sNew, tx: vb.x - sNew * px, ty: vb.y - sNew * py });
    });
  }

  // non-passive wheel listener so we can preventDefault
  React.useEffect(() => {
    const svg = svgRef.current;
    if (!svg) return;
    const onWheel = (e) => {
      e.preventDefault();
      zoomAt(e.clientX, e.clientY, e.deltaY < 0 ? 1.18 : 1 / 1.18);
    };
    svg.addEventListener('wheel', onWheel, { passive: false });
    return () => svg.removeEventListener('wheel', onWheel);
  }, []);

  function onPointerDown(e) {
    if (e.button === 1 || e.target.closest('[data-pin]')) { /* allow pin click */ }
    drag.current = { x: e.clientX, y: e.clientY, moved: false };
    e.currentTarget.setPointerCapture(e.pointerId);
  }
  function onPointerMove(e) {
    if (!drag.current) return;
    const ctm = svgRef.current.getScreenCTM();
    const dx = (e.clientX - drag.current.x) / ctm.a;
    const dy = (e.clientY - drag.current.y) / ctm.d;
    if (Math.abs(e.clientX - drag.current.x) + Math.abs(e.clientY - drag.current.y) > 3) drag.current.moved = true;
    drag.current.x = e.clientX; drag.current.y = e.clientY;
    setView((prev) => clampView({ s: prev.s, tx: prev.tx + dx, ty: prev.ty + dy }));
  }
  function onPointerUp(e) {
    if (drag.current) { try { e.currentTarget.releasePointerCapture(e.pointerId); } catch (_) {} }
    drag.current = null;
  }
  function btnZoom(factor) {
    const r = svgRef.current.getBoundingClientRect();
    zoomAt(r.left + r.width / 2, r.top + r.height / 2, factor);
  }

  const zoomed = view.s > 1.01;

  return (
    <div className="relative h-full w-full overflow-hidden">
      <svg ref={svgRef} viewBox="0 0 1000 620" className="block h-full w-full select-none"
        preserveAspectRatio="xMidYMid slice"
        style={{ direction: 'ltr', cursor: zoomed ? 'grab' : 'default', touchAction: 'none' }}
        onPointerDown={onPointerDown} onPointerMove={onPointerMove} onPointerUp={onPointerUp} onPointerLeave={onPointerUp}
        role="img" aria-label={lang === 'ar' ? 'خريطة طرابلس' : 'Map of Tripoli'}>
        <defs>
          <linearGradient id="sea" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stopColor={C.sea1} />
            <stop offset="1" stopColor={C.sea2} />
          </linearGradient>
          <linearGradient id="land" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stopColor={C.land1} />
            <stop offset="1" stopColor={C.land2} />
          </linearGradient>
          <pattern id="grid" width="34" height="34" patternUnits="userSpaceOnUse">
            <path d="M34 0H0V34" fill="none" stroke={C.grid} strokeOpacity={C.gridOp} strokeWidth="1" />
          </pattern>
          {Object.entries(ROADS).map(([id, d]) => <path key={id} id={id} d={d} fill="none" />)}
        </defs>

        <g transform={`translate(${view.tx} ${view.ty}) scale(${view.s})`}>
          {/* base land */}
          <rect x="0" y="0" width="1000" height="620" fill="url(#land)" />
          <rect x="0" y="0" width="1000" height="620" fill="url(#grid)" />

          {/* sea + coastline */}
          <path d="M0,0 H1000 V96 C880,128 760,86 612,108 C470,128 360,92 232,118 C150,134 70,108 0,124 Z" fill="url(#sea)" />
          <path d="M0,124 C70,108 150,134 232,118 C360,92 470,128 612,108 C760,86 880,128 1000,96" fill="none" stroke={C.coast} strokeWidth="2.5" />
          <text x="60" y="64" fontSize="22" fill={C.seaLabel} fontWeight="600"
            letterSpacing={lang === 'ar' ? '0' : '2'}
            style={{ textTransform: lang === 'ar' ? 'none' : 'uppercase', fontFamily: fontFam }}>{lang === 'ar' ? 'البحر المتوسط' : 'Mediterranean Sea'}</text>

          {/* district zones */}
          {DISTRICTS.map((d) => (
            <g key={d.id}>
              <circle cx={d.x} cy={d.y} r={d.r} fill={C.zoneFill} fillOpacity={C.zoneOp} />
              <circle cx={d.x} cy={d.y} r={d.r} fill="none" stroke={C.zoneStroke} strokeOpacity={C.zoneStrokeOp} strokeDasharray="3 5" />
            </g>
          ))}

          {/* roads */}
          {Object.keys(ROADS).map((id) => (
            <use key={id} href={'#' + id} fill="none" stroke={C.road} strokeWidth="10" strokeOpacity={dark ? '0.7' : '0.9'} strokeLinecap="round" />
          ))}
          {Object.keys(ROADS).map((id) => (
            <use key={id + '-c'} href={'#' + id} fill="none" stroke={C.roadCore} strokeWidth="2" strokeLinecap="round" />
          ))}

          {/* district labels */}
          {DISTRICTS.map((d) => (
            <text key={d.id + 't'} x={d.x} y={d.y} textAnchor="middle" fontSize="16" fill={C.label} fontWeight="600"
              style={{ fontFamily: fontFam }}>
              {tt(d.name, lang)}
            </text>
          ))}

          {/* office pins */}
          {OFFICES.map((o) => {
            const sel = selectedOffice === o.id;
            return (
              <g key={o.id} data-pin transform={`translate(${o.x},${o.y})`} style={{ cursor: 'pointer' }}
                onClick={() => { if (!drag.current || !drag.current.moved) onSelectOffice && onSelectOffice(sel ? null : o.id); }}>
                {o.hq && <circle r="26" fill={accent} fillOpacity="0.12" />}
                <g className="map-pin" style={{ animationDelay: (o.x % 7) * 0.12 + 's' }}>
                  <g transform="translate(0,-2)">
                    <path d={pinPath()} fill={accent} stroke={dark ? '#0b1120' : '#fff'} strokeWidth="2"
                      style={{ filter: 'drop-shadow(0 2px 3px rgba(15,23,42,.25))' }} />
                    <g transform="translate(-6.5,-30) scale(0.55)" stroke={dark ? '#0b1120' : '#fff'} strokeWidth="2.4" fill="none" strokeLinecap="round">
                      <rect x="2" y="0" width="16" height="22" rx="1.5" />
                      <path d="M6 5h2M12 5h2M6 10h2M12 10h2M6 15h2M12 15h2" />
                    </g>
                  </g>
                </g>
                {sel && (
                  <g transform="translate(0,16)">
                    <rect x="-92" y="0" width="184" height="46" rx="8" fill={C.tipBg} />
                    <text x="0" y="19" textAnchor="middle" fontSize="14" fill={C.tipName} fontWeight="600"
                      style={{ fontFamily: fontFam }}>{tt(o.name, lang)}</text>
                    <text x="0" y="36" textAnchor="middle" fontSize="12" fill={C.tipSub}
                      style={{ fontFamily: fontFam }}>{(lang === 'ar' ? 'الطاقم: ' : 'Staff: ') + num(o.staff, lang)}</text>
                  </g>
                )}
              </g>
            );
          })}

          {/* idle drivers */}
          {DRIVERS.filter((d) => d.status === 'idle').map((d) => (
            <g key={d.id} transform={`translate(${d.x + 22},${d.y - 4})`}>
              <circle r="6" fill={C.idle} stroke={dark ? '#0b1120' : '#fff'} strokeWidth="2" />
            </g>
          ))}

          {/* live moving drivers */}
          {DRIVERS.filter((d) => d.status === 'moving').map((d) => (
            <g key={d.id}>
              <circle r="13" fill="#16a34a" fillOpacity="0.18">
                <animate attributeName="r" values="8;18;8" dur="2.4s" repeatCount="indefinite" />
                <animate attributeName="fill-opacity" values="0.28;0;0.28" dur="2.4s" repeatCount="indefinite" />
              </circle>
              <circle r="6.5" fill="#16a34a" stroke="#fff" strokeWidth="2.4" />
              <animateMotion dur={d.dur + 's'} repeatCount="indefinite" rotate="0">
                <mpath href={'#' + d.path} />
              </animateMotion>
            </g>
          ))}
        </g>
      </svg>

      {/* zoom controls */}
      <div className="absolute bottom-3 flex flex-col gap-1.5" style={{ insetInlineEnd: '12px' }}>
        <MapBtn label={lang === 'ar' ? 'تكبير' : 'Zoom in'} onClick={() => btnZoom(1.4)} icon="plus" />
        <MapBtn label={lang === 'ar' ? 'تصغير' : 'Zoom out'} onClick={() => btnZoom(1 / 1.4)} icon="minus" />
        {zoomed && <MapBtn label={lang === 'ar' ? 'إعادة' : 'Reset'} onClick={() => setView({ s: 1, tx: 0, ty: 0 })} icon="reset" />}
      </div>

      {/* hint */}
      <div className="pointer-events-none absolute top-3 select-none rounded-md bg-white/85 px-2.5 py-1 text-[11.5px] font-medium text-slate-500 ring-1 ring-slate-200 backdrop-blur"
        style={{ insetInlineStart: '12px' }}>
        {lang === 'ar' ? 'مرّر للتكبير · اسحب للتحريك' : 'Scroll to zoom · drag to pan'}
      </div>
    </div>
  );
}

function MapBtn({ onClick, icon, label }) {
  const PATHS = {
    plus: <path d="M12 5v14M5 12h14" />,
    minus: <path d="M5 12h14" />,
    reset: <><path d="M4 9a8 8 0 1 1-1.5 5" /><path d="M3 4v5h5" /></>,
  };
  return (
    <button onClick={onClick} aria-label={label} title={label}
      className="grid h-9 w-9 place-items-center rounded-lg border border-slate-200 bg-white/95 text-slate-600 shadow-sm backdrop-blur transition hover:bg-white hover:text-slate-900">
      <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round">{PATHS[icon]}</svg>
    </button>
  );
}

function pinPath() {
  return 'M0 8 C-13 8 -19 -6 -19 -15 A19 19 0 1 1 19 -15 C19 -6 13 8 0 8 Z';
}

Object.assign(window, { TripoliMap });
