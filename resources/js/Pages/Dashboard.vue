<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import type { PageProps } from '@/types';

type GenerationStatus = 'queued' | 'processing' | 'succeeded' | 'failed';

interface GenerationHistoryItem {
    id: number;
    status: GenerationStatus;
    prompt: string;
    size: string;
    quality: string;
    image_url: string | null;
    image_url_expires_at: string | null;
    error: { message?: string; code?: string } | null;
    created_at: string;
    completed_at: string | null;
}

const props = defineProps<{
    generations: GenerationHistoryItem[];
}>();

const page = usePage<
    PageProps<{
        flash?: {
            status?: string;
        };
    }>
>();

const generationHistory = ref([...props.generations]);
const isRefreshing = ref(false);
const refreshFailed = ref(false);
let refreshTimer: number | undefined;
let refreshInProgress = false;

const form = useForm({
    idempotency_key: createIdempotencyKey(),
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
});

const queuedNotice = computed(
    () => page.props.flash?.status === 'generation_queued',
);

watch(
    () => props.generations,
    (generations) => {
        generationHistory.value = [...generations];
    },
);

function submitGeneration(): void {
    form.post(route('generations.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('prompt');
            form.idempotency_key = createIdempotencyKey();
        },
    });
}

function createIdempotencyKey(): string {
    const browserCrypto = globalThis.crypto;

    if (typeof browserCrypto?.randomUUID === 'function') {
        return browserCrypto.randomUUID();
    }

    if (typeof browserCrypto?.getRandomValues === 'function') {
        const randomBytes = browserCrypto.getRandomValues(new Uint8Array(16));
        const key = Array.from(randomBytes, (byte) =>
            byte.toString(16).padStart(2, '0'),
        ).join('');

        return `submission-${key}`;
    }

    return `submission-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function isActive(generation: GenerationHistoryItem): boolean {
    return generation.status === 'queued' || generation.status === 'processing';
}

async function refreshGeneration(
    generation: GenerationHistoryItem,
): Promise<GenerationHistoryItem | null> {
    try {
        const response = await fetch(
            route('generations.refresh', generation.id),
            {
                headers: {
                    Accept: 'application/json',
                },
            },
        );

        if (!response.ok) {
            return null;
        }

        const result = (await response.json()) as {
            generation: GenerationHistoryItem;
        };

        return result.generation;
    } catch {
        return null;
    }
}

async function refreshActiveGenerations(): Promise<void> {
    if (refreshInProgress) {
        return;
    }

    const activeGenerations = generationHistory.value.filter(isActive);

    if (activeGenerations.length === 0) {
        return;
    }

    refreshInProgress = true;
    isRefreshing.value = true;

    const results = await Promise.all(
        activeGenerations.map((generation) => refreshGeneration(generation)),
    );

    let hasRefreshFailure = false;

    results.forEach((generation) => {
        if (generation === null) {
            hasRefreshFailure = true;
            return;
        }

        const index = generationHistory.value.findIndex(
            (historyItem) => historyItem.id === generation.id,
        );

        if (index !== -1) {
            generationHistory.value[index] = generation;
        }
    });

    refreshFailed.value = hasRefreshFailure;
    isRefreshing.value = false;
    refreshInProgress = false;
}

function statusLabel(status: GenerationStatus): string {
    return status.charAt(0).toUpperCase() + status.slice(1);
}

function statusClasses(status: GenerationStatus): string {
    const classes: Record<GenerationStatus, string> = {
        queued:
            'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
        processing:
            'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
        succeeded:
            'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
        failed: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
    };

    return classes[status];
}

function formatDate(date: string): string {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(date));
}

onMounted(() => {
    void refreshActiveGenerations();
    refreshTimer = window.setInterval(() => {
        void refreshActiveGenerations();
    }, 5000);
});

onUnmounted(() => {
    if (refreshTimer !== undefined) {
        window.clearInterval(refreshTimer);
    }
});
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2
                class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200"
            >
                Image generation
            </h2>
        </template>

        <div class="py-8 sm:py-12">
            <div class="mx-auto grid max-w-7xl gap-8 px-4 sm:px-6 lg:px-8">
                <section
                    class="rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800 sm:p-8"
                >
                    <div class="grid gap-2">
                        <h3
                            class="text-lg font-semibold text-gray-900 dark:text-gray-100"
                        >
                            Create an image
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            Describe the image you want to generate.
                        </p>
                    </div>

                    <div
                        v-if="queuedNotice"
                        role="status"
                        class="mt-6 rounded-md bg-emerald-50 p-4 text-sm text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200"
                    >
                        Your generation has been queued. Its progress will
                        appear in your history below.
                    </div>

                    <form class="mt-6 grid gap-6" @submit.prevent="submitGeneration">
                        <div class="grid gap-2">
                            <label
                                for="prompt"
                                class="text-sm font-medium text-gray-700 dark:text-gray-300"
                            >
                                Prompt
                            </label>
                            <textarea
                                id="prompt"
                                v-model="form.prompt"
                                rows="4"
                                maxlength="4000"
                                required
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
                                placeholder="A lighthouse on a rocky coast at sunrise, cinematic lighting..."
                            />
                            <p
                                v-if="form.errors.prompt"
                                class="text-sm text-red-600 dark:text-red-400"
                            >
                                {{ form.errors.prompt }}
                            </p>
                        </div>

                        <div class="grid gap-6 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <label
                                    for="size"
                                    class="text-sm font-medium text-gray-700 dark:text-gray-300"
                                >
                                    Image size
                                </label>
                                <select
                                    id="size"
                                    v-model="form.size"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
                                >
                                    <option value="1024x1024">Square · 1024 × 1024</option>
                                    <option value="1536x1024">Landscape · 1536 × 1024</option>
                                    <option value="1024x1536">Portrait · 1024 × 1536</option>
                                </select>
                                <p
                                    v-if="form.errors.size"
                                    class="text-sm text-red-600 dark:text-red-400"
                                >
                                    {{ form.errors.size }}
                                </p>
                            </div>

                            <div class="grid gap-2">
                                <label
                                    for="quality"
                                    class="text-sm font-medium text-gray-700 dark:text-gray-300"
                                >
                                    Quality
                                </label>
                                <select
                                    id="quality"
                                    v-model="form.quality"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
                                >
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                </select>
                                <p
                                    v-if="form.errors.quality"
                                    class="text-sm text-red-600 dark:text-red-400"
                                >
                                    {{ form.errors.quality }}
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-4">
                            <button
                                type="submit"
                                :disabled="form.processing || !form.prompt.trim()"
                                class="inline-flex items-center rounded-md bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:focus:ring-offset-gray-800"
                            >
                                {{
                                    form.processing
                                        ? 'Submitting...'
                                        : 'Generate image'
                                }}
                            </button>
                            <span
                                v-if="form.recentlySuccessful"
                                class="text-sm text-emerald-700 dark:text-emerald-300"
                            >
                                Generation accepted.
                            </span>
                        </div>
                    </form>
                </section>

                <section class="grid gap-5">
                    <div
                        class="flex flex-wrap items-end justify-between gap-3"
                    >
                        <div class="grid gap-1">
                            <h3
                                class="text-lg font-semibold text-gray-900 dark:text-gray-100"
                            >
                                Generation history
                            </h3>
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                Your recent images and generation progress.
                            </p>
                        </div>
                        <p
                            v-if="isRefreshing"
                            class="text-sm text-gray-500 dark:text-gray-400"
                            aria-live="polite"
                        >
                            Checking active generations…
                        </p>
                    </div>

                    <p
                        v-if="refreshFailed"
                        role="status"
                        class="rounded-md bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200"
                    >
                        Some generation statuses could not be refreshed. We’ll
                        try again shortly.
                    </p>

                    <div
                        v-if="generationHistory.length === 0"
                        class="rounded-lg border border-dashed border-gray-300 bg-white px-6 py-12 text-center dark:border-gray-700 dark:bg-gray-800"
                    >
                        <h4
                            class="font-medium text-gray-900 dark:text-gray-100"
                        >
                            No generations yet
                        </h4>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                            Your generated images will show up here.
                        </p>
                    </div>

                    <article
                        v-for="generation in generationHistory"
                        :key="generation.id"
                        class="grid gap-5 rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800 sm:grid-cols-[minmax(0,1fr)_16rem] sm:p-6"
                    >
                        <div class="grid content-start gap-4">
                            <div
                                class="flex flex-wrap items-center justify-between gap-3"
                            >
                                <span
                                    class="inline-flex rounded-full px-3 py-1 text-xs font-semibold"
                                    :class="statusClasses(generation.status)"
                                >
                                    {{ statusLabel(generation.status) }}
                                </span>
                                <time
                                    :datetime="generation.created_at"
                                    class="text-xs text-gray-500 dark:text-gray-400"
                                >
                                    {{ formatDate(generation.created_at) }}
                                </time>
                            </div>

                            <p
                                class="whitespace-pre-wrap break-words text-sm leading-6 text-gray-900 dark:text-gray-100"
                            >
                                {{ generation.prompt }}
                            </p>

                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ generation.size }} · {{ generation.quality }}
                            </p>

                            <div
                                v-if="generation.status === 'queued' || generation.status === 'processing'"
                                class="flex items-center gap-3 rounded-md bg-gray-50 p-3 text-sm text-gray-600 dark:bg-gray-900/60 dark:text-gray-300"
                            >
                                <span
                                    class="h-2.5 w-2.5 animate-pulse rounded-full bg-indigo-500"
                                    aria-hidden="true"
                                />
                                {{
                                    generation.status === 'queued'
                                        ? 'Waiting to start…'
                                        : 'Your image is being created…'
                                }}
                            </div>

                            <p
                                v-else-if="generation.status === 'failed'"
                                class="rounded-md bg-red-50 p-3 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200"
                            >
                                {{
                                    generation.error?.message ??
                                    'This generation could not be completed.'
                                }}
                            </p>

                            <p
                                v-if="generation.image_url_expires_at"
                                class="text-xs text-gray-500 dark:text-gray-400"
                            >
                                Image link expires
                                {{ formatDate(generation.image_url_expires_at) }}
                            </p>
                        </div>

                        <a
                            v-if="generation.status === 'succeeded' && generation.image_url"
                            :href="generation.image_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="block overflow-hidden rounded-md bg-gray-100 dark:bg-gray-900"
                        >
                            <img
                                :src="generation.image_url"
                                :alt="generation.prompt"
                                class="h-56 w-full object-cover sm:h-full"
                                loading="lazy"
                            />
                        </a>
                    </article>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
