import Dashboard from './pages/Dashboard.vue';
import Settings from './pages/Settings.vue';
import Widget from './components/Widget.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('insights::Dashboard', Dashboard);
    Statamic.$inertia.register('insights::Settings', Settings);
    Statamic.$components.register('insights-widget', Widget);
});
