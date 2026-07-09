import Dashboard from './pages/Dashboard.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('insights::Dashboard', Dashboard);
});
