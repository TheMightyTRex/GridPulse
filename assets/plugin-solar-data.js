/**
 * Shared reference data for the Plug-in Solar section - used by
 * pages/plugin-solar-calculator.html (and the small sun-hours strips shown
 * on a couple of the other plug-in-solar pages).
 *
 * SOLAR IRRADIANCE DATA
 * ---------------------
 * Monthly average solar irradiance (kWh/m^2/day) for UK towns/cities,
 * sourced from a 20-year (Jan 2001 - Dec 2020) satellite-derived climatology
 * ("All-Sky Surface Shortwave Downward Irradiance") as published at
 * https://sunhours.app/uk-solar-hours-by-region. This is climatological
 * reference data (like this site's "Records" panels elsewhere), not a
 * live per-request feed - solar irradiance for a given month barely
 * changes year to year, unlike electricity demand or price. It should be
 * re-checked against the source page occasionally, not every page load.
 *
 * Several nearby towns share one entry because the source itself groups
 * them under a single ~1-degree satellite grid cell (it does the same,
 * e.g. quoting identical figures for Newcastle/Sunderland/Hartlepool) -
 * this is disclosed to the user in the calculator's own copy, not hidden.
 *
 * Northern Ireland is NOT covered by this dataset (it is a Great Britain-
 * only source). Belfast is included below as an explicit, clearly-labelled
 * stand-in using the closest-latitude GB location (Newcastle upon Tyne,
 * 54.97 N vs Belfast's 54.6 N) - this is disclosed on-page, not silently
 * substituted.
 *
 * CONVERSION FORMULA
 * -------------------
 * kWh generated = system_kWp x irradiance(kWh/m^2/day, i.e. "peak sun
 * hours") x days_in_month x 0.80
 *
 * The 0.80 is an industry-standard "performance ratio" - the same factor
 * sunhours.app itself uses in its own worked examples - covering inverter
 * conversion losses, cabling, temperature derating and general real-world
 * inefficiency for a well-oriented, unshaded array. This calculator
 * layers its OWN separate orientation/placement factor on top (see
 * PLACEMENT_FACTORS below) to account for how the panel is actually
 * mounted, since a plug-in kit is rarely on an optimally tilted south
 * roof - so the two factors are multiplied together, not double-counting
 * the same loss twice: 0.80 models "a well-aimed panel's real-world
 * losses", PLACEMENT_FACTORS models "how far this panel is from being
 * well-aimed."
 */
(function (global) {
  "use strict";

  var PERFORMANCE_RATIO = 0.80;

  var MONTH_LABELS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
  var DAYS_IN_MONTH = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31]; // non-leap; near enough for an estimate

  // Shared monthly irradiance profiles (kWh/m^2/day), keyed by a short id.
  var PROFILES = {
    cornwall:   [0.82, 1.61, 2.80, 4.47, 5.43, 5.81, 5.36, 4.56, 3.47, 1.96, 1.04, 0.65],
    south_coast:[0.85, 1.59, 2.87, 4.58, 5.53, 6.00, 5.79, 4.80, 3.57, 1.98, 1.03, 0.67],
    london:     [0.84, 1.51, 2.64, 4.04, 4.87, 5.30, 5.09, 4.19, 3.23, 1.85, 0.99, 0.66],
    bristol_sw: [0.80, 1.49, 2.66, 4.11, 5.02, 5.32, 5.04, 4.16, 3.22, 1.81, 0.96, 0.62],
    east_anglia:[0.75, 1.45, 2.62, 4.10, 4.93, 5.16, 4.99, 4.19, 3.18, 1.81, 0.92, 0.60],
    midlands:   [0.77, 1.48, 2.60, 3.98, 4.91, 5.13, 4.99, 4.11, 3.12, 1.78, 0.95, 0.63],
    nw_england: [0.64, 1.33, 2.52, 3.93, 4.90, 5.05, 4.81, 4.01, 2.89, 1.60, 0.79, 0.49],
    yorkshire:  [0.68, 1.37, 2.47, 3.83, 4.77, 4.84, 4.77, 3.92, 2.94, 1.66, 0.84, 0.54],
    ne_england: [0.62, 1.26, 2.37, 3.74, 4.73, 4.73, 4.62, 3.78, 2.81, 1.53, 0.76, 0.46],
    edinburgh:  [0.55, 1.20, 2.25, 3.66, 4.70, 4.57, 4.40, 3.65, 2.64, 1.46, 0.71, 0.40],
    glasgow:    [0.50, 1.19, 2.32, 3.77, 4.77, 4.75, 4.49, 3.67, 2.60, 1.42, 0.67, 0.37],
    aberdeen:   [0.44, 1.10, 2.15, 3.59, 4.63, 4.53, 4.43, 3.52, 2.51, 1.34, 0.58, 0.30],
    highlands:  [0.38, 0.98, 2.01, 3.43, 4.46, 4.35, 4.10, 3.33, 2.36, 1.26, 0.53, 0.26]
  };

  // ~20 towns/cities offered in the calculator's location picker, each
  // pointing at one of the profiles above.
  var CITIES = [
    { id: "penzance",   label: "Penzance / Truro (Cornwall)",         profile: "cornwall" },
    { id: "plymouth",   label: "Plymouth",                             profile: "cornwall" },
    { id: "brighton",   label: "Brighton / Eastbourne",               profile: "south_coast" },
    { id: "southampton",label: "Southampton / Portsmouth",            profile: "south_coast" },
    { id: "london",     label: "London",                               profile: "london" },
    { id: "bristol",    label: "Bristol",                              profile: "bristol_sw" },
    { id: "cardiff",    label: "Cardiff",                              profile: "bristol_sw" },
    { id: "norwich",    label: "Norwich / East Anglia",               profile: "east_anglia" },
    { id: "birmingham", label: "Birmingham",                           profile: "midlands" },
    { id: "nottingham", label: "Nottingham / East Midlands",          profile: "midlands" },
    { id: "manchester", label: "Manchester",                           profile: "nw_england" },
    { id: "liverpool",  label: "Liverpool",                            profile: "nw_england" },
    { id: "leeds",      label: "Leeds / Bradford",                    profile: "yorkshire" },
    { id: "newcastle",  label: "Newcastle upon Tyne",                 profile: "ne_england" },
    { id: "sunderland", label: "Sunderland / Hartlepool",             profile: "ne_england" },
    { id: "belfast",    label: "Belfast (estimated - see note)",      profile: "ne_england", estimated: true },
    { id: "edinburgh",  label: "Edinburgh",                            profile: "edinburgh" },
    { id: "glasgow",    label: "Glasgow",                              profile: "glasgow" },
    { id: "aberdeen",   label: "Aberdeen",                             profile: "aberdeen" },
    { id: "inverness",  label: "Inverness / Highlands",               profile: "highlands" }
  ];

  // Orientation / mounting-placement factors. 1.00 = the ideal this
  // dataset's irradiance figures already assume (an unshaded, optimally-
  // tilted south-facing surface). Everything else is a multiplier on top.
  // The window-mounted entries additionally derate for glass transmission
  // loss - see pages/plugin-solar-considerations.html for the sourcing
  // and caveats on those two figures specifically (manufacturers' EN 410
  // data puts glass loss at ~11% for clear single glazing and ~27-52% for
  // low-E double glazing - these assume ~30-35%, a clearly-labelled
  // estimate, not a lab-measured constant).
  var PLACEMENT_FACTORS = [
    { id: "south_tilt",   label: "South-facing, tilted ~30-40° (best case - ground frame or angled bracket)", factor: 1.00 },
    { id: "south_vert",   label: "South-facing, vertical (typical balcony rail or wall mount)",                factor: 0.78 },
    { id: "ew",           label: "East or west-facing",                                                        factor: 0.62 },
    { id: "north",        label: "North-facing (not recommended)",                                             factor: 0.35 },
    { id: "window_vert",  label: "Indoors, behind a vertical window (see note below)",                         factor: 0.55 },
    { id: "window_angle", label: "Indoors, angled behind glass e.g. a conservatory roof/skylight (see note)",  factor: 0.65 }
  ];

  // Newer three-part placement picker used by the calculator: which way the
  // panel faces, whether it is tilted or vertical, and how it is physically
  // fixed. Facing and orientation each carry a multiplier (combined by
  // multiplying the two together - see computePlacementFactor below);
  // mounting type is informational/illustrative only, since a ground frame,
  // a balcony rail, an outside wall-hang and a wall mount can each be set up
  // tilted or vertical, so the mounting choice itself does not change the
  // output estimate. East and west are given the same factor as this
  // dataset does not have a sourced east/west asymmetry to draw on.
  var FACINGS = [
    { id: "south", label: "South facing",                       factor: 1.00 },
    { id: "east",  label: "East facing",                        factor: 0.62 },
    { id: "west",  label: "West facing",                        factor: 0.62 },
    { id: "north", label: "North facing (not recommended)",     factor: 0.35 }
  ];

  var ORIENTATIONS = [
    { id: "tilted",   label: "Tilted (~30-40°)", factor: 1.00 },
    { id: "vertical", label: "Vertical",         factor: 0.78 }
  ];

  // The two indoor entries are a special case: unlike the four outdoor
  // mounting types, they carry their own fixed "indoorFactor" and, when
  // selected, override the facing x orientation multiplication entirely
  // (see the calculator's script). That is because these figures already
  // assume a south-facing window and bake in an estimated glass-
  // transmission loss on top of the orientation loss - the same two
  // figures (0.55/0.65) used in the older single-dropdown picker and
  // still shown on pages/plugin-solar-considerations.html, where the
  // glass-loss range this estimate is drawn from is sourced
  // and explained in full.
  var MOUNTINGS = [
    { id: "ground_frame",   label: "Outside (Ground Frame)",                      icon: "ground" },
    { id: "balcony",        label: "Outside (Balcony)",                           icon: "balcony" },
    { id: "wall_hang",      label: "Outside (Wall / Hang on outside of Balcony)", icon: "wallhang" },
    { id: "wall_mount",     label: "Outside Wall mount",                          icon: "wallmount" },
    { id: "indoor_flat",    label: "Indoors (flat against window)",               icon: "indoor_flat",   indoorFactor: 0.55 },
    { id: "indoor_tilted",  label: "Indoors (tilted behind window)",              icon: "indoor_tilted", indoorFactor: 0.65 }
  ];

  // Manually-maintained price constants (same convention as the previous
  // single-page calculator's Ofgem constant). Refresh each time the
  // relevant cap period/tariff changes - see each page's "last checked"
  // note. See pages/plugin-solar-calculator.html Sources section for why
  // this is not a live per-page-load fetch (Octopus's public API is real
  // and keyless, but weekly server-side ingestion into this site's
  // database is future work - see the CHANGELOG for this release).
  var PRICES = {
    ofgem_cap_gbp_per_kwh: 0.2632,     // GB-average price cap, direct debit, Oct-Dec 2026 cap period
    octopus_flexible_gbp_per_kwh: 0.2481, // Octopus "Flexible Octopus" GB-average variable unit rate, incl. VAT, as published on Octopus's own tariff pages, checked 20 Sep 2026 - varies by region/GSP group in reality, this is a national indicative average
    checked_date: "20 September 2026"
  };

  var BASE_LOAD_DEFAULT_W = 200; // typical always-on household draw: fridge-freezer, router, standby devices

  function getCity(id) {
    var i;
    for (i = 0; i < CITIES.length; i++) {
      if (CITIES[i].id === id) { return CITIES[i]; }
    }
    return CITIES[0];
  }

  function getPlacement(id) {
    var i;
    for (i = 0; i < PLACEMENT_FACTORS.length; i++) {
      if (PLACEMENT_FACTORS[i].id === id) { return PLACEMENT_FACTORS[i]; }
    }
    return PLACEMENT_FACTORS[0];
  }

  function getFacing(id) {
    var i;
    for (i = 0; i < FACINGS.length; i++) {
      if (FACINGS[i].id === id) { return FACINGS[i]; }
    }
    return FACINGS[0];
  }

  function getOrientation(id) {
    var i;
    for (i = 0; i < ORIENTATIONS.length; i++) {
      if (ORIENTATIONS[i].id === id) { return ORIENTATIONS[i]; }
    }
    return ORIENTATIONS[0];
  }

  function getMounting(id) {
    var i;
    for (i = 0; i < MOUNTINGS.length; i++) {
      if (MOUNTINGS[i].id === id) { return MOUNTINGS[i]; }
    }
    return MOUNTINGS[0];
  }

  // Combined placement multiplier for the facing+orientation picker -
  // simply the two factors multiplied together (mounting type does not
  // change this - see the MOUNTINGS comment above).
  function computePlacementFactor(facingId, orientationId) {
    return getFacing(facingId).factor * getOrientation(orientationId).factor;
  }

  function getProfile(cityId) {
    var city = getCity(cityId);
    return PROFILES[city.profile] || PROFILES.london;
  }

  // Meteorological seasons (not astronomical), each mapped to its three
  // month indices (0 = Jan). A season's "representative day" uses the
  // average irradiance across its three months.
  var SEASONS = [
    { id: "spring", label: "Spring (Mar-May)", months: [2, 3, 4] },
    { id: "summer", label: "Summer (Jun-Aug)", months: [5, 6, 7] },
    { id: "autumn", label: "Autumn (Sep-Nov)", months: [8, 9, 10] },
    { id: "winter", label: "Winter (Dec-Feb)", months: [11, 0, 1] }
  ];

  // Illustrative day-to-day weather multipliers applied on top of a
  // season's average irradiance, to show how much a single day can swing
  // above/below the seasonal norm. These are NOT derived from a specific
  // measured weather dataset - there is no citable UK source breaking
  // solar irradiance down by named weather condition - so they are an
  // illustrative estimate only, clearly labelled as such wherever shown,
  // consistent with this site's practice of disclosing assumptions rather
  // than presenting them as measured fact.
  var WEATHER_CONDITIONS = [
    { id: "sunny", label: "Sunny / clear sky", factor: 1.60 },
    { id: "cloudy", label: "Cloudy (broken cloud)", factor: 1.00 },
    { id: "overcast", label: "Overcast", factor: 0.55 },
    { id: "wet", label: "Wet / rain", factor: 0.35 },
    { id: "stormy", label: "Stormy / heavy rain", factor: 0.15 }
  ];

  function getSeason(id) {
    var i;
    for (i = 0; i < SEASONS.length; i++) {
      if (SEASONS[i].id === id) { return SEASONS[i]; }
    }
    return SEASONS[0];
  }

  function getWeather(id) {
    var i;
    for (i = 0; i < WEATHER_CONDITIONS.length; i++) {
      if (WEATHER_CONDITIONS[i].id === id) { return WEATHER_CONDITIONS[i]; }
    }
    return WEATHER_CONDITIONS[1];
  }

  /**
   * Single-day model for the calculator's "Day" tab. Takes the same
   * panel/placement/base-load/occupancy inputs as computeMonthly, plus a
   * season and a named weather condition, and returns one day's
   * {generationKwh, usableKwh, irradiance, weatherFactor}.
   */
  function computeDay(panelW, cityId, placementFactor, baseLoadW, occupancyFactor, seasonId, weatherId) {
    var profile = getProfile(cityId);
    var season = getSeason(seasonId);
    var weather = getWeather(weatherId);
    var factor = placementFactor != null ? placementFactor : 1.00;

    var seasonalIrradiance = (profile[season.months[0]] + profile[season.months[1]] + profile[season.months[2]]) / 3;
    var dayIrradiance = seasonalIrradiance * weather.factor;

    var clippedKw = (Math.min(panelW, 2000, 800) + Math.max(0, Math.min(panelW, 2000) - 800) * 0.45) / 1000;
    var generationKwh = clippedKw * dayIrradiance * PERFORMANCE_RATIO * factor;

    var baseLoadKwhPerDay = (baseLoadW || 0) * 24 / 1000;
    var belowBaseload = Math.min(generationKwh, baseLoadKwhPerDay);
    var aboveBaseload = Math.max(0, generationKwh - baseLoadKwhPerDay);
    var usableKwh = (belowBaseload * 0.95) + (aboveBaseload * (occupancyFactor != null ? occupancyFactor : 0.5));

    return {
      generationKwh: generationKwh,
      usableKwh: usableKwh,
      irradiance: dayIrradiance,
      seasonalIrradiance: seasonalIrradiance,
      weatherFactor: weather.factor
    };
  }

  /**
   * Core monthly-generation model.
   * panelW: nameplate DC panel capacity in watts (before the 800W inverter cap)
   * cityId: see above. placementFactor: a combined facing x orientation
   * multiplier, e.g. from computePlacementFactor() (1.00 = the ideal
   * baseline this dataset's irradiance figures already assume).
   * baseLoadW: household "always-on" draw in watts, user-editable
   * occupancyFactor: 0-1, fraction of above-baseload generation actually used live
   * Returns an array of 12 {label, generationKwh, usableKwh} objects.
   */
  function computeMonthly(panelW, cityId, placementFactor, baseLoadW, occupancyFactor) {
    var city = getCity(cityId);
    var profile = PROFILES[city.profile];
    var factor = placementFactor != null ? placementFactor : 1.00;

    // Inverter clipping: the DESNZ interim spec caps the inverter at
    // 800VA/800W regardless of panel rating. Panels above 800W raise how
    // much of the day the inverter sits AT its 800W ceiling (mornings,
    // evenings, hazy/overcast periods need less-than-ideal irradiance to
    // still clear 800W of DC input) rather than raising the ceiling
    // itself. Modelled here the same way as the site's original
    // calculator: the first 800W converts at full effective yield,
    // capacity above that is derated (not simply discarded) to reflect
    // the extra low/medium-irradiance capture. See
    // pages/plugin-solar-considerations.html for the worked "why bother"
    // explanation with concrete examples.
    var clippedKw = (Math.min(panelW, 2000, 800) + Math.max(0, Math.min(panelW, 2000) - 800) * 0.45) / 1000;

    var baseLoadKwhPerDay = (baseLoadW || 0) * 24 / 1000;

    var out = [];
    var m;
    for (m = 0; m < 12; m++) {
      var irradiance = profile[m];
      var days = DAYS_IN_MONTH[m];
      var dailyGenKwh = clippedKw * irradiance * PERFORMANCE_RATIO * factor;
      var monthlyGenKwh = dailyGenKwh * days;

      var belowBaseload = Math.min(dailyGenKwh, baseLoadKwhPerDay);
      var aboveBaseload = Math.max(0, dailyGenKwh - baseLoadKwhPerDay);
      // Generation up to the household's always-on draw is treated as
      // ~95% self-consumed (something is almost always pulling at least
      // that much); generation above it is only captured if someone's
      // actually home and actively using more than baseline power, i.e.
      // gated by the occupancy factor. A simplification (not an hourly
      // simulation of your actual daylight hours), disclosed on-page.
      var dailyUsableKwh = (belowBaseload * 0.95) + (aboveBaseload * (occupancyFactor != null ? occupancyFactor : 0.5));
      var monthlyUsableKwh = dailyUsableKwh * days;

      out.push({
        label: MONTH_LABELS[m],
        generationKwh: monthlyGenKwh,
        usableKwh: monthlyUsableKwh
      });
    }
    return out;
  }

  global.UKGL_PLUGIN_SOLAR = {
    MONTH_LABELS: MONTH_LABELS,
    CITIES: CITIES,
    PLACEMENT_FACTORS: PLACEMENT_FACTORS,
    FACINGS: FACINGS,
    ORIENTATIONS: ORIENTATIONS,
    MOUNTINGS: MOUNTINGS,
    SEASONS: SEASONS,
    WEATHER_CONDITIONS: WEATHER_CONDITIONS,
    PRICES: PRICES,
    BASE_LOAD_DEFAULT_W: BASE_LOAD_DEFAULT_W,
    PERFORMANCE_RATIO: PERFORMANCE_RATIO,
    getCity: getCity,
    getPlacement: getPlacement,
    getFacing: getFacing,
    getOrientation: getOrientation,
    getMounting: getMounting,
    computePlacementFactor: computePlacementFactor,
    getProfile: getProfile,
    getSeason: getSeason,
    getWeather: getWeather,
    computeMonthly: computeMonthly,
    computeDay: computeDay
  };
})(window);
