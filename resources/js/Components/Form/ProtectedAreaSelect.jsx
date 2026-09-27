import ScopedOptionSelect from '@/Components/Form/ScopedOptionSelect';

export default function ProtectedAreaSelect({ value = '', onChange, targetOfficeId, protectedAreasByOffice = {}, ...props }) {
    const areas = protectedAreasByOffice[String(targetOfficeId)] || [];
    return <ScopedOptionSelect {...props} label={props.label || 'Protected Area'} value={value} onChange={onChange} options={areas.map(area => ({ ...area, label: area.name }))} placeholder="Select a Protected Area" />;
}
