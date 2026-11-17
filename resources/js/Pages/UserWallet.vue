<script setup>
import { Link, usePoll } from "@inertiajs/vue3";
import AppLayout from "../Components/AppLayout.vue";
import WalletSummary from "../Components/WalletSummary.vue";
import WalletActivity from "../Components/WalletActivity.vue";
defineProps({ wallet: Object });
usePoll(4000, { only: ["wallet", "auth"] });
</script>
<template>
    <AppLayout title="User balance" section="Operations">
        <div class="page-heading">
            <div>
                <span class="eyebrow">USER BALANCE & HISTORY</span>
                <h1>{{ wallet.owner.name }}’s <em>credits.</em></h1>
                <p>{{ wallet.owner.email }}</p>
            </div>
            <Link href="/admin/wallets" class="subtle-link"
                >← All user balances</Link
            >
        </div>
        <WalletSummary :summary="wallet.summary" /><WalletActivity
            :wallet="wallet"
            :show-history="false"
        />
    </AppLayout>
</template>
