<script setup>
import { Link } from "@inertiajs/vue3";
import Pagination from "./Pagination.vue";
import { date, money } from "../lib";
defineProps({ wallet: Object, showHistory: { type: Boolean, default: true } });
</script>
<template>
    <section class="activity-section wallet-section">
        <div class="section-heading">
            <h2>
                Pending reservations
                <span>{{ wallet.reservations.total }}</span>
            </h2>
            <Link v-if="showHistory" href="/history" class="subtle-link"
                >Image history ↗</Link
            >
        </div>
        <p class="wallet-section-note">
            These amounts are held, not spent. Success replaces the hold with
            one final charge; failure releases it in full.
        </p>
        <div v-if="wallet.reservations.data.length" class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Status</th>
                        <th>Requested</th>
                        <th class="number">Estimate</th>
                        <th class="number">Reserved</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="reservation in wallet.reservations.data"
                        :key="reservation.id"
                    >
                        <td>
                            <strong
                                class="wallet-description"
                                :title="reservation.prompt"
                                >{{ reservation.prompt }}</strong><small
                                class="table-subtitle"
                                >{{ reservation.id }}</small
                            >
                        </td>
                        <td>{{ reservation.status }}</td>
                        <td>{{ date(reservation.created_at) }}</td>
                        <td class="number">
                            {{ money(reservation.estimated, true) }}
                        </td>
                        <td class="number">
                            {{ money(reservation.reserved, true) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-else class="empty-strip">
            <span>◈</span>
            <div>
                <strong>No credits are currently reserved.</strong>
                <p>Your available balance is ready for your next creation.</p>
            </div>
        </div>
        <Pagination :data="wallet.reservations" />
    </section>
    <section class="activity-section wallet-section">
        <div class="section-heading">
            <h2>Balance history</h2>
            <span class="muted">Exact amounts in USD · newest first</span>
        </div>
        <p class="wallet-section-note">
            Every credit addition and final generation charge, with the credit
            balance immediately after that entry. Reservations appear separately
            above.
        </p>
        <div v-if="wallet.entries.data.length" class="table-wrap">
            <table class="wallet-ledger">
                <thead>
                    <tr>
                        <th>Activity</th>
                        <th>Date</th>
                        <th class="number">Added</th>
                        <th class="number">Spent</th>
                        <th class="number">Balance after</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in wallet.entries.data" :key="entry.id">
                        <td>
                            <strong>{{
                                entry.kind === "purchase"
                                    ? "Demo credit purchase"
                                    : entry.generation_status === "failed"
                                      ? "Failed image · no charge"
                                      : "Image generation"
                            }}</strong><span
                                class="wallet-description table-subtitle"
                                :title="entry.description"
                                >{{ entry.description }}</span
                            ><small
                                class="table-subtitle"
                                :title="entry.generation_id || ''"
                                >{{
                                    entry.generation_id
                                        ? "Image " +
                                          entry.generation_id.slice(0, 8)
                                        : "Top-up"
                                }}
                                · Entry #{{ entry.id }}</small
                            >
                        </td>
                        <td>{{ date(entry.created_at) }}</td>
                        <td class="number positive">
                            {{ money(entry.added, true) }}
                        </td>
                        <td class="number">{{ money(entry.spent, true) }}</td>
                        <td class="number">
                            <strong>{{
                                money(entry.balance_after, true)
                            }}</strong>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-else class="empty-strip">
            <span>◈</span>
            <div>
                <strong>A fresh balance history.</strong>
                <p>Credit additions and final charges will appear here.</p>
            </div>
        </div>
        <Pagination :data="wallet.entries" />
    </section>
</template>
