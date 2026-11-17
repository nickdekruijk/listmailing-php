<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
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
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

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
                ->and($e->getPrevious())->toBeNull()
                ->and($e->getMessage())->toContain('ConnectException')->toContain('Connection timed out');
        }
    });

    it('leaves the email address out of a connection failure', function () {
        $url = 'https://listmailing.test/api/v1/lists/list-1/subscribers?email=anna%40example.com';
        $client = client([new ConnectException("cURL error 28: Operation timed out for {$url}", new Request('GET', $url))]);

        try {
            $client->status('anna@example.com');
            $this->fail('Nothing was thrown.');
        } catch (ConnectionFailed $e) {
            expect($e->getMessage())->not->toContain('anna')
                ->toContain('cURL error 28: Operation timed out')
                ->toContain('/lists/list-1/subscribers?email=[hidden]')
                ->and($e->getPrevious())->toBeNull();
        }
    });

    it('throws on an answer it does not understand', function (Response $response) {
        expect(fn () => client([$response])->subscribe(new Subscription('anna@example.com')))
            ->toThrow(UnexpectedResponse::class);
    })->with([
        'no json' => [fn () => new Response(200, [], 'OK')],
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

    it('refuses a timeout of 0 or less', function (float $seconds) {
        new Client('lm_test_token', timeout: $seconds);
    })->throws(InvalidArgumentException::class, 'timeout')->with([0.0, -1.0]);

    it('keeps the token out of a dump', function () {
        expect(print_r(new Client('lm_secret_token'), true))->not->toContain('lm_secret_token');
    });
});

describe('withTimeout', function () {
    it('gives a copy with a shorter timeout', function () {
        $client = client([json(201, ['status' => 'pending', 'subscriber_id' => null])], $history);

        $short = $client->withTimeout(3);
        $short->subscribe(new Subscription('anna@example.com'));

        expect($short)->not->toBe($client)
            ->and($short->timeout)->toBe(3.0)
            ->and($client->timeout)->toBe(10.0)
            ->and($short->defaultList)->toBe('list-1')
            ->and($short->baseUrl)->toBe('https://listmailing.test/api/v1')
            ->and($history[0]['options']['timeout'])->toBe(3.0)
            ->and($history[0]['options']['connect_timeout'])->toBe(3.0)
            ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer lm_test_token');
    });

    it('refuses 0 or less', function (float $seconds) {
        client([])->withTimeout($seconds);
    })->throws(InvalidArgumentException::class, 'timeout')->with([0.0, -3.0]);
});

describe('defaultLocale', function () {
    it('sends the fallback language when the sign-up has none it can use', function (?string $locale, string $sent) {
        $client = client([json(201, ['status' => 'pending', 'subscriber_id' => null])], $history, defaultLocale: 'en');

        $client->subscribe(new Subscription('anna@example.com', locale: $locale));

        expect(json_decode((string) $history[0]['request']->getBody(), true)['locale'])->toBe($sent);
    })->with([
        'no language' => [null, 'en'],
        'unknown language' => ['de', 'en'],
        'its own language wins' => ['nl_NL', 'nl'],
    ]);

    it('normalizes the fallback language', function (?string $given, ?string $kept) {
        expect((new Client('lm_test_token', defaultLocale: $given))->defaultLocale)->toBe($kept);
    })->with([
        ['EN-gb', 'en'],
        ['de', null],
        [null, null],
    ]);

    it('sends no language without a fallback', function () {
        $client = client([json(201, ['status' => 'pending', 'subscriber_id' => null])], $history);

        $client->subscribe(new Subscription('anna@example.com', locale: 'de'));

        expect(json_decode((string) $history[0]['request']->getBody(), true))->not->toHaveKey('locale');
    });

    it('keeps the fallback language in a copy with a shorter timeout', function () {
        expect((new Client('lm_test_token', defaultLocale: 'en'))->withTimeout(3)->defaultLocale)->toBe('en');
    });
});

describe('the token', function () {
    it('is trimmed, so a secret file that ends in a newline works', function () {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([json(201, ['status' => 'pending', 'subscriber_id' => null])]));
        $stack->push(Middleware::history($history));

        (new Client("lm_test_token\n", 'https://listmailing.test/api/v1', 'list-1', 10.0, new GuzzleHttp\Client(['handler' => $stack])))
            ->subscribe(new Subscription('anna@example.com'));

        expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer lm_test_token');
    });

    it('is refused when it holds a character a header cannot carry, without repeating it', function (string $token) {
        try {
            new Client($token);
        } catch (InvalidArgumentException $e) {
            expect($e->getMessage())->not->toContain('lm_secret');

            return;
        }

        $this->fail('The token was accepted.');
    })->with([
        'a line break inside' => ["lm_secret\ntoken"],
        'a control character' => ["lm_secret\x01token"],
        'a space inside' => ['lm_secret token'],
    ]);

    it('stays out of every kind of dump', function () {
        $client = new Client('lm_secret_token');

        $dumper = new CliDumper;
        $dumped = $dumper->dump((new VarCloner)->cloneVar($client), true);

        expect($dumped)->not->toContain('lm_secret_token')
            ->and(var_export($client, true))->not->toContain('lm_secret_token')
            ->and(print_r($client, true))->not->toContain('lm_secret_token')
            ->and(json_encode($client))->not->toContain('lm_secret_token');
    });

    it('still travels with a copy made by withTimeout', function () {
        $client = client([json(201, ['status' => 'pending', 'subscriber_id' => null])], $history);

        $client->withTimeout(3)->subscribe(new Subscription('anna@example.com'));

        expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer lm_test_token');
    });
});

describe('answers nobody planned for', function () {
    it('gives a status ListMailing added later as unknown, with the word it sent', function () {
        $result = client([json(201, ['status' => 'something_new', 'subscriber_id' => 'sub_1'])])
            ->subscribe(new Subscription('anna@example.com'));

        // The sign-up went through: a new word for it must not be an error.
        expect($result->outcome)->toBe(SubscribeOutcome::Unknown)
            ->and($result->status)->toBe('something_new')
            ->and($result->subscriberId)->toBe('sub_1')
            ->and($result->outcome->awaitsConfirmation())->toBeFalse()
            ->and($result->outcome->isOnList())->toBeFalse();
    });

    it('keeps the word ListMailing sent for a status it knows too', function () {
        $result = client([json(200, ['status' => 'already_subscribed', 'subscriber_id' => 'sub_1'])])
            ->subscribe(new Subscription('anna@example.com'));

        expect($result->status)->toBe('already_subscribed');
    });

    it('still refuses a 2xx without a status', function () {
        client([json(200, ['subscriber_id' => 'sub_1'])])->subscribe(new Subscription('anna@example.com'));
    })->throws(UnexpectedResponse::class);

    it('retries any 429, also one that is not from ListMailing itself', function (Response $response, ?int $retryAfter) {
        try {
            client([$response])->subscribe(new Subscription('anna@example.com'));
        } catch (RateLimited $e) {
            expect($e)->toBeInstanceOf(Retryable::class)
                ->and($e->retryAfter())->toBe($retryAfter);

            return;
        }

        $this->fail('No RateLimited was thrown.');
    })->with([
        'html from a proxy' => [new Response(429, ['Retry-After' => '30'], '<html>Too many requests</html>'), 30],
        'json without a code' => [new Response(429, ['Content-Type' => 'application/json'], '{"message":"slow down"}'), null],
        'json with a code of somebody else' => [new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '5'], '{"error":"too_many","message":"slow down"}'), 5],
    ]);

    it('still tells a full day apart from a full minute', function () {
        client([json(429, ['error' => 'daily_limit_reached', 'message' => 'Full.'], ['Retry-After' => '3600'])])
            ->subscribe(new Subscription('anna@example.com'));
    })->throws(DailyLimitReached::class);

    it('refuses text that is not valid UTF-8 before anything is sent, and does not call it a connection failure', function () {
        $client = client([json(201, ['status' => 'pending', 'subscriber_id' => null])], $history);

        try {
            $client->subscribe(new Subscription('anna@example.com', firstName: "Ren\xE9"));
        } catch (Throwable $e) {
            expect($e)->toBeInstanceOf(InvalidArgumentException::class)
                ->not->toBeInstanceOf(Retryable::class)
                ->and($history)->toBeEmpty();

            return;
        }

        $this->fail('Nothing was thrown.');
    });
});
