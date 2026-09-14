import StatusBadge from '@/Components/StatusBadge';

const variantFor = (remark) => {
    const value = String(remark || 'Weather Condition Unavailable');

    if (value === 'Normal Weather Conditions') return 'active';
    if (value === 'Moderate Rain Observed') return 'info';
    if (value.includes('Heavy Rainfall') || value.includes('Strong Wind') || value.includes('High Temperature')) return 'pending';
    if (value === 'Cool Conditions') return 'inactive';
    if (value === 'No Data') return 'inactive';

    return 'pending';
};

export default function AwsWeatherRemarkBadge({ remark }) {
    const value = String(remark || 'Weather Condition Unavailable');

    return <StatusBadge variant={variantFor(value)}>
        <span className="max-w-full whitespace-normal text-left leading-tight">{value}</span>
    </StatusBadge>;
}
