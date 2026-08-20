import { useMemo, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';

import AuthenticatedLayout from '../Layouts/AuthenticatedLayout';
import Card from '../Components/Card';
import DataTable from '../Components/DataTable';
import StatusBadge from '../Components/StatusBadge';

export default function Dashboard({
    protectedAreasCount = 0,

    activeManagementPlansCount = 0,
    expiredManagementPlansCount = 0,
    plansForUpdatingCount = 0,

    bmsRecordsCount = 0,
    bamsRecordsCount = 0,
    newSpeciesCount = 0,
    bmsThreatsCount = 0,

    imeaAssessmentsCount = 0,

    totalFacilitiesCount = 0,
    functionalFacilitiesCount = 0,

    awsCount = 0,
    awsChartData = [],

    dbActivities = [],

    semester1Count = 0,
    semester2Count = 0,
}) {
    const { auth } = usePage().props;

    // ============================================================
    // MANAGEMENT PLAN PERCENTAGES
    // ============================================================

    const totalManagedPlans =
        activeManagementPlansCount +
        expiredManagementPlansCount +
        plansForUpdatingCount;

    const activePercent =
        totalManagedPlans > 0
            ? Math.round(
                  (activeManagementPlansCount / totalManagedPlans) * 100
              )
            : 0;

    const expiredPercent =
        totalManagedPlans > 0
            ? Math.round(
                  (expiredManagementPlansCount / totalManagedPlans) * 100
              )
            : 0;

    const updatingPercent =
        totalManagedPlans > 0
            ? Math.round(
                  (plansForUpdatingCount / totalManagedPlans) * 100
              )
            : 0;

    // ============================================================
    // BMS GRAPH
    // ============================================================

    const maxSemesterCount = Math.max(
        semester1Count,
        semester2Count,
        1
    );

    const sem1HeightPercent = Math.max(
        Math.round((semester1Count / maxSemesterCount) * 75),
        15
    );

    const sem2HeightPercent = Math.max(
        Math.round((semester2Count / maxSemesterCount) * 75),
        15
    );

    // ============================================================
    // AWS GRAPH
    // ============================================================

    const [awsMetric, setAwsMetric] = useState('air_temperature');

    const awsMetrics = {
        air_temperature: {
            label: 'Air Temperature',
            shortLabel: 'Temperature',
            unit: '°C',
        },

        precipitation: {
            label: 'Precipitation',
            shortLabel: 'Rainfall',
            unit: 'mm',
        },

        relative_humidity: {
            label: 'Relative Humidity',
            shortLabel: 'Humidity',
            unit: '%',
        },

        atmospheric_pressure: {
            label: 'Atmospheric Pressure',
            shortLabel: 'Pressure',
            unit: 'hPa',
        },

        wind_speed: {
            label: 'Wind Speed',
            shortLabel: 'Wind',
            unit: 'm/s',
        },
    };

    const selectedAwsMetric = awsMetrics[awsMetric];

    const awsValues = useMemo(() => {
        return awsChartData
            .map((item) => {
                const value = Number(item?.[awsMetric]);

                return {
                    ...item,
                    numericValue: Number.isFinite(value)
                        ? value
                        : null,
                };
            })
            .filter((item) => item.numericValue !== null);
    }, [awsChartData, awsMetric]);

    const awsGraph = useMemo(() => {
        if (awsValues.length === 0) {
            return {
                points: '',
                areaPoints: '',
                min: 0,
                max: 0,
            };
        }

        const values = awsValues.map(
            (item) => item.numericValue
        );

        let min = Math.min(...values);
        let max = Math.max(...values);

        // Prevent zero-height graph when all values are identical.
        if (min === max) {
            min -= 1;
            max += 1;
        }

        const width = 900;
        const height = 280;

        const paddingX = 45;
        const paddingY = 25;

        const graphWidth = width - paddingX * 2;
        const graphHeight = height - paddingY * 2;

        const points = awsValues
            .map((item, index) => {
                const x =
                    awsValues.length === 1
                        ? width / 2
                        : paddingX +
                          (index / (awsValues.length - 1)) *
                              graphWidth;

                const y =
                    paddingY +
                    ((max - item.numericValue) /
                        (max - min)) *
                        graphHeight;

                return `${x},${y}`;
            })
            .join(' ');

        const firstX =
            awsValues.length === 1
                ? width / 2
                : paddingX;

        const lastX =
            awsValues.length === 1
                ? width / 2
                : paddingX + graphWidth;

        const areaPoints = [
            `${firstX},${height - paddingY}`,
            points,
            `${lastX},${height - paddingY}`,
        ].join(' ');

        return {
            points,
            areaPoints,
            min,
            max,
            width,
            height,
            paddingX,
            paddingY,
        };
    }, [awsValues]);

    const awsLatestValue =
        awsValues.length > 0
            ? awsValues[awsValues.length - 1].numericValue
            : null;

    const awsPreviousValue =
        awsValues.length > 1
            ? awsValues[awsValues.length - 2].numericValue
            : null;

    const awsChange =
        awsLatestValue !== null &&
        awsPreviousValue !== null
            ? awsLatestValue - awsPreviousValue
            : null;

    // ============================================================
    // RECENT ACTIVITIES TABLE
    // ============================================================

    const columns = [
        {
            key: 'activity',
            label: 'Activity',

            render: (row) => (
                <span className="font-semibold text-gray-900 dark:text-white">
                    {row.activity}
                </span>
            ),
        },

        {
            key: 'module',
            label: 'Module',

            render: (row) => {
                let badgeColor =
                    'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';

                if (row.module === 'BMS') {
                    badgeColor =
                        'bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-300';
                } else if (row.module === 'BAMS') {
                    badgeColor =
                        'bg-purple-50 text-purple-700 border border-purple-200 dark:bg-purple-950/50 dark:text-purple-300';
                } else if (row.module === 'IMEA') {
                    badgeColor =
                        'bg-cyan-50 text-cyan-700 border border-cyan-200 dark:bg-cyan-950/50 dark:text-cyan-300';
                } else if (row.module === 'Management Plans') {
                    badgeColor =
                        'bg-blue-50 text-blue-700 border border-blue-200 dark:bg-blue-950/50 dark:text-blue-300';
                } else if (row.module === 'AWS') {
                    badgeColor =
                        'bg-sky-50 text-sky-700 border border-sky-200 dark:bg-sky-950/50 dark:text-sky-300';
                }

                return (
                    <span
                        className={`text-[11px] font-bold px-2.5 py-1 rounded-md ${badgeColor}`}
                    >
                        {row.module}
                    </span>
                );
            },
        },

        {
            key: 'date',
            label: 'Date',
            cellClassName: 'text-gray-500 text-sm',

            render: (row) => (
                <span className="text-xs">
                    {row.date}
                </span>
            ),
        },

        {
            key: 'status',
            label: 'Status',

            render: (row) => (
                <StatusBadge
                    variant={
                        row.status === 'Completed'
                            ? 'active'
                            : 'pending'
                    }
                >
                    {row.status}
                </StatusBadge>
            ),
        },
    ];

    // ============================================================
    // RENDER
    // ============================================================

    return (
        <AuthenticatedLayout title="Consolidated CDS Dashboard">

            <div className="relative z-0 pb-3 pt-2">

                <div className="relative overflow-hidden rounded-2xl bg-gradient-to-r from-emerald-600 via-teal-700 to-cyan-800 p-6 text-white shadow-xl">

                    <div className="absolute -right-8 -top-8 h-32 w-32 rounded-full bg-white/15 pointer-events-none"></div>

                    <div className="relative z-10 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

                        <div>

                            <p className="text-xs font-bold uppercase tracking-wider text-emerald-100 opacity-90">
                                PENRO Davao Oriental
                            </p>

                            <h1 className="mt-1 text-xl sm:text-2xl font-extrabold tracking-tight text-white">
                                PENRO Davao Oriental - CDS Dashboard
                            </h1>

                            <p className="mt-1 max-w-3xl text-xs sm:text-sm text-emerald-100 opacity-90">
                                Unified monitoring overview for Protected Areas,
                                Management Plans, BMS, BAMS, IMEA, AWS, and
                                Infrastructure Inventories.
                            </p>

                        </div>

                        <span className="inline-flex w-fit items-center gap-1.5 rounded-xl bg-white/10 backdrop-blur-md px-3.5 py-2 text-xs font-semibold text-white shadow-xs border border-white/20 whitespace-nowrap">

                            <span className="h-2 w-2 rounded-full bg-emerald-400 animate-pulse" />

                            Live System

                        </span>

                    </div>

                </div>

            </div>

            <div className="space-y-6 mt-4">

                {/* ========================================================
                    WELCOME BANNER
                ======================================================== */}

                <Card className="border-l-4 border-l-emerald-600 bg-white dark:bg-gray-800 shadow-sm rounded-2xl">

                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

                        <div>

                            <p className="text-xs font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">
                                Welcome back, Conservation Development Section 👋
                            </p>

                            <h2 className="mt-1 text-xl font-extrabold text-gray-900 dark:text-white">
                                Conservation Development Section IMS
                            </h2>

                            <p className="mt-1 max-w-2xl text-xs sm:text-sm text-gray-600 dark:text-gray-300">
                                Real-time consolidated tracking across biodiversity
                                monitoring systems, permanent plots, automated
                                weather stations, and management plans.
                            </p>

                        </div>

                        <span className="inline-flex w-fit items-center gap-1.5 rounded-lg bg-gray-50 dark:bg-gray-900 px-3 py-1.5 text-xs font-semibold text-emerald-600 dark:text-emerald-300 shadow-xs border border-emerald-200 dark:border-emerald-800">

                            <span className="h-2 w-2 rounded-full bg-emerald-500" />

                            PENRO Davao Oriental

                        </span>

                    </div>

                </Card>

                {/* ========================================================
                    STATISTICS CARDS
                ======================================================== */}

                <section
                    className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
                    aria-label="Consolidated system statistics"
                >

                    {/* Protected Areas */}

                    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-emerald-600 to-green-800 p-5 text-white shadow-md flex flex-col justify-between min-h-[135px] hover:scale-[1.01] transition-transform">

                        <div className="absolute -right-6 -top-6 h-28 w-28 rounded-full bg-white/10 pointer-events-none"></div>

                        <div className="relative z-10 flex items-center justify-between">

                            <span className="text-2xl">
                                🌲
                            </span>

                            <span className="text-3xl font-extrabold tracking-tight">
                                {protectedAreasCount}
                            </span>

                        </div>

                        <div className="relative z-10 mt-auto pt-4">

                            <h4 className="text-xs font-semibold uppercase tracking-wider text-emerald-100">
                                Protected Areas
                            </h4>

                        </div>

                    </div>

                    {/* Management Plans */}

                    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-700 p-5 text-white shadow-md flex flex-col justify-between min-h-[135px] hover:scale-[1.01] transition-transform">

                        <div className="absolute -right-6 -top-6 h-28 w-28 rounded-full bg-white/10 pointer-events-none"></div>

                        <div className="relative z-10 flex items-center justify-between">

                            <span className="text-2xl">
                                📄
                            </span>

                            <span className="text-3xl font-extrabold tracking-tight">
                                {activeManagementPlansCount}
                            </span>

                        </div>

                        <div className="relative z-10 mt-auto pt-4">

                            <h4 className="text-xs font-semibold uppercase tracking-wider text-blue-100">
                                Management Plans
                            </h4>

                        </div>

                    </div>

                    {/* BMS Records */}

                    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-teal-600 to-emerald-800 p-5 text-white shadow-md flex flex-col justify-between min-h-[135px] hover:scale-[1.01] transition-transform">

                        <div className="absolute -right-6 -top-6 h-28 w-28 rounded-full bg-white/10 pointer-events-none"></div>

                        <div className="relative z-10 flex items-center justify-between">

                            <span className="text-2xl">
                                🐾
                            </span>

                            <span className="text-3xl font-extrabold tracking-tight">
                                {bmsRecordsCount}
                            </span>

                        </div>

                        <div className="relative z-10 mt-auto pt-3">

                            <h4 className="text-xs font-semibold uppercase tracking-wider text-teal-100">
                                BMS Records
                            </h4>

                            {newSpeciesCount > 0 && (
                                <Link
                                    href={route('bms.index')}
                                    className="mt-1.5 inline-flex items-center gap-1.5 rounded-md bg-amber-500/30 border border-amber-400/40 px-2 py-0.5 text-[11px] font-medium text-amber-100 hover:bg-amber-500/40 transition"
                                >
                                    <span className="h-2 w-2 rounded-full bg-amber-400 animate-pulse"></span>

                                    <span>
                                        {newSpeciesCount} new species detected →
                                    </span>
                                </Link>
                            )}

                        </div>

                    </div>

                    {/* BAMS Records */}

                    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-purple-600 to-fuchsia-800 p-5 text-white shadow-md flex flex-col justify-between min-h-[135px] hover:scale-[1.01] transition-transform">

                        <div className="absolute -right-6 -top-6 h-28 w-28 rounded-full bg-white/10 pointer-events-none"></div>

                        <div className="relative z-10 flex items-center justify-between">

                            <span className="text-2xl">
                                📊
                            </span>

                            <span className="text-3xl font-extrabold tracking-tight">
                                {bamsRecordsCount}
                            </span>

                        </div>

                        <div className="relative z-10 mt-auto pt-4">

                            <h4 className="text-xs font-semibold uppercase tracking-wider text-purple-100">
                                BAMS Records
                            </h4>

                        </div>

                    </div>

                    {/* BMS Threats */}

                    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-rose-600 to-pink-800 p-5 text-white shadow-md flex flex-col justify-between min-h-[135px] hover:scale-[1.01] transition-transform">

                        <div className="absolute -right-6 -top-6 h-28 w-28 rounded-full bg-white/10 pointer-events-none"></div>

                        <div className="relative z-10 flex items-center justify-between">

                            <span className="text-2xl">
                                ⚠️
                            </span>

                            <span className="text-3xl font-extrabold tracking-tight">
                                {bmsThreatsCount}
                            </span>

                        </div>

                        <div className="relative z-10 mt-auto pt-4">

                            <h4 className="text-xs font-semibold uppercase tracking-wider text-rose-100">
                                BMS Threats
                            </h4>

                        </div>

                    </div>

                    {/* IMEA */}

                    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-cyan-600 to-blue-900 p-5 text-white shadow-md flex flex-col justify-between min-h-[135px] hover:scale-[1.01] transition-transform">

                        <div className="absolute -right-6 -top-6 h-28 w-28 rounded-full bg-white/10 pointer-events-none"></div>

                        <div className="relative z-10 flex items-center justify-between">

                            <span className="text-2xl">
                                📈
                            </span>

                            <span className="text-3xl font-extrabold tracking-tight">
                                {imeaAssessmentsCount}
                            </span>

                        </div>

                        <div className="relative z-10 mt-auto pt-4">

                            <h4 className="text-xs font-semibold uppercase tracking-wider text-cyan-100">
                                IMEA Assessments
                            </h4>

                        </div>

                    </div>

                    {/* Facilities */}

                    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-amber-500 to-rose-700 p-5 text-white shadow-md flex flex-col justify-between min-h-[135px] hover:scale-[1.01] transition-transform">

                        <div className="absolute -right-6 -top-6 h-28 w-28 rounded-full bg-white/10 pointer-events-none"></div>

                        <div className="relative z-10 flex items-center justify-between">

                            <span className="text-2xl">
                                🏗️
                            </span>

                            <span className="text-3xl font-extrabold tracking-tight">
                                {totalFacilitiesCount}
                            </span>

                        </div>

                        <div className="relative z-10 mt-auto pt-4">

                            <h4 className="text-xs font-semibold uppercase tracking-wider text-amber-100">
                                Facilities Inventory
                            </h4>

                        </div>

                    </div>

                    {/* AWS */}

                    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-sky-500 to-indigo-800 p-5 text-white shadow-md flex flex-col justify-between min-h-[135px] hover:scale-[1.01] transition-transform">

                        <div className="absolute -right-6 -top-6 h-28 w-28 rounded-full bg-white/10 pointer-events-none"></div>

                        <div className="relative z-10 flex items-center justify-between">

                            <span className="text-2xl">
                                🌤️
                            </span>

                            <span className="text-3xl font-extrabold tracking-tight">
                                {awsCount}
                            </span>

                        </div>

                        <div className="relative z-10 mt-auto pt-4">

                            <h4 className="text-xs font-semibold uppercase tracking-wider text-sky-100">
                                AWS Submitted Reports
                            </h4>

                            <p className="mt-1 text-[10px] text-sky-100/80">
                                Official monitoring reports
                            </p>

                        </div>

                    </div>

                </section>

                {/* ========================================================
                    MANAGEMENT PLANS + BMS
                ======================================================== */}

                <section className="grid gap-6 lg:grid-cols-2">

                    {/* Management Plans Health */}

                    <Card className="border border-gray-100 dark:border-gray-800 shadow-sm rounded-2xl p-6">

                        <div className="flex items-center justify-between mb-5">

                            <div>

                                <h2 className="font-extrabold text-gray-950 dark:text-white text-base">
                                    Management Plans Health
                                </h2>

                                <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    Real-time status proportion across registered protected areas.
                                </p>

                            </div>

                            <Link
                                href="/management-plans/summary"
                                className="text-xs font-bold text-emerald-700 hover:text-emerald-900 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/50 px-3 py-1.5 rounded-xl border border-emerald-200 dark:border-emerald-800 transition"
                            >
                                Full Report →
                            </Link>

                        </div>

                        <div className="space-y-4 bg-gray-50/50 dark:bg-gray-900/30 p-4 rounded-2xl border border-gray-100 dark:border-gray-800">

                            <div>

                                <div className="flex justify-between text-xs font-bold mb-1.5 text-gray-700 dark:text-gray-300">

                                    <span>
                                        🟢 Active / Approved ({activeManagementPlansCount})
                                    </span>

                                    <span className="font-mono text-emerald-600">
                                        {activePercent}%
                                    </span>

                                </div>

                                <div className="h-3 w-full bg-gray-200 dark:bg-gray-800 rounded-full overflow-hidden">

                                    <div
                                        className="h-full bg-emerald-600 rounded-full transition-all duration-500 shadow-sm"
                                        style={{
                                            width: `${activePercent}%`,
                                        }}
                                    />

                                </div>

                            </div>

                            <div>

                                <div className="flex justify-between text-xs font-bold mb-1.5 text-gray-700 dark:text-gray-300">

                                    <span>
                                        🔄 For Updating ({plansForUpdatingCount})
                                    </span>

                                    <span className="font-mono text-amber-600">
                                        {updatingPercent}%
                                    </span>

                                </div>

                                <div className="h-3 w-full bg-gray-200 dark:bg-gray-800 rounded-full overflow-hidden">

                                    <div
                                        className="h-full bg-amber-500 rounded-full transition-all duration-500 shadow-sm"
                                        style={{
                                            width: `${updatingPercent}%`,
                                        }}
                                    />

                                </div>

                            </div>

                            <div>

                                <div className="flex justify-between text-xs font-bold mb-1.5 text-gray-700 dark:text-gray-300">

                                    <span>
                                        🔴 Expired Plans ({expiredManagementPlansCount})
                                    </span>

                                    <span className="font-mono text-rose-600">
                                        {expiredPercent}%
                                    </span>

                                </div>

                                <div className="h-3 w-full bg-gray-200 dark:bg-gray-800 rounded-full overflow-hidden">

                                    <div
                                        className="h-full bg-rose-600 rounded-full transition-all duration-500 shadow-sm"
                                        style={{
                                            width: `${expiredPercent}%`,
                                        }}
                                    />

                                </div>

                            </div>

                        </div>

                    </Card>

                    {/* BMS */}

                    <Card className="border border-gray-100 dark:border-gray-800 shadow-sm rounded-2xl p-6">

                        <div className="flex items-center justify-between mb-4">

                            <div>

                                <h2 className="font-extrabold text-gray-950 dark:text-white text-base">
                                    Semestral Biodiversity Monitoring (BMS) Trends
                                </h2>

                                <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    Visual overview of wildlife & habitat sightings per semester.
                                </p>

                            </div>

                            <Link
                                href="/bms"
                                className="text-xs font-bold text-emerald-700 hover:text-emerald-900 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/50 px-3 py-1.5 rounded-xl border border-emerald-200 dark:border-emerald-800 transition"
                            >
                                View BMS →
                            </Link>

                        </div>

                        <div className="mt-4">

                            <div className="flex items-end justify-around h-44 gap-6 px-4 bg-gray-50 dark:bg-gray-900/40 rounded-2xl p-4 border border-gray-100 dark:border-gray-800">

                                <div className="flex flex-col items-center justify-end gap-2 w-1/2 h-full">

                                    <span className="text-[11px] font-bold text-gray-500">
                                        Semester 1
                                    </span>

                                    <div
                                        className="w-full bg-teal-500/20 dark:bg-teal-500/30 rounded-t-xl flex items-center justify-center pb-2 transition-all"
                                        style={{
                                            height: `${sem1HeightPercent}%`,
                                        }}
                                    >
                                        <span className="text-xs font-bold text-teal-700 dark:text-teal-300 whitespace-nowrap">
                                            {semester1Count} Rec
                                        </span>
                                    </div>

                                    <span className="text-[10px] text-gray-400 font-medium">
                                        Jan - Jun
                                    </span>

                                </div>

                                <div className="flex flex-col items-center justify-end gap-2 w-1/2 h-full">

                                    <span className="text-[11px] font-bold text-gray-500">
                                        Semester 2
                                    </span>

                                    <div
                                        className="w-full bg-emerald-600 rounded-t-xl flex items-center justify-center pb-2 transition-all shadow-sm"
                                        style={{
                                            height: `${sem2HeightPercent}%`,
                                        }}
                                    >
                                        <span className="text-xs font-bold text-white whitespace-nowrap">
                                            {semester2Count} Rec
                                        </span>
                                    </div>

                                    <span className="text-[10px] text-gray-400 font-medium">
                                        Jul - Dec
                                    </span>

                                </div>

                            </div>

                            <div className="flex items-center justify-between mt-3 text-[11px] text-gray-500 px-1">

                                <span>
                                    Total Recorded Indices:{' '}
                                    <strong className="text-gray-900 dark:text-white">
                                        {bmsRecordsCount}
                                    </strong>
                                </span>

                                <span className="text-emerald-600 font-semibold">
                                    Semestral Monitoring Cycle
                                </span>

                            </div>

                        </div>

                    </Card>

                </section>

                {/* ========================================================
                    AWS WEATHER DATA GRAPH
                ======================================================== */}

                <Card className="border border-gray-100 dark:border-gray-800 shadow-sm rounded-2xl p-6">

                    <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">

                        <div>

                            <h2 className="font-extrabold text-gray-950 dark:text-white text-base">
                                AWS Weather Data Trends
                            </h2>

                            <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                Imported weather observations from the latest 30-day monitoring period.
                            </p>

                        </div>

                        <div className="flex flex-wrap gap-2">

                            {Object.entries(awsMetrics).map(
                                ([key, metric]) => (
                                    <button
                                        key={key}
                                        type="button"
                                        onClick={() =>
                                            setAwsMetric(key)
                                        }
                                        className={`rounded-lg px-3 py-1.5 text-[11px] font-bold border transition ${
                                            awsMetric === key
                                                ? 'bg-sky-600 text-white border-sky-600 shadow-sm'
                                                : 'bg-white dark:bg-gray-900 text-gray-600 dark:text-gray-300 border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800'
                                        }`}
                                    >
                                        {metric.shortLabel}
                                    </button>
                                )
                            )}

                        </div>

                    </div>

                    {/* AWS Summary */}

                    <div className="mt-5 grid gap-3 sm:grid-cols-3">

                        <div className="rounded-xl bg-sky-50 dark:bg-sky-950/30 border border-sky-100 dark:border-sky-900/50 p-4">

                            <p className="text-[10px] font-bold uppercase tracking-wider text-sky-600 dark:text-sky-400">
                                Selected Metric
                            </p>

                            <p className="mt-1 text-sm font-extrabold text-gray-900 dark:text-white">
                                {selectedAwsMetric.label}
                            </p>

                        </div>

                        <div className="rounded-xl bg-gray-50 dark:bg-gray-900/40 border border-gray-100 dark:border-gray-800 p-4">

                            <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500">
                                Latest Reading
                            </p>

                            <p className="mt-1 text-xl font-black text-gray-900 dark:text-white">

                                {awsLatestValue !== null
                                    ? `${awsLatestValue} ${selectedAwsMetric.unit}`
                                    : '—'}

                            </p>

                        </div>

                        <div className="rounded-xl bg-gray-50 dark:bg-gray-900/40 border border-gray-100 dark:border-gray-800 p-4">

                            <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500">
                                Change from Previous
                            </p>

                            <p
                                className={`mt-1 text-xl font-black ${
                                    awsChange === null
                                        ? 'text-gray-900 dark:text-white'
                                        : awsChange > 0
                                        ? 'text-emerald-600'
                                        : awsChange < 0
                                        ? 'text-rose-600'
                                        : 'text-gray-900 dark:text-white'
                                }`}
                            >
                                {awsChange === null
                                    ? '—'
                                    : `${awsChange > 0 ? '+' : ''}${awsChange.toFixed(
                                          2
                                      )} ${selectedAwsMetric.unit}`}
                            </p>

                        </div>

                    </div>

                    {/* Graph */}

                    <div className="mt-5 rounded-2xl border border-gray-100 dark:border-gray-800 bg-gray-50/60 dark:bg-gray-900/30 p-4">

                        {awsValues.length > 0 ? (
                            <>

                                <div className="w-full overflow-x-auto">

                                    <svg
                                        viewBox="0 0 900 280"
                                        className="w-full min-w-[700px] h-[280px]"
                                        preserveAspectRatio="none"
                                    >

                                        {/* Horizontal guide lines */}

                                        {[0, 1, 2, 3, 4].map(
                                            (line) => {
                                                const y =
                                                    25 +
                                                    (line / 4) *
                                                        230;

                                                return (
                                                    <line
                                                        key={line}
                                                        x1="45"
                                                        y1={y}
                                                        x2="855"
                                                        y2={y}
                                                        stroke="currentColor"
                                                        className="text-gray-200 dark:text-gray-800"
                                                        strokeWidth="1"
                                                        strokeDasharray="4 5"
                                                    />
                                                );
                                            }
                                        )}

                                        {/* Area */}

                                        <polygon
                                            points={
                                                awsGraph.areaPoints
                                            }
                                            fill="currentColor"
                                            className="text-sky-100 dark:text-sky-950/40"
                                            opacity="0.8"
                                        />

                                        {/* Main Line */}

                                        <polyline
                                            points={
                                                awsGraph.points
                                            }
                                            fill="none"
                                            stroke="currentColor"
                                            className="text-sky-600 dark:text-sky-400"
                                            strokeWidth="4"
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                        />

                                        {/* Data Points */}

                                        {awsValues.map(
                                            (item, index) => {

                                                const width = 900;
                                                const height = 280;
                                                const paddingX = 45;
                                                const paddingY = 25;

                                                const graphWidth =
                                                    width -
                                                    paddingX * 2;

                                                const graphHeight =
                                                    height -
                                                    paddingY * 2;

                                                const x =
                                                    awsValues.length ===
                                                    1
                                                        ? width / 2
                                                        : paddingX +
                                                          (index /
                                                              (awsValues.length -
                                                                  1)) *
                                                              graphWidth;

                                                const y =
                                                    paddingY +
                                                    ((awsGraph.max -
                                                        item.numericValue) /
                                                        (awsGraph.max -
                                                            awsGraph.min)) *
                                                        graphHeight;

                                                return (
                                                    <circle
                                                        key={`${item.date}-${index}`}
                                                        cx={x}
                                                        cy={y}
                                                        r="4"
                                                        fill="currentColor"
                                                        className="text-sky-600 dark:text-sky-400"
                                                    />
                                                );
                                            }
                                        )}

                                    </svg>

                                </div>

                                {/* Date labels */}

                                <div className="mt-1 flex justify-between px-8 text-[10px] text-gray-400">

                                    <span>
                                        {awsValues[0]?.full_date}
                                    </span>

                                    <span>
                                        {awsValues[
                                            Math.floor(
                                                awsValues.length / 2
                                            )
                                        ]?.full_date}
                                    </span>

                                    <span>
                                        {
                                            awsValues[
                                                awsValues.length - 1
                                            ]?.full_date
                                        }
                                    </span>

                                </div>

                            </>
                        ) : (
                            <div className="h-[280px] flex flex-col items-center justify-center text-center">

                                <div className="text-4xl">
                                    🌤️
                                </div>

                                <h3 className="mt-3 text-sm font-bold text-gray-900 dark:text-white">
                                    No AWS weather data available
                                </h3>

                                <p className="mt-1 max-w-md text-xs text-gray-500 dark:text-gray-400">
                                    Import AWS weather data first. Imported
                                    records will automatically appear here as
                                    a dashboard trend.
                                </p>

                                <Link
                                    href="/aws"
                                    className="mt-4 rounded-lg bg-sky-600 px-4 py-2 text-xs font-bold text-white hover:bg-sky-700 transition"
                                >
                                    View AWS Module →
                                </Link>

                            </div>
                        )}

                    </div>

                    <div className="mt-3 flex flex-col gap-1 text-[11px] text-gray-500 dark:text-gray-400 sm:flex-row sm:items-center sm:justify-between">

                        <span>
                            Showing imported/raw weather observations only.
                        </span>

                        <Link
                            href="/aws"
                            className="font-bold text-sky-600 dark:text-sky-400 hover:underline"
                        >
                            Open AWS Monitoring →
                        </Link>

                    </div>

                </Card>

                {/* ========================================================
                    IMEA + FACILITIES
                ======================================================== */}

                <section className="grid gap-6 lg:grid-cols-2">

                    {/* IMEA & Infrastructure */}

                    <Card className="border border-gray-100 dark:border-gray-800 shadow-sm rounded-2xl p-6">

                        <div className="flex items-center justify-between mb-4">

                            <div>

                                <h2 className="font-extrabold text-gray-950 dark:text-white text-base">
                                    IMEA & Infrastructure Status
                                </h2>

                                <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    Ecotourism impact assessments & facilities inventory.
                                </p>

                            </div>

                            <Link
                                href="/imea"
                                className="text-xs font-bold text-emerald-700 hover:text-emerald-900 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/50 px-3 py-1.5 rounded-xl border border-emerald-200 dark:border-emerald-800 transition"
                            >
                                View IMEA →
                            </Link>

                        </div>

                        <div className="grid grid-cols-2 gap-4 mt-5">

                            <div className="bg-cyan-50/50 dark:bg-cyan-950/20 p-5 rounded-2xl border border-cyan-200/60 dark:border-cyan-800/50 text-center shadow-xs flex flex-col justify-center">

                                <span className="text-3xl font-black text-cyan-600 dark:text-cyan-400">
                                    {imeaAssessmentsCount}
                                </span>

                                <h4 className="text-[11px] font-extrabold text-gray-600 dark:text-gray-300 mt-1 uppercase tracking-wider">
                                    IMEA Assessments
                                </h4>

                            </div>

                            <div className="bg-blue-50/50 dark:bg-blue-950/20 p-5 rounded-2xl border border-blue-200/60 dark:border-blue-800/50 text-center shadow-xs flex flex-col justify-center">

                                <span className="text-3xl font-black text-blue-700 dark:text-blue-400">
                                    {totalFacilitiesCount}
                                </span>

                                <h4 className="text-[11px] font-extrabold text-gray-600 dark:text-gray-300 mt-1 uppercase tracking-wider">
                                    Total Facilities
                                </h4>

                                <p className="text-[11px] font-semibold text-gray-500 mt-0.5">
                                    {functionalFacilitiesCount} Functional Units
                                </p>

                            </div>

                        </div>

                    </Card>

                    {/* Quick Navigation */}

                    <Card className="border border-gray-100 dark:border-gray-800 shadow-sm rounded-2xl p-6">

                        <h2 className="font-extrabold text-gray-950 dark:text-white text-base">
                            Quick Module Navigation
                        </h2>

                        <p className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            Direct access to core database sections.
                        </p>

                        <div className="mt-4 grid gap-3 text-xs font-bold">

                            <Link
                                href="/protected-areas"
                                className="rounded-xl bg-emerald-50/70 dark:bg-emerald-950/30 text-emerald-800 dark:text-emerald-300 p-3.5 border border-emerald-200 dark:border-emerald-800/60 flex justify-between items-center transition hover:bg-emerald-100/80 shadow-xs"
                            >
                                <span className="flex items-center gap-2">
                                    🌲 Protected Areas Registry
                                </span>

                                <span className="bg-white dark:bg-emerald-900 w-6 h-6 rounded-full flex items-center justify-center text-emerald-700 dark:text-emerald-200 shadow-xs">
                                    →
                                </span>
                            </Link>

                            <Link
                                href="/management-plans"
                                className="rounded-xl bg-blue-50/70 dark:bg-blue-950/30 text-blue-800 dark:text-blue-300 p-3.5 border border-blue-200 dark:border-blue-800/60 flex justify-between items-center transition hover:bg-blue-100/80 shadow-xs"
                            >
                                <span className="flex items-center gap-2">
                                    📄 Management Plans
                                </span>

                                <span className="bg-white dark:bg-blue-900 w-6 h-6 rounded-full flex items-center justify-center text-blue-700 dark:text-blue-200 shadow-xs">
                                    →
                                </span>
                            </Link>

                            <Link
                                href="/bms"
                                className="rounded-xl bg-teal-50/70 dark:bg-teal-950/30 text-teal-800 dark:text-teal-300 p-3.5 border border-teal-200 dark:border-teal-800/60 flex justify-between items-center transition hover:bg-teal-100/80 shadow-xs"
                            >
                                <span className="flex items-center gap-2">
                                    🐾 Biodiversity Monitoring (BMS)
                                </span>

                                <span className="bg-white dark:bg-teal-900 w-6 h-6 rounded-full flex items-center justify-center text-teal-700 dark:text-teal-200 shadow-xs">
                                    →
                                </span>
                            </Link>

                            <Link
                                href="/aws"
                                className="rounded-xl bg-sky-50/70 dark:bg-sky-950/30 text-sky-800 dark:text-sky-300 p-3.5 border border-sky-200 dark:border-sky-800/60 flex justify-between items-center transition hover:bg-sky-100/80 shadow-xs"
                            >
                                <span className="flex items-center gap-2">
                                    🌤️ Automated Weather Stations
                                </span>

                                <span className="bg-white dark:bg-sky-900 w-6 h-6 rounded-full flex items-center justify-center text-sky-700 dark:text-sky-200 shadow-xs">
                                    →
                                </span>
                            </Link>

                        </div>

                    </Card>

                </section>

                {/* ========================================================
                    RECENT ACTIVITIES
                ======================================================== */}

                <section>

                    <Card
                        className="border border-gray-100 dark:border-gray-800 shadow-sm rounded-2xl"
                        padding="p-0"
                    >

                        <div className="flex items-center justify-between border-b border-gray-200 px-6 py-5 dark:border-gray-700">

                            <div>

                                <h2 className="font-extrabold text-gray-950 dark:text-white text-base">
                                    Recent System Activities
                                </h2>

                                <p className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    Latest actions across BMS, BAMS, IMEA, AWS, and Management Plans.
                                </p>

                            </div>

                        </div>

                        {dbActivities.length > 0 ? (
                            <DataTable
                                columns={columns}
                                rows={dbActivities}
                            />
                        ) : (

                            <div className="p-8 text-center">

                                <h3 className="text-sm font-semibold text-gray-900 dark:text-white">
                                    No recent activities recorded yet
                                </h3>

                                <p className="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-sm mx-auto">
                                    Quick access to core system modules:
                                </p>

                                <div className="mt-4 flex flex-wrap justify-center gap-2">

                                    <Link
                                        href="/protected-areas"
                                        className="rounded-lg bg-gray-100 dark:bg-gray-800 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-200 transition"
                                    >
                                        Protected Areas
                                    </Link>

                                    <Link
                                        href="/management-plans"
                                        className="rounded-lg bg-gray-100 dark:bg-gray-800 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-200 transition"
                                    >
                                        Management Plans
                                    </Link>

                                    <Link
                                        href="/bms"
                                        className="rounded-lg bg-gray-100 dark:bg-gray-800 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-200 transition"
                                    >
                                        BMS / BAMS
                                    </Link>

                                    <Link
                                        href="/imea"
                                        className="rounded-lg bg-gray-100 dark:bg-gray-800 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-200 transition"
                                    >
                                        IMEA
                                    </Link>

                                    <Link
                                        href="/aws"
                                        className="rounded-lg bg-gray-100 dark:bg-gray-800 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-200 transition"
                                    >
                                        AWS
                                    </Link>

                                </div>

                            </div>

                        )}

                    </Card>

                </section>

            </div>

        </AuthenticatedLayout>
    );
}
