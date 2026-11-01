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

const PAGES = ['GB', 'IE', 'FR', 'NL', 'BE', 'NO', 'DK', 'DE', 'ES', 'IT', 'SE', 'PT', 'PL'];
const W = 320, H = 280;
const round = (s) => s.replace(/(\d+\.\d)\d+/g, '$1');

for (const home of PAGES) {
  const codes = [home, ...NEIGHBOURS[home]];
  const lons = codes.map((c) => ZONES[c][0]), lats = codes.map((c) => ZONES[c][1]);
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
  const out = { home, w: W, h: H, land, pts, neighbours: NEIGHBOURS[home],
    credit: 'Map: Natural Earth (public domain)' };
  fs.writeFileSync(`../../assets/maps/${home}.json`, JSON.stringify(out));
  console.log(home, NEIGHBOURS[home].join(','), Math.round(JSON.stringify(out).length / 1024) + 'KB');
}
