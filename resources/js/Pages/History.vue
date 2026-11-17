<script setup>
import { Link, usePoll } from "@inertiajs/vue3";
import AppLayout from "../Components/AppLayout.vue";
import GenerationCard from "../Components/GenerationCard.vue";
import Pagination from "../Components/Pagination.vue";
defineProps({ generations: Object, filters: Object });
usePoll(4000, { only: ["generations", "auth"] });
</script>
<template>
    <AppLayout title="My creations">
        <div class="page-heading">
            <div>
                <span class="eyebrow">YOUR IDEAS, TAKING SHAPE</span>
                <h1>A collection of <em>possibilities.</em></h1>
                <p>Every prompt, every little experiment. All in one place.</p>
            </div>
            <Link href="/studio" class="button primary compact"
                >New creation <span>+</span></Link
            >
        </div>
        <div class="collection-toolbar">
            <nav class="filter-tabs" aria-label="Filter creations">
                <Link
                    v-for="filter in [
                        { label: 'All creations', value: '' },
                        { label: 'Queued', value: 'queued' },
                        { label: 'Processing', value: 'processing' },
                        { label: 'Succeeded', value: 'succeeded' },
                        { label: 'Failed', value: 'failed' },
                    ]"
                    :key="filter.value"
                    :href="
                        filter.value
                            ? '/history?status=' + filter.value
                            : '/history'
                    "
                    :class="{
                        active: (filters?.status || '') === filter.value,
                    }"
                    >{{ filter.label }}</Link
                >
            </nav>
            <span class="live-indicator"><i></i>Live updates</span>
        </div>
        <div v-if="generations.data.length" class="generation-grid">
            <GenerationCard
                v-for="generation in generations.data"
                :key="generation.id"
                :generation="generation"
            />
        </div>
        <div v-else class="empty-state">
            <span class="empty-star">✧</span>
            <h2>
                {{
                    filters?.status
                        ? "Nothing here just yet."
                        : "Every collection starts with an idea."
                }}
            </h2>
            <p>
                {{
                    filters?.status
                        ? "Try another filter, or create something new."
                        : "Describe what you have in mind. We’ll give it a canvas."
                }}
            </p>
            <Link href="/studio" class="button primary compact"
                >Create an image <span>↗</span></Link
            >
        </div>
        <Pagination :data="generations" />
        <p class="page-footnote">
            All prices include everything. Your final cost is charged when the
            image is ready. Image links expire after 24 hours.
        </p>
    </AppLayout>
</template>
