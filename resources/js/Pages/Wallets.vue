<script setup>
import { Link, usePoll } from "@inertiajs/vue3";
import AppLayout from "../Components/AppLayout.vue";
import Pagination from "../Components/Pagination.vue";
import { money } from "../lib";
defineProps({ users: Object });
usePoll(5000, { only: ["users", "auth"] });
</script>
<template>
    <AppLayout title="User balances" section="Operations">
        <div class="page-heading">
            <div>
                <span class="eyebrow">EVERY CREDIT, ACCOUNTED FOR</span>
                <h1>Balances, <em>in detail.</em></h1>
                <p>
                    Credit balances, pending holds and available funds for every
                    user.
                </p>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>User</th>
                        <th class="number">Credit balance</th>
                        <th class="number">Reserved</th>
                        <th class="number">Available</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in users.data" :key="user.id">
                        <td>
                            <strong>{{ user.name }}</strong><small
                                class="table-subtitle"
                                >{{ user.email }}</small
                            >
                        </td>
                        <td class="number">{{ money(user.balance, true) }}</td>
                        <td class="number">{{ money(user.reserved, true) }}</td>
                        <td class="number">
                            {{ money(user.available, true) }}
                        </td>
                        <td>
                            <Link
                                :href="'/admin/wallets/' + user.id"
                                class="subtle-link"
                                >View balance history ↗</Link
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :data="users" />
    </AppLayout>
</template>
