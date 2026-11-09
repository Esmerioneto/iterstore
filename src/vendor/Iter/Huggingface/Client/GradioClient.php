<?php

declare(strict_types=1);

namespace Iter\Huggingface\Client;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Calls a Gradio app on a Hugging Face Space (upload the files, join the queue, read the queue's event stream until
 * it ends, download the result); nothing about the provador here. Tests replace it with a fake.
 * The queue and not POST /call/{endpoint}: on Gradio 6 /call answers every error as "data: null", hiding the
 * reason (the free GPU's quota used up, for one); the queue's process_completed brings the message.
 */
class GradioClient
{
    public function __construct(private readonly ?ClientInterface $http = null)
    {
    }

    /** the Space's own host: fashn-ai/fashn-vton-1.5 => https://fashn-ai-fashn-vton-1-5.hf.space */
    public static function host(string $space): string
    {
        return 'https://' . strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', trim($space, " /"))) . '.hf.space';
    }

    /**
     * @param array<string, string> $files name => bytes, sent first in the endpoint's inputs, in this order
     * @param array $args the endpoint's other inputs, after the files
     * @return array{0: string, 1: mixed} ['complete', the outputs] | ['error', the app's message or null] |
     *         ['http', [status, body]] when the Space didn't take the call (0 = no answer or the stream timed out)
     */
    public function predict(string $space, string $token, string $endpoint, array $files, array $args, int $timeout = 110): array
    {
        $base = self::host($space) . '/gradio_api';
        $headers = $token !== '' ? ['Authorization' => 'Bearer ' . $token] : [];
        $multipart = [];
        foreach ($files as $name => $bytes) {
            $multipart[] = ['name' => 'files', 'contents' => $bytes, 'filename' => $name . '.jpg', 'headers' => ['Content-Type' => 'image/jpeg']];
        }
        [$status, $body] = $this->request('POST', $base . '/upload', $headers, ['multipart' => $multipart], 30);
        $paths = json_decode($body, true);
        if ($status !== 200 || !is_array($paths) || count($paths) !== count($files)) {
            return ['http', [$status, $body]];
        }
        $inputs = array_merge(array_map(static fn (string $path) => ['path' => $path, 'meta' => ['_type' => 'gradio.FileData']], $paths), $args);
        // the queue takes the endpoint by its index in the app's config
        [$status, $body] = $this->request('GET', self::host($space) . '/config', $headers, [], 30);
        $dependencies = json_decode($body, true)['dependencies'] ?? [];
        $index = array_column(is_array($dependencies) ? $dependencies : [], 'id', 'api_name')[$endpoint] ?? null;
        if ($status !== 200 || !is_int($index)) {
            return ['http', [$status === 200 ? 404 : $status, $body]];
        }
        $session = bin2hex(random_bytes(8));
        [$status, $body] = $this->request('POST', $base . '/queue/join', $headers, ['json' => ['data' => $inputs, 'fn_index' => $index, 'session_hash' => $session]], 30);
        $event = json_decode($body, true)['event_id'] ?? null;
        if ($status !== 200 || !is_string($event)) {
            return ['http', [$status, $body]];
        }
        [$status, $body] = $this->request('GET', $base . '/queue/data?session_hash=' . $session, $headers, [], $timeout);
        // one "data: {json}" per message; the call ends with process_completed (or unexpected_error)
        foreach (preg_match_all('/^data: *(\{.*\})\s*$/m', $body, $m) ? $m[1] : [] as $line) {
            $msg = json_decode($line, true);
            if (($msg['msg'] ?? null) === 'process_completed') {
                return !empty($msg['success']) ? ['complete', $msg['output']['data'] ?? null] : ['error', $msg['output']['error'] ?? null];
            }
            if (($msg['msg'] ?? null) === 'unexpected_error') {
                return ['error', $msg['message'] ?? null];
            }
        }

        return ['http', [$status === 200 ? 0 : $status, $body]];
    }

    /** @return string|null the file's bytes */
    public function download(string $url, string $token): ?string
    {
        [$status, $body] = $this->request('GET', $url, $token !== '' ? ['Authorization' => 'Bearer ' . $token] : [], [], 30);

        return $status === 200 && $body !== '' ? $body : null;
    }

    /**
     * @param array $options Guzzle's body option: ['multipart' => parts] or ['json' => data]
     * @return array{0: int, 1: string} HTTP status (0 = no answer or timed out) and the body
     */
    protected function request(string $method, string $url, array $headers, array $options, int $timeout): array
    {
        try {
            $response = ($this->http ?? new Client())->request($method, $url, $options + [
                'headers' => $headers,
                'connect_timeout' => 10,
                'timeout' => $timeout,
                'http_errors' => false,
            ]);
        } catch (GuzzleException) {
            return [0, ''];
        }

        return [$response->getStatusCode(), (string) $response->getBody()];
    }
}
