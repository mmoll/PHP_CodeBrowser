<?php
/**
 * Cli controller
 *
 * PHP Version 5.3.2
 *
 * Copyright (c) 2007-2010, Mayflower GmbH
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *   * Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 *
 *   * Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in
 *     the documentation and/or other materials provided with the
 *     distribution.
 *
 *   * Neither the name of Mayflower GmbH nor the names of his
 *     contributors may be used to endorse or promote products derived
 *     from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @category  PHP_CodeBrowser
 * @package   PHP_CodeBrowser
 * @author    Elger Thiele <elger.thiele@mayflower.de>
 * @author    Simon Kohlmeyer <simon.kohlmeyer@mayflower.de>
 * @copyright 2007-2010 Mayflower GmbH
 * @license   http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version   SVN: $Id$
 * @link      http://www.phpunit.de/
 * @since     File available since  0.1.0
 */

namespace PHPCodeBrowser;

use Console_CommandLine;
use Exception;
use File_Iterator_Factory;
use Monolog\Logger;
use PHPCodeBrowser\Helper\IOHelper;
use PHPCodeBrowser\View\ViewReview;

if (!defined('PHPCB_ROOT_DIR')) {
    define('PHPCB_ROOT_DIR', realpath(dirname(__FILE__) . '/../'));
}
if (!defined('PHPCB_TEMPLATE_DIR')) {
    define('PHPCB_TEMPLATE_DIR', realpath(dirname(__FILE__) . '/../../templates'));
}

/**
 * CLIController
 *
 * @category  PHP_CodeBrowser
 * @package   PHP_CodeBrowser
 * @author    Elger Thiele <elger.thiele@mayflower.de>
 * @author    Michel Hartmann <michel.hartmann@mayflower.de>
 * @author    Simon Kohlmeyer <simon.kohlmeyer@mayflower.de>
 * @copyright 2007-2010 Mayflower GmbH
 * @license   http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version   Release: @package_version@
 * @link      http://www.phpunit.de/
 * @since     Class available since  0.1.0
 */
class CLIController
{
    /**
     * Path to the Cruise Control input xml file
     *
     * @var string
     */
    private $logDir;

    /**
     * Path to the code browser html output folder
     *
     * @var string
     */
    private $htmlOutputDir;

    /**
     * Path to the project source code files
     *
     * @var string
     */
    private $projectSource;

    /**
     * array of PCREs. Matching files will not appear in the output.
     *
     * @var array
     */
    private $excludeExpressions;

    /**
     * array of glob patterns. Matching files will not appear in the output.
     *
     * @var array
     */
    private $excludePatterns;

    /**
     * The error plugin classes
     *
     * @var array
     */
    private $registeredPlugins;

    /**
     * The IOHelper used for filesystem interaction.
     *
     * @var IOHelper
     */
    private $ioHelper;

    /**
     * Pear Log object where debug output should go to.
     *
     * @var Logger
     */
    private $debugLog;

    /**
     * Plugin-specific options. Formatted like
     *  array(
     *      'ErrorCRAP' => array(
     *          'threshold' => 2
     *      )
     *  )
     *
     * @var array
     */
    private $pluginOptions = array();

    /**
     * File extensions that we take as php files.
     *
     * @var array
     */
    private $phpSuffixes;

    /**
     * We want to exclude files with no issues
     *
     * @var boolean
     */
    private $excludeOK;

    /**
     * The constructor
     *
     * Standard setters are initialized
     *
     * @param string   $logPath            The (path-to) xml log files. Can be null.
     * @param array    $projectSource      The project sources. Can be null.
     * @param string   $htmlOutputDir      The html output dir, where new files will be created
     * @param array    $excludeExpressions A list of PCREs. Files matching will not appear in the output.
     * @param array    $excludePatterns    A list of glob patterns. Files matching will not appear in the output.
     * @param array    $pluginOptions      array of arrays with plugin-specific options
     * @param IOHelper $ioHelper           The IOHelper object to be used for filesystem interaction.
     * @param Logger   $debugLog
     * @param array    $phpSuffixes
     * @param bool     $excludeOK
     */
    public function __construct(
        $logPath,
        array $projectSource,
        $htmlOutputDir,
        array $excludeExpressions,
        array $excludePatterns,
        array $pluginOptions,
        $ioHelper,
        Logger $debugLog,
        array $phpSuffixes,
        $excludeOK = false
    ) {
        $this->logDir             = $logPath;
        $this->projectSource      = $projectSource;
        $this->htmlOutputDir      = $htmlOutputDir;
        $this->excludeExpressions = $excludeExpressions;
        $this->excludePatterns    = $excludePatterns;
        foreach ($pluginOptions as $plugin => $options) {
            $this->pluginOptions["Error$plugin"] = $options;
        }
        $this->ioHelper           = $ioHelper;
        $this->debugLog           = $debugLog;
        $this->registeredPlugins  = array();
        $this->phpSuffixes        = $phpSuffixes;
        $this->excludeOK          = $excludeOK;
    }

    /**
     * Setter/adder method for the used plugin classes.
     * For each plugin to use, add it to this array
     *
     * @param mixed $classNames Definition of plugin classes
     *
     * @return void
     */
    public function addErrorPlugins($classNames)
    {
        foreach ((array) $classNames as $className) {
            $this->registeredPlugins[] = $className;
        }
    }

    /**
     * Main execute function for PHP_CodeBrowser.
     *
     * Following steps are resolved:
     * 1. Clean-up output directory
     * 2. Merge xml log files
     * 3. Generate XML file via error list from plugins
     * 4. Save the ErrorList as XML file
     * 5. Generate HTML output from XML
     * 6. Copy resources (css, js, images) from template directory to output
     *
     * @return void
     */
    public function run()
    {
        // clear and create output directory
        if (is_dir($this->htmlOutputDir)) {
            $this->ioHelper->deleteDirectory($this->htmlOutputDir);
        } elseif (is_file($this->htmlOutputDir)) {
            $this->ioHelper->deleteFile($this->htmlOutputDir);
        }
        $this->ioHelper->createDirectory($this->htmlOutputDir);

        // init needed classes
        $viewReview  = new ViewReview(
            PHPCB_TEMPLATE_DIR,
            $this->htmlOutputDir,
            $this->ioHelper,
            $this->phpSuffixes
        );

        $sourceHandler = new SourceHandler($this->debugLog);

        if (isset($this->logDir)) {
            $issueXml    = new IssueXml();

            // merge xml files
            $issueXml->addDirectory($this->logDir);

            // conversion of XML file cc to cb format
            foreach ($this->registeredPlugins as $className) {
                if (array_key_exists($className, $this->pluginOptions)) {
                    $plugin = new $className(
                        $issueXml,
                        $this->pluginOptions[$className]
                    );
                } else {
                    $plugin = new $className($issueXml);
                }
                $sourceHandler->addPlugin($plugin);
            }
        }

        if (isset($this->projectSource)) {
            foreach ($this->projectSource as $source) {
                if (is_dir($source)) {
                    $factory = new File_Iterator_Factory;

                    $suffixes = array_merge(
                        $this->phpSuffixes,
                        array('php','js','css', 'html')
                    );

                    $sourceHandler->addSourceFiles(
                        $factory->getFileIterator(
                            $source,
                            $suffixes
                        )
                    );
                } else {
                    $sourceHandler->addSourceFile($source);
                }
            }
        }

        array_walk(
            $this->excludeExpressions,
            array($sourceHandler, 'excludeMatchingPCRE')
        );
        array_walk(
            $this->excludePatterns,
            array($sourceHandler, 'excludeMatchingPattern')
        );

        $files = $sourceHandler->getFiles();

        if (!$files) {
            $viewReview->copyNoErrorsIndex();
        } else {
            // Get the path prefix all files have in common
            $commonPathPrefix = $sourceHandler->getCommonPathPrefix();

            $error_reporting = ini_get('error_reporting');
            // Disable E_Strict, Text_Highlighter might throw up
            ini_set('error_reporting', $error_reporting & ~E_STRICT);
            foreach ($files as $file) {
                $viewReview->generate(
                    $file->getIssues(),
                    $file->name(),
                    $commonPathPrefix,
                    $this->excludeOK
                );
            }
            ini_set('error_reporting', $error_reporting);

            // Copy needed ressources (eg js libraries) to output directory
            $viewReview->copyResourceFolders();
            $viewReview->generateIndex($files, $this->excludeOK);
        }
    }

    /**
     * Main method called by script
     *
     * @return void
     */
    public static function main()
    {
        $parser = self::createCommandLineParser();
        $opts   = array();

        try {
            $opts = $parser->parse()->options;
        } catch (Exception $e) {
            $parser->displayError($e->getMessage());
        }

        $errors = self::errorsForOpts($opts);
        if ($errors) {
            foreach ($errors as $e) {
                error_log("[Error] $e\n");
            }
            exit(1);
        }

        // Convert the --ignore arguments to patterns
        if (null !== $opts['ignore']) {
            $dirSep = preg_quote(DIRECTORY_SEPARATOR, '/');
            foreach (explode(',', $opts['ignore']) as $ignore) {
                $ig = realpath($ignore);
                if (!$ig) {
                    error_log("[Warning] $ignore does not exists");
                } else {
                    $ig = preg_quote($ig, '/');
                    $opts['excludePCRE'][] = "/^$ig($dirSep|$)/";
                }
            }
        }

        // init new CLIController
        $controller = new CLIController(
            $opts['log'],
            $opts['source'] ? $opts['source'] : array(),
            $opts['output'],
            $opts['excludePCRE'] ? $opts['excludePCRE'] : array(),
            $opts['excludePattern'] ? $opts['excludePattern'] : array(),
            $opts['crapThreshold'] ? array('CRAP' => array('threshold' => $opts['crapThreshold'])) : array(),
            new IOHelper(),
            $opts['debugExcludes']
                ? new Logger('PHPCodeBrowser') //'console', '', 'PHPCB') FIXME
                : new Logger('PHPCodeBrowser'), //Log::factory('null'),
            $opts['phpSuffixes'] ? explode(',', $opts['phpSuffixes']) : array('php'),
            $opts['excludeOK'] ? $opts['excludeOK'] : false
        );

        $plugins = self::getAvailablePlugins();

        if ($opts['disablePlugin']) {
            foreach ($opts['disablePlugin'] as $idx => $val) {
                $opts['disablePlugin'][$idx] = strtolower($val);
            }
            foreach ($plugins as $pluginKey => $plugin) {
                $name = substr($plugin, strlen('Error'));
                if (in_array(strtolower($name), $opts['disablePlugin'])) {
                    // Remove it from the plugins list
                    unset($plugins[$pluginKey]);
                }
            }
        }
        $controller->addErrorPlugins($plugins);

        try {
            $controller->run();
        } catch (Exception $e) {
            error_log(
<<<HERE
[Error] {$e->getMessage()}

{$e->getTraceAsString()}
HERE
            );
        }
    }

    /**
     * Returns a list of available plugins.
     *
     * Currently hard-coded.
     *
     * @return string[] Class names of error plugins
     */
    public static function getAvailablePlugins()
    {
        return array(
            'PHPCodeBrowser\\Plugins\\ErrorCheckstyle',
            'PHPCodeBrowser\\Plugins\\ErrorPMD',
            'PHPCodeBrowser\\Plugins\\ErrorCPD',
            'PHPCodeBrowser\\Plugins\\ErrorPadawan',
            'PHPCodeBrowser\\Plugins\\ErrorCoverage',
            'PHPCodeBrowser\\Plugins\\ErrorCRAP'
        );
    }

    /**
     * Checks the given options array for errors.
     *
     * @param array $opts Options as returned by Console_CommandLine->parse()
     *
     * @return array of string error messages.
     */
    private static function errorsForOpts($opts)
    {
        $errors = array();

        if (!isset($opts['log'])) {
            if (!isset($opts['source'])) {
                $errors[] = 'Missing log or source argument.';
            }
        } elseif (!file_exists($opts['log'])) {
            $errors[] = 'Log directory does not exist.';
        } elseif (!is_dir($opts['log'])) {
            $errors[] = 'Log argument must be a directory, a file was given.';
        }

        if (!isset($opts['output'])) {
            $errors[] = 'Missing output argument.';
        } elseif (file_exists($opts['output']) && !is_dir($opts['output'])) {
            $errors[] = 'Output argument must be a directory, a file was given.';
        }

        if (isset($opts['source'])) {
            foreach ($opts['source'] as $s) {
                if (!file_exists($s)) {
                    $errors[] = "Source '$s' does not exist";
                }
            }
        }

        return $errors;
    }

    /**
     * Creates a Console_CommandLine object to parse options.
     *
     * @return Console_CommandLine
     */
    private static function createCommandLineParser()
    {
        $parser = new Console_CommandLine(
            array(
                'description' => 'A Code browser for PHP files with syntax '
                                    . 'highlighting and colored error-sections '
                                    . 'found by quality assurance tools like '
                                    . 'PHPUnit or PHP_CodeSniffer.',
                'version'     => (strpos('@package_version@', '@') === false)
                                    ? '@package_version@'
                                    : 'from Git'
            )
        );

        $parser->addOption(
            'log',
            array(
                'description' => 'The path to the xml log files, e.g. generated'
                                    . ' from PHPUnit. Either this or --source '
                                    . 'must be given',
                'short_name'  => '-l',
                'long_name'   => '--log',
                'help_name'   => '<directory>'
            )
        );

        $parser->addOption(
            'phpSuffixes',
            array(
                'description' => 'A comma separated list of php file extensions'
                                    .' to include.',
                'short_name'  => '-S',
                'long_name'   => '--extensions',
                'help_name'   => '<extensions>'
            )
        );

        $parser->addOption(
            'output',
            array(
                'description' => 'Path to the output folder where generated '
                                    . 'files should be stored.',
                'short_name'  => '-o',
                'long_name'   => '--output',
                'help_name'   => '<directory>'
            )
        );

        $parser->addOption(
            'source',
            array(
                'description' => 'Path to the project source code. Can either '
                                    . 'be a directory or a single file. Parse '
                                    . 'complete source directory if set, else '
                                    . 'only files found in logs. Either this or'
                                    . ' --log must be given. Can be given '
                                    . 'multiple times',
                'short_name'  => '-s',
                'long_name'   => '--source',
                'action'      => 'StoreArray',
                'help_name'   => '<dir|file>'
            )
        );

        $parser->addOption(
            'ignore',
            array(
                'description' => 'Comma separated string of files or '
                                    . 'directories that will be ignored during'
                                    . 'the parsing process.',
                'short_name'  => '-i',
                'long_name'   => '--ignore',
                'help_name'   => '<files>'
            )
        );

        $parser->addOption(
            'excludePattern',
            array(
                'description' => 'Excludes all files matching the given glob '
                                    . 'pattern. This is done after pulling the '
                                    . 'files in the source dir in if one is '
                                    . 'given. Can be given multiple times. Note'
                                    . ' that the match is run against '
                                    . 'absolute file names.',
                'short_name'  => '-e',
                'long_name'   => '--exclude',
                'action'      => 'StoreArray',
                'help_name'   => '<pattern>'
            )
        );

        $parser->addOption(
            'excludePCRE',
            array(
                'description' => 'Works like -e but takes PCRE instead of '
                                    . 'glob patterns.',
                'short_name'  => '-E',
                'long_name'   => '--excludePCRE',
                'action'      => 'StoreArray',
                'help_name'   => '<expression>'
            )
        );

        $parser->addOption(
            'debugExcludes',
            array(
                'description' => 'Print which files are excluded by which '
                                    . 'expressions and patterns.',
                'long_name'   => '--debugExcludes',
                'action'      => 'StoreTrue'
            )
        );
        $parser->addOption(
            'excludeOK',
            array(
                'description' => 'Exclude files with no issues from the report',
                'long_name'   => '--excludeOK',
                'action'      => 'StoreTrue'
            )
        );

        $plugins = array_map(
            function ($class) {
                return '"' . substr($class, strlen('Error')) . '"';
            },
            self::getAvailablePlugins()
        );

        $parser->addOption(
            'disablePlugin',
            array(
                'description' => 'Disable single Plugins. Can be one of ' . implode(', ', $plugins),
                'choices'     => $plugins,
                'long_name'   => '--disablePlugin',
                'action'      => 'StoreArray',
                'help_name'   => '<plugin>'
            )
        );

        $parser->addOption(
            'crapThreshold',
            array(
                'description' => 'The minimum value for CRAP errors to be '
                                    . 'recognized. Defaults to 0. Regardless '
                                    . 'of this setting, values below 30 will '
                                    . 'be considered notices, those above '
                                    . 'warnings.',
                'long_name'   => '--crapThreshold',
                'action'      => 'StoreInt',
                'help_name'   => '<threshold>'
            )
        );

        return $parser;
    }
}
