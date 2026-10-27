<?php

declare(strict_types=1);

namespace MyBeast\Logs\Laravel\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use MyBeast\Logs\Laravel\Facades\Logs;
use RuntimeException;

/** Personal data never leaves the app: what the spool keeps is already scrubbed. */
final class ScrubTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('mybeast-logs.scrub', ['/\bCUST-\d{6}\b/' => '[customer]', 'not a pattern' => '[x]']);
    }

    public function test_e_mails_ibans_personal_ids_phones_and_tokens_are_taken_out(): void
    {
        Log::info('Sent to anna.virtanen@example.fi: hetu 131052-308T, IBAN FI21 1234 5600 0007 85, call +358 40 123 4567, Bearer abc.def-123', [
            'to' => 'anna@example.fi',
            'note' => 'customer CUST-123456 phoned from 040-1234567',
            'key' => str_repeat('a1B2', 12),
            'count' => 134217728,
        ]);

        [$line] = $this->spooled();
        $this->assertSame('Sent to [email]: hetu [hetu], IBAN [iban], call [phone], Bearer [token]', $line['message']);
        $this->assertSame(['to' => '[email]', 'note' => 'customer [customer] phoned from [phone]', 'key' => '[token]', 'count' => 134217728], $line['context']);
    }

    public function test_a_secret_looking_key_keeps_no_value_whatever_it_holds(): void
    {
        Log::info('Signing in', ['email' => 'x', 'password' => 'hunter2', 'nested' => ['X-Api-Key' => ['a', 'b'], 'refresh_token' => 42, 'Cookie' => 'c=1'], 'tokens_left' => 3]);

        [$line] = $this->spooled();
        $this->assertSame(['email' => 'x', 'password' => '[redacted]', 'nested' => ['X-Api-Key' => '[redacted]', 'refresh_token' => '[redacted]', 'Cookie' => '[redacted]'], 'tokens_left' => '[redacted]'], $line['context']);
    }

    public function test_the_hosts_own_rule(): void
    {
        Logs::scrubUsing(fn (string $text): string => str_replace('Matti', '[name]', $text));

        Log::info('Matti paid', ['who' => 'Matti']);

        [$line] = $this->spooled();
        $this->assertSame(['[name] paid', ['who' => '[name]']], [$line['message'], $line['context']]);
    }

    public function test_a_model_is_its_class_and_key_never_its_attributes(): void
    {
        $person = new class extends Model
        {
            protected $guarded = [];
        };
        $person->forceFill(['id' => 7, 'email' => 'anna@example.fi', 'name' => 'Anna Virtanen']);

        Log::info('Updated', ['person' => $person]);

        [$line] = $this->spooled();
        $this->assertSame(7, $line['context']['person']['key']);
        $this->assertStringNotContainsString('Anna', json_encode($line));
    }

    public function test_an_exception_is_its_class_message_and_place_scrubbed(): void
    {
        $thrownAt = __LINE__ + 1;
        $e = new RuntimeException('No account for anna@example.fi', 3, new \LogicException('inner'));

        Log::error('Payment failed', ['exception' => $e]);

        [$line] = $this->spooled();
        $exception = $line['context']['exception'];
        $this->assertSame([RuntimeException::class, 'No account for [email]', 3], [$exception['class'], $exception['message'], $exception['code']]);
        $this->assertSame('tests/ScrubTest.php:'.$thrownAt, str_replace(dirname(__DIR__).'/', '', $exception['file']));
        $this->assertNotEmpty($exception['trace']);
        $this->assertSame(\LogicException::class, $exception['previous']['class']);
    }

    public function test_deep_long_and_big_context_is_cut_and_says_so(): void
    {
        Log::info('Deep', ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => 'too deep']]]]]]]]);
        Log::info('Long', ['list' => range(1, 150)]);
        Log::info('Big', ['blob' => array_fill(0, 100, str_repeat('word ', 300)), 'exception' => new RuntimeException('kept')]);

        [$deep, $long, $big] = $this->spooled();
        // Six levels below the top are kept; the seventh says it was cut.
        $this->assertSame('[…]', $deep['context']['a']['b']['c']['d']['e']['f']['g']);
        $this->assertCount(101, $long['context']['list']);
        $this->assertSame('50 more', $long['context']['list']['…']);
        $this->assertStringContainsString('a line carries 16 KB', $big['context']['_cut']);
        $this->assertSame('kept', $big['context']['exception']['message']);
    }
}
