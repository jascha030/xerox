<?php

declare(strict_types=1);

namespace Jascha030\Xerox\Tests\Console\Command;

use DI\ContainerBuilder;
use Exception;
use Jascha030\Xerox\Application\Application;
use Jascha030\Xerox\Console\Command\InitCommand;
use Jascha030\Xerox\Database\DatabaseService;
use Jascha030\Xerox\Tests\TestDotEnvTrait;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/**
 * @covers \Jascha030\Xerox\Console\Command\InitCommand
 * @covers \Jascha030\Xerox\Console\Question\AsksConsoleQuestionsTrait
 * @internal
 */
final class InitCommandTest extends TestCase
{
    use TestDotEnvTrait;

    private const TEST_VALUES = [
        'DB_NAME'     => 'testdb',
        'DB_USER'     => 'user',
        'DB_PASSWORD' => 'test_password',
        'WP_HOME'     => 'https://example.test',
        'SALTS'       => 'SALTS="test"',
        'WP_DEBUG'    => true,
    ];

    private const WP_CONFIG_CONSTANTS = [
        'AUTH_KEY',
        'SECURE_AUTH_KEY',
        'LOGGED_IN_KEY',
        'NONCE_KEY',
        'AUTH_SALT',
        'SECURE_AUTH_SALT',
        'LOGGED_IN_SALT',
        'NONCE_SALT',
    ];

    private string $projectDir;

    private Filesystem $fileSystem;

    public function setUp(): void
    {
        $this->fileSystem = new Filesystem();
        $this->projectDir = dirname(__FILE__, 3) . '/Fixtures/testproject';

        $this->cleanTestProject();
    }

    public function tearDown(): void
    {
        $this->cleanTestProject();
    }

    /**
     * @throws Exception
     */
    public function testConstruct(): InitCommand
    {
        $container = $this->getContainer();
        $command   = new InitCommand($container);

        $this->assertInstanceOf(InitCommand::class, $command);

        return $command;
    }

    /**
     * @depends testConstruct
     */
    public function testConfigure(InitCommand $command): void
    {
        $description = 'Init a new Environment with database.';

        $command->setDescription('test');
        $this->assertEquals('test', $command->getDescription());

        $command->configure();
        $this->assertEquals($description, $command->getDescription());
    }

    /**
     * @depends testConstruct
     */
    public function testGetQuestionKey(InitCommand $command): void
    {
        $this->assertEquals('init', $command->getQuestionKey());
    }

    /**
     * @depends testConstruct
     */
    public function testGetQuestionHelper(InitCommand $command): void
    {
        $command->setApplication($this->getApplication());

        /** @noinspection UnnecessaryAssertionInspection */
        $this->assertInstanceOf(QuestionHelper::class, $command->getQuestionHelper());
    }

    /**
     * @depends testConstruct
     * @depends testConfigure
     * @depends testSanitizeDatabaseName
     * @depends testGetSalts
     * @depends testGenerateEnvContents
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function testExecute(InitCommand $command): void
    {
        $env     = $this->getDotEnv();
        $project = uniqid('unittest', false);
        $command->setApplication($this->getApplication());

        $commandTester = new CommandTester($command);
        $commandTester->setInputs([$project, $env['DB_USER'], $env['DB_PASSWORD'], $project, $env['ROOT_PASSWORD']]);

        $this->assertEquals(0, $commandTester->execute(['command' => $command]));
        $this->assertTrue($this->fileSystem->exists($this->projectDir . '/public/.env'));

        $this->cleanTestProject();

        $database = new DatabaseService($env['DB_USER'], $env['DB_PASSWORD']);
        $database->dropDatabase("wp_{$project}");
        $this->unlinkPublicDir($project);

        $this->assertEquals(0, $commandTester->execute(['command' => $command, '--production' => true]));
        $this->assertTrue($this->fileSystem->exists($this->projectDir . '/public/.env'));

        $database->dropDatabase("wp_{$project}");
        $this->unlinkPublicDir($project);
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     * @throws Exception
     */
    public function testExecuteWithInvalidTwigTemplate(): void
    {
        $env         = $this->getDotEnv();
        $projectName = uniqid('unittest', false);
        $command     = new InitCommand($this->getContainerWithTestTwigEnvironment());
        $command->setApplication($this->getApplication());

        $commandTester = new CommandTester($command);
        $commandTester->setInputs([
            $projectName,
            $env['DB_USER'],
            $env['DB_PASSWORD'],
            $projectName,
            $env['ROOT_PASSWORD'],
        ]);

        $this->assertEquals(1, $commandTester->execute(['command' => $command]));

        // Remove created database.
        $database = new DatabaseService($env['DB_USER'], $env['DB_PASSWORD']);
        $database->dropDatabase("wp_{$projectName}");
    }

    public function testFailureOnInvalidDatabaseCredentials(): void
    {
        $command = new InitCommand($this->getContainer());
        $command->setApplication($this->getApplication());

        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['unittest', 'probablyNotYourUsername', 'probablyNotYourPassword', 'unittest', '']);

        $this->assertEquals(1, $commandTester->execute(['command' => $command]));
    }

    /**
     * @depends testConstruct
     */
    public function testSanitizeDatabaseName(InitCommand $command): void
    {
        $this->assertEquals('testdb', $command->sanitizeDatabaseName('test db'));
    }

    /**
     * @depends testConstruct
     *
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function testGenerateEnvContents(InitCommand $command): void
    {
        $testEnv  = dirname(__FILE__, 3) . '/Fixtures/Templates/test.env';
        $contents = $command->generateEnvContents(...array_values(self::TEST_VALUES));

        $testAgainst = file_get_contents($testEnv);

        $this->assertEquals($testAgainst, $contents);
    }

    /**
     * @depends testConstruct
     */
    public function testGetSalts(InitCommand $command): void
    {
        $salts = $command->getSalts();

        $this->assertIsString($salts);

        $lines = explode(PHP_EOL, $salts);
        $this->assertCount(9, $lines);
        $this->assertEquals('', $lines[8]);

        array_pop($lines);

        $constants = [];

        foreach ($lines as $line) {
            $constants[] = substr($line, 0, strpos($line, '='));
        }

        $this->assertEquals(self::WP_CONFIG_CONSTANTS, $constants);
    }

    /**
     * @throws Exception
     */
    public function testExceptionIsThrownOnInvalidTwigTemplate(): void
    {
        $command = new InitCommand($this->getContainerWithTestTwigEnvironment());
        $command->setApplication($this->getApplication());

        $this->expectException(LoaderError::class);
        $command->generateEnvContents(...array_values(self::TEST_VALUES));
    }

    private function getContainer(): ContainerInterface
    {
        return include dirname(__FILE__, 4) . '/includes/bootstrap.php';
    }

    /**
     * @throws Exception
     */
    private function getContainerWithTestTwigEnvironment(): ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(false);
        $builder->useAnnotations(false);
        $builder->addDefinitions(dirname(__FILE__, 4) . '/config/console.php');
        $builder->addDefinitions(dirname(__FILE__, 3) . '/test-twig-definition.php');

        return $builder->build();
    }

    private function getApplication(): Application
    {
        $app = new Application($this->getContainer());
        $app->setAutoExit(false);

        return $app;
    }

    private function cleanTestProject(): void
    {
        if (! isset($this->projectDir)) {
            $class = __CLASS__;

            throw new \RuntimeException(
                "`{$class}::cleanTestProject()` can't run before `{$class}::setUp()`."
            );
        }

        if ($this->fileSystem->exists($this->projectDir . '/public/.env')) {
            $this->fileSystem->remove($this->projectDir . '/public/.env');
        }
    }

    /**
     * Unlink symbolic link created by valet.
     */
    private function unlinkPublicDir(string $linkedName): void
    {
        $output   = new ConsoleOutput();
        $callback = static function ($type, $buffer) use ($output) {
            $output->writeln($buffer);
        };

        $link = Process::fromShellCommandline("valet unlink {$linkedName}");
        $link->setWorkingDirectory($this->projectDir . '/public');
        $link->run($callback);
    }
}
