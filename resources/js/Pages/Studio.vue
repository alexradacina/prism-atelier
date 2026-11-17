<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from "vue";
import { Link, useForm, usePage, usePoll } from "@inertiajs/vue3";
import AppLayout from "../Components/AppLayout.vue";
import ArtStudy from "../Components/ArtStudy.vue";
import GenerationCard from "../Components/GenerationCard.vue";
import { money, toNanos } from "../lib";
defineProps({ generations: Array });
const page = usePage();
const form = useForm({ quote_id: "" });
const prompt = ref("");
const size = ref("1024x1024");
const quality = ref("medium");
const references = ref([]);
let nextReferenceId = 0;
const quote = ref(null);
const quoting = ref(false);
const quoteError = ref("");
const now = ref(Date.now());
const referenceImages = computed(() =>
    references.value.map((reference) => reference.url.trim()).filter(Boolean),
);
function addReference() {
    if (references.value.length >= 4) return;
    const reference = { id: nextReferenceId++, url: "" };
    references.value.push(reference);
    nextTick(() =>
        document.getElementById(`reference-${reference.id}`)?.focus(),
    );
}
const validQuote = computed(
    () => quote.value && Date.parse(quote.value.expires_at) > now.value,
);
const affordable = computed(
    () =>
        quote.value &&
        toNanos(page.props.auth.available) >= toNanos(quote.value.maximum_cost),
);
let timer,
    controller,
    requestNumber = 0;
const tick = setInterval(() => (now.value = Date.now()), 1000);
usePoll(5000, { only: ["generations", "auth"] });
async function estimate() {
    const current = ++requestNumber;
    controller?.abort();
    if (!prompt.value.trim()) {
        quote.value = null;
        quoting.value = false;
        return;
    }
    controller = new AbortController();
    quoting.value = true;
    quoteError.value = "";
    try {
        const response = await fetch("/quotes", {
            method: "POST",
            signal: controller.signal,
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": document.querySelector(
                    'meta[name="csrf-token"]',
                ).content,
            },
            body: JSON.stringify({
                prompt: prompt.value,
                size: size.value,
                quality: quality.value,
                reference_images: referenceImages.value,
            }),
        });
        const data = await response.json();
        if (current !== requestNumber) return;
        if (!response.ok)
            throw new Error(
                Object.values(data.errors || {}).flat()[0] ||
                    data.message ||
                    "Could not get an estimate.",
            );
        quote.value = data;
    } catch (error) {
        if (error.name !== "AbortError" && current === requestNumber)
            quoteError.value = error.message;
    } finally {
        if (current === requestNumber) quoting.value = false;
    }
}
watch([prompt, size, quality, referenceImages], () => {
    quote.value = null;
    quoteError.value = "";
    clearTimeout(timer);
    controller?.abort();
    requestNumber++;
    quoting.value = !!prompt.value.trim();
    timer = setTimeout(estimate, 450);
});
onBeforeUnmount(() => {
    clearTimeout(timer);
    clearInterval(tick);
    controller?.abort();
});
function submit() {
    if (!validQuote.value) {
        estimate();
        return;
    }
    form.quote_id = quote.value.id;
    form.post("/generations");
}
const ideas = [
    {
        title: "Editorial still life",
        prompt: "A sculptural ceramic vase beside a ripe orange on a cream linen tablecloth, soft afternoon sunlight, editorial still life photography [succeed]",
    },
    {
        title: "A place to escape",
        prompt: "A quiet glass cabin tucked into a misty pine forest beside a mountain lake, cinematic light, serene atmosphere [succeed]",
    },
    {
        title: "Something surreal",
        prompt: "A floating spiral staircase made of pale pink stone above a calm blue sea, surreal architectural photography [succeed]",
    },
    {
        title: "A cosy corner",
        prompt: "A quiet reading corner with a moss-green armchair, warm lamplight and rain on the window, inviting interior photography [succeed]",
    },
    {
        title: "A vintage escape",
        prompt: "A vintage travel poster of a sunlit Mediterranean seaside town, terracotta rooftops, deep blue water and a warm limited colour palette [succeed]",
    },
    {
        title: "Up close with nature",
        prompt: "An intimate macro photograph of a fern unfurling, tiny dew drops and soft morning light, rich natural greens [succeed]",
    },
    {
        title: "Made of paper",
        prompt: "A layered paper-cut landscape of rolling hills and a rising sun, handmade textures, warm ochre and muted blue tones [succeed]",
    },
    {
        title: "A tiny world",
        prompt: "A miniature bookshop inside a walnut shell, tiny glowing windows and shelves of books, charming handcrafted diorama photography [succeed]",
    },
    {
        title: "After the rain",
        prompt: "A quiet city street after the rain, glowing shop windows reflected in the pavement, cinematic night photography with soft amber and teal light [succeed]",
    },
];
const shuffledIdeas = [...ideas];
for (let index = shuffledIdeas.length - 1; index > 0; index--) {
    const randomIndex = Math.floor(Math.random() * (index + 1));
    [shuffledIdeas[index], shuffledIdeas[randomIndex]] = [
        shuffledIdeas[randomIndex],
        shuffledIdeas[index],
    ];
}
const visibleIdeas = shuffledIdeas.slice(0, 3);
</script>
<template>
    <AppLayout title="Image studio">
        <div class="page-heading">
            <div>
                <span class="eyebrow">THE POSSIBILITIES ARE OPEN</span>
                <h1>What are you <em>imagining?</em></h1>
                <p>Start with a few words. See where they take you.</p>
            </div>
            <span class="heading-index">01 / CREATE</span>
        </div>
        <div class="studio-grid">
            <form class="create-panel" @submit.prevent="submit">
                <div class="panel-heading">
                    <span class="step-number">01</span>
                    <h2>Describe your idea</h2>
                    <span class="optional-tag">THE STARTING POINT</span>
                </div>
                <div class="prompt-wrap">
                    <label for="prompt" class="sr-only">Image prompt</label
                    ><textarea
                        id="prompt"
                        v-model="prompt"
                        placeholder="A sun-drenched room, a chair that’s almost a sculpture, the softest afternoon light…"
                        maxlength="4000"
                        required
                    ></textarea>
                    <div class="prompt-footer">
                        <span>Be as specific or as unexpected as you like.</span
                        ><span>{{ prompt.length }} / 4,000</span>
                    </div>
                </div>
                <div class="idea-row">
                    <span>A little inspiration</span
                    ><button
                        v-for="idea in visibleIdeas"
                        :key="idea.title"
                        type="button"
                        @click="prompt = idea.prompt"
                    >
                        {{ idea.title }} <span>↗</span>
                    </button>
                </div>
                <div class="form-divider"></div>
                <div class="panel-heading">
                    <span class="step-number">02</span>
                    <h2>Make it yours</h2>
                </div>
                <fieldset>
                    <legend>Canvas size</legend>
                    <div class="size-options">
                        <label
                            v-for="option in [
                                {
                                    value: '1024x1024',
                                    label: 'Square',
                                    ratio: '1:1',
                                },
                                {
                                    value: '1536x1024',
                                    label: 'Landscape',
                                    ratio: '3:2',
                                },
                                {
                                    value: '1024x1536',
                                    label: 'Portrait',
                                    ratio: '2:3',
                                },
                            ]"
                            :key="option.value"
                            :class="[
                                'size-option',
                                { selected: size === option.value },
                            ]"
                            ><input
                                v-model="size"
                                type="radio"
                                :value="option.value"
                                name="size"
                            /><span
                                :class="[
                                    'ratio-shape',
                                    'ratio-' + option.label.toLowerCase(),
                                ]"
                            ></span
                            ><strong>{{ option.label }}</strong
                            ><small
                                >{{ option.ratio }} <span>·</span>
                                {{ option.value }}</small
                            ><span class="choice-check">{{
                                size === option.value ? "✓" : ""
                            }}</span></label
                        >
                    </div>
                </fieldset>
                <fieldset>
                    <legend>
                        Quality
                        <span class="label-note"
                            >More detail, a little more time.</span
                        >
                    </legend>
                    <div class="quality-options">
                        <label
                            v-for="option in [
                                {
                                    value: 'low',
                                    label: 'Draft',
                                    note: 'Explore an idea',
                                },
                                {
                                    value: 'medium',
                                    label: 'Standard',
                                    note: 'A balanced finish',
                                },
                                {
                                    value: 'high',
                                    label: 'High detail',
                                    note: 'Every little detail',
                                },
                            ]"
                            :key="option.value"
                            :class="{ selected: quality === option.value }"
                            ><input
                                v-model="quality"
                                type="radio"
                                :value="option.value"
                                name="quality"
                            /><strong
                                >{{ option.label }}
                                <span
                                    v-if="option.value === 'medium'"
                                    class="recommended-dot"
                                ></span></strong
                            ><small>{{ option.note }}</small></label
                        >
                    </div>
                </fieldset>
                <div class="reference-section">
                    <div class="reference-heading">
                        <h3>Reference images <span>Optional</span></h3>
                        <span>{{ references.length }} / 4</span>
                    </div>
                    <p id="reference-help" class="reference-help">
                        Add a public image URL to guide your creation. Your
                        estimate updates automatically.
                    </p>
                    <div
                        v-for="(reference, index) in references"
                        :key="reference.id"
                        class="reference-field"
                    >
                        <label :for="`reference-${reference.id}`"
                            >Reference image {{ index + 1 }} URL</label
                        >
                        <div class="reference-field-controls">
                            <input
                                :id="`reference-${reference.id}`"
                                v-model="reference.url"
                                type="url"
                                inputmode="url"
                                maxlength="2048"
                                placeholder="https://example.com/reference.jpg"
                                aria-describedby="reference-help"
                                @keydown.enter.prevent
                            />
                            <button
                                type="button"
                                class="remove-reference"
                                :aria-label="`Remove reference image ${index + 1}`"
                                @click="references.splice(index, 1)"
                            >
                                <span aria-hidden="true">×</span>
                            </button>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="add-reference"
                        :disabled="references.length >= 4"
                        @click="addReference"
                    >
                        <span aria-hidden="true">+</span> Add reference image
                    </button>
                </div>
                <p v-if="form.errors.quote_id" class="field-error" role="alert">
                    {{ form.errors.quote_id }}
                </p>
                <div class="mobile-generate">
                    <button
                        class="button primary"
                        :disabled="
                            quoting ||
                            !validQuote ||
                            !affordable ||
                            form.processing
                        "
                    >
                        Generate image <span>↗</span>
                    </button>
                </div>
            </form>
            <aside class="studio-aside">
                <div class="quote-panel">
                    <div class="quote-heading">
                        <span class="eyebrow">A LITTLE TRANSPARENCY</span
                        ><span>◈</span>
                    </div>
                    <h2>Your creation, <em>estimated.</em></h2>
                    <div class="quote-amount" aria-live="polite">
                        {{
                            quoting
                                ? "…"
                                : quote
                                  ? money(quote.estimated_cost)
                                  : "—"
                        }}
                        <span v-if="quote && !quoting">USD</span>
                    </div>
                    <p class="quote-description">
                        Your estimated total, with everything included. You’ll
                        only be charged the final cost when your image is ready.
                    </p>
                    <div v-if="quote" class="quote-breakdown">
                        <div>
                            <span>Amount reserved</span
                            ><strong :title="money(quote.maximum_cost, true)">{{
                                money(quote.maximum_cost)
                            }}</strong>
                        </div>
                        <div>
                            <span>Your available credits</span
                            ><strong>{{
                                money(page.props.auth.available)
                            }}</strong>
                        </div>
                    </div>
                    <p v-else class="quote-hint">
                        Your estimate will appear as you write.
                    </p>
                    <p v-if="quoteError" class="field-error" role="alert">
                        {{ quoteError }}
                        <button
                            type="button"
                            class="text-button"
                            @click="estimate"
                        >
                            Try again
                        </button>
                    </p>
                    <p v-if="quote && !validQuote" class="field-error">
                        Your estimate expired.
                        <button
                            class="text-button"
                            type="button"
                            @click="estimate"
                        >
                            Refresh estimate
                        </button>
                    </p>
                    <p v-if="quote && !affordable" class="field-error">
                        You need more credits to cover this request.
                        <Link href="/credits">Add credits →</Link>
                    </p>
                    <button
                        class="button primary generate-button"
                        :disabled="
                            quoting ||
                            !validQuote ||
                            !affordable ||
                            form.processing
                        "
                        @click="submit"
                    >
                        {{
                            form.processing
                                ? "Reserving your canvas…"
                                : "Generate image"
                        }}<span>✧</span>
                    </button>
                    <p class="reserve-note">
                        <span>⌁</span> Unused reserved credits return
                        automatically.<br />Failed generations always cost $0.
                    </p>
                </div>
                <div class="possibility-card">
                    <ArtStudy />
                    <div>
                        <span class="eyebrow">FROM A WORD TO A WORLD</span>
                        <p>Your next favourite image<br />starts right here.</p>
                    </div>
                </div>
            </aside>
        </div>
        <section class="recent-section">
            <div class="section-heading">
                <h2>
                    Fresh from your studio
                    <span v-if="generations.length">{{
                        generations.length.toString().padStart(2, "0")
                    }}</span>
                </h2>
                <Link href="/history" class="subtle-link"
                    >All creations <span>↗</span></Link
                >
            </div>
            <div v-if="generations.length" class="generation-grid">
                <GenerationCard
                    v-for="generation in generations"
                    :key="generation.id"
                    :generation="generation"
                />
            </div>
            <div v-else class="empty-strip">
                <span>✧</span>
                <div>
                    <strong>A collection waiting to happen.</strong>
                    <p>
                        Your creations will find a home here. Let’s make the
                        first one.
                    </p>
                </div>
                <span class="empty-arrow">↖</span>
            </div>
        </section>
    </AppLayout>
</template>
