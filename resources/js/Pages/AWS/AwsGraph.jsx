import { FloatingSelect, FloatingInput } from "@/Components/Form";import { useState } from 'react';
import { router } from '@inertiajs/react';
import Card from '../../Components/Card';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler } from
'chart.js';
import { Bar, Line } from 'react-chartjs-2';

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler
);

export default function AwsGraph({ chartRecords = [], protectedAreas = [], filters = {} }) {
  const [graphMetric, setGraphMetric] = useState('air_temperature');
  const [graphStartDate, setGraphStartDate] = useState(filters.graph_start_date || '');
  const [graphEndDate, setGraphEndDate] = useState(filters.graph_end_date || '');
  const [selectedPaId, setSelectedPaId] = useState(filters.protected_area_id || '');
  const [rangePreset, setRangePreset] = useState(String(filters.graph_range || '30'));
  const [analysisView, setAnalysisView] = useState('overall');

  const metricConfig = {
    air_temperature: {
      label: 'Air Temperature',
      shortLabel: 'Temperature',
      unit: '°C',
      type: 'line',
      decimals: 1
    },
    precipitation: {
      label: 'Daily Rainfall',
      shortLabel: 'Rainfall',
      unit: 'mm',
      type: 'bar',
      decimals: 1
    },
    wind_speed: {
      label: 'Wind Speed',
      shortLabel: 'Wind Speed',
      unit: 'm/s',
      type: 'line',
      decimals: 2
    },
    relative_humidity: {
      label: 'Relative Humidity',
      shortLabel: 'Humidity',
      unit: '%',
      type: 'line',
      decimals: 1
    },
    atmospheric_pressure: {
      label: 'Atmospheric Pressure',
      shortLabel: 'Pressure',
      unit: 'kPa',
      type: 'line',
      decimals: 2
    }
  };

  const currentMetric = metricConfig[graphMetric] || metricConfig.air_temperature;

  const formatChartDate = (value) => {
    if (!value) return '';
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
      return String(value);
    }

    return date.toLocaleDateString(undefined, {
      month: 'short',
      day: 'numeric'
    });
  };

  const formatChartDateLong = (value) => {
    if (!value) return '';
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
      return String(value);
    }

    return date.toLocaleDateString(undefined, {
      month: 'short',
      day: 'numeric',
      year: 'numeric'
    });
  };

  const applyRangePreset = (preset) => {
    setRangePreset(preset);

    if (preset === 'custom') {
      return;
    }

    if (!preset) {
      setGraphStartDate('');
      setGraphEndDate('');
      triggerUpdate(undefined, '', '', '30');
      return;
    }

    // Quick ranges are calculated by the backend.
    // This is important because every PA may have a different latest record date.
    setGraphStartDate('');
    setGraphEndDate('');
    triggerUpdate(undefined, '', '', preset);
  };

  const clearGraphRange = () => {
    setRangePreset('30');
    setGraphStartDate('');
    setGraphEndDate('');
    triggerUpdate(undefined, '', '', '30');
  };

  const triggerUpdate = (newPaId, newStartDate, newEndDate, newRange = undefined) => {
    router.get(
      route('aws.index'),
      {
        protected_area_id: newPaId !== undefined ? newPaId : selectedPaId,
        tab: 'analytics',
        graph_start_date: newStartDate !== undefined ? newStartDate : graphStartDate,
        graph_end_date: newEndDate !== undefined ? newEndDate : graphEndDate,
        graph_range: newRange !== undefined ? newRange : rangePreset
      },
      { preserveState: true, preserveScroll: true }
    );
  };

  const handleProtectedAreaChange = (e) => {
    const val = e.target.value;
    setSelectedPaId(val);

    if (rangePreset === 'custom') {
      triggerUpdate(val, graphStartDate, graphEndDate, 'custom');
      return;
    }

    // Clear the previous PA's dates. Backend recalculates the range
    // from the newly selected PA (or each PA when "All Protected Areas" is selected).
    setGraphStartDate('');
    setGraphEndDate('');
    triggerUpdate(val, '', '', rangePreset || '30');
  };

  const handleStartDateChange = (e) => {
    const val = e.target.value;
    setRangePreset('custom');
    setGraphStartDate(val);

    if (val && graphEndDate && val <= graphEndDate) {
      triggerUpdate(undefined, val, graphEndDate);
    }
  };

  const handleEndDateChange = (e) => {
    const val = e.target.value;
    setRangePreset('custom');
    setGraphEndDate(val);

    if (val && graphStartDate && graphStartDate <= val) {
      triggerUpdate(undefined, graphStartDate, val);
    }
  };

  const formatDate = (date) => {
    if (!date) return '—';
    const parsedDate = new Date(date);
    if (Number.isNaN(parsedDate.getTime())) return '—';

    return parsedDate.toLocaleDateString(undefined, {
      month: 'short',
      day: 'numeric',
      year: 'numeric'
    });
  };


  const protectedAreaMap = Object.fromEntries(
    protectedAreas.map((pa) => [String(pa.id), pa])
  );

  const getPaName = (paId) =>
  protectedAreaMap[String(paId)]?.name || `Protected Area ${paId}`;

  const getPaShortName = (paId) => {
    const area = protectedAreaMap[String(paId)];
    const name = String(area?.name || '').trim();

    if (
    name === 'Aliwagwag Protected Landscape (APL)' ||
    name === 'Aliwagwag Protected Landscape')
    {
      return 'APL';
    }

    return area?.short_name || area?.name || `PA ${paId}`;
  };

  const paGroups = Object.values(
    chartRecords.reduce((groups, record) => {
      const key = String(record.protected_area_id ?? 'unknown');

      if (!groups[key]) {
        groups[key] = {
          protectedAreaId: key,
          name: getPaName(key),
          shortName: getPaShortName(key),
          records: []
        };
      }

      groups[key].records.push(record);
      return groups;
    }, {})
  ).sort((a, b) => a.name.localeCompare(b.name));

  const allPaMode = selectedPaId === '';

  const toNumber = (value) => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
  };

  const mean = (values) => {
    const valid = values.filter((value) => value !== null && Number.isFinite(value));
    return valid.length ?
    valid.reduce((sum, value) => sum + value, 0) / valid.length :
    null;
  };

  const sumValues = (values) =>
  values.filter((value) => value !== null && Number.isFinite(value)).
  reduce((sum, value) => sum + value, 0);

  const aggregateAllPa = () => {
    const dailyRecords = chartRecords;

    const temperature = dailyRecords.map((r) => toNumber(r.air_temperature));
    const wind = dailyRecords.map((r) => toNumber(r.wind_speed));
    const humidity = dailyRecords.map((r) => toNumber(r.relative_humidity));
    const pressure = dailyRecords.map((r) => toNumber(r.atmospheric_pressure));
    const rainfall = dailyRecords.map((r) => toNumber(r.precipitation));

    const completeness = dailyRecords.
    map((r) => toNumber(r.data_completeness)).
    filter((v) => v !== null);

    const rainyPaDays = rainfall.filter((v) => v !== null && v > 0).length;

    return {
      protectedAreas: paGroups.length,
      dailyRecords: dailyRecords.length,
      averageTemperature: mean(temperature),
      averageWind: mean(wind),
      averageHumidity: mean(humidity),
      averagePressure: mean(pressure),
      averageDailyRainfall: mean(rainfall),
      cumulativeStationRainfall: sumValues(rainfall),
      rainyPaDays,
      completeness: completeness.length ? mean(completeness) : null
    };
  };

  const allPaSummary = aggregateAllPa();

  const paComparison = paGroups.map((group) => {
    const temperature = group.records.map((r) => toNumber(r.air_temperature));
    const wind = group.records.map((r) => toNumber(r.wind_speed));
    const humidity = group.records.map((r) => toNumber(r.relative_humidity));
    const rainfall = group.records.map((r) => toNumber(r.precipitation));
    const completeness = group.records.
    map((r) => toNumber(r.data_completeness)).
    filter((v) => v !== null);

    const dominantDirections = {};
    group.records.forEach((record) => {
      const direction = String(record.wind_direction || '').trim();
      if (direction && direction !== '—') {
        dominantDirections[direction] = (dominantDirections[direction] || 0) + 1;
      }
    });

    const dominant = Object.entries(dominantDirections).
    sort((a, b) => b[1] - a[1])[0];

    return {
      ...group,
      dailyRecords: group.records.length,
      averageTemperature: mean(temperature),
      totalRainfall: sumValues(rainfall),
      averageDailyRainfall: mean(rainfall),
      averageWind: mean(wind),
      averageHumidity: mean(humidity),
      completeness: completeness.length ? mean(completeness) : null,
      dominantDirection: dominant?.[0] || '—',
      dominantDirectionFrequency: dominant ?
      dominant[1] / Object.values(dominantDirections).reduce((sum, value) => sum + value, 0) * 100 :
      0,
      rainyDays: rainfall.filter((value) => value !== null && value > 0).length
    };
  });

  const overallDailySeries = Object.values(
    chartRecords.reduce((groups, record) => {
      const date = record.start_date;

      if (!groups[date]) {
        groups[date] = {
          date,
          values: []
        };
      }

      const value = Number(record?.[graphMetric]);
      if (Number.isFinite(value)) {
        groups[date].values.push(value);
      }

      return groups;
    }, {})
  ).
  sort((a, b) => new Date(a.date) - new Date(b.date)).
  map((item) => ({
    date: item.date,
    value: mean(item.values)
  }));

  // --- WEATHER INTELLIGENCE & TREND ANALYSIS ---
  // For a specific PA, analyze that PA's daily observations.
  // For "All Protected Areas", preserve each PA's records for statistics,
  // while building a daily network series for trends so stations are not
  // treated as one continuous time series.
  const groupRecordsByPa = (records) => {
    return Object.values(
      records.reduce((groups, record) => {
        const key = String(record.protected_area_id ?? 'unknown');

        if (!groups[key]) {
          groups[key] = [];
        }

        groups[key].push(record);
        return groups;
      }, {})
    );
  };

  const buildDailyNetworkSeries = (records) => {
    const byDate = records.reduce((groups, record) => {
      const date = record.start_date;
      if (!date) return groups;

      if (!groups[date]) {
        groups[date] = {
          date,
          temperatures: [],
          precipitation: [],
          wind: [],
          humidity: [],
          pressure: []
        };
      }

      const pushNumber = (bucket, value) => {
        const number = Number(value);
        if (Number.isFinite(number)) {
          bucket.push(number);
        }
      };

      pushNumber(groups[date].temperatures, record.air_temperature);
      pushNumber(groups[date].precipitation, record.precipitation);
      pushNumber(groups[date].wind, record.wind_speed);
      pushNumber(groups[date].humidity, record.relative_humidity);
      pushNumber(groups[date].pressure, record.atmospheric_pressure);

      return groups;
    }, {});

    const average = (values) =>
    values.length ?
    values.reduce((sum, value) => sum + value, 0) / values.length :
    null;

    return Object.values(byDate).
    sort((a, b) => new Date(a.date) - new Date(b.date)).
    map((item) => ({
      start_date: item.date,
      air_temperature: average(item.temperatures),
      // Network rainfall is the mean station rainfall for each day.
      // This avoids presenting the sum of multiple stations as an
      // areal rainfall measurement.
      precipitation: average(item.precipitation),
      wind_speed: average(item.wind),
      relative_humidity: average(item.humidity),
      atmospheric_pressure: average(item.pressure)
    }));
  };

  const calculateSpellStats = (records) => {
    const paGroupsForSpells = groupRecordsByPa(records);
    let longestWetSpell = 0;
    let longestDrySpell = 0;

    paGroupsForSpells.forEach((paRecords) => {
      const sorted = [...paRecords].
      filter((record) => record?.start_date).
      sort((a, b) => new Date(a.start_date) - new Date(b.start_date));

      let wet = 0;
      let dry = 0;

      sorted.forEach((record) => {
        const precipitation = Number(record?.precipitation);
        const isWet = Number.isFinite(precipitation) && precipitation > 0;

        if (isWet) {
          wet += 1;
          dry = 0;
          longestWetSpell = Math.max(longestWetSpell, wet);
        } else {
          dry += 1;
          wet = 0;
          longestDrySpell = Math.max(longestDrySpell, dry);
        }
      });
    });

    return { longestWetSpell, longestDrySpell };
  };

  const generateAiAnalysis = () => {
    if (!chartRecords || chartRecords.length === 0) return null;

    const toNumber = (value) => {
      const n = Number(value);
      return Number.isFinite(n) ? n : null;
    };

    const rawRecords = [...chartRecords].
    map((record) => ({
      ...record,
      date: new Date(record.start_date),
      temperature: toNumber(record.air_temperature),
      precipitation: toNumber(record.precipitation),
      windSpeed: toNumber(record.wind_speed),
      humidity: toNumber(record.relative_humidity),
      pressure: toNumber(record.atmospheric_pressure),
      port2Precipitation: toNumber(record.port2_precipitation),
      port2MaxPrecipRate: toNumber(record.port2_max_precipitation_rate),
      port3WaterContent: toNumber(record.port3_water_content),
      port3SoilTemperature: toNumber(record.port3_soil_temperature),
      port3Ec: toNumber(record.port3_ec),
      rainfallDifferencePercent: toNumber(record.rainfall_difference_percent),
      rainfallCrosscheckStatus: record.rainfall_crosscheck_status || 'Unavailable',
      soilConditionContext: record.soil_condition_context || 'Unavailable'
    })).
    filter((record) => !Number.isNaN(record.date.getTime())).
    sort((a, b) => a.date - b.date);

    const isAllPa = selectedPaId === '';
    const networkDaily = isAllPa ?
    buildDailyNetworkSeries(rawRecords).map((record) => ({
      ...record,
      date: new Date(record.start_date),
      temperature: toNumber(record.air_temperature),
      precipitation: toNumber(record.precipitation),
      windSpeed: toNumber(record.wind_speed),
      humidity: toNumber(record.relative_humidity),
      pressure: toNumber(record.atmospheric_pressure)
    })) :
    rawRecords;

    const records = networkDaily;

    const average = (values) =>
    values.length ? values.reduce((sum, value) => sum + value, 0) / values.length : null;

    const sum = (values) =>
    values.reduce((total, value) => total + value, 0);

    const maxRecord = (key) => {
      const valid = records.filter((record) => record[key] !== null);
      return valid.length ?
      valid.reduce((best, record) => record[key] > best[key] ? record : best) :
      null;
    };

    const minRecord = (key) => {
      const valid = records.filter((record) => record[key] !== null);
      return valid.length ?
      valid.reduce((best, record) => record[key] < best[key] ? record : best) :
      null;
    };

    const firstDate = records[0]?.date;
    const lastDate = records[records.length - 1]?.date;

    const trend = (key) => {
      const valid = records.
      filter((record) => record[key] !== null).
      map((record) => ({
        x: firstDate ? (record.date - firstDate) / (1000 * 60 * 60 * 24) : 0,
        y: record[key]
      }));

      if (valid.length < 3) {
        return {
          direction: 'Insufficient Data',
          label: 'Insufficient Data',
          slope: 0,
          rSquared: null,
          strength: 'Insufficient Data',
          meaningful: false
        };
      }

      const xMean = average(valid.map((point) => point.x));
      const yMean = average(valid.map((point) => point.y));

      const numerator = valid.reduce(
        (total, point) => total + (point.x - xMean) * (point.y - yMean),
        0
      );

      const denominator = valid.reduce(
        (total, point) => total + (point.x - xMean) ** 2,
        0
      );

      const slope = denominator ? numerator / denominator : 0;
      const intercept = yMean - slope * xMean;

      const ssTotal = valid.reduce(
        (total, point) => total + (point.y - yMean) ** 2,
        0
      );

      const ssResidual = valid.reduce(
        (total, point) => total + (point.y - (intercept + slope * point.x)) ** 2,
        0
      );

      const rSquared = ssTotal > 0 ?
      Math.max(0, Math.min(1, 1 - ssResidual / ssTotal)) :
      0;

      let strength = 'Mostly Stable';
      let meaningful = false;

      if (rSquared >= 0.50) {
        strength = slope >= 0 ? 'Increasing' : 'Decreasing';
        meaningful = true;
      } else if (rSquared >= 0.25) {
        strength = slope >= 0 ? 'Increasing' : 'Decreasing';
        meaningful = true;
      }

      return {
        slope,
        direction: slope >= 0 ? 'Increasing' : 'Decreasing',
        rSquared,
        strength,
        meaningful,
        label: `${strength} (${slope >= 0 ? '+' : ''}${slope.toFixed(3)}/day; R² ${rSquared.toFixed(2)})`
      };
    };

    const temps = records.map((r) => r.temperature).filter((v) => v !== null);
    const precip = records.map((r) => r.precipitation).filter((v) => v !== null);
    const wind = records.map((r) => r.windSpeed).filter((v) => v !== null);
    const humidity = records.map((r) => r.humidity).filter((v) => v !== null);
    const pressure = records.map((r) => r.pressure).filter((v) => v !== null);

    const avgTemp = average(temps);
    const averageDailyRainfall = average(precip);
    const totalPrecipForSelectedPa = sum(precip);

    const avgWind = average(wind);
    const avgHum = average(humidity);
    const avgPressure = average(pressure);

    const standardDeviation = (values) => {
      if (values.length < 2) return 0;

      const mean = average(values);
      const variance = values.reduce(
        (total, value) => total + (value - mean) ** 2,
        0
      ) / values.length;

      return Math.sqrt(variance);
    };

    const completenessValues = rawRecords.
    map((record) => Number(record.data_completeness)).
    filter((value) => Number.isFinite(value));

    const avgCompleteness = completenessValues.length ?
    average(completenessValues) :
    null;

    const qcDays = completenessValues.length;
    const fullyCoveredDays = completenessValues.filter((value) => value >= 100).length;

    const completenessStatus = qcDays === 0 ?
    'QC unavailable for older records' :
    avgCompleteness >= 98 ?
    'Good coverage' :
    avgCompleteness >= 90 ?
    'Acceptable coverage' :
    'Limited coverage';

    const diffDays = firstDate && lastDate ?
    Math.max(1, Math.round((lastDate - firstDate) / (1000 * 60 * 60 * 24)) + 1) :
    records.length;

    const maxTempRecord = maxRecord('temperature');
    const minTempRecord = minRecord('temperature');
    const maxRainRecord = maxRecord('precipitation');
    const maxWindRecord = maxRecord('windSpeed');

    const rawPositiveRain = rawRecords.filter((record) => (record.precipitation ?? 0) > 0).length;
    const rawHeavyRain = rawRecords.filter((record) => (record.precipitation ?? 0) >= 15).length;

    const rainDays = isAllPa ?
    records.filter((record) => (record.precipitation ?? 0) > 0).length :
    rawPositiveRain;

    const heavyRainDays = isAllPa ?
    records.filter((record) => (record.precipitation ?? 0) >= 15).length :
    rawHeavyRain;

    const highWindDays = records.filter((record) => (record.windSpeed ?? 0) > 10).length;
    const highHumidityDays = records.filter((record) => (record.humidity ?? 0) >= 80).length;
    const hotDays = records.filter((record) => (record.temperature ?? -Infinity) >= 32).length;

    const rainfallCrosscheckRecords = rawRecords.filter(
      (record) => record.port2Precipitation !== null
    );

    const rainfallCrosscheckGenerallyConsistent = rainfallCrosscheckRecords.filter(
      (record) => record.rainfallCrosscheckStatus === 'Generally consistent'
    ).length;

    const rainfallCrosscheckReviewCount = rainfallCrosscheckRecords.filter(
      (record) => record.rainfallCrosscheckStatus === 'Review discrepancy'
    ).length;

    const rainfallCrosscheckDays = rainfallCrosscheckRecords.length;
    const rainfallCrosscheckCoverage = rawRecords.length ?
    rainfallCrosscheckDays / rawRecords.length * 100 :
    0;

    // Determine soil condition day-by-day first, then summarize the
    // selected date range. This avoids letting one multi-day average
    // dominate the interpretation.
    //
    // For All Protected Areas, each date may have multiple PA observations.
    // We first determine the daily dominant status among the available PAs,
    // then count those daily statuses across the selected period.
    const soilStatusesByDate = {};

    rawRecords.forEach((record) => {
      const dateKey = record.start_date;
      const status = record.soilConditionContext;

      if (
      !dateKey ||
      !status ||
      status === 'Unavailable')
      {
        return;
      }

      if (!soilStatusesByDate[dateKey]) {
        soilStatusesByDate[dateKey] = [];
      }

      soilStatusesByDate[dateKey].push(status);
    });

    const dailySoilStatusCounts = {
      'Higher soil moisture': 0,
      'Moderate soil moisture': 0,
      'Lower soil moisture': 0
    };

    Object.values(soilStatusesByDate).forEach((statuses) => {
      if (!statuses.length) return;

      const dailyCounts = {
        'Higher soil moisture': 0,
        'Moderate soil moisture': 0,
        'Lower soil moisture': 0
      };

      statuses.forEach((status) => {
        if (dailyCounts[status] !== undefined) {
          dailyCounts[status] += 1;
        }
      });

      const dailyDominant = Object.entries(dailyCounts).
      sort((a, b) => b[1] - a[1])[0];

      if (dailyDominant && dailyDominant[1] > 0) {
        dailySoilStatusCounts[dailyDominant[0]] += 1;
      }
    });

    const soilDaysEvaluated = Object.values(soilStatusesByDate).
    filter((statuses) => statuses.length > 0).
    length;

    const dominantSoilEntry = Object.entries(dailySoilStatusCounts).
    sort((a, b) => b[1] - a[1])[0];

    const dominantSoilContext = dominantSoilEntry?.[0] || 'Unavailable';
    const dominantSoilDays = dominantSoilEntry?.[1] || 0;

    const soilContextSummary = dominantSoilContext === 'Unavailable' ?
    'Unavailable' :
    soilDaysEvaluated > 0 ?
    `${dominantSoilContext} on ${dominantSoilDays} of ${soilDaysEvaluated} day(s)` :
    dominantSoilContext;

    const directionCounts = {};
    rawRecords.forEach((record) => {
      const direction = String(record.wind_direction || '').trim();

      if (direction && direction !== '—') {
        directionCounts[direction] = (directionCounts[direction] || 0) + 1;
      }
    });

    const dominantWindEntry =
    Object.entries(directionCounts).sort((a, b) => b[1] - a[1])[0] || null;

    const dominantWindDirection = dominantWindEntry?.[0] || '—';
    const dominantWindDirectionFrequency = dominantWindEntry ?
    dominantWindEntry[1] / Object.values(directionCounts).reduce((sum, value) => sum + value, 0) * 100 :
    0;

    const spellStats = calculateSpellStats(rawRecords);

    const temperatureTrend = trend('temperature');
    const precipitationTrend = trend('precipitation');
    const windTrend = trend('windSpeed');
    const pressureTrend = trend('pressure');

    let season = '⚖️ Stable / Transition Period';
    let badgeColor = 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-500/20';

    let explanation = isAllPa ?
    `Across ${paGroups.length || 0} Protected Areas, the selected period shows generally balanced weather conditions. ` +
    `Average temperature across monitored PA observations was ${avgTemp !== null ? avgTemp.toFixed(1) : '—'}°C, ` +
    `average daily rainfall was ${averageDailyRainfall !== null ? averageDailyRainfall.toFixed(1) : '—'} mm, ` +
    `average wind speed was ${avgWind !== null ? avgWind.toFixed(1) : '—'} m/s, ` +
    `and average humidity was ${avgHum !== null ? avgHum.toFixed(1) : '—'}%.` :
    `The selected period shows generally balanced weather conditions. Average temperature was ${avgTemp !== null ? avgTemp.toFixed(1) : '—'}°C, ` +
    `cumulative precipitation was ${totalPrecipForSelectedPa.toFixed(1)} mm, ` +
    `average wind speed was ${avgWind !== null ? avgWind.toFixed(1) : '—'} m/s, ` +
    `and average humidity was ${avgHum !== null ? avgHum.toFixed(1) : '—'}%.`;

    if (heavyRainDays >= Math.max(2, Math.ceil(diffDays * 0.2)) || (averageDailyRainfall ?? 0) >= 8) {
      season = '🌧️ Wet / Rain-Dominant Period';
      badgeColor = 'bg-indigo-500/10 text-indigo-700 dark:text-indigo-300 border-indigo-500/20';

      explanation = isAllPa ?
      `Across ${paGroups.length || 0} Protected Areas, the period was generally wet. ` +
      `Average daily rainfall across monitored PA stations was ${averageDailyRainfall !== null ? averageDailyRainfall.toFixed(1) : '—'} mm, ` +
      `${rainDays} day(s) recorded measurable network rainfall, and ${heavyRainDays} day(s) had network average rainfall of at least 15 mm. ` +
      `These patterns may affect field access, trail conditions, drainage, and observation effort.` :
      `The selected period was generally wet. Total precipitation reached ${totalPrecipForSelectedPa.toFixed(1)} mm, ` +
      `with ${rainDays} rainy day(s) and ${heavyRainDays} day(s) at or above 15 mm. ` +
      `The wet pattern may affect field access, trail conditions, drainage, and observation effort.`;
    } else if ((avgTemp ?? 0) >= 26 && (averageDailyRainfall ?? 0) < 2.5) {
      season = '☀️ Warm / Relatively Dry Period';
      badgeColor = 'bg-amber-500/10 text-amber-700 dark:text-amber-300 border-amber-500/20';

      explanation = isAllPa ?
      `Across ${paGroups.length || 0} Protected Areas, the period was generally warm and relatively dry. ` +
      `Average temperature was ${avgTemp !== null ? avgTemp.toFixed(1) : '—'}°C and average daily rainfall was ` +
      `${averageDailyRainfall !== null ? averageDailyRainfall.toFixed(1) : '—'} mm. ` +
      `Monitor prolonged dry periods, elevated evaporation, and localized heat stress.` :
      `The selected period was generally warm and relatively dry, with an average temperature of ${avgTemp !== null ? avgTemp.toFixed(1) : '—'}°C ` +
      `and ${totalPrecipForSelectedPa.toFixed(1)} mm cumulative precipitation. Monitor prolonged dry spells, elevated evaporation, and localized heat stress.`;
    } else if (highWindDays > 0) {
      season = '💨 Wind-Active Period';
      badgeColor = 'bg-sky-500/10 text-sky-700 dark:text-sky-300 border-sky-500/20';

      explanation = isAllPa ?
      `Wind conditions were more noticeable across the selected Protected Areas. ${highWindDays} network day(s) exceeded 10 m/s, ` +
      `with the strongest daily average reaching ${maxWindRecord?.windSpeed?.toFixed(1) ?? '—'} m/s. ` +
      `Consider wind exposure when scheduling field activities and interpreting sensor observations.` :
      `Wind conditions were more noticeable during this period. ${highWindDays} day(s) exceeded 10 m/s, ` +
      `with the strongest daily average reaching ${maxWindRecord?.windSpeed?.toFixed(1) ?? '—'} m/s. ` +
      `Consider wind exposure when scheduling field activities and interpreting sensor observations.`;
    }

    const alerts = [];

    if (isAllPa) {
      if (rawHeavyRain > 0) alerts.push(`${rawHeavyRain} high-rain PA-day(s)`);
      if (spellStats.longestWetSpell >= 7) alerts.push(`${spellStats.longestWetSpell}-day wet spell in at least one PA`);
      if (highWindDays > 0) alerts.push(`${highWindDays} high-wind day(s)`);
      if (hotDays > 0) alerts.push(`${hotDays} hot day(s)`);
      if (highHumidityDays > 0) alerts.push(`${highHumidityDays} high-humidity day(s)`);
    } else {
      if (heavyRainDays > 0) alerts.push(`${heavyRainDays} high-rain day(s)`);
      if (spellStats.longestWetSpell >= 7) alerts.push(`${spellStats.longestWetSpell}-day wet spell`);
      if (highWindDays > 0) alerts.push(`${highWindDays} high-wind day(s)`);
      if (hotDays > 0) alerts.push(`${hotDays} hot day(s)`);
      if (highHumidityDays > 0) alerts.push(`${highHumidityDays} high-humidity day(s)`);
    }

    return {
      season,
      explanation,
      avgTemp,
      totalPrecip: isAllPa ? averageDailyRainfall : totalPrecipForSelectedPa,
      averageDailyRainfall,
      avgWind,
      avgHum,
      avgPressure,
      badgeColor,
      diffDays,
      rainDays,
      heavyRainDays,
      highWindDays,
      hotDays,
      highHumidityDays,
      dominantWindDirection,
      dominantWindDirectionFrequency,
      longestWetSpell: spellStats.longestWetSpell,
      longestDrySpell: spellStats.longestDrySpell,
      maxTempRecord,
      minTempRecord,
      maxRainRecord,
      maxWindRecord,
      temperatureTrend,
      precipitationTrend,
      windTrend,
      pressureTrend,
      alerts,
      dataCoverage: isAllPa ? rawRecords.length : records.length,
      firstDate,
      lastDate,
      tempStdDev: standardDeviation(temps),
      windStdDev: standardDeviation(wind),
      humidityStdDev: standardDeviation(humidity),
      pressureStdDev: standardDeviation(pressure),
      avgCompleteness,
      qcDays,
      fullyCoveredDays,
      completenessStatus,
      isAllPa,
      protectedAreaCount: paGroups.length,
      rainfallCrosscheckDays,
      rainfallCrosscheckCoverage,
      rainfallCrosscheckGenerallyConsistent,
      rainfallCrosscheckReviewCount,
      dominantSoilContext,
      dominantSoilDays,
      soilDaysEvaluated,
      soilContextSummary
    };
  };

  const aiSummary = generateAiAnalysis();

  const chartSourceRecords = allPaMode && analysisView === 'overall' ?
  overallDailySeries.map((item) => ({ start_date: item.date, [graphMetric]: item.value })) :
  chartRecords;

  const chartLabels = allPaMode ?
  overallDailySeries.map((item) => item.date) :
  chartRecords.map((record) => record.start_date);

  const metricValues = chartSourceRecords.map((record) => {
    const value = Number(record?.[graphMetric]);
    return Number.isFinite(value) ? value : null;
  });

  const validMetricValues = metricValues.filter((value) => value !== null);
  const graphAverage = validMetricValues.length ?
  validMetricValues.reduce((sum, value) => sum + value, 0) / validMetricValues.length :
  null;
  const graphMinimum = validMetricValues.length ? Math.min(...validMetricValues) : null;
  const graphMaximum = validMetricValues.length ? Math.max(...validMetricValues) : null;

  const palette = [
  'rgb(16, 185, 129)',
  'rgb(37, 99, 235)',
  'rgb(234, 88, 12)',
  'rgb(147, 51, 234)',
  'rgb(8, 145, 178)',
  'rgb(220, 38, 38)',
  'rgb(101, 163, 13)',
  'rgb(219, 39, 119)'];


  const buildPaDataset = (group, index) => {
    const map = Object.fromEntries(
      group.records.map((record) => [record.start_date, toNumber(record[graphMetric])])
    );

    return {
      label: group.shortName,
      data: overallDailySeries.map((item) =>
      map[item.date] !== undefined ? map[item.date] : null
      ),
      borderColor: palette[index % palette.length],
      backgroundColor: 'transparent',
      borderWidth: 2,
      pointRadius: 2.5,
      pointHoverRadius: 6,
      tension: 0.3,
      spanGaps: false
    };
  };

  const graphData = {
    labels: chartLabels,
    datasets: allPaMode && analysisView === 'compare' ?
    paGroups.map((group, index) => buildPaDataset(group, index)) :
    [{
      label: `${allPaMode ? 'All Protected Areas — ' : ''}${currentMetric.label} (${currentMetric.unit})`,
      data: metricValues,
      borderColor: 'rgb(16, 185, 129)',
      backgroundColor: currentMetric.type === 'bar' ?
      'rgba(16, 185, 129, 0.68)' :
      'rgba(16, 185, 129, 0.16)',
      borderWidth: currentMetric.type === 'bar' ? 1 : 2.5,
      pointBackgroundColor: 'rgb(16, 185, 129)',
      pointBorderColor: '#fff',
      pointHoverRadius: 6,
      pointRadius: currentMetric.type === 'bar' ? 0 : 3,
      fill: currentMetric.type !== 'bar',
      tension: 0.32,
      borderRadius: currentMetric.type === 'bar' ? 4 : 0,
      spanGaps: false
    }]
  };

  const startLabelPlugin = {
    id: 'awsStartLabels',
    afterDatasetsDraw(chart, _args, pluginOptions) {
      if (!pluginOptions?.enabled) return;

      const { ctx, data } = chart;

      ctx.save();
      ctx.font = '600 11px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
      ctx.textBaseline = 'middle';

      chart.data.datasets.forEach((dataset, datasetIndex) => {
        const meta = chart.getDatasetMeta(datasetIndex);

        const firstIndex = dataset.data.findIndex(
          (value) => value !== null && value !== undefined && Number.isFinite(Number(value))
        );

        if (firstIndex < 0) return;

        const point = meta.data[firstIndex];
        if (!point) return;

        const label = String(dataset.label || 'Protected Area');
        const textWidth = ctx.measureText(label).width;
        const paddingX = 7;
        const boxWidth = textWidth + paddingX * 2;
        const boxHeight = 22;

        let x = point.x + 8;
        let y = point.y - 25;

        if (x + boxWidth > chart.chartArea.right) {
          x = point.x - boxWidth - 8;
        }

        if (y - boxHeight / 2 < chart.chartArea.top) {
          y = point.y + 22;
        }

        const color = dataset.borderColor || '#374151';

        ctx.fillStyle = 'rgba(255,255,255,0.94)';
        ctx.strokeStyle = color;
        ctx.lineWidth = 1.2;

        const radius = 6;

        ctx.beginPath();
        ctx.moveTo(x + radius, y - boxHeight / 2);
        ctx.lineTo(x + boxWidth - radius, y - boxHeight / 2);
        ctx.quadraticCurveTo(x + boxWidth, y - boxHeight / 2, x + boxWidth, y - boxHeight / 2 + radius);
        ctx.lineTo(x + boxWidth, y + boxHeight / 2 - radius);
        ctx.quadraticCurveTo(x + boxWidth, y + boxHeight / 2, x + boxWidth - radius, y + boxHeight / 2);
        ctx.lineTo(x + radius, y + boxHeight / 2);
        ctx.quadraticCurveTo(x, y + boxHeight / 2, x, y + boxHeight / 2 - radius);
        ctx.lineTo(x, y - boxHeight / 2 + radius);
        ctx.quadraticCurveTo(x, y - boxHeight / 2, x + radius, y - boxHeight / 2);
        ctx.closePath();
        ctx.fill();
        ctx.stroke();

        ctx.fillStyle = color;
        ctx.fillText(label, x + paddingX, y);
      });

      ctx.restore();
    }
  };

  const graphOptions = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: {
      intersect: false,
      mode: 'index'
    },
    plugins: {
      legend: {
        display: !(allPaMode && analysisView === 'compare'),
        position: 'top',
        align: 'start',
        labels: {
          font: { weight: 'bold', size: 11 },
          usePointStyle: true,
          padding: 18
        }
      },
      awsStartLabels: {
        enabled: allPaMode && analysisView === 'compare' && currentMetric.type !== 'bar'
      },
      tooltip: {
        callbacks: {
          title: (items) => {
            const item = items?.[0];
            return item ? formatChartDateLong(chartLabels[item.dataIndex]) : '';
          },
          label: (context) => {
            const value = context.raw;

            if (value === null || value === undefined) {
              return `${context.dataset.label}: No data`;
            }

            return `${context.dataset.label}: ${Number(value).toFixed(currentMetric.decimals)} ${currentMetric.unit}`;
          }
        }
      },
      title: {
        display: false
      }
    },
    scales: {
      x: {
        grid: {
          display: false
        },
        ticks: {
          autoSkip: true,
          maxTicksLimit: 10,
          maxRotation: 0,
          minRotation: 0,
          callback: (_value, index) => formatChartDate(chartLabels[index])
        }
      },
      y: {
        beginAtZero: currentMetric.type === 'bar' || graphMetric === 'precipitation',
        grid: {
          color: 'rgba(156, 163, 175, 0.12)'
        },
        ticks: {
          callback: (value) => `${value}${currentMetric.unit}`
        }
      }
    }
  };

  return (
    <div className="space-y-6">
            <Card className="border border-gray-100 dark:border-gray-800 shadow-xl rounded-2xl p-6 space-y-6 bg-white dark:bg-gray-900 transition-all duration-300">
                {/* FILTERS + GRAPH HEADER */}
                <div className="flex flex-col gap-4">
                    <div className="rounded-2xl border border-gray-200/60 bg-gray-50/80 p-4 backdrop-blur-sm dark:border-gray-700/60 dark:bg-gray-800/40">
                        <div className="grid grid-cols-1 lg:grid-cols-3 gap-3">
                            <div>



                                <FloatingSelect id="awsgraph-protected-area" label="Protected Area"
                value={selectedPaId}
                onChange={handleProtectedAreaChange}>


                                    <option value="">All Protected Areas</option>
                                    {protectedAreas.map((pa) =>
                  <option key={pa.id} value={pa.id}>{pa.name}</option>
                  )}
                                </FloatingSelect>
                            </div>

                            <div>



                                <FloatingSelect id="awsgraph-metric" label="Metric"
                value={graphMetric}
                onChange={(e) => setGraphMetric(e.target.value)}>


                                    <option value="air_temperature">Air Temperature (°C)</option>
                                    <option value="precipitation">Daily Rainfall (mm)</option>
                                    <option value="wind_speed">Wind Speed (m/s)</option>
                                    <option value="relative_humidity">Relative Humidity (%)</option>
                                    <option value="atmospheric_pressure">Atmospheric Pressure (kPa)</option>
                                </FloatingSelect>
                            </div>

                            <div>



                                <FloatingSelect id="awsgraph-quick-range" label="Quick Range"
                value={rangePreset}
                onChange={(e) => applyRangePreset(e.target.value)}>


                                    <option value="7">Last 7 Days</option>
                                    <option value="30">Last 30 Days</option>
                                    <option value="90">Last 90 Days</option>
                                    <option value="365">Last 12 Months</option>
                                    <option value="custom">Custom Range</option>
                                </FloatingSelect>
                            </div>
                        </div>

                        {rangePreset === 'custom' &&
            <div className="mt-3 grid grid-cols-1 md:grid-cols-[1fr_1fr_auto] gap-3 items-end">
                                <div>



                                    <FloatingInput id="awsgraph-start-date" label="Start Date"
                type="date"
                value={graphStartDate}
                onChange={handleStartDateChange} />


                                </div>

                                <div>



                                    <FloatingInput id="awsgraph-end-date" label="End Date"
                type="date"
                value={graphEndDate}
                onChange={handleEndDateChange} />


                                </div>

                                <button
                type="button"
                onClick={clearGraphRange}
                className="rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-5 py-2.5 text-xs font-semibold text-gray-700 dark:text-gray-200 hover:border-emerald-400 hover:text-emerald-700 transition whitespace-nowrap">

                                    Clear Date Filter
                                </button>
                            </div>
            }

                        {rangePreset !== 'custom' && (graphStartDate || graphEndDate) &&
            <div className="mt-3 flex justify-end">
                                <button
                type="button"
                onClick={clearGraphRange}
                className="rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-5 py-2.5 text-xs font-semibold text-gray-700 dark:text-gray-200 hover:border-emerald-400 hover:text-emerald-700 transition whitespace-nowrap">

                                    Clear Date Filter
                                </button>
                            </div>
            }
                    </div>

                    {allPaMode &&
          <div className="rounded-2xl border border-emerald-500/20 bg-emerald-50/40 dark:bg-emerald-950/10 p-4">
                            <div className="flex flex-col gap-4">
                                <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
                                    <div>
                                        <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-emerald-700 dark:text-emerald-400">
                                            All Protected Areas
                                        </p>
                                        <p className="text-xs text-gray-600 dark:text-gray-300">
                                            Combined from the selected daily AWS observations across all Protected Areas.
                                        </p>
                                    </div>

                                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                        <div className="rounded-xl bg-white/80 dark:bg-gray-900/70 px-3 py-2 border border-gray-100 dark:border-gray-700">
                                            <span className="block text-[9px] uppercase text-gray-400">Protected Areas</span>
                                            <span className="text-sm font-bold text-gray-900 dark:text-white">{allPaSummary.protectedAreas}</span>
                                        </div>
                                        <div className="rounded-xl bg-white/80 dark:bg-gray-900/70 px-3 py-2 border border-gray-100 dark:border-gray-700">
                                            <span className="block text-[9px] uppercase text-gray-400">PA-Day Records</span>
                                            <span className="text-sm font-bold text-gray-900 dark:text-white">{allPaSummary.dailyRecords}</span>
                                        </div>
                                        <div className="rounded-xl bg-white/80 dark:bg-gray-900/70 px-3 py-2 border border-gray-100 dark:border-gray-700">
                                            <span className="block text-[9px] uppercase text-gray-400">Avg Temp</span>
                                            <span className="text-sm font-bold text-gray-900 dark:text-white">
                                                {allPaSummary.averageTemperature !== null ? `${allPaSummary.averageTemperature.toFixed(1)}°C` : '—'}
                                            </span>
                                        </div>
                                        <div className="rounded-xl bg-white/80 dark:bg-gray-900/70 px-3 py-2 border border-gray-100 dark:border-gray-700">
                                            <span className="block text-[9px] uppercase text-gray-400">Avg Humidity</span>
                                            <span className="text-sm font-bold text-gray-900 dark:text-white">
                                                {allPaSummary.averageHumidity !== null ? `${allPaSummary.averageHumidity.toFixed(1)}%` : '—'}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div className="rounded-xl border border-emerald-200/70 bg-white/70 dark:border-emerald-900/50 dark:bg-gray-900/40 overflow-hidden">
                                    <div className="px-4 py-2.5 border-b border-emerald-100 dark:border-emerald-900/40">
                                        <h4 className="text-[10px] font-bold uppercase tracking-[0.14em] text-emerald-800 dark:text-emerald-400">
                                            PA Breakdown
                                        </h4>
                                        <p className="mt-1 text-[10px] text-gray-500 dark:text-gray-400">
                                            These are the PAs contributing data to the current All Protected Areas analysis.
                                        </p>
                                    </div>

                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-2 p-3">
                                        {paComparison.length > 0 ?
                  paComparison.map((pa) =>
                  <div
                    key={pa.protectedAreaId}
                    className="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2.5">

                                                    <div className="flex items-start justify-between gap-3">
                                                        <div className="min-w-0">
                                                            <p className="text-xs font-bold text-gray-800 dark:text-white truncate">
                                                                {pa.name}
                                                            </p>
                                                            <p className="mt-1 text-[9px] text-gray-500 dark:text-gray-400">
                                                                {pa.dailyRecords} PA-day record(s)
                                                            </p>
                                                        </div>

                                                        <span className="shrink-0 rounded-full bg-emerald-50 px-2 py-1 text-[9px] font-bold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300">
                                                            {pa.completeness !== null ? `${pa.completeness.toFixed(1)}% complete` : 'QC unavailable'}
                                                        </span>
                                                    </div>

                                                    <div className="mt-2 grid grid-cols-3 gap-2 text-[9px]">
                                                        <div>
                                                            <span className="block text-gray-400 uppercase">Avg Temp</span>
                                                            <span className="font-semibold text-gray-700 dark:text-gray-200">
                                                                {pa.averageTemperature !== null ? `${pa.averageTemperature.toFixed(1)}°C` : '—'}
                                                            </span>
                                                        </div>
                                                        <div>
                                                            <span className="block text-gray-400 uppercase">Rainfall</span>
                                                            <span className="font-semibold text-gray-700 dark:text-gray-200">
                                                                {pa.averageDailyRainfall !== null ? `${pa.averageDailyRainfall.toFixed(1)} mm/day` : '—'}
                                                            </span>
                                                        </div>
                                                        <div>
                                                            <span className="block text-gray-400 uppercase">Humidity</span>
                                                            <span className="font-semibold text-gray-700 dark:text-gray-200">
                                                                {pa.averageHumidity !== null ? `${pa.averageHumidity.toFixed(1)}%` : '—'}
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                  ) :

                  <div className="md:col-span-2 rounded-lg bg-gray-50 dark:bg-gray-800/60 px-3 py-3 text-xs text-gray-500">
                                                No Protected Area data is available for the selected period.
                                            </div>
                  }
                                    </div>
                                </div>
                            </div>
                        </div>
          }

                    <div className="flex flex-col xl:flex-row xl:items-center xl:justify-between gap-3">
                        <div>
                            <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-gray-400">
                                {currentMetric.label}
                            </p>
                            <h3 className="text-lg font-bold text-gray-900 dark:text-white">
                                Daily observations
                            </h3>
                        </div>

                        <div className="grid grid-cols-3 gap-2 min-w-0">
                            <div className="rounded-xl bg-gray-50 dark:bg-gray-800/70 px-3 py-2 border border-gray-100 dark:border-gray-700">
                                <span className="block text-[9px] uppercase text-gray-400">Average</span>
                                <span className="text-sm font-bold text-gray-900 dark:text-white">
                                    {graphAverage !== null ? `${graphAverage.toFixed(currentMetric.decimals)} ${currentMetric.unit}` : '—'}
                                </span>
                            </div>
                            <div className="rounded-xl bg-gray-50 dark:bg-gray-800/70 px-3 py-2 border border-gray-100 dark:border-gray-700">
                                <span className="block text-[9px] uppercase text-gray-400">Lowest</span>
                                <span className="text-sm font-bold text-gray-900 dark:text-white">
                                    {graphMinimum !== null ? `${graphMinimum.toFixed(currentMetric.decimals)} ${currentMetric.unit}` : '—'}
                                </span>
                            </div>
                            <div className="rounded-xl bg-gray-50 dark:bg-gray-800/70 px-3 py-2 border border-gray-100 dark:border-gray-700">
                                <span className="block text-[9px] uppercase text-gray-400">Highest</span>
                                <span className="text-sm font-bold text-gray-900 dark:text-white">
                                    {graphMaximum !== null ? `${graphMaximum.toFixed(currentMetric.decimals)} ${currentMetric.unit}` : '—'}
                                </span>
                            </div>
                        </div>
                    </div>

                    {allPaMode &&
          <div className="flex justify-end">
                            <div className="flex items-center gap-1 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-1">
                                <button
                type="button"
                onClick={() => setAnalysisView('overall')}
                className={`rounded-lg px-3 py-1.5 text-[10px] font-bold transition ${
                analysisView === 'overall' ?
                'bg-emerald-600 text-white' :
                'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'}`
                }>

                                    Overall
                                </button>
                                <button
                type="button"
                onClick={() => setAnalysisView('compare')}
                className={`rounded-lg px-3 py-1.5 text-[10px] font-bold transition ${
                analysisView === 'compare' ?
                'bg-emerald-600 text-white' :
                'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'}`
                }>

                                    Compare PAs
                                </button>
                            </div>
                        </div>
          }

{rangePreset === 'custom' && graphStartDate && graphEndDate && graphStartDate > graphEndDate &&
          <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[10px] font-semibold text-red-700 dark:border-red-900/50 dark:bg-red-950/20 dark:text-red-300">
                            Start date must be on or before the end date.
                        </div>
          }

                    {(graphStartDate || graphEndDate) &&
          <div className="text-[10px] font-medium text-gray-500 dark:text-gray-400">
                            Showing {graphStartDate || 'start'} to {graphEndDate || 'latest available record'}.
                        </div>
          }

                    <div className="w-full h-[460px] flex items-center justify-center p-2 relative">
                        {chartRecords.length > 0 ?
            currentMetric.type === 'bar' && !(allPaMode && analysisView === 'compare') ?
            <Bar data={graphData} options={graphOptions} /> :

            <Line
              data={graphData}
              options={graphOptions}
              plugins={[startLabelPlugin]} /> :



            <div className="text-center p-8 text-gray-400">
                                <span className="text-4xl mb-2 block">📊</span>
                                <p className="text-sm font-semibold">No weather data found for the selected date range.</p>
                            </div>
            }
                    </div>
                </div>


            </Card>

            {/* WEATHER INTELLIGENCE & TRENDS */}
            {aiSummary &&
      <Card className="border border-emerald-500/20 shadow-2xl rounded-2xl p-6 bg-gradient-to-br from-white via-emerald-50/20 to-emerald-100/30 dark:from-gray-900 dark:via-gray-900 dark:to-emerald-950/20 space-y-5 transition-all duration-300">
                    <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 border-b border-gray-200/60 dark:border-gray-800 pb-4">
                        <div className="flex items-center gap-3">
                            <div className="w-10 h-10 rounded-xl bg-emerald-500/10 flex items-center justify-center text-emerald-600 dark:text-emerald-400 shadow-inner">
                                <span className="text-xl">🧠</span>
                            </div>
                            <div>
                                <h3 className="text-xs font-bold uppercase tracking-widest text-gray-800 dark:text-gray-200">
                                    Weather Summary & Trends
                                </h3>
                                <p className="text-[10px] text-gray-500 dark:text-gray-400">
                                    Easy-to-read summary of the selected daily AWS observations
                                </p>
                            </div>
                        </div>
                        <div className="flex items-center gap-2 flex-wrap">
                            <span className={`px-3.5 py-1.5 rounded-full text-xs font-bold border shadow-xs ${aiSummary.badgeColor}`}>
                                {aiSummary.season}
                            </span>
                            <span className="px-3 py-1.5 rounded-full text-[10px] font-bold bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                {aiSummary.dataCoverage} daily records
                            </span>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                        <div className="bg-white/90 dark:bg-gray-800/80 p-4 rounded-xl border border-gray-100 dark:border-gray-700/80 shadow-xs">
                            <span className="block text-[9px] font-bold uppercase tracking-wider text-gray-400">Average Temperature</span>
                            <span className="mt-1 block text-xl font-extrabold text-gray-800 dark:text-white">
                                {aiSummary.avgTemp !== null ? `${aiSummary.avgTemp.toFixed(1)}°C` : '—'}
                            </span>
                            <span className="mt-1 block text-[10px] font-semibold text-gray-500">
                                Observed: {aiSummary.temperatureTrend.strength}
                            </span>
                        </div>

                        <div className="bg-white/90 dark:bg-gray-800/80 p-4 rounded-xl border border-gray-100 dark:border-gray-700/80 shadow-xs">
                            <span className="block text-[9px] font-bold uppercase tracking-wider text-gray-400">
                                {aiSummary?.isAllPa ? 'Average Daily Rainfall' : 'Total Rainfall'}
                            </span>
                            <span className="mt-1 block text-xl font-extrabold text-gray-800 dark:text-white">
                                {aiSummary.totalPrecip.toFixed(1)} mm
                            </span>
                            <span className="mt-1 block text-[10px] font-semibold text-gray-500">
                                {aiSummary.isAllPa ?
              `${aiSummary.rainDays} rainy network day(s)` :
              `${aiSummary.rainDays} rainy day(s)`}
                            </span>
                        </div>

                        <div className="bg-white/90 dark:bg-gray-800/80 p-4 rounded-xl border border-gray-100 dark:border-gray-700/80 shadow-xs">
                            <span className="block text-[9px] font-bold uppercase tracking-wider text-gray-400">Average Wind Speed</span>
                            <span className="mt-1 block text-xl font-extrabold text-gray-800 dark:text-white">
                                {aiSummary.avgWind !== null ? `${aiSummary.avgWind.toFixed(1)} m/s` : '—'}
                            </span>
                            <span className="mt-1 block text-[10px] font-semibold text-gray-500">
                                Most Common Direction: {aiSummary.dominantWindDirection} ({aiSummary.dominantWindDirectionFrequency.toFixed(1)}%)
                            </span>
                        </div>

                        <div className="bg-white/90 dark:bg-gray-800/80 p-4 rounded-xl border border-gray-100 dark:border-gray-700/80 shadow-xs">
                            <span className="block text-[9px] font-bold uppercase tracking-wider text-gray-400">Average Humidity</span>
                            <span className="mt-1 block text-xl font-extrabold text-gray-800 dark:text-white">
                                {aiSummary.avgHum !== null ? `${aiSummary.avgHum.toFixed(1)}%` : '—'}
                            </span>
                            <span className="mt-1 block text-[10px] font-semibold text-gray-500">
                                {aiSummary.isAllPa ?
              `${aiSummary.highHumidityDays} high-humidity PA-day(s)` :
              `${aiSummary.highHumidityDays} high-humidity day(s)`}
                            </span>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <div className="rounded-xl border border-gray-200/80 dark:border-gray-700 bg-white/80 dark:bg-gray-800/80 p-4">
                            <h4 className="text-[10px] font-bold uppercase tracking-wider text-emerald-800 dark:text-emerald-400 mb-3">
                                Weather Trends
                            </h4>
                            <p className="mb-3 text-[10px] text-gray-500 dark:text-gray-400">
                                These labels describe the selected daily observations only; they are not forecasts. R² shows how closely the data follow the indicated pattern.
                            </p>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                <div className="rounded-lg bg-gray-50 dark:bg-gray-900/60 px-3 py-2">
                                    <span className="block text-[9px] text-gray-400 uppercase">Temperature</span>
                                    <span className="text-xs font-bold text-gray-800 dark:text-white">{aiSummary.temperatureTrend.label}</span>
                                    <span className="block mt-1 text-[9px] text-gray-400">
                                        Based on the selected daily data
                                    </span>
                                </div>
                                <div className="rounded-lg bg-gray-50 dark:bg-gray-900/60 px-3 py-2">
                                    <span className="block text-[9px] text-gray-400 uppercase">Rainfall</span>
                                    <span className="text-xs font-bold text-gray-800 dark:text-white">{aiSummary.precipitationTrend.label}</span>
                                </div>
                                <div className="rounded-lg bg-gray-50 dark:bg-gray-900/60 px-3 py-2">
                                    <span className="block text-[9px] text-gray-400 uppercase">Wind Speed</span>
                                    <span className="text-xs font-bold text-gray-800 dark:text-white">{aiSummary.windTrend.label}</span>
                                </div>
                                <div className="rounded-lg bg-gray-50 dark:bg-gray-900/60 px-3 py-2">
                                    <span className="block text-[9px] text-gray-400 uppercase">Pressure</span>
                                    <span className="text-xs font-bold text-gray-800 dark:text-white">{aiSummary.pressureTrend.label}</span>
                                </div>
                            </div>
                        </div>

                        <div className="rounded-xl border border-gray-200/80 dark:border-gray-700 bg-white/80 dark:bg-gray-800/80 p-4">
                            <h4 className="text-[10px] font-bold uppercase tracking-wider text-emerald-800 dark:text-emerald-400 mb-3">
                                Highest & Lowest Readings
                            </h4>
                            <div className="space-y-2 text-xs">
                                <div className="flex items-center justify-between gap-3">
                                    <span className="text-gray-500">Highest Temperature</span>
                                    <span className="font-bold text-gray-800 dark:text-white">
                                        {aiSummary.maxTempRecord ?
                  `${aiSummary.maxTempRecord.temperature.toFixed(1)}°C — ${formatDate(aiSummary.maxTempRecord.date)}` :
                  '—'}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between gap-3">
                                    <span className="text-gray-500">Lowest Temperature</span>
                                    <span className="font-bold text-gray-800 dark:text-white">
                                        {aiSummary.minTempRecord ?
                  `${aiSummary.minTempRecord.temperature.toFixed(1)}°C — ${formatDate(aiSummary.minTempRecord.date)}` :
                  '—'}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between gap-3">
                                    <span className="text-gray-500">
                                            {aiSummary.isAllPa ? 'Highest Network Daily Rainfall' : 'Highest Daily Rainfall'}
                                        </span>
                                    <span className="font-bold text-gray-800 dark:text-white">
                                        {aiSummary.maxRainRecord ?
                  `${aiSummary.maxRainRecord.precipitation.toFixed(1)} mm — ${formatDate(aiSummary.maxRainRecord.date)}` :
                  '—'}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between gap-3">
                                    <span className="text-gray-500">Strongest Wind Day</span>
                                    <span className="font-bold text-gray-800 dark:text-white">
                                        {aiSummary.maxWindRecord ?
                  `${aiSummary.maxWindRecord.windSpeed.toFixed(1)} m/s — ${formatDate(aiSummary.maxWindRecord.date)}` :
                  '—'}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between gap-3">
                                    <span className="text-gray-500">Longest Rainy Period</span>
                                    <span className="font-bold text-gray-800 dark:text-white">{aiSummary.longestWetSpell} day(s)</span>
                                </div>
                                <div className="flex items-center justify-between gap-3">
                                    <span className="text-gray-500">Longest Dry Period</span>
                                    <span className="font-bold text-gray-800 dark:text-white">{aiSummary.longestDrySpell} day(s)</span>
                                </div>

                            </div>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <div className="rounded-xl border border-gray-200/80 dark:border-gray-700 bg-white/80 dark:bg-gray-800/80 p-4">
                            <h4 className="text-[10px] font-bold uppercase tracking-wider text-emerald-800 dark:text-emerald-400 mb-3">
                                Data Completeness
                            </h4>
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <span className="block text-[9px] text-gray-400 uppercase">Average Data Completeness</span>
                                    <span className="text-sm font-bold text-gray-800 dark:text-white">
                                        {aiSummary.avgCompleteness !== null ? `${aiSummary.avgCompleteness.toFixed(1)}%` : '—'}
                                    </span>
                                </div>
                                <div>
                                    <span className="block text-[9px] text-gray-400 uppercase">
                                        {aiSummary.isAllPa ? 'Complete PA-Days' : 'Complete Data Days'}
                                    </span>
                                    <span className="text-sm font-bold text-gray-800 dark:text-white">
                                        {aiSummary.qcDays > 0 ? `${aiSummary.fullyCoveredDays}/${aiSummary.qcDays}` : '—'}
                                    </span>
                                </div>
                            </div>
                            <p className="mt-3 text-[10px] font-semibold text-gray-500">
                                {aiSummary.completenessStatus}. Based on unique ZENTRA 15-minute timestamps (96 expected per day).
                            </p>
                        </div>

                        <div className="rounded-xl border border-sky-200/80 bg-sky-50/30 dark:border-sky-900/50 dark:bg-sky-950/10 p-4">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h4 className="text-[10px] font-bold uppercase tracking-wider text-sky-800 dark:text-sky-300 mb-1">
                                        Sensor Cross-Check
                                    </h4>
                                    <p className="text-[9px] text-gray-500 dark:text-gray-400">
                                        Additional ZENTRA sensors are used internally to check rainfall consistency and support ground-condition interpretation.
                                    </p>
                                </div>
                                <span className="shrink-0 rounded-full bg-white px-2.5 py-1 text-[9px] font-bold text-sky-700 border border-sky-100 dark:bg-gray-900 dark:text-sky-300 dark:border-sky-800">
                                    Reference only
                                </span>
                            </div>

                            <div className="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <div>
                                    <span className="block text-[9px] text-gray-400 uppercase">Rainfall Cross-Check</span>
                                    <span className="font-bold text-gray-800 dark:text-white">
                                        {aiSummary.rainfallCrosscheckDays > 0 ?
                  `${aiSummary.rainfallCrosscheckGenerallyConsistent}/${aiSummary.rainfallCrosscheckDays} days generally consistent` :
                  'Unavailable'}
                                    </span>
                                </div>
                                <div>
                                    <span className="block text-[9px] text-gray-400 uppercase">Days for Review</span>
                                    <span className="font-bold text-gray-800 dark:text-white">
                                        {aiSummary.rainfallCrosscheckReviewCount}
                                    </span>
                                </div>
                                <div>
                                    <span className="block text-[9px] text-gray-400 uppercase">Ground Condition Signal</span>
                                    <span className="font-bold text-gray-800 dark:text-white">
                                        {aiSummary.soilContextSummary}
                                    </span>
                                    <span className="mt-1 block text-[8px] text-gray-400">
                                        Daily soil-moisture status summarized over the selected period.
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div className="rounded-xl border border-gray-200/80 dark:border-gray-700 bg-white/80 dark:bg-gray-800/80 p-4">
                            <h4 className="text-[10px] font-bold uppercase tracking-wider text-emerald-800 dark:text-emerald-400 mb-3">
                                Day-to-Day Variation
                            </h4>
                            <div className="grid grid-cols-2 gap-3 text-xs">
                                <div>
                                    <span className="block text-[9px] text-gray-400 uppercase">Temperature Variation</span>
                                    <span className="font-bold text-gray-800 dark:text-white">{aiSummary.tempStdDev.toFixed(2)}°C</span>
                                </div>
                                <div>
                                    <span className="block text-[9px] text-gray-400 uppercase">Wind Variation</span>
                                    <span className="font-bold text-gray-800 dark:text-white">{aiSummary.windStdDev.toFixed(2)} m/s</span>
                                </div>
                                <div>
                                    <span className="block text-[9px] text-gray-400 uppercase">Humidity Variation</span>
                                    <span className="font-bold text-gray-800 dark:text-white">{aiSummary.humidityStdDev.toFixed(2)}%</span>
                                </div>
                                <div>
                                    <span className="block text-[9px] text-gray-400 uppercase">Pressure Variation</span>
                                    <span className="font-bold text-gray-800 dark:text-white">{aiSummary.pressureStdDev.toFixed(3)} kPa</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {aiSummary.alerts.length > 0 &&
        <div className="rounded-xl border border-amber-200 bg-amber-50/70 dark:border-amber-900/50 dark:bg-amber-950/20 p-4">
                            <h4 className="text-[10px] font-bold uppercase tracking-wider text-amber-800 dark:text-amber-300 mb-2">
                                Things to Watch
                            </h4>
                            <p className="text-xs leading-relaxed text-amber-900 dark:text-amber-200">
                                The selected period includes {aiSummary.alerts.join(', ')}. These are items worth checking during field monitoring; they are not automatic hazard warnings.
                            </p>
                        </div>
        }

                    <div className="bg-white/90 dark:bg-gray-800/90 p-4 rounded-xl border border-emerald-500/20 dark:border-emerald-900/40 shadow-xs text-xs text-gray-700 dark:text-gray-300 leading-relaxed">
                        <strong className="text-emerald-800 dark:text-emerald-400 block mb-1.5 font-bold uppercase text-[10px] tracking-wider">
                            What the Data Shows
                        </strong>
                        <p>{aiSummary.explanation}</p>
                    </div>
                </Card>
      }
        </div>);

}
