<script setup>
import { computed } from "vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import Brand from "../Components/Brand.vue";
import ArtStudy from "../Components/ArtStudy.vue";
const props = defineProps({ mode: String });
const registering = computed(() => props.mode === "register");
const form = useForm({
    name: "",
    email: "",
    password: "",
    password_confirmation: "",
    remember: false,
});
function submit() {
    form.post(registering.value ? "/register" : "/login", {
        onFinish: () => form.reset("password", "password_confirmation"),
    });
}
</script>
<template>
    <Head :title="registering ? 'Create your account' : 'Welcome back'" />
    <div class="auth-shell">
        <div class="auth-story">
            <Link href="/" class="brand"><Brand /></Link>
            <div class="auth-story-copy">
                <span class="eyebrow">AN ATELIER FOR YOUR IMAGINATION</span>
                <h1>It starts with<br />a little <em>what if.</em></h1>
                <p>
                    Turn the picture in your head into something you can see.
                    Your words. A little possibility.
                </p>
            </div>
            <ArtStudy />
            <div class="auth-story-bottom">
                <span>WORDS INTO WORLDS</span><span>EST. 2026</span>
            </div>
        </div>
        <div class="auth-panel">
            <div class="auth-form-wrap">
                <span class="eyebrow">YOUR CREATIVE SPACE</span>
                <h2>
                    {{
                        registering ? "Make yourself at home." : "Welcome back."
                    }}
                </h2>
                <p class="muted">
                    {{
                        registering
                            ? "Create an account and bring your first idea to life."
                            : "A fresh canvas is waiting for you."
                    }}
                </p>
                <form @submit.prevent="submit" class="auth-form">
                    <label v-if="registering"
                        >Your name<input
                            v-model="form.name"
                            required
                            maxlength="100"
                            autocomplete="name"
                            placeholder="Alex Morgan"
                        /><span class="field-error">{{
                            form.errors.name
                        }}</span></label
                    >
                    <label
                        >Email address<input
                            v-model="form.email"
                            type="email"
                            required
                            autocomplete="email"
                            placeholder="you@example.com"
                        /><span class="field-error">{{
                            form.errors.email
                        }}</span></label
                    >
                    <label
                        >Password<input
                            v-model="form.password"
                            type="password"
                            required
                            :minlength="registering ? 12 : undefined"
                            :autocomplete="
                                registering
                                    ? 'new-password'
                                    : 'current-password'
                            "
                            :placeholder="
                                registering
                                    ? 'At least 12 characters'
                                    : 'Your password'
                            "
                        /><span class="field-error">{{
                            form.errors.password
                        }}</span></label
                    >
                    <label v-if="registering"
                        >Confirm password<input
                            v-model="form.password_confirmation"
                            type="password"
                            required
                            autocomplete="new-password"
                            placeholder="One more time"
                        /><span class="field-error">{{
                            form.errors.password_confirmation
                        }}</span></label
                    >
                    <label v-if="!registering" class="checkbox-label"
                        ><input type="checkbox" v-model="form.remember" />Keep
                        me signed in</label
                    >
                    <button class="button primary" :disabled="form.processing">
                        {{
                            form.processing
                                ? "One moment…"
                                : registering
                                  ? "Create your account"
                                  : "Step into the studio"
                        }}
                        <span>↗</span>
                    </button>
                </form>
                <p class="auth-switch">
                    {{
                        registering
                            ? "Already have an account?"
                            : "New to the atelier?"
                    }}
                    <Link :href="registering ? '/login' : '/register'">{{
                        registering ? "Sign in" : "Create an account"
                    }}</Link>
                </p>
                <div class="demo-note">
                    <span>◈</span>
                    <p>
                        This is a demo workspace. Credits are in US dollars,
                        purchases are simulated, and the studio produces
                        placeholder images.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
