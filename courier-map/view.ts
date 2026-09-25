/* global google */

const DAY_ORDER = [
  'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday',
];
const DAY_NAMES_BY_JS_INDEX = [
  'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday',
];

const ICONS = {
  call: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6.6 10.8c1.4 2.8 3.8 5.2 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C11.4 21 3 12.6 3 3c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.4 0 .8-.2 1L6.6 10.8Z"/></svg>',
  pin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 22s7-7.4 7-12.6A7 7 0 0 0 5 9.4C5 14.6 12 22 12 22Z"/><circle cx="12" cy="9.5" r="2.4"/></svg>',
  globe: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.7 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.7-3.8-9S9.5 5.6 12 3Z"/></svg>',
  share: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="18" cy="5" r="2.4"/><circle cx="6" cy="12" r="2.4"/><circle cx="18" cy="19" r="2.4"/><path d="M8.2 10.8 15.8 6.2M8.2 13.2l7.6 4.6"/></svg>',
};

interface MapCategory {
  key: string;
  label: string;
  color: string;
}

interface MapLocation {
  id: string;
  title: string;
  address: string;
  lat: number;
  lng: number;
  phone: string;
  url: string;
  description: string;
  category: string;
  rating: number;
  price: string;
  hours: Record<string, string>;
}

function waitForGoogleMaps(timeout = 5000): Promise<void> {
  return new Promise((resolve, reject) => {
    const started = Date.now();
    const check = () => {
      if (window.google?.maps?.importLibrary) {
        resolve();
        return;
      }
      if (Date.now() - started >= timeout) {
        reject(new Error('Google Maps API failed to initialize.'));
        return;
      }
      window.setTimeout(check, 50);
    };
    check();
  });
}

function escapeHtml(value: string): string {
  const div = document.createElement('div');
  div.textContent = value;
  return div.innerHTML;
}

function locationLabel(location: MapLocation): string {
  if (location.title) return location.title;
  if (location.address) return location.address;
  return 'Untitled location';
}

function findCategory(categories: MapCategory[], key: string): MapCategory | undefined {
  return categories.find((cat) => cat.key === key);
}

function categoryColor(
  location: MapLocation,
  categories: MapCategory[],
  accentColor: string,
): string {
  const category = findCategory(categories, location.category);
  return category ? category.color : accentColor;
}

function stars(rating: number): string {
  return `★ ${rating.toFixed(1)}`;
}

function hasHours(hours: Record<string, string> | undefined): boolean {
  if (!hours) return false;
  return DAY_ORDER.some((day) => (hours[day] ?? '').trim() !== '');
}

function hoursTableHtml(hours: Record<string, string>): string {
  const todayName = DAY_NAMES_BY_JS_INDEX[new Date().getDay()];
  const rows = DAY_ORDER.map((day) => {
    const value = hours[day] ?? '';
    if (!value) return '';
    const isToday = day === todayName;
    return `<tr class="${isToday ? 'courier-map-hours-today' : ''}"><td>${escapeHtml(day)}</td><td>${escapeHtml(value)}</td></tr>`;
  }).join('');
  return `<table class="courier-map-hours"><tbody>${rows}</tbody></table>`;
}

function buildTile(location: MapLocation, categories: MapCategory[], accentColor: string): string {
  const color = categoryColor(location, categories, accentColor);
  const category = findCategory(categories, location.category);
  const letter = (category ? category.label : locationLabel(location)).charAt(0).toUpperCase();
  return `<div class="courier-map-tile" style="background:${escapeHtml(color)}">${escapeHtml(letter)}</div>`;
}

function buildEyebrow(location: MapLocation, categories: MapCategory[]): string {
  const category = findCategory(categories, location.category);
  if (!category) return '';
  return `<p class="courier-map-eyebrow" style="color:${escapeHtml(category.color)}">${escapeHtml(category.label)}</p>`;
}

function buildMetaLine(
  location: MapLocation,
  categories: MapCategory[],
  accentColor: string,
): string {
  const color = categoryColor(location, categories, accentColor);
  const parts: string[] = [];
  if (location.rating) parts.push(`<b>${stars(location.rating)}</b>`);
  if (location.price) {
    parts.push(`<span style="color:${escapeHtml(color)}">${escapeHtml(location.price)}</span>`);
  }
  if (!parts.length) return '';
  return `<p class="courier-map-row__meta">${parts.join(' &nbsp;·&nbsp; ')}</p>`;
}

function shareLocation(location: MapLocation): void {
  const shareData = {
    title: locationLabel(location),
    text: `${locationLabel(location)} — ${location.address}`,
    url: location.url || window.location.href,
  };

  if (navigator.share) {
    navigator.share(shareData).catch(() => {
      // User cancelled the share sheet; nothing to do.
    });
    return;
  }

  navigator.clipboard
    .writeText(`${shareData.text} — ${shareData.url}`)
    .then(() => {
      // eslint-disable-next-line no-alert
      window.alert('Copied to clipboard.');
    })
    .catch(() => {
      // Clipboard unavailable; nothing to do.
    });
}

function renderList(
  listEl: HTMLElement,
  countEl: HTMLElement,
  locations: MapLocation[],
  categories: MapCategory[],
  accentColor: string,
  onSelect: (location: MapLocation) => void,
): void {
  // eslint-disable-next-line no-param-reassign
  countEl.textContent = String(locations.length);

  // eslint-disable-next-line no-param-reassign
  listEl.innerHTML = locations.map((location) => `
    <div class="courier-map-row" data-id="${escapeHtml(location.id)}">
      ${buildTile(location, categories, accentColor)}
      <div class="courier-map-row__body">
        ${buildEyebrow(location, categories)}
        <h3>${escapeHtml(locationLabel(location))}</h3>
        ${location.description ? `<p class="courier-map-row__desc">${escapeHtml(location.description)}</p>` : ''}
        ${buildMetaLine(location, categories, accentColor)}
      </div>
      <div class="courier-map-row__chevron">›</div>
    </div>
  `).join('');

  listEl.querySelectorAll<HTMLElement>('.courier-map-row').forEach((row) => {
    row.addEventListener('click', () => {
      const { id } = row.dataset;
      const location = locations.find((l) => l.id === id);
      if (location) onSelect(location);
    });
  });
}

function renderDetail(
  detailEl: HTMLElement,
  listEl: HTMLElement,
  backLinkEl: HTMLElement,
  countRowEl: HTMLElement,
  location: MapLocation,
  categories: MapCategory[],
  accentColor: string,
  map: google.maps.Map,
): void {
  // eslint-disable-next-line no-param-reassign
  listEl.style.display = 'none';
  // eslint-disable-next-line no-param-reassign
  countRowEl.style.display = 'none';
  // eslint-disable-next-line no-param-reassign
  backLinkEl.style.display = 'flex';
  // eslint-disable-next-line no-param-reassign
  detailEl.style.display = 'block';

  const color = categoryColor(location, categories, accentColor);
  const iconSpan = (icon: string) => `<span>${icon}</span>`;

  const actions: string[] = [];
  if (location.phone) {
    actions.push(`<button class="courier-map-action" data-action="call">${iconSpan(ICONS.call)}Call</button>`);
  }
  actions.push(`<button class="courier-map-action" data-action="directions">${iconSpan(ICONS.pin)}Directions</button>`);
  if (location.url) {
    actions.push(`<button class="courier-map-action" data-action="website">${iconSpan(ICONS.globe)}Website</button>`);
  }
  actions.push(`<button class="courier-map-action" data-action="share">${iconSpan(ICONS.share)}Share</button>`);

  const category = findCategory(categories, location.category);
  const eyebrowParts: string[] = [];
  if (category) eyebrowParts.push(escapeHtml(category.label));
  if (location.price) {
    eyebrowParts.push(`<span style="color:${escapeHtml(color)}">${escapeHtml(location.price)}</span>`);
  }

  // eslint-disable-next-line no-param-reassign
  detailEl.innerHTML = `
    <div class="courier-map-detail__head">
      ${buildTile(location, categories, accentColor)}
      <div>
        ${eyebrowParts.length ? `<p class="courier-map-eyebrow" style="color:${escapeHtml(category.color)}">${eyebrowParts.join(' &nbsp;·&nbsp; ')}</p>` : ''}
        <h2>${escapeHtml(locationLabel(location))}</h2>
        ${location.rating ? `<p class="courier-map-detail__stars">${stars(location.rating)} <span>rating</span></p>` : ''}
      </div>
    </div>
    <div class="courier-map-actions" style="grid-template-columns: repeat(${actions.length}, 1fr);">${actions.join('')}</div>
    ${location.description ? `<div class="courier-map-section"><h4>About</h4><p>${escapeHtml(location.description)}</p></div>` : ''}
    ${hasHours(location.hours) ? `<div class="courier-map-section"><h4>Hours of operation</h4>${hoursTableHtml(location.hours)}</div>` : ''}
    <div class="courier-map-section">
      <h4>Location</h4>
      <p>${escapeHtml(location.address)}${location.phone ? `<br>${escapeHtml(location.phone)}` : ''}</p>
    </div>
  `;

  detailEl.querySelector('[data-action="call"]')?.addEventListener('click', () => {
    window.location.href = `tel:${location.phone.replace(/[^\d+]/g, '')}`;
  });
  detailEl.querySelector('[data-action="directions"]')?.addEventListener('click', () => {
    window.open(`https://www.google.com/maps/dir/?api=1&destination=${location.lat},${location.lng}`, '_blank', 'noopener');
  });
  detailEl.querySelector('[data-action="website"]')?.addEventListener('click', () => {
    window.open(location.url, '_blank', 'noopener');
  });
  detailEl.querySelector('[data-action="share"]')?.addEventListener('click', () => {
    shareLocation(location);
  });

  map.panTo({ lat: Number(location.lat), lng: Number(location.lng) });
  map.setZoom(15);
}

function renderFilters(
  filtersEl: HTMLElement,
  categories: MapCategory[],
  onFilter: (categoryKey: string) => void,
): void {
  if (!categories.length) {
    // eslint-disable-next-line no-param-reassign
    filtersEl.style.display = 'none';
    return;
  }

  // eslint-disable-next-line no-param-reassign
  filtersEl.innerHTML = [
    '<div class="courier-map-tab courier-map-tab--active" data-key="">All</div>',
    ...categories.map((cat) => `<div class="courier-map-tab" data-key="${escapeHtml(cat.key)}">${escapeHtml(cat.label)}</div>`),
  ].join('');

  filtersEl.querySelectorAll<HTMLElement>('.courier-map-tab').forEach((tab) => {
    tab.addEventListener('click', () => {
      filtersEl.querySelectorAll('.courier-map-tab').forEach((t) => t.classList.remove('courier-map-tab--active'));
      tab.classList.add('courier-map-tab--active');
      onFilter(tab.dataset.key ?? '');
    });
  });
}

async function initMapBlock(wrapper: HTMLElement): Promise<void> {
  if (wrapper.dataset.courierMapInitialized === 'true') return;
  // eslint-disable-next-line no-param-reassign
  wrapper.dataset.courierMapInitialized = 'true';

  const {
    mapId, accentColor, zoom, locations: rawLocations, categories: rawCategories,
    panelTitle, panelTagline,
  } = wrapper.dataset;

  let locations: MapLocation[] = [];
  let categories: MapCategory[] = [];

  try {
    locations = rawLocations ? JSON.parse(rawLocations) : [];
    categories = rawCategories ? JSON.parse(rawCategories) : [];
  } catch (error) {
    return;
  }

  const validLocations = locations.filter((location) => {
    const lat = Number(location.lat);
    const lng = Number(location.lng);
    return Number.isFinite(lat) && Number.isFinite(lng) && !(lat === 0 && lng === 0);
  });

  if (!validLocations.length) return;

  const canvas = wrapper.querySelector<HTMLElement>('.courier-map-block__canvas');
  if (!canvas) return;

  try {
    await waitForGoogleMaps();

    const [mapsLibrary, markerLibrary] = await Promise.all([
      google.maps.importLibrary('maps'),
      google.maps.importLibrary('marker'),
    ]);

    const { Map } = mapsLibrary as google.maps.MapsLibrary;
    const { AdvancedMarkerElement, PinElement } = markerLibrary as google.maps.MarkerLibrary;

    const bounds = new google.maps.LatLngBounds();
    validLocations.forEach((location) => {
      bounds.extend({ lat: Number(location.lat), lng: Number(location.lng) });
    });

    const defaultZoom = Number(zoom) || 12;
    const color = accentColor || '#1c86c4';

    const map = new Map(canvas, {
      center: bounds.getCenter(),
      zoom: defaultZoom,
      mapId: mapId || 'DEMO_MAP_ID',
      clickableIcons: false,
      styles: [
        {
          featureType: 'poi',
          elementType: 'labels',
          stylers: [{ visibility: 'off' }],
        },
        {
          featureType: 'poi',
          elementType: 'geometry',
          stylers: [{ visibility: 'off' }],
        },
      ],
    });

    const markerById: Record<string, google.maps.marker.AdvancedMarkerElement> = {};

    validLocations.forEach((location) => {
      const position = { lat: Number(location.lat), lng: Number(location.lng) };
      const pin = new PinElement({ background: categoryColor(location, categories, color) });
      const marker = new AdvancedMarkerElement({
        map,
        position,
        title: locationLabel(location),
        content: pin.element,
      });
      markerById[location.id] = marker;
    });

    if (validLocations.length === 1) {
      map.setCenter({ lat: Number(validLocations[0].lat), lng: Number(validLocations[0].lng) });
      map.setZoom(defaultZoom);
    } else {
      map.fitBounds(bounds, 60);
    }

    const panel = document.createElement('div');
    panel.className = 'courier-map-panel';
    panel.style.setProperty('--courier-map-accent', color);
    wrapper.appendChild(panel);

    const title = panelTitle || 'Locations';
    const header = document.createElement('div');
    header.className = 'courier-map-panel__header';
    header.innerHTML = `
      <h2>${escapeHtml(title)}</h2>
      ${panelTagline ? `<p>${escapeHtml(panelTagline)}</p>` : ''}
    `;
    panel.appendChild(header);

    const filtersEl = document.createElement('div');
    filtersEl.className = 'courier-map-filters';
    panel.appendChild(filtersEl);

    const countRowEl = document.createElement('div');
    countRowEl.className = 'courier-map-count';
    countRowEl.innerHTML = 'Showing <strong class="courier-map-count__number"></strong> locations';
    panel.appendChild(countRowEl);
    const countEl = countRowEl.querySelector<HTMLElement>('.courier-map-count__number');
    if (!countEl) return;

    const backLinkEl = document.createElement('div');
    backLinkEl.className = 'courier-map-back';
    backLinkEl.innerHTML = '‹ Back to results';
    backLinkEl.style.display = 'none';
    panel.appendChild(backLinkEl);

    const listEl = document.createElement('div');
    listEl.className = 'courier-map-panel__list';
    panel.appendChild(listEl);

    const detailEl = document.createElement('div');
    detailEl.className = 'courier-map-panel__detail';
    detailEl.style.display = 'none';
    panel.appendChild(detailEl);

    backLinkEl.addEventListener('click', () => {
      detailEl.style.display = 'none';
      backLinkEl.style.display = 'none';
      listEl.style.display = 'block';
      countRowEl.style.display = 'block';
    });

    const onSelect = (location: MapLocation) => {
      renderDetail(detailEl, listEl, backLinkEl, countRowEl, location, categories, color, map);
    };

    const applyFilter = (categoryKey: string) => {
      const filtered = categoryKey
        ? validLocations.filter((location) => location.category === categoryKey)
        : validLocations;

      renderList(listEl, countEl, filtered, categories, color, onSelect);

      validLocations.forEach((location) => {
        const show = !categoryKey || location.category === categoryKey;
        const marker = markerById[location.id];
        if (marker) marker.map = show ? map : null;
      });
    };

    renderFilters(filtersEl, categories, applyFilter);
    applyFilter('');

    validLocations.forEach((location) => {
      markerById[location.id]?.addListener('click', () => onSelect(location));
    });
  } catch (error) {
    // Google Maps failed to load or initialize; leave the block empty.
  }
}

function initMapBlocks(): void {
  document.querySelectorAll<HTMLElement>('.courier-map-block').forEach((wrapper) => {
    initMapBlock(wrapper);
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initMapBlocks);
} else {
  initMapBlocks();
}
