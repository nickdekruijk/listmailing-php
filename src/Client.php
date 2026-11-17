<?php

namespace NickDeKruijk\ListMailing;

use Composer\InstalledVersions;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use NickDeKruijk\ListMailing\Exceptions\ConnectionFailed;
use NickDeKruijk\ListMailing\Exceptions\DailyLimitReached;
use NickDeKruijk\ListMailing\Exceptions\HttpsRequired;
use NickDeKruijk\ListMailing\Exceptions\ListMailingException;
use NickDeKruijk\ListMailing\Exceptions\ListNotAllowed;
use NickDeKruijk\ListMailing\Exceptions\NotFound;
use NickDeKruijk\ListMailing\Exceptions\PlanRequired;
use NickDeKruijk\ListMailing\Exceptions\RateLimited;
use NickDeKruijk\ListMailing\Exceptions\ServerError;
use NickDeKruijk\ListMailing\Exceptions\SingleOptInNotEnabled;
use NickDeKruijk\ListMailing\Exceptions\Unauthenticated;
use NickDeKruijk\ListMailing\Exceptions\UnexpectedResponse;
use NickDeKruijk\ListMailing\Exceptions\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/**
 * Client for the ListMailing sign-up API. Keep the token on your server, it
 * signs people up in your name.
 */
final class Client implements ClientInterface
{
    public const DEFAULT_BASE_URL = 'https://listmailing.nl/api/v1';

    /** The base URL without a trailing slash. */
    public readonly string $baseUrl;

    private readonly GuzzleClientInterface $http;

    /**
     * @param  string  $token  the API token, starting with lm_
     * @param  string  $baseUrl  must be https
     * @param  string|null  $defaultList  the list id used when a call names none
     * @param  float  $timeout  seconds to wait for an answer, more than 0
     * @param  GuzzleClientInterface|null  $http  for tests or a custom handler stack
     *
     * @throws InvalidArgumentException on an empty token, a base URL that is not https or a timeout of 0 or less
     */
    public function __construct(
        #[SensitiveParameter] private readonly string $token,
        string $baseUrl = self::DEFAULT_BASE_URL,
        public readonly ?string $defaultList = null,
        public readonly float $timeout = 10.0,
        ?GuzzleClientInterface $http = null,
    ) {
        if (trim($token) === '') {
            throw new InvalidArgumentException('A ListMailing API token is required.');
        }

        // The token goes in every request, so it never travels unencrypted.
        if (! str_starts_with(strtolower($baseUrl), 'https://')) {
            throw new InvalidArgumentException('The ListMailing base URL must start with https://.');
        }

        self::ensureTimeout($timeout);

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->http = $http ?? new GuzzleClient;
    }

    /**
     * A copy that waits at most this many seconds for an answer, for a call
     * a visitor waits on, such as asking for a confirmation link while a
     * booking is saved. Not part of ClientInterface, so an implementation of
     * its own does not break; ClientFake has it too.
     *
     * @throws InvalidArgumentException on 0 or less
     */
    public function withTimeout(float $seconds): static
    {
        return new self($this->token, $this->baseUrl, $this->defaultList, $seconds, $this->http);
    }

    /**
     * Guzzle reads a timeout of 0 as no timeout at all, the opposite of what
     * a short timeout is meant for.
     *
     * @throws InvalidArgumentException
     */
    private static function ensureTimeout(float $seconds): void
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException('The ListMailing timeout must be more than 0 seconds.');
        }
    }

    public function subscribe(Subscription $subscription, ?string $listId = null, ?string $idempotencyKey = null): SubscribeResult
    {
        $headers = $idempotencyKey === null || $idempotencyKey === '' ? [] : ['Idempotency-Key' => $idempotencyKey];

        $response = $this->send('POST', $this->path($listId), [
            'headers' => $headers,
            'json' => $subscription->toArray(),
        ]);

        return SubscribeResult::fromResponse($this->decode($response), $response->getStatusCode());
    }

    public function status(string $email, ?string $listId = null): StatusResult
    {
        $response = $this->send('GET', $this->path($listId), [
            'query' => ['email' => $email],
        ]);

        return StatusResult::fromResponse($this->decode($response), $response->getStatusCode());
    }

    /**
     * Leaves the token out of var_dump and dump().
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'defaultList' => $this->defaultList,
            'timeout' => $this->timeout,
        ];
    }

    private function path(?string $listId): string
    {
        $listId ??= $this->defaultList;

        if ($listId === null || $listId === '') {
            throw new InvalidArgumentException('No list id given and no default list set.');
        }

        return $this->baseUrl.'/lists/'.rawurlencode($listId).'/subscribers';
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws ConnectionFailed
     */
    private function send(string $method, string $url, array $options): ResponseInterface
    {
        $options['headers'] = [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'listmailing-php/'.(InstalledVersions::getPrettyVersion('nickdekruijk/listmailing-php') ?? 'dev'),
            ...($options['headers'] ?? []),
        ];

        try {
            return $this->http->request($method, $url, [
                ...$options,
                'http_errors' => false,
                // A redirect is never part of the API; following one would
                // turn a sign-up into a GET somewhere else.
                'allow_redirects' => false,
                'timeout' => $this->timeout,
                'connect_timeout' => min($this->timeout, 5.0),
            ]);
        } catch (GuzzleException $e) {
            // Not chained as previous: error trackers report the whole chain,
            // and the message of the Guzzle exception holds the full URL.
            throw new ConnectionFailed('ListMailing could not be reached ('.$e::class.'): '.self::withoutAddress($e->getMessage()));
        }
    }

    /**
     * A Guzzle message without the address a status lookup puts in the query
     * string, so it does not end up in logs or an error tracker.
     */
    private static function withoutAddress(string $message): string
    {
        return preg_replace('/([?&]email=)[^&\s]*/i', '$1[hidden]', $message) ?? 'ListMailing could not be reached.';
    }

    /**
     * The JSON body of a successful answer.
     *
     * @return array<mixed>
     *
     * @throws ListMailingException
     */
    private function decode(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);
        $body = is_array($body) ? $body : null;

        // A 5xx may be an HTML maintenance page, so it is checked before the body.
        if ($status >= 500) {
            throw new ServerError(
                self::message($body, "ListMailing answered with HTTP {$status}."),
                self::errorCode($body),
                $status,
                self::retryAfter($response),
            );
        }

        if ($body === null) {
            throw new UnexpectedResponse("ListMailing answered HTTP {$status} without a JSON body.", null, $status);
        }

        if ($status >= 200 && $status < 300) {
            return $body;
        }

        throw self::error($status, $body, $response);
    }

    /**
     * @param  array<mixed>  $body
     */
    private static function error(int $status, array $body, ResponseInterface $response): ListMailingException
    {
        $code = self::errorCode($body);
        $message = self::message($body, "ListMailing answered with HTTP {$status}.");

        if ($status === 422) {
            return new ValidationFailed($message, self::validationErrors($body));
        }

        return match ($code) {
            'unauthenticated' => new Unauthenticated($message, $code, $status),
            'plan_required' => new PlanRequired($message, $code, $status),
            'list_not_allowed' => new ListNotAllowed($message, $code, $status),
            'single_opt_in_not_enabled' => new SingleOptInNotEnabled($message, $code, $status),
            'https_required' => new HttpsRequired($message, $code, $status),
            'not_found' => new NotFound($message, $code, $status),
            'rate_limited' => new RateLimited($message, $code, $status, self::retryAfter($response)),
            'daily_limit_reached' => new DailyLimitReached($message, $code, $status, self::retryAfter($response)),
            default => new UnexpectedResponse($message, $code, $status),
        };
    }

    /**
     * @param  array<mixed>|null  $body
     */
    private static function errorCode(?array $body): ?string
    {
        return is_string($body['error'] ?? null) ? $body['error'] : null;
    }

    /**
     * @param  array<mixed>|null  $body
     */
    private static function message(?array $body, string $fallback): string
    {
        return is_string($body['message'] ?? null) && $body['message'] !== '' ? $body['message'] : $fallback;
    }

    /**
     * @param  array<mixed>  $body
     * @return array<string, list<string>>
     */
    private static function validationErrors(array $body): array
    {
        $errors = [];

        foreach (is_array($body['errors'] ?? null) ? $body['errors'] : [] as $field => $messages) {
            $errors[(string) $field] = array_values(array_filter((array) $messages, 'is_string'));
        }

        return $errors;
    }

    /**
     * The Retry-After header in seconds. It may be a number or an HTTP date.
     */
    private static function retryAfter(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $time = strtotime($value);

        return $time === false ? null : max(0, $time - time());
    }
}
