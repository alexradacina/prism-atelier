<script setup>
import { useForm, usePage } from "@inertiajs/vue3";
import AppLayout from "../Components/AppLayout.vue";
import Pagination from "../Components/Pagination.vue";
import { money, date } from "../lib";
const props = defineProps({ purchase_id: String, entries: Object });
const page = usePage();
const form = useForm({ amount: "10", purchase_id: props.purchase_id });
function purchase() {
    form.post("/credits", {
        preserveScroll: true,
        onSuccess: () => (form.purchase_id = page.props.purchase_id),
    });
}
</script>
<template>
    <AppLayout title="Credits & activity">
        <div class="page-heading">
            <div>
                <span class="eyebrow">FUEL FOR YOUR IMAGINATION</span>
                <h1>A little credit. <em>A lot of possibility.</em></h1>
                <p>
                    Simple US dollar credits. You only pay for images that
                    succeed.
                </p>
            </div>
        </div>
        <div class="credit-grid">
            <div class="balance-card">
                <span class="eyebrow">AVAILABLE TO CREATE</span>
                <div class="balance-amount">
                    {{ money(page.props.auth.available) }}<span>USD</span>
                </div>
                <div class="balance-detail">
                    <span
                        >Total balance<strong
                            :title="money(page.props.auth.balance, true)"
                            >{{ money(page.props.auth.balance) }}</strong
                        ></span
                    ><span
                        >Reserved for active requests<strong
                            :title="money(page.props.auth.reserved, true)"
                            >{{ money(page.props.auth.reserved) }}</strong
                        ></span
                    >
                </div>
                <p>
                    We reserve the maximum estimated cost while an image
                    develops. Any unused amount comes back when it’s done.
                </p>
            </div>
            <form class="purchase-card" @submit.prevent="purchase">
                <span class="eyebrow">KEEP THE IDEAS COMING</span>
                <h2>Top up your canvas.</h2>
                <p class="muted">Choose an amount to add to your workspace.</p>
                <fieldset class="purchase-options">
                    <legend class="sr-only">Credit amount</legend>
                    <label
                        v-for="amount in ['5', '10', '25', '50']"
                        :key="amount"
                        :class="{ selected: form.amount === amount }"
                        ><input
                            v-model="form.amount"
                            type="radio"
                            name="amount"
                            :value="amount"
                        /><strong>${{ amount }}</strong></label
                    >
                </fieldset>
                <p
                    v-for="error in form.errors"
                    :key="error"
                    class="field-error"
                >
                    {{ error }}
                </p>
                <button class="button primary" :disabled="form.processing">
                    {{
                        form.processing
                            ? "Adding credits…"
                            : "Buy " + money(form.amount) + " in credits"
                    }}<span>↗</span>
                </button>
                <p class="demo-disclosure">
                    DEMO PURCHASE · No real payment. No card required.
                </p>
            </form>
        </div>
        <section class="activity-section">
            <div class="section-heading">
                <h2>Credit activity</h2>
                <span class="muted">Exact amounts in USD</span>
            </div>
            <div v-if="entries.data.length" class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Activity</th>
                            <th>Date</th>
                            <th class="number">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="entry in entries.data" :key="entry.id">
                            <td>
                                <span :class="['activity-icon', entry.kind]">{{
                                    entry.kind === "purchase" ? "+" : "✧"
                                }}</span>{{
                                    entry.kind === "purchase"
                                        ? "Demo credit purchase"
                                        : "Image generation"
                                }}
                            </td>
                            <td class="muted">{{ date(entry.created_at) }}</td>
                            <td
                                :class="[
                                    'number',
                                    { positive: entry.kind === 'purchase' },
                                ]"
                            >
                                {{ entry.kind === "purchase" ? "+" : ""
                                }}{{ money(entry.amount, true) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="empty-strip">
                <span>◈</span>
                <div>
                    <strong>A fresh balance sheet.</strong>
                    <p>Add some demo credits to start creating.</p>
                </div>
            </div>
            <Pagination :data="entries" />
        </section>
    </AppLayout>
</template>
