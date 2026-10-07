import { useForm } from '@inertiajs/react';
import SettingsShell from '@/Components/Admin/SettingsShell';
import RoutingPositionControlsPanel from '@/Components/Admin/RoutingPositionControlsPanel';

export default function RoutingWorkflow({ settings, canUpdate }) {
    const form = useForm({
        expected_version: settings.version,
        office_penro_enabled: settings.office_penro_enabled,
        penro_tsd_chief_enabled: settings.penro_tsd_chief_enabled,
        reason: '',
    });

    return <SettingsShell active="Report Routing">
        <RoutingPositionControlsPanel
            settings={settings}
            canUpdate={canUpdate}
            data={form.data}
            errors={form.errors}
            processing={form.processing}
            onToggle={(key, value) => form.setData(key, value)}
            onReason={value => form.setData('reason', value)}
            onSave={() => form.put('/settings/routing-workflow', {
                preserveScroll: true,
                onSuccess: page => {
                    const saved = page.props.settings;
                    if (!saved?.available) return;

                    const acknowledged = {
                        expected_version: saved.version,
                        office_penro_enabled: saved.office_penro_enabled,
                        penro_tsd_chief_enabled: saved.penro_tsd_chief_enabled,
                        reason: '',
                    };
                    form.setDefaults(acknowledged);
                    form.setData(acknowledged);
                },
            })}
        />
    </SettingsShell>;
}
