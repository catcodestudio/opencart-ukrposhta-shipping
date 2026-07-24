(() => {
  const cfg = window.__ukrposhta;
  if (!cfg || window.__upPickerMounted) return;

  // Storefront checkout only.
  const route = new URLSearchParams(location.search).get('route') || '';
  if (route !== 'checkout/checkout') return;

  const accent = /^#[0-9a-fA-F]{6}$/.test(cfg.accentColor || '') ? cfg.accentColor : '#374151';
  const radiusRaw = parseInt(cfg.radius, 10);
  const radius = Number.isFinite(radiusRaw) ? Math.max(0, Math.min(28, radiusRaw)) : 14;
  const theme = ['auto', 'light', 'dark'].includes(cfg.theme) ? cfg.theme : 'auto';
  const surfaceVars = theme === 'light'
    ? '--up-surface:#fff;--up-text:#18181b;--up-muted:#71717a;--up-line:#e4e4e7;--up-soft:#f4f4f5;'
    : theme === 'dark'
      ? '--up-surface:#1e1e22;--up-text:#f4f4f5;--up-muted:#a1a1aa;--up-line:#33333a;--up-soft:#26262b;'
      : '--up-surface:var(--bs-body-bg,#fff);--up-text:var(--bs-body-color,#18181b);--up-muted:var(--bs-secondary-color,#71717a);--up-line:var(--bs-border-color,#e4e4e7);--up-soft:var(--bs-secondary-bg,#f4f4f5);';

  const STYLE = `
  .up-box{--up-accent:${accent};--up-radius:${radius}px;${surfaceVars}border:1px solid var(--up-line);border-radius:var(--up-radius);margin:0 0 20px;background:var(--up-surface);box-shadow:0 1px 2px rgba(0,0,0,.04);}
  .up-box__head{display:flex;align-items:center;gap:10px;font-weight:600;font-size:15px;padding:14px 18px;color:var(--up-text);background:color-mix(in srgb,var(--up-accent) 14%,transparent);border-bottom:1px solid var(--up-line);border-radius:var(--up-radius) var(--up-radius) 0 0;}
  .up-box__logo{width:26px;height:26px;border-radius:calc(var(--up-radius) * .45);background:var(--up-accent);color:#0f1b3d;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;letter-spacing:-.5px;flex:none;}
  .up-box__body{padding:16px 18px 16px;}
  .up-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
  .up-grid .up-field--region{grid-column:1 / -1;}
  @media(max-width:640px){.up-grid{grid-template-columns:1fr;}}
  .up-field{position:relative;}
  .up-field>label{display:block;font-size:12px;font-weight:500;color:var(--up-muted);margin-bottom:6px;}
  .up-input{width:100%;height:44px;padding:0 38px 0 13px;border:1px solid var(--up-line);border-radius:calc(var(--up-radius) * .7);font-size:14px;line-height:42px;background:var(--up-surface);color:var(--up-text);transition:border-color .15s,box-shadow .15s;}
  .up-input::placeholder{color:var(--up-muted);opacity:.8;}
  .up-input:focus{outline:none;border-color:var(--up-accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--up-accent) 30%,transparent);}
  .up-input:disabled{background:var(--up-soft);color:var(--up-muted);cursor:not-allowed;}
  .up-field__icon{position:absolute;right:13px;bottom:0;height:44px;display:flex;align-items:center;color:var(--up-muted);pointer-events:none;}
  .up-menu{position:absolute;left:0;right:0;top:calc(100% + 5px);z-index:60;background:var(--up-surface);border:1px solid var(--up-line);border-radius:calc(var(--up-radius) * .8);box-shadow:0 10px 34px rgba(0,0,0,.16);max-height:264px;overflow-y:auto;display:none;}
  .up-menu.is-open{display:block;}
  .up-opt{padding:10px 13px;font-size:14px;cursor:pointer;border-bottom:1px solid var(--up-line);color:var(--up-text);}
  .up-opt:last-child{border-bottom:0;}
  .up-opt small{display:block;color:var(--up-muted);font-size:12px;margin-top:1px;}
  .up-opt:hover,.up-opt.is-active{background:color-mix(in srgb,var(--up-accent) 16%,transparent);}
  .up-opt--muted{color:var(--up-muted);cursor:default;text-align:center;}
  .up-opt--pin{display:inline-block;font-size:11px;font-weight:700;color:var(--up-accent);margin-right:6px;}
  .up-summary{display:inline-flex;align-items:center;gap:8px;margin-top:14px;padding:9px 13px;font-size:13px;font-weight:600;color:var(--up-text);background:color-mix(in srgb,var(--up-accent) 16%,transparent);border-radius:calc(var(--up-radius) * .65);}
  .up-summary[hidden]{display:none;}
  .up-spin{display:inline-block;width:15px;height:15px;border:2px solid color-mix(in srgb,var(--up-accent) 40%,transparent);border-top-color:var(--up-accent);border-radius:50%;animation:up-spin .6s linear infinite;vertical-align:middle;}
  @keyframes up-spin{to{transform:rotate(360deg)}}
  .up-native-hidden{display:none!important;}
  .up-gated{display:none!important;}`;

  const SVG = (p, w) => `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="${w || 1.9}" stroke-linecap="round" stroke-linejoin="round">${p}</svg>`;
  const ICON_MAP = SVG('<path d="M9 18l-6 2V6l6-2 6 2 6-2v14l-6 2-6-2Z"/><path d="M9 4v14M15 6v14"/>');
  const ICON_PIN = SVG('<path d="M20 10c0 4.4-8 12-8 12s-8-7.6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>');
  const ICON_BOX = SVG('<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>');
  const ICON_OK  = SVG('<polyline points="20 6 9 17 4 12"/>', 2.4);
  const ICON_MARK = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 9h18"/></svg>';

  const t = {
    title: 'Доставка Укрпоштою',
    region: 'Область',
    regionPh: 'Оберіть область…',
    city: 'Місто / населений пункт',
    cityPh: 'Спочатку оберіть область',
    cityReady: 'Почніть вводити назву…',
    office: 'Відділення',
    officePh: 'Спочатку оберіть місто',
    officeReady: 'Оберіть відділення або введіть індекс…',
    searching: 'Пошук…',
    loading: 'Завантажуємо відділення…',
    loadingRegions: 'Завантаження областей…',
    regionsFail: 'Області недоступні. Перевірте підключення до Укрпошти.',
    noRegion: 'Не знайдено',
    noCity: 'Нічого не знайдено',
    noOffice: 'Відділень не знайдено',
  };

  const el = (html) => { const d = document.createElement('div'); d.innerHTML = html.trim(); return d.firstElementChild; };
  const debounce = (fn, ms) => { let h; return (...a) => { clearTimeout(h); h = setTimeout(() => fn(...a), ms); }; };
  const esc = (s) => (s || '').replace(/"/g, '&quot;');

  const api = (url, body) => {
    const opts = { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' }, credentials: 'same-origin' };
    if (body) opts.body = new URLSearchParams(body);
    return fetch(url, opts).then((r) => r.json());
  };

  const wrap = el(`
    <div class="up-box">
      <div class="up-box__head"><span>${t.title}</span></div>
      <div class="up-box__body">
        <div class="up-grid">
          <div class="up-field up-field--region" data-up="region">
            <label for="up-region-q">${t.region}</label>
            <input class="up-input" id="up-region-q" type="text" autocomplete="off" spellcheck="false" data-lpignore="true" readonly onfocus="this.removeAttribute('readonly')" placeholder="${t.regionPh}">
            <span class="up-field__icon">${ICON_MAP}</span>
            <div class="up-menu" id="up-region-results" role="listbox"></div>
          </div>
          <div class="up-field" data-up="city">
            <label for="up-city-q">${t.city}</label>
            <input class="up-input" id="up-city-q" type="text" autocomplete="off" spellcheck="false" data-lpignore="true" readonly onfocus="this.removeAttribute('readonly')" placeholder="${t.cityPh}" disabled>
            <span class="up-field__icon">${ICON_PIN}</span>
            <div class="up-menu" id="up-city-results" role="listbox"></div>
          </div>
          <div class="up-field" data-up="office">
            <label for="up-office-q">${t.office}</label>
            <input class="up-input" id="up-office-q" type="text" autocomplete="off" spellcheck="false" data-lpignore="true" readonly onfocus="this.removeAttribute('readonly')" placeholder="${t.officePh}" disabled>
            <span class="up-field__icon">${ICON_BOX}</span>
            <div class="up-menu" id="up-office-results" role="listbox"></div>
            <input type="hidden" id="up-office">
          </div>
        </div>
        <div class="up-summary" id="up-summary" hidden></div>
      </div>
    </div>`);

  const style = document.createElement('style');
  style.textContent = STYLE;

  const mount = () => {
    const anchors = ['#shipping-address', '#checkout-payment-method', '#checkout-confirm', '#checkout-checkout'];
    for (const sel of anchors) {
      const node = document.querySelector(sel);
      if (!node) continue;
      const visible = node.offsetParent !== null || sel === '#checkout-checkout';
      if (!visible) continue;
      if (sel === '#shipping-address' || sel === '#checkout-checkout') {
        node.insertBefore(wrap, node.firstChild);
      } else {
        node.parentNode.insertBefore(wrap, node);
      }
      document.head.appendChild(style);
      return true;
    }
    return false;
  };

  // --- state ---
  let regions = [];
  let regionsReady = false;
  let regionId = '';
  let regionName = '';
  let cityId = '';
  let cityDistrict = '';
  let cityName = '';
  let offices = [];
  let officesLoaded = false;

  // --- offline fallback ------------------------------------------------------
  // Regions are static reality — a full oblast list keeps the picker usable
  // when the Ukrposhta API key is missing/unreachable. Cities and offices are
  // NOT faked: without live data the customer types their city and 5-digit
  // branch index manually (an index fully identifies an Ukrposhta branch).
  const STATIC_REGIONS = [
    'Вінницька', 'Волинська', 'Дніпропетровська', 'Донецька', 'Житомирська', 'Закарпатська',
    'Запорізька', 'Івано-Франківська', 'Київська', 'Кіровоградська', 'Луганська', 'Львівська',
    'Миколаївська', 'Одеська', 'Полтавська', 'Рівненська', 'Сумська', 'Тернопільська',
    'Харківська', 'Херсонська', 'Хмельницька', 'Черкаська', 'Чернівецька', 'Чернігівська', 'м. Київ',
  ].map((name, i) => ({ id: 'static-' + i, name }));

  // --- native OpenCart shipping-address bridge ---
  const NATIVE = {
    address1: '#input-shipping-address-1',
    city:     '#input-shipping-city',
    postcode: '#input-shipping-postcode',
    country:  '#input-shipping-country',
    zone:     '#input-shipping-zone',
  };
  const q1 = (s) => document.querySelector(s);
  const setVal = (elm, v) => {
    if (!elm) return;
    elm.value = v;
    elm.dispatchEvent(new Event('input',  { bubbles: true }));
    elm.dispatchEvent(new Event('change', { bubbles: true }));
  };
  // Cyrillic → Latin (national standard) so a Ukrainian oblast name can be
  // compared against a transliterated OpenCart zone list.
  const TRANSLIT = { 'а':'a','б':'b','в':'v','г':'h','ґ':'g','д':'d','е':'e','є':'ie','ж':'zh','з':'z','и':'y','і':'i','ї':'i','й':'i','к':'k','л':'l','м':'m','н':'n','о':'o','п':'p','р':'r','с':'s','т':'t','у':'u','ф':'f','х':'kh','ц':'ts','ч':'ch','ш':'sh','щ':'shch','ь':'','ю':'iu','я':'ia',"'":'','’':'' };
  const translit = (s) => (s || '').toLowerCase().split('').map((ch) => (ch in TRANSLIT ? TRANSLIT[ch] : ch)).join('');
  const normLat = (s) => (s || '').toLowerCase().replace(/[^a-z]/g, '');
  const matchZone = (zoneSel, area) => {
    const key = normLat(translit((area || '').replace(/\s*(область|обл\.?|oblast'?|м\.)\s*/gi, '')));
    if (!key) return null;
    const opts = [...zoneSel.options].filter((o) => o.value);
    return opts.find((o) => normLat(o.text).indexOf(key) === 0)
        || opts.find((o) => key.indexOf(normLat(o.text)) === 0)
        || null;
  };

  const hideNativeAddress = () => {
    const host = document.querySelector('#shipping-address');
    if (!host) return;
    [...host.children].forEach((child) => {
      if (child === wrap || child.contains(wrap)) return;
      // не ховати віджети інших перевізників (напр. Нова Пошта), лише нативну розмітку OpenCart
      if (child.classList.contains('np-box') || child.querySelector('.np-box')) return;
      child.classList.add('up-native-hidden');
    });
  };
  // Client stores may add REQUIRED custom address fields (e.g. a legacy
  // carrier text field). They live inside the hidden native address area, so
  // an unfilled one would fail register.save with an error the customer can't
  // see. Keep every empty text-like custom field satisfied; overwrite only
  // values we wrote ourselves (ccAutofill marker), never the customer's.
  const fillCustomFields = (text) => {
    const groups = {};
    document.querySelectorAll('#shipping-address input[name^="shipping_custom_field"], #shipping-address textarea[name^="shipping_custom_field"], #shipping-address select[name^="shipping_custom_field"]').forEach((el) => {
      if (wrap.contains(el)) return;
      const type = (el.type || '').toLowerCase();
      if (type === 'hidden') return;
      if (type === 'radio' || type === 'checkbox') {
        (groups[el.name] = groups[el.name] || []).push(el);
      } else if (el.tagName === 'SELECT') {
        if (!el.value) {
          const opt = [...el.options].find((o) => o.value);
          if (opt) { el.value = opt.value; el.dispatchEvent(new Event('change', { bubbles: true })); }
        }
      } else if (!(el.value || '').trim() || el.dataset.ccAutofill === '1') {
        setVal(el, text); el.dataset.ccAutofill = '1';
      }
    });
    // Required radio/checkbox groups hidden with the native form: default to the
    // first option so validation passes (the real branch comes from the picker).
    Object.values(groups).forEach((els) => {
      if (!els.some((e) => e.checked)) { els[0].checked = true; els[0].dispatchEvent(new Event('change', { bubbles: true })); }
    });
  };

  const fillNativeAddress = () => {
    // Only the *selected* carrier may write the shared native address fields.
    // Both pickers coexist on the checkout; this widget's late restore()/fill
    // would otherwise stomp a Nova Poshta choice with "Укрпошта"/first-zone
    // (AR Krym) defaults. No-op unless the Ukrposhta method is chosen.
    if (selectedShippingCode().indexOf('ukrposhta.') !== 0) return;
    const country = q1(NATIVE.country);
    if (country && country.value !== '220') {
      const opt = [...country.options].find((o) => /Україна|Ukraine/i.test(o.text));
      if (opt) { country.value = opt.value; country.dispatchEvent(new Event('change', { bubbles: true })); }
    }
    const zone = q1(NATIVE.zone);
    if (zone) {
      // Zone lists are often transliterated (e.g. "Kharkivs'ka Oblast'"), so a
      // raw Cyrillic compare against regionName never hits and the old code fell
      // back to the FIRST option — always "Avtonomna Respublika Krym".
      // Transliterate the region and match on the normalized prefix; only touch
      // the zone on a real match, never force a wrong first option.
      const opt = matchZone(zone, regionName);
      if (opt && zone.value !== opt.value) { zone.value = opt.value; zone.dispatchEvent(new Event('change', { bubbles: true })); }
    }
    setVal(q1(NATIVE.city),     cityName || 'Україна');
    setVal(q1(NATIVE.address1), officeHidden().dataset.name || 'Укрпошта');
    setVal(q1(NATIVE.postcode), officeHidden().value || '00000');
    const offName = officeHidden().dataset.name || '';
    const offPi = officeHidden().value || '';
    fillCustomFields(offName ? `${cityName}, ${offName} (${offPi})` : (cityName || 'Відділення перевізника'));
  };

  const regionInput = () => wrap.querySelector('#up-region-q');
  const regionMenu = () => wrap.querySelector('#up-region-results');
  const cityInput = () => wrap.querySelector('#up-city-q');
  const cityMenu = () => wrap.querySelector('#up-city-results');
  const officeInput = () => wrap.querySelector('#up-office-q');
  const officeMenu = () => wrap.querySelector('#up-office-results');
  const officeHidden = () => wrap.querySelector('#up-office');
  const summary = () => wrap.querySelector('#up-summary');

  const openMenu = (m) => m.classList.add('is-open');
  const closeMenu = (m) => m.classList.remove('is-open');
  const muted = (text) => `<div class="up-opt up-opt--muted">${text}</div>`;

  const renderSummary = () => {
    const s = summary();
    const offName = officeHidden().dataset.name || '';
    const pi = officeHidden().value || '';
    if (cityName && offName) {
      s.innerHTML = `${ICON_OK}<span>${cityName} — ${offName} (${pi})</span>`;
      s.hidden = false;
    } else {
      s.hidden = true;
    }
  };

  // --- region combobox ---
  const renderRegions = (filter) => {
    const menu = regionMenu();
    const f = (filter || '').trim().toLowerCase();
    const list = f ? regions.filter((r) => r.name.toLowerCase().includes(f)) : regions;
    if (!regions.length) { menu.innerHTML = muted(regionsReady ? t.regionsFail : t.loadingRegions); openMenu(menu); return; }
    if (!list.length) { menu.innerHTML = muted(t.noRegion); openMenu(menu); return; }
    menu.innerHTML = list.map((r) =>
      `<div class="up-opt" role="option" data-id="${esc(r.id)}" data-name="${esc(r.name)}">${r.name}</div>`
    ).join('');
    openMenu(menu);
  };

  const pickRegion = (id, name) => {
    regionId = id; regionName = name;
    regionInput().value = name;
    closeMenu(regionMenu());
    // reset downstream
    cityId = ''; cityName = ''; cityDistrict = '';
    const ci = cityInput(); ci.disabled = false; ci.value = ''; ci.placeholder = t.cityReady;
    const oi = officeInput(); oi.disabled = true; oi.value = ''; oi.placeholder = t.officePh;
    officeHidden().value = ''; officeHidden().dataset.name = ''; offices = [];
    renderSummary();
  };

  // --- city autocomplete (needs region) ---
  // When the classifier has no data (no API key / API down) let the customer
  // use exactly what they typed as the city — never invent a fake list.
  const manualCityOpt = (q) =>
    `<div class="up-opt" role="option" data-id="manual" data-district="" data-name="${esc(q)}">Використати: «${esc(q)}»</div>`;
  const searchCities = debounce((q) => {
    const menu = cityMenu();
    api(cfg.searchCities, { region_id: regionId, q }).then((d) => {
      const list = (d && d.cities) || [];
      if (!list.length) { menu.innerHTML = q.trim().length >= 2 ? manualCityOpt(q.trim()) : muted(t.noCity); openMenu(menu); return; }
      menu.innerHTML = list.map((c) =>
        `<div class="up-opt" role="option" data-id="${esc(c.id)}" data-district="${esc(c.district_id)}" data-name="${esc(c.name)}">${c.name}</div>`
      ).join('');
      openMenu(menu);
    }).catch(() => {
      menu.innerHTML = q.trim().length >= 2 ? manualCityOpt(q.trim()) : muted(t.noCity);
      openMenu(menu);
    });
  }, 280);

  const pickCity = (id, district, name) => {
    cityId = id; cityDistrict = district || ''; cityName = name;
    cityInput().value = name;
    closeMenu(cityMenu());
    const oi = officeInput();
    oi.disabled = false; oi.value = ''; oi.placeholder = t.loading;
    officeHidden().value = ''; officeHidden().dataset.name = ''; offices = []; officesLoaded = false;
    renderSummary();
    fillNativeAddress();
    api(cfg.getOffices, { city_id: id, district_id: cityDistrict, region_id: regionId }).then((d) => {
      offices = (d && d.offices) || [];
    }).catch(() => { offices = []; }).finally(() => {
      officesLoaded = true;
      // No live office list (no API key / manual city) — the customer enters
      // the 5-digit branch index instead; never show fake demo branches.
      oi.placeholder = offices.length ? t.officeReady : 'Введіть 5-значний індекс відділення…';
      if (oi === document.activeElement) renderOffices(oi.value);
    });
  };

  // --- office combobox (client-side filter) ---
  const renderOffices = (filter) => {
    const menu = officeMenu();
    const f = (filter || '').trim().toLowerCase();
    const list = f ? offices.filter((o) => (o.name + ' ' + o.postindex + ' ' + o.address).toLowerCase().includes(f)) : offices;
    if (!offices.length) {
      if (officesLoaded && /^\d{5}$/.test(f)) {
        // Manual entry path: a bare postindex fully identifies the branch.
        menu.innerHTML = `<div class="up-opt" role="option" data-pi="${esc(f)}" data-name="Відділення Укрпошти"><span class="up-opt--pin">${esc(f)}</span>Відділення за індексом ${esc(f)}</div>`;
      } else {
        menu.innerHTML = muted(officesLoaded ? 'Введіть 5-значний поштовий індекс відділення' : t.loading);
      }
      openMenu(menu);
      return;
    }
    if (!list.length) { menu.innerHTML = muted(t.noOffice); openMenu(menu); return; }
    menu.innerHTML = list.slice(0, 80).map((o) =>
      `<div class="up-opt" role="option" data-pi="${esc(o.postindex)}" data-name="${esc(o.name)}"><span class="up-opt--pin">${o.postindex}</span>${o.name}${o.address ? `<small>${o.address}</small>` : ''}</div>`
    ).join('');
    openMenu(menu);
  };

  const pickOffice = (pi, name) => {
    officeInput().value = `${name} (${pi})`;
    officeHidden().value = pi;
    officeHidden().dataset.name = name;
    closeMenu(officeMenu());
    renderSummary();
    fillNativeAddress();
    api(cfg.setSelection, { region_id: regionId, region_name: regionName, city_id: cityId, city_name: cityName, office_postindex: pi, office_name: name });
  };

  const keyNav = (menu, e) => {
    const opts = [...menu.querySelectorAll('.up-opt[data-id],.up-opt[data-pi]')];
    if (!opts.length) return;
    let i = opts.findIndex((o) => o.classList.contains('is-active'));
    if (e.key === 'ArrowDown') { e.preventDefault(); i = (i + 1) % opts.length; }
    else if (e.key === 'ArrowUp') { e.preventDefault(); i = (i - 1 + opts.length) % opts.length; }
    else if (e.key === 'Enter' && i >= 0) { e.preventDefault(); opts[i].click(); return; }
    else if (e.key === 'Escape') { closeMenu(menu); return; }
    else return;
    opts.forEach((o) => o.classList.remove('is-active'));
    opts[i].classList.add('is-active');
    opts[i].scrollIntoView({ block: 'nearest' });
  };

  const bind = () => {
    const ri = regionInput(), rm = regionMenu();
    const ci = cityInput(), cm = cityMenu();
    const oi = officeInput(), om = officeMenu();

    ri.addEventListener('focus', () => renderRegions(ri.value));
    ri.addEventListener('input', () => { regionId = ''; renderRegions(ri.value); });
    rm.addEventListener('click', (e) => {
      const o = e.target.closest('.up-opt[data-id]'); if (!o) return;
      pickRegion(o.dataset.id, o.dataset.name);
    });
    ri.addEventListener('keydown', (e) => keyNav(rm, e));

    ci.addEventListener('input', () => {
      const v = ci.value.trim();
      cityId = ''; renderSummary();
      if (v.length < 2) { closeMenu(cm); return; }
      cm.innerHTML = muted(`<span class="up-spin"></span> ${t.searching}`); openMenu(cm);
      searchCities(v);
    });
    cm.addEventListener('click', (e) => {
      const o = e.target.closest('.up-opt[data-id]'); if (!o) return;
      pickCity(o.dataset.id, o.dataset.district, o.dataset.name);
    });
    ci.addEventListener('keydown', (e) => keyNav(cm, e));

    oi.addEventListener('focus', () => { if (!oi.disabled) renderOffices(oi.value); });
    oi.addEventListener('input', () => renderOffices(oi.value));
    om.addEventListener('click', (e) => {
      const o = e.target.closest('.up-opt[data-pi]'); if (!o) return;
      pickOffice(o.dataset.pi, o.dataset.name);
    });
    oi.addEventListener('keydown', (e) => keyNav(om, e));

    document.addEventListener('click', (e) => {
      if (!wrap.contains(e.target)) { closeMenu(rm); closeMenu(cm); closeMenu(om); }
    });
  };

  const loadRegions = () => api(cfg.regions).then((d) => { regions = (d && d.regions) || []; }).catch(() => { regions = []; }).finally(() => { if (!regions.length) regions = STATIC_REGIONS.slice(); regionsReady = true; if (regionInput() === document.activeElement) renderRegions(regionInput().value); });

  const restore = () => {
    api(cfg.getSelection).then((d) => {
      if (!d) return;
      if (d.region_id) { regionId = d.region_id; regionName = d.region_name || regionName; if (regionName) regionInput().value = regionName; }
      if (d.city_id && d.city_name) {
        cityId = d.city_id; cityName = d.city_name;
        cityInput().disabled = false; cityInput().value = cityName; cityInput().placeholder = t.cityReady;
      }
      if (d.office_postindex) {
        const oi = officeInput(); oi.disabled = false; oi.placeholder = t.officeReady;
        officeHidden().value = d.office_postindex;
        officeHidden().dataset.name = d.office_name || '';
        oi.value = `${d.office_name || ''} (${d.office_postindex})`;
        if (cityId) {
          api(cfg.getOffices, { city_id: cityId, region_id: regionId }).then((r) => { offices = (r && r.offices) || []; });
        }
        renderSummary();
        api(cfg.setSelection, { region_id: regionId, region_name: regionName, city_id: cityId, city_name: cityName, office_postindex: d.office_postindex, office_name: d.office_name || '' });
      }
      fillNativeAddress();
    }).catch(() => {});
  };

  // --- method-first gating ---------------------------------------------------
  // Show the city/office widget only after the customer picks the Ukrposhta
  // shipping method (theme radio input[name="shipping_method"] = "ukrposhta.*").
  const selectedShippingCode = () =>
    (document.querySelector('#input-shipping-code')?.value
      || document.querySelector('input[name="shipping_method"]:checked')?.value
      || '');
  const gate = () => {
    const isUp = selectedShippingCode().indexOf('ukrposhta.') === 0;
    const _wasHidden = wrap.classList.contains('up-gated');
    wrap.classList.toggle('up-gated', !isUp);
    // Widget appeared for our carrier — on mobile it renders above the
    // method selector, so scroll it into view instead of leaving the
    // customer to hunt for it (only after a real pick, not initial render).
    if (isUp && _wasHidden && window.__ccMethodPicked) setTimeout(function () { try { wrap.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {} }, 350);
  };
  const wireGate = () => {
    document.addEventListener('change', (e) => {
      if (e.target && e.target.name === 'shipping_method') { window.__ccMethodPicked = true; gate(); }
    });
    const codeEl = document.querySelector('#input-shipping-code') || document.querySelector('#input-shipping-method');
    if (codeEl) new MutationObserver(gate).observe(codeEl, { attributes: true, attributeFilter: ['value'] });
    // fallback poll — jQuery .val() sets don't fire attribute mutations
    let n = 0; const iv = setInterval(() => { gate(); if (++n > 60) clearInterval(iv); }, 500);
  };
  // --- contacts-first confirm gate ------------------------------------------
  // The module seeds an empty guest customer server-side so shipping methods
  // can be quoted before «Зберегти дані» (method-first UX). Flip side: the
  // core confirm step no longer blocks on missing contact data, so keep the
  // confirm button disabled until the theme reports a successful
  // register.save — an anonymous order can't be placed through the UI.
  const wireConfirmGate = () => {
    window.__ccGatePredicates = window.__ccGatePredicates || [];
    // This module's predicate: while OUR shipping method is selected, an
    // office must actually be picked — otherwise the order would ship to the
    // seeded placeholder (city «Україна», first zone) instead of a branch.
    window.__ccGatePredicates.push(() =>
      selectedShippingCode().indexOf('ukrposhta.') !== 0 || !!officeHidden().value);
    if (window.__ccConfirmGateWired) return; // shared machinery already wired by the other carrier
    if (!window.jQuery) return; // can't detect the theme's save — keep stock behaviour
    window.__ccConfirmGateWired = true;
    const contactsForm = document.querySelector('#checkout-register, #form-register');
    if (contactsForm) {
      // Contacts already in the session from an earlier save (page reload) are
      // pre-filled by the theme — no need to force a second save.
      if ((document.querySelector('#input-firstname')?.value || '').trim()) window.__ccContactsSaved = true;
      window.__ccGatePredicates.push(() => !!window.__ccContactsSaved);
      window.jQuery(document).ajaxSuccess((e, xhr, settings) => {
        if (((settings && settings.url) || '').indexOf('register.save') === -1) return;
        try { if ((JSON.parse(xhr.responseText) || {}).success) window.__ccContactsSaved = true; } catch (err) { /* not json */ }
        if (window.__ccGateRefresh) window.__ccGateRefresh();
      });
    }
    const refresh = () => {
      const ok = (window.__ccGatePredicates || []).every((p) => { try { return p(); } catch (err) { return true; } });
      document.querySelectorAll('#checkout-confirm button').forEach((b) => {
        if (!ok && !b.disabled) { b.disabled = true; b.dataset.ccGated = '1'; b.title = 'Оберіть відділення доставки та збережіть контактні дані'; }
        if (ok && b.dataset.ccGated) { b.disabled = false; delete b.dataset.ccGated; b.removeAttribute('title'); }
      });
    };
    window.__ccGateRefresh = refresh;
    const host = document.querySelector('#checkout-confirm');
    if (host) new MutationObserver(refresh).observe(host, { childList: true, subtree: true });
    setInterval(refresh, 1200); // safety net for picker/method changes with no DOM event
    refresh();
  };

  // --- keep the chosen carrier across «Зберегти дані» ------------------------
  // Stock OpenCart 4 register.save wipes the selected shipping AND payment
  // method from the session and blanks their display inputs (only the hidden
  // #input-shipping-code / #input-payment-code survive), then reloads the
  // confirm block — so the customer has to pick the carrier a second time after
  // saving their contact data. Re-apply the retained codes for them: re-quote
  // (register.save may have changed the address), re-save both methods and
  // reload the confirm block. Shared across both carrier modules — the first
  // picker to load wires it, and it restores whatever code the theme holds.
  const wireMethodPersistence = () => {
    if (window.__ccMethodPersistWired) return;
    if (!window.jQuery) return; // no theme AJAX to hook — keep stock behaviour
    window.__ccMethodPersistWired = true;
    const $ = window.jQuery;
    const langParam = new URLSearchParams(location.search).get('language');
    const L = langParam ? '&language=' + encodeURIComponent(langParam) : '';
    const U = (r) => 'index.php?route=' + r + L;

    $(document).ajaxSuccess((e, xhr, settings) => {
      if ((((settings && settings.url) || '')).indexOf('register.save') === -1) return;
      let ok = false; try { ok = !!(JSON.parse(xhr.responseText) || {}).success; } catch (err) { /* not json */ }
      if (!ok || window.__ccRestoringMethods) return;
      const shipCode = ($('#input-shipping-code').val() || '').trim();
      const payCode = ($('#input-payment-code').val() || '').trim();
      if (!shipCode) return; // no carrier was chosen before saving contacts
      window.__ccRestoringMethods = true;
      const done = () => { window.__ccRestoringMethods = false; if (window.__ccGateRefresh) window.__ccGateRefresh(); };
      const reloadConfirm = () => { $('#checkout-confirm').load(U('checkout/confirm.confirm'), done); };
      const restorePayment = () => {
        if (!payCode) return reloadConfirm();
        $.ajax({ url: U('checkout/payment_method.getMethods'), dataType: 'json' }).done((pm) => {
          let plabel = '';
          const groups = (pm && pm.payment_methods) || {};
          for (const i in groups) { const opt = groups[i].option || {}; for (const k in opt) if (opt[k].code === payCode) plabel = opt[k].name; }
          $.ajax({ url: U('checkout/payment_method.save'), type: 'post', data: { payment_method: payCode }, dataType: 'json' })
            .always(() => { if (plabel) $('#input-payment-method').val(plabel); reloadConfirm(); });
        }).fail(reloadConfirm);
      };
      $.ajax({ url: U('checkout/shipping_method.quote'), dataType: 'json' }).done((q) => {
        let label = '';
        const methods = (q && q.shipping_methods) || {};
        for (const i in methods) { const quotes = methods[i].quote || {}; for (const j in quotes) if (quotes[j].code === shipCode) label = quotes[j].name + ' - ' + quotes[j].text; }
        if (!label) return done(); // carrier no longer quotable for the saved address
        $.ajax({ url: U('checkout/shipping_method.save'), type: 'post', data: { shipping_method: shipCode }, dataType: 'json' })
          .always(() => { $('#input-shipping-method').val(label); restorePayment(); });
      }).fail(done);
    });
  };

  // Seed native country so the theme can quote methods before the widget is
  // used; precise city/index are written once the customer picks a warehouse.
  const seedNative = () => {
    const country = q1(NATIVE.country);
    if (country && country.value !== '220') {
      const opt = [...country.options].find((o) => /Україна|Ukraine/i.test(o.text));
      if (opt) { country.value = opt.value; country.dispatchEvent(new Event('change', { bubbles: true })); }
    }
    setTimeout(() => {
      const zone = q1(NATIVE.zone);
      if (zone && !zone.value) {
        const opt = [...zone.options].find((o) => o.value);
        if (opt) { zone.value = opt.value; zone.dispatchEvent(new Event('change', { bubbles: true })); }
      }
      // Placeholders must PASS core register.save validation (city 2–128,
      // address_1 3–128 chars) — a bare '—' made the hidden form unsaveable
      // and the customer saw nothing happen on «Зберегти дані».
      if (!q1(NATIVE.city)?.value)     setVal(q1(NATIVE.city), 'Україна');
      if (!q1(NATIVE.address1)?.value) setVal(q1(NATIVE.address1), 'Відділення перевізника');
      if (!q1(NATIVE.postcode)?.value) setVal(q1(NATIVE.postcode), '00000');
      fillCustomFields('Відділення перевізника');
    }, 500);
  };

  const init = () => {
    if (window.__upPickerMounted) return;
    if (!mount()) return;
    window.__upPickerMounted = true;
    wrap.classList.add('up-gated'); // hidden until the Ukrposhta method is chosen
    // The page was rendered before the module's server-side address seed ran,
    // so the core printed «Потрібна адреса доставки!» under the method field.
    // The session is seeded by now — drop the stale error so it doesn't scare
    // the customer off clicking the method button (which now works).
    document.querySelector('#error-shipping-method')?.classList.remove('d-block');
    hideNativeAddress();
    bind();
    seedNative();
    wireGate();
    wireConfirmGate();
    wireMethodPersistence();
    gate();
    loadRegions().then(restore);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
