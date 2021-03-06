<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Integration;

use Exception;
use Piwik\Common;
use Piwik\Config;
use Piwik\Container\ContainerFactory;
use Piwik\Container\StaticContainer;
use Piwik\Db;
use Piwik\Log;
use Piwik\Plugins\TestPlugin\TestLoggingUtility;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

require_once PIWIK_INCLUDE_PATH . '/tests/resources/TestPluginLogClass.php';

/**
 * @group Core
 * @group Log
 */
class LogTest extends IntegrationTestCase
{
    const TESTMESSAGE = 'test%smessage';
    const STRING_MESSAGE_FORMAT = '[%tag%] %message%';
    const STRING_MESSAGE_FORMAT_SPRINTF = "[%s] %s";

    public static $expectedExceptionOutput = '[Piwik\Tests\Integration\LogTest] LogTest.php(122): dummy error message
  dummy backtrace';

    public static $expectedErrorOutput = '[Piwik\Tests\Integration\LogTest] dummyerrorfile.php(145): Unknown error (102) - dummy error string
  dummy backtrace';

    public function setUp()
    {
        parent::setUp();

        // Create the container in the normal environment (because in tests logging is disabled)
        $containerFactory = new ContainerFactory();
        $container = $containerFactory->create();
        StaticContainer::set($container);
        Log::unsetInstance();

        Config::getInstance()->log['string_message_format'] = self::STRING_MESSAGE_FORMAT;
        Config::getInstance()->log['logger_file_path'] = self::getLogFileLocation();
        Config::getInstance()->log['log_level'] = Log::INFO;
        @unlink(self::getLogFileLocation());
        Log::$debugBacktraceForTests = "dummy backtrace";
    }

    public function tearDown()
    {
        parent::tearDown();

        StaticContainer::clearContainer();
        Log::unsetInstance();

        @unlink(self::getLogFileLocation());
        Log::$debugBacktraceForTests = null;
    }

    /**
     * Data provider for every test.
     */
    public function getBackendsToTest()
    {
        return array(
            'file'     => array('file'),
            'database' => array('database'),
        );
    }

    /**
     * @dataProvider getBackendsToTest
     */
    public function testLoggingWorksWhenMessageIsString($backend)
    {
        Config::getInstance()->log['log_writers'] = array($backend);

        Log::warning(self::TESTMESSAGE);

        $this->checkBackend($backend, self::TESTMESSAGE, $formatMessage = true, $tag = __CLASS__);
    }

    /**
     * @dataProvider getBackendsToTest
     */
    public function testLoggingWorksWhenMessageIsSprintfString($backend)
    {
        Config::getInstance()->log['log_writers'] = array($backend);

        Log::warning(self::TESTMESSAGE, " subst ");

        $this->checkBackend($backend, sprintf(self::TESTMESSAGE, " subst "), $formatMessage = true, $tag = __CLASS__);
    }

    /**
     * @dataProvider getBackendsToTest
     */
    public function testLoggingWorksWhenMessageIsError($backend)
    {
        Config::getInstance()->log['log_writers'] = array($backend);

        $error = new \ErrorException("dummy error string", 0, 102, "dummyerrorfile.php", 145);
        Log::error($error);

        $this->checkBackend($backend, self::$expectedErrorOutput, $formatMessage = false, $tag = __CLASS__);
    }

    /**
     * @dataProvider getBackendsToTest
     */
    public function testLoggingWorksWhenMessageIsException($backend)
    {
        Config::getInstance()->log['log_writers'] = array($backend);

        $exception = new Exception("dummy error message");
        Log::error($exception);

        $this->checkBackend($backend, self::$expectedExceptionOutput, $formatMessage = false, $tag = __CLASS__);
    }

    /**
     * @dataProvider getBackendsToTest
     */
    public function testLoggingCorrectlyIdentifiesPlugin($backend)
    {
        Config::getInstance()->log['log_writers'] = array($backend);

        TestLoggingUtility::doLog(self::TESTMESSAGE);

        $this->checkBackend($backend, self::TESTMESSAGE, $formatMessage = true, $tag = 'TestPlugin');
    }

    /**
     * @dataProvider getBackendsToTest
     */
    public function testLogMessagesIgnoredWhenNotWithinLevel($backend)
    {
        Config::getInstance()->log['log_writers'] = array($backend);
        Config::getInstance()->log['log_level'] = 'ERROR';

        Log::info(self::TESTMESSAGE);

        $this->checkNoMessagesLogged($backend);
    }

    /**
     * @dataProvider getBackendsToTest
     */
    public function testLogMessagesAreTrimmed($backend)
    {
        Config::getInstance()->log['log_writers'] = array($backend);

        TestLoggingUtility::doLog(" \n   ".self::TESTMESSAGE."\n\n\n   \n");

        $this->checkBackend($backend, self::TESTMESSAGE, $formatMessage = true, $tag = 'TestPlugin');
    }

    private function checkBackend($backend, $expectedMessage, $formatMessage = false, $tag = false)
    {
        if ($formatMessage) {
            $expectedMessage = sprintf(self::STRING_MESSAGE_FORMAT_SPRINTF, $tag, $expectedMessage);
        }

        if ($backend == 'file') {
            $this->assertTrue(file_exists(self::getLogFileLocation()));

            $fileContents = file_get_contents(self::getLogFileLocation());
            $fileContents = $this->removePathsFromBacktrace($fileContents);

            $this->assertEquals($expectedMessage . "\n", $fileContents);
        } else if ($backend == 'database') {
            $count = Db::fetchOne("SELECT COUNT(*) FROM " . Common::prefixTable('logger_message'));
            $this->assertEquals(1, $count);

            $message = Db::fetchOne("SELECT message FROM " . Common::prefixTable('logger_message') . " LIMIT 1");
            $message = $this->removePathsFromBacktrace($message);
            $this->assertEquals($expectedMessage, $message);

            $tagInDb = Db::fetchOne("SELECT tag FROM " . Common::prefixTable('logger_message') . " LIMIT 1");
            if ($tag === false) {
                $this->assertEmpty($tagInDb);
            } else {
                $this->assertEquals($tag, $tagInDb);
            }
        }
    }

    private function checkNoMessagesLogged($backend)
    {
        if ($backend == 'file') {
            $this->assertFalse(file_exists(self::getLogFileLocation()));
        } else if ($backend == 'database') {
            $this->assertEquals(0, Db::fetchOne("SELECT COUNT(*) FROM " . Common::prefixTable('logger_message')));
        }
    }

    private function removePathsFromBacktrace($content)
    {
        return preg_replace_callback("/(?:\/[^\s(<>]+)*\//", function ($matches) {
            if ($matches[0] == '/') {
                return '/';
            } else {
                return '';
            }
        }, $content);
    }

    public static function getLogFileLocation()
    {
        return StaticContainer::get('path.tmp') . '/logs/piwik.test.log';
    }
}
