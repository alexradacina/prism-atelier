<script setup>
import { ref } from "vue";
import { money, date } from "../lib";
defineProps({ generation: Object });
const unavailable = ref(false);
</script>
<template>
    <article class="generation-card">
        <div class="generation-image" :class="'image-' + generation.status">
            <img
                v-if="generation.image_url && !unavailable"
                :src="generation.image_url"
                :alt="generation.prompt"
                loading="lazy"
                referrerpolicy="no-referrer"
                @error="unavailable = true"
            />
            <div v-else class="generation-placeholder">
                <span
                    :class="[
                        'placeholder-symbol',
                        {
                            rotating: ['queued', 'processing'].includes(
                                generation.status,
                            ),
                        },
                    ]"
                    >{{ generation.status === "failed" ? "↻" : "✧" }}</span
                >
                <strong>{{
                    unavailable
                        ? "Image link expired"
                        : {
                              queued: "An idea in waiting",
                              processing: "Taking shape…",
                              failed: "This one didn’t develop",
                              succeeded: "Your image is ready",
                          }[generation.status]
                }}</strong>
                <span>{{
                    unavailable
                        ? "Image links last 24 hours."
                        : generation.status === "failed"
                          ? "No credits were charged."
                          : generation.status === "succeeded"
                            ? "Image unavailable"
                            : "We’ll update this automatically."
                }}</span>
            </div>
            <span class="status-badge" :class="'status-' + generation.status"
                ><i></i>{{ generation.status }}</span
            >
            <a
                v-if="generation.image_url && !unavailable"
                :href="generation.image_url"
                target="_blank"
                rel="noopener noreferrer"
                class="image-open"
                aria-label="Open generated image"
                >↗</a
            >
        </div>
        <div class="generation-details">
            <p class="generation-prompt" :title="generation.prompt">
                {{ generation.prompt }}
            </p>
            <div class="generation-meta">
                <span>{{ generation.size }} · {{ generation.quality }}</span
                ><time>{{ date(generation.created_at) }}</time>
            </div>
            <p v-if="generation.error" class="generation-error">
                {{ generation.error }}
            </p>
            <p v-else-if="generation.notice" class="generation-notice" role="status">
                {{ generation.notice }}
            </p>
            <div class="generation-costs">
                <div>
                    <span>Estimated</span
                    ><strong :title="money(generation.estimated_cost, true)">{{
                        money(generation.estimated_cost)
                    }}</strong>
                </div>
                <div>
                    <span>Actual cost</span
                    ><strong :title="money(generation.actual_cost, true)">{{
                        money(generation.actual_cost)
                    }}</strong>
                </div>
            </div>
        </div>
    </article>
</template>
