import Secretary from './pages/Secretary.vue';
import SecretaryPanel from './components/SecretaryPanel.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('statamic-secretary::Secretary', Secretary);
    Statamic.$components.register('statamic-secretary-panel', SecretaryPanel);

    if (Statamic.$permissions.has('use secretary')) {
        Statamic.$components.append('statamic-secretary-panel', {
            props: {
                endpoint: cp_url('secretary/panel/data'),
            },
        });
    }
});
