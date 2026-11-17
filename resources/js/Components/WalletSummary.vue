<script setup>
import { money } from "../lib";
defineProps({ summary: Object });
</script>
<template>
    <div class="wallet-summary">
        <div class="wallet-stat">
            <span class="eyebrow">CREDIT BALANCE</span
            ><strong>{{ money(summary.balance, true) }}</strong>
            <p>
                Credits added minus completed charges. Pending images do not
                reduce this balance.
            </p>
        </div>
        <div class="wallet-stat wallet-reserved">
            <span class="eyebrow">RESERVED · NOT CHARGED</span
            ><strong>{{ money(summary.reserved, true) }}</strong>
            <p>
                Held for pending images. These credits cannot be used for
                another generation.
            </p>
        </div>
        <div class="wallet-stat wallet-available">
            <span class="eyebrow">AVAILABLE TO SPEND</span
            ><strong>{{ money(summary.available, true) }}</strong>
            <p>
                Your credit balance minus reservations. New requests must fit
                within this amount.
            </p>
        </div>
    </div>
    <div class="wallet-lifetime">
        <span
            >Total added <strong>{{ money(summary.added, true) }}</strong></span
        ><span
            >Total spent <strong>{{ money(summary.spent, true) }}</strong></span
        ><span :class="summary.reconciled ? 'positive' : 'negative'">{{
            summary.reconciled
                ? "✓ History and reservations reconciled"
                : "Balance needs reconciliation"
        }}</span>
    </div>
    <div v-if="!summary.reconciled" class="flash error">
        Balance difference: {{ money(summary.balance_difference, true) }}.
        Reservation difference:
        {{ money(summary.reservation_difference, true) }}.
    </div>
</template>
