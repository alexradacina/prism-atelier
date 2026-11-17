<script setup>
import { useForm, usePage, usePoll } from "@inertiajs/vue3";
import AppLayout from "../Components/AppLayout.vue";
import WalletCharts from "../Components/WalletCharts.vue";
import WalletSummary from "../Components/WalletSummary.vue";
import WalletActivity from "../Components/WalletActivity.vue";
import { money } from "../lib";
const props = defineProps({ purchase_id: String, wallet: Object });
const page = usePage();
usePoll(4000, { only: ["wallet", "auth"] });
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
        <WalletSummary :summary="wallet.summary" />
        <WalletCharts :wallet="wallet" />
        <div class="credit-grid">
            <div class="wallet-explainer">
                <span class="eyebrow">A HOLD IS NOT A CHARGE</span>
                <h2>Your credits stay yours<br />until the image succeeds.</h2>
                <p>
                    We reserve enough for the maximum estimated cost before
                    accepting a request. Available credits exclude that hold, so
                    the same money cannot fund another image.
                </p>
                <p>
                    Success creates one final charge and releases the hold.
                    Failure releases it in full and costs nothing.
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
        <WalletActivity :wallet="wallet" />
    </AppLayout>
</template>
