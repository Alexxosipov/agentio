<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Commands;

use Illuminate\Console\Command;
use Obrazmisli\Agentio\Console\Concerns\RunsPackageScripts;
use Obrazmisli\Agentio\Settings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Process\Process;

/**
 * Runs the project's tests the way the agents must (scripts/run-tests.sh of the package): the output goes to a
 * log file under storage/logs/tests, Playwright servers left behind by browser tests are killed, and only the
 * result is printed. Every argument except --full is passed to the test command, e.g.
 * `php artisan agentio:test --filter=Avatar tests/Feature/AvatarTest.php`.
 */
#[AsCommand(name: 'agentio:test')]
final class TestCommand extends Command
{
    use RunsPackageScripts;

    public const string SCRIPT = 'run-tests.sh';

    /**
     * @var string
     */
    protected $signature = 'agentio:test
        {arguments?* : Arguments of the test command, e.g. --filter=Avatar or test files}
        {--full : Run the full quality gate (AGENTIO_FULL_TEST_COMMAND, composer test by default)}';

    /**
     * @var string
     */
    protected $description = 'Run the tests the way the agents do (output in a log file, the result printed)';

    public function __construct()
    {
        parent::__construct();

        // Options of the test command (--filter=…, --parallel, …) are passed through instead of being rejected.
        $this->ignoreValidationErrors();
    }

    public function handle(Settings $settings): int
    {
        $full = (bool) $this->option('full');

        return $this->runScript(new Process(
            [...self::scriptCommand(self::SCRIPT), ...($full ? [] : $this->testArguments())],
            $settings->basePath(),
            $this->scriptEnvironment($settings, ['RUN_TESTS_FULL' => $full ? '1' : '0']),
            null,
            null,
        ));
    }

    /**
     * The arguments after the command name as they were typed (unknown options included), without --full.
     *
     * @return list<string>
     */
    private function testArguments(): array
    {
        $arguments = $this->input instanceof ArgvInput
            ? $this->input->getRawTokens(true)
            : array_values(array_map(strval(...), (array) $this->argument('arguments')));

        return array_values(array_filter($arguments, fn (string $argument): bool => $argument !== '--full'));
    }
}
