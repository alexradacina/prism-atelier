<script setup>
import { computed } from "vue";
import { Head, Link, usePage } from "@inertiajs/vue3";
import Brand from "./Brand.vue";
import { money } from "../lib";
defineProps({ title: String, section: { type: String, default: "Workspace" } });
const page = usePage();
const user = computed(() => page.props.auth);
const active = (path) => page.url.split("?")[0] === path;
</script>
<template>
    <Head :title="title" />
    <div class="app-shell">
        <aside class="sidebar">
            <Link href="/studio" class="brand" aria-label="Prism Atelier studio"
                ><Brand
            /></Link>
            <Link href="/logout" method="post" as="button" class="mobile-logout"
                >Sign out ↪</Link
            >
            <div class="workspace-label">YOUR WORKSPACE</div>
            <nav aria-label="Main navigation">
                <Link
                    href="/studio"
                    :class="['nav-link', { active: active('/studio') }]"
                    ><span class="nav-icon">✧</span> Image studio
                    <span class="nav-arrow">↗</span></Link
                >
                <Link
                    href="/history"
                    :class="['nav-link', { active: active('/history') }]"
                    ><span class="nav-icon">▦</span> My creations</Link
                >
                <Link
                    href="/credits"
                    :class="['nav-link', { active: active('/credits') }]"
                    ><span class="nav-icon">◈</span> Credits & activity</Link
                >
                <template v-if="user.is_admin"
                    ><div class="workspace-label operator-label">
                        OPERATIONS
                    </div>
                    <Link
                        href="/admin/reports"
                        :class="[
                            'nav-link',
                            { active: active('/admin/reports') },
                        ]"
                        ><span class="nav-icon">▤</span> Spend report</Link
                    >
                    <a
                        href="/horizon"
                        class="nav-link"
                        target="_blank"
                        rel="noopener noreferrer"
                        ><span class="nav-icon">◷</span> Queue monitor
                        <span class="nav-arrow">↗</span></a
                    >
                </template>
            </nav>
            <div class="sidebar-bottom">
                <div class="atelier-note">
                    <span class="tiny-spark">✧</span>
                    <p>A space for your<br />next bright idea.</p>
                    <span class="eyebrow">WORDS INTO WORLDS</span>
                </div>
                <div class="profile">
                    <span class="avatar">{{
                        user.name.slice(0, 1).toUpperCase()
                    }}</span>
                    <div>
                        <strong>{{ user.name }}</strong><small
                            >Personal workspace</small
                        >
                    </div>
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        class="logout"
                        aria-label="Sign out"
                        title="Sign out"
                        >↪</Link
                    >
                </div>
            </div>
        </aside>
        <div class="workspace">
            <header class="topbar">
                <div class="breadcrumb">
                    {{ section }} <span>/</span> <strong>{{ title }}</strong>
                </div>
                <Link href="/credits" class="wallet-pill"
                    ><span class="wallet-dot"></span>{{ money(user.available) }}
                    <span>available</span
                    ><span class="wallet-plus">+</span></Link
                >
            </header>
            <main>
                <div
                    v-if="page.props.flash?.message"
                    class="flash"
                    role="status"
                >
                    {{ page.props.flash.message }}
                </div>
                <slot />
            </main>
            <footer class="workspace-footer">
                <span>PRISM ATELIER</span
                ><span
                    >Make room for imagination.
                    <span class="footer-spark">✧</span></span
                ><span>USD credits · Demo workspace</span>
            </footer>
        </div>
    </div>
</template>
