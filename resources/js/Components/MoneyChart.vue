<script setup>
import { computed } from "vue";
import { money } from "../lib";
const props = defineProps({
    title: String,
    subtitle: String,
    rows: Array,
    series: Array,
    bars: Boolean,
});
const width = 760,
    height = 230,
    left = 96,
    right = 40,
    top = 22,
    bottom = 38;
const bounds = computed(() => {
    const values = props.rows.flatMap((row) =>
        props.series.map((s) => Number(row[s.key] || 0)),
    );
    const low = Math.min(0, ...values),
        high = Math.max(0, ...values);
    return { low, high: high === low ? low + 1 : high };
});
const x = (index) =>
    left +
    ((index + 0.5) * (width - left - right)) / Math.max(1, props.rows.length);
const y = (value) =>
    top +
    ((bounds.value.high - Number(value || 0)) /
        (bounds.value.high - bounds.value.low)) *
        (height - top - bottom);
const points = (series) =>
    props.rows
        .map((row, index) => `${x(index)},${y(row[series.key])}`)
        .join(" ");
const ticks = computed(() =>
    [
        ...new Set([
            0,
            Math.floor((props.rows.length - 1) / 2),
            props.rows.length - 1,
        ]),
    ].filter((i) => i >= 0),
);
const axisMoney = (value) =>
    new Intl.NumberFormat("en-US", {
        style: "currency",
        currency: "USD",
        maximumSignificantDigits: 3,
    }).format(value);
</script>
<template>
    <section class="money-chart">
        <div class="chart-heading">
            <h2>{{ title }}</h2>
            <p>{{ subtitle }}</p>
        </div>
        <div class="chart-legend">
            <span v-for="s in series" :key="s.key"
                ><i :style="{ background: s.color }"></i>{{ s.label }}</span
            >
        </div>
        <svg
            v-if="rows.length"
            :viewBox="`0 0 ${width} ${height}`"
            role="img"
            :aria-label="title"
        >
            <title>
                {{ title }}. Hover or focus a point for the exact amount. Exact
                figures are also listed in the tables.
            </title>
            <g v-for="fraction in [0, 0.5, 1]" :key="fraction">
                <line
                    :x1="left"
                    :x2="width - right"
                    :y1="top + fraction * (height - top - bottom)"
                    :y2="top + fraction * (height - top - bottom)"
                    stroke="#e5e2d7"
                    stroke-dasharray="3 5"
                />
                <text
                    :x="left - 10"
                    :y="top + fraction * (height - top - bottom) + 4"
                    text-anchor="end"
                >
                    {{
                        axisMoney(
                            bounds.high - fraction * (bounds.high - bounds.low),
                        )
                    }}
                </text>
            </g>
            <line
                :x1="left"
                :x2="width - right"
                :y1="y(0)"
                :y2="y(0)"
                stroke="#c9cbbb"
            />
            <g v-for="(s, si) in series" :key="s.key">
                <polyline
                    v-if="!bars"
                    :points="points(s)"
                    fill="none"
                    :stroke="s.color"
                    stroke-width="2.5"
                    stroke-linejoin="round"
                />
                <template v-for="(row, index) in rows" :key="index">
                    <rect
                        v-if="bars"
                        :x="
                            x(index) +
                            (si - series.length / 2) *
                                Math.min(
                                    18,
                                    560 /
                                        Math.max(1, rows.length) /
                                        series.length,
                                )
                        "
                        :y="Math.min(y(row[s.key]), y(0))"
                        :width="
                            Math.min(
                                16,
                                520 / Math.max(1, rows.length) / series.length,
                            )
                        "
                        :height="Math.abs(y(row[s.key]) - y(0))"
                        :fill="s.color"
                        rx="2"
                        tabindex="0"
                    >
                        <title>
                            {{ row.label }} · {{ s.label }}:
                            {{ money(row[s.key], true) }}
                        </title>
                    </rect>
                    <circle
                        v-else
                        :cx="x(index)"
                        :cy="y(row[s.key])"
                        r="3.5"
                        :fill="s.color"
                        stroke="white"
                        tabindex="0"
                    >
                        <title>
                            {{ row.label }} · {{ s.label }}:
                            {{ money(row[s.key], true) }}
                        </title>
                    </circle>
                </template>
            </g>
            <text
                v-for="index in ticks"
                :key="index"
                :x="x(index)"
                :y="height - 12"
                text-anchor="middle"
            >
                {{
                    rows[index].label.length > 22
                        ? rows[index].label.slice(0, 20) + "…"
                        : rows[index].label
                }}
            </text>
        </svg>
        <p v-else class="chart-empty">
            Activity will appear here when there is data to show.
        </p>
    </section>
</template>
