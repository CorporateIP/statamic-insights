<script setup>
import { ref } from 'vue';
import { Head } from '@statamic/cms/inertia';
import { Button, Card, Header, Heading, Select, Subheading } from '@statamic/cms/ui';

const props = defineProps({
    title: { type: String, required: true },
    saveUrl: { type: String, required: true },
    dashboardUrl: { type: String, required: true },
    goals: { type: Array, required: true },
    forms: { type: Array, required: true },
    email: { type: Object, required: true },
});

const goals = ref(props.goals.map((goal) => ({ ...goal })));
const recipients = ref(props.email.recipients.join('\n'));
const weekly = ref(props.email.weekly);
const monthly = ref(props.email.monthly);
const saving = ref(false);

const typeOptions = [
    { value: 'path', label: __('Page visit') },
    { value: 'event', label: __('Custom event') },
    { value: 'form', label: __('Form submission') },
];

const formOptions = props.forms.map((form) => ({ value: form.handle, label: form.title }));

const valuePlaceholder = (type) => (type === 'path' ? '/bedankt or /docs/*' : type === 'event' ? 'newsletter-signup' : '');

function addGoal() {
    goals.value.push({ handle: '', name: '', type: 'path', value: '' });
}

function removeGoal(index) {
    goals.value.splice(index, 1);
}

async function save() {
    saving.value = true;

    try {
        const response = await fetch(props.saveUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
            },
            body: JSON.stringify({
                goals: goals.value.filter((goal) => goal.name && goal.value),
                email: {
                    recipients: recipients.value
                        .split('\n')
                        .map((line) => line.trim())
                        .filter(Boolean),
                    weekly: weekly.value,
                    monthly: monthly.value,
                },
            }),
        });

        if (response.ok) {
            const { goals: saved } = await response.json();
            goals.value = saved.map((goal) => ({ ...goal }));
            Statamic.$toast.success(__('Saved'));
        } else {
            const body = await response.json().catch(() => ({}));
            Statamic.$toast.error(body.message || __('Something went wrong'));
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Head :title="[__(title)]" />

    <div>
        <Header :title="__(title)" icon="chart-monitoring-indicator">
            <div class="flex items-center gap-2">
                <Button size="sm" :text="__('Back to dashboard')" :href="dashboardUrl" />
                <Button size="sm" variant="primary" :text="__('Save')" :disabled="saving" @click="save" />
            </div>
        </Header>

        <Card class="insights-rise">
            <Heading :text="__('Goals')" />
            <p class="mt-1 text-sm text-gray-500">
                {{ __('Conversions to count: a page being visited, a custom event, or a Statamic form being submitted.') }}
            </p>

            <div v-if="goals.length" class="mt-4 space-y-2">
                <div v-for="(goal, index) in goals" :key="index" class="insights-goal-row">
                    <input v-model="goal.name" type="text" class="insights-input" :placeholder="__('Name')" />
                    <Select
                        :model-value="goal.type"
                        :options="typeOptions"
                        option-label="label"
                        option-value="value"
                        size="sm"
                        @update:model-value="(value) => (goal.type = value)"
                    />
                    <Select
                        v-if="goal.type === 'form'"
                        :model-value="goal.value"
                        :options="formOptions"
                        option-label="label"
                        option-value="value"
                        size="sm"
                        @update:model-value="(value) => (goal.value = value)"
                    />
                    <input v-else v-model="goal.value" type="text" class="insights-input" :placeholder="valuePlaceholder(goal.type)" />
                    <button type="button" class="insights-remove" :title="__('Remove')" @click="removeGoal(index)">×</button>
                </div>
            </div>
            <p v-else class="mt-4 text-sm text-gray-500" v-text="__('No goals defined yet.')" />

            <div class="mt-3">
                <Button size="sm" :text="__('Add goal')" @click="addGoal" />
            </div>
        </Card>

        <Card class="insights-rise mt-4" style="animation-delay: 60ms">
            <Heading :text="__('Email reports')" />
            <p class="mt-1 text-sm text-gray-500">
                {{ __('A stats digest sent to the addresses below - recipients do not need a CP account.') }}
            </p>

            <div class="mt-4">
                <Subheading :text="__('Recipients')" />
                <textarea
                    v-model="recipients"
                    rows="4"
                    class="insights-input mt-1 w-full"
                    :placeholder="__('One email address per line')"
                ></textarea>
            </div>

            <div class="mt-3 flex items-center gap-6 text-sm">
                <label class="flex items-center gap-2">
                    <input v-model="weekly" type="checkbox" />
                    {{ __('Weekly (Monday)') }}
                </label>
                <label class="flex items-center gap-2">
                    <input v-model="monthly" type="checkbox" />
                    {{ __('Monthly (1st)') }}
                </label>
            </div>
        </Card>
    </div>
</template>

<style>
.insights-goal-row {
    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(0, 1.2fr) minmax(0, 2fr) auto;
    gap: 0.5rem;
    align-items: center;
}

.insights-input {
    border: 1px solid color-mix(in srgb, currentColor 20%, transparent);
    border-radius: 0.375rem;
    background: transparent;
    color: inherit;
    font-size: 0.8125rem;
    padding: 0.375rem 0.5rem;
}

.insights-remove {
    opacity: 0.4;
    font-size: 1rem;
    padding: 0 0.375rem;
}

.insights-remove:hover {
    opacity: 1;
}
</style>
