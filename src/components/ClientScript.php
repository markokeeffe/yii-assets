<?php
/**
 * Author:  Mark O'Keeffe

 * Date:    22/11/13
 *
 */

class ClientScript extends CClientScript {

  /**
   * Position for CSS files
   */
  const POS_CSS=5;

  /**
   * Toggle minification
   *
   * @var bool
   */
  public $minify = true;

  /**
   * Set a default script file position
   *
   * @var int
   */
  public $defaultScriptFilePosition = self::POS_END;

  /**
   * Set a default core script position
   *
   * @var int
   */
  public $coreScriptPosition = self::POS_END;

  /**
   * The position of script group being created
   * @var string
   */
  protected $currentPosition;

  /**
   * The asset manager class
   *
   * @var \MOK\Assets\Assets
   */
  protected $assets;

  /**
   * Inject dependencies
   */
  public function __construct()
  {
    $this->assets = Yii::app()->Assets;
  }

  /**
   * Set up
   */
  public function init()
  {
    parent::init();
    if (!$this->minify) {
      return;
    }

    // Check the controller has been mapped
    $this->checkController();
  }

  /**
   * Check for the asset controller ID inside CWebApplication::$controllerMap
   *
   * @throws CException
   */
  protected function checkController()
  {
    if (!isset(Yii::app()->controllerMap[$this->assets->controllerId])) {
      throw new CException('The asset controller with ID "'
        .$this->assets->controllerId
        .'" needs to be defined in CWebApplication::$controllerMap.'
      );
    }
  }

  /**
   * Register all javascripts found in the chosen directory
   *
   * @param string $dir The name of the directory in the js directory
   * @param int    $pos Where to load the scripts
   *
   */
  public function registerScriptDirectory($dir, $pos = self::POS_END)
  {

    // Get the path to the directory from the provided URL
    $path = $this->assets->getPath($dir);

    if (is_dir($path)) {
      if ($dh = opendir($path)) {
        while (($file = readdir($dh)) !== false) {
          $extension = strtolower(substr(strrchr($file, '.'), 1));
          if ($extension == 'js') {
            $this->registerScriptFile($dir . '/' . $file, $pos);
          }
        }
        closedir($dh);
      }
    }

  }

  /**
   * Process files registered with CClientScript::registerCssFile() or CClientScript::registerScriptFile().
   *
   * @param string  $type     Type of files to process.
   * @param int     $position Position of scripts to process. Not needed for CSS files.
   */
  protected function _minScriptProcessor($type, $position) {
    // Get file system paths for registered files and reset CClientScript::$scriptFiles or CClientScript::$cssFiles
    $files = array();
    if ($type == 'js') {
      // Loop through registered script files
      if (isset($this -> scriptFiles[$position])) {
        foreach ($this->scriptFiles[$position] as $scriptUrl) {
          $files[$position][$scriptUrl] = $this->assets->getPath($scriptUrl);
          unset($this -> scriptFiles[$position][$scriptUrl]);
        }
      }
    } elseif ($type == 'css') {
      // Loop through registered CSS files and ensure that the correct order is kept
      $cssSort = 0;
      foreach ($this->cssFiles as $cssUrl => $cssMedia) {
        if (isset($prevCssMedia) && $cssMedia == $prevCssMedia) {
          $cssMediaSort = $cssMedia . 'minScriptCssSort' . $cssSort;
        } else {
          $cssMediaSort = $cssMedia . 'minScriptCssSort' . ($cssSort = $cssSort + 1);
        }
        $prevCssMedia = $cssMedia;
        $files[$cssMediaSort][$cssUrl] = $this->assets->getPath($cssUrl);
        unset($this -> cssFiles[$cssUrl]);
      }
    }
    // Loop through registered positions/medias
    foreach (array_keys($files) as $key) {
      $urls = array();
      // Get URLs for registered files
      foreach ($files[$key] as $url => $path) {
        if ($path !== false) {
          $paths[] = $path;
        } else {
          // To keep the correct order, the minScript group creation process is split up if an external/excluded URL is detected
          if (!empty($paths)) {
            $urls[] = $this->assets->createGroup($paths, $type, $position);
            $paths = array();
          }
          $urls[] = $url;
        }
      }
      if (!empty($paths)) {
        $urls[] = $this->assets->createGroup($paths, $type, $position);
        $paths = array();
      }
      // Store URLs back to CClientScript::$scriptFiles or CClientScript::$cssFiles
      foreach ($urls as $url) {
        if ($type == 'js') {
          $this -> scriptFiles[$key][$url] = $url;
        } elseif ($type == 'css') {
          $keySegments = explode('minScriptCssSort', $key);
          $this -> cssFiles[$url] = array_shift($keySegments);
        }
      }
    }
  }

  /**
   * Inserts the scripts at the beginning of the body section (overrides parent method).
   * @param string $output the output to be inserted with scripts.
   * @since 2.0
   */
  public function renderBodyBegin(&$output) {
    if ($this->minify) {
      $this -> _minScriptProcessor('js', self::POS_BEGIN);
    }
    parent::renderBodyBegin($output);
  }


  /**
   * Inserts the scripts at the end of the body section.
   * @param string $output the output to be inserted with scripts.
   */
  public function renderBodyEnd(&$output)
  {

    if ($this->minify) {
      $this -> _minScriptProcessor('js', self::POS_END);
    }

    if(!isset($this->scriptFiles[self::POS_END]) && !isset($this->scripts[self::POS_END])
      && !isset($this->scripts[self::POS_READY]) && !isset($this->scripts[self::POS_LOAD]))
      return;

    $fullPage=0;
    if (preg_match('/<\!-- ENDSCRIPTS --\>/', $output)) {
      $output=preg_replace('/<\!-- ENDSCRIPTS --\>/','<###end###>$1',$output,1,$fullPage);
    } else {
      $output=preg_replace('/(<\\/body\s*>)/is','<###end###>$1',$output,1,$fullPage);
    }

    $html='';
    if(isset($this->scriptFiles[self::POS_END]))
    {
      foreach($this->scriptFiles[self::POS_END] as $scriptFileUrl=>$scriptFileValue)
      {
        if(is_array($scriptFileValue))
          $html.=CHtml::scriptFile($scriptFileUrl,$scriptFileValue)."\n";
        else
          $html.=CHtml::scriptFile($scriptFileUrl)."\n";
      }
    }
    $scripts=isset($this->scripts[self::POS_END]) ? $this->scripts[self::POS_END] : array();
    if(isset($this->scripts[self::POS_READY]))
    {
      if($fullPage)
        $scripts[]="jQuery(function($) {\n".implode("\n",$this->scripts[self::POS_READY])."\n});";
      else
        $scripts[]=implode("\n",$this->scripts[self::POS_READY]);
    }
    if(isset($this->scripts[self::POS_LOAD]))
    {
      if($fullPage)
        $scripts[]="jQuery(window).on('load',function() {\n".implode("\n",$this->scripts[self::POS_LOAD])."\n});";
      else
        $scripts[]=implode("\n",$this->scripts[self::POS_LOAD]);
    }
    if(!empty($scripts))
      $html.=$this->renderScriptBatch($scripts);

    if($fullPage)
      $output=str_replace('<###end###>',$html,$output);
    else
      $output=$output.$html;
  }

  /**
   * Inserts the scripts in the head section (overrides parent method).
   * @param string $output the output to be inserted with scripts.
   * @since 2.0
   */
  public function renderHead(&$output) {
    if ($this->minify) {
      $this -> _minScriptProcessor('js', self::POS_HEAD);
      $this -> _minScriptProcessor('css', self::POS_CSS);
    }
    parent::renderHead($output);
  }


}
