<?php

declare(strict_types=1);

namespace Iter\Tryon\Api;

/**
 * The layer between the provador and whoever generates the image (TRYON-PLAN §5.1). Iter_Tryon only knows
 * this contract; the provider's JSON, model ids and error codes stay in its module (Iter_Runware). A module
 * registers its provider in etc/tryon_provider.json ({"class": "..."}).
 */
interface TryonProviderInterface
{
    /** stable id kept with each request ("runware"): a request is read back from the provider that started it */
    public function code(): string;

    /** key saved and model chosen; without it the provador is off */
    public function isConfigured(): bool;

    /** the configured model, as the provider names it; part of the "same request" key */
    public function model(): string;

    /** the configured model's price in US$, for the monthly ceiling before the real cost arrives */
    public function estimatedCost(int $garments): float;

    /**
     * Starts a generation. An asynchronous provider returns at once and is asked later with fetch(); a synchronous
     * one waits for the image and gives it in 'result' (then fetch() is never called for that task).
     *
     * @param array{person: string, garments: string[], category?: string, resultSeconds: int} $job JPEG bytes; the garment's part
     *        of the body (tops, bottoms, one-pieces: "Tipo de peça no provador"); how long the image stays online
     * @return array{task: string, request: array, result?: array} the provider's task id, what was sent (without the
     *         images) and, from a synchronous provider, the result as fetch() gives it
     * @throws TryonProviderException
     */
    public function submit(array $job): array;

    /**
     * @return array{status: 'processing'|'success'|'error', progress?: ?int, imageUrl?: string, image?: string, cost?: ?float,
     *               error?: string, providerCode?: ?string, response: array}
     *         imageUrl: the image online at the provider, or image: its JPEG bytes, which the store keeps until it expires;
     *         error: one of TryonProviderException's codes; response: the provider's answer as it came, without images
     * @throws TryonProviderException when the provider couldn't be asked (the request stays processing)
     */
    public function fetch(string $task): array;
}
