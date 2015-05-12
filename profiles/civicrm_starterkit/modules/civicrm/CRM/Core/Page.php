<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.4                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2013                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*/

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC (c) 2004-2013
 * $Id$
 *
 */

/**
 * A Page is basically data in a nice pretty format.
 *
 * Pages should not have any form actions / elements in them. If they
 * do, make sure you use CRM_Core_Form and the related structures. You can
 * embed simple forms in Page and do your own form handling.
 *
 */
class CRM_Core_Page {

  /**
   * The name of the page (auto generated from class name)
   *
   * @var string
   * @access protected
   */
  protected $_name;

  /**
   * the title associated with this page
   *
   * @var object
   * @access protected
   */
  protected $_title;

  /**
   * A page can have multiple modes. (i.e. displays
   * a different set of data based on the input
   * @var int
   * @access protected
   */
  protected $_mode;

  /**
   * Is this object being embedded in another object. If
   * so the display routine needs to not do any work. (The
   * parent object takes care of the display)
   *
   * @var boolean
   * @access protected
   */
  protected $_embedded = FALSE;

  /**
   * Are we in print mode? if so we need to modify the display
   * functionality to do a minimal display :)
   *
   * @var boolean
   * @access protected
   */
  protected $_print = FALSE;

  /**
   * cache the smarty template for efficiency reasons
   *
   * @var CRM_Core_Smarty
   * @access protected
   * @static
   */
  static protected $_template;

  /**
   * cache the session for efficiency reasons
   *
   * @var CRM_Core_Session
   * @access protected
   * @static
   */
  static protected $_session;

  /**
   * class constructor
   *
   * @param string $title title of the page
   * @param int    $mode  mode of the page
   *
   * @return CRM_Core_Page
   */
  function __construct($title = NULL, $mode = NULL) {
    $this->_name  = CRM_Utils_System::getClassName($this);
    $this->_title = $title;
    $this->_mode  = $mode;

    // let the constructor initialize this, should happen only once
    if (!isset(self::$_template)) {
      self::$_template = CRM_Core_Smarty::singleton();
      self::$_session = CRM_Core_Session::singleton();
    }

    if (isset($_REQUEST['snippet']) && $_REQUEST['snippet']) {
      if ($_REQUEST['snippet'] == 3) {
        $this->_print = CRM_Core_Smarty::PRINT_PDF;
      }
      else if ($_REQUEST['snippet'] == 5) {
        $this->_print = CRM_Core_Smarty::PRINT_NOFORM;
      }
      else {
        $this->_print = CRM_Core_Smarty::PRINT_SNIPPET;
      }
    }

    // if the request has a reset value, initialize the controller session
    if (CRM_Utils_Array::value('reset', $_REQUEST)) {
      $this->reset();
    }
  }

  /**
   * This function takes care of all the things common to all
   * pages. This typically involves assigning the appropriate
   * smarty variable :)
   *
   * @return string The content generated by running this page
   */
  function run() {
    if ($this->_embedded) {
      return;
    }

    self::$_template->assign('mode', $this->_mode);

    $pageTemplateFile = $this->getHookedTemplateFileName();
    self::$_template->assign('tplFile', $pageTemplateFile);

    // invoke the pagRun hook, CRM-3906
    CRM_Utils_Hook::pageRun($this);

    if ($this->_print) {
      if (in_array( $this->_print, array( CRM_Core_Smarty::PRINT_SNIPPET,
        CRM_Core_Smarty::PRINT_PDF, CRM_Core_Smarty::PRINT_NOFORM ))) {
        $content = self::$_template->fetch('CRM/common/snippet.tpl');
      }
      else {
        $content = self::$_template->fetch('CRM/common/print.tpl');
      }

      CRM_Utils_System::appendTPLFile($pageTemplateFile,
        $content,
        $this->overrideExtraTemplateFileName()
      );

      //its time to call the hook.
      CRM_Utils_Hook::alterContent($content, 'page', $pageTemplateFile, $this);

      if ($this->_print == CRM_Core_Smarty::PRINT_PDF) {
        CRM_Utils_PDF_Utils::html2pdf($content, "{$this->_name}.pdf", FALSE,
          array('paper_size' => 'a3', 'orientation' => 'landscape')
        );
      }
      else {
        echo $content;
      }
      CRM_Utils_System::civiExit();
    }

    $config = CRM_Core_Config::singleton();

    // TODO: Is there a better way to ensure these actions don't happen during AJAX requests?
    if (empty($_GET['snippet'])) {
      // Version check and intermittent alert to admins
      CRM_Utils_VersionCheck::singleton()->versionAlert();
      CRM_Utils_Check::singleton()->showPeriodicAlerts();

      // Debug msg once per hour
      if ($config->debug && CRM_Core_Permission::check('administer CiviCRM') && CRM_Core_Session::singleton()->timer('debug_alert', 3600)) {
        $msg = ts('Warning: Debug is enabled in <a href="%1">system settings</a>. This should not be enabled on production servers.', array(1 => CRM_Utils_System::url('civicrm/admin/setting/debug', 'reset=1')));
        CRM_Core_Session::setStatus($msg, ts('Debug Mode'));
      }
    }

    $content = self::$_template->fetch('CRM/common/' . strtolower($config->userFramework) . '.tpl');

    // Render page header
    if (!defined('CIVICRM_UF_HEAD') && $region = CRM_Core_Region::instance('html-header', FALSE)) {
      CRM_Utils_System::addHTMLHead($region->render(''));
    }
    CRM_Utils_System::appendTPLFile($pageTemplateFile, $content);

    //its time to call the hook.
    CRM_Utils_Hook::alterContent($content, 'page', $pageTemplateFile, $this);

    echo CRM_Utils_System::theme($content, $this->_print);
    return;
  }

  /**
   * Store the variable with the value in the form scope
   *
   * @param  string|array $name  name  of the variable or an assoc array of name/value pairs
   * @param  mixed        $value value of the variable if string
   *
   * @access public
   *
   * @return void
   *
   */
  function set($name, $value = NULL) {
    self::$_session->set($name, $value, $this->_name);
  }

  /**
   * Get the variable from the form scope
   *
   * @param  string name  : name  of the variable
   *
   * @access public
   *
   * @return mixed
   *
   */
  function get($name) {
    return self::$_session->get($name, $this->_name);
  }

  /**
   * assign value to name in template
   *
   * @param array|string $name  name  of variable
   * @param mixed $value value of varaible
   *
   * @return void
   * @access public
   */
  function assign($var, $value = NULL) {
    self::$_template->assign($var, $value);
  }

  /**
   * assign value to name in template by reference
   *
   * @param array|string $name  name  of variable
   * @param mixed $value (reference) value of varaible
   *
   * @return void
   * @access public
   */
  function assign_by_ref($var, &$value) {
    self::$_template->assign_by_ref($var, $value);
  }

  /**
   * appends values to template variables
   *
   * @param array|string $tpl_var the template variable name(s)
   * @param mixed $value the value to append
   * @param bool $merge
   */
  function append($tpl_var, $value=NULL, $merge=FALSE) {
    self::$_template->append($tpl_var, $value, $merge);
  }

  /**
   * Returns an array containing template variables
   *
   * @param string $name
   * @param string $type
   * @return array
   */
  function get_template_vars($name=null) {
    return self::$_template->get_template_vars($name);
  }

  /**
   * function to destroy all the session state of this page.
   *
   * @access public
   *
   * @return void
   */
  function reset() {
    self::$_session->resetScope($this->_name);
  }

  /**
   * Use the form name to create the tpl file name
   *
   * @return string
   * @access public
   */
  function getTemplateFileName() {
    return str_replace('_',
      DIRECTORY_SEPARATOR,
      CRM_Utils_System::getClassName($this)
    ) . '.tpl';
  }

  /**
   * A wrapper for getTemplateFileName that includes calling the hook to
   * prevent us from having to copy & paste the logic of calling the hook
   */
  function getHookedTemplateFileName() {
    $pageTemplateFile = $this->getTemplateFileName();
    CRM_Utils_Hook::alterTemplateFile(get_class($this), $this, 'page', $pageTemplateFile);
    return $pageTemplateFile;
  }

  /**
   * Default extra tpl file basically just replaces .tpl with .extra.tpl
   * i.e. we dont override
   *
   * @return string
   * @access public
   */
  function overrideExtraTemplateFileName() {
    return NULL;
  }

  /**
   * setter for embedded
   *
   * @param boolean $embedded
   *
   * @return void
   * @access public
   */
  function setEmbedded($embedded) {
    $this->_embedded = $embedded;
  }

  /**
   * getter for embedded
   *
   * @return boolean return the embedded value
   * @access public
   */
  function getEmbedded() {
    return $this->_embedded;
  }

  /**
   * setter for print
   *
   * @param boolean $print
   *
   * @return void
   * @access public
   */
  function setPrint($print) {
    $this->_print = $print;
  }

  /**
   * getter for print
   *
   * @return boolean return the print value
   * @access public
   */
  function getPrint() {
    return $this->_print;
  }

  static function &getTemplate() {
    return self::$_template;
  }

  function getVar($name) {
    return isset($this->$name) ? $this->$name : NULL;
  }

  function setVar($name, $value) {
    $this->$name = $value;
  }
}

