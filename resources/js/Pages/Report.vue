<script setup>
import { computed, reactive } from "vue";
import { router } from "@inertiajs/vue3";
import MoneyChart from "../Components/MoneyChart.vue";
import AppLayout from "../Components/AppLayout.vue";
import { money } from "../lib";
const props = defineProps({
    report: Object,
    report_error: String,
    filters: Object,
});
const chartDays = computed(() =>
    [...(props.report?.daily || [])]
        .sort((a, b) => a.date.localeCompare(b.date))
        .map((day) => ({ ...day, label: day.date.slice(5) })),
);
const chartUsers = computed(() =>
    [...(props.report?.users || [])]
        .sort((a, b) => Number(b.charged) - Number(a.charged))
        .slice(0, 8)
        .map((user) => ({ ...user, label: user.name })),
);
const spendSeries = [
    { key: "charged", label: "User spend", color: "#963f32" },
    { key: "provider", label: "Provider cost", color: "#b78c4a" },
    { key: "profit", label: "Profit", color: "#778267" },
];
const dates = reactive({ ...props.filters });
function update() {
    router.get("/admin/reports", dates, { preserveState: true });
}
</script>
<template>
    <AppLayout title="Spend report" section="Operations">
        <div class="page-heading">
            <div>
                <span class="eyebrow">THE BUSINESS OF CREATING</span>
                <h1>Every dollar, <em>accounted for.</em></h1>
                <p>
                    Charges, provider spend and profit. Grouped by completion
                    date in UTC.
                </p>
            </div>
        </div>
        <form class="report-filter" @submit.prevent="update">
            <label
                >From<input
                    type="date"
                    v-model="dates.start_date"
                    required /></label
            ><span>→</span
            ><label
                >To<input
                    type="date"
                    v-model="dates.end_date"
                    required /></label
            ><button class="button secondary compact">Update report ↗</button
            ><span class="muted">Up to 92 days</span>
        </form>
        <p v-for="error in $page.props.errors" :key="error" class="field-error">
            {{ error }}
        </p>
        <div v-if="report_error" class="flash error">{{ report_error }}</div>
        <template v-if="report"
            ><div class="report-stats">
                <div>
                    <span>USER SPEND</span
                    ><strong :title="money(report.total.charged, true)">{{
                        money(report.total.charged)
                    }}</strong
                    ><small>Actual credits charged</small>
                </div>
                <div>
                    <span>PROVIDER COST</span
                    ><strong :title="money(report.total.provider, true)">{{
                        money(report.total.provider)
                    }}</strong
                    ><small>From Pixelforge /v1/usage</small>
                </div>
                <div>
                    <span>PROFIT</span
                    ><strong :title="money(report.total.profit, true)">{{
                        money(report.total.profit)
                    }}</strong
                    ><small>User spend less provider cost</small>
                </div>
            </div>
            <div
                :class="[
                    'reconciliation-note',
                    { discrepancy: Number(report.total.difference) !== 0 },
                ]"
            >
                <span>{{
                    Number(report.total.difference) === 0 ? "✓" : "!"
                }}</span>
                <div>
                    <strong>{{
                        Number(report.total.difference) === 0
                            ? "Provider costs reconciled."
                            : "A reconciliation difference needs attention."
                    }}</strong>
                    <p>
                        Pixelforge less local provider cost:
                        {{ money(report.total.difference, true) }}. Provider
                        totals include every generation made with this API key.
                        Report refreshes within 30 seconds.
                    </p>
                </div>
            </div>
            <div class="finance-charts">
                <MoneyChart
                    title="Daily spend & profit"
                    subtitle="Selected date range · UTC completion days · USD"
                    :rows="chartDays"
                    :series="spendSeries"
                /><MoneyChart
                    title="Spend by user"
                    subtitle="Top 8 users by spend in this period · USD"
                    :rows="chartUsers"
                    :series="spendSeries"
                    bars
                />
            </div>
            <section class="report-section">
                <div class="section-heading">
                    <h2>By day <span>UTC</span></h2>
                    <span class="muted"
                        >Provider costs match the usage API</span
                    >
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Completion date</th>
                                <th class="number">User spend</th>
                                <th class="number">Provider cost</th>
                                <th class="number">Profit</th>
                                <th class="number">Difference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="day in report.daily" :key="day.date">
                                <td>{{ day.date }}</td>
                                <td class="number">
                                    {{ money(day.charged, true) }}
                                </td>
                                <td class="number">
                                    {{ money(day.provider, true) }}
                                </td>
                                <td class="number">
                                    {{ money(day.profit, true) }}
                                </td>
                                <td
                                    :class="[
                                        'number',
                                        {
                                            negative:
                                                Number(day.difference) !== 0,
                                        },
                                    ]"
                                >
                                    {{ money(day.difference, true) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <section class="report-section">
                <div class="section-heading">
                    <h2>By user</h2>
                    <span class="muted">Attributed to local generations</span>
                </div>
                <div v-if="report.users.length" class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>User</th>
                                <th class="number">Images</th>
                                <th class="number">User spend</th>
                                <th class="number">Provider cost</th>
                                <th class="number">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template
                                v-for="user in report.users"
                                :key="user.email"
                                ><tr>
                                    <td>
                                        <strong>{{ user.name }}</strong><small
                                            class="table-subtitle"
                                            >{{ user.email }}</small
                                        >
                                    </td>
                                    <td class="number">
                                        {{ user.generations }}
                                    </td>
                                    <td class="number">
                                        {{ money(user.charged, true) }}
                                    </td>
                                    <td class="number">
                                        {{ money(user.provider, true) }}
                                    </td>
                                    <td class="number">
                                        {{ money(user.profit, true) }}
                                    </td>
                                </tr>
                                <tr
                                    v-for="day in user.days"
                                    :key="day.date"
                                    class="user-day"
                                >
                                    <td>{{ day.date }} · UTC</td>
                                    <td class="number">
                                        {{ day.generations }}
                                    </td>
                                    <td class="number">
                                        {{ money(day.charged, true) }}
                                    </td>
                                    <td class="number">
                                        {{ money(day.provider, true) }}
                                    </td>
                                    <td class="number">
                                        {{ money(day.profit, true) }}
                                    </td>
                                </tr></template
                            >
                        </tbody>
                    </table>
                </div>
                <div v-else class="empty-strip">
                    <span>▤</span>
                    <div>
                        <strong
                            >No completed generations in this period.</strong
                        >
                        <p>Try a different date range.</p>
                    </div>
                </div>
            </section></template
        >
    </AppLayout>
</template>
