<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use NickDeKruijk\ListMailing\Client;
use NickDeKruijk\ListMailing\Confirmation;
use NickDeKruijk\ListMailing\Exceptions\ConnectionFailed;
use NickDeKruijk\ListMailing\Exceptions\DailyLimitReached;
use NickDeKruijk\ListMailing\Exceptions\HttpsRequired;
use NickDeKruijk\ListMailing\Exceptions\ListMailingException;
use NickDeKruijk\ListMailing\Exceptions\ListNotAllowed;
use NickDeKruijk\ListMailing\Exceptions\NotFound;
use NickDeKruijk\ListMailing\Exceptions\PlanRequired;
use NickDeKruijk\ListMailing\Exceptions\RateLimited;
use NickDeKruijk\ListMailing\Exceptions\Retryable;
use NickDeKruijk\ListMailing\Exceptions\ServerError;
use NickDeKruijk\ListMailing\Exceptions\SingleOptInNotEnabled;
use NickDeKruijk\ListMailing\Exceptions\Unauthenticated;
use NickDeKruijk\ListMailing\Exceptions\UnexpectedResponse;
use NickDeKruijk\ListMailing\Exceptions\ValidationFailed;
use NickDeKruijk\ListMailing\MembershipStatus;
use NickDeKruijk\ListMailing\SubscribeOutcome;
use NickDeKruijk\ListMailing\Subscription;

describe('subscribe', function () {
    it('maps every status to an outcome', function (int $http, string $status, SubscribeOutcome $outcome, bool $created) {
        $result = client([json($http, ['status' => $status, 'subscriber_id' => null])])
            ->subscribe(new Subscription('anna@example.com'));

        expect($result->outcome)->toBe($outcome)
            ->and($result->created)->toBe($created)
            ->and($result->subscriberId)->toBeNull()
            ->and($result->confirmationUrl)->toBeNull();
    })->with([
        'pending, new address' => [201, 'pending', SubscribeOutcome::Pending, true],
        'pending, asked before' => [200, 'pending', SubscribeOutcome::Pending, false],
        'already subscribed' => [200, 'already_subscribed', SubscribeOutcome::AlreadySubscribed, false],
        'resubscribe pending' => [200, 'resubscribe_pending', SubscribeOutcome::ResubscribePending, false],
        'subscribed, new address' => [201, 'subscribed', SubscribeOutcome::Subscribed, true],
        'subscribed, after unsubscribing' => [200, 'subscribed', SubscribeOutcome::Subscribed, false],
        'suppressed' => [200, 'suppressed', SubscribeOutcome::Suppressed, false],
    ]);

    it('returns the subscriber id once there is one', function () {
        $result = client([json(200, ['status' => 'already_subscribed', 'subscriber_id' => 'sub_123'])])
            ->subscribe(new Subscription('anna@example.com'));

        expect($result->subscriberId)->toBe('sub_123');
    });

    it('returns the confirmation url for a link', function () {
        $result = client([json(201, ['status' => 'pending', 'subscriber_id' => null, 'confirmation_url' => 'https://listmailing.test/confirm/abc'])])
            ->subscribe(new Subscription('anna@example.com', confirmation: Confirmation::Link));

        expect($result->confirmationUrl)->toBe('https://listmailing.test/confirm/abc')
            ->and($result->outcome->awaitsConfirmation())->toBeTrue();
    });

    it('sends the sign-up as json with the token', function () {
        $client = client([json(201, ['status' => 'pending', 'subscriber_id' => null])], $history);

        $client->subscribe(new Subscription(
            email: 'anna@example.com',
            firstName: 'Anna',
            lastName: 'de Vries',
            locale: 'nl',
            source: 'booking-form',
            reference: 'B-2026-0123',
            consentedAt: new DateTimeImmutable('2026-11-17 10:15:00', new DateTimeZone('Europe/Amsterdam')),
            consentText: 'Ja, stuur mij de nieuwsbrief',
            confirmation: Confirmation::Email,
        ), 'list-9', 'key-1');

        /** @var Request $request */
        $request = $history[0]['request'];

        expect($request->getMethod())->toBe('POST')
            ->and((string) $request->getUri())->toBe('https://listmailing.test/api/v1/lists/list-9/subscribers')
            ->and($request->getHeaderLine('Authorization'))->toBe('Bearer lm_test_token')
            ->and($request->getHeaderLine('Accept'))->toBe('application/json')
            ->and($request->getHeaderLine('Content-Type'))->toBe('application/json')
            ->and($request->getHeaderLine('Idempotency-Key'))->toBe('key-1')
            ->and($request->getHeaderLine('User-Agent'))->toStartWith('listmailing-php/')
            ->and(json_decode((string) $request->getBody(), true))->toBe([
                'email' => 'anna@example.com',
                'first_name' => 'Anna',
                'last_name' => 'de Vries',
                'locale' => 'nl',
                'source' => 'booking-form',
                'reference' => 'B-2026-0123',
                'consented_at' => '2026-11-17T10:15:00+01:00',
                'consent_text' => 'Ja, stuur mij de nieuwsbrief',
                'confirmation' => 'email',
            ]);
    });

    it('leaves out empty fields and the idempotency key when there is none', function () {
        $client = client([json(201, ['status' => 'pending', 'subscriber_id' => null])], $history);

        $client->subscribe(new Subscription('anna@example.com'));

        $request = $history[0]['request'];

        expect(json_decode((string) $request->getBody(), true))->toBe(['email' => 'anna@example.com'])
            ->and($request->hasHeader('Idempotency-Key'))->toBeFalse()
            ->and((string) $request->getUri())->toBe('https://listmailing.test/api/v1/lists/list-1/subscribers');
    });

    it('encodes the list id in the path', function () {
        $client = client([json(201, ['status' => 'pending', 'subscriber_id' => null])], $history);

        $client->subscribe(new Subscription('anna@example.com'), 'a/b c');

        expect($history[0]['request']->getUri()->getPath())->toBe('/api/v1/lists/a%2Fb%20c/subscribers');
    });

    it('does not follow a redirect', function () {
        $client = client([new Response(302, ['Location' => 'https://elsewhere.test/'])], $history);

        expect(fn () => $client->subscribe(new Subscription('anna@example.com')))->toThrow(UnexpectedResponse::class);
        expect($history)->toHaveCount(1);
    });
});

describe('status', function () {
    it('maps every status', function (string $status, MembershipStatus $expected, ?string $id) {
        $result = client([json(200, ['status' => $status, 'subscriber_id' => $id])])->status('anna@example.com');

        expect($result->status)->toBe($expected)
            ->and($result->subscriberId)->toBe($id);
    })->with([
        'subscribed' => ['subscribed', MembershipStatus::Subscribed, 'sub_123'],
        'pending' => ['pending', MembershipStatus::Pending, null],
        'unsubscribed' => ['unsubscribed', MembershipStatus::Unsubscribed, 'sub_123'],
        'suppressed' => ['suppressed', MembershipStatus::Suppressed, 'sub_123'],
        'not subscribed' => ['not_subscribed', MembershipStatus::NotSubscribed, null],
    ]);

    it('asks with a GET and the address in the query', function () {
        $client = client([json(200, ['status' => 'not_subscribed', 'subscriber_id' => null])], $history);

        $client->status('anna+news@example.com', 'list-9');

        $request = $history[0]['request'];

        expect($request->getMethod())->toBe('GET')
            ->and($request->getUri()->getPath())->toBe('/api/v1/lists/list-9/subscribers')
            ->and($request->getUri()->getQuery())->toBe('email=anna%2Bnews%40example.com')
            ->and($request->getHeaderLine('Authorization'))->toBe('Bearer lm_test_token');
    });
});

describe('errors', function () {
    it('throws an exception per error code', function (int $http, string $code, string $class) {
        $client = client([json($http, ['error' => $code, 'message' => 'Plain language.'])]);

        try {
            $client->subscribe(new Subscription('anna@example.com'));
            $this->fail('Nothing was thrown.');
        } catch (ListMailingException $e) {
            expect($e)->toBeInstanceOf($class)
                ->and($e->errorCode)->toBe($code)
                ->and($e->httpStatus)->toBe($http)
                ->and($e->getMessage())->toBe('Plain language.');
        }
    })->with([
        'unauthenticated' => [401, 'unauthenticated', Unauthenticated::class],
        'plan required' => [403, 'plan_required', PlanRequired::class],
        'list not allowed' => [403, 'list_not_allowed', ListNotAllowed::class],
        'single opt-in not enabled' => [403, 'single_opt_in_not_enabled', SingleOptInNotEnabled::class],
        'https required' => [403, 'https_required', HttpsRequired::class],
        'not found' => [404, 'not_found', NotFound::class],
        'unknown code' => [403, 'something_new', UnexpectedResponse::class],
    ]);

    it('throws the same exceptions on a status lookup', function () {
        $client = client([json(404, ['error' => 'not_found', 'message' => 'Not found.'])]);

        expect(fn () => $client->status('anna@example.com'))->toThrow(NotFound::class);
    });

    it('gives the validation errors per field', function () {
        $client = client([json(422, [
            'message' => 'The email field must be a valid email address.',
            'errors' => ['email' => ['The email field must be a valid email address.']],
        ])]);

        try {
            $client->subscribe(new Subscription('not-an-address'));
            $this->fail('Nothing was thrown.');
        } catch (ValidationFailed $e) {
            expect($e->errors())->toBe(['email' => ['The email field must be a valid email address.']])
                ->and($e->httpStatus)->toBe(422)
                ->and($e->errorCode)->toBeNull()
                ->and($e)->not->toBeInstanceOf(Retryable::class);
        }
    });

    it('gives retry after on both limits', function (string $code, string $class) {
        $client = client([json(429, ['error' => $code, 'message' => 'Wait.'], ['Retry-After' => '3600'])]);

        try {
            $client->subscribe(new Subscription('anna@example.com'));
            $this->fail('Nothing was thrown.');
        } catch (ListMailingException $e) {
            expect($e)->toBeInstanceOf($class)
                ->and($e)->toBeInstanceOf(Retryable::class)
                ->and($e->retryAfter())->toBe(3600)
                ->and($e->httpStatus)->toBe(429);
        }
    })->with([
        'per minute' => ['rate_limited', RateLimited::class],
        'per day' => ['daily_limit_reached', DailyLimitReached::class],
    ]);

    it('reads retry after as an http date', function () {
        $when = gmdate('D, d M Y H:i:s', time() + 120).' GMT';
        $client = client([json(429, ['error' => 'rate_limited', 'message' => 'Wait.'], ['Retry-After' => $when])]);

        try {
            $client->subscribe(new Subscription('anna@example.com'));
            $this->fail('Nothing was thrown.');
        } catch (RateLimited $e) {
            expect($e->retryAfter())->toBeGreaterThanOrEqual(118)->toBeLessThanOrEqual(120);
        }
    });

    it('throws a retryable server error on a 5xx, also without json', function (Response $response, ?int $retryAfter) {
        $client = client([$response]);

        try {
            $client->subscribe(new Subscription('anna@example.com'));
            $this->fail('Nothing was thrown.');
        } catch (ServerError $e) {
            expect($e)->toBeInstanceOf(Retryable::class)
                ->and($e->httpStatus)->toBe($response->getStatusCode())
                ->and($e->retryAfter())->toBe($retryAfter);
        }
    })->with([
        '500 with json' => [fn () => json(500, ['message' => 'Server Error']), null],
        '503 maintenance page' => [fn () => new Response(503, ['Retry-After' => '60'], '<html>Down</html>'), 60],
    ]);

    it('throws a retryable connection failure when no answer comes back', function () {
        $client = client([new ConnectException('Connection timed out', new Request('POST', 'https://listmailing.test'))]);

        try {
            $client->subscribe(new Subscription('anna@example.com'));
            $this->fail('Nothing was thrown.');
        } catch (ConnectionFailed $e) {
            expect($e)->toBeInstanceOf(Retryable::class)
                ->and($e->retryAfter())->toBeNull()
                ->and($e->httpStatus)->toBeNull()
                ->and($e->getPrevious())->toBeInstanceOf(ConnectException::class);
        }
    });

    it('throws on an answer it does not understand', function (Response $response) {
        expect(fn () => client([$response])->subscribe(new Subscription('anna@example.com')))
            ->toThrow(UnexpectedResponse::class);
    })->with([
        'no json' => [fn () => new Response(200, [], 'OK')],
        'unknown status' => [fn () => json(200, ['status' => 'something_new', 'subscriber_id' => null])],
        'no status' => [fn () => json(201, ['subscriber_id' => null])],
    ]);

    it('throws on an unknown membership status', function () {
        expect(fn () => client([json(200, ['status' => 'something_new'])])->status('anna@example.com'))
            ->toThrow(UnexpectedResponse::class);
    });
});

describe('setup', function () {
    it('refuses a base url without https', function () {
        new Client('lm_test_token', 'http://listmailing.nl/api/v1');
    })->throws(InvalidArgumentException::class, 'https://');

    it('refuses an empty token', function () {
        new Client(' ');
    })->throws(InvalidArgumentException::class, 'token');

    it('needs a list id when there is no default list', function () {
        client([], defaultList: null)->subscribe(new Subscription('anna@example.com'));
    })->throws(InvalidArgumentException::class, 'list id');

    it('trims a trailing slash from the base url', function () {
        expect((new Client('lm_test_token', 'https://listmailing.test/api/v1/'))->baseUrl)->toBe('https://listmailing.test/api/v1');
    });

    it('keeps the token out of a dump', function () {
        expect(print_r(new Client('lm_secret_token'), true))->not->toContain('lm_secret_token');
    });
});
