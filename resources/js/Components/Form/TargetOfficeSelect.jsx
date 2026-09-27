import ScopedOptionSelect from '@/Components/Form/ScopedOptionSelect';

export default function TargetOfficeSelect(props) {
    const readOnly = props.readOnly ?? props.options?.length === 1;
    return <ScopedOptionSelect {...props} readOnly={readOnly} label={props.label || 'Target Office'} placeholder="Select a Target Office" />;
}
