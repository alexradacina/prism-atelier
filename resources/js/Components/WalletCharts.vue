<script setup>
import { computed } from "vue";
import MoneyChart from "./MoneyChart.vue";
import { money } from "../lib";
const props = defineProps({ wallet: Object });
const parts = computed(() => [
    { label: "Spent", value: props.wallet.summary.spent, color: "#963f32" },
    {
        label: "Reserved",
        value: props.wallet.summary.reserved,
        color: "#b78c4a",
    },
    {
        label: "Available",
        value: props.wallet.summary.available,
        color: "#778267",
    },
]);
const positiveTotal = computed(() =>
    parts.value.reduce((sum, part) => sum + Math.max(0, Number(part.value)), 0),
);
</script>
<template>
    <div class="finance-charts">
        <MoneyChart
            title="Balance over time"
            subtitle="Last 30 ledger entries · credit balance after each entry · UTC"
            :rows="wallet.trend || []"
            :series="[
                { key: 'balance', label: 'Credit balance', color: '#778267' },
            ]"
        />
        <section class="money-chart credit-distribution">
            <div class="chart-heading">
                <h2>Where your credits stand</h2>
                <p>Reservations are held, never counted as spending.</p>
            </div>
            <div
                v-if="positiveTotal > 0"
                class="allocation-bar"
                aria-hidden="true"
            >
                <span
                    v-for="part in parts"
                    :key="part.label"
                    :style="{
                        width:
                            (Math.max(0, Number(part.value)) / positiveTotal) *
                                100 +
                            '%',
                        background: part.color,
                    }"
                ></span>
            </div>
            <p v-else class="chart-empty">Add credits to get started.</p>
            <div
                class="allocation-detail"
                v-for="part in parts"
                :key="part.label"
            >
                <span
                    ><i :style="{ background: part.color }"></i
                    >{{ part.label }}</span
                ><strong>{{ money(part.value, true) }}</strong>
            </div>
            <p v-if="Number(wallet.summary.available) < 0" class="field-error">
                Available credit is negative. Add credits before making another
                request.
            </p>
        </section>
    </div>
</template>
