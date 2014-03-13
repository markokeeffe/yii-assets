<?php namespace MOK\Assets;
/**
 * Author:  Mark O'Keeffe
 * Date:    22/11/13
 */


use Yii;
use Assetic\Asset\AssetCollection;
use Assetic\Asset\FileAsset;
use Assetic\Filter\CssRewriteFilter;
use Assetic\Filter\CssMinFilter;
use Assetic\Filter\JSMinFilter;

class Assets extends \CApplicationComponent {

  /**
   * Path to a directory to store asset group files
   *
   * @var string
   */
  public $groupDir;

  /**
   * A string to prefix group IDs to ensure uniqueness
   *
   * @var string
   */
  public $groupIdPrefix;

  /**
   * ID of the asset controller. Defaults to "asset".
   *
   * @var string
   */
  public $controllerId = 'asset';

  /**
   * Toggle CDN
   *
   * @var bool
   */
  public $useCDN = false;

  /**
   * Force SSL connection?
   *
   * @var bool
   */
  public $ssl = false;

  /**
   * Use cache for 'Last Modified?'
   * Expire LM cache after x minutes
   * @var null|int
   */
  public $lmCacheExpire = null;

  /**
   * ID of the cache component
   *
   * @var string
   */
  public $cacheId = 'cache';

  /**
   * The name of the application component to use as a remote repository
   *
   * @var string
   */
  public $remoteComponent;

  /**
   * The cache component
   *
   * @var \CCache
   */
  protected $_cache;

  /**
   * The remote repository e.g. Rackspace CDN
   *
   * @var \MOK\RepoRackspace\RemoteRepositoryInterface
   */
  protected $_remote;

  /**
   * Set up
   */
  public function init()
  {
    parent::init();

    $this->_remote = Yii::app()->getComponent($this->remoteComponent);

    if (!$this->groupDir) {
      $this->groupDir = Yii::app()->runtimePath.'/vassets/';
    }

    if (!$this->groupIdPrefix) {
      $this->groupIdPrefix = Yii::app()->id . 'assetGroup';
    }

    // Set up the cache component
    $this->initCache();
  }

  /**
   * Get the cache component, or create a dummy
   */
  protected function initCache()
  {
    // Initialize the cache application component instance for minScript
    if ($this->cacheId !== false && ($cache = Yii::app()->getComponent($this->cacheId)) !== null) {
      $this->_cache = $cache;
    } else {
      Yii::app()->setComponents(array(
        'vCache' => array('class' => 'system.caching.CDummyCache'),
      ), false);
      $this->_cache = Yii::app()->getComponent('vCache');
    }
  }

  /**
   * Get the cache component
   *
   * @return \CCache
   */
  public function getCache()
  {
    return $this->_cache;
  }

  /**
   * Create a file containing serialised paths to all asset files in a group
   *
   * @param array   $files
   * @param string  $type     js|css
   * @param int     $position
   *
   * @throws \CException
   * @return string
   */
  public function createGroup($files, $type, $position)
  {
    $files = (array)$files;

    $filesSerialized = serialize($files);
    $groupFile = $this->groupDir . md5($this->groupIdPrefix . $filesSerialized);
    // Create group if necessary
    if (@is_file($groupFile) === false) {
      $groupsPath = dirname($groupFile);
      if (@is_dir($groupsPath) === false) {
        @mkdir($groupsPath, 0777, true);
      }
      if (@is_writable($groupsPath) !== true) {
        throw new \CException($groupsPath . '" is not writable.');
      }
      @file_put_contents($groupFile, $filesSerialized, LOCK_EX);
    }
    // Get last modified timestamp
    $lm = $this->_getLm($files);
    // Generate URL
    $params['group'] = basename($groupFile);

    $params['pos'] = 'scriptpos'.$position;

    $params['lm'] = $lm;

    // Are we using a CDN?
    if ($this->useCDN) {
      // Get the remote URL to the group source, uploading if necessary
      $url = $this->_getCdnUrl($params, $type);
    } else {
      // Get the URL to the asset controller to generate the source on-the-fly
      $url = Yii::app()->createUrl($this->controllerId . '/index', array(
        'group' => $params['group'],
        'type' => $type,
        'lm' => $lm,
      ));
    }

    return $url;
  }

  /**
   * Get files from the specified minScript group.
   *
   * @param $groupId
   *
   * @return array Files from the group or false if group doesn't exist.
   */
  public function getGroup($groupId) {
    $filesSerialized = @file_get_contents($this->groupDir . $groupId);
    return ($filesSerialized === false) ? false : unserialize($filesSerialized);
  }

  /**
   * Get the source code for a group of assets either from cache,
   * or by processing with Assetic
   *
   * @param $group
   * @param $type
   * @param $lm
   *
   * @return string
   */
  public function getGroupSource($group, $type, $lm)
  {
    // Is there something in the cache for this group?
    if ($cached = $this->_cache->get($group)) {
      // Decode the data into an array
      $cached = json_decode($cached, true);
      // Do the modified timestamps match?
      if ($cached['lm'] == $lm && $cached['source']) {
        // Return the minified source
        return $cached['source'];
      }
    }

    // Process the files in the group to produce minified source code
    $source = $this->_processGroup($group, $type);

    // Add the source to the cache
    $this->_cache->set($group, json_encode(compact('lm', 'source')));

    // Return the source code
    return $source;
  }

  /**
   * Get the file system path from a URL.
   *
   * @param string $url The URL for which to get the path.
   *
   * @return mixed The absolute file system path with no trailing slash. Returns false if the URL points
   * to a remote resource or is excluded from processing.
   */
  public function getPath($url)
  {

    // Get document root
    $docRoot = rtrim(substr($_SERVER['SCRIPT_FILENAME'], 0, strpos($_SERVER['SCRIPT_FILENAME'], $_SERVER['SCRIPT_NAME'])), '/\\');

    // Is the URL absolute?
    if (preg_match('/^([a-z0-9\.+-]+:)?\/\//i', $url) > 0) {
      // The URL is absolute
      $urlAbsolute = (strpos($url, '//') === 0) ? 'http:' . $url : $url;
      if (($urlSegments = @parse_url($urlAbsolute)) && isset($urlSegments['host']) && $urlSegments['host'] != @parse_url(Yii::app()->request->hostInfo . Yii::app()->request->url, PHP_URL_HOST)) {
        return false;
      }
      $urlPath = (isset($urlSegments['path'])) ? $urlSegments['path'] : '';
      $path = $docRoot . $urlPath;
    } elseif (strpos($url, Yii::app()->assetManager->baseUrl) === 0) {
      // The URL points to an asset
      $assetBasePath = rtrim(Yii::app()->assetManager->basePath, '/\\');
      $path = $assetBasePath . (string)@parse_url(substr($url, strlen(Yii::app()->assetManager->baseUrl)), PHP_URL_PATH);
    } elseif (strpos($url, '/') === 0) {
      // The URL is relative to the document root
      $path = $docRoot . (string)@parse_url($url, PHP_URL_PATH);
    } else {
      // The URL is relative to the current request
      $requestPathRaw = @parse_url(Yii::app()->request->hostInfo . Yii::app()->request->url, PHP_URL_PATH);
      if ($requestPathRaw && substr($requestPathRaw, -1) == '/') {
        $requestPathRaw .= 'dummy';
      }
      $requestPath = rtrim(dirname($requestPathRaw), '/\\');
      if (!empty($this->minScriptBaseUrl)) {
        $basePathRaw = @parse_url($this->minScriptBaseUrl, PHP_URL_PATH);
        if ($basePathRaw && substr($basePathRaw, -1) == '/') {
          $basePathRaw .= 'dummy';
        }
        $basePath = rtrim(dirname($basePathRaw), '/\\');
      }
      $path = (isset($basePath)) ? $docRoot . $basePath . '/' . (string)@parse_url($url, PHP_URL_PATH) : $docRoot . $requestPath . '/' . (string)@parse_url($url, PHP_URL_PATH);
    }

    return rtrim($path, '/\\');
  }

  /**
   * Get the URL to a remote file containing the minified source
   *
   * @param $params
   * @param $type
   *
   * @return bool|mixed
   */
  protected function _getCdnUrl($params, $type)
  {
    $remoteId = $params['group'].'remote';

    // Is the URL for this version of the file stored in cache?
    if ($file = $this->_cache->get($remoteId)) {
      $file = json_decode($file, true);
      if ($file['lm'] == $params['lm']) {
        // Return it!
        return $file['url'];
      }
    }

    // Check the CDN if this version of the script exists and return the URL
    $url = $this->_checkCDN($params, $type);

    $file = array_merge(array('url' => $url), $params);
    // Add this version to the cache
    $this->_cache->set($remoteId, json_encode($file));

    return $url;
  }

  /**
   * Use Assetic to combine and minify a group of assets and return the source
   *
   * @param $group
   * @param $type
   *
   * @return string
   * @throws \CHttpException
   */
  protected function _processGroup($group, $type)
  {
    // Get absolute URL to the public directory
    $pubUrl = \Yii::app()->createAbsoluteUrl('/');
    // Get the absolute path to the public directory
    $pubPath = str_replace('\\', '/', \Yii::getPathOfAlias('webroot'));

    // Is this app using a theme?
    if ($theme = \Yii::app()->theme) {
      // Get the absolute URL to the theme directory
      $themeUrl = \Yii::app()->request->getHostInfo().$theme->baseUrl;
      // Get the absolute path to the theme directory
      $themePath = str_replace('\\', '/', $theme->basePath);
    }

    // Get an array of file paths from the group ID
    $files = $this->getGroup($group);

    if (!$files) {
      throw new \CHttpException(404, 'Invalid group ID: '.\CHtml::encode($group));
    }

    // Create an array of file assets from the paths
    $assets = array();
    foreach ($files as $file) {
      // Get the root of this file
      $sourceRoot = str_replace('\\', '/', dirname($file));

      if ($theme && strstr($sourceRoot, $themePath)) {
        $filePath = str_replace($themePath, $themeUrl, $sourceRoot);
      } elseif (strstr($sourceRoot, $pubPath)) {
        $filePath = str_replace($pubPath, $pubUrl, $sourceRoot);
      } else {
        throw new \CHttpException(500, 'Invalid asset file path.');
      }
      // Get the absolute public URL to this file
      $sourcePath = $filePath.'/'.basename($file);
      // Create the asset
      $asset = new FileAsset($file, array(), $sourceRoot, $sourcePath);
      // Add to the assets
      $assets[] = $asset;
    }

    // Get a minification filter based on asset type
    switch ($type) {
      case 'css' :
        $filters = array(
          new CssMinFilter(),
          new CssRewriteFilter(),
        );
        break;
      case 'js' :
        $filters = array(
          new JSMinFilter(),
        );
        break;
      default :
        throw new \CHttpException(500, 'Invalid asset type.');
    }

    // Create a collection from the assets
    $collection = new AssetCollection($assets, $filters);

    return $collection->dump();
  }

  /**
   * Check the Rackspace CloudFiles CDN for this version of the script
   *
   * @param array   $params The script group and last modified time
   * @param string  $type
   *
   * @return bool|mixed
   */
  protected function _checkCDN($params, $type)
  {

    // Remote type (normal or SSL)
    $remoteType = ($this->ssl ? 'SSL' : null);

    // Create a unique name for the file at the CDN
    $name = $params['pos'] . '-' . $params['group'] . '-' . $params['lm'];

    // Is the file available from the CDN?
    if ($url = $this->_remote->getUrl($name, $remoteType)) {
      // Return the remote URL
      return $url;
    }

    // Clear versions of this group from the CDN
    $this->_deleteOldVersions($params);

    // Get the minified source for this group
    $data = $this->_processGroup($params['group'], $type);

    // Get the content type
    $content_type = ($type == 'css' ? 'text/css' : 'application/javascript');

    // Upload the source to the CDN
    $this->_remote->saveObject($name, compact('data', 'content_type'));

    // Return the remote URL
    return $this->_remote->getUrl($name, $remoteType);
  }

  /**
   * Find any previous versions of this minified script at the
   * Cloud Files API and delete them
   *
   * @param array        $params The file params
   *
   */
  protected function _deleteOldVersions($params)
  {
    if ($objects = $this->_remote->listObjects($params['pos'] . '-' . $params['group'])) {
      foreach ($objects as $obj) {
        try {
          $this->_remote->deleteObject($obj);
        } catch (\Exception $e) {
          continue;
        }
      }
    }
  }

  /**
   * Get a 'Last Modified' timestamp for a group of files
   *
   * @param $files
   *
   * @return bool|mixed
   */
  protected function _getLm($files)
  {
    $files = (array)$files;
    $lmId = 'assetLm' . serialize($files);
    if (!empty($this->lmCacheExpire) && !YII_DEBUG && ($lmCache = $this->_cache->get($lmId)) !== false) {
      // Get last modified timestamp from cache
      $lm = $lmCache;
    } else {
      $fileMTimes = array();
      // Get last modified timestamp from files
      foreach ($files as $file) {
        $fileMTimes[] = @filemtime($file);
      }
      $lm = (!in_array(false, $fileMTimes, true)) ? max($fileMTimes) : false;
      // Add last modified timestamp to cache
      if (!empty($this->lmCacheExpire) && !YII_DEBUG && $lm !== false) {
        $this->_cache->set($lmId, $lm, (int)$this->lmCacheExpire);
      }
    }
    return $lm;
  }

}
