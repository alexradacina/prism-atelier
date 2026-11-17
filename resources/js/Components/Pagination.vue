<script setup>
import { Link } from "@inertiajs/vue3";
defineProps({ data: Object });
</script>
<template>
    <nav v-if="data.last_page > 1" class="pagination" aria-label="Pagination">
        <Link
            v-if="data.prev_page_url"
            :href="data.prev_page_url"
            preserve-scroll
            >← Previous</Link
        ><span>Page {{ data.current_page }} of {{ data.last_page }}</span
        ><Link
            v-if="data.next_page_url"
            :href="data.next_page_url"
            preserve-scroll
            >Next →</Link
        >
    </nav>
</template>
