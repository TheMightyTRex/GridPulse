// Builds the small border maps on the country pages (assets/maps/XX.json).
//
// Coastlines and borders: Natural Earth 1:50m, public domain, via the
// world-atlas package. Each map shows the home country in white, its
// neighbours in grey and the sea left transparent, with one point per
// area for the flow lines and labels. Price zones (NO2, DK1, SE3 and their
// neighbouring zones) are drawn as the whole country, with a label point
// at the zone's rough position.
//
// Run from tools/maps:  npm install  then  node build_maps.mjs
// Keep NEIGHBOURS in step with ukgrid_flow_borders() in
// includes/flows_annual.php (and the GB cables in api/flows.php).
import fs from 'fs';
import * as topo from 'topojson-client';
import { geoMercator, geoPath } from 'd3-geo';

const world = JSON.parse(fs.readFileSync('node_modules/world-atlas/countries-50m.json'));
const countries = topo.feature(world, world.objects.countries).features;

// Label point for each area or price zone: [lon, lat], plus the Natural
// Earth country it belongs to.
const ZONES = {
  GB: [-1.6, 52.8, 'United Kingdom'], IE: [-7.9, 53.2, 'Ireland'], FR: [2.4, 46.7, 'France'],
  NL: [5.6, 52.2, 'Netherlands'], BE: [4.6, 50.6, 'Belgium'], NO: [7.6, 59.2, 'Norway'],
  NO1: [10.9, 60.6, 'Norway'], NO5: [6.6, 61.3, 'Norway'], DK: [9.1, 56.1, 'Denmark'], DK2: [12.0, 55.4, 'Denmark'],
  DE: [10.2, 51.0, 'Germany'], ES: [-3.7, 40.3, 'Spain'], IT: [12.4, 42.9, 'Italy'], SE: [14.9, 59.4, 'Sweden'],
  SE2: [16.4, 63.6, 'Sweden'], SE4: [13.9, 56.3, 'Sweden'], FI: [25.6, 62.4, 'Finland'], PT: [-8.1, 39.7, 'Portugal'],
  PL: [19.3, 52.1, 'Poland'], CH: [8.2, 46.8, 'Switzerland'], AT: [14.4, 47.6, 'Austria'], CZ: [15.4, 49.8, 'Czechia'],
  SK: [19.6, 48.7, 'Slovakia'], LT: [23.9, 55.3, 'Lithuania'], SI: [14.8, 46.1, 'Slovenia'], GR: [22.0, 39.6, 'Greece'],
};

const BORDERS = [
  ['FR', 'BE'], ['FR', 'DE'], ['FR', 'ES'], ['FR', 'IT'], ['FR', 'CH'],
  ['NL', 'BE'], ['NL', 'DE'], ['NL', 'NO'], ['NL', 'DK'],
  ['BE', 'DE'],
  ['NO', 'DE'], ['NO', 'DK'], ['NO', 'NO1'], ['NO', 'NO5'],
  ['DK', 'DE'], ['DK', 'SE'], ['DK', 'DK2'],
  ['DE', 'PL'], ['DE', 'CZ'], ['DE', 'AT'], ['DE', 'CH'], ['DE', 'DK2'], ['DE', 'SE4'],
  ['ES', 'PT'],
  ['IT', 'CH'], ['IT', 'AT'], ['IT', 'SI'], ['IT', 'GR'],
  ['SE', 'NO1'], ['SE', 'FI'], ['SE', 'SE2'], ['SE', 'SE4'],
  ['PL', 'CZ'], ['PL', 'SK'], ['PL', 'LT'], ['PL', 'SE4'],
];
// Great Britain's interconnectors, by the country at the other end.
const NEIGHBOURS = { GB: ['FR', 'BE', 'NL', 'NO', 'DK', 'IE'], IE: ['GB'] };
for (const [a, b] of BORDERS) {
  (NEIGHBOURS[a] = NEIGHBOURS[a] || []).push(b);
  (NEIGHBOURS[b] = NEIGHBOURS[b] || []).push(a);
}
// GB links to the European pages too.
for (const c of ['FR', 'BE', 'NL', 'NO', 'DK']) NEIGHBOURS[c].unshift('GB');


// Where each border's interconnection actually is, roughly: [lon, lat] on
// the first area's side, then on the second's. Subsea cables use their
// two landing points; land borders use the main crossing. Names match the
// cable names in api/flows.php (GB) or are the link's own name.
// Sources: the operators' and project pages for each link (positions are
// approximate, to within a few kilometres - good enough for a small map).
const LINKS = {
  'GB-FR': [['IFA', [1.0, 51.1], [1.8, 50.9]], ['IFA2', [-1.25, 50.85], [-0.1, 49.2]], ['ElecLink', [1.15, 51.09], [1.8, 50.93]]],
  'GB-BE': [['Nemo Link', [1.35, 51.3], [3.2, 51.32]]],
  'GB-NL': [['BritNed', [0.7, 51.45], [4.03, 51.96]]],
  'GB-NO': [['North Sea Link', [-1.5, 55.13], [6.6, 59.5]]],
  'GB-DK': [['Viking Link', [-0.2, 52.93], [9.2, 55.5]]],
  'GB-IE': [['Moyle', [-5.0, 55.07], [-5.75, 54.84]], ['East-West', [-3.4, 53.33], [-6.1, 53.53]], ['Greenlink', [-5.0, 51.68], [-6.98, 52.26]]],
  'FR-BE': [['', [3.2, 50.3], [4.0, 50.55]]],
  'FR-DE': [['', [7.4, 48.4], [8.3, 48.6]]],
  'FR-ES': [['', [-1.0, 43.15], [-1.5, 42.75]], ['', [2.8, 42.6], [2.9, 42.1]]],
  'FR-IT': [['', [6.6, 45.3], [7.6, 45.05]]],
  'FR-CH': [['', [6.2, 46.25], [6.9, 46.45]]],
  'NL-BE': [['', [4.4, 51.6], [4.4, 51.1]]],
  'NL-DE': [['', [6.2, 52.0], [7.0, 51.6]]],
  'NL-NO': [['NorNed', [6.8, 53.45], [6.8, 58.27]]],
  'NL-DK': [['COBRAcable', [6.8, 53.45], [8.7, 55.5]]],
  'BE-DE': [['ALEGrO', [5.9, 50.65], [6.3, 50.8]]],
  'NO-DE': [['NordLink', [6.7, 58.66], [9.4, 53.9]]],
  'NO-DK': [['Skagerrak', [8.0, 58.15], [9.6, 56.5]]],
  'NO-NO1': [['', [8.6, 59.6], [10.0, 60.0]]],
  'NO-NO5': [['', [6.5, 59.8], [6.8, 60.5]]],
  'DK-DE': [['', [9.3, 55.1], [9.6, 54.4]]],
  'DK-SE': [['Konti-Skan', [10.6, 57.0], [12.1, 57.6]]],
  'DK-DK2': [['Storebaelt', [10.1, 55.4], [11.3, 55.4]]],
  'DE-PL': [['', [14.3, 52.3], [14.9, 52.4]]],
  'DE-CZ': [['', [12.9, 50.5], [13.4, 50.3]]],
  'DE-AT': [['', [12.6, 48.0], [13.3, 47.8]]],
  'DE-CH': [['', [8.2, 47.65], [8.3, 47.4]]],
  'DE-DK2': [['Kontek', [12.2, 54.1], [12.0, 55.4]]],
  'DE-SE4': [['Baltic Cable', [10.8, 53.9], [13.15, 55.4]]],
  'ES-PT': [['', [-6.9, 41.9], [-7.6, 41.7]], ['', [-7.0, 38.6], [-7.6, 38.4]]],
  'IT-CH': [['', [8.8, 45.9], [8.9, 46.3]]],
  'IT-AT': [['', [12.4, 46.5], [12.6, 46.8]]],
  'IT-SI': [['', [13.6, 45.85], [14.0, 45.95]]],
  'IT-GR': [['GRITA', [18.1, 40.2], [21.0, 39.3]]],
  'SE-NO1': [['', [12.3, 59.5], [11.2, 59.8]]],
  'SE-FI': [['Fenno-Skan', [18.4, 60.3], [21.5, 61.1]]],
  'SE-SE2': [['', [16.0, 61.2], [16.2, 62.3]]],
  'SE-SE4': [['', [14.5, 57.2], [14.2, 56.6]]],
  'PL-CZ': [['', [18.2, 50.0], [17.8, 49.8]]],
  'PL-SK': [['', [19.6, 49.4], [19.9, 49.0]]],
  'PL-LT': [['LitPol Link', [22.4, 53.8], [24.05, 54.4]]],
  'PL-SE4': [['SwePol', [16.9, 54.5], [14.9, 56.15]]],
};
/** The links between two areas, starting on `from`'s side. */
function linksBetween(from, to) {
  if (LINKS[from + '-' + to]) return LINKS[from + '-' + to].map(([n, a, b]) => [n, a, b]);
  if (LINKS[to + '-' + from]) return LINKS[to + '-' + from].map(([n, a, b]) => [n, b, a]);
  return [];
}

const PAGES = ['GB', 'IE', 'FR', 'NL', 'BE', 'NO', 'DK', 'DE', 'ES', 'IT', 'SE', 'PT', 'PL'];
const W = 320, H = 280;
const round = (s) => s.replace(/(\d+\.\d)\d+/g, '$1');

for (const home of PAGES) {
  const codes = [home, ...NEIGHBOURS[home]];
  const linkPts = NEIGHBOURS[home].flatMap((c) => linksBetween(home, c).flatMap(([, a, b]) => [a, b]));
  const lons = codes.map((c) => ZONES[c][0]).concat(linkPts.map((p) => p[0]));
  const lats = codes.map((c) => ZONES[c][1]).concat(linkPts.map((p) => p[1]));
  const pad = 1.6;
  const frame = { type: 'Feature', geometry: { type: 'Polygon', coordinates: [[
    [Math.min(...lons) - pad, Math.min(...lats) - pad], [Math.min(...lons) - pad, Math.max(...lats) + pad],
    [Math.max(...lons) + pad, Math.max(...lats) + pad], [Math.max(...lons) + pad, Math.min(...lats) - pad],
    [Math.min(...lons) - pad, Math.min(...lats) - pad]]] } };
  const proj = geoMercator().fitExtent([[14, 14], [W - 14, H - 14]], frame);
  proj.clipExtent([[0, 0], [W, H]]);
  const path = geoPath(proj);
  const homeCountry = ZONES[home][2];
  const land = [];
  for (const f of countries) {
    const d = path(f);
    if (!d) continue;
    land.push([round(d), f.properties.name === homeCountry ? 1 : 0]);
  }
  land.sort((a, b) => a[1] - b[1]); // home drawn last, on top
  const pts = {};
  for (const c of codes) pts[c] = proj([ZONES[c][0], ZONES[c][1]]).map((v) => Math.round(v * 10) / 10);
  const xy = (p) => proj(p).map((v) => Math.round(v * 10) / 10);
  const links = {};
  for (const c of NEIGHBOURS[home]) {
    const l = linksBetween(home, c);
    if (l.length) links[c] = l.map(([name, a, b]) => ({ name, from: xy(a), to: xy(b) }));
  }
  const out = { home, w: W, h: H, land, pts, links, neighbours: NEIGHBOURS[home],
    credit: 'Map: Natural Earth (public domain)' };
  fs.writeFileSync(`../../assets/maps/${home}.json`, JSON.stringify(out));
  console.log(home, NEIGHBOURS[home].join(','), Math.round(JSON.stringify(out).length / 1024) + 'KB');
}
