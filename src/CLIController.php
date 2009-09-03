<?php
/**
 * Cli controller
 *
 * Copyright (c) 2007-2009, Mayflower GmbH
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
 * @category   PHP_CodeBrowser
 * @package    PHP_CodeBrowser
 * @author     Elger Thiele <elger.thiele@mayflower.de>
 * @copyright  2007-2009 Mayflower GmbH
 * @license    http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version    SVN: $Id$
 * @link       http://www.phpunit.de/
 * @since      File available since 1.0
 */

/**
 * cbCLIController
 *
 * @category   PHP_CodeBrowser
 * @package    PHP_CodeBrowser
 * @author     Elger Thiele <elger.thiele@mayflower.de>
 * @author     Christopher Weckerle <christopher.weckerle@mayflower.de>
 * @copyright  2007-2009 Mayflower GmbH
 * @license    http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version    Release: @package_version@
 * @link       http://www.phpunit.de/
 * @since      Class available since 1.0
 */
class cbCLIController
{
    /**
     * Path to the Cruise Control input xml file
     *
     * @var string
     */
    private $_ccXMLFile;
    
    /**
     * Path to the generated code browser xml file
     *
     * @var string
     */
    private $_cbXMLFile;
    
    /**
     * Path to the code browser html output folder
     *
     * @var string
     */
    private $_htmlOutputDir;
    
    /**
     * Path to the project source code files
     *
     * @var string
     */
    private $_projectSourceDir;
    
    /**
     * The error plugin classes 
     *
     * @var array
     */
    private $_registeredErrorPlugins;
    
    /**
     * The constructor
     * 
     * Standard setters are initialized
     *
     * @param string $ccXMLFile        The (path-to) cruisecontrol XML file
     * @param string $projectSourceDir The project source directory
     * @param string $htmlOutputDir    The html output dir, where new files will be created
     * @param string $cbXMLFile        The (path-to) PHP_CodeBrowser XML file
     */
    public function __construct ($ccXMLFile, $projectSourceDir, $htmlOutputDir, $cbXMLFile)
    {
        $this->setXMLFile($ccXMLFile, 'cc');
        $this->setXMLFile($cbXMLFile, 'cb');
        $this->setProjectSourceDir($projectSourceDir);
        $this->setHtmlOutputDir($htmlOutputDir);
    }
    
    /**
     * Setter method for the (path-to) XML files
     *
     * @param string $fileName The (path-to) XML file
     * @param string $type     The type definition for the XML file (cc=CruiseControl, cb=PHP_CodeBrowser)
     * 
     * @return void
     */
    public function setXMLFile ($fileName, $type)
    {
        if ('cc' == $type) $this->_ccXMLFile = $fileName;
        if ('cb' == $type) $this->_cbXMLFile = $fileName;
    }
    
    /**
     * Setter method for the project source directory
     *
     * @param string $projectSourceDir The (path-to) project source directory
     * 
     * @return void
     */
    public function setProjectSourceDir ($projectSourceDir)
    {
        $this->_projectSourceDir = $projectSourceDir;
    }
    
    /**
     * Setter method for the output directory
     *
     * @param string $htmlOutputDir The (path-to) output directory
     * 
     * @return void
     */
    public function setHtmlOutputDir ($htmlOutputDir)
    {
        $this->_htmlOutputDir = $htmlOutputDir;
    }
    
    /**
     * Setter/adder method for the used plugin classes.
     * For each plugin to use, add it to this array
     *
     * @param mixed $classNames Definition of plugin classes
     * 
     * @return void
     */
    public function addErrorPlugins ($classNames)
    {
        foreach ((array) $classNames as $className) {
            $this->_registeredErrorPlugins[] = $className;
        }
    }
    
    /**
     * Main execute function for PHP_CodeBrowser.
     * 
     * Following steps are resolved:
     * 1. Clean-up output directory
     * 2. Generate cbXML file via errorlist from plugins
     * 3. Save the cbErrorList as XML file
     * 4. Generate HTML output from cbXML
     * 5. Copy ressources (css, js, images) from template directory to output 
     * 
     * @return void
     */
    public function run ()
    {
        // init needed classes
        $cbFDHandler    = new cbFDHandler();
        $cbXMLHandler   = new cbXMLHandler($cbFDHandler);
        $cbErrorHandler = new cbErrorHandler($cbXMLHandler);
        $cbJSGenerator  = new cbJSGenerator($cbFDHandler);
        
        // clear and create output directory
        $cbFDHandler->deleteDirectory($this->_htmlOutputDir);
        $cbFDHandler->createDirectory($this->_htmlOutputDir);
        
        // conversion of XML file cc to cb format
        $list = array();
        foreach ($this->_registeredErrorPlugins as $className) {
            $plugin = new $className($this->_projectSourceDir, $cbXMLHandler);
            $plugin->setXML($this->_ccXMLFile);
            $list = $list + $plugin->parseXMLError();
        }
        
        // construct the error list
        $cbXMLGenerator = new cbXMLGenerator($cbFDHandler);
        $cbXMLGenerator->setXMLName($this->_cbXMLFile);
        $cbXMLGenerator->saveCbXML($cbXMLGenerator->generateXMLFromErrors($list));
        
        // get cb error list
        $errors = $cbErrorHandler->getFilesWithErrors($this->_cbXMLFile);
        
        // create html views
        $templateDir = realpath(dirname(__FILE__) . "/./../") . '/templates';
        $html        = new cbHTMLGenerator($cbFDHandler, $cbErrorHandler, $cbJSGenerator);
        $html->setTemplateDir($templateDir);
        $html->setOutputDir($this->_htmlOutputDir);
        $html->generateViewFlat($errors);
        $html->generateViewTree($errors);
        $html->generateViewReview($errors, $this->_cbXMLFile, $this->_projectSourceDir);
        
        // copy needed resources like css, js, images
        $html->copyRessourceFolders();
    }
}