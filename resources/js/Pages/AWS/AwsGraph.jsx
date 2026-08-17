import { useState } from 'react';
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
    Filler
} from 'chart.js';
import { Line } from 'react-chartjs-2';

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

    const triggerUpdate = (newPaId, newStartDate, newEndDate) => {
        router.get(
            route('aws.index'),
            {
                protected_area_id: newPaId !== undefined ? newPaId : selectedPaId,
                tab: 'analytics',
                graph_start_date: newStartDate !== undefined ? newStartDate : graphStartDate,
                graph_end_date: newEndDate !== undefined ? newEndDate : graphEndDate
            },
            { preserveState: true, preserveScroll: true }
        );
    };

    const handleProtectedAreaChange = (e) => {
        const val = e.target.value;
        setSelectedPaId(val);
        triggerUpdate(val, undefined, undefined);
    };

    const handleStartDateChange = (e) => {
        const val = e.target.value;
        setGraphStartDate(val);
        triggerUpdate(undefined, val, undefined);
    };

    const handleEndDateChange = (e) => {
        const val = e.target.value;
        setGraphEndDate(val);
        triggerUpdate(undefined, undefined, val);
    };

    // --- AI WEATHER ANALYSIS LOGIC ---
    const generateAiAnalysis = () => {
        if (!chartRecords || chartRecords.length === 0) return null;

        const temps = chartRecords.map(r => Number(r.air_temperature)).filter(v => !isNaN(v));
        const precip = chartRecords.map(r => Number(r.precipitation)).filter(v => !isNaN(v));
        const wind = chartRecords.map(r => Number(r.wind_speed)).filter(v => !isNaN(v));
        const humidity = chartRecords.map(r => Number(r.relative_humidity)).filter(v => !isNaN(v));

        const avgTemp = temps.length > 0 ? (temps.reduce((a, b) => a + b, 0) / temps.length).toFixed(1) : 0;
        const totalPrecip = precip.length > 0 ? precip.reduce((a, b) => a + b, 0).toFixed(1) : 0;
        const avgWind = wind.length > 0 ? (wind.reduce((a, b) => a + b, 0) / wind.length).toFixed(1) : 0;
        const avgHum = humidity.length > 0 ? (humidity.reduce((a, b) => a + b, 0) / humidity.length).toFixed(1) : 0;

        const firstDate = new Date(chartRecords[0].start_date);
        const lastDate = new Date(chartRecords[chartRecords.length - 1].start_date);
        const diffDays = Math.ceil(Math.abs(lastDate - firstDate) / (1000 * 60 * 60 * 24)) + 1;

        let season = 'Balanced / Transition Period';
        let badgeColor = 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-500/20';
        let explanation = `During this ${diffDays}-day observation period, weather conditions remained relatively stable. The average air temperature was recorded at ${avgTemp}°C with a cumulative precipitation of ${totalPrecip} mm. Wind speeds averaged ${avgWind} m/s and humidity levels at ${avgHum}%, indicating normal meteorological conditions ideal for standard field monitoring.`;

        if (totalPrecip > 80 || totalPrecip > (diffDays * 2.5)) {
            season = '🌧️ Rainy / Wet Period';
            badgeColor = 'bg-indigo-500/10 text-indigo-700 dark:text-indigo-300 border-indigo-500/20';
            explanation = `This evaluation spanning ${diffDays} days recorded a significant cumulative rainfall of ${totalPrecip} mm. With an average temperature of ${avgTemp}°C and a high relative humidity index of ${avgHum}%, the data reflects a wet climate pattern. Field teams should maintain vigilance regarding soil moisture retention and drainage within the protected area.`;
        } else if (avgTemp >= 26 && totalPrecip < 50) {
            season = '☀️ Dry & Warm Period';
            badgeColor = 'bg-amber-500/10 text-amber-700 dark:text-amber-300 border-amber-500/20';
            explanation = `The meteorological trend over this ${diffDays}-day span indicates warm and relatively dry conditions. Temperatures averaged a warm ${avgTemp}°C while total rainfall remained low at ${totalPrecip} mm. Environmental parameters show high evaporation potential, requiring monitoring for dry spells or localized heat stress.`;
        }

        return { season, explanation, avgTemp, totalPrecip, avgWind, avgHum, badgeColor, diffDays };
    };

    const aiSummary = generateAiAnalysis();

    // Chart Gradient Effect Handler
    const handleChartInit = (chart) => {
        const ctx = chart.ctx;
        const gradient = ctx.createLinearGradient(0, 0, 0, 400);
        gradient.addColorStop(0, 'rgba(16, 185, 129, 0.35)');
        gradient.addColorStop(1, 'rgba(16, 185, 129, 0.0)');
        chart.data.datasets[0].backgroundColor = gradient;
        chart.update();
    };

    const lineChartData = {
        labels: chartRecords.map(r => r.start_date),
        datasets: [{
            label: graphMetric.replace('_', ' ').toUpperCase(),
            data: chartRecords.map(r => Number(r[graphMetric]) || 0),
            borderColor: 'rgb(16, 185, 129)',
            borderWidth: 2.5,
            pointBackgroundColor: 'rgb(16, 185, 129)',
            pointBorderColor: '#fff',
            pointHoverRadius: 6,
            fill: true,
            tension: 0.35,
        }],
    };

    const lineChartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'top',
                labels: { font: { weight: 'bold', size: 11 }, usePointStyle: true }
            },
            title: { display: false },
        },
        scales: {
            x: { grid: { display: false } },
            y: { grid: { color: 'rgba(156, 163, 175, 0.1)' } }
        }
    };

    return (
        <div className="space-y-6">
            <Card className="border border-gray-100 dark:border-gray-800 shadow-xl rounded-2xl p-6 space-y-6 bg-white dark:bg-gray-900 transition-all duration-300">
                {/* FILTERS SECTION */}
                <div className="flex flex-col sm:flex-row items-center gap-4 bg-gray-50/80 dark:bg-gray-800/40 p-4 rounded-xl border border-gray-200/60 dark:border-gray-700/60 backdrop-blur-sm">
                    <div className="w-full sm:w-auto">
                        <label className="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Protected Area</label>
                        <select
                            value={selectedPaId}
                            onChange={handleProtectedAreaChange}
                            className="rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-xs px-3 py-2 font-medium w-full sm:w-52 shadow-xs focus:ring-emerald-500 focus:border-emerald-500"
                        >
                            <option value="">All Protected Areas</option>
                            {protectedAreas.map((pa) => (
                                <option key={pa.id} value={pa.id}>{pa.name}</option>
                            ))}
                        </select>
                    </div>

                    <div className="w-full sm:w-auto">
                        <label className="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Select Metric</label>
                        <select
                            value={graphMetric}
                            onChange={(e) => setGraphMetric(e.target.value)}
                            className="rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-xs px-3 py-2 font-medium w-full sm:w-52 shadow-xs focus:ring-emerald-500 focus:border-emerald-500"
                        >
                            <option value="air_temperature">Air Temperature (°C)</option>
                            <option value="precipitation">Precipitation (mm)</option>
                            <option value="wind_speed">Wind Speed (m/s)</option>
                            <option value="relative_humidity">Relative Humidity (%)</option>
                            <option value="atmospheric_pressure">Atmospheric Pressure (kPa)</option>
                        </select>
                    </div>

                    <div className="w-full sm:w-auto">
                        <label className="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Start Date</label>
                        <input
                            type="date"
                            value={graphStartDate}
                            onChange={handleStartDateChange}
                            className="rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-xs px-3 py-2 font-medium shadow-xs focus:ring-emerald-500 focus:border-emerald-500"
                        />
                    </div>

                    <div className="w-full sm:w-auto">
                        <label className="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">End Date</label>
                        <input
                            type="date"
                            value={graphEndDate}
                            onChange={handleEndDateChange}
                            className="rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-xs px-3 py-2 font-medium shadow-xs focus:ring-emerald-500 focus:border-emerald-500"
                        />
                    </div>
                </div>

                <div className="w-full h-[440px] flex items-center justify-center p-2 relative">
                    {chartRecords.length > 0 ? (
                        <Line
                            data={lineChartData}
                            options={lineChartOptions}
                            getChart={handleChartInit}
                        />
                    ) : (
                        <div className="text-center p-8 text-gray-400">
                            <span className="text-4xl mb-2 block">📊</span>
                            <p className="text-sm font-semibold">No weather data found for the selected date range.</p>
                        </div>
                    )}
                </div>
            </Card>

            {/* AI CARD ANALYSIS (MODERN UI) */}
            {aiSummary && (
                <Card className="border border-emerald-500/20 shadow-2xl rounded-2xl p-6 bg-gradient-to-br from-white via-emerald-50/20 to-emerald-100/30 dark:from-gray-900 dark:via-gray-900 dark:to-emerald-950/20 space-y-5 transition-all duration-300">
                    <div className="flex items-center justify-between border-b border-gray-200/60 dark:border-gray-800 pb-4">
                        <div className="flex items-center gap-3">
                            <div className="w-9 h-9 rounded-xl bg-emerald-500/10 flex items-center justify-center text-emerald-600 dark:text-emerald-400 shadow-inner">
                                <span className="text-lg">🤖</span>
                            </div>
                            <div>
                                <h3 className="text-xs font-bold uppercase tracking-widest text-gray-800 dark:text-gray-200">
                                    AI Weather Intelligence & Trends
                                </h3>
                                <p className="text-[10px] text-gray-500 dark:text-gray-400">Automated meteorological analytics report</p>
                            </div>
                        </div>
                        <span className={`px-3.5 py-1.5 rounded-full text-xs font-bold border shadow-xs ${aiSummary.badgeColor}`}>
                            {aiSummary.season}
                        </span>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div className="bg-white/90 dark:bg-gray-800/80 p-4 rounded-xl border border-gray-100 dark:border-gray-700/80 shadow-xs flex items-center gap-3">
                            <div className="p-2.5 rounded-lg bg-orange-500/10 text-orange-600 text-sm font-bold">🌡️</div>
                            <div>
                                <span className="block text-[9px] font-bold uppercase tracking-wider text-gray-400">Average Air Temp</span>
                                <span className="text-base font-extrabold text-gray-800 dark:text-white">{aiSummary.avgTemp}°C</span>
                            </div>
                        </div>

                        <div className="bg-white/90 dark:bg-gray-800/80 p-4 rounded-xl border border-gray-100 dark:border-gray-700/80 shadow-xs flex items-center gap-3">
                            <div className="p-2.5 rounded-lg bg-blue-500/10 text-blue-600 text-sm font-bold">🌧️</div>
                            <div>
                                <span className="block text-[9px] font-bold uppercase tracking-wider text-gray-400">Total Precipitation</span>
                                <span className="text-base font-extrabold text-gray-800 dark:text-white">{aiSummary.totalPrecip} mm</span>
                            </div>
                        </div>

                        <div className="bg-white/90 dark:bg-gray-800/80 p-4 rounded-xl border border-gray-100 dark:border-gray-700/80 shadow-xs flex items-center gap-3">
                            <div className="p-2.5 rounded-lg bg-emerald-500/10 text-emerald-600 text-sm font-bold">📅</div>
                            <div>
                                <span className="block text-[9px] font-bold uppercase tracking-wider text-gray-400">Period Span / Range</span>
                                <span className="text-base font-extrabold text-emerald-700 dark:text-emerald-400">{aiSummary.diffDays} Days</span>
                            </div>
                        </div>
                    </div>

                    <div className="bg-white/90 dark:bg-gray-800/90 p-4 rounded-xl border border-emerald-500/20 dark:border-emerald-900/40 shadow-xs text-xs text-gray-700 dark:text-gray-300 leading-relaxed">
                        <strong className="text-emerald-800 dark:text-emerald-400 block mb-1.5 font-bold uppercase text-[10px] tracking-wider">
                            Detailed Explanation & Insight:
                        </strong>
                        <p>{aiSummary.explanation}</p>
                    </div>
                </Card>
            )}
        </div>
    );
}
