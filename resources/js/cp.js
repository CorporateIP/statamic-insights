import Dashboard from './pages/Dashboard.vue';
import Widget from './components/Widget.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('insights::Dashboard', Dashboard);
    Statamic.$components.register('insights-widget', Widget);
});
